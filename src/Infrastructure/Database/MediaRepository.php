<?php

namespace WPE\Infrastructure\Database;

use RuntimeException;
use InvalidArgumentException;
use WPE\Compatibility\WordPress\MetaIdAllocator;

/**
 * Media, asset, and metadata persistence repository.
 * Handles ordered featured/gallery/variant media, shared asset lifecycle (no cascade delete),
 * repeated and serialized WordPress metadata with authoritative MetaIdAllocator IDs,
 * downloadable products with preserved immutable download IDs, and cross/upsell product links.
 */
class MediaRepository
{
    private \wpdb $wpdb;
    private MetaIdAllocator $metaIdAllocator;

    private string $tableAssets;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableMeta;
    private string $tableDownloads;
    private string $tableProductLinks;
    private string $tableAssetStorage;

    public function __construct(?\wpdb $wpdb = null, ?MetaIdAllocator $metaIdAllocator = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->metaIdAllocator = $metaIdAllocator ?? new MetaIdAllocator($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableAssets       = $prefix . 'wpe_assets';
        $this->tableProductMedia = $prefix . 'wpe_product_media';
        $this->tableVariantMedia = $prefix . 'wpe_variant_media';
        $this->tableMeta         = $prefix . 'wpe_meta';
        $this->tableDownloads    = $prefix . 'wpe_downloads';
        $this->tableProductLinks = $prefix . 'wpe_product_links';
        $this->tableAssetStorage = $prefix . 'wpe_asset_storage';
    }

    /* -------------------------------------------------------------------------- */
    /* Assets                                                                     */
    /* -------------------------------------------------------------------------- */

    /**
     * Create an asset record.
     * Validates JSON structure of delivery_policy and renditions (AC-T08-01).
     */
    public function createAsset(array $data): array
    {
        $sourceUrl = trim((string)($data['source_url'] ?? ''));
        if ($sourceUrl === '') {
            throw new InvalidArgumentException("Asset source_url cannot be empty.");
        }

        // Validate JSON fields (AC-T08-01)
        $deliveryPolicyJson = '{}';
        if (isset($data['delivery_policy'])) {
            if (is_string($data['delivery_policy'])) {
                json_decode($data['delivery_policy']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException("Malformed delivery_policy JSON: " . json_last_error_msg());
                }
                $deliveryPolicyJson = $data['delivery_policy'];
            } else {
                $deliveryPolicyJson = json_encode($data['delivery_policy'], JSON_UNESCAPED_UNICODE);
            }
        }

        $renditionsJson = '[]';
        if (isset($data['renditions'])) {
            if (is_string($data['renditions'])) {
                json_decode($data['renditions']);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new InvalidArgumentException("Malformed renditions JSON: " . json_last_error_msg());
                }
                $renditionsJson = $data['renditions'];
            } else {
                $renditionsJson = json_encode($data['renditions'], JSON_UNESCAPED_UNICODE);
            }
        }

        $urlKeyHex = hash('sha256', $sourceUrl);
        $now = gmdate('Y-m-d H:i:s.u');

        $widthSql = isset($data['width']) && $data['width'] !== null ? (int)$data['width'] : 'NULL';
        $heightSql = isset($data['height']) && $data['height'] !== null ? (int)$data['height'] : 'NULL';
        $attachmentSql = isset($data['attachment_id']) && $data['attachment_id'] !== null ? (int)$data['attachment_id'] : 'NULL';

        $sql = "INSERT INTO {$this->tableAssets}
                (kind, source_url, url_key, provider, provider_key, mime, width, height, alt_text, title, caption, attachment_id, delivery_policy, renditions, health, created_at, updated_at, version)
                VALUES (%s, %s, UNHEX(%s), %s, %s, %s, {$widthSql}, {$heightSql}, %s, %s, %s, {$attachmentSql}, %s, %s, %s, %s, %s, 1)";

        $res = $this->wpdb->query($this->wpdb->prepare(
            $sql,
            $data['kind'] ?? 'image',
            $sourceUrl,
            $urlKeyHex,
            $data['provider'] ?? 'default',
            $data['provider_key'] ?? null,
            $data['mime'] ?? 'image/jpeg',
            $data['alt_text'] ?? '',
            $data['title'] ?? '',
            $data['caption'] ?? '',
            $deliveryPolicyJson,
            $renditionsJson,
            $data['health'] ?? 'healthy',
            $now,
            $now
        ));

        if ($res === false) {
            throw new RuntimeException("Failed to insert asset: " . $this->wpdb->last_error);
        }

        $assetId = (int)$this->wpdb->insert_id;
        return $this->getAssetById($assetId);
    }

