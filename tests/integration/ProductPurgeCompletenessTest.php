<?php
/**
 * P039.1 — A permanent product delete removes every native row of the product and its variants.
 *
 * 1. Two variable products are imported from a Shopify CSV (options, values, variant values, terms,
 *    meta, inventory, SKUs, object state, import fingerprints) and get extra rows: variant meta,
 *    an external ID, a link from a kept product, catalog rows in two generations.
 * 2. Product A is deleted through WordPress (`wp_delete_post`, the lifecycle hook path); product B
 *    through `ProductRepository::delete($id, true)`. No native row, fingerprint or shell is left,
 *    and the kept product keeps all its rows.
 * 3. Option and option-value inserts move on to the next ID when the computed ID is already taken
 *    (simulated concurrent writer), instead of failing with a duplicate PRIMARY key.
 *
 * Run: wp --require=tests/performance/use-test-db.php eval-file tests/integration/ProductPurgeCompletenessTest.php
 */

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

global $wpdb;
$p = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('ProductPurgeCompleteness failed: ' . $msg);
    }
    $checks++;
};

$uid = strtolower(wp_generate_password(6, false));
$handles = ["p039-purge-a-{$uid}", "p039-purge-b-{$uid}", "p039-keep-{$uid}"];
$headers = ['URL handle', 'Title', 'Description', 'Vendor', 'Product category', 'Type', 'Tags', 'Status', 'Option1 name', 'Option1 value', 'Option2 name', 'Option2 value', 'SKU', 'Price'];
$csv = sys_get_temp_dir() . '/p039_purge_' . wp_generate_password(8, false) . '.csv';
$fh = fopen($csv, 'w');
fputcsv($fh, $headers, ',', '"', '\\');
foreach ($handles as $i => $handle) {
    foreach (['Hoodie', 'Zip Hoodie'] as $style) {
        foreach (['S', 'M'] as $size) {
            $first = ($style === 'Hoodie' && $size === 'S');
            $row = [
                'URL handle' => $handle, 'Title' => $first ? "P039 Purge {$i}" : '', 'Description' => $first ? '<p>P039</p>' : '',
                'Vendor' => $first ? 'V' : '', 'Product category' => $first ? "P039 Cat {$uid}" : '', 'Type' => '', 'Tags' => $first ? "p039-{$uid}" : '',
                'Status' => $first ? 'active' : '', 'Option1 name' => $first ? 'Style' : '', 'Option1 value' => $style,
                'Option2 name' => $first ? 'Size' : '', 'Option2 value' => $size,
                'SKU' => strtoupper("{$handle}-" . str_replace(' ', '', $style) . "-{$size}"), 'Price' => '19.99',
            ];
            fputcsv($fh, array_map(static fn($h) => (string)$row[$h], $headers), ',', '"', '\\');
        }
    }
}
fclose($fh);

$jobId = '';
$created = [];
$optionIdsCreated = [];
$valueIdsCreated = [];
$filter = null;

