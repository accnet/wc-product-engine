<?php

/**
 * P011.3 — Storage provider layer.
 *
 * Default storage selection (object storage when enabled, otherwise local), no silent fallback when the default
 * cannot be used, upload followed by verification, per-asset provider handling after the default changes,
 * and credential redaction. Uses injected doubles; no network access and no real credentials.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';
require_once __DIR__ . '/support/FakeStorageProvider.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Application\Media\Storage\LocalStorageProvider;
use WPE\Application\Media\Storage\StorageException;
use WPE\Application\Media\Storage\StoragePluginProvider;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Application\Media\Storage\StorageSanitizer;
use WPE\Application\Media\Storage\StorageUnavailableException;

/** Stand-in for the plugin settings object. */
final class FakePluginSettings
{
    public function __construct(private array $config) {}
    public function storageConfig(): array { return $this->config; }
    public function buildObjectKey(string $relativePath): string
    {
        $prefix = trim((string) ($this->config['path_prefix'] ?? ''), '/');
        return ($prefix === '' ? '' : $prefix . '/') . ltrim($relativePath, '/');
    }
}

/** Stand-in for a plugin adapter, with switches for each failure. */
final class FakePluginAdapter
{
    public array $objects = [];
    public bool $configured = true;
    public bool $uploadOk = true;
    public bool $throwOnUpload = false;
    public ?string $lastError = null;
    public bool $reportLength = true;
    public int $lengthDelta = 0;
    public int $uploads = 0;

    public function isConfigured(): bool { return $this->configured; }
    public function getLastError(): ?string { return $this->lastError; }
    public function upload(string $localPath, string $key, string $mimeType = '', array $metadata = []): bool
    {
        $this->uploads++;
        if ($this->throwOnUpload) { throw new RuntimeException('connection reset'); }
        if (!$this->uploadOk) { return false; }
        $this->objects[$key] = (string) file_get_contents($localPath);
        return true;
    }
    public function exists(string $key): bool { return isset($this->objects[$key]); }
    public function getMetadata(string $key): array
    {
        if (!isset($this->objects[$key])) { return []; }
        return $this->reportLength ? ['ContentLength' => strlen($this->objects[$key]) + $this->lengthDelta] : [];
    }
    public function getUrl(string $key): string { return 'https://bucket.example.test/' . $key; }
    public function delete(string $key): bool { unset($this->objects[$key]); return true; }
}

class StorageResolverTest
{
    private int $checks = 0;
    private array $tmp = [];

