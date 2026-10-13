<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use WP_Post;
use Throwable;

/**
 * ProductEditorBridge
 *
 * Mounts the WC Product Engine variants manager on the WooCommerce product edit screen as its own
 * meta box (`wpe_variants_manager`), below the Product data box.
 *
 * - The meta box is shown for variable products only. The server renders the initial visibility and the
 *   editor script follows the product type selector. Other product types are left untouched.
 * - The legacy Attributes and Variations tabs stay hidden for variable products by CSS in
 *   product-editor.css; no tab is added to Product data any more.
 * - Persistence goes through the standard Update/Publish submit (`woocommerce_process_product_meta`) using
 *   a hidden payload that the editor fills only when its draft is dirty.
 * - A failed save is never silent: the error and the submitted draft are kept per user and product, the
 *   next load shows an admin notice and the editor restores the draft as unsaved changes.
 * - Fullscreen modal and drawer are printed in the admin footer, outside the postbox.
 * - On Simple and Variable products the native Product image and Product gallery boxes are hidden and the
 *   product image library is the single writer of the featured image and the gallery: the posted
 *   `_thumbnail_id` and `product_image_gallery` keys are removed before WordPress and WooCommerce read them,
 *   and WooCommerce's gallery save callback is not run for that request. The legacy mirror projects the
 *   library into the post meta. Other product types keep the native boxes.
 */
class ProductEditorBridge
{
    public const BOX_ID = 'wpe_variants_manager';

    /** Native image boxes that the product image library replaces. */
    public const NATIVE_IMAGE_BOXES = ['postimagediv', 'woocommerce-product-images'];

    /** Product types whose images are managed by the library. */
    private const MEDIA_LIBRARY_TYPES = ['simple', 'variable'];

    /** Seconds a failure notice / kept draft survives. */
    private const FAILURE_TTL = 3600;

    /** Largest draft payload (bytes) that is kept after a failed save. */
    private const DRAFT_MAX_BYTES = 2097152;

    /** Mirror statuses that mean the WooCommerce shell is consistent. */
    private const MIRROR_OK = ['mirrored', 'skipped_obsolete_revision'];

