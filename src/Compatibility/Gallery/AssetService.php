<?php

namespace WPE\Compatibility\Gallery;

use RuntimeException;
use InvalidArgumentException;
use WPE\Infrastructure\Database\MediaRepository;

/**
 * Interface for CDN image delivery providers.
 */
interface CdnProviderInterface
{
    public function getName(): string;
    public function canTransform(): bool;
    public function getRendition(string $sourceUrl, string $size, ?int $width = null, ?int $height = null): array;
    public function getStableExportUrl(string $sourceUrl): string;
    public function probe(string $sourceUrl): array;
}

/**
 * Default CDN provider: passthrough original URL, explicit rendition dimensions,
 * sanitizes export URLs by removing volatile signature tokens.
 */
class DefaultCdnProvider implements CdnProviderInterface
{
    private string $name;
    private array $sizeMap;

    public function __construct(string $name = 'default')
    {
        $this->name = $name;
        $this->sizeMap = [
            'thumbnail'                      => ['width' => 150,  'height' => 150,  'crop' => true],
            'medium'                         => ['width' => 300,  'height' => 300,  'crop' => false],
            'woocommerce_thumbnail'          => ['width' => 300,  'height' => 300,  'crop' => true],
            'woocommerce_single'             => ['width' => 600,  'height' => 600,  'crop' => false],
            'woocommerce_gallery_thumbnail' => ['width' => 100,  'height' => 100,  'crop' => true],
            'large'                          => ['width' => 1024, 'height' => 1024, 'crop' => false],
            'full'                           => ['width' => 1920, 'height' => 1080, 'crop' => false],
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function canTransform(): bool
    {
        return false;
    }

    /**
     * Compute rendition dimensions for a given size and source dimensions (R85).
     */
    public function getRendition(string $sourceUrl, string $size, ?int $width = null, ?int $height = null): array
    {
        $targetConfig = $this->sizeMap[$size] ?? $this->sizeMap['full'];
        $targetW = $targetConfig['width'];
        $targetH = $targetConfig['height'];
        $isCrop  = $targetConfig['crop'] ?? false;

        $computedW = $targetW;
        $computedH = $targetH;

        if ($width !== null && $width > 0 && $height !== null && $height > 0) {
            if ($size === 'full') {
                $computedW = $width;
                $computedH = $height;
            } elseif ($isCrop) {
                // Square/cropped rendition: exact target bounding dimensions
                $computedW = min($width, $targetW);
                $computedH = min($height, $targetH);
            } else {
                // Proportional downscale
                $scale = min($targetW / $width, $targetH / $height);
                if ($scale < 1.0) {
                    $computedW = (int)round($width * $scale);
                    $computedH = (int)round($height * $scale);
                } else {
                    $computedW = $width;
                    $computedH = $height;
                }
            }
        }

        return [
            'size'   => $size,
            'url'    => $sourceUrl,
            'width'  => $computedW,
            'height' => $computedH,
            'mime'   => 'image/jpeg',
        ];
    }

    /**
     * Strip transient authorization/signature tokens while preserving canonical query parameters (R84, 10 §4).
     */
    public function getStableExportUrl(string $sourceUrl): string
    {
        $parsed = parse_url($sourceUrl);
        if (!$parsed || empty($parsed['query'])) {
            return $sourceUrl;
        }

        parse_str($parsed['query'], $queryParams);

        // Volatile signature and credential parameters to strip from export
        $volatileKeys = [
            'x-amz-signature',
            'x-amz-algorithm',
            'x-amz-credential',
            'x-amz-date',
            'x-amz-expires',
            'x-amz-signedheaders',
            'x-amz-security-token',
            'token',
            'sig',
            'signature',
            'expires',
            'key-pair-id',
            'policy',
            'auth',
        ];

        $retainedParams = [];
        foreach ($queryParams as $k => $v) {
            if (!in_array(strtolower((string)$k), $volatileKeys, true)) {
                $retainedParams[$k] = $v;
            }
        }

        $scheme   = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host     = $parsed['host'] ?? '';
        $port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path     = $parsed['path'] ?? '';
        $query    = !empty($retainedParams) ? '?' . http_build_query($retainedParams) : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $fragment;
    }

    public function probe(string $sourceUrl): array
    {
        return [
            'healthy' => true,
            'status'  => 'healthy',
            'url'     => $sourceUrl,
        ];
    }
}

/**
 * URL and SSRF validator.
 * Enforces HTTPS, validates credentials, checks DNS resolutions against private/link-local/metadata IPs (R83).
 */
class UrlValidator
{
    private bool $allowHttp;
    /** @var callable|null */
    private static $dnsResolver = null;

    public function __construct(bool $allowHttp = false)
    {
        $this->allowHttp = $allowHttp;
    }

    public static function setDnsResolver(?callable $resolver): void
    {
        self::$dnsResolver = $resolver;
    }

    /**
     * Validate URL format, scheme, host credentials and SSRF private IP ranges (R83).
     */
    public function validateUrl(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return ['valid' => false, 'error' => 'URL cannot be empty.', 'canonical_url' => ''];
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['valid' => false, 'error' => 'Malformed URL format.', 'canonical_url' => ''];
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'https') {
            if (!$this->allowHttp || $scheme !== 'http') {
                return ['valid' => false, 'error' => "Disallowed URL scheme '{$scheme}'. Only HTTPS is permitted.", 'canonical_url' => ''];
            }
        }

        // Credentials embedded in URL must be rejected (10 §4)
        if (!empty($parsed['user']) || !empty($parsed['pass'])) {
            return ['valid' => false, 'error' => 'Embedded credentials in URL are prohibited.', 'canonical_url' => ''];
        }

        $host = strtolower($parsed['host']);

        // Check dangerous hostnames
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'local' || str_ends_with($host, '.local') || $host === '127.0.0.1' || $host === '[::1]') {
            return ['valid' => false, 'error' => "SSRF: Hostname '{$host}' is restricted.", 'canonical_url' => ''];
        }

        // Canonical URL construction (lowercased scheme and host, preserved path and query)
        $port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path     = $parsed['path'] ?? '/';
        $query    = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        $canonicalUrl = $scheme . '://' . $host . $port . $path . $query . $fragment;

        // Check if host is direct IP
        $rawHost = trim($host, '[]');
        if (filter_var($rawHost, FILTER_VALIDATE_IP)) {
            if (self::isPrivateOrReservedIp($rawHost)) {
                return ['valid' => false, 'error' => "SSRF: Direct IP '{$rawHost}' is in a private or reserved range.", 'canonical_url' => $canonicalUrl];
            }
            return ['valid' => true, 'error' => null, 'canonical_url' => $canonicalUrl, 'resolved_ips' => [$rawHost]];
        }

        // Resolve DNS
        $resolvedIps = $this->resolveHostIps($host);
        if (empty($resolvedIps)) {
            return ['valid' => false, 'error' => "DNS resolution failed for host '{$host}'.", 'canonical_url' => $canonicalUrl];
        }

        // Check every resolved IP for private / reserved ranges (R83)
        foreach ($resolvedIps as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                return [
                    'valid'         => false,
                    'error'         => "SSRF: Host '{$host}' resolved to restricted private/reserved IP '{$ip}'.",
                    'canonical_url' => $canonicalUrl,
                    'resolved_ips'  => $resolvedIps,
                ];
            }
        }

