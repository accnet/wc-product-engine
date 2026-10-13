<?php

declare(strict_types=1);

namespace WPE\Application\Media;

use RuntimeException;
use WPE\Application\Media\Storage\StorageException;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Application\Media\Storage\StorageSanitizer;
use WPE\Application\Media\Storage\StorageUnavailableException;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

/**
 * Managed image upload through the system default storage (docs/02 §10, docs/04 §7).
 *
 * Order of work: check permission, record the upload intent, validate the file by content, upload to the
 * default storage, verify the object (exists, same size), and only then create the asset and the managed
 * storage row in one database transaction. A failure at any step leaves no available asset. Retrying with the
 * same upload key resumes from the stored state and never uploads or registers twice.
 */
final class ManagedUploadService
{
    public const OPTION_MAX_BYTES = 'wpe_media_max_upload_bytes';
    public const DEFAULT_MAX_BYTES = 10485760;
    public const MAX_SIDE = 20000;
    public const MAX_PIXELS = 100000000;

    /** @var array<string,string[]> sniffed mime => allowed client extensions */
    private const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png'  => ['png'],
        'image/gif'  => ['gif'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
    ];

    private \wpdb $wpdb;
    private StorageResolver $resolver;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private AssetService $assets;
    /** @var callable(string):bool */
    private $uploadedFileCheck;
    private int $lockTimeout;

    public function __construct(
        ?StorageResolver $resolver = null,
        ?MediaRepository $media = null,
        ?UploadItemRepository $uploads = null,
        ?AssetService $assets = null,
        ?callable $uploadedFileCheck = null,
        ?\wpdb $wpdb = null,
        int $lockTimeoutSeconds = 20
    ) {
        $this->lockTimeout       = max(0, $lockTimeoutSeconds);
        $this->wpdb              = $wpdb ?? $GLOBALS['wpdb'];
        $this->resolver          = $resolver ?? new StorageResolver();
        $this->media             = $media ?? new MediaRepository($this->wpdb);
        $this->uploads           = $uploads ?? new UploadItemRepository($this->wpdb);
        $this->assets            = $assets ?? new AssetService($this->wpdb);
        $this->uploadedFileCheck = $uploadedFileCheck ?? 'is_uploaded_file';
    }

    /** @return array{max_bytes:int,mime_types:string[]} */
    public static function limits(): array
    {
        $configured = (int) get_option(self::OPTION_MAX_BYTES, self::DEFAULT_MAX_BYTES);
        $configured = $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;

        return [
            'max_bytes'  => min($configured, (int) wp_max_upload_size()),
            'mime_types' => array_keys(self::TYPES),
        ];
    }

