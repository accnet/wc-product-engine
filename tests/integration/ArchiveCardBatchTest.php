<?php
/**
 * Integration Test — P047 archive product cards: batched price fingerprints, lowest-price inputs,
 * gallery and owned meta.
 *
 * Run on a test_* copy of the site DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/ArchiveCardBatchTest.php
 */

use WPE\Application\Variant\VariantResolver;
use WPE\Compatibility\Gallery\GalleryAdapter;
use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Support\AuthorityResolver;

global $wpdb, $wp_query;

if (strpos((string)DB_NAME, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run outside a test_* database (DB_NAME=" . DB_NAME . ").\n");
    exit(1);
}

// zend.assertions is -1 for WP-CLI on this host, so checks throw instead of using assert().
$must = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$checks = 0;
$ok = static function (string $msg) use (&$checks): void {
    $checks++;
    echo "  [OK] {$msg}\n";
};
$callPrivate = static function (object $obj, string $method, ...$args) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
};
$getPrivate = static function (object $obj, string $prop) {
    $ref = new ReflectionProperty($obj, $prop);
    $ref->setAccessible(true);
    return $ref->getValue($obj);
};
$resetGalleryStatics = static function (): void {
    foreach (['featuredAttachmentCache', 'galleryAttachmentIdsCache', 'featuredRowCache'] as $prop) {
        $ref = new ReflectionProperty(GalleryAdapter::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }
};
$sqlLog = [];
$queryFilter = static function ($sql) use (&$sqlLog) {
    $sqlLog[] = (string)$sql;
    return $sql;
};
$countMatching = static function (array $log, string $needle): int {
    return count(array_filter($log, static fn(string $q): bool => strpos($q, $needle) !== false));
};
// Per-card candidate read: getCandidateVariants() binds a literal product ID.
$perCardCandidates = static function (array $log): int {
    return count(array_filter($log, static fn(string $q): bool => preg_match('/WHERE v\.product_id = \d+ AND v\.status/', $q) === 1));
};

$p = $wpdb->prefix;
$origQuery = $wp_query;

try {
    echo "=== P047 ArchiveCardBatchTest ===\n";

    // Twelve published native variable products (an archive page), plus edge cases.
    $cards = array_map('intval', $wpdb->get_col(
        "SELECT p.id FROM {$p}wpe_products p JOIN {$wpdb->posts} w ON w.ID = p.id AND w.post_type = 'product' AND w.post_status = 'publish'
         WHERE p.type = 'variable' AND p.deleted_at IS NULL
           AND EXISTS (SELECT 1 FROM {$p}wpe_variants v WHERE v.product_id = p.id AND v.status = 'publish' AND v.deleted_at IS NULL)
         ORDER BY p.id DESC LIMIT 12"
    ));
    $must(count($cards) === 12, 'test DB needs 12 published native variable products, found ' . count($cards));
    $noInventory = (int)$wpdb->get_var(
        "SELECT p.id FROM {$p}wpe_products p WHERE p.deleted_at IS NULL
           AND NOT EXISTS (SELECT 1 FROM {$p}wpe_inventory i WHERE i.owner_id = p.id) ORDER BY p.id DESC LIMIT 1"
    );
    $notNative = (int)$wpdb->get_var(
        "SELECT w.ID FROM {$wpdb->posts} w LEFT JOIN {$p}wpe_products p ON p.id = w.ID
         WHERE w.post_type = 'product' AND p.id IS NULL ORDER BY w.ID DESC LIMIT 1"
    );
    $edge = array_values(array_filter([$noInventory, $notNative, 999999999]));

    /* 1. Bulk lowest-price keys == per-product keys */
    $single = new SelectorAdapter($wpdb);
    $bulk = new SelectorAdapter($wpdb);
    $all = array_merge($cards, $edge);
    $callPrivate($bulk, 'primeLowestPriceCacheKeys', $all);
    $memo = $getPrivate($bulk, 'lowestKeyMemo');
    foreach ($all as $id) {
        $expected = $callPrivate($single, 'computeLowestPriceCacheKey', $id);
        $must(($memo[$id] ?? null) === $expected, "Bulk lowest-price key differs for {$id}: " . var_export($memo[$id] ?? null, true) . " vs {$expected}");
    }
    $ok('bulk lowest-price keys equal computeLowestPriceCacheKey() for ' . count($all) . ' products (incl. no inventory, not native, missing)');

    /* 2. Bulk resolver inputs == per-product loaders */
    $resolver = new VariantResolver($wpdb);
    $bulkVariants = $resolver->getCandidateVariantsFor($all);
    $bulkParents = $resolver->getParentInventoriesFor($all);
    foreach ($all as $id) {
        $one = $resolver->getCandidateVariants($id, false)['variants'];
        $must(($bulkVariants[$id] ?? null) === $one, "Bulk candidate variants differ for {$id}");
        $parent = $callPrivate($resolver, 'getParentInventory', $id);
        $must(($bulkParents[$id] ?? null) === $parent, "Bulk parent inventory differs for {$id}: " . wp_json_encode([$bulkParents[$id] ?? null, $parent]));
        $direct = $resolver->resolveLowestPurchasableVariation($id);
        $viaInputs = $resolver->resolveLowestPurchasableVariation($id, $bulkVariants[$id] ?? [], $bulkParents[$id] ?? []);
        $must($direct == $viaInputs, "Lowest variation differs when resolved from bulk inputs for {$id}");
    }
    $ok('bulk candidate variants and parent inventory equal the per-product loaders; lowest variation unchanged');

    /* 3. Owned meta and gallery from the primed bundle == direct reads */
    $keys = ['_thumbnail_id', '_product_image_gallery', '_price', '_regular_price', '_sale_price', '_sku', '_stock_status', '_manage_stock', '_stock', '_upsell_ids', '_crosssell_ids', '_tax_status', 'total_sales'];
    $read = static function (array $ids) use ($keys): array {
        $out = [];
        foreach ($ids as $id) {
            foreach ($keys as $k) {
                $out[$id][$k] = get_post_meta($id, $k, true);
            }
            $out[$id]['gallery_ids'] = wc_get_product($id)->get_gallery_image_ids();
        }
        return $out;
    };
    AuthorityResolver::flushAll();
    $resetGalleryStatics();
    wp_cache_flush();
    $plain = $read($cards);

    AuthorityResolver::flushAll();
    $resetGalleryStatics();
    wp_cache_flush();
    _prime_post_caches($cards, true, true);
    AuthorityResolver::primeObjects($cards);
    ProductDataStore::primeProducts($cards);
    $sqlLog = [];
    add_filter('query', $queryFilter);
    $primed = $read($cards);
    remove_filter('query', $queryFilter);
    $must($primed === $plain, "Owned meta / gallery from the bundle differ:\nprimed " . wp_json_encode($primed) . "\nplain  " . wp_json_encode($plain));
    $ownedQueries = $countMatching($sqlLog, "FROM {$p}wpe_variants WHERE id =") + $countMatching($sqlLog, "FROM {$p}wpe_products WHERE id =")
        + $countMatching($sqlLog, "pm.role = 'featured' LIMIT") + $countMatching($sqlLog, "pm.role = 'gallery' ORDER BY pm.position");
    $must($ownedQueries === 0, "Primed cards still ran {$ownedQueries} per-card owned-meta/gallery queries:\n" . implode("\n", $sqlLog));
    $ok('owned meta (' . count($keys) . ' keys) and gallery IDs from the primed bundle equal direct reads, with no per-card queries');

    /* 4. Archive loop: lowest price HTML with one fingerprint batch and one input batch */
    $plainHtml = [];
    $reference = new SelectorAdapter($wpdb);
    foreach ($cards as $id) {
        $plainHtml[$id] = $reference->getLowestPriceHtml($id);
    }

    $wp_query = new WP_Query();
    $wp_query->posts = array_map('get_post', $cards);
    $wp_query->post_count = count($cards);
    add_filter('wpe_authority_request_memo', '__return_true');
    wp_cache_flush();
    $archive = new SelectorAdapter($wpdb);
    $sqlLog = [];
    add_filter('query', $queryFilter);
    $html = [];
    foreach ($cards as $id) {
        $html[$id] = $archive->getLowestPriceHtml($id);
    }
    remove_filter('query', $queryFilter);
    remove_filter('wpe_authority_request_memo', '__return_true');

    $must($html === $plainHtml, "Lowest price HTML changed:\n" . wp_json_encode([$html, $plainHtml]));
    $fingerprints = $countMatching($sqlLog, 'AS var_sig');
    $candidates = $perCardCandidates($sqlLog);
    $bulkCandidates = $countMatching($sqlLog, 'AS wpe_product_id, v.id');
    // getParentInventory()'s own read (product loads for price formatting read inventory separately).
    $parentReads = count(array_filter($sqlLog, static fn(string $q): bool => preg_match('/SELECT manage_stock, quantity, stock_status, backorders FROM \S+ WHERE owner_id = \d+/', $q) === 1));
    $must($fingerprints === 1, "Expected one fingerprint batch for 12 cards, got {$fingerprints}");
    $must($candidates === 0 && $bulkCandidates === 1, "Expected one candidate batch and no per-card reads, got {$bulkCandidates} batch / {$candidates} per-card");
    $must($parentReads === 0, "Per-card parent inventory reads: {$parentReads}");
    $ok('12-card archive loop: identical lowest price HTML, ' . count($sqlLog) . ' queries (1 fingerprint batch, 1 candidate batch, no per-card reads)');

    // Warm object cache: no input batch at all.
    add_filter('wpe_authority_request_memo', '__return_true');
    $warm = new SelectorAdapter($wpdb);
    $sqlLog = [];
    add_filter('query', $queryFilter);
    foreach ($cards as $id) {
        $warm->getLowestPriceHtml($id);
    }
    remove_filter('query', $queryFilter);
    remove_filter('wpe_authority_request_memo', '__return_true');
    $must($countMatching($sqlLog, 'AS wpe_product_id, v.id') === 0 && $perCardCandidates($sqlLog) === 0, 'Cached lowest prices must not load resolver inputs');
    $ok('cached lowest prices skip the input batch (' . count($sqlLog) . ' queries)');

    echo "ALL P047 ARCHIVE CARD BATCH ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    remove_filter('query', $queryFilter);
    remove_filter('wpe_authority_request_memo', '__return_true');
    $wp_query = $origQuery;
    AuthorityResolver::flushAll();
}
