<?php

declare(strict_types=1);

namespace WPE\Application\Media;

use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

/**
 * The image library of one product (docs/04 §7).
 *
 * The library is the product's featured image plus its ordered gallery (`product_media`). Variation images
 * (`variant_media`) are chosen from it. The rules live here, not in the UI:
 *
 * - an asset can enter a library only when this user registered it for this product (upload or CDN URL) and it
 *   has not yet been linked, or when it is already linked;
 * - a variation can only be given an asset of the final library of the same request (an unchanged legacy
 *   variation image outside the library is kept as is);
 * - removing an image only removes link rows; an image in use as featured or variation image needs an explicit
 *   replacement or a confirmed fallback, otherwise the whole request is refused;
 * - everything is applied in one database transaction, guarded by a revision token of the current state.
 *
 * Writes use direct statements inside one transaction on purpose: the repository helpers open their own
 * transactions, which would commit the outer one.
 */
final class ProductMediaLibrary
{
    /** Gallery positions start at 1 like the existing data (featured uses 0). */
    private const GALLERY_START = 1;

    private \wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private string $tAssets;
    private string $tProductMedia;
    private string $tVariantMedia;
    private string $tVariants;

    public function __construct(?MediaRepository $media = null, ?UploadItemRepository $uploads = null, ?\wpdb $wpdb = null)
    {
        $this->wpdb          = $wpdb ?? $GLOBALS['wpdb'];
        $this->media         = $media ?? new MediaRepository($this->wpdb);
        $this->uploads       = $uploads ?? new UploadItemRepository($this->wpdb);
        $p                   = $this->wpdb->prefix;
        $this->tAssets       = $p . 'wpe_assets';
        $this->tProductMedia = $p . 'wpe_product_media';
        $this->tVariantMedia = $p . 'wpe_variant_media';
        $this->tVariants     = $p . 'wpe_variants';
    }

    /* ---------------------------------------------------------------------- */
    /* Reading                                                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * @return array{product_id:string,version:string,items:array<int,array<string,mixed>>,upload_limits:array}
     */
    public function read(int $productId, int $userId = 0): array
    {
        $state = $this->state($productId);
        $items = [];

        $ids = array_merge(
            $state['featured'] !== null ? [$state['featured']['asset_id']] : [],
            array_column($state['gallery'], 'asset_id'),
            $this->variantOnlyAssetIds($state)
        );
        $pending = $userId > 0 ? $this->pendingAssets($productId, $userId, $state) : [];
        $ids     = array_merge($ids, array_keys($pending));
        $assets  = $this->loadAssets($ids);
        $storage = $this->media->getAssetStorageMap($ids);
        $usedBy  = $this->usedByVariants($state);

        $build = function (int $assetId, string $role, int $position, ?string $altOverride) use ($assets, $storage, $usedBy): ?array {
            $asset = $assets[$assetId] ?? null;
            if ($asset === null) {
                return null;
            }
            $row = $storage[$assetId] ?? null;
            return [
                'asset_id'         => (string) $assetId,
                'role'             => $role,
                'position'         => $position,
                'origin'           => $row !== null ? 'managed' : 'external',
                'url'              => (string) $asset['source_url'],
                'file_name'        => $row['file_name'] ?? null,
                'mime'             => $asset['mime'] ?? null,
                'size_bytes'       => $row !== null ? (int) $row['size_bytes'] : null,
                'width'            => $asset['width'] !== null ? (int) $asset['width'] : null,
                'height'           => $asset['height'] !== null ? (int) $asset['height'] : null,
                'alt_text'         => (string) ($asset['alt_text'] ?? ''),
                'alt_override'     => $altOverride,
                'storage_provider' => $row['storage_provider'] ?? null,
                'health'           => (string) ($asset['health'] ?? 'healthy'),
                'used_by_variants' => array_map('strval', $usedBy[$assetId] ?? []),
            ];
        };

        if ($state['featured'] !== null) {
            $item = $build($state['featured']['asset_id'], 'featured', 0, $state['featured']['alt']);
            $item && $items[] = $item;
        }
        foreach ($state['gallery'] as $g) {
            $item = $build($g['asset_id'], 'gallery', $g['position'], $g['alt']);
            $item && $items[] = $item;
        }
        foreach ($this->variantOnlyAssetIds($state) as $assetId) {
            $item = $build($assetId, 'variant_only', 0, null);
            $item && $items[] = $item;
        }
        foreach ($pending as $assetId => $uploadItem) {
            $item = $build((int) $assetId, 'pending_link', 0, null);
            $item && $items[] = $item;
        }

        return [
            'product_id'    => (string) $productId,
            'version'       => $this->revision($state),
            'items'         => $items,
            'upload_limits' => ManagedUploadService::limits(),
        ];
    }

