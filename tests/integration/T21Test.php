<?php
/**
 * T21 Integration Test — URL registry, providers và rendition contract.
 *
 * Verifies:
 * - R83 (ssrf_redirect_dns): SSRF validation blocking private IPs and dangerous redirect chains during probe.
 * - R84 (url_query_not_stripped): URL dedup compares full canonical URL preserving query strings.
 * - R85 (rendition_dimensions): Provider interface generates explicit rendition dimensions and format widths.
 * - R86 (render_no_network_probe): Rendering image HTML/tags performs 0 runtime network probes.
 * - AC-T21-01: No network calls during image rendering; invalid/private redirect rejected; duplicate URL idempotent with per-product alt preserved.
 * - AC-T21-02: Broken/unreachable image URLs return SVG/placeholder rather than causing PDP fatal errors.
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
use WPE\Compatibility\Gallery\DefaultCdnProvider;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Infrastructure\Database\MediaRepository;

class T21Test
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private AssetService $service;

    private array $cleanAssetIds = [];
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->mediaRepo = new MediaRepository($this->wpdb);
        $this->service = new AssetService($this->wpdb, $this->mediaRepo);
    }

    public function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;
        if (!empty($this->cleanAssetIds)) {
            $ids = implode(',', array_map('intval', $this->cleanAssetIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE asset_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE asset_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_assets WHERE id IN ({$ids})");
            $this->cleanAssetIds = [];
        }

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$ids})");
            $this->cleanProductIds = [];
        }

        UrlValidator::setDnsResolver(null);
        $this->service->setHttpTransport(null);
    }

    public function runAll(): void
    {
        echo "Running T21 Integration Tests...\n";

        try {
            $this->testR83_ssrf_redirect_dns();
            $this->testR84_url_query_not_stripped();
            $this->testR85_rendition_dimensions();
            $this->testR86_render_no_network_probe();
            $this->testAC_T21_01_DedupAndSsrfIntegrity();
            $this->testAC_T21_02_BrokenUrlPlaceholderFallback();

            echo "All T21 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    /**
     * R83: SSRF validation blocking private IPs and dangerous redirect chains during probe.
     */
    public function testR83_ssrf_redirect_dns(): void
    {
        echo " - testR83_ssrf_redirect_dns...";

        $validator = new UrlValidator(false);

        // 1. Direct restricted IP addresses and local hostnames
        $restrictedUrls = [
            'https://127.0.0.1/image.jpg',
            'https://127.0.0.50:8080/image.jpg',
            'https://10.0.0.1/test.png',
            'https://172.16.5.10/photo.webp',
            'https://172.31.255.254/image.jpg',
            'https://192.168.1.1/secret.jpg',
            'https://169.254.169.254/latest/meta-data',
            'https://0.0.0.0/test.jpg',
            'https://localhost/image.jpg',
            'https://internal.localhost/image.jpg',
            'https://service.local/image.jpg',
            'https://[::1]/image.jpg',
        ];

        foreach ($restrictedUrls as $badUrl) {
            $val = $validator->validateUrl($badUrl);
            if ($val['valid']) {
                throw new RuntimeException("R83 Failed: Expected SSRF block for '{$badUrl}', but got valid.");
            }
        }

        // 2. Disallow insecure non-HTTPS schemes
        $nonHttpsUrls = [
            'http://cdn.example.com/image.jpg',
            'file:///etc/passwd',
            'gopher://127.0.0.1:6379/1',
            'ftp://cdn.example.com/image.jpg',
            'data:image/png;base64,iVBOR',
        ];

        foreach ($nonHttpsUrls as $nonHttps) {
            $val = $validator->validateUrl($nonHttps);
            if ($val['valid']) {
                throw new RuntimeException("R83 Failed: Insecure scheme '{$nonHttps}' was not rejected.");
            }
        }

        // 3. Disallow credentials embedded in URL (10 §4)
        $credentialUrl = 'https://admin:secretpass@cdn.example.com/image.jpg';
        $valCred = $validator->validateUrl($credentialUrl);
        if ($valCred['valid']) {
            throw new RuntimeException("R83 Failed: Embedded credentials were not rejected.");
        }

        // 4. Test DNS resolution resolving to private/metadata IP (R83)
        UrlValidator::setDnsResolver(function (string $host): array {
            if ($host === 'metadata.internal' || str_contains($host, 'metadata')) {
                return ['169.254.169.254'];
            }
            if ($host === 'internal.lan' || str_contains($host, 'internal')) {
                return ['10.0.0.5'];
            }
            if ($host === 'private.example.com') {
                return ['192.168.1.100'];
            }
            return ['93.184.215.14']; // Default safe public IP (example.com)
        });

        $dnsPrivateVal = $validator->validateUrl('https://internal.lan/secret.jpg');
        if ($dnsPrivateVal['valid']) {
            throw new RuntimeException("R83 Failed: Host resolving to private IP 10.0.0.5 was not rejected.");
        }

        $dnsMetaVal = $validator->validateUrl('https://metadata.internal/meta.jpg');
        if ($dnsMetaVal['valid']) {
            throw new RuntimeException("R83 Failed: Host resolving to metadata IP 169.254.169.254 was not rejected.");
        }

        // 5. Test dangerous redirect chains during probe
        // Mock transport that redirects to a private IP (169.254.169.254 / metadata)
        $mockTransportCalls = 0;
        $mockTransport = function (string $method, string $url, array $headers) use (&$mockTransportCalls): array {
            $mockTransportCalls++;
            if ($url === 'https://cdn.example.com/banner.jpg') {
                return [
                    'status_code' => 302,
                    'headers'     => ['location' => 'https://169.254.169.254/latest/meta-data'],
                    'body'        => '',
                    'error'       => null,
                ];
            }
            if ($url === 'https://169.254.169.254/latest/meta-data') {
                throw new RuntimeException("SECURITY BREACH: Probe connected to restricted IP!");
            }
            return [
                'status_code' => 200,
                'headers'     => ['content-type' => 'image/jpeg'],
                'body'        => '',
                'error'       => null,
            ];
        };

        $this->service->setHttpTransport($mockTransport);

        // Probe should detect that redirect target is private IP and abort without connecting
        $probeResult = $this->service->probeUrl('https://cdn.example.com/banner.jpg');
        if ($probeResult['healthy']) {
            throw new RuntimeException("R83 Failed: Probe followed redirect to private IP!");
        }
        if (!str_contains($probeResult['error'], 'SSRF')) {
            throw new RuntimeException("R83 Failed: Expected SSRF error message on redirect, got: " . ($probeResult['error'] ?? ''));
        }

        // 6. Test redirect loop
        $loopTransport = function (string $method, string $url) {
            if ($url === 'https://cdn.example.com/loop1.jpg') {
                return ['status_code' => 302, 'headers' => ['location' => 'https://cdn.example.com/loop2.jpg'], 'body' => '', 'error' => null];
            }
            return ['status_code' => 302, 'headers' => ['location' => 'https://cdn.example.com/loop1.jpg'], 'body' => '', 'error' => null];
        };

        $this->service->setHttpTransport($loopTransport);
        $loopResult = $this->service->probeUrl('https://cdn.example.com/loop1.jpg');
        if ($loopResult['healthy'] || !str_contains($loopResult['error'], 'loop')) {
            throw new RuntimeException("R83 Failed: Circular redirect loop was not detected.");
        }

        $this->service->setHttpTransport(null);
        echo " OK\n";
    }

    /**
     * R84: URL dedup compares full canonical URL preserving query strings.
     */
    public function testR84_url_query_not_stripped(): void
    {
        echo " - testR84_url_query_not_stripped...";

        $urlV1 = 'https://cdn.example.com/products/shoe.jpg?w=800&v=1.0';
        $urlV2 = 'https://cdn.example.com/products/shoe.jpg?w=800&v=2.0';

        // Register both URLs
        $asset1 = $this->service->registerAsset($urlV1, ['width' => 800, 'height' => 600, 'alt_text' => 'Default Alt']);
        $asset2 = $this->service->registerAsset($urlV2, ['width' => 800, 'height' => 600, 'alt_text' => 'Default Alt']);

        $this->cleanAssetIds[] = (int)$asset1['id'];
        $this->cleanAssetIds[] = (int)$asset2['id'];

        // Verify that different query parameters generate different asset records and different url_key
        if ((int)$asset1['id'] === (int)$asset2['id']) {
            throw new RuntimeException("R84 Failed: URL query strings were stripped; two distinct versions resulted in the same asset ID.");
        }
        if ($asset1['url_key_hex'] === $asset2['url_key_hex']) {
            throw new RuntimeException("R84 Failed: url_key hash must differ when query strings differ.");
        }

        // Registering the exact same canonical URL again must be idempotent and reuse the existing record
        $asset1Duplicate = $this->service->registerAsset($urlV1);
        if ((int)$asset1Duplicate['id'] !== (int)$asset1['id']) {
            throw new RuntimeException("R84 Failed: Exact duplicate canonical URL did not reuse existing asset ID.");
        }

        // Test stable export URL strips volatile signatures but preserves canonical query parameters
        $signedUrl = 'https://cdn.example.com/products/shoe.jpg?w=800&v=1.0&X-Amz-Signature=abc123secret&token=tok456';
        $provider = $this->service->getProvider('default');
        $exportUrl = $provider->getStableExportUrl($signedUrl);

        if (str_contains($exportUrl, 'X-Amz-Signature') || str_contains($exportUrl, 'token')) {
            throw new RuntimeException("R84 Failed: Stable export URL failed to strip volatile signature tokens: {$exportUrl}");
        }
        if (!str_contains($exportUrl, 'w=800') || !str_contains($exportUrl, 'v=1.0')) {
            throw new RuntimeException("R84 Failed: Stable export URL stripped valid canonical query parameters: {$exportUrl}");
        }

        // Test attaching same asset to multiple products with separate per-product alt overrides (AC-T21-01)
        $prefix = $this->wpdb->prefix;
        $allocator = new \WPE\Compatibility\WordPress\PostIdAllocator($this->wpdb);
        $prodAId = $allocator->allocateShell('product');
        $this->cleanProductIds[] = $prodAId;

        $prodBId = $allocator->allocateShell('product');
        $this->cleanProductIds[] = $prodBId;

        $this->wpdb->insert($prefix . 'wpe_products', ['id' => $prodAId, 'type' => 'simple', 'status' => 'publish', 'slug' => 'prod-t21-a', 'title' => 'Product A', 'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->wpdb->insert($prefix . 'wpe_products', ['id' => $prodBId, 'type' => 'simple', 'status' => 'publish', 'slug' => 'prod-t21-b', 'title' => 'Product B', 'version' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);

        $this->service->attachProductImage($prodAId, (int)$asset1['id'], 'featured', 0, 'Custom Alt for Product A');
        $this->service->attachProductImage($prodBId, (int)$asset1['id'], 'featured', 0, 'Custom Alt for Product B');

        $mediaA = $this->mediaRepo->getFeaturedImage($prodAId);
        $mediaB = $this->mediaRepo->getFeaturedImage($prodBId);

        if ((int)$mediaA['id'] !== (int)$mediaB['id']) {
            throw new RuntimeException("R84 Failed: Both products should reference the same shared asset ID.");
        }
        if ($mediaA['alt_override'] !== 'Custom Alt for Product A' || $mediaB['alt_override'] !== 'Custom Alt for Product B') {
            throw new RuntimeException("R84 Failed: Per-product alt overrides were not preserved separately.");
        }

        echo " OK\n";
    }

    /**
     * R85: Provider interface generates explicit rendition dimensions and format widths.
     */
    public function testR85_rendition_dimensions(): void
    {
        echo " - testR85_rendition_dimensions...";

        $provider = new DefaultCdnProvider('default');
        $sourceUrl = 'https://cdn.example.com/highres.jpg';
        $sourceW = 1600;
        $sourceH = 1200;

        // 1. Medium size (300 max bounding box, proportional downscale: 300x225)
        $medium = $provider->getRendition($sourceUrl, 'medium', $sourceW, $sourceH);
        if ($medium['width'] !== 300 || $medium['height'] !== 225) {
            throw new RuntimeException("R85 Failed: Expected 300x225 for medium rendition, got {$medium['width']}x{$medium['height']}");
        }

        // 2. Large size (1024 max bounding box: 1024x768)
        $large = $provider->getRendition($sourceUrl, 'large', $sourceW, $sourceH);
        if ($large['width'] !== 1024 || $large['height'] !== 768) {
            throw new RuntimeException("R85 Failed: Expected 1024x768 for large rendition, got {$large['width']}x{$large['height']}");
        }

        // 3. Full size: original dimensions
        $full = $provider->getRendition($sourceUrl, 'full', $sourceW, $sourceH);
        if ($full['width'] !== 1600 || $full['height'] !== 1200) {
            throw new RuntimeException("R85 Failed: Expected 1600x1200 for full rendition, got {$full['width']}x{$full['height']}");
        }

        // 4. Test WordPress compatibility attachment image src
        $asset = $this->service->registerAsset('https://cdn.example.com/catalog/dress.jpg', [
            'width'  => 1600,
            'height' => 1200,
        ]);
        $this->cleanAssetIds[] = (int)$asset['id'];

        $srcMedium = $this->service->getAttachmentImageSrc((int)$asset['id'], 'medium');
        if (!is_array($srcMedium) || count($srcMedium) !== 4) {
            throw new RuntimeException("R85 Failed: getAttachmentImageSrc did not return 4-tuple [url, w, h, is_intermediate].");
        }
        [$url, $w, $h, $isIntermediate] = $srcMedium;
        if ($w !== 300 || $h !== 225 || !$isIntermediate) {
            throw new RuntimeException("R85 Failed: Intermediate size dimensions mismatch ({$w}x{$h}, inter: " . var_export($isIntermediate, true) . ").");
        }

        echo " OK\n";
    }

    /**
     * R86: Rendering image HTML/tags performs 0 runtime network probes. Probes are strictly background/asynchronous.
     */
    public function testR86_render_no_network_probe(): void
    {
        echo " - testR86_render_no_network_probe...";

        $asset = $this->service->registerAsset('https://cdn.example.com/products/jacket.jpg', [
            'width'    => 1200,
            'height'   => 900,
            'alt_text' => 'Winter Jacket',
        ]);
        $this->cleanAssetIds[] = (int)$asset['id'];

        // Inject network transport that throws if called
        $this->service->setHttpTransport(function () {
            throw new RuntimeException("R86 VIOLATION: Network probe attempted during renderImageHtml!");
        });

        $this->service->resetNetworkProbeCount();

        // Render multiple sizes and HTML tags
        $htmlMedium = $this->service->renderImageHtml((int)$asset['id'], 'medium', ['class' => 'featured-img']);
        $htmlThumb  = $this->service->renderImageHtml((int)$asset['id'], 'thumbnail');
        $htmlFull   = $this->service->renderImageHtml((int)$asset['id'], 'full');

        // Check that 0 network probes were executed
        if ($this->service->getNetworkProbeCount() !== 0) {
            throw new RuntimeException("R86 Failed: Network probes were performed during render ({$this->service->getNetworkProbeCount()} probes).");
        }

        if (!str_contains($htmlMedium, 'src="https://cdn.example.com/products/jacket.jpg"')) {
            throw new RuntimeException("R86 Failed: renderImageHtml did not output proper src.");
        }
        if (!str_contains($htmlMedium, 'width="300"') || !str_contains($htmlMedium, 'height="225"')) {
            throw new RuntimeException("R86 Failed: renderImageHtml did not output explicit width and height attributes.");
        }

        // Verify probes only occur when explicit background probe methods are executed
        $mockTransport = function (string $method, string $url) {
            return [
                'status_code' => 200,
                'headers'     => ['content-type' => 'image/jpeg'],
                'body'        => '',
                'error'       => null,
            ];
        };
        $this->service->setHttpTransport($mockTransport);

        $probeResult = $this->service->probeAsset((int)$asset['id']);
        if ($this->service->getNetworkProbeCount() !== 1) {
            throw new RuntimeException("R86 Failed: Explicit probeAsset should increment probeCount to 1, got {$this->service->getNetworkProbeCount()}.");
        }
        if (!$probeResult['healthy']) {
            throw new RuntimeException("R86 Failed: probeAsset failed on valid mock transport.");
        }

        $this->service->setHttpTransport(null);
        echo " OK\n";
    }

    /**
     * AC-T21-01: No network call during image rendering; invalid/private redirect rejected; duplicate URL idempotent with per-product alt preserved.
     */
    public function testAC_T21_01_DedupAndSsrfIntegrity(): void
    {
        echo " - testAC_T21_01_DedupAndSsrfIntegrity...";

        // Verify duplicate URL idempotent
        $url = 'https://cdn.example.com/shared/badge.png?size=large';
        $a1 = $this->service->registerAsset($url, ['alt_text' => 'Global Badge Alt']);
        $a2 = $this->service->registerAsset($url);

        $this->cleanAssetIds[] = (int)$a1['id'];

        if ((int)$a1['id'] !== (int)$a2['id']) {
            throw new RuntimeException("AC-T21-01 Failed: Duplicate URL registration is not idempotent.");
        }

        // Verify private redirect rejection in probe
        $transport = function (string $method, string $url) {
            return [
                'status_code' => 301,
                'headers'     => ['location' => 'https://10.0.0.1/admin-console'],
                'body'        => '',
                'error'       => null,
            ];
        };
        $this->service->setHttpTransport($transport);

        $probe = $this->service->probeUrl('https://cdn.example.com/redirect-to-private');
        if ($probe['healthy'] || !str_contains($probe['error'], 'SSRF')) {
            throw new RuntimeException("AC-T21-01 Failed: Redirect to private network IP was not rejected.");
        }

        $this->service->setHttpTransport(null);
        echo " OK\n";
    }

    /**
     * AC-T21-02: Broken/unreachable image URLs return SVG/placeholder rather than causing PDP fatal errors.
     */
    public function testAC_T21_02_BrokenUrlPlaceholderFallback(): void
    {
        echo " - testAC_T21_02_BrokenUrlPlaceholderFallback...";

        // Create an asset marked broken (e.g. 404 on CDN)
        $brokenAsset = $this->service->registerAsset('https://cdn.example.com/missing-404.jpg', [
            'width'  => 400,
            'height' => 400,
            'health' => 'broken',
        ]);
        $this->cleanAssetIds[] = (int)$brokenAsset['id'];

        // Rendering broken asset must not throw exception, must return SVG placeholder
        $html = $this->service->renderImageHtml((int)$brokenAsset['id'], 'medium');

        if (!str_contains($html, 'data:image/svg+xml') && !str_contains($html, '<svg')) {
            throw new RuntimeException("AC-T21-02 Failed: Broken asset HTML did not render SVG placeholder.");
        }
        if (!str_contains($html, 'wpe-image-placeholder')) {
            throw new RuntimeException("AC-T21-02 Failed: Placeholder missing 'wpe-image-placeholder' class.");
        }
        if (!str_contains($html, 'width="300"') || !str_contains($html, 'height="300"')) {
            throw new RuntimeException("AC-T21-02 Failed: Placeholder missing explicit width and height dimensions to prevent CLS.");
        }

        // Test non-existent asset ID also returns placeholder safely without crashing
        $htmlNonExistent = $this->service->renderImageHtml(99999999, 'medium');
        if (!str_contains($htmlNonExistent, 'data:image/svg+xml')) {
            throw new RuntimeException("AC-T21-02 Failed: Non-existent asset ID did not return safe placeholder.");
        }

        echo " OK\n";
    }
}

// Run tests if executed directly
$test = new T21Test();
$test->runAll();
