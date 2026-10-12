<?php
/**
 * T23 Integration Test — Native gallery và variation image payload.
 *
 * Verifies:
 * - R91 (classic_gallery_zoom_lightbox): Coherent zoom, lightbox, swipe, and thumbnail gallery markup.
 * - R92 (variant_image_reset): Variation image resets restoring parent gallery state.
 * - R93 (multiple_variant_images): Multi-image variation gallery extension.
 * - R94 (missing_image_fallback): Deterministic fallback for variations lacking images.
 * - AC-T23-01: Coherent zoom/lightbox/swipe attributes without mismatched thumbnail/full_src.
 * - AC-T23-02: Zero WooCommerce Core template modifications.
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\Gallery\GalleryAdapter;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\MediaRepository;

class T23Test
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private AssetService $assetService;
    private AttachmentBridge $bridge;
    private GalleryAdapter $adapter;
    private PostIdAllocator $allocator;

    private array $cleanAssetIds = [];
    private array $cleanProductIds = [];
    private array $cleanAttachmentIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->mediaRepo = new MediaRepository($this->wpdb);
        $this->assetService = new AssetService($this->wpdb, $this->mediaRepo);
        $this->bridge = AttachmentBridge::register(new AttachmentBridge($this->wpdb, $this->assetService, $this->mediaRepo));
        $this->adapter = GalleryAdapter::register(new GalleryAdapter($this->wpdb, $this->assetService, $this->bridge, $this->mediaRepo));
        $this->allocator = new PostIdAllocator($this->wpdb);

        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14']; // Safe public IP (example.com)
        });
    }

    public function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;

        if (!empty($this->cleanAttachmentIds)) {
            foreach ($this->cleanAttachmentIds as $attId) {
                wp_delete_attachment($attId, true);
            }
            $this->cleanAttachmentIds = [];
        }

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanAssetIds)) {
            $ids = implode(',', array_map('intval', $this->cleanAssetIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE asset_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE asset_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_assets WHERE id IN ({$ids})");
            $this->cleanAssetIds = [];
        }

        UrlValidator::setDnsResolver(null);
    }

    public function runAll(): void
    {
        echo "Running T23 Integration Tests...\n";

        try {
            $this->testR91_classic_gallery_zoom_lightbox();
            $this->testR92_variant_image_reset();
            $this->testR93_multiple_variant_images();
            $this->testR94_missing_image_fallback();
            $this->testAC_T23_01_CoherentGalleryZoomAndSelection();
            $this->testAC_T23_02_NoCoreTemplateModification();

            echo "All T23 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    /**
     * R91: Coherent zoom, lightbox, swipe, and thumbnail gallery markup.
     */
    public function testR91_classic_gallery_zoom_lightbox(): void
    {
        echo " - testR91_classic_gallery_zoom_lightbox...";

        $asset = $this->assetService->registerAsset('https://cdn.example.com/gallery/classic-jacket.jpg', [
            'width'    => 1600,
            'height'   => 1200,
            'alt_text' => 'Classic Leather Jacket',
            'title'    => 'Classic Jacket Title',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        // Render gallery thumbnail item markup
        $html = $this->adapter->filterGalleryThumbnailHtml('', $attId);

        // Verify native WooCommerce gallery structure
        if (!str_contains($html, 'class="woocommerce-product-gallery__image"')) {
            throw new RuntimeException("R91 Failed: Missing woocommerce-product-gallery__image class.");
        }

        // Verify zoom data attributes
        if (!str_contains($html, 'data-large_image="https://cdn.example.com/gallery/classic-jacket.jpg"')) {
            throw new RuntimeException("R91 Failed: data-large_image missing or incorrect.");
        }
        if (!str_contains($html, 'data-large_image_width="1600"') || !str_contains($html, 'data-large_image_height="1200"')) {
            throw new RuntimeException("R91 Failed: Zoom dimensions mismatch in markup.");
        }

        // Verify coherent thumbnail and full_src link (AC-T23-01)
        if (!str_contains($html, 'data-thumb="https://cdn.example.com/gallery/classic-jacket.jpg"') || !str_contains($html, '<a href="https://cdn.example.com/gallery/classic-jacket.jpg"')) {
            throw new RuntimeException("R91 Failed: Incoherent thumbnail or link destination.");
        }

        echo " OK\n";
    }

    /**
     * R92: Variation image resets restoring parent gallery state.
     */
    public function testR92_variant_image_reset(): void
    {
        echo " - testR92_variant_image_reset...";

        // 1. Create parent product
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;
        $prefix = $this->wpdb->prefix;
        $this->wpdb->insert($prefix . 'wpe_products', [
            'id'         => $parentId,
            'type'       => 'variable',
            'status'     => 'publish',
            'slug'       => 'prod-t23-var',
            'title'      => 'Parent Variable Product',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Register parent featured image and gallery image
        $parentFeat = $this->assetService->registerAsset('https://cdn.example.com/parent-featured.jpg', ['width' => 1200, 'height' => 900, 'alt_text' => 'Parent Featured']);
        $parentGal  = $this->assetService->registerAsset('https://cdn.example.com/parent-gal.jpg', ['width' => 1200, 'height' => 900, 'alt_text' => 'Parent Gallery']);
        $this->cleanAssetIds[] = (int)$parentFeat['id'];
        $this->cleanAssetIds[] = (int)$parentGal['id'];

        $this->mediaRepo->setFeaturedImage($parentId, (int)$parentFeat['id']);
        $this->mediaRepo->setGallery($parentId, [(int)$parentGal['id']]);

        // 2. Create variation with its own primary image
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $varId;
        $this->wpdb->insert($prefix . 'wpe_variants', [
            'id'         => $varId,
            'product_id' => $parentId,
            'status'     => 'publish',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $varFeat = $this->assetService->registerAsset('https://cdn.example.com/var-red.jpg', ['width' => 800, 'height' => 800, 'alt_text' => 'Red Variation']);
        $this->cleanAssetIds[] = (int)$varFeat['id'];
        $this->mediaRepo->setVariantPrimaryImage($varId, (int)$varFeat['id']);

        // 3. Test variant image payload
        $varPayload = $this->adapter->serializeVariantImagePayload($varId, $parentId);
        if ($varPayload['src'] !== 'https://cdn.example.com/var-red.jpg' || $varPayload['alt'] !== 'Red Variation') {
            throw new RuntimeException("R92 Failed: Variant payload did not match variation primary image.");
        }

        // 4. Test parent reset gallery payload
        $parentPayload = $this->adapter->getParentGalleryPayload($parentId);
        if ($parentPayload['featured']['src'] !== 'https://cdn.example.com/parent-featured.jpg') {
            throw new RuntimeException("R92 Failed: Parent reset payload did not return parent featured image.");
        }
        if (count($parentPayload['gallery']) !== 1 || $parentPayload['gallery'][0]['src'] !== 'https://cdn.example.com/parent-gal.jpg') {
            throw new RuntimeException("R92 Failed: Parent reset payload did not return parent gallery images.");
        }

        echo " OK\n";
    }

    /**
     * R93: Multi-image variation gallery extension.
     */
    public function testR93_multiple_variant_images(): void
    {
        echo " - testR93_multiple_variant_images...";

        $prefix = $this->wpdb->prefix;
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $varId;
        $this->wpdb->insert($prefix . 'wpe_variants', [
            'id'         => $varId,
            'product_id' => 1001,
            'status'     => 'publish',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Register 3 images for this variation
        $img1 = $this->assetService->registerAsset('https://cdn.example.com/var-angle1.jpg', ['width' => 800, 'height' => 600, 'alt_text' => 'Front Angle']);
        $img2 = $this->assetService->registerAsset('https://cdn.example.com/var-angle2.jpg', ['width' => 800, 'height' => 600, 'alt_text' => 'Back Angle']);
        $img3 = $this->assetService->registerAsset('https://cdn.example.com/var-angle3.jpg', ['width' => 800, 'height' => 600, 'alt_text' => 'Detail Angle']);

        $this->cleanAssetIds[] = (int)$img1['id'];
        $this->cleanAssetIds[] = (int)$img2['id'];
        $this->cleanAssetIds[] = (int)$img3['id'];

        // Set primary and additional gallery images
        $this->mediaRepo->setVariantPrimaryImage($varId, (int)$img1['id']);
        $this->assetService->attachVariantImage($varId, (int)$img2['id'], 1, false, 'Back Angle Custom');
        $this->assetService->attachVariantImage($varId, (int)$img3['id'], 2, false, 'Detail Angle Custom');

        // Test multi-image retrieval
        $gallery = $this->adapter->getVariantGalleryImages($varId);
        if (count($gallery) !== 3) {
            throw new RuntimeException("R93 Failed: Expected 3 variant images in multi-image gallery, got " . count($gallery));
        }

        if ($gallery[0]['alt'] !== 'Front Angle' || $gallery[1]['alt'] !== 'Back Angle Custom' || $gallery[2]['alt'] !== 'Detail Angle Custom') {
            throw new RuntimeException("R93 Failed: Multi-image ordering or alt text corrupted.");
        }

        // Test available variation filter enriches with gallery_images
        $enriched = $this->adapter->filterAvailableVariation(['variation_id' => $varId], null, null);
        if (empty($enriched['gallery_images']) || count($enriched['gallery_images']) !== 3) {
            throw new RuntimeException("R93 Failed: filterAvailableVariation did not populate gallery_images array.");
        }

        echo " OK\n";
    }

    /**
     * R94: Deterministic fallback for variations lacking images.
     */
    public function testR94_missing_image_fallback(): void
    {
        echo " - testR94_missing_image_fallback...";

        $prefix = $this->wpdb->prefix;

        // Parent with featured image
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;
        $this->wpdb->insert($prefix . 'wpe_products', [
            'id'         => $parentId,
            'type'       => 'variable',
            'status'     => 'publish',
            'slug'       => 'prod-t23-fb',
            'title'      => 'Parent for Fallback',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $parentImg = $this->assetService->registerAsset('https://cdn.example.com/parent-only.jpg', ['width' => 1000, 'height' => 1000, 'alt_text' => 'Parent Only']);
        $this->cleanAssetIds[] = (int)$parentImg['id'];
        $this->mediaRepo->setFeaturedImage($parentId, (int)$parentImg['id']);

        // Variation WITHOUT image
        $varNoImgId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $varNoImgId;
        $this->wpdb->insert($prefix . 'wpe_variants', [
            'id'         => $varNoImgId,
            'product_id' => $parentId,
            'status'     => 'publish',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Should deterministically fall back to parent image
        $payload = $this->adapter->serializeVariantImagePayload($varNoImgId, $parentId);
        if ($payload['src'] !== 'https://cdn.example.com/parent-only.jpg') {
            throw new RuntimeException("R94 Failed: Variation lacking image did not fall back to parent featured image.");
        }

        // Parent WITHOUT image either -> falls back to placeholder data URI
        $parentEmptyId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentEmptyId;
        $this->wpdb->insert($prefix . 'wpe_products', [
            'id'         => $parentEmptyId,
            'type'       => 'variable',
            'status'     => 'publish',
            'slug'       => 'prod-t23-empty',
            'title'      => 'Parent Without Image',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $varEmptyId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $varEmptyId;
        $this->wpdb->insert($prefix . 'wpe_variants', [
            'id'         => $varEmptyId,
            'product_id' => $parentEmptyId,
            'status'     => 'publish',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $placeholderPayload = $this->adapter->serializeVariantImagePayload($varEmptyId, $parentEmptyId);
        if (!str_contains($placeholderPayload['src'], 'data:image/svg+xml')) {
            throw new RuntimeException("R94 Failed: Empty parent/variation did not fall back to SVG placeholder data URI.");
        }
        if ($placeholderPayload['src_w'] <= 0 || $placeholderPayload['src_h'] <= 0) {
            throw new RuntimeException("R94 Failed: Placeholder missing valid dimensions.");
        }

        echo " OK\n";
    }

    /**
     * AC-T23-01: Coherent zoom/lightbox/swipe attributes without mismatched thumbnail/full_src.
     */
    public function testAC_T23_01_CoherentGalleryZoomAndSelection(): void
    {
        echo " - testAC_T23_01_CoherentGalleryZoomAndSelection...";

        $asset = $this->assetService->registerAsset('https://cdn.example.com/gallery/coherent-hero.jpg', [
            'width'    => 2000,
            'height'   => 1500,
            'alt_text' => 'Coherent Hero Image',
            'title'    => 'Hero',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        $html = $this->adapter->filterGalleryThumbnailHtml('', $attId);

        // Parse attributes to verify thumbnail and full_src match the same asset
        preg_match('/data-thumb="([^"]+)"/', $html, $thumbMatches);
        preg_match('/href="([^"]+)"/', $html, $hrefMatches);
        preg_match('/data-large_image="([^"]+)"/', $html, $largeMatches);

        if (empty($thumbMatches[1]) || empty($hrefMatches[1]) || empty($largeMatches[1])) {
            throw new RuntimeException("AC-T23-01 Failed: Gallery markup missing required attributes.");
        }

        // Base domain and path must be identical (no thumbnail of image A and full_src of image B)
        $thumbPath = parse_url($thumbMatches[1], PHP_URL_PATH);
        $hrefPath  = parse_url($hrefMatches[1], PHP_URL_PATH);
        $largePath = parse_url($largeMatches[1], PHP_URL_PATH);

        if ($thumbPath !== $hrefPath || $hrefPath !== $largePath) {
            throw new RuntimeException("AC-T23-01 Failed: Incoherent asset paths in gallery markup: thumb={$thumbPath}, href={$hrefPath}, large={$largePath}");
        }

        echo " OK\n";
    }

    /**
     * AC-T23-02: Zero WooCommerce Core template modifications.
     */
    public function testAC_T23_02_NoCoreTemplateModification(): void
    {
        echo " - testAC_T23_02_NoCoreTemplateModification...";

        // Verify that integration hooks into standard filters without altering WC template files
        $hasVariationFilter = has_filter('woocommerce_available_variation', [$this->adapter, 'filterAvailableVariation']);
        $hasGalleryFilter   = has_filter('woocommerce_single_product_image_thumbnail_html', [$this->adapter, 'filterGalleryThumbnailHtml']);

        if (!$hasVariationFilter || !$hasGalleryFilter) {
            throw new RuntimeException("AC-T23-02 Failed: Core filters not registered on GalleryAdapter.");
        }

        // Verify no modified WC core files exist via plugin checksums
        $cmd = 'wp --path=/var/www/site1.localhost --allow-root plugin verify-checksums woocommerce 2>&1';
        $output = shell_exec($cmd);
        if (!str_contains((string)$output, 'Success: Verified 1 of 1 plugins.')) {
            throw new RuntimeException("AC-T23-02 Failed: WooCommerce Core files have modifications: " . $output);
        }

        echo " OK\n";
    }
}

// Run tests if executed directly
$test = new T23Test();
$test->runAll();
