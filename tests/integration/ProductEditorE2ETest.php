<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

// These tests rely on assert(). WP-CLI on some hosts runs with zend.assertions=-1, which compiles every
// assert() out and makes the file pass without checking anything. Fail fast instead of passing vacuously.
if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Admin\ProductEditor\ProductEditorBridge;
use WPE\Admin\ProductEditor\ProductEditorApiController;
use WPE\Admin\ProductEditor\ConcurrencyConflictException;
use WPE\Compatibility\Gallery\UrlValidator;

class ProductEditorE2ETest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private ProductEditorBridge $bridge;
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
        $this->bridge = ProductEditorBridge::getInstance();
    }

    public function run(): void
    {
        echo "Running ProductEditor E2E Integration Tests (10 Acceptance Criteria)...\n";

        try {
            $this->setUp();
            $this->testAC1_VariableProductTabIntegration();
            $this->testAC2_NonVariableProductsUntouched();
            $this->testAC3_ExistingDataLoadedAccurately();
            $this->testAC4_OptionEditPreservesVariantIds();
            $this->testAC5_SelectiveCombinationGeneration();
            $this->testAC6_BulkEditPreviewAndScope();
            $this->testAC7_DualSavePersistencePaths();
            $this->testAC8_ConcurrencyConflictProtection();
            $this->testAC9_InventoryParentStockGuard();
            $this->testAC10_MediaAssignmentUnlinkOnly();
            $this->tearDown();

            echo "All 10 ProductEditor E2E acceptance criteria verified successfully!\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
    }

    private function setUp(): void
    {
        $this->cleanProductIds = [];
        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        $adminUsers = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($adminUsers)) {
            wp_set_current_user($adminUsers[0]->ID);
        }
    }

    private function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanProductIds as $id) {
            $variantIds = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d",
                $id
            ));
            if (!empty($variantIds)) {
                $idList = implode(',', array_map('intval', $variantIds));
                $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$idList})");
            }
            $optionIds = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$prefix}wpe_options WHERE product_id = %d",
                $id
            ));
            if (!empty($optionIds)) {
                $optList = implode(',', array_map('intval', $optionIds));
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE option_id IN ({$optList})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN ({$optList})");
            }
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
    }

    private function createProduct(string $type = 'variable'): int
    {
        $postId = wp_insert_post([
            'post_title'   => 'E2E ' . ucfirst($type) . ' Product ' . uniqid(),
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create product post");
        }

        wp_set_object_terms($postId, $type, 'product_type');
        wp_cache_flush(); // WooCommerce caches the product type on first lookup, which wp_insert_post triggers.
        $this->cleanProductIds[] = $postId;
        return $postId;
    }

    public function testAC1_VariableProductTabIntegration(): void
    {
        echo " - AC1: Variable Product Tab Integration...";

        // The Variants Manager is its own meta box now: no tab may be added to Product data.
        $tabs = [
            'general'    => ['label' => 'General', 'target' => 'general_product_data', 'class' => ['hide_if_grouped']],
            'attribute'  => ['label' => 'Attributes', 'target' => 'product_attributes', 'class' => ['attribute_options']],
            'variations' => ['label' => 'Variations', 'target' => 'variable_product_options', 'class' => ['variations_options', 'show_if_variable']],
        ];
        $filteredTabs = apply_filters('woocommerce_product_data_tabs', $tabs);
        assert(!isset($filteredTabs['wpe_variants_manager']), 'No Variants Manager tab may be added to Product data');

        // The box is registered on the product screen and shown for a variable product.
        global $wp_meta_boxes, $post, $thepostid;
        $backupBoxes = $wp_meta_boxes;
        $wp_meta_boxes = [];
        $this->bridge->registerMetaBox('product');
        assert(isset($wp_meta_boxes['product']['normal']['default'][\WPE\Admin\ProductEditor\ProductEditorBridge::BOX_ID]), 'Variants Manager meta box must be registered on the product screen');
        $wp_meta_boxes = $backupBoxes;

        $variableId = $this->createProduct('variable');
        $post = get_post($variableId);
        $thepostid = $variableId;
        assert(!in_array('wpe-box-hidden', $this->bridge->filterBoxClasses(['postbox']), true), 'The box must be visible for a variable product');

        // Check CSS rule exists for hiding default tabs on variable products
        $cssContent = file_get_contents(dirname(__DIR__, 2) . '/assets/admin/product-editor.css');
        assert(strpos($cssContent, '#woocommerce-product-data.wpe-is-variable .product_data_tabs li.attribute_options') !== false, 'CSS must hide attribute_options on variable product');
        assert(strpos($cssContent, '#woocommerce-product-data.wpe-is-variable .product_data_tabs li.variations_options') !== false, 'CSS must hide variations_options on variable product');

        echo " OK\n";
    }

    public function testAC2_NonVariableProductsUntouched(): void
    {
        echo " - AC2: Non-Variable Products Untouched...";

        $simpleId = $this->createProduct('simple');
        $simpleProduct = wc_get_product($simpleId);
        assert($simpleProduct->get_type() === 'simple', 'Product type must be simple');

        // The box renders hidden for an unsupported product type (e.g. grouped) in MODE_BLOCKS,
        // and in MODE_METABOX (recovery mode) for simple products as well.
        $groupedId = $this->createProduct('grouped');
        global $post, $thepostid;
        $post = get_post($groupedId);
        $thepostid = $groupedId;
        assert(in_array('wpe-box-hidden', $this->bridge->filterBoxClasses(['postbox']), true), 'The box must render hidden for an unsupported product type');

        update_option('wpe_editor_mode', \WPE\Admin\ProductEditor\ProductEditorBridge::MODE_METABOX);
        $post = get_post($simpleId);
        $thepostid = $simpleId;
        assert(in_array('wpe-box-hidden', $this->bridge->filterBoxClasses(['postbox']), true), 'In MODE_METABOX, the box must render hidden for a simple product');
        delete_option('wpe_editor_mode');

        $cssContent = file_get_contents(dirname(__DIR__, 2) . '/assets/admin/product-editor.css');
        assert(strpos($cssContent, '#wpe_variants_manager.wpe-box-hidden') !== false, 'CSS must hide the box for non-variable products');

        // A normal Update of a simple product carries no payload and must not create variant data.
        global $wpdb;
        $_POST['wpe_variants_payload'] = '';
        $this->bridge->processProductMetaSave($simpleId);
        unset($_POST['wpe_variants_payload']);
        assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpe_products WHERE id = %d", $simpleId)) === 0, 'A simple product must not get native variant data');

        echo " OK\n";
    }

    public function testAC3_ExistingDataLoadedAccurately(): void
    {
        echo " - AC3: Existing Data Loaded Accurately...";

        $productId = $this->createProduct('variable');
        $data = $this->service->getProductEditorData($productId);

        assert(!empty($data['product']), 'Product payload loaded');
        assert(isset($data['product']['version']), 'Product version loaded');
        assert(is_array($data['descriptive_attributes']), 'Descriptive attributes array');
        assert(is_array($data['variation_options']), 'Variation options array');
        assert(is_array($data['variations']), 'Variations array');
        assert(is_array($data['taxonomies']), 'Taxonomies array');

        echo " OK\n";
    }

    public function testAC4_OptionEditPreservesVariantIds(): void
    {
        echo " - AC4: Option Edit Preserves Variant IDs...";

        $productId = $this->createProduct('variable');
        $initialData = $this->service->getProductEditorData($productId);

        // 1. Initial creation
        $payload1 = [
            'expected_version' => $initialData['product']['version'],
            'variation_options' => [
                [
                    'name'     => 'Color',
                    'position' => 0,
                    'values'   => [['label' => 'Red', 'position' => 0]],
                ]
            ],
            'variations' => [
                [
                    'combination'   => ['Color' => 'Red'],
                    'sku'           => 'E2E-RED-' . uniqid(),
                    'regular_price' => 30.00,
                ]
            ]
        ];
        $res1 = $this->service->saveProductVariants($productId, $payload1);
        $savedData = $this->service->getProductEditorData($productId);
        $originalVariantId = $savedData['variations'][0]['id'];
        $optId = $savedData['variation_options'][0]['id'];

        // 2. Rename Option "Color" -> "Colour" and update value
        $payload2 = [
            'expected_version' => $res1['new_version'],
            'variation_options' => [
                [
                    'id'       => $optId,
                    'name'     => 'Colour',
                    'position' => 0,
                    'values'   => [['label' => 'Red', 'position' => 0]],
                ]
            ],
            'variations' => [
                [
                    'id'            => $originalVariantId,
                    'combination'   => ['Colour' => 'Red'],
                    'sku'           => $savedData['variations'][0]['sku'],
                    'regular_price' => 32.00,
                ]
            ]
        ];
        $res2 = $this->service->saveProductVariants($productId, $payload2);
        $reloaded = $this->service->getProductEditorData($productId);

        assert(count($reloaded['variations']) === 1, 'Should have 1 variation');
        assert($reloaded['variations'][0]['id'] === $originalVariantId, 'Variant ID MUST be preserved across option edits');
        assert((float)$reloaded['variations'][0]['regular_price'] === 32.0, 'Price updated');

        echo " OK\n";
    }

    public function testAC5_SelectiveCombinationGeneration(): void
    {
        echo " - AC5: Selective Combination Generation...";

        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $options = [
            [
                'name'   => 'Size',
                'values' => [['label' => 'S'], ['label' => 'M'], ['label' => 'L']],
            ],
            [
                'name'   => 'Style',
                'values' => [['label' => 'Modern'], ['label' => 'Classic']],
            ]
        ];

        // 3 * 2 = 6 potential combinations
        $preview = $this->service->previewCombinations($productId, $options);
        assert($preview['total_possible'] === 6, 'Total potential must be 6');

        // Choose to only create 2 specific combinations: (S / Modern) and (L / Classic)
        $chosenCombos = [
            ['Size' => 'S', 'Style' => 'Modern'],
            ['Size' => 'L', 'Style' => 'Classic']
        ];

        $data = $this->service->getProductEditorData($productId);
        $payload = [
            'expected_version'  => $data['product']['version'],
            'variation_options' => $options,
            'variations'        => array_map(function ($combo) {
                return [
                    'combination'   => $combo,
                    'sku'           => 'SEL-' . uniqid(),
                    'regular_price' => 45.00,
                ];
            }, $chosenCombos),
        ];

        $saveRes = $this->service->saveProductVariants($productId, $payload);
        $reloaded = $this->service->getProductEditorData($productId);

        assert(count($reloaded['variations']) === 2, 'Only the 2 selected combinations should be created');
        $sModern = false;
        $lClassic = false;
        foreach ($reloaded['variations'] as $v) {
            $attrs = $v['combination'];
            if ($attrs['Size'] === 'S' && $attrs['Style'] === 'Modern') $sModern = true;
            if ($attrs['Size'] === 'L' && $attrs['Style'] === 'Classic') $lClassic = true;
        }
        assert($sModern && $lClassic, 'Exact chosen combinations must exist');

        echo " OK\n";
    }

    public function testAC6_BulkEditPreviewAndScope(): void
    {
        echo " - AC6: Bulk Edit Preview and Scope...";

        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $data = $this->service->getProductEditorData($productId);
        $targetId = $data['variations'][0]['id'];
        $untouchedId = $data['variations'][1]['id'];

        // Preview modifying targetId only
        $preview = $this->service->previewBulkEdit($productId, [$targetId], [
            'regular_price' => ['type' => 'fixed', 'value' => 88.00],
            'status'        => 'private',
        ]);

        assert($preview['affected_count'] === 1, 'Only 1 row affected in preview');
        assert($preview['diffs'][0]['changes']['regular_price']['after'] === 88.00, 'Preview price after is 88.00');

        echo " OK\n";
    }

    public function testAC7_DualSavePersistencePaths(): void
    {
        echo " - AC7: Dual Save Persistence Paths (REST & WP Form Process)...";

        $productId = $this->createProduct('variable');
        $data = $this->service->getProductEditorData($productId);

        // Path 1: Direct Service / REST API Save
        $payload1 = [
            'expected_version' => $data['product']['version'],
            'variation_options' => [
                ['name' => 'Size', 'values' => [['label' => 'M']]]
            ],
            'variations' => [
                ['combination' => ['Size' => 'M'], 'sku' => 'REST-M-' . uniqid(), 'regular_price' => 50.00]
            ]
        ];
        $res1 = $this->service->saveProductVariants($productId, $payload1);
        assert($res1['success'] === true, 'Path 1 save succeeded');

        // Path 2: Standard WordPress Update Form Submit
        $data2 = $this->service->getProductEditorData($productId);
        $payload2 = [
            'expected_version' => $data2['product']['version'],
            'variation_options' => [
                ['name' => 'Size', 'values' => [['label' => 'M'], ['label' => 'L']]]
            ],
            'variations' => [
                $data2['variations'][0],
                ['combination' => ['Size' => 'L'], 'sku' => 'WP-L-' . uniqid(), 'regular_price' => 55.00]
            ]
        ];

        $_POST['wpe_variants_payload'] = wp_slash(json_encode($payload2));
        $_POST['wpe_variants_nonce']   = wp_create_nonce('wpe_save_variants');

        $this->bridge->processProductMetaSave($productId);

        unset($_POST['wpe_variants_payload']);
        unset($_POST['wpe_variants_nonce']);

        $reloaded = $this->service->getProductEditorData($productId);
        assert(count($reloaded['variations']) === 2, 'Path 2 saved correctly and has 2 variations');

        echo " OK\n";
    }

    public function testAC8_ConcurrencyConflictProtection(): void
    {
        echo " - AC8: Concurrency Conflict Protection...";

        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $data = $this->service->getProductEditorData($productId);
        $currentVersion = $data['product']['version'];

        $staleAttemptFailed = false;
        try {
            $this->service->saveProductVariants($productId, [
                'expected_version' => $currentVersion - 1,
            ]);
        } catch (ConcurrencyConflictException $e) {
            $staleAttemptFailed = true;
            assert($e->getCode() === 409, 'Conflict code must be 409');
        }

        assert($staleAttemptFailed, 'Stale concurrent update must be blocked with HTTP 409');

        echo " OK\n";
    }

    public function testAC9_InventoryParentStockGuard(): void
    {
        echo " - AC9: Inventory Parent Stock Guard...";

        $productId = $this->createProduct('variable');
        $data = $this->service->getProductEditorData($productId);

        $payload = [
            'expected_version' => $data['product']['version'],
            'variation_options' => [
                ['name' => 'Size', 'values' => [['label' => 'OneSize']]]
            ],
            'variations' => [
                [
                    'combination'   => ['Size' => 'OneSize'],
                    'sku'           => 'PSTK-' . uniqid(),
                    'regular_price' => 15.00,
                    'manage_stock'  => 0, // Uses parent stock
                ]
            ]
        ];

        $this->service->saveProductVariants($productId, $payload);
        $reloaded = $this->service->getProductEditorData($productId);
        $v = $reloaded['variations'][0];

        assert($v['manage_stock'] === false, 'manage_stock must be false');
        assert((int)$v['inventory']['stock_owner_id'] === $productId, 'Stock owner must be parent');

        echo " OK\n";
    }

    public function testAC10_MediaAssignmentUnlinkOnly(): void
    {
        echo " - AC10: Media Assignment Unlink Only...";

        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $data = $this->service->getProductEditorData($productId);
        $variant = $data['variations'][0];

        // Create a real WP attachment
        $uploadDir = wp_upload_dir();
        $testFilePath = $uploadDir['path'] . '/test_e2e_img_' . uniqid() . '.jpg';
        file_put_contents($testFilePath, 'dummy image content');

        $attachmentId = wp_insert_attachment([
            'guid'           => $uploadDir['url'] . '/' . basename($testFilePath),
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'Test E2E Media',
            'post_content'   => '',
            'post_status'    => 'inherit'
        ], $testFilePath);

        // A variation image comes from the product library: register the image and add it to the library first.
        $mediaRepo = new \WPE\Infrastructure\Database\MediaRepository($this->wpdb);
        $asset     = $mediaRepo->createAsset([
            'source_url' => 'https://cdn.example.test/e2e-' . uniqid() . '.jpg',
            'mime'       => 'image/jpeg',
        ]);
        $mediaRepo->setFeaturedImage($productId, (int)$asset['id']);

        // Assign the library image to the variation
        $variant['attachment_id']  = $attachmentId;
        $variant['image_asset_id'] = (string)$asset['id'];
        $variant['image_url']      = $asset['source_url'];

        $payload = [
            'expected_version'  => $data['product']['version'],
            'variation_options' => $data['variation_options'],
            'variations'        => [$variant],
        ];
        $res = $this->service->saveProductVariants($productId, $payload);

        // Now unlink (remove) image from variation
        $freshData = $this->service->getProductEditorData($productId);
        $variant = $freshData['variations'][0];
        $variant['attachment_id']  = null;
        $variant['image_asset_id'] = null;
        $variant['image_url']      = null;
        $payloadUnlink = [
            'expected_version'  => $res['new_version'],
            'variation_options' => $freshData['variation_options'],
            'variations'        => [$variant],
        ];
        $this->service->saveProductVariants($productId, $payloadUnlink);

        // Verify media post STILL exists in WordPress media library (unlinked, NOT deleted!)
        $attachmentPost = get_post($attachmentId);
        assert($attachmentPost !== null, 'Media post must NOT be deleted from library when unlinked');

        $this->wpdb->delete($this->wpdb->prefix . 'wpe_product_media', ['product_id' => $productId]);
        $mediaRepo->deleteAsset((int)$asset['id'], true);

        // Cleanup test attachment
        wp_delete_attachment($attachmentId, true);
        if (file_exists($testFilePath)) {
            @unlink($testFilePath);
        }

        echo " OK\n";
    }
}

$test = new ProductEditorE2ETest();
$test->run();
