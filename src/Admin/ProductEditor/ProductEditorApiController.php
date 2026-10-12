<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Throwable;
use InvalidArgumentException;

/**
 * ProductEditorApiController
 *
 * REST API Controller for Product Variable & Attribute Editor:
 * - GET  /wpe/v1/admin/product/{id}/variants
 * - POST /wpe/v1/admin/product/{id}/variants
 * - POST /wpe/v1/admin/product/{id}/variants/preview-generator
 * - POST /wpe/v1/admin/product/{id}/variants/preview-bulk-edit
 */
class ProductEditorApiController
{
    private static ?ProductEditorApiController $instance = null;
    private ProductEditorService $service;

    public function __construct(?ProductEditorService $service = null)
    {
        $this->service = $service ?? ProductEditorService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function registerRoutes(): void
    {
        $namespace = 'wpe/v1/admin';

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getProductVariants'],
                'permission_callback' => [$this, 'checkProductEditPermission'],
                'args'                => [
                    'id' => [
                        'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'saveProductVariants'],
                'permission_callback' => [$this, 'checkProductEditPermission'],
                'args'                => [
                    'id' => [
                        'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                    ],
                ],
            ],
        ]);

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants/preview-generator', [
            'methods'             => 'POST',
            'callback'            => [$this, 'previewGenerator'],
            'permission_callback' => [$this, 'checkProductEditPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants/preview-bulk-edit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'previewBulkEdit'],
            'permission_callback' => [$this, 'checkProductEditPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);
    }

    public function checkProductEditPermission(WP_REST_Request $request): bool
    {
        $productId = (int) $request->get_param('id');
        if ($productId <= 0) {
            return false;
        }

        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        return current_user_can('edit_post', $productId);
    }

    public function getProductVariants(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        try {
            $data = $this->service->getProductEditorData($productId);
            return new WP_REST_Response([
                'success' => true,
                'data'    => $data,
            ], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function saveProductVariants(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        $payload   = $request->get_json_params() ?: [];
        $actorId   = (int) get_current_user_id();

        try {
            $result = $this->service->saveProductVariants($productId, $payload, $actorId);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (ConcurrencyConflictException $e) {
            return new WP_REST_Response([
                'success'     => false,
                'code'        => 'concurrency_conflict',
                'message'     => $e->getMessage(),
                'server_data' => $e->getServerData(),
            ], 409);
        } catch (InvalidArgumentException $e) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'invalid_argument',
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'code'    => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function previewGenerator(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        $params    = $request->get_json_params() ?: [];
        $options   = $params['variation_options'] ?? [];

        try {
            $result = $this->service->previewCombinations($productId, $options);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function previewBulkEdit(WP_REST_Request $request): WP_REST_Response
    {
        $productId    = (int) $request->get_param('id');
        $params       = $request->get_json_params() ?: [];
        $variationIds = array_map('intval', (array)($params['variation_ids'] ?? []));
        $actions      = (array)($params['actions'] ?? []);

        try {
            $result = $this->service->previewBulkEdit($productId, $variationIds, $actions);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
