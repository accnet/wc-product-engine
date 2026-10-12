<?php

namespace WPE\Compatibility\StoreApi;

use RuntimeException;
use InvalidArgumentException;
use WC_Product;
use WC_Product_Variable;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\GalleryAdapter;

/**
 * Variant Selector UI, Swatches, and Asynchronous Store API / Classic Adapter.
 *
 * Responsibilities:
 * - Enqueue accessible variant selector scripts/styles strictly on PDP for variable products (R108).
 * - Render accessible swatch controls (color, image, button, select) with keyboard navigation and screen-reader support (R108, AC-T27-01).
 * - Ensure color is never the sole visual signal by providing text labels and aria-labels (R108, AC-T27-01).
 * - Enforce large variant payload budget by capping woocommerce_ajax_variation_threshold to 0 for WPE products,
 *   preventing embedding of 10,000 variations in HTML/JSON attributes (R110, AC-T27-02).
 * - Support reset to default selection and restoration of parent gallery/price (R109).
 * - Expose selector configuration for WooCommerce Blocks and Store API consumers (AC-T27-02).
 */
class SelectorAdapter
{
    private static ?SelectorAdapter $instance = null;
    private static bool $registered = false;

    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private ?GalleryAdapter $galleryAdapter;

    private string $tableProducts;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariants;

    public function __construct(
        ?\wpdb $wpdb = null,
        ?OptionsRepository $optionsRepo = null,
        ?GalleryAdapter $galleryAdapter = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->galleryAdapter = $galleryAdapter;

        $prefix = $this->wpdb->prefix;
        $this->tableProducts     = $prefix . 'wpe_products';
        $this->tableOptions      = $prefix . 'wpe_options';
        $this->tableOptionValues = $prefix . 'wpe_option_values';
        $this->tableVariants     = $prefix . 'wpe_variants';
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

        if (self::$registered) {
            return $instance;
        }

        // 1. Enqueue scripts and styles only on PDP (R108)
        add_action('wp_enqueue_scripts', [$instance, 'enqueueScripts']);

        // 2. Prevent WooCommerce from dumping 10k variations into data-product_variations HTML attribute (R110, AC-T27-02)
        add_filter('woocommerce_ajax_variation_threshold', [$instance, 'filterVariationThreshold'], 20, 2);

        // 3. Render accessible swatches enhancing/alongside native variation dropdowns (R108, AC-T27-01)
        add_filter('woocommerce_dropdown_variation_attribute_options_html', [$instance, 'renderSwatchControls'], 10, 2);

        // 4. Store API and Blocks integration
        add_action('woocommerce_blocks_loaded', [$instance, 'registerStoreApiIntegration']);

        self::$registered = true;
        return $instance;
    }

    /**
     * Check if a product is managed by WPE.
     */
    public function isProductManagedByWpe(int $productId): bool
    {
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        return $count > 0;
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
            // For WPE variable products, force threshold to 0 so data-product_variations="false"
            return 0;
        }