/** Count every native row owned by a product, its variants and options. */
$footprint = static function (int $productId, array $variantIds, array $optionIds) use ($wpdb, $p): array {
    $owners = implode(',', array_merge([$productId], $variantIds));
    $inV = $variantIds ? implode(',', $variantIds) : '0';
    $inOpt = $optionIds ? implode(',', $optionIds) : '0';
    $q = static fn(string $sql): int => (int)$wpdb->get_var($sql);
    return [
        'products'       => $q("SELECT COUNT(*) FROM {$p}wpe_products WHERE id = {$productId}"),
        'variants'       => $q("SELECT COUNT(*) FROM {$p}wpe_variants WHERE product_id = {$productId}"),
        'options'        => $q("SELECT COUNT(*) FROM {$p}wpe_options WHERE id IN ({$inOpt}) OR product_id = {$productId}"),
        'option_values'  => $q("SELECT COUNT(*) FROM {$p}wpe_option_values WHERE option_id IN ({$inOpt})"),
        'variant_values' => $q("SELECT COUNT(*) FROM {$p}wpe_variant_values WHERE variant_id IN ({$inV}) OR option_id IN ({$inOpt})"),
        'variant_media'  => $q("SELECT COUNT(*) FROM {$p}wpe_variant_media WHERE variant_id IN ({$inV})"),
        'product_terms'  => $q("SELECT COUNT(*) FROM {$p}wpe_product_terms WHERE product_id = {$productId}"),
        'meta'           => $q("SELECT COUNT(*) FROM {$p}wpe_meta WHERE object_id IN ({$owners})"),
        'links'          => $q("SELECT COUNT(*) FROM {$p}wpe_product_links WHERE product_id = {$productId} OR target_id IN ({$owners})"),
        'sku_registry'   => $q("SELECT COUNT(*) FROM {$p}wpe_sku_registry WHERE object_id IN ({$owners})"),
        'inventory'      => $q("SELECT COUNT(*) FROM {$p}wpe_inventory WHERE owner_id IN ({$owners})"),
        'object_state'   => $q("SELECT COUNT(*) FROM {$p}wpe_object_state WHERE object_id IN ({$owners}) OR parent_id = {$productId}"),
        'external_ids'   => $q("SELECT COUNT(*) FROM {$p}wpe_external_ids WHERE object_id IN ({$owners})"),
        'catalog'        => $q("SELECT COUNT(*) FROM {$p}wpe_catalog WHERE product_id = {$productId}"),
        'catalog_facets' => $q("SELECT COUNT(*) FROM {$p}wpe_catalog_facets WHERE product_id = {$productId}"),
        'fingerprint'    => $q("SELECT COUNT(*) FROM {$p}wpe_control WHERE `key` = 'shopify_import_fp:{$productId}'"),
        'shells'         => $q("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$owners})"),
    ];
};

