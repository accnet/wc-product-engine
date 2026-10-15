<?php

declare(strict_types=1);

namespace WPE\Application\Migration;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\AssetService;

/**
 * LegacyCapture
 *
 * Implements:
 * - CRUD, post, term, metadata and stock hooks for legacy change capture (R150).
 * - Coalesces parent and variation changes to parent aggregate ID (R150).
 * - Reconciliation scanner detecting missed hooks via high-water mark and content hashes (R149, AC-T37-01).
 * - Detection and audit alerts for direct SQL writes without hooks (R151).
 * - Origin guards preventing infinite ping-pong sync loops between native and legacy (R152, AC-T37-01).
 * - Native authority protection: alerts on legacy mutations under native authority without overwriting native (AC-T37-02).
 */
class LegacyCapture
{
    private static ?LegacyCapture $instance = null;

    private static int $syncDepth = 0;
    private static string $currentOrigin = '';

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableAudit;
    private string $tableControl;
    private string $tableProductTerms;
    private string $tableSkuRegistry;

    private array $dirtyAggregates = [];
    private bool $hooksRegistered = false;

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts     = "{$prefix}wpe_products";
        $this->tableVariants     = "{$prefix}wpe_variants";
        $this->tableInventory    = "{$prefix}wpe_inventory";
        $this->tableObjectState  = "{$prefix}wpe_object_state";
        $this->tableAudit        = "{$prefix}wpe_audit";
        $this->tableControl      = "{$prefix}wpe_control";
        $this->tableProductTerms = "{$prefix}wpe_product_terms";
        $this->tableSkuRegistry  = "{$prefix}wpe_sku_registry";

        $this->productRepo  = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo  = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->assetService = $assetService ?? new AssetService($this->wpdb);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Origin Guard context wrapper (R152, AC-T37-01).
     *
     * Prevents infinite ping-pong loops by setting execution origin context.
     * When origin is 'native_projection', legacy hooks ignore triggered changes.
     */
    public static function withOrigin(string $origin, callable $callback)
    {
        $prevOrigin = self::$currentOrigin;
        self::$currentOrigin = $origin;
        self::$syncDepth++;
        try {
            return $callback();
        } finally {
            self::$syncDepth--;
            self::$currentOrigin = $prevOrigin;
        }
    }

    public static function isSyncing(): bool
    {
        return self::$syncDepth > 0;
    }

    public static function getOrigin(): string
    {
        return self::$currentOrigin;
    }

    /**
     * Register legacy change capture hooks (R150, R152).
     */
    public function registerHooks(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        // Post CRUD hooks
        add_action('save_post_product', [$this, 'onPostSaved'], 20, 2);
        add_action('save_post_product_variation', [$this, 'onPostSaved'], 20, 2);
        add_action('woocommerce_update_product', [$this, 'onProductSaved'], 20, 1);
        add_action('woocommerce_update_product_variation', [$this, 'onProductSaved'], 20, 1);

        // Delete / Trash hooks (R150)
        add_action('wp_trash_post', [$this, 'onPostTrashed'], 20, 1);
        add_action('trash_post', [$this, 'onPostTrashed'], 20, 1);
        add_action('trashed_post', [$this, 'onPostTrashed'], 20, 1);
        add_action('before_delete_post', [$this, 'onPostDeleted'], 20, 1);
        add_action('delete_post', [$this, 'onPostDeleted'], 20, 1);
        add_action('deleted_post', [$this, 'onPostDeleted'], 20, 1);

        // Taxonomy / Term hooks (R150)
        add_action('set_object_terms', [$this, 'onObjectTermsSet'], 20, 6);

        // Metadata hooks (R150)
        add_action('updated_post_meta', [$this, 'onPostMetaChanged'], 20, 4);
        add_action('added_post_meta', [$this, 'onPostMetaChanged'], 20, 4);
        add_action('deleted_post_meta', [$this, 'onPostMetaDeleted'], 20, 3);

        // Stock hooks (R150)
        add_action('woocommerce_product_set_stock', [$this, 'onProductStockSet'], 20, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'onProductStockSet'], 20, 1);

