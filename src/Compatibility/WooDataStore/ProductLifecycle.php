<?php
declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

use WC_Product;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OutboxWorker;
use WPE\Compatibility\WooDataStore\PostShellBridge;

/**
 * ProductLifecycle — Handles product lifecycle events, transitions, cache invalidation,
 * and projection reentrancy guards for WooCommerce compatibility.
 *
 * Implements requirements:
 * - R59: Preserves action payload and order for WC lifecycle hooks.
 * - R60: Simple <-> Variable transitions preserving historical variation references.
 * - R61: Persistent and transient cache invalidation after mutations.
 * - R62: Prevents projection/mirror writes from emitting duplicate business events.
 * - AC-T15-01: Subscribers receive events with correct product ID/object and committed DB state.
 * - AC-T15-02: Durable stock operations with idempotency keys.
 * - AC-T15-03: Destructive transitions preserve historical variation references.
 */
class ProductLifecycle
{
    private \wpdb $wpdb;
    private ProductRepository $prodRepo;
    private PostShellBridge $shellBridge;
    private OutboxWorker $outboxWorker;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableObjectState;
    private string $tableStockOperations;

    /**
     * Reentrancy guard to prevent projection / mirror writes from emitting business events twice.
     */
    private static bool $inProjection = false;

    /**
     * Reentrancy guard for active lifecycle mutations per product ID.
     */
    private static array $activeMutations = [];

    /**
     * Singleton / boot flag.
     */
    private static bool $registered = false;

    public function __construct(
        ?ProductRepository $prodRepo = null,
        ?PostShellBridge $shellBridge = null,
        ?\wpdb $wpdb = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->prodRepo = $prodRepo ?? new ProductRepository();
        $this->shellBridge = $shellBridge ?? new PostShellBridge();
        $this->outboxWorker = new OutboxWorker();

        $this->tableProducts = $this->wpdb->prefix . 'wpe_products';
        $this->tableVariants = $this->wpdb->prefix . 'wpe_variants';
        $this->tableObjectState = $this->wpdb->prefix . 'wpe_object_state';
        $this->tableStockOperations = $this->wpdb->prefix . 'wpe_stock_operations';
    }

    /**
     * Registers WordPress lifecycle hooks to keep native storage and cache in sync.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $instance = new self();

        add_action('wp_trash_post', [$instance, 'onWpTrashPost'], 10, 1);
        add_action('untrashed_post', [$instance, 'onWpUntrashedPost'], 10, 1);
        add_action('before_delete_post', [$instance, 'onWpBeforeDeletePost'], 10, 2);
    }

    /* -------------------------------------------------------------------------- */
    /* Projection Guard (R62)                                                     */
    /* -------------------------------------------------------------------------- */

    /**
     * Checks if a projection / legacy mirror is currently executing.
     */
    public static function isProjectionInProgress(): bool
    {
        return self::$inProjection;
    }

