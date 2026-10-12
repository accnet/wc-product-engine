<?php

namespace WPE\Compatibility\Gallery;

use RuntimeException;
use InvalidArgumentException;
use WC_Product;
use WC_Product_Variation;
use WC_Order_Item_Product;
use WP_Post;
use WPE\Infrastructure\Database\MediaRepository;

/**
 * Image surfaces adapter for WooCommerce external CDN images.
 *
 * Provides compatibility across consumer surfaces:
 * - Cart, mini-cart, and checkout thumbnails (R95, AC-T24-01)
 * - Order item thumbnails, order confirmation, and order emails (R95)
 * - Product schema (JSON-LD structured data), OpenGraph, Twitter cards, and sitemaps (R96, AC-T24-01)
 * - WooCommerce Blocks Store API cart items and block rendering (R97)
 * - Strips signed/volatile secret credentials before exposure on public surfaces (R98)
 * - Alt override policy clearly distinguishing contextual vs single-attachment postmeta (AC-T24-01)
 * - Third-party SEO and feed plugin compatibility matrix (AC-T24-02)
 */
class ImageSurfaces
{
    private static ?ImageSurfaces $instance = null;
    private static bool $filtersRegistered = false;

    private \wpdb $wpdb;
    private AssetService $assetService;
    private AttachmentBridge $bridge;
    private GalleryAdapter $galleryAdapter;
    private MediaRepository $mediaRepo;

    private string $tableAssets;
    private string $tableProductMedia;
    private string $tableVariantMedia;

    /**
     * Known third-party SEO and Feed plugins and their compatibility status.
     * Unregistered or uncertified plugins default to 'unknown' or 'blocked' (AC-T24-02).
     */
    private array $seoAndFeedRegistry = [
        'wordpress-seo' => [
            'name' => 'Yoast SEO',
            'type' => 'seo',
            'status' => 'unknown',
            'classification' => 'WP API bridge / Schema producer',
            'notes' => 'Requires verification with native WPE schema filters before certifying',
        ],
        'seo-by-rank-math' => [
            'name' => 'Rank Math SEO',
            'type' => 'seo',
            'status' => 'unknown',
            'classification' => 'WP API bridge / Schema producer',
            'notes' => 'Requires verification with native WPE schema filters before certifying',
        ],
        'all-in-one-seo-pack' => [
            'name' => 'All in One SEO',
            'type' => 'seo',
            'status' => 'unknown',
            'classification' => 'WP API bridge / Schema producer',
            'notes' => 'Requires verification with native WPE schema filters before certifying',
        ],
        'google-product-feed-for-woocommerce' => [
            'name' => 'Google Product Feed',
            'type' => 'feed',
            'status' => 'blocked',
            'classification' => 'SQL-reader',
            'notes' => 'Direct queries wp_postmeta for image URLs without bridge; blocked until adapter implemented',
        ],
        'woocommerce-product-feeds' => [
            'name' => 'WooCommerce Product Feeds',
            'type' => 'feed',
            'status' => 'blocked',
            'classification' => 'SQL-reader',
            'notes' => 'Unadapted feed generator reading postmeta directly; blocked for native authority',
        ],
        'ctx-feed' => [
            'name' => 'CTX Feed',
            'type' => 'feed',
            'status' => 'blocked',
            'classification' => 'SQL-reader',
            'notes' => 'Direct database reader; blocked for native authority',
        ],
    ];

