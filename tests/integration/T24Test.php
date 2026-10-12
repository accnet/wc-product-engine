<?php
/**
 * T24 Integration Test — Ảnh trên cart/email/SEO/feed/Blocks.
 *
 * Verifies:
 * - R95 (cart_email_variant_image): Cart, mini-cart, checkout, order history, and emails render variation CDN images.
 * - R96 (schema_og_sitemap): Enriches JSON-LD schema, OpenGraph/Twitter cards, and sitemaps with public CDN URLs.
 * - R97 (blocks_gallery): WooCommerce Blocks Store API cart items and block rendering with variation CDN images.
 * - R98 (no_signed_secret_url_exposure): Strips signed/volatile credentials (X-Amz-Signature, token, secret, auth) across public surfaces.
 * - AC-T24-01: Variation images coherent across surfaces; OG/schema not empty; alt override policy clearly defined.
 * - AC-T24-02: Unsupported/unregistered SEO and feed plugins classified as blocked/unknown.
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
use WPE\Compatibility\Gallery\ImageSurfaces;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\MediaRepository;

class T24Test
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private AssetService $assetService;
    private AttachmentBridge $bridge;
    private GalleryAdapter $galleryAdapter;
    private ImageSurfaces $surfaces;
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
        $this->galleryAdapter = GalleryAdapter::register(new GalleryAdapter($this->wpdb, $this->assetService, $this->bridge, $this->mediaRepo));
        $this->surfaces = ImageSurfaces::register(new ImageSurfaces($this->wpdb, $this->assetService, $this->bridge, $this->galleryAdapter, $this->mediaRepo));
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
        echo "Running T24 Integration Tests...\n";

        try {
            $this->testR95_cart_email_variant_image();
            $this->testR96_schema_og_sitemap();
            $this->testR97_blocks_gallery();
            $this->testR98_no_signed_secret_url_exposure();
            $this->testAC_T24_01_SurfacesCoherenceAndAltOverridePolicy();
            $this->testAC_T24_02_UnsupportedSeoAndFeedPluginsClassified();

            echo "All T24 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    /**
     * R95: Filters woocommerce_cart_item_thumbnail and woocommerce_order_item_thumbnail
     * returning variation-specific CDN image for cart, checkout, mini-cart, and emails.
     */
    public function testR95_cart_email_variant_image(): void
    {
        echo " - testR95_cart_email_variant_image...";

        $prefix = $this->wpdb->prefix;

        // 1. Create parent product and 2 variations
        $parentId = $this->allocator->allocateShell('product');
        $varRedId = $this->allocator->allocateShell('product_variation');
        $varBlueId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$parentId, $varRedId, $varBlueId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'SURF-JACKET', 'slug' => 'surf-jacket-' . $parentId, 'title' => 'Surface Jacket', 'type' => 'variable',
            'status' => 'publish', 'description' => '', 'short_description' => '', 'default_selection' => '{}', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varRedId, 'product_id' => $parentId, 'sku' => 'SURF-JACKET-RED', 'status' => 'publish',
            'combination_key' => hash('sha256', 'red', true), 'description' => '', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varBlueId, 'product_id' => $parentId, 'sku' => 'SURF-JACKET-BLUE', 'status' => 'publish',
            'combination_key' => hash('sha256', 'blue', true), 'description' => '', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        // 2. Register CDN assets
        $parentAsset = $this->assetService->registerAsset('https://cdn.example.com/surfaces/parent-jacket.jpg', [
            'width' => 1200, 'height' => 1200, 'alt_text' => 'Parent Jacket Default Alt', 'title' => 'Parent Jacket'
        ]);
        $redAsset = $this->assetService->registerAsset('https://cdn.example.com/surfaces/red-jacket.jpg', [
            'width' => 1200, 'height' => 1200, 'alt_text' => 'Red Jacket Default Alt', 'title' => 'Red Jacket'
        ]);
        $this->cleanAssetIds = array_merge($this->cleanAssetIds, [(int)$parentAsset['id'], (int)$redAsset['id']]);

        // Map parent featured
        $this->wpdb->insert("{$prefix}wpe_product_media", [
            'product_id' => $parentId, 'asset_id' => (int)$parentAsset['id'], 'role' => 'featured',
            'position' => 0, 'alt_override' => 'Parent Contextual Alt'
        ]);

        // Map red variation primary with specific alt override
        $this->wpdb->insert("{$prefix}wpe_variant_media", [
            'variant_id' => $varRedId, 'asset_id' => (int)$redAsset['id'], 'position' => 0,
            'is_primary' => 1, 'alt_override' => 'Ruby Red Variant Alt'
        ]);
        // Var Blue has NO image in variant_media (should fallback to parent featured)

        // 3. Test Cart Item Thumbnail for Red variation
        $cartItemRed = [
            'product_id'   => $parentId,
            'variation_id' => $varRedId,
            'quantity'     => 1,
        ];
        $cartHtmlRed = $this->surfaces->filterCartItemThumbnail('', $cartItemRed);

        $this->assert(str_contains($cartHtmlRed, 'red-jacket.jpg'), "Cart thumbnail must contain red variation image URL");
        $this->assert(str_contains($cartHtmlRed, 'Ruby Red Variant Alt'), "Cart thumbnail must use variation alt override");
        $this->assert(str_contains($cartHtmlRed, 'attachment-woocommerce_thumbnail'), "Cart thumbnail must have native WC thumbnail class");

        // 4. Test Cart Item Thumbnail for Blue variation (fallback to parent featured)
        $cartItemBlue = [
            'product_id'   => $parentId,
            'variation_id' => $varBlueId,
            'quantity'     => 1,
        ];
        $cartHtmlBlue = $this->surfaces->filterCartItemThumbnail('', $cartItemBlue);

        $this->assert(str_contains($cartHtmlBlue, 'parent-jacket.jpg'), "Blue variation must deterministically fallback to parent featured image");
        $this->assert(str_contains($cartHtmlBlue, 'Parent Contextual Alt'), "Blue variation must use parent contextual alt override");

        // 5. Test Order Item Thumbnail (emails / order details)
        $orderItemRed = [
            'product_id'   => $parentId,
            'variation_id' => $varRedId,
            'name'         => 'Surface Jacket - Red',
        ];
        $orderHtmlRed = $this->surfaces->filterOrderItemThumbnail('', $orderItemRed);

        $this->assert(str_contains($orderHtmlRed, 'red-jacket.jpg'), "Order item thumbnail must contain red variation image");
        $this->assert(str_contains($orderHtmlRed, 'Ruby Red Variant Alt'), "Order item thumbnail must retain variation alt");

        echo " OK\n";
    }

    /**
     * R96: Enriches JSON-LD schema, OpenGraph tags, and sitemaps with public CDN URLs.
     */
    public function testR96_schema_og_sitemap(): void
    {
        echo " - testR96_schema_og_sitemap...";

        $prefix = $this->wpdb->prefix;

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $productId, 'sku' => 'OG-PRODUCT', 'slug' => 'og-product-' . $productId, 'title' => 'SEO Camera Lens', 'type' => 'simple',
            'status' => 'publish', 'description' => '', 'short_description' => '', 'default_selection' => '{}', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $asset = $this->assetService->registerAsset('https://cdn.example.com/seo/lens-50mm.jpg', [
            'width' => 1920, 'height' => 1080, 'alt_text' => '50mm F1.8 Lens', 'title' => 'Camera Lens 50mm'
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $this->wpdb->insert("{$prefix}wpe_product_media", [
            'product_id' => $productId, 'asset_id' => $assetId, 'role' => 'featured',
            'position' => 0, 'alt_override' => 'Prime Lens 50mm F1.8'
        ]);

        $wcProduct = wc_get_product($productId);

        // 1. Structured Data Schema
        $initialMarkup = [
            '@context' => 'https://schema.org/',
            '@type'    => 'Product',
            'name'     => 'SEO Camera Lens',
        ];
        $schema = $this->surfaces->filterStructuredDataProduct($initialMarkup, $wcProduct);

        $this->assert(!empty($schema['image']), "Schema image must not be empty (AC-T24-01)");
        $this->assert(str_contains((string)$schema['image'], 'lens-50mm.jpg'), "Schema image must contain CDN asset URL");

        // 2. OpenGraph & Twitter Cards
        $ogData = $this->surfaces->getOpenGraphData($wcProduct);

        $this->assert(!empty($ogData['og:image']), "OpenGraph og:image must not be empty (AC-T24-01)");
        $this->assert(str_contains($ogData['og:image'], 'lens-50mm.jpg'), "og:image must point to CDN URL");
        $this->assert((int)$ogData['og:image:width'] === 1920, "og:image:width must match asset width");
        $this->assert((int)$ogData['og:image:height'] === 1080, "og:image:height must match asset height");
        $this->assert($ogData['og:image:alt'] === 'Prime Lens 50mm F1.8', "og:image:alt must use product alt override");
        $this->assert($ogData['twitter:card'] === 'summary_large_image', "twitter:card must be summary_large_image");
        $this->assert(str_contains($ogData['twitter:image'], 'lens-50mm.jpg'), "twitter:image must point to CDN URL");

        $ogHtml = $this->surfaces->renderOpenGraphMetaTags($wcProduct);
        $this->assert(str_contains($ogHtml, '<meta property="og:image" content="https://cdn.example.com/seo/lens-50mm.jpg"'), "Rendered OG HTML must include meta tag");

        // 3. XML Sitemap Images
        $sitemapImages = $this->surfaces->getSitemapImages($productId);
        $this->assert(count($sitemapImages) >= 1, "Sitemap must contain at least 1 image");
        $this->assert(str_contains($sitemapImages[0]['loc'], 'lens-50mm.jpg'), "Sitemap image loc must match CDN URL");

        echo " OK\n";
    }

    /**
     * R97: Adapts WooCommerce Blocks Store API cart items and gallery block endpoints.
     */
    public function testR97_blocks_gallery(): void
    {
        echo " - testR97_blocks_gallery...";

        $prefix = $this->wpdb->prefix;

        $productId = $this->allocator->allocateShell('product');
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$productId, $varId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $productId, 'sku' => 'BLK-RUNNER', 'slug' => 'blk-runner-' . $productId, 'title' => 'Block Runner', 'type' => 'variable',
            'status' => 'publish', 'description' => '', 'short_description' => '', 'default_selection' => '{}', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varId, 'product_id' => $productId, 'sku' => 'BLK-RUNNER-BLK', 'status' => 'publish',
            'combination_key' => hash('sha256', 'black', true), 'description' => '', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $asset = $this->assetService->registerAsset('https://cdn.example.com/blocks/runner-black.jpg', [
            'width' => 1500, 'height' => 1500, 'alt_text' => 'Black Runner Shoe', 'title' => 'Runner Black Edition'
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $this->wpdb->insert("{$prefix}wpe_variant_media", [
            'variant_id' => $varId, 'asset_id' => $assetId, 'position' => 0,
            'is_primary' => 1, 'alt_override' => 'Block Edition Black Shoe'
        ]);

        // 1. Store API Cart Item Images Filter
        $cartItem = [
            'id'           => $productId,
            'variation_id' => $varId,
        ];
        $storeImages = $this->surfaces->filterStoreApiCartItemImages([], $cartItem);

        $this->assert(count($storeImages) === 1, "Store API must return 1 adapted image object");
        $imgObj = $storeImages[0];

        $this->assert(is_object($imgObj), "Store API image must be an object");
        $this->assert($imgObj->id > 0, "Store API image must have valid attachment ID");
        $this->cleanAttachmentIds[] = $imgObj->id;

        $this->assert(str_contains($imgObj->src, 'runner-black.jpg'), "src must contain CDN image URL");
        $this->assert(str_contains($imgObj->thumbnail, 'runner-black.jpg'), "thumbnail must contain CDN image URL");
        $this->assert($imgObj->alt === 'Block Edition Black Shoe', "alt must match variant alt override");
        $this->assert(!empty($imgObj->srcset), "srcset must not be empty");
        $this->assert(!empty($imgObj->thumbnail_srcset), "thumbnail_srcset must not be empty");

        // 2. Gutenberg Product Image Block Render
        $block = ['blockName' => 'woocommerce/product-image'];
        $sampleBlockHtml = '<div class="wp-block-woocommerce-product-image"><img src="https://example.com/placeholder.png" alt="Old Alt" /></div>';

        // Also map to parent so single product view block has image
        $this->wpdb->insert("{$prefix}wpe_product_media", [
            'product_id' => $productId, 'asset_id' => $assetId, 'role' => 'featured', 'position' => 0
        ]);

        global $post;
        $prevPost = $post;
        $post = get_post($productId);

        try {
            $filteredBlockHtml = $this->surfaces->filterProductImageBlock($sampleBlockHtml, $block);
            $this->assert(str_contains($filteredBlockHtml, 'runner-black.jpg'), "Block render must replace src with CDN image URL");
            $this->assert(str_contains($filteredBlockHtml, 'Black Runner Shoe'), "Block render must use asset alt text");
        } finally {
            $post = $prevPost;
        }

        echo " OK\n";
    }

    /**
     * R98: Strips signed/volatile credentials (X-Amz-Signature, X-Amz-Credential, token, secret, signature, auth)
     * before exposing URLs on public surfaces (OG, schema, sitemaps, emails, feeds).
     */
    public function testR98_no_signed_secret_url_exposure(): void
    {
        echo " - testR98_no_signed_secret_url_exposure...";

        // 1. Direct URL Sanitizer Verification
        $dirtyUrl = 'https://s3.amazonaws.com/my-bucket/products/bag.jpg?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20261012%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20261012T100000Z&X-Amz-Expires=3600&X-Amz-SignedHeaders=host&X-Amz-Signature=d41d8cd98f00b204e9800998ecf8427e&token=secret_jwt_token&auth_token=super_secret&sig=12345&access_token=xyz999&v=4.2&format=webp';

        $cleanUrl = ImageSurfaces::sanitizePublicUrl($dirtyUrl);

        $this->assert(!str_contains(strtolower($cleanUrl), 'x-amz-signature'), "Must strip X-Amz-Signature");
        $this->assert(!str_contains(strtolower($cleanUrl), 'x-amz-credential'), "Must strip X-Amz-Credential");
        $this->assert(!str_contains(strtolower($cleanUrl), 'x-amz-algorithm'), "Must strip X-Amz-Algorithm");
        $this->assert(!str_contains(strtolower($cleanUrl), 'secret_jwt_token'), "Must strip token");
        $this->assert(!str_contains(strtolower($cleanUrl), 'auth_token'), "Must strip auth_token");
        $this->assert(!str_contains(strtolower($cleanUrl), 'access_token'), "Must strip access_token");
        $this->assert(!str_contains(strtolower($cleanUrl), 'sig=12345'), "Must strip sig parameter");

        // Non-sensitive version and format parameters must be preserved
        $this->assert(str_contains($cleanUrl, 'v=4.2'), "Safe version parameter v=4.2 must be retained");
        $this->assert(str_contains($cleanUrl, 'format=webp'), "Safe format parameter format=webp must be retained");

        // 2. Embedded Basic Auth userinfo stripping
        $authUrl = 'https://admin:superSecretPassword@cdn.example.com/private/image.jpg?v=1';
        $sanitizedAuth = ImageSurfaces::sanitizePublicUrl($authUrl);
        $this->assert(!str_contains($sanitizedAuth, 'superSecretPassword'), "Embedded basic auth credentials must be stripped");
        $this->assert(!str_contains($sanitizedAuth, 'admin:'), "Embedded username must be stripped");
        $this->assert(str_contains($sanitizedAuth, 'https://cdn.example.com/private/image.jpg?v=1'), "Clean URL preserved");

        // 3. Surface level sanitization test
        $prefix = $this->wpdb->prefix;
        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $productId, 'sku' => 'SEC-BAG', 'slug' => 'sec-bag-' . $productId, 'title' => 'Secret Bag', 'type' => 'simple',
            'status' => 'publish', 'description' => '', 'short_description' => '', 'default_selection' => '{}', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $asset = $this->assetService->registerAsset($dirtyUrl, [
            'width' => 800, 'height' => 800, 'alt_text' => 'Leather Bag', 'title' => 'Bag Title'
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $this->wpdb->insert("{$prefix}wpe_product_media", [
            'product_id' => $productId, 'asset_id' => $assetId, 'role' => 'featured', 'position' => 0
        ]);

        // Schema JSON-LD
        $wcProduct = wc_get_product($productId);
        $schema = $this->surfaces->filterStructuredDataProduct(['@context' => 'https://schema.org/'], $wcProduct);
        $this->assert(!str_contains(strtolower((string)$schema['image']), 'x-amz-signature'), "Schema must never contain signed secret");

        // OpenGraph
        $og = $this->surfaces->getOpenGraphData($wcProduct);
        $this->assert(!str_contains(strtolower($og['og:image']), 'x-amz-signature'), "OG image must never contain signed secret");

        // Sitemaps
        $sitemap = $this->surfaces->getSitemapImages($productId);
        $this->assert(!str_contains(strtolower($sitemap[0]['loc']), 'x-amz-signature'), "Sitemap image must never contain signed secret");

        // Cart thumbnail
        $cartThumb = $this->surfaces->filterCartItemThumbnail('', ['product_id' => $productId]);
        $this->assert(!str_contains(strtolower($cartThumb), 'x-amz-signature'), "Cart thumbnail HTML must never contain signed secret");

        echo " OK\n";
    }

    /**
     * AC-T24-01: Variation image across all surfaces; OG/schema not empty;
     * alt override policy clear where only single attachment alt is supported.
     */
    public function testAC_T24_01_SurfacesCoherenceAndAltOverridePolicy(): void
    {
        echo " - testAC_T24_01_SurfacesCoherenceAndAltOverridePolicy...";

        $prefix = $this->wpdb->prefix;

        $productId = $this->allocator->allocateShell('product');
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$productId, $varId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $productId, 'sku' => 'POLICY-PROD', 'slug' => 'policy-prod-' . $productId, 'title' => 'Policy Watch', 'type' => 'variable',
            'status' => 'publish', 'description' => '', 'short_description' => '', 'default_selection' => '{}', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varId, 'product_id' => $productId, 'sku' => 'POLICY-WATCH-GOLD', 'status' => 'publish',
            'combination_key' => hash('sha256', 'gold', true), 'description' => '', 'extra_props' => '{}',
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $asset = $this->assetService->registerAsset('https://cdn.example.com/policy/gold-watch.jpg', [
            'width' => 1000, 'height' => 1000, 'alt_text' => 'Base Canonical Asset Alt', 'title' => 'Gold Watch'
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        // Context 1: Product has its own alt override
        $this->wpdb->insert("{$prefix}wpe_product_media", [
            'product_id' => $productId, 'asset_id' => $assetId, 'role' => 'featured',
            'position' => 0, 'alt_override' => 'Product Level Contextual Alt'
        ]);

        // Context 2: Variation has its own alt override
        $this->wpdb->insert("{$prefix}wpe_variant_media", [
            'variant_id' => $varId, 'asset_id' => $assetId, 'position' => 0,
            'is_primary' => 1, 'alt_override' => 'Variant Level Contextual Alt'
        ]);

        // 1. Verify Variation Context Resolution
        $varAlt = $this->surfaces->resolveAltText($assetId, $productId, $varId);
        $this->assert($varAlt === 'Variant Level Contextual Alt', "Variation context must resolve variant alt override");

        // 2. Verify Product Context Resolution
        $prodAlt = $this->surfaces->resolveAltText($assetId, $productId, 0);
        $this->assert($prodAlt === 'Product Level Contextual Alt', "Product context must resolve product alt override");

        // 3. Verify Base Asset Fallback Resolution
        $baseAlt = $this->surfaces->resolveAltText($assetId, 0, 0);
        $this->assert($baseAlt === 'Base Canonical Asset Alt', "Empty context must resolve canonical asset alt");

        // 4. Verify Policy Documentation & Attachment Boundary
        $policyDoc = $this->surfaces->getAltOverridePolicyDoc();
        $this->assert(!empty($policyDoc['hierarchy']), "Policy doc hierarchy must be present");
        $this->assert($policyDoc['single_attachment_alt_supported'] === true, "Must explicitly clarify single attachment boundary");

        // 5. Verify Bridge serves canonical asset alt for uncontextualized attachment queries
        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        $uncontextualizedAlt = get_post_meta($attId, '_wp_attachment_image_alt', true);
        $this->assert($uncontextualizedAlt === 'Base Canonical Asset Alt', "Uncontextualized attachment metadata query must return canonical asset alt");

        echo " OK\n";
    }

    /**
     * AC-T24-02: Unsupported SEO and feed plugins are classified as blocked/unknown.
     */
    public function testAC_T24_02_UnsupportedSeoAndFeedPluginsClassified(): void
    {
        echo " - testAC_T24_02_UnsupportedSeoAndFeedPluginsClassified...";

        // 1. Check Yoast SEO
        $yoast = $this->surfaces->checkPluginCompatibility('wordpress-seo');
        $this->assert($yoast['status'] === 'unknown', "Yoast SEO must be classified as unknown");
        $this->assert($yoast['allowed'] === false, "Yoast SEO must not be allowed until certified");

        // 2. Check Rank Math SEO
        $rankMath = $this->surfaces->checkPluginCompatibility('seo-by-rank-math');
        $this->assert($rankMath['status'] === 'unknown', "Rank Math SEO must be classified as unknown");
        $this->assert($rankMath['allowed'] === false, "Rank Math SEO must not be allowed until certified");

        // 3. Check Google Product Feed (direct SQL reader)
        $gFeed = $this->surfaces->checkPluginCompatibility('google-product-feed-for-woocommerce');
        $this->assert($gFeed['status'] === 'blocked', "Google Product Feed must be classified as blocked");
        $this->assert($gFeed['classification'] === 'SQL-reader', "Google Product Feed must be classified as SQL-reader");
        $this->assert($gFeed['allowed'] === false, "Google Product Feed must not be allowed");

        // 4. Check CTX Feed
        $ctxFeed = $this->surfaces->checkPluginCompatibility('ctx-feed');
        $this->assert($ctxFeed['status'] === 'blocked', "CTX Feed must be classified as blocked");
        $this->assert($ctxFeed['allowed'] === false, "CTX Feed must not be allowed");

        // 5. Check arbitrary unregistered third-party plugin
        $arbitrarySeo = $this->surfaces->checkPluginCompatibility('custom-random-seo');
        $this->assert($arbitrarySeo['status'] === 'unknown', "Unregistered SEO plugin must default to unknown");
        $this->assert($arbitrarySeo['allowed'] === false, "Unregistered plugin must not be allowed");

        $arbitraryFeed = $this->surfaces->checkPluginCompatibility('custom-xml-feed');
        $this->assert($arbitraryFeed['status'] === 'blocked', "Unregistered Feed plugin must default to blocked");
        $this->assert($arbitraryFeed['allowed'] === false, "Unregistered feed plugin must not be allowed");

        echo " OK\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException("Assertion failed: " . $message);
        }
    }
}

$test = new T24Test();
$test->runAll();
