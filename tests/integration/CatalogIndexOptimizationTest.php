<?php
/**
 * Integration Test — P028.2 Catalog Workload Indexes, Zero-Filesort B-Tree Compilation,
 * Native Option Facets, Same-Variant Multi-Attribute Filtering, and Exact Filtered Counts.
 */

use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Catalog\CatalogQuery;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\SchemaMigrator;

global $wpdb;
$prefix = $wpdb->prefix;

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$cleanupProductIds = [];
$cleanupVariantIds = [];

try {
    echo "=== P028 CatalogIndexOptimizationTest ===\n";

    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'native_bridge',
    ]));
    PluginBootstrap::instance()->loadFlags();

    // 1. SchemaMigrator v3 creates all 4 workload sort indexes idempotently
    $migrator = new SchemaMigrator($wpdb);
    $migRes = $migrator->migrate();
    assert($migRes['success'] === true, 'SchemaMigrator::migrate() must succeed');
    assert((int)$migRes['schema_version'] === 3, 'Expected schema version 3');

    CatalogQuery::resetIndexCache();
    $idxRows = $wpdb->get_results("SHOW INDEX FROM `{$prefix}wpe_catalog`", ARRAY_A) ?: [];
    $idxNames = array_unique(array_column($idxRows, 'Key_name'));
    foreach (['idx_cat_menu_order', 'idx_cat_rating', 'idx_cat_title', 'idx_cat_price'] as $expectedIdx) {
        assert(in_array($expectedIdx, $idxNames, true), "Missing expected index {$expectedIdx}");
    }
    echo "  [OK] SchemaMigrator v3 verified all 4 workload sort indexes on wpe_catalog\n";

    // 2. Seed variable products with native wpe_options / wpe_option_values / wpe_variant_values
    $repo = new ProductRepository($wpdb);
    $optRepo = new OptionsRepository($wpdb);
    $projector = new CatalogProjector($wpdb);
    $catalogQuery = new CatalogQuery($wpdb, $projector);
    $gen = $projector->getActiveGeneration();
    if ($gen <= 0) {
        $gen = 1;
        $projector->switchActiveGeneration(1);
    }

    $tagSlug = 'p028_tag_' . wp_rand(1000, 9999);
    $catSlug = 'p028_cat_' . wp_rand(1000, 9999);

    // Product A: Variant 1 = (red, s), Variant 2 = (blue, xl) -> must NOT match (red + xl)!
    $prodA = $repo->create([
        'title'      => 'P028 Cross Variant False Positive Guard',
        'slug'       => 'p028-cross-var-' . wp_rand(1000, 9999),
        'type'       => 'variable',
        'status'     => 'publish',
        'menu_order' => 10,
        'variations' => [
            [
                'sku'             => 'P028-CV-1-' . wp_rand(1000, 9999),
                'status'          => 'publish',
                'combination_key' => hash('sha256', 'cv-1'),
                'attributes'      => ['slot' => '1'],
                'regular_price'   => '30.00',
                'position'        => 1,
            ],
            [
                'sku'             => 'P028-CV-2-' . wp_rand(1000, 9999),
                'status'          => 'publish',
                'combination_key' => hash('sha256', 'cv-2'),
                'attributes'      => ['slot' => '2'],
                'regular_price'   => '40.00',
                'position'        => 2,
            ],
        ],
    ]);
    $pidA = (int)$prodA['id'];
    $cleanupProductIds[] = $pidA;
    $vIdsA = array_values(array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d ORDER BY position ASC",
        $pidA
    ))));
    $cleanupVariantIds = array_merge($cleanupVariantIds, $vIdsA);

    $colorOptA = $optRepo->createOption($pidA, [
        'key' => 'color', 'name' => 'Color', 'taxonomy' => 'pa_color', 'visible' => 1, 'used_for_variation' => 1,
        'values' => [
            ['value_key' => 'red', 'label' => 'Red'],
            ['value_key' => 'blue', 'label' => 'Blue'],
        ],
    ]);
    $sizeOptA = $optRepo->createOption($pidA, [
        'key' => 'size', 'name' => 'Size', 'taxonomy' => 'pa_size', 'visible' => 1, 'used_for_variation' => 1,
        'values' => [
            ['value_key' => 's', 'label' => 'S'],
            ['value_key' => 'xl', 'label' => 'XL'],
        ],
    ]);
    // vIdsA[0] = Red + S; vIdsA[1] = Blue + XL
    $wpdb->replace("{$prefix}wpe_variant_values", ['variant_id' => $vIdsA[0], 'option_id' => $colorOptA['id'], 'value_id' => $colorOptA['values'][0]['id'], 'is_any' => 0]);
    $wpdb->replace("{$prefix}wpe_variant_values", ['variant_id' => $vIdsA[0], 'option_id' => $sizeOptA['id'], 'value_id' => $sizeOptA['values'][0]['id'], 'is_any' => 0]);
    $wpdb->replace("{$prefix}wpe_variant_values", ['variant_id' => $vIdsA[1], 'option_id' => $colorOptA['id'], 'value_id' => $colorOptA['values'][1]['id'], 'is_any' => 0]);
    $wpdb->replace("{$prefix}wpe_variant_values", ['variant_id' => $vIdsA[1], 'option_id' => $sizeOptA['id'], 'value_id' => $sizeOptA['values'][1]['id'], 'is_any' => 0]);

    // Product B: Variant 1 = (red, xl) -> MUST match (red + xl)!
    $searchSkuB = 'P028-MATCH-SKU-' . wp_rand(1000, 9999);
    $prodB = $repo->create([
        'title'      => 'P028 True Same Variant Match',
        'slug'       => 'p028-true-var-' . wp_rand(1000, 9999),
        'type'       => 'variable',
        'status'     => 'publish',
        'sku'        => $searchSkuB,
        'menu_order' => 5,
        'variations' => [
            [
                'sku'             => 'P028-TV-1-' . wp_rand(1000, 9999),
                'status'          => 'publish',
                'combination_key' => hash('sha256', 'tv-1'),
                'attributes'      => ['slot' => '1'],
                'regular_price'   => '25.00',
                'position'        => 1,
            ],
        ],
    ]);
    $pidB = (int)$prodB['id'];
    $cleanupProductIds[] = $pidB;
    $vIdsB = array_values(array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d ORDER BY position ASC",
        $pidB
    ))));
    $cleanupVariantIds = array_merge($cleanupVariantIds, $vIdsB);

    $colorOptB = $optRepo->createOption($pidB, [
        'key' => 'color', 'name' => 'Color', 'taxonomy' => 'pa_color', 'visible' => 1, 'used_for_variation' => 1,
        'values' => [['value_key' => 'red', 'label' => 'Red']],
    ]);
    $sizeOptB = $optRepo->createOption($pidB, [
        'key' => 'size', 'name' => 'Size', 'taxonomy' => 'pa_size', 'visible' => 1, 'used_for_variation' => 1,
        'values' => [['value_key' => 'xl', 'label' => 'XL']],
    ]);
    $wpdb->replace("{$prefix}wpe_variant_values", ['variant_id' => $vIdsB[0], 'option_id' => $colorOptB['id'], 'value_id' => $colorOptB['values'][0]['id'], 'is_any' => 0]);
    $wpdb->replace("{$prefix}wpe_variant_values", ['variant_id' => $vIdsB[0], 'option_id' => $sizeOptB['id'], 'value_id' => $sizeOptB['values'][0]['id'], 'is_any' => 0]);

    // Product C: Simple unpriced product (min_price = NULL) to verify nulls-last in executeArchivePage
    $prodC = $repo->create([
        'title'         => 'P028 Null Price Simple',
        'slug'          => 'p028-null-simple-' . wp_rand(1000, 9999),
        'type'          => 'simple',
        'status'        => 'publish',
        'regular_price' => null,
        'menu_order'    => 20,
    ]);
    $pidC = (int)$prodC['id'];
    $cleanupProductIds[] = $pidC;

    // Project all 3 into active generation + attach test category/tag facets
    foreach ([$pidA, $pidB, $pidC] as $pid) {
        $projector->projectProduct($pid, $gen);
        $wpdb->insert("{$prefix}wpe_catalog_facets", [
            'generation' => $gen,
            'product_id' => $pid,
            'variant_id' => 0,
            'facet_key'  => 'category',
            'value_key'  => $catSlug,
        ]);
    }
    $wpdb->insert("{$prefix}wpe_catalog_facets", [
        'generation' => $gen,
        'product_id' => $pidB,
        'variant_id' => 0,
        'facet_key'  => 'tag',
        'value_key'  => $tagSlug,
    ]);

    // Verify native facets projected into wpe_catalog_facets
    $facetCountB = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM {$prefix}wpe_catalog_facets WHERE generation = %d AND product_id = %d AND variant_id = %d",
        $gen,
        $pidB,
        $vIdsB[0]
    ));
    assert($facetCountB >= 2, "Expected native variant facets projected for Product B, got {$facetCountB}");
    echo "  [OK] CatalogProjector projected native wpe_variant_values into wpe_catalog_facets\n";

    // 3. Multi-attribute same-variant match + exact count
    $comboRes = $catalogQuery->execute([
        'category'   => $catSlug,
        'attributes' => ['pa_color' => 'red', 'pa_size' => 'xl'],
        'count_mode' => 'exact',
    ]);
    $comboIds = array_column($comboRes['items'], 'product_id');
    assert($comboIds === [$pidB], 'Multi-attribute filter must match Product B and reject cross-variant Product A, got ' . json_encode($comboIds));
    assert($comboRes['total'] === 1, "Expected exact filtered count 1 for attributes, got {$comboRes['total']}");
    echo "  [OK] Multi-attribute same-variant semi-join rejected cross-variant false positive and counted accurately\n";

    // 4. Tag & Search exact counts
    $tagRes = $catalogQuery->execute(['tag' => $tagSlug, 'count_mode' => 'exact']);
    assert($tagRes['total'] === 1 && $tagRes['items'][0]['product_id'] === $pidB, 'Tag filter count must be 1');

    $searchRes = $catalogQuery->execute(['search' => $searchSkuB, 'count_mode' => 'exact']);
    assert($searchRes['total'] === 1 && $searchRes['items'][0]['product_id'] === $pidB, 'SKU search count must be 1');
    echo "  [OK] executeCount() matches filtered tag, attributes, and search results\n";

    // 5. Archive OFFSET pagination on price_asc places NULL last across page boundary
    $archP1 = $catalogQuery->executeArchivePage([
        'category' => $catSlug,
        'sort'     => 'price_asc',
        'limit'    => 2,
        'offset'   => 0,
    ]);
    $archP2 = $catalogQuery->executeArchivePage([
        'category' => $catSlug,
        'sort'     => 'price_asc',
        'limit'    => 2,
        'offset'   => 2,
    ]);
    assert($archP1['total'] === 3, "Expected archive total 3, got {$archP1['total']}");
    assert($archP1['ids'] === [$pidB, $pidA], 'Page 1 must be priced products [$25, $30], got ' . json_encode($archP1['ids']));
    assert($archP2['ids'] === [$pidC], 'Page 2 must be null-priced Product C last, got ' . json_encode($archP2['ids']));
    echo "  [OK] Two-leg archive OFFSET price_asc preserves exact nulls-last order across pages\n";

    // 6. EXPLAIN verification: ZERO 'Using filesort' and ZERO 'Using temporary' across all storefront sorts!
    $refCompile = new ReflectionMethod(CatalogQuery::class, 'compileQuery');
    $refCompile->setAccessible(true);
    $refNorm = new ReflectionMethod(CatalogQuery::class, 'normalizeFilters');
    $refNorm->setAccessible(true);

    $explainCases = [
        ['label' => 'shop_menu_order', 'filters' => ['visibility' => 'visible'], 'sort' => 'menu_order', 'leg' => null],
        ['label' => 'default_menu_order', 'filters' => [], 'sort' => 'menu_order', 'leg' => null],
        ['label' => 'date_desc', 'filters' => ['visibility' => 'visible'], 'sort' => 'date_desc', 'leg' => null],
        ['label' => 'popularity', 'filters' => ['visibility' => 'visible'], 'sort' => 'popularity', 'leg' => null],
        ['label' => 'rating', 'filters' => ['visibility' => 'visible'], 'sort' => 'rating', 'leg' => null],
        ['label' => 'title_asc', 'filters' => ['visibility' => 'visible'], 'sort' => 'title_asc', 'leg' => null],
        ['label' => 'price_desc', 'filters' => ['visibility' => 'visible'], 'sort' => 'price_desc', 'leg' => null],
        ['label' => 'price_asc_non_null', 'filters' => ['visibility' => 'visible'], 'sort' => 'price_asc', 'leg' => 'non_null'],
        ['label' => 'price_asc_null_only', 'filters' => ['visibility' => 'visible'], 'sort' => 'price_asc', 'leg' => 'null_only'],
        ['label' => 'cat_attr_menu_order', 'filters' => ['category' => $catSlug, 'attributes' => ['pa_color' => 'red']], 'sort' => 'menu_order', 'leg' => null],
    ];

    foreach ($explainCases as $case) {
        $norm = $refNorm->invoke($catalogQuery, $case['filters']);
        [$sql, $params] = $refCompile->invoke($catalogQuery, $norm, $case['sort'], 12, $gen, null, $case['leg']);
        $prepared = $wpdb->prepare($sql, ...$params);
        $planRows = $wpdb->get_results('EXPLAIN ' . $prepared, ARRAY_A) ?: [];
        assert($planRows !== [], "EXPLAIN returned empty plan for {$case['label']}");
        foreach ($planRows as $pr) {
            $extra = (string)($pr['Extra'] ?? '');
            assert(stripos($extra, 'Using filesort') === false, "Unexpected filesort on {$case['label']}: " . json_encode($pr));
            assert(stripos($extra, 'Using temporary') === false, "Unexpected temporary table on {$case['label']}: " . json_encode($pr));
        }
    }
    echo "  [OK] EXPLAIN verified 0 'Using filesort' and 0 'Using temporary' across all 10 sort/filter cases\n";

    echo "ALL P028.2 CATALOG INDEX & COMPILER ASSERTIONS PASSED\n";
} finally {
    if ($origFlags === false) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    PluginBootstrap::instance()->loadFlags();

    foreach ($cleanupVariantIds as $vid) {
        $wpdb->delete("{$prefix}wpe_variant_values", ['variant_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_variants", ['id' => $vid]);
        wp_delete_post($vid, true);
    }
    foreach ($cleanupProductIds as $pid) {
        $optIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}wpe_options WHERE product_id = %d", $pid)) ?: [];
        foreach ($optIds as $oid) {
            $wpdb->delete("{$prefix}wpe_option_values", ['option_id' => (int)$oid]);
        }
        $wpdb->delete("{$prefix}wpe_options", ['product_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_catalog_facets", ['product_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_audit", ['target_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_products", ['id' => $pid]);
        wp_delete_post($pid, true);
    }
}
