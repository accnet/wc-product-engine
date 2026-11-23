<?php
/**
 * P031 — Shopify import taxonomy: tags, default category, ignored-column report (docs/10 §7).
 *
 * 1. initJob lists present-but-unmapped columns (Product category, Type, Vendor) in ignored_cols and
 *    in a WARNING line of the initial log.
 * 2. Import: Tags become product_tag (trimmed, case-insensitive de-dup, HTML entities decoded);
 *    a product without category gets default_product_cat; one without tags gets none.
 * 3. Re-import with an empty Tags cell keeps the tags and a hand-set category (no default added).
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/ShopifyImportTaxonomyTest.php
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
        throw new \RuntimeException('ShopifyImportTaxonomy failed: ' . $msg);
    }
    $checks++;
};

$uid = strtolower(wp_generate_password(6, false));
$handleA = "p031-tax-a-{$uid}";
$handleB = "p031-tax-b-{$uid}";
$tagGift = "P031 Gift {$uid}";
$tagHoodie = "P031 Hoodie & Co {$uid}";
$keepTag = "P031 Keep {$uid}";
$handCat = "P031 Hand Category {$uid}";

$headers = ['URL handle', 'Title', 'Description', 'Vendor', 'Product category', 'Type', 'Tags', 'Status', 'Option1 name', 'Option1 value', 'SKU', 'Price'];
$writeCsv = static function (array $rows) use ($headers): string {
    $path = sys_get_temp_dir() . '/p031_tax_' . wp_generate_password(8, false) . '.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fh, array_map(static fn($h) => (string)($row[$h] ?? ''), $headers), ',', '"', '\\');
    }
    fclose($fh);
    return $path;
};
$base = static fn(string $handle, string $title, string $tags, string $sku): array => [
    'URL handle' => $handle, 'Title' => $title, 'Description' => '<p>P031</p>', 'Vendor' => 'P031 Vendor',
    'Product category' => 'Apparel &amp; Accessories > Clothing > Hoodies', 'Type' => 'Hoodie', 'Tags' => $tags,
    'Status' => 'active', 'Option1 name' => 'Title', 'Option1 value' => 'Default Title', 'SKU' => $sku, 'Price' => '19.99',
];

$importer = new ShopifyBatchImporter();
$jobs = [];
$files = [];
$runJob = static function (string $csv, array $options = []) use ($importer, &$jobs): array {
    $init = $importer->initJob($csv, $options + ['batch_size' => 25, 'skip_unchanged' => false]);
    $jobs[] = $init['job_id'];
    for ($i = 0; $i < 20; $i++) {
        $res = $importer->processBatch($init['job_id'], 25);
        if (!empty($res['completed'])) {
            break;
        }
    }
    return $init;
};
$productId = static fn(string $handle): int => (int)$wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$prefix}wpe_products WHERE slug = %s AND deleted_at IS NULL",
    $handle
));
$termNames = static function (int $pid, string $tax): array {
    // WordPress stores term names HTML-escaped (`&` as `&amp;`); compare the display text.
    $names = array_map(
        static fn($n) => html_entity_decode((string)$n, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        wp_get_object_terms($pid, $tax, ['fields' => 'names'])
    );
    sort($names);
    return $names;
};

$skuA = 'P031-A-' . strtoupper($uid);
$skuB = 'P031-B-' . strtoupper($uid);
$defaultCat = (int)get_option('default_product_cat', 0);
$defaultName = $defaultCat ? html_entity_decode(get_term($defaultCat, 'product_cat')->name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

try {
    $check($defaultCat > 0, 'site has no default_product_cat');

    // 1-2. First import.
    $files[] = $csv1 = $writeCsv([
        $base($handleA, 'P031 Taxonomy A', "{$tagGift}, " . str_replace('&', '&amp;', $tagHoodie) . ', ' . strtolower($tagGift), $skuA),
        $base($handleB, 'P031 Taxonomy B', '', $skuB),
    ]);
    $init = $runJob($csv1);
    $check(
        in_array('Product category', $init['ignored_cols'], true) && in_array('Type', $init['ignored_cols'], true) && in_array('Vendor', $init['ignored_cols'], true),
        'ignored_cols must list Product category, Type, Vendor: ' . wp_json_encode($init['ignored_cols'])
    );
    $check(str_contains($init['initial_log'], '[WARNING] Columns not imported') && str_contains($init['initial_log'], 'Product category'),
        'initial log must warn about unmapped columns');
    echo "  [OK] unmapped columns reported in ignored_cols and the job log\n";

    $a = $productId($handleA);
    $b = $productId($handleB);
    $check($a > 0 && $b > 0, 'fixture products not imported');
    $expectedTags = [$tagGift, $tagHoodie];
    sort($expectedTags);
    $check($termNames($a, 'product_tag') === $expectedTags, 'A tags: ' . wp_json_encode($termNames($a, 'product_tag')));
    $check($termNames($b, 'product_tag') === [], 'B must have no tags');
    $check($termNames($a, 'product_cat') === [$defaultName] && $termNames($b, 'product_cat') === [$defaultName],
        'both products must get the default category');
    echo "  [OK] tags -> product_tag (de-duplicated, entities decoded); default category assigned\n";

    // 3. Hand-set category and tag on B, then re-import with an empty Tags cell.
    $cat = wp_insert_term($handCat, 'product_cat');
    $check(!is_wp_error($cat), 'could not create hand category');
    wp_set_object_terms($b, [(int)$cat['term_id']], 'product_cat');
    wp_set_object_terms($b, [$keepTag], 'product_tag');
    $files[] = $csv2 = $writeCsv([$base($handleB, 'P031 Taxonomy B', '', $skuB)]);
    $runJob($csv2);
    $check($termNames($b, 'product_cat') === [$handCat], 'hand-set category must stay without the default: ' . wp_json_encode($termNames($b, 'product_cat')));
    $check($termNames($b, 'product_tag') === [$keepTag], 'empty Tags cell must not clear tags');
    echo "  [OK] re-import keeps hand-set category and existing tags\n";
} finally {
    foreach ([$handleA, $handleB] as $h) {
        $pid = $productId($h);
        if ($pid > 0) {
            wp_delete_post($pid, true);
        }
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$prefix}wpe_sku_registry WHERE original_sku IN (%s, %s)",
        $skuA,
        $skuB
    ));
    foreach ([$tagGift, $tagHoodie, $keepTag] as $name) {
        $t = get_term_by('name', $name, 'product_tag');
        if ($t) {
            wp_delete_term($t->term_id, 'product_tag');
        }
    }
    $t = get_term_by('name', $handCat, 'product_cat');
    if ($t) {
        wp_delete_term($t->term_id, 'product_cat');
    }
    foreach ($jobs as $jid) {
        $wpdb->delete("{$prefix}wpe_jobs", ['id' => $jid]);
        @unlink(WP_CONTENT_DIR . "/uploads/wpe-imports/logs/import_{$jid}.log");
    }
    foreach ($files as $f) {
        @unlink($f);
    }
}

echo "ALL SHOPIFY IMPORT TAXONOMY ASSERTIONS PASSED ({$checks} checks)\n";
