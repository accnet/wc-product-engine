<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Bootstrap\PluginBootstrap;

/**
 * SettingsPage
 *
 * Tabbed administration screen for WC Product Engine:
 *  - Overview    : read-only authority/health state and catalog counts.
 *  - Features    : editable feature flags (see SettingsRegistry).
 *  - Operations  : operations & health dashboard, parity checks, backfill/audit/rollback controls.
 *  - Maintenance : catalog index rebuild.
 *  - Advanced    : authority explained (read-only) and environment diagnostics.
 *
 * Authority (`cohort_mode`, `system_mode`, `authority_epoch`) is displayed here
 * but is never editable: it changes only through the governed rollout commands
 * (docs/02-domain.md §4-5). Styles live in assets/admin/settings.css under the
 * `.wpe-admin` scope; behaviour lives in assets/admin/settings.js.
 */
class SettingsPage
{
    public const PAGE_SLUG = 'wc-product-engine';
    public const TABS = ['overview', 'features', 'product-page', 'operations', 'maintenance', 'advanced'];

    private static ?SettingsPage $instance = null;
    private \wpdb $wpdb;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(?\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    public static function render(): void
    {
        self::getInstance()->renderPage();
    }

    /**
     * @return array<string,string> tab slug => label
     */
    public static function tabLabels(): array
    {
        return [
            'overview'     => __('Overview', 'wc-product-engine'),
            'features'     => __('Features', 'wc-product-engine'),
            'product-page' => __('Product Page', 'wc-product-engine'),
            'operations'   => __('Operations & Health', 'wc-product-engine'),
            'maintenance'  => __('Maintenance', 'wc-product-engine'),
            'advanced'     => __('Advanced', 'wc-product-engine'),
        ];
    }

    /**
     * Resolve the requested tab against the allow-list.
     */
    public static function currentTab(): string
    {
        // Read-only navigation parameter; no state change, so no nonce is required.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string)$_GET['tab'])) : '';
        return in_array($tab, self::TABS, true) ? $tab : 'overview';
    }

    public static function tabUrl(string $tab): string
    {
        return add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => $tab],
            admin_url('admin.php')
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-product-engine'));
        }

        $bootstrap = PluginBootstrap::instance();
        $tab = self::currentTab();
        $labels = self::tabLabels();

        ?>
        <div class="wrap wpe-admin wpe-settings" id="wpe-settings-app" data-tab="<?php echo esc_attr($tab); ?>">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-performance" aria-hidden="true"></span>
                <?php echo esc_html__('WC Product Engine', 'wc-product-engine'); ?>
            </h1>
            <hr class="wp-header-end">

            <nav class="wpe-tabs" aria-label="<?php echo esc_attr__('Settings sections', 'wc-product-engine'); ?>">
                <?php foreach ($labels as $slug => $label) : ?>
                    <a class="wpe-tab<?php echo $slug === $tab ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url(self::tabUrl($slug)); ?>"
                       <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div id="wpe-live" class="screen-reader-text" role="status" aria-live="polite"></div>
            <div id="wpe-notice" class="wpe-notice" role="alert" hidden></div>

            <noscript>
                <div class="wpe-notice is-warning">
                    <?php echo esc_html__('JavaScript is required to save settings and run maintenance actions on this screen.', 'wc-product-engine'); ?>
                </div>
            </noscript>

            <?php
            switch ($tab) {
                case 'features':
                    $this->renderFeatures($bootstrap);
                    break;
                case 'product-page':
                    $this->renderProductPage($bootstrap);
                    break;
                case 'operations':
                    $this->renderOperations();
                    break;
                case 'maintenance':
                    $this->renderMaintenance();
                    break;
                case 'advanced':
                    $this->renderAdvanced($bootstrap);
                    break;
                default:
                    $this->renderOverview($bootstrap);
            }
            ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------------