    public function __construct(
        ?\wpdb $wpdb = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $bridge = null,
        ?GalleryAdapter $galleryAdapter = null,
        ?MediaRepository $mediaRepo = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->mediaRepo = $mediaRepo ?? new MediaRepository($this->wpdb);
        $this->assetService = $assetService ?? new AssetService($this->wpdb, $this->mediaRepo);
        $this->bridge = $bridge ?? AttachmentBridge::instance($this->wpdb);
        $this->galleryAdapter = $galleryAdapter ?? GalleryAdapter::instance($this->wpdb);

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
     * Register WooCommerce and WordPress hooks for all consumer image surfaces.
     */
    public static function register(?ImageSurfaces $instance = null): self
    {
        $surfaces = $instance ?? self::instance();

        if (self::$filtersRegistered) {
            return $surfaces;
        }

        // Cart and checkout thumbnails (R95)
        add_filter('woocommerce_cart_item_thumbnail', [$surfaces, 'filterCartItemThumbnail'], 10, 3);

        // Order history, admin, and email item thumbnails (R95)
        add_filter('woocommerce_order_item_thumbnail', [$surfaces, 'filterOrderItemThumbnail'], 10, 2);
        add_filter('woocommerce_admin_order_item_thumbnail', [$surfaces, 'filterAdminOrderItemThumbnail'], 10, 3);

        // Structured data JSON-LD schema (R96, AC-T24-01)
        add_filter('woocommerce_structured_data_product', [$surfaces, 'filterStructuredDataProduct'], 10, 2);

        // OpenGraph and Twitter cards in head (R96, AC-T24-01)
        add_action('wp_head', [$surfaces, 'injectOpenGraphTags'], 5);

        // Blocks Store API cart items (R97)
        add_filter('woocommerce_store_api_cart_item_images', [$surfaces, 'filterStoreApiCartItemImages'], 10, 3);

        // Blocks single product image rendering (R97)
        add_filter('render_block_woocommerce/product-image', [$surfaces, 'filterProductImageBlock'], 10, 2);

        // Sitemaps entry filtering (R96)
        add_filter('wp_sitemaps_posts_entry', [$surfaces, 'filterSitemapPostsEntry'], 10, 3);

        self::$filtersRegistered = true;
        return $surfaces;
    }

    /* -------------------------------------------------------------------------- */
    /* Cart, Checkout, and Order Email Thumbnails (R95)                           */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept cart and mini-cart item thumbnail markup.
     * Guarantees variation-specific CDN image or deterministic parent fallback.
     */
    public function filterCartItemThumbnail(string $thumbnailHtml, array $cartItem, string $cartItemKey = ''): string
    {
        $variationId = !empty($cartItem['variation_id']) ? (int)$cartItem['variation_id'] : 0;
        $productId = !empty($cartItem['product_id']) ? (int)$cartItem['product_id'] : 0;

        if (isset($cartItem['data'])) {
            $data = $cartItem['data'];
            if ($data instanceof WC_Product_Variation) {
                $variationId = (int)$data->get_id();
                $productId = (int)$data->get_parent_id();
            } elseif ($data instanceof WC_Product && $productId === 0) {
                $productId = (int)$data->get_id();
            }
        }

        $imageHtml = $this->renderThumbnailHtml($productId, $variationId, 'woocommerce_thumbnail');
        return $imageHtml ?: $thumbnailHtml;
    }

    /**
     * Intercept order item thumbnail in emails and order details.
     */
    public function filterOrderItemThumbnail(string $thumbnailHtml, $item): string
    {
        $productId = 0;
        $variationId = 0;

        if ($item instanceof WC_Order_Item_Product) {
            $variationId = (int)$item->get_variation_id();
            $productId = (int)$item->get_product_id();
        } elseif (is_array($item)) {
            $variationId = (int)($item['variation_id'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
        }

        $imageHtml = $this->renderThumbnailHtml($productId, $variationId, 'woocommerce_thumbnail');
        return $imageHtml ?: $thumbnailHtml;
    }

    /**
     * Intercept admin order item thumbnail.
     */
    public function filterAdminOrderItemThumbnail(string $thumbnailHtml, $itemId, $item): string
    {
        return $this->filterOrderItemThumbnail($thumbnailHtml, $item);
    }

    /**
     * Render HTML thumbnail tag for a product or variation with CDN rendition, srcset, and alt.
     */
    public function renderThumbnailHtml(int $productId, int $variationId = 0, string $size = 'woocommerce_thumbnail'): ?string
    {
        $asset = null;
        $altOverride = null;

        // 1. If variation specified, look for variation primary image
        if ($variationId > 0) {
            $sql = "SELECT a.*, vm.alt_override
                    FROM {$this->tableVariantMedia} vm
                    JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                    WHERE vm.variant_id = %d AND vm.is_primary = 1
                    LIMIT 1";
            $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $variationId), ARRAY_A);
            if ($row) {
                $asset = $row;
                $altOverride = $row['alt_override'];
            }
        }

        // 2. Fallback to parent product featured image
        if (!$asset && $productId > 0) {
            $sql = "SELECT a.*, pm.alt_override
                    FROM {$this->tableProductMedia} pm
                    JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                    WHERE pm.product_id = %d AND pm.role = 'featured'
                    LIMIT 1";
            $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);
            if ($row) {
                $asset = $row;
                $altOverride = $row['alt_override'];
            }
        }

        if (!$asset) {
            return null;
        }

        // Broken health check fallback
        if (($asset['health'] ?? 'healthy') === 'broken') {
            return sprintf(
                '<img width="300" height="300" src="%s" class="attachment-%s size-%s" alt="%s" loading="lazy" />',
                esc_url(AssetService::PLACEHOLDER_DATA_URI),
                esc_attr($size),
                esc_attr($size),
                esc_attr($altOverride ?: ($asset['alt_text'] ?: 'Image unavailable'))
            );
        }

        $renditionThumb = $this->assetService->getRendition($asset, $size);
        $renditionFull  = $this->assetService->getRendition($asset, 'full');

        // Sanitize URLs to remove signed/secret tokens (R98)
        $thumbUrl = self::sanitizePublicUrl($renditionThumb['url']);
        $fullUrl  = self::sanitizePublicUrl($renditionFull['url']);

        $width  = (int)($renditionThumb['width'] ?: 300);
        $height = (int)($renditionThumb['height'] ?: 300);
        $alt    = $this->resolveAltText((int)$asset['id'], $productId, $variationId);

        $srcset = sprintf('%s %dw, %s %dw', $thumbUrl, $width, $fullUrl, (int)($renditionFull['width'] ?: 1920));
        $sizes  = sprintf('(max-width: %dpx) 100vw, %dpx', $width, $width);

        return sprintf(
            '<img width="%d" height="%d" src="%s" class="attachment-%s size-%s" alt="%s" loading="lazy" decoding="async" srcset="%s" sizes="%s" />',
            $width,
            $height,
            esc_url($thumbUrl),
            esc_attr($size),
            esc_attr($size),
            esc_attr($alt),
            esc_attr($srcset),
            esc_attr($sizes)
        );
    }

