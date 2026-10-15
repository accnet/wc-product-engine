<?php

namespace WPE\Compatibility\Gallery;

use RuntimeException;
use InvalidArgumentException;
use WC_Product;
use WC_Product_Variation;
use WPE\Infrastructure\Database\MediaRepository;

/**
 * Native WooCommerce Gallery and Variation Image Payload Adapter.
 *
 * Integrates external CDN images into WooCommerce's native single product gallery
 * without modifying WooCommerce Core templates (AC-T23-02).
 * Formats zoom, lightbox, swipe, and thumbnail data attributes coherently (R91, AC-T23-01),
 * supports variation image resets restoring parent gallery state (R92),
 * provides multi-image gallery extension for variations (R93),
 * and implements deterministic fallback for variations lacking images (R94).
 */
class GalleryAdapter
{
    private static ?GalleryAdapter $instance = null;
    private static bool $filtersRegistered = false;

    private \wpdb $wpdb;
    private AssetService $assetService;
    private AttachmentBridge $bridge;
    private MediaRepository $mediaRepo;

    private string $tableAssets;
    private string $tableProductMedia;
    private string $tableVariantMedia;

    /** @var array<int, int> */
    private static array $featuredAttachmentCache = [];

    /** @var array<int, array<int, int>> */
    private static array $galleryAttachmentIdsCache = [];

    public static function invalidateStaticProductCache(?int $productId = null): void
    {
        if ($productId === null) {
            self::$featuredAttachmentCache = [];
            self::$galleryAttachmentIdsCache = [];
            return;
        }
        unset(self::$featuredAttachmentCache[$productId], self::$galleryAttachmentIdsCache[$productId]);
    }

    public function __construct(
        ?\wpdb $wpdb = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $bridge = null,
        ?MediaRepository $mediaRepo = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->mediaRepo = $mediaRepo ?? new MediaRepository($this->wpdb);
        $this->assetService = $assetService ?? new AssetService($this->wpdb, $this->mediaRepo);
        $this->bridge = $bridge ?? AttachmentBridge::instance($this->wpdb);

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
     * Register WooCommerce hooks and filters (AC-T23-02).
     */
    public static function register(?GalleryAdapter $instance = null): self
    {
        $adapter = $instance ?? self::instance();
        self::$instance = $adapter;

        // Available variation image payload (R92, R93, R94)
        add_filter('woocommerce_available_variation', [$adapter, 'filterAvailableVariation'], 10, 3);

        // Single product gallery thumbnail markup (R91, AC-T23-01)
        add_filter('woocommerce_single_product_image_thumbnail_html', [$adapter, 'filterGalleryThumbnailHtml'], 10, 2);

        // Native WC getters bridge
        add_filter('woocommerce_product_get_image_id', [$adapter, 'filterProductImageId'], 10, 2);
        add_filter('woocommerce_product_get_gallery_image_ids', [$adapter, 'filterProductGalleryImageIds'], 10, 2);

        self::$filtersRegistered = true;
        return $adapter;
    }

    /* -------------------------------------------------------------------------- */
    /* Variation Image Payload & Fallback (R92, R93, R94)                         */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept woocommerce_available_variation to enrich the variation image payload.
     * Guarantees coherent src, full_src, gallery_thumbnail_src, and multi-image extension (R93).
     */
    public function filterAvailableVariation($variationData, $product, $variation)
    {
        if (!is_array($variationData)) {
            return $variationData;
        }

        $variationId = 0;
        if ($variation instanceof WC_Product_Variation) {
            $variationId = (int)$variation->get_id();
        } elseif (isset($variationData['variation_id'])) {
            $variationId = (int)$variationData['variation_id'];
        }

        $parentId = 0;
        if ($product instanceof WC_Product) {
            $parentId = (int)$product->get_id();
        } elseif ($variation instanceof WC_Product_Variation) {
            $parentId = (int)$variation->get_parent_id();
        }

        if ($variationId > 0) {
            $imagePayload = $this->serializeVariantImagePayload($variationId, $parentId);
            if ($imagePayload) {
                $variationData['image'] = $imagePayload;
                $variationData['image_id'] = $imagePayload['image_id'];
            }

            // Multi-image gallery extension for variation (R93)
            $multiImages = $this->getVariantGalleryImages($variationId);
            $variationData['gallery_images'] = $multiImages;
        }

        return $variationData;
    }

    /**
     * Serialize complete variation image payload with deterministic fallback (R94).
     * 1. Primary variant image from wpe_variant_media.
     * 2. If missing, fallback to parent featured image from wpe_product_media.
     * 3. If parent also missing, fallback to SVG placeholder.
     */
    public function serializeVariantImagePayload(int $variantId, int $parentProductId = 0): array
    {
        $asset = null;
        $altOverride = null;

        // 1. Primary image for this variation
        $sql = "SELECT a.*, vm.alt_override
                FROM {$this->tableVariantMedia} vm
                JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                WHERE vm.variant_id = %d AND vm.is_primary = 1
                LIMIT 1";
        $variantRow = $this->wpdb->get_row($this->wpdb->prepare($sql, $variantId), ARRAY_A);

        if ($variantRow) {
            $asset = $variantRow;
            $altOverride = $variantRow['alt_override'];
        } elseif ($parentProductId > 0) {
            // 2. Deterministic fallback to parent featured image (R94)
            $sqlParent = "SELECT a.*, pm.alt_override
                          FROM {$this->tableProductMedia} pm
                          JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                          WHERE pm.product_id = %d AND pm.role = 'featured'
                          LIMIT 1";
            $parentRow = $this->wpdb->get_row($this->wpdb->prepare($sqlParent, $parentProductId), ARRAY_A);

            if ($parentRow) {
                $asset = $parentRow;
                $altOverride = $parentRow['alt_override'];
            }
        }

        // 3. Fallback to placeholder if completely missing (R94, AC-T21-02)
        if (!$asset || ($asset['health'] ?? 'healthy') === 'broken') {
            return $this->buildPlaceholderPayload($altOverride ?: 'Image unavailable');
        }

        return $this->buildImagePayloadFromAsset($asset, $altOverride);
    }

    /**
     * Retrieve all gallery images for a variation (R93).
     *
     * @return array<int, array> Ordered list of serialized image payloads
     */
    public function getVariantGalleryImages(int $variantId): array
    {
        $sql = "SELECT a.*, vm.position, vm.is_primary, vm.alt_override
                FROM {$this->tableVariantMedia} vm
                JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                WHERE vm.variant_id = %d
                ORDER BY vm.position ASC";

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $variantId), ARRAY_A);
        if (!empty($rows)) {
            $this->bridge->primeAssets($rows);
        }
        $gallery = [];

