<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Admin\ProductEditor\ProductEditorBridge;
use WPE\Admin\ProductEditor\ProductEditorService;

class ProductEditorBridgeTest
{
    private wpdb $wpdb;
    private ProductEditorBridge $bridge;
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->bridge = ProductEditorBridge::getInstance();
    }

    public function run(): void
    {
        echo "Running ProductEditorBridge Integration Tests...\n";

        try {
            $this->setUp();
            $this->testProductDataTabRegistration();
            $this->testPanelRendering();
            $this->testProcessProductMetaSave();
            $this->tearDown();

            echo "All ProductEditorBridge tests passed successfully!\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
    }

    private function setUp(): void
    {
        $this->cleanProductIds = [];
        \WPE\Compatibility\Gallery\UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        // Set current user as administrator for permission checks
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
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
    }

    private function createTestProduct(): int
    {
        $postId = wp_insert_post([
            'post_title'   => 'Bridge Test Variable Product ' . uniqid(),
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create test product post");
        }

        wp_set_object_terms($postId, 'variable', 'product_type');
        $this->cleanProductIds[] = $postId;
        return $postId;
    }

    public function testProductDataTabRegistration(): void
    {
        echo " - testProductDataTabRegistration...";

        $tabs = [
            'general'   => ['label' => 'General', 'target' => 'general_product_data', 'class' => ['hide_if_grouped']],
            'attribute' => ['label' => 'Attributes', 'target' => 'product_attributes', 'class' => ['attribute_options']],
        ];

        $filtered = $this->bridge->filterProductDataTabs($tabs);

        assert(isset($filtered['wpe_variants_manager']), 'wpe_variants_manager tab must be added');
        $tab = $filtered['wpe_variants_manager'];
        assert($tab['target'] === 'wpe_variants_manager_data', 'Target must match panel id');
        assert(in_array('show_if_variable', $tab['class']), 'Tab must have show_if_variable class');
        assert(in_array('wpe_variants_tab', $tab['class']), 'Tab must have wpe_variants_tab class');

        echo " OK\n";
    }

    public function testPanelRendering(): void
    {
        echo " - testPanelRendering...";

        $productId = $this->createTestProduct();
        global $post, $thepostid;
        $post = get_post($productId);
        $thepostid = $productId;

        ob_start();
        $this->bridge->renderProductDataPanel();
        $html = ob_get_clean();

        assert(strpos($html, 'id="wpe_variants_manager_data"') !== false, 'Panel container must exist');
        assert(strpos($html, 'name="wpe_variants_payload"') !== false, 'Payload hidden input must exist');
        assert(strpos($html, 'name="wpe_variants_nonce"') !== false, 'Nonce hidden input must exist');
        assert(strpos($html, 'id="wpe-product-editor-inline-app"') !== false, 'Inline editor container must exist');
        assert(strpos($html, 'id="wpe-fullscreen-modal-overlay"') !== false, 'Fullscreen modal container must exist');
        assert(strpos($html, 'id="wpe-variation-drawer-overlay"') !== false, 'Drawer container must exist');

        echo " OK\n";
    }

    public function testProcessProductMetaSave(): void
    {
        echo " - testProcessProductMetaSave...";

        $productId = $this->createTestProduct();
        $service = ProductEditorService::getInstance();
        $initialData = $service->getProductEditorData($productId);
        $version = $initialData['product']['version'];

        $payload = [
            'expected_version'       => $version,
            'descriptive_attributes' => [
                [
                    'name'       => 'Origin',
                    'is_visible' => 1,
                    'position'   => 0,
                    'values'     => ['Vietnam'],
                ]
            ],
            'variation_options'      => [
                [
                    'name'     => 'Size',
                    'position' => 0,
                    'values'   => [['label' => 'XL', 'position' => 0]],
                ]
            ],
            'variations'             => [
                [
                    'combination'   => ['Size' => 'XL'],
                    'sku'           => 'BRIDGE-XL-' . uniqid(),
                    'regular_price' => 50.00,
                    'manage_stock'  => 1,
                    'stock_quantity'=> 12,
                ]
            ]
        ];

        $_POST['wpe_variants_payload'] = wp_slash(json_encode($payload));
        $_POST['wpe_variants_nonce']   = wp_create_nonce('wpe_save_variants');

        $this->bridge->processProductMetaSave($productId);

        unset($_POST['wpe_variants_payload']);
        unset($_POST['wpe_variants_nonce']);

        $updatedData = $service->getProductEditorData($productId);
        assert(count($updatedData['descriptive_attributes']) === 1, 'Descriptive attributes count should be 1');
        assert($updatedData['descriptive_attributes'][0]['name'] === 'Origin', 'Descriptive attribute name must match');
        assert(count($updatedData['variation_options']) === 1, 'Variation options count should be 1');
        assert(count($updatedData['variations']) === 1, 'Variations count should be 1');
        assert($updatedData['variations'][0]['sku'] === $payload['variations'][0]['sku'], 'Variation SKU must match');

        echo " OK\n";
    }
}

$test = new ProductEditorBridgeTest();
$test->run();
