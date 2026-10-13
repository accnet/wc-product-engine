<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use InvalidArgumentException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WPE\Application\Media\ManagedUploadService;
use WPE\Application\Media\ProductMediaLibrary;
use WPE\Application\Media\Storage\StorageException;
use WPE\Application\Media\UploadRejectedException;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

/**
 * REST routes of the per-product image library (docs/04 §7, contracts/api/endpoints.json):
 *
 * - POST  /wpe/v1/admin/assets                              upload a file or add a CDN URL (product or session)
 * - GET   /wpe/v1/admin/products/{id}/media                 library read
 * - PATCH /wpe/v1/admin/products/{id}/media                 apply a media patch (If-Match = library version)
 * - POST  /wpe/v1/admin/products/{id}/media/unlink-preview  places affected by removing images
 * - POST  /wpe/v1/admin/upload-sessions/{session}/claim     give a session's uploads to a created product
 *
 * Every handler answers with the error envelope of docs/04 §1.6. Cookie requests carry the REST nonce, which
 * WordPress verifies before the permission callbacks run. Storage credentials never leave the backend.
 */
final class ProductMediaApiController
{
    private const NAMESPACE_ = 'wpe/v1';
    private const RATE_UPLOAD_PER_MINUTE = 60;
    private const RATE_WRITE_PER_MINUTE  = 120;
    private const IDEMPOTENCY_TTL        = DAY_IN_SECONDS;

    private static ?self $instance = null;

    private ?ProductMediaLibrary $library;
    private ?ManagedUploadService $uploader;
    private ?UploadItemRepository $uploads;
    private ?AssetService $assets;
    private ?MediaRepository $media;