    /**
     * Get asset by ID.
     */
    public function getAssetById(int $assetId): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, kind, source_url, HEX(url_key) AS url_key_hex, provider, provider_key, mime, width, height, alt_text, title, caption, attachment_id, delivery_policy, renditions, health, created_at, updated_at, version
             FROM {$this->tableAssets} WHERE id = %d",
            $assetId
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Delete an asset safely.
     * Enforces shared asset lifecycle (R29): prevents deletion if referenced by other products or variants.
     */
    public function deleteAsset(int $assetId, bool $force = false): bool
    {
        if (!$force) {
            $pCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(1) FROM {$this->tableProductMedia} WHERE asset_id = %d",
                $assetId
            ));

            $vCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(1) FROM {$this->tableVariantMedia} WHERE asset_id = %d",
                $assetId
            ));

            if ($pCount > 0 || $vCount > 0) {
                throw new RuntimeException(
                    "Cannot delete shared asset {$assetId}: active references exist (product_refs: {$pCount}, variant_refs: {$vCount})."
                );
            }
        }

        $res = $this->wpdb->delete($this->tableAssets, ['id' => $assetId], ['%d']);
        return $res !== false;
    }

    /* -------------------------------------------------------------------------- */
    /* Product Media & Gallery                                                   */
    /* -------------------------------------------------------------------------- */

    /**
     * Set featured image for a product.
     * Enforces single featured image and validates foreign assets (AC-T08-01).
     */
    public function setFeaturedImage(int $productId, int $assetId, ?string $altOverride = null): bool
    {
        $this->assertAssetExists($assetId);

        $this->wpdb->query("START TRANSACTION");
        try {
            // Delete existing featured image for this product
            $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId, 'role' => 'featured'], ['%d', '%s']);

            // Insert new featured image
            $res = $this->wpdb->insert(
                $this->tableProductMedia,
                [
                    'product_id'   => $productId,
                    'asset_id'     => $assetId,
                    'role'         => 'featured',
                    'position'     => 0,
                    'alt_override' => $altOverride,
                ],
                ['%d', '%d', '%s', '%d', '%s']
            );

            if ($res === false) {
                throw new RuntimeException("Failed to set featured image: " . $this->wpdb->last_error);
            }

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Set product gallery images atomically with custom ordering (R28, AC-T08-01).
     *
     * @param int $productId
     * @param array $orderedAssetIds Array of asset IDs in order, or array of ['asset_id' => ..., 'alt_override' => ...]
     */
    public function setGallery(int $productId, array $orderedAssetIds): bool
    {
        // Validate all assets exist before modifying database (AC-T08-01)
        foreach ($orderedAssetIds as $item) {
            $assetId = is_array($item) ? (int)$item['asset_id'] : (int)$item;
            $this->assertAssetExists($assetId);
        }

        $this->wpdb->query("START TRANSACTION");

        try {
            // Remove previous gallery
            $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId, 'role' => 'gallery'], ['%d', '%s']);

            // Insert new ordered gallery
            $pos = 0;
            foreach ($orderedAssetIds as $item) {
                $assetId = is_array($item) ? (int)$item['asset_id'] : (int)$item;
                $alt = is_array($item) ? ($item['alt_override'] ?? null) : null;

                $res = $this->wpdb->insert(
                    $this->tableProductMedia,
                    [
                        'product_id'   => $productId,
                        'asset_id'     => $assetId,
                        'role'         => 'gallery',
                        'position'     => $pos++,
                        'alt_override' => $alt,
                    ],
                    ['%d', '%d', '%s', '%d', '%s']
                );

                if ($res === false) {
                    throw new RuntimeException("Failed to insert gallery item: " . $this->wpdb->last_error);
                }
            }

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Get gallery images for a product, ordered by position (R28).
     */
    public function getGallery(int $productId): array
    {
        $sql = "SELECT pm.position, pm.alt_override, a.*
                FROM {$this->tableProductMedia} pm
                JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                WHERE pm.product_id = %d AND pm.role = 'gallery'
                ORDER BY pm.position ASC";

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $productId), ARRAY_A);
    }

    /**
     * Get featured image for a product.
     */
    public function getFeaturedImage(int $productId): ?array
    {
        $sql = "SELECT pm.alt_override, a.*
                FROM {$this->tableProductMedia} pm
                JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                WHERE pm.product_id = %d AND pm.role = 'featured'
                LIMIT 1";

        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);
        return $row ?: null;
    }

    /* -------------------------------------------------------------------------- */
    /* Variant Media                                                              */
    /* -------------------------------------------------------------------------- */

    /**
     * Set primary image for a variation (AC-T08-01).
     */
    public function setVariantPrimaryImage(int $variantId, int $assetId, ?string $altOverride = null): bool
    {
        $this->assertAssetExists($assetId);

        $this->wpdb->query("START TRANSACTION");

        try {
            // Unset previous primary flag for this variation
            $this->wpdb->update($this->tableVariantMedia, ['is_primary' => 0], ['variant_id' => $variantId], ['%d'], ['%d']);

            // Upsert primary image
            $sql = "INSERT INTO {$this->tableVariantMedia} (variant_id, asset_id, position, is_primary, alt_override)
                    VALUES (%d, %d, 0, 1, %s)
                    ON DUPLICATE KEY UPDATE is_primary = 1, alt_override = VALUES(alt_override)";

            $this->wpdb->query($this->wpdb->prepare($sql, $variantId, $assetId, $altOverride));

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Get media items for a variation.
     */
    public function getVariantMedia(int $variantId): array
    {
        $sql = "SELECT vm.position, vm.is_primary, vm.alt_override, a.*
                FROM {$this->tableVariantMedia} vm
                JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                WHERE vm.variant_id = %d
                ORDER BY vm.position ASC";

        return $this->wpdb->get_results($this->wpdb->prepare($sql, $variantId), ARRAY_A);
    }

    /* -------------------------------------------------------------------------- */
    /* Metadata Persistence (R30, AC-T08-02)                                      */
    /* -------------------------------------------------------------------------- */

    /**
     * Add a metadata row with authoritative meta_id from MetaIdAllocator.
     * Supports repeated keys and standard WordPress serialization.
     */
    public function addMeta(int $objectId, string $objectKind, string $metaKey, $metaValue, bool $unique = false): int
    {
        if ($unique) {
            $existing = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT meta_id FROM {$this->tableMeta} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                $objectId,
                $metaKey
            ), ARRAY_A);

            if ($existing) {
                $this->updateMeta((int)$existing['meta_id'], $metaValue);
                return (int)$existing['meta_id'];
            }
        }

        // Allocate authoritative meta ID from WordPress postmeta allocator (03 §5)
        $metaId = $this->metaIdAllocator->allocateMetaId($objectId, $metaKey);

        $serializedValue = maybe_serialize($metaValue);

        $res = $this->wpdb->insert(
            $this->tableMeta,
            [
                'meta_id'     => $metaId,
                'object_id'   => $objectId,
                'object_kind' => $objectKind,
                'meta_key'    => $metaKey,
                'meta_value'  => $serializedValue,
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );

        if ($res === false) {
            throw new RuntimeException("Failed to insert meta row: " . $this->wpdb->last_error);
        }

        return $metaId;
    }

    /**
     * Get metadata for an object.
     * Supports repeated keys and auto-unserializes PHP serialized values.
     */
    public function getMeta(int $objectId, ?string $metaKey = null, bool $single = false)
    {
        if ($metaKey !== null) {
            $sql = "SELECT meta_id, meta_value FROM {$this->tableMeta} WHERE object_id = %d AND meta_key = %s ORDER BY meta_id ASC";
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $objectId, $metaKey), ARRAY_A);

            if (empty($rows)) {
                return $single ? '' : [];
            }

            if ($single) {
                return maybe_unserialize($rows[0]['meta_value']);
            }

            return array_map(fn($r) => maybe_unserialize($r['meta_value']), $rows);
        }

        $sql = "SELECT meta_id, meta_key, meta_value FROM {$this->tableMeta} WHERE object_id = %d ORDER BY meta_id ASC";
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $objectId), ARRAY_A);

        $meta = [];
        foreach ($rows as $r) {
            $k = $r['meta_key'];
            if (!isset($meta[$k])) {
                $meta[$k] = [];
            }
            $meta[$k][] = maybe_unserialize($r['meta_value']);
        }

        return $meta;
    }

    /**
     * Update an existing meta row by meta_id.
     */
    public function updateMeta(int $metaId, $metaValue): bool
    {
        $serialized = maybe_serialize($metaValue);
        $res = $this->wpdb->update(
            $this->tableMeta,
            ['meta_value' => $serialized],
            ['meta_id' => $metaId],
            ['%s'],
            ['%d']
        );

        return $res !== false;
    }

    /**
     * Delete metadata row by meta_id.
     */
    public function deleteMeta(int $metaId): bool
    {
        $res = $this->wpdb->delete($this->tableMeta, ['meta_id' => $metaId], ['%d']);
        return $res !== false;
    }

    /* -------------------------------------------------------------------------- */
    /* Downloads (R31, AC-T08-02)                                                 */
    /* -------------------------------------------------------------------------- */

    /**
     * Set downloadable files for an object, preserving custom download_id values.
     */
    public function setDownloads(int $objectId, array $downloads): bool
    {
        $this->wpdb->query("START TRANSACTION");

        try {
            $this->wpdb->delete($this->tableDownloads, ['object_id' => $objectId], ['%d']);

            $pos = 0;
            foreach ($downloads as $d) {
                $downloadId = trim((string)($d['download_id'] ?? wp_generate_uuid4()));
                $name = (string)($d['name'] ?? 'File');
                $fileUrl = (string)($d['file_url'] ?? '');

                $res = $this->wpdb->insert(
                    $this->tableDownloads,
                    [
                        'object_id'   => $objectId,
                        'download_id' => $downloadId,
                        'name'        => $name,
                        'file_url'    => $fileUrl,
                        'position'    => $pos++,
                    ],
                    ['%d', '%s', '%s', '%s', '%d']
                );

                if ($res === false) {
                    throw new RuntimeException("Failed to insert download row: " . $this->wpdb->last_error);
                }
            }

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Get downloadable files for an object.
     */
    public function getDownloads(int $objectId): array
    {
        $sql = "SELECT download_id, name, file_url, position FROM {$this->tableDownloads} WHERE object_id = %d ORDER BY position ASC";
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $objectId), ARRAY_A);
    }

    /* -------------------------------------------------------------------------- */
    /* Product Links                                                              */
    /* -------------------------------------------------------------------------- */

    /**
     * Set upsell or crosssell product links.
     */
    public function setLinks(int $productId, string $kind, array $targetIds): bool
    {
        $this->wpdb->query("START TRANSACTION");

        try {
            $this->wpdb->delete($this->tableProductLinks, ['product_id' => $productId, 'kind' => $kind], ['%d', '%s']);

            $pos = 0;
            foreach ($targetIds as $targetId) {
                $this->wpdb->insert(
                    $this->tableProductLinks,
                    [
                        'product_id' => $productId,
                        'target_id'  => (int)$targetId,
                        'kind'       => $kind,
                        'position'   => $pos++,
                    ],
                    ['%d', '%d', '%s', '%d']
                );
            }

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Get product links by kind.
     */
    public function getLinks(int $productId, ?string $kind = null): array
    {
        if ($kind !== null) {
            $sql = "SELECT target_id, kind, position FROM {$this->tableProductLinks} WHERE product_id = %d AND kind = %s ORDER BY position ASC";
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $productId, $kind), ARRAY_A);
        }

        $sql = "SELECT target_id, kind, position FROM {$this->tableProductLinks} WHERE product_id = %d ORDER BY kind ASC, position ASC";
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $productId), ARRAY_A);
    }

    /* -------------------------------------------------------------------------- */
    /* Managed file storage (docs/03 `asset_storage`)                              */
    /* -------------------------------------------------------------------------- */

    /**
     * Mark an asset as a managed file with its stored provider, bucket and object key.
     * An asset with no row here is an external URL. Idempotent for the same asset and object.
     *
     * @param array{storage_provider:string,bucket?:string,object_key:string,file_name?:string,size_bytes:int,content_hash:string,etag?:?string,created_by?:?int} $data content_hash is a sha256 hex digest
     */
    public function createAssetStorage(int $assetId, array $data): array
    {
        $this->assertAssetExists($assetId);

        $provider = (string)($data['storage_provider'] ?? '');
        $key      = (string)($data['object_key'] ?? '');
        if ($provider === '' || $key === '') {
            throw new InvalidArgumentException('Managed asset needs a storage provider and an object key.');
        }
        $bucket = (string)($data['bucket'] ?? '');
        $hash   = (string)($data['content_hash'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            throw new InvalidArgumentException('content_hash must be a sha256 hex digest.');
        }

        $existing = $this->getAssetStorage($assetId);
        if ($existing !== null) {
            if ($existing['storage_provider'] === $provider && $existing['bucket'] === $bucket && $existing['object_key'] === $key) {
                return $existing;
            }
            throw new RuntimeException("Asset {$assetId} is already bound to another stored object.");
        }

        $now     = gmdate('Y-m-d H:i:s.u');
        $keyHash = hash('sha256', $provider . "\0" . $bucket . "\0" . $key);
        $createdBy = isset($data['created_by']) && $data['created_by'] !== null ? (int)$data['created_by'] : 'NULL';

        $res = $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableAssetStorage}
                (asset_id, storage_provider, bucket, object_key, object_key_hash, file_name, size_bytes, content_hash, etag, state, created_by, created_at, updated_at)
             VALUES (%d, %s, %s, %s, UNHEX(%s), %s, %d, UNHEX(%s), %s, 'active', {$createdBy}, %s, %s)",
            $assetId,
            $provider,
            $bucket,
            $key,
            $keyHash,
            mb_substr((string)($data['file_name'] ?? ''), 0, 255),
            (int)($data['size_bytes'] ?? 0),
            strtolower($hash),
            isset($data['etag']) ? (string)$data['etag'] : null,
            $now,
            $now
        ));
        if ($res === false) {
            throw new RuntimeException('Failed to record managed storage: ' . $this->wpdb->last_error);
        }

        return $this->getAssetStorage($assetId);
    }

    public function getAssetStorage(int $assetId): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->storageColumns()} FROM {$this->tableAssetStorage} WHERE asset_id = %d LIMIT 1",
            $assetId
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Storage rows for many assets keyed by asset id; assets without a row are external.
     *
     * @param int[] $assetIds
     * @return array<int,array>
     */
    public function getAssetStorageMap(array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn ($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT {$this->storageColumns()} FROM {$this->tableAssetStorage} WHERE asset_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['asset_id']] = $row;
        }
        return $map;
    }

    public function findAssetStorageByObject(string $provider, string $bucket, string $objectKey): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$this->storageColumns()} FROM {$this->tableAssetStorage}
              WHERE storage_provider = %s AND bucket = %s AND object_key_hash = UNHEX(%s) LIMIT 1",
            $provider,
            $bucket,
            hash('sha256', $provider . "\0" . $bucket . "\0" . $objectKey)
        ), ARRAY_A);
        return $row ?: null;
    }

    public function markAssetStorageDeleted(int $assetId): bool
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $res = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableAssetStorage} SET state = 'deleted', deleted_at = %s, updated_at = %s WHERE asset_id = %d AND state = 'active'",
            $now,
            $now,
            $assetId
        ));
        return $res !== false && $res > 0;
    }

    public function deleteAssetStorage(int $assetId): bool
    {
        return $this->wpdb->delete($this->tableAssetStorage, ['asset_id' => $assetId], ['%d']) !== false;
    }

    private function storageColumns(): string
    {
        return 'asset_id, storage_provider, bucket, object_key, HEX(object_key_hash) AS object_key_hash, file_name, size_bytes, '
            . 'HEX(content_hash) AS content_hash, etag, state, created_by, created_at, updated_at, deleted_at';
    }

    /**
     * Check that an asset exists in wpe_assets; throw InvalidArgumentException if foreign (AC-T08-01).
     */
    private function assertAssetExists(int $assetId): void
    {
        $exists = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableAssets} WHERE id = %d",
            $assetId
        ));

        if ($exists === 0) {
            throw new InvalidArgumentException("Foreign asset error: asset ID {$assetId} does not exist in wpe_assets.");
        }
    }
}