    /**
     * @param array{tmp_name?:string,name?:string,size?:int,error?:int} $file one entry of the PHP upload array
     * @param array{upload_key?:string,session_id?:string,product_id?:int,alt_text?:string} $context
     * @return array<string,mixed> the managed asset as the library shows it
     * @throws UploadRejectedException|StorageException
     */
    public function upload(array $file, array $context): array
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0 || !current_user_can('upload_files')) {
            throw new UploadRejectedException('forbidden', 'You are not allowed to upload files.', 403);
        }
        $productId = isset($context['product_id']) ? (int) $context['product_id'] : 0;
        if ($productId > 0 && !current_user_can('edit_post', $productId)) {
            throw new UploadRejectedException('forbidden', 'You are not allowed to edit this product.', 403);
        }
        $uploadKey = (string) ($context['upload_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uploadKey)) {
            throw new UploadRejectedException('invalid_request', 'A valid upload key is required.', 400);
        }
        $sessionId = (string) ($context['session_id'] ?? '');
        if ($productId <= 0 && !preg_match('/^[A-Za-z0-9-]{8,64}$/', $sessionId)) {
            throw new UploadRejectedException('invalid_request', 'A product or an upload session is required.', 400);
        }
        if ($sessionId === '') {
            $sessionId = 'product-' . $productId;
        }
        if (!UploadItemRepository::ensureSchema($this->wpdb)) {
            throw new StorageUnavailableException('The media tables are not available.', 'storage_unavailable');
        }

        $lockName = 'wpe_upload_' . substr(hash('sha256', $userId . ':' . $uploadKey), 0, 40);
        if ((int) $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, $this->lockTimeout)) !== 1) {
            throw new UploadRejectedException('upload_in_progress', 'This upload is already being processed. Retry shortly.', 409, true);
        }
        try {
            return $this->process($file, $userId, $uploadKey, $sessionId, $productId, (string) ($context['alt_text'] ?? ''));
        } finally {
            $this->wpdb->get_var($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** @return array<string,mixed> */
    private function process(array $file, int $userId, string $uploadKey, string $sessionId, int $productId, string $altText): array
    {
        $clientName = sanitize_file_name((string) ($file['name'] ?? 'image'));
        $item       = $this->uploads->begin($userId, $uploadKey, $sessionId, $productId > 0 ? $productId : null, $clientName);

        if ($item['state'] === UploadItemRepository::STATE_REGISTERED && (int) $item['asset_id'] > 0) {
            return $this->describe((int) $item['asset_id'], true);
        }
        if ($item['state'] === UploadItemRepository::STATE_CLEANED) {
            throw new UploadRejectedException('upload_expired', 'This upload expired. Start a new upload with a new key.', 409);
        }
        if ($item['state'] === UploadItemRepository::STATE_FAILED) {
            $this->uploads->reopen((int) $item['id']);
            $item = $this->uploads->findById((int) $item['id']);
        }
        $itemId = (int) $item['id'];

        try {
            $checked = $this->validateFile($file, $clientName);
        } catch (UploadRejectedException $e) {
            $this->uploads->markFailed($itemId, $e->reason(), $e->getMessage());
            throw $e;
        }

        if ($item['content_hash'] !== null && $item['content_hash'] !== '' && strtolower((string) $item['content_hash']) !== $checked['hash']) {
            throw new UploadRejectedException('upload_key_reused', 'This upload key was already used for a different file.', 409);
        }

        $resumeStored = $item['state'] === UploadItemRepository::STATE_STORED && $item['object_key'] !== null;
        if ($resumeStored) {
            $stored = $this->verifyStoredObject($item, $checked['size']);
            if ($stored === null) {
                $resumeStored = false;
            }
        }

        if (!$resumeStored) {
            $stored = $this->storeObject($item, $checked, $itemId);
        }

        return $this->register($itemId, $stored, $checked, $clientName, $userId, $altText);
    }

    /**
     * @return array{tmp:string,size:int,hash:string,mime:string,ext:string,width:int,height:int}
     */
    private function validateFile(array $file, string $clientName): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadRejectedException('file_too_large', 'The file is larger than the server accepts.', 422);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadRejectedException('invalid_upload', 'The file did not upload completely. Retry.', 422, true);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp) || !(bool) ($this->uploadedFileCheck)($tmp)) {
            throw new UploadRejectedException('invalid_upload', 'The uploaded file is not available.', 422);
        }

        $size = (int) filesize($tmp);
        $max  = self::limits()['max_bytes'];
        if ($size <= 0) {
            throw new UploadRejectedException('invalid_upload', 'The file is empty.', 422);
        }
        if ($size > $max) {
            throw new UploadRejectedException('file_too_large', sprintf('The file is %s; the limit is %s.', size_format($size), size_format($max)), 422);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string) finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!isset(self::TYPES[$mime]) || !UrlValidator::isAllowedImageMime($mime)) {
            throw new UploadRejectedException('file_type_not_allowed', 'Only JPEG, PNG, GIF, WebP and AVIF images are accepted.', 422);
        }
        $ext = strtolower((string) pathinfo($clientName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::TYPES[$mime], true)) {
            throw new UploadRejectedException('file_type_not_allowed', 'The file extension does not match the file content.', 422);
        }

        $info = @getimagesize($tmp);
        if (!is_array($info) || ($info['mime'] ?? '') !== $mime || (int) $info[0] < 1 || (int) $info[1] < 1) {
            throw new UploadRejectedException('invalid_image', 'The file is not a readable image.', 422);
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new UploadRejectedException('invalid_image', 'The image dimensions are too large.', 422);
        }

        return [
            'tmp'    => $tmp,
            'size'   => $size,
            'hash'   => hash_file('sha256', $tmp),
            'mime'   => $mime,
            'ext'    => self::TYPES[$mime][0],
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Upload to the default storage and verify. Records the object as stored only after verification.
     *
     * @return array{provider:string,bucket:string,key:string,object:\WPE\Application\Media\Storage\StorageProviderInterface}
     */
    private function storeObject(array $item, array $checked, int $itemId): array
    {
        try {
            $provider = $this->resolver->default();
            $key      = $provider->objectKey($this->relativePath($item, $checked));
            $provider->put($checked['tmp'], $key, $checked['mime']);
            if (!$provider->verify($key, $checked['size'])) {
                $provider->delete($key);
                throw new StorageException('The storage could not confirm the uploaded file.', 'verification_failed');
            }
        } catch (StorageException $e) {
            $this->uploads->markFailed($itemId, $e->reason(), StorageSanitizer::message($e->getMessage()));
            throw $e;
        }

        $this->uploads->markStored($itemId, [
            'mime'             => $checked['mime'],
            'size_bytes'       => $checked['size'],
            'content_hash'     => $checked['hash'],
            'storage_provider' => $provider->name(),
            'bucket'           => $provider->bucket(),
            'object_key'       => $key,
        ]);

        return ['provider' => $provider->name(), 'bucket' => $provider->bucket(), 'key' => $key, 'object' => $provider];
    }

    /**
     * An earlier attempt stored the object but did not register the asset. Reuse it when the storage that
     * holds it can still confirm it; otherwise the caller uploads again.
     *
     * @return array{provider:string,bucket:string,key:string,object:\WPE\Application\Media\Storage\StorageProviderInterface}|null
     */
    private function verifyStoredObject(array $item, int $size): ?array
    {
        try {
            $provider = $this->resolver->forStored((string) $item['storage_provider'], (string) $item['bucket']);
        } catch (StorageException $e) {
            return null;
        }
        if (!$provider->verify((string) $item['object_key'], $size)) {
            return null;
        }
        return ['provider' => (string) $item['storage_provider'], 'bucket' => (string) $item['bucket'], 'key' => (string) $item['object_key'], 'object' => $provider];
    }

    /**
     * Create the asset and the managed storage row in one transaction. When it fails the intent stays stored,
     * so a retry registers without uploading again.
     *
     * @return array<string,mixed>
     */
    private function register(int $itemId, array $stored, array $checked, string $clientName, int $userId, string $altText): array
    {
        $url = $stored['object']->urlFor($stored['key']);

        $this->wpdb->query('START TRANSACTION');
        try {
            $asset = $this->assets->getAssetByUrl($url);
            if ($asset === null) {
                $renditions = [];
                $cdn        = $this->assets->getProvider('default');
                foreach (['thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_single', 'large', 'full'] as $size) {
                    $renditions[] = $cdn->getRendition($url, $size, $checked['width'], $checked['height']);
                }
                $title = trim((string) pathinfo($clientName, PATHINFO_FILENAME));
                $asset = $this->media->createAsset([
                    'kind'            => 'image',
                    'source_url'      => $url,
                    'provider'        => 'default',
                    'provider_key'    => $stored['key'],
                    'mime'            => $checked['mime'],
                    'width'           => $checked['width'],
                    'height'          => $checked['height'],
                    'alt_text'        => sanitize_text_field($altText),
                    'title'           => $title,
                    'delivery_policy' => ['export_strategy' => 'stable'],
                    'renditions'      => $renditions,
                    'health'          => 'healthy',
                ]);
            }
            $this->media->createAssetStorage((int) $asset['id'], [
                'storage_provider' => $stored['provider'],
                'bucket'           => $stored['bucket'],
                'object_key'       => $stored['key'],
                'file_name'        => $clientName,
                'size_bytes'       => $checked['size'],
                'content_hash'     => $checked['hash'],
                'created_by'       => $userId,
            ]);
            $this->uploads->markRegistered($itemId, (int) $asset['id']);
            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            $this->uploads->noteError($itemId, 'database_error', StorageSanitizer::message($e->getMessage()));
            throw new StorageException('The file is stored but could not be recorded. Retry to finish.', 'registration_failed');
        }

        return $this->describe((int) $asset['id'], false);
    }

    private function relativePath(array $item, array $checked): string
    {
        $created = strtotime((string) $item['created_at'] . ' UTC') ?: time();
        $base    = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) pathinfo((string) $item['file_name'], PATHINFO_FILENAME)));
        $base    = trim(substr($base, 0, 60), '-');
        $base    = $base !== '' ? $base : 'image';

        return sprintf('wpe-products/%s/%s-%s.%s', gmdate('Y/m', $created), substr($checked['hash'], 0, 16), $base, $checked['ext']);
    }

    /** @return array<string,mixed> */
    private function describe(int $assetId, bool $reused): array
    {
        $asset   = $this->media->getAssetById($assetId);
        $storage = $this->media->getAssetStorage($assetId);
        if ($asset === null) {
            throw new RuntimeException("Asset {$assetId} disappeared after upload.");
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
}
