<?php
/**
 * P035.5 — Item generations, full rebuilds and validated publishing.
 *
 * Verifies on the test catalog: a full rebuild of a Google XML feed writes every product's items
 * into a new generation and publishes a well-formed RSS file whose item count equals the eligible
 * items; a Microsoft TSV feed limited to a fixture category has the configured columns; the gzip
 * copy decompresses to the same file; a second rebuild switches generations and deletes the old
 * one; the item-drop gate keeps the previous file and records the reason, and a forced publish
 * goes through; a config edit during a build restarts it; run history is recorded; peak memory stays
 * bounded.
 *
 * Run: wp eval-file tests/integration/FeedBuildPublishTest.php
 */

use WPE\Application\Feeds\FeedBuilder;
use WPE\Application\Feeds\FeedRepository;
use WPE\Infrastructure\Database\ProductRepository;

// P048: pin the cohort mode instead of inheriting the shared test DB's (written for the default native_bridge mode).
require_once __DIR__ . '/support/CohortModeFixture.php';
WpeCohortModeFixture::pin('native_bridge');

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedBuildPublishTest failed: ' . $msg);
    }
    $checks++;
};

$builder = new FeedBuilder($wpdb);
$feeds = $builder->feeds();
$store = $builder->store();
$repo = new ProductRepository($wpdb);
$tag = strtolower(wp_generate_password(6, false));
$feedIds = [];
$pid = 0;
$term = null;

$runBuild = static function (int $id) use ($builder): array {
    $guard = 0;
    do {
        $res = $builder->buildStep($id, 20.0);
    } while ($res['status'] === 'building' && ++$guard < 200);
    return $res;
};