    /** Asset ids that are in the product library (featured and gallery). */
    public function libraryAssetIds(int $productId): array
    {
        $state = $this->state($productId);
        return $this->libraryIds($state);
    }

    /**
     * Refuse any asset that is not in the product library. Used for variation assignment and by apply.
     *
     * @param int[] $assetIds
     * @param int[] $alsoAllowed additional assets allowed for this call (for example linked in the same request)
     * @throws UploadRejectedException
     */
    public function assertInLibrary(int $productId, array $assetIds, array $alsoAllowed = [], string $pointer = '/asset_id'): void
    {
        $allowed = array_flip(array_merge($this->libraryAssetIds($productId), $alsoAllowed));
        $details = [];
        foreach (array_values($assetIds) as $i => $assetId) {
            if (!isset($allowed[(int) $assetId])) {
                $details[] = ['pointer' => $pointer === '/asset_id' ? $pointer : sprintf('%s/%d', $pointer, $i), 'reason' => 'asset_not_in_product', 'asset_id' => (string) (int) $assetId];
            }
        }
        if ($details !== []) {
            throw new UploadRejectedException('asset_not_in_product', 'The image is not in this product\'s library.', 422, false, $details);
        }
    }

    /**
     * Places that use the given assets, without writing anything.
     *
     * @param int[] $assetIds
     * @return array{affected:array<int,array<string,mixed>>,requires_resolution:bool}
     */
    public function previewUnlink(int $productId, array $assetIds): array
    {
        $state    = $this->state($productId);
        $affected = [];
        $requires = false;
        foreach (array_values(array_unique(array_map('intval', $assetIds))) as $assetId) {
            if ($state['featured'] !== null && $state['featured']['asset_id'] === $assetId) {
                $affected[] = ['asset_id' => (string) $assetId, 'place' => 'featured', 'variant_id' => null];
                $requires   = true;
            }
            foreach ($state['gallery'] as $g) {
                if ($g['asset_id'] === $assetId) {
                    $affected[] = ['asset_id' => (string) $assetId, 'place' => 'gallery', 'variant_id' => null];
                }
            }
            foreach ($state['variants'] as $variantId => $rows) {
                foreach ($rows as $row) {
                    if ($row['asset_id'] === $assetId) {
                        $affected[] = ['asset_id' => (string) $assetId, 'place' => 'variant', 'variant_id' => (string) $variantId];
                        $requires   = true;
                        break;
                    }
                }
            }
        }
        return ['affected' => $affected, 'requires_resolution' => $requires];
    }

    /* ---------------------------------------------------------------------- */
    /* Writing                                                                 */
    /* ---------------------------------------------------------------------- */

    /**
     * Apply a media patch atomically.
     *
     * @param array<string,mixed> $patch featured_asset_id, gallery, variant_media, alt_overrides, unlink, collection_mode
     * @return array{changed:bool,library:array} the new library state
     * @throws UploadRejectedException
     */
    public function apply(int $productId, array $patch, string $expectedVersion, int $userId): array
    {
        $this->assertPatchShape($patch);

        $this->wpdb->query('START TRANSACTION');
        try {
            // Lock the product's media rows so two saves cannot interleave.
            $this->wpdb->get_results($this->wpdb->prepare("SELECT asset_id FROM {$this->tProductMedia} WHERE product_id = %d FOR UPDATE", $productId));
            $state = $this->state($productId);
            if ($expectedVersion === '' || !hash_equals($this->revision($state), $expectedVersion)) {
                throw new UploadRejectedException('stale_version', 'The product images changed since they were loaded. Reload and retry.', 412);
            }

            $pending  = array_keys($this->pendingAssets($productId, $userId, $state));
            $final    = $this->computeFinalLibrary($state, $patch, $pending);
            $variants = $this->computeVariantMedia($productId, $state, $final, $patch);
            $this->assertImpactResolved($state, $final, $variants, $patch);

            $before = $this->revision($state);
            $this->writeProductMedia($productId, $final);
            $this->writeVariantMedia($productId, $state, $variants);
            $this->retireUploadIntents($productId, $final['removed']);
            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }

        $library = $this->read($productId, $userId);
        return ['changed' => $library['version'] !== $before, 'library' => $library];
    }

