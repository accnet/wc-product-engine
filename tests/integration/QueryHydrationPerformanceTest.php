<?php
/**
 * Integration Test — P028.1 & P028.3 Query & Hydration Performance Budgets.
 */

use WPE\Application\Catalog\CatalogAdapters;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Catalog\CatalogQuery;
use WPE\Application\Catalog\ContextCache;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Support\AuthorityResolver;

global $wpdb;
$prefix = $wpdb->prefix;

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$createdProductIds = [];
$createdVariantIds = [];

try {
    echo "=== P028 QueryHydrationPerformanceTest ===\n";

    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'native_bridge',
    ]));
    PluginBootstrap::instance()->loadFlags();
    add_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);

    $repo = new ProductRepository($wpdb);
    $now = gmdate('Y-m-d H:i:s.u');
    $pastEnd = gmdate('Y-m-d H:i:s', time() - 7200);

    // Create 3 variable products x 15 variations = 45 variations
    for ($pIdx = 1; $pIdx <= 3; $pIdx++) {
        $vars = [];
        for ($vIdx = 1; $vIdx <= 15; $vIdx++) {
            $vars[] = [
                'sku'             => sprintf('P028-V-%d-%d-%d', $pIdx, $vIdx, wp_rand(1000, 9999)),
                'status'          => 'publish',
                'combination_key' => hash('sha256', "p{$pIdx}-v{$vIdx}"),
                'attributes'      => ['size' => 'S' . $vIdx],
                'regular_price'   => (string)(20 + $vIdx),
                // vIdx=1 has active sale 15.00; vIdx=2 has expired sale 10.00 (should resolve to regular 22.00)
                'sale_price'     => $vIdx === 1 ? '15.00' : ($vIdx === 2 ? '10.00' : null),
                'sale_to'        => $vIdx === 2 ? $pastEnd : null,
                'manage_stock'   => 1,
                'stock_quantity' => '10',
                'position'       => $vIdx,
            ];
        }

        $created = $repo->create([
            'title'      => "P028 Perf Variable {$pIdx}",
            'slug'       => "p028-perf-var-{$pIdx}-" . wp_rand(1000, 9999),
            'type'       => 'variable',
            'status'     => 'publish',
            'variations' => $vars,
        ]);
        $pid = (int)$created['id'];
        $createdProductIds[] = $pid;

        $wpdb->update($wpdb->posts, ['post_status' => 'publish', 'post_title' => "P028 Perf Variable {$pIdx}"], ['ID' => $pid]);
        wp_set_object_terms($pid, 'variable', 'product_type');
        clean_post_cache($pid);

        $vIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d",
            $pid
        )));
        foreach ($vIds as $vid) {
            $createdVariantIds[] = $vid;
            // Explicitly set expired sale_to on 2nd variant to verify schedule awareness in read_price_data
        }
        if (isset($vIds[1])) {
            $wpdb->update("{$prefix}wpe_variants", ['sale_price' => '10.000000', 'sale_to' => $pastEnd], ['id' => $vIds[1]]);
        }
    }

    // Track SQL queries executed
    $sqlLog = [];
    $queryFilter = static function ($sql) use (&$sqlLog) {
        $sqlLog[] = $sql;
        return $sql;
    };
    add_filter('query', $queryFilter);

    // 1. Un-primed single variable product hydration + get_price_html() (15 variations)
    AuthorityResolver::flushAll();
    wp_cache_flush();
    wp_load_alloptions(); // Exclude one-time WordPress core bootstrap query after cache flush
    $sqlLog = [];

    $single = wc_get_product($createdProductIds[0]);
    $priceHtml = $single->get_price_html();
    $prices = $single->get_variation_prices(false);
    $unprimedQueries = count($sqlLog);

    assert(count($prices['price']) === 15, 'Expected 15 variation prices');
    $minPrice = min(array_map('floatval', $prices['price']));
    $maxPrice = max(array_map('floatval', $prices['price']));
    assert($minPrice === 15.0, "Expected active sale min price 15.0 (ignoring expired 10.0), got {$minPrice}");
    assert($maxPrice === 35.0, "Expected max price 35.0, got {$maxPrice}");
    assert($unprimedQueries <= 30, "Un-primed variable product + price matrix must execute <= 30 queries, got {$unprimedQueries}");
    echo "  [OK] Un-primed variable product (15 variants) hydrated + priced in {$unprimedQueries} queries (schedule-aware)\n";

    // 2. Bulk-primed archive loop across all 3 variable products (45 variations)
    AuthorityResolver::flushAll();
    wp_cache_flush();
    wp_load_alloptions();
    _prime_post_caches($createdProductIds, true, true);
    AuthorityResolver::primeObjects($createdProductIds);
    ProductDataStore::primeProducts($createdProductIds);

    $sqlLog = [];
    foreach ($createdProductIds as $pid) {
        $prod = wc_get_product($pid);
        $html = $prod->get_price_html();
        assert($html !== '', 'Price HTML must be non-empty');
    }
    $postPrimeLoopQueries = count($sqlLog);
    assert($postPrimeLoopQueries <= 5, "Post-prime loop across 3 variable products (45 variants) must run <= 5 queries, got {$postPrimeLoopQueries}");
    echo "  [OK] Bulk-primed loop across 3 variable products (45 variants) ran {$postPrimeLoopQueries} queries after prime\n";

    // 3. Eviction guarantee on mutation
    $repo->update($createdProductIds[0], ['title' => 'P028 Updated Title']);
    $reloaded = wc_get_product($createdProductIds[0]);
    assert($reloaded->get_name() === 'P028 Updated Title', 'Primed bundle must be evicted immediately on ProductRepository::update');
    echo "  [OK] Runtime hydration cache evicted immediately upon product update\n";

    // 4. End-to-end WP_Query Shop archive interception + bulk priming + ContextCache (P028.3)
    $projector = new CatalogProjector($wpdb);
    $gen = $projector->getActiveGeneration();
    if ($gen <= 0) {
        $gen = 1;
        $projector->switchActiveGeneration(1);
    }
    $archiveCat = 'p028_shop_cat_' . wp_rand(1000, 9999);
    foreach ($createdProductIds as $pid) {
        $projector->projectProduct($pid, $gen);
        $wpdb->insert("{$prefix}wpe_catalog_facets", [
            'generation' => $gen,
            'product_id' => $pid,
            'variant_id' => 0,
            'facet_key'  => 'category',
            'value_key'  => $archiveCat,
        ]);
    }

    $contextCache = new ContextCache($wpdb, $projector);
    $adapters = new CatalogAdapters($wpdb, new CatalogQuery($wpdb, $projector), true, $contextCache);
    add_filter('posts_pre_query', [$adapters, 'handlePostsPreQuery'], 10, 2);

    $prevGlobalQuery = $GLOBALS['wp_query'] ?? null;
    \WPE\Compatibility\Gallery\GalleryAdapter::invalidateStaticProductCache();
    AuthorityResolver::flushAll();
    wp_cache_flush();
    wp_load_alloptions();
    $sqlLog = [];

    $wpQuery = new WP_Query([
        'post_type'      => 'product',
        'wc_query'       => 'product_query',
        'product_cat'    => $archiveCat,
        'posts_per_page' => 12,
        'paged'          => 1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ]);
    $GLOBALS['wp_query'] = $wpQuery;
    assert($wpQuery->found_posts === 3, "Expected WP_Query found_posts = 3, got {$wpQuery->found_posts}");
    assert(count($wpQuery->posts) === 3, 'Expected 3 WP_Post shells returned');

    foreach ($wpQuery->posts as $postObj) {
        $loopProd = wc_get_product($postObj->ID);
        $loopPriceHtml = $loopProd->get_price_html();
        $loopImg = $loopProd->get_image();
        assert($loopPriceHtml !== '' && $loopImg !== '', 'Archive loop price HTML & thumbnail must render');
    }
    $GLOBALS['wp_query'] = $prevGlobalQuery;
    $fullArchiveQueries = count($sqlLog);
    assert($fullArchiveQueries <= 32, "Full cold archive WP_Query + priming + loop across 3 variable products (45 variants) must run <= 32 queries, got {$fullArchiveQueries}");
    echo "  [OK] End-to-end cold WP_Query Shop archive + loop hydration (45 variants) executed in {$fullArchiveQueries} total O(1) queries\n";

    // Repeat identical archive query in same context -> 0 wpe_catalog queries (only 1 control generation PK check)
    $sqlLog = [];
    $repeatRes = $adapters->executeAdaptedQuery([
        'product_cat'    => $archiveCat,
        'posts_per_page' => 12,
        'paged'          => 1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ]);
    $repeatCatalogQueries = count(array_filter($sqlLog, static fn(string $q): bool => str_contains($q, 'wpe_catalog')));
    assert($repeatRes['total'] === 3 && $repeatCatalogQueries === 0 && count($sqlLog) <= 1, 'Expected 0 wpe_catalog SQL queries on ContextCache repeat hit, got ' . json_encode($sqlLog));

    // ContextCache::invalidate() bumps cache_epoch -> forces fresh query execution
    $contextCache->invalidate(null, $gen);
    $sqlLog = [];
    $afterInvalidateRes = $adapters->executeAdaptedQuery([
        'product_cat'    => $archiveCat,
        'posts_per_page' => 12,
        'paged'          => 1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
    ]);
    $afterInvalidateQueries = count($sqlLog);
    assert($afterInvalidateRes['total'] === 3 && $afterInvalidateQueries >= 2, "Expected fresh DB query after ContextCache::invalidate(), got {$afterInvalidateQueries}");
    echo "  [OK] ContextCache repeat hit (0 queries) and epoch invalidation verified\n";

    remove_filter('posts_pre_query', [$adapters, 'handlePostsPreQuery'], 10, 2);
    remove_filter('query', $queryFilter);
    echo "ALL P028.1 & P028.3 HYDRATION & ARCHIVE PERFORMANCE ASSERTIONS PASSED\n";
} finally {
    remove_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);
    AuthorityResolver::flushAll();

    if ($origFlags === false) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    PluginBootstrap::instance()->loadFlags();

    foreach ($createdVariantIds as $vid) {
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_variants", ['id' => $vid]);
        wp_delete_post($vid, true);
    }
    foreach ($createdProductIds as $pid) {
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
