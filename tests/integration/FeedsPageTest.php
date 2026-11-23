<?php
/**
 * P035.9 — Product Feeds admin screens.
 *
 * Renders the real page as an administrator and as a shop manager and checks: list markup, toolbar
 * and embedded data (names escaped); Create form with every mapped attribute and section; Edit view
 * with status card, URL, actions and tabs; shop managers get a read-only form without the URL or
 * create/action buttons; the worker notice appears when feeds are enabled and no fresh heartbeat
 * exists; the submenu and the screen assets are registered; regenerate publishes inline for a
 * small catalog through the REST route; preview renders live items before the first build.
 *
 * Run: wp eval-file tests/integration/FeedsPageTest.php
 */

use WPE\Admin\FeedsPage;
use WPE\Application\Feeds\FeedRepository;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Application\Feeds\FeedConfig;
use WPE\Bootstrap\PluginBootstrap;

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedsPageTest failed: ' . $msg);
    }
    $checks++;
};
$render = static function (array $get): string {
    $saved = $_GET;
    $_GET = $get;
    ob_start();
    try {
        FeedsPage::render();
    } finally {
        $html = (string)ob_get_clean();
        $_GET = $saved;
    }
    return $html;
};

$repo = new FeedRepository($wpdb);
$originalUser = get_current_user_id();
$feedIds = [];
$tempUsers = [];
$heartbeat = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}wpe_control WHERE `key` = %s", FeedScheduler::HEARTBEAT_KEY), ARRAY_A);

