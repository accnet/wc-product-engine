<?php

/**
 * P011.2 — Managed file and upload intent storage.
 *
 * Covers the two additive tables (created by the migrator, columns match the contract), the managed/external
 * distinction, per-asset provider data, idempotent upload intents and session claiming. Uses disposable rows on
 * the live tables and removes them afterwards.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\SchemaMigrator;
use WPE\Infrastructure\Database\UploadItemRepository;

class MediaSchemaTest
{
    private wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private array $assetIds = [];
    private array $uploadKeys = [];
    private int $userId = 1;
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb    = $wpdb;
        $this->media   = new MediaRepository($wpdb);
        $this->uploads = new UploadItemRepository($wpdb);
    }

    public function run(): void
    {
        echo "Running media schema tests...\n";
        try {
            $this->testTablesExistAndMatchContract();
            $this->testManagedVersusExternal();
            $this->testStorageRowIsPerAssetAndImmutable();
            $this->testObjectKeyIsUnique();
            $this->testUploadIntentIsIdempotent();
            $this->testStateTransitionsAndRetry();
            $this->testSessionClaim();
            $this->testStaleStoredListing();
            echo "All media schema tests passed ({$this->checks} checks).\n";
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

    private function newAsset(string $suffix): int
    {
        $asset = $this->media->createAsset([
            'source_url' => 'https://cdn.example.test/p011-schema/' . uniqid($suffix . '-', true) . '.jpg',
            'mime'       => 'image/jpeg',
        ]);
        $this->assetIds[] = (int) $asset['id'];
        return (int) $asset['id'];
    }

    private function key(string $label): string
    {
        $key = 'p011-' . $label . '-' . uniqid('', true);
        $this->uploadKeys[] = $key;
        return $key;
    }

    private function testTablesExistAndMatchContract(): void
    {
        echo "[schema] tables and columns\n";
        $this->ok(UploadItemRepository::ensureSchema($this->wpdb), 'ensureSchema creates the additive tables');
        $this->ok(UploadItemRepository::ensureSchema($this->wpdb), 'ensureSchema is idempotent');
        $verify = (new SchemaMigrator($this->wpdb))->verifySchema();
        $this->ok($verify['valid'] === true, 'schema verification passes: ' . implode(',', $verify['missing_tables']));

        $contract = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/contracts/database/schema-contract.json'), true);
        foreach (['asset_storage', 'upload_items'] as $name) {
            $entry = null;
            foreach ($contract['tables'] as $t) {
                if ($t['name'] === $name) {
                    $entry = $t;
                }
            }
            $this->ok($entry !== null, "contract declares {$name}");
            preg_match_all('/`([a-z_]+) [A-Z]/', $entry['columns_contract'], $m);
            $expected = $m[1];
            $actual = $this->wpdb->get_col($this->wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
                $this->wpdb->prefix . 'wpe_' . $name
            ));
            $this->ok($expected === $actual, "live columns of {$name} equal the contract: " . implode(',', $actual));
        }
    }

    private function testManagedVersusExternal(): void
    {
        echo "[managed] versus external\n";
        $external = $this->newAsset('ext');
        $managed  = $this->newAsset('mng');
        $this->media->createAssetStorage($managed, [
            'storage_provider' => 'fake',
            'bucket'           => 'bucket-a',
            'object_key'       => 'wpe-products/2026/10/aaaa-shirt.jpg',
            'file_name'        => 'shirt.jpg',
            'size_bytes'       => 1234,
            'content_hash'     => hash('sha256', 'shirt'),
            'created_by'       => $this->userId,
        ]);
        $map = $this->media->getAssetStorageMap([$external, $managed]);
        $this->ok(!isset($map[$external]), 'external asset has no storage row');
        $this->ok(isset($map[$managed]) && $map[$managed]['state'] === 'active', 'managed asset has an active storage row');
        $this->ok($map[$managed]['bucket'] === 'bucket-a' && (int) $map[$managed]['size_bytes'] === 1234, 'provider data stored per asset');
        $this->ok($map[$managed]['content_hash'] === strtoupper(hash('sha256', 'shirt')), 'content hash round trips');
    }

    private function testStorageRowIsPerAssetAndImmutable(): void
    {
        echo "[managed] binding is stable\n";
        $id = $this->newAsset('bind');
        $data = ['storage_provider' => 'fake', 'bucket' => 'bucket-a', 'object_key' => 'k/' . uniqid('', true) . '.jpg', 'size_bytes' => 10, 'content_hash' => hash('sha256', 'x')];
        $first  = $this->media->createAssetStorage($id, $data);
        $second = $this->media->createAssetStorage($id, $data);
        $this->ok($first['asset_id'] === $second['asset_id'], 'same asset and object is idempotent');

        $threw = false;
        try {
            $this->media->createAssetStorage($id, ['object_key' => 'k/other.jpg'] + $data);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        $this->ok($threw, 'an asset cannot be rebound to another object (changing default storage never rewrites rows)');

        $threw = false;
        try {
            $this->media->createAssetStorage($id, ['content_hash' => 'nothex'] + $data);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        $this->ok($threw, 'invalid content hash is rejected');
    }

    private function testObjectKeyIsUnique(): void
    {
        echo "[managed] object uniqueness\n";
        $a = $this->newAsset('u1');
        $b = $this->newAsset('u2');
        $key = 'k/unique-' . uniqid('', true) . '.jpg';
        $base = ['storage_provider' => 'fake', 'bucket' => 'bucket-u', 'object_key' => $key, 'size_bytes' => 1, 'content_hash' => hash('sha256', 'u')];
        $this->media->createAssetStorage($a, $base);

        $this->wpdb->suppress_errors(true);
        $threw = false;
        try {
            $this->media->createAssetStorage($b, $base);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        $this->wpdb->suppress_errors(false);
        $this->ok($threw, 'the same provider, bucket and key cannot back two assets');

        $found = $this->media->findAssetStorageByObject('fake', 'bucket-u', $key);
        $this->ok($found !== null && (int) $found['asset_id'] === $a, 'lookup by object finds the owner');
        $this->ok($this->media->findAssetStorageByObject('fake', 'bucket-other', $key) === null, 'same key in another bucket is a different object');

        $this->ok($this->media->markAssetStorageDeleted($a) === true, 'storage row can be marked deleted');
        $this->ok($this->media->getAssetStorage($a)['state'] === 'deleted', 'state is deleted');
        $this->ok($this->media->markAssetStorageDeleted($a) === false, 'marking deleted twice is a no-op');
    }

    private function testUploadIntentIsIdempotent(): void
    {
        echo "[upload] intent idempotency\n";
        $key = $this->key('idem');
        $one = $this->uploads->begin($this->userId, $key, 'session-p011-a', null, 'one.jpg');
        $two = $this->uploads->begin($this->userId, $key, 'session-p011-a', null, 'one.jpg');
        $this->ok($one['id'] === $two['id'], 'same user and key resolve to one row');
        $this->ok($one['state'] === 'receiving' && $one['product_id'] === null, 'new intent is receiving and has no product');

        $other = $this->uploads->begin($this->userId + 1000, $key, 'session-p011-b', null, 'one.jpg');
        $this->ok($other['id'] !== $one['id'], 'the same key for another user is a separate intent');
        $this->wpdb->delete($this->uploads->tableName(), ['id' => $other['id']]);
    }

    private function testStateTransitionsAndRetry(): void
    {
        echo "[upload] states and retry\n";
        $key = $this->key('state');
        $row = $this->uploads->begin($this->userId, $key, 'session-p011-c', 0, 'two.jpg');
        $this->ok($row['product_id'] === null, 'product id 0 is treated as no product');

        $this->uploads->markFailed((int) $row['id'], 'storage_unavailable', "<b>Bucket</b> unreachable");
        $failed = $this->uploads->find($this->userId, $key);
        $this->ok($failed['state'] === 'failed' && $failed['error_reason'] === 'storage_unavailable', 'failure is recorded with a reason');
        $this->ok(strpos((string) $failed['error_message'], '<') === false, 'error text is stripped of markup');
        $this->ok($failed['asset_id'] === null, 'a failed upload has no asset');

        $this->uploads->reopen((int) $row['id']);
        $again = $this->uploads->find($this->userId, $key);
        $this->ok($again['state'] === 'receiving' && (int) $again['attempts'] === 2 && $again['error_reason'] === null, 'retry reopens with a higher attempt count');

        $this->uploads->markStored((int) $row['id'], [
            'mime' => 'image/jpeg', 'size_bytes' => 99, 'content_hash' => hash('sha256', 'two'),
            'storage_provider' => 'fake', 'bucket' => 'b', 'object_key' => 'k/two.jpg',
        ]);
        $stored = $this->uploads->find($this->userId, $key);
        $this->ok($stored['state'] === 'stored' && $stored['object_key'] === 'k/two.jpg' && (int) $stored['size_bytes'] === 99, 'stored state keeps the verified object data');
        $this->ok($stored['asset_id'] === null, 'stored does not create an asset');

        $assetId = $this->newAsset('reg');
        $this->uploads->markRegistered((int) $row['id'], $assetId);
        $reg = $this->uploads->find($this->userId, $key);
        $this->ok($reg['state'] === 'registered' && (int) $reg['asset_id'] === $assetId, 'registered links the asset');

        $this->uploads->reopen((int) $row['id']);
        $this->ok($this->uploads->find($this->userId, $key)['state'] === 'registered', 'only failed intents can be reopened');
    }

    private function testSessionClaim(): void
    {
        echo "[upload] session claim\n";
        $session = 'session-p011-claim-' . substr(md5(uniqid('', true)), 0, 8);
        $ids = [];
        foreach (['c1', 'c2'] as $n) {
            $key = $this->key($n);
            $row = $this->uploads->begin($this->userId, $key, $session, null, $n . '.jpg');
            $this->uploads->markStored((int) $row['id'], ['mime' => 'image/jpeg', 'size_bytes' => 1, 'content_hash' => hash('sha256', $n), 'storage_provider' => 'fake', 'bucket' => 'b', 'object_key' => 'k/' . $n . uniqid('', true)]);
            $ids[] = (int) $row['id'];
        }
        $receiving = $this->uploads->begin($this->userId, $this->key('c3'), $session, null, 'c3.jpg');

        $this->ok($this->uploads->claimSession($session, $this->userId + 1, 987654) === 0, 'another user cannot claim the session');
        $claimed = $this->uploads->claimSession($session, $this->userId, 987654);
        $this->ok($claimed === 2, 'owner claims only stored or registered items');
        $this->ok($this->uploads->findById((int) $receiving['id'])['product_id'] === null, 'an item still receiving is not claimed');
        $this->ok($this->uploads->claimSession($session, $this->userId, 987655) === 0, 'a claimed item cannot be claimed again');
        $this->ok((int) $this->uploads->findById($ids[0])['product_id'] === 987654, 'claimed item belongs to the product');
    }

    private function testStaleStoredListing(): void
    {
        echo "[upload] cleanup candidates\n";
        $key = $this->key('stale');
        $row = $this->uploads->begin($this->userId, $key, 'session-p011-stale', null, 'stale.jpg');
        $this->uploads->markStored((int) $row['id'], ['mime' => 'image/jpeg', 'size_bytes' => 1, 'content_hash' => hash('sha256', 'stale'), 'storage_provider' => 'fake', 'bucket' => 'b', 'object_key' => 'k/stale' . uniqid('', true)]);

        $future = gmdate('Y-m-d H:i:s.u', time() + 3600);
        $past   = gmdate('Y-m-d H:i:s.u', time() - 3600);
        $ids = array_map(static fn ($r) => (int) $r['id'], $this->uploads->listStaleStored($future, 500));
        $this->ok(in_array((int) $row['id'], $ids, true), 'a stored object without an asset is a cleanup candidate after the cutoff');
        $ids = array_map(static fn ($r) => (int) $r['id'], $this->uploads->listStaleStored($past, 500));
        $this->ok(!in_array((int) $row['id'], $ids, true), 'a recent stored object is not a candidate yet');

        $this->uploads->markCleaned((int) $row['id']);
        $ids = array_map(static fn ($r) => (int) $r['id'], $this->uploads->listStaleStored($future, 500));
        $this->ok(!in_array((int) $row['id'], $ids, true), 'a cleaned item leaves the candidate list');
    }

    private function cleanup(): void
    {
        foreach ($this->uploadKeys as $key) {
            $this->wpdb->delete($this->uploads->tableName(), ['upload_key' => $key]);
        }
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_upload_items WHERE session_id LIKE 'session-p011-%'");
        foreach ($this->assetIds as $id) {
            $this->media->deleteAssetStorage($id);
            $this->media->deleteAsset($id, true);
        }
    }
}

try {
    (new MediaSchemaTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
