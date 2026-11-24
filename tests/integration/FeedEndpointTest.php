<?php
/**
 * P035.8 — Token-protected public feed URL.
 *
 * Verifies FeedEndpoint::resolve() for: the pretty and query URL forms of a published feed (200,
 * content type, length, last-modified, ETag, no page-cache storage); the gzip copy; 304 on a
 * matching ETag or a recent If-Modified-Since; one indistinguishable 404 for an unknown key, a
 * wrong token, a wrong extension, a disabled feed and a feed with no published file; and that a
 * rotated token revokes the old URL. The request handler is registered on do_parse_request.
 *
 * Run: wp eval-file tests/integration/FeedEndpointTest.php
 */

use WPE\Application\Feeds\FeedBuilder;
use WPE\Application\Feeds\FeedEndpoint;
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
        throw new \RuntimeException('FeedEndpointTest failed: ' . $msg);
    }
    $checks++;
};

$builder = new FeedBuilder($wpdb);
$feeds = $builder->feeds();
$endpoint = new FeedEndpoint($feeds, $builder);
$repo = new ProductRepository($wpdb);
$tag = strtolower(wp_generate_password(6, false));
$feedIds = [];
$pid = 0;
$assetId = 0;
$term = wp_insert_term("Endpoint {$tag}", 'product_cat');

$pathOf = static fn(string $url): string => (string)parse_url($url, PHP_URL_PATH);
$queryOf = static function (string $url): array {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    return $q;
};

try {
    echo "=== FeedEndpointTest ===\n";
    $check(has_filter('do_parse_request') !== false, 'the endpoint must be registered on do_parse_request');

    $title = "Endpoint Lamp {$tag}";
    $created = $repo->create(['title' => $title, 'slug' => sanitize_title($title), 'type' => 'simple', 'status' => 'publish', 'sku' => "EPL-{$tag}", 'regular_price' => '5.00', 'description' => 'd']);
    $pid = (int)$created['id'];
    $check($pid > 0 && $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) === $title, 'fixture');
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $pid]);
    wp_set_object_terms($pid, [(int)$term['term_id']], 'product_cat');
    $asset = (new MediaRepository($wpdb))->createAsset(['source_url' => "https://cdn.example.com/{$tag}.jpg"]);
    $assetId = (int)$asset['id'];
    (new MediaRepository($wpdb))->setFeaturedImage($pid, $assetId);

    $feed = $feeds->create(['name' => "Endpoint {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true,
        'config' => ['filters' => ['include_categories' => [(int)$term['term_id']]], 'compress' => true]]);
    $feedIds[] = (int)$feed['id'];
    do {
        $r = $builder->buildStep((int)$feed['id'], 30.0);
    } while ($r['status'] === 'building');
    $feed = $feeds->get((int)$feed['id']);
    $token = $feeds->token($feed);
    $pretty = home_url("/wpe-feed/{$feed['feed_key']}/{$token}.xml");
    $file = $builder->publishedFile($feed);

    // 1. Pretty URL.
    $res = $endpoint->resolve($pathOf($pretty), [], []);
    $check($res['status'] === 200 && $res['file'] === $file, 'pretty URL must serve the published file');
    $check($res['headers']['Content-Type'] === 'application/xml; charset=UTF-8' && (int)$res['headers']['Content-Length'] === filesize($file), 'type/length');
    $check($res['headers']['X-Accel-Expires'] === '0' && isset($res['headers']['ETag'], $res['headers']['Last-Modified']), 'cache headers');
    echo "  [OK] pretty URL: 200 with type, length, ETag, Last-Modified, X-Accel-Expires: 0\n";

    // 2. Query form and gzip.
    $res = $endpoint->resolve('/', ['wpe_feed' => $feed['feed_key'], 'token' => $token, 'format' => 'xml'], []);
    $check($res['status'] === 200, 'query form must serve the file');
    $res = $endpoint->resolve($pathOf($pretty) . '.gz', [], []);
    $check($res['status'] === 200 && $res['headers']['Content-Type'] === 'application/gzip' && str_ends_with((string)$res['file'], '.gz'), 'gzip copy');
    $check(gzdecode((string)file_get_contents($res['file'])) === file_get_contents($file), 'gzip copy must match');
    echo "  [OK] query form and .gz copy\n";

    // 3. Conditional requests.
    $etag = $endpoint->resolve($pathOf($pretty), [], [])['headers']['ETag'];
    $check($endpoint->resolve($pathOf($pretty), [], ['HTTP_IF_NONE_MATCH' => $etag])['status'] === 304, 'matching ETag must be 304');
    $check($endpoint->resolve($pathOf($pretty), [], ['HTTP_IF_NONE_MATCH' => '"other"'])['status'] === 200, 'other ETag must be 200');
    $check($endpoint->resolve($pathOf($pretty), [], ['HTTP_IF_MODIFIED_SINCE' => gmdate('D, d M Y H:i:s', time() + 60) . ' GMT'])['status'] === 304, 'recent If-Modified-Since must be 304');
    echo "  [OK] 304 for matching ETag / If-Modified-Since\n";

    // 4. One 404 for every failure.
    $cases = [
        'unknown key' => $endpoint->resolve("/wpe-feed/no-such-feed/{$token}.xml", [], []),
        'wrong token' => $endpoint->resolve("/wpe-feed/{$feed['feed_key']}/" . str_repeat('a', 64) . '.xml', [], []),
        'wrong ext'   => $endpoint->resolve("/wpe-feed/{$feed['feed_key']}/{$token}.tsv", [], []),
        'bad token'   => $endpoint->resolve('/', ['wpe_feed' => $feed['feed_key'], 'token' => 'x', 'format' => 'xml'], []),
    ];
    $feeds->update((int)$feed['id'], ['enabled' => false], (int)$feed['version']);
    $cases['disabled'] = $endpoint->resolve($pathOf($pretty), [], []);
    $empty = $feeds->create(['name' => "Endpoint empty {$tag}", 'platform' => 'google', 'format' => 'xml', 'enabled' => true]);
    $feedIds[] = (int)$empty['id'];
    $cases['no file'] = $endpoint->resolve($pathOf($feeds->publicUrl($empty)), [], []);
    foreach ($cases as $label => $res) {
        $check($res['status'] === 404 && $res['file'] === null && $res['headers']['Cache-Control'] === 'no-store', "{$label} must be the same 404");
    }
    echo "  [OK] unknown key, wrong token/extension, disabled feed and missing file all return the same 404\n";

    // 5. Rotation revokes the old URL.
    $f = $feeds->get((int)$feed['id']);
    $feeds->update((int)$feed['id'], ['enabled' => true], (int)$f['version']);
    $f = $feeds->rotateToken((int)$feed['id']);
    $check($endpoint->resolve($pathOf($pretty), [], [])['status'] === 404, 'old URL must stop working after rotation');
    $check($endpoint->resolve($pathOf($feeds->publicUrl($f)), [], [])['status'] === 200, 'new URL must work');
    echo "  [OK] rotated token revokes the old URL\n";

    echo "ALL FEED ENDPOINT ASSERTIONS PASSED ({$checks} checks)\n";
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
    if ($assetId > 0) {
        $wpdb->delete("{$prefix}wpe_product_media", ['asset_id' => $assetId]);
        $wpdb->delete("{$prefix}wpe_assets", ['id' => $assetId]);
    }
    if ($term && !is_wp_error($term)) {
        wp_delete_term((int)$term['term_id'], 'product_cat');
    }
}