    public function run(): void
    {
        echo "Running storage resolver tests...\n";
        try {
            $this->testDefaultSelection();
            $this->testNoSilentFallback();
            $this->testPluginProviderFlow();
            $this->testPluginProviderFailures();
            $this->testProviderSwitchKeepsOldObjectsOwned();
            $this->testLocalProvider();
            $this->testRedaction();
            echo "All storage resolver tests passed ({$this->checks} checks).\n";
        } finally {
            foreach ($this->tmp as $path) {
                is_dir($path) ? $this->rrmdir($path) : @unlink($path);
            }
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

    private function tmpFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wpe-sr-');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;
        return $path;
    }

    private function localProvider(): LocalStorageProvider
    {
        $dir = sys_get_temp_dir() . '/wpe-sr-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->tmp[] = $dir;
        return new LocalStorageProvider($dir, 'https://uploads.example.test');
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function resolver(bool $enabled, ?StoragePluginProvider $plugin, ?LocalStorageProvider $local = null): StorageResolver
    {
        return new StorageResolver(static fn () => $plugin, static fn () => $enabled, $local ?? $this->localProvider());
    }

    private function plugin(?FakePluginAdapter $adapter = null, array $config = []): StoragePluginProvider
    {
        $config += ['provider' => 's3', 'bucket' => 'bucket-one', 'path_prefix' => 'shop', 'cdn_url' => ''];
        return new StoragePluginProvider(new FakePluginSettings($config), $adapter ?? new FakePluginAdapter());
    }

    private function testDefaultSelection(): void
    {
        echo "[default] selection\n";
        $plugin = $this->plugin();
        $this->ok($this->resolver(true, $plugin)->default() === $plugin, 'object storage enabled and usable is the default');
        $local = $this->localProvider();
        $this->ok($this->resolver(false, $plugin, $local)->default() === $local, 'object storage disabled selects local storage');
        $this->ok($this->resolver(true, $plugin)->defaultIsObjectStorage() === true, 'reader reports object storage');
    }

    private function testNoSilentFallback(): void
    {
        echo "[default] no silent fallback\n";
        $local = $this->localProvider();

        $threw = false;
        try {
            $this->resolver(true, null, $local)->default();
        } catch (StorageUnavailableException $e) {
            $threw = $e->reason() === 'storage_unavailable' && str_contains($e->getMessage(), 'not loaded');
        }
        $this->ok($threw, 'enabled but plugin not loaded is an error, not local storage');

        $adapter = new FakePluginAdapter();
        $adapter->configured = false;
        $adapter->lastError = 'Access denied for bucket';
        $threw = false;
        try {
            $this->resolver(true, $this->plugin($adapter), $local)->default();
        } catch (StorageUnavailableException $e) {
            $threw = str_contains($e->getMessage(), 'Access denied');
        }
        $this->ok($threw, 'enabled but not configured is an error with the provider message');

        $threw = false;
        try {
            $this->resolver(false, null, new LocalStorageProvider('/nonexistent-wpe-dir', 'https://x.test'))->default();
        } catch (StorageUnavailableException $e) {
            $threw = true;
        }
        $this->ok($threw, 'unusable local storage is an error too');
    }

    private function testPluginProviderFlow(): void
    {
        echo "[plugin] upload then verify\n";
        $adapter  = new FakePluginAdapter();
        $provider = $this->plugin($adapter);
        $key      = $provider->objectKey('wpe-products/2026/10/abc-shirt.jpg');
        $this->ok($key === 'shop/wpe-products/2026/10/abc-shirt.jpg', 'object key carries the plugin path prefix');
        $this->ok($provider->name() === 's3' && $provider->bucket() === 'bucket-one', 'name and bucket come from the plugin settings');

        $file = $this->tmpFile('image-bytes');
        $provider->put($file, $key, 'image/jpeg');
        $this->ok(isset($adapter->objects[$key]), 'the adapter received the object');
        $this->ok($provider->verify($key, strlen('image-bytes')) === true, 'verify passes with the right size');
        $this->ok($provider->verify($key, 999) === false, 'verify fails with another size');
        $this->ok($provider->verify('shop/missing.jpg', 1) === false, 'verify fails for a missing object');
        $this->ok($provider->urlFor($key) === 'https://bucket.example.test/' . $key, 'url comes from the adapter');
        $this->ok($provider->delete($key) === true && !isset($adapter->objects[$key]), 'delete removes the object');
    }

    private function testPluginProviderFailures(): void
    {
        echo "[plugin] failures\n";
        $file = $this->tmpFile('bytes');

        $adapter = new FakePluginAdapter();
        $adapter->uploadOk = false;
        $adapter->lastError = 'SlowDown';
        $threw = false;
        try { $this->plugin($adapter)->put($file, 'shop/a.jpg', 'image/jpeg'); } catch (StorageException $e) { $threw = $e->getMessage() === 'SlowDown'; }
        $this->ok($threw && $adapter->objects === [], 'adapter refusal raises an error and stores nothing');

        $adapter = new FakePluginAdapter();
        $adapter->throwOnUpload = true;
        $threw = false;
        try { $this->plugin($adapter)->put($file, 'shop/a.jpg', 'image/jpeg'); } catch (StorageException $e) { $threw = $e->getMessage() === 'connection reset'; }
        $this->ok($threw, 'adapter exceptions become storage errors');

        $adapter = new FakePluginAdapter();
        $adapter->configured = false;
        $threw = false;
        try { $this->plugin($adapter)->put($file, 'shop/a.jpg', 'image/jpeg'); } catch (StorageUnavailableException $e) { $threw = $adapter->uploads === 0; }
        $this->ok($threw, 'an unconfigured adapter is never called');

        $adapter = new FakePluginAdapter();
        $adapter->reportLength = false;
        $provider = $this->plugin($adapter);
        $provider->put($file, 'shop/b.jpg', 'image/jpeg');
        $this->ok($provider->verify('shop/b.jpg', 5) === false, 'missing size metadata is not treated as verified');

        $threw = false;
        try { $this->plugin()->put('/no/such/file.jpg', 'shop/c.jpg', 'image/jpeg'); } catch (StorageException $e) { $threw = $e->reason() === 'source_unreadable'; }
        $this->ok($threw, 'unreadable source file is reported');
    }

    private function testProviderSwitchKeepsOldObjectsOwned(): void
    {
        echo "[switch] default changes\n";
        $s3 = $this->plugin(new FakePluginAdapter(), ['provider' => 's3', 'bucket' => 'bucket-one']);
        $this->ok($this->resolver(true, $s3)->forStored('s3', 'bucket-one') === $s3, 'a stored object is handled by the provider that holds it');

        foreach ([['spaces', 'bucket-one'], ['s3', 'bucket-two']] as [$p, $b]) {
            $threw = false;
            try {
                $this->resolver(true, $s3)->forStored($p, $b);
            } catch (StorageUnavailableException $e) {
                $threw = $e->reason() === 'provider_mismatch';
            }
            $this->ok($threw, "object stored on {$p}/{$b} is never handled by the current default");
        }

        $threw = false;
        try { $this->resolver(false, null)->forStored('s3', 'bucket-one'); } catch (StorageUnavailableException $e) { $threw = $e->reason() === 'provider_mismatch'; }
        $this->ok($threw, 'after switching to local, objects of s3 are not touched');

        $local = $this->localProvider();
        $this->ok($this->resolver(true, $s3, $local)->forStored('local', '') === $local, 'old local objects stay with the local provider while object storage is the default');
    }

    private function testLocalProvider(): void
    {
        echo "[local] provider\n";
        $local = $this->localProvider();
        $key   = $local->objectKey('2026/10/abc-shirt.jpg');
        $this->ok($key === 'wpe-products/2026/10/abc-shirt.jpg', 'local keys live in the dedicated folder');
        $file = $this->tmpFile('local-bytes');
        $local->put($file, $key, 'image/jpeg');
        $this->ok($local->verify($key, strlen('local-bytes')) === true, 'local verify passes');
        $this->ok($local->verify($key, 1) === false, 'local verify checks the size');
        $this->ok($local->urlFor($key) === 'https://uploads.example.test/' . $key, 'local url comes from the uploads url');
        $this->ok($local->name() === 'local' && $local->bucket() === '', 'local provider name and bucket');

        foreach (['../escape.jpg', 'wpe-products/../escape.jpg', 'other/a.jpg', '/etc/passwd', 'wpe-products//a.jpg', 'wpe-products/a b.jpg'] as $bad) {
            $threw = false;
            try { $local->put($file, $bad, 'image/jpeg'); } catch (StorageException $e) { $threw = $e->reason() === 'invalid_key'; }
            $this->ok($threw, "key {$bad} is rejected");
        }
        $this->ok($local->delete($key) === true && $local->verify($key, strlen('local-bytes')) === false, 'local delete removes the file');
        $this->ok($local->delete($key) === true, 'deleting a missing local file is a no-op');
    }

    private function testRedaction(): void
    {
        echo "[safety] redaction\n";
        $text = StorageSanitizer::message('<b>Denied</b> for AKIAABCDEFGHIJKLMNOP using ' . str_repeat('a1B2', 10) . ' at https://x.test/?X-Amz-Signature=deadbeef&b=1');
        $this->ok(!str_contains($text, 'AKIAABCDEFGHIJKLMNOP'), 'access key ids are removed');
        $this->ok(!str_contains($text, str_repeat('a1B2', 10)), 'secret-like values are removed');
        $this->ok(!str_contains($text, 'deadbeef') && !str_contains($text, '<'), 'signed values and markup are removed');
        $this->ok(mb_strlen(StorageSanitizer::message(str_repeat('x ', 400))) <= 300, 'messages are bounded');
    }
}

try {
    (new StorageResolverTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