        return [
            'valid'         => true,
            'error'         => null,
            'canonical_url' => $canonicalUrl,
            'resolved_ips'  => $resolvedIps,
        ];
    }

    /**
     * Resolves IPv4 and IPv6 addresses for a hostname.
     */
    public function resolveHostIps(string $host): array
    {
        if (self::$dnsResolver !== null) {
            return call_user_func(self::$dnsResolver, $host);
        }

        $ips = [];

        // IPv4 resolution
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }

        // IPv6 resolution
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        return array_unique($ips);
    }

    /**
     * Determines whether an IP is in a private, loopback, link-local, or cloud metadata range (R83).
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        // 1. PHP built-in filter validation
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        // 2. Normalize IPv4-mapped IPv6 (::ffff:192.168.1.1)
        if (stripos($ip, '::ffff:') === 0) {
            $mappedIpv4 = substr($ip, 7);
            if (filter_var($mappedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::isPrivateOrReservedIp($mappedIpv4);
            }
        }

        // 3. IPv4 range checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            $ranges = [
                ['0.0.0.0', 8],       // Current network (RFC 1122)
                ['10.0.0.0', 8],      // Private network (RFC 1918)
                ['100.64.0.0', 10],   // Shared address space (RFC 6598)
                ['127.0.0.0', 8],     // Loopback (RFC 1122)
                ['169.254.0.0', 16],  // Link-local / Cloud metadata (RFC 3927)
                ['172.16.0.0', 12],   // Private network (RFC 1918)
                ['192.0.0.0', 24],    // IETF Protocol Assignments (RFC 6890)
                ['192.0.2.0', 24],    // TEST-NET-1 (RFC 5737)
                ['192.168.0.0', 16],  // Private network (RFC 1918)
                ['198.18.0.0', 15],   // Benchmarking (RFC 2544)
                ['198.51.100.0', 24], // TEST-NET-2 (RFC 5737)
                ['203.0.113.0', 24],  // TEST-NET-3 (RFC 5737)
                ['224.0.0.0', 4],     // Multicast (RFC 5771)
                ['240.0.0.0', 4],     // Reserved (RFC 1112)
            ];

            foreach ($ranges as [$net, $mask]) {
                $netLong = ip2long($net);
                $maskLong = ~((1 << (32 - (int)$mask)) - 1);
                if (($long & $maskLong) === ($netLong & $maskLong)) {
                    return true;
                }
            }
        }

        // 4. IPv6 range checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = inet_pton($ip);
            if ($binary === false) {
                return true;
            }

            // Loopback ::1
            if ($ip === '::1' || $binary === inet_pton('::1')) {
                return true;
            }

            // Link-local fe80::/10 (1111 1110 10xx xxxx)
            if (ord($binary[0]) === 0xfe && (ord($binary[1]) & 0xc0) === 0x80) {
                return true;
            }

            // Unique local fc00::/7 (1111 110x)
            if ((ord($binary[0]) & 0xfe) === 0xfc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate image MIME type (10 §4).
     */
    public static function isAllowedImageMime(?string $mime): bool
    {
        if ($mime === null || $mime === '') {
            return false;
        }

        $clean = strtolower(trim(explode(';', $mime)[0]));
        $allowed = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
            'image/gif',
        ];

        return in_array($clean, $allowed, true);
    }
}

