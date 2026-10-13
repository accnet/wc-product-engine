<?php

/**
 * P011.4 — Managed upload service.
 *
 * Permission and file rules, upload then verify then register, no asset on failure, idempotent retry,
 * resume after a database failure, no silent fallback to local storage, and per-asset provider data after the
 * default storage changes. Uses a fake storage provider; it is not a certification of real storage.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';
require_once __DIR__ . '/support/FakeStorageProvider.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Application\Media\ManagedUploadService;
use WPE\Application\Media\Storage\LocalStorageProvider;
use WPE\Application\Media\Storage\StorageException;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Application\Media\Storage\StorageUnavailableException;
use WPE\Application\Media\UploadRejectedException;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

/** Media repository that fails the managed storage write on demand, to simulate a database failure. */
class FailingStorageMediaRepository extends MediaRepository
{
    public bool $failStorageWrite = false;

    public function createAssetStorage(int $assetId, array $data): array
    {
        if ($this->failStorageWrite) {
            throw new RuntimeException('simulated database failure');
        }
        return parent::createAssetStorage($assetId, $data);
    }
}

class ManagedUploadTest
{
    private wpdb $wpdb;
    private FailingStorageMediaRepository $media;
    private UploadItemRepository $uploads;
    private FakeStorageProvider $fake;
    private array $tmp = [];
    private array $users = [];
    private array $posts = [];
    private array $keys = [];
    private int $checks = 0;
    private ?string $savedMaxOption = null;
    private bool $hadMaxOption = false;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb    = $wpdb;
        $this->media   = new FailingStorageMediaRepository($wpdb);
        $this->uploads = new UploadItemRepository($wpdb);
        $this->fake    = new FakeStorageProvider();
    }

    public function run(): void
    {
        echo "Running managed upload tests...\n";
        $this->hadMaxOption   = get_option(ManagedUploadService::OPTION_MAX_BYTES, null) !== null;
        $this->savedMaxOption = $this->hadMaxOption ? (string) get_option(ManagedUploadService::OPTION_MAX_BYTES) : null;
        wp_set_current_user(1);
        try {
            $this->testHappyPath();
            $this->testIdempotentRetry();
            $this->testStorageFailureLeavesNoAsset();
            $this->testVerificationFailure();
            $this->testNoSilentFallback();
            $this->testResumeAfterDatabaseFailure();
            $this->testFileRules();
            $this->testPermissions();
            $this->testKeyReuseWithOtherContent();
            $this->testSessionUpload();
            $this->testProviderSwitch();
            $this->testConcurrentLock();
            echo "All managed upload tests passed ({$this->checks} checks).\n";
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

    private function service(?FakeStorageProvider $provider = null, bool $objectStorage = true, ?MediaRepository $media = null): ManagedUploadService
    {
        $provider = $provider ?? $this->fake;
        $local = new LocalStorageProvider($this->tmpDir(), 'https://uploads.example.test');
        $resolver = new StorageResolver(static fn () => $provider, static fn () => $objectStorage, $local);
        return new ManagedUploadService($resolver, $media ?? $this->media, $this->uploads, null, static fn () => true, $this->wpdb, 1);
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/wpe-mu-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->tmp[] = $dir;
        return $dir;
    }

    /** Create a real image file with GD; the variant makes the bytes (and hash) unique. */
    private function image(string $type = 'jpeg', int $variant = 0): string
    {
        $im = imagecreatetruecolor(24, 16);
        imagefill($im, 0, 0, imagecolorallocate($im, 20 + $variant % 200, 120, 200));
        imagesetpixel($im, 1, 1, imagecolorallocate($im, $variant % 255, 5, 5));
        $path = tempnam(sys_get_temp_dir(), 'wpe-img-');
        $fn = ['jpeg' => 'imagejpeg', 'png' => 'imagepng', 'gif' => 'imagegif', 'webp' => 'imagewebp'][$type];
        $fn($im, $path);
        imagedestroy($im);
        $this->tmp[] = $path;
        return $path;
    }

    private function file(string $path, string $name): array
    {
        return ['tmp_name' => $path, 'name' => $name, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
    }

    private function key(string $label): string
    {
        $key = 'p011t-' . $label . '-' . bin2hex(random_bytes(4));
        $this->keys[] = $key;
        return $key;
    }

    private function assetCount(): int
    {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_assets");
    }

    private function product(int $author = 1): int
    {
        $id = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'P011 upload test', 'post_author' => $author]);
        $this->posts[] = $id;
        return (int) $id;
    }

    private function expectReject(callable $fn, string $reason): bool
    {
        try {
            $fn();
        } catch (UploadRejectedException $e) {
            return $e->reason() === $reason;
        }
        return false;
    }

    private function testHappyPath(): void
    {
        echo "[upload] happy path\n";
        $productId = $this->product();
        $path = $this->image('jpeg', 1);
        $before = $this->assetCount();
        $result = $this->service()->upload($this->file($path, 'Blue Shirt.JPG'), ['upload_key' => $key = $this->key('happy'), 'product_id' => $productId, 'alt_text' => 'Blue shirt']);

        $this->ok($this->assetCount() === $before + 1, 'one asset is created');
        $this->ok($result['origin'] === 'managed' && $result['storage_provider'] === 'fake', 'the asset is managed and records its provider');
        $this->ok($result['mime'] === 'image/jpeg' && $result['width'] === 24 && $result['height'] === 16, 'mime and dimensions come from the file');
        $this->ok($result['size_bytes'] === filesize($path) && $result['alt_text'] === 'Blue shirt', 'size and alt text are stored');
        $this->ok($result['file_name'] === 'Blue-Shirt.JPG', 'the sanitized file name is stored');
        $objectKeys = array_keys($this->fake->objects);
        $this->ok(count($objectKeys) === 1 && preg_match('#^fake-prefix/wpe-products/\d{4}/\d{2}/[a-f0-9]{16}-blue-shirt\.jpg$#', $objectKeys[0]) === 1, 'the object key is deterministic and sanitized: ' . $objectKeys[0]);
        $this->ok($this->fake->objects[$objectKeys[0]] === file_get_contents($path), 'the object holds the uploaded bytes');
        $this->ok($result['url'] === 'https://cdn.fake.test/' . $objectKeys[0], 'the url is the provider url');
        $row = $this->media->getAssetStorage((int) $result['asset_id']);
        $this->ok($row['object_key'] === $objectKeys[0] && $row['bucket'] === 'fake-bucket' && $row['state'] === 'active', 'storage row keeps provider, bucket and key');
        $item = $this->uploads->find(1, $key);
        $this->ok($item['state'] === 'registered' && (int) $item['asset_id'] === (int) $result['asset_id'] && (int) $item['product_id'] === $productId, 'the intent is registered for the product');
        $asset = $this->media->getAssetById((int) $result['asset_id']);
        $this->ok($asset['health'] === 'healthy' && !empty(json_decode($asset['renditions'], true)), 'renditions are precomputed from the dimensions');

        foreach (['png', 'gif', 'webp'] as $i => $type) {
            $r = $this->service()->upload($this->file($this->image($type, 10 + $i), "pic.$type"), ['upload_key' => $this->key("t$type"), 'product_id' => $productId]);
            $this->ok($r['mime'] === 'image/' . $type, "{$type} files are accepted");
        }
    }

    private function testIdempotentRetry(): void
    {
        echo "[upload] idempotent retry\n";
        $productId = $this->product();
        $path = $this->image('png', 40);
        $key  = $this->key('retry');
        $svc  = $this->service();
        $first = $svc->upload($this->file($path, 'retry.png'), ['upload_key' => $key, 'product_id' => $productId]);
        $puts  = $this->fake->putCalls;
        $count = $this->assetCount();
        $again = $svc->upload($this->file($path, 'retry.png'), ['upload_key' => $key, 'product_id' => $productId]);
        $this->ok($again['asset_id'] === $first['asset_id'] && $again['reused'] === true, 'the same key returns the same asset');
        $this->ok($this->fake->putCalls === $puts && $this->assetCount() === $count, 'a retry neither uploads nor creates an asset');

        $other = $svc->upload($this->file($path, 'retry.png'), ['upload_key' => $this->key('retry2'), 'product_id' => $productId]);
        $this->ok($other['asset_id'] === $first['asset_id'], 'the same file and name under a new key resolves to the same asset (no duplicate media)');
        $this->ok($this->assetCount() === $count, 'no extra asset row for identical content');
    }

    private function testStorageFailureLeavesNoAsset(): void
    {
        echo "[upload] storage failure\n";
        $productId = $this->product();
        $path = $this->image('jpeg', 60);
        $key  = $this->key('failput');
        $count = $this->assetCount();
        $this->fake->failPut = true;
        $threw = false;
        try {
            $this->service()->upload($this->file($path, 'fail.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        } catch (StorageException $e) {
            $threw = $e->reason() === 'upload_failed';
        }
        $this->ok($threw, 'a provider failure is raised, not hidden');
        $this->ok($this->assetCount() === $count, 'no asset exists after a failed upload');
        $item = $this->uploads->find(1, $key);
        $this->ok($item['state'] === 'failed' && $item['error_reason'] === 'upload_failed' && $item['asset_id'] === null, 'the intent records the failure');

        $this->fake->failPut = false;
        $r = $this->service()->upload($this->file($path, 'fail.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        $this->ok($this->assetCount() === $count + 1 && $r['origin'] === 'managed', 'retry with the same key succeeds with one asset');
        $this->ok((int) $this->uploads->find(1, $key)['attempts'] === 2, 'the attempt counter shows the retry');
    }

    private function testVerificationFailure(): void
    {
        echo "[upload] verification failure\n";
        $productId = $this->product();
        $path = $this->image('jpeg', 70);
        $key  = $this->key('verify');
        $count = $this->assetCount();
        $objects = count($this->fake->objects);
        $this->fake->failVerify = true;
        $threw = false;
        try {
            $this->service()->upload($this->file($path, 'verify.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        } catch (StorageException $e) {
            $threw = $e->reason() === 'verification_failed';
        }
        $this->fake->failVerify = false;
        $this->ok($threw, 'an unconfirmed object is an error');
        $this->ok($this->assetCount() === $count, 'no asset is created for an unconfirmed object');
        $this->ok(count($this->fake->objects) === $objects, 'the unconfirmed object is removed');
        $this->service()->upload($this->file($path, 'verify.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        $this->ok($this->assetCount() === $count + 1, 'retry succeeds');
    }

    private function testNoSilentFallback(): void
    {
        echo "[upload] no silent fallback\n";
        $productId = $this->product();
        $dir = $this->tmpDir();
        $local = new LocalStorageProvider($dir, 'https://uploads.example.test');
        $resolver = new StorageResolver(static fn () => null, static fn () => true, $local);
        $svc = new ManagedUploadService($resolver, $this->media, $this->uploads, null, static fn () => true, $this->wpdb, 1);
        $count = $this->assetCount();
        $key = $this->key('nofallback');
        $threw = false;
        try {
            $svc->upload($this->file($this->image('jpeg', 80), 'x.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        } catch (StorageUnavailableException $e) {
            $threw = $e->reason() === 'storage_unavailable';
        }
        $this->ok($threw, 'object storage enabled but unavailable is a storage unavailable error');
        $this->ok($this->assetCount() === $count, 'no asset is created');
        $this->ok(glob($dir . '/*') === [], 'nothing was written to local storage');
        $this->ok($this->uploads->find(1, $key)['error_reason'] === 'storage_unavailable', 'the intent records the reason for the retry');

        $svcLocal = $this->service(null, false);
        $r = $svcLocal->upload($this->file($this->image('jpeg', 81), 'local.jpg'), ['upload_key' => $this->key('local'), 'product_id' => $productId]);
        $this->ok($r['storage_provider'] === 'local', 'with object storage disabled the local provider is used');
        $row = $this->media->getAssetStorage((int) $r['asset_id']);
        $this->ok($row['bucket'] === '' && str_starts_with($row['object_key'], 'wpe-products/'), 'local objects live in the dedicated folder');
    }

    private function testResumeAfterDatabaseFailure(): void
    {
        echo "[upload] database failure after storing\n";
        $productId = $this->product();
        $path = $this->image('jpeg', 90);
        $key  = $this->key('resume');
        $count = $this->assetCount();
        $objects = count($this->fake->objects);

        $this->media->failStorageWrite = true;
        $threw = false;
        try {
            $this->service()->upload($this->file($path, 'resume.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        } catch (StorageException $e) {
            $threw = $e->reason() === 'registration_failed';
        }
        $this->media->failStorageWrite = false;
        $this->ok($threw, 'the registration failure is reported');
        $this->ok($this->assetCount() === $count, 'the transaction left no half-created asset');
        $this->ok(count($this->fake->objects) === $objects + 1, 'the object stays in storage');
        $item = $this->uploads->find(1, $key);
        $this->ok($item['state'] === 'stored' && $item['error_reason'] === 'database_error' && $item['asset_id'] === null, 'the intent stays stored with the error noted');

        $puts = $this->fake->putCalls;
        $r = $this->service()->upload($this->file($path, 'resume.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        $this->ok($this->fake->putCalls === $puts, 'the retry does not upload again');
        $this->ok($this->assetCount() === $count + 1 && $r['origin'] === 'managed', 'the retry creates exactly one asset');
        $this->ok($this->uploads->find(1, $key)['state'] === 'registered', 'the intent is registered');

        // Stored object vanished between attempts: the retry uploads again instead of registering a missing file.
        $key2  = $this->key('resume2');
        $path2 = $this->image('jpeg', 91);
        $this->media->failStorageWrite = true;
        try {
            $this->service()->upload($this->file($path2, 'gone.jpg'), ['upload_key' => $key2, 'product_id' => $productId]);
        } catch (StorageException $e) {
        }
        $this->media->failStorageWrite = false;
        $this->fake->objects = [];
        $puts = $this->fake->putCalls;
        $this->service()->upload($this->file($path2, 'gone.jpg'), ['upload_key' => $key2, 'product_id' => $productId]);
        $this->ok($this->fake->putCalls === $puts + 1, 'a vanished stored object is uploaded again');
    }

    private function testFileRules(): void
    {
        echo "[upload] file rules\n";
        $productId = $this->product();
        $count = $this->assetCount();
        $ctx = fn (string $l) => ['upload_key' => $this->key($l), 'product_id' => $productId];
        $svc = $this->service();

        $svg = tempnam(sys_get_temp_dir(), 'wpe-svg-');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>');
        $this->tmp[] = $svg;
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($svg, 'x.svg'), $ctx('svg')), 'file_type_not_allowed'), 'SVG is rejected');

        $php = tempnam(sys_get_temp_dir(), 'wpe-php-');
        file_put_contents($php, "<?php echo 'x';");
        $this->tmp[] = $php;
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($php, 'x.jpg'), $ctx('php')), 'file_type_not_allowed'), 'a script renamed to jpg is rejected by content');

        $this->ok($this->expectReject(fn () => $svc->upload($this->file($this->image('png', 100), 'x.jpg'), $ctx('ext')), 'file_type_not_allowed'), 'an extension that does not match the content is rejected');

        $broken = tempnam(sys_get_temp_dir(), 'wpe-bad-');
        file_put_contents($broken, "\xFF\xD8\xFF\xE0" . random_bytes(64));
        $this->tmp[] = $broken;
        $rejected = '';
        try {
            $svc->upload($this->file($broken, 'x.jpg'), $ctx('broken'));
        } catch (UploadRejectedException $e) {
            $rejected = $e->reason();
        }
        $this->ok(in_array($rejected, ['invalid_image', 'file_type_not_allowed'], true), 'a corrupted image is rejected (' . $rejected . ')');

        $empty = tempnam(sys_get_temp_dir(), 'wpe-empty-');
        $this->tmp[] = $empty;
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($empty, 'x.jpg'), $ctx('empty')), 'invalid_upload'), 'an empty file is rejected');

        $this->ok($this->expectReject(fn () => $svc->upload(['tmp_name' => '', 'name' => 'x.jpg', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE], $ctx('nofile')), 'invalid_upload'), 'a missing file is rejected');
        $this->ok($this->expectReject(fn () => $svc->upload(['tmp_name' => '', 'name' => 'x.jpg', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE], $ctx('ini')), 'file_too_large'), 'a server size limit error is a too large error');

        update_option(ManagedUploadService::OPTION_MAX_BYTES, 100);
        $big = $this->image('jpeg', 110);
        $this->ok(filesize($big) > 100 && $this->expectReject(fn () => $svc->upload($this->file($big, 'big.jpg'), $ctx('big')), 'file_too_large'), 'a file above the configured limit is rejected');
        $this->ok(ManagedUploadService::limits()['max_bytes'] === 100, 'limits report the configured maximum');
        if ($this->hadMaxOption) {
            update_option(ManagedUploadService::OPTION_MAX_BYTES, $this->savedMaxOption);
        } else {
            delete_option(ManagedUploadService::OPTION_MAX_BYTES);
        }
        $this->ok(ManagedUploadService::limits()['max_bytes'] <= (int) wp_max_upload_size(), 'the effective limit never exceeds the WordPress limit');

        $strict = new ManagedUploadService(new StorageResolver(fn () => $this->fake, fn () => true, new LocalStorageProvider($this->tmpDir(), 'https://u.test')), $this->media, $this->uploads, null, static fn () => false, $this->wpdb, 1);
        $this->ok($this->expectReject(fn () => $strict->upload($this->file($this->image('jpeg', 111), 'a.jpg'), $ctx('notupl')), 'invalid_upload'), 'a path that is not a real HTTP upload is rejected');

        $this->ok($this->assetCount() === $count, 'rejected files create no assets');
        $svgItem = null;
        foreach ($this->keys as $k) {
            if (str_contains($k, '-svg-')) {
                $svgItem = $this->uploads->find(1, $k);
            }
        }
        $this->ok($svgItem !== null && $svgItem['state'] === 'failed' && $svgItem['error_reason'] === 'file_type_not_allowed', 'a rejected upload is visible as failed with its reason');
    }

    private function testPermissions(): void
    {
        echo "[upload] permissions\n";
        $productId = $this->product(1);
        $count = $this->assetCount();
        $svc = $this->service();
        $path = $this->image('jpeg', 120);

        $sub = wp_insert_user(['user_login' => 'p011_sub_' . bin2hex(random_bytes(3)), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        $author = wp_insert_user(['user_login' => 'p011_aut_' . bin2hex(random_bytes(3)), 'user_pass' => wp_generate_password(), 'role' => 'author']);
        $this->users = [$sub, $author];

        wp_set_current_user($sub);
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($path, 'a.jpg'), ['upload_key' => $this->key('sub'), 'product_id' => $productId]), 'forbidden'), 'a user without upload_files is refused');

        wp_set_current_user($author);
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($path, 'a.jpg'), ['upload_key' => $this->key('aut'), 'product_id' => $productId]), 'forbidden'), 'an uploader who cannot edit this product is refused');

        wp_set_current_user(0);
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($path, 'a.jpg'), ['upload_key' => $this->key('anon'), 'product_id' => $productId]), 'forbidden'), 'an anonymous request is refused');

        wp_set_current_user(1);
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($path, 'a.jpg'), ['upload_key' => 'short', 'product_id' => $productId]), 'invalid_request'), 'a malformed upload key is refused');
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($path, 'a.jpg'), ['upload_key' => $this->key('noprod')]), 'invalid_request'), 'a product or a session is required');
        $this->ok($this->assetCount() === $count, 'refused requests create no assets');
    }

    private function testKeyReuseWithOtherContent(): void
    {
        echo "[upload] key reuse\n";
        $productId = $this->product();
        $key = $this->key('reuse');
        $svc = $this->service();
        $svc->upload($this->file($this->image('jpeg', 130), 'one.jpg'), ['upload_key' => $key, 'product_id' => $productId]);

        $key2 = $this->key('reuse2');
        $this->media->failStorageWrite = true;
        try {
            $svc->upload($this->file($this->image('jpeg', 131), 'two.jpg'), ['upload_key' => $key2, 'product_id' => $productId]);
        } catch (StorageException $e) {
        }
        $this->media->failStorageWrite = false;
        $this->ok($this->expectReject(fn () => $svc->upload($this->file($this->image('jpeg', 132), 'two.jpg'), ['upload_key' => $key2, 'product_id' => $productId]), 'upload_key_reused'), 'a key stored for one file cannot be used for different content');
    }

    private function testSessionUpload(): void
    {
        echo "[upload] session without a product\n";
        $session = 'sess-p011-' . bin2hex(random_bytes(4));
        $key = $this->key('session');
        $r = $this->service()->upload($this->file($this->image('jpeg', 140), 'sess.jpg'), ['upload_key' => $key, 'session_id' => $session]);
        $item = $this->uploads->find(1, $key);
        $this->ok($item['product_id'] === null && $item['session_id'] === $session && $item['state'] === 'registered', 'a session upload has no product yet');
        $productId = $this->product();
        $this->ok($this->uploads->claimSession($session, 1, $productId) === 1, 'the session is claimed by the new product');
        $this->ok((int) $this->uploads->find(1, $key)['product_id'] === $productId && $r['origin'] === 'managed', 'the upload now belongs to the product');
    }

    private function testProviderSwitch(): void
    {
        echo "[upload] default storage change\n";
        $productId = $this->product();
        $first = $this->service()->upload($this->file($this->image('jpeg', 150), 'old.jpg'), ['upload_key' => $this->key('old'), 'product_id' => $productId]);
        $otherProvider = new FakeStorageProvider();
        $otherProvider->providerName = 'spaces';
        $otherProvider->bucketName = 'other-bucket';
        $otherProvider->prefix = 'other';
        $second = $this->service($otherProvider)->upload($this->file($this->image('jpeg', 151), 'new.jpg'), ['upload_key' => $this->key('new'), 'product_id' => $productId]);

        $oldRow = $this->media->getAssetStorage((int) $first['asset_id']);
        $newRow = $this->media->getAssetStorage((int) $second['asset_id']);
        $this->ok($oldRow['storage_provider'] === 'fake' && $oldRow['bucket'] === 'fake-bucket', 'the old asset keeps its provider and bucket');
        $this->ok($newRow['storage_provider'] === 'spaces' && $newRow['bucket'] === 'other-bucket', 'the new asset uses the new default');
        $this->ok($this->media->getAssetById((int) $first['asset_id'])['source_url'] === $first['url'], 'the old url is not rewritten');
        $this->ok($oldRow['object_key'] !== $newRow['object_key'], 'objects are separate');
    }

    private function testConcurrentLock(): void
    {
        echo "[upload] concurrent request\n";
        $productId = $this->product();
        $key = $this->key('lock');
        $name = 'wpe_upload_' . substr(hash('sha256', '1:' . $key), 0, 40);
        $other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 5)");
        $count = $this->assetCount();
        $ok = $this->expectReject(fn () => $this->service()->upload($this->file($this->image('jpeg', 160), 'lock.jpg'), ['upload_key' => $key, 'product_id' => $productId]), 'upload_in_progress');
        $other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($name) . "')");
        $other->close();
        $this->ok($ok, 'a request for a key that is being processed is told to retry');
        $this->ok($this->assetCount() === $count, 'the blocked request created nothing');
        $r = $this->service()->upload($this->file($this->image('jpeg', 160), 'lock.jpg'), ['upload_key' => $key, 'product_id' => $productId]);
        $this->ok($r['origin'] === 'managed', 'after the lock is released the upload proceeds');
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function cleanup(): void
    {
        wp_set_current_user(1);
        if ($this->hadMaxOption) {
            update_option(ManagedUploadService::OPTION_MAX_BYTES, $this->savedMaxOption);
        } else {
            delete_option(ManagedUploadService::OPTION_MAX_BYTES);
        }
        $prefix = $this->wpdb->prefix;
        $ids = $this->wpdb->get_col("SELECT asset_id FROM {$prefix}wpe_upload_items WHERE upload_key LIKE 'p011t-%' AND asset_id IS NOT NULL");
        $this->wpdb->query("DELETE FROM {$prefix}wpe_upload_items WHERE upload_key LIKE 'p011t-%'");
        foreach (array_unique($ids) as $id) {
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
}

try {
    (new ManagedUploadTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
