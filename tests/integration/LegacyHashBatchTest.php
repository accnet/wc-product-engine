<?php
/**
 * Integration Test — P045 legacy hash with batched variation meta.
 *
 * Run on a test_* copy of the site DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/LegacyHashBatchTest.php
 *
 *  - LegacyCapture::computeLegacyHash() equals a reference copy of the pre-P045 algorithm
 *    (one postmeta query per variation) on real variable and simple products.
 *  - Same result with duplicated, missing and empty variation meta rows (last meta_id wins).
 *  - Its query count does not depend on the number of variations.
 */

use WPE\Application\Migration\LegacyCapture;

global $wpdb;

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

/** Pre-P045 computeLegacyHash(), kept verbatim as the reference. */
$reference = static function (int $productId) use ($wpdb): string {
    $loadPostMetaMap = static function (int $postId) use ($wpdb): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
            $postId
        ), ARRAY_A);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['meta_key']] = $r['meta_value'];
        }
        return $map;
    };

    $post = $wpdb->get_row($wpdb->prepare(
        "SELECT post_title, post_name, post_status, post_content, post_excerpt, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d",
        $productId
    ), ARRAY_A);
    if (!$post) {
        return hash('sha256', "missing_{$productId}");
    }
    $meta = $loadPostMetaMap($productId);
    $varRows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_status, post_modified_gmt FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY ID ASC",
        $productId
    ), ARRAY_A);
    $varHashes = [];
    foreach ($varRows as $vr) {
        $vMeta = $loadPostMetaMap((int)$vr['ID']);
        $varHashes[] = [
            'id'       => $vr['ID'],
            'modified' => $vr['post_modified_gmt'],
            'sku'      => $vMeta['_sku'] ?? '',
            'price'    => $vMeta['_price'] ?? '',
            'stock'    => $vMeta['_stock'] ?? '',
        ];
    }
    $termIds = $wpdb->get_col($wpdb->prepare(
        "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC",
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
};

$capture = new LegacyCapture($wpdb);
$count = 0;
$counter = static function ($sql) use (&$count) {
    $count++;
    return $sql;
};
$measure = static function (int $id) use ($capture, $counter, &$count): array {
    $count = 0;
    add_filter('query', $counter);
    try {
        $hash = $capture->computeLegacyHash($id);
    } finally {
        remove_filter('query', $counter);
    }
    return [$hash, $count];
};

echo "=== P045 LegacyHashBatchTest ===\n";

/* 1. Real products: variable ones of different sizes and a simple one */
$variable = array_map('intval', $wpdb->get_col(
    "SELECT post_parent FROM {$wpdb->posts}
     WHERE post_type = 'product_variation' AND post_parent > 0
     GROUP BY post_parent ORDER BY COUNT(*) DESC, post_parent ASC LIMIT 3"
));
$variable = array_merge($variable, array_map('intval', $wpdb->get_col(
    "SELECT post_parent FROM {$wpdb->posts}
     WHERE post_type = 'product_variation' AND post_parent > 0
     GROUP BY post_parent HAVING COUNT(*) BETWEEN 2 AND 12 ORDER BY post_parent DESC LIMIT 3"
)));
$simple = (int)$wpdb->get_var(
    "SELECT p.ID FROM {$wpdb->posts} p
     WHERE p.post_type = 'product' AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} c WHERE c.post_parent = p.ID AND c.post_type = 'product_variation')
     ORDER BY p.ID DESC LIMIT 1"
);
$ids = array_values(array_unique(array_filter(array_merge($variable, [$simple]))));
$must(count($variable) >= 2, 'test DB has too few variable products with variation shells');

$queryCounts = [];
foreach ($ids as $id) {
    $vars = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $id));
    [$hash, $queries] = $measure($id);
    $must($hash === $reference($id), "Hash differs from the reference for product {$id} ({$vars} variations)");
    $queryCounts[$id] = [$vars, $queries];
}
$summary = implode(', ', array_map(static fn($id, $c) => "#{$id}: {$c[0]} var / {$c[1]} q", array_keys($queryCounts), $queryCounts));
$ok('hash equals the pre-P045 algorithm on ' . count($ids) . " products ({$summary})");

$maxQueries = max(array_column($queryCounts, 1));
$must($maxQueries <= 5, "computeLegacyHash should need at most 5 queries, got {$maxQueries}");
$must(count(array_unique(array_column($queryCounts, 1))) <= 2, 'Query count varies with the variation count: ' . $summary);
$ok("query count is bounded ({$maxQueries} at most) and does not grow with variations");

/* 2. Duplicated, missing and empty variation meta */
$pid = 0;
foreach ($variable as $candidate) {
    if ($queryCounts[$candidate][0] >= 3 && $queryCounts[$candidate][0] <= 20) {
        $pid = $candidate;
        break;
    }
}
if ($pid === 0) {
    $pid = $variable[0];
}
$vids = array_map('intval', $wpdb->get_col($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY ID ASC LIMIT 3",
    $pid
)));
$must(count($vids) === 3, "Product {$pid} needs 3 variations for the edge cases");
[$vA, $vB, $vC] = $vids;

$pm = $wpdb->postmeta;
$hashBefore = $reference($pid);
$insertedIds = [];
$removedStock = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$pm} WHERE post_id = %d AND meta_key = '_stock'", $vB), ARRAY_A);
$priceRow = $wpdb->get_row($wpdb->prepare("SELECT meta_id, meta_value FROM {$pm} WHERE post_id = %d AND meta_key = '_price' ORDER BY meta_id DESC LIMIT 1", $vC), ARRAY_A);

try {
    $wpdb->insert($pm, ['post_id' => $vA, 'meta_key' => '_sku', 'meta_value' => 'p045-dup-later']);
    $insertedIds[] = (int)$wpdb->insert_id;
    $wpdb->insert($pm, ['post_id' => $vA, 'meta_key' => '_stock', 'meta_value' => '7']);
    $insertedIds[] = (int)$wpdb->insert_id;
    $wpdb->delete($pm, ['post_id' => $vB, 'meta_key' => '_stock']);
    if ($priceRow) {
        $wpdb->update($pm, ['meta_value' => ''], ['meta_id' => (int)$priceRow['meta_id']]);
    } else {
        $wpdb->insert($pm, ['post_id' => $vC, 'meta_key' => '_price', 'meta_value' => '']);
        $insertedIds[] = (int)$wpdb->insert_id;
    }

    $edited = $reference($pid);
    [$hash] = $measure($pid);
    $must($edited !== $hashBefore, 'Edge-case edits did not change the reference hash (fixture problem)');
    $must($hash === $edited, "Hash differs from the reference with duplicated/missing/empty variation meta on product {$pid}");
    $ok("duplicated (last meta_id wins), missing and empty variation meta hash like the reference (product {$pid})");
} finally {
    foreach ($insertedIds as $mid) {
        $wpdb->delete($pm, ['meta_id' => $mid]);
    }
    foreach ($removedStock as $r) {
        $wpdb->insert($pm, ['post_id' => $vB, 'meta_key' => $r['meta_key'], 'meta_value' => $r['meta_value']]);
    }
    if ($priceRow) {
        $wpdb->update($pm, ['meta_value' => $priceRow['meta_value']], ['meta_id' => (int)$priceRow['meta_id']]);
    }
}
[$restored] = $measure($pid);
$must($restored === $hashBefore && $restored === $reference($pid), 'Fixture meta was not restored');
$ok('fixture meta restored, hash back to its original value');

echo "ALL P045 LEGACY HASH BATCH ASSERTIONS PASSED ({$checks} checks)\n";
