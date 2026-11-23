<?php
/**
 * P032 — Shopify `Product category` path → WooCommerce category tree (category_mode=path).
 *
 * 1. category_mode=none: column reported in ignored_cols, product gets the default category only.
 * 2. category_mode=path: `Root &amp; Co > Mid > Leaf` creates Root/Mid/Leaf (entities decoded, nested),
 *    the product is filed under Leaf and the default category is dropped.
 * 3. The same path with different case/spacing reuses the same three terms.
 * 4. An empty cell leaves the default category; a hand-set category survives a path re-import.
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/ShopifyImportCategoryPathTest.php
 */

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
}

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('ShopifyImportCategoryPath failed: ' . $msg);
    }
    $checks++;
};

$uid = strtolower(wp_generate_password(6, false));
$root = "P032 Root {$uid} & Co";
$mid = "P032 Mid {$uid}";
$leaf = "P032 Leaf {$uid}";
$hand = "P032 Hand {$uid}";
$handles = ["p032-cat-a-{$uid}", "p032-cat-b-{$uid}", "p032-cat-c-{$uid}"];
$skus = array_map(static fn($h) => strtoupper($h), $handles);
$pathA = str_replace('&', '&amp;', "{$root} > {$mid} > {$leaf}");
$pathB = '  ' . strtoupper($root) . ">  {$mid}  >" . strtolower($leaf) . ' ';

$headers = ['URL handle', 'Title', 'Description', 'Vendor', 'Product category', 'Type', 'Tags', 'Status', 'Option1 name', 'Option1 value', 'SKU', 'Price'];
$files = [];
$writeCsv = static function (array $paths) use ($headers, $handles, $skus, &$files): string {
    $path = sys_get_temp_dir() . '/p032_cat_' . wp_generate_password(8, false) . '.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($paths as $i => $categoryPath) {
        $row = [
            'URL handle' => $handles[$i], 'Title' => "P032 Category {$i}", 'Description' => '<p>P032</p>', 'Vendor' => 'V',
            'Product category' => $categoryPath, 'Type' => 'Hoodie', 'Tags' => '', 'Status' => 'active',
            'Option1 name' => 'Title', 'Option1 value' => 'Default Title', 'SKU' => $skus[$i], 'Price' => '9.99',
        ];
        fputcsv($fh, array_map(static fn($h) => (string)$row[$h], $headers), ',', '"', '\\');
    }
    fclose($fh);
    $files[] = $path;
    return $path;
};

$importer = new ShopifyBatchImporter();
$jobs = [];
$runJob = static function (string $csv, string $mode) use ($importer, &$jobs): array {
    $init = $importer->initJob($csv, ['batch_size' => 25, 'skip_unchanged' => true, 'category_mode' => $mode]);
    $jobs[] = $init['job_id'];
    for ($i = 0; $i < 20; $i++) {
        $res = $importer->processBatch($init['job_id'], 25);
        if (!empty($res['completed'])) {
            break;
        }
    }
    return $init;
};
$pid = static fn(string $handle): int => (int)$wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$prefix}wpe_products WHERE slug = %s AND deleted_at IS NULL",
    $handle
));
$cats = static function (int $productId): array {
    $ids = array_map('intval', wp_get_object_terms($productId, 'product_cat', ['fields' => 'ids']));
    sort($ids);
    return $ids;
};
$default = (int)get_option('default_product_cat', 0);

try {
    $check($default > 0, 'site has no default_product_cat');
    $csv = $writeCsv([$pathA, $pathB, '']);

    // 1. category_mode=none
    $init = $runJob($csv, 'none');
    $check(in_array('Product category', $init['ignored_cols'], true) && $init['category_mode'] === 'none', 'none: column must be reported');
    $a = $pid($handles[0]);
    $check($a > 0 && $cats($a) === [$default], 'none: product must have the default category only');
    $check(!term_exists($leaf, 'product_cat'), 'none: no category may be created');
    echo "  [OK] category_mode=none: unmapped, reported, default category only\n";

    // 2-3. category_mode=path (same file: the fingerprint includes the mode, so nothing is skipped)
    $init = $runJob($csv, 'path');
    $check(!in_array('Product category', $init['ignored_cols'], true) && $init['category_mode'] === 'path', 'path: column must not be reported as ignored');
    $rootTerm = term_exists($root, 'product_cat', 0);
    $check(is_array($rootTerm), 'root term not created (entities must be decoded)');
    $midTerm = term_exists($mid, 'product_cat', (int)$rootTerm['term_id']);
    $check(is_array($midTerm), 'mid term must be a child of root');
    $leafTerm = term_exists($leaf, 'product_cat', (int)$midTerm['term_id']);
    $check(is_array($leafTerm), 'leaf term must be a child of mid');
    $leafId = (int)$leafTerm['term_id'];
    $a = $pid($handles[0]);
    $b = $pid($handles[1]);
    $c = $pid($handles[2]);
    $check($cats($a) === [$leafId], 'A must be filed under the leaf only: ' . wp_json_encode($cats($a)));
    $check($cats($b) === [$leafId], 'B (case/spacing variant) must reuse the same leaf: ' . wp_json_encode($cats($b)));
    $check($cats($c) === [$default], 'C (empty cell) keeps the default category');
    $created = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = 'product_cat' AND t.name LIKE %s",
        '%' . $wpdb->esc_like($uid) . '%'
    ));
    $check($created === 3, "exactly 3 categories expected, got {$created}");
    echo "  [OK] category_mode=path: Root > Mid > Leaf tree, leaf assigned, default dropped, terms reused\n";

    // 4. A hand-set category survives a path re-import.
    $handTerm = wp_insert_term($hand, 'product_cat');
    $check(!is_wp_error($handTerm), 'could not create hand category');
    wp_set_object_terms($a, [(int)$handTerm['term_id'], $leafId], 'product_cat');
    $runJob($writeCsv([$pathA, $pathB, '']), 'path');
    $expected = [(int)$handTerm['term_id'], $leafId];
    sort($expected);
    $check($cats($a) === $expected, 'hand-set category must stay: ' . wp_json_encode($cats($a)));
    echo "  [OK] re-import keeps a hand-set category next to the leaf\n";
} finally {
    foreach ($handles as $h) {
        $id = $pid($h);
        if ($id > 0) {
            wp_delete_post($id, true);
        }
    }
    foreach ($skus as $sku) {
        $wpdb->delete("{$prefix}wpe_sku_registry", ['original_sku' => $sku]);
    }
    $termIds = $wpdb->get_col($wpdb->prepare(
        "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = 'product_cat' AND t.name LIKE %s ORDER BY tt.parent DESC",
        '%' . $wpdb->esc_like($uid) . '%'
    ));
    foreach ($termIds as $termId) {
        wp_delete_term((int)$termId, 'product_cat');
    }
    foreach ($jobs as $jid) {
        $wpdb->delete("{$prefix}wpe_jobs", ['id' => $jid]);
        @unlink(WP_CONTENT_DIR . "/uploads/wpe-imports/logs/import_{$jid}.log");
    }
    foreach ($files as $f) {
        @unlink($f);
    }
}

echo "ALL SHOPIFY IMPORT CATEGORY PATH ASSERTIONS PASSED ({$checks} checks)\n";