        return $threshold;
    }

    /**
     * Enqueue scripts and styles strictly on PDP for variable products.
     */
    public function enqueueScripts(): void
    {
        // Guard: Enqueue only on single product page (is_product())
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
     * Get complete selector configuration for frontend client or Store API consumer.
     */
    public function getSelectorConfig(int $productId): array
    {
        $productRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, title, version, status, type FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);

        $version = $productRow ? (string)$productRow['version'] : '1';

        $rawOptions = $this->optionsRepo->getOptionsByProductId($productId);
        $formattedOptions = [];

        foreach ($rawOptions as $opt) {
            $optId = (int)$opt['id'];
            $optKey = (string)($opt['key'] ?? ($opt['option_key'] ?? ''));
            $optName = (string)($opt['name'] ?? $optKey);

            $swatchType = $this->detectSwatchType($optKey, $optName, $productId);

            $values = [];
            foreach ($opt['values'] as $val) {
                $valId = (int)$val['id'];
                $valKey = (string)$val['value_key'];
                $valLabel = (string)$val['label'];

                $swatchValue = $this->resolveSwatchValue($swatchType, $optKey, $valKey, $productId);

                $values[] = [
                    'id' => $valId,
                    'key' => $valKey,
                    'label' => $valLabel,
                    'swatch_value' => $swatchValue,
                ];
            }

            $formattedOptions[] = [
                'id' => $optId,
                'key' => $optKey,
                'name' => $optName,
                'position' => (int)($opt['position'] ?? 0),
                'swatch_type' => $swatchType,
                'values' => $values,
            ];
        }

        // Resolve default selection if present
        $defaultSelection = $this->resolveDefaultSelection($productId, $formattedOptions);

        $resolveEndpoint = rest_url("wpe/v1/products/{$productId}/resolve");
        $wcAjaxUrl = class_exists('\WC_AJAX') ? \WC_AJAX::get_endpoint('get_variation') : admin_url('admin-ajax.php?action=woocommerce_get_variation');

        return [
            'productId' => $productId,
            'resolveEndpoint' => $resolveEndpoint,
            'wcAjaxUrl' => $wcAjaxUrl,
            'nonce' => wp_create_nonce('wp_rest'),
            'productRevision' => $version,
            'options' => $formattedOptions,
            'defaultSelection' => $defaultSelection,
            'i18n' => [
                'unavailable' => __('This combination is unavailable', 'wc-product-engine'),
                'ambiguous' => __('Selection cannot be resolved', 'wc-product-engine'),
                'loading' => __('Checking availability...', 'wc-product-engine'),
                'reset' => __('Clear selection', 'wc-product-engine'),
                'inStock' => __('In stock', 'wc-product-engine'),
                'outOfStock' => __('Out of stock', 'wc-product-engine'),
                'selectOption' => __('Choose an option', 'wc-product-engine'),
            ],
        ];
    }

    /**
     * Render accessible swatches HTML enhancing WooCommerce dropdown.
     */
    public function renderSwatchControls(string $html, array $args): string
    {
        $product = $args['product'] ?? null;
        if (!$product instanceof WC_Product || !$this->isProductManagedByWpe($product->get_id())) {
            return $html;
        }

        $attributeName = $args['attribute'] ?? '';
        $options = $args['options'] ?? [];
        $selected = $args['selected'] ?? false;
        $productId = $product->get_id();

        $config = $this->getSelectorConfig($productId);
        $matchedOption = null;

        foreach ($config['options'] as $opt) {
            if ($opt['key'] === $attributeName || $opt['name'] === $attributeName || 'pa_' . $opt['key'] === $attributeName) {
                $matchedOption = $opt;
                break;
            }
        }

        if (!$matchedOption) {
            return $html;
        }

        $swatchesHtml = $this->buildAccessibleSwatchGroupHtml($matchedOption, (string)$selected);

        // Prepend swatches before dropdown and add wpe-select class to dropdown for sync
        $enhancedSelect = str_replace('<select ', '<select class="wpe-select wpe-sr-only" ', $html);

        return $swatchesHtml . $enhancedSelect;
    }

    /**
     * Build accessible HTML for a swatch group conforming to WCAG 2.1 AA.
     * Radiogroup container + Radio buttons with accessible labels, roving tabindex.
     */
    public function buildAccessibleSwatchGroupHtml(array $option, string $selectedValue = ''): string
    {
        $optId = $option['id'];
        $optName = esc_attr($option['name']);
        $swatchType = $option['swatch_type'];

        $out = sprintf(
            '<div class="wpe-swatches wpe-swatches-%s" role="radiogroup" aria-label="%s" data-option-id="%d">',
            esc_attr($swatchType),
            $optName,
            $optId
        );

        $hasSelection = false;
        foreach ($option['values'] as $val) {
            if ($selectedValue !== '' && ($val['key'] === $selectedValue || (string)$val['id'] === $selectedValue)) {
                $hasSelection = true;
                break;
            }
        }

        $isFirst = true;
        foreach ($option['values'] as $val) {
            $valId = (int)$val['id'];
            $valKey = esc_attr($val['key']);
            $valLabel = esc_attr($val['label']);
            $swatchVal = $val['swatch_value'];

            $isChecked = ($selectedValue !== '' && ($val['key'] === $selectedValue || (string)$val['id'] === $selectedValue));
            $tabindex = $isChecked ? '0' : (!$hasSelection && $isFirst ? '0' : '-1');

            $checkedAttr = $isChecked ? 'true' : 'false';
            $selectedClass = $isChecked ? ' is-selected' : '';

            $visual = '';
            if ($swatchType === 'color' && !empty($swatchVal)) {
                $visual = sprintf('<span class="wpe-swatch-color-bubble" style="background-color: %s;"></span>', esc_attr($swatchVal));
            } elseif ($swatchType === 'image' && !empty($swatchVal)) {
                $visual = sprintf('<img src="%s" alt="%s" width="32" height="32" />', esc_url($swatchVal), $valLabel);
            }

            // Accessible text label: ALWAYS present (R108, AC-T27-01: color is NOT sole signal)
            $labelClass = ($swatchType === 'color' || $swatchType === 'image') ? 'wpe-swatch-label wpe-sr-only' : 'wpe-swatch-label';

            $out .= sprintf(
                '<button type="button" role="radio" aria-checked="%s" aria-disabled="false" aria-label="%s" tabindex="%s" data-option-id="%d" data-value-id="%d" data-value-key="%s" class="wpe-swatch wpe-swatch-%s%s">%s<span class="%s">%s</span></button>',
                $checkedAttr,
                $valLabel,
                $tabindex,
                $optId,
                $valId,
                $valKey,
                esc_attr($swatchType),
                $selectedClass,
                $visual,
                $labelClass,
                esc_html($val['label'])
            );

            $isFirst = false;
        }

        $out .= '</div>';
        return $out;
    }

    /**
     * Detect swatch display type: 'color', 'image', 'button', or 'select'.
     */
    private function detectSwatchType(string $optKey, string $optName, int $productId): string
    {
        $normalized = strtolower($optKey . ' ' . $optName);

        // Check if color
        if (str_contains($normalized, 'color') || str_contains($normalized, 'màu')) {
            return 'color';
        }

        // Check if image/pattern/style
        if (str_contains($normalized, 'pattern') || str_contains($normalized, 'texture')) {
            return 'image';
        }

        // Default to accessible button swatches for discrete options (size, edition, etc.)
        return 'button';
    }

    /**
     * Resolve swatch visual value (hex color code or image URL).
     */
    private function resolveSwatchValue(string $swatchType, string $optKey, string $valKey, int $productId): ?string
    {
        if ($swatchType === 'color') {
            // Check term meta or standard hex mappings
            $colors = [
                'black' => '#000000',
                'white' => '#ffffff',
                'red' => '#e53935',
                'blue' => '#1e88e5',
                'green' => '#43a047',
                'yellow' => '#fdd835',
                'grey' => '#757575',
                'gray' => '#757575',
                'orange' => '#fb8c00',
                'purple' => '#8e24aa',
                'pink' => '#d81b60',
                'navy' => '#001f3f',
            ];
            $lower = strtolower($valKey);
            return $colors[$lower] ?? '#cccccc';
        }

        return null;
    }

    /**
     * Resolve default selection map: { [option_id]: value_id }
     */
    private function resolveDefaultSelection(int $productId, array $options): array
    {
        $defaultSelection = [];

        // Try reading default attributes from WC_Product if available
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product instanceof WC_Product_Variable) {
                $defaults = $product->get_default_attributes();
                if (!empty($defaults)) {
                    foreach ($options as $opt) {
                        $key = $opt['key'];
                        $paKey = 'pa_' . $key;
                        $defVal = $defaults[$key] ?? ($defaults[$paKey] ?? null);
                        if ($defVal !== null) {
                            foreach ($opt['values'] as $val) {
                                if ($val['key'] === $defVal || $val['label'] === $defVal) {
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
                        'productId' => ['type' => 'integer'],
                        'resolveEndpoint' => ['type' => 'string'],
                        'productRevision' => ['type' => 'string'],
                        'options' => ['type' => 'array'],
                    ];
                },
            ]);
        }
    }
}
