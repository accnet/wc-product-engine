<?php
/**
 * P035.10 — Product feed health on Settings → Overview ("Needs attention").
 *
 * Verifies: no feed items without enabled feeds; a missing/stale worker heartbeat warns with the
 * cron command; a fresh heartbeat clears it; a feed in error is listed as an error; a feed whose
 * last file is older than 48 hours is listed as a warning; disabled feeds are ignored.
 *
 * Run: wp eval-file tests/integration/FeedHealthTest.php
 */

use WPE\Admin\SettingsPage;
use WPE\Application\Feeds\FeedRepository;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Bootstrap\PluginBootstrap;

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedHealthTest failed: ' . $msg);
    }
    $checks++;
};

$repo = new FeedRepository($wpdb);
$feedIds = [];
$savedHeartbeat = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}wpe_control WHERE `key` = %s", FeedScheduler::HEARTBEAT_KEY), ARRAY_A);
$items = static function (): array {
    $m = new \ReflectionMethod(SettingsPage::class, 'attentionItems');
    $m->setAccessible(true);
    return array_values(array_filter(
        $m->invoke(new SettingsPage(), PluginBootstrap::instance(), ['status' => 'healthy']),
        static fn(array $i): bool => stripos($i['text'], 'feed') !== false
    ));
};
$setHeartbeat = static function (?int $age) use ($wpdb, $prefix): void {
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY]);
    if ($age !== null) {
        $wpdb->insert("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY, 'value' => wp_json_encode(['at' => gmdate('Y-m-d H:i:s', time() - $age)]), 'version' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
};

try {
    echo "=== FeedHealthTest ===\n";
    $others = $repo->enabledFeedIds();
    foreach ($others as $id) {
        $wpdb->update("{$prefix}wpe_feeds", ['enabled' => 0], ['id' => $id]);
    }

    $setHeartbeat(null);
    $check($items() === [], 'no enabled feeds: no feed items');
    echo "  [OK] no enabled feeds: nothing to report\n";

    $tag = strtolower(wp_generate_password(6, false));
    $ok = $repo->create(['name' => "Health OK {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true]);
    $feedIds[] = (int)$ok['id'];
    $list = $items();
    $check(count($list) === 1 && $list[0]['level'] === 'warning' && str_contains($list[0]['text'], 'wp wpe feeds tick'), 'missing heartbeat must warn with the cron command: ' . wp_json_encode($list));
    $check(str_contains($list[0]['text'], 'site requests'), 'with the fallback on the item must say feeds update from site requests');
    $off = static fn() => false;
    add_filter('wpe_feeds_autorun', $off);
    $offList = $items();
    remove_filter('wpe_feeds_autorun', $off);
    $check(str_contains($offList[0]['text'] ?? '', 'feeds are not updated'), 'with the fallback off the item says feeds are not updated');
    $setHeartbeat(900);
    $check(count($items()) === 1, 'stale heartbeat must warn');
    $setHeartbeat(10);
    $check($items() === [], 'fresh heartbeat clears the warning');
    echo "  [OK] worker heartbeat: missing/stale warns, fresh clears\n";

    $bad = $repo->create(['name' => "Health Bad {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true]);
    $feedIds[] = (int)$bad['id'];
    $repo->updateRuntime((int)$bad['id'], ['status' => 'error', 'last_error' => 'Publish gate: item_drop']);
    $repo->updateRuntime((int)$ok['id'], ['status' => 'ready', 'last_published_at' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS)]);
    $list = $items();
    $levels = array_column($list, 'level');
    $check(in_array('error', $levels, true) && str_contains(implode(' ', array_column($list, 'text')), "Health Bad {$tag}"), 'feed in error must be listed');
    $check(in_array('warning', $levels, true) && str_contains(implode(' ', array_column($list, 'text')), "Health OK {$tag}"), 'feed older than 48h must be listed');
    echo "  [OK] feed in error (error) and feed not published for 48h (warning) are listed\n";

    $f = $repo->get((int)$bad['id']);
    $repo->update((int)$bad['id'], ['enabled' => false], (int)$f['version']);
    $check(!str_contains(implode(' ', array_column($items(), 'text')), "Health Bad {$tag}"), 'disabled feeds are ignored');
    echo "  [OK] disabled feeds are ignored\n";

    echo "ALL FEED HEALTH ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    foreach ($feedIds as $id) {
        $repo->delete($id);
    }
    foreach ($others ?? [] as $id) {
        $wpdb->update("{$prefix}wpe_feeds", ['enabled' => 1], ['id' => $id]);
    }
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY]);
    if ($savedHeartbeat) {
        $wpdb->insert("{$prefix}wpe_control", $savedHeartbeat);
    }
}
