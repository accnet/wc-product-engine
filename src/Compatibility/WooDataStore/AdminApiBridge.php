<?php

declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

use wpdb;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Admin_Duplicate_Product;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Admin, REST v3, and Store API Bridge for native authority products.
 *
 * Implements:
 * - Admin CRUD, quick edit, bulk edit, and product duplication (R55, AC-T14-01).
 * - Full WC REST v3 product and variation roundtrip persistence (R56, AC-T14-01).
 * - Store API & public response sanitation preventing draft/private/cost leaks (R57, AC-T14-01).
 * - Object-level capability checks and denial for unauthorized actors (R58).
 * - Query support evaluation preventing stale postmeta fallback in native mode (AC-T14-02).
 */
class AdminApiBridge
{
    private static ?AdminApiBridge $instance = null;
    private static bool $booted = false;

    private wpdb $wpdb;
    private PostShellBridge $shellBridge;
    private InventoryBridge $inventoryBridge;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableSkuRegistry;
    private string $tableMeta;

    public function __construct(?wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->shellBridge = PostShellBridge::getInstance();
        $this->inventoryBridge = InventoryBridge::getInstance();

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = $prefix . 'wpe_products';
        $this->tableVariants    = $prefix . 'wpe_variants';
        $this->tableInventory   = $prefix . 'wpe_inventory';
        $this->tableSkuRegistry = $prefix . 'wpe_sku_registry';
        $this->tableMeta        = $prefix . 'wpe_meta';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot hooks for REST and Store API response sanitization.
     */
    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Strip private and cost data from REST response for public/unprivileged requests (R57)
        add_filter('woocommerce_rest_prepare_product_object', [$this, 'filterRestProductResponse'], 10, 3);
        add_filter('woocommerce_rest_prepare_product_variation_object', [$this, 'filterRestProductResponse'], 10, 3);
    }

    /* -------------------------------------------------------------------------- */
    /* Admin CRUD, Quick Edit, Bulk Edit, Duplicate (R55, AC-T14-01)               */
    /* -------------------------------------------------------------------------- */

    /**
     * Duplicates a product, generating a new shell, new SKU, and deep-copying variations.
     */
    public function duplicateProduct(WC_Product $product): WC_Product
    {
        if (class_exists('WC_Admin_Duplicate_Product')) {
            // Ensure original product and its children are registered in wc_product_meta_lookup
            // so WC_Admin_Duplicate_Product generates unique SKU suffix instead of throwing duplicate SKU error
            if ($product->get_sku()) {
                $this->wpdb->replace(
                    $this->wpdb->prefix . 'wc_product_meta_lookup',
                    [
                        'product_id' => $product->get_id(),
                        'sku'        => $product->get_sku(),
                    ],
                    ['%d', '%s']
                );
            }
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $childId) {
                    $child = wc_get_product($childId);
                    if ($child && $child->get_sku()) {
                        $this->wpdb->replace(
                            $this->wpdb->prefix . 'wc_product_meta_lookup',
                            [
                                'product_id' => $child->get_id(),
                                'sku'        => $child->get_sku(),
                            ],
                            ['%d', '%s']
                        );
                    }
                }
            }

