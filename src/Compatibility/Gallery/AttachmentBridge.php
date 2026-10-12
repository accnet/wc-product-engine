<?php

namespace WPE\Compatibility\Gallery;

use RuntimeException;
use InvalidArgumentException;
use WP_Error;
use WP_Post;
use WPE\Infrastructure\Database\MediaRepository;

/**
 * WordPress Real Attachment Bridge for External CDN Images.
 *
 * Provides real WordPress attachment post IDs on demand (03 §3, 10 §3),
 * transparently hooks WordPress image filters (wp_get_attachment_url, image_downsize,
 * wp_calculate_image_srcset, wp_prepare_attachment_for_js) (R88),
 * enforces concurrency/race safety so multiple concurrent calls share a single attachment (R87),
 * explicitly blocks unsupported local file actions like crop/edit without inventing fake paths (R89),
 * and safeguards shared assets against deletion when referenced by products (R90, AC-T22-02).
 */
class AttachmentBridge
{
    private static ?AttachmentBridge $instance = null;
    private static bool $filtersRegistered = false;

    private \wpdb $wpdb;
    private AssetService $assetService;
    private MediaRepository $mediaRepo;

    private string $tableAssets;
    private string $tableProductMedia;
    private string $tableVariantMedia;

    /** @var array<int, int> Cache of attachment_id => asset_id */
    private array $attachmentToAsset = [];

    /** @var array<int, array> Cache of asset_id => asset_data */
    private array $assetCache = [];

    /** @var array<string> Audit log of warnings and rebind policies */
    private array $auditLog = [];

    public function __construct(
        ?\wpdb $wpdb = null,
        ?AssetService $assetService = null,
        ?MediaRepository $mediaRepo = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->mediaRepo = $mediaRepo ?? new MediaRepository($this->wpdb);
        $this->assetService = $assetService ?? new AssetService($this->wpdb, $this->mediaRepo);

        $prefix = $this->wpdb->prefix;
        $this->tableAssets       = $prefix . 'wpe_assets';
        $this->tableProductMedia = $prefix . 'wpe_product_media';
        $this->tableVariantMedia = $prefix . 'wpe_variant_media';
    }

