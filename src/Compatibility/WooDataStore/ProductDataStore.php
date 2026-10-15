<?php

namespace WPE\Compatibility\WooDataStore;

use WC_Data_Store_WP;
use WC_Object_Data_Store_Interface;
use WC_Product_Data_Store_Interface;
use WC_Product_Variable_Data_Store_Interface;
use WC_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Product_Download;
use Exception;
use RuntimeException;
use InvalidArgumentException;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Compatibility\WooDataStore\ProductLifecycle;

/**
 * WooCommerce Product Data Store adapter for native engine storage.
 * Completely replaces WC_Product_Data_Store_CPT for products under native authority,
 * implementing all 39 sealed Data Store methods without silent postmeta fallbacks (R37, R40).
 */
class ProductDataStore extends WC_Data_Store_WP implements WC_Object_Data_Store_Interface, WC_Product_Data_Store_Interface, WC_Product_Variable_Data_Store_Interface
{
    protected \wpdb $wpdb;
    protected ProductRepository $prodRepo;
    protected OptionsRepository $optRepo;
    protected MediaRepository $mediaRepo;

    protected string $tableProducts;
    protected string $tableVariants;
    protected string $tableInventory;
    protected string $tableSkuRegistry;
    protected string $tableMeta;
    protected string $tableDownloads;
    protected string $tableProductLinks;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->prodRepo = new ProductRepository($this->wpdb);
        $this->optRepo = new OptionsRepository($this->wpdb);
        $this->mediaRepo = new MediaRepository($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts     = $prefix . 'wpe_products';
        $this->tableVariants     = $prefix . 'wpe_variants';
        $this->tableInventory    = $prefix . 'wpe_inventory';
        $this->tableSkuRegistry  = $prefix . 'wpe_sku_registry';
        $this->tableMeta         = $prefix . 'wpe_meta';
        $this->tableDownloads    = $prefix . 'wpe_downloads';
        $this->tableProductLinks = $prefix . 'wpe_product_links';
    }

    /**
     * Register native data stores in WooCommerce filter (woocommerce_data_stores).
     */
    public static function registerDataStores(array $stores): array
    {
        $stores['product']           = self::class;
        $stores['product-simple']    = self::class;
        $stores['product-variable']  = ProductVariableDataStore::class;
        $stores['product-variation'] = ProductVariationDataStore::class;
        return $stores;
    }

    /* -------------------------------------------------------------------------- */
    /* Core CRUD Lifecycle (WC_Object_Data_Store_Interface)                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Create product in native storage.
     */
    public function create(&$product): void
    {
        $payload = [
            'name'               => $product->get_name('edit'),
            'slug'               => $product->get_slug('edit'),
            'type'               => $product->get_type(),
            'status'             => $product->get_status('edit') ?: 'publish',
            'catalog_visibility' => $product->get_catalog_visibility('edit') ?: 'visible',
            'sku'                => $product->get_sku('edit') ?: null,
            'regular_price'      => $product->get_regular_price('edit') !== '' ? $product->get_regular_price('edit') : null,
            'sale_price'         => $product->get_sale_price('edit') !== '' ? $product->get_sale_price('edit') : null,
            'virtual'            => $product->get_virtual('edit'),
            'downloadable'       => $product->get_downloadable('edit'),
            'tax_status'         => $product->get_tax_status('edit') ?: 'taxable',
            'tax_class'          => $product->get_tax_class('edit') ?: '',
            'sold_individually'  => $product->get_sold_individually('edit'),
            'purchase_note'      => $product->get_purchase_note('edit') ?: '',
            'menu_order'         => (int)$product->get_menu_order('edit'),
            'featured'           => $product->get_featured('edit'),
            'shipping_class_id'  => $product->get_shipping_class_id('edit') ?: null,
            'weight'             => $product->get_weight('edit') !== '' ? $product->get_weight('edit') : null,
            'length'             => $product->get_length('edit') !== '' ? $product->get_length('edit') : null,
            'width'              => $product->get_width('edit') !== '' ? $product->get_width('edit') : null,
            'height'             => $product->get_height('edit') !== '' ? $product->get_height('edit') : null,
            'manage_stock'       => $product->get_manage_stock('edit'),
            'stock_quantity'     => $product->get_stock_quantity('edit') !== null ? (string)$product->get_stock_quantity('edit') : null,
            'stock_status'       => $product->get_stock_status('edit') ?: 'instock',
            'backorders'         => $product->get_backorders('edit') ?: 'no',
            'description'        => $product->get_description('edit') ?: '',
            'short_description'  => $product->get_short_description('edit') ?: '',
            'gallery_image_ids'  => $product->get_gallery_image_ids('edit') ?: [],
        ];

        if ($product->get_id() > 0) {
            $payload['id'] = $product->get_id();
        }

        $created = $this->prodRepo->create($payload, false);
        $productId = (int)$created['id'];
        $product->set_id($productId);

        $imageId = (int)$product->get_image_id('edit');
        if ($imageId > 0) {
            try {
                $this->mediaRepo->setFeaturedImage($productId, $imageId);
            } catch (\Throwable $e) {}
        }

        $this->save_meta($product);
        $this->save_downloads($product);

        $product->apply_changes();
        $product->set_object_read(true);
        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->invalidateProductCache($productId);
    }

