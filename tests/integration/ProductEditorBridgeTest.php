<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

// These tests rely on assert(). WP-CLI on some hosts runs with zend.assertions=-1, which compiles every
// assert() out and makes the file pass without checking anything. Fail fast instead of passing vacuously.
if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

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
            $this->testNoProductDataTab();
            $this->testMetaBoxRegistration();
            $this->testBoxVisibilityClass();
            $this->testAdminBodyClass();
            $this->testNativeImageBoxesFollowProductType();
            $this->testMetaBoxAndOverlayRendering();
            $this->testProcessProductMetaSave();
            $this->testEmptyPayloadLeavesVariantsUntouched();
            $this->testFailedSaveKeepsErrorAndDraft();
            $this->testRejectedRequestsAreReported();
            $this->testDraftIsHandedToEditorOnce();
            $this->testAssetsLocalization();
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
            foreach ($variantIds as $variantPostId) {
                wp_delete_post((int)$variantPostId, true);
            }
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
    }

    private function createTestProduct(string $type = 'variable'): int
    {
        $postId = wp_insert_post([
            'post_title'   => 'Bridge Test Variable Product ' . uniqid(),
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create test product post");
        }

        wp_set_object_terms($postId, $type, 'product_type');
        wp_cache_flush(); // WooCommerce caches the product type on first lookup, which wp_insert_post triggers.
        $this->cleanProductIds[] = $postId;
        return $postId;
    }

    private function setCurrentPost(int $productId): void
    {
        global $post, $thepostid;
        $post = get_post($productId);
        $thepostid = $productId;
    }

    private function key(string $kind, int $productId): string
    {
        return 'wpe_vm_' . $kind . '_' . get_current_user_id() . '_' . $productId;
    }

    public function testNoProductDataTab(): void
    {
        echo " - testNoProductDataTab...";

        $tabs = [
            'general'   => ['label' => 'General', 'target' => 'general_product_data', 'class' => ['hide_if_grouped']],
            'attribute' => ['label' => 'Attributes', 'target' => 'product_attributes', 'class' => ['attribute_options']],
        ];
        $filtered = apply_filters('woocommerce_product_data_tabs', $tabs);

        assert(!isset($filtered['wpe_variants_manager']), 'The Variants Manager must no longer be a Product data tab');
        assert(!method_exists($this->bridge, 'filterProductDataTabs'), 'The tab filter must be gone');
        assert(!method_exists($this->bridge, 'renderProductDataPanel'), 'The Product data panel renderer must be gone');
        assert(isset($filtered['attribute']) && isset($filtered['general']), 'Other tabs must be left as they are');

        echo " OK\n";
    }

    public function testMetaBoxRegistration(): void
    {
        echo " - testMetaBoxRegistration...";
        global $wp_meta_boxes;

        $backup = $wp_meta_boxes;
        $wp_meta_boxes = [];
        $this->bridge->registerMetaBox('post');
        assert(empty($wp_meta_boxes['post']), 'Nothing may be registered on other post types');

        // 1. In MODE_BLOCKS (default)
        delete_option('wpe_editor_mode');
        $this->bridge->registerMetaBox('product');

        $infoBox = $wp_meta_boxes['product']['normal']['default'][ProductEditorBridge::PRODUCT_INFO_BOX_ID] ?? null;
        assert(is_array($infoBox), 'Product Info meta box must be registered on product, normal context, default priority');
        assert($infoBox['title'] === 'Product Information', 'Product Info meta box title must be "Product Information"');
        assert(is_callable($infoBox['callback']), 'Product Info meta box callback must be callable');

        $variantsBox = $wp_meta_boxes['product']['normal']['default'][ProductEditorBridge::BOX_ID] ?? null;
        assert(is_array($variantsBox), 'Variants meta box must be registered on product, normal context, default priority');
        assert(!isset($wp_meta_boxes['product']['normal']['high'][ProductEditorBridge::BOX_ID]), "Must not use 'high' priority: it renders above user-sorted boxes such as Product data");
        assert($variantsBox['title'] === 'Variants', 'Variants meta box title in MODE_BLOCKS must be "Variants"');
        assert(is_callable($variantsBox['callback']), 'Variants meta box callback must be callable');

        // Registered after WooCommerce (priority 30) so that it lands below Product data.
        $priority = has_action('add_meta_boxes', [$this->bridge, 'registerMetaBox']);
        assert($priority !== false && $priority > 30, 'Must be registered after WooCommerce Product data (priority 30), got ' . var_export($priority, true));
        assert(has_filter('postbox_classes_product_' . ProductEditorBridge::BOX_ID, [$this->bridge, 'filterBoxClasses']) !== false, 'Variants class filter must be hooked');
        assert(has_filter('postbox_classes_product_' . ProductEditorBridge::PRODUCT_INFO_BOX_ID, [$this->bridge, 'filterProductInfoBoxClasses']) !== false, 'Product info class filter must be hooked');
        assert(has_filter('admin_body_class', [$this->bridge, 'filterAdminBodyClass']) !== false, 'Admin body class filter must be hooked');

        // 2. In MODE_METABOX (P005 recovery)
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_METABOX);
        $wp_meta_boxes = [];
        $this->bridge->registerMetaBox('product');
        assert(!isset($wp_meta_boxes['product']['normal']['default'][ProductEditorBridge::PRODUCT_INFO_BOX_ID]), 'Product Info box must NOT be registered in MODE_METABOX');
        $boxMetabox = $wp_meta_boxes['product']['normal']['default'][ProductEditorBridge::BOX_ID] ?? null;
        assert(is_array($boxMetabox), 'Variants Manager meta box must be registered in MODE_METABOX');
        assert($boxMetabox['title'] === 'Variants Manager', 'Meta box title in MODE_METABOX must be "Variants Manager"');

        // 3. In MODE_LEGACY
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_LEGACY);
        $wp_meta_boxes = [];
        $this->bridge->registerMetaBox('product');
        assert(empty($wp_meta_boxes['product']['normal']['default']), 'No meta boxes must be registered in MODE_LEGACY');

        delete_option('wpe_editor_mode');
        $wp_meta_boxes = $backup;
        echo " OK\n";
    }

    public function testBoxVisibilityClass(): void
    {
        echo " - testBoxVisibilityClass...";

        $variableId = $this->createTestProduct('variable');
        $simpleId   = $this->createTestProduct('simple');
        $groupedId  = $this->createTestProduct('grouped');

        // 1. In MODE_BLOCKS (default)
        delete_option('wpe_editor_mode');

        // Variable product: both boxes visible
        $this->setCurrentPost($variableId);
        $vClasses = $this->bridge->filterBoxClasses(['postbox']);
        assert(!in_array('wpe-box-hidden', $vClasses, true), 'MODE_BLOCKS: variable product must show variants box');
        $infoClasses = $this->bridge->filterProductInfoBoxClasses(['postbox']);
        assert(!in_array('wpe-box-hidden', $infoClasses, true), 'MODE_BLOCKS: variable product must show product info box');

        // Simple product: both boxes visible
        $this->setCurrentPost($simpleId);
        $sClasses = $this->bridge->filterBoxClasses(['postbox']);
        assert(!in_array('wpe-box-hidden', $sClasses, true), 'MODE_BLOCKS: simple product must show variants box');
        $infoClasses = $this->bridge->filterProductInfoBoxClasses(['postbox']);
        assert(!in_array('wpe-box-hidden', $infoClasses, true), 'MODE_BLOCKS: simple product must show product info box');

        // Grouped product (unsupported): both boxes hidden
        $this->setCurrentPost($groupedId);
        $gClasses = $this->bridge->filterBoxClasses(['postbox']);
        assert(in_array('wpe-box-hidden', $gClasses, true), 'MODE_BLOCKS: grouped product must hide variants box');
        $infoClasses = $this->bridge->filterProductInfoBoxClasses(['postbox']);
        assert(in_array('wpe-box-hidden', $infoClasses, true), 'MODE_BLOCKS: grouped product must hide product info box');

        // 2. In MODE_METABOX (P005 recovery)
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_METABOX);

        // Variable product: variants visible, info hidden
        $this->setCurrentPost($variableId);
        $vClasses = $this->bridge->filterBoxClasses(['postbox']);
        assert(!in_array('wpe-box-hidden', $vClasses, true), 'MODE_METABOX: variable product must show variants box');
        $infoClasses = $this->bridge->filterProductInfoBoxClasses(['postbox']);
        assert(in_array('wpe-box-hidden', $infoClasses, true), 'MODE_METABOX: product info box must be hidden');

        // Simple product: variants hidden, info hidden
        $this->setCurrentPost($simpleId);
        $sClasses = $this->bridge->filterBoxClasses(['postbox']);
        assert(in_array('wpe-box-hidden', $sClasses, true), 'MODE_METABOX: simple product must hide variants box');

        // 3. In MODE_LEGACY
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_LEGACY);
        $infoClasses = $this->bridge->filterProductInfoBoxClasses(['postbox']);
        assert(in_array('wpe-box-hidden', $infoClasses, true), 'MODE_LEGACY: product info box must be hidden');

        delete_option('wpe_editor_mode');
        echo " OK\n";
    }

    public function testAdminBodyClass(): void
    {
        echo " - testAdminBodyClass...";

        $variableId = $this->createTestProduct('variable');
        $simpleId   = $this->createTestProduct('simple');
        $groupedId  = $this->createTestProduct('grouped');

        // 1. In MODE_BLOCKS (default)
        delete_option('wpe_editor_mode');

        // Simple product
        $this->setCurrentPost($simpleId);
        $classes = $this->bridge->filterAdminBodyClass('post-php');
        assert(strpos($classes, 'wpe-mode-blocks') !== false, 'MODE_BLOCKS: simple product must add wpe-mode-blocks');
        assert(strpos($classes, 'wpe-supported-product') !== false, 'MODE_BLOCKS: simple product must add wpe-supported-product');

        // Variable product
        $this->setCurrentPost($variableId);
        $classes = $this->bridge->filterAdminBodyClass('post-php');
        assert(strpos($classes, 'wpe-mode-blocks') !== false, 'MODE_BLOCKS: variable product must add wpe-mode-blocks');
        assert(strpos($classes, 'wpe-supported-product') !== false, 'MODE_BLOCKS: variable product must add wpe-supported-product');

        // Grouped product (unsupported)
        $this->setCurrentPost($groupedId);
        $classes = $this->bridge->filterAdminBodyClass('post-php');
        assert(strpos($classes, 'wpe-mode-blocks') === false, 'MODE_BLOCKS: grouped product must NOT add wpe-mode-blocks');
        assert(strpos($classes, 'wpe-supported-product') === false, 'MODE_BLOCKS: grouped product must NOT add wpe-supported-product');

        // Non-product post
        $blogPostId = wp_insert_post(['post_title' => 'Blog Post ' . uniqid(), 'post_type' => 'post', 'post_status' => 'publish']);
        $this->setCurrentPost((int)$blogPostId);
        $classes = $this->bridge->filterAdminBodyClass('post-php');
        assert(strpos($classes, 'wpe-mode-blocks') === false, 'MODE_BLOCKS: non-product screen must NOT add wpe-mode-blocks');
        wp_delete_post((int)$blogPostId, true);

        // 2. In MODE_METABOX
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_METABOX);
        $this->setCurrentPost($variableId);
        $classes = $this->bridge->filterAdminBodyClass('post-php');
        assert(strpos($classes, 'wpe-mode-blocks') === false, 'MODE_METABOX: must NOT add wpe-mode-blocks');

        // 3. In MODE_LEGACY
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_LEGACY);
        $this->setCurrentPost($variableId);
        $classes = $this->bridge->filterAdminBodyClass('post-php');
        assert(strpos($classes, 'wpe-mode-blocks') === false, 'MODE_LEGACY: must NOT add wpe-mode-blocks');

        delete_option('wpe_editor_mode');
        echo " OK\n";
    }

    public function testNativeImageBoxesFollowProductType(): void
    {
        echo " - testNativeImageBoxesFollowProductType...";

        foreach (ProductEditorBridge::NATIVE_IMAGE_BOXES as $boxId) {
            assert(has_filter('postbox_classes_product_' . $boxId, [$this->bridge, 'filterNativeImageBoxClasses']) !== false, "{$boxId} class filter must be hooked");
        }
        assert(has_action('admin_init', [$this->bridge, 'guardNativeImageKeys']) !== false, 'The image key guard must be hooked');

        foreach (['variable' => true, 'simple' => true, 'grouped' => false] as $type => $hidden) {
            $id = $this->createTestProduct($type);
            $this->setCurrentPost($id);
            $classes = $this->bridge->filterNativeImageBoxClasses(['postbox']);
            assert(in_array('wpe-box-hidden', $classes, true) === $hidden, "{$type}: native image boxes hidden state must be " . var_export($hidden, true));
        }

        echo " OK\n";
    }

    public function testMetaBoxAndOverlayRendering(): void
    {
        echo " - testMetaBoxAndOverlayRendering...";

        $productId = $this->createTestProduct();
        $this->setCurrentPost($productId);

        // Overlays print nothing until the box has been rendered (no output on other screens).
        $fresh = new ProductEditorBridge();
        ob_start();
        $fresh->renderOverlays();
        assert(ob_get_clean() === '', 'Overlays must not print before the box is rendered');

        ob_start();
        $fresh->renderMetaBox(get_post($productId));
        $html = ob_get_clean();

        assert(strpos($html, 'name="wpe_variants_payload"') !== false, 'Payload hidden input must exist');
        assert(strpos($html, 'name="wpe_variants_nonce"') !== false, 'Nonce hidden input must exist');
        assert(strpos($html, 'id="wpe-product-editor-inline-app"') !== false, 'Inline editor container must exist');
        assert(strpos($html, 'data-product-id="' . $productId . '"') !== false, 'Product id must be exposed to the editor');
        assert(preg_match('/name="wpe_variants_payload"[^>]*value=""/', $html) === 1, 'Payload must start empty');
        assert(strpos($html, 'wpe-fullscreen-modal-overlay') === false, 'Overlays must not be inside the postbox');
        assert(strpos($html, 'woocommerce_options_panel') === false, 'WooCommerce panel classes must not be used');

        ob_start();
        $fresh->renderProductInfoBox(get_post($productId));
        $infoHtml = ob_get_clean();
        assert(strpos($infoHtml, 'id="wpe-product-info-box-body"') !== false, 'Product info box body container must exist');
        assert(strpos($infoHtml, 'id="wpe-product-info-app"') !== false, 'Product info app container must exist');
        assert(strpos($infoHtml, 'data-product-id="' . $productId . '"') !== false, 'Product id must be on product info app');

        ob_start();
        $fresh->renderOverlays();
        $footer = ob_get_clean();
        assert(strpos($footer, 'id="wpe-fullscreen-modal-overlay"') !== false, 'Fullscreen modal container must exist');
        assert(strpos($footer, 'id="wpe-variation-drawer-overlay"') !== false, 'Drawer container must exist');
        assert(substr_count($footer, 'role="dialog"') === 2 && substr_count($footer, 'aria-modal="true"') === 2, 'Both overlays must be modal dialogs');
        assert(substr_count($footer, 'aria-label="') === 2, 'Both overlays must have an accessible name');

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

    private function conflictingPayload(int $productId): array
    {
        $version = ProductEditorService::getInstance()->getProductEditorData($productId)['product']['version'];
        return [
            'expected_version' => $version + 50, // stale/foreign version => concurrency conflict
            'variation_options' => [['name' => 'Size', 'position' => 0, 'values' => [['label' => 'XS', 'position' => 0]]]],
            'variations' => [['combination' => ['Size' => 'XS'], 'sku' => 'BRIDGE-XS-' . uniqid(), 'regular_price' => 5]],
        ];
    }

    public function testEmptyPayloadLeavesVariantsUntouched(): void
    {
        echo " - testEmptyPayloadLeavesVariantsUntouched...";

        $productId = $this->createTestProduct();
        $service = ProductEditorService::getInstance();
        $before = $service->getProductEditorData($productId)['product']['version'];

        $_POST['wpe_variants_payload'] = '';
        $_POST['wpe_variants_nonce'] = wp_create_nonce('wpe_save_variants');
        $this->bridge->processProductMetaSave($productId);
        unset($_POST['wpe_variants_payload'], $_POST['wpe_variants_nonce']);

        assert($service->getProductEditorData($productId)['product']['version'] === $before, 'An empty payload must not write anything');
        assert(get_transient($this->key('err', $productId)) === false, 'An empty payload is not a failure');

        echo " OK\n";
    }

    public function testFailedSaveKeepsErrorAndDraft(): void
    {
        echo " - testFailedSaveKeepsErrorAndDraft...";

        $productId = $this->createTestProduct();
        delete_transient($this->key('err', $productId));
        delete_transient($this->key('draft', $productId));

        $payload = $this->conflictingPayload($productId);
        $raw = json_encode($payload);
        $_POST['wpe_variants_payload'] = wp_slash($raw);
        $_POST['wpe_variants_nonce'] = wp_create_nonce('wpe_save_variants');
        $this->bridge->processProductMetaSave($productId);
        unset($_POST['wpe_variants_payload'], $_POST['wpe_variants_nonce']);

        $error = get_transient($this->key('err', $productId));
        assert(is_string($error) && $error !== '', 'A failed save must leave an error for the next load');
        assert(strpos($error, 'kept as an unsaved draft') !== false, 'The error must tell the user the draft was kept');
        assert(get_transient($this->key('draft', $productId)) === $raw, 'The submitted draft must be kept verbatim');
        assert(count(ProductEditorService::getInstance()->getProductEditorData($productId)['variations']) === 0, 'A failed save must not write variants');

        // An oversized draft is not kept, and the user is told so.
        delete_transient($this->key('draft', $productId));
        $big = $payload;
        $big['padding'] = str_repeat('x', 2200000);
        $_POST['wpe_variants_payload'] = wp_slash(json_encode($big));
        $_POST['wpe_variants_nonce'] = wp_create_nonce('wpe_save_variants');
        $this->bridge->processProductMetaSave($productId);
        unset($_POST['wpe_variants_payload'], $_POST['wpe_variants_nonce']);
        assert(get_transient($this->key('draft', $productId)) === false, 'An oversized draft must not be stored');
        assert(strpos((string)get_transient($this->key('err', $productId)), 'could not be kept') !== false, 'The user must be told the draft was not kept');

        delete_transient($this->key('err', $productId));
        echo " OK\n";
    }

    public function testRejectedRequestsAreReported(): void
    {
        echo " - testRejectedRequestsAreReported...";

        $productId = $this->createTestProduct();
        $service = ProductEditorService::getInstance();
        $before = $service->getProductEditorData($productId)['product']['version'];
        $good = json_encode(['expected_version' => $before, 'variation_options' => [], 'variations' => []]);

        // Bad nonce
        delete_transient($this->key('err', $productId));
        $_POST['wpe_variants_payload'] = wp_slash($good);
        $_POST['wpe_variants_nonce'] = 'not-a-nonce';
        $this->bridge->processProductMetaSave($productId);
        assert(strpos((string)get_transient($this->key('err', $productId)), 'security check') !== false, 'A bad nonce must be reported, not ignored');

        // Unreadable payload
        delete_transient($this->key('err', $productId));
        $_POST['wpe_variants_payload'] = wp_slash('{not json');
        $_POST['wpe_variants_nonce'] = wp_create_nonce('wpe_save_variants');
        $this->bridge->processProductMetaSave($productId);
        assert(strpos((string)get_transient($this->key('err', $productId)), 'could not be read') !== false, 'Invalid JSON must be reported, not ignored');

        // Missing capability
        delete_transient($this->key('err', $productId));
        $adminId = get_current_user_id();
        $subscriber = wp_insert_user(['user_login' => 'wpe_sub_' . uniqid(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
        wp_set_current_user((int)$subscriber);
        $_POST['wpe_variants_payload'] = wp_slash($good);
        $_POST['wpe_variants_nonce'] = wp_create_nonce('wpe_save_variants');
        $this->bridge->processProductMetaSave($productId);
        $subKey = $this->key('err', $productId);
        $subError = get_transient($subKey);
        delete_transient($subKey);
        delete_transient($this->key('draft', $productId));
        wp_set_current_user($adminId);
        wp_delete_user((int)$subscriber);
        unset($_POST['wpe_variants_payload'], $_POST['wpe_variants_nonce']);
        assert(strpos((string)$subError, 'not allowed') !== false, 'A user without permission must get an error, not silence');

        assert($service->getProductEditorData($productId)['product']['version'] === $before, 'Rejected requests must not write anything');
        delete_transient($this->key('err', $productId));
        delete_transient($this->key('draft', $productId));

        echo " OK\n";
    }

    public function testDraftIsHandedToEditorOnce(): void
    {
        echo " - testDraftIsHandedToEditorOnce...";
        global $post;

        $productId = $this->createTestProduct();
        $this->setCurrentPost($productId);
        $raw = json_encode($this->conflictingPayload($productId));
        set_transient($this->key('draft', $productId), $raw, 600);

        wp_scripts()->remove('wpe-product-editor-js');
        $this->bridge->enqueueProductEditorAssets('post.php');
        $data = (string)wp_scripts()->get_data('wpe-product-editor-js', 'data');
        assert(strpos($data, '"restoredDraft":{') !== false, 'The kept draft must be given to the editor');
        assert(strpos($data, 'BRIDGE-XS-') !== false, 'The draft content must be the submitted one');
        assert(get_transient($this->key('draft', $productId)) === false, 'The draft must be handed over once');

        wp_scripts()->remove('wpe-product-editor-js');
        $this->bridge->enqueueProductEditorAssets('post.php');
        $data = (string)wp_scripts()->get_data('wpe-product-editor-js', 'data');
        assert(strpos($data, '"restoredDraft":null') !== false, 'A second load must not restore the draft again');

        echo " OK\n";
    }

    public function testAssetsLocalization(): void
    {
        echo " - testAssetsLocalization...";

        $variableId = $this->createTestProduct('variable');
        $this->setCurrentPost($variableId);

        // 1. In MODE_BLOCKS (default)
        delete_option('wpe_editor_mode');
        wp_dequeue_script('wpe-product-editor-js');
        wp_deregister_script('wpe-product-editor-js');
        $this->bridge->enqueueProductEditorAssets('post.php');
        $data = (string)wp_scripts()->get_data('wpe-product-editor-js', 'data');
        assert(strpos($data, '"editorMode":"blocks"') !== false, 'Localized config must contain editorMode=blocks');
        assert(strpos($data, '"infoBoxId":"wpe_product_info"') !== false, 'Localized config must contain infoBoxId=wpe_product_info');
        assert(strpos($data, '"variantsBoxId":"wpe_variants_manager"') !== false, 'Localized config must contain variantsBoxId=wpe_variants_manager');
        assert(strpos($data, '"boxId":"wpe_variants_manager"') !== false, 'Localized config must contain backward-compat boxId');
        assert(strpos($data, '"supportedTypes":["simple","variable"]') !== false, 'Localized config must contain supportedTypes');

        // 2. In MODE_METABOX
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_METABOX);
        wp_dequeue_script('wpe-product-editor-js');
        wp_deregister_script('wpe-product-editor-js');
        $this->bridge->enqueueProductEditorAssets('post.php');
        $data = (string)wp_scripts()->get_data('wpe-product-editor-js', 'data');
        assert(strpos($data, '"editorMode":"metabox"') !== false, 'Localized config must contain editorMode=metabox');

        // 3. In MODE_LEGACY
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_LEGACY);
        wp_dequeue_script('wpe-product-editor-js');
        wp_deregister_script('wpe-product-editor-js');
        $this->bridge->enqueueProductEditorAssets('post.php');
        assert(!wp_script_is('wpe-product-editor-js', 'enqueued'), 'Scripts must NOT be enqueued in MODE_LEGACY');

        delete_option('wpe_editor_mode');
        echo " OK\n";
    }
}

$test = new ProductEditorBridgeTest();
$test->run();
