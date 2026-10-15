<?php

/**
 * P011.8 — Single writer guard for the featured image and the gallery.
 *
 * On Simple and Variable products the native image boxes are hidden and the posted `_thumbnail_id` and
 * `product_image_gallery` keys never reach WordPress or WooCommerce; other product types keep the native
 * path. A control run without the guard proves the legacy path really is a writer.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use WPE\Admin\ProductEditor\ProductEditorBridge;

class MediaLegacyWriterGuardTest
{
    private ProductEditorBridge $bridge;
    private array $posts = [];
    private array $attachments = [];
    private array $post = [];
    private array $request = [];
    private int $checks = 0;
    private $stubCallback = null;

    public function __construct()
    {
        $this->bridge = ProductEditorBridge::getInstance();
    }

    public function run(): void
    {
        echo "Running media legacy writer guard tests...\n";
        wp_set_current_user(1);
        $priorMode = get_option('wpe_editor_mode', '__unset__');
        update_option('wpe_editor_mode', ProductEditorBridge::MODE_BLOCKS);
        $this->post    = $_POST;
        $this->request = $_REQUEST;
        try {
            $this->testSupportedTypes();
            $this->testHooksAndBoxClasses();
            $this->testGuardByType();
            $this->testGuardIgnoresOtherRequests();
            $this->testGuardSkipsWooCommerceGallerySave();
            $this->testLegacyPathIsStoppedEndToEnd();
            echo "All media legacy writer guard tests passed ({$this->checks} checks).\n";
        } finally {
            $_POST    = $this->post;
            $_REQUEST = $this->request;
            if ($priorMode === '__unset__' || $priorMode === false) {
                delete_option('wpe_editor_mode');
            } else {
                update_option('wpe_editor_mode', $priorMode);
            }
            $this->cleanup();
        }
    }

    private function ok(bool $cond, string $label): void
    {
        $this->checks++;
        if (!$cond) {
            throw new RuntimeException("FAILED: {$label}");
        }
        echo "  [OK] {$label}\n";
    }

    private function product(string $type): int
    {
        $id = wp_insert_post(['post_title' => 'P011 guard ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product', 'post_author' => 1]);
        wp_set_object_terms($id, $type, 'product_type');
        wp_cache_flush();
        $this->posts[] = $id;
        return (int) $id;
    }

    private function attachment(): int
    {
        $dir  = wp_upload_dir();
        $path = $dir['path'] . '/p011-guard-' . uniqid() . '.png';
        $im   = imagecreatetruecolor(8, 8);
        imagepng($im, $path);
        imagedestroy($im);
        $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'P011 guard', 'post_status' => 'inherit'], $path);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $path));
        $this->attachments[] = $id;
        return (int) $id;
    }

    private function submit(int $productId, string $type, array $extra = []): void
    {
        $_POST = $_REQUEST = array_merge([
            'action'               => 'editpost',
            'post_type'            => 'product',
            'post_ID'              => (string) $productId,
            'product-type'         => $type,
            '_thumbnail_id'        => '777777',
            'product_image_gallery' => '777778,777779',
            'post_title'           => 'kept',
        ], $extra);
    }

    /* ------------------------------------------------------------------ */

    private function testSupportedTypes(): void
    {
        echo "[guard] supported types\n";
        $this->ok(ProductEditorBridge::supportsMediaLibrary('simple') && ProductEditorBridge::supportsMediaLibrary('variable'), 'simple and variable products use the library');
        $this->ok(!ProductEditorBridge::supportsMediaLibrary('grouped') && !ProductEditorBridge::supportsMediaLibrary('external') && !ProductEditorBridge::supportsMediaLibrary(''), 'other types keep the native boxes');
        add_filter('wpe_media_library_replaces_native_boxes', '__return_false');
        $this->ok(!ProductEditorBridge::supportsMediaLibrary('simple'), 'an operator can bring the native boxes back');
        remove_filter('wpe_media_library_replaces_native_boxes', '__return_false');
        $this->ok(ProductEditorBridge::supportsMediaLibrary('simple'), 'the default is the library');
    }

    private function testHooksAndBoxClasses(): void
    {
        echo "[guard] boxes\n";
        foreach (ProductEditorBridge::NATIVE_IMAGE_BOXES as $box) {
            $this->ok(has_filter('postbox_classes_product_' . $box, [$this->bridge, 'filterNativeImageBoxClasses']) !== false, "class filter hooked for {$box}");
        }
        $this->ok(has_action('admin_init', [$this->bridge, 'guardNativeImageKeys']) !== false, 'the guard runs on admin_init');

        global $post, $thepostid;
        foreach (['simple' => true, 'variable' => true, 'grouped' => false, 'external' => false] as $type => $hidden) {
            $id = $this->product($type);
            $post = get_post($id);
            $thepostid = $id;
            $classes = $this->bridge->filterNativeImageBoxClasses(['postbox']);
            $this->ok(in_array('hidden', $classes, true) === $hidden && in_array('wpe-box-hidden', $classes, true) === $hidden && in_array('postbox', $classes, true), "{$type}: native image boxes " . ($hidden ? 'hidden' : 'shown'));
        }
    }

    private function testGuardByType(): void
    {
        echo "[guard] by type\n";
        foreach (['simple', 'variable'] as $type) {
            $id = $this->product($type);
            $this->submit($id, $type);
            $this->ok($this->bridge->guardNativeImageKeys() === true, "{$type}: the guard is active");
            $this->ok(!isset($_POST['_thumbnail_id'], $_POST['product_image_gallery']) && !isset($_REQUEST['_thumbnail_id']) && !isset($_REQUEST['product_image_gallery']), "{$type}: the posted image keys are removed");
            $this->ok(($_POST['post_title'] ?? '') === 'kept', "{$type}: other posted fields are kept");
        }
        foreach (['grouped', 'external'] as $type) {
            $id = $this->product($type);
            $this->submit($id, $type);
            $this->ok($this->bridge->guardNativeImageKeys() === false && ($_POST['_thumbnail_id'] ?? '') === '777777' && ($_POST['product_image_gallery'] ?? '') === '777778,777779', "{$type}: the native path is left alone");
        }

        // No posted type: the saved type decides.
        $id = $this->product('variable');
        $this->submit($id, 'x');
        unset($_POST['product-type'], $_REQUEST['product-type']);
        $this->ok($this->bridge->guardNativeImageKeys() === true && !isset($_POST['_thumbnail_id']), 'without a posted type the saved type decides');
        $id = $this->product('grouped');
        $this->submit($id, 'x');
        unset($_POST['product-type'], $_REQUEST['product-type']);
        $this->ok($this->bridge->guardNativeImageKeys() === false && isset($_POST['_thumbnail_id']), 'a saved grouped product keeps the native keys');

        // A type forged in the request to dodge the guard does not help: the posted type is what decides, and
        // a supported posted type guards the request.
        $id = $this->product('grouped');
        $this->submit($id, 'simple');
        $this->ok($this->bridge->guardNativeImageKeys() === true && !isset($_POST['_thumbnail_id']), 'a product switched to a supported type in the form is guarded');
    }

    private function testGuardIgnoresOtherRequests(): void
    {
        echo "[guard] other requests\n";
        $id = $this->product('simple');
        $this->submit($id, 'simple', ['action' => 'inline-save']);
        $this->ok($this->bridge->guardNativeImageKeys() === false && isset($_POST['_thumbnail_id']), 'only the product edit form is guarded');
        $this->submit($id, 'simple', ['post_type' => 'page']);
        $this->ok($this->bridge->guardNativeImageKeys() === false && isset($_POST['_thumbnail_id']), 'other post types are not touched');
        $this->submit(987654321, 'simple');
        $this->ok($this->bridge->guardNativeImageKeys() === false && isset($_POST['_thumbnail_id']), 'an unknown product is not guarded');
        $page = wp_insert_post(['post_title' => 'P011 guard page', 'post_status' => 'draft', 'post_type' => 'page']);
        $this->posts[] = $page;
        $this->submit((int) $page, 'simple');
        $this->ok($this->bridge->guardNativeImageKeys() === false && isset($_POST['_thumbnail_id']), 'a post that is not a product is not guarded');
        $_POST = $_REQUEST = [];
        $this->ok($this->bridge->guardNativeImageKeys() === false, 'a request without a form is ignored');
    }

    private function testGuardSkipsWooCommerceGallerySave(): void
    {
        echo "[guard] WooCommerce gallery save\n";
        // The admin classes are not loaded in the CLI; register a stand-in under the same callable.
        add_action('woocommerce_process_product_meta', ['WC_Meta_Box_Product_Images', 'save'], 20, 2);
        $this->ok(has_action('woocommerce_process_product_meta', ['WC_Meta_Box_Product_Images', 'save']) === 20, 'the stand-in is registered');
        $id = $this->product('grouped');
        $this->submit($id, 'grouped');
        $this->bridge->guardNativeImageKeys();
        $this->ok(has_action('woocommerce_process_product_meta', ['WC_Meta_Box_Product_Images', 'save']) === 20, 'a grouped product keeps the WooCommerce callback');
        $id = $this->product('simple');
        $this->submit($id, 'simple');
        $this->bridge->guardNativeImageKeys();
        $this->ok(has_action('woocommerce_process_product_meta', ['WC_Meta_Box_Product_Images', 'save']) === false, 'a supported product does not run the WooCommerce gallery save (a missing key would read as an empty gallery)');
    }

    private function testLegacyPathIsStoppedEndToEnd(): void
    {
        echo "[guard] end to end\n";
        $att = $this->attachment();
        $id  = $this->product('simple');
        update_post_meta($id, '_thumbnail_id', $att);
        $original = (string) get_post_meta($id, '_thumbnail_id', true);

        $other = $this->attachment();
        $form  = fn (array $over = []) => array_merge([
            'action' => 'editpost', 'post_type' => 'product', 'post_ID' => (string) $id, 'post_title' => 'Guard test',
            'post_status' => 'publish', 'product-type' => 'simple', 'post_author' => '1', 'content' => '',
            '_thumbnail_id' => (string) $other,
        ], $over);

        // Control: without the guard the legacy path writes the featured image.
        $_POST = $_REQUEST = $form();
        edit_post($_POST);
        $control = (string) get_post_meta($id, '_thumbnail_id', true);
        $this->ok($control === (string) $other, 'control: the native form is a writer of _thumbnail_id when unguarded');

        // Guarded: the posted value is dropped before edit_post, the saved value stays.
        update_post_meta($id, '_thumbnail_id', $att);
        $_POST = $_REQUEST = $form();
        $this->bridge->guardNativeImageKeys();
        edit_post($_POST);
        $this->ok((string) get_post_meta($id, '_thumbnail_id', true) === (string) $att, 'guarded: a tampered thumbnail id does not change the featured image');

        // A "remove image" post (-1) is dropped too.
        $_POST = $_REQUEST = $form(['_thumbnail_id' => '-1']);
        $this->bridge->guardNativeImageKeys();
        edit_post($_POST);
        $this->ok((string) get_post_meta($id, '_thumbnail_id', true) === (string) $att, 'guarded: the native remove-image post does not clear it');
        $this->ok($original === (string) $att, 'the starting value was the one the library had projected');
    }

    /* ------------------------------------------------------------------ */

    private function cleanup(): void
    {
        wp_set_current_user(1);
        remove_action('woocommerce_process_product_meta', ['WC_Meta_Box_Product_Images', 'save'], 20);
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
        foreach ($this->attachments as $id) {
            wp_delete_attachment($id, true);
        }
    }
}

try {
    (new MediaLegacyWriterGuardTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
