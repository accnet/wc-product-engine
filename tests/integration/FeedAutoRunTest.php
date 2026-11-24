<?php
/**
 * P036.3 — Feed updates without a system cron (FeedAutoRunner).
 *
 * Fixture: one native product in its own category and a Google XML feed limited to it; other
 * enabled feeds are disabled for the run. Verifies: a new feed counts as pending build work; a
 * fresh worker heartbeat suppresses the fallback; nothing pending means nothing runs; a native
 * price change marks the product dirty and bypasses the throttle; the fallback tick re-renders the
 * product without writing a heartbeat; the debounced publish waits, then a due publish is pending
 * and the fallback publishes the new price; the throttle and the opt-out filter stop it; a held
 * global lock makes run() return null; the shutdown hook is not registered under WP-CLI.
 *
 * Run: wp eval-file tests/integration/FeedAutoRunTest.php
 */

use WPE\Application\Feeds\FeedAutoRunner;
use WPE\Application\Feeds\FeedBuilder;
use WPE\Application\Feeds\FeedChangeTracker;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\ProductRepository;

// P048: pin the cohort mode instead of inheriting the shared test DB's (written for the default native_bridge mode).
require_once __DIR__ . '/support/CohortModeFixture.php';
WpeCohortModeFixture::pin('native_bridge');

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedAutoRunTest failed: ' . $msg);
    }
    $checks++;
};

