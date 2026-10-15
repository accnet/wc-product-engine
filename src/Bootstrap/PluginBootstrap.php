<?php

namespace WPE\Bootstrap;

class PluginBootstrap
{
    private static ?PluginBootstrap $instance = null;
    private array $flags = [];
    private bool $dependencies_satisfied = false;
    private array $dependency_errors = [];

    public const OPTION_FLAGS = 'wpe_feature_flags';
    public const OPTION_ROUTING = 'wpe_cohort_routing';
    public const OPTION_ATTR_DISPLAY = 'wpe_attribute_display_config';
    public const OPTION_PRODUCT_PAGE_VER = 'wpe_product_page_settings_ver';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->loadFlags();
        if (function_exists('WC') || did_action('plugins_loaded')) {
            $this->checkDependencies();
        }
    }

    public function init(): void
    {
        if (did_action('plugins_loaded')) {
            $this->onPluginsLoaded();
        } else {
            add_action('plugins_loaded', [$this, 'onPluginsLoaded'], 20);
        }
        $this->registerLifecycleHooks();

        if (defined('WP_CLI') && WP_CLI) {
            $this->registerCliCommands();
        }
    }

    public function onPluginsLoaded(): void
    {
        $this->checkDependencies();

        if (!$this->dependencies_satisfied) {
            add_action('admin_notices', [$this, 'renderDependencyNotices']);
            return;
        }

        $this->registerCapabilities();
        $this->registerDataStores();
        $this->registerRestRoutes();
        $opsPage = \WPE\Admin\OperationsPage::getInstance();
        $opsPage->init();
        remove_action('admin_menu', [$opsPage, 'registerAdminMenu']);
        remove_action('admin_enqueue_scripts', [$opsPage, 'enqueueAssets']);
        \WPE\Admin\ProductEditor\ProductEditorBridge::getInstance()->init();

        add_action('admin_menu', [$this, 'redirectLegacyOperationsPage'], 1);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        \WPE\Compatibility\Gallery\AttachmentBridge::register();
        \WPE\Compatibility\Gallery\GalleryAdapter::register();
        \WPE\Compatibility\WooDataStore\PostShellBridge::getInstance()->boot();
        \WPE\Application\Variant\ResolverApi::register();
        \WPE\Compatibility\StoreApi\SelectorAdapter::register();
    }

    public function checkDependencies(): bool
    {
        $this->dependency_errors = [];

        // 1. PHP Version
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $this->dependency_errors[] = sprintf(
                'WC Product Engine requires PHP 8.3.0 or higher. Current version: %s',
                PHP_VERSION
            );
        }

        // 2. WooCommerce Dependency
        if (!class_exists('WooCommerce') && !function_exists('WC')) {
            $this->dependency_errors[] = 'WC Product Engine requires WooCommerce to be installed and active.';
        } elseif (defined('WC_VERSION') && version_compare(WC_VERSION, '11.0.0', '<')) {
            $this->dependency_errors[] = sprintf(
                'WC Product Engine requires WooCommerce 11.0.0 or higher. Current version: %s',
                WC_VERSION
            );
        }

        $this->dependencies_satisfied = empty($this->dependency_errors);
        return $this->dependencies_satisfied;
    }

    public function areDependenciesSatisfied(): bool
    {
        return $this->dependencies_satisfied;
    }

    public function getDependencyErrors(): array
    {
        return $this->dependency_errors;
    }

    public function renderDependencyNotices(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        foreach ($this->dependency_errors as $error) {
            printf(
                '<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
                esc_html__('WC Product Engine Warning', 'wc-product-engine'),
                esc_html($error)
            );
        }
    }

    public function loadFlags(): void
    {
        $defaults = [
            'variants_ui_enabled'      => true,
            'query_enabled'            => false,
            'media_enabled'            => false,
            'resolver_enabled'         => false,
            'native_writes_enabled'    => false,
            'single_price_display'     => false,
            'show_selected_value'      => false,
            'show_help_text'           => false,
            'button_selector'          => false,
            'color_swatches'           => false,
            'image_swatches'           => false,
            'mark_unavailable_options' => false,
            'cohort_mode'              => 'legacy', // 'legacy' | 'cohort_canary' | 'full_native'
        ];

        $saved = get_option(self::OPTION_FLAGS, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $this->flags = array_merge($defaults, $saved);
    }

    public function getFlags(): array
    {
        return $this->flags;
    }

    public function isFlagEnabled(string $flag): bool
    {
        // If dependencies not satisfied, native features are strictly disabled
        if (!$this->dependencies_satisfied && $flag !== 'legacy') {
            return false;
        }
        return (bool)($this->flags[$flag] ?? false);
    }

    public function setFlag(string $flag, bool $value): void
    {
        $this->flags[$flag] = $value;
        update_option(self::OPTION_FLAGS, $this->flags);
        $this->bumpProductPageSettingsVersion();
    }

    /**
     * Global attribute display configuration keyed by WooCommerce attribute_id or opt_<option_key>.
     *
     * @return array<int|string, array{display_type: string, help_text: string, term_colors: array<int|string, string>}>
     */
    public function getAttributeDisplayConfig(): array
    {
        $raw = get_option(self::OPTION_ATTR_DISPLAY, []);
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $attrId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (is_numeric($attrId) && (int)$attrId > 0) {
                $id = (int)$attrId;
            } elseif (is_string($attrId) && preg_match('/^opt_[a-z0-9_-]{1,64}$/', $attrId)) {
                $id = $attrId;
            } else {
                continue;
            }
            $type = (string)($entry['display_type'] ?? 'auto');
            if (!in_array($type, ['auto', 'dropdown', 'button', 'color', 'image'], true)) {
                $type = 'auto';
            }
            $helpText = sanitize_text_field((string)($entry['help_text'] ?? ''));
            $colors = [];
            if (isset($entry['term_colors']) && is_array($entry['term_colors'])) {
                foreach ($entry['term_colors'] as $termKey => $hex) {
                    $hexStr = strtolower(trim((string)$hex));
                    if (!preg_match('/^#[0-9a-f]{6}$/', $hexStr)) {
                        continue;
                    }
                    if (is_numeric($termKey) && (int)$termKey > 0) {
                        $colors[(int)$termKey] = $hexStr;
                    } elseif (is_string($termKey) && preg_match('/^[a-z0-9_-]{1,64}$/', $termKey)) {
                        $colors[$termKey] = $hexStr;
                    }
                }
            }
            $normalized[$id] = [
                'display_type' => $type,
                'help_text'    => $helpText,
                'term_colors'  => $colors,
            ];
        }

        return $normalized;
    }

    /**
     * Merge and persist global attribute display configuration.
     *
     * @param array<int|string, array{display_type: string, help_text: string, term_colors: array<int|string, string>}> $updates
     */
    public function saveAttributeDisplayConfig(array $updates): void
    {
        $current = $this->getAttributeDisplayConfig();
        foreach ($updates as $attrId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (is_numeric($attrId) && (int)$attrId > 0) {
                $id = (int)$attrId;
            } elseif (is_string($attrId) && preg_match('/^opt_[a-z0-9_-]{1,64}$/', $attrId)) {
                $id = $attrId;
            } else {
                continue;
            }
            $current[$id] = [
                'display_type' => (string)($entry['display_type'] ?? 'auto'),
                'help_text'    => (string)($entry['help_text'] ?? ''),
                'term_colors'  => is_array($entry['term_colors'] ?? null) ? $entry['term_colors'] : [],
            ];
        }
        update_option(self::OPTION_ATTR_DISPLAY, $current);
        $this->bumpProductPageSettingsVersion();
    }

    public function getProductPageSettingsVersion(): int
    {
        $ver = (int)get_option(self::OPTION_PRODUCT_PAGE_VER, 1);
        return $ver > 0 ? $ver : 1;
    }

    public function bumpProductPageSettingsVersion(): int
    {
        $next = $this->getProductPageSettingsVersion() + 1;
        update_option(self::OPTION_PRODUCT_PAGE_VER, $next);
        return $next;
    }

    /**
     * Resolves product authority with deterministic precedence:
     * 1. Exact Product ID rule in cohort routing table
     * 2. Category cohort rule
     * 3. Default cohort mode from flags
     */
    public function resolveProductAuthority(int $product_id): string
    {
        if (!$this->dependencies_satisfied) {
            return 'legacy';
        }

        $routing = get_option(self::OPTION_ROUTING, []);
        if (is_array($routing)) {
            // Rule 1: Exact product ID match
            if (isset($routing['products'][$product_id])) {
                return (string)$routing['products'][$product_id];
            }

            // Rule 2: Category cohort match
            if (!empty($routing['categories']) && function_exists('wc_get_product_term_ids')) {
                $term_ids = wc_get_product_term_ids($product_id, 'product_cat');
                foreach ($term_ids as $tid) {
                    if (isset($routing['categories'][$tid])) {
                        return (string)$routing['categories'][$tid];
                    }
                }
            }
        }

        // Rule 3: Default cohort mode
        return $this->flags['cohort_mode'] ?? 'legacy';
    }

    public function registerCapabilities(): void
    {
        $caps = [
            'wpe_import_products',
            'wpe_export_products',
            'wpe_manage_operations',
        ];

        $role = get_role('administrator');
        if ($role) {
            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    public function registerDataStores(): void
    {
        // Register Data Store filter only if native writes or query is active
        add_filter('woocommerce_data_stores', [$this, 'filterDataStores']);
    }

    public function filterDataStores(array $stores): array
    {
        // When native mode is off, keep standard WooCommerce data stores
        if (!$this->isFlagEnabled('native_writes_enabled')) {
            return $stores;
        }

        // Future tasks (T10) attach native data store classes here
        return $stores;
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            __('WC Product Engine', 'wc-product-engine'),
            __('WC Product Engine', 'wc-product-engine'),
            'manage_woocommerce',
            'wc-product-engine',
            [\WPE\Admin\SettingsPage::class, 'render'],
            'dashicons-performance',
            56
        );

        add_submenu_page(
            'wc-product-engine',
            __('Settings', 'wc-product-engine'),
            __('Settings', 'wc-product-engine'),
            'manage_woocommerce',
            'wc-product-engine',
            [\WPE\Admin\SettingsPage::class, 'render']
        );

        add_submenu_page(
            'wc-product-engine',
            __('Shopify CSV Import', 'wc-product-engine'),
            __('Shopify CSV Import', 'wc-product-engine'),
            'wpe_import_products',
            'wpe-shopify-import',
            [\WPE\Admin\ShopifyImportPage::class, 'render']
        );
    }

    public function redirectLegacyOperationsPage(): void
    {
        if (!is_admin()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string)$_GET['page'])) : '';
        if ($page === 'wpe-operations') {
            wp_safe_redirect(\WPE\Admin\SettingsPage::tabUrl('operations'));
            exit;
        }
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if (strpos($hook, 'wc-product-engine') === false && strpos($hook, 'wpe-shopify-import') === false) {
            return;
        }

        $cssUrl = plugins_url('assets/admin/admin.css', dirname(__DIR__, 1));
        wp_enqueue_style('wpe-admin-css', $cssUrl, [], '1.2.0');

        if (strpos($hook, 'wpe-shopify-import') !== false) {
            $jsUrl = plugins_url('assets/admin/shopify-import.js', dirname(__DIR__, 1));
            wp_enqueue_script('wpe-shopify-import-js', $jsUrl, ['jquery'], '1.2.0', true);
            wp_localize_script('wpe-shopify-import-js', 'wpeImportConfig', [
                'restUrl' => esc_url_raw(rest_url('wpe/v1/admin/')),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]);
            return;
        }

        // Settings assets belong to the Settings screen only (the top-level page).
        if ($hook !== 'toplevel_page_wc-product-engine') {
            return;
        }

        $assetDir = WPE_PLUGIN_DIR . 'assets/admin/';
        $cssVer = (string)(@filemtime($assetDir . 'settings.css') ?: '1.2.0');
        $jsVer = (string)(@filemtime($assetDir . 'settings.js') ?: '1.2.0');

        wp_enqueue_style(
            'wpe-settings-css',
            plugins_url('assets/admin/settings.css', dirname(__DIR__, 1)),
            ['wpe-admin-css'],
            $cssVer
        );
        wp_enqueue_script(
            'wpe-settings-js',
            plugins_url('assets/admin/settings.js', dirname(__DIR__, 1)),
            ['jquery'],
            $jsVer,
            true
        );
        wp_localize_script('wpe-settings-js', 'wpeAdminConfig', [
            'restUrl'    => esc_url_raw(rest_url('wpe/v1/admin/')),
            'opsRestUrl' => esc_url_raw(rest_url('wpe/v1/operations/')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'canManageOps' => current_user_can('wpe_manage_operations') || current_user_can('manage_woocommerce'),
            'i18n'       => [
                /* translators: %d: number of changed settings */
                'unsavedOne'      => __('%d unsaved change', 'wc-product-engine'),
                /* translators: %d: number of changed settings */
                'unsavedMany'     => __('%d unsaved changes', 'wc-product-engine'),
                'saving'          => __('Saving…', 'wc-product-engine'),
                'saveLabel'       => __('Save changes', 'wc-product-engine'),
                'saved'           => __('Settings saved.', 'wc-product-engine'),
                'saveFailed'      => __('Settings could not be saved.', 'wc-product-engine'),
                'discarded'       => __('Changes discarded.', 'wc-product-engine'),
                'rebuilding'      => __('Rebuilding catalog index…', 'wc-product-engine'),
                'rebuilt'         => __('Catalog index rebuilt.', 'wc-product-engine'),
                'rebuildFailed'   => __('Rebuild failed.', 'wc-product-engine'),
                'confirmRebuild'  => __('Rebuild the catalog index now?', 'wc-product-engine'),
                'opsRunning'      => __('Processing operation: %s…', 'wc-product-engine'),
                'opsFailed'       => __('Operation failed.', 'wc-product-engine'),
                'confirmRollback' => __('WARNING: Are you sure you want to initiate an Authority Rollback back to Legacy CPT? Current native catalog data will be reconciled before switching.', 'wc-product-engine'),
                'resumeLabel'     => __('Resume', 'wc-product-engine'),
                'noCheckpoints'   => __('No recent background jobs recorded.', 'wc-product-engine'),
            ],
        ]);
    }

    public function registerRestRoutes(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('wpe/v1', '/status', [
                'methods' => 'GET',
                'callback' => [$this, 'getRestStatus'],
                'permission_callback' => function () {
                    return current_user_can('manage_woocommerce') || current_user_can('wpe_manage_operations');
                },
            ]);
            \WPE\Application\ImportExport\CsvStager::getInstance()->registerRestRoutes();
            \WPE\Admin\Rest\AdminApiController::getInstance()->registerRoutes();
            \WPE\Admin\ProductEditor\ProductEditorApiController::getInstance()->registerRoutes();
            \WPE\Admin\ProductEditor\ProductMediaApiController::getInstance()->registerRoutes();
        });
    }

    public function getRestStatus(): array
    {
        return [
            'status' => 'operational',
            'dependencies_satisfied' => $this->dependencies_satisfied,
            'flags' => $this->flags,
            'environment' => [
                'wordpress' => defined('WP_VERSION') ? WP_VERSION : (get_bloginfo('version') ?: 'unknown'),
                'woocommerce' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
                'php' => PHP_VERSION,
                'authority_mode' => $this->flags['cohort_mode'] ?? 'legacy',
            ],
        ];
    }

    private function registerLifecycleHooks(): void
    {
        register_activation_hook(WPE_PLUGIN_FILE, [self::class, 'onActivate']);
        register_deactivation_hook(WPE_PLUGIN_FILE, [self::class, 'onDeactivate']);
        // Do NOT drop tables on uninstall automatically
    }

    public static function onActivate(): void
    {
        // AC-T02-01: Activation does not mutate data, products, or default authority
        $instance = self::instance();
        $instance->loadFlags();
        $instance->registerCapabilities();

        // Ensure default flags exist without modifying any existing products
        if (get_option(self::OPTION_FLAGS) === false) {
            update_option(self::OPTION_FLAGS, [
                'variants_ui_enabled'      => true,
                'query_enabled'            => false,
                'media_enabled'            => false,
                'resolver_enabled'         => false,
                'native_writes_enabled'    => false,
                'single_price_display'     => false,
                'show_selected_value'      => false,
                'show_help_text'           => false,
                'button_selector'          => false,
                'color_swatches'           => false,
                'image_swatches'           => false,
                'mark_unavailable_options' => false,
                'cohort_mode'              => 'legacy',
            ]);
        }
    }

    public static function onDeactivate(): void
    {
        // R06: Deactivation retains all data and tables. Never DROP tables.
        // Clean transient caches only if appropriate.
    }

    private function registerCliCommands(): void
    {
        \WPE\Cli\Commands::register();

        \WP_CLI::add_command('wpe status', function ($args, $assoc_args) {
            $status = $this->getRestStatus();
            \WP_CLI::log(json_encode($status, JSON_PRETTY_PRINT));
        });

        \WP_CLI::add_command('wpe flag', function ($args, $assoc_args) {
            $flag = $args[0] ?? null;
            $val = $args[1] ?? null;
            if (!$flag) {
                \WP_CLI::log(json_encode($this->getFlags(), JSON_PRETTY_PRINT));
                return;
            }
            if ($val !== null) {
                $boolVal = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                $this->setFlag($flag, $boolVal);
                \WP_CLI::success("Flag {$flag} updated to " . ($boolVal ? 'true' : 'false'));
            } else {
                \WP_CLI::log("{$flag}: " . ($this->isFlagEnabled($flag) ? 'true' : 'false'));
            }
        });
    }
}
