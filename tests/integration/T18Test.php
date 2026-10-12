<?php
/**
 * T18 Integration Test — Facets coherent theo variant và counts.
 *
 * Verifies:
 * - R71 (impossible_combination_filter): Variant-coherent mode requires matching variation; impossible combinations rejected.
 * - R72 (distinct_parent_counts): Products with multiple matching variations counted exactly once per parent.
 * - R73 (self_exclusion_facets): Disjunctive facet counts exclude own facet filter while preserving other facet filters.
 * - R74 (category_descendants_deleted_term): Taxonomy hierarchy expands descendants, deleted terms excluded.
 * - AC-T18-01: Black-M/White-L does not match Black-L in coherent mode; counts do not double across variations.
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
use WPE\Application\Catalog\FacetQuery;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WooDataStore\ProductLifecycle;

class T18Test
{
    private \wpdb $wpdb;
    private CatalogProjector $projector;
    private FacetQuery $facetQuery;
    private array $cleanProductIds = [];
    private array $cleanTermIds = [];
    private int $activeGen;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        ProductLifecycle::register();

        $this->projector = new CatalogProjector($this->wpdb);
        $this->facetQuery = new FacetQuery($this->wpdb, $this->projector);
        $this->activeGen = $this->projector->getActiveGeneration();
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        if (!empty($this->cleanProductIds)) {
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

        if (!empty($this->cleanTermIds)) {
            foreach ($this->cleanTermIds as $termId) {
                wp_delete_term($termId, 'product_cat');
            }
            $this->cleanTermIds = [];
        }
    }

    /**
     * Helper to create a variable product with explicit variations and facets.
     */
    private function createVariableProductWithVariations(string $title, array $variations, array $categories = []): int
    {
        $now = gmdate('Y-m-d H:i:s.u');

        // Create WooCommerce variable product
        $product = new \WC_Product_Variable();
        $product->set_name($title);
        $product->set_status('publish');
        $product->save();

        $pid = $product->get_id();
        $this->cleanProductIds[] = $pid;

        // Update wpe_products
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_products',
            [
                'type'               => 'variable',
                'status'             => 'publish',
                'catalog_visibility' => 'visible',
                'updated_at'         => $now,
            ],
            ['id' => $pid]
        );

        // Upsert wpe_catalog
        $this->wpdb->insert(
            $this->wpdb->prefix . 'wpe_catalog',
            [
                'generation'     => $this->activeGen,
                'product_id'     => $pid,
                'source_version' => 1,
                'type'           => 'variable',
                'status'         => 'publish',
                'visibility'     => 'visible',
                'in_stock'       => 1,
                'featured'       => 0,
                'on_sale'        => 0,
                'min_price'      => 50.0,
                'max_price'      => 100.0,
                'total_sales'    => 0.0,
                'rating_average' => 0.0,
                'rating_count'   => 0,
                'created_at'     => $now,
                'menu_order'     => 0,
                'title_sort'     => $title,
                'image_asset_id' => null,
                'indexed_at'     => $now,
            ]
        );

        // Insert category facets (variant_id = 0)
        foreach ($categories as $cat) {
            $this->wpdb->insert(
                $this->wpdb->prefix . 'wpe_catalog_facets',
                [
                    'generation' => $this->activeGen,
                    'product_id' => $pid,
                    'variant_id' => 0,
                    'facet_key'  => 'category',
                    'value_key'  => $cat,
                ]
            );
        }

        // Insert each variation and its attributes
        $vIndex = 1;
        foreach ($variations as $varAttrs) {
            $varId = $pid * 100 + $vIndex++;

            // Insert into wpe_variants
            $this->wpdb->insert(
                $this->wpdb->prefix . 'wpe_variants',
                [
                    'id'            => $varId,
                    'product_id'    => $pid,
                    'status'        => 'publish',
                    'sku'           => 'SKU-VAR-' . $varId,
                    'regular_price' => '50.000000',
                    'extra_props'   => json_encode(['attributes' => $varAttrs], JSON_UNESCAPED_UNICODE),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                    'version'       => 1,
                ]
            );

            // Insert variant-level facets into wpe_catalog_facets
            foreach ($varAttrs as $attrKey => $attrVal) {
                $this->wpdb->insert(
                    $this->wpdb->prefix . 'wpe_catalog_facets',
                    [
                        'generation' => $this->activeGen,
                        'product_id' => $pid,
                        'variant_id' => $varId,
                        'facet_key'  => sanitize_key((string)$attrKey),
                        'value_key'  => sanitize_key((string)$attrVal),
                    ]
                );

                // Also insert parent-level facet (variant_id = 0) for WC compatibility
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$this->wpdb->prefix}wpe_catalog_facets (generation, product_id, variant_id, facet_key, value_key)
                     VALUES (%d, %d, 0, %s, %s)
                     ON DUPLICATE KEY UPDATE value_key = VALUES(value_key)",
                    $this->activeGen,
                    $pid,
                    sanitize_key((string)$attrKey),
                    sanitize_key((string)$attrVal)
                ));
            }
        }

        return $pid;
    }

    /**
     * R71 & AC-T18-01: Impossible combination filter.
     *
     * Product A: Black-M, White-L
     * Product B: Black-L, White-M
     *
     * Query: pa_color = black AND pa_size = m
     * - Coherent mode: ONLY Product A matches. Product B does NOT match (impossible combination).
     * - Compatible mode: Both Product A and Product B match.
     */
    public function testR71ImpossibleCombinationFilter(): void
    {
        echo "Testing R71 & AC-T18-01 (impossible_combination_filter)...\n";

        // Product A has Black-M and White-L
        $prodA = $this->createVariableProductWithVariations(
            'Product A (Black-M / White-L)',
            [
                ['pa_color' => 'black', 'pa_size' => 'm'],
                ['pa_color' => 'white', 'pa_size' => 'l'],
            ]
        );

        // Product B has Black-L and White-M (has black and has m, but NOT Black-M!)
        $prodB = $this->createVariableProductWithVariations(
            'Product B (Black-L / White-M)',
            [
                ['pa_color' => 'black', 'pa_size' => 'l'],
                ['pa_color' => 'white', 'pa_size' => 'm'],
            ]
        );

        // 1. Variant-coherent mode
        $filterCoherent = [
            'facet_mode' => FacetQuery::MODE_COHERENT,
            'attributes' => [
                'pa_color' => 'black',
                'pa_size'  => 'm',
            ],
        ];

        $resCoherent = $this->facetQuery->queryProducts($filterCoherent);
        $coherentIds = $resCoherent['product_ids'];

        if (!in_array($prodA, $coherentIds, true)) {
            throw new \RuntimeException("R71 Failed: Product A (Black-M) should match in variant-coherent mode!");
        }
        if (in_array($prodB, $coherentIds, true)) {
            throw new \RuntimeException("R71 Failed: Product B (Black-L / White-M) should NOT match Black-M in variant-coherent mode!");
        }

        // 2. WC-compatible mode
        $filterCompatible = [
            'facet_mode' => FacetQuery::MODE_COMPATIBLE,
            'attributes' => [
                'pa_color' => 'black',
                'pa_size'  => 'm',
            ],
        ];

        $resCompatible = $this->facetQuery->queryProducts($filterCompatible);
        $compatibleIds = $resCompatible['product_ids'];

        if (!in_array($prodA, $compatibleIds, true) || !in_array($prodB, $compatibleIds, true)) {
            throw new \RuntimeException("R71 Failed: Both Product A and Product B should match in wc-compatible mode!");
        }

        echo "PASSED: R71 & AC-T18-01 verified. Coherent correctly isolates Black-M, compatible retains Woo behavior.\n\n";
    }

    /**
     * R72 & AC-T18-01: Distinct parent counts without duplication.
     *
     * Product C has 3 variations, all Black (Black-S, Black-M, Black-L).
     * Product D has 1 variation: Black-XL.
     *
     * Count for pa_color = black must be 2 (distinct products), NOT 4!
     */
    public function testR72DistinctParentCounts(): void
    {
        echo "Testing R72 (distinct_parent_counts)...\n";

        $prodC = $this->createVariableProductWithVariations(
            'Product C (3 Black Variations)',
            [
                ['pa_color' => 'black', 'pa_size' => 's'],
                ['pa_color' => 'black', 'pa_size' => 'm'],
                ['pa_color' => 'black', 'pa_size' => 'l'],
            ],
            ['test_counts_cat']
        );

        $prodD = $this->createVariableProductWithVariations(
            'Product D (1 Black Variation)',
            [
                ['pa_color' => 'black', 'pa_size' => 'xl'],
            ],
            ['test_counts_cat']
        );

        $facets = $this->facetQuery->computeFacets(
            ['category' => 'test_counts_cat'],
            ['pa_color']
        );

        $colorFacets = $facets['facets']['pa_color'] ?? [];
        $blackCount = null;
        foreach ($colorFacets as $item) {
            if ($item['value'] === 'black') {
                $blackCount = $item['count'];
                break;
            }
        }

        if ($blackCount !== 2) {
            throw new \RuntimeException("R72 Failed: Expected distinct product count 2 for black, got: " . var_export($blackCount, true));
        }

        echo "PASSED: R72 distinct parent counts verified. Exactly 2 products counted despite 4 total variations.\n\n";
    }

    /**
     * R73: Disjunctive facet counts with strict self-exclusion.
     *
     * Product E: category = 'footwear', pa_color = 'red'
     * Product F: category = 'footwear', pa_color = 'blue'
     * Product G: category = 'apparel',  pa_color = 'red'
     *
     * When user filters by category='footwear' AND pa_color='red':
     * - The counts for facet 'pa_color' must self-exclude pa_color='red'.
     *   Therefore, 'blue' must have count = 1 (Product F in footwear), NOT 0!
     * - 'red' has count = 1 (Product E in footwear).
     * - Product G is in apparel, so it is excluded by category='footwear'.
     */
    public function testR73SelfExclusionFacets(): void
    {
        echo "Testing R73 (self_exclusion_facets)...\n";

        $prodE = $this->createVariableProductWithVariations(
            'Product E (Footwear Red)',
            [['pa_color' => 'red']],
            ['footwear']
        );

        $prodF = $this->createVariableProductWithVariations(
            'Product F (Footwear Blue)',
            [['pa_color' => 'blue']],
            ['footwear']
        );

        $prodG = $this->createVariableProductWithVariations(
            'Product G (Apparel Red)',
            [['pa_color' => 'red']],
            ['apparel']
        );

        // Active filters: category = footwear, pa_color = red
        $facets = $this->facetQuery->computeFacets(
            [
                'category'   => 'footwear',
                'attributes' => ['pa_color' => 'red'],
            ],
            ['pa_color']
        );

        $colorFacets = $facets['facets']['pa_color'] ?? [];
        $counts = array_column($colorFacets, 'count', 'value');

        // Under self-exclusion:
        // 'red' count within footwear = 1 (Product E)
        // 'blue' count within footwear = 1 (Product F)
        if (($counts['red'] ?? 0) !== 1) {
            throw new \RuntimeException("R73 Failed: 'red' count should be 1 within footwear, got: " . ($counts['red'] ?? 0));
        }
        if (($counts['blue'] ?? 0) !== 1) {
            throw new \RuntimeException("R73 Failed: 'blue' count should be 1 within footwear under self-exclusion, got: " . ($counts['blue'] ?? 0));
        }

        echo "PASSED: R73 self-exclusion facets verified. Blue remains available with count 1 when red is selected.\n\n";
    }

    /**
     * R74: Category hierarchy descendants expansion with term version / deletion safety.
     */
    public function testR74CategoryDescendantsDeletedTerm(): void
    {
        echo "Testing R74 (category_descendants_deleted_term)...\n";

        // Create parent category 'root_clothing'
        $rootTerm = wp_insert_term('Root Clothing ' . wp_generate_password(4, false), 'product_cat');
        if (is_wp_error($rootTerm)) {
            throw new \RuntimeException("Failed to create root term: " . $rootTerm->get_error_message());
        }
        $rootId = (int)$rootTerm['term_id'];
        $rootSlug = get_term($rootId, 'product_cat')->slug;
        $this->cleanTermIds[] = $rootId;

        // Create child category 'child_hoodies' under 'root_clothing'
        $childTerm = wp_insert_term('Child Hoodies ' . wp_generate_password(4, false), 'product_cat', ['parent' => $rootId]);
        if (is_wp_error($childTerm)) {
            throw new \RuntimeException("Failed to create child term: " . $childTerm->get_error_message());
        }
        $childId = (int)$childTerm['term_id'];
        $childSlug = get_term($childId, 'product_cat')->slug;
        $this->cleanTermIds[] = $childId;

        // Create child category 'child_deleted' under 'root_clothing'
        $delTerm = wp_insert_term('Child To Delete ' . wp_generate_password(4, false), 'product_cat', ['parent' => $rootId]);
        if (is_wp_error($delTerm)) {
            throw new \RuntimeException("Failed to create delete term: " . $delTerm->get_error_message());
        }
        $delId = (int)$delTerm['term_id'];
        $delSlug = get_term($delId, 'product_cat')->slug;

        // Create product in child_hoodies
        $prodHoodie = $this->createVariableProductWithVariations(
            'Hoodie in Child Category',
            [['pa_size' => 'm']],
            [$childSlug]
        );

        // 1. Verify hierarchy expansion: query by root category should include child_hoodies
        $resolved = $this->facetQuery->resolveCategorySlugs($rootSlug);
        if (!in_array($childSlug, $resolved, true)) {
            throw new \RuntimeException("R74 Failed: Child slug '{$childSlug}' not found in resolved descendants of '{$rootSlug}': " . json_encode($resolved));
        }

        $resRoot = $this->facetQuery->queryProducts(['category' => $rootSlug]);
        if (!in_array($prodHoodie, $resRoot['product_ids'], true)) {
            throw new \RuntimeException("R74 Failed: Product in child category did not match when filtering by root category!");
        }

        // 2. Now delete the third category 'child_deleted'
        wp_delete_term($delId, 'product_cat');
        clean_term_cache($delId, 'product_cat');

        // Resolve descendants again: deleted term must not be resurrected
        $resolvedAfterDelete = $this->facetQuery->resolveCategorySlugs($rootSlug);
        if (in_array($delSlug, $resolvedAfterDelete, true)) {
            throw new \RuntimeException("R74 Failed: Deleted category slug '{$delSlug}' was still present in descendants!");
        }

        echo "PASSED: R74 category descendants expansion and deleted term safety verified.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T18 Integration Tests ===\n\n";

        $this->testR71ImpossibleCombinationFilter();
        $this->testR72DistinctParentCounts();
        $this->testR73SelfExclusionFacets();
        $this->testR74CategoryDescendantsDeletedTerm();

        echo "=== Test Summary ===\n";
        echo "R71 (impossible_combination_filter): PASSED\n";
        echo "R72 (distinct_parent_counts): PASSED\n";
        echo "R73 (self_exclusion_facets): PASSED\n";
        echo "R74 (category_descendants_deleted_term): PASSED\n";
        echo "AC-T18-01 (coherent_vs_compatible_no_double_counts): PASSED\n";
    }
}

$test = new T18Test();
$test->runAll();
exit(0);