        foreach ($rows as $row) {
            $gallery[] = $this->buildImagePayloadFromAsset($row, $row['alt_override']);
        }

        return $gallery;
    }

    /**
     * Get parent product default gallery state for reset operations (R92).
     */
    public function getParentGalleryPayload(int $productId): array
    {
        // 1. Featured image
        $featured = null;
        $sqlFeatured = "SELECT a.*, pm.alt_override
                        FROM {$this->tableProductMedia} pm
                        JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                        WHERE pm.product_id = %d AND pm.role = 'featured'
                        LIMIT 1";
        $featRow = $this->wpdb->get_row($this->wpdb->prepare($sqlFeatured, $productId), ARRAY_A);
        if ($featRow) {
            $featured = $this->buildImagePayloadFromAsset($featRow, $featRow['alt_override']);
        } else {
            $featured = $this->buildPlaceholderPayload('Product Image');
        }

        // 2. Gallery images
        $sqlGallery = "SELECT a.*, pm.position, pm.alt_override
                       FROM {$this->tableProductMedia} pm
                       JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                       WHERE pm.product_id = %d AND pm.role = 'gallery'
                       ORDER BY pm.position ASC";
        $galleryRows = $this->wpdb->get_results($this->wpdb->prepare($sqlGallery, $productId), ARRAY_A);
        if (!empty($galleryRows)) {
            $this->bridge->primeAssets($galleryRows);
        }

        $gallery = [];
        $galleryImageIds = [];
        foreach ($galleryRows as $grow) {
            $payload = $this->buildImagePayloadFromAsset($grow, $grow['alt_override']);
            $gallery[] = $payload;
            $galleryImageIds[] = $payload['image_id'];
        }

        return [
            'featured'          => $featured,
            'gallery'           => $gallery,
            'gallery_image_ids' => $galleryImageIds,
            'product_id'        => $productId,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* Classic Gallery Template Integration (R91, AC-T23-01)                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Filter single product image thumbnail HTML markup.
     * Ensures coherent zoom (data-large_image/full_src), lightbox, and thumbnails (R91, AC-T23-01).
     */
    public function filterGalleryThumbnailHtml($html, $attachmentId): string
    {
        $htmlStr = (string)$html;
        if (!is_numeric($attachmentId) || (int)$attachmentId <= 0) {
            return $htmlStr;
        }

        $attachmentId = (int)$attachmentId;
        $asset = $this->bridge->getAssetForAttachment($attachmentId);
        if (!$asset) {
            return $htmlStr;
        }

        // Preserve custom theme thumbnail strip items (e.g., Flatsome <div class="col"><a><img/></a></div>)
        // that already resolved their CDN src via wp_get_attachment_image_src()
        if ($htmlStr !== '' && str_contains($htmlStr, '<div class="col') && !str_contains($htmlStr, 'woocommerce-product-gallery__image')) {
            return $htmlStr;
        }

        $provider  = $this->assetService->getProvider($asset['provider'] ?? 'default');
        $sourceUrl = $asset['source_url'];
        $origW     = (int)($asset['width'] ?? 1920);
        $origH     = (int)($asset['height'] ?? 1080);
        $altText   = esc_attr($asset['alt_text'] ?? 'Product Image');

        // Coherent renditions for the SAME image (AC-T23-01)
        $singleRend = $provider->getRendition($sourceUrl, 'woocommerce_single', $origW, $origH);
        $fullRend   = $provider->getRendition($sourceUrl, 'full', $origW, $origH);
        $thumbRend  = $provider->getRendition($sourceUrl, 'woocommerce_gallery_thumbnail', $origW, $origH);

        $singleSrc = esc_url($singleRend['url']);
        $fullSrc   = esc_url($fullRend['url']);
        $thumbSrc  = esc_url($thumbRend['url']);

        $singleW = (int)$singleRend['width'];
        $singleH = (int)$singleRend['height'];
        $fullW   = (int)$fullRend['width'];
        $fullH   = (int)$fullRend['height'];

        $srcset = wp_get_attachment_image_srcset($attachmentId, 'woocommerce_single');
        $sizes  = wp_get_attachment_image_sizes($attachmentId, 'woocommerce_single');

        $srcsetAttribute = $srcset ? sprintf(' srcset="%s"', esc_attr($srcset)) : '';
        $sizesAttribute  = $sizes ? sprintf(' sizes="%s"', esc_attr($sizes)) : '';

        $wrapperClass = 'woocommerce-product-gallery__image';
        if (str_contains($htmlStr, 'slide first')) {
            $wrapperClass .= ' slide first';
        } elseif (str_contains($htmlStr, 'class="slide"') || str_contains($htmlStr, ' slide"') || str_contains($htmlStr, ' slide ')) {
            $wrapperClass .= ' slide';
        }

        // Native WooCommerce gallery markup and data attributes
        return sprintf(
            '<div data-thumb="%s" data-thumb-alt="%s" class="%s">' .
            '<a href="%s">' .
            '<img width="%d" height="%d" src="%s" class="wp-post-image" alt="%s" title="%s" data-src="%s" data-large_image="%s" data-large_image_width="%d" data-large_image_height="%d"%s%s loading="lazy" decoding="async" />' .
            '</a>' .
            '</div>',
            $thumbSrc,
            $altText,
            esc_attr($wrapperClass),
            $fullSrc,
            $singleW,
            $singleH,
            $singleSrc,
            $altText,
            esc_attr($asset['title'] ?? ''),
            $singleSrc,
            $fullSrc,
            $fullW,
            $fullH,
            $srcsetAttribute,
            $sizesAttribute
        );
    }

    /* -------------------------------------------------------------------------- */
    /* WooCommerce Native Getters Interceptors                                    */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept get_image_id() on WC_Product to return real attachment ID (03 §3).
     */
    public function filterProductImageId($imageId, $product)
    {
        $productId = is_numeric($product) ? (int)$product : ($product instanceof WC_Product ? (int)$product->get_id() : 0);
        if ($productId <= 0) {
            return $imageId;
        }

        if (array_key_exists($productId, self::$featuredAttachmentCache)) {
            $cached = self::$featuredAttachmentCache[$productId];
            return $cached > 0 ? $cached : $imageId;
        }

        $feat = $this->mediaRepo->getFeaturedImage($productId);
        if ($feat && !empty($feat['id'])) {
            $this->bridge->primeAsset($feat);
            $attId = $this->bridge->getOrCreateAttachmentId((int)$feat['id']);
            self::$featuredAttachmentCache[$productId] = $attId;
            return $attId;
        }

        self::$featuredAttachmentCache[$productId] = 0;
        return $imageId;
    }

    /**
     * Intercept get_gallery_image_ids() on WC_Product to return real attachment IDs (03 §3).
     */
    public function filterProductGalleryImageIds($galleryIds, $product)
    {
        $productId = is_numeric($product) ? (int)$product : ($product instanceof WC_Product ? (int)$product->get_id() : 0);
        if ($productId <= 0) {
            return $galleryIds;
        }

        if (array_key_exists($productId, self::$galleryAttachmentIdsCache)) {
            $cached = self::$galleryAttachmentIdsCache[$productId];
            return !empty($cached) ? $cached : $galleryIds;
        }

        $gallery = $this->mediaRepo->getGallery($productId);
        if (empty($gallery)) {
            self::$galleryAttachmentIdsCache[$productId] = [];
            return $galleryIds;
        }

        $this->bridge->primeAssets($gallery);
        $attachmentIds = [];
        foreach ($gallery as $item) {
            if (!empty($item['id'])) {
                $attachmentIds[] = $this->bridge->getOrCreateAttachmentId((int)$item['id']);
            }
        }

        self::$galleryAttachmentIdsCache[$productId] = $attachmentIds;
        return !empty($attachmentIds) ? $attachmentIds : $galleryIds;
    }

    /* -------------------------------------------------------------------------- */
    /* Private Payload Builders                                                   */
    /* -------------------------------------------------------------------------- */

    private function buildImagePayloadFromAsset(array $asset, ?string $altOverride = null): array
    {
        $assetId      = (int)$asset['id'];
        $this->bridge->primeAsset($asset);
        $attachmentId = $this->bridge->getOrCreateAttachmentId($assetId);
        $provider     = $this->assetService->getProvider($asset['provider'] ?? 'default');
        $sourceUrl    = $asset['source_url'];
        $origW        = (int)($asset['width'] ?? 1920);
        $origH        = (int)($asset['height'] ?? 1080);

        $singleRend = $provider->getRendition($sourceUrl, 'woocommerce_single', $origW, $origH);
        $fullRend   = $provider->getRendition($sourceUrl, 'full', $origW, $origH);
        $thumbRend  = $provider->getRendition($sourceUrl, 'woocommerce_gallery_thumbnail', $origW, $origH);

        $alt = !empty($altOverride) ? $altOverride : ($asset['alt_text'] ?? '');

        return [
            'image_id'                => $attachmentId,
            'asset_id'                => $assetId,
            'title'                   => $asset['title'] ?? '',
            'caption'                 => $asset['caption'] ?? '',
            'alt'                     => $alt,
            'src'                     => $singleRend['url'],
            'src_w'                   => (int)$singleRend['width'],
            'src_h'                   => (int)$singleRend['height'],
            'full_src'                => $fullRend['url'],
            'full_src_w'              => (int)$fullRend['width'],
            'full_src_h'              => (int)$fullRend['height'],
            'gallery_thumbnail_src'   => $thumbRend['url'],
            'gallery_thumbnail_src_w' => (int)$thumbRend['width'],
            'gallery_thumbnail_src_h' => (int)$thumbRend['height'],
            'thumb_src'               => $thumbRend['url'],
            'thumb_src_w'             => (int)$thumbRend['width'],
            'thumb_src_h'             => (int)$thumbRend['height'],
            'srcset'                  => wp_get_attachment_image_srcset($attachmentId, 'woocommerce_single') ?: '',
            'sizes'                   => wp_get_attachment_image_sizes($attachmentId, 'woocommerce_single') ?: '',
        ];
    }

    private function buildPlaceholderPayload(string $alt = 'Image unavailable'): array
    {
        $placeholderUri = $this->assetService->getPlaceholderDataUri(600, 600);
        $thumbUri       = $this->assetService->getPlaceholderDataUri(100, 100);

        return [
            'image_id'                => 0,
            'asset_id'                => 0,
            'title'                   => 'Image unavailable',
            'caption'                 => '',
            'alt'                     => $alt,
            'src'                     => $placeholderUri,
            'src_w'                   => 600,
            'src_h'                   => 600,
            'full_src'                => $placeholderUri,
            'full_src_w'              => 600,
            'full_src_h'              => 600,
            'gallery_thumbnail_src'   => $thumbUri,
            'gallery_thumbnail_src_w' => 100,
            'gallery_thumbnail_src_h' => 100,
            'thumb_src'               => $thumbUri,
            'thumb_src_w'             => 100,
            'thumb_src_h'             => 100,
            'srcset'                  => '',
            'sizes'                   => '',
        ];
    }
}