try {
    echo "=== FeedsPageTest ===\n";
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    wp_set_current_user((int)$admins[0]);
    $tag = strtolower(wp_generate_password(6, false));

    $feed = $repo->create(['name' => "Page <script>alert(1)</script> {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true]);
    $feedIds[] = (int)$feed['id'];

    // 1. List.
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY]);
    $html = $render([]);
    $check(str_contains($html, 'id="wpe-feeds-table"') && str_contains($html, 'id="wpe-feeds-search"') && str_contains($html, 'id="wpe-feeds-platform"'), 'list table and toolbar');
    $check(str_contains($html, 'id="wpe-feeds-data"') && !str_contains($html, '<script>alert(1)</script>'), 'embedded data must escape the feed name');
    $check(str_contains($html, 'feed=new') && str_contains($html, 'id="wpe-feeds-delete-dialog"'), 'create button and delete dialog');
    $check(str_contains($html, 'id="wpe-feeds-worker-notice"') && str_contains($html, 'wp wpe feeds tick'), 'worker notice with cron line when no heartbeat');
    $check(str_contains($html, 'data-fallback="1"') && str_contains($html, 'updated from site requests') && !str_contains($html, 'not published until'), 'notice must describe the request fallback, not claim nothing is published');
    $off = static fn() => false;
    add_filter('wpe_feeds_autorun', $off);
    $offHtml = $render([]);
    remove_filter('wpe_feeds_autorun', $off);
    $check(str_contains($offHtml, 'data-fallback="0"') && str_contains($offHtml, 'not built or updated automatically'), 'with the fallback off the notice says feeds are not updated');
    echo "  [OK] list: table, toolbar, escaped data, create button, delete dialog, worker notice\n";

    // 2. Fresh heartbeat hides the notice.
    $wpdb->replace("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY, 'value' => wp_json_encode(['at' => gmdate('Y-m-d H:i:s')]), 'version' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    $check(!str_contains($render([]), 'id="wpe-feeds-worker-notice"'), 'fresh heartbeat must hide the worker notice');
    echo "  [OK] fresh heartbeat hides the worker notice\n";

    // 3. Create form.
    $html = $render(['feed' => 'new']);
    foreach (array_keys(FeedConfig::MAPPED_ATTRIBUTES) as $attr) {
        $check(str_contains($html, 'data-path="config.mapping.' . $attr . '.source"'), "mapping row for {$attr}");
    }
    foreach (['name', 'platform', 'format', 'enabled', 'config.variation_mode', 'config.filters.include_categories', 'config.filters.exclude_categories',
        'config.filters.include_out_of_stock', 'config.shipping.mode', 'config.market.country', 'config.schedule.quiet_seconds', 'config.schedule.max_drop_percent'] as $path) {
        $check(str_contains($html, 'data-path="' . $path . '"'), "field {$path}");
    }
    $check(str_contains($html, 'id="wpe-category-map-template"') && str_contains($html, 'id="wpe-feed-save"'), 'category map and save button');
    $check(!str_contains($html, 'id="wpe-feed-status"'), 'create form has no status card');
    echo "  [OK] create form: every section, mapping row and setting\n";

    // 4. Edit view.
    $html = $render(['feed' => (string)$feed['id']]);
    $check(str_contains($html, 'id="wpe-feed-status"') && str_contains($html, 'id="wpe-feed-url"') && str_contains($html, $repo->token($repo->get((int)$feed['id']))), 'status card with URL');
    $check(str_contains($html, 'data-regenerate="publish"') && str_contains($html, 'data-regenerate="rebuild"') && str_contains($html, 'data-feed-tab="diagnostics"'), 'actions and tabs');
    $check(str_contains($html, 'data-version="1"'), 'form carries the version for CAS');
    echo "  [OK] edit view: status card, URL, actions, tabs, version\n";

    // 5. Shop manager: read-only.
    $uid = wp_insert_user(['user_login' => 'wpe_feedpage_sm_' . $tag, 'user_pass' => wp_generate_password(24), 'user_email' => "wpe-feedpage-{$tag}@example.invalid", 'role' => 'shop_manager']);
    $tempUsers[] = (int)$uid;
    wp_set_current_user((int)$uid);
    $html = $render([]);
    $check(!str_contains($html, 'wpe-feeds__create'), 'shop manager must not see Create');
    $html = $render(['feed' => (string)$feed['id']]);
    $check(!str_contains($html, 'id="wpe-feed-url"') && !str_contains($html, 'data-regenerate') && !str_contains($html, 'id="wpe-feed-save"'), 'shop manager must not see URL, actions or save');
    $check(preg_match('/data-path="name"[^>]*disabled/', $html) === 1, 'shop manager form must be disabled');
    $check(str_contains($render(['feed' => 'new']), 'wpe-notice is-error'), 'shop manager cannot open Create');
    wp_set_current_user((int)$admins[0]);
    echo "  [OK] shop manager: read-only form, no URL, no actions, no create\n";

    // 6. Menu and assets.
    set_current_screen('dashboard');
    PluginBootstrap::instance()->registerAdminMenu();
    global $submenu;
    $slugs = array_column($submenu['wc-product-engine'] ?? [], 2);
    $check(in_array(FeedsPage::SLUG, $slugs, true), 'Product Feeds submenu must be registered');
    PluginBootstrap::instance()->enqueueAdminAssets('wc-product-engine_page_' . FeedsPage::SLUG);
    $check(wp_script_is('wpe-feeds-js', 'enqueued') && wp_style_is('wpe-feeds-css', 'enqueued') && wp_style_is('wpe-admin-ui', 'enqueued'), 'feeds assets must be enqueued on the screen');
    echo "  [OK] submenu registered; feeds.js/feeds.css and the UI kit enqueued\n";

    // 7. Live preview before the first build, then inline rebuild via REST (small test catalog).
    $req = new \WP_REST_Request('GET', '/wpe/v1/admin/feeds/' . $feed['id'] . '/preview');
    $res = rest_do_request($req);
    $check($res->get_status() === 200 && $res->get_data()['source'] === 'live' && count($res->get_data()['items']) > 0, 'preview before build must render live items');
    $check(str_contains((string)$res->get_data()['rendered'], '<rss'), 'preview must include the rendered fragment');
    $products = (new \WPE\Application\Feeds\FeedItemSource($wpdb))->countProducts();
    if ($products <= \WPE\Admin\Rest\FeedsApiController::INLINE_REBUILD_MAX_PRODUCTS) {
        $req = new \WP_REST_Request('POST', '/wpe/v1/admin/feeds/' . $feed['id'] . '/regenerate');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body('{"mode":"rebuild"}');
        $res = rest_do_request($req);
        $check(in_array($res->get_status(), [200, 202], true), 'regenerate rebuild must be accepted, got ' . $res->get_status());
        if ($res->get_status() === 200) {
            $check($res->get_data()['feed']['status'] === 'ready' && !empty($res->get_data()['feed']['stats']['published']['items']), 'inline rebuild must publish');
            $req = new \WP_REST_Request('POST', '/wpe/v1/admin/feeds/' . $feed['id'] . '/regenerate');
            $req->set_header('Content-Type', 'application/json');
            $req->set_body('{"mode":"publish"}');
            $res = rest_do_request($req);
            $check($res->get_status() === 200 && $res->get_data()['status'] === 'published', 'publish now must publish');
            $diag = rest_do_request(new \WP_REST_Request('GET', '/wpe/v1/admin/feeds/' . $feed['id'] . '/diagnostics'))->get_data();
            $check(count($diag['runs']) >= 2 && $diag['totals']['eligible'] > 0, 'diagnostics must list the runs and totals');
        }
        echo "  [OK] live preview before build; inline rebuild + publish now through REST; diagnostics runs\n";
    }

    // 8. Create → build right away: the open Edit screen drives `continue` steps to a published
    // file for any catalog size; `continue` never starts a new build.
    $post = static function (string $path, array $body): \WP_REST_Response {
        $req = new \WP_REST_Request('POST', '/wpe/v1/admin/feeds' . $path);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(wp_json_encode($body));
        return rest_do_request($req);
    };
    $res = $post('', ['name' => "Page continue {$tag}", 'platform' => 'microsoft', 'format' => 'tsv', 'enabled' => true]);
    $check($res->get_status() === 201 && $res->get_data()['rebuild_pending'] === true, 'a new feed must have a pending build');
    $newId = (int)$res->get_data()['id'];
    $feedIds[] = $newId;
    $steps = 0;
    do {
        $res = $post("/{$newId}/regenerate", ['mode' => 'continue']);
        $steps++;
        $check(in_array($res->get_status(), [200, 202], true), 'continue must step, got ' . $res->get_status() . ' ' . wp_json_encode($res->get_data()));
    } while ($res->get_status() === 202 && $steps < 200);
    $data = $res->get_data();
    $check($data['status'] === 'completed' && $data['feed']['status'] === 'ready' && !empty($data['feed']['last_published_at']), 'continue must finish with a published file: ' . wp_json_encode($data['status']));
    $check(is_file((string)(new \WPE\Application\Feeds\FeedBuilder($wpdb))->publishedFile($repo->get($newId))), 'published file must exist after the driven build');
    $gen = (int)$repo->get($newId)['active_generation'];
    $res = $post("/{$newId}/regenerate", ['mode' => 'continue']);
    $check($res->get_status() === 200 && $res->get_data()['status'] === 'idle' && (int)$repo->get($newId)['active_generation'] === $gen && (int)$repo->get($newId)['build_generation'] === 0, 'continue without a pending build must be idle and start nothing');
    $check($post("/{$newId}/regenerate", ['mode' => 'bogus'])->get_status() === 422, 'unknown mode must be rejected');
    $js = (string)file_get_contents(dirname(__DIR__, 2) . '/assets/admin/feeds.js');
    $check(str_contains($js, "mode: 'continue'") && str_contains($js, 'driveBuild()'), 'Edit screen must drive pending builds');
    echo "  [OK] create → continue steps build and publish ({$steps} step(s)); idle afterwards; Edit screen drives it\n";

    echo "ALL FEEDS PAGE ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    foreach ($feedIds as $id) {
        $repo->delete($id);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($tempUsers as $u) {
        wp_delete_user($u);
    }
    $wpdb->delete("{$prefix}wpe_control", ['key' => FeedScheduler::HEARTBEAT_KEY]);
    if ($heartbeat) {
        $wpdb->insert("{$prefix}wpe_control", $heartbeat);
    }
    wp_set_current_user($originalUser);
}
