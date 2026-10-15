<?php

namespace WPE\Compatibility\StoreApi;

use RuntimeException;
use InvalidArgumentException;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WPE\Admin\SettingsRegistry;
use WPE\Application\Variant\VariantResolver;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\GalleryAdapter;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\OptionsRepository;

/**
 * Variant Selector UI, Swatches, Single Price Display, and Asynchronous Store API / Classic Adapter.
 *
 * Responsibilities:
 * - Enqueue accessible variant selector scripts/styles on storefront for variable products (R108).
 * - Resolve per-option display type ('dropdown', 'button', 'color', 'image') strictly by stable option/attribute ID,
 *   never guessing from "Color/Size" names, with global toggle fallback to 'dropdown' (P012).
 * - Default 'image' display type to the first variation-forming option by saved position when unconfigured (P012).
 * - Render accessible swatch controls (color, image, button, dropdown) with keyboard navigation, help text
 *   (aria-describedby), selected value label metadata, and unavailable option states (R108, AC-T27-01, P012).
 * - Single Price Display hooks replacing variable price ranges on PDP and shop/category/search/product cards
 *   with the lowest effective price among valid purchasable variations, never returning fake 0 (P012).
 * - Enforce large variant payload budget by capping woocommerce_ajax_variation_threshold to 0 for WPE products (R110).
 * - Versioned caching and invalidation on settings, option, media, price, and inventory changes (P012).
 */
class SelectorAdapter
{
    private static ?SelectorAdapter $instance = null;
    private static bool $registered = false;

    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private ?GalleryAdapter $galleryAdapter;
    private VariantResolver $resolver;
    private SettingsRegistry $settingsRegistry;
    private AssetService $assetService;

    private string $tableProducts;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableVariantMedia;
    private string $tableProductMedia;

    /** @var array<string, array> */
    private array $configCache = [];

    /** @var array<string, string> */
    private array $lowestPriceCache = [];

    /** @var array<int, array|null> */
    private array $lastProductRow = [];

    public function __construct(
        ?\wpdb $wpdb = null,
        ?OptionsRepository $optionsRepo = null,
        ?GalleryAdapter $galleryAdapter = null,
        ?VariantResolver $resolver = null,
        ?SettingsRegistry $settingsRegistry = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->galleryAdapter = $galleryAdapter;
        $this->assetService = new AssetService($this->wpdb, new MediaRepository($this->wpdb));
        $this->resolver = $resolver ?? new VariantResolver($this->wpdb, $this->optionsRepo, $this->assetService);
        $this->settingsRegistry = $settingsRegistry ?? new SettingsRegistry();

        $prefix = $this->wpdb->prefix;
        $this->tableProducts     = $prefix . 'wpe_products';
        $this->tableOptions      = $prefix . 'wpe_options';
        $this->tableOptionValues = $prefix . 'wpe_option_values';
        $this->tableVariants     = $prefix . 'wpe_variants';
        $this->tableInventory    = $prefix . 'wpe_inventory';
        $this->tableVariantMedia = $prefix . 'wpe_variant_media';
        $this->tableProductMedia = $prefix . 'wpe_product_media';
    }