    public function __construct(
        ?ProductMediaLibrary $library = null,
        ?ManagedUploadService $uploader = null,
        ?UploadItemRepository $uploads = null,
        ?AssetService $assets = null,
        ?MediaRepository $media = null
    ) {
        $this->library  = $library;
        $this->uploader = $uploader;
        $this->uploads  = $uploads;
        $this->assets   = $assets;
        $this->media    = $media;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ---------------------------------------------------------------------- */
    /* Routes                                                                  */
    /* ---------------------------------------------------------------------- */

    public function registerRoutes(): void
    {
        add_filter('rest_post_dispatch', [$this, 'shapePermissionErrors'], 10, 1);

        register_rest_route(self::NAMESPACE_, '/admin/assets', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createAsset'],
            'permission_callback' => [$this, 'canUpload'],
        ]);

        register_rest_route(self::NAMESPACE_, '/admin/products/(?P<id>[1-9][0-9]*)/media', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getLibrary'],
                'permission_callback' => [$this, 'canEditProductMedia'],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [$this, 'patchLibrary'],
                'permission_callback' => [$this, 'canEditProductMedia'],
            ],
        ]);

        register_rest_route(self::NAMESPACE_, '/admin/products/(?P<id>[1-9][0-9]*)/media/unlink-preview', [
            'methods'             => 'POST',
            'callback'            => [$this, 'unlinkPreview'],
            'permission_callback' => [$this, 'canEditProductMedia'],
        ]);

        register_rest_route(self::NAMESPACE_, '/admin/upload-sessions/(?P<session>[A-Za-z0-9-]{8,64})/claim', [
            'methods'             => 'POST',
            'callback'            => [$this, 'claimSession'],
            'permission_callback' => [$this, 'canUpload'],
        ]);
    }

    /** @return bool|\WP_Error */
    public function canUpload()
    {
        if (!is_user_logged_in()) {
            return $this->permissionError('unauthenticated', 'Sign in to manage product images.', 401);
        }
        return current_user_can('upload_files')
            ? true
            : $this->permissionError('forbidden', 'You are not allowed to upload files.', 403);
    }

    /** @return bool|\WP_Error */
    public function canEditProductMedia(WP_REST_Request $request)
    {
        $allowed = $this->canUpload();
        if ($allowed !== true) {
            return $allowed;
        }
        $productId = (int) $request->get_param('id');
        if ($productId <= 0 || get_post_type($productId) !== 'product') {
            return $this->permissionError('not_found', 'The product does not exist.', 404);
        }
        return current_user_can('edit_post', $productId)
            ? true
            : $this->permissionError('forbidden', 'You are not allowed to edit this product.', 403);
    }

    /**
     * Permission failures of these routes carry the error envelope of docs/04 §1.6 like every other answer.
     *
     * @param mixed $result
     * @return mixed
     */
    public function shapePermissionErrors($result)
    {
        if (!($result instanceof WP_REST_Response)) {
            return $result;
        }
        $data = $result->get_data();
        if (!is_array($data) || empty($data['data']['wpe_envelope'])) {
            return $result;
        }
        $status = (int) ($data['data']['status'] ?? 403);
        $result->set_data([
            'code'       => $this->codeFor($status),
            'message'    => (string) ($data['message'] ?? ''),
            'details'    => [['pointer' => '', 'reason' => (string) ($data['code'] ?? 'forbidden')]],
            'request_id' => wp_generate_uuid4(),
            'retryable'  => false,
        ]);
        return $result;
    }

    private function permissionError(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status, 'wpe_envelope' => true]);
    }

    /* ---------------------------------------------------------------------- */
    /* Handlers                                                                */
    /* ---------------------------------------------------------------------- */

    public function createAsset(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $this->rateLimit('upload', self::RATE_UPLOAD_PER_MINUTE);

            $files = $request->get_file_params();
            if (isset($files['file'])) {
                $result = $this->uploader()->upload($files['file'], [
                    'upload_key' => (string) $request->get_param('upload_key'),
                    'session_id' => (string) $request->get_param('session_id'),
                    'product_id' => (int) $request->get_param('product_id'),
                    'alt_text'   => (string) $request->get_param('alt_text'),
                ]);
            } else {
                $result = $this->addExternal($request);
            }

            return $this->json(['id' => $result['asset_id']] + $result, 201);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function getLibrary(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $library  = $this->library()->read((int) $request->get_param('id'), (int) get_current_user_id());
            $response = $this->json($library, 200);
            $response->header('ETag', '"' . $library['version'] . '"');
            $response->header('Cache-Control', 'no-store');
            return $response;
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function patchLibrary(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $this->rateLimit('write', self::RATE_WRITE_PER_MINUTE);
            $productId = (int) $request->get_param('id');
            $userId    = (int) get_current_user_id();
            $idemKey   = $this->requireHeader($request, 'idempotency_key', 'Idempotency-Key');
            $version   = $this->ifMatch($request);
            $patch     = $this->validatePatch($request->get_json_params());

            return $this->idempotent($userId, 'PATCH media ' . $productId, $idemKey, [$patch, $version], function () use ($productId, $patch, $version, $userId): array {
                $result = $this->library()->apply($productId, $patch, $version, $userId);
                $new    = $result['library']['version'];
                if ($result['changed']) {
                    /**
                     * Fires after the media library of a product changed and committed.
                     *
                     * @param int $productId
                     * @param int $userId
                     */
                    do_action('wpe_product_media_changed', $productId, $userId);
                }
                return [200, ['id' => (string) $productId, 'version' => $new, 'parent_version' => $new]];
            });
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function unlinkPreview(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $this->rateLimit('write', self::RATE_WRITE_PER_MINUTE);
            $body = $request->get_json_params();
            $ids  = is_array($body) ? ($body['asset_ids'] ?? null) : null;
            if (!is_array($ids) || $ids === [] || count($body) !== 1) {
                throw new UploadRejectedException('invalid_request', 'asset_ids must be a non-empty list.', 400, false, [['pointer' => '/asset_ids', 'reason' => 'required']]);
            }
            $clean = [];
            foreach (array_values($ids) as $i => $id) {
                if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/', $id)) {
                    throw new UploadRejectedException('invalid_request', 'Asset ids are invalid.', 400, false, [['pointer' => "/asset_ids/{$i}", 'reason' => 'invalid']]);
                }
                $clean[] = (int) $id;
            }
            return $this->json($this->library()->previewUnlink((int) $request->get_param('id'), $clean), 200);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function claimSession(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $this->rateLimit('write', self::RATE_WRITE_PER_MINUTE);
            $userId  = (int) get_current_user_id();
            $session = (string) $request->get_param('session');
            $idemKey = $this->requireHeader($request, 'idempotency_key', 'Idempotency-Key');
            $body    = $request->get_json_params();
            $raw     = is_array($body) && count($body) === 1 ? ($body['product_id'] ?? null) : null;
            if (!is_string($raw) || !preg_match('/^[1-9][0-9]*$/', $raw)) {
                throw new UploadRejectedException('invalid_request', 'product_id is required.', 400, false, [['pointer' => '/product_id', 'reason' => 'required']]);
            }
            $productId = (int) $raw;
            if (get_post_type($productId) !== 'product') {
                throw new UploadRejectedException('not_found', 'The product does not exist.', 404);
            }
            if (!current_user_can('edit_post', $productId)) {
                throw new UploadRejectedException('forbidden', 'You are not allowed to edit this product.', 403);
            }

            return $this->idempotent($userId, 'POST claim ' . $session, $idemKey, [$productId], function () use ($session, $userId, $productId): array {
                $claimed = $this->uploads()->claimSession($session, $userId, $productId);
                return [200, ['claimed' => $claimed]];
            });
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* URL mode                                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * Register an external CDN image and record it as an upload intent so it counts as pending for the product
     * (or the session) until the user links it. The Idempotency-Key header is the upload key.
     *
     * @return array<string,mixed>
     */
    private function addExternal(WP_REST_Request $request): array
    {
        $userId    = (int) get_current_user_id();
        $uploadKey = $this->requireHeader($request, 'idempotency_key', 'Idempotency-Key');
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uploadKey)) {
            throw new UploadRejectedException('invalid_request', 'The Idempotency-Key must be 8 to 64 letters, digits, dashes or underscores.', 400, false, [['pointer' => '/Idempotency-Key', 'reason' => 'invalid']]);
        }
        $body = $request->get_json_params();
        $allowed = ['url', 'alt_text', 'title', 'width', 'height', 'provider', 'renditions'];
        if (!is_array($body) || !isset($body['url']) || !is_string($body['url']) || array_diff(array_keys($body), $allowed) !== []) {
            throw new UploadRejectedException('invalid_request', 'Send a file, or a JSON body with a url.', 400, false, [['pointer' => '/url', 'reason' => 'required']]);
        }

        $productId = (int) $request->get_param('product_id');
        $sessionId = (string) $request->get_param('session_id');
        if ($productId > 0) {
            if (get_post_type($productId) !== 'product' || !current_user_can('edit_post', $productId)) {
                throw new UploadRejectedException('forbidden', 'You are not allowed to edit this product.', 403);
            }
            $sessionId = 'product-' . $productId;
        } elseif (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $sessionId)) {
            throw new UploadRejectedException('invalid_request', 'A product or an upload session is required.', 400);
        }
        if (!UploadItemRepository::ensureSchema()) {
            throw new UploadRejectedException('storage_unavailable', 'The media tables are not available.', 503, true);
        }

        $item = $this->uploads()->begin($userId, $uploadKey, $sessionId, $productId > 0 ? $productId : null, 'external-url');
        if ($item['state'] === UploadItemRepository::STATE_REGISTERED && (int) $item['asset_id'] > 0) {
            return $this->describeAsset((int) $item['asset_id'], true);
        }

        $options = [];
        foreach (['alt_text', 'title', 'provider'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                $options[$key] = sanitize_text_field($body[$key]);
            }
        }
        foreach (['width', 'height'] as $key) {
            if (isset($body[$key])) {
                $options[$key] = max(1, (int) $body[$key]);
            }
        }
        if (isset($body['renditions']) && is_array($body['renditions'])) {
            $options['renditions'] = $body['renditions'];
        }

        try {
            $asset = $this->assets()->registerAsset($body['url'], $options);
        } catch (InvalidArgumentException $e) {
            throw new UploadRejectedException('invalid_url', $e->getMessage(), 422, false, [['pointer' => '/url', 'reason' => 'invalid_url']]);
        }
        $this->uploads()->markRegistered((int) $item['id'], (int) $asset['id']);

        return $this->describeAsset((int) $asset['id'], false);
    }

    /** @return array<string,mixed> */
    private function describeAsset(int $assetId, bool $reused): array
    {
        $asset   = $this->media()->getAssetById($assetId);
        $storage = $this->media()->getAssetStorage($assetId);
        if ($asset === null) {
            throw new \RuntimeException("Asset {$assetId} is missing.");
        }
        return [
            'asset_id'         => (string) $assetId,
            'origin'           => $storage !== null ? 'managed' : 'external',
            'url'              => (string) $asset['source_url'],
            'file_name'        => $storage['file_name'] ?? null,
            'mime'             => $asset['mime'] ?? null,
            'size_bytes'       => $storage !== null ? (int) $storage['size_bytes'] : null,
            'width'            => $asset['width'] !== null ? (int) $asset['width'] : null,
            'height'           => $asset['height'] !== null ? (int) $asset['height'] : null,
            'alt_text'         => (string) ($asset['alt_text'] ?? ''),
            'storage_provider' => $storage['storage_provider'] ?? null,
            'version'          => (string) ($asset['version'] ?? '1'),
            'reused'           => $reused,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Validation, headers, idempotency, rate limit                            */
    /* ---------------------------------------------------------------------- */

    /**
     * @param mixed $body
     * @return array<string,mixed>
     */
    private function validatePatch($body): array
    {
        if (!is_array($body)) {
            throw new UploadRejectedException('invalid_request', 'The body must be a JSON object.', 400);
        }
        $allowed = ['featured_asset_id', 'gallery', 'variant_media', 'collection_mode', 'alt_overrides', 'unlink', 'pending_upload_keys'];
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new UploadRejectedException('invalid_request', "Unknown field {$key}.", 400, false, [['pointer' => '/' . $key, 'reason' => 'unknown_field']]);
            }
        }
        if (!isset($body['collection_mode']) || !in_array($body['collection_mode'], ['merge', 'replace'], true)) {
            throw new UploadRejectedException('invalid_request', 'collection_mode must be merge or replace.', 400, false, [['pointer' => '/collection_mode', 'reason' => 'required']]);
        }
        $isId = static fn ($v): bool => is_string($v) && preg_match('/^[1-9][0-9]*$/', $v) === 1;
        $bad  = static function (string $pointer): UploadRejectedException {
            return new UploadRejectedException('invalid_request', 'The media patch is invalid.', 400, false, [['pointer' => $pointer, 'reason' => 'invalid']]);
        };

        if (array_key_exists('featured_asset_id', $body) && $body['featured_asset_id'] !== null && !$isId($body['featured_asset_id'])) {
            throw $bad('/featured_asset_id');
        }
        foreach (['gallery', 'variant_media', 'alt_overrides', 'unlink', 'pending_upload_keys'] as $key) {
            if (array_key_exists($key, $body) && (!is_array($body[$key]) || !array_is_list($body[$key]))) {
                throw $bad('/' . $key);
            }
        }
        foreach ((array) ($body['gallery'] ?? []) as $i => $e) {
            if (!is_array($e) || !$isId($e['asset_id'] ?? null) || !isset($e['position']) || !is_int($e['position'])
                || array_diff(array_keys($e), ['asset_id', 'position', 'alt_override']) !== []
                || (array_key_exists('alt_override', $e) && $e['alt_override'] !== null && !is_string($e['alt_override']))) {
                throw $bad("/gallery/{$i}");
            }
        }
        foreach ((array) ($body['variant_media'] ?? []) as $i => $e) {
            if (!is_array($e) || !$isId($e['variant_id'] ?? null) || !is_array($e['assets'] ?? null)
                || array_diff(array_keys($e), ['variant_id', 'assets', 'primary_asset_id']) !== []
                || (isset($e['primary_asset_id']) && !$isId($e['primary_asset_id']))) {
                throw $bad("/variant_media/{$i}");
            }
            foreach ($e['assets'] as $k => $a) {
                if (!$isId($a)) {
                    throw $bad("/variant_media/{$i}/assets/{$k}");
                }
            }
        }
        foreach ((array) ($body['alt_overrides'] ?? []) as $i => $e) {
            if (!is_array($e) || !$isId($e['asset_id'] ?? null) || array_diff(array_keys($e), ['asset_id', 'alt_override']) !== []
                || (array_key_exists('alt_override', $e) && $e['alt_override'] !== null && !is_string($e['alt_override']))) {
                throw $bad("/alt_overrides/{$i}");
            }
        }
        foreach ((array) ($body['unlink'] ?? []) as $i => $e) {
            if (!is_array($e) || !$isId($e['asset_id'] ?? null) || array_diff(array_keys($e), ['asset_id', 'replacement_asset_id', 'fallback']) !== []
                || (isset($e['replacement_asset_id']) && !$isId($e['replacement_asset_id']))
                || (isset($e['fallback']) && !is_bool($e['fallback']))) {
                throw $bad("/unlink/{$i}");
            }
        }
        foreach ((array) ($body['pending_upload_keys'] ?? []) as $i => $k) {
            if (!is_string($k)) {
                throw $bad("/pending_upload_keys/{$i}");
            }
        }
        return $body;
    }

    private function requireHeader(WP_REST_Request $request, string $name, string $label): string
    {
        $value = trim((string) $request->get_header($name));
        if ($value === '') {
            throw new UploadRejectedException('precondition_required', "The {$label} header is required.", 428, false, [['pointer' => '/' . $label, 'reason' => 'required']]);
        }
        return $value;
    }

    private function ifMatch(WP_REST_Request $request): string
    {
        $raw = $this->requireHeader($request, 'if_match', 'If-Match');
        return trim(preg_replace('/^W\//', '', $raw), " \"");
    }

    /**
     * Replay the stored answer for the same user, route, key and body; refuse the same key with another body.
     *
     * @param callable():array{0:int,1:array} $run
     */
    private function idempotent(int $userId, string $route, string $key, array $fingerprint, callable $run): WP_REST_Response
    {
        $name = 'wpe_idem_' . substr(hash('sha256', $userId . '|' . $route . '|' . $key), 0, 40);
        $hash = hash('sha256', (string) wp_json_encode($fingerprint));
        $seen = get_transient($name);
        if (is_array($seen)) {
            if (!hash_equals((string) $seen['hash'], $hash)) {
                throw new UploadRejectedException('idempotency_conflict', 'This Idempotency-Key was already used with another request.', 409);
            }
            $replay = $this->json($seen['body'], (int) $seen['status']);
            $replay->header('Idempotent-Replay', 'true');
            return $replay;
        }

        [$status, $body] = $run();
        set_transient($name, ['hash' => $hash, 'status' => $status, 'body' => $body], self::IDEMPOTENCY_TTL);
        return $this->json($body, $status);
    }

    private function rateLimit(string $bucket, int $perMinute): void
    {
        $userId = (int) get_current_user_id();
        $slot   = (int) floor(time() / 60);
        $name   = "wpe_rl_{$bucket}_{$userId}_{$slot}";
        $count  = (int) get_transient($name) + 1;
        set_transient($name, $count, 90);
        if ($count > $perMinute) {
            throw new UploadRejectedException('rate_limited', 'Too many requests. Retry shortly.', 429, true);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Responses                                                               */
    /* ---------------------------------------------------------------------- */

    /** @param array<mixed> $data */
    private function json(array $data, int $status): WP_REST_Response
    {
        return new WP_REST_Response($data, $status);
    }

    private function errorResponse(Throwable $e): WP_REST_Response
    {
        $retryable = false;
        $details   = [];
        $reason    = 'error';
        if ($e instanceof UploadRejectedException) {
            $status    = $e->httpStatus();
            $reason    = $e->reason();
            $retryable = $e->retryable();
            $message   = $e->getMessage();
            foreach ($e->details() as $d) {
                $pointer = (string) ($d['pointer'] ?? '');
                if (isset($d['asset_id'], $d['place'])) {
                    $pointer = '/assets/' . $d['asset_id'] . '/' . $d['place'] . (isset($d['variant_id']) ? '/' . $d['variant_id'] : '');
                } elseif (isset($d['asset_id']) && $pointer === '') {
                    $pointer = '/assets/' . $d['asset_id'];
                }
                $details[] = ['pointer' => $pointer, 'reason' => (string) ($d['reason'] ?? $reason)];
            }
        } elseif ($e instanceof StorageException) {
            $status    = 503;
            $reason    = $e->reason();
            $retryable = true;
            $message   = $e->getMessage();
        } else {
            $status  = 500;
            $message = 'The request could not be completed.';
            // The cause stays in the server log; it may contain paths or SQL.
            error_log('[wpe] media api: ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($details === []) {
            $details[] = ['pointer' => '', 'reason' => $reason];
        }

        $registered = ['idempotency_conflict', 'precondition_required', 'stale_version', 'rate_limited', 'unauthenticated', 'forbidden', 'not_found'];
        $response   = new WP_REST_Response([
            'code'       => in_array($reason, $registered, true) ? $reason : $this->codeFor($status),
            'message'    => $message,
            'details'    => $details,
            'request_id' => wp_generate_uuid4(),
            'retryable'  => $retryable,
        ], $status);
        if ($status === 429) {
            $response->header('Retry-After', '30');
        }
        return $response;
    }

    private function codeFor(int $status): string
    {
        return match (true) {
            $status === 400 => 'invalid_request',
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 412 => 'stale_version',
            $status === 428 => 'precondition_required',
            $status === 429 => 'rate_limited',
            $status === 503 => 'dependency_unavailable',
            $status >= 500  => 'dependency_unavailable',
            default         => 'validation_failed',
        };
    }

    /* ---------------------------------------------------------------------- */
    /* Lazy dependencies                                                       */
    /* ---------------------------------------------------------------------- */

    private function library(): ProductMediaLibrary
    {
        return $this->library ??= new ProductMediaLibrary();
    }

    private function uploader(): ManagedUploadService
    {
        return $this->uploader ??= new ManagedUploadService();
    }

    private function uploads(): UploadItemRepository
    {
        return $this->uploads ??= new UploadItemRepository();
    }

    private function assets(): AssetService
    {
        return $this->assets ??= new AssetService();
    }

    private function media(): MediaRepository
    {
        return $this->media ??= new MediaRepository($GLOBALS['wpdb']);
    }
}