    /* ---------------------------------------------------------------------- */
    /* Patch computation                                                       */
    /* ---------------------------------------------------------------------- */

    private function assertPatchShape(array $patch): void
    {
        $mode = $patch['collection_mode'] ?? null;
        if (!in_array($mode, ['merge', 'replace'], true)) {
            throw new UploadRejectedException('invalid_request', 'collection_mode must be merge or replace.', 400);
        }
        foreach (['gallery', 'variant_media', 'alt_overrides', 'unlink'] as $key) {
            if (isset($patch[$key]) && !is_array($patch[$key])) {
                throw new UploadRejectedException('invalid_request', "{$key} must be a list.", 400);
            }
        }
    }

    /**
     * Final featured image and gallery.
     *
     * @return array{featured:?array{asset_id:int,alt:?string},gallery:array<int,array{asset_id:int,alt:?string}>,removed:int[]}
     */
    private function computeFinalLibrary(array $state, array $patch, array $pending): array
    {
        $current    = $this->libraryIds($state);
        $linkable   = array_flip(array_merge($current, $pending));
        $mode       = $patch['collection_mode'];
        $featured   = $state['featured'] !== null ? ['asset_id' => $state['featured']['asset_id'], 'alt' => $state['featured']['alt']] : null;
        $gallery    = array_map(static fn ($g) => ['asset_id' => $g['asset_id'], 'alt' => $g['alt']], $state['gallery']);
        $oldFeatured = $featured;

        $require = function (int $assetId, string $pointer) use ($linkable): void {
            if ($assetId <= 0 || !isset($linkable[$assetId])) {
                throw new UploadRejectedException('asset_not_in_product', 'The image is not in this product\'s library and was not added for it.', 422, false, [
                    ['pointer' => $pointer, 'reason' => 'asset_not_in_product', 'asset_id' => (string) $assetId],
                ]);
            }
        };

        if (array_key_exists('gallery', $patch)) {
            if ($mode === 'replace') {
                $gallery = [];
                $entries = $this->sortedEntries((array) $patch['gallery']);
                foreach ($entries as $i => $entry) {
                    $assetId = (int) ($entry['asset_id'] ?? 0);
                    $require($assetId, "/gallery/{$i}/asset_id");
                    $gallery[] = ['asset_id' => $assetId, 'alt' => $this->altOrNull($entry, $this->currentAlt($state, $assetId))];
                }
            } else {
                $known = array_column($gallery, 'asset_id');
                foreach ((array) $patch['gallery'] as $i => $entry) {
                    $assetId = (int) ($entry['asset_id'] ?? 0);
                    $require($assetId, "/gallery/{$i}/asset_id");
                    $idx = array_search($assetId, $known, true);
                    if ($idx !== false) {
                        $gallery[$idx]['alt'] = $this->altOrNull($entry, $gallery[$idx]['alt']);
                    } elseif ($featured !== null && $featured['asset_id'] === $assetId) {
                        $featured['alt'] = $this->altOrNull($entry, $featured['alt']);
                    } else {
                        $gallery[] = ['asset_id' => $assetId, 'alt' => $this->altOrNull($entry, null)];
                        $known[]   = $assetId;
                    }
                }
            }
        }

        if (array_key_exists('featured_asset_id', $patch)) {
            $newId = $patch['featured_asset_id'] === null ? null : (int) $patch['featured_asset_id'];
            if ($newId === null) {
                $featured = null;
            } else {
                $require($newId, '/featured_asset_id');
                if ($featured === null || $featured['asset_id'] !== $newId) {
                    $alt = null;
                    foreach ($gallery as $g) {
                        if ($g['asset_id'] === $newId) {
                            $alt = $g['alt'];
                        }
                    }
                    $featured = ['asset_id' => $newId, 'alt' => $alt ?? $this->currentAlt($state, $newId)];
                }
            }
            // The featured asset is never also a gallery item.
            $gallery = array_values(array_filter($gallery, static fn ($g) => $featured === null || $g['asset_id'] !== $featured['asset_id']));
            // A replaced featured image is kept as the first gallery item unless the gallery list was given.
            if (!array_key_exists('gallery', $patch) && $oldFeatured !== null && ($featured === null || $featured['asset_id'] !== $oldFeatured['asset_id'])) {
                array_unshift($gallery, $oldFeatured);
            }
        }

        // Explicit unlink entries remove the asset from the library.
        $unlinkIds = [];
        foreach ((array) ($patch['unlink'] ?? []) as $i => $entry) {
            $assetId = (int) ($entry['asset_id'] ?? 0);
            if ($assetId <= 0) {
                throw new UploadRejectedException('invalid_request', 'unlink needs an asset id.', 400, false, [['pointer' => "/unlink/{$i}/asset_id", 'reason' => 'invalid']]);
            }
            $unlinkIds[$assetId] = $entry;
        }
        foreach ($unlinkIds as $assetId => $entry) {
            if ($featured !== null && $featured['asset_id'] === $assetId) {
                $replacement = isset($entry['replacement_asset_id']) && $entry['replacement_asset_id'] !== null ? (int) $entry['replacement_asset_id'] : null;
                if ($replacement !== null && $replacement !== $assetId) {
                    $require($replacement, '/unlink/featured/replacement_asset_id');
                    $alt = $this->currentAlt($state, $replacement);
                    foreach ($gallery as $g) {
                        if ($g['asset_id'] === $replacement) {
                            $alt = $g['alt'];
                        }
                    }
                    $featured = ['asset_id' => $replacement, 'alt' => $alt];
                } else {
                    $featured = null;
                }
            }
            $gallery = array_values(array_filter($gallery, static fn ($g) => $g['asset_id'] !== $assetId && ($featured === null || $g['asset_id'] !== $featured['asset_id'])));
        }

        foreach ((array) ($patch['alt_overrides'] ?? []) as $i => $entry) {
            $assetId = (int) ($entry['asset_id'] ?? 0);
            $alt     = array_key_exists('alt_override', $entry) ? ($entry['alt_override'] === null ? null : sanitize_text_field((string) $entry['alt_override'])) : null;
            $hit     = false;
            if ($featured !== null && $featured['asset_id'] === $assetId) {
                $featured['alt'] = $alt;
                $hit = true;
            }
            foreach ($gallery as $k => $g) {
                if ($g['asset_id'] === $assetId) {
                    $gallery[$k]['alt'] = $alt;
                    $hit = true;
                }
            }
            if (!$hit) {
                throw new UploadRejectedException('asset_not_in_product', 'Alt text can only be set on images of this product.', 422, false, [
                    ['pointer' => "/alt_overrides/{$i}/asset_id", 'reason' => 'asset_not_in_product', 'asset_id' => (string) $assetId],
                ]);
            }
        }

        // No duplicates.
        $seen = $featured !== null ? [$featured['asset_id'] => true] : [];
        foreach ($gallery as $g) {
            if (isset($seen[$g['asset_id']])) {
                throw new UploadRejectedException('invalid_request', 'An image appears more than once.', 400, false, [['pointer' => '/gallery', 'reason' => 'duplicate_asset', 'asset_id' => (string) $g['asset_id']]]);
            }
            $seen[$g['asset_id']] = true;
        }

        $finalIds = array_keys($seen);
        return [
            'featured' => $featured,
            'gallery'  => $gallery,
            'removed'  => array_values(array_diff($current, $finalIds)),
            'unlink'   => $unlinkIds,
        ];
    }

