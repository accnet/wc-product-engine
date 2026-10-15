<?php

declare(strict_types=1);

namespace WPE\Application\Media;

use WPE\Application\Media\Storage\StorageException;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

/**
 * Explicit managed-file deletion and stale upload intent cleanup (docs/02 §10, docs/03 `asset_storage`, docs/04 §7).
 *
 * Rules:
 * - Unlinking an image from a product only removes link rows; deleting the physical object is an explicit operation.
 * - Only managed assets (`wpe_asset_storage` row present) can have their file deleted; external URLs are refused.
 * - Deletion is refused whenever any product media link, variant media link, unexpired upload retention hold,
 *   or another active asset storage row still references the asset/object.
 * - Stale stored upload intents (`state = 'stored'`, `asset_id IS NULL`, older than retention cutoff) are cleaned
 *   only after verifying no `wpe_asset_storage` row owns the same stored object.
 */
final class OrphanCleanup
{
    private \wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private StorageResolver $resolver;

    private string $tAssets;
    private string $tAssetStorage;
    private string $tProductMedia;
    private string $tVariantMedia;
    private string $tVariants;
    private string $tUploadItems;

    public function __construct(
        ?MediaRepository $media = null,
        ?UploadItemRepository $uploads = null,
        ?StorageResolver $resolver = null,
        ?\wpdb $wpdb = null
    ) {
        $this->wpdb     = $wpdb ?? $GLOBALS['wpdb'];
        $this->media    = $media ?? new MediaRepository($this->wpdb);
        $this->uploads  = $uploads ?? new UploadItemRepository($this->wpdb);
        $this->resolver = $resolver ?? new StorageResolver();

        $p = $this->wpdb->prefix;
        $this->tAssets       = $p . 'wpe_assets';
        $this->tAssetStorage = $p . 'wpe_asset_storage';
        $this->tProductMedia = $p . 'wpe_product_media';
        $this->tVariantMedia = $p . 'wpe_variant_media';
        $this->tVariants     = $p . 'wpe_variants';
        $this->tUploadItems  = $p . 'wpe_upload_items';
    }

