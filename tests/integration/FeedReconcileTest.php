<?php
/**
 * P035.7 — Scheduled Reconciliation, drift escalation and worker heartbeat.
 *
 * Fixture: three native products in their own category; a feed limited to it.
 * Verifies: products outside the filter store no items; a price changed directly in the database
 * (no change signal) is found by a reconciliation cycle, counted as drift and opens the debounce
 * window; an item whose product no longer exists is removed; drift above 1% requests a full rebuild,
 * a clean cycle does not; the cursor advances across ticks and wraps at the end of the catalog; the
 * tick records a heartbeat.
 *
 * Run: wp eval-file tests/integration/FeedReconcileTest.php
 */

use WPE\Application\Feeds\FeedBuilder;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\ProductRepository;

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedReconcileTest failed: ' . $msg);
    }
    $checks++;
};

$builder = new FeedBuilder($wpdb);
$feeds = $builder->feeds();
$store = $builder->store();
$scheduler = new FeedScheduler($builder);
$repo = new ProductRepository($wpdb);
$media = new MediaRepository($wpdb);
$tag = strtolower(wp_generate_password(6, false));
$feedId = 0;
$products = [];
$assets = [];
$term = wp_insert_term("Reconcile {$tag}", 'product_cat');

$sliceFilter = static fn() => 100;
$loopFilter = static fn() => true;

$fullCycle = static function () use ($scheduler, $feeds, &$feedId): array {
    add_filter('wpe_feed_reconcile_continue', '__return_true');
    try {
        return $scheduler->reconcileSlice($feeds->get($feedId), 120.0);
    } finally {
        remove_filter('wpe_feed_reconcile_continue', '__return_true');
    }
};