    /**
     * Final variation media: current rows, then explicit variation entries, then unlink replacements.
     *
     * @return array<int,array<int,array{asset_id:int,is_primary:int,alt:?string}>> variant id => rows (primary first)
     */
    private function computeVariantMedia(int $productId, array $state, array $final, array $patch): array
    {
        $result = [];
        foreach ($state['variants'] as $variantId => $rows) {
            $result[$variantId] = array_map(static fn ($r) => ['asset_id' => $r['asset_id'], 'is_primary' => $r['is_primary'], 'alt' => $r['alt']], $rows);
        }
        $owned      = array_flip($state['variant_ids']);
        $libraryNow = array_flip(array_merge(
            $final['featured'] !== null ? [$final['featured']['asset_id']] : [],
            array_column($final['gallery'], 'asset_id')
        ));

        foreach ((array) ($patch['variant_media'] ?? []) as $i => $entry) {
            $variantId = (int) ($entry['variant_id'] ?? 0);
            if (!isset($owned[$variantId])) {
                throw new UploadRejectedException('validation_failed', 'The variation does not belong to this product.', 422, false, [
                    ['pointer' => "/variant_media/{$i}/variant_id", 'reason' => 'wrong_product'],
                ]);
            }
            $existing = array_column($state['variants'][$variantId] ?? [], 'asset_id');
            $assets   = array_values(array_unique(array_map('intval', (array) ($entry['assets'] ?? []))));
            $primary  = isset($entry['primary_asset_id']) && $entry['primary_asset_id'] !== null ? (int) $entry['primary_asset_id'] : null;
            if ($primary !== null && !in_array($primary, $assets, true)) {
                $assets = array_merge([$primary], $assets);
            }
            $details = [];
            foreach ($assets as $k => $assetId) {
                // A legacy image the variation already had stays allowed; a new one must be in the library.
                if (!isset($libraryNow[$assetId]) && !in_array($assetId, $existing, true)) {
                    $details[] = ['pointer' => "/variant_media/{$i}/assets/{$k}", 'reason' => 'asset_not_in_product', 'asset_id' => (string) $assetId];
                }
            }
            if ($details !== []) {
                throw new UploadRejectedException('asset_not_in_product', 'A variation image must come from this product\'s library.', 422, false, $details);
            }
            $rows = [];
            foreach ($assets as $assetId) {
                $keep = null;
                foreach ($state['variants'][$variantId] ?? [] as $old) {
                    if ($old['asset_id'] === $assetId) {
                        $keep = $old['alt'];
                    }
                }
                $rows[] = ['asset_id' => $assetId, 'is_primary' => $assetId === $primary ? 1 : 0, 'alt' => $keep];
            }
            usort($rows, static fn ($a, $b) => $b['is_primary'] <=> $a['is_primary']);
            $result[$variantId] = $rows;
        }

        // Unlink: variations that still use the asset get the replacement or fall back to the product image.
        foreach ($final['unlink'] as $assetId => $entry) {
            $replacement = isset($entry['replacement_asset_id']) && $entry['replacement_asset_id'] !== null ? (int) $entry['replacement_asset_id'] : null;
            $fallback    = !empty($entry['fallback']);
            foreach ($result as $variantId => $rows) {
                $uses = false;
                foreach ($rows as $r) {
                    if ($r['asset_id'] === $assetId) {
                        $uses = true;
                    }
                }
                if (!$uses) {
                    continue;
                }
                if ($replacement !== null && $replacement !== $assetId) {
                    if (!isset($libraryNow[$replacement])) {
                        throw new UploadRejectedException('asset_not_in_product', 'The replacement image is not in this product\'s library.', 422, false, [
                            ['pointer' => '/unlink/replacement_asset_id', 'reason' => 'asset_not_in_product', 'asset_id' => (string) $replacement],
                        ]);
                    }
                    $out = [];
                    $has = false;
                    foreach ($rows as $r) {
                        if ($r['asset_id'] === $assetId) {
                            if (!$has) {
                                $out[] = ['asset_id' => $replacement, 'is_primary' => $r['is_primary'], 'alt' => null];
                                $has   = true;
                            }
                            continue;
                        }
                        if ($r['asset_id'] === $replacement) {
                            continue;
                        }
                        $out[] = $r;
                    }
                    $result[$variantId] = $out;
                } elseif ($fallback) {
                    $result[$variantId] = array_values(array_filter($rows, static fn ($r) => $r['asset_id'] !== $assetId));
                }
            }
        }

        return $result;
    }

