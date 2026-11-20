<?php

/**
 * P011.12 — Gated live storage round trip: real upload, verify, read back, delete test object.
 *
 * This is the only P011 test that talks to real object storage. It reads the live default storage
 * settings at run time (never logging or embedding credentials), uploads a small generated PNG
 * through ManagedUploadService + StorageResolver, checks remote existence and byte size, fetches
 * the object back through its stored URL and verifies byte-for-byte SHA-256 parity, then deletes
 * the remote test object and cleans up all test rows.
 *
 * Per docs/plans/P011-product-media-library/plan.md, this test fails (does not skip and does not
 * fall back to local storage) when the sibling object storage plugin (`images-space`) is not
 * loadable, not enabled, or not configured.
 */

if (!defined('ABSPATH')) {
    $wpLoad = rtrim((string) (getenv('WPE_WP_PATH') ?: '/var/www/site1.localhost'), '/') . '/wp-load.php';
    if (file_exists($wpLoad)) {
        require_once $wpLoad;
    } else {
        fwrite(STDERR, "FAILED: WordPress environment not found at {$wpLoad}\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/StorageLiveRoundTripTest.php\n";
    exit(1);
}

use WPE\Application\Media\ManagedUploadService;
use WPE\Application\Media\OrphanCleanup;
use WPE\Application\Media\Storage\StoragePluginProvider;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Application\Media\Storage\StorageSanitizer;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

final class StorageLiveRoundTripTest
{
    private \wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private StorageResolver $resolver;
    private ManagedUploadService $uploader;
    private OrphanCleanup $cleanupService;

    private int $checks = 0;
    private ?string $tmpFile = null;
    private ?int $productId = null;
    private ?int $assetId = null;
    private ?string $uploadKey = null;
    private ?string $objectKey = null;
    private ?StoragePluginProvider $liveProvider = null;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb           = $wpdb;
        $this->media          = new MediaRepository($wpdb);
        $this->uploads        = new UploadItemRepository($wpdb);
        $this->resolver       = new StorageResolver();
        $this->uploader       = new ManagedUploadService($this->resolver, $this->media, $this->uploads, null, static fn (): bool => true, $wpdb);
        $this->cleanupService = new OrphanCleanup($this->media, $this->uploads, $this->resolver, $wpdb);
    }

    public function run(): void
    {
        echo "Running P011.12 gated live storage round-trip test...\n";

        // 1. Fail-closed gate: storage plugin must be loadable, enabled as default, and configured.
        if (!StoragePluginProvider::pluginLoaded()) {
            throw new RuntimeException(
                'Object storage plugin is not installed or loaded (' . StoragePluginProvider::SETTINGS_CLASS . ' / ' . StoragePluginProvider::FACTORY_CLASS . ' missing). Live round trip cannot be skipped.'
            );
        }
        $this->ok(true, 'object storage plugin classes are loaded');

        $rawSettings = get_option(StorageResolver::SETTINGS_OPTION, null);
        if (!is_array($rawSettings) || empty($rawSettings['enabled'])) {
            throw new RuntimeException(
                'Option ' . StorageResolver::SETTINGS_OPTION . ' is missing or object storage is not enabled as the default storage. Live round trip cannot fall back to local storage.'
            );
        }
        $this->ok($this->resolver->defaultIsObjectStorage(), 'default storage is object storage');

        $provider = $this->resolver->default();
        if (!$provider instanceof StoragePluginProvider) {
            throw new RuntimeException('Resolved default storage is not StoragePluginProvider (got ' . get_class($provider) . ').');
        }
        if (!$provider->isAvailable()) {
            $reason = StorageSanitizer::message((string) ($provider->unavailableReason() ?? 'not configured'));
            throw new RuntimeException('Object storage provider is not configured/available: ' . $reason);
        }
        $this->liveProvider = $provider;
        $this->ok($provider->name() !== '' && $provider->bucket() !== '', 'object storage provider and bucket configured (' . $provider->name() . ')');

        wp_set_current_user(1);

        try {
            $this->executeRoundTrip($provider);
            echo "All P011.12 live storage round-trip checks passed ({$this->checks} checks).\n";
        } finally {
            $this->cleanup();
        }
    }

    private function executeRoundTrip(StoragePluginProvider $provider): void
    {
        // 2. Generate a small unique valid PNG file.
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            throw new RuntimeException('PHP GD PNG support is required to generate the round-trip test image.');
        }

        $im = imagecreatetruecolor(16, 16);
        if ($im === false) {
            throw new RuntimeException('Failed to create test image resource.');
        }
        $seed = random_int(1, 254);
        imagefill($im, 0, 0, imagecolorallocate($im, $seed, 110, 190));
        imagesetpixel($im, 3, 5, imagecolorallocate($im, 255 - $seed, 40, 20));
        $tmpPath = tempnam(sys_get_temp_dir(), 'wpe-p011-live-');
        if ($tmpPath === false || !imagepng($im, $tmpPath)) {
            imagedestroy($im);
            throw new RuntimeException('Failed to write temporary PNG test image.');
        }
        imagedestroy($im);
        $this->tmpFile = $tmpPath;

        $expectedBytes = (string) file_get_contents($tmpPath);
        $expectedSize  = strlen($expectedBytes);
        $expectedHash  = hash('sha256', $expectedBytes);
        $this->ok($expectedSize > 0, "generated test PNG ({$expectedSize} bytes)");

        // 3. Create a disposable draft product to scope the upload.
        $productId = wp_insert_post([
            'post_type'   => 'product',
            'post_status' => 'draft',
            'post_title'  => 'P011 Live RoundTrip ' . bin2hex(random_bytes(4)),
            'post_author' => 1,
        ]);
        if (is_wp_error($productId) || (int) $productId <= 0) {
            throw new RuntimeException('Failed to create disposable product for live storage round trip.');
        }
        $this->productId = (int) $productId;

        $this->uploadKey = 'p011-live-' . bin2hex(random_bytes(8));

        // 4. Upload through ManagedUploadService using the real StorageResolver.
        $uploaded = $this->uploader->upload(
            [
                'tmp_name' => $tmpPath,
                'name'     => 'wpe-p011-roundtrip.png',
                'size'     => $expectedSize,
                'error'    => UPLOAD_ERR_OK,
            ],
            [
                'upload_key' => $this->uploadKey,
                'product_id' => $this->productId,
                'alt_text'   => 'P011 live round-trip test',
            ]
        );

        $this->assetId = (int) ($uploaded['asset_id'] ?? 0);
        $this->ok($this->assetId > 0, 'upload registered a managed asset id');
        $this->ok(($uploaded['origin'] ?? '') === 'managed', 'asset origin is managed');
        $this->ok(($uploaded['storage_provider'] ?? '') === $provider->name(), 'asset recorded live storage provider');
        $this->ok((int) ($uploaded['size_bytes'] ?? 0) === $expectedSize, 'asset recorded exact byte size');

        $storage = $this->media->getAssetStorage($this->assetId);
        $this->ok($storage !== null && ($storage['state'] ?? '') === 'active', 'asset_storage row is active');
        $this->ok(($storage['bucket'] ?? '') === $provider->bucket(), 'asset_storage recorded configured bucket');
        $this->ok(strtolower((string) ($storage['content_hash'] ?? '')) === $expectedHash, 'asset_storage recorded exact SHA-256 hash');

        $this->objectKey = (string) ($storage['object_key'] ?? '');
        $this->ok($this->objectKey !== '', 'asset_storage recorded non-empty object key');

        // 5. Verify remote object exists with the expected byte size directly through the provider.
        $this->ok($provider->verify($this->objectKey, $expectedSize), 'remote storage verifies object existence and exact byte size');

        // 6. Read the object back through the stored public URL.
        $storedUrl = (string) ($uploaded['url'] ?? '');
        $this->ok($storedUrl !== '' && str_starts_with($storedUrl, 'https://'), 'stored URL is HTTPS');

        $response = wp_remote_get($storedUrl, [
            'timeout'     => 15,
            'redirection' => 3,
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException('HTTP GET of stored URL failed: ' . StorageSanitizer::message($response->get_error_message()));
        }
        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body       = (string) wp_remote_retrieve_body($response);
        $this->ok($statusCode === 200, "stored URL responded HTTP 200 (got {$statusCode})");
        $this->ok(strlen($body) === $expectedSize, 'downloaded payload size matches uploaded file');
        $this->ok(hash('sha256', $body) === $expectedHash, 'downloaded payload SHA-256 matches uploaded file');

        // 7. Release upload retention hold and delete the remote object through OrphanCleanup::deleteFile().
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->prefix}wpe_upload_items SET expires_at = %s WHERE upload_key = %s",
            gmdate('Y-m-d H:i:s.u', time() - 60),
            $this->uploadKey
        ));

        $deleted = $this->cleanupService->deleteFile($this->assetId);
        $this->ok(!empty($deleted['deleted']) && ($deleted['storage_provider'] ?? '') === $provider->name(), 'OrphanCleanup::deleteFile deleted remote object');
        $this->ok(!$provider->verify($this->objectKey, $expectedSize), 'remote storage confirms deleted object no longer exists');

        $afterStorage = $this->media->getAssetStorage($this->assetId);
        $this->ok($afterStorage !== null && ($afterStorage['state'] ?? '') === 'deleted', 'asset_storage row marked deleted');
    }

    private function cleanup(): void
    {
        if ($this->liveProvider !== null && $this->objectKey !== null && $this->objectKey !== '') {
            try {
                $this->liveProvider->delete($this->objectKey);
            } catch (\Throwable $e) {
                // Best-effort cleanup of remote test object.
            }
        }

        if ($this->uploadKey !== null) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_upload_items', ['upload_key' => $this->uploadKey], ['%s']);
        }

        if ($this->assetId !== null && $this->assetId > 0) {
            $asset = $this->media->getAssetById($this->assetId);
            if ($asset !== null && !empty($asset['attachment_id'])) {
                wp_delete_post((int) $asset['attachment_id'], true);
            }
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_product_media', ['asset_id' => $this->assetId], ['%d']);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_variant_media', ['asset_id' => $this->assetId], ['%d']);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_asset_storage', ['asset_id' => $this->assetId], ['%d']);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_assets', ['id' => $this->assetId], ['%d']);
        }

        if ($this->productId !== null && $this->productId > 0) {
            wp_delete_post($this->productId, true);
        }

        if ($this->tmpFile !== null && file_exists($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    private function ok(bool $cond, string $label): void
    {
        $this->checks++;
        if (!$cond) {
            throw new RuntimeException("FAILED: {$label}");
        }
        echo "  [OK] {$label}\n";
    }
}

try {
    (new StorageLiveRoundTripTest())->run();
    exit(0);
} catch (\Throwable $e) {
    echo 'FAILED: ' . StorageSanitizer::message($e->getMessage()) . "\n";
    exit(1);
}