/**
 * External CDN Image Asset Service.
 * Implements URL registry, dedup preserving full canonical query (R84),
 * provider rendition contract (R85), 0 network probe on render (R86),
 * SSRF and redirect chain validation (R83), and broken URL placeholder fallback (AC-T21-02).
 */
class AssetService
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private UrlValidator $validator;

    private string $tableAssets;
    private string $tableProductMedia;
    private string $tableVariantMedia;

    /** @var array<string, CdnProviderInterface> */
    private array $providers = [];

    /** @var callable|null Injectable transport for network probes in tests */
    private $httpTransport = null;

    /** @var int Spy counter for executed network probes */
    private int $probeCount = 0;

    public function __construct(
        ?\wpdb $wpdb = null,
        ?MediaRepository $mediaRepo = null,
        ?UrlValidator $validator = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->mediaRepo = $mediaRepo ?? new MediaRepository($this->wpdb);
        $this->validator = $validator ?? new UrlValidator();

        $prefix = $this->wpdb->prefix;
        $this->tableAssets       = $prefix . 'wpe_assets';
        $this->tableProductMedia = $prefix . 'wpe_product_media';
        $this->tableVariantMedia = $prefix . 'wpe_variant_media';

        // Register default provider
        $defaultProvider = new DefaultCdnProvider();
        $this->registerProvider($defaultProvider);
    }

    public function registerProvider(CdnProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    public function getProvider(string $name = 'default'): CdnProviderInterface
    {
        return $this->providers[$name] ?? $this->providers['default'];
    }

    public function setHttpTransport(?callable $transport): void
    {
        $this->httpTransport = $transport;
    }

    public function getNetworkProbeCount(): int
    {
        return $this->probeCount;
    }

    public function resetNetworkProbeCount(): void
    {
        $this->probeCount = 0;
    }

    /* -------------------------------------------------------------------------- */
    /* Asset Registry & Deduplication (R84, AC-T21-01)                             */
    /* -------------------------------------------------------------------------- */

    /**
     * Register an external asset URL idempotently.
     * Compares full canonical URL preserving query strings (version, signature, resize) (R84).
     */
    public function registerAsset(string $url, array $options = []): array
    {
        $validation = $this->validator->validateUrl($url);
        if (!$validation['valid']) {
            throw new InvalidArgumentException("Asset URL validation failed: " . $validation['error']);
        }

        $canonicalUrl = $validation['canonical_url'];
        $urlKeyHex    = hash('sha256', $canonicalUrl);

        // Deduplication lookup: match both url_key and source_url (03 §3)
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, kind, source_url, HEX(url_key) AS url_key_hex, provider, provider_key, mime, width, height, alt_text, title, caption, attachment_id, delivery_policy, renditions, health, created_at, updated_at, version
             FROM {$this->tableAssets}
             WHERE url_key = UNHEX(%s) AND source_url = %s
             LIMIT 1",
            $urlKeyHex,
            $canonicalUrl
        ), ARRAY_A);

        if ($existing) {
            // Idempotent reuse of existing asset record
            return $existing;
        }

        // Create new asset record via repository
        $providerName = $options['provider'] ?? 'default';
        $provider = $this->getProvider($providerName);

        // Precompute standard renditions if dimensions are provided
        $renditions = $options['renditions'] ?? [];
        if (empty($renditions) && isset($options['width'], $options['height'])) {
            $sizes = ['thumbnail', 'medium', 'woocommerce_thumbnail', 'woocommerce_single', 'large', 'full'];
            foreach ($sizes as $s) {
                $renditions[] = $provider->getRendition($canonicalUrl, $s, (int)$options['width'], (int)$options['height']);
            }
        }

        $assetData = [
            'kind'            => 'image',
            'source_url'      => $canonicalUrl,
            'provider'        => $providerName,
            'provider_key'    => $options['provider_key'] ?? null,
            'mime'            => $options['mime'] ?? 'image/jpeg',
            'width'           => $options['width'] ?? null,
            'height'          => $options['height'] ?? null,
            'alt_text'        => $options['alt_text'] ?? '',
            'title'           => $options['title'] ?? '',
            'caption'         => $options['caption'] ?? '',
            'delivery_policy' => $options['delivery_policy'] ?? ['export_strategy' => 'stable'],
            'renditions'      => $renditions,
            'health'          => $options['health'] ?? 'healthy',
        ];

        return $this->mediaRepo->createAsset($assetData);
    }

    public function getAssetById(int $assetId): ?array
    {
        return $this->mediaRepo->getAssetById($assetId);
    }

    public function getAssetByUrl(string $url): ?array
    {
        $urlKeyHex = hash('sha256', trim($url));
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, kind, source_url, HEX(url_key) AS url_key_hex, provider, provider_key, mime, width, height, alt_text, title, caption, attachment_id, delivery_policy, renditions, health, created_at, updated_at, version
             FROM {$this->tableAssets}
             WHERE url_key = UNHEX(%s) AND source_url = %s
             LIMIT 1",
            $urlKeyHex,
            trim($url)
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Attach an asset to a product with an optional per-product alt override (AC-T21-01).
     */
    public function attachProductImage(
        int $productId,
        int $assetId,
        string $role = 'featured',
        int $position = 0,
        ?string $altOverride = null
    ): bool {
        $asset = $this->getAssetById($assetId);
        if (!$asset) {
            throw new InvalidArgumentException("Asset ID {$assetId} does not exist.");
        }

        if ($role === 'featured') {
            return $this->mediaRepo->setFeaturedImage($productId, $assetId, $altOverride);
        }

        $res = $this->wpdb->insert(
            $this->tableProductMedia,
            [
                'product_id'   => $productId,
                'asset_id'     => $assetId,
                'role'         => $role,
                'position'     => $position,
                'alt_override' => $altOverride,
            ],
            ['%d', '%d', '%s', '%d', '%s']
        );

        GalleryAdapter::invalidateStaticProductCache($productId);

        return $res !== false;
    }

    /**
     * Attach an asset to a variation with an optional per-variation alt override (AC-T21-01).
     */
    public function attachVariantImage(
        int $variantId,
        int $assetId,
        int $position = 0,
        bool $isPrimary = true,
        ?string $altOverride = null
    ): bool {
        $asset = $this->getAssetById($assetId);
        if (!$asset) {
            throw new InvalidArgumentException("Asset ID {$assetId} does not exist.");
        }

        if ($isPrimary) {
            return $this->mediaRepo->setVariantPrimaryImage($variantId, $assetId, $altOverride);
        }

        $res = $this->wpdb->insert(
            $this->tableVariantMedia,
            [
                'variant_id'   => $variantId,
                'asset_id'     => $assetId,
                'position'     => $position,
                'is_primary'   => 0,
                'alt_override' => $altOverride,
            ],
            ['%d', '%d', '%d', '%d', '%s']
        );

        return $res !== false;
    }

    /* -------------------------------------------------------------------------- */
    /* Renditions & Dimensions (R85)                                              */
    /* -------------------------------------------------------------------------- */

    /**
     * Get rendition details for a specific size (R85).
     */
    public function getRendition($assetOrId, string $size = 'full'): array
    {
        $asset = is_array($assetOrId) ? $assetOrId : $this->getAssetById((int)$assetOrId);
        if (!$asset) {
            return $this->getDefaultPlaceholderRendition($size);
        }

        // Check if pre-stored renditions JSON has this size
        if (!empty($asset['renditions'])) {
            $renditions = is_string($asset['renditions']) ? json_decode($asset['renditions'], true) : $asset['renditions'];
            if (is_array($renditions)) {
                foreach ($renditions as $r) {
                    if (isset($r['size']) && $r['size'] === $size) {
                        return $r;
                    }
                }
            }
        }

        $provider = $this->getProvider($asset['provider'] ?? 'default');
        $w = isset($asset['width']) && $asset['width'] !== null ? (int)$asset['width'] : null;
        $h = isset($asset['height']) && $asset['height'] !== null ? (int)$asset['height'] : null;

        return $provider->getRendition($asset['source_url'], $size, $w, $h);
    }

    /**
     * Compatible with WordPress wp_get_attachment_image_src contract.
     * Returns: [$url, $width, $height, $is_intermediate]
     */
    public function getAttachmentImageSrc($assetOrId, string $size = 'full'): ?array
    {
        $asset = is_array($assetOrId) ? $assetOrId : $this->getAssetById((int)$assetOrId);
        if (!$asset) {
            return null;
        }

        if (($asset['health'] ?? 'healthy') === 'broken') {
            $dims = $this->getDefaultDimensionsForSize($size);
            return [
                $this->getPlaceholderDataUri($dims['width'], $dims['height']),
                $dims['width'],
                $dims['height'],
                $size !== 'full'
            ];
        }

        $rendition = $this->getRendition($asset, $size);
        return [
            $rendition['url'],
            (int)$rendition['width'],
            (int)$rendition['height'],
            $size !== 'full'
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* Safe Image Rendering (R86, AC-T21-01, AC-T21-02)                           */
    /* -------------------------------------------------------------------------- */

    /**
     * Render an <img> HTML tag for an asset.
     * Strict requirement: ZERO network probes are executed during HTML render (R86, AC-T21-01).
     * If asset is broken, returns clean SVG placeholder without throwing or causing PDP fatal (AC-T21-02).
     */
    public function renderImageHtml($assetOrId, string $size = 'full', array $attrs = []): string
    {
        $asset = is_array($assetOrId) ? $assetOrId : $this->getAssetById((int)$assetOrId);

        // If asset is not found or marked broken, return placeholder (AC-T21-02)
        if (!$asset || ($asset['health'] ?? 'healthy') === 'broken') {
            return $this->renderPlaceholder($size, $attrs);
        }

        // Render from cached DB metadata; strictly 0 network probe calls (R86)
        $rendition = $this->getRendition($asset, $size);
        $w = (int)($attrs['width'] ?? $rendition['width']);
        $h = (int)($attrs['height'] ?? $rendition['height']);

        $alt = $attrs['alt'] ?? ($asset['alt_text'] ?? '');
        $class = $attrs['class'] ?? 'wpe-cdn-image';

        $safeSrc   = esc_url($rendition['url']);
        $safeAlt   = esc_attr($alt);
        $safeClass = esc_attr($class);

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s" class="%s" loading="lazy" decoding="async" />',
            $safeSrc,
            $w,
            $h,
            $safeAlt,
            $safeClass
        );
    }

    /**
     * Render a lightweight, responsive SVG placeholder preventing layout shifts (AC-T21-02).
     */
    public function renderPlaceholder(string $size = 'full', array $attrs = []): string
    {
        $dims = $this->getDefaultDimensionsForSize($size);
        $w = (int)($attrs['width'] ?? $dims['width']);
        $h = (int)($attrs['height'] ?? $dims['height']);

        $alt   = esc_attr($attrs['alt'] ?? 'Image unavailable');
        $class = esc_attr($attrs['class'] ?? 'wpe-image-placeholder');

        $dataUri = $this->getPlaceholderDataUri($w, $h);

        return sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s" class="%s" loading="lazy" decoding="async" />',
            $dataUri,
            $w,
            $h,
            $alt,
            $class
        );
    }

    public function getPlaceholderDataUri(int $width, int $height): string
    {
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">' .
            '<rect width="100%%" height="100%%" fill="#f0f0f1"/>' .
            '<text x="50%%" y="50%%" fill="#8c8f94" font-family="system-ui,-apple-system,sans-serif" font-size="14" text-anchor="middle" dominant-baseline="middle">Image unavailable</text>' .
            '</svg>',
            $width, $height, $width, $height
        );

        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
    }

    /* -------------------------------------------------------------------------- */
    /* Health Probes & Background Workers (R83, 10 §4)                            */
    /* -------------------------------------------------------------------------- */

    /**
     * Probe a URL for availability and valid image MIME type.
     * Enforces DNS checks, blocks private/internal IPs, and validates redirect chains (R83).
     * Connect timeout 2s, total timeout 5s, max 3 redirects, HEAD fallback GET Range (10 §4).
     */
    public function probeUrl(string $url, int $maxRedirects = 3): array
    {
        $currentUrl = $url;
        $visited = [];

        for ($redirectCount = 0; $redirectCount <= $maxRedirects; $redirectCount++) {
            if (in_array($currentUrl, $visited, true)) {
                return [
                    'healthy'        => false,
                    'status'         => 'broken',
                    'error'          => 'Circular redirect loop detected.',
                    'redirect_count' => $redirectCount,
                    'final_url'      => $currentUrl,
                ];
            }
            $visited[] = $currentUrl;

            // Validate URL and enforce SSRF check BEFORE any connection (R83)
            $validation = $this->validator->validateUrl($currentUrl);
            if (!$validation['valid']) {
                return [
                    'healthy'        => false,
                    'status'         => 'broken',
                    'error'          => 'SSRF validation failed: ' . $validation['error'],
                    'redirect_count' => $redirectCount,
                    'final_url'      => $currentUrl,
                ];
            }

            // Execute probe via injected transport or default cURL
            $response = $this->executeHttpRequest('HEAD', $currentUrl);

            // Fallback to GET with Range request if HEAD is not supported (10 §4)
            if ($response['status_code'] === 405) {
                $response = $this->executeHttpRequest('GET', $currentUrl, ['Range: bytes=0-65535']);
            }

            $statusCode = $response['status_code'];

            // Follow redirect with SSRF revalidation
            if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                $location = $response['headers']['location'] ?? null;
                if (!$location) {
                    return [
                        'healthy'        => false,
                        'status'         => 'broken',
                        'error'          => "Redirect response {$statusCode} missing Location header.",
                        'redirect_count' => $redirectCount,
                        'final_url'      => $currentUrl,
                    ];
                }

                if ($redirectCount >= $maxRedirects) {
                    return [
                        'healthy'        => false,
                        'status'         => 'broken',
                        'error'          => "Exceeded maximum allowed redirects ({$maxRedirects}).",
                        'redirect_count' => $redirectCount,
                        'final_url'      => $currentUrl,
                    ];
                }

                // Resolve relative redirect URL
                $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                continue;
            }

            // HTTP 2xx or 304 success
            if (($statusCode >= 200 && $statusCode < 300) || $statusCode === 304) {
                $mime = $response['headers']['content-type'] ?? '';
                if (!UrlValidator::isAllowedImageMime($mime)) {
                    return [
                        'healthy'        => false,
                        'status'         => 'broken',
                        'error'          => "Unsafe or disallowed image MIME type: '{$mime}'.",
                        'redirect_count' => $redirectCount,
                        'final_url'      => $currentUrl,
                        'http_code'      => $statusCode,
                    ];
                }

                return [
                    'healthy'        => true,
                    'status'         => 'healthy',
                    'http_code'      => $statusCode,
                    'mime'           => $mime,
                    'redirect_count' => $redirectCount,
                    'final_url'      => $currentUrl,
                    'error'          => null,
                ];
            }

            // Other status code (e.g. 404, 500)
            return [
                'healthy'        => false,
                'status'         => 'broken',
                'http_code'      => $statusCode,
                'error'          => "HTTP probe returned status code {$statusCode}.",
                'redirect_count' => $redirectCount,
                'final_url'      => $currentUrl,
            ];
        }

        return [
            'healthy'        => false,
            'status'         => 'broken',
            'error'          => 'Probe terminated without resolution.',
            'redirect_count' => $redirectCount,
            'final_url'      => $currentUrl,
        ];
    }

    /**
     * Probe a single stored asset and update its health state in DB.
     */
    public function probeAsset(int $assetId): array
    {
        $this->probeCount++;

        $asset = $this->getAssetById($assetId);
        if (!$asset) {
            throw new InvalidArgumentException("Asset ID {$assetId} not found.");
        }

        $probeResult = $this->probeUrl($asset['source_url']);
        $newHealth = $probeResult['healthy'] ? 'healthy' : 'broken';

        $this->markAssetHealth($assetId, $newHealth, $probeResult['error']);

        return $probeResult;
    }

    /**
     * Update asset health status in wpe_assets table.
     */
    public function markAssetHealth(int $assetId, string $health, ?string $lastError = null): bool
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $sql = "UPDATE {$this->tableAssets}
                SET health = %s, updated_at = %s, version = version + 1
                WHERE id = %d";

        $res = $this->wpdb->query($this->wpdb->prepare($sql, $health, $now, $assetId));
        GalleryAdapter::invalidateStaticProductCache(null);
        return $res !== false;
    }

    /**
     * Background worker: probe a batch of pending or broken assets.
     */
    public function runHealthCheckBatch(int $limit = 50): array
    {
        $sql = "SELECT id, source_url, health
                FROM {$this->tableAssets}
                WHERE health = 'pending'
                ORDER BY updated_at ASC
                LIMIT %d";

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $limit), ARRAY_A);
        $summary = [
            'probed'  => 0,
            'healthy' => 0,
            'broken'  => 0,
            'results' => [],
        ];

        foreach ($rows as $row) {
            $res = $this->probeAsset((int)$row['id']);
            $summary['probed']++;
            if ($res['healthy']) {
                $summary['healthy']++;
            } else {
                $summary['broken']++;
            }
            $summary['results'][] = [
                'asset_id' => $row['id'],
                'healthy'  => $res['healthy'],
                'error'    => $res['error'] ?? null,
            ];
        }

        return $summary;
    }

    /* -------------------------------------------------------------------------- */
    /* Private Helpers                                                            */
    /* -------------------------------------------------------------------------- */

    private function executeHttpRequest(string $method, string $url, array $headers = []): array
    {
        if ($this->httpTransport !== null) {
            return call_user_func($this->httpTransport, $method, $url, $headers);
        }

        // cURL execution with strict timeouts (connect 2s, total 5s) (10 §4)
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Manually followed for SSRF checks
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $rawOutput = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($rawOutput === false || $curlError !== '') {
            return [
                'status_code' => 0,
                'headers'     => [],
                'body'        => '',
                'error'       => $curlError ?: 'Connection failed',
            ];
        }

        $headerText = substr($rawOutput, 0, $headerSize);
        $body = substr($rawOutput, $headerSize);

        return [
            'status_code' => $statusCode,
            'headers'     => $this->parseHeaders($headerText),
            'body'        => $body,
            'error'       => null,
        ];
    }

    private function parseHeaders(string $headerText): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerText);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return $headers;
    }

    private function resolveRedirectUrl(string $baseUrl, string $redirectUrl): string
    {
        $parsedRedirect = parse_url($redirectUrl);
        if (!empty($parsedRedirect['scheme'])) {
            return $redirectUrl; // Already absolute URL
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host   = $parsedBase['host'] ?? '';
        $port   = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        if (str_starts_with($redirectUrl, '//')) {
            return $scheme . ':' . $redirectUrl;
        }

        if (str_starts_with($redirectUrl, '/')) {
            return $scheme . '://' . $host . $port . $redirectUrl;
        }

        $basePath = $parsedBase['path'] ?? '/';
        $dir = dirname($basePath);
        if ($dir === '\\' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $host . $port . $dir . '/' . $redirectUrl;
    }

    private function getDefaultDimensionsForSize(string $size): array
    {
        $map = [
            'thumbnail'                      => ['width' => 150,  'height' => 150],
            'medium'                         => ['width' => 300,  'height' => 300],
            'woocommerce_thumbnail'          => ['width' => 300,  'height' => 300],
            'woocommerce_single'             => ['width' => 600,  'height' => 600],
            'woocommerce_gallery_thumbnail' => ['width' => 100,  'height' => 100],
            'large'                          => ['width' => 1024, 'height' => 1024],
            'full'                           => ['width' => 1920, 'height' => 1080],
        ];

        return $map[$size] ?? $map['full'];
    }

    private function getDefaultPlaceholderRendition(string $size): array
    {
        $dims = $this->getDefaultDimensionsForSize($size);
        return [
            'size'   => $size,
            'url'    => $this->getPlaceholderDataUri($dims['width'], $dims['height']),
            'width'  => $dims['width'],
            'height' => $dims['height'],
            'mime'   => 'image/svg+xml',
        ];
    }
}