    /**
     * Wraps a projection or legacy mirror callback with the reentrancy guard.
     */
    public static function wrapProjection(callable $callback): mixed
    {
        $prev = self::$inProjection;
        self::$inProjection = true;
        try {
            return $callback();
        } finally {
            self::$inProjection = $prev;
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Lifecycle Action Dispatcher (R59, AC-T15-01)                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Dispatches a lifecycle action after commit, guaranteeing correct payload and committed state.
     *
     * @param string $hook The action hook name (e.g. 'woocommerce_new_product')
     * @param int $productId Product ID
     * @param WC_Product|null $product Instantiated product object (hydrated if null)
     * @param array $extraArgs Additional arguments to pass to the hook
     */
    public function dispatchLifecycleHook(string $hook, int $productId, ?WC_Product $product = null, array $extraArgs = []): void
    {
        if (self::$inProjection) {
            // Suppress duplicate business write events during projection
            return;
        }

        if ($product === null) {
            $product = wc_get_product($productId);
        }

        switch ($hook) {
            case 'woocommerce_new_product':
            case 'woocommerce_update_product':
                do_action($hook, $productId, $product, ...$extraArgs);
                break;

            case 'woocommerce_new_product_variation':
            case 'woocommerce_update_product_variation':
            case 'woocommerce_delete_product':
            case 'woocommerce_trash_product':
            case 'woocommerce_untrash_product':
                do_action($hook, $productId, ...$extraArgs);
                break;

            case 'woocommerce_product_type_changed':
                // $extraArgs: [$fromType, $toType]
                do_action($hook, $product, $extraArgs[0] ?? '', $extraArgs[1] ?? '');
                break;

            default:
                do_action($hook, $productId, $product, ...$extraArgs);
                break;
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Transitions (R60, AC-T15-03)                                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Transitions a product between Simple and Variable types.
     *
     * @param int $productId
     * @param string $targetType 'simple' or 'variable'
     * @param array $options Optional configuration (e.g. regular_price, sale_price)
     * @return array Transition report
     */
    public function transitionType(int $productId, string $targetType, array $options = []): array
    {
        $this->wpdb->query("START TRANSACTION");
        try {
            $report = $this->applyTypeChange($productId, $targetType, $options);
            $this->wpdb->query("COMMIT");
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }

        if (!empty($report['changed'])) {
            $this->completeTypeChange($productId, (string)$report['from'], $targetType);
        }

        return $report;
    }

    /**
     * Writes a Simple/Variable type change WITHOUT opening or closing a transaction.
     *
     * The caller owns the transaction (the product editor flips the type inside its save transaction) and must
     * call completeTypeChange() after the commit. Options: regular_price and sale_price (variable to simple) and
     * bump_version (default true; pass false when the caller writes the product version itself).
     *
     * @return array Transition report
     */
    public function applyTypeChange(int $productId, string $targetType, array $options = []): array
    {
        $current = $this->prodRepo->getById($productId, true);
        if (!$current) {
            throw new \InvalidArgumentException("Product not found: {$productId}");
        }

        $currentType = $current['type'];
        if ($currentType === $targetType) {
            return [
                'success' => true,
                'from'    => $currentType,
                'to'      => $targetType,
                'changed' => false,
            ];
        }

        $now = gmdate('Y-m-d H:i:s');
        $tombstonedVariations = [];

        if ($currentType === 'variable' && $targetType === 'simple') {
            // Variable -> Simple
            // AC-T15-03: Soft-delete child variations into tombstones, preserving historical records
            $variants = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL",
                $productId
            ), ARRAY_A);

            foreach ($variants as $v) {
                $vid = (int)$v['id'];
                $tombstonedVariations[] = $vid;
                $this->wpdb->update(
                    $this->tableVariants,
                    [
                        'status'                 => 'trash',
                        'deleted_at'             => $now,
                        'active_combination_key' => null,
                        'updated_at'             => $now,
                    ],
                    ['id' => $vid]
                );

                // Update object state tombstone for variation
                $this->wpdb->update(
                    $this->tableObjectState,
                    ['tombstone' => 1, 'updated_at' => $now],
                    ['object_id' => $vid]
                );
            }

            // Update product type to simple
            $productUpdate = [
                'type'       => 'simple',
                'updated_at' => $now,
                'version'    => (int)$current['version'] + 1,
            ];
            if (isset($options['regular_price'])) {
                $productUpdate['regular_price'] = $options['regular_price'];
            }
            if (isset($options['sale_price'])) {
                $productUpdate['sale_price'] = $options['sale_price'];
            }

            if (($options['bump_version'] ?? true) === false) {
                unset($productUpdate['version']);
            }
            $this->wpdb->update($this->tableProducts, $productUpdate, ['id' => $productId]);

        } elseif ($currentType === 'simple' && $targetType === 'variable') {
            // Simple -> Variable
            $productUpdate = [
                'type'       => 'variable',
                'updated_at' => $now,
                'version'    => (int)$current['version'] + 1,
            ];

            if (($options['bump_version'] ?? true) === false) {
                unset($productUpdate['version']);
            }
            $this->wpdb->update($this->tableProducts, $productUpdate, ['id' => $productId]);
        } else {
            throw new \InvalidArgumentException("Unsupported transition: {$currentType} -> {$targetType}");
        }

        // Record outbox event
        $this->outboxWorker->publishEvent(
            $productId,
            (int)$current['version'] + 1,
            1,
            'product.type_changed',
            [
                'product_id' => $productId,
                'from_type'  => $currentType,
                'to_type'    => $targetType,
                'version'    => (int)$current['version'] + 1,
            ]
        );


        return [
            'success'               => true,
            'from'                  => $currentType,
            'to'                    => $targetType,
            'changed'               => true,
            'tombstoned_variations' => $tombstonedVariations,
        ];
    }

    /**
     * Post-commit part of a type change: cache invalidation, shell projection and lifecycle hooks.
     */
    public function completeTypeChange(int $productId, string $currentType, string $targetType): void
    {
        $this->invalidateProductCache($productId, $currentType);

        self::wrapProjection(function () use ($productId, $targetType) {
            $this->shellBridge->projectShell($productId, [
                'post_type' => 'product',
            ]);
        });

        // Dispatch lifecycle actions
        $product = wc_get_product($productId);
        add_filter('woocommerce_delete_variations_on_product_type_change', '__return_false', 999);
        $this->dispatchLifecycleHook('woocommerce_product_type_changed', $productId, $product, [$currentType, $targetType]);
        remove_filter('woocommerce_delete_variations_on_product_type_change', '__return_false', 999);
        $this->dispatchLifecycleHook('woocommerce_update_product', $productId, $product);
    }

    /**
     * Resolves historical variation record even if trashed or parent transitioned to simple (AC-T15-03).
     *
     * @param int $variationId
     * @return array|null Historical variation data
     */
    public function resolveHistoricalVariation(int $variationId): ?array
    {
        return $this->prodRepo->getVariantById($variationId, true);
    }

    /* -------------------------------------------------------------------------- */
    /* Trash, Untrash, Delete Lifecycle Actions                                   */
    /* -------------------------------------------------------------------------- */

    /**
     * Trashes a product and its variations, updating native storage and shell.
     */
    public function trashProduct(int $productId): bool
    {
        $guardKey = 'trash_' . $productId;
        if (self::$inProjection || isset(self::$activeMutations[$guardKey])) {
            return true;
        }
        self::$activeMutations[$guardKey] = true;

        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->wpdb->query("START TRANSACTION");
            try {
                $this->wpdb->update(
                    $this->tableProducts,
                    ['status' => 'trash', 'deleted_at' => $now, 'updated_at' => $now],
                    ['id' => $productId]
                );

                $this->wpdb->update(
                    $this->tableVariants,
                    ['status' => 'trash', 'deleted_at' => $now, 'active_combination_key' => null, 'updated_at' => $now],
                    ['product_id' => $productId]
                );

                $this->wpdb->update(
                    $this->tableObjectState,
                    ['tombstone' => 1, 'updated_at' => $now],
                    ['object_id' => $productId]
                );

                $this->wpdb->query("COMMIT");
            } catch (\Throwable $e) {
                $this->wpdb->query("ROLLBACK");
                throw $e;
            }

            // Project shell status
            self::wrapProjection(function () use ($productId) {
                $this->shellBridge->projectShell($productId, ['status' => 'trash']);
            });

            $this->invalidateProductCache($productId);

            $this->dispatchLifecycleHook('woocommerce_trash_product', $productId);
            return true;
        } finally {
            unset(self::$activeMutations[$guardKey]);
        }
    }

    /**
     * Restores a product and its variations from trash.
     */
    public function untrashProduct(int $productId): bool
    {
        $guardKey = 'untrash_' . $productId;
        if (self::$inProjection || isset(self::$activeMutations[$guardKey])) {
            return true;
        }
        self::$activeMutations[$guardKey] = true;

        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->wpdb->query("START TRANSACTION");
            try {
                $this->wpdb->update(
                    $this->tableProducts,
                    ['status' => 'publish', 'deleted_at' => null, 'updated_at' => $now],
                    ['id' => $productId]
                );

                // Restore active combination key for variants
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->tableVariants}
                     SET status = 'publish', deleted_at = NULL, active_combination_key = combination_key, updated_at = %s
                     WHERE product_id = %d",
                    $now, $productId
                ));

