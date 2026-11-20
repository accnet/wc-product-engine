<?php

namespace WPE\Application\Variant;

use RuntimeException;
use InvalidArgumentException;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WPE\Compatibility\Gallery\GalleryAdapter;

/**
 * Public REST Resolver API and Native WooCommerce AJAX Variation Adapter.
 *
 * Responsibilities:
 * - Public POST /wpe/v1/products/{id}/resolve endpoint (R104, R106).
 * - Native wc-ajax=get_variation adapter for classic WooCommerce theme compatibility (R103, AC-T26-01).
 * - Public permission guards ensuring draft/private products and variations are never exposed (R104, AC-T26-01).
 * - Payload limits (max 32 attributes, capped quantity) and rate limiting with Retry-After header (R105, AC-T26-02).
 * - Lightweight execution avoiding hydration of 10,000 variations on empty selection (R105, AC-T26-02).
 * - Revision tracking and ETag support; zero stock reservations at resolve time (R106).
 */
class ResolverApi
{
    private static ?ResolverApi $instance = null;
    private static bool $routesRegistered = false;

    private \wpdb $wpdb;
    private VariantResolver $resolver;
    private GalleryAdapter $galleryAdapter;

    private string $tableProducts;
    private string $tableVariants;

    // Rate limiting configuration
    private const RATE_LIMIT_GROUP = 'wpe_rate_limit';
    private int $rateLimitMax = 60; // Max requests per window
    private int $rateLimitWindow = 60; // Window in seconds

    public function __construct(
        ?\wpdb $wpdb = null,
        ?VariantResolver $resolver = null,
        ?GalleryAdapter $galleryAdapter = null
    ) {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->resolver = $resolver ?? new VariantResolver($this->wpdb);
        $this->galleryAdapter = $galleryAdapter ?? GalleryAdapter::instance($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts = $prefix . 'wpe_products';
        $this->tableVariants = $prefix . 'wpe_variants';
    }

    public static function instance(?\wpdb $wpdb = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($wpdb);
        }
        return self::$instance;
    }

    /**
     * Register REST API routes and WooCommerce AJAX hooks.
     */
    public static function register(?ResolverApi $instance = null): self
    {
        $api = $instance ?? self::instance();

        if (self::$routesRegistered) {
            return $api;
        }

        add_action('rest_api_init', [$api, 'registerRestRoutes']);

        // Intercept native wc-ajax=get_variation at high priority (R103, AC-T26-01)
        add_action('wc_ajax_get_variation', [$api, 'handleWcAjaxGetVariation'], 5);

        self::$routesRegistered = true;
        return $api;
    }