try {
    echo "=== ProductPurgeCompletenessTest ===\n";

    // 1. Import three variable products and decorate them with extra native rows.
    $importer = new ShopifyBatchImporter();
    $init = $importer->initJob($csv, ['batch_size' => 25, 'skip_unchanged' => true]);
    $jobId = (string)$init['job_id'];
    for ($i = 0; $i < 20; $i++) {
        $res = $importer->processBatch($jobId, 25);
        if (!empty($res['completed'])) {
            break;
        }
    }
    $ids = [];
    foreach ($handles as $handle) {
        $ids[] = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}wpe_products WHERE slug = %s", $handle));
    }
    [$pidA, $pidB, $pidKeep] = $ids;
    $created = $ids;
    $check($pidA > 0 && $pidB > 0 && $pidKeep > 0, 'import must create the three products');

    $variants = [];
    $options = [];
    foreach ($ids as $id) {
        $variants[$id] = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}wpe_variants WHERE product_id = {$id}"));
        $options[$id] = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}wpe_options WHERE product_id = {$id}"));
        $check(count($variants[$id]) === 4, "product {$id} must have 4 variants");
        $check(count($options[$id]) === 2, "product {$id} must have 2 options");
    }

    $now = gmdate('Y-m-d H:i:s.u');
    foreach ([$pidA, $pidB] as $id) {
        $vid = $variants[$id][0];
        $metaId = (int)$wpdb->get_var("SELECT COALESCE(MAX(meta_id), 0) + 1 FROM {$p}wpe_meta");
        $wpdb->insert("{$p}wpe_meta", ['meta_id' => $metaId, 'object_id' => $vid, 'object_kind' => 'variant', 'meta_key' => '_p039_note', 'meta_value' => 'x']);
        $wpdb->replace("{$p}wpe_product_terms", ['product_id' => $id, 'term_taxonomy_id' => 999039, 'role' => 'p039']);
        $wpdb->insert("{$p}wpe_external_ids", ['source' => 'p039', 'source_scope' => $uid, 'external_key' => "ext-{$id}", 'object_kind' => 'product', 'object_id' => $id]);
        $wpdb->insert("{$p}wpe_product_links", ['product_id' => $pidKeep, 'kind' => 'upsell', 'target_id' => $id, 'position' => 0]);
        $wpdb->insert("{$p}wpe_catalog", ['generation' => 999001, 'product_id' => $id, 'source_version' => 1, 'type' => 'variable', 'status' => 'publish', 'visibility' => 'visible', 'created_at' => $now, 'indexed_at' => $now]);
        $wpdb->insert("{$p}wpe_catalog_facets", ['generation' => 999001, 'facet_key' => 'p039', 'value_key' => 'x', 'product_id' => $id, 'variant_id' => $vid]);
    }
    $keepBefore = $footprint($pidKeep, $variants[$pidKeep], $options[$pidKeep]);

    foreach ([$pidA, $pidB] as $id) {
        $fp = $footprint($id, $variants[$id], $options[$id]);
        foreach (['products', 'variants', 'options', 'option_values', 'variant_values', 'product_terms', 'meta', 'links', 'sku_registry', 'inventory', 'object_state', 'external_ids', 'catalog', 'catalog_facets', 'fingerprint', 'shells'] as $k) {
            $check($fp[$k] > 0, "setup: product {$id} must own {$k} rows before the delete");
        }
    }
    echo "  [OK] Setup: two variable products own rows in every native table\n";

    // 2a. Delete through WordPress (lifecycle hook path).
    wp_delete_post($pidA, true);
    $left = array_filter($footprint($pidA, $variants[$pidA], $options[$pidA]));
    $check($left === [], 'wp_delete_post left rows: ' . wp_json_encode($left));
    echo "  [OK] wp_delete_post removes every native row, fingerprint and shell\n";

    // 2b. Delete through the repository; shells are the caller's job here.
    (new ProductRepository($wpdb))->delete($pidB, true);
    $left = $footprint($pidB, $variants[$pidB], $options[$pidB]);
    unset($left['shells']);
    $left = array_filter($left);
    $check($left === [], 'ProductRepository::delete left rows: ' . wp_json_encode($left));
    echo "  [OK] ProductRepository::delete(force) removes every native row\n";

    $keepAfter = $footprint($pidKeep, $variants[$pidKeep], $options[$pidKeep]);
    $keepBefore['links'] -= 2; // the two upsell links pointed at the purged products
    $check($keepAfter == $keepBefore, 'kept product lost rows: ' . wp_json_encode([$keepBefore, $keepAfter]));
    echo "  [OK] Another product keeps all its rows (only links to purged products go)\n";

    // 3. Option / value IDs move on when the computed ID is taken by a concurrent writer.
    $repo = new OptionsRepository($wpdb);
    $stale = static function (string $sql) use ($p): string {
        foreach (["{$p}wpe_options", "{$p}wpe_option_values"] as $table) {
            if ($sql === "SELECT COALESCE(MAX(id), 0) FROM {$table}") {
                // Pretend the newest row is not visible yet: the first candidate collides.
                return "SELECT COALESCE(MAX(id), 0) - 1 FROM {$table}";
            }
        }
        return $sql;
    };
    $filter = $stale;
    add_filter('query', $filter);
    $maxBefore = (int)$wpdb->get_var("SELECT MAX(id) FROM {$p}wpe_options");
    $opt = $repo->createOption($pidKeep, ['name' => "P039 Extra {$uid}", 'values' => ['One', 'Two']]);
    remove_filter('query', $filter);
    $filter = null;
    $optionIdsCreated[] = (int)$opt['id'];
    foreach (($opt['values'] ?? []) as $v) {
        $valueIdsCreated[] = (int)$v['id'];
    }
    $check((int)$opt['id'] > $maxBefore, 'option must get a free ID above the taken one, got ' . (int)$opt['id']);
    $check(count($valueIdsCreated) === 2, 'both values must be created despite the collision');
    echo "  [OK] Option and value inserts retry with the next ID on a duplicate PRIMARY key\n";

    echo "ProductPurgeCompletenessTest: PASS ({$checks} checks)\n";
} finally {
    if ($filter) {
        remove_filter('query', $filter);
    }
    foreach ($created as $id) {
        if ($id > 0 && get_post($id)) {
            wp_delete_post($id, true);
        }
    }
    foreach ($optionIdsCreated as $oid) {
        $wpdb->delete("{$p}wpe_option_values", ['option_id' => $oid]);
        $wpdb->delete("{$p}wpe_options", ['id' => $oid]);
    }
    $wpdb->delete("{$p}wpe_external_ids", ['source' => 'p039']);
    $wpdb->delete("{$p}wpe_product_terms", ['term_taxonomy_id' => 999039]);
    $wpdb->delete("{$p}wpe_catalog", ['generation' => 999001]);
    $wpdb->delete("{$p}wpe_catalog_facets", ['generation' => 999001]);
    if ($jobId !== '') {
        $wpdb->delete("{$p}wpe_import_rows", ['job_id' => $jobId]);
        $wpdb->delete("{$p}wpe_jobs", ['id' => $jobId]);
    }
    @unlink($csv);
}