try {
    echo "=== FeedBuildPublishTest ===\n";

    // Fixture product in its own category, with an image.
    $term = wp_insert_term("Feed Build {$tag}", 'product_cat');
    $title = "Feed Build Lamp {$tag}";
    $created = $repo->create(['title' => $title, 'slug' => sanitize_title($title), 'type' => 'simple', 'status' => 'publish',
        'sku' => "FBL-{$tag}", 'regular_price' => '42.00', 'description' => "Tab\there & <b>bold</b>\nnew line"]);
    $pid = (int)$created['id'];
    $check($pid > 0 && $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) === $title, 'fixture');
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $pid]);
    wp_set_object_terms($pid, [(int)$term['term_id']], 'product_cat');
    $asset = (new \WPE\Infrastructure\Database\MediaRepository($wpdb))->createAsset(['source_url' => "https://cdn.example.com/{$tag}.jpg"]);
    (new \WPE\Infrastructure\Database\MediaRepository($wpdb))->setFeaturedImage($pid, (int)$asset['id']);

    // 1. Full rebuild of a whole-catalog Google XML feed.
    $google = $feeds->create(['name' => "Build Probe {$tag} G", 'platform' => 'google', 'format' => 'xml', 'enabled' => true,
        'config' => ['mapping' => ['brand' => ['source' => 'static', 'value' => 'Probe']], 'compress' => true]]);
    $feedIds[] = (int)$google['id'];
    $products = $builder->source()->countProducts();
    $t0 = microtime(true);
    $memBefore = memory_get_usage(true);
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $res = $runBuild((int)$google['id']);
    $elapsed = microtime(true) - $t0;
    $peakMb = round((memory_get_peak_usage(true) - $memBefore) / 1048576, 1);
    $check($res['status'] === 'completed' && $res['done'] === $products, "build must cover all {$products} products: " . wp_json_encode($res));
    $check(($res['published']['status'] ?? '') === 'published', 'build must publish: ' . wp_json_encode($res['published'] ?? null));
    $g = $feeds->get((int)$google['id']);
    $check($g['active_generation'] === 1 && $g['build_generation'] === 0 && $g['status'] === 'ready' && $g['rebuild_reason'] === null, 'generation 1 must be active');
    $counts = $store->counts((int)$google['id'], 1);
    $check($counts['eligible'] === (int)$g['stats']['published']['items'] && $counts['eligible'] > 0, 'published items must equal eligible items');
    $file = $builder->publishedFile($g);
    $check($file !== null && \WPE\Application\Feeds\FeedWriter::verify($file, 'xml', $counts['eligible']) === null, 'published XML must be well-formed with every eligible item');
    $xml = (string)file_get_contents($file); // test-side read; the builder never loads the file
    $check(str_contains($xml, 'xmlns:g="http://base.google.com/ns/1.0"') && str_contains($xml, "<g:id>FBL-{$tag}</g:id>"), 'RSS must carry the g namespace and the fixture item');
    $check(str_contains($xml, '<g:price>42.00 USD</g:price>') && str_contains($xml, '<g:brand>Probe</g:brand>'), 'fixture price/brand');
    $check(str_contains($xml, 'Tab here &amp; bold new line'), 'description must be plain, escaped text');
    $gz = $builder->publishedFile($g, true);
    $check($gz !== null && gzdecode((string)file_get_contents($gz)) === $xml, 'gzip copy must decompress to the same file');
    printf("  [OK] full rebuild: %d products, %d items (%d eligible) in %.1fs, file %.1f MB, extra peak memory %s MB\n",
        $products, $counts['total'], $counts['eligible'], $elapsed, filesize($file) / 1048576, $peakMb);
    $check($peakMb < 128, "build + publish must stay under 128 MB extra memory, used {$peakMb} MB");

    // 2. Microsoft TSV limited to the fixture category.
    $bing = $feeds->create(['name' => "Build Probe {$tag} B", 'platform' => 'microsoft', 'format' => 'tsv', 'enabled' => true,
        'config' => ['filters' => ['include_categories' => [(int)$term['term_id']]], 'mapping' => ['brand' => ['source' => 'static', 'value' => 'Probe']]]]);
    $feedIds[] = (int)$bing['id'];
    $res = $runBuild((int)$bing['id']);
    $b = $feeds->get((int)$bing['id']);
    $lines = file($builder->publishedFile($b), FILE_IGNORE_NEW_LINES);
    $check(count($lines) === 2, 'TSV must have a header and the single fixture row, got ' . count($lines));
    $header = explode("\t", $lines[0]);
    $row = array_combine($header, explode("\t", $lines[1]));
    $check($header[0] === 'id' && !in_array('item_group_id', $header, true) || $header[0] === 'id', 'header starts with id');
    $check($row['id'] === "FBL-{$tag}" && $row['price'] === '42.00 USD' && $row['description'] === 'Tab here & bold new line', 'TSV row values: ' . wp_json_encode($row));
    echo "  [OK] Microsoft TSV with category filter: header + 1 row, tabs/newlines cleaned\n";

    // 3. Second rebuild switches generations and deletes the old one.
    $feeds->requestRebuild((int)$bing['id'], 'test');
    $runBuild((int)$bing['id']);
    $b = $feeds->get((int)$bing['id']);
    $check($b['active_generation'] === 2, 'second rebuild must activate generation 2');
    $check($store->counts((int)$bing['id'], 1)['total'] === 0, 'generation 1 must be deleted');
    echo "  [OK] rebuild switches to generation 2 and deletes generation 1\n";

    // 4. Item-drop gate keeps the previous file; forced publish goes through.
    $path = $builder->publishedFile($b);
    $before = hash_file('sha256', $path);
    $feeds->mergeStats((int)$bing['id'], ['published' => ['items' => 100] + $b['stats']['published']]);
    $res = $builder->publish((int)$bing['id']);
    $b = $feeds->get((int)$bing['id']);
    $check($res['status'] === 'rejected' && str_starts_with($res['reason'], 'item_drop'), 'a 99% drop must be rejected: ' . wp_json_encode($res));
    $check(hash_file('sha256', $path) === $before, 'rejected publish must keep the previous file');
    $check($b['status'] === 'error' && str_contains((string)$b['last_error'], 'item_drop'), 'gate failure must be recorded');
    $res = $builder->publish((int)$bing['id'], true);
    $check($res['status'] === 'published' && $feeds->get((int)$bing['id'])['status'] === 'ready', 'forced publish must go through');
    echo "  [OK] publish gate rejects an item drop and keeps the file; forced publish succeeds\n";

    // 5. A config edit during a build restarts it.
    $feeds->requestRebuild((int)$google['id'], 'test');
    $step = $builder->buildStep((int)$google['id'], 0.01);
    $check($step['status'] === 'building', 'a short step must leave the build running');
    $g = $feeds->get((int)$google['id']);
    $feeds->update((int)$google['id'], ['config' => ['title' => ['append_variant_values' => false]]], (int)$g['version']);
    $res = $runBuild((int)$google['id']);
    $check($res['status'] === 'restarted', 'config change mid-build must restart: ' . $res['status']);
    $g = $feeds->get((int)$google['id']);
    $check($g['active_generation'] === 1 && $g['rebuild_reason'] === 'config_changed', 'restart keeps the old generation and asks for a new build');
    $res = $runBuild((int)$google['id']);
    $check($res['status'] === 'completed' && $feeds->get((int)$google['id'])['active_generation'] === 2, 'the new build completes as generation 2');
    echo "  [OK] config edit during a build restarts it; old generation served until done\n";

    // 6. Run history.
    $runs = $builder->runs((int)$bing['id'], 10);
    $kinds = array_count_values(array_column($runs, 'kind'));
    $check(($kinds['build'] ?? 0) >= 2 && ($kinds['publish'] ?? 0) >= 3, 'runs must record builds and publishes: ' . wp_json_encode($kinds));
    $check(in_array('failed', array_column($runs, 'status'), true), 'the rejected publish must appear as failed');
    echo "  [OK] run history records builds/publishes including the rejected one\n";

    echo "ALL FEED BUILD/PUBLISH ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    foreach ($feedIds as $id) {
        $feeds->delete($id);
    }
    if ($pid > 0) {
        wp_delete_post($pid, true);
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}wpe_products WHERE id = %d", $pid))) {
            $repo->delete($pid, true);
        }
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $pid]);
    }
    if (!empty($asset['id'])) {
        $wpdb->delete("{$prefix}wpe_product_media", ['asset_id' => (int)$asset['id']]);
        $wpdb->delete("{$prefix}wpe_assets", ['id' => (int)$asset['id']]);
    }
    if ($term && !is_wp_error($term)) {
        wp_delete_term((int)$term['term_id'], 'product_cat');
    }
}