    /**
     * Authoritative state, taken from the same report the Operations screen uses
     * so both screens always agree.
     *
     * @return array<string,mixed>|null null when the report cannot be built.
     */
    private function healthReport(): ?array
    {
        try {
            return OperationsPage::getInstance()->getHealthReport();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function controlValue(string $key): ?string
    {
        $table = $this->wpdb->prefix . 'wpe_control';
        $value = $this->wpdb->get_var($this->wpdb->prepare("SELECT value FROM {$table} WHERE `key` = %s", $key));
        return $value === null ? null : (string)$value;
    }

    private function countNativeVariants(): int
    {
        $table = $this->wpdb->prefix . 'wpe_variants';
        return (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL");
    }

    private function catalogGeneration(): int
    {
        return (int)($this->controlValue('catalog_generation') ?? 0) ?: 1;
    }

    /**
     * Items that deserve the administrator's attention, most important first.
     *
     * @param array<string,mixed>|null $health
     * @return array<int,array{level:string,text:string}>
     */
    private function attentionItems(PluginBootstrap $bootstrap, ?array $health): array
    {
        $items = [];

        if (!$bootstrap->areDependenciesSatisfied()) {
            $items[] = [
                'level' => 'error',
                'text'  => __('Requirements are not met, so all engine features are switched off. See the Advanced tab.', 'wc-product-engine'),
            ];
        }

        if ($health === null) {
            $items[] = [
                'level' => 'warning',
                'text'  => __('The health report could not be built. Authority figures below may be unavailable.', 'wc-product-engine'),
            ];
        } else {
            if (($health['status'] ?? '') !== 'healthy') {
                $items[] = [
                    'level' => ($health['status'] ?? '') === 'unhealthy' ? 'error' : 'warning',
                    /* translators: %s: health status such as "degraded" */
                    'text'  => sprintf(__('System health is %s. Review it in Operations & Health.', 'wc-product-engine'), (string)($health['status'] ?? 'unknown')),
                ];
            }
            if ((int)($health['mismatches'] ?? 0) > 0) {
                $items[] = [
                    'level' => 'error',
                    /* translators: %d: number of shadow-diff mismatches */
                    'text'  => sprintf(__('%d shadow-diff mismatch(es) need review.', 'wc-product-engine'), (int)$health['mismatches']),
                ];
            }
            if ((int)($health['dlq_count'] ?? 0) > 0) {
                $items[] = [
                    'level' => 'error',
                    /* translators: %d: number of dead-letter queue events */
                    'text'  => sprintf(__('%d event(s) are in the dead-letter queue.', 'wc-product-engine'), (int)$health['dlq_count']),
                ];
            }
        }

        $unwired = 0;
        $flags = $bootstrap->getFlags();
        foreach (SettingsRegistry::flags() as $key => $def) {
            if ($def['runtime'] === SettingsRegistry::RUNTIME_PREFERENCE && !empty($flags[$key])) {
                $unwired++;
            }
        }
        if ($unwired > 0) {
            $items[] = [
                'level' => 'info',
                /* translators: %d: number of enabled features */
                'text'  => sprintf(
                    _n(
                        '%d enabled feature is saved but not applied by the runtime yet.',
                        '%d enabled features are saved but not applied by the runtime yet.',
                        $unwired,
                        'wc-product-engine'
                    ),
                    $unwired
                ),
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------------
    // Tabs
    // ---------------------------------------------------------------------

    private function renderOverview(PluginBootstrap $bootstrap): void
    {
        $health = $this->healthReport();
        $flags = $bootstrap->getFlags();
        $items = $this->attentionItems($bootstrap, $health);

        $status = $health['status'] ?? 'unknown';
        $statusClass = $status === 'healthy' ? 'is-ok' : ($status === 'unhealthy' ? 'is-error' : 'is-warning');
        $statusIcon = $status === 'healthy' ? 'yes-alt' : 'warning';
        $mode = (string)($health['system_mode'] ?? ($this->controlValue('system_mode') ?? 'legacy'));
        $epoch = $health['authority_epoch'] ?? null;
        $counts = $health['counts'] ?? [];
        $canOps = current_user_can('wpe_manage_operations') || current_user_can('manage_woocommerce');
        ?>
        <section class="wpe-panel" aria-labelledby="wpe-h-status">
            <div class="wpe-statusbar <?php echo esc_attr($statusClass); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($statusIcon); ?>" aria-hidden="true"></span>
                <div class="wpe-statusbar__text">
                    <h2 id="wpe-h-status">
                        <?php
                        /* translators: %s: health status such as "healthy" */
                        echo esc_html(sprintf(__('System health: %s', 'wc-product-engine'), $status));
                        ?>
                    </h2>
                    <p>
                        <?php
                        echo esc_html__('Mode', 'wc-product-engine') . ': ';
                        ?><code><?php echo esc_html($mode); ?></code>
                        &middot;
                        <?php echo esc_html__('Epoch', 'wc-product-engine') . ': '; ?><code><?php echo esc_html($epoch === null ? '—' : (string)$epoch); ?></code>
                    </p>
                </div>
                <?php if ($canOps) : ?>
                    <a class="button" href="<?php echo esc_url(self::tabUrl('operations')); ?>">
                        <?php echo esc_html__('Open Operations & Health', 'wc-product-engine'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($items !== []) : ?>
                <div class="wpe-card">
                    <h3><?php echo esc_html__('Needs attention', 'wc-product-engine'); ?></h3>
                    <ul class="wpe-attention">
                        <?php foreach ($items as $item) : ?>
                            <li class="is-<?php echo esc_attr($item['level']); ?>">
                                <span class="wpe-attention__level"><?php echo esc_html($this->levelLabel($item['level'])); ?></span>
                                <?php echo esc_html($item['text']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="wpe-metrics">
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Catalog generation', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value" id="wpe-current-gen">#<?php echo esc_html((string)$this->catalogGeneration()); ?></span>
                    <span class="wpe-metric__hint"><?php echo esc_html__('Active catalog index build', 'wc-product-engine'); ?></span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Native products', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value"><?php echo esc_html(number_format_i18n((int)($counts['native_products'] ?? 0))); ?></span>
                    <span class="wpe-metric__hint">
                        <?php
                        /* translators: %s: number of products whose authority is native */
                        echo esc_html(sprintf(__('%s native-authoritative', 'wc-product-engine'), number_format_i18n((int)($counts['native_authority'] ?? 0))));
                        ?>
                    </span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Native variants', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value"><?php echo esc_html(number_format_i18n($this->countNativeVariants())); ?></span>
                    <span class="wpe-metric__hint"><?php echo esc_html__('Active, not deleted', 'wc-product-engine'); ?></span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Legacy products', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value"><?php echo esc_html(number_format_i18n((int)($counts['legacy_products'] ?? 0))); ?></span>
                    <span class="wpe-metric__hint">
                        <?php
                        /* translators: %s: number of products whose authority is legacy */
                        echo esc_html(sprintf(__('%s legacy-authoritative', 'wc-product-engine'), number_format_i18n((int)($counts['legacy_authority'] ?? 0))));
                        ?>
                    </span>
                </div>
            </div>

            <div class="wpe-card">
                <h3><?php echo esc_html__('Default routing', 'wc-product-engine'); ?></h3>
                <p>
                    <code><?php echo esc_html((string)($flags['cohort_mode'] ?? 'legacy')); ?></code>
                    <span class="wpe-badge"><?php echo esc_html__('Read-only', 'wc-product-engine'); ?></span>
                </p>
                <p class="wpe-help">
                    <?php echo esc_html__('Used for products that have no product or category routing rule. This is separate from the global system mode shown above, and it is changed only through the governed rollout commands.', 'wc-product-engine'); ?>
                </p>
            </div>
        </section>
        <?php
    }

    private function renderFeatures(PluginBootstrap $bootstrap): void
    {
        $flags = $bootstrap->getFlags();
        $depsOk = $bootstrap->areDependenciesSatisfied();
        $featureFlags = SettingsRegistry::flagsByGroup(SettingsRegistry::GROUP_FEATURES);
        $initial = [];
        foreach (array_keys($featureFlags) as $key) {
            $initial[$key] = !empty($flags[$key]);
        }
        ?>
        <section class="wpe-panel" aria-labelledby="wpe-h-features"
                 data-initial="<?php echo esc_attr((string)wp_json_encode($initial)); ?>">
            <h2 id="wpe-h-features" class="screen-reader-text"><?php echo esc_html__('Features', 'wc-product-engine'); ?></h2>

            <?php if (!$depsOk) : ?>
                <div class="wpe-notice is-error">
                    <strong><?php echo esc_html__('Features are locked.', 'wc-product-engine'); ?></strong>
                    <ul>
                        <?php foreach ($bootstrap->getDependencyErrors() as $error) : ?>
                            <li><?php echo esc_html((string)$error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="wpe-settings-form" class="wpe-card wpe-features" novalidate>
                <?php foreach ($featureFlags as $key => $def) :
                    $this->renderFlagRow($key, $def, $flags, $bootstrap, $depsOk);
                endforeach; ?>
            </form>

            <div class="wpe-savebar" id="wpe-savebar" hidden>
                <span class="wpe-savebar__dot" aria-hidden="true"></span>
                <span class="wpe-savebar__text" id="wpe-savebar-text"></span>
                <span class="wpe-savebar__actions">
                    <button type="button" class="wpe-btn is-ghost" id="wpe-discard-btn"><?php echo esc_html__('Discard', 'wc-product-engine'); ?></button>
                    <button type="submit" form="wpe-settings-form" class="wpe-btn is-primary" id="wpe-save-settings-btn"><?php echo esc_html__('Save changes', 'wc-product-engine'); ?></button>
                </span>
            </div>
        </section>
        <?php
    }

    private function renderProductPage(PluginBootstrap $bootstrap): void
    {
        $flags = $bootstrap->getFlags();
        $depsOk = $bootstrap->areDependenciesSatisfied();
        $priceFlags = SettingsRegistry::flagsByGroup(SettingsRegistry::GROUP_PRODUCT_PAGE, SettingsRegistry::SECTION_PRICE_DISPLAY);
        $selectorFlags = SettingsRegistry::flagsByGroup(SettingsRegistry::GROUP_PRODUCT_PAGE, SettingsRegistry::SECTION_VARIANT_SELECTOR);
        $productPageFlags = $priceFlags + $selectorFlags;

        $initial = [];
        foreach (array_keys($productPageFlags) as $key) {
            $initial[$key] = !empty($flags[$key]);
        }

        $attrConfig = $bootstrap->getAttributeDisplayConfig();
        $taxonomies = function_exists('wc_get_attribute_taxonomies') ? (array)wc_get_attribute_taxonomies() : [];
        $initialAttrConfig = [];
        $attributeRows = [];
        $coveredBaseSlugs = [];

        foreach ($taxonomies as $tax) {
            $attrId = (int)($tax->attribute_id ?? 0);
            if ($attrId <= 0) {
                continue;
            }
            $baseSlug = sanitize_title((string)$tax->attribute_name);
            if ($baseSlug !== '') {
                $coveredBaseSlugs[$baseSlug] = true;
            }
            $attrLabel = (string)($tax->attribute_label ?: $tax->attribute_name);
            $attrSlug = function_exists('wc_attribute_taxonomy_name')
                ? wc_attribute_taxonomy_name((string)$tax->attribute_name)
                : ('pa_' . (string)$tax->attribute_name);
            $savedEntry = $attrConfig[$attrId] ?? [];
            $initialAttrConfig[$attrId] = [
                'display_type' => (string)($savedEntry['display_type'] ?? 'auto'),
                'help_text'    => (string)($savedEntry['help_text'] ?? ''),
                'term_colors'  => is_array($savedEntry['term_colors'] ?? null) ? $savedEntry['term_colors'] : [],
            ];

            $terms = get_terms([
                'taxonomy'   => $attrSlug,
                'hide_empty' => false,
            ]);
            $valueItems = [];
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $term) {
                    $valueItems[] = [
                        'id'    => (string)(int)$term->term_id,
                        'label' => (string)$term->name,
                    ];
                }
            }

            $attributeRows[] = [
                'config_key' => (string)$attrId,
                'label'      => $attrLabel,
                'badge_code' => $attrSlug,
                'badge_meta' => '#' . $attrId,
                'entry'      => $initialAttrConfig[$attrId],
                'values'     => $valueItems,
            ];
        }

        global $wpdb;
        if ($wpdb instanceof \wpdb) {
            $optTable = $wpdb->prefix . 'wpe_options';
            $valTable = $wpdb->prefix . 'wpe_option_values';
            $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $optTable));
            if ($tableExists === $optTable) {
                $customOpts = $wpdb->get_results(
                    "SELECT `key`, MAX(name) AS label, COUNT(DISTINCT product_id) AS product_count
                     FROM {$optTable}
                     WHERE used_for_variation = 1
                     GROUP BY `key`
                     ORDER BY product_count DESC, label ASC
                     LIMIT 50"
                );
                if (is_array($customOpts)) {
                    foreach ($customOpts as $cOpt) {
                        $rawKey = (string)($cOpt->key ?? '');
                        $stripped = str_starts_with($rawKey, 'pa_') ? substr($rawKey, 3) : $rawKey;
                        $baseSlug = sanitize_title($stripped);
                        if ($baseSlug === '' || isset($coveredBaseSlugs[$baseSlug]) || !preg_match('/^[a-z0-9_-]{1,60}$/', $baseSlug)) {
                            continue;
                        }
                        $coveredBaseSlugs[$baseSlug] = true;
                        $configKey = 'opt_' . $baseSlug;
                        $savedEntry = $attrConfig[$configKey] ?? [];
                        $initialAttrConfig[$configKey] = [
                            'display_type' => (string)($savedEntry['display_type'] ?? 'auto'),
                            'help_text'    => (string)($savedEntry['help_text'] ?? ''),
                            'term_colors'  => is_array($savedEntry['term_colors'] ?? null) ? $savedEntry['term_colors'] : [],
                        ];

                        $valRows = $wpdb->get_results($wpdb->prepare(
                            "SELECT ov.value_key, MAX(ov.label) AS label
                             FROM {$valTable} ov
                             INNER JOIN {$optTable} o ON o.id = ov.option_id
                             WHERE o.used_for_variation = 1 AND o.`key` = %s
                             GROUP BY ov.value_key
                             ORDER BY label ASC
                             LIMIT 30",
                            $rawKey
                        ));
                        $valueItems = [];
                        if (is_array($valRows)) {
                            foreach ($valRows as $vRow) {
                                $vKey = sanitize_title((string)($vRow->value_key ?? ''));
                                if ($vKey !== '' && preg_match('/^[a-z0-9_-]{1,64}$/', $vKey)) {
                                    $valueItems[] = [
                                        'id'    => $vKey,
                                        'label' => (string)($vRow->label ?: $vKey),
                                    ];
                                }
                            }
                        }

                        $cnt = (int)($cOpt->product_count ?? 0);
                        $attributeRows[] = [
                            'config_key' => $configKey,
                            'label'      => (string)($cOpt->label ?: ucfirst($baseSlug)),
                            'badge_code' => $baseSlug,
                            'badge_meta' => sprintf(_n('%d product', '%d products', $cnt, 'wc-product-engine'), $cnt),
                            'entry'      => $initialAttrConfig[$configKey],
                            'values'     => $valueItems,
                        ];
                    }
                }
            }
        }
        ?>
        <section class="wpe-panel" id="wpe-product-page-panel" aria-labelledby="wpe-h-product-page"
                 data-initial="<?php echo esc_attr((string)wp_json_encode($initial)); ?>"
                 data-initial-attr-config="<?php echo esc_attr((string)wp_json_encode($initialAttrConfig)); ?>">
            <h2 id="wpe-h-product-page" class="screen-reader-text"><?php echo esc_html__('Product Page', 'wc-product-engine'); ?></h2>

            <?php if (!$depsOk) : ?>
                <div class="wpe-notice is-error">
                    <strong><?php echo esc_html__('Settings are locked.', 'wc-product-engine'); ?></strong>
                    <ul>
                        <?php foreach ($bootstrap->getDependencyErrors() as $error) : ?>
                            <li><?php echo esc_html((string)$error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="wpe-settings-form" class="wpe-product-page-form" novalidate>
                <div class="wpe-card wpe-settings-group" id="wpe-group-price-display">
                    <div class="wpe-settings-group__header">
                        <h3><?php echo esc_html__('Price Display', 'wc-product-engine'); ?></h3>
                        <p class="wpe-help">
                            <?php echo esc_html__('Control how variable product prices are rendered in the main storefront price area and on catalog product cards.', 'wc-product-engine'); ?>
                        </p>
                    </div>
                    <div class="wpe-features">
                        <?php foreach ($priceFlags as $key => $def) :
                            $this->renderFlagRow($key, $def, $flags, $bootstrap, $depsOk);
                        endforeach; ?>
                    </div>
                </div>

                <div class="wpe-card wpe-settings-group" id="wpe-group-variant-selector">
                    <div class="wpe-settings-group__header">
                        <h3><?php echo esc_html__('Variant Selector', 'wc-product-engine'); ?></h3>
                        <p class="wpe-help">
                            <?php echo esc_html__('Configure storefront variation selector controls, option labels, help text, swatches, and unavailable option indicators. When a selector style toggle is turned off, attributes using that style automatically fall back to a standard dropdown.', 'wc-product-engine'); ?>
                        </p>
                    </div>
                    <div class="wpe-features">
                        <?php foreach ($selectorFlags as $key => $def) :
                            $this->renderFlagRow($key, $def, $flags, $bootstrap, $depsOk);
                        endforeach; ?>
                    </div>
                </div>

                <div class="wpe-card wpe-settings-group" id="wpe-group-attribute-display">
                    <div class="wpe-settings-group__header">
                        <h3><?php echo esc_html__('Global Attribute Display Configuration', 'wc-product-engine'); ?></h3>
                        <p class="wpe-help">
                            <?php echo esc_html__('Configure the storefront display type, help text, and swatch colors globally for all variation attributes across your catalog. All products sharing an attribute automatically follow these global settings.', 'wc-product-engine'); ?>
                        </p>
                        <p class="wpe-field-error" id="wpe-attr-config-error" data-error-for="attribute_display_config" hidden></p>
                    </div>

                    <?php if ($attributeRows === []) : ?>
                        <p class="wpe-help wpe-empty-hint">
                            <?php echo esc_html__('No variation attributes exist yet. Once attributes or product variation options are created, they will appear here for global display configuration.', 'wc-product-engine'); ?>
                        </p>
                    <?php else : ?>
                        <div class="wpe-attr-config-list" id="wpe-global-attr-config-list">
                            <?php foreach ($attributeRows as $row) :
                                $attrKey = (string)$row['config_key'];
                                $attrLabel = (string)$row['label'];
                                $badgeCode = (string)$row['badge_code'];
                                $badgeMeta = (string)$row['badge_meta'];
                                $entry = $row['entry'];
                                $selectedType = (string)($entry['display_type'] ?? 'auto');
                                $helpTextVal = (string)($entry['help_text'] ?? '');
                                $termColors = is_array($entry['term_colors'] ?? null) ? $entry['term_colors'] : [];
                                $valueItems = is_array($row['values'] ?? null) ? $row['values'] : [];
                                ?>
                                <div class="wpe-attr-config-item" data-attr-config-row="<?php echo esc_attr($attrKey); ?>">
                                    <div class="wpe-attr-config-item__main">
                                        <div class="wpe-attr-config-item__info">
                                            <strong class="wpe-attr-config-item__label"><?php echo esc_html($attrLabel); ?></strong>
                                            <span class="wpe-badge is-muted"><code><?php echo esc_html($badgeCode); ?></code> &middot; <?php echo esc_html($badgeMeta); ?></span>
                                        </div>
                                        <div class="wpe-attr-config-item__controls">
                                            <label class="wpe-attr-field">
                                                <span class="wpe-attr-field__label"><?php echo esc_html__('Display Type', 'wc-product-engine'); ?></span>
                                                <select class="wpe-input" data-attr-display-type="<?php echo esc_attr($attrKey); ?>" <?php disabled(!$depsOk); ?>>
                                                    <option value="auto" <?php selected($selectedType, 'auto'); ?>><?php echo esc_html__('Auto (Color = Color Swatch, Visual = Image Swatch, Size = Button)', 'wc-product-engine'); ?></option>
                                                    <option value="dropdown" <?php selected($selectedType, 'dropdown'); ?>><?php echo esc_html__('Dropdown', 'wc-product-engine'); ?></option>
                                                    <option value="button" <?php selected($selectedType, 'button'); ?>><?php echo esc_html__('Button', 'wc-product-engine'); ?></option>
                                                    <option value="color" <?php selected($selectedType, 'color'); ?>><?php echo esc_html__('Color Swatch', 'wc-product-engine'); ?></option>
                                                    <option value="image" <?php selected($selectedType, 'image'); ?>><?php echo esc_html__('Image Swatch', 'wc-product-engine'); ?></option>
                                                </select>
                                            </label>
                                            <label class="wpe-attr-field wpe-attr-field--wide">
                                                <span class="wpe-attr-field__label"><?php echo esc_html__('Help Text', 'wc-product-engine'); ?></span>
                                                <input type="text" class="wpe-input" maxlength="255"
                                                       data-attr-help-text="<?php echo esc_attr($attrKey); ?>"
                                                       value="<?php echo esc_attr($helpTextVal); ?>"
                                                       placeholder="<?php echo esc_attr__('e.g. Choose your size', 'wc-product-engine'); ?>"
                                                       <?php disabled(!$depsOk); ?>>
                                            </label>
                                        </div>
                                    </div>
                                    <?php if ($valueItems !== []) : ?>
                                        <div class="wpe-attr-config-item__colors" data-attr-colors-row="<?php echo esc_attr($attrKey); ?>"<?php echo $selectedType === 'color' ? '' : ' hidden'; ?>>
                                            <span class="wpe-attr-field__label"><?php echo esc_html__('Swatch Colors (leave empty to auto-detect standard POD colors or fall back to text button):', 'wc-product-engine'); ?></span>
                                            <div class="wpe-attr-term-colors">
                                                <?php foreach ($valueItems as $vItem) :
                                                    $tid = (string)$vItem['id'];
                                                    $vLabel = (string)$vItem['label'];
                                                    $hex = (string)($termColors[$tid] ?? ($termColors[(int)$tid] ?? ''));
                                                    $pickerVal = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : '#000000';
                                                    ?>
                                                    <div class="wpe-attr-term-color">
                                                        <input type="color" class="wpe-color-picker"
                                                               value="<?php echo esc_attr($pickerVal); ?>"
                                                               data-color-picker-for="<?php echo esc_attr($attrKey . '-' . $tid); ?>"
                                                               aria-label="<?php echo esc_attr(sprintf(__('Pick color for %s', 'wc-product-engine'), $vLabel)); ?>"
                                                               <?php disabled(!$depsOk); ?>>
                                                        <span class="wpe-attr-term-color__name"><?php echo esc_html($vLabel); ?></span>
                                                        <input type="text" class="wpe-input wpe-input--hex"
                                                               maxlength="7"
                                                               placeholder="#RRGGBB"
                                                               value="<?php echo esc_attr($hex); ?>"
                                                               data-attr-term-color="<?php echo esc_attr($attrKey); ?>"
                                                               data-term-id="<?php echo esc_attr($tid); ?>"
                                                               data-color-hex-id="<?php echo esc_attr($attrKey . '-' . $tid); ?>"
                                                               aria-label="<?php echo esc_attr(sprintf(__('Hex color for %s', 'wc-product-engine'), $vLabel)); ?>"
                                                               <?php disabled(!$depsOk); ?>>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

            <div class="wpe-savebar" id="wpe-savebar" hidden>
                <span class="wpe-savebar__dot" aria-hidden="true"></span>
                <span class="wpe-savebar__text" id="wpe-savebar-text"></span>
                <span class="wpe-savebar__actions">
                    <button type="button" class="wpe-btn is-ghost" id="wpe-discard-btn"><?php echo esc_html__('Discard', 'wc-product-engine'); ?></button>
                    <button type="submit" form="wpe-settings-form" class="wpe-btn is-primary" id="wpe-save-settings-btn"><?php echo esc_html__('Save changes', 'wc-product-engine'); ?></button>
                </span>
            </div>
        </section>
        <?php
    }

    /**
     * @param array{label:string, description:string, affects:string, runtime:string} $def
     * @param array<string,mixed> $flags
     */
    private function renderFlagRow(string $key, array $def, array $flags, PluginBootstrap $bootstrap, bool $depsOk): void
    {
        $checked = !empty($flags[$key]);
        $inactive = $checked && !$bootstrap->isFlagEnabled($key);
        $id = 'wpe-flag-' . $key;
        ?>
        <div class="wpe-feature" data-flag-row="<?php echo esc_attr($key); ?>">
            <div class="wpe-feature__body">
                <label class="wpe-feature__title" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($def['label']); ?></label>
                <p class="wpe-help" id="<?php echo esc_attr($id); ?>-desc"><?php echo esc_html($def['description']); ?></p>
                <p class="wpe-feature__meta">
                    <span class="wpe-badge"><?php
                        /* translators: %s: area affected by a feature, e.g. Storefront */
                        echo esc_html(sprintf(__('Affects: %s', 'wc-product-engine'), $def['affects']));
                    ?></span>
                    <?php if ($def['runtime'] === SettingsRegistry::RUNTIME_GATE) : ?>
                        <span class="wpe-badge is-ok"><?php echo esc_html__('Active gate', 'wc-product-engine'); ?></span>
                    <?php else : ?>
                        <span class="wpe-badge is-muted"><?php echo esc_html__('Saved, not applied by the runtime yet', 'wc-product-engine'); ?></span>
                    <?php endif; ?>
                    <span class="wpe-badge is-warning" data-inactive-badge<?php echo $inactive ? '' : ' hidden'; ?>>
                        <?php echo esc_html__('On, but inactive: requirements not met', 'wc-product-engine'); ?>
                    </span>
                </p>
                <p class="wpe-field-error" id="<?php echo esc_attr($id); ?>-error" data-error-for="<?php echo esc_attr($key); ?>" hidden></p>
            </div>
            <span class="wpe-switch">
                <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($key); ?>" value="1"
                       data-flag="<?php echo esc_attr($key); ?>"
                       role="switch"
                       aria-describedby="<?php echo esc_attr($id); ?>-desc"
                       <?php checked($checked); ?>
                       <?php disabled(!$depsOk); ?>>
                <span class="wpe-switch__track" aria-hidden="true"></span>
            </span>
        </div>
        <?php
    }

    private function renderOperations(): void
    {
        $health = $this->healthReport();
        $status = (string)($health['status'] ?? 'unknown');
        $statusClass = $status === 'healthy' ? 'is-ok' : ($status === 'unhealthy' ? 'is-error' : 'is-warning');
        $statusIcon = $status === 'healthy' ? 'yes-alt' : 'warning';
        $mode = (string)($health['system_mode'] ?? ($this->controlValue('system_mode') ?? 'legacy'));
        $epoch = $health['authority_epoch'] ?? null;
        $counts = $health['counts'] ?? [];
        $mismatches = (int)($health['mismatches'] ?? 0);
        $dlqCount = (int)($health['dlq_count'] ?? 0);
        $outboxPending = (int)($health['outbox_pending'] ?? 0);
        $projLag = (int)($health['projection_lag']['pending_projections'] ?? 0);
        $starvation = $health['worker_starvation'] ?? ['alert' => false, 'starved_job_id' => null, 'threshold_sec' => 120];
        $checkpoints = is_array($health['checkpoints'] ?? null) ? $health['checkpoints'] : [];
        $canOps = current_user_can('wpe_manage_operations') || current_user_can('manage_woocommerce');
        ?>
        <section class="wpe-panel" id="wpe-operations-panel" aria-labelledby="wpe-h-ops">
            <div id="wpe-health-banner" class="wpe-statusbar <?php echo esc_attr($statusClass); ?>">
                <span class="dashicons dashicons-<?php echo esc_attr($statusIcon); ?>" id="wpe-health-icon" aria-hidden="true"></span>
                <div class="wpe-statusbar__text">
                    <h2 id="wpe-h-ops">
                        <?php echo esc_html__('System Health Status:', 'wc-product-engine'); ?>
                        <span id="wpe-status-text" class="wpe-ops-status-text"><?php echo esc_html($status); ?></span>
                    </h2>
                    <p>
                        <?php echo esc_html__('Mode', 'wc-product-engine') . ': '; ?><code id="wpe-ops-mode"><?php echo esc_html($mode); ?></code>
                        &middot;
                        <?php echo esc_html__('Epoch', 'wc-product-engine') . ': '; ?><code id="wpe-ops-epoch"><?php echo esc_html($epoch === null ? '—' : (string)$epoch); ?></code>
                        <?php if (!empty($health['timestamp'])) : ?>
                            &middot;
                            <span class="wpe-ops-timestamp"><?php echo esc_html__('Updated', 'wc-product-engine') . ': '; ?><code id="wpe-ops-updated"><?php echo esc_html((string)$health['timestamp']); ?></code></span>
                        <?php endif; ?>
                    </p>
                </div>
                <button type="button" class="wpe-btn is-secondary" id="wpe-ops-refresh-btn">
                    <?php echo esc_html__('Refresh status', 'wc-product-engine'); ?>
                </button>
            </div>

            <div id="wpe-starvation-alert" class="wpe-notice is-error"<?php echo !empty($starvation['alert']) ? '' : ' hidden'; ?>>
                <strong><?php echo esc_html__('Worker Starvation Alert:', 'wc-product-engine'); ?></strong>
                <span id="wpe-starvation-text">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: job ID, 2: threshold in seconds */
                        __('Active job %1$s has recorded no heartbeat or checkpoint for over %2$ds.', 'wc-product-engine'),
                        (string)($starvation['starved_job_id'] ?? 'unknown'),
                        (int)($starvation['threshold_sec'] ?? 120)
                    ));
                    ?>
                </span>
            </div>

            <div class="wpe-metrics wpe-metrics--ops">
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Native Products', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value" id="wpe-ops-native-products"><?php echo esc_html(number_format_i18n((int)($counts['native_products'] ?? 0))); ?></span>
                    <span class="wpe-metric__hint" id="wpe-ops-native-auth">
                        <?php echo esc_html__('Authority: ', 'wc-product-engine') . esc_html(number_format_i18n((int)($counts['native_authority'] ?? 0))); ?>
                    </span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Legacy Products', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value" id="wpe-ops-legacy-products"><?php echo esc_html(number_format_i18n((int)($counts['legacy_products'] ?? 0))); ?></span>
                    <span class="wpe-metric__hint" id="wpe-ops-legacy-auth">
                        <?php echo esc_html__('Authority: ', 'wc-product-engine') . esc_html(number_format_i18n((int)($counts['legacy_authority'] ?? 0))); ?>
                    </span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Mismatches', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value <?php echo $mismatches > 0 ? 'is-error' : 'is-ok'; ?>" id="wpe-ops-mismatches"><?php echo esc_html((string)$mismatches); ?></span>
                    <span class="wpe-metric__hint"><?php echo esc_html__('Shadow diff gaps', 'wc-product-engine'); ?></span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('DLQ Failures', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value <?php echo $dlqCount > 0 ? 'is-error' : 'is-ok'; ?>" id="wpe-ops-dlq"><?php echo esc_html((string)$dlqCount); ?></span>
                    <span class="wpe-metric__hint"><?php echo esc_html__('Dead-letter queue events', 'wc-product-engine'); ?></span>
                </div>
                <div class="wpe-metric">
                    <span class="wpe-metric__label"><?php echo esc_html__('Projection Lag', 'wc-product-engine'); ?></span>
                    <span class="wpe-metric__value" id="wpe-ops-lag"><?php echo esc_html((string)$projLag); ?></span>
                    <span class="wpe-metric__hint" id="wpe-ops-outbox">
                        <?php echo esc_html(sprintf(__('Pending legacy syncs (Outbox: %d)', 'wc-product-engine'), $outboxPending)); ?>
                    </span>
                </div>
            </div>

            <div class="wpe-card">
                <h3><?php echo esc_html__('Operations Controls', 'wc-product-engine'); ?></h3>
                <p class="wpe-help">
                    <?php echo esc_html__('Trigger background catalog operations, index rebuilds, shadow diff audits, or emergency rollbacks.', 'wc-product-engine'); ?>
                </p>
                <div class="wpe-ops-actions">
                    <button type="button" class="wpe-btn is-primary wpe-action-btn" data-action="audit" <?php disabled(!$canOps); ?>>
                        <?php echo esc_html__('Run Shadow Diff Audit', 'wc-product-engine'); ?>
                    </button>
                    <button type="button" class="wpe-btn is-secondary wpe-action-btn" data-action="rebuild" <?php disabled(!$canOps); ?>>
                        <?php echo esc_html__('Rebuild Index Generation', 'wc-product-engine'); ?>
                    </button>
                    <button type="button" class="wpe-btn is-secondary wpe-action-btn" data-action="migrate" <?php disabled(!$canOps); ?>>
                        <?php echo esc_html__('Run Keyset Backfill Batch', 'wc-product-engine'); ?>
                    </button>
                    <button type="button" class="wpe-btn is-danger wpe-action-btn wpe-ops-rollback-btn" data-action="rollback" <?php disabled(!$canOps); ?>>
                        <?php echo esc_html__('Initiate Authority Rollback', 'wc-product-engine'); ?>
                    </button>
                </div>
                <div id="wpe-action-result" class="wpe-notice wpe-ops-result" role="status" hidden></div>
            </div>

            <div class="wpe-card">
                <h3><?php echo esc_html__('Recent Jobs & Checkpoints', 'wc-product-engine'); ?></h3>
                <p class="wpe-help">
                    <?php echo esc_html__('Recent background backfill, audit, and export jobs. Paused or failed jobs can be resumed directly.', 'wc-product-engine'); ?>
                </p>
                <div class="wpe-ops-table-wrap">
                    <table class="wpe-ops-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Job ID', 'wc-product-engine'); ?></th>
                                <th><?php echo esc_html__('Kind', 'wc-product-engine'); ?></th>
                                <th><?php echo esc_html__('Status', 'wc-product-engine'); ?></th>
                                <th><?php echo esc_html__('Updated', 'wc-product-engine'); ?></th>
                                <th><?php echo esc_html__('Action', 'wc-product-engine'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="wpe-ops-checkpoints-body">
                            <?php if ($checkpoints === []) : ?>
                                <tr>
                                    <td colspan="5" class="wpe-help"><?php echo esc_html__('No recent background jobs recorded.', 'wc-product-engine'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($checkpoints as $cp) :
                                    $cpStatus = (string)($cp['status'] ?? 'unknown');
                                    $badgeCls = $cpStatus === 'completed' ? 'is-ok' : (in_array($cpStatus, ['failed', 'paused'], true) ? 'is-warning' : '');
                                    ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string)($cp['id'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string)($cp['kind'] ?? '')); ?></td>
                                        <td>
                                            <span class="wpe-badge <?php echo esc_attr($badgeCls); ?>"><?php echo esc_html($cpStatus); ?></span>
                                            <?php if (!empty($cp['error'])) : ?>
                                                <small class="wpe-help"> (<?php echo esc_html((string)$cp['error']); ?>)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html((string)($cp['updated_at'] ?? '—')); ?></td>
                                        <td>
                                            <?php if (!empty($cp['can_resume']) && $canOps) : ?>
                                                <button type="button" class="wpe-btn is-secondary wpe-btn--sm wpe-resume-job-btn" data-job-id="<?php echo esc_attr((string)($cp['id'] ?? '')); ?>">
                                                    <?php echo esc_html__('Resume', 'wc-product-engine'); ?>
                                                </button>
                                            <?php else : ?>
                                                <span class="wpe-help">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="wpe-card">
                <h3><?php echo esc_html__('System Worker Guidance & Thresholds (08 §8)', 'wc-product-engine'); ?></h3>
                <dl class="wpe-dl">
                    <dt><?php echo esc_html__('Worker Scheduling', 'wc-product-engine'); ?></dt>
                    <dd><?php echo esc_html__('Run queue loop via dedicated systemd or Linux system cron (not WP page traffic).', 'wc-product-engine'); ?></dd>
                    <dt><?php echo esc_html__('Concurrency & Batches', 'wc-product-engine'); ?></dt>
                    <dd><?php echo esc_html__('Initial concurrency = 1, batch size = 100, worker lease = 60s, heartbeat = 20s.', 'wc-product-engine'); ?></dd>
                    <dt><?php echo esc_html__('Starvation Alert', 'wc-product-engine'); ?></dt>
                    <dd><?php echo esc_html__('Triggered when active worker has no heartbeat or checkpoint for > 120s.', 'wc-product-engine'); ?></dd>
                </dl>
            </div>
        </section>

        <dialog id="wpe-rollback-dialog" class="wpe-dialog" aria-labelledby="wpe-rollback-title" aria-describedby="wpe-rollback-body">
            <form method="dialog">
                <h2 id="wpe-rollback-title"><?php echo esc_html__('Initiate authority rollback?', 'wc-product-engine'); ?></h2>
                <p id="wpe-rollback-body">
                    <?php echo esc_html__('WARNING: Are you sure you want to initiate an Authority Rollback back to Legacy CPT? Current native catalog data will be reconciled before switching.', 'wc-product-engine'); ?>
                </p>
                <div class="wpe-dialog__actions">
                    <button type="submit" value="cancel" class="wpe-btn is-secondary" id="wpe-rollback-cancel"><?php echo esc_html__('Cancel', 'wc-product-engine'); ?></button>
                    <button type="submit" value="confirm" class="wpe-btn is-danger" id="wpe-rollback-ok"><?php echo esc_html__('Rollback now', 'wc-product-engine'); ?></button>
                </div>
            </form>
        </dialog>
        <?php
    }

    private function renderMaintenance(): void
    {
        $canOps = current_user_can('wpe_manage_operations') || current_user_can('manage_woocommerce');
        ?>
        <section class="wpe-panel" aria-labelledby="wpe-h-maint">
            <div class="wpe-card">
                <h2 id="wpe-h-maint"><?php echo esc_html__('Catalog index', 'wc-product-engine'); ?></h2>
                <p>
                    <?php echo esc_html__('Current generation', 'wc-product-engine'); ?>:
                    <strong id="wpe-current-gen">#<?php echo esc_html((string)$this->catalogGeneration()); ?></strong>
                </p>
                <p class="wpe-help">
                    <?php echo esc_html__('Rebuilding recomputes the search index and facet caches and activates a new generation. Run it after a large import or when the storefront shows stale results. It runs now and the request waits for it to finish.', 'wc-product-engine'); ?>
                </p>
                <p>
                    <button type="button" class="wpe-btn is-secondary" id="wpe-rebuild-gen-btn">
                        <?php echo esc_html__('Rebuild catalog index', 'wc-product-engine'); ?>
                    </button>
                </p>
                <p id="wpe-rebuild-status" class="wpe-rebuild-status" hidden></p>

                <?php if ($canOps) : ?>
                    <p class="wpe-help">
                        <?php echo esc_html__('Prefer a background, resumable run? Use the job-backed action in', 'wc-product-engine'); ?>
                        <a href="<?php echo esc_url(self::tabUrl('operations')); ?>"><?php echo esc_html__('Operations & Health', 'wc-product-engine'); ?></a>.
                    </p>
                <?php endif; ?>
            </div>

            <?php if (current_user_can('wpe_import_products') || current_user_can('manage_woocommerce')) : ?>
                <div class="wpe-card">
                    <h3><?php echo esc_html__('Importing a catalog?', 'wc-product-engine'); ?></h3>
                    <p class="wpe-help"><?php echo esc_html__('Large Shopify CSV imports run in chunks with pause, resume and a live log.', 'wc-product-engine'); ?></p>
                    <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wpe-shopify-import')); ?>"><?php echo esc_html__('Open Shopify CSV Import', 'wc-product-engine'); ?></a></p>
                </div>
            <?php endif; ?>
        </section>

        <dialog id="wpe-confirm-dialog" class="wpe-dialog" aria-labelledby="wpe-confirm-title" aria-describedby="wpe-confirm-body">
            <form method="dialog">
                <h2 id="wpe-confirm-title"><?php echo esc_html__('Rebuild catalog index?', 'wc-product-engine'); ?></h2>
                <p id="wpe-confirm-body">
                    <?php echo esc_html__('This builds a new catalog generation and makes it active. It can take a while on large catalogs. Do not close this page until it finishes.', 'wc-product-engine'); ?>
                </p>
                <div class="wpe-dialog__actions">
                    <button type="submit" value="cancel" class="wpe-btn is-secondary" id="wpe-confirm-cancel"><?php echo esc_html__('Cancel', 'wc-product-engine'); ?></button>
                    <button type="submit" value="confirm" class="wpe-btn is-primary" id="wpe-confirm-ok"><?php echo esc_html__('Rebuild now', 'wc-product-engine'); ?></button>
                </div>
            </form>
        </dialog>
        <?php
    }

    private function renderAdvanced(PluginBootstrap $bootstrap): void
    {
        $flags = $bootstrap->getFlags();
        $mode = $this->controlValue('system_mode') ?? 'legacy';
        $health = $this->healthReport();
        $epoch = $health['authority_epoch'] ?? null;
        $errors = $bootstrap->getDependencyErrors();
        ?>
        <section class="wpe-panel" aria-labelledby="wpe-h-adv">
            <div class="wpe-card">
                <h2 id="wpe-h-adv"><?php echo esc_html__('Authority', 'wc-product-engine'); ?> <span class="wpe-badge"><?php echo esc_html__('Read-only', 'wc-product-engine'); ?></span></h2>
                <p class="wpe-help">
                    <?php echo esc_html__('Authority decides whether WooCommerce posts or the engine tables are the source of truth. It must change only through the governed rollout steps, which check readiness gates, take a barrier and advance the epoch. It cannot be edited from this screen.', 'wc-product-engine'); ?>
                </p>
                <dl class="wpe-dl">
                    <dt><?php echo esc_html__('System mode', 'wc-product-engine'); ?></dt>
                    <dd><code><?php echo esc_html($mode); ?></code></dd>
                    <dt><?php echo esc_html__('Authority epoch', 'wc-product-engine'); ?></dt>
                    <dd><code><?php echo esc_html($epoch === null ? '—' : (string)$epoch); ?></code></dd>
                    <dt><?php echo esc_html__('Default routing', 'wc-product-engine'); ?></dt>
                    <dd><code><?php echo esc_html((string)($flags['cohort_mode'] ?? 'legacy')); ?></code></dd>
                </dl>
                <p class="wpe-help">
                    <?php echo esc_html__('To inspect or change authority use Operations, or WP-CLI:', 'wc-product-engine'); ?>
                    <code>wp wpe health</code>, <code>wp wpe migrate</code>, <code>wp wpe rollback</code>.
                </p>
            </div>

            <div class="wpe-card">
                <h2><?php echo esc_html__('Environment', 'wc-product-engine'); ?></h2>
                <dl class="wpe-dl">
                    <dt>WordPress</dt>
                    <dd><?php echo esc_html(defined('WP_VERSION') ? (string)WP_VERSION : (string)get_bloginfo('version')); ?></dd>
                    <dt>WooCommerce</dt>
                    <dd><?php echo esc_html(defined('WC_VERSION') ? (string)WC_VERSION : __('not detected', 'wc-product-engine')); ?></dd>
                    <dt>PHP</dt>
                    <dd><?php echo esc_html(PHP_VERSION); ?></dd>
                    <dt><?php echo esc_html__('Requirements', 'wc-product-engine'); ?></dt>
                    <dd>
                        <?php if ($errors === []) : ?>
                            <span class="wpe-badge is-ok"><?php echo esc_html__('All met', 'wc-product-engine'); ?></span>
                        <?php else : ?>
                            <ul>
                                <?php foreach ($errors as $error) : ?>
                                    <li><?php echo esc_html((string)$error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>
        </section>
        <?php
    }

    private function levelLabel(string $level): string
    {
        switch ($level) {
            case 'error':
                return __('Error', 'wc-product-engine');
            case 'warning':
                return __('Warning', 'wc-product-engine');
            default:
                return __('Info', 'wc-product-engine');
        }
    }
}