    /**
     * Any image removed from the library that is still used as featured or variation image must have been
     * resolved by this request; otherwise refuse with the list of affected places.
     */
    private function assertImpactResolved(array $state, array $final, array $variants, array $patch): void
    {
        $affected = [];
        $removed  = array_flip($final['removed']);
        foreach (array_keys($removed) as $assetId) {
            $entry = $final['unlink'][$assetId] ?? null;

            if ($state['featured'] !== null && $state['featured']['asset_id'] === $assetId) {
                $replacedExplicitly = $final['featured'] !== null && $final['featured']['asset_id'] !== $assetId;
                $confirmed          = $entry !== null && (!empty($entry['fallback']) || !empty($entry['replacement_asset_id']));
                if (!$replacedExplicitly && !$confirmed) {
                    $affected[] = ['asset_id' => (string) $assetId, 'place' => 'featured', 'variant_id' => null];
                }
            }
            foreach ($variants as $variantId => $rows) {
                foreach ($rows as $r) {
                    if ($r['asset_id'] === $assetId) {
                        $affected[] = ['asset_id' => (string) $assetId, 'place' => 'variant', 'variant_id' => (string) $variantId];
                        break;
                    }
                }
            }
        }
        if ($affected !== []) {
            throw new UploadRejectedException(
                'impact_requires_resolution',
                'Some images being removed are still in use. Choose a replacement image or confirm the fallback.',
                409,
                false,
                array_map(static fn ($a) => $a + ['reason' => 'impact_requires_resolution'], $affected)
            );
        }
    }