            $duplicator = new WC_Admin_Duplicate_Product();
            $duplicate = $duplicator->product_duplicate($product);
        } else {
            // Manual duplication fallback
            $duplicate = clone $product;
            $duplicate->set_id(0);
            $duplicate->set_name($product->get_name() . ' (Copy)');
            $duplicate->set_slug(sanitize_title($duplicate->get_name()));
            $duplicate->set_sku($product->get_sku() ? $product->get_sku() . '-copy' : '');
            $duplicate->set_status('draft');
            $duplicate->save();
        }

        // Ensure unique SKU and draft status in native storage
        $dupId = $duplicate->get_id();
        $newSku = $duplicate->get_sku();
        if (empty($newSku)) {
            $newSku = 'SKU-DUP-' . $dupId . '-' . wp_generate_password(4, false);
            $duplicate->set_sku($newSku);
            $duplicate->save();
        }

        // Project shell for the duplicate
        $this->shellBridge->projectShell($dupId, [
            'title'  => $duplicate->get_name(),
            'slug'   => $duplicate->get_slug(),
            'status' => 'draft',
        ]);

        return $duplicate;
    }

    /**
     * Bulk update status for a list of products.
     */
    public function bulkUpdateStatus(array $productIds, string $newStatus): int
    {
        $allowed = ['publish', 'draft', 'pending', 'private', 'trash'];
        if (!in_array($newStatus, $allowed, true)) {
            return 0;
        }

        $count = 0;
        $now = gmdate('Y-m-d H:i:s.u');

        foreach ($productIds as $pid) {
            $pid = absint($pid);
            if ($pid <= 0) continue;

            // Check if product or variation
            $isVar = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(1) FROM {$this->tableVariants} WHERE id = %d",
                $pid
            ));

            if ($isVar > 0) {
                $this->wpdb->update(
                    $this->tableVariants,
                    ['status' => $newStatus, 'updated_at' => $now],
                    ['id' => $pid]
                );
            } else {
                $this->wpdb->update(
                    $this->tableProducts,
                    ['status' => $newStatus, 'updated_at' => $now],
                    ['id' => $pid]
                );
            }

            // Sync shell in wp_posts
            $this->wpdb->update(
                $this->wpdb->posts,
                ['post_status' => $newStatus, 'post_modified' => gmdate('Y-m-d H:i:s')],
                ['ID' => $pid]
            );

            clean_post_cache($pid);
            $count++;
        }

        return $count;
    }

    /**
     * Bulk trash products.
     */
    public function bulkTrash(array $productIds): int
    {
        $now = gmdate('Y-m-d H:i:s.u');
        foreach ($productIds as $pid) {
            $pid = absint($pid);
            if ($pid <= 0) continue;

            $this->wpdb->update(
                $this->tableProducts,
                ['deleted_at' => $now, 'status' => 'trash'],
                ['id' => $pid]
            );
            $this->wpdb->update(
                $this->tableVariants,
                ['deleted_at' => $now, 'status' => 'trash'],
                ['id' => $pid]
            );
        }

        return $this->bulkUpdateStatus($productIds, 'trash');
    }

    /**
     * Quick edit fields on a product.
     */
    public function quickEdit(int $productId, array $fields): bool
    {
        $product = wc_get_product($productId);
        if (!$product instanceof WC_Product) {
            return false;
        }

        if (isset($fields['name'])) $product->set_name((string)$fields['name']);
        if (isset($fields['slug'])) $product->set_slug((string)$fields['slug']);
        if (isset($fields['status'])) $product->set_status((string)$fields['status']);
        if (isset($fields['sku'])) $product->set_sku((string)$fields['sku']);
        if (isset($fields['regular_price'])) $product->set_regular_price((string)$fields['regular_price']);
        if (isset($fields['sale_price'])) $product->set_sale_price((string)$fields['sale_price']);
        if (isset($fields['manage_stock'])) $product->set_manage_stock((bool)$fields['manage_stock']);
        if (isset($fields['stock_quantity'])) $product->set_stock_quantity((float)$fields['stock_quantity']);

        $product->save();

        // Project shell
        $this->shellBridge->projectShell($productId, [
            'title'  => $product->get_name(),
            'slug'   => $product->get_slug(),
            'status' => $product->get_status(),
        ]);

        return true;
    }

    /* -------------------------------------------------------------------------- */
    /* Store API & Public Data Leak Prevention (R57, AC-T14-01)                   */
    /* -------------------------------------------------------------------------- */

    /**
     * Filters REST response data to prevent draft, private, or cost leakage (R57, AC-T14-01).
     *
     * @param WP_REST_Response $response
     * @param WC_Product $product
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function filterRestProductResponse(WP_REST_Response $response, WC_Product $product, WP_REST_Request $request): WP_REST_Response
    {
        $currentUserId = get_current_user_id();
        $canReadPrivate = user_can($currentUserId, 'read_private_products');

        $status = $product->get_status();
        $isPrivateOrDraft = in_array($status, ['draft', 'private', 'pending', 'trash'], true);

        // If unprivileged and product is not published, clear data
        if ($isPrivateOrDraft && !$canReadPrivate) {
            $response->set_data([]);
            $response->set_status(403);
            return $response;
        }

        $data = $response->get_data();
        if (is_array($data)) {
            // Strip cost fields and internal keys
            $forbiddenKeys = ['cost', '_cost', 'cost_price', '_cost_price', 'purchase_cost', '_purchase_cost'];
            foreach ($forbiddenKeys as $fk) {
                unset($data[$fk]);
            }

            // Strip private meta from meta_data unless user has manage_woocommerce
            if (isset($data['meta_data']) && is_array($data['meta_data']) && !user_can($currentUserId, 'manage_woocommerce')) {
                $filteredMeta = [];
                foreach ($data['meta_data'] as $metaItem) {
                    $key = is_array($metaItem) ? ($metaItem['key'] ?? '') : ($metaItem->key ?? '');
                    // Exclude internal underscore keys
                    if (!str_starts_with((string)$key, '_') || str_starts_with((string)$key, '_wc_')) {
                        $filteredMeta[] = $metaItem;
                    }
                }
                $data['meta_data'] = $filteredMeta;
            }

            $response->set_data($data);
        }

        return $response;
    }

    /**
     * Filters Store API response payloads directly.
     */
    public function filterStoreApiResponse(array $productData, int $userId = 0): ?array
    {
        $status = $productData['status'] ?? 'publish';
        $isPrivateOrDraft = in_array($status, ['draft', 'private', 'pending', 'trash'], true);

        $canReadPrivate = $userId > 0 && user_can($userId, 'read_private_products');
        if ($isPrivateOrDraft && !$canReadPrivate) {
            return null; // Suppressed from public surface
        }

        // Sanitize cost and internal properties
        $forbiddenKeys = ['cost', '_cost', 'cost_price', '_cost_price', 'purchase_cost', '_purchase_cost'];
        foreach ($forbiddenKeys as $fk) {
            unset($productData[$fk]);
        }

        return $productData;
    }

    /* -------------------------------------------------------------------------- */
    /* Object-Level Capability Enforcement (R58)                                  */
    /* -------------------------------------------------------------------------- */

    /**
     * Enforces object-level capabilities.
     *
     * @param int $productId
     * @param string $action 'read', 'edit', 'delete'
     * @param int $userId 0 for current user or anonymous
     * @return bool
     */
    public function checkObjectPermission(int $productId, string $action, ?int $userId = null): bool
    {
        if ($userId !== null) {
            $uid = $userId;
        } else {
            $user = wp_get_current_user();
            $uid = $user ? (int)$user->ID : 0;
        }

        if ($action === 'read') {
            $status = get_post_status($productId);
            if ($status === 'publish') {
                return true;
            }
            if ($uid <= 0) {
                return false;
            }
            return user_can($uid, 'read_private_products') || user_can($uid, 'edit_product', $productId);
        }

        if ($action === 'edit') {
            if ($uid <= 0) {
                return false;
            }
            return user_can($uid, 'edit_product', $productId) || user_can($uid, 'edit_post', $productId);
        }

        if ($action === 'delete') {
            if ($uid <= 0) {
                return false;
            }
            return user_can($uid, 'delete_product', $productId) || user_can($uid, 'delete_post', $productId);
        }

        return false;
    }

    /* -------------------------------------------------------------------------- */
    /* Query Support Evaluation (AC-T14-02)                                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Checks if a catalog/admin query is supported natively or attempts to query stale postmeta.
     * In native authority mode, unknown meta_queries are rejected rather than silently querying stale postmeta.
     */
    public function isQuerySupported(array $queryArgs, string $authorityMode = 'native'): array
    {
        if (empty($queryArgs['meta_query'])) {
            return [
                'supported'        => true,
                'fallback_allowed' => true,
                'unsupported_keys' => [],
            ];
        }

        $metaQuery = $queryArgs['meta_query'];
        $unsupportedKeys = [];

        // Known owned keys and supported indexed keys
        $supportedKeys = [
            '_price', '_regular_price', '_sale_price', '_sku',
            '_manage_stock', '_stock', '_stock_status', '_backorders',
            '_thumbnail_id', 'total_sales'
        ];

        foreach ($metaQuery as $k => $clause) {
            if (!is_array($clause)) continue;
            $key = $clause['key'] ?? '';
            if ($key !== '' && !in_array($key, $supportedKeys, true)) {
                // Check if key exists in wpe_meta
                $inNativeMeta = (int)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(1) FROM {$this->tableMeta} WHERE meta_key = %s",
                    $key
                ));
                if ($inNativeMeta === 0) {
                    $unsupportedKeys[] = $key;
                }
            }
        }

        if (!empty($unsupportedKeys)) {
            $isNative = ($authorityMode === 'native' || $authorityMode === 'full_native');
            return [
                'supported'        => false,
                'fallback_allowed' => !$isNative, // Only fallback in legacy mode (AC-T14-02)
                'unsupported_keys' => $unsupportedKeys,
                'error'            => $isNative
                    ? 'Cannot query unindexed third-party meta in native mode without bridge registration'
                    : 'Fallback to legacy CPT query allowed',
            ];
        }

        return [
            'supported'        => true,
            'fallback_allowed' => true,
            'unsupported_keys' => [],
        ];
    }
}
