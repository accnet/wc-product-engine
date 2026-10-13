<?php

/**
 * P010.7 — ProductEditorSaveTest
 *
 * Verifies unified save endpoint, atomic transaction, conflict tokens,
 * idempotency via operation_key, partial projection result, and preview endpoints:
 *  1. Unified save result structure (required + projection + full data)
 *  2. Idempotency via operation_key (cached commit, no double increment)
 *  3. Product version conflict detection (409 / ConcurrencyConflictException)
 *  4. Variation version conflict detection (409 / ConcurrencyConflictException)
 *  5. API validation failed mapping (HTTP 422 with pointer and reason)
 *  6. Simple <-> Variable transitions through atomic save
 *  7. Preview endpoints (preview-stock-policy, preview-type-transition, apply-bulk-edit)
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorApiController;
use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Admin\ProductEditor\ConcurrencyConflictException;
use WPE\Admin\ProductEditor\EditorValidationException;
use WPE\Admin\ProductEditor\VariantRulesService;

class ProductEditorSaveTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private ProductEditorApiController $controller;
    private array $cleanProductIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
        $this->controller = ProductEditorApiController::getInstance();
    }

    private function check(bool $cond, string $msg): void
    {
        $this->checks++;
        if (!$cond) {
            throw new \AssertionError($msg);
        }
    }

    public function run(): void
    {
        echo "Running ProductEditorSave integration tests...\n";
        try {
            $this->testUnifiedSaveResultStructure();
            $this->testOperationKeyIdempotency();
            $this->testProductVersionConflict();
            $this->testVariationVersionConflict();
            $this->testApiValidationFailedMapping();
            $this->testTypeTransitionsViaSave();
            $this->testPreviewEndpoints();
            $this->cleanup();
            echo "All ProductEditorSave tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function createPost(string $type = 'simple', string $title = 'Save Test Product'): int
    {
        $id = wp_insert_post([
            'post_title'  => $title . ' ' . uniqid(),
            'post_status' => 'publish',
            'post_type'   => 'product',
        ]);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create test product');
        }
        wp_set_object_terms($id, $type, 'product_type');
        $this->cleanProductIds[] = (int)$id;
        return (int)$id;
    }

    private function testUnifiedSaveResultStructure(): void
    {
        echo " - unified save result structure (required + projection)...";
        $pid = $this->createPost('simple', 'Structure Test');

        $res = $this->service->saveProductVariants($pid, [
            'product' => [
                'sku'           => 'STRUCT-SKU-' . $pid,
                'regular_price' => '120.00',
                'sale_price'    => '99.00',
                'stock'         => [
                    'manage_stock'   => true,
                    'stock_quantity' => 25,
                    'stock_status'   => 'instock',
                    'backorders'     => 'no',
                ],
            ],
            'descriptive_attributes' => [
                ['name' => 'Brand', 'values' => ['Antigravity']],
            ],
        ]);

        $this->check($res['success'] === true, 'save returned success true');
        $this->check(isset($res['required']), 'response contains required key');
        $this->check(isset($res['projection']), 'response contains projection key');
        $this->check(isset($res['data']), 'response contains data key');

        // Required block
        $req = $res['required'];
        $this->check((int)$req['new_version'] >= 1, 'required.new_version is integer >= 1');
        $this->check($req['type'] === 'simple', 'required.type is simple');
        $this->check(is_array($req['variation_versions']), 'required.variation_versions is array');
        $this->check(count($req['variation_versions']) === 0, 'required.variation_versions is empty for simple product');

        // Projection block
        $proj = $res['projection'];
        $this->check(isset($proj['legacy_mirror']), 'projection has legacy_mirror status');
        $this->check(isset($proj['catalog_index']), 'projection has catalog_index status');

        // Data block
        $data = $res['data'];
        $this->check($data['product']['sku'] === 'STRUCT-SKU-' . $pid, 'data.product.sku matches saved SKU');
        $this->check((float)$data['product']['regular_price'] === 120.00, 'regular_price matches');
        $this->check((float)$data['product']['sale_price'] === 99.00, 'sale_price matches');
        $this->check($data['product']['parent_stock']['manage_stock'] === true, 'manage_stock is true');
        $this->check((int)$data['product']['parent_stock']['stock_quantity'] === 25, 'stock_quantity is 25');
        $this->check($data['common_price'] === null, 'common_price is null for simple');
        $this->check($data['reference_price']['state'] === 'none', 'reference_price state is none');
        $this->check($data['derived_type'] === 'simple', 'derived_type is simple');
        $this->check(count($data['descriptive_attributes']) === 1, 'one descriptive attribute saved');

        echo " OK\n";
    }

    private function testOperationKeyIdempotency(): void
    {
        echo " - operation_key idempotency...";
        $pid = $this->createPost('simple', 'Idempotency Test');
        $init = $this->service->getProductEditorData($pid);
        $v1 = (int)$init['product']['version'];

        $opKey = 'op-save-test-' . uniqid();
        $payload = [
            'operation_key'    => $opKey,
            'expected_version' => $v1,
            'product'          => [
                'sku'           => 'IDEMP-' . $pid,
                'regular_price' => '85.00',
                'stock'         => ['manage_stock' => false],
            ],
        ];

        // First invocation
        $res1 = $this->service->saveProductVariants($pid, $payload);
        $newV = (int)$res1['required']['new_version'];
        $this->check($newV === $v1 + 1, 'first save incremented product version');

        // Second invocation with identical operation_key
        $res2 = $this->service->saveProductVariants($pid, $payload);
        $this->check($res2['required']['new_version'] === $newV, 'idempotent call returns identical new_version');
        $this->check($res2['data']['product']['regular_price'] === $res1['data']['product']['regular_price'], 'idempotent call returns cached data');

        // Check database version did not increment again
        $dbVersion = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT version FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $pid
        ));
        $this->check($dbVersion === $newV, 'database product version remained unchanged on idempotent replay');

        echo " OK\n";
    }

    private function testProductVersionConflict(): void
    {
        echo " - product version conflict detection (409)...";
        $pid = $this->createPost('simple', 'Version Conflict Test');
        $this->service->saveProductVariants($pid, [
            'product' => [
                'sku'           => 'CONF-SKU-' . $pid,
                'regular_price' => '50.00',
                'stock'         => ['manage_stock' => false],
            ],
        ]);

        $data = $this->service->getProductEditorData($pid);
        $currentVersion = (int)$data['product']['version'];
        $this->check($currentVersion >= 2, 'product version is at least 2');

        $conflictCaught = false;
        try {
            $this->service->saveProductVariants($pid, [
                'expected_version' => $currentVersion - 1, // Stale version
                'product'          => [
                    'sku'           => 'CONF-SKU-' . $pid,
                    'regular_price' => '60.00',
                ],
            ]);
        } catch (ConcurrencyConflictException $e) {
            $conflictCaught = true;
            $this->check(stripos($e->getMessage(), 'version') !== false, 'conflict exception message notes version');
            $serverData = $e->getServerData();
            $this->check(isset($serverData['product']), 'conflict exception includes fresh server data');
            $this->check((int)$serverData['product']['version'] === $currentVersion, 'server data has current version');
        }

        $this->check($conflictCaught, 'ConcurrencyConflictException was thrown for stale product expected_version');
        echo " OK\n";
    }

    private function testVariationVersionConflict(): void
    {
        echo " - variation version conflict detection (409)...";
        $pid = $this->createPost('variable', 'Var Conflict Test');
        $init = $this->service->getProductEditorData($pid);

        // Save with 1 variation
        $saved = $this->service->saveProductVariants($pid, [
            'expected_version'  => (int)$init['product']['version'],
            'variation_options' => [
                [
                    'name'     => 'Size',
                    'position' => 0,
                    'values'   => [['label' => 'M', 'position' => 0]],
                ],
            ],
            'variations' => [
                [
                    'sku'           => 'VAR-CONF-M-' . $pid,
                    'combination'   => ['Size' => 'M'],
                    'pricing_mode'  => 'own',
                    'regular_price' => '30.00',
                    'status'        => 'publish',
                    'stock'         => ['manage_stock' => false],
                ],
            ],
        ]);

        $varId = (int)$saved['data']['variations'][0]['id'];
        $varVer = (int)$saved['data']['variations'][0]['version'];
        $pVer = (int)$saved['required']['new_version'];

        $conflictCaught = false;
        try {
            $this->service->saveProductVariants($pid, [
                'expected_version'  => $pVer,
                'variation_options' => $saved['data']['variation_options'],
                'variations'        => [
                    [
                        'id'            => $varId,
                        'version'       => $varVer + 99, // Mismatched variation version
                        'sku'           => 'VAR-CONF-M-' . $pid,
                        'combination'   => ['Size' => 'M'],
                        'pricing_mode'  => 'own',
                        'regular_price' => '35.00',
                        'status'        => 'publish',
                    ],
                ],
            ]);
        } catch (ConcurrencyConflictException $e) {
            $conflictCaught = true;
            $this->check(stripos($e->getMessage(), 'variation') !== false, 'conflict message notes variation version');
        }

        $this->check($conflictCaught, 'ConcurrencyConflictException was thrown for variation version mismatch');
        echo " OK\n";
    }

    private function testApiValidationFailedMapping(): void
    {
        echo " - REST API validation_failed mapping (422)...";
        $pid = $this->createPost('simple', 'API Validation Test');
        $data = $this->service->getProductEditorData($pid);

        // Case A: Negative regular price
        $req1 = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants");
        $req1->set_param('id', $pid);
        $req1->set_header('content-type', 'application/json');
        $req1->set_body(wp_json_encode([
            'expected_version' => $data['product']['version'],
            'product'          => [
                'regular_price' => '-10.00',
            ],
        ]));

        $res1 = $this->controller->saveProductVariants($req1);
        $this->check($res1->get_status() === 422, 'negative regular price returns 422');
        $d1 = $res1->get_data();
        $this->check($d1['success'] === false, 'success is false');
        $this->check($d1['code'] === 'validation_failed', 'code is validation_failed');
        $this->check(isset($d1['details']) && is_array($d1['details']), 'details array is present');
        $foundPriceErr = false;
        foreach ($d1['details'] as $det) {
            if ($det['pointer'] === '/product/regular_price' && $det['reason'] === 'invalid_amount') {
                $foundPriceErr = true;
            }
        }
        $this->check($foundPriceErr, 'detail points to /product/regular_price with reason invalid_amount');

        // Case B: Removing common price when variation uses shared pricing mode
        $pVar = $this->createPost('variable', 'Shared Removal API Test');
        $initVar = $this->service->getProductEditorData($pVar);
        $vRes = $this->service->saveProductVariants($pVar, [
            'expected_version'  => (int)$initVar['product']['version'],
            'common_price'      => ['regular_price' => '50.00'],
            'variation_options' => [
                [
                    'name'     => 'Color',
                    'position' => 0,
                    'values'   => [['label' => 'Red', 'position' => 0]],
                ],
            ],
            'variations' => [
                [
                    'sku'          => 'SHARED-RED-' . $pVar,
                    'combination'  => ['Color' => 'Red'],
                    'pricing_mode' => 'shared',
                    'status'       => 'publish',
                ],
            ],
        ]);

        $savedVar = $vRes['data']['variations'][0];
        $req2 = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pVar}/variants");
        $req2->set_param('id', $pVar);
        $req2->set_header('content-type', 'application/json');
        // Removing common price while variations are in shared pricing mode without updating their mode
        $req2->set_body(wp_json_encode([
            'expected_version'  => $vRes['required']['new_version'],
            'common_price'      => null,
            'variation_options' => $vRes['data']['variation_options'],
            'variations'        => [
                [
                    'id'           => $savedVar['id'],
                    'version'      => $savedVar['version'],
                    'sku'          => $savedVar['sku'],
                    'combination'  => $savedVar['combination'],
                    'pricing_mode' => 'shared', // Still shared!
                ],
            ],
        ]));

        $res2 = $this->controller->saveProductVariants($req2);
        $this->check($res2->get_status() === 422, 'removing in-use common price returns 422');
        $d2 = $res2->get_data();
        $this->check($d2['code'] === 'validation_failed', 'code is validation_failed');
        $foundCommonErr = false;
        foreach ($d2['details'] as $det) {
            if ($det['reason'] === 'common_price_in_use') {
                $foundCommonErr = true;
            }
        }
        $this->check($foundCommonErr, 'detail reports common_price_in_use');

        echo " OK\n";
    }

    private function testTypeTransitionsViaSave(): void
    {
        echo " - type transitions via atomic save...";
        // 1. Simple -> Variable
        $pid = $this->createPost('simple', 'Transition S to V');
        $init = $this->service->getProductEditorData($pid);
        $this->check($init['product']['type'] === 'simple', 'initially simple');

        $toVarRes = $this->service->saveProductVariants($pid, [
            'expected_version'  => (int)$init['product']['version'],
            'variation_options' => [
                [
                    'name'     => 'Format',
                    'position' => 0,
                    'values'   => [
                        ['label' => 'Standard', 'position' => 0],
                        ['label' => 'Deluxe', 'position' => 1],
                    ],
                ],
            ],
            'variations' => [
                [
                    'sku'           => 'TRANS-STD-' . $pid,
                    'combination'   => ['Format' => 'Standard'],
                    'pricing_mode'  => 'own',
                    'regular_price' => '20.00',
                    'status'        => 'publish',
                ],
                [
                    'sku'           => 'TRANS-DLX-' . $pid,
                    'combination'   => ['Format' => 'Deluxe'],
                    'pricing_mode'  => 'own',
                    'regular_price' => '40.00',
                    'status'        => 'publish',
                ],
            ],
        ]);

        $this->check($toVarRes['required']['type'] === 'variable', 'required.type is now variable');
        $dbType = $this->wpdb->get_var($this->wpdb->prepare("SELECT type FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $pid));
        $this->check($dbType === 'variable', 'database wpe_products type is variable');
        $terms = wp_get_object_terms($pid, 'product_type');
        $termSlugs = wp_list_pluck($terms, 'slug');
        $this->check(in_array('variable', $termSlugs, true), 'WP product_type term is variable');

        // 2. Variable -> Simple
        $receiverId = (int)$toVarRes['data']['variations'][0]['id'];
        $toSimpleRes = $this->service->saveProductVariants($pid, [
            'expected_version'  => (int)$toVarRes['required']['new_version'],
            'product'           => [
                'sku'           => 'TRANS-SIMPLE-' . $pid,
                'regular_price' => '25.00',
                'stock'         => ['manage_stock' => false],
            ],
            'variation_options' => [],
            'variations'        => [],
            'type_transition'   => [
                'receiver_id'       => $receiverId,
                'confirm_tombstone' => true,
            ],
        ]);

        $this->check($toSimpleRes['required']['type'] === 'simple', 'required.type is now simple');
        $dbType2 = $this->wpdb->get_var($this->wpdb->prepare("SELECT type FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $pid));
        $this->check($dbType2 === 'simple', 'database wpe_products type is simple');
        $terms2 = wp_get_object_terms($pid, 'product_type');
        $termSlugs2 = wp_list_pluck($terms2, 'slug');
        $this->check(in_array('simple', $termSlugs2, true), 'WP product_type term is simple');

        // Variations should now be deleted or empty
        $activeVariants = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d AND deleted_at IS NULL",
            $pid
        ));
        $this->check((int)$activeVariants === 0, 'active variations count is 0 after variable -> simple transition');

        echo " OK\n";
    }

    private function testPreviewEndpoints(): void
    {
        echo " - preview endpoints (previewStockPolicy, previewTypeTransition, applyBulkEdit)...";
        $pid = $this->createPost('variable', 'Preview Test Product');
        $init = $this->service->getProductEditorData($pid);

        // Setup 2 variations sharing parent stock
        $saved = $this->service->saveProductVariants($pid, [
            'expected_version'  => (int)$init['product']['version'],
            'product'           => [
                'sku'   => 'PREV-PAR-' . $pid,
                'stock' => ['manage_stock' => true, 'stock_quantity' => 10],
            ],
            'variation_options' => [
                [
                    'name'     => 'Flavor',
                    'position' => 0,
                    'values'   => [
                        ['label' => 'Vanilla', 'position' => 0],
                        ['label' => 'Choc', 'position' => 1],
                    ],
                ],
            ],
            'variations' => [
                [
                    'sku'            => 'PREV-VAN-' . $pid,
                    'combination'    => ['Flavor' => 'Vanilla'],
                    'pricing_mode'   => 'own',
                    'regular_price'  => '15.00',
                    'inventory_mode' => VariantRulesService::MODE_SHARED,
                    'status'         => 'publish',
                ],
                [
                    'sku'            => 'PREV-CHOC-' . $pid,
                    'combination'    => ['Flavor' => 'Choc'],
                    'pricing_mode'   => 'own',
                    'regular_price'  => '15.00',
                    'inventory_mode' => VariantRulesService::MODE_SHARED,
                    'status'         => 'publish',
                ],
            ],
        ]);

        // A. previewStockPolicy via REST
        $reqStock = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants/preview-stock-policy");
        $reqStock->set_param('id', $pid);
        $reqStock->set_header('content-type', 'application/json');
        $reqStock->set_body(wp_json_encode(['manage_stock' => false]));

        $resStock = $this->controller->previewStockPolicy($reqStock);
        $this->check($resStock->get_status() === 200, 'previewStockPolicy returns 200');
        $dStock = $resStock->get_data()['result'];
        $this->check($dStock['current']['manage_stock'] === true, 'current manage_stock is true');
        $this->check($dStock['proposed']['manage_stock'] === false, 'proposed manage_stock is false');
        $this->check(count($dStock['affected_variations']) === 2, 'two variations affected by parent stock disable');
        $this->check($dStock['requires_confirmation'] === true, 'requires confirmation');

        // B. previewTypeTransition via REST
        $reqTrans = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants/preview-type-transition");
        $reqTrans->set_param('id', $pid);
        $reqTrans->set_header('content-type', 'application/json');
        $reqTrans->set_body(wp_json_encode(['target_type' => 'simple']));

        $resTrans = $this->controller->previewTypeTransition($reqTrans);
        $this->check($resTrans->get_status() === 200, 'previewTypeTransition returns 200');
        $dTrans = $resTrans->get_data()['result'];
        $this->check($dTrans['from'] === 'variable', 'from is variable');
        $this->check($dTrans['to'] === 'simple', 'to is simple');
        $this->check($dTrans['changed'] === true, 'changed is true');
        $this->check(count($dTrans['variations']) === 2, 'lists 2 variations to be removed');

        // C. applyBulkEdit via REST
        // First preview bulk edit to obtain resolved ids and scope token
        $varIds = array_map(fn($v) => (int)$v['id'], $saved['data']['variations']);
        $bulkPreview = $this->service->previewBulk($pid, ['type' => 'ids', 'ids' => $varIds], [
            'stock_quantity' => 50,
        ]);
        $token = $bulkPreview['scope']['token'];
        $this->check(is_string($token) && str_contains($token, '.') && strlen($token) > 64, 'previewBulk returned HMAC scope token');

        // Apply bulk edit with invalid token -> 422
        $reqBulkBad = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants/apply-bulk-edit");
        $reqBulkBad->set_param('id', $pid);
        $reqBulkBad->set_header('content-type', 'application/json');
        $reqBulkBad->set_body(wp_json_encode([
            'ids'     => $varIds,
            'token'   => 'tampered_fake_token_1234567890abcdef1234567890abcdef1234567890abcdef',
            'actions' => ['stock_quantity' => 50],
        ]));
        $resBulkBad = $this->controller->applyBulkEdit($reqBulkBad);
        $this->check($resBulkBad->get_status() === 422, 'tampered scope token returns 422');

        // Apply bulk edit with valid token
        $reqBulkOk = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants/apply-bulk-edit");
        $reqBulkOk->set_param('id', $pid);
        $reqBulkOk->set_header('content-type', 'application/json');
        $reqBulkOk->set_body(wp_json_encode([
            'ids'     => $varIds,
            'token'   => $token,
            'actions' => ['stock_quantity' => 50],
        ]));
        $resBulkOk = $this->controller->applyBulkEdit($reqBulkOk);
        $this->check($resBulkOk->get_status() === 200, 'applyBulkEdit with valid token returns 200');
        $dBulkOk = $resBulkOk->get_data()['result'];
        $this->check(isset($dBulkOk['bulk']), 'result contains bulk summary');

        // Check shared stock owner was updated to 50
        $parentQty = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT quantity FROM {$this->wpdb->prefix}wpe_inventory WHERE owner_id = %d",
            $pid
        ));
        $this->check($parentQty === 50.0, 'parent stock quantity was updated to 50 via bulk owner grouping');

        echo " OK\n";
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanProductIds as $id) {
            $variantIds = $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d", $id));
            if ($variantIds) {
                $vList = implode(',', array_map('intval', $variantIds));
                $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$vList})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_sku_registry WHERE object_id IN ({$vList})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$vList})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE id IN ({$vList})");
                foreach ($variantIds as $vid) {
                    wp_delete_post((int)$vid, true);
                }
            }
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
    }
}

$test = new ProductEditorSaveTest();
$test->run();