    private function writeProductMedia(int $productId, array $final): void
    {
        $this->exec($this->wpdb->prepare("DELETE FROM {$this->tProductMedia} WHERE product_id = %d AND role IN ('featured','gallery')", $productId));

        if ($final['featured'] !== null) {
            $this->insertProductMedia($productId, $final['featured']['asset_id'], 'featured', 0, $final['featured']['alt']);
        }
        $pos = self::GALLERY_START;
        foreach ($final['gallery'] as $g) {
            $this->insertProductMedia($productId, $g['asset_id'], 'gallery', $pos++, $g['alt']);
        }
    }

    private function insertProductMedia(int $productId, int $assetId, string $role, int $position, ?string $alt): void
    {
        $altSql = $alt === null ? 'NULL' : $this->wpdb->prepare('%s', $alt);
        $this->exec($this->wpdb->prepare(
            "INSERT INTO {$this->tProductMedia} (product_id, asset_id, role, position, alt_override) VALUES (%d, %d, %s, %d, {$altSql})",
            $productId,
            $assetId,
            $role,
            $position
        ));
    }

    private function writeVariantMedia(int $productId, array $state, array $variants): void
    {
        foreach ($variants as $variantId => $rows) {
            $current = array_map(static fn ($r) => [$r['asset_id'], $r['is_primary'], $r['alt']], $state['variants'][$variantId] ?? []);
            $target  = array_map(static fn ($r) => [$r['asset_id'], $r['is_primary'], $r['alt']], $rows);
            if ($current === $target) {
                continue;
            }
            $this->exec($this->wpdb->prepare("DELETE FROM {$this->tVariantMedia} WHERE variant_id = %d", $variantId));
            $pos = 0;
            foreach ($rows as $r) {
                $altSql = $r['alt'] === null ? 'NULL' : $this->wpdb->prepare('%s', $r['alt']);
                $this->exec($this->wpdb->prepare(
                    "INSERT INTO {$this->tVariantMedia} (variant_id, asset_id, position, is_primary, alt_override) VALUES (%d, %d, %d, %d, {$altSql})",
                    $variantId,
                    $r['asset_id'],
                    $pos++,
                    $r['is_primary']
                ));
            }
        }
    }

