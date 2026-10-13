<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Throwable;
use InvalidArgumentException;
use WPE\Application\Media\UploadRejectedException;

/**
 * ProductEditorApiController
 *
 * REST API Controller for Product Variable & Attribute Editor:
 * - GET  /wpe/v1/admin/product/{id}/variants
 * - POST /wpe/v1/admin/product/{id}/variants
 * - POST /wpe/v1/admin/product/{id}/variants/preview-generator
 * - POST /wpe/v1/admin/product/{id}/variants/preview-bulk-edit
 * - POST /wpe/v1/admin/product/{id}/variants/preview-image-assignment
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

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants/preview-image-assignment', [
            'methods'             => 'POST',
            'callback'            => [$this, 'previewImageAssignment'],
            'permission_callback' => [$this, 'checkProductEditPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants/preview-stock-policy', [
            'methods'             => 'POST',
            'callback'            => [$this, 'previewStockPolicy'],
            'permission_callback' => [$this, 'checkProductEditPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants/preview-type-transition', [
            'methods'             => 'POST',
            'callback'            => [$this, 'previewTypeTransition'],
            'permission_callback' => [$this, 'checkProductEditPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                ],
            ],
        ]);

        register_rest_route($namespace, '/product/(?P<id>\d+)/variants/apply-bulk-edit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'applyBulkEdit'],
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
                'success'    => true,
                'result'     => $result,
                'required'   => $result['required'] ?? null,
                'projection' => $result['projection'] ?? null,
            ], 200);
        } catch (EditorValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (UploadRejectedException $e) {
            return $this->mediaRuleResponse($e);
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
            $selected = isset($params['selected']) && is_array($params['selected']) ? $params['selected'] : null;
            $result = $this->service->previewCombinations($productId, $options, $selected);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (EditorValidationException $e) {
            return $this->validationErrorResponse($e);
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
            if (isset($params['scope']) && is_array($params['scope'])) {
                $result = $this->service->previewBulk($productId, $params['scope'], $actions);
            } else {
                $result = $this->service->previewBulkEdit($productId, $variationIds, $actions);
            }
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (EditorValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (UploadRejectedException $e) {
            return $this->mediaRuleResponse($e);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function previewImageAssignment(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        $params    = $request->get_json_params() ?: [];
        $rawAsset  = $params['asset_id'] ?? null;

        try {
            if (!is_string($rawAsset) || !preg_match('/^[1-9][0-9]*$/', $rawAsset)) {
                throw new UploadRejectedException('invalid_request', 'asset_id is required.', 400, false, [['pointer' => '/asset_id', 'reason' => 'required']]);
            }
            $target = [];
            if (isset($params['option']) && is_array($params['option'])) {
                $target['option'] = $params['option'];
            } else {
                $target['variant_ids'] = $params['variant_ids'] ?? null;
            }
            return new WP_REST_Response([
                'success' => true,
                'result'  => $this->service->previewImageAssignment($productId, (int) $rawAsset, $target),
            ], 200);
        } catch (UploadRejectedException $e) {
            return $this->mediaRuleResponse($e);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /** A media rule refused the request: answer with its status, a stable code and the places involved. */
    private function mediaRuleResponse(UploadRejectedException $e): WP_REST_Response
    {
        $details = [];
        foreach ($e->details() as $d) {
            $details[] = ['pointer' => (string) ($d['pointer'] ?? ''), 'reason' => (string) ($d['reason'] ?? $e->reason())];
        }
        return new WP_REST_Response([
            'success' => false,
            'code'    => $e->reason(),
            'message' => $e->getMessage(),
            'details' => $details !== [] ? $details : [['pointer' => '', 'reason' => $e->reason()]],
        ], $e->httpStatus());
    }
    public function previewStockPolicy(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        $params    = $request->get_json_params() ?: [];

        try {
            $result = $this->service->previewStockPolicy($productId, $params);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (EditorValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function previewTypeTransition(WP_REST_Request $request): WP_REST_Response
    {
        $productId  = (int) $request->get_param('id');
        $params     = $request->get_json_params() ?: [];
        $targetType = (string)($params['target_type'] ?? 'simple');

        try {
            $result = $this->service->previewTypeTransition($productId, $targetType);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (EditorValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function applyBulkEdit(WP_REST_Request $request): WP_REST_Response
    {
        $productId = (int) $request->get_param('id');
        $params    = $request->get_json_params() ?: [];
        $ids       = array_map('intval', (array)($params['ids'] ?? []));
        $token     = (string)($params['token'] ?? '');
        $actions   = (array)($params['actions'] ?? []);
        $actorId   = (int) get_current_user_id();

        try {
            $result = $this->service->applyBulkEdit($productId, $ids, $token, $actions, $actorId);
            return new WP_REST_Response([
                'success' => true,
                'result'  => $result,
            ], 200);
        } catch (EditorValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (UploadRejectedException $e) {
            return $this->mediaRuleResponse($e);
        } catch (ConcurrencyConflictException $e) {
            return new WP_REST_Response([
                'success'     => false,
                'code'        => 'concurrency_conflict',
                'message'     => $e->getMessage(),
                'server_data' => $e->getServerData(),
            ], 409);
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    private function validationErrorResponse(EditorValidationException $e): WP_REST_Response
    {
        $resp = [
            'success' => false,
            'code'    => 'validation_failed',
            'reason'  => $e->reason(),
            'message' => $e->getMessage(),
            'details' => $e->details(),
        ];
        if ($e->context() !== []) {
            $resp['context'] = $e->context();
        }
        return new WP_REST_Response($resp, 422);
    }
}