    /* -------------------------------------------------------------------------- */
    /* Structured Data (Schema.org JSON-LD), OpenGraph, and Sitemaps (R96)       */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept WooCommerce product structured data.
     * Enriches JSON-LD markup with canonical CDN image URLs without secret parameters.
     */
    public function filterStructuredDataProduct(array $markup, $product): array
    {
        if (!$product instanceof WC_Product) {
            return $markup;
        }

        $productId = (int)$product->get_id();
        $images = $this->getProductPublicImages($productId, $product->is_type('variable'));

        if (!empty($images)) {
            $markup['image'] = count($images) === 1 ? $images[0]['url'] : array_column($images, 'url');
        }

        return $markup;
    }

    /**
     * Generate OpenGraph and Twitter card metadata for a product.
     * Guarantees non-empty og:image and sanitizes URLs against secret exposure (R96, R98, AC-T24-01).
     */
    public function getOpenGraphData(?WC_Product $product = null): array
    {
        if ($product === null) {
            if (function_exists('is_product') && is_product()) {
                $product = wc_get_product(get_the_ID());
            }
        }

        if (!$product instanceof WC_Product) {
            return [];
        }

        $productId = (int)$product->get_id();
        $images = $this->getProductPublicImages($productId, false);

        if (empty($images)) {
            return [];
        }

        $primary = $images[0];
        $alt = $this->resolveAltText($primary['asset_id'], $productId, 0);

        return [
            'og:image'            => $primary['url'],
            'og:image:secure_url' => $primary['url'],
            'og:image:width'      => $primary['width'],
            'og:image:height'     => $primary['height'],
            'og:image:alt'        => $alt,
            'og:image:type'       => $primary['mime'] ?? 'image/jpeg',
            'twitter:image'       => $primary['url'],
            'twitter:card'        => 'summary_large_image',
        ];
    }