    /**
     * An image the user removed must not come back as a pending upload: end the retention of its intent.
     * The file and the asset record stay untouched.
     *
     * @param int[] $assetIds
     */
    private function retireUploadIntents(int $productId, array $assetIds): void
    {
        if ($assetIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($assetIds), '%d'));
        $this->exec($this->wpdb->prepare(
            "UPDATE {$this->uploads->tableName()} SET expires_at = %s WHERE product_id = %d AND asset_id IN ({$in})",
            gmdate('Y-m-d H:i:s.u'),
            $productId,
            ...array_map('intval', $assetIds)
        ));
    }

    private function exec(string $sql): void
    {
        if ($this->wpdb->query($sql) === false) {
            throw new \RuntimeException('Media write failed: ' . $this->wpdb->last_error);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* State                                                                   */
    /* ---------------------------------------------------------------------- */

    /**
     * @return array{featured:?array{asset_id:int,alt:?string},gallery:array<int,array{asset_id:int,position:int,alt:?string}>,variant_ids:int[],variants:array<int,array<int,array{asset_id:int,position:int,is_primary:int,alt:?string}>>}
     */
    private function state(int $productId): array
    {
        $featured = null;
        $gallery  = [];
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT asset_id, role, position, alt_override FROM {$this->tProductMedia}
              WHERE product_id = %d AND role IN ('featured','gallery') ORDER BY role ASC, position ASC, asset_id ASC",
            $productId
        ), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            if ($r['role'] === 'featured' && $featured === null) {
                $featured = ['asset_id' => (int) $r['asset_id'], 'alt' => $r['alt_override']];
            } elseif ($r['role'] === 'gallery') {
                $gallery[] = ['asset_id' => (int) $r['asset_id'], 'position' => (int) $r['position'], 'alt' => $r['alt_override']];
            }
        }

        $variantIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->tVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC",
            $productId
        )) ?: []);

        $variants = [];
        if ($variantIds !== []) {
            $in = implode(',', array_fill(0, count($variantIds), '%d'));
            $vrows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT variant_id, asset_id, position, is_primary, alt_override FROM {$this->tVariantMedia}
                  WHERE variant_id IN ({$in}) ORDER BY variant_id ASC, is_primary DESC, position ASC, asset_id ASC",
                ...$variantIds
            ), ARRAY_A) ?: [];
            foreach ($vrows as $r) {
                $variants[(int) $r['variant_id']][] = [
                    'asset_id'   => (int) $r['asset_id'],
                    'position'   => (int) $r['position'],
                    'is_primary' => (int) $r['is_primary'],
                    'alt'        => $r['alt_override'],
                ];
            }
        }

        return ['featured' => $featured, 'gallery' => $gallery, 'variant_ids' => $variantIds, 'variants' => $variants];
    }

    private function libraryIds(array $state): array
    {
        return array_values(array_merge(
            $state['featured'] !== null ? [$state['featured']['asset_id']] : [],
            array_column($state['gallery'], 'asset_id')
        ));
    }

    private function variantOnlyAssetIds(array $state): array
    {
        $lib = array_flip($this->libraryIds($state));
        $out = [];
        foreach ($state['variants'] as $rows) {
            foreach ($rows as $r) {
                if (!isset($lib[$r['asset_id']])) {
                    $out[$r['asset_id']] = $r['asset_id'];
                }
            }
        }
        return array_values($out);
    }

    /** @return array<int,int[]> asset id => variant ids that use it */
    private function usedByVariants(array $state): array
    {
        $out = [];
        foreach ($state['variants'] as $variantId => $rows) {
            foreach ($rows as $r) {
                $out[$r['asset_id']][] = $variantId;
            }
        }
        return $out;
    }

    /**
     * Registered, not yet linked uploads of this user for this product that are still within retention.
     *
     * @return array<int,array> asset id => upload item
     */
    private function pendingAssets(int $productId, int $userId, array $state): array
    {
        $lib     = array_flip($this->libraryIds($state));
        $now     = gmdate('Y-m-d H:i:s.u');
        $pending = [];
        foreach ($this->uploads->listRegisteredForProduct($productId, $userId) as $item) {
            $assetId = (int) $item['asset_id'];
            if ($assetId > 0 && !isset($lib[$assetId]) && (string) $item['expires_at'] > $now) {
                $pending[$assetId] = $item;
            }
        }
        return $pending;
    }

    private function revision(array $state): string
    {
        $canon = [
            'f' => $state['featured'],
            'g' => array_map(static fn ($g) => [$g['asset_id'], $g['alt']], $state['gallery']),
            'v' => array_map(static fn ($rows) => array_map(static fn ($r) => [$r['asset_id'], $r['is_primary'], $r['alt']], $rows), $state['variants']),
        ];
        ksort($canon['v']);
        return substr(hash('sha256', (string) wp_json_encode($canon)), 0, 32);
    }

    /** @return array<int,array<string,mixed>> */
    private function loadAssets(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, source_url, mime, width, height, alt_text, health FROM {$this->tAssets} WHERE id IN ({$in})",
            ...$ids
        ), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    private function currentAlt(array $state, int $assetId): ?string
    {
        if ($state['featured'] !== null && $state['featured']['asset_id'] === $assetId) {
            return $state['featured']['alt'];
        }
        foreach ($state['gallery'] as $g) {
            if ($g['asset_id'] === $assetId) {
                return $g['alt'];
            }
        }
        return null;
    }

    private function altOrNull(array $entry, ?string $default): ?string
    {
        if (!array_key_exists('alt_override', $entry)) {
            return $default;
        }
        return $entry['alt_override'] === null ? null : sanitize_text_field((string) $entry['alt_override']);
    }

    /** Entries ordered by their position field (stable), entries without position keep their order. */
    private function sortedEntries(array $entries): array
    {
        $indexed = [];
        foreach (array_values($entries) as $i => $e) {
            $indexed[] = [isset($e['position']) ? (int) $e['position'] : PHP_INT_MAX, $i, $e];
        }
        usort($indexed, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(static fn ($x) => $x[2], $indexed);
    }
}