                $this->wpdb->update(
                    $this->tableObjectState,
                    ['tombstone' => 0, 'updated_at' => $now],
                    ['object_id' => $productId]
                );

                $this->wpdb->query("COMMIT");
            } catch (\Throwable $e) {
                $this->wpdb->query("ROLLBACK");
                throw $e;
            }

            // Project shell status
            self::wrapProjection(function () use ($productId) {
                $this->shellBridge->projectShell($productId, ['status' => 'publish']);
            });

            $this->invalidateProductCache($productId);

            $this->dispatchLifecycleHook('woocommerce_untrash_product', $productId);
            return true;
        } finally {
            unset(self::$activeMutations[$guardKey]);
        }
    }

    /**
     * Deletes a product permanently or moves to trash.
     */
    public function deleteProduct(int $productId, bool $force = false): bool
    {
        if (!$force) {
            return $this->trashProduct($productId);
        }

        $guardKey = 'delete_' . $productId;
        if (self::$inProjection || isset(self::$activeMutations[$guardKey])) {
            return true;
        }
        self::$activeMutations[$guardKey] = true;

        try {
            // Delegate hard delete to ProductRepository
            $this->prodRepo->delete($productId, true);

            // Delete post shell
            self::wrapProjection(function () use ($productId) {
                wp_delete_post($productId, true);
            });

            $this->invalidateProductCache($productId);

            $this->dispatchLifecycleHook('woocommerce_delete_product', $productId);
            return true;
        } finally {
            unset(self::$activeMutations[$guardKey]);
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Cache Invalidation Integration (R61)                                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Invalidates all object, transient, and post caches for a product.
     *
     * @param int $productId
     * @param string|null $oldType
     */
    public function invalidateProductCache(int $productId, ?string $oldType = null): void
    {
        // 1. WordPress core post cache
        clean_post_cache($productId);

        // 2. WooCommerce transients
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($productId);
        }

        // 3. Object cache entries
        wp_cache_delete($productId, 'posts');
        wp_cache_delete($productId, 'post_meta');
        wp_cache_delete($productId, 'products');
        wp_cache_delete('wc_product_' . $productId, 'products');

        // Invalidate children variations if variable
        $children = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE product_id = %d",
            $productId
        ));

        foreach ($children as $cid) {
            $childId = (int)$cid;
            clean_post_cache($childId);
            wp_cache_delete($childId, 'posts');
            wp_cache_delete($childId, 'post_meta');
            wp_cache_delete($childId, 'products');
            wp_cache_delete('wc_product_' . $childId, 'products');
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Durable Stock Operations (AC-T15-02)                                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Executes a stock operation using a durable operation key for idempotency.
     *
     * @param string $operationKey Unique key for this operation
     * @param int $ownerId Stock owner ID
     * @param string $operationType Operation kind ('reduce', 'restore', 'adjust', etc.)
     * @param float $quantityChange Amount change
     * @param callable $executor Callback executing the actual inventory adjustment
     * @return array Operation receipt
     */
    public function executeDurableStockOperation(
        string $operationKey,
        int $ownerId,
        string $operationType,
        float $quantityChange,
        callable $executor
    ): array {
        // 1. Check if operation was already recorded
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableStockOperations} WHERE operation_key = %s",
            $operationKey
        ), ARRAY_A);

        if ($existing) {
            return [
                'idempotent_replay' => true,
                'operation_key'     => $operationKey,
                'owner_id'          => (int)$existing['owner_id'],
                'operation_type'    => $existing['kind'],
                'quantity_change'   => (float)$existing['delta'],
                'balance_after'     => (float)$existing['quantity_after'],
            ];
        }

        // 2. Execute within transaction
        $this->wpdb->query("START TRANSACTION");
        try {
            $result = $executor();
            $balanceAfter = isset($result['balance_after']) ? (float)$result['balance_after'] : 0.0;

            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->insert(
                $this->tableStockOperations,
                [
                    'operation_key'  => $operationKey,
                    'owner_id'       => $ownerId,
                    'kind'           => $operationType,
                    'delta'          => $quantityChange,
                    'quantity_after' => $balanceAfter,
                    'created_at'     => $now,
                ]
            );

            $this->wpdb->query("COMMIT");
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }

        return [
            'idempotent_replay' => false,
            'operation_key'     => $operationKey,
            'owner_id'          => $ownerId,
            'operation_type'    => $operationType,
            'quantity_change'   => $quantityChange,
            'balance_after'     => $balanceAfter,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* Core Hook Listeners                                                        */
    /* -------------------------------------------------------------------------- */

    public function onWpTrashPost(int $postId): void
    {
        if (self::$inProjection) {
            return;
        }

        $postType = get_post_type($postId);
        if ($postType === 'product' || $postType === 'product_variation') {
            $this->trashProduct($postId);
        }
    }

    public function onWpUntrashedPost(int $postId): void
    {
        if (self::$inProjection) {
            return;
        }

        $postType = get_post_type($postId);
        if ($postType === 'product' || $postType === 'product_variation') {
            $this->untrashProduct($postId);
        }
    }

    public function onWpBeforeDeletePost(int $postId, ?\WP_Post $post = null): void
    {
        if (self::$inProjection) {
            return;
        }

        $postType = $post ? $post->post_type : get_post_type($postId);
        if ($postType === 'product' || $postType === 'product_variation') {
            $this->deleteProduct($postId, true);
        }
    }
}
