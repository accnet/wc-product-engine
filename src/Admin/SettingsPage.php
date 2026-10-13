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
    public const TABS = ['overview', 'features', 'maintenance', 'advanced'];

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
            'overview'    => __('Overview', 'wc-product-engine'),
            'features'    => __('Features', 'wc-product-engine'),
            'maintenance' => __('Maintenance', 'wc-product-engine'),
            'advanced'    => __('Advanced', 'wc-product-engine'),
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
                    'text'  => sprintf(__('System health is %s. Review it in Operations.', 'wc-product-engine'), (string)($health['status'] ?? 'unknown')),
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
        $canOps = current_user_can('wpe_manage_operations');
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
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wpe-operations')); ?>">
                        <?php echo esc_html__('Open Operations', 'wc-product-engine'); ?>
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
        $initial = [];
        foreach (SettingsRegistry::flagKeys() as $key) {
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
                <?php foreach (SettingsRegistry::flags() as $key => $def) :
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
                <?php endforeach; ?>
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

    private function renderMaintenance(): void
    {
        $canOps = current_user_can('wpe_manage_operations');
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
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpe-operations')); ?>"><?php echo esc_html__('Operations', 'wc-product-engine'); ?></a>.
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
