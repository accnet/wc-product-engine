<?php
/**
 * P035.6 — On Change marks and Debounced Publish.
 *
 * Fixture: two native products in their own category and a Google XML feed limited to it.
 * Verifies: the first tick builds and publishes; a native price update marks the product dirty
 * through the outbox action; the tick re-renders it and opens the debounce window; publish waits
 * for the quiet window and the minimum interval, then the file has the new price; max wait publishes
 * during continuous changes; an unchanged product does not open the window; a purge removes the
 * product's items; renaming the category re-renders its products; a sale that starts later is picked
 * up through next_change; manual mode never auto-publishes; a large dirty set and changed store
 * settings request a full rebuild.
 *
 * Run: wp eval-file tests/integration/FeedIncrementalTest.php
 */

use WPE\Application\Feeds\FeedBuilder;
use WPE\Application\Feeds\FeedChangeTracker;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\ProductRepository;

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedIncrementalTest failed: ' . $msg);
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
$term = null;

FeedChangeTracker::register();

$makeProduct = static function (string $name, string $price) use ($repo, $media, $wpdb, $prefix, $tag, &$products, &$assets, &$term): int {
    $title = "{$name} {$tag}";
    $created = $repo->create(['title' => $title, 'slug' => sanitize_title($title), 'type' => 'simple', 'status' => 'publish',
        'sku' => strtoupper($name) . "-{$tag}", 'regular_price' => $price, 'description' => "{$name} description"]);
    $id = (int)$created['id'];
    if ($id <= 0 || $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $id)) !== $title) {
        throw new \RuntimeException('fixture failed');
    }
    $products[] = $id;
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $id]);
    wp_set_object_terms($id, [(int)$term['term_id']], 'product_cat');
    $asset = $media->createAsset(['source_url' => "https://cdn.example.com/{$tag}-{$name}.jpg"]);
    $assets[] = (int)$asset['id'];
    $media->setFeaturedImage($id, (int)$asset['id']);
    return $id;
};
$fileHas = static function (string $needle) use ($builder, $feeds, &$feedId): bool {
    $path = $builder->publishedFile($feeds->get($feedId));
    return $path !== null && str_contains((string)file_get_contents($path), $needle);
};
$tick = static function (?int $at = null) use ($scheduler, &$feedId): array {
    FeedChangeTracker::flush();
    $scheduler->setClock($at);
    return $scheduler->tickFeed($feedId, 30.0);
};

