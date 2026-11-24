<?php
/**
 * P039.2 — Bulk product purge (plan, start, batched run) through the Operations action.
 *
 * 1. Four variable products are imported (two in category A, one in B, one draft in B).
 * 2. Plan by category A: counts products and variations; a product in an order is skipped (D01).
 * 3. Guards: unknown scope, wrong confirmation and a pending Shopify import refuse to start (D03, D05).
 * 4. Start + run in one-product batches: the job stays in progress until the selection is empty,
 *    then completes; the purged product leaves no shell, variation, meta, term row, lookup row or
 *    native row; the ordered product and the other products are untouched; the category count is
 *    recounted; start and completion are audited.
 * 5. Scope by post status and by import job select the expected products.
 * 6. A run as an administrator (WooCommerce delete_post handler active) also purges cleanly.
 *
 * Run: wp --require=tests/performance/use-test-db.php eval-file tests/integration/ProductBulkPurgeTest.php
 */

use WPE\Admin\OperationsPage;
use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;

global $wpdb;
$p = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('ProductBulkPurge failed: ' . $msg);
    }
    $checks++;
};
$throws = static function (callable $fn, string $needle) use ($check): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        $check(stripos($e->getMessage(), $needle) !== false, "expected error containing '{$needle}', got: " . $e->getMessage());
        return;
    }
    $check(false, "expected an error containing '{$needle}'");
};

$uid = strtolower(wp_generate_password(6, false));
$catA = "P039 Bulk A {$uid}";
$catB = "P039 Bulk B {$uid}";
$items = [
    ["p039-bulk-a1-{$uid}", $catA, 'active'],
    ["p039-bulk-a2-{$uid}", $catA, 'active'],
    ["p039-bulk-b1-{$uid}", $catB, 'active'],
    ["p039-bulk-b2-{$uid}", $catB, 'draft'],
];
$headers = ['URL handle', 'Title', 'Description', 'Vendor', 'Product category', 'Type', 'Tags', 'Status', 'Option1 name', 'Option1 value', 'SKU', 'Price'];
$csv = sys_get_temp_dir() . '/p039_bulk_' . wp_generate_password(8, false) . '.csv';
$fh = fopen($csv, 'w');
fputcsv($fh, $headers, ',', '"', '\\');
foreach ($items as $i => [$handle, $cat, $status]) {
    foreach (['S', 'M'] as $j => $size) {
        $row = [
            'URL handle' => $handle, 'Title' => $j === 0 ? "P039 Bulk {$i}" : '', 'Description' => '', 'Vendor' => '',
            'Product category' => $j === 0 ? $cat : '', 'Type' => '', 'Tags' => '', 'Status' => $j === 0 ? $status : '',
            'Option1 name' => $j === 0 ? 'Size' : '', 'Option1 value' => $size,
            'SKU' => strtoupper("{$handle}-{$size}"), 'Price' => '15.00',
        ];
        fputcsv($fh, array_map(static fn($h) => (string)$row[$h], $headers), ',', '"', '\\');
    }
}
fclose($fh);

$ops = OperationsPage::getInstance();
$action = static fn(array $params) => $ops->handleAction('purge_products', $params, 0);
$importJob = '';
$fakeJob = '';
$purgeJobs = [];
$orderId = 0;
$ids = [];