    private static ?ProductEditorBridge $instance = null;
    private ProductEditorService $service;
    private bool $boxRendered = false;

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
        // `add_meta_boxes_product` fires before `add_meta_boxes`, so it would register this box above
        // WooCommerce's Product data box. Register later than WooCommerce (priority 30) instead.
        add_action('add_meta_boxes', [$this, 'registerMetaBox'], 40, 2);
        add_filter('postbox_classes_product_' . self::BOX_ID, [$this, 'filterBoxClasses']);
        add_action('admin_footer', [$this, 'renderOverlays']);
        add_action('admin_notices', [$this, 'renderSaveNotices']);
        add_action('woocommerce_process_product_meta', [$this, 'processProductMetaSave'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueProductEditorAssets']);

        // The product image library replaces the native image boxes (hidden, not removed, so the editor can
        // restore them) and is the only writer of the featured image and gallery on supported products.
        foreach (self::NATIVE_IMAGE_BOXES as $boxId) {
            add_filter('postbox_classes_product_' . $boxId, [$this, 'filterNativeImageBoxClasses']);
        }
        add_action('admin_init', [$this, 'guardNativeImageKeys'], 1);
    }

    /**
     * Whether the image library manages the images of this product type.
     */
    public static function supportsMediaLibrary(string $productType): bool
    {
        /**
         * Lets an operator bring the native image boxes back (for example while the library UI is unavailable).
         *
         * @param bool $enabled Whether the library replaces the native image boxes.
         */
        if (!apply_filters('wpe_media_library_replaces_native_boxes', true)) {
            return false;
        }
        return in_array($productType, self::MEDIA_LIBRARY_TYPES, true);
    }

    /**
     * Hide a native image box on first paint when the library replaces it for this product.
     *
     * @param array<int,string> $classes
     * @return array<int,string>
     */
    public function filterNativeImageBoxClasses(array $classes): array
    {
        if (self::supportsMediaLibrary($this->currentProductType())) {
            // `hidden` is the WordPress admin utility class (display: none); the second class lets scripts find them.
            $classes[] = 'hidden';
            $classes[] = 'wpe-box-hidden';
        }
        return $classes;
    }

    /**
     * Single writer guard (docs/04 §7): on a product update for a supported type, drop the posted featured
     * image and gallery keys and skip WooCommerce's gallery save, so the legacy path cannot write them.
     * Without removing that callback the missing gallery key would be read as an empty gallery.
     *
     * Reads and changes the superglobals of the current request; it runs on `admin_init`, before post.php
     * passes the request to edit_post().
     *
     * @return bool true when the keys were guarded for this request
     */
    public function guardNativeImageKeys(): bool
    {
        if (($_POST['action'] ?? '') !== 'editpost' || ($_POST['post_type'] ?? '') !== 'product') {
            return false;
        }
        $productId = (int)($_POST['post_ID'] ?? 0);
        if ($productId <= 0 || get_post_type($productId) !== 'product') {
            return false;
        }

        $type = isset($_POST['product-type']) ? sanitize_key((string)wp_unslash($_POST['product-type'])) : '';
        if ($type === '' && function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            $type = $product ? (string)$product->get_type() : 'simple';
        }
        if (!self::supportsMediaLibrary($type !== '' ? $type : 'simple')) {
            return false;
        }

        foreach (['_thumbnail_id', 'product_image_gallery'] as $key) {
            unset($_POST[$key], $_REQUEST[$key]);
        }
        remove_action('woocommerce_process_product_meta', ['WC_Meta_Box_Product_Images', 'save'], 20);
        return true;
    }

    /**
     * Register the Variants Manager meta box on the product screen.
     */
    public function registerMetaBox(string $postType, $post = null): void
    {
        if ($postType !== 'product') {
            return;
        }

        add_meta_box(
            self::BOX_ID,
            __('Variants Manager', 'wc-product-engine'),
            [$this, 'renderMetaBox'],
            'product',
            'normal',
            // Not 'high': WordPress renders 'high' boxes before any box the user has re-ordered, which would
            // put this box above Product data for every user who has ever saved a box order.
            'default'
        );
    }

    /**
     * Hide the box on first paint when the product is not variable, so it does not flash.
     * The editor script keeps it in sync with the product type selector afterwards.
     *
     * @param array<int,string> $classes
     * @return array<int,string>
     */
    public function filterBoxClasses(array $classes): array
    {
        if ($this->currentProductType() !== 'variable') {
            $classes[] = 'wpe-box-hidden';
        }
        return $classes;
    }

    /**
     * Render the meta box body: hidden save inputs and the inline editor mount point.
     */
    public function renderMetaBox($post = null): void
    {
        $productId = $post instanceof WP_Post ? (int)$post->ID : 0;
        if ($productId <= 0) {
            global $thepostid;
            $productId = $thepostid ? (int)$thepostid : 0;
        }
        if ($productId <= 0) {
            return;
        }

        $this->boxRendered = true;
        $nonce = wp_create_nonce('wpe_save_variants');
        ?>
        <div id="wpe-variants-box-body" class="wpe-product-editor-panel">
            <!-- Filled by the editor only when the draft is dirty and the product type is variable -->
            <input type="hidden" name="wpe_variants_payload" id="wpe_variants_payload" value="" />
            <input type="hidden" name="wpe_variants_nonce" id="wpe_variants_nonce" value="<?php echo esc_attr($nonce); ?>" />

            <div id="wpe-product-editor-inline-app" data-product-id="<?php echo esc_attr((string)$productId); ?>">
                <div class="wpe-editor-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e('Loading variants manager...', 'wc-product-engine'); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Print the fullscreen editor modal and the variation drawer in the admin footer.
     * They are kept outside the postbox so that stacking and focus are not affected by it.
     */
    public function renderOverlays(): void
    {
        if (!$this->boxRendered) {
            return;
        }

        global $post;
        $productId = $post instanceof WP_Post ? (int)$post->ID : 0;
        ?>
        <div id="wpe-fullscreen-modal-overlay" class="wpe-fullscreen-overlay" style="display: none;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Fullscreen Variants Manager', 'wc-product-engine'); ?>">
            <div class="wpe-fullscreen-container">
                <div id="wpe-product-editor-modal-app" data-product-id="<?php echo esc_attr((string)$productId); ?>"></div>
            </div>
        </div>

        <div id="wpe-variation-drawer-overlay" class="wpe-drawer-overlay" style="display: none;" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Variation details', 'wc-product-engine'); ?>">
            <div class="wpe-drawer-container">
                <div id="wpe-variation-drawer-app"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Show the outcome of the last Update/Publish for this product: a failed variants save, or a
     * save that committed but whose WooCommerce projection did not complete.
     */
    public function renderSaveNotices(): void
    {
        $productId = $this->currentScreenProductId();
        if ($productId <= 0) {
            return;
        }

        $error = $this->takeTransient($this->failureKey('err', $productId));
        if (is_string($error) && $error !== '') {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Variants Manager: the product was saved, but the variants were NOT saved.', 'wc-product-engine'),
                esc_html($error)
            );
        }

        $warning = $this->takeTransient($this->failureKey('warn', $productId));
        if (is_string($warning) && $warning !== '') {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Variants Manager:', 'wc-product-engine'),
                esc_html($warning)
            );
        }
    }

    /**
     * Process standard WordPress product update / publish form submission.
     */
    public function processProductMetaSave(int $productId, $post = null): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // The editor leaves the payload empty when nothing was edited: variants are then not touched.
        if (empty($_POST['wpe_variants_payload'])) {
            return;
        }

        $rawPayload = (string)wp_unslash((string)$_POST['wpe_variants_payload']);

        if (empty($_POST['wpe_variants_nonce']) || !wp_verify_nonce((string)$_POST['wpe_variants_nonce'], 'wpe_save_variants')) {
            $this->recordFailure($productId, __('The security check failed. Reload the page and apply the changes again.', 'wc-product-engine'), $rawPayload);
            return;
        }

        if (!current_user_can('edit_post', $productId) && !current_user_can('manage_woocommerce')) {
            $this->recordFailure($productId, __('You are not allowed to change the variants of this product.', 'wc-product-engine'), $rawPayload);
            return;
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            $this->recordFailure($productId, __('The submitted variants data could not be read.', 'wc-product-engine'), $rawPayload);
            return;
        }

        try {
            $actorId = (int) get_current_user_id();
            $result = $this->service->saveProductVariants($productId, $payload, $actorId);
        } catch (Throwable $e) {
            $this->recordFailure($productId, $e->getMessage(), $rawPayload);
            return;
        }

        // The save committed: drop any older failure, then report an incomplete projection honestly.
        delete_transient($this->failureKey('err', $productId));
        delete_transient($this->failureKey('draft', $productId));

        $projection = is_array($result['projection'] ?? null) ? $result['projection'] : [];
        $mirror = (string)($projection['legacy_mirror'] ?? 'unknown');
        $index = (string)($projection['catalog_index'] ?? 'unknown');
        if (!in_array($mirror, self::MIRROR_OK, true) || $index !== 'ok') {
            set_transient(
                $this->failureKey('warn', $productId),
                sprintf(
                    /* translators: 1: legacy mirror status, 2: catalog index status */
                    __('The variants were saved, but the WooCommerce compatibility projection did not complete (legacy mirror: %1$s, catalog index: %2$s). Storefront data may be out of date until this is resolved in Operations.', 'wc-product-engine'),
                    $mirror,
                    $index
                ),
                self::FAILURE_TTL
            );
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

        $cssPath = dirname(__DIR__, 2) . '/assets/admin/product-editor.css';
        $jsPath  = dirname(__DIR__, 2) . '/assets/admin/product-editor.js';
        $cssVer  = file_exists($cssPath) ? (string) filemtime($cssPath) : '1.4.0';
        $jsVer   = file_exists($jsPath) ? (string) filemtime($jsPath) : '1.4.0';

        wp_enqueue_style(
            'wpe-product-editor-css',
            $pluginBaseUrl . '/assets/admin/product-editor.css',
            [],
            $cssVer
        );

        wp_enqueue_script(
            'wpe-product-editor-js',
            $pluginBaseUrl . '/assets/admin/product-editor.js',
            ['jquery', 'wp-util'],
            $jsVer,
            true
        );

        $currencySymbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $productType = $product ? $product->get_type() : 'simple';

        // A draft kept after a failed save is handed over once; the editor restores it as unsaved changes.
        $draft = null;
        $draftRaw = $this->takeTransient($this->failureKey('draft', $productId));
        if (is_string($draftRaw) && $draftRaw !== '') {
            $decoded = json_decode($draftRaw, true);
            $draft = is_array($decoded) ? $decoded : null;
        }

        wp_localize_script('wpe-product-editor-js', 'wpeEditorConfig', [
            'productId'       => $productId,
            'boxId'           => self::BOX_ID,
            'restBase'        => esc_url_raw(rest_url('wpe/v1/admin/product/' . $productId . '/variants')),
            'nonce'           => wp_create_nonce('wp_rest'),
            'currencySymbol'  => $currencySymbol,
            'productType'     => $productType,
            'restoredDraft'   => $draft,
            'i18n'            => [
                'saveSuccess'     => __('Variants saved successfully!', 'wc-product-engine'),
                'saving'          => __('Saving changes...', 'wc-product-engine'),
                'saveError'       => __('Error saving variants:', 'wc-product-engine'),
                'conflictError'   => __('Server data has been modified by another process. Please reload data!', 'wc-product-engine'),
                'confirmDelete'   => __('Are you sure you want to delete this option?', 'wc-product-engine'),
                'unsavedWarning'  => __('You have unsaved changes. Are you sure you want to leave?', 'wc-product-engine'),
                'typeChangeWarning' => __('You have unsaved variant changes, but the product type is no longer Variable, so they will NOT be saved. Continue anyway?', 'wc-product-engine'),
                'draftRestored'   => __('Your unsaved variant changes were restored after the failed save.', 'wc-product-engine'),
                'projectionWarning' => __('Saved, but the WooCommerce compatibility projection did not complete:', 'wc-product-engine'),
            ],
        ]);
    }

    private function currentProductType(): string
    {
        global $post;
        $id = $post instanceof WP_Post ? (int)$post->ID : 0;
        $product = ($id > 0 && function_exists('wc_get_product')) ? wc_get_product($id) : null;
        return $product ? (string)$product->get_type() : 'simple';
    }

    private function currentScreenProductId(): int
    {
        if (!function_exists('get_current_screen')) {
            return 0;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'product') {
            return 0;
        }
        global $post;
        return $post instanceof WP_Post ? (int)$post->ID : 0;
    }

    private function failureKey(string $kind, int $productId): string
    {
        return 'wpe_vm_' . $kind . '_' . (int)get_current_user_id() . '_' . $productId;
    }

    /**
     * Read and delete a transient so that a notice or draft is shown exactly once.
     *
     * @return mixed
     */
    private function takeTransient(string $key)
    {
        $value = get_transient($key);
        if ($value !== false) {
            delete_transient($key);
        }
        return $value;
    }

    /**
     * Keep a failed save visible: store the error and, when small enough, the submitted draft.
     */
    private function recordFailure(int $productId, string $message, string $rawPayload): void
    {
        $message = trim(wp_strip_all_tags($message));
        if (strlen($message) > 300) {
            $message = substr($message, 0, 300) . '…';
        }
        if ($message === '') {
            $message = __('Unknown error.', 'wc-product-engine');
        }

        if ($rawPayload !== '' && strlen($rawPayload) <= self::DRAFT_MAX_BYTES && is_array(json_decode($rawPayload, true))) {
            set_transient($this->failureKey('draft', $productId), $rawPayload, self::FAILURE_TTL);
            $message .= ' ' . __('Your changes were kept as an unsaved draft in the Variants Manager.', 'wc-product-engine');
        } else {
            $message .= ' ' . __('Your changes could not be kept, please apply them again.', 'wc-product-engine');
        }

        set_transient($this->failureKey('err', $productId), $message, self::FAILURE_TTL);
    }
}