    public static function instance(?\wpdb $wpdb = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($wpdb);
        }
        return self::$instance;
    }

    /**
     * Register WordPress filters and actions.
     */
    public static function register(?AttachmentBridge $instance = null): self
    {
        if ($instance !== null) {
            self::$instance = $instance;
        }

        $bridge = self::instance();

        if (self::$filtersRegistered) {
            return $bridge;
        }

        // Image URL and sizing filters (R88)
        add_filter('wp_get_attachment_url', function ($url, $id) {
            return self::instance()->filterAttachmentUrl($url, $id);
        }, 10, 2);
        add_filter('image_downsize', function ($out, $id, $size) {
            return self::instance()->filterImageDownsize($out, $id, $size);
        }, 10, 3);
        add_filter('wp_get_attachment_metadata', function ($data, $id) {
            return self::instance()->filterAttachmentMetadata($data, $id);
        }, 10, 2);
        add_filter('wp_calculate_image_srcset', function ($sources, $size_array, $image_src, $image_meta, $attachment_id) {
            return self::instance()->filterCalculateImageSrcset($sources, $size_array, $image_src, $image_meta, $attachment_id);
        }, 10, 5);
        add_filter('wp_calculate_image_sizes', function ($sizes, $size, $image_src, $image_meta, $attachment_id) {
            return self::instance()->filterCalculateImageSizes($sizes, $size, $image_src, $image_meta, $attachment_id);
        }, 10, 5);

        // Media Library modal and REST preview (AC-T22-01)
        add_filter('wp_prepare_attachment_for_js', function ($response, $attachment, $meta) {
            return self::instance()->filterPrepareAttachmentForJs($response, $attachment, $meta);
        }, 10, 3);

        // Local file actions guard (R89)
        add_filter('get_attached_file', function ($file, $attachment_id) {
            return self::instance()->filterGetAttachedFile($file, $attachment_id);
        }, 10, 2);

        // Shared deletion safeguards (R90, AC-T22-02)
        add_action('delete_attachment', function ($post_id, $post) {
            self::instance()->onDeleteAttachment($post_id, $post);
        }, 10, 2);

        self::$filtersRegistered = true;
        return $bridge;
    }

    /* -------------------------------------------------------------------------- */
    /* Real Attachment ID Allocation & Race Safety (R87, AC-T22-01)               */
    /* -------------------------------------------------------------------------- */

    /**
     * Get or create a real WordPress attachment post for an asset.
     * Thread-safe with row locking and CAS to prevent duplicate attachments during concurrent requests (R87).
     */
    public function getOrCreateAttachmentId(int $assetId): int
    {
        // 1. Fast check if already attached and valid post exists in wp_posts
        $asset = $this->getAssetById($assetId);
        if (!$asset) {
            throw new InvalidArgumentException("Asset ID {$assetId} not found in wpe_assets.");
        }

        if (!empty($asset['attachment_id'])) {
            $existingId = (int)$asset['attachment_id'];
            $post = get_post($existingId);
            if ($post && $post->post_type === 'attachment') {
                $this->attachmentToAsset[$existingId] = $assetId;
                return $existingId;
            }
        }

        // 2. Transaction with pessimistic row lock to resolve race condition (R87)
        $this->wpdb->query("START TRANSACTION");

        try {
            $lockedRow = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id, source_url, mime, alt_text, title, caption, width, height, renditions, provider, attachment_id
                 FROM {$this->tableAssets}
                 WHERE id = %d
                 FOR UPDATE",
                $assetId
            ), ARRAY_A);

            if (!$lockedRow) {
                throw new RuntimeException("Asset {$assetId} disappeared during allocation transaction.");
            }

            // Another concurrent worker may have allocated the attachment while we waited for lock
            if (!empty($lockedRow['attachment_id'])) {
                $winningId = (int)$lockedRow['attachment_id'];
                $post = get_post($winningId);
                if ($post && $post->post_type === 'attachment') {
                    $this->wpdb->query("COMMIT");
                    $this->attachmentToAsset[$winningId] = $assetId;
                    return $winningId;
                }
            }

            // 3. Create real attachment post in wp_posts
            $sourceUrl = $lockedRow['source_url'];
            $title     = !empty($lockedRow['title']) ? $lockedRow['title'] : pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?: 'image', PATHINFO_FILENAME);
            $mime      = !empty($lockedRow['mime']) ? $lockedRow['mime'] : 'image/jpeg';
            $caption   = $lockedRow['caption'] ?? '';

            $postData = [
                'post_author'    => get_current_user_id() ?: 1,
                'post_date'      => current_time('mysql'),
                'post_date_gmt'  => current_time('mysql', 1),
                'post_title'     => $title,
                'post_status'    => 'inherit',
                'post_type'      => 'attachment',
                'post_mime_type' => $mime,
                'post_content'   => $caption,
                'post_excerpt'   => $caption,
            ];

            $attachmentId = wp_insert_post($postData, true);
            if (is_wp_error($attachmentId) || !is_numeric($attachmentId) || $attachmentId <= 0) {
                $errMsg = is_wp_error($attachmentId) ? $attachmentId->get_error_message() : 'Unknown error';
                throw new RuntimeException("Failed to allocate attachment in wp_posts: " . $errMsg);
            }
            $attachmentId = (int)$attachmentId;

            // 4. Update wpe_assets with authoritative attachment_id
            $now = gmdate('Y-m-d H:i:s.u');
            $updated = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableAssets}
                 SET attachment_id = %d, updated_at = %s, version = version + 1
                 WHERE id = %d",
                $attachmentId,
                $now,
                $assetId
            ));

            if ($updated === false) {
                throw new RuntimeException("Failed to associate attachment {$attachmentId} to asset {$assetId}: " . $this->wpdb->last_error);
            }

            // 5. Store metadata in wp_postmeta for WP compatibility
            if (!empty($lockedRow['alt_text'])) {
                update_post_meta($attachmentId, '_wp_attachment_image_alt', $lockedRow['alt_text']);
            }
            update_post_meta($attachmentId, '_wpe_asset_id', $assetId);

            $meta = $this->buildAttachmentMetadata($lockedRow);
            update_post_meta($attachmentId, '_wp_attachment_metadata', $meta);

            $this->wpdb->query("COMMIT");

            $this->attachmentToAsset[$attachmentId] = $assetId;
            $this->assetCache[$assetId] = array_merge($lockedRow, ['attachment_id' => $attachmentId]);

            return $attachmentId;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Resolve the asset ID for a given WordPress attachment ID.
     */
    public function getAssetIdForAttachment(int $attachmentId): ?int
    {
        if (isset($this->attachmentToAsset[$attachmentId])) {
            return $this->attachmentToAsset[$attachmentId];
        }

        // Check meta tag first
        $metaAssetId = get_post_meta($attachmentId, '_wpe_asset_id', true);
        if ($metaAssetId && is_numeric($metaAssetId)) {
            $this->attachmentToAsset[$attachmentId] = (int)$metaAssetId;
            return (int)$metaAssetId;
        }

        // Lookup in wpe_assets
        $assetId = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tableAssets} WHERE attachment_id = %d LIMIT 1",
            $attachmentId
        ));

        if ($assetId && is_numeric($assetId)) {
            $this->attachmentToAsset[$attachmentId] = (int)$assetId;
            return (int)$assetId;
        }

        return null;
    }

    /**
     * Resolve the asset data array for a given WordPress attachment ID.
     */
    public function getAssetForAttachment(int $attachmentId): ?array
    {
        $assetId = $this->getAssetIdForAttachment($attachmentId);
        if (!$assetId) {
            return null;
        }

        return $this->getAssetById($assetId);
    }

    /* -------------------------------------------------------------------------- */
    /* WordPress Image Filters (R88, AC-T22-01)                                   */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept wp_get_attachment_url for bridged CDN assets (R88).
     */
    public function filterAttachmentUrl($url, int $attachmentId): string
    {
        $asset = $this->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return (string)$url;
        }

        return $asset['source_url'];
    }

    /**
     * Intercept image_downsize for bridged CDN assets (R88).
     * Returns: [$url, $width, $height, $is_intermediate]
     */
    public function filterImageDownsize($out, int $attachmentId, $size)
    {
        $asset = $this->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return $out; // Pass through to default local attachment handler
        }

        $sizeName = is_string($size) ? $size : 'full';
        return $this->assetService->getAttachmentImageSrc($asset, $sizeName);
    }

    /**
     * Provide rich attachment metadata for WordPress (R88).
     */
    public function filterAttachmentMetadata($data, int $attachmentId)
    {
        $asset = $this->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return $data;
        }

        return $this->buildAttachmentMetadata($asset);
    }

    /**
     * Intercept wp_calculate_image_srcset to generate accurate responsive CDN sources (R88).
     */
    public function filterCalculateImageSrcset($sources, $size_array, $image_src, $image_meta, int $attachmentId)
    {
        $asset = $this->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return $sources;
        }

        // Generate srcset for standard widths
        $provider = $this->assetService->getProvider($asset['provider'] ?? 'default');
        $sourceUrl = $asset['source_url'];
        $origW = (int)($asset['width'] ?? 1920);
        $origH = (int)($asset['height'] ?? 1080);

        $standardSizes = ['thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_single', 'large', 'full'];
        $computedSources = [];

        foreach ($standardSizes as $sz) {
            $rendition = $provider->getRendition($sourceUrl, $sz, $origW, $origH);
            $w = (int)$rendition['width'];
            if ($w > 0 && !isset($computedSources[$w])) {
                $computedSources[$w] = [
                    'url'        => $rendition['url'],
                    'descriptor' => 'w',
                    'value'      => $w,
                ];
            }
        }

        return !empty($computedSources) ? $computedSources : $sources;
    }

    /**
     * Intercept wp_calculate_image_sizes for responsive rendering (R88).
     */
    public function filterCalculateImageSizes($sizes, $size, $image_src, $image_meta, int $attachmentId)
    {
        $asset = $this->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return $sizes;
        }

        $width = is_array($size) ? (int)$size[0] : 0;
        if ($width > 0) {
            return "(max-width: {$width}px) 100vw, {$width}px";
        }

        return '(max-width: 768px) 100vw, 768px';
    }

    /**
     * Prepare attachment data for WordPress Media Library JS preview modal and grid (AC-T22-01).
     */
    public function filterPrepareAttachmentForJs(array $response, WP_Post $attachment, $meta): array
    {
        $asset = $this->getAssetForAttachment((int)$attachment->ID);
        if (!$asset) {
            return $response;
        }

        $provider = $this->assetService->getProvider($asset['provider'] ?? 'default');
        $origW = (int)($asset['width'] ?? 1920);
        $origH = (int)($asset['height'] ?? 1080);
        $sourceUrl = $asset['source_url'];

        $response['url'] = $sourceUrl;
        $response['alt'] = !empty($asset['alt_text']) ? $asset['alt_text'] : ($response['alt'] ?? '');
        $response['width']  = $origW;
        $response['height'] = $origH;

        // Build preview sizes for Media Library modal
        $sizes = [];
        foreach (['thumbnail', 'medium', 'large', 'full'] as $s) {
            $rend = $provider->getRendition($sourceUrl, $s, $origW, $origH);
            $sizes[$s] = [
                'url'         => $rend['url'],
                'width'       => $rend['width'],
                'height'      => $rend['height'],
                'orientation' => $rend['width'] >= $rend['height'] ? 'landscape' : 'portrait',
            ];
        }
        $response['sizes'] = $sizes;

        return $response;
    }

    /* -------------------------------------------------------------------------- */
    /* Local File Actions Guard (R89)                                             */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept get_attached_file.
     * Strictly avoids returning fake local file paths (03 §3, 10 §3, R89).
     */
    public function filterGetAttachedFile($file, int $attachmentId)
    {
        $asset = $this->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return $file;
        }

        // Return empty string; do NOT invent fake local paths like '/tmp/fake.jpg'
        return '';
    }

    /**
     * Check if a local file manipulation action is supported on this attachment (R89).
     */
    public function isLocalActionSupported(string $action): bool
    {
        $unsupportedActions = ['crop', 'rotate', 'flip', 'regenerate_local', 'edit_image'];
        return !in_array(strtolower($action), $unsupportedActions, true);
    }

    /**
     * Explicitly reject unsupported local file operations with a descriptive WP_Error (R89).
     */
    public function executeLocalAction(int $attachmentId, string $action, array $args = []): array|WP_Error
    {
        if (!$this->isLocalActionSupported($action)) {
            return new WP_Error(
                'unsupported_feature',
                "External CDN images do not support local filesystem action '{$action}' without explicit download/materialization.",
                ['attachment_id' => $attachmentId, 'action' => $action]
            );
        }

        return ['success' => true];
    }

    /* -------------------------------------------------------------------------- */
    /* Shared Deletion Safeguards (R90, AC-T22-02)                                */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept attachment deletion in WordPress.
     * If the asset still has active references in products/variants, enforces rebind policy:
     * unlinks attachment_id from wpe_assets, preserves product media relations,
     * and strictly avoids deleting remote CDN images (R90, AC-T22-02).
     */
    public function onDeleteAttachment(int $attachmentId, ?WP_Post $post = null): void
    {
        $assetId = $this->getAssetIdForAttachment($attachmentId);
        if (!$assetId) {
            return;
        }

        // Check active references in product_media and variant_media
        $pCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableProductMedia} WHERE asset_id = %d",
            $assetId
        ));

        $vCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableVariantMedia} WHERE asset_id = %d",
            $assetId
        ));

        if ($pCount > 0 || $vCount > 0) {
            // Rebind policy: unlink attachment_id from wpe_assets without deleting canonical asset
            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableAssets}
                 SET attachment_id = NULL, updated_at = %s, version = version + 1
                 WHERE id = %d",
                $now,
                $assetId
            ));

            $warning = sprintf(
                "Attachment %d deleted from Media Library; external CDN asset %d retained with active references (products: %d, variants: %d). Remote CDN file untouched.",
                $attachmentId,
                $assetId,
                $pCount,
                $vCount
            );
            $this->auditLog[] = $warning;

            unset($this->attachmentToAsset[$attachmentId]);
            if (isset($this->assetCache[$assetId])) {
                $this->assetCache[$assetId]['attachment_id'] = null;
            }
        } else {
            // No references remain; unbind attachment_id
            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableAssets}
                 SET attachment_id = NULL, updated_at = %s, version = version + 1
                 WHERE id = %d",
                $now,
                $assetId
            ));

            unset($this->attachmentToAsset[$attachmentId]);
        }
    }

    public function getAuditLog(): array
    {
        return $this->auditLog;
    }

    /* -------------------------------------------------------------------------- */
    /* Helpers                                                                    */
    /* -------------------------------------------------------------------------- */

    private function getAssetById(int $assetId): ?array
    {
        if (isset($this->assetCache[$assetId])) {
            return $this->assetCache[$assetId];
        }

        $row = $this->mediaRepo->getAssetById($assetId);
        if ($row) {
            $this->assetCache[$assetId] = $row;
            if (!empty($row['attachment_id'])) {
                $this->attachmentToAsset[(int)$row['attachment_id']] = $assetId;
            }
        }

        return $row;
    }

    private function buildAttachmentMetadata(array $asset): array
    {
        $provider = $this->assetService->getProvider($asset['provider'] ?? 'default');
        $origW = (int)($asset['width'] ?? 1920);
        $origH = (int)($asset['height'] ?? 1080);
        $sourceUrl = $asset['source_url'];
        $mime = $asset['mime'] ?? 'image/jpeg';

        $sizes = [];
        foreach (['thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_single', 'large', 'full'] as $s) {
            $rend = $provider->getRendition($sourceUrl, $s, $origW, $origH);
            $sizes[$s] = [
                'file'      => $rend['url'],
                'width'     => $rend['width'],
                'height'    => $rend['height'],
                'mime-type' => $mime,
            ];
        }

        return [
            'width'      => $origW,
            'height'     => $origH,
            'file'       => $sourceUrl,
            'sizes'      => $sizes,
            'image_meta' => [
                'alt'     => $asset['alt_text'] ?? '',
                'title'   => $asset['title'] ?? '',
                'caption' => $asset['caption'] ?? '',
            ],
        ];
    }
}