try {
    echo "=== FeedReconcileTest ===\n";
    foreach (['One', 'Two', 'Three'] as $i => $name) {
        $title = "Reconcile {$name} {$tag}";
        $created = $repo->create(['title' => $title, 'slug' => sanitize_title($title), 'type' => 'simple', 'status' => 'publish',
            'sku' => "REC-{$i}-{$tag}", 'regular_price' => (string)(10 + $i), 'description' => 'x']);
        $id = (int)$created['id'];
        $check($id > 0 && $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $id)) === $title, 'fixture');
        $products[] = $id;
        $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $id]);
        wp_set_object_terms($id, [(int)$term['term_id']], 'product_cat');
        $asset = $media->createAsset(['source_url' => "https://cdn.example.com/{$tag}-{$i}.jpg"]);
        $assets[] = (int)$asset['id'];
        $media->setFeaturedImage($id, (int)$asset['id']);
    }

    $feed = $feeds->create(['name' => "Reconcile {$tag}", 'platform' => 'google', 'format' => 'tsv', 'enabled' => true,
        'config' => ['filters' => ['include_categories' => [(int)$term['term_id']]], 'mapping' => ['brand' => ['source' => 'static', 'value' => 'R']]]]);
    $feedId = (int)$feed['id'];
    do {
        $res = $builder->buildStep($feedId, 30.0);
    } while ($res['status'] === 'building');
    $gen = (int)$feeds->get($feedId)['active_generation'];
    $check($store->counts($feedId, $gen)['total'] === 3, 'products outside the filter must store no items, got ' . $store->counts($feedId, $gen)['total']);
    echo "  [OK] filtered feed stores only its 3 items\n";

    add_filter('wpe_feed_reconcile_slice', $sliceFilter);

    // 1. A clean cycle: no drift, no rebuild.
    $res = $fullCycle();
    $check(isset($res['cycle_completed']) && $res['cycle_completed']['drift'] === 0 && !isset($res['rebuild_requested']), 'clean cycle: ' . wp_json_encode($res));
    $check($res['cycle_completed']['checked'] === 3, 'cycle must check the 3 items');
    echo "  [OK] clean cycle: 3 checked, no drift, no rebuild\n";

    // 2. Silent DB change + orphan item.
    $wpdb->update("{$prefix}wpe_products", ['regular_price' => '99.00'], ['id' => $products[0]]);
    $ghost = max($products) - 1;
    while (get_post($ghost)) {
        $ghost--;
    }
    $wpdb->insert("{$prefix}wpe_feed_items", ['feed_id' => $feedId, 'generation' => $gen, 'product_id' => $ghost, 'variant_id' => 0,
        'item_id' => 'ghost', 'eligible' => 1, 'payload' => '{"id":"ghost"}', 'payload_hash' => str_repeat("\0", 32), 'issues' => '[]', 'updated_at' => gmdate('Y-m-d H:i:s')]);
    $res = $fullCycle();
    $payload = (string)$wpdb->get_var($wpdb->prepare("SELECT payload FROM {$prefix}wpe_feed_items WHERE feed_id = %d AND generation = %d AND product_id = %d", $feedId, $gen, $products[0]));
    $check(str_contains($payload, '"price":"99.00 USD"'), 'silent price change must be re-rendered');
    $check(!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$prefix}wpe_feed_items WHERE feed_id = %d AND product_id = %d", $feedId, $ghost)), 'orphan item must be removed');
    $check($res['cycle_completed']['drift'] === 2 && ($res['rebuild_requested'] ?? '') === 'reconcile_drift', 'drift 2/3 > 1% must request a rebuild: ' . wp_json_encode($res));
    $check($feeds->get($feedId)['changes_first_at'] !== null, 'drift must open the debounce window');
    echo "  [OK] silent DB change and orphan item found as drift; >1% requests a rebuild\n";

    // 3. Cursor advances across ticks and wraps.
    $feeds->updateRuntime($feedId, ['rebuild_reason' => null]);
    remove_filter('wpe_feed_reconcile_slice', $sliceFilter);
    add_filter('wpe_feed_reconcile_slice', static fn() => 50);
    $first = $scheduler->reconcileSlice($feeds->get($feedId), 30.0);
    $c1 = (int)($feeds->get($feedId)['stats']['reconcile']['cursor'] ?? 0);
    $second = $scheduler->reconcileSlice($feeds->get($feedId), 30.0);
    $c2 = (int)($feeds->get($feedId)['stats']['reconcile']['cursor'] ?? 0);
    $check($first['products'] === 50 && $c1 > 0 && $c2 > $c1, "cursor must advance per slice ({$c1} -> {$c2})");
    $guard = 0;
    do {
        $r = $scheduler->reconcileSlice($feeds->get($feedId), 30.0);
    } while (!isset($r['cycle_completed']) && ++$guard < 1000);
    $check(isset($r['cycle_completed']) && (int)($feeds->get($feedId)['stats']['reconcile']['cursor'] ?? -1) === 0, 'cycle must wrap to cursor 0');
    echo "  [OK] cursor advances 50 products per slice and wraps after {$guard} more slices\n";

    // 4. Heartbeat.
    $scheduler->tick(5.0);
    $hb = FeedScheduler::lastHeartbeat($wpdb);
    $check($hb !== null && $hb['age'] <= 10 && isset($hb['last_duration']), 'tick must record a fresh heartbeat: ' . wp_json_encode($hb));
    echo "  [OK] tick records the worker heartbeat\n";

    echo "ALL FEED RECONCILE ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    if ($feedId > 0) {
        $feeds->delete($feedId);
    }
    foreach ($products as $id) {
        wp_delete_post($id, true);
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}wpe_products WHERE id = %d", $id))) {
            $repo->delete($id, true);
        }
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $id]);
    }
    foreach ($assets as $aid) {
        $wpdb->delete("{$prefix}wpe_product_media", ['asset_id' => $aid]);
        $wpdb->delete("{$prefix}wpe_assets", ['id' => $aid]);
    }
    if ($term && !is_wp_error($term)) {
        wp_delete_term((int)$term['term_id'], 'product_cat');
    }
}
