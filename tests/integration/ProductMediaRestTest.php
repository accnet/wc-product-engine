<?php

/**
 * P011.6 — Product media REST routes.
 *
 * Route registration, permissions with the error envelope, multipart upload and URL add, library read, patch
 * with If-Match and Idempotency-Key, unlink preview, session claim, rate limit and error mapping. Uses a fake
 * storage provider; this is not a certification of real storage.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';
require_once __DIR__ . '/support/FakeStorageProvider.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductMediaApiController;
use WPE\Application\Media\ManagedUploadService;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Application\Media\ProductMediaLibrary;
use WPE\Application\Media\Storage\LocalStorageProvider;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

class ProductMediaRestTest
{
    private wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private FakeStorageProvider $fake;
    private ProductMediaApiController $api;
    private array $tmp = [];
    private array $posts = [];
    private array $users = [];
    private array $assetIds = [];
    private array $transients = [];
    private int $checks = 0;
    private int $hookCalls = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb    = $wpdb;
        $this->media   = new MediaRepository($wpdb);
        $this->uploads = new UploadItemRepository($wpdb);
        $this->fake    = new FakeStorageProvider();
        $local         = new LocalStorageProvider($this->tmpDir(), 'https://uploads.example.test');
        $resolver      = new StorageResolver(fn () => $this->fake, static fn () => true, $local);
        $uploader      = new ManagedUploadService($resolver, $this->media, $this->uploads, null, static fn () => true, $wpdb, 1);
        $this->api     = new ProductMediaApiController(new ProductMediaLibrary($this->media, $this->uploads, $wpdb), $uploader, $this->uploads, null, $this->media);
    }

    public function run(): void
    {
        echo "Running product media REST tests...\n";
        add_action('wpe_product_media_changed', function () {
            $this->hookCalls++;
        });
        wp_set_current_user(1);
        // No network in tests: every host resolves to a public documentation address.
        UrlValidator::setDnsResolver(static fn (string $host): array => ['93.184.216.34']);
        try {
            $this->testRoutes();
            $this->testPermissions();
            $this->testUpload();
            $this->testUrlMode();
            $this->testLibraryAndPatch();
            $this->testIdempotency();
            $this->testImpactAndPreview();
            $this->testSessionClaim();
            $this->testRateLimit();
            echo "All product media REST tests passed ({$this->checks} checks).\n";
        } finally {
            $this->cleanup();
        }
    }

    /* ------------------------------------------------------------------ */

    private function ok(bool $cond, string $label): void
    {
        $this->checks++;
        if (!$cond) {
            throw new RuntimeException("FAILED: {$label}");
        }
        echo "  [OK] {$label}\n";
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/wpe-pmr-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->tmp[] = $dir;
        return $dir;
    }

    private function product(int $author = 1): int
    {
        $id = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'P011 rest test', 'post_author' => $author]);
        $this->posts[] = $id;
        return (int) $id;
    }

    private function user(string $role): int
    {
        $id = wp_insert_user(['user_login' => 'p011-rest-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(20), 'user_email' => 'p011rest' . wp_generate_password(6, false, false) . '@example.test', 'role' => $role]);
        $this->users[] = $id;
        return (int) $id;
    }

    private function png(int $n): array
    {
        $im = imagecreatetruecolor(20, 14);
        imagefill($im, 0, 0, imagecolorallocate($im, ($n * 17) % 250, ($n * 29) % 250, 60));
        imagesetpixel($im, 3, 3, imagecolorallocate($im, ($n * 41) % 250, 9, 9));
        $path = tempnam(sys_get_temp_dir(), 'wpe-pmr-img-');
        imagepng($im, $path);
        imagedestroy($im);
        $this->tmp[] = $path;
        return ['tmp_name' => $path, 'name' => "rest-{$n}.png", 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
    }

    private function request(string $method, string $route, array $params = [], array $headers = [], ?array $json = null, array $files = []): WP_REST_Request
    {
        $r = new WP_REST_Request($method, $route);
        foreach ($params as $k => $v) {
            $r->set_param($k, $v);
        }
        foreach ($headers as $k => $v) {
            $r->set_header($k, $v);
        }
        if ($json !== null) {
            $r->set_header('content-type', 'application/json');
            $r->set_body(wp_json_encode($json));
        }
        if ($files !== []) {
            $r->set_file_params($files);
        }
        return $r;
    }

    private function upload(int $productId, int $n, ?string $key = null, ?string $session = null): array
    {
        $params = ['upload_key' => $key ?? 'p011t-rest-' . $n . '-' . bin2hex(random_bytes(3))];
        $params += $session !== null ? ['session_id' => $session] : ['product_id' => (string) $productId];
        $res = $this->api->createAsset($this->request('POST', '/wpe/v1/admin/assets', $params, [], null, ['file' => $this->png($n)]));
        if ($res->get_status() === 201) {
            $this->assetIds[] = (int) $res->get_data()['asset_id'];
        }
        return [$res->get_status(), $res->get_data(), $res];
    }

    private function patch(int $productId, array $body, ?string $version, ?string $key = 'p011t-patch-key'): WP_REST_Response
    {
        $headers = [];
        if ($key !== null) {
            $headers['idempotency_key'] = $key;
        }
        if ($version !== null) {
            $headers['if_match'] = '"' . $version . '"';
        }
        $res = $this->api->patchLibrary($this->request('PATCH', "/wpe/v1/admin/products/{$productId}/media", ['id' => (string) $productId], $headers, $body));
        $this->trackIdem($key, $productId);
        return $res;
    }

    private function trackIdem(?string $key, int $productId): void
    {
        if ($key !== null) {
            $this->transients[] = 'wpe_idem_' . substr(hash('sha256', get_current_user_id() . '|PATCH media ' . $productId . '|' . $key), 0, 40);
        }
    }

    private function library(int $productId): array
    {
        return $this->api->getLibrary($this->request('GET', "/wpe/v1/admin/products/{$productId}/media", ['id' => (string) $productId]))->get_data();
    }

    private function envelope(WP_REST_Response $res, int $status, string $code): bool
    {
        $d = $res->get_data();
        return $res->get_status() === $status
            && is_array($d)
            && array_keys($d) === ['code', 'message', 'details', 'request_id', 'retryable']
            && $d['code'] === $code
            && is_array($d['details']) && $d['details'] !== []
            && is_string($d['request_id']) && $d['request_id'] !== '';
    }

    /* ------------------------------------------------------------------ */

    private function testRoutes(): void
    {
        echo "[rest] routes\n";
        $routes = rest_get_server()->get_routes();
        foreach (['/wpe/v1/admin/assets', '/wpe/v1/admin/products/(?P<id>[1-9][0-9]*)/media', '/wpe/v1/admin/products/(?P<id>[1-9][0-9]*)/media/unlink-preview', '/wpe/v1/admin/upload-sessions/(?P<session>[A-Za-z0-9-]{8,64})/claim'] as $route) {
            $this->ok(isset($routes[$route]), "route registered: {$route}");
        }
        $methods = [];
        foreach ($routes['/wpe/v1/admin/products/(?P<id>[1-9][0-9]*)/media'] as $handler) {
            $methods = array_merge($methods, array_keys($handler['methods']));
        }
        $this->ok(in_array('GET', $methods, true) && in_array('PATCH', $methods, true), 'the library route serves GET and PATCH');
    }

    private function testPermissions(): void
    {
        echo "[rest] permissions\n";
        $pid = $this->product(1);
        // serve_request applies this filter to every answer; rest_do_request skips it, so apply it the same way.
        $dispatch = function (string $method, string $route) {
            $request = new WP_REST_Request($method, $route);
            return apply_filters('rest_post_dispatch', rest_do_request($request), rest_get_server(), $request);
        };

        wp_set_current_user(0);
        $res = $dispatch('GET', "/wpe/v1/admin/products/{$pid}/media");
        $this->ok($this->envelope($res, 401, 'unauthenticated'), 'a visitor gets 401 in the error envelope');

        $subscriber = $this->user('subscriber');
        wp_set_current_user($subscriber);
        $res = $dispatch('GET', "/wpe/v1/admin/products/{$pid}/media");
        $this->ok($this->envelope($res, 403, 'forbidden'), 'a user without upload_files gets 403');
        $res = $dispatch('POST', '/wpe/v1/admin/assets');
        $this->ok($this->envelope($res, 403, 'forbidden'), 'uploading needs upload_files');

        $author = $this->user('author');
        wp_set_current_user($author);
        $res = $dispatch('GET', "/wpe/v1/admin/products/{$pid}/media");
        $this->ok($this->envelope($res, 403, 'forbidden'), 'an author cannot read the library of a product they cannot edit');
        $res = $dispatch('GET', '/wpe/v1/admin/products/987654321/media');
        $this->ok($this->envelope($res, 404, 'not_found'), 'an unknown product is 404');
        $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'P011 rest page', 'post_author' => $author]);
        $this->posts[] = $page;
        $res = $dispatch('GET', "/wpe/v1/admin/products/{$page}/media");
        $this->ok($this->envelope($res, 404, 'not_found'), 'a post that is not a product is 404');

        wp_set_current_user(1);
        $res = $dispatch('GET', "/wpe/v1/admin/products/{$pid}/media");
        $this->ok($res->get_status() === 200 && isset($res->get_data()['items']), 'an editor reads the library through the real route');
    }

    private function testUpload(): void
    {
        echo "[rest] upload\n";
        $pid = $this->product();
        [$status, $data, $res] = $this->upload($pid, 1);
        $this->ok($status === 201 && $data['origin'] === 'managed' && $data['id'] === $data['asset_id'], 'a file upload answers 201 with a managed asset');
        $this->ok(count($this->fake->objects) >= 1, 'the file went to the storage provider');

        $items = array_column($this->library($pid)['items'], 'role', 'asset_id');
        $this->ok(($items[$data['asset_id']] ?? '') === 'pending_link', 'the upload waits for a link in the library');

        $bad = $this->png(2);
        file_put_contents($bad['tmp_name'], 'not an image');
        $res = $this->api->createAsset($this->request('POST', '/wpe/v1/admin/assets', ['upload_key' => 'p011t-rest-bad-' . bin2hex(random_bytes(3)), 'product_id' => (string) $pid], [], null, ['file' => $bad]));
        $this->ok($this->envelope($res, 422, 'validation_failed'), 'a file that is not an image is a validation error');

        $this->fake->failPut = true;
        [$status, $d] = $this->upload($pid, 3);
        $this->fake->failPut = false;
        $this->ok($status === 503 && $d['code'] === 'dependency_unavailable' && $d['retryable'] === true && $d['details'][0]['reason'] === 'upload_failed', 'a storage failure is 503, retryable, with a reason');

        $res = $this->api->createAsset($this->request('POST', '/wpe/v1/admin/assets', ['product_id' => (string) $pid], [], null, ['file' => $this->png(4)]));
        $this->ok($this->envelope($res, 400, 'invalid_request'), 'the upload key is required');

        $key = 'p011t-rest-same-' . bin2hex(random_bytes(3));
        [$s1, $d1] = $this->upload($pid, 5, $key);
        [$s2, $d2] = $this->upload($pid, 5, $key);
        $this->ok($s1 === 201 && $s2 === 201 && $d1['asset_id'] === $d2['asset_id'] && $d2['reused'] === true, 'a retry with the same key returns the same asset');
    }

    private function testUrlMode(): void
    {
        echo "[rest] URL mode\n";
        $pid = $this->product();
        $url = 'https://cdn.example.com/p011-' . bin2hex(random_bytes(4)) . '.jpg';
        $key = 'p011t-url-' . bin2hex(random_bytes(4));
        $req = fn (array $body, ?string $k) => $this->request('POST', '/wpe/v1/admin/assets', ['product_id' => (string) $pid], $k === null ? [] : ['idempotency_key' => $k], $body);
        $res = $this->api->createAsset($req(['url' => $url, 'alt_text' => 'CDN image', 'width' => 400, 'height' => 300], $key));
        $this->ok($res->get_status() === 201 && $res->get_data()['origin'] === 'external', 'a CDN URL is added as an external asset');
        $this->assetIds[] = (int) $res->get_data()['asset_id'];
        $items = array_column($this->library($pid)['items'], 'origin', 'asset_id');
        $this->ok(($items[$res->get_data()['asset_id']] ?? '') === 'external', 'the library shows it as external and pending');
        $again = $this->api->createAsset($req(['url' => $url], $key));
        $this->ok($again->get_status() === 201 && $again->get_data()['asset_id'] === $res->get_data()['asset_id'] && $again->get_data()['reused'] === true, 'the same key returns the same asset');

        $res = $this->api->createAsset($req(['url' => 'http://localhost/a.jpg'], 'p011t-url-bad-' . bin2hex(random_bytes(3))));
        $this->ok($this->envelope($res, 422, 'validation_failed') && $res->get_data()['details'][0]['pointer'] === '/url', 'an unsafe URL is refused');
        $res = $this->api->createAsset($req(['url' => $url, 'extra' => 1], 'p011t-url-x-' . bin2hex(random_bytes(3))));
        $this->ok($this->envelope($res, 400, 'invalid_request'), 'unknown fields are refused');
        $res = $this->api->createAsset($req(['url' => $url], null));
        $this->ok($this->envelope($res, 428, 'precondition_required'), 'the Idempotency-Key header is required');
        $other = $this->product(1);
        $author = $this->user('author');
        wp_set_current_user($author);
        $res = $this->api->createAsset($this->request('POST', '/wpe/v1/admin/assets', ['product_id' => (string) $other], ['idempotency_key' => 'p011t-url-f-' . bin2hex(random_bytes(3))], ['url' => $url]));
        wp_set_current_user(1);
        $this->ok($this->envelope($res, 403, 'forbidden'), 'adding an image to a product the user cannot edit is refused');
    }

    private function testLibraryAndPatch(): void
    {
        echo "[rest] library and patch\n";
        $pid = $this->product();
        [, $a] = $this->upload($pid, 11);
        [, $b] = $this->upload($pid, 12);
        $lib = $this->api->getLibrary($this->request('GET', '', ['id' => (string) $pid]));
        $this->ok($lib->get_status() === 200 && preg_match('/^[0-9]+$/', $lib->get_data()['version']) === 1, 'the library version is a digit string');
        $this->ok(($lib->get_headers()['ETag'] ?? '') === '"' . $lib->get_data()['version'] . '"' && str_contains($lib->get_headers()['Cache-Control'] ?? '', 'no-store'), 'the library has an ETag and is not cached');
        $v = $lib->get_data()['version'];

        $body = ['collection_mode' => 'merge', 'featured_asset_id' => $a['asset_id'], 'gallery' => [['asset_id' => $b['asset_id'], 'position' => 1]]];
        $this->ok($this->envelope($this->patch($pid, $body, null, 'p011t-k-nomatch'), 428, 'precondition_required'), 'If-Match is required');
        $this->ok($this->envelope($this->patch($pid, $body, $v, null), 428, 'precondition_required'), 'Idempotency-Key is required');
        $this->ok($this->envelope($this->patch($pid, ['featured_asset_id' => null], $v, 'p011t-k-mode'), 400, 'invalid_request'), 'collection_mode is required');
        $this->ok($this->envelope($this->patch($pid, ['collection_mode' => 'merge', 'bogus' => 1], $v, 'p011t-k-bogus'), 400, 'invalid_request'), 'unknown fields are refused');
        $this->ok($this->envelope($this->patch($pid, ['collection_mode' => 'merge', 'featured_asset_id' => 12], $v, 'p011t-k-int'), 400, 'invalid_request'), 'asset ids must be digit strings');
        $this->ok($this->envelope($this->patch($pid, $body, '1', 'p011t-k-stale'), 412, 'stale_version'), 'a stale version is 412');
        $res = $this->patch($pid, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => '999999995', 'position' => 1]]], $v, 'p011t-k-unknown');
        $this->ok($this->envelope($res, 422, 'validation_failed') && $res->get_data()['details'][0]['reason'] === 'asset_not_in_product', 'an asset outside the library is refused with its reason');
        $this->ok($this->library($pid)['version'] === $v, 'refused requests change nothing');

        $before = $this->hookCalls;
        $res    = $this->patch($pid, $body, $v, 'p011t-k-ok');
        $d      = $res->get_data();
        $this->ok($res->get_status() === 200 && array_keys($d) === ['id', 'version', 'parent_version'] && $d['id'] === (string) $pid && $d['version'] !== $v, 'a valid patch answers 200 with id and new version');
        $this->ok($this->hookCalls === $before + 1, 'the media changed action fired once after the commit');
        $roles = array_column($this->library($pid)['items'], 'role', 'asset_id');
        $this->ok($roles[$a['asset_id']] === 'featured' && $roles[$b['asset_id']] === 'gallery', 'the images are linked in the product library');

        $noChange = $this->patch($pid, ['collection_mode' => 'merge'], $d['version'], 'p011t-k-noop');
        $this->ok($noChange->get_status() === 200 && $this->hookCalls === $before + 1, 'a patch that changes nothing does not fire the action');
    }

    private function testIdempotency(): void
    {
        echo "[rest] idempotency\n";
        $pid = $this->product();
        [, $a] = $this->upload($pid, 21);
        $v    = $this->library($pid)['version'];
        $body = ['collection_mode' => 'merge', 'featured_asset_id' => $a['asset_id']];
        $first = $this->patch($pid, $body, $v, 'p011t-k-idem');
        $before = $this->hookCalls;
        $replay = $this->patch($pid, $body, $v, 'p011t-k-idem');
        $this->ok($replay->get_status() === 200 && $replay->get_data() === $first->get_data() && ($replay->get_headers()['Idempotent-Replay'] ?? '') === 'true', 'the same key and body replay the first answer');
        $this->ok($this->hookCalls === $before, 'a replay does not apply the patch again');
        $conflict = $this->patch($pid, ['collection_mode' => 'merge', 'featured_asset_id' => null], $v, 'p011t-k-idem');
        $this->ok($this->envelope($conflict, 409, 'idempotency_conflict'), 'the same key with another body is an idempotency conflict');
    }

    private function testImpactAndPreview(): void
    {
        echo "[rest] impact and preview\n";
        $pid = $this->product();
        [, $a] = $this->upload($pid, 31);
        [, $b] = $this->upload($pid, 32);
        $v = $this->library($pid)['version'];
        $this->patch($pid, ['collection_mode' => 'merge', 'featured_asset_id' => $a['asset_id'], 'gallery' => [['asset_id' => $b['asset_id'], 'position' => 1]]], $v, 'p011t-k-imp1');

        $prev = fn (array $body) => $this->api->unlinkPreview($this->request('POST', '', ['id' => (string) $pid], [], $body));
        $res  = $prev(['asset_ids' => [$a['asset_id'], $b['asset_id']]]);
        $d    = $res->get_data();
        $this->ok($res->get_status() === 200 && $d['requires_resolution'] === true && count($d['affected']) === 2, 'the preview lists the affected places');
        $this->ok($this->envelope($prev(['asset_ids' => []]), 400, 'invalid_request'), 'the preview needs at least one asset id');
        $this->ok($this->envelope($prev(['asset_ids' => [5]]), 400, 'invalid_request'), 'the preview refuses invalid ids');
        $this->ok($this->envelope($prev(['asset_ids' => [$a['asset_id']], 'x' => 1]), 400, 'invalid_request'), 'the preview refuses unknown fields');

        $v   = $this->library($pid)['version'];
        $res = $this->patch($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $a['asset_id']]]], $v, 'p011t-k-imp2');
        $d   = $res->get_data();
        $this->ok($this->envelope($res, 409, 'conflict') && $d['details'][0]['reason'] === 'impact_requires_resolution' && $d['details'][0]['pointer'] === '/assets/' . $a['asset_id'] . '/featured', 'removing an in-use image without a resolution is a 409 with the place');
        $res = $this->patch($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $a['asset_id'], 'replacement_asset_id' => $b['asset_id']]]], $v, 'p011t-k-imp3');
        $this->ok($res->get_status() === 200, 'removing with a replacement succeeds');
        $objects = count($this->fake->objects);
        $this->ok($objects >= 2 && $this->media->getAssetStorage((int) $a['asset_id']) !== null, 'the file and its storage record are kept');
    }

    private function testSessionClaim(): void
    {
        echo "[rest] session claim\n";
        $session = 'sess-' . bin2hex(random_bytes(6));
        [$status, $data] = $this->upload(0, 41, null, $session);
        $this->ok($status === 201, 'a file can be uploaded for a product that does not exist yet');

        $pid   = $this->product();
        $claim = fn (string $s, array $body, ?string $key) => $this->api->claimSession($this->request('POST', '', ['session' => $s], $key === null ? [] : ['idempotency_key' => $key], $body));
        $this->transients[] = 'wpe_idem_' . substr(hash('sha256', '1|POST claim ' . $session . '|p011t-claim'), 0, 40);

        $this->ok($this->envelope($claim($session, ['product_id' => (string) $pid], null), 428, 'precondition_required'), 'the claim needs an Idempotency-Key');
        $this->ok($this->envelope($claim($session, [], 'p011t-claim'), 400, 'invalid_request'), 'the claim needs a product');
        $this->ok($this->envelope($claim($session, ['product_id' => '987654321'], 'p011t-claim'), 404, 'not_found'), 'the product must exist');

        $author = $this->user('author');
        wp_set_current_user($author);
        $res = $claim($session, ['product_id' => (string) $pid], 'p011t-claim-author');
        wp_set_current_user(1);
        $this->ok($this->envelope($res, 403, 'forbidden'), 'the claim needs edit rights on the product');

        $res = $claim($session, ['product_id' => (string) $pid], 'p011t-claim');
        $this->ok($res->get_status() === 200 && $res->get_data() === ['claimed' => 1], 'the session uploads are given to the product');
        $items = array_column($this->library($pid)['items'], 'role', 'asset_id');
        $this->ok(($items[$data['asset_id']] ?? '') === 'pending_link', 'the claimed upload is pending in the product library');
        $replay = $claim($session, ['product_id' => (string) $pid], 'p011t-claim');
        $this->ok($replay->get_data() === ['claimed' => 1], 'a replay returns the first answer');

        $second = $this->product();
        $this->transients[] = 'wpe_idem_' . substr(hash('sha256', '1|POST claim ' . $session . '|p011t-claim2'), 0, 40);
        $res = $claim($session, ['product_id' => (string) $second], 'p011t-claim2');
        $this->ok($res->get_data() === ['claimed' => 0], 'uploads already given to a product are not claimed again');
    }

    private function testRateLimit(): void
    {
        echo "[rest] rate limit\n";
        $pid  = $this->product();
        $slot = (int) floor(time() / 60);
        $name = 'wpe_rl_upload_1_' . $slot;
        $this->transients[] = $name;
        set_transient($name, 1000, 90);
        [$status, $d, $res] = $this->upload($pid, 51);
        $this->ok($status === 429 && $d['code'] === 'rate_limited' && $d['retryable'] === true && ($res->get_headers()['Retry-After'] ?? '') !== '', 'too many uploads is 429 with Retry-After');
        delete_transient($name);
        // The counter of the next minute is not touched; remove it too.
        $this->transients[] = 'wpe_rl_upload_1_' . ($slot + 1);
    }

    /* ------------------------------------------------------------------ */

    private function cleanup(): void
    {
        UrlValidator::setDnsResolver(null);
        wp_set_current_user(1);
        $p = $this->wpdb->prefix;
        foreach ($this->transients as $t) {
            delete_transient($t);
        }
        $slot = (int) floor(time() / 60);
        foreach (array_merge([1], $this->users) as $uid) {
            foreach ([$slot - 1, $slot, $slot + 1] as $s) {
                foreach (['upload', 'write'] as $bucket) {
                    delete_transient("wpe_rl_{$bucket}_{$uid}_{$s}");
                }
            }
        }
        foreach ($this->posts as $id) {
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$p}wpe_product_media WHERE product_id = %d", $id));
        }
        $this->wpdb->query("DELETE FROM {$p}wpe_upload_items WHERE upload_key LIKE 'p011t-%'");
        foreach (array_unique($this->assetIds) as $id) {
            $this->media->deleteAssetStorage((int) $id);
            $this->media->deleteAsset((int) $id, true);
        }
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->users as $id) {
            wp_delete_user($id);
        }
        foreach ($this->tmp as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}

try {
    (new ProductMediaRestTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
