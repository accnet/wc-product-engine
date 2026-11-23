<?php
/**
 * P035.3/P035.9 — Product feed admin REST API through the real REST server.
 *
 * Verifies: schema tables exist at the current schema version; create (201) normalizes config,
 * starts with a rebuild request and returns a token URL; list search/filter/sort; GET; PATCH needs
 * expected_version (428), rejects a stale one (412), rejects unknown/invalid settings (422) and
 * requests a rebuild only for render-affecting changes; duplicate is disabled with a new key and
 * token; rotate-token revokes the old URL; delete removes items and dirty marks; shop managers read
 * without URLs and cannot change feeds; anonymous callers are refused. When the P035.9 routes are
 * registered it also checks preview, diagnostics and regenerate.
 *
 * Run: wp eval-file tests/integration/FeedsApiTest.php
 */

use WPE\Application\Feeds\FeedRepository;
use WPE\Infrastructure\Database\SchemaMigrator;

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedsApiTest failed: ' . $msg);
    }
    $checks++;
};
$request = static function (string $method, string $route, ?array $body = null, array $query = []): \WP_REST_Response {
    $req = new \WP_REST_Request($method, '/wpe/v1' . $route);
    if ($body !== null) {
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string)wp_json_encode($body));
    }
    foreach ($query as $k => $v) {
        $req->set_param($k, $v);
    }
    return rest_do_request($req);
};

$originalUser = get_current_user_id();
$created = [];
$tempUsers = [];
$repo = new FeedRepository($wpdb);

