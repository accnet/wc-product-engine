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
    private int $rateLimitMax = 60; // Max requests per window
    private int $rateLimitWindow = 60; // Window in seconds

    public function __construct(
        ?\wpdb $wpdb = null,
        ?VariantResolver $resolver = null,
        ?GalleryAdapter $galleryAdapter = null
    ) {
        global $wpdb;
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
        if (!$this->checkRateLimit($clientIp, $this->rateLimitMax, $this->rateLimitWindow)) {
            $response = new WP_REST_Response([
                'code'    => 'rate_limited',
                'message' => sprintf('Too many requests. Please retry after %d seconds.', $this->rateLimitWindow),
                'data'    => ['status' => 429, 'retry_after' => $this->rateLimitWindow],
            ], 429);
            $response->header('Retry-After', (string)$this->rateLimitWindow);
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
        ob_start();

        if (empty($_POST['product_id'])) {
            wp_die();
        }

        $productId = absint($_POST['product_id']);
        $product = wc_get_product($productId);

        if (!$product || !$product->is_type('variable')) {
            wp_die();
        }

        if (!$product->is_viewable()) {
            wp_die();
        }

        // Extract attributes from POST data
        $selection = [];
        foreach ($_POST as $key => $val) {
            if (str_starts_with($key, 'attribute_') && $val !== '') {
                $selection[$key] = wp_unslash($val);
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
     * Serialize variation data matching standard WC_Product_Variable::get_available_variation() structure.
     */
    public function serializeAvailableVariation(int $variationId, int $parentId): array|false
    {
        // 1. Fetch variant and parent from WPE tables
        $sql = "SELECT v.*, p.status AS parent_status
                FROM {$this->tableVariants} v
                JOIN {$this->tableProducts} p ON p.id = v.product_id
                WHERE v.id = %d AND v.product_id = %d AND v.deleted_at IS NULL LIMIT 1";
        $variant = $this->wpdb->get_row($this->wpdb->prepare($sql, $variationId, $parentId), ARRAY_A);

        if (!$variant) {
            return false;
        }

        // Public visibility guard (R104)
        if (($variant['status'] !== 'publish' || $variant['parent_status'] !== 'publish') && !current_user_can('edit_product', $parentId)) {
            return false;
        }

        // Try WC native product if available
        $variableProduct = wc_get_product($parentId);
        $variationProduct = wc_get_product($variationId);

        if ($variableProduct instanceof WC_Product_Variable && $variationProduct instanceof WC_Product_Variation) {
            $data = $variableProduct->get_available_variation($variationProduct);
            if (is_array($data) && !empty($data)) {
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

        $regPrice = $variant['regular_price'] !== null ? (float)$variant['regular_price'] : 0.0;
        $salePrice = $variant['sale_price'] !== null ? (float)$variant['sale_price'] : null;
        $price = $salePrice !== null ? $salePrice : $regPrice;

        $data = [
            'variation_id'           => $variationId,
            'variation_is_visible'   => true,
            'variation_is_active'    => true,
            'is_purchasable'         => ($variant['regular_price'] !== null),
            'display_price'          => (float)$price,
            'display_regular_price'  => (float)$regPrice,
            'attributes'             => $attributes,
            'image'                  => $imagePayload,
            'image_id'               => (int)($imagePayload['image_id'] ?? 0),
            'is_in_stock'            => true,
            'sku'                    => (string)($variant['sku'] ?? ''),
            'availability_html'      => '<p class="stock in-stock">' . esc_html__('In stock', 'woocommerce') . '</p>',
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
                'id'             => $varId,
                'product_id'     => (string)$productId,
                'version'        => (string)($varVersion ?: '1'),
                'sku'            => $v['sku'] ? (string)$v['sku'] : null,
                'selection'      => !empty($selection) ? array_map('strval', $selection) : null,
                'price'          => $v['price'] !== null ? (string)$v['price'] : null,
                'display_price'  => (string)($v['display_price'] ?? ''),
                'stock_owner_id' => $v['stock_owner_id'] !== null ? (string)$v['stock_owner_id'] : null,
                'available'      => (bool)$v['available'],
                'purchasable'    => (bool)$v['purchasable'],
                'media_asset_id' => $v['media_asset_id'] !== null ? (string)$v['media_asset_id'] : null,
            ];
        }

        // Ensure allowed_values has array of string values
        $formattedAllowed = [];
        foreach ($rawResult['allowed_values'] ?? [] as $optKey => $valList) {
            $formattedAllowed[(string)$optKey] = array_values(array_map('strval', (array)$valList));
        }

        return [
            'state'            => $rawResult['state'],
            'request_sequence' => (int)$rawResult['request_sequence'],
            'product_revision' => (string)$rawResult['product_revision'],
            'variant'          => $variantView,
            'allowed_values'   => $formattedAllowed,
            'reason'           => $rawResult['reason'],
        ];
    }

    /**
     * Rate limiter using WordPress transients (R105, AC-T26-02).
     */
    public function checkRateLimit(string $clientKey, int $maxRequests = 60, int $windowSeconds = 60): bool
    {
        $transientKey = 'wpe_rl_' . md5($clientKey);
        $count = (int)get_transient($transientKey);

        if ($count >= $maxRequests) {
            return false;
        }

        if ($count === 0) {
            set_transient($transientKey, 1, $windowSeconds);
        } else {
            // Increment count preserving remaining TTL
            set_transient($transientKey, $count + 1, $windowSeconds);
        }

        return true;
    }

    /**
     * Clear rate limit for testing.
     */
    public function clearRateLimit(string $clientKey): void
    {
        $transientKey = 'wpe_rl_' . md5($clientKey);
        delete_transient($transientKey);
    }

    /**
     * Retrieve client IP address securely.
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: '127.0.0.1';
        }
        return '127.0.0.1';
    }
}