$leftovers = static function (array $productIds, array $variationIds) use ($wpdb, $p): array {
    $all = implode(',', array_merge($productIds, $variationIds));
    $inP = implode(',', $productIds);
    $q = static fn(string $sql): int => (int)$wpdb->get_var($sql);
    return array_filter([
        'posts'          => $q("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ({$all})"),
        'postmeta'       => $q("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$all})"),
        'term_rows'      => $q("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ({$all})"),
        'meta_lookup'    => $q("SELECT COUNT(*) FROM {$p}wc_product_meta_lookup WHERE product_id IN ({$all})"),
        'wpe_products'   => $q("SELECT COUNT(*) FROM {$p}wpe_products WHERE id IN ({$inP})"),
        'wpe_variants'   => $q("SELECT COUNT(*) FROM {$p}wpe_variants WHERE product_id IN ({$inP})"),
        'wpe_options'    => $q("SELECT COUNT(*) FROM {$p}wpe_options WHERE product_id IN ({$inP})"),
        'wpe_inventory'  => $q("SELECT COUNT(*) FROM {$p}wpe_inventory WHERE owner_id IN ({$all})"),
        'wpe_state'      => $q("SELECT COUNT(*) FROM {$p}wpe_object_state WHERE object_id IN ({$all})"),
        'wpe_catalog'    => $q("SELECT COUNT(*) FROM {$p}wpe_catalog WHERE product_id IN ({$inP})"),
        'fingerprints'   => $q("SELECT COUNT(*) FROM {$p}wpe_control WHERE `key` IN ('" . implode("','", array_map(static fn($id) => "shopify_import_fp:{$id}", $productIds)) . "')"),
    ]);
};
$variationsOf = static fn(int $id): array => array_map('intval', $wpdb->get_col("SELECT id FROM {$p}wpe_variants WHERE product_id = {$id}"));
$runToEnd = static function (string $jobId) use ($action): array {
    for ($i = 0; $i < 50; $i++) {
        $res = $action(['mode' => 'run', 'job_id' => $jobId, 'batch_size' => 1, 'max_batches' => 1]);
        if ($res['status'] === 'completed') {
            return $res;
        }
    }
    throw new \RuntimeException('purge job did not complete');
};