    /**
     * Render OpenGraph and Twitter meta tag HTML strings.
     */
    public function renderOpenGraphMetaTags(?WC_Product $product = null): string
    {
        $data = $this->getOpenGraphData($product);
        if (empty($data)) {
            return '';
        }

        $html = '';
        foreach ($data as $property => $content) {
            if (empty($content)) {
                continue;
            }
            if (str_starts_with($property, 'twitter:')) {
                $html .= sprintf('<meta name="%s" content="%s" />' . "\n", esc_attr($property), esc_attr((string)$content));
            } else {
                $html .= sprintf('<meta property="%s" content="%s" />' . "\n", esc_attr($property), esc_attr((string)$content));
            }
        }
        return $html;
    }

    /**
     * Inject OpenGraph tags into wp_head when viewing a single product.
     */
    public function injectOpenGraphTags(): void
    {
        $tags = $this->renderOpenGraphMetaTags();
        if (!empty($tags)) {
            echo $tags;
        }
    }

    /**
     * Retrieve clean, public CDN image URLs for XML sitemaps and merchant feeds.
     */
    public function getSitemapImages(int $productId): array
    {
        $images = $this->getProductPublicImages($productId, true);
        $result = [];

        foreach ($images as $img) {
            $result[] = [
                'loc'     => $img['url'],
                'title'   => $img['title'] ?: get_the_title($productId),
                'caption' => $img['caption'] ?: '',
            ];
        }

        return $result;
    }

    /**
     * Filter WordPress core sitemap post entry for product images.
     */
    public function filterSitemapPostsEntry(array $entry, WP_Post $post, string $postType): array
    {
        if ($postType !== 'product') {
            return $entry;
        }

        $sitemapImages = $this->getSitemapImages((int)$post->ID);
        if (!empty($sitemapImages)) {
            $entry['images'] = $sitemapImages;
        }

        return $entry;
    }