    public static function instance(?\wpdb $wpdb = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($wpdb);
        }
        return self::$instance;
    }

    /**
     * Register WordPress and WooCommerce hooks.
     */
    public static function register(?SelectorAdapter $adapter = null): self
    {
        $instance = $adapter ?? self::instance();
        self::$instance = $instance;

        if (self::$registered) {
            return $instance;
        }

        // 1. Enqueue scripts and styles on storefront (R108)
        add_action('wp_enqueue_scripts', [$instance, 'enqueueScripts']);

        // 2. Prevent WooCommerce from dumping 10k variations into data-product_variations HTML attribute (R110, AC-T27-02)
        add_filter('woocommerce_ajax_variation_threshold', [$instance, 'filterVariationThreshold'], 20, 2);

        // 3. Render accessible swatches or standard dropdown per option (R108, AC-T27-01, P012)
        add_filter('woocommerce_dropdown_variation_attribute_options_html', [$instance, 'renderSwatchControls'], 10, 2);

        // 4. Embed instance-scoped JSON config inside each variations_form for multi-instance / quick-view safety (P012)
        add_action('woocommerce_before_variations_form', [$instance, 'renderFormInstanceConfig'], 10);

        // 5. Single Price Display filters across PDP and shop/category/search/product cards (P012)
        add_filter('woocommerce_variable_price_html', [$instance, 'filterVariablePriceHtml'], 20, 2);
        add_filter('woocommerce_variable_sale_price_html', [$instance, 'filterVariablePriceHtml'], 20, 2);
        add_filter('woocommerce_get_price_html', [$instance, 'filterProductGetPriceHtml'], 20, 2);
        add_filter('woocommerce_available_variation', [$instance, 'filterAvailableVariationPrice'], 20, 3);

        // 6. Store API and Blocks integration
        add_action('woocommerce_blocks_loaded', [$instance, 'registerStoreApiIntegration']);

        // 7. Cache invalidation when settings change
        add_action('update_option_wpe_feature_flags', [$instance, 'onSettingsUpdated'], 10, 0);
        add_action('update_option_wpe_attribute_display_config', [$instance, 'onSettingsUpdated'], 10, 0);

        self::$registered = true;
        return $instance;
    }

    /**
     * Clear cached selector configs and lowest price HTML when settings or product data change.
     */
    public function invalidateCache(?int $productId = null): void
    {
        if ($productId === null) {
            $this->configCache = [];
            $this->lowestPriceCache = [];
            $this->lastProductRow = [];
            $ver = (int)get_option('wpe_selector_cache_ver', 1);
            update_option('wpe_selector_cache_ver', $ver + 1, false);
            return;
        }

        unset($this->lastProductRow[$productId]);
        foreach (array_keys($this->configCache) as $k) {
            if (str_starts_with($k, $productId . ':')) {
                unset($this->configCache[$k]);
            }
        }
        foreach (array_keys($this->lowestPriceCache) as $k) {
            if (str_starts_with($k, $productId . ':')) {
                unset($this->lowestPriceCache[$k]);
            }
        }
    }

    public function onSettingsUpdated(): void
    {
        $this->invalidateCache(null);
    }

    /**
     * Check if a product is managed by WPE.
     */
    public function isProductManagedByWpe(int $productId): bool
    {
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ));
        return $count > 0;
    }

    /**
     * Read current Product Page feature flags with safe defaults.
     */
    public function getProductPageFlags(): array
    {
        $flags = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, []);
        if (!is_array($flags)) {
            $flags = [];
        }
        return [
            'variant_selector_ui'      => array_key_exists('variant_selector_ui', $flags) ? (bool)$flags['variant_selector_ui'] : true,
            'single_price_display'     => !empty($flags['single_price_display']),
            'show_selected_value'      => !empty($flags['show_selected_value']),
            'show_help_text'           => !empty($flags['show_help_text']),
            'button_selector'          => !empty($flags['button_selector']),
            'color_swatches'           => !empty($flags['color_swatches']),
            'image_swatches'           => !empty($flags['image_swatches']),
            'mark_unavailable_options' => !empty($flags['mark_unavailable_options']),
        ];
    }

    /**
     * Read global attribute display configuration map from wp_options.
     */
    public function getAttributeDisplayConfig(): array
    {
        $cfg = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ATTR_DISPLAY, []);
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * Filter WooCommerce variation threshold to 0 for WPE variable products.
     * Prevents embedding 10,000 variation objects into HTML attributes (R110, AC-T27-02).
     */
    public function filterVariationThreshold(int $threshold, mixed $product): int
    {
        if (!$product) {
            return $threshold;
        }

        $productId = is_numeric($product) ? (int)$product : ($product instanceof WC_Product ? $product->get_id() : 0);
        if ($productId <= 0) {
            return $threshold;
        }

        if ($this->isProductManagedByWpe($productId)) {
            $flags = $this->getProductPageFlags();
            if (!$flags['variant_selector_ui'] && !$flags['single_price_display']) {
                return $threshold;
            }
            return 0;
        }

        return $threshold;
    }

    /**
     * Enqueue scripts and styles on storefront when custom selector UI or Single Price Display is enabled.
     */
    public function enqueueScripts(): void
    {
        if (function_exists('is_product') && !is_product()) {
            return;
        }

        global $product;
        if (!$product || !($product instanceof WC_Product)) {
            $postId = get_the_ID();
            if ($postId && function_exists('wc_get_product')) {
                $product = wc_get_product($postId);
            }
        }

        if (!$product || !$product->is_type('variable')) {
            return;
        }

        $productId = $product->get_id();
        if (!$this->isProductManagedByWpe($productId)) {
            return;
        }

        $flags = $this->getProductPageFlags();
        if (!$flags['variant_selector_ui'] && !$flags['single_price_display']) {
            return;
        }

        $pluginUrl = defined('WPE_PLUGIN_FILE') ? plugin_dir_url(WPE_PLUGIN_FILE) : '';
        $version = defined('WPE_VERSION') ? WPE_VERSION : '1.1.0';

        wp_enqueue_style(
            'wpe-variant-selector',
            $pluginUrl . 'assets/variant-selector/selector.css',
            [],
            $version
        );

        wp_enqueue_script(
            'wpe-variant-selector',
            $pluginUrl . 'assets/variant-selector/selector.js',
            [],
            $version,
            true
        );

        $config = $this->getSelectorConfig($productId);
        wp_localize_script('wpe-variant-selector', 'wpeSelectorData', $config);
    }

    /**
     * Render instance-scoped JSON config inside each form.variations_form.
     * Supports multiple product instances on the same page (e.g. main product + quick-view modal).
     */
    public function renderFormInstanceConfig(): void
    {
        global $product;
        if (!$product instanceof WC_Product || !$product->is_type('variable')) {
            return;
        }
        $productId = (int)$product->get_id();
        if (!$this->isProductManagedByWpe($productId)) {
            return;
        }

        $flags = $this->getProductPageFlags();
        if (!$flags['variant_selector_ui'] && !$flags['single_price_display']) {
            return;
        }

        $config = $this->getSelectorConfig($productId);
        printf(
            '<script type="application/json" class="wpe-selector-instance-config" data-product-id="%d">%s</script>',
            $productId,
            wp_json_encode($config)
        );
        if (!empty($flags['variant_selector_ui'])) {
            echo '<script>(function(){var s=document.currentScript,c=s&&s.closest?s.closest(".starterkit-product-form--live"):null;if(c){c.setAttribute("data-selector-type","wpe");c.setAttribute("data-preselect-first","false");}})();</script>';
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Single Price Display Filters                                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Check if a product is a variable product managed by WPE.
     */
    public function isVariableProductManagedByWpe(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE id = %d AND type = 'variable' AND deleted_at IS NULL",
            $productId
        ));
        return $count > 0;
    }

    /**
     * Filter variable product price range HTML when Single Price Display is enabled.
     */
    public function filterVariablePriceHtml(string $priceHtml, mixed $product): string
    {
        $productId = is_numeric($product) ? (int)$product : ($product instanceof WC_Product ? (int)$product->get_id() : 0);
        if ($productId <= 0 || !$this->isVariableProductManagedByWpe($productId)) {
            return $priceHtml;
        }

        $flags = $this->getProductPageFlags();
        if (!$flags['single_price_display']) {
            return $priceHtml;
        }

        return $this->buildSinglePriceDisplayHtml($productId);
    }

    /**
     * Filter woocommerce_get_price_html to cover variable products across PDP and archive/shop/category/search cards.
     */
    public function filterProductGetPriceHtml(string $priceHtml, mixed $product): string
    {
        $productId = is_numeric($product) ? (int)$product : ($product instanceof WC_Product ? (int)$product->get_id() : 0);
        if ($productId <= 0 || !$this->isVariableProductManagedByWpe($productId)) {
            return $priceHtml;
        }

        $flags = $this->getProductPageFlags();
        if (!$flags['single_price_display']) {
            return $priceHtml;
        }

        return $this->buildSinglePriceDisplayHtml($productId);
    }

    /**
     * Ensure woocommerce_available_variation always provides full price_html for priced variations.
     */
    public function filterAvailableVariationPrice($variationData, $product, $variation)
    {
        if (!is_array($variationData)) {
            return $variationData;
        }

        $variationId = 0;
        if ($variation instanceof WC_Product_Variation) {
            $variationId = (int)$variation->get_id();
        } elseif (isset($variationData['variation_id'])) {
            $variationId = (int)$variationData['variation_id'];
        }

        $parentId = 0;
        if ($product instanceof WC_Product) {
            $parentId = (int)$product->get_id();
        } elseif ($variation instanceof WC_Product_Variation) {
            $parentId = (int)$variation->get_parent_id();
        }

        if ($variationId > 0 && $parentId > 0 && empty($variationData['price_html']) && $this->isProductManagedByWpe($parentId)) {
            $variantRow = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableVariants} WHERE id = %d AND product_id = %d AND deleted_at IS NULL LIMIT 1",
                $variationId,
                $parentId
            ), ARRAY_A);
            if ($variantRow) {
                $priceHtml = $this->resolver->formatVariationPriceHtml($parentId, $variantRow);
                if ($priceHtml !== '') {
                    $variationData['price_html'] = $priceHtml;
                }
            }
        }

        return $variationData;
    }

    /**
     * Build Single Price Display HTML for a variable product.
     * Returns '' (never fake 0) if no valid purchasable variation with a price exists.
     */
    public function buildSinglePriceDisplayHtml(int $productId, bool $preferDefaultSelection = false): string
    {
        if ($preferDefaultSelection || (function_exists('is_product') && is_product())) {
            $config = $this->getSelectorConfig($productId);
            $initRes = $config['initialResolution'] ?? [];
            if (
                ($initRes['state'] ?? '') === 'exact'
                && !empty($initRes['variant']['purchasable'])
                && !empty($initRes['variant']['price_html'])
            ) {
                $inner = $this->unwrapOuterPriceSpan((string)$initRes['variant']['price_html']);
                return sprintf(
                    '<span class="wpe-single-price-target" data-wpe-product-id="%d" data-wpe-price-state="resolved">%s</span>',
                    $productId,
                    $inner
                );
            }
        }

        $lowestHtml = $this->getLowestPriceHtml($productId);
        if ($lowestHtml === '') {
            return '';
        }

        $inner = $this->unwrapOuterPriceSpan($lowestHtml);
        return sprintf(
            '<span class="wpe-single-price-target" data-wpe-product-id="%d" data-wpe-price-state="lowest">%s</span>',
            $productId,
            $inner
        );
    }

    /**
     * Get cached lowest purchasable variation price HTML for a product.
     */
    public function getLowestPriceHtml(int $productId): string
    {
        $cacheKey = $this->getLowestPriceCacheKey($productId);
        if (array_key_exists($cacheKey, $this->lowestPriceCache)) {
            return $this->lowestPriceCache[$cacheKey];
        }

        $cached = wp_cache_get('lowest_' . $cacheKey, 'wpe_selector');
        if (is_string($cached)) {
            $this->lowestPriceCache[$cacheKey] = $cached;
            return $cached;
        }

        $lowest = $this->resolver->resolveLowestPurchasableVariation($productId);
        $html = $lowest ? (string)$lowest['price_html'] : '';

        $this->lowestPriceCache[$cacheKey] = $html;
        wp_cache_set('lowest_' . $cacheKey, $html, 'wpe_selector', 300);

        return $html;
    }

    /* -------------------------------------------------------------------------- */
    /* Selector Configuration & Display Type Resolution                           */
    /* -------------------------------------------------------------------------- */

    /**
     * Get complete selector configuration for frontend client or Store API consumer.
     */
    public function getSelectorConfig(int $productId): array
    {
        $cacheKey = $this->getProductCacheKey($productId);
        if (isset($this->configCache[$cacheKey])) {
            return $this->configCache[$cacheKey];
        }

        $cached = wp_cache_get('cfg_' . $cacheKey, 'wpe_selector');
        if (is_array($cached)) {
            $this->configCache[$cacheKey] = $cached;
            return $cached;
        }

        $productRow = array_key_exists($productId, $this->lastProductRow)
            ? $this->lastProductRow[$productId]
            : $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id, title, version, status, type, default_selection, extra_props FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
                $productId
            ), ARRAY_A);

        $version = $productRow ? (string)$productRow['version'] : '1';
        $flags = $this->getProductPageFlags();
        $globalAttrConfig = $this->getAttributeDisplayConfig();

        $extraProps = [];
        if (!empty($productRow['extra_props']) && is_string($productRow['extra_props'])) {
            $decodedExtra = json_decode($productRow['extra_props'], true);
            if (is_array($decodedExtra)) {
                $extraProps = $decodedExtra;
            }
        }
        $optionUiMap = is_array($extraProps['option_ui'] ?? null) ? $extraProps['option_ui'] : [];

        $rawOptions = $this->optionsRepo->getOptionsByProductId($productId);
        $variationOptions = array_values(array_filter(
            $rawOptions,
            fn(array $o) => (int)($o['used_for_variation'] ?? 1) === 1
        ));

        // Resolve default selection before running initial resolution
        $defaultSelection = $this->resolveDefaultSelection($productId, $variationOptions, $productRow);

        // Execute bounded initial resolution once for defaultSelection, passing preloaded product & options
        $initialRes = $this->resolver->resolve(
            $productId,
            $defaultSelection,
            1,
            null,
            0,
            is_array($productRow) ? $productRow : null,
            $variationOptions
        );
        $lowestPriceHtml = (string)($initialRes['lowest_price_html'] ?? '');

        // Identify the default Image Swatch option (1st variation option by position, or promoted option with distinct variation media)
        $defaultImageOptionId = $this->resolveDefaultImageOptionId(
            $variationOptions,
            $optionUiMap,
            $flags,
            $initialRes['value_thumbnails'] ?? []
        );

        $formattedOptions = [];
        foreach ($variationOptions as $opt) {
            $optId = (int)$opt['id'];
            $optIdStr = (string)$optId;
            $optKey = (string)($opt['key'] ?? ($opt['option_key'] ?? ''));
            $optName = (string)($opt['name'] ?? $optKey);
            $isFirstOption = ($optId === $defaultImageOptionId);

            $optUi = is_array($optionUiMap[$optIdStr] ?? null)
                ? $optionUiMap[$optIdStr]
                : (is_array($optionUiMap[$optId] ?? null) ? $optionUiMap[$optId] : []);
            if (!isset($opt['display_type']) && isset($optUi['display_type'])) {
                $opt['display_type'] = $optUi['display_type'];
            }
            if (!isset($opt['help_text']) && isset($optUi['help_text'])) {
                $opt['help_text'] = $optUi['help_text'];
            }

            $displayMeta = $this->resolveOptionDisplayMeta($opt, $isFirstOption, $flags, $globalAttrConfig);
            $configuredType = $displayMeta['configured_display_type'];
            $effectiveType  = $displayMeta['effective_display_type'];
            $helpText       = $displayMeta['help_text'];
            $globalValues   = $displayMeta['global_values'];

            $optThumbs  = $initialRes['value_thumbnails'][$optIdStr] ?? [];
            $optDetails = $initialRes['allowed_details'][$optIdStr] ?? [];

            $values = [];
            foreach ($opt['values'] as $val) {
                $valId = (int)$val['id'];
                $valIdStr = (string)$valId;
                $valKey = (string)$val['value_key'];
                $valLabel = (string)$val['label'];
                $termId = isset($val['term_id']) && $val['term_id'] !== null ? (int)$val['term_id'] : 0;

                $valSlug = sanitize_title($valKey);
                $globalColorFallback = $globalValues[$valKey]
                    ?? ($valSlug !== '' ? ($globalValues[$valSlug] ?? null) : null)
                    ?? ($termId > 0 ? ($globalValues[(string)$termId] ?? ($globalValues[$termId] ?? null)) : null);
                $podColorFallback = ($configuredType === 'color' || $effectiveType === 'color' || $this->isColorOption($opt))
                    ? $this->resolvePodColorHex($valKey, $valLabel)
                    : null;
                $colorHex = $this->extractValidColorHex($val['swatch'] ?? null, $globalColorFallback, $podColorFallback);
                $thumbInfo = $optThumbs[$valIdStr] ?? null;
                $detailInfo = $optDetails[$valIdStr] ?? [
                    'exists'      => true,
                    'purchasable' => true,
                    'in_stock'    => true,
                ];

                $swatchValue = null;
                if ($configuredType === 'color' || $effectiveType === 'color') {
                    $swatchValue = $colorHex;
                } elseif ($configuredType === 'image' || $effectiveType === 'image') {
                    $swatchValue = $thumbInfo['src'] ?? null;
                }

                $values[] = [
                    'id'           => $valId,
                    'key'          => $valKey,
                    'label'        => $valLabel,
                    'swatch_value' => $swatchValue,
                    'color_hex'    => $colorHex,
                    'is_light'     => $colorHex !== null ? $this->isLightHexColor($colorHex) : false,
                    'thumbnail'    => $thumbInfo,
                    'exists'       => (bool)$detailInfo['exists'],
                    'purchasable'  => (bool)$detailInfo['purchasable'],
                    'available'    => (bool)$detailInfo['purchasable'],
                    'in_stock'     => (bool)$detailInfo['in_stock'],
                ];
            }

            $formattedOptions[] = [
                'id'                      => $optId,
                'key'                     => $optKey,
                'name'                    => $optName,
                'position'                => (int)($opt['position'] ?? 0),
                'configured_display_type' => $configuredType,
                'display_type'            => $effectiveType,
                'swatch_type'             => $effectiveType,
                'help_text'               => $helpText,
                'values'                  => $values,
            ];
        }

        $resolveEndpoint = rest_url("wpe/v1/products/{$productId}/resolve");
        $wcAjaxUrl = class_exists('\WC_AJAX') ? \WC_AJAX::get_endpoint('get_variation') : admin_url('admin-ajax.php?action=woocommerce_get_variation');

        $config = [
            'productId'         => $productId,
            'resolveEndpoint'   => $resolveEndpoint,
            'wcAjaxUrl'         => $wcAjaxUrl,
            'nonce'             => wp_create_nonce('wp_rest'),
            'productRevision'   => $version,
            'flags'             => $flags,
            'options'           => $formattedOptions,
            'defaultSelection'  => $defaultSelection,
            'lowestPriceHtml'   => $lowestPriceHtml,
            'initialResolution' => [
                'state'             => $initialRes['state'] ?? 'partial',
                'variant'           => $initialRes['variant'] ?? null,
                'candidate_image'   => $initialRes['candidate_image'] ?? null,
                'reason'            => $initialRes['reason'] ?? null,
                'allowed_values'    => $initialRes['allowed_values'] ?? [],
                'allowed_details'   => $initialRes['allowed_details'] ?? [],
                'value_thumbnails'  => $initialRes['value_thumbnails'] ?? [],
                'lowest_price_html' => $lowestPriceHtml,
            ],
            'i18n' => [
                'unavailable'  => __('This combination is unavailable', 'wc-product-engine'),
                'ambiguous'    => __('Selection cannot be resolved', 'wc-product-engine'),
                'loading'      => __('Checking availability...', 'wc-product-engine'),
                'reset'        => __('Clear selection', 'wc-product-engine'),
                'inStock'      => __('In stock', 'wc-product-engine'),
                'outOfStock'   => __('Out of stock', 'wc-product-engine'),
                'selectOption' => __('Choose an option', 'wc-product-engine'),
            ],
        ];

        $this->configCache[$cacheKey] = $config;
        wp_cache_set('cfg_' . $cacheKey, $config, 'wpe_selector', 300);

        return $config;
    }

    /**
     * Identify which unconfigured (auto) variation option should default to 'image' when Image Swatches is active.
     * Defaults to the 1st variation option by saved position; if the 1st option is a color attribute (when color_swatches
     * is ON) or has at most 1 distinct variation thumbnail while a later unconfigured non-color/non-size option has >= 2
     * distinct variation thumbnails, promote that option.
     */
    private function resolveDefaultImageOptionId(
        array $variationOptions,
        array $optionUiMap,
        array $flags,
        array $valueThumbnails
    ): int {
        if (empty($variationOptions)) {
            return 0;
        }

        $firstId = (int)$variationOptions[0]['id'];
        $firstOpt = $variationOptions[0];
        $firstDistinctThumbs = $this->countDistinctOptionVariationThumbs($firstId, $valueThumbnails);
        if (count($variationOptions) <= 1) {
            if ($this->isSizeOption($firstOpt) && $firstDistinctThumbs <= 1) {
                return 0;
            }
            return $firstId;
        }

        $firstIsAutoColor = !empty($flags['color_swatches']) && $this->isColorOption($firstOpt);

        if (!$firstIsAutoColor && $firstDistinctThumbs >= 2 && !$this->isSizeOption($firstOpt)) {
            return $firstId;
        }

        $validTypes = ['dropdown', 'button', 'color', 'image'];
        foreach ($variationOptions as $idx => $opt) {
            if ($idx === 0) {
                continue;
            }
            $optId = (int)$opt['id'];
            $optIdStr = (string)$optId;
            $optUi = is_array($optionUiMap[$optIdStr] ?? null)
                ? $optionUiMap[$optIdStr]
                : (is_array($optionUiMap[$optId] ?? null) ? $optionUiMap[$optId] : []);
            $perType = isset($opt['display_type'])
                ? strtolower(trim((string)$opt['display_type']))
                : (isset($optUi['display_type']) ? strtolower(trim((string)$optUi['display_type'])) : '');
            if (in_array($perType, $validTypes, true)) {
                continue;
            }
            if (!empty($flags['color_swatches']) && $this->isColorOption($opt)) {
                continue;
            }
            if ($this->isSizeOption($opt)) {
                continue;
            }
            if ($this->countDistinctOptionVariationThumbs($optId, $valueThumbnails) >= 2) {
                return $optId;
            }
        }

        if ($this->isSizeOption($firstOpt) && $firstDistinctThumbs <= 1) {
            return 0;
        }

        return $firstId;
    }

    /**
     * Count distinct variation-sourced thumbnail URLs across an option's values.
     */
    private function countDistinctOptionVariationThumbs(int $optId, array $valueThumbnails): int
    {
        $thumbs = $valueThumbnails[(string)$optId] ?? ($valueThumbnails[$optId] ?? []);
        if (!is_array($thumbs)) {
            return 0;
        }
        $uniqueUrls = [];
        foreach ($thumbs as $t) {
            if (!is_array($t) || empty($t['src'])) {
                continue;
            }
            $source = (string)($t['source'] ?? '');
            if ($source === 'variation' || $source === 'representative') {
                $uniqueUrls[(string)$t['src']] = true;
            }
        }
        return count($uniqueUrls);
    }

    /**
     * Detect whether an option represents a Size attribute.
     */
    private function isSizeOption(array $opt): bool
    {
        $key = strtolower(trim((string)($opt['key'] ?? ($opt['option_key'] ?? ''))));
        $name = strtolower(trim((string)($opt['name'] ?? '')));
        $strippedKey = str_starts_with($key, 'pa_') ? substr($key, 3) : $key;
        $sizeTokens = ['size', 'sizes', 'kich-thuoc', 'kich_thuoc', 'kích thước', 'kich-co', 'kích cỡ'];
        return in_array($strippedKey, $sizeTokens, true) || in_array($name, $sizeTokens, true);
    }

    /**
     * Resolve configured_display_type, effective_display_type, and help_text for an option from Global Settings.
     */
    private function resolveOptionDisplayMeta(
        array $opt,
        bool $isFirstOption,
        array $flags,
        array $globalAttrConfig
    ): array {
        $optId = (int)$opt['id'];
        $optKey = (string)($opt['key'] ?? ($opt['option_key'] ?? ''));
        $rawKey = $optKey !== '' ? $optKey : (string)($opt['name'] ?? '');
        $strippedKey = str_starts_with($rawKey, 'pa_') ? substr($rawKey, 3) : $rawKey;
        $baseSlug = sanitize_title($strippedKey);
        $validTypes = ['dropdown', 'button', 'color', 'image'];

        // Lookup global attribute configuration by stable WC attribute_id, opt_<slug>, or taxonomy key
        $globalEntry = null;
        $registeredTaxAttrIds = [];
        if (function_exists('wc_get_attribute_taxonomies')) {
            foreach ((array)wc_get_attribute_taxonomies() as $taxObj) {
                $tid = (int)($taxObj->attribute_id ?? 0);
                if ($tid > 0) {
                    $registeredTaxAttrIds[$tid] = true;
                }
            }
        }

        if ($baseSlug !== '' && function_exists('wc_attribute_taxonomy_id_by_name')) {
            $taxAttrId = (int)wc_attribute_taxonomy_id_by_name('pa_' . $baseSlug);
            if ($taxAttrId <= 0 && $optKey !== '') {
                $taxAttrId = (int)wc_attribute_taxonomy_id_by_name($optKey);
            }
            if ($taxAttrId > 0) {
                if (isset($globalAttrConfig[(string)$taxAttrId]) && is_array($globalAttrConfig[(string)$taxAttrId])) {
                    $globalEntry = $globalAttrConfig[(string)$taxAttrId];
                } elseif (isset($globalAttrConfig[$taxAttrId]) && is_array($globalAttrConfig[$taxAttrId])) {
                    $globalEntry = $globalAttrConfig[$taxAttrId];
                }
            }
        }

        if ($globalEntry === null && $baseSlug !== '') {
            $optCfgKey = 'opt_' . $baseSlug;
            if (isset($globalAttrConfig[$optCfgKey]) && is_array($globalAttrConfig[$optCfgKey])) {
                $globalEntry = $globalAttrConfig[$optCfgKey];
            } elseif (isset($globalAttrConfig[$baseSlug]) && is_array($globalAttrConfig[$baseSlug])) {
                $globalEntry = $globalAttrConfig[$baseSlug];
            } elseif (isset($globalAttrConfig['pa_' . $baseSlug]) && is_array($globalAttrConfig['pa_' . $baseSlug])) {
                $globalEntry = $globalAttrConfig['pa_' . $baseSlug];
            }
        }

        if ($globalEntry === null && $optKey !== '' && isset($globalAttrConfig[$optKey]) && is_array($globalAttrConfig[$optKey])) {
            $globalEntry = $globalAttrConfig[$optKey];
        }

        // Only match by wpe_options.id if it does not collide with a registered WC taxonomy attribute_id
        if ($globalEntry === null && !isset($registeredTaxAttrIds[$optId])) {
            if (isset($globalAttrConfig[(string)$optId]) && is_array($globalAttrConfig[(string)$optId])) {
                $globalEntry = $globalAttrConfig[(string)$optId];
            } elseif (isset($globalAttrConfig[$optId]) && is_array($globalAttrConfig[$optId])) {
                $globalEntry = $globalAttrConfig[$optId];
            }
        }

        // 1. Determine configured display type (Global Settings -> legacy per-option fallback -> auto defaults)
        $globalType = isset($globalEntry['display_type']) ? strtolower(trim((string)$globalEntry['display_type'])) : '';
        $perOptionType = isset($opt['display_type']) ? strtolower(trim((string)$opt['display_type'])) : '';
        $isExplicitConfig = false;
        $isColorAttr = $this->isColorOption($opt);

        if (in_array($globalType, $validTypes, true)) {
            $configuredType = $globalType;
            $isExplicitConfig = true;
        } elseif (in_array($perOptionType, $validTypes, true)) {
            $configuredType = $perOptionType;
            $isExplicitConfig = true;
        } elseif ($isColorAttr && !empty($flags['color_swatches'])) {
            // Auto-detect color attributes as circular color swatches when Color Swatches is enabled
            $configuredType = 'color';
        } elseif ($isFirstOption) {
            // Default to 'image' for the first variation-forming option by saved position when unconfigured (§7)
            $configuredType = 'image';
        } elseif (!empty($flags['button_selector'])) {
            $configuredType = 'button';
        } else {
            $configuredType = 'dropdown';
        }

        // 2. Apply system toggles: if the configured display type's toggle is disabled, fallback appropriately (§5, §7, §9)
        if (empty($flags['variant_selector_ui'])) {
            $effectiveType = 'dropdown';
        } elseif ($configuredType === 'button') {
            $effectiveType = !empty($flags['button_selector']) ? 'button' : 'dropdown';
        } elseif ($configuredType === 'color') {
            $effectiveType = !empty($flags['color_swatches']) ? 'color' : 'dropdown';
        } elseif ($configuredType === 'image') {
            if (!empty($flags['image_swatches'])) {
                $effectiveType = 'image';
            } elseif (!$isExplicitConfig && !empty($flags['button_selector'])) {
                // Unconfigured (auto) 1st option uses button selector when image_swatches is OFF and button_selector is ON
                $effectiveType = 'button';
            } else {
                $effectiveType = 'dropdown';
            }
        } else {
            $effectiveType = 'dropdown';
        }

        // 3. Resolve help text (Global Settings help_text -> per-option help_text fallback)
        $rawHelpText = '';
        if (isset($globalEntry['help_text']) && trim((string)$globalEntry['help_text']) !== '') {
            $rawHelpText = (string)$globalEntry['help_text'];
        } elseif (isset($opt['help_text']) && trim((string)$opt['help_text']) !== '') {
            $rawHelpText = (string)$opt['help_text'];
        }
        $helpText = sanitize_text_field($rawHelpText);

        $globalValues = [];
        if (isset($globalEntry['values']) && is_array($globalEntry['values'])) {
            $globalValues = $globalEntry['values'];
        }
        if (isset($globalEntry['term_colors']) && is_array($globalEntry['term_colors'])) {
            foreach ($globalEntry['term_colors'] as $tk => $tv) {
                $globalValues[(string)$tk] = $tv;
            }
        }

        return [
            'configured_display_type' => $configuredType,
            'effective_display_type'  => $effectiveType,
            'help_text'               => $helpText,
            'global_values'           => $globalValues,
        ];
    }

    /**
     * Alias for renderSwatchControls matching WooCommerce filter naming.
     */
    public function filterDropdownAttributeOptionsHtml(string $html, array $args): string
    {
        return $this->renderSwatchControls($html, $args);
    }

    /**
     * Render accessible swatches or enhanced dropdown for a variation option.
     * Guarantees only a single interactive control per option is visible.
     */
    public function renderSwatchControls(string $html, array $args): string
    {
        $product = $args['product'] ?? null;
        if (!$product instanceof WC_Product || !$this->isProductManagedByWpe($product->get_id())) {
            return $html;
        }

        $productId = (int)$product->get_id();
        $flags = $this->getProductPageFlags();
        if (!$flags['variant_selector_ui']) {
            return $html;
        }

        $attributeName = (string)($args['attribute'] ?? '');
        $attrLower = strtolower(trim($attributeName));
        $attrSlug = sanitize_title($attributeName);
        $selected = $args['selected'] ?? false;

        $config = $this->getSelectorConfig($productId);
        $matchedOption = null;

        foreach ($config['options'] as $opt) {
            $optKeyLower = strtolower((string)$opt['key']);
            $optNameLower = strtolower((string)$opt['name']);
            $optNameSlug = sanitize_title((string)$opt['name']);
            if (
                $opt['key'] === $attributeName
                || $opt['name'] === $attributeName
                || 'pa_' . $opt['key'] === $attributeName
                || 'attribute_' . $opt['key'] === $attributeName
                || $optKeyLower === $attrLower
                || $optNameLower === $attrLower
                || 'pa_' . $optKeyLower === $attrLower
                || 'attribute_' . $optKeyLower === $attrLower
                || ($attrSlug !== '' && ($optKeyLower === $attrSlug || $optNameSlug === $attrSlug))
            ) {
                $matchedOption = $opt;
                break;
            }
        }

        if (!$matchedOption) {
            return $html;
        }

        $optId = (int)$matchedOption['id'];
        if (($selected === false || $selected === '' || $selected === null) && isset($config['defaultSelection'][(string)$optId])) {
            $selected = (string)$config['defaultSelection'][(string)$optId];
        }
        $swatchType = (string)$matchedOption['swatch_type'];
        $helpText = (string)($matchedOption['help_text'] ?? '');
        $showHelp = !empty($flags['show_help_text']) && $helpText !== '';
        $helpId = sprintf('wpe-help-%d-%d', $productId, $optId);

        $helpHtml = '';
        if ($showHelp) {
            $helpHtml = sprintf(
                '<div id="%s" class="wpe-option-help-text" data-option-id="%d">%s</div>',
                esc_attr($helpId),
                $optId,
                esc_html($helpText)
            );
        }

        $cleanHtml = preg_replace('/(<select\b[^>]*?)\s+class="[^"]*"/i', '$1', $html, 1) ?: $html;

        $selectedValObj = null;
        if ($selected !== false && $selected !== '' && $selected !== null) {
            $selStr = (string)$selected;
            foreach ($matchedOption['values'] ?? [] as $valItem) {
                if (
                    (string)($valItem['id'] ?? '') === $selStr
                    || (string)($valItem['value_key'] ?? '') === $selStr
                    || (string)($valItem['key'] ?? '') === $selStr
                    || strcasecmp((string)($valItem['label'] ?? ''), $selStr) === 0
                ) {
                    $selectedValObj = $valItem;
                    break;
                }
            }
        }
        if ($selectedValObj !== null && stripos($cleanHtml, 'selected') === false) {
            $candidates = array_values(array_unique(array_filter([
                (string)($selectedValObj['value_key'] ?? ''),
                (string)($selectedValObj['key'] ?? ''),
                (string)($selectedValObj['label'] ?? ''),
                (string)($selectedValObj['id'] ?? ''),
            ], static fn(string $s): bool => $s !== '')));
            foreach ($candidates as $cand) {
                $escapedCand = preg_quote(esc_attr($cand), '/');
                $updatedHtml = preg_replace(
                    '/(<option\b[^>]*\bvalue="' . $escapedCand . '"[^>]*)(>)/i',
                    '$1 selected="selected"$2',
                    $cleanHtml,
                    1,
                    $count
                );
                if ($count > 0 && is_string($updatedHtml)) {
                    $cleanHtml = $updatedHtml;
                    break;
                }
            }
        }

        // When effective display type is dropdown, keep only the native <select> visible (no competing swatch group)
        if ($swatchType === 'dropdown') {
            $selectAttrs = sprintf(
                '<select class="wpe-select" data-option-id="%d" data-option-name="%s"%s ',
                $optId,
                esc_attr($matchedOption['name']),
                $showHelp ? sprintf(' aria-describedby="%s"', esc_attr($helpId)) : ''
            );
            $enhancedSelect = preg_replace('/<select\s+/i', $selectAttrs, $cleanHtml, 1) ?: $cleanHtml;
            return $helpHtml . $enhancedSelect;
        }

        $swatchesHtml = $this->buildAccessibleSwatchGroupHtml($matchedOption, (string)$selected, $flags, $productId);

        // Hide native <select> visually and from tab order so only one interactive control exists per attribute
        $hiddenSelectAttrs = sprintf(
            '<select class="wpe-select wpe-sr-only wpe-select-hidden" data-option-id="%d" data-option-name="%s"%s tabindex="-1" aria-hidden="true" ',
            $optId,
            esc_attr($matchedOption['name']),
            $showHelp ? sprintf(' aria-describedby="%s"', esc_attr($helpId)) : ''
        );
        $enhancedSelect = preg_replace('/<select\s+/i', $hiddenSelectAttrs, $cleanHtml, 1) ?: $cleanHtml;

        return $helpHtml . $swatchesHtml . $enhancedSelect;
    }

    /**
     * Build accessible HTML for a swatch group conforming to WCAG 2.1 AA.
     * Radiogroup container + Radio buttons with accessible labels, roving tabindex,
     * contrasting borders for light colors, button fallback for missing hex codes,
     * and thumbnail + value name for image swatches.
     */
    public function buildAccessibleSwatchGroupHtml(
        array $option,
        string $selectedValue = '',
        array $flags = [],
        int $productId = 0
    ): string {
        $optId = (int)$option['id'];
        $optName = esc_attr($option['name']);
        $swatchType = (string)($option['swatch_type'] ?? 'button');
        $helpText = (string)($option['help_text'] ?? '');
        $showHelp = !empty($flags['show_help_text']) && $helpText !== '';
        $markUnavailable = !empty($flags['mark_unavailable_options']);

        $ariaDescribedBy = '';
        if ($showHelp) {
            $helpId = sprintf('wpe-help-%d-%d', $productId, $optId);
            $ariaDescribedBy = sprintf(' aria-describedby="%s"', esc_attr($helpId));
        }

        $out = sprintf(
            '<div class="wpe-swatches wpe-swatches-%s" role="radiogroup" aria-label="%s" data-option-id="%d" data-option-name="%s"%s>',
            esc_attr($swatchType),
            $optName,
            $optId,
            $optName,
            $ariaDescribedBy
        );

        $hasSelection = false;
        foreach ($option['values'] as $val) {
            if ($selectedValue !== '' && ($val['key'] === $selectedValue || (string)$val['id'] === $selectedValue)) {
                $hasSelection = true;
                break;
            }
        }

        $isFirstAvailable = true;
        foreach ($option['values'] as $val) {
            $valId = (int)$val['id'];
            $valKey = esc_attr($val['key']);
            $valLabelRaw = (string)$val['label'];
            $valLabel = esc_attr($valLabelRaw);
            $swatchVal = $val['swatch_value'] ?? ($val['color_hex'] ?? null);
            $thumbData = is_array($val['thumbnail'] ?? null) ? $val['thumbnail'] : null;
            $isAvailable = array_key_exists('available', $val) ? (bool)$val['available'] : true;

            $isChecked = ($selectedValue !== '' && ($val['key'] === $selectedValue || (string)$val['id'] === $selectedValue));
            $tabindex = '-1';
            if ($isChecked) {
                $tabindex = '0';
            } elseif (!$hasSelection && $isAvailable && $isFirstAvailable) {
                $tabindex = '0';
                $isFirstAvailable = false;
            }

            $checkedAttr = $isChecked ? 'true' : 'false';
            $selectedClass = $isChecked ? ' is-selected' : '';

            $ariaDisabled = $isAvailable ? 'false' : 'true';
            $disabledAttr = $isAvailable ? '' : ' disabled';
            $availClass = '';
            if (!$isAvailable) {
                $availClass = ' is-unavailable' . ($markUnavailable ? ' is-marked-unavailable' : ' is-disabled-standard');
            }

            $visual = '';
            $btnTypeClass = 'wpe-swatch-' . esc_attr($swatchType);
            $labelClass = 'wpe-swatch-label';
            $tooltipAttrs = '';

            if ($swatchType === 'color') {
                $validHex = $this->extractValidColorHex(
                    $swatchVal,
                    $val['color_hex'] ?? null,
                    $this->resolvePodColorHex((string)($val['key'] ?? ''), $valLabelRaw)
                );
                if ($validHex !== null) {
                    $lightClass = $this->isLightHexColor($validHex) ? ' is-light-swatch' : '';
                    $visual = sprintf(
                        '<span class="wpe-swatch-color-bubble%s" style="background-color: %s;" aria-hidden="true"></span>',
                        $lightClass,
                        esc_attr($validHex)
                    );
                    $labelClass = 'wpe-swatch-label wpe-sr-only';
                    $tooltipAttrs = sprintf(' title="%s" data-tooltip="%s"', $valLabel, $valLabel);
                } else {
                    // Fallback to named button when color hex is missing (§6)
                    $btnTypeClass = 'wpe-swatch-color wpe-swatch-button wpe-swatch-color-fallback';
                    $labelClass = 'wpe-swatch-label';
                }
            } elseif ($swatchType === 'image') {
                $imgSrc = $thumbData['src'] ?? (is_string($swatchVal) && $swatchVal !== '' ? $swatchVal : $this->assetService->getPlaceholderDataUri(100, 100));
                $imgAlt = esc_attr($thumbData['alt'] ?? $valLabelRaw);
                $imgSource = esc_attr($thumbData['source'] ?? 'variation');
                $allowedProtocols = array_merge(wp_allowed_protocols(), ['data']);
                $visual = sprintf(
                    '<span class="wpe-swatch-image-wrap"><img class="wpe-swatch-image-thumb" src="%s" alt="%s" width="40" height="40" loading="lazy" decoding="async" data-thumb-source="%s" /></span>',
                    esc_url((string)$imgSrc, $allowedProtocols),
                    $imgAlt,
                    $imgSource
                );
                // Image swatch always displays both thumbnail and visible value name (§7)
                $labelClass = 'wpe-swatch-label wpe-swatch-image-label';
            }

            $out .= sprintf(
                '<button type="button" role="radio" aria-checked="%s" aria-disabled="%s" aria-label="%s"%s tabindex="%s" data-option-id="%d" data-value-id="%d" data-value-key="%s" data-value-label="%s" class="wpe-swatch %s%s%s"%s>%s<span class="%s">%s</span></button>',
                $checkedAttr,
                $ariaDisabled,
                $valLabel,
                $tooltipAttrs,
                $tabindex,
                $optId,
                $valId,
                $valKey,
                $valLabel,
                $btnTypeClass,
                $selectedClass,
                $availClass,
                $disabledAttr,
                $visual,
                $labelClass,
                esc_html($valLabelRaw)
            );
        }

        $out .= '</div>';
        return $out;
    }

    /**
     * Detect whether an option represents a color attribute by its key, taxonomy, or label.
     */
    private function isColorOption(array $opt): bool
    {
        $candidates = [
            (string)($opt['key'] ?? ''),
            (string)($opt['taxonomy'] ?? ''),
            (string)($opt['name'] ?? ''),
        ];
        $colorTokens = [
            'color',
            'colour',
            'colors',
            'colours',
            'mau',
            'mau-sac',
            'màu',
            'màu-sắc',
        ];

        foreach ($candidates as $raw) {
            $norm = strtolower(trim($raw));
            if ($norm === '') {
                continue;
            }
            if (str_starts_with($norm, 'attribute_pa_')) {
                $norm = substr($norm, 13);
            } elseif (str_starts_with($norm, 'attribute_')) {
                $norm = substr($norm, 10);
            } elseif (str_starts_with($norm, 'pa_')) {
                $norm = substr($norm, 3);
            }
            $norm = (string)preg_replace('/[\s_]+/u', '-', $norm);
            if (in_array($norm, $colorTokens, true)) {
                return true;
            }
            if (str_ends_with($norm, '-color') || str_ends_with($norm, '-colour')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve popular POD (Print-on-Demand) garment color names/slugs to standard hex codes
     * when explicit option_value.swatch metadata is not populated.
     */
    private function resolvePodColorHex(string $valKey, string $valLabel): ?string
    {
        static $palette = [
            // Blacks & Charcoals
            'black'                => '#111111',
            'jet-black'            => '#0a0a0a',
            'pitch-black'          => '#111111',
            'true-black'           => '#111111',
            'vintage-black'        => '#242424',
            'faded-black'          => '#2d2d2d',
            'washed-black'         => '#2b2b2b',
            'black-heather'        => '#232323',
            'heather-black'        => '#232323',
            'charcoal'             => '#36454f',
            'charcoal-heather'     => '#3f4448',
            'heather-charcoal'     => '#3f4448',
            'dark-grey'            => '#4b5563',
            'dark-gray'            => '#4b5563',
            'dark-heather'         => '#4a4f55',
            'graphite-heather'     => '#54585a',
            'graphite'             => '#4b4e53',
            'asphalt'              => '#3d4144',
            'pepper'               => '#3e3d3b',
            'heavy-metal'          => '#46494c',
            'carbon'               => '#2e3136',

            // Greys & Silvers
            'sport-grey'           => '#9e9e9e',
            'sport-gray'           => '#9e9e9e',
            'athletic-heather'     => '#a6a9aa',
            'grey'                 => '#8e9297',
            'gray'                 => '#8e9297',
            'heather-grey'         => '#9ca3af',
            'heather-gray'         => '#9ca3af',
            'oxford-grey'          => '#8d9093',
            'ash'                  => '#d4d6d2',
            'ash-grey'             => '#d4d6d2',
            'ash-gray'             => '#d4d6d2',
            'ice-grey'             => '#d7d8d6',
            'ice-gray'             => '#d7d8d6',
            'light-grey'           => '#d1d5db',
            'light-gray'           => '#d1d5db',
            'silver'               => '#c0c0c0',

            // Whites, Naturals, Creams & Sands
            'white'                => '#ffffff',
            'pure-white'           => '#ffffff',
            'optic-white'          => '#ffffff',
            'natural'              => '#f5f0e6',
            'cream'                => '#f7f2e7',
            'soft-cream'           => '#f3ead8',
            'ivory'                => '#fffff0',
            'bone'                 => '#e3dac9',
            'off-white'            => '#f8f6f0',
            'cornsilk'             => '#fff8dc',
            'sand'                 => '#d2b48c',
            'tan'                  => '#d2b48c',
            'khaki'                => '#c3b091',
            'desert-dust'          => '#d8c8b8',
            'beige'                => '#d4b996',
            'oatmeal'              => '#dfdad0',

            // Navys, Royals, Blues & Teals
            'navy'                 => '#1b263b',
            'navy-blue'            => '#1b263b',
            'true-navy'            => '#162133',
            'oxford-navy'          => '#1c2841',
            'classic-navy'         => '#1b263b',
            'midnight-navy'        => '#141c2b',
            'dark-navy'            => '#141c2b',
            'french-navy'          => '#1d2951',
            'heather-navy'         => '#3b4963',
            'navy-heather'         => '#3b4963',
            'royal'                => '#1d4ed8',
            'royal-blue'           => '#1d4ed8',
            'true-royal'           => '#1e40af',
            'heather-royal'        => '#3b66bc',
            'blue'                 => '#2563eb',
            'ocean-blue'           => '#0284c7',
            'sapphire'             => '#0f52ba',
            'heather-sapphire'     => '#2b6cb0',
            'indigo'               => '#3f51b5',
            'indigo-blue'          => '#4b6584',
            'slate-blue'           => '#64748b',
            'denim'                => '#3b5998',
            'steel-blue'           => '#4682b4',
            'light-blue'           => '#89cff0',
            'baby-blue'            => '#89cff0',
            'sky'                  => '#7dd3fc',
            'sky-blue'             => '#7dd3fc',
            'carolina-blue'        => '#56a0d3',
            'powder-blue'          => '#b0e0e6',
            'ice-blue'             => '#bae6fd',
            'aqua'                 => '#00bcd4',
            'turquoise'            => '#30d5c8',
            'teal'                 => '#008080',
            'tropical-blue'        => '#008b8b',
            'jade-dome'            => '#008080',
            'deep-teal'            => '#005f6a',
            'heather-deep-teal'    => '#2c6e76',

            // Greens, Sages & Olives
            'dark-sage'            => '#5f6f65',
            'sage'                 => '#7c9082',
            'desert-sage'          => '#87988a',
            'sage-green'           => '#7c9082',
            'heather-sage'         => '#7c9082',
            'military-green'       => '#4b5320',
            'heather-military-green' => '#5a6332',
            'army'                 => '#4b5320',
            'army-green'           => '#4b5320',
            'olive'                => '#556b2f',
            'olive-drab'           => '#6b8e23',
            'moss'                 => '#606c38',
            'forest-green'         => '#1e3f20',
            'forest'               => '#1e3f20',
            'bottle-green'         => '#006a4e',
            'dark-green'           => '#1b4332',
            'hunter-green'         => '#355e3b',
            'pine'                 => '#2a4d3e',
            'irish-green'          => '#169b62',
            'kelly-green'          => '#169b62',
            'green'                => '#169b62',
            'emerald'              => '#047857',
            'mint'                 => '#98dfaf',
            'mint-green'           => '#98dfaf',
            'island-reef'          => '#9fe2bf',
            'seafoam'              => '#71eeb8',
            'kiwi'                 => '#8ee53f',
            'lime'                 => '#84cc16',

            // Reds, Maroons & Burgundies
            'red'                  => '#c93636',
            'cherry-red'           => '#b91c1c',
            'true-red'             => '#dc2626',
            'heather-red'          => '#c04040',
            'scarlet'              => '#b91c1c',
            'cardinal'             => '#8c1d40',
            'cardinal-red'         => '#8c1d40',
            'heather-cardinal'     => '#93324e',
            'crimson'              => '#991b1b',
            'maroon'               => '#631d28',
            'burgundy'             => '#631d28',
            'garnet'               => '#73202c',
            'oxblood'              => '#4a0404',
            'wine'                 => '#581829',
            'merlot'               => '#541e27',
            'rust'                 => '#b7410e',
            'terracotta'           => '#c85a32',
            'plaid'                => '#7f1d1d',

            // Pinks, Berries & Corals
            'light-pink'           => '#f8c8dc',
            'baby-pink'            => '#f8c8dc',
            'blush'                => '#f4c2c2',
            'soft-pink'            => '#f8c8dc',
            'pink'                 => '#ec4899',
            'azalea'               => '#f472b6',
            'rose'                 => '#e11d48',
            'heliconia'            => '#d81b60',
            'hot-pink'             => '#db2777',
            'magenta'              => '#c026d3',
            'berry'                => '#881337',
            'fuchsia'              => '#c026d3',
            'coral'                => '#ff6f61',
            ' coral-silk'          => '#ff7f50',
            'peach'                => '#ffcba4',
            'heather-peach'        => '#f5b895',

            // Purples, Lilacs & Mauves
            'purple'               => '#6b3fa0',
            'team-purple'          => '#5b21b6',
            'heather-purple'       => '#7e57c2',
            'violet'               => '#7c3aed',
            'plum'                 => '#581c87',
            'eggplant'             => '#4a154b',
            'lilac'                => '#c8a2c8',
            'lavender'             => '#b57edc',
            'orchid'               => '#da70d6',
            'mauve'                => '#b784a7',
            'heather-mauve'        => '#b784a7',

            // Yellows, Golds & Oranges
            'gold'                 => '#f59e0b',
            'golden-yellow'        => '#f59e0b',
            'mustard'              => '#d97706',
            'amber'                => '#f59e0b',
            'yellow'               => '#facc15',
            'daisy'                => '#fde047',
            'lemon'                => '#fef08a',
            'sunflower'            => '#eab308',
            'orange'               => '#ea580c',
            'safety-orange'        => '#ff5f00',
            'sunset'               => '#f97316',
            'burnt-orange'         => '#c2410c',
            'heather-orange'       => '#e06c38',

            // Browns
            'brown'                => '#4e342e',
            'dark-chocolate'       => '#3e2723',
            'chocolate'            => '#4e342e',
            'coffee'               => '#5c4033',
            'espresso'             => '#3c2820',
            'cocoa'                => '#6d4c41',
            'chestnut'             => '#7b3f00',
        ];

        foreach ([$valKey, $valLabel] as $raw) {
            $slug = strtolower(trim($raw));
            if ($slug === '') {
                continue;
            }
            if (str_starts_with($slug, 'pa_')) {
                $slug = substr($slug, 3);
            }
            $slug = (string)preg_replace('/[\s_]+/u', '-', $slug);
            if (isset($palette[$slug])) {
                return $palette[$slug];
            }

            // Strip common POD garment modifiers (e.g. "heather-dark-sage" -> "dark-sage")
            $stripped = (string)preg_replace('/^(heather|vintage|faded|washed|solid|triblend|garment-dyed)-/', '', $slug);
            $stripped = (string)preg_replace('/-(heather|marl|triblend)$/', '', $stripped);
            if ($stripped !== '' && isset($palette[$stripped])) {
                return $palette[$stripped];
            }
        }

        return null;
    }

    /**
     * Extract a validated hex color string (#RGB or #RRGGBB) from option value swatch metadata, global config, or POD palette.
     * Returns null when missing or invalid so the value falls back to a named button.
     */
    private function extractValidColorHex(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $hex = null;
            if (is_string($candidate)) {
                $trimmed = trim($candidate);
                if (str_starts_with($trimmed, '{')) {
                    $decoded = json_decode($trimmed, true);
                    if (is_array($decoded)) {
                        $hex = $decoded['color'] ?? ($decoded['hex'] ?? ($decoded['value'] ?? null));
                    }
                } else {
                    $hex = $trimmed;
                }
            } elseif (is_array($candidate)) {
                $hex = $candidate['color'] ?? ($candidate['hex'] ?? ($candidate['value'] ?? null));
            }

            if (is_string($hex)) {
                $hex = trim($hex);
                if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) {
                    return strtolower($hex);
                }
            }
        }

        return null;
    }

    /**
     * Detect if a hex color is light/white (relative luminance > 0.82) so contrasting border is reinforced.
     */
    private function isLightHexColor(string $hex): bool
    {
        $clean = ltrim($hex, '#');
        if (strlen($clean) === 3) {
            $r = hexdec(str_repeat($clean[0], 2));
            $g = hexdec(str_repeat($clean[1], 2));
            $b = hexdec(str_repeat($clean[2], 2));
        } elseif (strlen($clean) === 6) {
            $r = hexdec(substr($clean, 0, 2));
            $g = hexdec(substr($clean, 2, 2));
            $b = hexdec(substr($clean, 4, 2));
        } else {
            return false;
        }

        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance >= 0.82;
    }

    /**
     * Resolve default selection map: { [option_id]: value_id } from wpe_products.default_selection,
     * wpe_options.default_value_id, or WC_Product_Variable::get_default_attributes().
     */
    private function resolveDefaultSelection(int $productId, array $options, ?array $productRow = null): array
    {
        $defaultSelection = [];

        // 1. Check wpe_options.default_value_id
        foreach ($options as $opt) {
            if (!empty($opt['default_value_id'])) {
                $defValId = (int)$opt['default_value_id'];
                foreach ($opt['values'] as $val) {
                    if ((int)$val['id'] === $defValId) {
                        $defaultSelection[(string)$opt['id']] = (string)$defValId;
                        break;
                    }
                }
            }
        }

        // 2. Check wpe_products.default_selection JSON if present
        if (!empty($productRow['default_selection']) && is_string($productRow['default_selection'])) {
            $decoded = json_decode($productRow['default_selection'], true);
            if (is_array($decoded) && !empty($decoded)) {
                foreach ($options as $opt) {
                    $optIdStr = (string)$opt['id'];
                    $optKey = (string)$opt['key'];
                    $rawDef = $decoded[$optIdStr] ?? ($decoded[$optKey] ?? ($decoded['pa_' . $optKey] ?? null));
                    if ($rawDef !== null && $rawDef !== '') {
                        foreach ($opt['values'] as $val) {
                            if ((string)$val['id'] === (string)$rawDef || $val['value_key'] === (string)$rawDef || $val['label'] === (string)$rawDef) {
                                $defaultSelection[$optIdStr] = (string)$val['id'];
                                break;
                            }
                        }
                    }
                }
            }
        }

        // 3. Fallback to WC_Product_Variable::get_default_attributes() if available
        if (empty($defaultSelection) && function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product instanceof WC_Product_Variable) {
                $defaults = $product->get_default_attributes();
                if (!empty($defaults)) {
                    foreach ($options as $opt) {
                        $key = (string)$opt['key'];
                        $paKey = str_starts_with($key, 'pa_') ? $key : 'pa_' . $key;
                        $strippedKey = str_starts_with($key, 'pa_') ? substr($key, 3) : $key;
                        $defVal = $defaults[$key] ?? ($defaults[$paKey] ?? ($defaults[$strippedKey] ?? null));
                        if ($defVal !== null) {
                            foreach ($opt['values'] as $val) {
                                $vKey = (string)($val['value_key'] ?? ($val['key'] ?? ''));
                                if ($vKey === (string)$defVal || (string)$val['label'] === (string)$defVal || (string)$val['id'] === (string)$defVal) {
                                    $defaultSelection[(string)$opt['id']] = (string)$val['id'];
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $defaultSelection;
    }

    /**
     * Build a lightweight versioned cache key for a product's lowest purchasable price state.
     * Consolidates product, variant price/sale, and inventory state into a single SQL query.
     */
    private function getLowestPriceCacheKey(int $productId): string
    {
        $globalVer = (int)get_option('wpe_selector_cache_ver', 1);
        $flagsJson = wp_json_encode($this->getProductPageFlags());

        $stamp = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                p.version,
                p.updated_at,
                (SELECT CONCAT_WS(':', COUNT(*), COALESCE(MAX(v.version), 0), COALESCE(SUM(COALESCE(v.regular_price, 0) + COALESCE(v.sale_price, 0)), 0), COALESCE(GROUP_CONCAT(CONCAT_WS(':', v.id, COALESCE(v.regular_price, 'null'), COALESCE(v.sale_price, 'null'), COALESCE(v.sale_from, ''), COALESCE(v.sale_to, '')) ORDER BY v.id SEPARATOR '|'), ''))
                 FROM {$this->tableVariants} v
                 WHERE v.product_id = %d AND v.status = 'publish' AND v.deleted_at IS NULL) AS var_sig,
                (SELECT GROUP_CONCAT(CONCAT_WS(':', i.owner_id, i.manage_stock, COALESCE(i.quantity, 'null'), i.stock_status, i.backorders) ORDER BY i.owner_id SEPARATOR '|')
                 FROM {$this->tableInventory} i
                 WHERE i.owner_id = %d OR i.owner_id IN (SELECT v2.id FROM {$this->tableVariants} v2 WHERE v2.product_id = %d)) AS inv_sig
             FROM (SELECT %d AS target_id) q
             LEFT JOIN {$this->tableProducts} p ON p.id = q.target_id AND p.deleted_at IS NULL
             LIMIT 1",
            $productId,
            $productId,
            $productId,
            $productId
        ), ARRAY_A);

        $raw = implode('||', [
            (string)$productId,
            (string)$globalVer,
            (string)$flagsJson,
            wp_json_encode($stamp),
        ]);

        return $productId . ':lp:' . md5($raw);
    }

    /**
     * Build a deterministic versioned cache key for a product's selector config and price state.
     * Consolidates all 6 table signatures into a single SQL round-trip while also priming lastProductRow.
     */
    private function getProductCacheKey(int $productId): string
    {
        $globalVer = (int)get_option('wpe_selector_cache_ver', 1);
        $flagsJson = wp_json_encode($this->getProductPageFlags());
        $attrCfgJson = wp_json_encode($this->getAttributeDisplayConfig());

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                p.id,
                p.title,
                p.version,
                p.status,
                p.type,
                p.updated_at,
                p.default_selection,
                p.extra_props,
                (SELECT CONCAT_WS(':', COUNT(*), COALESCE(MAX(v.version), 0), COALESCE(SUM(COALESCE(v.regular_price, 0) + COALESCE(v.sale_price, 0)), 0))
                 FROM {$this->tableVariants} v
                 WHERE v.product_id = %d AND v.status = 'publish' AND v.deleted_at IS NULL) AS var_sig,
                (SELECT CONCAT_WS('#', COUNT(o.id), COALESCE(GROUP_CONCAT(CONCAT_WS(':', o.id, o.position, COALESCE(o.default_value_id, '')) ORDER BY o.id SEPARATOR '|'), ''))
                 FROM {$this->tableOptions} o
                 WHERE o.product_id = %d) AS opt_sig,
                (SELECT GROUP_CONCAT(CONCAT_WS(':', ov.id, ov.label, COALESCE(ov.swatch, '')) ORDER BY ov.id SEPARATOR '|')
                 FROM {$this->tableOptionValues} ov
                 JOIN {$this->tableOptions} o2 ON o2.id = ov.option_id
                 WHERE o2.product_id = %d) AS val_sig,
                (SELECT GROUP_CONCAT(CONCAT_WS(':', i.owner_id, i.manage_stock, COALESCE(i.quantity, 'null'), i.stock_status, i.backorders) ORDER BY i.owner_id SEPARATOR '|')
                 FROM {$this->tableInventory} i
                 WHERE i.owner_id = %d OR i.owner_id IN (SELECT v2.id FROM {$this->tableVariants} v2 WHERE v2.product_id = %d)) AS inv_sig,
                (SELECT GROUP_CONCAT(CONCAT_WS(':', pm.asset_id, pm.role, pm.position) ORDER BY pm.position SEPARATOR ',')
                 FROM {$this->tableProductMedia} pm
                 WHERE pm.product_id = %d) AS pm_sig,
                (SELECT GROUP_CONCAT(CONCAT_WS(':', vm.variant_id, vm.asset_id, vm.is_primary) ORDER BY vm.variant_id, vm.position SEPARATOR ',')
                 FROM {$this->tableVariantMedia} vm
                 JOIN {$this->tableVariants} v3 ON v3.id = vm.variant_id
                 WHERE v3.product_id = %d) AS vm_sig
             FROM (SELECT %d AS target_id) q
             LEFT JOIN {$this->tableProducts} p ON p.id = q.target_id AND p.deleted_at IS NULL
             LIMIT 1",
            $productId,
            $productId,
            $productId,
            $productId,
            $productId,
            $productId,
            $productId,
            $productId
        ), ARRAY_A);

        if (is_array($row) && !empty($row['id'])) {
            $this->lastProductRow[$productId] = [
                'id'                => $row['id'],
                'title'             => $row['title'],
                'version'           => $row['version'],
                'status'            => $row['status'],
                'type'              => $row['type'],
                'updated_at'        => $row['updated_at'],
                'default_selection' => $row['default_selection'],
                'extra_props'       => $row['extra_props'],
            ];
        } else {
            $this->lastProductRow[$productId] = null;
        }

        $raw = implode('||', [
            (string)$productId,
            (string)$globalVer,
            (string)$flagsJson,
            (string)$attrCfgJson,
            wp_json_encode($this->lastProductRow[$productId]),
            (string)($row['var_sig'] ?? ''),
            (string)($row['opt_sig'] ?? ''),
            (string)($row['val_sig'] ?? ''),
            (string)($row['inv_sig'] ?? ''),
            (string)($row['pm_sig'] ?? '') . '#' . (string)($row['vm_sig'] ?? ''),
        ]);

        return $productId . ':' . md5($raw);
    }

    private function unwrapOuterPriceSpan(string $priceHtml): string
    {
        $trimmed = trim($priceHtml);
        if (preg_match('/^<span\s+class=["\']price["\']>(.*)<\/span>$/is', $trimmed, $m)) {
            return $m[1];
        }
        return $trimmed;
    }

    /**
     * WooCommerce Blocks Store API extension hook.
     */
    public function registerStoreApiIntegration(): void
    {
        if (function_exists('woocommerce_store_api_register_endpoint_data')) {
            woocommerce_store_api_register_endpoint_data([
                'endpoint' => 'products',
                'namespace' => 'wpe',
                'data_callback' => function ($product) {
                    if (!$product instanceof WC_Product || !$this->isProductManagedByWpe($product->get_id())) {
                        return [];
                    }
                    return $this->getSelectorConfig($product->get_id());
                },
                'schema_callback' => function () {
                    return [
                        'productId'       => ['type' => 'integer'],
                        'resolveEndpoint' => ['type' => 'string'],
                        'productRevision' => ['type' => 'string'],
                        'flags'           => ['type' => 'object'],
                        'options'         => ['type' => 'array'],
                        'lowestPriceHtml' => ['type' => 'string'],
                    ];
                },
            ]);
        }
    }
}
