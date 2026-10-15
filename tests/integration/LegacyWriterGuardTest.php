<?php

/**
 * P010.7 — LegacyWriterGuardTest
 *
 * Verifies single-writer enforcement (D09): on supported products (Simple and Variable),
 * owned legacy keys are stripped from $_POST and $_REQUEST at priority 1 of
 * woocommerce_process_product_meta so WooCommerce at priority 10 cannot write them.
 * A test fails if any owned key reaches a data store write from the legacy path.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorBridge;
use WPE\Admin\ProductEditor\ProductEditorService;

class LegacyWriterGuardTest
{
    private wpdb $wpdb;
    private ProductEditorBridge $bridge;
    private ProductEditorService $service;
    private array $cleanProductIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->bridge = ProductEditorBridge::getInstance();
        $this->service = ProductEditorService::getInstance();
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
        echo "Running LegacyWriterGuard tests...\n";
        try {
            $this->testEditorModeSwitch();
            $this->testLegacyKeysStrippedAtPriority1();
            $this->testLegacySaveCannotOverwriteNativeData();
            $this->testLegacyRecoveryModeAllowsLegacySave();
            $this->cleanup();
            echo "All LegacyWriterGuard tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function createProduct(string $type = 'simple', array $initialData = []): int
    {
        $id = wp_insert_post([
            'post_title'  => 'Legacy Guard Test ' . uniqid(),
            'post_status' => 'publish',
            'post_type'   => 'product',
        ]);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create test product');
        }
        wp_set_object_terms($id, $type, 'product_type');
        $this->cleanProductIds[] = (int)$id;

        $saveData = array_merge([
            'product' => [
                'sku'           => 'LWG-' . substr(md5((string)$id), 0, 6),
                'regular_price' => $type === 'simple' ? '50.00' : null,
                'stock'         => ['manage_stock' => false],
            ],
        ], $initialData);

        $this->service->saveProductVariants((int)$id, $saveData);
        return (int)$id;
    }

    private function testEditorModeSwitch(): void
    {
        echo " - editor mode resolution and recovery filter...";
        delete_option('wpe_editor_mode');
        $this->check(ProductEditorBridge::getEditorMode() === ProductEditorBridge::MODE_METABOX, 'default editor mode is metabox');

        update_option('wpe_editor_mode', ProductEditorBridge::MODE_BLOCKS);
        $this->check(ProductEditorBridge::getEditorMode() === ProductEditorBridge::MODE_BLOCKS, 'option sets blocks mode');

        update_option('wpe_editor_mode', ProductEditorBridge::MODE_METABOX);
        $this->check(ProductEditorBridge::getEditorMode() === ProductEditorBridge::MODE_METABOX, 'option sets metabox mode');

        update_option('wpe_editor_mode', ProductEditorBridge::MODE_LEGACY);
        $this->check(ProductEditorBridge::getEditorMode() === ProductEditorBridge::MODE_LEGACY, 'option sets legacy mode');

        add_filter('wpe_editor_mode', fn() => ProductEditorBridge::MODE_BLOCKS);
        $this->check(ProductEditorBridge::getEditorMode() === ProductEditorBridge::MODE_BLOCKS, 'filter overrides option');
        remove_all_filters('wpe_editor_mode');

        delete_option('wpe_editor_mode');
        echo " OK\n";
    }

    private function testLegacyKeysStrippedAtPriority1(): void
    {
        echo " - legacy keys stripped at priority 1 of product meta save...";
        $productId = $this->createProduct('simple');

        update_option('wpe_editor_mode', ProductEditorBridge::MODE_BLOCKS);

        $_POST['product-type'] = 'simple';
        $_POST['_regular_price'] = '999.00';
        $_POST['_sale_price'] = '888.00';
        $_POST['_sku'] = 'ATTEMPTED-OVERWRITE-SKU';
        $_POST['_manage_stock'] = 'yes';
        $_POST['_stock'] = '500';
        $_POST['_weight'] = '99.5';
        $_REQUEST['_regular_price'] = '999.00';
        $_REQUEST['_sku'] = 'ATTEMPTED-OVERWRITE-SKU';

        $guarded = $this->bridge->guardLegacyProductKeys($productId);
        $this->check($guarded === true, 'guardLegacyProductKeys returns true for supported product');

        foreach (ProductEditorBridge::LEGACY_OWNED_POST_KEYS as $key) {
            $this->check(!isset($_POST[$key]), "legacy key {$key} was stripped from \$_POST");
            $this->check(!isset($_REQUEST[$key]), "legacy key {$key} was stripped from \$_REQUEST");
        }

        delete_option('wpe_editor_mode');
        // Clean superglobals
        unset($_POST['product-type'], $_POST['_regular_price'], $_POST['_sku'], $_REQUEST['_regular_price'], $_REQUEST['_sku']);
        echo " OK\n";
    }

    private function testLegacySaveCannotOverwriteNativeData(): void
    {
        echo " - legacy form submission cannot overwrite native data...";
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_BLOCKS);

        $safeSku = 'SAFE-SKU-' . uniqid();
        $productId = $this->createProduct('simple', [
            'product' => [
                'sku'           => $safeSku,
                'regular_price' => '45.00',
                'stock'         => ['manage_stock' => false],
            ],
        ]);

        $post = get_post($productId);

        // Simulate WordPress admin product update form POST with legacy inputs
        $_POST['post_ID'] = (string)$productId;
        $_POST['product-type'] = 'simple';
        $_POST['_regular_price'] = '9999.00';
        $_POST['_sale_price'] = '8888.00';
        $_POST['_sku'] = 'PIRATE-SKU';
        $_POST['_manage_stock'] = 'yes';
        $_POST['_stock'] = '1000';
        $_POST['wpe_variants_payload'] = ''; // No block changes

        // Run full woocommerce_process_product_meta hook
        do_action('woocommerce_process_product_meta', $productId, $post);

        // Verify native wpe_products data was NOT altered by legacy post data
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT sku, regular_price FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $productId
        ), ARRAY_A);

        $this->check($row['sku'] === $safeSku, 'product SKU was preserved against legacy write');
        $this->check((float)$row['regular_price'] === 45.00, 'product regular_price was preserved against legacy write');

        // Check wp_postmeta: _regular_price in postmeta should not be 9999.00
        $metaPrice = get_post_meta($productId, '_regular_price', true);
        $this->check((string)$metaPrice !== '9999.00', 'legacy postmeta _regular_price did not get 9999.00');

        delete_option('wpe_editor_mode');
        unset($_POST['post_ID'], $_POST['product-type'], $_POST['_regular_price'], $_POST['_sale_price'], $_POST['_sku'], $_POST['_manage_stock'], $_POST['_stock'], $_POST['wpe_variants_payload']);
        echo " OK\n";
    }

    private function testLegacyRecoveryModeAllowsLegacySave(): void
    {
        echo " - recovery mode allows legacy save when enabled...";
        $productId = $this->createProduct('simple');

        update_option('wpe_editor_mode', ProductEditorBridge::MODE_LEGACY);

        $_POST['product-type'] = 'simple';
        $_POST['_regular_price'] = '77.00';
        $_REQUEST['_regular_price'] = '77.00';

        $guarded = $this->bridge->guardLegacyProductKeys($productId);
        $this->check($guarded === false, 'guard returns false in legacy mode without blocks payload');
        $this->check(isset($_POST['_regular_price']) && $_POST['_regular_price'] === '77.00', 'legacy key is preserved in recovery mode');

        delete_option('wpe_editor_mode');
        unset($_POST['product-type'], $_POST['_regular_price'], $_REQUEST['_regular_price']);
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
        $this->cleanProductIds = [];
        delete_option('wpe_editor_mode');
    }
}

(new LegacyWriterGuardTest())->run();