    /**
     * Register public REST route /wpe/v1/products/{id}/resolve.
     */
    public function registerRestRoutes(): void
    {
        register_rest_route('wpe/v1', '/products/(?P<id>[1-9][0-9]*)/resolve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resolveEndpoint'],
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => [
                'id' => [
                    'validate_callback' => fn($param) => is_numeric($param) && (int)$param > 0,
                    'sanitize_callback' => 'absint',
                    'required'          => true,
                ],
            ],
        ]);
    }

    /* -------------------------------------------------------------------------- */
    /* REST Endpoint: POST /products/{id}/resolve                                 */
    /* -------------------------------------------------------------------------- */

    /**
     * Handle POST /products/{id}/resolve request.
     */
    public function resolveEndpoint(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int)$request['id'];

        // 1. Rate Limiting Check (R105, AC-T26-02)
        $clientIp = $this->getClientIp();
        $limit = $this->rateLimitSettings();
        if (!$this->checkRateLimit($clientIp, $limit['max'], $limit['window'])) {
            $response = new WP_REST_Response([
                'code'    => 'rate_limited',
                'message' => sprintf('Too many requests. Please retry after %d seconds.', $limit['window']),
                'data'    => ['status' => 429, 'retry_after' => $limit['window']],
            ], 429);
            $response->header('Retry-After', (string)$limit['window']);
            return $response;
        }

        // 2. Fetch Parent Product and Permission/Visibility Check (R104, AC-T26-01)
        $sql = "SELECT id, type, status, version FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL LIMIT 1";
        $productRow = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);

        if (!$productRow) {
            return new WP_REST_Response([
                'code'    => 'not_found',
                'message' => 'Product not found.',
                'data'    => ['status' => 404],
            ], 404);
        }

        // Public visibility guard: non-published products require edit permission
        if ($productRow['status'] !== 'publish' && !current_user_can('edit_product', $productId)) {
            return new WP_REST_Response([
                'code'    => 'not_found',
                'message' => 'Product not found.',
                'data'    => ['status' => 404],
            ], 404);
        }

        // 3. Payload Validation and Limits (R105)
        $params = $request->get_json_params() ?: $request->get_params();

        $rawSelection = $params['selection'] ?? [];
        if (!is_array($rawSelection)) {
            return new WP_REST_Response([
                'code'    => 'invalid_request',
                'message' => 'Invalid selection format. Must be an object.',
                'data'    => ['status' => 400],
            ], 400);
        }

        // Cap maximum selection attributes to 32 (R105, OpenAPI maxProperties)
        if (count($rawSelection) > 32) {
            return new WP_REST_Response([
                'code'    => 'invalid_request',
                'message' => 'Selection exceeds maximum allowable properties (32).',
                'data'    => ['status' => 400],
            ], 400);
        }

        // Validate quantity
        $rawQty = $params['quantity'] ?? '1';
        if (!is_numeric($rawQty) || (float)$rawQty <= 0 || (float)$rawQty > 9999) {
            return new WP_REST_Response([
                'code'    => 'invalid_request',
                'message' => 'Quantity must be a positive number between 1 and 9999.',
                'data'    => ['status' => 400],
            ], 400);
        }
        $quantity = (int)$rawQty;

        $requestSequence = isset($params['request_sequence']) ? (int)$params['request_sequence'] : 0;
        $expectedRevision = isset($params['expected_revision']) ? (string)$params['expected_revision'] : null;

        // 4. ETag Check (R106)
        $currentRevision = (string)($productRow['version'] ?? '1');
        $etag = sprintf('W/"%s"', hash('sha256', $productId . ':' . $currentRevision . ':' . json_encode($rawSelection)));
        $ifNoneMatch = $request->get_header('If-None-Match');

        if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
            $notModified = new WP_REST_Response(null, 304);
            $notModified->header('ETag', $etag);
            return $notModified;
        }

        // 5. Execute Variation Resolver (R99, R100, R101)
        // Zero stock holds or reservations created during resolve (R106)
        $rawResult = $this->resolver->resolve($productId, $rawSelection, $quantity, $expectedRevision, $requestSequence);

        // 6. Public Variant Visibility Guard (R104, AC-T26-01)
        if (!empty($rawResult['variant']) && is_array($rawResult['variant'])) {
            $variantId = (int)$rawResult['variant']['id'];
            $varStatus = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT status FROM {$this->tableVariants} WHERE id = %d LIMIT 1",
                $variantId
            ));

            if ($varStatus !== 'publish' && !current_user_can('edit_product', $productId)) {
                // Draft/private variation: hide from public resolver response
                $rawResult['state'] = 'none';
                $rawResult['variant'] = null;
                $rawResult['reason'] = 'no_matching_variation';
            }
        }

        // 7. Format Output Conforming to Sealed OpenAPI ResolveResponse Schema
        $formattedResponse = $this->serializeResolveResponse($productId, $rawResult, $rawSelection);

        $response = new WP_REST_Response($formattedResponse, 200);
        $response->header('ETag', $etag);

        return $response;
    }

    /* -------------------------------------------------------------------------- */
    /* Native WooCommerce AJAX: ?wc-ajax=get_variation (R103, AC-T26-01)          */
    /* -------------------------------------------------------------------------- */

    /**
     * Intercept and handle native ?wc-ajax=get_variation requests.
     * Guarantees seamless compatibility with classic WooCommerce frontend scripts.
     */
    public function handleWcAjaxGetVariation(): void
    {
        $productId = absint($_POST['product_id'] ?? 0);

        // Only answer for variable products the engine manages. For any other product (not migrated,
        // created through the WooCommerce REST API or another plugin) return without output so
        // WooCommerce's own get_variation handler at priority 10 answers (audit F03).
        if ($productId <= 0 || !$this->isManagedVariableProduct($productId)) {
            return;
        }

        ob_start();

        $product = wc_get_product($productId);

        if (!$product || !$product->is_type('variable')) {
            wp_die();
        }

        if (!$product->is_viewable()) {
            wp_die();
        }

        // Extract attributes from POST data. Only scalar values are selections; cleaned the same
        // way WooCommerce's own get_variation handler cleans them.
        $selection = [];
        foreach ($_POST as $key => $val) {
            if (!is_string($key) || !str_starts_with($key, 'attribute_') || !is_scalar($val)) {
                continue;
            }
            $clean = wc_clean(wp_unslash((string)$val));
            if ($clean !== '') {
                $selection[$key] = $clean;
            }
        }

        // Resolve variation
        $result = $this->resolver->resolve($productId, $selection);

        if ($result['state'] !== 'exact' || empty($result['variant']['id'])) {
            wp_send_json(false);
            return;
        }

        $variationId = (int)$result['variant']['id'];
        $variationData = $this->serializeAvailableVariation($variationId, $productId);

        wp_send_json($variationData);
    }

    /**
     * Whether the classic get_variation request for this product should be answered by the engine.
     */
    private function isManagedVariableProduct(int $productId): bool
    {
        $managed = (bool)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM {$this->tableProducts} WHERE id = %d AND type = 'variable' AND deleted_at IS NULL LIMIT 1",
            $productId
        ));

        /**
         * Whether the engine answers ?wc-ajax=get_variation for this product instead of WooCommerce.
         *
         * @param bool $managed    True when the product is a variable product in the engine tables.
         * @param int  $productId  Product ID from the request.
         */
        return (bool)apply_filters('wpe_handle_wc_ajax_get_variation', $managed, $productId);
    }

    /**
     * Serialize variation data matching standard WC_Product_Variable::get_available_variation() structure.
     * Guarantees price_html is always populated for priced variations even when all variations share the same price.
     */
    public function serializeAvailableVariation(int $variationId, int $parentId): array|false
    {
        // 1. Fetch variant, parent, and inventory from WPE tables
        $sql = "SELECT v.*, p.status AS parent_status,
                       i.manage_stock, i.quantity AS stock_qty, i.stock_status, i.backorders
                FROM {$this->tableVariants} v
                JOIN {$this->tableProducts} p ON p.id = v.product_id
                LEFT JOIN {$this->wpdb->prefix}wpe_inventory i ON i.owner_id = COALESCE(v.stock_owner_id, v.id)
                WHERE v.id = %d AND v.product_id = %d AND v.deleted_at IS NULL LIMIT 1";
        $variant = $this->wpdb->get_row($this->wpdb->prepare($sql, $variationId, $parentId), ARRAY_A);

        if (!$variant) {
            return false;
        }

        // Public visibility guard (R104)
        if (($variant['status'] !== 'publish' || $variant['parent_status'] !== 'publish') && !current_user_can('edit_product', $parentId)) {
            return false;
        }

        $eval = $this->resolver->evaluateVariantAvailability($parentId, $variant);
        $resolvedPrice = $eval['resolved_price'];
        $priceHtml = $this->resolver->formatVariationPriceHtml($parentId, $variant, $resolvedPrice);

        // Try WC native product if available
        $variableProduct = wc_get_product($parentId);
        $variationProduct = wc_get_product($variationId);

        if ($variableProduct instanceof WC_Product_Variable && $variationProduct instanceof WC_Product_Variation) {
            $data = $variableProduct->get_available_variation($variationProduct);
            if (is_array($data) && !empty($data)) {
                if (empty($data['price_html']) && $priceHtml !== '') {
                    $data['price_html'] = $priceHtml;
                }
                return $data;
            }
        }

        // Fallback / Direct serialization matching WC get_available_variation contract
        $imagePayload = $this->galleryAdapter->serializeVariantImagePayload($variationId, $parentId);

        // Fetch attribute assignments for variation
        $sqlAttrs = "SELECT o.`key`, ov.value_key
                     FROM {$this->wpdb->prefix}wpe_variant_values vv
                     JOIN {$this->wpdb->prefix}wpe_options o ON o.id = vv.option_id
                     LEFT JOIN {$this->wpdb->prefix}wpe_option_values ov ON ov.id = vv.value_id
                     WHERE vv.variant_id = %d";
        $attrRows = $this->wpdb->get_results($this->wpdb->prepare($sqlAttrs, $variationId), ARRAY_A);

        $attributes = [];
        foreach ($attrRows as $ar) {
            $attrKey = 'attribute_' . sanitize_title($ar['key']);
            $attributes[$attrKey] = $ar['value_key'] ?? '';
        }

        $regPrice = $resolvedPrice['regular'] !== null ? (float)$resolvedPrice['regular'] : 0.0;
        $activePrice = $resolvedPrice['active_price'] !== null ? (float)$resolvedPrice['active_price'] : 0.0;

        $availabilityHtml = $eval['available']
            ? '<p class="stock in-stock">' . esc_html__('In stock', 'woocommerce') . '</p>'
            : '<p class="stock out-of-stock">' . esc_html__('Out of stock', 'woocommerce') . '</p>';

        $data = [
            'variation_id'           => $variationId,
            'variation_is_visible'   => true,
            'variation_is_active'    => true,
            'is_purchasable'         => (bool)$eval['purchasable'],
            'display_price'          => $activePrice,
            'display_regular_price'  => $regPrice,
            'price_html'             => $priceHtml,
            'attributes'             => $attributes,
            'image'                  => $imagePayload,
            'image_id'               => (int)($imagePayload['image_id'] ?? 0),
            'is_in_stock'            => (bool)$eval['available'],
            'sku'                    => (string)($variant['sku'] ?? ''),
            'availability_html'      => $availabilityHtml,
            'variation_description'  => (string)($variant['description'] ?? ''),
        ];

        return apply_filters('woocommerce_available_variation', $data, $variableProduct, $variationProduct);
    }

    /* -------------------------------------------------------------------------- */
    /* Serializer & Helpers                                                       */
    /* -------------------------------------------------------------------------- */

    /**
     * Serialize resolver result strictly conforming to OpenAPI ResolveResponse.
     */
    public function serializeResolveResponse(int $productId, array $rawResult, array $selection = []): array
    {
        $variantView = null;
        if (!empty($rawResult['variant']) && is_array($rawResult['variant'])) {
            $v = $rawResult['variant'];
            $varId = (string)$v['id'];

            // Fetch variant version
            $varVersion = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT version FROM {$this->tableVariants} WHERE id = %d LIMIT 1",
                (int)$varId
            ));

            $variantView = [
                'id'                    => $varId,
                'variation_id'          => (int)$varId,
                'product_id'            => (string)$productId,
                'version'               => (string)($varVersion ?: '1'),
                'sku'                   => $v['sku'] ? (string)$v['sku'] : null,
                'selection'             => !empty($selection) ? array_map('strval', $selection) : null,
                'price'                 => $v['price'] !== null ? (string)$v['price'] : null,
                'regular_price'         => isset($v['regular_price']) && $v['regular_price'] !== null ? (string)$v['regular_price'] : null,
                'sale_price'            => isset($v['sale_price']) && $v['sale_price'] !== null ? (string)$v['sale_price'] : null,
                'sale_active'           => (bool)($v['sale_active'] ?? false),
                'display_price'         => (string)($v['display_price'] ?? ''),
                'price_html'            => (string)($v['price_html'] ?? ''),
                'stock_owner_id'        => $v['stock_owner_id'] !== null ? (string)$v['stock_owner_id'] : null,
                'available'             => (bool)$v['available'],
                'purchasable'           => (bool)$v['purchasable'],
                'is_in_stock'           => (bool)$v['available'],
                'is_purchasable'        => (bool)$v['purchasable'],
                'variation_is_visible'  => true,
                'variation_is_active'   => true,
                'variation_description' => (string)($v['description'] ?? ''),
                'media_asset_id'        => $v['media_asset_id'] !== null ? (string)$v['media_asset_id'] : null,
                'image'                 => $v['image'] ?? null,
            ];
        }

        // Ensure allowed_values has array of string values
        $formattedAllowed = [];
        foreach ($rawResult['allowed_values'] ?? [] as $optKey => $valList) {
            $formattedAllowed[(string)$optKey] = array_values(array_map('strval', (array)$valList));
        }

        return [
            'state'             => $rawResult['state'],
            'request_sequence'  => (int)$rawResult['request_sequence'],
            'product_revision'  => (string)$rawResult['product_revision'],
            'variant'           => $variantView,
            'candidate_image'   => $rawResult['candidate_image'] ?? null,
            'allowed_values'    => $formattedAllowed,
            'allowed_details'   => $rawResult['allowed_details'] ?? [],
            'value_thumbnails'  => $rawResult['value_thumbnails'] ?? [],
            'lowest_price_html' => (string)($rawResult['lowest_price_html'] ?? ''),
            'reason'            => $rawResult['reason'],
        ];
    }

    /**
     * Fixed-window rate limiter (R105, AC-T26-02): at most $maxRequests per client per window.
     *
     * With a persistent object cache (Redis/Memcached) the counter is an atomic wp_cache_incr(), so
     * it never writes wp_options and concurrent requests cannot lose increments. Without one it
     * falls back to a transient that keeps the window's original expiry instead of extending it on
     * every request. A $maxRequests of 0 or less disables the limit (audit F09).
     */
    public function checkRateLimit(string $clientKey, int $maxRequests = 60, int $windowSeconds = 60): bool
    {
        if ($maxRequests <= 0) {
            return true;
        }
        $windowSeconds = max(1, $windowSeconds);
        $key = 'wpe_rl_' . md5($clientKey);

        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::RATE_LIMIT_GROUP, $windowSeconds);
            $count = wp_cache_incr($key, 1, self::RATE_LIMIT_GROUP);
            if ($count !== false) {
                return (int)$count <= $maxRequests;
            }
        }

        $now = time();
        $state = get_transient($key);
        if (!is_array($state) || (int)($state['reset'] ?? 0) <= $now) {
            $state = ['count' => 0, 'reset' => $now + $windowSeconds];
        }
        if ((int)$state['count'] >= $maxRequests) {
            return false;
        }
        $state['count'] = (int)$state['count'] + 1;
        set_transient($key, $state, max(1, (int)$state['reset'] - $now));

        return true;
    }

    /**
     * Clear rate limit for testing.
     */
    public function clearRateLimit(string $clientKey): void
    {
        $key = 'wpe_rl_' . md5($clientKey);
        wp_cache_delete($key, self::RATE_LIMIT_GROUP);
        delete_transient($key);
    }

    /**
     * Requests allowed per client and window, filterable for sites behind a CDN or with heavy selectors.
     *
     * @return array{max:int, window:int}
     */
    private function rateLimitSettings(): array
    {
        /**
         * Rate limit of the public resolve endpoint.
         *
         * @param array{max:int, window:int} $settings max requests per window (0 disables), window in seconds.
         */
        $settings = (array)apply_filters('wpe_resolver_rate_limit', [
            'max'    => $this->rateLimitMax,
            'window' => $this->rateLimitWindow,
        ]);
        return [
            'max'    => (int)($settings['max'] ?? $this->rateLimitMax),
            'window' => max(1, (int)($settings['window'] ?? $this->rateLimitWindow)),
        ];
    }

    /**
     * The client address used as the rate-limit key.
     *
     * Behind a CDN or reverse proxy REMOTE_ADDR is the proxy, so every shopper would share one
     * bucket. Define WPE_TRUSTED_PROXY_HEADER (for example 'HTTP_CF_CONNECTING_IP' or
     * 'HTTP_X_FORWARDED_FOR') only when the proxy overwrites that header; the first valid address in
     * it is used. The wpe_resolver_client_ip filter can supply the address instead.
     */
    private function getClientIp(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string)filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP) : '';
        $ip = $remote;

        if (defined('WPE_TRUSTED_PROXY_HEADER') && is_string(WPE_TRUSTED_PROXY_HEADER)) {
            $header = WPE_TRUSTED_PROXY_HEADER;
            if (isset($_SERVER[$header]) && is_string($_SERVER[$header])) {
                foreach (explode(',', wp_unslash($_SERVER[$header])) as $candidate) {
                    $valid = filter_var(trim($candidate), FILTER_VALIDATE_IP);
                    if ($valid !== false) {
                        $ip = (string)$valid;
                        break;
                    }
                }
            }
        }

        /**
         * Client address used for the resolve endpoint rate limit.
         *
         * @param string $ip     Address from WPE_TRUSTED_PROXY_HEADER when configured, else REMOTE_ADDR.
         * @param string $remote REMOTE_ADDR as validated.
         */
        $ip = (string)apply_filters('wpe_resolver_client_ip', $ip, $remote);

        return $ip !== '' ? $ip : '127.0.0.1';
    }
}