    /* -------------------------------------------------------------------------- */
    /* WooCommerce Blocks & Store API Adapter (R97)                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Adapt Store API cart item images response.
     * Returns an array of image objects conforming to WooCommerce ImageAttachmentSchema.
     */
    public function filterStoreApiCartItemImages(array $productImages, array $cartItem, string $cartItemKey = ''): array
    {
        $variationId = !empty($cartItem['variation_id']) ? (int)$cartItem['variation_id'] : 0;
        $productId = !empty($cartItem['id']) ? (int)$cartItem['id'] : (!empty($cartItem['product_id']) ? (int)$cartItem['product_id'] : 0);

        $asset = null;
        $altOverride = null;

        if ($variationId > 0) {
            $sql = "SELECT a.*, vm.alt_override
                    FROM {$this->tableVariantMedia} vm
                    JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                    WHERE vm.variant_id = %d AND vm.is_primary = 1
                    LIMIT 1";
            $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $variationId), ARRAY_A);
            if ($row) {
                $asset = $row;
                $altOverride = $row['alt_override'];
            }
        }

        if (!$asset && $productId > 0) {
            $sql = "SELECT a.*, pm.alt_override
                    FROM {$this->tableProductMedia} pm
                    JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                    WHERE pm.product_id = %d AND pm.role = 'featured'
                    LIMIT 1";
            $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);
            if ($row) {
                $asset = $row;
                $altOverride = $row['alt_override'];
            }
        }

        if (!$asset) {
            return $productImages;
        }

        $renditionFull  = $this->assetService->getRendition($asset, 'full');
        $renditionThumb = $this->assetService->getRendition($asset, 'woocommerce_thumbnail');

        $fullUrl  = self::sanitizePublicUrl($renditionFull['url']);
        $thumbUrl = self::sanitizePublicUrl($renditionThumb['url']);
        $alt      = $this->resolveAltText((int)$asset['id'], $productId, $variationId);

        $attachmentId = (int)($asset['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            $attachmentId = $this->bridge->getOrCreateAttachmentId((int)$asset['id']);
        }

        $thumbW = (int)($renditionThumb['width'] ?: 300);
        $fullW  = (int)($renditionFull['width'] ?: 1920);

        $imageObject = (object) [
            'id'               => $attachmentId,
            'src'              => $fullUrl,
            'thumbnail'        => $thumbUrl,
            'srcset'           => sprintf('%s %dw, %s %dw', $thumbUrl, $thumbW, $fullUrl, $fullW),
            'sizes'            => '(max-width: 600px) 100vw, 600px',
            'thumbnail_srcset' => sprintf('%s %dw', $thumbUrl, $thumbW),
            'thumbnail_sizes'  => sprintf('(max-width: %dpx) 100vw, %dpx', $thumbW, $thumbW),
            'name'             => (string)($asset['title'] ?: 'Product image'),
            'alt'              => (string)$alt,
        ];

        return [$imageObject];
    }

    /**
     * Filter Gutenberg WooCommerce product image block HTML rendering.
     */
    public function filterProductImageBlock(string $blockContent, array $block): string
    {
        $productId = get_the_ID();
        if (!$productId) {
            return $blockContent;
        }

        $sql = "SELECT a.*, pm.alt_override
                FROM {$this->tableProductMedia} pm
                JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                WHERE pm.product_id = %d AND pm.role = 'featured'
                LIMIT 1";
        $asset = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);

        if (!$asset) {
            return $blockContent;
        }

        $rendition = $this->assetService->getRendition($asset, 'woocommerce_single');
        $cleanUrl = self::sanitizePublicUrl($rendition['url']);
        $alt = $this->resolveAltText((int)$asset['id'], $productId, 0);

        // Replace src in img tag if present
        if (preg_match('/<img[^>]+>/', $blockContent, $matches)) {
            $imgTag = $matches[0];
            $newImgTag = preg_replace('/src="[^"]*"/', sprintf('src="%s"', esc_url($cleanUrl)), $imgTag);
            $newImgTag = preg_replace('/alt="[^"]*"/', sprintf('alt="%s"', esc_attr($alt)), $newImgTag);
            return str_replace($imgTag, $newImgTag, $blockContent);
        }

        return $blockContent;
    }

    /* -------------------------------------------------------------------------- */
    /* Public URL Sanitization & No Signed Secret URL Exposure (R98)             */
    /* -------------------------------------------------------------------------- */

    /**
     * Strips signed/volatile credentials and query secrets from URLs before exposing on public surfaces.
     * Prevents AWS S3 / CloudFront / GCS signed parameter leaks in schema, OG, sitemaps, emails, feeds.
     */
    public static function sanitizePublicUrl(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return $url;
        }

        $volatileKeys = [
            'x-amz-signature',
            'x-amz-algorithm',
            'x-amz-credential',
            'x-amz-date',
            'x-amz-expires',
            'x-amz-signedheaders',
            'x-amz-security-token',
            'token',
            'secret',
            'signature',
            'sig',
            'auth',
            'access_token',
            'expires',
            'key',
            'key-pair-id',
            'policy',
            'temp_url_sig',
        ];

        $retainedParams = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            foreach ($queryParams as $key => $val) {
                $lowerKey = strtolower((string)$key);
                if (in_array($lowerKey, $volatileKeys, true)) {
                    continue;
                }
                if (str_ends_with($lowerKey, '_signature') ||
                    str_ends_with($lowerKey, '_token') ||
                    str_ends_with($lowerKey, '_secret') ||
                    str_ends_with($lowerKey, '_sig')) {
                    continue;
                }
                $retainedParams[$key] = $val;
            }
        }

        $scheme   = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host     = $parsed['host'] ?? '';
        $port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        // Never output embedded basic auth credentials on public surfaces
        $path     = $parsed['path'] ?? '';
        $query    = !empty($retainedParams) ? '?' . http_build_query($retainedParams) : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $fragment;
    }

    /* -------------------------------------------------------------------------- */
    /* Alt Override Policy (AC-T24-01)                                           */
    /* -------------------------------------------------------------------------- */

    /**
     * Resolves alt text following the documented hierarchy:
     * 1. Variation contextual override (wpe_variant_media.alt_override)
     * 2. Product contextual override (wpe_product_media.alt_override)
     * 3. Base asset alt text (wpe_assets.alt_text)
     * 4. Where only a single attachment alt can be provided (global postmeta boundary),
     *    the canonical asset alt_text is served by the bridge.
     */
    public function resolveAltText(int $assetId, ?int $productId = null, ?int $variationId = null): string
    {
        // 1. Variation context
        if ($variationId !== null && $variationId > 0) {
            $sql = "SELECT alt_override FROM {$this->tableVariantMedia} WHERE variant_id = %d AND asset_id = %d LIMIT 1";
            $override = $this->wpdb->get_var($this->wpdb->prepare($sql, $variationId, $assetId));
            if (!empty($override)) {
                return (string)$override;
            }
        }

        // 2. Product context
        if ($productId !== null && $productId > 0) {
            $sql = "SELECT alt_override FROM {$this->tableProductMedia} WHERE product_id = %d AND asset_id = %d LIMIT 1";
            $override = $this->wpdb->get_var($this->wpdb->prepare($sql, $productId, $assetId));
            if (!empty($override)) {
                return (string)$override;
            }
        }

        // 3. Asset fallback
        $sql = "SELECT alt_text FROM {$this->tableAssets} WHERE id = %d LIMIT 1";
        $alt = $this->wpdb->get_var($this->wpdb->prepare($sql, $assetId));

        return (string)($alt ?: '');
    }

    /**
     * Return documentation on alt override policy.
     */
    public function getAltOverridePolicyDoc(): array
    {
        return [
            'policy' => 'contextual_precedence_with_canonical_fallback',
            'hierarchy' => [
                '1_variation_context' => 'wpe_variant_media.alt_override takes precedence in cart items, PDP variant selections, and Store API cart',
                '2_product_context'   => 'wpe_product_media.alt_override takes precedence in parent gallery, schema.org, and OpenGraph',
                '3_canonical_asset'   => 'wpe_assets.alt_text serves as the base fallback when no override is set',
                '4_postmeta_boundary' => 'WordPress _wp_attachment_image_alt postmeta supports only one global value per attachment; AttachmentBridge serves canonical asset alt_text for uncontextualized queries',
            ],
            'single_attachment_alt_supported' => true,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* Third-Party SEO & Feed Compatibility Matrix (AC-T24-02)                    */
    /* -------------------------------------------------------------------------- */

    /**
     * Check compatibility status for an SEO or Feed plugin.
     * Unsupported or uncertified plugins are returned as 'unknown' or 'blocked'.
     */
    public function checkPluginCompatibility(string $slug): array
    {
        // 1. Check local registry first
        if (isset($this->seoAndFeedRegistry[$slug])) {
            $entry = $this->seoAndFeedRegistry[$slug];
            return [
                'slug'           => $slug,
                'status'         => $entry['status'],
                'type'           => $entry['type'],
                'classification' => $entry['classification'],
                'notes'          => $entry['notes'],
                'allowed'        => ($entry['status'] === 'supported'),
            ];
        }

        // 2. Check matrix.json if available
        $matrixFile = dirname(__DIR__, 3) . '/contracts/compatibility/matrix.json';
        if (file_exists($matrixFile)) {
            $matrix = json_decode(file_get_contents($matrixFile), true);
            if (isset($matrix['third_party_plugins'])) {
                foreach ($matrix['third_party_plugins'] as $p) {
                    if (($p['slug'] ?? '') === $slug) {
                        return [
                            'slug'           => $slug,
                            'status'         => $p['status'] ?? 'unknown',
                            'type'           => 'general',
                            'classification' => $p['classification'] ?? 'unspecified',
                            'notes'          => $p['notes'] ?? '',
                            'allowed'        => (($p['status'] ?? '') === 'supported'),
                        ];
                    }
                }
            }
        }

        // 3. Default for uncertified plugins: unknown or blocked
        $isFeed = str_contains($slug, 'feed');
        $status = $isFeed ? 'blocked' : 'unknown';

        return [
            'slug'           => $slug,
            'status'         => $status,
            'type'           => $isFeed ? 'feed' : 'seo',
            'classification' => $isFeed ? 'SQL-reader' : 'WP API bridge',
            'notes'          => 'Unregistered third-party plugin; native cohort rollout is blocked until certified',
            'allowed'        => false,
        ];
    }

    /**
     * Retrieve the SEO and Feed compatibility registry.
     */
    public function getSeoAndFeedRegistry(): array
    {
        return $this->seoAndFeedRegistry;
    }

    /**
     * Audit currently active plugins on the site for SEO and Feed compatibility.
     */
    public function auditActiveSeoAndFeedPlugins(): array
    {
        $activePlugins = (array)get_option('active_plugins', []);
        $audit = [];

        foreach ($activePlugins as $pluginPath) {
            $slug = dirname($pluginPath);
            if ($slug === '.' || empty($slug)) {
                $slug = basename($pluginPath, '.php');
            }

            // Check if plugin matches known SEO or Feed keywords
            if (isset($this->seoAndFeedRegistry[$slug]) ||
                str_contains($slug, 'seo') ||
                str_contains($slug, 'feed') ||
                str_contains($slug, 'sitemap')) {
                $audit[$slug] = $this->checkPluginCompatibility($slug);
            }
        }

        return $audit;
    }

    /* -------------------------------------------------------------------------- */
    /* Helpers                                                                    */
    /* -------------------------------------------------------------------------- */

    /**
     * Helper to fetch public sanitized CDN images for a product.
     */
    private function getProductPublicImages(int $productId, bool $includeGallery = false): array
    {
        $sql = "SELECT a.*, pm.role, pm.position
                FROM {$this->tableProductMedia} pm
                JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                WHERE pm.product_id = %d AND (a.health IS NULL OR a.health = 'healthy')
                ORDER BY CASE WHEN pm.role = 'featured' THEN 0 ELSE 1 END, pm.position ASC";

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $productId), ARRAY_A);
        if (empty($rows)) {
            return [];
        }

        $images = [];
        foreach ($rows as $row) {
            if (!$includeGallery && $row['role'] !== 'featured') {
                continue;
            }

            $rendition = $this->assetService->getRendition($row, 'full');
            $cleanUrl  = self::sanitizePublicUrl($rendition['url']);

            $images[] = [
                'asset_id' => (int)$row['id'],
                'url'      => $cleanUrl,
                'width'    => (int)($rendition['width'] ?: 1920),
                'height'   => (int)($rendition['height'] ?: 1080),
                'mime'     => $row['mime'] ?: 'image/jpeg',
                'title'    => $row['title'] ?: '',
                'caption'  => $row['caption'] ?: '',
                'role'     => $row['role'],
            ];
        }

        return $images;
    }
}