try {
    echo "=== FeedIncrementalTest ===\n";
    $term = wp_insert_term("Incremental {$tag}", 'product_cat');
    $a = $makeProduct('Alpha', '10.00');
    $b = $makeProduct('Beta', '20.00');

    $feed = $feeds->create(['name' => "Incremental {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true,
        'config' => ['filters' => ['include_categories' => [(int)$term['term_id']]], 'mapping' => ['brand' => ['source' => 'static', 'value' => 'Inc']]]]);
    $feedId = (int)$feed['id'];
    FeedChangeTracker::reset();

    // 1. First tick builds and publishes.
    $res = $tick();
    $check(($res['build']['status'] ?? '') === 'completed' && ($res['build']['published']['status'] ?? '') === 'published', 'first tick must build and publish: ' . wp_json_encode($res));
    $check($fileHas("<g:id>ALPHA-{$tag}</g:id>") && $fileHas('<g:price>10.00 USD</g:price>'), 'initial file must have Alpha at 10.00');
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}wpe_feed_dirty WHERE feed_id = %d", $feedId));
    echo "  [OK] first tick builds and publishes\n";

    // 2. Native update -> outbox action -> dirty mark.
    $repo->update($a, ['regular_price' => '12.00']);
    FeedChangeTracker::flush();
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}wpe_feed_dirty WHERE feed_id = %d AND product_id = %d", $feedId, $a)) === 1, 'update must mark Alpha dirty');
    echo "  [OK] native update marks the product dirty (wpe_aggregate_changed)\n";

    // 3. Tick re-renders and debounces.
    $now = time();
    $res = $tick($now);
    $check($res['dirty_products'] === 1 && $res['changed_items'] === 1, 'tick must re-render one changed item: ' . wp_json_encode($res));
    $check($res['publish'] === 'debouncing', 'publish must wait for the quiet window, got ' . wp_json_encode($res['publish']));
    $check($store->dirtyCount($feedId) === 0 && $feeds->get($feedId)['changes_first_at'] !== null, 'dirty cleared, change window open');
    $res = $tick($now + 130);
    $check($res['publish'] === 'min_interval', 'after the quiet window the minimum interval still applies, got ' . wp_json_encode($res['publish']));
    $res = $tick($now + 1000);
    $check(is_array($res['publish']) && $res['publish']['status'] === 'published', 'publish after min interval: ' . wp_json_encode($res['publish']));
    $check($fileHas('<g:price>12.00 USD</g:price>') && $feeds->get($feedId)['changes_first_at'] === null, 'file must have the new price and the window must close');
    echo "  [OK] dirty product re-rendered; publish waits for quiet window + min interval, then publishes 12.00\n";

    // 4. Max wait publishes during continuous changes.
    $repo->update($a, ['regular_price' => '13.00']);
    $tick(time());
    $wpdb->query($wpdb->prepare(
        "UPDATE {$prefix}wpe_feeds SET changes_first_at = %s, changes_last_at = %s, last_published_at = %s WHERE id = %d",
        gmdate('Y-m-d H:i:s', time() - 400), gmdate('Y-m-d H:i:s', time() - 5), gmdate('Y-m-d H:i:s', time() - 2000), $feedId
    ));
    $res = $tick(time());
    $check(is_array($res['publish']) && $res['publish']['status'] === 'published', 'max wait must publish during continuous changes: ' . wp_json_encode($res['publish']));
    echo "  [OK] max wait (5 min) publishes despite continuous changes\n";

    // 5. Unchanged data does not open the window.
    FeedChangeTracker::queue($b);
    $res = $tick(time());
    $check($res['dirty_products'] === 1 && $res['changed_items'] === 0 && $res['publish'] === 'no_changes', 'unchanged product must not open the window: ' . wp_json_encode($res));
    echo "  [OK] re-rendering an unchanged product publishes nothing\n";

    // 6. Permanent delete (wp-admin/WP-CLI path: shell deleted, native aggregate purged) removes the items.
    wp_delete_post($b, true);
    $check(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}wpe_products WHERE id = %d", $b)), 'deleting the shell must purge the native aggregate');
    $products = array_values(array_diff($products, [$b]));
    $res = $tick(time());
    $g = (int)$feeds->get($feedId)['active_generation'];
    $check($res['changed_items'] === 1 && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}wpe_feed_items WHERE feed_id = %d AND generation = %d AND product_id = %d", $feedId, $g, $b)) === 0,
        'purge must remove Beta items: ' . wp_json_encode($res));
    echo "  [OK] permanent delete removes the product's items\n";

    // 7. Category rename re-renders its products.
    wp_update_term((int)$term['term_id'], 'product_cat', ['name' => "Renamed {$tag}"]);
    $res = $tick(time());
    $item = $wpdb->get_var($wpdb->prepare("SELECT payload FROM {$prefix}wpe_feed_items WHERE feed_id = %d AND generation = %d AND product_id = %d", $feedId, $g, $a));
    $check($res['dirty_products'] >= 1 && str_contains((string)$item, "Renamed {$tag}"), 'category rename must re-render product_type: ' . wp_json_encode($res));
    echo "  [OK] category rename (edited_term) re-renders products in the category\n";

    // 8. A sale that starts later is picked up through next_change.
    $start = gmdate('Y-m-d H:i:s', time() + 3);
    $repo->update($a, ['sale_price' => '9.00', 'sale_from' => $start, 'sale_to' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    $tick(time());
    $row = $wpdb->get_row($wpdb->prepare("SELECT payload, next_change FROM {$prefix}wpe_feed_items WHERE feed_id = %d AND generation = %d AND product_id = %d", $feedId, $g, $a), ARRAY_A);
    $check(!str_contains((string)$row['payload'], 'sale_price') && $row['next_change'] !== null && str_starts_with((string)$row['next_change'], substr($start, 0, 16)), 'future sale: no sale_price yet, next_change at start: ' . wp_json_encode($row));
    sleep(4);
    $res = $tick();
    $row = $wpdb->get_row($wpdb->prepare("SELECT payload FROM {$prefix}wpe_feed_items WHERE feed_id = %d AND generation = %d AND product_id = %d", $feedId, $g, $a), ARRAY_A);
    $check($res['due_products'] === 1 && str_contains((string)$row['payload'], '"sale_price":"9.00 USD"'), 'due sale must be applied: ' . wp_json_encode($res));
    echo "  [OK] scheduled sale start re-renders the item when due (next_change)\n";

    // 9. Manual mode never auto-publishes.
    $f = $feeds->get($feedId);
    $feeds->update($feedId, ['config' => ['schedule' => ['mode' => 'manual']]], (int)$f['version']);
    $repo->update($a, ['regular_price' => '14.00']);
    $res = $tick(time() + 5000);
    $check($res['changed_items'] === 1 && $res['publish'] === 'manual', 'manual mode must keep items fresh but not publish: ' . wp_json_encode($res));
    $f = $feeds->get($feedId);
    $feeds->update($feedId, ['config' => ['schedule' => ['mode' => 'auto']]], (int)$f['version']);
    echo "  [OK] manual mode re-renders items but never auto-publishes\n";

    // 10. Escalations request a full rebuild.
    $store->markDirty([$feedId], range(900000001, 900000001 + FeedScheduler::DIRTY_ESCALATION_MIN));
    $res = $tick(time());
    $check(($res['rebuild_requested'] ?? '') === 'dirty_threshold' && $feeds->get($feedId)['rebuild_reason'] === 'dirty_threshold', 'large dirty set must request a rebuild: ' . wp_json_encode($res));
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}wpe_feed_dirty WHERE feed_id = %d", $feedId));
    $tick(time());
    $feeds->mergeStats($feedId, ['store_hash' => 'changed']);
    $res = $tick(time());
    $check(($res['rebuild_requested'] ?? '') === 'store_settings_changed', 'changed store settings must request a rebuild: ' . wp_json_encode($res));
    $res = $tick(time());
    $check(($res['build']['status'] ?? '') === 'completed', 'requested rebuild must run on the next tick');
    echo "  [OK] dirty threshold and store-settings change request a full rebuild\n";

    echo "ALL FEED INCREMENTAL ASSERTIONS PASSED ({$checks} checks)\n";
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
    FeedChangeTracker::reset();
}
