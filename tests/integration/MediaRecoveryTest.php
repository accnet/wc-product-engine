<?php

/**
 * P011.11 — Media recovery, orphan cleanup, and explicit file deletion.
 *
 * Verifies OrphanCleanup::blockingReferences(), deleteFile(), cleanStaleUploads(), report(),
 * and the DELETE /wpe/v1/admin/assets/{id}/file REST route.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';
require_once __DIR__ . '/support/FakeStorageProvider.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductMediaApiController;
use WPE\Application\Media\ManagedUploadService;
use WPE\Application\Media\OrphanCleanup;
use WPE\Application\Media\ProductMediaLibrary;
use WPE\Application\Media\Storage\LocalStorageProvider;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Application\Media\UploadRejectedException;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

class MediaRecoveryTest
{
    private wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private FakeStorageProvider $fake;
    private StorageResolver $resolver;
    private ManagedUploadService $uploader;
    private ProductMediaLibrary $library;
    private OrphanCleanup $cleanupService;
    private ProductMediaApiController $api;
    private array $tmp = [];
    private array $posts = [];
    private array $assetIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb           = $wpdb;
        $this->media          = new MediaRepository($wpdb);
        $this->uploads        = new UploadItemRepository($wpdb);
        $this->fake           = new FakeStorageProvider();
        $local                = new LocalStorageProvider($this->tmpDir(), 'https://uploads.example.test');
        $this->resolver       = new StorageResolver(fn () => $this->fake, static fn () => true, $local);
        $this->uploader       = new ManagedUploadService($this->resolver, $this->media, $this->uploads, null, static fn () => true, $wpdb, 1);
        $this->library        = new ProductMediaLibrary($this->media, $this->uploads, $wpdb);
        $this->cleanupService = new OrphanCleanup($this->media, $this->uploads, $this->resolver, $wpdb);
        $this->api            = new ProductMediaApiController($this->library, $this->uploader, $this->uploads, null, $this->media, $this->cleanupService);
    }

    public function run(): void
    {
        echo "Running media recovery and orphan cleanup tests...\n";
        wp_set_current_user(1);
        try {
            $this->testBlockingReferencesAndDeleteFile();
            $this->testCleanStaleUploads();
            $this->testReport();
            $this->testDeleteRestEndpoint();
            echo "All media recovery tests passed ({$this->checks} checks).\n";
        } finally {
            $this->cleanup();
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

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (UploadRejectedException $e) {
            return $e->reason();
        }
        return null;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/wpe-mrec-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->tmp[] = $dir;
        return $dir;
    }

    private function product(): int
    {
        $id = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'P011 recovery test', 'post_author' => 1]);
        $this->posts[] = (int) $id;
        return (int) $id;
    }

    private function uploadManaged(int $productId, string $label): int
    {
        static $n = 0;
        $n++;
        $im = imagecreatetruecolor(16, 16);
        imagefill($im, 0, 0, imagecolorallocate($im, ($n * 23) % 250, ($n * 41) % 250, 120));
        imagesetpixel($im, 4, 4, imagecolorallocate($im, ($n * 53) % 250, 12, 12));
        $path = tempnam(sys_get_temp_dir(), 'wpe-mrec-img-');
        imagepng($im, $path);
        imagedestroy($im);
        $this->tmp[] = $path;
        $res = $this->uploader->upload(
            ['tmp_name' => $path, 'name' => $label . '.png', 'size' => filesize($path), 'error' => UPLOAD_ERR_OK],
            ['upload_key' => 'p011t-rec-' . $n . '-' . bin2hex(random_bytes(3)), 'product_id' => $productId, 'alt_text' => $label]
        );
        $assetId = (int) $res['asset_id'];
        $this->assetIds[] = $assetId;
        return $assetId;
    }

    private function testBlockingReferencesAndDeleteFile(): void
    {
        echo "[recovery] blocking references and deleteFile\n";
        $pid = $this->product();
        $a   = $this->uploadManaged($pid, 'managed-a');

        // 1. While upload intent is unexpired, it blocks deletion
        $refs = $this->cleanupService->blockingReferences($a);
        $this->ok(count($refs) === 1 && $refs[0]['place'] === 'retention_hold', 'active upload retention hold blocks deletion');
        $this->ok($this->reason(fn () => $this->cleanupService->deleteFile($a)) === 'asset_in_use', 'deleteFile refuses asset with active upload intent');

        // 2. Link into product library -> product_media blocks deletion
        $lib = $this->library->read($pid, 1);
        $this->library->apply($pid, ['collection_mode' => 'merge', 'featured_asset_id' => $a], $lib['version'], 1);
        $this->ok($this->reason(fn () => $this->cleanupService->deleteFile($a)) === 'asset_in_use', 'deleteFile refuses asset linked in product_media');

        // 3. Unlink from product library (which retires upload intent too)
        $lib = $this->library->read($pid, 1);
        $this->library->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $a, 'fallback' => true]]], $lib['version'], 1);
        $this->ok($this->cleanupService->blockingReferences($a) === [], 'unlinked asset has no blocking references');

        // 4. Delete unreferenced managed asset file
        $storageBefore = $this->media->getAssetStorage($a);
        $this->ok($storageBefore !== null && isset($this->fake->objects[$storageBefore['object_key']]), 'storage object exists before deleteFile');
        $del = $this->cleanupService->deleteFile($a);
        $this->ok($del['deleted'] === true && $del['asset_id'] === (string) $a, 'deleteFile succeeds on unreferenced managed asset');
        $this->ok(!isset($this->fake->objects[$storageBefore['object_key']]), 'object removed from storage provider');
        $storageAfter = $this->media->getAssetStorage($a);
        $this->ok(($storageAfter['state'] ?? '') === 'deleted', 'wpe_asset_storage state marked deleted');

        // 5. External asset is refused with asset_external
        $ext = $this->media->createAsset(['source_url' => 'https://cdn.example.test/ext-' . bin2hex(random_bytes(3)) . '.jpg', 'mime' => 'image/jpeg']);
        $extId = (int) $ext['id'];
        $this->assetIds[] = $extId;
        $this->ok($this->reason(fn () => $this->cleanupService->deleteFile($extId)) === 'asset_external', 'external asset cannot be file-deleted');
    }

    private function testCleanStaleUploads(): void
    {
        echo "[recovery] cleanStaleUploads\n";
        $pid = $this->product();

        // Create a stored upload item that never registered an asset
        $objKey = 'wpe-products/2026/10/stale-' . bin2hex(random_bytes(4)) . '.png';
        $this->fake->objects[$objKey] = ['body' => 'fake-image-bytes', 'mime' => 'image/png', 'size' => 16];
        $item = $this->uploads->begin(1, 'p011t-stale-' . bin2hex(random_bytes(3)), 'sess-stale', $pid, 'stale.png');
        $itemId = (int) $item['id'];
        $this->uploads->markStored($itemId, [
            'mime'             => 'image/png',
            'size_bytes'       => 16,
            'content_hash'     => str_repeat('a', 64),
            'storage_provider' => $this->fake->name(),
            'bucket'           => $this->fake->bucket(),
            'object_key'       => $objKey,
        ]);

        // Backdate updated_at so it's older than cutoff
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->uploads->tableName()} SET updated_at = %s WHERE id = %d",
            '2020-01-01 00:00:00.000000',
            $itemId
        ));

        $res = $this->cleanupService->cleanStaleUploads('2025-01-01 00:00:00.000000', 50);
        $this->ok($res['cleaned'] >= 1, 'cleanStaleUploads cleans stale stored upload');
        $this->ok(!isset($this->fake->objects[$objKey]), 'stale upload physical object removed');
        $row = $this->uploads->findById($itemId);
        $this->ok(($row['state'] ?? '') === UploadItemRepository::STATE_CLEANED, 'upload item state marked cleaned');
    }

    private function testReport(): void
    {
        echo "[recovery] report\n";
        $report = $this->cleanupService->report();
        $this->ok(
            isset($report['assets'], $report['storage_by_provider'], $report['upload_items'], $report['stale_stored_count']),
            'report returns diagnostic sections'
        );
    }

    private function testDeleteRestEndpoint(): void
    {
        echo "[recovery] DELETE /wpe/v1/admin/assets/{id}/file\n";
        $pid = $this->product();
        $a   = $this->uploadManaged($pid, 'rest-del');
        $lib = $this->library->read($pid, 1);
        $this->library->apply($pid, ['collection_mode' => 'merge', 'featured_asset_id' => $a], $lib['version'], 1);

        // In-use asset returns 409
        $reqInUse = new WP_REST_Request('DELETE', "/wpe/v1/admin/assets/{$a}/file");
        $reqInUse->set_param('id', (string) $a);
        $reqInUse->set_header('idempotency_key', 'p011t-del-inuse-' . bin2hex(random_bytes(3)));
        $resInUse = $this->api->deleteAssetFile($reqInUse);
        $this->ok($resInUse->get_status() === 409 && $resInUse->get_data()['details'][0]['reason'] === 'asset_in_use', 'REST DELETE returns 409 when asset is in use');

        // Unlink then delete via REST
        $lib = $this->library->read($pid, 1);
        $this->library->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $a, 'fallback' => true]]], $lib['version'], 1);

        $idemKey = 'p011t-del-ok-' . bin2hex(random_bytes(3));
        $reqDel  = new WP_REST_Request('DELETE', "/wpe/v1/admin/assets/{$a}/file");
        $reqDel->set_param('id', (string) $a);
        $reqDel->set_header('idempotency_key', $idemKey);
        $resDel = $this->api->deleteAssetFile($reqDel);
        $this->ok($resDel->get_status() === 200 && ($resDel->get_data()['deleted'] ?? false) === true, 'REST DELETE returns 200 and deletes unreferenced managed file');

        // Idempotent replay
        $replay = $this->api->deleteAssetFile($reqDel);
        $this->ok($replay->get_status() === 200 && ($replay->get_headers()['Idempotent-Replay'] ?? '') === 'true', 'REST DELETE replays idempotently');
    }

    private function cleanup(): void
    {
        $p = $this->wpdb->prefix;
        foreach ($this->posts as $id) {
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$p}wpe_product_media WHERE product_id = %d", $id));
            wp_delete_post($id, true);
        }
        $this->wpdb->query("DELETE FROM {$p}wpe_upload_items WHERE upload_key LIKE 'p011t-%'");
        foreach (array_unique($this->assetIds) as $id) {
            $this->media->deleteAssetStorage((int) $id);
            $this->media->deleteAsset((int) $id, true);
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
    (new MediaRecoveryTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
