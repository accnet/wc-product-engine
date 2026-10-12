<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use WP_Post;
use Throwable;

/**
 * ProductEditorBridge
 *
 * Bridges the WC Product Engine custom variant management experience
 * into the standard WooCommerce Product Data metabox (#woocommerce-product-data).
 *
 * Requirements satisfied:
 * - Tab 'wpe_variants_manager' registered with 'show_if_variable'.
 * - On variable products, default tabs (attribute_options, variations_options) are hidden.
 * - Non-variable products (simple, grouped, external) remain 100% unaffected.
 * - Persistence via standard WordPress Update/Publish form submit (woocommerce_process_product_meta).
 * - Enqueues editor CSS and JS with localized REST configuration and media library hooks.
 */
class ProductEditorBridge
{
    private static ?ProductEditorBridge $instance = null;
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

    public function init(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'filterProductDataTabs'], 99);
        add_action('woocommerce_product_data_panels', [$this, 'renderProductDataPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'processProductMetaSave'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueProductEditorAssets']);
    }

    /**
     * Add 'Quản lý biến thể' tab to the product data metabox.
     */
    public function filterProductDataTabs(array $tabs): array
    {
        $tabs['wpe_variants_manager'] = [
            'label'    => __('Variants Manager', 'wc-product-engine'),
            'target'   => 'wpe_variants_manager_data',
            'class'    => ['show_if_variable', 'wpe_variants_tab'],
            'priority' => 65,
        ];

        return $tabs;
    }

    /**
     * Render the custom product data panel for wpe_variants_manager.
     */
    public function renderProductDataPanel(): void
    {
        global $post, $thepostid, $product_object;

        $productId = $thepostid ? (int)$thepostid : ($post ? (int)$post->ID : 0);
        if ($productId <= 0) {
            return;
        }

        $nonce = wp_create_nonce('wpe_save_variants');
        ?>
        <div id="wpe_variants_manager_data" class="panel woocommerce_options_panel hidden wpe-product-editor-panel">
            <!-- Hidden input capturing modified reactive state during standard WP post form submission -->
            <input type="hidden" name="wpe_variants_payload" id="wpe_variants_payload" value="" />
            <input type="hidden" name="wpe_variants_nonce" id="wpe_variants_nonce" value="<?php echo esc_attr($nonce); ?>" />

            <!-- Inline editor mounting point -->
            <div id="wpe-product-editor-inline-app" data-product-id="<?php echo esc_attr($productId); ?>">
                <div class="wpe-editor-loading">
                    <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
                    <?php esc_html_e('Loading variants manager...', 'wc-product-engine'); ?>
                </div>
            </div>
        </div>

        <!-- Fullscreen Editor Modal (shared state with inline app) -->
        <div id="wpe-fullscreen-modal-overlay" class="wpe-fullscreen-overlay" style="display: none;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Fullscreen Variants Manager', 'wc-product-engine'); ?>">
            <div class="wpe-fullscreen-container">
                <div id="wpe-product-editor-modal-app" data-product-id="<?php echo esc_attr($productId); ?>"></div>
            </div>
        </div>

        <!-- Slide-over Variation Detail Drawer -->
        <div id="wpe-variation-drawer-overlay" class="wpe-drawer-overlay" style="display: none;" role="dialog" aria-modal="true">
            <div class="wpe-drawer-container">
                <div id="wpe-variation-drawer-app"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Process standard WordPress product update / publish form submission.
     */
    public function processProductMetaSave(int $productId, $post = null): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (empty($_POST['wpe_variants_payload'])) {
            return;
        }

        if (empty($_POST['wpe_variants_nonce']) || !wp_verify_nonce((string)$_POST['wpe_variants_nonce'], 'wpe_save_variants')) {
            return;
        }

        if (!current_user_can('edit_post', $productId) && !current_user_can('manage_woocommerce')) {
            return;
        }

        $rawPayload = stripslashes((string)$_POST['wpe_variants_payload']);
        $payload = json_decode($rawPayload, true);

        if (!is_array($payload)) {
            return;
        }

        try {
            $actorId = (int) get_current_user_id();
            $this->service->saveProductVariants($productId, $payload, $actorId);
        } catch (Throwable $e) {
            // Log or store admin error notice
            set_transient('wpe_error_' . $productId, $e->getMessage(), 60);
        }
    }

    /**
     * Enqueue CSS, JS and localized settings on the product edit screen.
     */
    public function enqueueProductEditorAssets(string $hook): void
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $productId = (int) $post->ID;

        // Ensure WordPress native Media Library dependencies are available
        wp_enqueue_media();

        $pluginBaseUrl = plugins_url('', dirname(__DIR__, 2));

        // Enqueue Editor Stylesheet
        wp_enqueue_style(
            'wpe-product-editor-css',
            $pluginBaseUrl . '/assets/admin/product-editor.css',
            [],
            '1.3.0'
        );

        // Enqueue Editor Script
        wp_enqueue_script(
            'wpe-product-editor-js',
            $pluginBaseUrl . '/assets/admin/product-editor.js',
            ['jquery', 'wp-util'],
            '1.3.0',
            true
        );

        $currencySymbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $productType = $product ? $product->get_type() : 'simple';

        wp_localize_script('wpe-product-editor-js', 'wpeEditorConfig', [
            'productId'       => $productId,
            'restBase'        => esc_url_raw(rest_url('wpe/v1/admin/product/' . $productId . '/variants')),
            'nonce'           => wp_create_nonce('wp_rest'),
            'currencySymbol'  => $currencySymbol,
            'productType'     => $productType,
            'i18n'            => [
                'saveSuccess'     => __('Variants saved successfully!', 'wc-product-engine'),
                'saving'          => __('Saving changes...', 'wc-product-engine'),
                'saveError'       => __('Error saving variants:', 'wc-product-engine'),
                'conflictError'   => __('Server data has been modified by another process. Please reload data!', 'wc-product-engine'),
                'confirmDelete'   => __('Are you sure you want to delete this option?', 'wc-product-engine'),
                'unsavedWarning'  => __('You have unsaved changes. Are you sure you want to leave?', 'wc-product-engine'),
            ],
        ]);
    }
}
