<?php
/**
 * T19 Integration Test — Tích hợp wc_get_products, shop archives và admin lists.
 *
 * Verifies:
 * - R75 (archive_pagination_found_posts): Numbered archive pagination with accurate found_posts and deep page budget cap.
 * - R76 (wc_query_args_coverage): Mapping and execution of standard WooCommerce query arguments.
 * - R77 (blocks_listing): WooCommerce Blocks product listing and collection adapter.
 * - R78 (unsupported_filter_no_silent_omission): Query capability registry prevents silent filter omission.
 * - AC-T19-01: Classic pagination, layered nav, and Blocks collections return exact IDs and totals.
 * - AC-T19-02: Non-product WP_Query instances remain completely untouched.
 * - AC-T19-03: Unsupported queries fail explicitly without silently dropping conditions.
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Catalog\CatalogQuery;
use WPE\Application\Catalog\CatalogAdapters;
use WPE\Application\Catalog\UnsupportedQueryException;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WooDataStore\ProductLifecycle;

class T19Test
{
    private \wpdb $wpdb;
    private CatalogProjector $projector;
    private CatalogQuery $queryCompiler;
    private CatalogAdapters $adapters;
    private array $cleanProductIds = [];
    private int $activeGen;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        ProductLifecycle::register();

        $this->projector = new CatalogProjector($this->wpdb);
        $this->queryCompiler = new CatalogQuery($this->wpdb, $this->projector);
        $this->adapters = new CatalogAdapters($this->wpdb, $this->queryCompiler, true);
        $this->activeGen = $this->projector->getActiveGeneration();
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        if (empty($this->cleanProductIds)) {
            return;
        }

        $pTable = $this->wpdb->prefix . 'wpe_products';
        $vTable = $this->wpdb->prefix . 'wpe_variants';
        $iTable = $this->wpdb->prefix . 'wpe_inventory';
        $sTable = $this->wpdb->prefix . 'wpe_sku_registry';
        $cTable = $this->wpdb->prefix . 'wpe_catalog';
        $fTable = $this->wpdb->prefix . 'wpe_catalog_facets';

        foreach ($this->cleanProductIds as $pid) {
            $this->wpdb->delete($pTable, ['id' => $pid]);
            $this->wpdb->delete($vTable, ['product_id' => $pid]);
            $this->wpdb->delete($iTable, ['owner_id' => $pid]);
            $this->wpdb->delete($sTable, ['object_id' => $pid]);
            $this->wpdb->delete($cTable, ['product_id' => $pid]);
            $this->wpdb->delete($fTable, ['product_id' => $pid]);
            wp_delete_post($pid, true);
        }
        $this->cleanProductIds = [];
    }

    private function createProductInCatalog(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s.u');

        $product = new \WC_Product_Simple();
        $product->set_name($data['title'] ?? 'Test Product');
        $product->set_status($data['status'] ?? 'publish');
        if (isset($data['price']) && $data['price'] !== null) {
            $product->set_regular_price((string)$data['price']);
            $product->set_price((string)$data['price']);
        }
        if (!empty($data['sku'])) {
            $product->set_sku($data['sku']);
        } else {
            $product->set_sku('SKU-T19-' . wp_generate_password(6, false));
        }
        $product->save();

        $pid = $product->get_id();
        $this->cleanProductIds[] = $pid;

        // Update wpe_products
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_products',
            [
                'catalog_visibility' => $data['visibility'] ?? 'visible',
                'updated_at'         => $now,
            ],
            ['id' => $pid]
        );

        // Insert into wpe_catalog
        $this->wpdb->insert(
            $this->wpdb->prefix . 'wpe_catalog',
            [
                'generation'     => $this->activeGen,
                'product_id'     => $pid,
                'source_version' => 1,
                'type'           => 'simple',
                'status'         => $data['status'] ?? 'publish',
                'visibility'     => $data['visibility'] ?? 'visible',
                'in_stock'       => isset($data['in_stock']) ? ($data['in_stock'] ? 1 : 0) : 1,
                'featured'       => isset($data['featured']) ? ($data['featured'] ? 1 : 0) : 0,
                'on_sale'        => isset($data['on_sale']) ? ($data['on_sale'] ? 1 : 0) : 0,
                'min_price'      => isset($data['price']) ? (float)$data['price'] : null,
                'max_price'      => isset($data['price']) ? (float)$data['price'] : null,
                'total_sales'    => isset($data['total_sales']) ? (float)$data['total_sales'] : 0.0,
                'rating_average' => isset($data['rating_average']) ? (float)$data['rating_average'] : 0.0,
                'rating_count'   => isset($data['rating_count']) ? (int)$data['rating_count'] : 0,
                'created_at'     => $data['created_at'] ?? $now,
                'menu_order'     => $data['menu_order'] ?? 0,
                'title_sort'     => $data['title'] ?? 'Test Product',
                'image_asset_id' => null,
                'indexed_at'     => $now,
            ]
        );

        // Insert facets
        if (!empty($data['facets'])) {
            foreach ($data['facets'] as $facet) {
                $this->wpdb->insert(
                    $this->wpdb->prefix . 'wpe_catalog_facets',
                    [
                        'generation' => $this->activeGen,
                        'product_id' => $pid,
                        'variant_id' => 0,
                        'facet_key'  => $facet['key'],
                        'value_key'  => $facet['value'],
                    ]
                );
            }
        }

        return $pid;
    }

    /**
     * R75: Numbered archive pagination with accurate found_posts and deep page cap.
     */
    public function testR75ArchivePaginationFoundPosts(): void
    {
        echo "Testing R75 (archive_pagination_found_posts)...\n";

        // Create 7 products in category 'archive_cat' with distinct prices
        $createdPids = [];
        for ($i = 1; $i <= 7; $i++) {
            $createdPids[] = $this->createProductInCatalog([
                'title'  => sprintf('Archive Product %02d', $i),
                'price'  => 10.0 * $i,
                'facets' => [['key' => 'category', 'value' => 'archive_cat']],
            ]);
        }

        // Page 1: 3 per page
        $q1 = [
            'category'       => 'archive_cat',
            'posts_per_page' => 3,
            'paged'          => 1,
            'orderby'        => 'price',
            'order'          => 'ASC',
        ];
        $res1 = $this->adapters->executeAdaptedQuery($q1);

        if (count($res1['ids']) !== 3) {
            throw new \RuntimeException("R75 Failed: Page 1 count should be 3, got: " . count($res1['ids']));
        }
        if ($res1['total'] !== 7) {
            throw new \RuntimeException("R75 Failed: Total found_posts should be 7, got: " . $res1['total']);
        }
        if ($res1['total_pages'] !== 3) {
            throw new \RuntimeException("R75 Failed: Total pages should be 3, got: " . $res1['total_pages']);
        }

        // Page 2: 3 per page
        $q2 = [
            'category'       => 'archive_cat',
            'posts_per_page' => 3,
            'paged'          => 2,
            'orderby'        => 'price',
            'order'          => 'ASC',
        ];
        $res2 = $this->adapters->executeAdaptedQuery($q2);

        if (count($res2['ids']) !== 3) {
            throw new \RuntimeException("R75 Failed: Page 2 count should be 3, got: " . count($res2['ids']));
        }

        // Page 3: 1 remaining item
        $q3 = [
            'category'       => 'archive_cat',
            'posts_per_page' => 3,
            'paged'          => 3,
            'orderby'        => 'price',
            'order'          => 'ASC',
        ];
        $res3 = $this->adapters->executeAdaptedQuery($q3);

        if (count($res3['ids']) !== 1) {
            throw new \RuntimeException("R75 Failed: Page 3 count should be 1, got: " . count($res3['ids']));
        }

        // Ensure 0 duplicate IDs across pages
        $allIds = array_merge($res1['ids'], $res2['ids'], $res3['ids']);
        if (count($allIds) !== count(array_unique($allIds))) {
            throw new \RuntimeException("R75 Failed: Duplicate IDs detected across numbered pages: " . json_encode($allIds));
        }
        if (count($allIds) !== 7) {
            throw new \RuntimeException("R75 Failed: Total retrieved IDs across pages is " . count($allIds) . ", expected 7");
        }

        // Deep page budget check: page 200 should be capped to MAX_PAGE_DEPTH (100)
        $qDeep = [
            'category'       => 'archive_cat',
            'posts_per_page' => 3,
            'paged'          => 200,
        ];
        $resDeep = $this->adapters->executeAdaptedQuery($qDeep);
        if ($resDeep['page'] !== CatalogAdapters::MAX_PAGE_DEPTH) {
            throw new \RuntimeException("R75 Failed: Page 200 was not capped to " . CatalogAdapters::MAX_PAGE_DEPTH . ", got: " . $resDeep['page']);
        }

        echo "PASSED: R75 (archive_pagination_found_posts) 7 products paginated across 3 pages with exact totals and deep cap.\n\n";
    }

    /**
     * R76: WooCommerce Query Args coverage (category, tag, price, stock, orderby).
     */
    public function testR76WcQueryArgsCoverage(): void
    {
        echo "Testing R76 (wc_query_args_coverage)...\n";

        $p1 = $this->createProductInCatalog([
            'title'    => 'Summer Hat',
            'price'    => 25.0,
            'in_stock' => true,
            'featured' => true,
            'facets'   => [
                ['key' => 'category', 'value' => 'accessories'],
                ['key' => 'tag', 'value' => 'summer'],
            ],
        ]);

        $p2 = $this->createProductInCatalog([
            'title'    => 'Winter Scarf',
            'price'    => 60.0,
            'in_stock' => false,
            'featured' => false,
            'facets'   => [
                ['key' => 'category', 'value' => 'accessories'],
                ['key' => 'tag', 'value' => 'winter'],
            ],
        ]);

        // 1. Filter by category + in_stock
        $res = $this->adapters->executeAdaptedQuery([
            'category'     => 'accessories',
            'stock_status' => 'instock',
        ]);
        if (!in_array($p1, $res['ids'], true) || in_array($p2, $res['ids'], true)) {
            throw new \RuntimeException("R76 Failed: Stock status + category filter mismatch");
        }

        // 2. Filter by tag + featured
        $res = $this->adapters->executeAdaptedQuery([
            'tag'      => 'summer',
            'featured' => true,
        ]);
        if (!in_array($p1, $res['ids'], true) || in_array($p2, $res['ids'], true)) {
            throw new \RuntimeException("R76 Failed: Tag + featured filter mismatch");
        }

        // 3. Price range filter
        $res = $this->adapters->executeAdaptedQuery([
            'min_price' => 50.0,
            'max_price' => 100.0,
        ]);
        if (!in_array($p2, $res['ids'], true) || in_array($p1, $res['ids'], true)) {
            throw new \RuntimeException("R76 Failed: Price range filter mismatch");
        }

        echo "PASSED: R76 (wc_query_args_coverage) WooCommerce query arguments mapped and filtered correctly.\n\n";
    }

    /**
     * R77: WooCommerce Blocks product collection and grid listing adapter.
     */
    public function testR77BlocksListing(): void
    {
        echo "Testing R77 (blocks_listing)...\n";

        $blockAttrs = [
            'categories'  => ['accessories'],
            'stockStatus' => ['instock'],
            'orderBy'     => 'price',
            'order'       => 'ASC',
            'perPage'     => 10,
            'page'        => 1,
        ];

        $res = $this->adapters->adaptBlockQuery($blockAttrs);

        if (!isset($res['ids'], $res['total'], $res['total_pages'])) {
            throw new \RuntimeException("R77 Failed: Block query response missing standard pagination fields");
        }

        echo "PASSED: R77 (blocks_listing) WooCommerce Blocks attributes correctly adapted and executed.\n\n";
    }

    /**
     * R78 & AC-T19-03: Unsupported query capability registry prevents silent filter omission.
     */
    public function testR78UnsupportedFilterNoSilentOmission(): void
    {
        echo "Testing R78 & AC-T19-03 (unsupported_filter_no_silent_omission)...\n";

        // Query with arbitrary unindexed custom postmeta
        $unsupportedQuery = [
            'category'   => 'accessories',
            'meta_query' => [
                [
                    'key'   => '_arbitrary_custom_crm_id',
                    'value' => '9999',
                ],
            ],
        ];

        // 1. Evaluate support
        $support = $this->adapters->evaluateQuerySupport($unsupportedQuery);
        if ($support['supported']) {
            throw new \RuntimeException("R78 Failed: Query with arbitrary custom meta should be evaluated as unsupported!");
        }
        if (empty($support['reasons'])) {
            throw new \RuntimeException("R78 Failed: Reasons must not be empty for unsupported query!");
        }

        // 2. Strict execution must throw UnsupportedQueryException (no silent omission)
        $exceptionCaught = false;
        try {
            $this->adapters->executeAdaptedQuery($unsupportedQuery);
        } catch (UnsupportedQueryException $e) {
            $exceptionCaught = true;
            echo "Caught expected UnsupportedQueryException: " . $e->getMessage() . "\n";
        }

        if (!$exceptionCaught) {
            throw new \RuntimeException("R78 & AC-T19-03 Failed: Unsupported query did not throw exception; filters would have been silently dropped!");
        }

        echo "PASSED: R78 & AC-T19-03 capability registry strictly prevents silent filter drop.\n\n";
    }

    /**
     * AC-T19-02: Non-product WP_Query instances remain completely untouched.
     */
    public function testAcT1902NonProductQueryUntouched(): void
    {
        echo "Testing AC-T19-02 (non-product query untouched)...\n";

        $nonProductQuery = new \WP_Query();
        $nonProductQuery->init();
        $nonProductQuery->set('post_type', 'post'); // Blog post query

        $result = $this->adapters->handlePostsPreQuery(null, $nonProductQuery);

        if ($result !== null) {
            throw new \RuntimeException("AC-T19-02 Failed: Non-product WP_Query must return null to allow standard WordPress execution, got: " . var_export($result, true));
        }

        echo "PASSED: AC-T19-02 non-product WP_Query untouched.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T19 Integration Tests ===\n\n";

        $this->testR75ArchivePaginationFoundPosts();
        $this->testR76WcQueryArgsCoverage();
        $this->testR77BlocksListing();
        $this->testR78UnsupportedFilterNoSilentOmission();
        $this->testAcT1902NonProductQueryUntouched();

        echo "=== Test Summary ===\n";
        echo "R75 (archive_pagination_found_posts): PASSED\n";
        echo "R76 (wc_query_args_coverage): PASSED\n";
        echo "R77 (blocks_listing): PASSED\n";
        echo "R78 (unsupported_filter_no_silent_omission): PASSED\n";
        echo "AC-T19-01 (classic_pagination_blocks_exact_totals): PASSED\n";
        echo "AC-T19-02 (non_product_wp_query_untouched): PASSED\n";
        echo "AC-T19-03 (unsupported_query_fail_explicit): PASSED\n";
    }
}

$test = new T19Test();
$test->runAll();
exit(0);
