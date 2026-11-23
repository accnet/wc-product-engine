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
use WPE\Compatibility\WooDataStore\PostShellBridge;
use WPE\Application\Variant\EffectivePriceResolver;

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

    /**
     * Evict any primed runtime hydration bundle for a product (P028).
     */
    public static function evictRuntimeCache(int $productId): void
    {
        \WPE\Support\AuthorityResolver::evictObject($productId);
    }

    /**
     * Bulk-preload native product aggregates for archive loops in O(1) SQL queries (P028).
     *
     * @param int[] $productIds
     */
    public static function primeProducts(array $productIds, ?\wpdb $db = null): void
    {
        \WPE\Support\AuthorityResolver::ensureRuntimeCacheGroup();
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || !function_exists('wp_cache_set')) {
            return;
        }

        $wpdb = $db ?? $GLOBALS['wpdb'];
        $prefix = $wpdb->prefix;
        $inList = \WPE\Support\Sql::intList($ids);

        // 1. Products
        $prodById = [];
        $prodRows = $wpdb->get_results(
            "SELECT * FROM {$prefix}wpe_products WHERE id IN ({$inList}) AND deleted_at IS NULL",
            ARRAY_A
        ) ?: [];
        foreach ($prodRows as $pr) {
            $prodById[(int)$pr['id']] = $pr;
        }
        if ($prodById === []) {
            return;
        }

        $validIds = array_keys($prodById);
        $validIn = \WPE\Support\Sql::intList($validIds);

        // 2. Inventory
        $invById = [];
        $invRows = $wpdb->get_results(
            "SELECT * FROM {$prefix}wpe_inventory WHERE owner_id IN ({$validIn})",
            ARRAY_A
        ) ?: [];
        foreach ($invRows as $ir) {
            $invById[(int)$ir['owner_id']] = $ir;
        }

        // 3. Featured + Gallery Media
        $featuredById = [];
        $galleryById = [];
        $mediaRows = $wpdb->get_results(
            "SELECT pm.product_id AS wpe_pid, pm.role AS wpe_role, pm.position AS wpe_pos, pm.alt_override, a.*
             FROM {$prefix}wpe_product_media pm
             JOIN {$prefix}wpe_assets a ON a.id = pm.asset_id
             WHERE pm.product_id IN ({$validIn})
             ORDER BY pm.product_id ASC, pm.role ASC, pm.position ASC",
            ARRAY_A
        ) ?: [];
        foreach ($mediaRows as $mr) {
            $pid = (int)$mr['wpe_pid'];
            $role = (string)$mr['wpe_role'];
            unset($mr['wpe_pid'], $mr['wpe_role'], $mr['wpe_pos']);
            if ($role === 'featured') {
                $featuredById[$pid] ??= $mr;
            } elseif ($role === 'gallery') {
                $galleryById[$pid][] = $mr;
            }
        }

        // 4. Upsell + Cross-sell Links
        $upsellsById = [];
        $crosssellsById = [];
        $linkRows = $wpdb->get_results(
            "SELECT product_id, target_id, kind FROM {$prefix}wpe_product_links
             WHERE product_id IN ({$validIn})
             ORDER BY product_id ASC, position ASC",
            ARRAY_A
        ) ?: [];
        foreach ($linkRows as $lr) {
            $pid = (int)$lr['product_id'];
            if ($lr['kind'] === 'upsell') {
                $upsellsById[$pid][] = ['target_id' => (int)$lr['target_id']];
            } elseif ($lr['kind'] === 'crosssell') {
                $crosssellsById[$pid][] = ['target_id' => (int)$lr['target_id']];
            }
        }

        // 5. Custom Meta
        $metaById = [];
        $metaRows = $wpdb->get_results(
            "SELECT object_id, meta_key, meta_value FROM {$prefix}wpe_meta
             WHERE object_id IN ({$validIn})
             ORDER BY meta_id ASC",
            ARRAY_A
        ) ?: [];
        foreach ($metaRows as $mr) {
            $pid = (int)$mr['object_id'];
            $metaById[$pid][$mr['meta_key']][] = maybe_unserialize($mr['meta_value']);
        }

        // 6. Downloads
        $downloadsById = [];
        $dlRows = $wpdb->get_results(
            "SELECT object_id, download_id, name, file_url, position FROM {$prefix}wpe_downloads
             WHERE object_id IN ({$validIn})
             ORDER BY object_id ASC, position ASC",
            ARRAY_A
        ) ?: [];
        foreach ($dlRows as $dr) {
            $downloadsById[(int)$dr['object_id']][] = $dr;
        }

        // 7. Options & Option Values (covers both readNativeAttributes and read_default_attributes)
        $optionsById = [];
        $optRows = $wpdb->get_results(
            "SELECT o.product_id, o.id AS option_id, o.name, o.`key`, o.taxonomy, o.position, o.visible,
                    o.used_for_variation, o.default_value_id,
                    ov.id AS value_id, ov.label, ov.value_key, ov.term_id
             FROM {$prefix}wpe_options o
             LEFT JOIN {$prefix}wpe_option_values ov ON ov.option_id = o.id
             WHERE o.product_id IN ({$validIn})
             ORDER BY o.product_id ASC, o.position ASC, o.id ASC, ov.position ASC, ov.id ASC",
            ARRAY_A
        ) ?: [];
        foreach ($optRows as $or) {
            $optionsById[(int)$or['product_id']][] = $or;
        }

        // 8. Child Variations (covers read_children, lowestVariationPrice, and read_price_data)
        $variantsById = [];
        $varIds = [];
        foreach ($validIds as $pid) {
            if (($prodById[$pid]['type'] ?? '') === 'variable') {
                $varIds[] = $pid;
            }
        }
        if ($varIds !== []) {
            $varIn = \WPE\Support\Sql::intList($varIds);
            $vRows = $wpdb->get_results(
                "SELECT id, product_id, status, regular_price, sale_price, sale_from, sale_to, tax_class
                 FROM {$prefix}wpe_variants
                 WHERE product_id IN ({$varIn}) AND deleted_at IS NULL
                 ORDER BY product_id ASC, position ASC, id ASC",
                ARRAY_A
            ) ?: [];
            foreach ($vRows as $vr) {
                $variantsById[(int)$vr['product_id']][] = $vr;
            }
        }

        foreach ($validIds as $pid) {
            $bundle = [
                'product'      => $prodById[$pid],
                'inventory'    => $invById[$pid] ?? null,
                'featured'     => $featuredById[$pid] ?? null,
                'gallery'      => $galleryById[$pid] ?? [],
                'upsells'      => $upsellsById[$pid] ?? [],
                'crosssells'   => $crosssellsById[$pid] ?? [],
                'meta_map'     => $metaById[$pid] ?? [],
                'downloads'    => $downloadsById[$pid] ?? [],
                'option_rows'  => $optionsById[$pid] ?? [],
                'variant_rows' => $variantsById[$pid] ?? [],
            ];
            wp_cache_set('prod_bundle_' . $pid, $bundle, \WPE\Support\AuthorityResolver::CACHE_GROUP);
        }
    }

    protected function getPrimedBundle(int $productId): ?array
    {
        if ($productId <= 0 || !function_exists('wp_cache_get')) {
            return null;
        }
        $bundle = wp_cache_get('prod_bundle_' . $productId, \WPE\Support\AuthorityResolver::CACHE_GROUP);
        return is_array($bundle) ? $bundle : null;
    }

    /* -------------------------------------------------------------------------- */
    /* Core CRUD Lifecycle (WC_Object_Data_Store_Interface)                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Create product in native storage.
     */
    public function create(&$product): void
    {
        LegacyOnlyProps::assertNativeSavable($product);
        $payload = [
            'name'               => $product->get_name('edit'),
            'slug'               => $product->get_slug('edit'),
            'type'               => $product->get_type(),
            'status'             => $product->get_status('edit') ?: 'publish',
            'catalog_visibility' => $product->get_catalog_visibility('edit') ?: 'visible',
            'sku'                => $product->get_sku('edit') ?: null,
            'regular_price'      => $product->get_regular_price('edit') !== '' ? $product->get_regular_price('edit') : null,
            'sale_price'         => $product->get_sale_price('edit') !== '' ? $product->get_sale_price('edit') : null,
            'sale_from'          => self::utcDateString($product->get_date_on_sale_from('edit')),
            'sale_to'            => self::utcDateString($product->get_date_on_sale_to('edit')),
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

        // 03 §5 "Cấp ID và shell": after the canonical commit, project the preallocated
        // auto-draft shell so WordPress discovery (status, title, slug, dates) matches the product.
        $this->projectPostShell($productId, $created + ['reviews_allowed' => $product->get_reviews_allowed('edit')], true);
        $this->projectDiscovery($product, null);
    }

    /**
     * Projects WordPress-owned discovery data of a committed native product: product_cat,
     * product_tag, product_type and product_visibility relationships, upsell/cross-sell links on
     * create, the wpe_product_terms registry, and the wc_product_meta_lookup row.
     *
     * Taxonomy stays WordPress-owned (ADR-002 / T13 taxonomy bridge); visibility terms follow
     * WC_Product_Data_Store_CPT::update_visibility() exactly so core tax_query consumers agree with
     * native props. Runs inside the projection guard so LegacyCapture does not treat it as a legacy write.
     *
     * @param array<string, mixed>|null $changes Changed props on update; null on create (project everything).
     */
    protected function projectDiscovery(WC_Product $product, ?array $changes): void
    {
        $productId = (int)$product->get_id();
        if ($productId <= 0 || $product->get_type() === 'variation') {
            return;
        }

        $isCreate = $changes === null;
        $changed  = static fn(string ...$keys): bool => $isCreate || array_intersect($keys, array_keys((array)$changes)) !== [];

        ProductLifecycle::wrapProjection(function () use ($product, $productId, $isCreate, $changed): void {
            $bridge     = PostShellBridge::getInstance();
            $termsMoved = false;

            if ($changed('category_ids')) {
                $categoryIds = array_values(array_filter(array_map('intval', (array)$product->get_category_ids('edit'))));
                if ($categoryIds === [] && $isCreate) {
                    $default = (int)get_option('default_product_cat', 0);
                    if ($default > 0) {
                        $categoryIds = [$default];
                    }
                }
                $bridge->setProductCategories($productId, $categoryIds);
                $termsMoved = true;
            }

            if ($changed('tag_ids')) {
                $bridge->setProductTags($productId, array_values(array_filter(array_map('intval', (array)$product->get_tag_ids('edit')))));
                $termsMoved = true;
            }

            if ($isCreate) {
                wp_set_object_terms($productId, $product->get_type(), 'product_type');
                $termsMoved = true;
            }

            if ($changed('featured', 'stock_status', 'average_rating', 'catalog_visibility')) {
                $visibility = [];
                if ($product->get_featured('edit')) {
                    $visibility[] = 'featured';
                }
                if ($product->get_stock_status('edit') === 'outofstock') {
                    $visibility[] = 'outofstock';
                }
                $rating = min(5, (int)round((float)$product->get_average_rating('edit'), 0));
                if ($rating > 0) {
                    $visibility[] = 'rated-' . $rating;
                }
                switch ($product->get_catalog_visibility('edit')) {
                    case 'hidden':
                        $visibility[] = 'exclude-from-search';
                        $visibility[] = 'exclude-from-catalog';
                        break;
                    case 'catalog':
                        $visibility[] = 'exclude-from-search';
                        break;
                    case 'search':
                        $visibility[] = 'exclude-from-catalog';
                        break;
                }
                if (!is_wp_error(wp_set_post_terms($productId, $visibility, 'product_visibility', false))) {
                    do_action('woocommerce_product_set_visibility', $productId, $product->get_catalog_visibility('edit'));
                }
                $termsMoved = true;
            }

            if ($isCreate) {
                $upsells = array_values(array_filter(array_map('intval', (array)$product->get_upsell_ids('edit'))));
                if ($upsells !== []) {
                    $this->mediaRepo->setLinks($productId, 'upsell', $upsells);
                }
                $crossSells = array_values(array_filter(array_map('intval', (array)$product->get_cross_sell_ids('edit'))));
                if ($crossSells !== []) {
                    $this->mediaRepo->setLinks($productId, 'crosssell', $crossSells);
                }
            }

            if ($termsMoved) {
                $this->syncProductTermRegistry($productId);
            }

            if ($isCreate || $changed('regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'stock_status', 'stock_quantity', 'manage_stock', 'sku', 'virtual', 'downloadable', 'tax_class', 'average_rating', 'rating_counts', 'review_count')) {
                try {
                    (new LookupTableProjector())->projectProduct($productId);
                } catch (\Throwable $e) {
                    // Lookup rows are a derived read model; `wp wpe lookup rebuild` repairs gaps.
                }
            }
        });
    }

    /**
     * Mirrors the product's WP term relationships into the wpe_product_terms registry
     * (same shape LegacyCapture writes) so rollback and mirror projection stay consistent.
     */
    protected function syncProductTermRegistry(int $productId): void
    {
        $table = $this->wpdb->prefix . 'wpe_product_terms';
        $rows  = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT tr.term_taxonomy_id, tt.taxonomy
             FROM {$this->wpdb->term_relationships} tr
             JOIN {$this->wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tr.object_id = %d",
            $productId
        ), ARRAY_A);

        $this->wpdb->delete($table, ['product_id' => $productId]);
        foreach ((array)$rows as $row) {
            $this->wpdb->replace($table, [
                'product_id'       => $productId,
                'term_taxonomy_id' => (int)$row['term_taxonomy_id'],
                'role'             => (string)$row['taxonomy'],
            ]);
        }
    }

    /**
     * Projects discovery fields of a committed native product onto its real WP post shell.
     *
     * Only shell-owned columns are written (no price/stock/business props), through
     * PostShellBridge inside the projection reentrancy guard so legacy capture hooks ignore it.
     *
     * @param array<string, mixed> $fields Canonical aggregate or changed fields (title/name, slug, status, description, short_description, menu_order).
     * @param bool $isCreate Whether the shell is being published for the first time.
     */
    protected function projectPostShell(int $productId, array $fields, bool $isCreate): void
    {
        if ($productId <= 0) {
            return;
        }

        $shell = [];
        $title = $fields['title'] ?? ($fields['name'] ?? null);
        if ($title !== null) {
            $shell['title'] = (string)$title;
        }
        $slug = isset($fields['slug']) ? (string)$fields['slug'] : '';
        if ($slug === '' && $isCreate && !empty($shell['title'])) {
            $slug = sanitize_title($shell['title']);
        }
        if ($slug !== '') {
            $shell['slug'] = $slug;
        }
        if (!empty($fields['status'])) {
            $shell['status'] = (string)$fields['status'];
        }
        foreach (['description', 'short_description', 'menu_order'] as $key) {
            if (array_key_exists($key, $fields) && $fields[$key] !== null) {
                $shell[$key] = $fields[$key];
            }
        }
        if (array_key_exists('reviews_allowed', $fields)) {
            // WooCommerce stores reviews_allowed as the post's comment_status.
            $shell['comment_status'] = $fields['reviews_allowed'] ? 'open' : 'closed';
        }
        if ($isCreate) {
            // wp_insert_post() leaves post_date_gmt zeroed for auto-draft shells.
            $currentGmt = (string)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT post_date_gmt FROM {$this->wpdb->posts} WHERE ID = %d",
                $productId
            ));
            if ($currentGmt === '' || str_starts_with($currentGmt, '0000-00-00')) {
                $shell['date_gmt'] = gmdate('Y-m-d H:i:s');
            }
        }

        if (empty($shell)) {
            return;
        }

        ProductLifecycle::wrapProjection(function () use ($productId, $shell): void {
            PostShellBridge::getInstance()->projectShell($productId, $shell);
        });
    }

    /**
     * WooCommerce price props for a stored product or variation row (audit LOG-09).
     *
     * `price` is the effective price from EffectivePriceResolver: the sale price only inside its
     * sale window and only when it is not above the regular price, otherwise the regular price.
     * The sale window is exposed as date_on_sale_from/to so WC_Product::is_on_sale() agrees.
     * Previously `price` was the sale price whenever one was stored, and the window was not read.
     *
     * @return array<string, mixed>
     */
    protected static function priceProps(array $row): array
    {
        $resolved = EffectivePriceResolver::resolve($row);
        return [
            'regular_price'     => $row['regular_price'] !== null ? (string)$row['regular_price'] : '',
            'sale_price'        => $row['sale_price'] !== null ? (string)$row['sale_price'] : '',
            'price'             => $resolved['active_price'] ?? '',
            'date_on_sale_from' => self::utcTimestamp($row['sale_from'] ?? null),
            'date_on_sale_to'   => self::utcTimestamp($row['sale_to'] ?? null),
        ];
    }

    /**
     * Lowest effective price of a variable product's published variations, '' when none is priced.
     *
     * Same rules as EffectivePriceResolver::resolve() in one aggregate query, so loading a parent
     * with thousands of variations does not hydrate them. Stored variation columns already hold
     * materialized shared prices.
     */
    protected function lowestVariationPrice(int $productId): string
    {
        $bundle = $this->getPrimedBundle($productId);
        if ($bundle !== null) {
            $lowest = null;
            foreach ($bundle['variant_rows'] as $vr) {
                if (($vr['status'] ?? '') !== 'publish') {
                    continue;
                }
                $eff = EffectivePriceResolver::resolve($vr)['active_price'] ?? '';
                if ($eff === '') {
                    continue;
                }
                if ($lowest === null || (float)$eff < (float)$lowest) {
                    $lowest = (string)$eff;
                }
            }
            return $lowest ?? '';
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $min = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT MIN(CASE
                        WHEN sale_price IS NOT NULL
                         AND (regular_price IS NULL OR sale_price <= regular_price)
                         AND (sale_from IS NULL OR sale_from <= %s)
                         AND (sale_to IS NULL OR sale_to >= %s)
                        THEN sale_price
                        ELSE regular_price
                    END)
             FROM {$this->tableVariants}
             WHERE product_id = %d AND status = 'publish' AND deleted_at IS NULL",
            $now,
            $now,
            $productId
        ));
        return $min === null ? '' : (string)$min;
    }

    /** UTC timestamp for a stored DATETIME column, or null. */
    protected static function utcTimestamp($value): ?int
    {
        if ($value === null || $value === '' || str_starts_with((string)$value, '0000-00-00')) {
            return null;
        }
        $ts = strtotime((string)$value . ' UTC');
        return $ts === false ? null : $ts;
    }

    /** Stored DATETIME (UTC) for a WooCommerce date prop, or null. */
    protected static function utcDateString($date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return gmdate('Y-m-d H:i:s', $date->getTimestamp());
        }
        if (is_int($date)) {
            return gmdate('Y-m-d H:i:s', $date);
        }
        return null;
    }

    /**
     * Read product data from native storage without fallback to postmeta (R40).
     */
    public function read(&$product): void
    {
        $productId = $product->get_id();
        $bundle = $this->getPrimedBundle($productId);

        if ($bundle !== null) {
            $data       = $bundle['product'];
            $inv        = $bundle['inventory'];
            $featured   = $bundle['featured'];
            $gallery    = $bundle['gallery'];
            $upsells    = $bundle['upsells'];
            $crosssells = $bundle['crosssells'];
        } else {
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

            $featured = null;
            $gallery = [];
            $mediaRows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT pm.role AS wpe_role, pm.position AS wpe_pos, pm.alt_override, a.*
                 FROM {$this->wpdb->prefix}wpe_product_media pm
                 JOIN {$this->wpdb->prefix}wpe_assets a ON a.id = pm.asset_id
                 WHERE pm.product_id = %d
                 ORDER BY pm.role ASC, pm.position ASC",
                $productId
            ), ARRAY_A) ?: [];
            foreach ($mediaRows as $mr) {
                $role = (string)$mr['wpe_role'];
                unset($mr['wpe_role'], $mr['wpe_pos']);
                if ($role === 'featured') {
                    $featured ??= $mr;
                } elseif ($role === 'gallery') {
                    $gallery[] = $mr;
                }
            }

            $upsells = [];
            $crosssells = [];
            $linkRows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT target_id, kind FROM {$this->tableProductLinks} WHERE product_id = %d ORDER BY position ASC",
                $productId
            ), ARRAY_A) ?: [];
            foreach ($linkRows as $lr) {
                if ($lr['kind'] === 'upsell') {
                    $upsells[] = ['target_id' => (int)$lr['target_id']];
                } elseif ($lr['kind'] === 'crosssell') {
                    $crosssells[] = ['target_id' => (int)$lr['target_id']];
                }
            }
        }

        $extra = json_decode($data['extra_props'] ?? '{}', true) ?: [];

        $featuredImageId = 0;
        if ($featured) {
            $featuredImageId = !empty($featured['attachment_id']) ? (int)$featured['attachment_id'] : (int)($featured['id'] ?? 0);
        }
        $galleryImageIds = [];
        foreach ($gallery as $gItem) {
            $gId = !empty($gItem['attachment_id']) ? (int)$gItem['attachment_id'] : (int)($gItem['id'] ?? 0);
            if ($gId > 0) {
                $galleryImageIds[] = $gId;
            }
        }

        $priceProps = self::priceProps($data);
        if ($product instanceof WC_Product_Variable) {
            // A variable parent has no price of its own; WooCommerce exposes the lowest active
            // price of its published variations, as _price does under the CPT store (audit LOG-09).
            $priceProps['price'] = $this->lowestVariationPrice($productId);
        }

        $product->set_props($priceProps + [
            'name'               => $data['title'],
            'slug'               => $data['slug'],
            'status'             => $data['status'],
            'catalog_visibility' => $data['catalog_visibility'] ?? 'visible',
            'sku'                => $data['sku'] ?? '',
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
            'image_id'           => $featuredImageId,
            'gallery_image_ids'  => $galleryImageIds,
            'upsell_ids'         => array_map('intval', array_column($upsells, 'target_id')),
            'cross_sell_ids'     => array_map('intval', array_column($crosssells, 'target_id')),
            'average_rating'     => (float)($extra['average_rating'] ?? 0.0),
            'review_count'       => (int)($extra['review_count'] ?? 0),
            'rating_counts'      => $extra['rating_counts'] ?? [],
            'date_created'       => $data['created_at'],
            'date_modified'      => $data['updated_at'],
        ]);

        $product->set_attributes($this->readNativeAttributes($productId));

        // Taxonomy is WordPress-owned (T13 bridge); expose it through CRUD like WC_Product_Data_Store_CPT.
        $shellPost = get_post($productId);
        $product->set_props([
            'category_ids'    => $this->get_term_ids($product, 'product_cat'),
            'tag_ids'         => $this->get_term_ids($product, 'product_tag'),
            'reviews_allowed' => $shellPost instanceof \WP_Post ? $shellPost->comment_status === 'open' : true,
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
     * WooCommerce attribute name for a native option: the attribute taxonomy when the option is
     * linked to a registered one, otherwise the option's display name (custom attribute).
     */
    protected static function attributeNameFor(array $option): string
    {
        $taxonomy = (string)($option['taxonomy'] ?? '');
        if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
            return $taxonomy;
        }
        return (string)(($option['name'] ?? '') !== '' ? $option['name'] : ($option['key'] ?? ''));
    }

    /**
     * WooCommerce attribute value for a native option value: the term slug for taxonomy
     * attributes, the label for custom attributes (as WC_Product_Data_Store_CPT stores them).
     */
    protected static function attributeValueFor(array $row): string
    {
        $taxonomy = (string)($row['taxonomy'] ?? '');
        if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $termId = (int)($row['term_id'] ?? 0);
            if ($termId > 0) {
                $term = get_term($termId, $taxonomy);
                if ($term instanceof \WP_Term) {
                    return (string)$term->slug;
                }
            }
            return (string)($row['value_key'] ?? '');
        }
        return (string)($row['label'] ?? '');
    }

    /**
     * Build the product's WC_Product_Attribute list from wpe_options / wpe_option_values.
     *
     * @return array<string, \WC_Product_Attribute>
     */
    protected function readNativeAttributes(int $productId): array
    {
        $bundle = $this->getPrimedBundle($productId);
        if ($bundle !== null) {
            $rows = $bundle['option_rows'];
        } else {
            $prefix = $this->wpdb->prefix;
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT o.id AS option_id, o.name, o.`key`, o.taxonomy, o.position, o.visible, o.used_for_variation,
                        ov.id AS value_id, ov.label, ov.value_key, ov.term_id
                 FROM {$prefix}wpe_options o
                 LEFT JOIN {$prefix}wpe_option_values ov ON ov.option_id = o.id
                 WHERE o.product_id = %d
                 ORDER BY o.position ASC, o.id ASC, ov.position ASC, ov.id ASC",
                $productId
            ), ARRAY_A);
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $optionId = (int)$row['option_id'];
            if (!isset($grouped[$optionId])) {
                $grouped[$optionId] = ['option' => $row, 'values' => []];
            }
            if ($row['value_id'] !== null) {
                $grouped[$optionId]['values'][] = $row;
            }
        }

        $attributes = [];
        foreach ($grouped as $optionId => $group) {
            $option = $group['option'];
            $name = self::attributeNameFor($option);
            if ($name === '') {
                continue;
            }
            $isTaxonomy = $name === (string)($option['taxonomy'] ?? '');

            $values = [];
            foreach ($group['values'] as $valueRow) {
                if ($isTaxonomy) {
                    $termId = (int)($valueRow['term_id'] ?? 0);
                    if ($termId > 0) {
                        $values[] = $termId;
                    }
                } elseif ((string)$valueRow['label'] !== '') {
                    $values[] = (string)$valueRow['label'];
                }
            }

            $attribute = new \WC_Product_Attribute();
            $attribute->set_id($isTaxonomy ? (int)wc_attribute_taxonomy_id_by_name($name) : 0);
            $attribute->set_name($name);
            $attribute->set_options($values);
            $attribute->set_position((int)$option['position']);
            $attribute->set_visible(!empty($option['visible']));
            $attribute->set_variation(!empty($option['used_for_variation']));
            $attributes[sanitize_title($name)] = $attribute;
        }

        return $attributes;
    }

    /**
     * Variation attributes (attribute key => value, '' for "any") from wpe_variant_values.
     *
     * @return array<string, string>
     */
    protected function readNativeVariationAttributes(int $variantId): array
    {
        $prefix = $this->wpdb->prefix;
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.name, o.`key`, o.taxonomy, vv.is_any, ov.label, ov.value_key, ov.term_id
             FROM {$prefix}wpe_variant_values vv
             INNER JOIN {$prefix}wpe_options o ON o.id = vv.option_id
             LEFT JOIN {$prefix}wpe_option_values ov ON ov.id = vv.value_id AND ov.option_id = vv.option_id
             WHERE vv.variant_id = %d AND o.used_for_variation = 1
             ORDER BY o.position ASC, o.id ASC",
            $variantId
        ), ARRAY_A);

        $attributes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = self::attributeNameFor($row);
            if ($name === '') {
                continue;
            }
            $isAny = !empty($row['is_any']) || $row['label'] === null;
            $attributes[sanitize_title($name)] = $isAny ? '' : self::attributeValueFor($row);
        }
        return $attributes;
    }

    /**
     * Values each variation attribute takes across the product's variations, keyed by attribute
     * name, mirroring WC_Product_Variable_Data_Store_CPT::read_variation_attributes() without
     * reading legacy variation postmeta.
     *
     * @return array<string, string[]>
     */
    public function read_variation_attributes(&$product): array
    {
        $productId = $product->get_id();
        $variationAttributes = array_filter(
            $product->get_attributes(),
            static fn ($attribute): bool => $attribute instanceof \WC_Product_Attribute && $attribute->get_variation()
        );
        if ($variationAttributes === []) {
            return [];
        }

        $prefix = $this->wpdb->prefix;
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.name, o.`key`, o.taxonomy, vv.is_any, ov.label, ov.value_key, ov.term_id
             FROM {$prefix}wpe_variant_values vv
             INNER JOIN {$this->tableVariants} v ON v.id = vv.variant_id
             INNER JOIN {$prefix}wpe_options o ON o.id = vv.option_id
             LEFT JOIN {$prefix}wpe_option_values ov ON ov.id = vv.value_id AND ov.option_id = vv.option_id
             WHERE v.product_id = %d AND v.deleted_at IS NULL AND o.used_for_variation = 1",
            $productId
        ), ARRAY_A);

        $assigned = [];
        $anyFor = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = sanitize_title(self::attributeNameFor($row));
            if (!empty($row['is_any']) || $row['label'] === null) {
                $anyFor[$key] = true;
                continue;
            }
            $assigned[$key][self::attributeValueFor($row)] = true;
        }

        $result = [];
        foreach ($variationAttributes as $key => $attribute) {
            $all = $attribute->is_taxonomy()
                ? array_values(array_filter(array_map(
                    static function ($termId) use ($attribute) {
                        $term = get_term((int)$termId, $attribute->get_name());
                        return $term instanceof \WP_Term ? (string)$term->slug : '';
                    },
                    $attribute->get_options()
                )))
                : array_map('strval', $attribute->get_options());

            if (!empty($anyFor[$key]) || empty($assigned[$key])) {
                $values = $all;
            } else {
                $values = array_values(array_filter($all, static fn (string $v): bool => isset($assigned[$key][$v])));
            }
            $result[$attribute->get_name()] = array_values(array_unique($values));
        }

        return $result;
    }

    /**
     * Update product in native storage.
     */
    public function update(&$product): void
    {
        LegacyOnlyProps::assertNativeSavable($product);
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
        if (array_key_exists('date_on_sale_from', $changes)) $payload['sale_from'] = self::utcDateString($changes['date_on_sale_from']);
        if (array_key_exists('date_on_sale_to', $changes)) $payload['sale_to'] = self::utcDateString($changes['date_on_sale_to']);
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

        // Keep the post shell's discovery fields in step with committed canonical changes.
        $shellChanges = array_intersect_key($payload, array_flip([
            'title', 'slug', 'status', 'description', 'short_description', 'menu_order',
        ]));
        if (array_key_exists('reviews_allowed', $changes)) {
            $shellChanges['reviews_allowed'] = (bool)$changes['reviews_allowed'];
        }
        $this->projectPostShell((int)$productId, $shellChanges, false);
        $this->projectDiscovery($product, $changes);
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
        $bundle = !$force_read ? $this->getPrimedBundle($productId) : null;
        if ($bundle !== null) {
            $rows = $bundle['variant_rows'];
        } else {
            $sql = "SELECT id, status FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC";
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $productId), ARRAY_A);
        }

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
        $bundle = $this->getPrimedBundle($productId);
        if ($bundle !== null) {
            $rows = [];
            foreach ($bundle['option_rows'] as $or) {
                if (
                    !empty($or['used_for_variation'])
                    && !empty($or['default_value_id'])
                    && $or['value_id'] !== null
                    && (int)$or['value_id'] === (int)$or['default_value_id']
                ) {
                    $rows[] = $or;
                }
            }
        } else {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT o.name, o.`key`, o.taxonomy, ov.label, ov.value_key, ov.term_id
                 FROM {$this->wpdb->prefix}wpe_options o
                 INNER JOIN {$this->wpdb->prefix}wpe_option_values ov
                     ON ov.id = o.default_value_id AND ov.option_id = o.id
                 WHERE o.product_id = %d AND o.used_for_variation = 1 AND o.default_value_id IS NOT NULL
                 ORDER BY o.position ASC",
                $productId
            ), ARRAY_A);
        }

        $defaults = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $attrKey = sanitize_title(self::attributeNameFor($r));
                $attrVal = self::attributeValueFor($r);
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
        // active_combination_key takes the column's value; $wpdb->update() would store the literal string.
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableVariants}
             SET status = 'publish', deleted_at = NULL, active_combination_key = combination_key
             WHERE product_id = %d",
            $product_id
        ));
    }

    /* -------------------------------------------------------------------------- */
    /* 39 Sealed Data Store Interface Methods                                     */
    /* -------------------------------------------------------------------------- */

    public function find_matching_product_variation($product, $match_attributes = []): int
    {
        $productId = $product->get_id();
        try {
            // WooCommerce passes posted form keys (attribute_<sanitized name>); map them to option ids.
            $selections = [];
            $byAttributeKey = [];
            foreach ($this->optRepo->getOptionsByProductId($productId) as $opt) {
                $byAttributeKey[sanitize_title(self::attributeNameFor($opt))] = (int)$opt['id'];
            }
            foreach ((array)$match_attributes as $key => $value) {
                $key = (string)$key;
                if (strpos($key, 'attribute_') === 0) {
                    $attrKey = sanitize_title(substr($key, strlen('attribute_')));
                    if (isset($byAttributeKey[$attrKey])) {
                        if ($value !== '' && $value !== null) {
                            $selections[$byAttributeKey[$attrKey]] = wp_unslash((string)$value);
                        }
                        continue;
                    }
                }
                $selections[$key] = $value;
            }
            $vector = $this->optRepo->buildCombinationVector($productId, $selections);
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
        return \WPE\Support\AuthorityResolver::getNativeProductType((int)$product_id, $this->wpdb);
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
        $objectId = (int)$object->get_id();
        $bundle = $this->getPrimedBundle($objectId);
        $metaMap = $bundle !== null ? $bundle['meta_map'] : $this->mediaRepo->getMeta($objectId);
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
        self::evictRuntimeCache((int)$object->get_id());
        return $this->mediaRepo->addMeta($object->get_id(), 'product', $meta->key, $meta->value, false);
    }

    public function update_meta(&$object, $meta)
    {
        self::evictRuntimeCache((int)$object->get_id());
        if (!empty($meta->id)) {
            $this->mediaRepo->updateMeta((int)$meta->id, $meta->value);
        } else {
            $this->mediaRepo->addMeta($object->get_id(), 'product', $meta->key, $meta->value, true);
        }
    }

    public function delete_meta(&$object, $meta)
    {
        self::evictRuntimeCache((int)$object->get_id());
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
        $productId = (int)$product->get_id();
        $bundle = $this->getPrimedBundle($productId);
        $downloads = $bundle !== null ? $bundle['downloads'] : $this->mediaRepo->getDownloads($productId);
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
        // parent::read() already calls read_children() and read_default_attributes() for WC_Product_Variable
        parent::read($product);
    }

    /**
     * Read all published child variation prices directly from wpe_variants (or primed archive bundle)
     * in O(1) SQL queries without instantiating WC_Product_Variation objects in a loop (P028 D02).
     */
    public function read_price_data(&$product, $for_display = false): array
    {
        $prices = [
            'price'         => [],
            'regular_price' => [],
            'sale_price'    => [],
        ];
        $productId = (int)$product->get_id();
        if ($productId <= 0) {
            return $prices;
        }

        $bundle = $this->getPrimedBundle($productId);
        if ($bundle !== null) {
            $rows = [];
            foreach ($bundle['variant_rows'] as $vr) {
                if (($vr['status'] ?? '') === 'publish') {
                    $rows[] = $vr;
                }
            }
        } else {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, regular_price, sale_price, sale_from, sale_to, tax_class
                 FROM {$this->tableVariants}
                 WHERE product_id = %d AND status = 'publish' AND deleted_at IS NULL
                 ORDER BY position ASC, id ASC",
                $productId
            ), ARRAY_A) ?: [];
        }

        if ($rows === []) {
            return $prices;
        }

        $decimals = wc_get_price_decimals();
        $applyDisplayTax = $for_display
            && function_exists('wc_tax_enabled')
            && wc_tax_enabled()
            && $product->is_taxable();

        foreach ($rows as $row) {
            $childId = (int)$row['id'];
            $resolved = EffectivePriceResolver::resolve($row);
            $p = $resolved['active_price'] ?? '';
            $reg = $row['regular_price'] !== null ? (string)$row['regular_price'] : '';
            $sale = ($resolved['on_sale'] ?? false) && $row['sale_price'] !== null ? (string)$row['sale_price'] : '';

            if ($applyDisplayTax && function_exists('wc_get_price_to_display')) {
                if ($p !== '') {
                    $p = (string)wc_get_price_to_display($product, ['price' => $p]);
                }
                if ($reg !== '') {
                    $reg = (string)wc_get_price_to_display($product, ['price' => $reg]);
                }
                if ($sale !== '') {
                    $sale = (string)wc_get_price_to_display($product, ['price' => $sale]);
                }
            }

            if ($p !== '') {
                $prices['price'][$childId] = wc_format_decimal($p, $decimals);
            }
            if ($reg !== '') {
                $prices['regular_price'][$childId] = wc_format_decimal($reg, $decimals);
            }
            if ($sale !== '') {
                $prices['sale_price'][$childId] = wc_format_decimal($sale, $decimals);
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
        LegacyOnlyProps::assertNativeSavable($product);
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
                (id, product_id, status, sku, combination_key, active_combination_key, regular_price, sale_price, sale_from, sale_to, tax_class, virtual, downloadable, weight, length, width, height, position, description, stock_owner_id, extra_props, version, created_at, updated_at, deleted_at)
                VALUES (%d, %d, %s, %s, UNHEX(%s), UNHEX(%s), %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), %s, %d, %d, %s, %s, %s, %s, %d, %s, %d, %s, 1, %s, %s, NULL)
                ON DUPLICATE KEY UPDATE
                status = VALUES(status), sku = VALUES(sku), combination_key = VALUES(combination_key), active_combination_key = VALUES(active_combination_key),
                regular_price = VALUES(regular_price), sale_price = VALUES(sale_price), sale_from = VALUES(sale_from), sale_to = VALUES(sale_to), tax_class = VALUES(tax_class),
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
            (string)self::utcDateString($product->get_date_on_sale_from('edit')),
            (string)self::utcDateString($product->get_date_on_sale_to('edit')),
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

        $this->projectVariationShell($variantId, $parentId, [
            'status'     => $product->get_status('edit') ?: 'publish',
            'menu_order' => (int)$product->get_menu_order('edit'),
        ], true);

        $product->apply_changes();
        $product->set_object_read(true);
        (new ProductLifecycle($this->prodRepo, null, $this->wpdb))->invalidateProductCache($parentId);
    }

    /**
     * Keep the variation's wp_posts shell in step with the native row, as LegacyMirror does:
     * post_parent, post_status, "Variation for <parent>" title and menu_order. Without it a
     * variation created through the WC API keeps the allocator's auto-draft shell with
     * post_parent 0, invisible to core lookups by parent until a mirror runs.
     *
     * @param array{status?: string, menu_order?: int} $fields
     */
    private function projectVariationShell(int $variantId, int $parentId, array $fields, bool $isCreate): void
    {
        if ($variantId <= 0) {
            return;
        }

        $shell = [];
        if (!empty($fields['status'])) {
            $shell['status'] = (string)$fields['status'];
        }
        if (array_key_exists('menu_order', $fields)) {
            $shell['menu_order'] = (int)$fields['menu_order'];
        }
        if ($isCreate && $parentId > 0) {
            $parentTitle = (string)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT title FROM {$this->tableProducts} WHERE id = %d",
                $parentId
            ));
            $shell['parent_id'] = $parentId;
            $shell['post_type'] = 'product_variation';
            $shell['title'] = 'Variation for ' . $parentTitle;
        }
        if ($shell === []) {
            return;
        }

        ProductLifecycle::wrapProjection(function () use ($variantId, $shell): void {
            PostShellBridge::getInstance()->projectShell($variantId, $shell);
        });

        if ($isCreate) {
            // The allocator's auto-draft shell has post_date_gmt zeroed; stamp the creation date.
            $nowGmt = gmdate('Y-m-d H:i:s');
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->wpdb->posts}
                 SET post_date_gmt = %s, post_date = %s
                 WHERE ID = %d AND post_date_gmt = '0000-00-00 00:00:00'",
                $nowGmt,
                get_date_from_gmt($nowGmt),
                $variantId
            ));
            clean_post_cache($variantId);
        }
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

        $product->set_props(self::priceProps($row) + [
            'parent_id'         => (int)$row['product_id'],
            'status'            => $row['status'],
            'sku'               => $row['sku'] ?? '',
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

        $attributes = $this->readNativeVariationAttributes($variantId);
        if ($attributes === [] && !empty($extra['attributes']) && is_array($extra['attributes'])) {
            $attributes = $extra['attributes'];
        }
        if ($attributes !== []) {
            $product->set_attributes($attributes);
        }

        $parentData = $this->readParentData((int)$row['product_id']);
        $product->set_parent_data($parentData);
        $product->set_props([
            'sold_individually' => wc_string_to_bool($parentData['sold_individually']),
            'tax_status'        => $parentData['tax_status'] !== '' ? $parentData['tax_status'] : 'taxable',
            'cross_sell_ids'    => $parentData['_crosssell_ids'],
            'name'              => $this->generateVariationTitle($product, $parentData['title']),
            'attribute_summary' => wc_get_formatted_variation($product, true, true),
        ]);

        $this->read_meta($product);
        $this->read_downloads($product);
        $product->set_object_read(true);
    }

    /**
     * Variation title as WC_Product_Variation_Data_Store_CPT::generate_product_title() builds it:
     * "<parent title> - <attribute values>", attributes omitted for 3+ attributes or for several
     * attributes with a multi-word name, with the same WooCommerce filters.
     */
    /**
     * Parent props a variation inherits (WC_Product_Variation::set_parent_data), read from the
     * native parent row like WC_Product_Variation_Data_Store_CPT::read_product_data() reads postmeta.
     * Customs props stay empty: they are legacy-only and refused under native authority.
     */
    protected function readParentData(int $parentId): array
    {
        $parent = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d",
            $parentId
        ), ARRAY_A) ?: [];
        $inv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $parentId
        ), ARRAY_A) ?: [];

        $featured = $parentId > 0 ? $this->mediaRepo->getFeaturedImage($parentId) : null;
        $imageId = 0;
        if ($featured) {
            $imageId = !empty($featured['attachment_id']) ? (int)$featured['attachment_id'] : (int)($featured['id'] ?? 0);
        }
        $crossSells = $parentId > 0 ? $this->mediaRepo->getLinks($parentId, 'crosssell') : [];
        $dimension = static fn ($v): string => $v !== null && $v !== '' ? (string)$v : '';

        return [
            'title'              => (string)($parent['title'] ?? ''),
            'status'             => (string)($parent['status'] ?? ''),
            'sku'                => (string)($parent['sku'] ?? ''),
            'global_unique_id'   => '',
            'manage_stock'       => !empty($inv['manage_stock']) ? 'yes' : 'no',
            'backorders'         => (string)($inv['backorders'] ?? 'no'),
            'stock_quantity'     => isset($inv['quantity']) && $inv['quantity'] !== null ? wc_stock_amount($inv['quantity']) : 0,
            'weight'             => $dimension($parent['weight'] ?? null),
            'length'             => $dimension($parent['length'] ?? null),
            'width'              => $dimension($parent['width'] ?? null),
            'height'             => $dimension($parent['height'] ?? null),
            'tax_class'          => (string)($parent['tax_class'] ?? ''),
            'purchase_note'      => (string)($parent['purchase_note'] ?? ''),
            'sold_individually'  => !empty($parent['sold_individually']) ? 'yes' : 'no',
            'tax_status'         => (string)($parent['tax_status'] ?? 'taxable'),
            '_crosssell_ids'     => array_map('intval', array_column($crossSells, 'target_id')),
            'shipping_class_id'  => (int)($parent['shipping_class_id'] ?? 0),
            'catalog_visibility' => (string)($parent['catalog_visibility'] ?? 'visible'),
            'image_id'           => $imageId,
        ];
    }

    protected function generateVariationTitle(WC_Product_Variation $product, string $titleBase): string
    {
        $attributes = (array)$product->get_attributes();
        $includeAttributes = count($attributes) < 3;
        if ($includeAttributes && count($attributes) > 1) {
            foreach (array_keys($attributes) as $name) {
                if (strpos((string)$name, '-') !== false) {
                    $includeAttributes = false;
                    break;
                }
            }
        }
        $includeAttributes = (bool)apply_filters('woocommerce_product_variation_title_include_attributes', $includeAttributes, $product);

        $separator = apply_filters('woocommerce_product_variation_title_attributes_separator', ' - ', $product);
        $titleSuffix = $includeAttributes ? wc_get_formatted_variation($product, true, false) : '';

        return (string)apply_filters(
            'woocommerce_product_variation_title',
            $titleSuffix ? $titleBase . $separator . $titleSuffix : $titleBase,
            $product,
            $titleBase,
            $titleSuffix
        );
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
        if (array_key_exists('date_on_sale_from', $changes)) $payload['sale_from'] = self::utcDateString($changes['date_on_sale_from']);
        if (array_key_exists('date_on_sale_to', $changes)) $payload['sale_to'] = self::utcDateString($changes['date_on_sale_to']);
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

        $shellFields = [];
        if (isset($changes['status'])) {
            $shellFields['status'] = (string)$changes['status'];
        }
        if (isset($changes['menu_order'])) {
            $shellFields['menu_order'] = (int)$changes['menu_order'];
        }
        $this->projectVariationShell($variantId, (int)$product->get_parent_id(), $shellFields, false);

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
            // The CPT store removes the post; without this the shell outlives the variation.
            if ($variantId > 0 && get_post_type($variantId) === 'product_variation') {
                ProductLifecycle::wrapProjection(static function () use ($variantId): void {
                    wp_delete_post($variantId, true);
                });
            }
        } else {
            $this->projectVariationShell($variantId, $parentId, ['status' => 'trash'], false);
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
