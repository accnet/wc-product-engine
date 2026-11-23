<?php
/**
 * P035.5 — Full feed rebuild benchmark on the whole catalog.
 *
 * Creates a temporary Google XML feed (variants mode), runs a full rebuild in 50-second steps as the
 * cron tick would, publishes, prints time, items, file size, item-store size and peak memory, then
 * deletes the feed (items, dirty marks, files and run history).
 *
 * Run on the disposable test DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/performance/FeedBuildBenchmark.php
 * Optional env: FEED_BENCH_FORMAT=tsv, FEED_BENCH_MODE=parent.
 */

use WPE\Application\Feeds\FeedBuilder;

global $wpdb;
$builder = new FeedBuilder($wpdb);
$feeds = $builder->feeds();
$format = getenv('FEED_BENCH_FORMAT') === 'tsv' ? 'tsv' : 'xml';
$mode = getenv('FEED_BENCH_MODE') === 'parent' ? 'parent' : 'variants';

$feed = $feeds->create([
    'name'     => 'Feed benchmark ' . gmdate('His'),
    'platform' => 'google',
    'format'   => $format,
    'enabled'  => false,
    'config'   => ['variation_mode' => $mode, 'mapping' => ['brand' => ['source' => 'static', 'value' => 'Bench']]],
]);
$id = (int)$feed['id'];

try {
    $products = $builder->source()->countProducts();
    printf("Products: %d, format %s, mode %s\n", $products, $format, $mode);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $base = memory_get_usage(true);
    $t0 = microtime(true);
    $steps = 0;
    do {
        $res = $builder->buildStep($id, 50.0);
        $steps++;
        printf("  step %d: %s %d/%d (%.1fs)\n", $steps, $res['status'], $res['done'], $res['total'], microtime(true) - $t0);
    } while ($res['status'] === 'building');
    $elapsed = microtime(true) - $t0;

    $f = $feeds->get($id);
    $counts = $builder->store()->counts($id, (int)$f['active_generation']);
    $file = $builder->publishedFile($f);
    $storeBytes = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(LENGTH(payload) + LENGTH(issues) + 120), 0) FROM {$wpdb->prefix}wpe_feed_items WHERE feed_id = %d",
        $id
    ));

    $tp = microtime(true);
    $pub = $builder->publish($id, true);
    $publishSeconds = microtime(true) - $tp;

    printf("Build + publish: %.1fs in %d step(s); publish alone %.1fs\n", $elapsed, $steps, $publishSeconds);
    printf("Items: %d (%d eligible); file %.1f MB; item store ~%.1f MB\n", $counts['total'], $counts['eligible'], ($file ? filesize($file) : 0) / 1048576, $storeBytes / 1048576);
    printf("Peak memory above baseline: %.1f MB\n", (memory_get_peak_usage(true) - $base) / 1048576);
    printf("Publish result: %s\n", wp_json_encode($pub));
} finally {
    $feeds->delete($id);
}