try {
    echo "=== FeedsApiTest ===\n";

    // 0. Schema.
    $check((int)get_option(SchemaMigrator::OPTION_SCHEMA_VERSION) >= 4, 'schema version must be at least 4');
    foreach (['wpe_feeds', 'wpe_feed_items', 'wpe_feed_dirty'] as $t) {
        $check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . $t)) === $prefix . $t, "table {$t} must exist");
    }
    echo "  [OK] feed tables exist at schema version " . get_option(SchemaMigrator::OPTION_SCHEMA_VERSION) . "\n";

    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    $check(!empty($admins), 'an administrator is required');
    wp_set_current_user((int)$admins[0]);
    $check(current_user_can('wpe_manage_operations'), 'administrator must hold wpe_manage_operations');

    // 1. Create.
    $tag = wp_generate_password(6, false);
    $res = $request('POST', '/admin/feeds', [
        'name' => "Feed API Probe {$tag} Google", 'platform' => 'google', 'format' => 'xml', 'enabled' => true,
        'config' => ['market' => ['country' => 'us', 'language' => 'EN'], 'mapping' => ['brand' => ['source' => 'static', 'value' => 'Acme']]],
    ]);
    $check($res->get_status() === 201, 'create must return 201, got ' . $res->get_status() . ' ' . wp_json_encode($res->get_data()));
    $feed = $res->get_data();
    $created[] = (int)$feed['id'];
    $check($feed['version'] === 1 && $feed['status'] === 'new' && $feed['rebuild_pending'] === true, 'new feed must be version 1, status new, rebuild pending');
    $check($feed['config']['market']['country'] === 'US' && $feed['config']['market']['language'] === 'en', 'market must be normalized');
    $check($feed['config']['mapping']['brand'] === ['source' => 'static', 'value' => 'Acme'], 'mapping must be kept');
    $check(is_string($feed['url']) && preg_match('/[a-f0-9]{64}/', $feed['url']) === 1, 'admin must receive a token URL');
    $check(!isset($feed['stats']['dir']), 'private directory name must not leak');
    $stored = $repo->get((int)$feed['id']);
    $check($repo->verifyToken($stored, $repo->token($stored)) && !$repo->verifyToken($stored, str_repeat('0', 64)), 'token must verify only itself');
    $check($wpdb->get_var($wpdb->prepare("SELECT LOCATE(%s, CONCAT(config, stats)) FROM {$prefix}wpe_feeds WHERE id = %d", $repo->token($stored), $feed['id'])) === '0',
        'token must not be stored');
    echo "  [OK] create: 201, normalized config, rebuild pending, token URL (token not stored)\n";

    $res = $request('POST', '/admin/feeds', ['name' => "Feed API Probe {$tag} Bing", 'platform' => 'microsoft', 'format' => 'tsv']);
    $check($res->get_status() === 201, 'second create must succeed');
    $bing = $res->get_data();
    $created[] = (int)$bing['id'];

    // 2. Create validation.
    $res = $request('POST', '/admin/feeds', ['name' => '', 'platform' => 'amazon', 'format' => 'csv', 'config' => ['bogus' => 1]]);
    $check($res->get_status() === 422 && $res->get_data()['code'] === 'validation_failed', 'invalid create must be 422 validation_failed');
    $pointers = array_column($res->get_data()['details'], 'pointer');
    $check(in_array('/name', $pointers, true) && in_array('/platform', $pointers, true), 'details must name invalid fields: ' . implode(',', $pointers));
    $res = $request('POST', '/admin/feeds', ['name' => 'x', 'platform' => 'google', 'config' => ['bogus' => 1]]);
    $check($res->get_status() === 422 && in_array('/config/bogus', array_column($res->get_data()['details'], 'pointer'), true), 'unknown config key must be rejected');
    $res = $request('POST', '/admin/feeds', ['name' => 'x', 'platform' => 'google', 'config' => ['shipping' => ['mode' => 'flat', 'country' => 'U', 'price' => '-1']]]);
    $check($res->get_status() === 422 && count($res->get_data()['details']) === 2, 'flat shipping must need country and price');
    echo "  [OK] create validation: 422 with per-field details\n";

    // 3. List, search, filter, sort.
    $res = $request('GET', '/admin/feeds', null, ['search' => "Feed API Probe {$tag}"]);
    $check($res->get_status() === 200 && $res->get_data()['total'] === 2, 'search must find both probes');
    $res = $request('GET', '/admin/feeds', null, ['search' => "Feed API Probe {$tag}", 'platform' => 'microsoft']);
    $check($res->get_data()['total'] === 1 && $res->get_data()['items'][0]['id'] === $bing['id'], 'platform filter must work');
    $res = $request('GET', '/admin/feeds', null, ['search' => "Feed API Probe {$tag}", 'status' => 'disabled']);
    $check($res->get_data()['total'] === 1 && $res->get_data()['items'][0]['id'] === $bing['id'], 'disabled filter must work');
    $res = $request('GET', '/admin/feeds', null, ['search' => "Feed API Probe {$tag}", 'orderby' => 'name', 'order' => 'desc']);
    $check($res->get_data()['items'][0]['id'] === $feed['id'], 'name desc must put the Google probe first');
    echo "  [OK] list: search, platform/status filter, sort\n";

    // 4. PATCH concurrency and rebuild classification.
    $route = '/admin/feeds/' . $feed['id'];
    $res = $request('PATCH', $route, ['name' => 'no version']);
    $check($res->get_status() === 428, 'PATCH without expected_version must be 428, got ' . $res->get_status());
    $repo->updateRuntime((int)$feed['id'], ['rebuild_reason' => null, 'status' => 'ready']);
    $res = $request('PATCH', $route, ['expected_version' => 1, 'name' => "Feed API Probe {$tag} Google renamed", 'config' => ['schedule' => ['quiet_seconds' => 180]]]);
    $check($res->get_status() === 200 && $res->get_data()['version'] === 2, 'PATCH must bump version');
    $check($res->get_data()['rebuild_required'] === false && $res->get_data()['rebuild_pending'] === false, 'schedule/name change must not need a rebuild');
    $check($res->get_data()['config']['schedule']['quiet_seconds'] === 180, 'schedule must be saved');
    $res = $request('PATCH', $route, ['expected_version' => 1, 'name' => 'stale']);
    $check($res->get_status() === 412 && $res->get_data()['code'] === 'stale_version', 'stale version must be 412');
    $res = $request('PATCH', $route, ['expected_version' => 2, 'config' => ['variation_mode' => 'parent']]);
    $check($res->get_status() === 200 && $res->get_data()['rebuild_required'] === true && $res->get_data()['rebuild_pending'] === true, 'variation mode change must request a rebuild');
    $res = $request('PATCH', $route, ['expected_version' => 3, 'config' => ['schedule' => ['quiet_seconds' => 600, 'max_wait_seconds' => 300]]]);
    $check($res->get_status() === 422, 'max wait shorter than quiet window must be rejected');
    echo "  [OK] PATCH: 428 without version, 412 stale, rebuild only for render changes\n";

    // 5. Shop manager reads without URLs and cannot change; anonymous refused.
    $uid = wp_insert_user([
        'user_login' => 'wpe_feeds_sm_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(24),
        'user_email' => 'wpe-feeds-sm-' . wp_generate_password(6, false) . '@example.invalid', 'role' => 'shop_manager',
    ]);
    $check(!is_wp_error($uid), 'could not create shop manager');
    $tempUsers[] = (int)$uid;
    wp_set_current_user((int)$uid);
    $res = $request('GET', $route);
    $check($res->get_status() === 200 && $res->get_data()['url'] === null, 'shop manager must read without the token URL');
    $res = $request('DELETE', $route);
    $check($res->get_status() === 403, 'shop manager must not delete, got ' . $res->get_status());
    wp_set_current_user(0);
    $res = $request('GET', '/admin/feeds');
    $check(in_array($res->get_status(), [401, 403], true), 'anonymous must be refused');
    wp_set_current_user((int)$admins[0]);
    echo "  [OK] shop manager read-only without URL; anonymous refused\n";

    // 6. Duplicate and rotate token.
    $res = $request('POST', $route . '/duplicate', []);
    $check($res->get_status() === 201, 'duplicate must return 201');
    $copy = $res->get_data();
    $created[] = (int)$copy['id'];
    $check($copy['enabled'] === false && $copy['feed_key'] !== $feed['feed_key'] && $copy['url'] !== $feed['url'], 'copy must be disabled with its own key and URL');
    $check($copy['config']['variation_mode'] === 'parent', 'copy must keep the configuration');
    $before = $repo->get((int)$feed['id']);
    $oldToken = $repo->token($before);
    $res = $request('POST', $route . '/rotate-token', []);
    $after = $repo->get((int)$feed['id']);
    $check($res->get_status() === 200 && !$repo->verifyToken($after, $oldToken) && $repo->verifyToken($after, $repo->token($after)), 'rotated token must revoke the old one');
    echo "  [OK] duplicate (disabled, new key/token) and rotate-token (old token revoked)\n";

    // 7. Optional P035.9 routes.
    $routes = rest_get_server()->get_routes();
    if (isset($routes['/wpe/v1/admin/feeds/(?P<id>\d+)/preview'])) {
        $res = $request('GET', $route . '/preview', null, ['limit' => 5]);
        $check($res->get_status() === 200 && isset($res->get_data()['items']), 'preview must return items');
        $res = $request('GET', $route . '/diagnostics');
        $check($res->get_status() === 200 && isset($res->get_data()['issues'], $res->get_data()['runs'], $res->get_data()['worker']), 'diagnostics must return issues, runs and worker');
        $res = $request('POST', $route . '/regenerate', ['mode' => 'bogus']);
        $check($res->get_status() === 422, 'regenerate with an unknown mode must be 422');
        echo "  [OK] preview, diagnostics and regenerate validation\n";
    }

    // 8. Delete removes items and dirty marks.
    $wpdb->insert("{$prefix}wpe_feed_dirty", ['feed_id' => $feed['id'], 'product_id' => 1, 'first_marked_at' => gmdate('Y-m-d H:i:s'), 'last_marked_at' => gmdate('Y-m-d H:i:s')]);
    $res = $request('DELETE', $route);
    $check($res->get_status() === 200, 'delete must return 200');
    $check($repo->get((int)$feed['id']) === null, 'feed must be gone');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}wpe_feed_dirty WHERE feed_id = %d", $feed['id'])) === 0, 'dirty marks must be gone');
    $res = $request('GET', $route);
    $check($res->get_status() === 404 && $res->get_data()['code'] === 'not_found', 'deleted feed must be 404');
    echo "  [OK] delete removes the feed and its dirty marks; then 404\n";

    echo "ALL FEEDS API ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    wp_set_current_user(isset($admins[0]) ? (int)$admins[0] : 0);
    foreach ($created as $id) {
        $repo->delete($id);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tempUsers as $u) {
        wp_delete_user($u);
    }
    wp_set_current_user($originalUser);
}