        $this->hooksRegistered = true;
    }

    /**
     * Handle save_post hook.
     */
    public function onPostSaved($postId, $post = null): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = (int)$postId;
        $parentAggregateId = $this->resolveParentAggregateId($id);
        if ($parentAggregateId <= 0) {
            return;
        }

        $this->markDirty($parentAggregateId);
        $this->captureLegacyProduct($parentAggregateId, 'save_post');
    }

    /**
     * Handle woocommerce_update_product hook.
     */
    public function onProductSaved($product): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = is_object($product) && method_exists($product, 'get_id') ? (int)$product->get_id() : (int)$product;
        if ($id <= 0) {
            return;
        }

        $parentAggregateId = $this->resolveParentAggregateId($id);
        $this->markDirty($parentAggregateId);
        $this->captureLegacyProduct($parentAggregateId, 'woocommerce_update_product');
    }

    /**
     * Handle post trashing (R150).
     */
    public function onPostTrashed($postId): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = (int)$postId;
        $parentAggregateId = $this->resolveParentAggregateId($id);
        if ($parentAggregateId <= 0) {
            return;
        }

        $this->markDirty($parentAggregateId);

        // Check authority: if native, alert, do not overwrite (AC-T37-02)
        if ($this->isNativeAuthority($parentAggregateId)) {
            $this->logAuditAlert('native_authority_legacy_mismatch', $parentAggregateId, [
                'trigger' => 'wp_trash_post',
                'target_id' => $id,
                'message' => 'Legacy post trashed while aggregate is under native authority; native aggregate untouched',
            ]);
            return;
        }

        // Under legacy authority: set tombstone or update status
        $this->wpdb->update($this->tableProducts, ['status' => 'trash'], ['id' => $parentAggregateId]);
        $this->wpdb->update($this->tableObjectState, [
            'tombstone'  => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['object_id' => $parentAggregateId]);
    }

    /**
     * Handle post deletion (R150).
     */
    public function onPostDeleted($postId): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = (int)$postId;
        $parentAggregateId = $this->resolveParentAggregateId($id);
        if ($parentAggregateId <= 0) {
            return;
        }

        if ($this->isNativeAuthority($parentAggregateId)) {
            $this->logAuditAlert('native_authority_legacy_mismatch', $parentAggregateId, [
                'trigger' => 'before_delete_post',
                'target_id' => $id,
                'message' => 'Legacy post deleted while aggregate is under native authority; native aggregate untouched',
            ]);
            return;
        }

        // Under legacy authority: record tombstone
        $this->wpdb->update($this->tableObjectState, [
            'tombstone'  => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['object_id' => $id]);
    }

    /**
     * Handle taxonomy / term assignment (R150).
     */
    public function onObjectTermsSet($objectId, $terms, $ttIds, $taxonomy, $append, $oldTtIds): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = (int)$objectId;
        $parentAggregateId = $this->resolveParentAggregateId($id);
        if ($parentAggregateId <= 0) {
            return;
        }

        $this->markDirty($parentAggregateId);
        $this->captureLegacyProduct($parentAggregateId, 'set_object_terms');
    }

    /**
     * Handle metadata changes (R150).
     */
    public function onPostMetaChanged($metaId, $objectId, $metaKey, $metaValue): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $trackedKeys = [
            '_price', '_regular_price', '_sale_price', '_stock', '_stock_status',
            '_manage_stock', 'total_sales', '_thumbnail_id', '_product_image_gallery',
            '_wc_average_rating', '_wc_rating_count', '_sku',
        ];

        if (!in_array($metaKey, $trackedKeys, true)) {
            return;
        }

        $id = (int)$objectId;
        $parentAggregateId = $this->resolveParentAggregateId($id);
        if ($parentAggregateId <= 0) {
            return;
        }

        $this->markDirty($parentAggregateId);
        $this->captureLegacyProduct($parentAggregateId, "meta_{$metaKey}");
    }

    /**
     * Handle metadata deletion (R150).
     */
    public function onPostMetaDeleted($metaIds, $objectId, $metaKey): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = (int)$objectId;
        $parentAggregateId = $this->resolveParentAggregateId($id);
        if ($parentAggregateId <= 0) {
            return;
        }

        $this->markDirty($parentAggregateId);
        $this->captureLegacyProduct($parentAggregateId, "meta_deleted_{$metaKey}");
    }

    /**
     * Handle WooCommerce stock changes (R150).
     */
    public function onProductStockSet($product): void
    {
        if ($this->shouldIgnoreHook()) {
            return;
        }

        $id = is_object($product) && method_exists($product, 'get_id') ? (int)$product->get_id() : (int)$product;
        if ($id <= 0) {
            return;
        }

        $parentAggregateId = $this->resolveParentAggregateId($id);
        $this->markDirty($parentAggregateId);
        $this->captureLegacyProduct($parentAggregateId, 'woocommerce_set_stock');
    }

    /**
     * Capture and synchronize a legacy product aggregate into native storage.
     *
     * @param int $parentAggregateId Parent product ID
     * @param string $trigger Triggering reason/hook
     * @return array Status of capture
     */
    public function captureLegacyProduct(int $parentAggregateId, string $trigger = 'manual'): array
    {
        // AC-T37-02: Native authority protection
        if ($this->isNativeAuthority($parentAggregateId)) {
            $this->logAuditAlert('native_authority_legacy_mismatch', $parentAggregateId, [
                'trigger' => $trigger,
                'message' => 'Legacy change detected on product under native authority; native data preserved without overwrite',
            ]);

            return [
                'status'    => 'blocked_native_authority',
                'id'        => $parentAggregateId,
                'alert'     => 'native_authority_legacy_mismatch',
                'overwrote' => false,
            ];
        }

        // Fetch post row
        $post = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->posts} WHERE ID = %d",
            $parentAggregateId
        ), ARRAY_A);

        if (!$post) {
            return [
                'status' => 'not_found',
                'id'     => $parentAggregateId,
            ];
        }

        if (($post['post_status'] ?? '') === 'auto-draft') {
            return [
                'status' => 'skipped_auto_draft',
                'id'     => $parentAggregateId,
            ];
        }

        // Gather metadata
        $meta = $this->loadPostMetaMap($parentAggregateId);

        $sku          = $meta['_sku'] ?? '';
        $regularPrice = isset($meta['_regular_price']) && $meta['_regular_price'] !== '' ? (float)$meta['_regular_price'] : null;
        $salePrice    = isset($meta['_sale_price']) && $meta['_sale_price'] !== '' ? (float)$meta['_sale_price'] : null;
        $manageStock  = !empty($meta['_manage_stock']) && $meta['_manage_stock'] === 'yes' ? 1 : 0;
        $stockQty     = isset($meta['_stock']) && $meta['_stock'] !== '' ? (float)$meta['_stock'] : null;
        $stockStatus  = $meta['_stock_status'] ?? 'instock';
        $weight       = isset($meta['_weight']) && $meta['_weight'] !== '' ? (float)$meta['_weight'] : null;

        $now = gmdate('Y-m-d H:i:s.u');

        // Check if product exists in wpe_products
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, version FROM {$this->tableProducts} WHERE id = %d",
            $parentAggregateId
        ), ARRAY_A);

        // Check children variations
        $varRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status != 'trash'",
            $parentAggregateId
        ), ARRAY_A);

        $isTrash     = ($post['post_status'] === 'trash');
        $productType = !empty($varRows) ? 'variable' : 'simple';
        $status      = $isTrash ? 'trash' : (($post['post_status'] === 'publish') ? 'publish' : 'draft');

        if ($existing) {
            $this->wpdb->update($this->tableProducts, [
                'title'             => $post['post_title'],
                'slug'              => $post['post_name'],
                'description'       => $post['post_content'],
                'short_description' => $post['post_excerpt'],
                'type'              => $productType,
                'status'            => $status,
                'sku'               => $sku !== '' ? $sku : null,
                'regular_price'     => $regularPrice,
                'sale_price'        => $salePrice,
                'weight'            => $weight,
                'updated_at'        => $now,
            ], ['id' => $parentAggregateId]);
        } else {
            $this->wpdb->insert($this->tableProducts, [
                'id'                 => $parentAggregateId,
                'type'               => $productType,
                'status'             => $status,
                'slug'               => $post['post_name'] !== '' ? $post['post_name'] : 'product-' . $parentAggregateId,
                'title'              => $post['post_title'],
                'description'        => $post['post_content'],
                'short_description'  => $post['post_excerpt'],
                'catalog_visibility' => 'visible',
                'sku'                => $sku !== '' ? $sku : null,
                'regular_price'      => $regularPrice,
                'sale_price'         => $salePrice,
                'weight'             => $weight,
                'purchase_note'      => '',
                'default_selection'  => '',
                'extra_props'        => '{}',
                'created_at'         => $now,
                'updated_at'         => $now,
                'version'            => 1,
            ]);
        }

        // Update inventory
        $this->wpdb->replace($this->tableInventory, [
            'owner_id'         => $parentAggregateId,
            'manage_stock'     => $manageStock,
            'quantity'         => $stockQty,
            'backorders'       => 'no',
            'stock_status'     => $stockStatus,
            'low_stock_amount' => null,
            'updated_at'       => $now,
            'version'          => 1,
        ]);

        // Sync variations if variable
        if (!empty($varRows)) {
            foreach ($varRows as $vr) {
                $vId = (int)$vr['ID'];
                $vMeta = $this->loadPostMetaMap($vId);
                $vSku = $vMeta['_sku'] ?? null;
                $vReg = isset($vMeta['_regular_price']) && $vMeta['_regular_price'] !== '' ? (float)$vMeta['_regular_price'] : null;
                $vSale = isset($vMeta['_sale_price']) && $vMeta['_sale_price'] !== '' ? (float)$vMeta['_sale_price'] : null;
                $vStock = isset($vMeta['_stock']) && $vMeta['_stock'] !== '' ? (float)$vMeta['_stock'] : null;
                $vManage = !empty($vMeta['_manage_stock']) && $vMeta['_manage_stock'] === 'yes' ? 1 : 0;
                $vStatus = $vMeta['_stock_status'] ?? 'instock';

                $vExisting = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$this->tableVariants} WHERE id = %d",
                    $vId
                ));

                $combKey = hash('sha256', "var_{$vId}", true);

                if ($vExisting) {
                    $this->wpdb->update($this->tableVariants, [
                        'sku'           => $vSku !== '' ? $vSku : null,
                        'regular_price' => $vReg,
                        'sale_price'    => $vSale,
                        'updated_at'    => $now,
                    ], ['id' => $vId]);
                } else {
                    $this->wpdb->insert($this->tableVariants, [
                        'id'                     => $vId,
                        'product_id'             => $parentAggregateId,
                        'status'                 => 'publish',
                        'sku'                    => $vSku !== '' ? $vSku : null,
                        'combination_key'        => $combKey,
                        'active_combination_key' => $combKey,
                        'regular_price'          => $vReg,
                        'sale_price'             => $vSale,
                        'description'            => '',
                        'extra_props'            => '{}',
                        'created_at'             => $now,
                        'updated_at'             => $now,
                        'version'                => 1,
                    ]);
                }

                $this->wpdb->replace($this->tableInventory, [
                    'owner_id'         => $vId,
                    'manage_stock'     => $vManage,
                    'quantity'         => $vStock,
                    'backorders'       => 'no',
                    'stock_status'     => $vStatus,
                    'low_stock_amount' => null,
                    'updated_at'       => $now,
                    'version'          => 1,
                ]);

                // Register SKU for variation
                if (!empty($vSku)) {
                    $normSku = mb_strtolower(trim($vSku), 'UTF-8');
                    $this->wpdb->replace($this->tableSkuRegistry, [
                        'normalized_sku' => $normSku,
                        'object_id'      => $vId,
                        'object_kind'    => 'variation',
                        'original_sku'   => $vSku,
                    ]);
                }
            }
        }

        // Register SKU for parent
        if (!empty($sku)) {
            $normSku = mb_strtolower(trim($sku), 'UTF-8');
            $this->wpdb->replace($this->tableSkuRegistry, [
                'normalized_sku' => $normSku,
                'object_id'      => $parentAggregateId,
                'object_kind'    => 'product',
                'original_sku'   => $sku,
            ]);
        }

        // Sync taxonomy terms (R150)
        $termRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT tr.term_taxonomy_id, tt.taxonomy
             FROM {$this->wpdb->term_relationships} tr
             JOIN {$this->wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id = %d",
            $parentAggregateId
        ), ARRAY_A);

        $this->wpdb->delete($this->tableProductTerms, ['product_id' => $parentAggregateId]);
        if (!empty($termRows)) {
            foreach ($termRows as $tr) {
                $this->wpdb->replace($this->tableProductTerms, [
                    'product_id'       => $parentAggregateId,
                    'term_taxonomy_id' => (int)$tr['term_taxonomy_id'],
                    'role'             => $tr['taxonomy'],
                ]);
            }
        }

        // Calculate legacy hash
        $legacyHash = $this->computeLegacyHash($parentAggregateId);

        // Update wpe_object_state
        $state = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableObjectState} WHERE object_id = %d",
            $parentAggregateId
        ), ARRAY_A);

        $legacyRev = $state ? (int)$state['legacy_revision'] + 1 : 1;

        $this->wpdb->replace($this->tableObjectState, [
            'object_id'       => $parentAggregateId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'legacy',
            'epoch'           => 1,
            'legacy_revision' => $legacyRev,
            'native_revision' => 1,
            'legacy_applied'  => $legacyRev,
            'native_applied'  => 0,
            'legacy_hash'     => hex2bin($legacyHash),
            'native_hash'     => null,
            'tombstone'       => $isTrash ? 1 : 0,
            'updated_at'      => $now,
        ]);

        return [
            'status'          => 'captured',
            'id'              => $parentAggregateId,
            'legacy_revision' => $legacyRev,
            'legacy_hash'     => $legacyHash,
            'overwrote'       => true,
        ];
    }

    /**
     * Run reconciliation scan detecting missed hooks & raw SQL writes (R149, R151, AC-T37-01).
     *
     * @param array $options Configuration options:
     *                       - 'full_scan': bool (default false, scan since highwater)
     *                       - 'force_repair': bool (default true)
     * @return array Scan and repair summary
     */
    public function runReconciliationScan(array $options = []): array
    {
        $fullScan    = $options['full_scan'] ?? false;
        $forceRepair = $options['force_repair'] ?? true;

        $highwater = $this->getHighwater();

        $sql = "SELECT ID, post_modified_gmt, post_type, post_parent
                FROM {$this->wpdb->posts}
                WHERE post_type IN ('product', 'product_variation')";

        if (!$fullScan && $highwater !== null) {
            $sql .= $this->wpdb->prepare(" AND post_modified_gmt > %s", $highwater);
        }
        $sql .= " ORDER BY post_modified_gmt ASC, ID ASC";

        $posts = $this->wpdb->get_results($sql, ARRAY_A);

        $scanned    = 0;
        $repaired   = 0;
        $alerts     = 0;
        $mismatches = [];
        $latestGmt  = $highwater;

        $processedParents = [];

        foreach ($posts as $p) {
            $scanned++;
            $pId = (int)$p['ID'];
            $parentAggregateId = $this->resolveParentAggregateId($pId);

            if ($p['post_modified_gmt'] > ($latestGmt ?? '')) {
                $latestGmt = $p['post_modified_gmt'];
            }

            if (isset($processedParents[$parentAggregateId])) {
                continue;
            }
            $processedParents[$parentAggregateId] = true;

            // Compute current legacy content hash
            $currentHash = $this->computeLegacyHash($parentAggregateId);

            // Fetch state from wpe_object_state
            $state = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableObjectState} WHERE object_id = %d",
                $parentAggregateId
            ), ARRAY_A);

            $storedHash = $state && $state['legacy_hash'] ? bin2hex($state['legacy_hash']) : null;
            $authority  = $state ? $state['authority'] : 'legacy';

            if ($storedHash === null || $storedHash !== $currentHash) {
                // Mismatch detected! (Missed hook or raw SQL write - R149, R151)
                $mismatchItem = [
                    'product_id'  => $parentAggregateId,
                    'authority'   => $authority,
                    'stored_hash' => $storedHash,
                    'new_hash'    => $currentHash,
                ];
                $mismatches[] = $mismatchItem;

                if ($authority === 'native') {
                    // AC-T37-02: Native authority mismatch -> log alert, do NOT overwrite native
                    $alerts++;
                    $this->logAuditAlert('native_authority_legacy_mismatch', $parentAggregateId, [
                        'source'      => 'reconciliation_scanner',
                        'stored_hash' => $storedHash,
                        'new_hash'    => $currentHash,
                        'message'     => 'Raw legacy change detected on product under native authority; native data preserved',
                    ]);
                } else {
                    // Legacy authority -> log raw_sql_detected alert and repair missed hook into native (R149, R151, AC-T37-01)
                    $this->logAuditAlert('raw_sql_detected', $parentAggregateId, [
                        'source'      => 'reconciliation_scanner',
                        'stored_hash' => $storedHash,
                        'new_hash'    => $currentHash,
                        'message'     => 'Direct legacy change detected via reconciliation scanner',
                    ]);
                    if ($forceRepair) {
                        $this->captureLegacyProduct($parentAggregateId, 'reconciliation_repair');
                        $repaired++;
                        $this->logAuditAlert('reconciliation_repair', $parentAggregateId, [
                            'source'      => 'reconciliation_scanner',
                            'stored_hash' => $storedHash,
                            'new_hash'    => $currentHash,
                        ]);
                    }
                }
            }
        }

        if ($latestGmt !== null) {
            $this->setHighwater($latestGmt);
        }

        return [
            'status'     => 'completed',
            'scanned'    => $scanned,
            'repaired'   => $repaired,
            'alerts'     => $alerts,
            'mismatches' => $mismatches,
            'highwater'  => $latestGmt,
        ];
    }

    /**
     * Compute deterministic hash of legacy product aggregate data.
     */
    public function computeLegacyHash(int $productId): string
    {
        $post = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT post_title, post_name, post_status, post_content, post_excerpt, post_modified_gmt FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ), ARRAY_A);

        if (!$post) {
            return hash('sha256', "missing_{$productId}");
        }

        $meta = $this->loadPostMetaMap($productId);

        // Include critical variant rows
        $varRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ID, post_title, post_status, post_modified_gmt FROM {$this->wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY ID ASC",
            $productId
        ), ARRAY_A);

        $varHashes = [];
        foreach ($varRows as $vr) {
            $vMeta = $this->loadPostMetaMap((int)$vr['ID']);
            $varHashes[] = [
                'id'       => $vr['ID'],
                'modified' => $vr['post_modified_gmt'],
                'sku'      => $vMeta['_sku'] ?? '',
                'price'    => $vMeta['_price'] ?? '',
                'stock'    => $vMeta['_stock'] ?? '',
            ];
        }

        // Include taxonomy terms (R150)
        $termIds = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$this->wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC",
            $productId
        ));

        $payload = [
            'id'            => $productId,
            'title'         => $post['post_title'],
            'slug'          => $post['post_name'],
            'status'        => $post['post_status'],
            'modified'      => $post['post_modified_gmt'],
            'sku'           => $meta['_sku'] ?? '',
            'price'         => $meta['_price'] ?? '',
            'regular_price' => $meta['_regular_price'] ?? '',
            'sale_price'    => $meta['_sale_price'] ?? '',
            'stock'         => $meta['_stock'] ?? '',
            'stock_status'  => $meta['_stock_status'] ?? '',
            'terms'         => $termIds ?: [],
            'variants'      => $varHashes,
        ];

        return hash('sha256', json_encode($payload));
    }

    /**
     * Check if current operation should bypass legacy hooks.
     */
    private function shouldIgnoreHook(): bool
    {
        // R152, AC-T37-01: Ping-pong guard
        if (self::$syncDepth > 0 && self::$currentOrigin === 'native_projection') {
            return true;
        }
        return false;
    }

    /**
     * Coalesce variation ID to parent product ID (R150).
     */
    public function resolveParentAggregateId(int $objectId): int
    {
        $post = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT post_type, post_parent FROM {$this->wpdb->posts} WHERE ID = %d",
            $objectId
        ), ARRAY_A);

        if (!$post) {
            return $objectId;
        }

        if ($post['post_type'] === 'product_variation' && !empty($post['post_parent'])) {
            return (int)$post['post_parent'];
        }

        return $objectId;
    }

    /**
     * Check if product is under native authority.
     */
    public function isNativeAuthority(int $productId): bool
    {
        $authority = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT authority FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));

        return ($authority === 'native');
    }

    /**
     * Set authority for a product.
     */
    public function setAuthority(int $productId, string $authority): void
    {
        if (!in_array($authority, ['legacy', 'native', 'shadow', 'indexed'], true)) {
            throw new InvalidArgumentException("Invalid authority: {$authority}");
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableObjectState} (object_id, kind, authority, epoch, legacy_revision, native_revision, updated_at)
             VALUES (%d, 'product', %s, 1, 1, 1, %s)
             ON DUPLICATE KEY UPDATE authority = VALUES(authority), updated_at = VALUES(updated_at)",
            $productId,
            $authority,
            $now
        ));
    }

    /**
     * Mark an aggregate dirty in memory and registry.
     */
    public function markDirty(int $productId): void
    {
        $this->dirtyAggregates[$productId] = true;
    }

    /**
     * Get list of dirty aggregates.
     */
    public function getDirtyAggregates(): array
    {
        return array_keys($this->dirtyAggregates);
    }

    public function clearDirtyAggregates(): void
    {
        $this->dirtyAggregates = [];
    }

    /**
     * Log an audit event.
     */
    public function logAuditAlert(string $operation, int $targetId, array $payload): void
    {
        $this->wpdb->insert($this->tableAudit, [
            'actor_id'   => get_current_user_id() ?: null,
            'request_id' => wp_generate_uuid4(),
            'operation'  => $operation,
            'target_id'  => $targetId,
            'summary'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * Load metadata map for post.
     */
    private function loadPostMetaMap(int $postId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d",
            $postId
        ), ARRAY_A);

        $map = [];
        foreach ($rows as $r) {
            $map[$r['meta_key']] = $r['meta_value'];
        }
        return $map;
    }

    /**
     * Get highwater mark from wpe_control.
     */
    public function getHighwater(): ?string
    {
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'legacy_scan_highwater'
        ));
    }

    /**
     * Set highwater mark in wpe_control.
     */
    public function setHighwater(string $timestamp): void
    {
        $this->wpdb->replace($this->tableControl, [
            'key'        => 'legacy_scan_highwater',
            'value'      => $timestamp,
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