    /**
     * Read product data from native storage without fallback to postmeta (R40).
     */
    public function read(&$product): void
    {
        $productId = $product->get_id();
        $data = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ), ARRAY_A);

        if (!$data) {
            throw new Exception(esc_html__('Invalid product.', 'woocommerce'));
        }

        $inv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ), ARRAY_A);

        $featured = $this->mediaRepo->getFeaturedImage($productId);
        $gallery = $this->mediaRepo->getGallery($productId);
        $upsells = $this->mediaRepo->getLinks($productId, 'upsell');
        $crosssells = $this->mediaRepo->getLinks($productId, 'crosssell');
        $extra = json_decode($data['extra_props'] ?? '{}', true) ?: [];

        $product->set_props([
            'name'               => $data['title'],
            'slug'               => $data['slug'],
            'status'             => $data['status'],
            'catalog_visibility' => $data['catalog_visibility'] ?? 'visible',
            'sku'                => $data['sku'] ?? '',
            'regular_price'      => $data['regular_price'] !== null ? (string)$data['regular_price'] : '',
            'sale_price'         => $data['sale_price'] !== null ? (string)$data['sale_price'] : '',
            'price'              => ($data['sale_price'] !== null && $data['sale_price'] !== '') ? (string)$data['sale_price'] : ($data['regular_price'] !== null ? (string)$data['regular_price'] : ''),
            'virtual'            => !empty($data['virtual']),
            'downloadable'       => !empty($data['downloadable']),
            'tax_status'         => $data['tax_status'] ?? 'taxable',
            'tax_class'          => $data['tax_class'] ?? '',
            'sold_individually'  => !empty($data['sold_individually']),
            'purchase_note'      => $data['purchase_note'] ?? '',
            'menu_order'         => (int)($data['menu_order'] ?? 0),
            'featured'           => !empty($data['featured']),
            'shipping_class_id'  => (int)($data['shipping_class_id'] ?? 0),
            'weight'             => $data['weight'] !== null ? (string)$data['weight'] : '',
            'length'             => $data['length'] !== null ? (string)$data['length'] : '',
            'width'              => $data['width'] !== null ? (string)$data['width'] : '',
            'height'             => $data['height'] !== null ? (string)$data['height'] : '',
            'manage_stock'       => !empty($inv['manage_stock']),
            'stock_quantity'     => $inv && $inv['quantity'] !== null ? (float)$inv['quantity'] : null,
            'stock_status'       => $inv['stock_status'] ?? 'instock',
            'backorders'         => $inv['backorders'] ?? 'no',
            'description'        => $data['description'] ?? '',
            'short_description'  => $data['short_description'] ?? '',
            'image_id'           => $featured ? (int)$featured['asset_id'] : 0,
            'gallery_image_ids'  => array_map('intval', array_column($gallery, 'asset_id')),
            'upsell_ids'         => array_map('intval', array_column($upsells, 'target_id')),
            'cross_sell_ids'     => array_map('intval', array_column($crosssells, 'target_id')),
            'average_rating'     => (float)($extra['average_rating'] ?? 0.0),
            'review_count'       => (int)($extra['review_count'] ?? 0),
            'rating_counts'      => $extra['rating_counts'] ?? [],
            'date_created'       => $data['created_at'],
            'date_modified'      => $data['updated_at'],
        ]);

        $this->read_meta($product);
        $this->read_downloads($product);

        if ($product instanceof WC_Product_Variable) {
            $this->read_children($product);
            $this->read_default_attributes($product);
        }

        $product->set_object_read(true);
    }

    /**
     * Update product in native storage.
     */
    public function update(&$product): void
    {
        $productId = $product->get_id();
        $changes = $product->get_changes();

        $payload = [];
        if (isset($changes['name'])) $payload['title'] = $changes['name'];
        if (isset($changes['slug'])) $payload['slug'] = $changes['slug'];
        if (isset($changes['status'])) $payload['status'] = $changes['status'];
        if (isset($changes['catalog_visibility'])) $payload['catalog_visibility'] = $changes['catalog_visibility'];
        if (array_key_exists('sku', $changes)) $payload['sku'] = $changes['sku'] ?: null;
        if (array_key_exists('regular_price', $changes)) $payload['regular_price'] = $changes['regular_price'] !== '' ? $changes['regular_price'] : null;
        if (array_key_exists('sale_price', $changes)) $payload['sale_price'] = $changes['sale_price'] !== '' ? $changes['sale_price'] : null;
        if (isset($changes['virtual'])) $payload['virtual'] = $changes['virtual'];
        if (isset($changes['downloadable'])) $payload['downloadable'] = $changes['downloadable'];
        if (isset($changes['tax_status'])) $payload['tax_status'] = $changes['tax_status'];
        if (isset($changes['tax_class'])) $payload['tax_class'] = $changes['tax_class'];
        if (isset($changes['sold_individually'])) $payload['sold_individually'] = $changes['sold_individually'];
        if (isset($changes['purchase_note'])) $payload['purchase_note'] = $changes['purchase_note'];
        if (isset($changes['menu_order'])) $payload['menu_order'] = $changes['menu_order'];
        if (isset($changes['featured'])) $payload['featured'] = $changes['featured'];
        if (isset($changes['shipping_class_id'])) $payload['shipping_class_id'] = $changes['shipping_class_id'] ?: null;
        if (isset($changes['weight'])) $payload['weight'] = $changes['weight'] !== '' ? $changes['weight'] : null;
        if (isset($changes['length'])) $payload['length'] = $changes['length'] !== '' ? $changes['length'] : null;
        if (isset($changes['width'])) $payload['width'] = $changes['width'] !== '' ? $changes['width'] : null;
        if (isset($changes['height'])) $payload['height'] = $changes['height'] !== '' ? $changes['height'] : null;
        if (isset($changes['manage_stock'])) $payload['manage_stock'] = $changes['manage_stock'];
        if (isset($changes['stock_quantity'])) $payload['stock_quantity'] = $changes['stock_quantity'] !== null ? (string)$changes['stock_quantity'] : null;
        if (isset($changes['stock_status'])) $payload['stock_status'] = $changes['stock_status'];
        if (isset($changes['description'])) $payload['description'] = $changes['description'];
        if (isset($changes['short_description'])) $payload['short_description'] = $changes['short_description'];
        if (isset($changes['gallery_image_ids'])) $payload['gallery_image_ids'] = $changes['gallery_image_ids'];

        if (!empty($payload)) {
            $this->prodRepo->update($productId, $payload);
        }

        if (isset($changes['image_id'])) {
            $imgId = (int)$changes['image_id'];
            if ($imgId > 0) {
                try {
                    $this->mediaRepo->setFeaturedImage($productId, $imgId);
                } catch (\Throwable $e) {}
            }
        }

        if (isset($changes['upsell_ids'])) {
            $this->mediaRepo->setLinks($productId, 'upsell', $changes['upsell_ids']);
        }
        if (isset($changes['cross_sell_ids'])) {
            $this->mediaRepo->setLinks($productId, 'crosssell', $changes['cross_sell_ids']);
        }

        $this->save_meta($product);
        $this->save_downloads($product);

        $product->apply_changes();
        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->invalidateProductCache($productId);
    }

    /**
     * Delete product from native storage.
     */
    public function delete(&$product, $args = []): void
    {
        $productId = $product->get_id();
        $force = !empty($args['force_delete']);

        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->deleteProduct($productId, $force);
        $product->set_id(0);
    }

    /* -------------------------------------------------------------------------- */
    /* Variable Product Methods (WC_Product_Variable_Data_Store_Interface)        */
    /* -------------------------------------------------------------------------- */

    public function read_children(&$product, $force_read = false): array
    {
        $productId = $product->get_id();
        $sql = "SELECT id, status FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC";
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $productId), ARRAY_A);

        $all = [];
        $visible = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $all[] = $id;
            if ($row['status'] === 'publish') {
                $visible[] = $id;
            }
        }

        $product->set_children($all);
        $product->set_visible_children($visible);

        return [
            'all'     => $all,
            'visible' => $visible,
        ];
    }

    protected function read_default_attributes(&$product): void
    {
        $productId = $product->get_id();
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.name, o.`key`, o.taxonomy, ov.label, ov.value_key
             FROM {$this->wpdb->prefix}wpe_options o
             INNER JOIN {$this->wpdb->prefix}wpe_option_values ov
                 ON ov.id = o.default_value_id AND ov.option_id = o.id
             WHERE o.product_id = %d AND o.used_for_variation = 1 AND o.default_value_id IS NOT NULL
             ORDER BY o.position ASC",
            $productId
        ), ARRAY_A);

        $defaults = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $attrKey = sanitize_title((string)($r['name'] ?: $r['key']));
                $attrVal = (string)$r['label'];
                if ($attrKey !== '' && $attrVal !== '') {
                    $defaults[$attrKey] = $attrVal;
                }
            }
        }
        $product->set_default_attributes($defaults);
    }

    public function sync_price(&$product): void
    {
        $productId = $product->get_id();
        $sql = "SELECT MIN(regular_price) as min_reg, MAX(regular_price) as max_reg,
                       MIN(COALESCE(sale_price, regular_price)) as min_price,
                       MAX(COALESCE(sale_price, regular_price)) as max_price
                FROM {$this->tableVariants}
                WHERE product_id = %d AND status = 'publish' AND deleted_at IS NULL";

        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);
        if ($row && $row['min_price'] !== null) {
            $this->wpdb->update(
                $this->tableProducts,
                [
                    'regular_price' => $row['min_reg'],
                    'sale_price'    => $row['min_price'],
                ],
                ['id' => $productId]
            );
        }
    }

    public function sync_variation_names(&$product, $previous_name = '', $new_name = ''): void
    {
        // No-op for native variable products as variation titles are resolved dynamically
    }

    public function sync_managed_variation_stock_status(&$product): void
    {
        $productId = $product->get_id();
        $childrenData = $this->read_children($product);
        $children = $childrenData['all'] ?? [];
        if (empty($children)) {
            return;
        }

        $inStockCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableInventory} WHERE owner_id IN (" . implode(',', $children) . ") AND stock_status = 'instock'"
        ));

        $newStatus = $inStockCount > 0 ? 'instock' : 'outofstock';
        $this->wpdb->update($this->tableInventory, ['stock_status' => $newStatus], ['owner_id' => $productId]);
    }

    public function child_has_weight($product): bool
    {
        $childrenData = $this->read_children($product);
        $children = $childrenData['all'] ?? [];
        if (empty($children)) return false;
        $count = (int)$this->wpdb->get_var("SELECT COUNT(1) FROM {$this->tableVariants} WHERE id IN (" . implode(',', $children) . ") AND weight IS NOT NULL AND weight > 0");
        return $count > 0;
    }

    public function child_has_dimensions($product): bool
    {
        $childrenData = $this->read_children($product);
        $children = $childrenData['all'] ?? [];
        if (empty($children)) return false;
        $count = (int)$this->wpdb->get_var("SELECT COUNT(1) FROM {$this->tableVariants} WHERE id IN (" . implode(',', $children) . ") AND length IS NOT NULL AND length > 0");
        return $count > 0;
    }

    public function child_is_in_stock($product): bool
    {
        $childrenData = $this->read_children($product);
        $children = $childrenData['all'] ?? [];
        if (empty($children)) return false;
        $count = (int)$this->wpdb->get_var("SELECT COUNT(1) FROM {$this->tableInventory} WHERE owner_id IN (" . implode(',', $children) . ") AND stock_status = 'instock'");
        return $count > 0;
    }

    public function delete_variations($product_id, $force_delete = false): void
    {
        $sql = "SELECT id FROM {$this->tableVariants} WHERE product_id = %d";
        $ids = $this->wpdb->get_col($this->wpdb->prepare($sql, $product_id));
        foreach ($ids as $id) {
            $this->prodRepo->delete((int)$id, $force_delete);
        }
    }

    public function untrash_variations($product_id): void
    {
        $this->wpdb->update(
            $this->tableVariants,
            ['status' => 'publish', 'deleted_at' => null, 'active_combination_key' => 'combination_key'],
            ['product_id' => $product_id]
        );
    }

    /* -------------------------------------------------------------------------- */
    /* 39 Sealed Data Store Interface Methods                                     */
    /* -------------------------------------------------------------------------- */

    public function find_matching_product_variation($product, $match_attributes = []): int
    {
        $productId = $product->get_id();
        try {
            $vector = $this->optRepo->buildCombinationVector($productId, $match_attributes);
            $keyHex = $this->optRepo->buildCombinationKey($vector);
            $varId = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND active_combination_key = UNHEX(%s) AND status = 'publish' AND deleted_at IS NULL",
                $productId,
                $keyHex
            ));
            return (int)$varId;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function sort_all_product_variations($parent_id): void
    {
        $variants = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE product_id = %d ORDER BY id ASC",
            $parent_id
        ));

        $pos = 0;
        foreach ($variants as $id) {
            $this->wpdb->update($this->tableVariants, ['position' => $pos++], ['id' => $id]);
        }
    }

    public function create_all_product_variations($product, $limit = -1, $default_values = [], $metadata = []): int
    {
        // Native engine prevents Cartesian auto-generation per R27
        return 0;
    }

    public function get_on_sale_products(): array
    {
        $sql = "SELECT id FROM {$this->tableProducts}
                WHERE status = 'publish' AND deleted_at IS NULL
                  AND sale_price IS NOT NULL AND regular_price IS NOT NULL
                  AND sale_price < regular_price";
        return array_map('intval', $this->wpdb->get_col($sql));
    }

    public function get_featured_product_ids(): array
    {
        $sql = "SELECT id FROM {$this->tableProducts}
                WHERE status = 'publish' AND deleted_at IS NULL AND featured = 1";
        return array_map('intval', $this->wpdb->get_col($sql));
    }

    public function is_existing_sku($product_id, $sku): bool
    {
        $existingId = $this->get_product_id_by_sku($sku);
        return $existingId > 0 && $existingId !== (int)$product_id;
    }

    public function get_product_id_by_sku($sku): int
    {
        $trimmed = trim((string)$sku);
        if ($trimmed === '') return 0;

        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT object_id FROM {$this->tableSkuRegistry} WHERE normalized_sku = %s",
            $trimmed
        ));
        return (int)$id;
    }

    public function get_product_type($product_id)
    {
        $type = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT type FROM {$this->tableProducts} WHERE id = %d",
            $product_id
        ));

        if ($type) {
            return $type;
        }

        $isVar = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableVariants} WHERE id = %d",
            $product_id
        ));

        return ((int)$isVar > 0) ? 'variation' : false;
    }

    public function get_starting_sales(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = "SELECT id FROM {$this->tableProducts} WHERE sale_from <= %s AND (sale_to IS NULL OR sale_to >= %s)";
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql, $now, $now)));
    }

    public function get_ending_sales(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $sql = "SELECT id FROM {$this->tableProducts} WHERE sale_to < %s";
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql, $now)));
    }

    public function get_related_products($cats_array, $tags_array, $exclude_ids, $limit, $product_id): array
    {
        $exclude_ids = array_map('intval', (array)$exclude_ids);
        $exclude_ids[] = (int)$product_id;
        $exclude_ids = array_values(array_unique(array_filter($exclude_ids)));

        // Prefer explicit links
        $sql = "SELECT target_id FROM {$this->tableProductLinks} WHERE product_id = %d";
        if (!empty($exclude_ids)) {
            $sql .= " AND target_id NOT IN (" . implode(',', $exclude_ids) . ")";
        }
        $sql .= " ORDER BY position ASC LIMIT %d";
        $results = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql, $product_id, $limit)));

        if (count($results) >= $limit) {
            return array_slice($results, 0, $limit);
        }

        $needed = $limit - count($results);
        $allExcludes = array_merge($exclude_ids, $results);
        $sql2 = "SELECT id FROM {$this->tableProducts} WHERE status = 'publish' AND deleted_at IS NULL";
        if (!empty($allExcludes)) {
            $sql2 .= " AND id NOT IN (" . implode(',', array_map('intval', $allExcludes)) . ")";
        }
        $sql2 .= " ORDER BY id DESC LIMIT %d";
        $more = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql2, $needed)));

        return array_merge($results, $more);
    }

    public function get_related_products_query($cats_array, $tags_array, $exclude_ids, $limit)
    {
        $exclude_ids = array_map('intval', (array)$exclude_ids);
        $where = "WHERE status = 'publish' AND deleted_at IS NULL";
        if (!empty($exclude_ids)) {
            $where .= " AND id NOT IN (" . implode(',', $exclude_ids) . ")";
        }
        return [
            'select' => "SELECT id FROM {$this->tableProducts}",
            'where'  => $where,
            'limit'  => "LIMIT " . (int)$limit,
        ];
    }

    public function update_product_stock($product_id_with_stock, $stock_quantity = null, $operation = 'set'): float
    {
        $bridge = InventoryBridge::getInstance();
        $res = $bridge->updateProductStock(
            absint($product_id_with_stock),
            $stock_quantity !== null ? (float)$stock_quantity : null,
            $operation
        );
        return (float)($res['to'] ?? 0.0);
    }

    public function get_stock_quantity($product_id): ?float
    {
        return InventoryBridge::getInstance()->getStockQuantity(absint($product_id));
    }

    public function update_product_sales($product_id, $quantity = null, $operation = 'set'): void
    {
        $productId = absint($product_id);
        if (!$productId) return;
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableProducts} WHERE id = %d", $productId), ARRAY_A);
        if (!$row) return;
        $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
        $current = (int)($extra['total_sales'] ?? 0);
        if ($operation === 'increase') {
            $newSales = $current + (int)$quantity;
        } elseif ($operation === 'decrease') {
            $newSales = max(0, $current - (int)$quantity);
        } else {
            $newSales = (int)$quantity;
        }
        $extra['total_sales'] = $newSales;
        $this->wpdb->update($this->tableProducts, ['extra_props' => json_encode($extra, JSON_UNESCAPED_UNICODE)], ['id' => $productId]);
    }

    public function get_shipping_class_id_by_slug($slug)
    {
        $term = get_term_by('slug', $slug, 'product_shipping_class');
        return $term ? (int)$term->term_id : false;
    }

    public function get_products($args = []): array
    {
        return $this->query($args);
    }

    public function query($query_vars = []): array
    {
        $limit = isset($query_vars['limit']) ? (int)$query_vars['limit'] : 20;
        $sql = "SELECT id FROM {$this->tableProducts} WHERE deleted_at IS NULL";

        if (!empty($query_vars['type'])) {
            $sql .= $this->wpdb->prepare(" AND type = %s", $query_vars['type']);
        }
        if (!empty($query_vars['status'])) {
            $sql .= $this->wpdb->prepare(" AND status = %s", $query_vars['status']);
        }
        if (!empty($query_vars['include'])) {
            $includeIds = array_map('intval', (array)$query_vars['include']);
            $sql .= " AND id IN (" . implode(',', $includeIds) . ")";
        }

        $order = isset($query_vars['order']) && strtoupper($query_vars['order']) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY id {$order} LIMIT %d";
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql, $limit)));
    }

    public function read_stock_quantity(&$product, $new_stock = null)
    {
        if (is_numeric($product)) {
            $productId = (int)$product;
            $productObj = null;
        } elseif (is_object($product) && method_exists($product, 'get_id')) {
            $productId = (int)$product->get_id();
            $productObj = $product;
        } else {
            return null;
        }

        if ($new_stock !== null) {
            $qty = (float)$new_stock;
        } else {
            $qty = InventoryBridge::getInstance()->getStockQuantity($productId);
        }

        if ($productObj) {
            $object_read = $productObj->get_object_read();
            $productObj->set_object_read(false);
            $productObj->set_stock_quantity($qty);
            $productObj->set_object_read($object_read);
        }

        return $qty;
    }

    public function read_meta(&$object)
    {
        $metaMap = $this->mediaRepo->getMeta($object->get_id());
        $rawMeta = [];
        $metaIdCounter = 1;

        foreach ($metaMap as $key => $values) {
            foreach ($values as $val) {
                $rawMeta[] = (object)[
                    'meta_id'    => $metaIdCounter++,
                    'meta_key'   => $key,
                    'meta_value' => $val,
                ];
            }
        }

        $filtered = $this->filter_raw_meta_data($object, $rawMeta);
        $object->init_meta_data($filtered);
        return $filtered;
    }

    public function add_meta(&$object, $meta)
    {
        return $this->mediaRepo->addMeta($object->get_id(), 'product', $meta->key, $meta->value, false);
    }

    public function update_meta(&$object, $meta)
    {
        if (!empty($meta->id)) {
            $this->mediaRepo->updateMeta((int)$meta->id, $meta->value);
        } else {
            $this->mediaRepo->addMeta($object->get_id(), 'product', $meta->key, $meta->value, true);
        }
    }

    public function delete_meta(&$object, $meta)
    {
        if (!empty($meta->id)) {
            $this->mediaRepo->deleteMeta((int)$meta->id);
        }
    }

    public function filter_raw_meta_data(&$object, $raw_meta_data)
    {
        $this->internal_meta_keys = array_unique(
            array_merge(
                $this->get_internal_meta_keys(),
                array_map(
                    function($key) { return '_' . $key; },
                    $object->get_data_keys()
                )
            )
        );
        $meta_data = array_filter($raw_meta_data, function($meta) {
            return !in_array($meta->meta_key, $this->internal_meta_keys, true);
        });
        return apply_filters("woocommerce_data_store_wp_product_read_meta", $meta_data, $object, $this);
    }

    public function get_internal_meta_keys(): array
    {
        return array_merge(parent::get_internal_meta_keys(), [
            '_wpe_anchor',
            '_wpe_authority',
            '_wpe_epoch',
            '_wpe_version',
        ]);
    }

    public function get_query_for_stock($product_id)
    {
        return $this->wpdb->prepare(
            "SELECT COALESCE(MAX(quantity), 0) FROM {$this->tableInventory} WHERE owner_id = %d",
            $product_id
        );
    }

    public function is_existing_global_unique_id($product_id, $global_unique_id): bool
    {
        $existing = $this->get_product_id_by_global_unique_id($global_unique_id);
        return $existing > 0 && $existing !== (int)$product_id;
    }

    public function get_product_id_by_global_unique_id($global_unique_id): int
    {
        $guid = trim((string)$global_unique_id);
        if ($guid === '') {
            return 0;
        }
        $prodId = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tableProducts} WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_props, '$.global_unique_id')) = %s AND deleted_at IS NULL",
            $guid
        ));
        if ($prodId) {
            return (int)$prodId;
        }
        $varId = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE JSON_UNQUOTE(JSON_EXTRACT(extra_props, '$.global_unique_id')) = %s AND deleted_at IS NULL",
            $guid
        ));
        return (int)$varId;
    }

    public function update_average_rating($product): void
    {
        $productId = $product->get_id();
        $rating = (float)$product->get_average_rating('edit');
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableProducts} WHERE id = %d", $productId), ARRAY_A);
        if (!$row) return;
        $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
        $extra['average_rating'] = $rating;
        $this->wpdb->update($this->tableProducts, ['extra_props' => json_encode($extra, JSON_UNESCAPED_UNICODE)], ['id' => $productId]);
    }

    public function update_rating_counts($product): void
    {
        $productId = $product->get_id();
        $counts = $product->get_rating_counts('edit');
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableProducts} WHERE id = %d", $productId), ARRAY_A);
        if (!$row) return;
        $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
        $extra['rating_counts'] = $counts;
        $this->wpdb->update($this->tableProducts, ['extra_props' => json_encode($extra, JSON_UNESCAPED_UNICODE)], ['id' => $productId]);
    }

    public function update_review_count($product): void
    {
        $productId = $product->get_id();
        $count = (int)$product->get_review_count('edit');
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableProducts} WHERE id = %d", $productId), ARRAY_A);
        if (!$row) return;
        $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
        $extra['review_count'] = $count;
        $this->wpdb->update($this->tableProducts, ['extra_props' => json_encode($extra, JSON_UNESCAPED_UNICODE)], ['id' => $productId]);
    }

    public function search_products($term, $type = '', $include_variations = false, $all_statuses = false, $limit = null, $include = null, $exclude = null): array
    {
        $like = '%' . $this->wpdb->esc_like(trim((string)$term)) . '%';
        $sql = "SELECT id FROM {$this->tableProducts} WHERE (title LIKE %s OR sku LIKE %s) AND deleted_at IS NULL";
        $params = [$like, $like];

        if ($type !== '') {
            $sql .= " AND type = %s";
            $params[] = $type;
        }

        $sql .= " LIMIT %d";
        $params[] = $limit ?: 20;

        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql, ...$params)));
    }

    public function refresh_product_lookup_table(int $product_id): void
    {
        // Synced to catalog projection
    }

    public function delete_from_lookup_table($id, $table)
    {
        $id = absint($id);
        if (empty($id) || empty($table)) {
            return false;
        }
        if (isset($this->wpdb->$table)) {
            $this->wpdb->delete($this->wpdb->$table, ['product_id' => $id]);
        }
        wp_cache_delete('lookup_table', 'object_' . $id);
        return true;
    }

    public function reviews_allowed_query_where($where, $wp_query)
    {
        if (isset($wp_query->query_vars['reviews_allowed']) && is_bool($wp_query->query_vars['reviews_allowed'])) {
            $open = $wp_query->query_vars['reviews_allowed'] ? 'open' : 'closed';
            $where .= " AND {$this->wpdb->posts}.comment_status = '{$open}'";
        }
        return $where;
    }

    public function parse_date_for_wp_query($query_var, $key, $wp_query_args = [])
    {
        return parent::parse_date_for_wp_query($query_var, $key, $wp_query_args);
    }

    /* -------------------------------------------------------------------------- */
    /* Helpers                                                                    */
    /* -------------------------------------------------------------------------- */

    protected function save_meta(&$product): void
    {
        $metaData = $product->get_meta_data();
        foreach ($metaData as $meta) {
            $data = $meta->get_data();
            $this->mediaRepo->addMeta($product->get_id(), 'product', $data['key'], $data['value'], true);
        }
    }

    protected function save_downloads(&$product): void
    {
        $downloads = $product->get_downloads();
        $payload = [];
        foreach ($downloads as $d) {
            $payload[] = [
                'download_id' => $d->get_id(),
                'name'        => $d->get_name(),
                'file_url'    => $d->get_file(),
            ];
        }
        if (!empty($payload)) {
            $this->mediaRepo->setDownloads($product->get_id(), $payload);
        }
    }

    protected function read_downloads(&$product): void
    {
        $downloads = $this->mediaRepo->getDownloads($product->get_id());
        $wcDownloads = [];
        foreach ($downloads as $d) {
            $wcDownload = new WC_Product_Download();
            $wcDownload->set_id($d['download_id']);
            $wcDownload->set_name($d['name']);
            $wcDownload->set_file($d['file_url']);
            $wcDownloads[$d['download_id']] = $wcDownload;
        }
        $product->set_downloads($wcDownloads);
    }
}