try {
    echo "=== ProductBulkPurgeTest ===\n";

    // 1. Import.
    $importer = new ShopifyBatchImporter();
    $init = $importer->initJob($csv, ['batch_size' => 25, 'skip_unchanged' => true]);
    $importJob = (string)$init['job_id'];
    for ($i = 0; $i < 20; $i++) {
        $res = $importer->processBatch($importJob, 25);
        if (!empty($res['completed'])) {
            break;
        }
    }
    foreach ($items as [$handle]) {
        $ids[] = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}wpe_products WHERE slug = %s", $handle));
    }
    [$a1, $a2, $b1, $b2] = $ids;
    $check(min($ids) > 0, 'import must create four products');
    $termA = get_term_by('name', $catA, 'product_cat');
    $check($termA instanceof \WP_Term, 'category A must exist');
    $check(get_post_status($b2) === 'draft', 'b2 must be a draft');

    // 2. Plan by category; an order on a1 skips it.
    $critA = ['scope' => 'category', 'category' => $termA->slug];
    $plan = $action(['mode' => 'plan', 'criteria' => $critA]);
    $check($plan['products'] === 2 && $plan['variations'] === 4 && $plan['native_products'] === 2, 'plan A before order: ' . wp_json_encode($plan));

    $order = wc_create_order();
    $order->add_product(wc_get_product($variationsOf($a1)[0]), 1);
    $order->save();
    $orderId = (int)$order->get_id();
    $plan = $action(['mode' => 'plan', 'criteria' => $critA]);
    $check($plan['products'] === 1 && $plan['skipped_ordered'] === 1 && $plan['variations'] === 2, 'plan A with order: ' . wp_json_encode($plan));
    $planAll = $action(['mode' => 'plan', 'criteria' => $critA + ['include_ordered' => true]]);
    $check($planAll['products'] === 2, 'include_ordered must select the ordered product too');
    echo "  [OK] Plan counts products/variations and skips ordered products unless include_ordered\n";

    // 3. Guards.
    $throws(fn() => $action(['mode' => 'plan', 'criteria' => ['scope' => 'everything']]), 'scope must be one of');
    $throws(fn() => $action(['mode' => 'start', 'criteria' => $critA, 'confirm' => '2']), 'Confirmation must be');
    $fakeJob = wp_generate_uuid4();
    $now = gmdate('Y-m-d H:i:s.u');
    $wpdb->insert("{$p}wpe_jobs", ['id' => $fakeJob, 'kind' => 'shopify_import', 'status' => 'in_progress', 'config' => '{}', 'checkpoint' => '{}', 'counts' => '{}', 'created_at' => $now, 'updated_at' => $now]);
    $throws(fn() => $action(['mode' => 'start', 'criteria' => $critA, 'confirm' => '1']), 'Shopify import is pending or running');
    $wpdb->delete("{$p}wpe_jobs", ['id' => $fakeJob]);
    echo "  [OK] Unknown scope, wrong confirmation and a running import refuse to start\n";

    // 4. Start + batched run.
    $termCountBefore = (int)get_term($termA->term_id, 'product_cat')->count;
    $a2Variations = $variationsOf($a2);
    $start = $action(['mode' => 'start', 'criteria' => $critA, 'confirm' => '1']);
    $purgeJobs[] = $start['job_id'];
    $first = $action(['mode' => 'run', 'job_id' => $start['job_id'], 'batch_size' => 1, 'max_batches' => 1]);
    $check($first['status'] === 'in_progress' && $first['counts']['products'] === 1, 'first batch: ' . wp_json_encode($first));
    $done = $runToEnd($start['job_id']);
    $check($done['status'] === 'completed' && $done['counts']['products'] === 1 && $done['counts']['variations'] === 2, 'completed: ' . wp_json_encode($done));
    $left = $leftovers([$a2], $a2Variations);
    $check($left === [], 'purged product left rows: ' . wp_json_encode($left));
    foreach ([$a1, $b1, $b2] as $keep) {
        $check(get_post($keep) instanceof \WP_Post && count($variationsOf($keep)) === 2, "product {$keep} must be untouched");
    }
    clean_term_cache($termA->term_id, 'product_cat');
    $check((int)get_term($termA->term_id, 'product_cat')->count === $termCountBefore - 1, 'category count must be recounted');
    $audits = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}wpe_audit WHERE operation IN ('product_purge_started', 'product_purge_completed') AND summary LIKE %s",
        '%' . $wpdb->esc_like($start['job_id']) . '%'
    ));
    $check($audits === 2, 'start and completion must be audited');
    $again = $action(['mode' => 'run', 'job_id' => $start['job_id']]);
    $check($again['status'] === 'completed', 'a completed job stays completed');
    echo "  [OK] Batched run completes, purges everything of the selected product and keeps the rest\n";

    // 5. Other scopes.
    $planDraft = $action(['mode' => 'plan', 'criteria' => ['scope' => 'status', 'status' => 'draft']]);
    $draftIds = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'draft'"));
    $check(in_array($b2, $draftIds, true) && $planDraft['products'] >= 1, 'status scope must include the draft');
    $planJob = $action(['mode' => 'plan', 'criteria' => ['scope' => 'import_job', 'import_job' => $importJob]]);
    $check($planJob['products'] === 2 && $planJob['skipped_ordered'] === 1, 'import job scope: ' . wp_json_encode($planJob));
    $check(!isset($planJob['criteria']['product_ids']), 'plan output must not carry the resolved ID list');
    echo "  [OK] Status and import-job scopes select the expected products\n";

    // 6. Purge the rest of the import as an administrator.
    $admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $prevUser = get_current_user_id();
    if ($admin) {
        wp_set_current_user((int)$admin[0]);
    }
    $restVariations = array_merge($variationsOf($b1), $variationsOf($b2));
    $startJob = $action(['mode' => 'start', 'criteria' => ['scope' => 'import_job', 'import_job' => $importJob], 'confirm' => '2']);
    $purgeJobs[] = $startJob['job_id'];
    $done = $runToEnd($startJob['job_id']);
    wp_set_current_user($prevUser);
    $check($done['counts']['products'] === 2, 'import-job purge must delete b1 and b2');
    $left = $leftovers([$b1, $b2], $restVariations);
    $check($left === [], 'admin-run purge left rows: ' . wp_json_encode($left));
    $check(get_post($a1) instanceof \WP_Post, 'the ordered product survives the import-job purge');
    echo "  [OK] Import-job purge as administrator removes everything and still skips the ordered product\n";

    echo "ProductBulkPurgeTest: PASS ({$checks} checks)\n";
} finally {
    if ($orderId) {
        $o = wc_get_order($orderId);
        if ($o) {
            $o->delete(true);
        }
    }
    foreach ($ids as $id) {
        if ($id > 0 && get_post($id)) {
            wp_delete_post($id, true);
        }
    }
    foreach ([$catA, $catB] as $name) {
        $t = get_term_by('name', $name, 'product_cat');
        if ($t) {
            wp_delete_term($t->term_id, 'product_cat');
        }
    }
    foreach (array_filter(array_merge([$importJob, $fakeJob], $purgeJobs)) as $jid) {
        $wpdb->delete("{$p}wpe_jobs", ['id' => $jid]);
    }
    @unlink($csv);
}