$builder = new FeedBuilder($wpdb);
$feeds = $builder->feeds();
$store = $builder->store();
$repo = new ProductRepository($wpdb);
$tag = strtolower(wp_generate_password(6, false));
$feedId = 0;
$productId = 0;
$term = null;
$assetId = 0;
$others = [];
$savedLastRun = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}wpe_control WHERE `key` = %s", FeedAutoRunner::LAST_RUN_KEY), ARRAY_A);
$savedHeartbeat = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}wpe_control WHERE `key` = %s", FeedScheduler::HEARTBEAT_KEY), ARRAY_A);
$heartbeatRow = static fn() => $wpdb->get_var($wpdb->prepare("SELECT value FROM {$prefix}wpe_control WHERE `key` = %s", FeedScheduler::HEARTBEAT_KEY));
$setHeartbeat = static function (?int $age) use ($wpdb, $prefix): void {
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY]);
    if ($age !== null) {
        $wpdb->insert("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY, 'value' => wp_json_encode(['at' => gmdate('Y-m-d H:i:s', time() - $age)]), 'version' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
};
$fileHas = static function (string $needle) use ($builder, $feeds, &$feedId): bool {
    $path = $builder->publishedFile($feeds->get($feedId));
    return $path !== null && str_contains((string)file_get_contents($path), $needle);
};

FeedChangeTracker::register();

try {
    echo "=== FeedAutoRunTest ===\n";
    foreach ($feeds->enabledFeedIds() as $id) {
        $others[] = $id;
        $wpdb->update("{$prefix}wpe_feeds", ['enabled' => 0], ['id' => $id]);
    }
    $setHeartbeat(null);
    delete_transient(FeedAutoRunner::THROTTLE_KEY);

    $term = wp_insert_term("Autorun {$tag}", 'product_cat');
    $title = "Autorun Gamma {$tag}";
    $created = $repo->create(['title' => $title, 'slug' => sanitize_title($title), 'type' => 'simple', 'status' => 'publish',
        'sku' => "AUTO-{$tag}", 'regular_price' => '30.00', 'description' => 'Autorun description']);
    $productId = (int)$created['id'];
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $productId]);
    wp_set_object_terms($productId, [(int)$term['term_id']], 'product_cat');
    $media = new MediaRepository($wpdb);
    $assetId = (int)$media->createAsset(['source_url' => "https://cdn.example.com/{$tag}-autorun.jpg"])['id'];
    $media->setFeaturedImage($productId, $assetId);

    $feed = $feeds->create(['name' => "Autorun {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true,
        'config' => ['filters' => ['include_categories' => [(int)$term['term_id']]],
            'mapping' => ['brand' => ['source' => 'static', 'value' => 'Auto']]]]);
    $feedId = (int)$feed['id'];
    FeedChangeTracker::reset();

    // 1. A new feed is pending build work; the fallback builds and publishes it without a heartbeat.
    $check((FeedAutoRunner::pendingWork()[$feedId] ?? '') === 'build', 'a new feed must be pending build work');
    $res = FeedAutoRunner::run(10.0);
    $check(is_array($res) && ($res['feeds'][$feedId]['build']['status'] ?? '') === 'completed', 'fallback must build the new feed: ' . wp_json_encode($res));
    $check($fileHas('<g:price>30.00 USD</g:price>'), 'built file must have the price: ' . wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT eligible, issues FROM {$prefix}wpe_feed_items WHERE feed_id = %d", $feedId), ARRAY_A)));
    $check($heartbeatRow() === null, 'the fallback must not write the worker heartbeat');
    $last = FeedAutoRunner::lastRun();
    $check(is_array($last) && $last['age'] <= 60 && $last['feeds'] >= 1, 'the fallback must record its last run: ' . wp_json_encode($last));
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}wpe_feed_dirty WHERE feed_id = %d", $feedId));
    $check(FeedAutoRunner::pendingWork() === [], 'nothing pending after the build: ' . wp_json_encode(FeedAutoRunner::pendingWork()));
    delete_transient(FeedAutoRunner::THROTTLE_KEY);
    $check(FeedAutoRunner::shouldRun() === false, 'nothing pending: nothing runs');
    echo "  [OK] new feed built and published by the fallback; no heartbeat written; idle when nothing is pending\n";

    // 2. A product change marks dirty and bypasses the throttle; a fresh heartbeat suppresses the fallback.
    set_transient(FeedAutoRunner::THROTTLE_KEY, 1, FeedAutoRunner::THROTTLE_SECONDS);
    $repo->update($productId, ['regular_price' => '33.00']);
    FeedChangeTracker::flush();
    $check(FeedChangeTracker::markedInRequest() && (FeedAutoRunner::pendingWork()[$feedId] ?? '') === 'dirty', 'price change must leave dirty work');
    $setHeartbeat(10);
    $check(FeedAutoRunner::shouldRun() === false, 'a fresh worker heartbeat must suppress the fallback');
    $setHeartbeat(900);
    $check(FeedAutoRunner::shouldRun() === true, 'stale heartbeat + product change must run despite the throttle');
    $setHeartbeat(null);
    echo "  [OK] product change bypasses the throttle; fresh heartbeat suppresses, stale heartbeat allows\n";

    // 3. The fallback re-renders the product and opens the debounce window.
    $res = FeedAutoRunner::run(10.0);
    $r = $res['feeds'][$feedId] ?? [];
    $check(($r['dirty_products'] ?? 0) === 1 && ($r['changed_items'] ?? 0) === 1 && ($r['publish'] ?? '') === 'debouncing', 'fallback tick must re-render and debounce: ' . wp_json_encode($r));
    $check($store->dirtyCount($feedId) === 0 && $heartbeatRow() === null, 'dirty cleared, still no heartbeat');
    $check(FeedAutoRunner::pendingWork() === [], 'a debouncing publish is not pending work');
    echo "  [OK] fallback re-renders the changed product; publish waits for the quiet window\n";

    // 4. Once the publish is due the fallback publishes the new price.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$prefix}wpe_feeds SET changes_first_at = %s, changes_last_at = %s, last_published_at = %s WHERE id = %d",
        gmdate('Y-m-d H:i:s', time() - 400), gmdate('Y-m-d H:i:s', time() - 200), gmdate('Y-m-d H:i:s', time() - 2000), $feedId
    ));
    $check((FeedAutoRunner::pendingWork()[$feedId] ?? '') === 'publish', 'a due publish must be pending work');
    $res = FeedAutoRunner::run(10.0);
    $check(is_array($res['feeds'][$feedId]['publish'] ?? null) && $res['feeds'][$feedId]['publish']['status'] === 'published', 'fallback must publish: ' . wp_json_encode($res['feeds'][$feedId] ?? null));
    $check($fileHas('<g:price>33.00 USD</g:price>') && $feeds->get($feedId)['changes_first_at'] === null, 'file must have the new price');
    echo "  [OK] due debounced publish runs from the fallback; file has 33.00\n";

    // 5. Throttle, opt-out, global lock, registration.
    FeedChangeTracker::reset();
    $repo->update($productId, ['regular_price' => '34.00']);
    FeedChangeTracker::flush();
    FeedChangeTracker::reset(); // a later request that changed nothing
    set_transient(FeedAutoRunner::THROTTLE_KEY, 1, FeedAutoRunner::THROTTLE_SECONDS);
    $check(FeedAutoRunner::shouldRun() === false, 'throttle must stop a request that changed nothing');
    delete_transient(FeedAutoRunner::THROTTLE_KEY);
    $check(FeedAutoRunner::shouldRun() === true && get_transient(FeedAutoRunner::THROTTLE_KEY), 'after the throttle expires it runs and re-arms the throttle');
    $off = static fn() => false;
    add_filter('wpe_feeds_autorun', $off);
    $check(FeedAutoRunner::enabled() === false, 'filter must opt out');
    remove_filter('wpe_feeds_autorun', $off);
    $check(FeedAutoRunner::enabled() === true, 'enabled by default');

    $host = DB_HOST;
    $port = null;
    $socket = null;
    if (str_contains($host, ':')) {
        [$host, $rest] = explode(':', $host, 2);
        is_numeric($rest) ? $port = (int)$rest : $socket = $rest;
    }
    $other = @new \mysqli($host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket);
    if (!$other->connect_errno) {
        $lock = substr($prefix . 'wpe_feed_autorun', 0, 64);
        $other->query("SELECT GET_LOCK('" . $other->real_escape_string($lock) . "', 0)");
        $check(FeedAutoRunner::run(5.0) === null, 'a held global lock must make run() return null');
        $other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($lock) . "')");
        $other->close();
        echo "  [OK] throttle, opt-out filter and global lock\n";
    } else {
        echo "  [SKIP] global lock check: second connection failed\n";
    }
    $check(has_action('shutdown', [FeedAutoRunner::class, 'onShutdown']) === false, 'the shutdown hook must not be registered under WP-CLI');
    echo "  [OK] not registered under WP-CLI\n";

    echo "ALL FEED AUTORUN ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    if ($feedId > 0) {
        $feeds->delete($feedId);
    }
    if ($productId > 0) {
        wp_delete_post($productId, true);
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}wpe_products WHERE id = %d", $productId))) {
            $repo->delete($productId, true);
        }
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $productId]);
    }
    if ($assetId > 0) {
        $wpdb->delete("{$prefix}wpe_product_media", ['asset_id' => $assetId]);
        $wpdb->delete("{$prefix}wpe_assets", ['id' => $assetId]);
    }
    if ($term && !is_wp_error($term)) {
        wp_delete_term((int)$term['term_id'], 'product_cat');
    }
    foreach ($others as $id) {
        $wpdb->update("{$prefix}wpe_feeds", ['enabled' => 1], ['id' => $id]);
    }
    delete_transient(FeedAutoRunner::THROTTLE_KEY);
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedAutoRunner::LAST_RUN_KEY]);
    if ($savedLastRun) {
        $wpdb->insert("{$prefix}wpe_control", $savedLastRun);
    }
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY]);
    if ($savedHeartbeat) {
        $wpdb->insert("{$prefix}wpe_control", $savedHeartbeat);
    }
    FeedChangeTracker::reset();
}