    /**
     * All places that currently reference an asset and block deleting its file.
     *
     * @return array<int,array<string,mixed>>
     */
    public function blockingReferences(int $assetId): array
    {
        $refs = [];

        $pmRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT product_id, role FROM {$this->tProductMedia} WHERE asset_id = %d ORDER BY product_id ASC, role ASC",
            $assetId
        ), ARRAY_A) ?: [];
        foreach ($pmRows as $r) {
            $refs[] = [
                'pointer'    => '/product_media/' . (int) $r['product_id'] . '/' . (string) $r['role'],
                'reason'     => 'asset_in_use',
                'asset_id'   => (string) $assetId,
                'place'      => (string) $r['role'],
                'product_id' => (string) (int) $r['product_id'],
            ];
        }

        $vmRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT vm.variant_id, v.product_id
               FROM {$this->tVariantMedia} vm
               LEFT JOIN {$this->tVariants} v ON v.id = vm.variant_id
              WHERE vm.asset_id = %d
              ORDER BY vm.variant_id ASC",
            $assetId
        ), ARRAY_A) ?: [];
        foreach ($vmRows as $r) {
            $refs[] = [
                'pointer'    => '/variant_media/' . (int) $r['variant_id'],
                'reason'     => 'asset_in_use',
                'asset_id'   => (string) $assetId,
                'place'      => 'variant',
                'variant_id' => (string) (int) $r['variant_id'],
                'product_id' => isset($r['product_id']) ? (string) (int) $r['product_id'] : null,
            ];
        }

        if (UploadItemRepository::ensureSchema($this->wpdb)) {
            $now = gmdate('Y-m-d H:i:s.u');
            $holds = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, product_id, session_id FROM {$this->tUploadItems}
                  WHERE asset_id = %d AND state = %s AND expires_at > %s
                  ORDER BY id ASC",
                $assetId,
                UploadItemRepository::STATE_REGISTERED,
                $now
            ), ARRAY_A) ?: [];
            foreach ($holds as $h) {
                $refs[] = [
                    'pointer'    => '/upload_items/' . (int) $h['id'],
                    'reason'     => 'asset_in_use',
                    'asset_id'   => (string) $assetId,
                    'place'      => 'retention_hold',
                    'product_id' => $h['product_id'] !== null ? (string) (int) $h['product_id'] : null,
                ];
            }
        }

        return $refs;
    }

    /**
     * Delete the underlying storage object for a managed asset when no references remain.
     *
     * @return array{asset_id:string,deleted:bool,storage_provider:?string}
     * @throws UploadRejectedException|StorageException
     */
    public function deleteFile(int $assetId): array
    {
        if (!is_user_logged_in() || !current_user_can('upload_files') || (!current_user_can('delete_posts') && !current_user_can('delete_products'))) {
            throw new UploadRejectedException('forbidden', 'You are not allowed to delete managed files.', 403);
        }

        $asset = $this->media->getAssetById($assetId);
        if ($asset === null) {
            throw new UploadRejectedException('not_found', 'The asset does not exist.', 404);
        }

        $storage = $this->media->getAssetStorage($assetId);
        if ($storage === null) {
            throw new UploadRejectedException(
                'asset_external',
                'External images are not managed files and cannot be deleted from storage.',
                422,
                false,
                [['pointer' => '/asset_id', 'reason' => 'asset_external', 'asset_id' => (string) $assetId]]
            );
        }

        $blocking = $this->blockingReferences($assetId);
        if ($blocking !== []) {
            throw new UploadRejectedException(
                'asset_in_use',
                'The image is still referenced and its file cannot be deleted.',
                409,
                false,
                $blocking
            );
        }

        if (($storage['state'] ?? '') === 'deleted') {
            return [
                'asset_id'         => (string) $assetId,
                'deleted'          => true,
                'storage_provider' => (string) $storage['storage_provider'],
            ];
        }

        $providerName = (string) $storage['storage_provider'];
        $bucket       = (string) $storage['bucket'];
        $objectKey    = (string) $storage['object_key'];

        $provider = $this->resolver->forStored($providerName, $bucket);
        if (!$provider->delete($objectKey)) {
            throw new StorageException('The storage provider could not delete the file.', 'delete_failed');
        }

        $this->media->markAssetStorageDeleted($assetId);

        // Also delete the compatibility WP attachment shell if one was created for this asset and is unused.
        if (!empty($asset['attachment_id'])) {
            $attId = (int) $asset['attachment_id'];
            if ($attId > 0 && get_post_type($attId) === 'attachment') {
                wp_delete_post($attId, true);
                $this->wpdb->update($this->tAssets, ['attachment_id' => null], ['id' => $assetId], ['%s'], ['%d']);
            }
        }

        return [
            'asset_id'         => (string) $assetId,
            'deleted'          => true,
            'storage_provider' => $providerName,
        ];
    }

    /**
     * Clean stale upload items that stored an object but never registered an asset before the cutoff.
     *
     * @return array{scanned:int,cleaned:int,kept_referenced:int,errors:int}
     */
    public function cleanStaleUploads(?string $olderThan = null, int $limit = 50): array
    {
        if (!UploadItemRepository::ensureSchema($this->wpdb)) {
            return ['scanned' => 0, 'cleaned' => 0, 'kept_referenced' => 0, 'errors' => 0];
        }

        $cutoff = $olderThan ?? gmdate('Y-m-d H:i:s.u', time() - UploadItemRepository::DEFAULT_TTL_SECONDS);
        $items  = $this->uploads->listStaleStored($cutoff, $limit);

        $scanned        = count($items);
        $cleaned        = 0;
        $keptReferenced = 0;
        $errors         = 0;

        foreach ($items as $item) {
            $itemId       = (int) $item['id'];
            $providerName = (string) ($item['storage_provider'] ?? '');
            $bucket       = (string) ($item['bucket'] ?? '');
            $objectKey    = (string) ($item['object_key'] ?? '');

            if ($providerName === '' || $objectKey === '') {
                $this->uploads->markCleaned($itemId);
                $cleaned++;
                continue;
            }

            // Never delete the object if an asset_storage row now owns it.
            $owned = $this->media->findAssetStorageByObject($providerName, $bucket, $objectKey);
            if ($owned !== null && ($owned['state'] ?? '') === 'active') {
                $this->uploads->markCleaned($itemId);
                $keptReferenced++;
                continue;
            }

            try {
                $provider = $this->resolver->forStored($providerName, $bucket);
                if ($provider->delete($objectKey)) {
                    $this->uploads->markCleaned($itemId);
                    $cleaned++;
                } else {
                    $errors++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return [
            'scanned'         => $scanned,
            'cleaned'         => $cleaned,
            'kept_referenced' => $keptReferenced,
            'errors'          => $errors,
        ];
    }

    /**
     * Summary report of media assets, managed storage objects and upload intents for CLI / Operations.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        UploadItemRepository::ensureSchema($this->wpdb);

        $totalAssets   = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tAssets}");
        $activeManaged = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tAssetStorage} WHERE state = 'active'");
        $deletedManaged = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tAssetStorage} WHERE state = 'deleted'");
        $externalAssets = max(0, $totalAssets - $activeManaged - $deletedManaged);

        $byProviderRows = $this->wpdb->get_results(
            "SELECT storage_provider, state, COUNT(*) AS cnt, COALESCE(SUM(size_bytes), 0) AS total_bytes
               FROM {$this->tAssetStorage}
              GROUP BY storage_provider, state
              ORDER BY storage_provider ASC, state ASC",
            ARRAY_A
        ) ?: [];

        $uploadStates = [];
        $stateRows = $this->wpdb->get_results(
            "SELECT state, COUNT(*) AS cnt FROM {$this->tUploadItems} GROUP BY state ORDER BY state ASC",
            ARRAY_A
        ) ?: [];
        foreach ($stateRows as $r) {
            $uploadStates[(string) $r['state']] = (int) $r['cnt'];
        }

        $cutoff = gmdate('Y-m-d H:i:s.u', time() - UploadItemRepository::DEFAULT_TTL_SECONDS);
        $staleStored = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tUploadItems} WHERE state = %s AND asset_id IS NULL AND updated_at < %s",
            UploadItemRepository::STATE_STORED,
            $cutoff
        ));

        return [
            'assets' => [
                'total'           => $totalAssets,
                'managed_active'  => $activeManaged,
                'managed_deleted' => $deletedManaged,
                'external'        => $externalAssets,
            ],
            'storage_by_provider' => $byProviderRows,
            'upload_items'        => $uploadStates,
            'stale_stored_count'  => $staleStored,
        ];
    }
}