/**
 * Variable product data store adapter.
 */
class ProductVariableDataStore extends ProductDataStore
{
    public function read(&$product): void
    {
        parent::read($product);
        $this->read_children($product);
    }

    public function read_price_data(&$product, $for_display = false): array
    {
        $prices = [
            'price'         => [],
            'regular_price' => [],
            'sale_price'    => [],
        ];
        $children = $product->get_children();
        if (empty($children)) {
            return $prices;
        }

        foreach ($children as $childId) {
            $child = wc_get_product($childId);
            if (!$child) {
                continue;
            }
            $reg = $child->get_regular_price();
            $sale = $child->get_sale_price();
            $p = $child->get_price();

            if ($p !== '' && $p !== null) {
                $prices['price'][$childId] = wc_format_decimal($p, wc_get_price_decimals());
            }
            if ($reg !== '' && $reg !== null) {
                $prices['regular_price'][$childId] = wc_format_decimal($reg, wc_get_price_decimals());
            }
            if ($sale !== '' && $sale !== null) {
                $prices['sale_price'][$childId] = wc_format_decimal($sale, wc_get_price_decimals());
            }
        }

        return $prices;
    }
}

/**
 * Variation product data store adapter.
 */
class ProductVariationDataStore extends ProductDataStore
{
    public function create(&$product): void
    {
        $parentId = (int)$product->get_parent_id();
        if ($parentId <= 0) {
            throw new InvalidArgumentException("Variation requires a valid parent ID");
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $variantId = $product->get_id();
        if ($variantId <= 0) {
            $allocator = new PostIdAllocator($this->wpdb);
            $variantId = $allocator->allocateShell('product_variation');
        }

        $sku = $product->get_sku('edit') ?: null;
        if (!empty($sku)) {
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->tableSkuRegistry} (normalized_sku, object_id, object_kind, original_sku)
                 VALUES (%s, %d, 'variation', %s)
                 ON DUPLICATE KEY UPDATE normalized_sku = VALUES(normalized_sku), object_kind = VALUES(object_kind), original_sku = VALUES(original_sku)",
                $sku, $variantId, $sku
            ));
        }

        $attrs = $product->get_attributes('edit') ?: [];
        if (!empty($attrs) && is_array($attrs)) {
            ksort($attrs);
            $canonicalAttrs = json_encode($attrs, JSON_UNESCAPED_UNICODE);
        } else {
            $canonicalAttrs = 'var:' . $variantId;
        }
        $combHex = hash('sha256', $canonicalAttrs);

        $regularPrice = $product->get_regular_price('edit') !== '' ? $product->get_regular_price('edit') : null;
        $salePrice = $product->get_sale_price('edit') !== '' ? $product->get_sale_price('edit') : null;

        $extraProps = ['attributes' => $attrs];

        $sql = "INSERT INTO {$this->tableVariants}
                (id, product_id, status, sku, combination_key, active_combination_key, regular_price, sale_price, tax_class, virtual, downloadable, weight, length, width, height, position, description, stock_owner_id, extra_props, version, created_at, updated_at, deleted_at)
                VALUES (%d, %d, %s, %s, UNHEX(%s), UNHEX(%s), %s, %s, %s, %d, %d, %s, %s, %s, %s, %d, %s, %d, %s, 1, %s, %s, NULL)
                ON DUPLICATE KEY UPDATE
                status = VALUES(status), sku = VALUES(sku), combination_key = VALUES(combination_key), active_combination_key = VALUES(active_combination_key),
                regular_price = VALUES(regular_price), sale_price = VALUES(sale_price), tax_class = VALUES(tax_class),
                virtual = VALUES(virtual), downloadable = VALUES(downloadable), weight = VALUES(weight), length = VALUES(length),
                width = VALUES(width), height = VALUES(height), description = VALUES(description), position = VALUES(position),
                extra_props = VALUES(extra_props), version = version + 1, updated_at = VALUES(updated_at), deleted_at = NULL";

        $manageStock = $product->get_manage_stock('edit') ? 1 : 0;
        $stockOwnerId = $manageStock ? $variantId : $parentId;

        $this->wpdb->query($this->wpdb->prepare(
            $sql,
            $variantId,
            $parentId,
            $product->get_status('edit') ?: 'publish',
            $sku,
            $combHex,
            $combHex,
            $regularPrice,
            $salePrice,
            $product->get_tax_class('edit') ?: null,
            $product->get_virtual('edit') ? 1 : 0,
            $product->get_downloadable('edit') ? 1 : 0,
            $product->get_weight('edit') !== '' ? $product->get_weight('edit') : null,
            $product->get_length('edit') !== '' ? $product->get_length('edit') : null,
            $product->get_width('edit') !== '' ? $product->get_width('edit') : null,
            $product->get_height('edit') !== '' ? $product->get_height('edit') : null,
            (int)$product->get_menu_order('edit'),
            $product->get_description('edit') ?: '',
            $stockOwnerId,
            json_encode($extraProps, JSON_UNESCAPED_UNICODE),
            $now,
            $now
        ));

        // Inventory
        $stockQty = $product->get_stock_quantity('edit') !== null ? (string)$product->get_stock_quantity('edit') : null;
        $stockStatus = $product->get_stock_status('edit') ?: 'instock';

        $this->wpdb->replace(
            $this->tableInventory,
            [
                'owner_id'         => $variantId,
                'manage_stock'     => $manageStock,
                'quantity'         => $stockQty,
                'backorders'       => $product->get_backorders('edit') ?: 'no',
                'stock_status'     => $stockStatus,
                'low_stock_amount' => null,
                'version'          => 1,
                'updated_at'       => $now,
            ]
        );

        $product->set_id($variantId);
        $this->save_meta($product);
        $this->save_downloads($product);

        $product->apply_changes();
        $product->set_object_read(true);
        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->invalidateProductCache($parentId);
    }

    public function read(&$product): void
    {
        $variantId = $product->get_id();
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableVariants} WHERE id = %d",
            $variantId
        ), ARRAY_A);

        if (!$row) {
            throw new Exception(esc_html__('Invalid product variation.', 'woocommerce'));
        }

        $inv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $variantId
        ), ARRAY_A);

        $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];

        $product->set_props([
            'parent_id'         => (int)$row['product_id'],
            'status'            => $row['status'],
            'sku'               => $row['sku'] ?? '',
            'regular_price'     => $row['regular_price'] !== null ? (string)$row['regular_price'] : '',
            'sale_price'        => $row['sale_price'] !== null ? (string)$row['sale_price'] : '',
            'price'             => ($row['sale_price'] !== null && $row['sale_price'] !== '') ? (string)$row['sale_price'] : ($row['regular_price'] !== null ? (string)$row['regular_price'] : ''),
            'virtual'           => !empty($row['virtual']),
            'downloadable'      => !empty($row['downloadable']),
            'tax_class'         => $row['tax_class'] ?? '',
            'weight'            => $row['weight'] !== null ? (string)$row['weight'] : '',
            'length'            => $row['length'] !== null ? (string)$row['length'] : '',
            'width'             => $row['width'] !== null ? (string)$row['width'] : '',
            'height'            => $row['height'] !== null ? (string)$row['height'] : '',
            'manage_stock'      => !empty($inv['manage_stock']),
            'stock_quantity'    => $inv && $inv['quantity'] !== null ? (float)$inv['quantity'] : null,
            'stock_status'      => $inv['stock_status'] ?? 'instock',
            'backorders'        => $inv['backorders'] ?? 'no',
            'menu_order'        => (int)($row['position'] ?? 0),
            'description'       => $row['description'] ?? '',
            'date_created'      => $row['created_at'],
            'date_modified'     => $row['updated_at'],
        ]);

        if (!empty($extra['attributes']) && is_array($extra['attributes'])) {
            $product->set_attributes($extra['attributes']);
        }

        $this->read_meta($product);
        $this->read_downloads($product);
        $product->set_object_read(true);
    }

    public function update(&$product): void
    {
        $variantId = $product->get_id();
        $changes = $product->get_changes();
        $now = gmdate('Y-m-d H:i:s.u');

        $payload = ['updated_at' => $now];
        if (isset($changes['status'])) $payload['status'] = $changes['status'];
        if (array_key_exists('sku', $changes)) {
            $payload['sku'] = $changes['sku'] ?: null;
            if ($payload['sku']) {
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$this->tableSkuRegistry} (normalized_sku, object_id, object_kind, original_sku)
                     VALUES (%s, %d, 'variation', %s)
                     ON DUPLICATE KEY UPDATE normalized_sku = VALUES(normalized_sku), object_kind = VALUES(object_kind), original_sku = VALUES(original_sku)",
                    $payload['sku'], $variantId, $payload['sku']
                ));
            } else {
                $this->wpdb->delete($this->tableSkuRegistry, ['object_id' => $variantId]);
            }
        }
        if (array_key_exists('regular_price', $changes)) $payload['regular_price'] = $changes['regular_price'] !== '' ? $changes['regular_price'] : null;
        if (array_key_exists('sale_price', $changes)) $payload['sale_price'] = $changes['sale_price'] !== '' ? $changes['sale_price'] : null;
        if (isset($changes['virtual'])) $payload['virtual'] = $changes['virtual'] ? 1 : 0;
        if (isset($changes['downloadable'])) $payload['downloadable'] = $changes['downloadable'] ? 1 : 0;
        if (isset($changes['tax_class'])) $payload['tax_class'] = $changes['tax_class'];
        if (isset($changes['weight'])) $payload['weight'] = $changes['weight'] !== '' ? $changes['weight'] : null;
        if (isset($changes['length'])) $payload['length'] = $changes['length'] !== '' ? $changes['length'] : null;
        if (isset($changes['width'])) $payload['width'] = $changes['width'] !== '' ? $changes['width'] : null;
        if (isset($changes['height'])) $payload['height'] = $changes['height'] !== '' ? $changes['height'] : null;
        if (isset($changes['menu_order'])) $payload['position'] = (int)$changes['menu_order'];
        if (isset($changes['description'])) $payload['description'] = $changes['description'];

        if (isset($changes['attributes'])) {
            $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableVariants} WHERE id = %d", $variantId), ARRAY_A);
            $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
            $extra['attributes'] = $changes['attributes'];
            $payload['extra_props'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
        }

        if (!empty($payload)) {
            $this->wpdb->update($this->tableVariants, $payload, ['id' => $variantId]);
        }

        // Inventory
        if (isset($changes['manage_stock']) || array_key_exists('stock_quantity', $changes) || isset($changes['stock_status']) || isset($changes['backorders'])) {
            $invPayload = ['updated_at' => $now];
            if (isset($changes['manage_stock'])) {
                $isManaging = $changes['manage_stock'] ? 1 : 0;
                $invPayload['manage_stock'] = $isManaging;
                $newOwnerId = $isManaging ? $variantId : (int)$product->get_parent_id();
                $this->wpdb->update($this->tableVariants, ['stock_owner_id' => $newOwnerId], ['id' => $variantId]);
            }
            if (array_key_exists('stock_quantity', $changes)) $invPayload['quantity'] = $changes['stock_quantity'] !== null ? (string)$changes['stock_quantity'] : null;
            if (isset($changes['stock_status'])) $invPayload['stock_status'] = $changes['stock_status'];
            if (isset($changes['backorders'])) $invPayload['backorders'] = $changes['backorders'];
            $this->wpdb->update($this->tableInventory, $invPayload, ['owner_id' => $variantId]);
        }

        $this->save_meta($product);
        $this->save_downloads($product);

        $product->apply_changes();
        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->invalidateProductCache((int)$product->get_parent_id());
    }

    public function delete(&$product, $args = []): void
    {
        $variantId = $product->get_id();
        $parentId = (int)$product->get_parent_id();
        $force = !empty($args['force_delete']);

        if ($force) {
            $this->wpdb->delete($this->tableSkuRegistry, ['object_id' => $variantId]);
            $this->wpdb->delete($this->tableInventory, ['owner_id' => $variantId]);
            $this->wpdb->delete($this->tableVariants, ['id' => $variantId]);
        } else {
            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->update(
                $this->tableVariants,
                [
                    'status'                 => 'trash',
                    'deleted_at'             => $now,
                    'active_combination_key' => null,
                    'updated_at'             => $now,
                ],
                ['id' => $variantId]
            );
        }

        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->invalidateProductCache($parentId > 0 ? $parentId : $variantId);
        $product->set_id(0);
    }
}
