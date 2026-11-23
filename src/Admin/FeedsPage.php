<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Application\Feeds\FeedConfig;
use WPE\Application\Feeds\FeedRepository;
use WPE\Application\Feeds\FeedScheduler;
use WPE\Support\Capabilities;

/**
 * WC Product Engine → Product Feeds (09 §11): feed list and the Create/Edit screen.
 *
 * The page is server-rendered with the shared `.wpe-ui` kit; assets/admin/feeds.js saves through
 * the admin REST API (04 §10), renders the list filters, preview and diagnostics, and polls while
 * a build runs. Users without `wpe_manage_operations` see everything read-only and never the URL.
 */
final class FeedsPage
{
    public const SLUG = 'wpe-feeds';
    public const WORKER_STALE_SECONDS = 300;

    public static function render(): void
    {
        (new self())->renderPage();
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce') && !Capabilities::canRunOperations()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wc-product-engine'));
        }
        $feedParam = isset($_GET['feed']) ? sanitize_key(wp_unslash((string)$_GET['feed'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $repo = new FeedRepository();
        $canManage = Capabilities::canRunOperations();

        ?>
        <div class="wrap wpe-admin wpe-ui wpe-feeds" id="wpe-feeds-app">
            <header class="wpe-page-header">
                <p class="wpe-page-header__eyebrow"><?php echo esc_html__('WC Product Engine', 'wc-product-engine'); ?></p>
                <h1 class="wp-heading-inline"><?php echo esc_html__('Product Feeds', 'wc-product-engine'); ?></h1>
                <?php if ($feedParam === '' && $canManage) : ?>
                    <a class="wpe-btn is-primary wpe-feeds__create" href="<?php echo esc_url(self::url(['feed' => 'new'])); ?>"><?php echo esc_html__('Create feed', 'wc-product-engine'); ?></a>
                <?php endif; ?>
            </header>
            <hr class="wp-header-end">

            <div id="wpe-live" class="screen-reader-text" role="status" aria-live="polite"></div>
            <div id="wpe-notice" class="wpe-notice" role="alert" hidden></div>
            <noscript><div class="wpe-notice is-warning"><?php echo esc_html__('JavaScript is required to manage feeds on this screen.', 'wc-product-engine'); ?></div></noscript>

            <?php $this->renderWorkerNotice($repo); ?>

            <?php
            if ($feedParam === '') {
                $this->renderList($repo, $canManage);
            } elseif ($feedParam === 'new') {
                $canManage ? $this->renderEdit(null, $repo, $canManage) : $this->renderForbidden();
            } else {
                $feed = $repo->get((int)$feedParam);
                $feed ? $this->renderEdit($feed, $repo, $canManage) : $this->renderMissing();
            }
            ?>
        </div>
        <?php
    }

    private function renderWorkerNotice(FeedRepository $repo): void
    {
        if ($repo->enabledFeedIds() === []) {
            return;
        }
        $hb = FeedScheduler::lastHeartbeat();
        if ($hb !== null && $hb['age'] <= self::WORKER_STALE_SECONDS) {
            return;
        }
        $fallback = \WPE\Application\Feeds\FeedAutoRunner::enabled();
        $workerText = $hb === null
            ? __('No worker run has been recorded.', 'wc-product-engine')
            /* translators: %s: human time difference, e.g. "2 hours" */
            : sprintf(__('Last worker run %s ago.', 'wc-product-engine'), human_time_diff(time() - (int)$hb['age']));
        if ($fallback) {
            $last = \WPE\Application\Feeds\FeedAutoRunner::lastRun();
            $detail = $last === null
                ? __('Feeds are updated from site requests instead, so publishing can be late while the site is quiet.', 'wc-product-engine')
                /* translators: %s: human time difference, e.g. "2 minutes" */
                : sprintf(__('Feeds are updated from site requests instead (last update %s ago), so publishing can be late while the site is quiet.', 'wc-product-engine'), human_time_diff(time() - (int)$last['age']));
            $cronLead = __('For reliable timing, add this line to the system crontab:', 'wc-product-engine');
        } else {
            $detail = __('Feeds are not built or updated automatically until it runs.', 'wc-product-engine');
            $cronLead = __('Add this line to the system crontab:', 'wc-product-engine');
        }
        ?>
        <div class="wpe-notice is-warning" id="wpe-feeds-worker-notice" data-fallback="<?php echo $fallback ? '1' : '0'; ?>">
            <strong><?php echo esc_html($fallback ? __('No system cron runs the feed worker.', 'wc-product-engine') : __('The feed worker is not running.', 'wc-product-engine')); ?></strong>
            <?php echo esc_html($workerText . ' ' . $detail); ?>
            <?php echo esc_html($cronLead); ?>
            <code>* * * * * cd <?php echo esc_html(untrailingslashit(ABSPATH)); ?> &amp;&amp; wp wpe feeds tick --quiet</code>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* List                                                               */
    /* ------------------------------------------------------------------ */

    private function renderList(FeedRepository $repo, bool $canManage): void
    {
        $feeds = array_map(fn(array $f): array => $repo->view($f, $canManage), $repo->list());
        ?>
        <section class="wpe-panel" aria-labelledby="wpe-h-feeds">
            <h2 id="wpe-h-feeds" class="screen-reader-text"><?php echo esc_html__('Feeds', 'wc-product-engine'); ?></h2>
            <div class="wpe-card wpe-feeds-list">
                <div class="wpe-feeds-toolbar" id="wpe-feeds-toolbar" role="search">
                    <label class="screen-reader-text" for="wpe-feeds-search"><?php echo esc_html__('Search feeds', 'wc-product-engine'); ?></label>
                    <input type="search" id="wpe-feeds-search" class="wpe-input" placeholder="<?php echo esc_attr__('Search feeds…', 'wc-product-engine'); ?>">
                    <label class="screen-reader-text" for="wpe-feeds-platform"><?php echo esc_html__('Platform', 'wc-product-engine'); ?></label>
                    <select id="wpe-feeds-platform" class="wpe-input">
                        <option value=""><?php echo esc_html__('All platforms', 'wc-product-engine'); ?></option>
                        <option value="google">Google</option>
                        <option value="microsoft">Microsoft</option>
                    </select>
                    <label class="screen-reader-text" for="wpe-feeds-status"><?php echo esc_html__('Status', 'wc-product-engine'); ?></label>
                    <select id="wpe-feeds-status" class="wpe-input">
                        <option value=""><?php echo esc_html__('All statuses', 'wc-product-engine'); ?></option>
                        <?php foreach ($this->statusLabels() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wpe-feeds-table-wrap">
                    <table class="wpe-feeds-table" id="wpe-feeds-table">
                        <thead>
                            <tr>
                                <th scope="col"><button type="button" class="wpe-sort" data-sort="name" aria-sort="ascending"><?php echo esc_html__('Name', 'wc-product-engine'); ?></button></th>
                                <th scope="col"><?php echo esc_html__('Platform', 'wc-product-engine'); ?></th>
                                <th scope="col"><?php echo esc_html__('Status', 'wc-product-engine'); ?></th>
                                <th scope="col"><button type="button" class="wpe-sort" data-sort="items"><?php echo esc_html__('Items', 'wc-product-engine'); ?></button></th>
                                <th scope="col"><button type="button" class="wpe-sort" data-sort="published"><?php echo esc_html__('Last published', 'wc-product-engine'); ?></button></th>
                                <th scope="col"><?php echo esc_html__('Enabled', 'wc-product-engine'); ?></th>
                                <th scope="col"><span class="screen-reader-text"><?php echo esc_html__('Actions', 'wc-product-engine'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody id="wpe-feeds-rows"></tbody>
                    </table>
                </div>
                <div class="wpe-empty-hint" id="wpe-feeds-empty" hidden>
                    <p><strong><?php echo esc_html__('No feeds yet', 'wc-product-engine'); ?></strong></p>
                    <p><?php echo esc_html__('Create a feed to publish your catalog to Google Merchant Center or Microsoft Merchant Center.', 'wc-product-engine'); ?></p>
                    <?php if ($canManage) : ?>
                        <a class="wpe-btn is-primary" href="<?php echo esc_url(self::url(['feed' => 'new'])); ?>"><?php echo esc_html__('Create feed', 'wc-product-engine'); ?></a>
                    <?php endif; ?>
                </div>
                <p class="wpe-empty-hint" id="wpe-feeds-nomatch" hidden><?php echo esc_html__('No feeds match these filters.', 'wc-product-engine'); ?></p>
            </div>
        </section>

        <dialog class="wpe-dialog" id="wpe-feeds-delete-dialog" aria-labelledby="wpe-feeds-delete-title">
            <h2 id="wpe-feeds-delete-title"><?php echo esc_html__('Delete feed?', 'wc-product-engine'); ?></h2>
            <p><?php echo esc_html__('The feed URL stops working immediately and Merchant Center can no longer fetch it. Items, files and run history are removed.', 'wc-product-engine'); ?></p>
            <p><strong id="wpe-feeds-delete-name"></strong></p>
            <div class="wpe-dialog__actions">
                <button type="button" class="wpe-btn is-secondary" data-dialog-cancel><?php echo esc_html__('Cancel', 'wc-product-engine'); ?></button>
                <button type="button" class="wpe-btn is-danger" id="wpe-feeds-delete-confirm"><?php echo esc_html__('Delete feed', 'wc-product-engine'); ?></button>
            </div>
        </dialog>

        <script type="application/json" id="wpe-feeds-data"><?php echo wp_json_encode(['feeds' => $feeds]); ?></script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Create / Edit                                                      */
    /* ------------------------------------------------------------------ */

    private function renderEdit(?array $feed, FeedRepository $repo, bool $canManage): void
    {
        $isNew = $feed === null;
        $config = $isNew ? FeedConfig::defaults() : $feed['config'];
        $view = $isNew ? null : $repo->view($feed, $canManage);
        $platform = $isNew ? 'google' : $feed['platform'];
        $format = $isNew ? 'xml' : $feed['format'];
        $ro = $canManage ? '' : ' disabled';
        $categories = $this->categoryOptions();
        ?>
        <p><a href="<?php echo esc_url(self::url()); ?>">&larr; <?php echo esc_html__('All feeds', 'wc-product-engine'); ?></a></p>

        <?php if (!$isNew) : ?>
            <?php $this->renderStatusCard($view, $canManage); ?>
            <nav class="wpe-tabs" aria-label="<?php echo esc_attr__('Feed sections', 'wc-product-engine'); ?>">
                <a class="wpe-tab is-active" href="#settings" data-feed-tab="settings" aria-current="page"><?php echo esc_html__('Settings', 'wc-product-engine'); ?></a>
                <a class="wpe-tab" href="#preview" data-feed-tab="preview"><?php echo esc_html__('Preview', 'wc-product-engine'); ?></a>
                <a class="wpe-tab" href="#diagnostics" data-feed-tab="diagnostics"><?php echo esc_html__('Diagnostics', 'wc-product-engine'); ?></a>
            </nav>
        <?php endif; ?>

        <form id="wpe-feed-form" class="wpe-panel wpe-feed-form" data-feed-panel="settings" novalidate
              data-feed-id="<?php echo esc_attr($isNew ? '' : (string)$feed['id']); ?>"
              data-version="<?php echo esc_attr($isNew ? '' : (string)$feed['version']); ?>">

            <div class="wpe-card wpe-settings-group">
                <div class="wpe-settings-group__header"><h3><?php echo esc_html__('General', 'wc-product-engine'); ?></h3></div>
                <?php $this->field('name', __('Name', 'wc-product-engine'), sprintf('<input type="text" class="wpe-input" id="wpe-f-name" data-path="name" maxlength="191" required value="%s"%s>', esc_attr($isNew ? '' : $feed['name']), $ro), __('Shown in this list only.', 'wc-product-engine')); ?>
                <?php
                $this->field('platform', __('Platform', 'wc-product-engine'), $this->select('wpe-f-platform', 'platform', ['google' => 'Google Merchant Center', 'microsoft' => 'Microsoft Merchant Center'], $platform, $ro));
                $formatOptions = ['xml' => __('XML (RSS 2.0)', 'wc-product-engine'), 'tsv' => __('TSV (tab-separated)', 'wc-product-engine')];
                $this->field('format', __('Format', 'wc-product-engine'), $this->select('wpe-f-format', 'format', $formatOptions, $format, $ro), __('Microsoft reads TSV, and Google-formatted XML.', 'wc-product-engine'));
                $this->field('enabled', __('Enabled', 'wc-product-engine'), $this->toggle('wpe-f-enabled', 'enabled', $isNew ? true : (bool)$feed['enabled'], $ro), __('Disabled feeds are not updated and their URL returns 404.', 'wc-product-engine'));
                $this->field('compress', __('Gzip copy', 'wc-product-engine'), $this->toggle('wpe-f-compress', 'config.compress', !empty($config['compress']), $ro), __('Also publish a .gz file (smaller downloads).', 'wc-product-engine'));
                ?>
            </div>

            <div class="wpe-card wpe-settings-group">
                <div class="wpe-settings-group__header"><h3><?php echo esc_html__('Products', 'wc-product-engine'); ?></h3>
                    <p class="wpe-help"><?php echo esc_html__('Only published, visible simple and variable products are exported.', 'wc-product-engine'); ?></p></div>
                <?php
                $this->field('variation_mode', __('Variations', 'wc-product-engine'), $this->select('wpe-f-variation-mode', 'config.variation_mode', [
                    'variants' => __('One item per variation (recommended)', 'wc-product-engine'),
                    'parent'   => __('One item per product (lowest price)', 'wc-product-engine'),
                ], $config['variation_mode'], $ro), __('Per-variation items carry color/size and link to the exact variation.', 'wc-product-engine'));
                $this->field('append', __('Variation titles', 'wc-product-engine'), $this->toggle('wpe-f-append', 'config.title.append_variant_values', !empty($config['title']['append_variant_values']), $ro), __('Append values to the title, e.g. "Shirt - Red / XL".', 'wc-product-engine'));
                $this->field('include_categories', __('Only these categories', 'wc-product-engine'), $this->multiSelect('wpe-f-include', 'config.filters.include_categories', $categories, $config['filters']['include_categories'], $ro), __('Empty means all categories. Sub-categories are included.', 'wc-product-engine'));
                $this->field('exclude_categories', __('Exclude categories', 'wc-product-engine'), $this->multiSelect('wpe-f-exclude', 'config.filters.exclude_categories', $categories, $config['filters']['exclude_categories'], $ro));
                $this->field('oos', __('Out-of-stock items', 'wc-product-engine'), $this->toggle('wpe-f-oos', 'config.filters.include_out_of_stock', !empty($config['filters']['include_out_of_stock']), $ro), __('Keep them in the feed as out_of_stock (recommended by Google).', 'wc-product-engine'));
                $this->field('require_image', __('Skip items without image', 'wc-product-engine'), $this->toggle('wpe-f-image', 'config.filters.require_image', !empty($config['filters']['require_image']), $ro), __('Off: they are reported as errors instead.', 'wc-product-engine'));
                ?>
            </div>

            <div class="wpe-card wpe-settings-group">
                <div class="wpe-settings-group__header"><h3><?php echo esc_html__('Attribute mapping', 'wc-product-engine'); ?></h3>
                    <p class="wpe-help"><?php echo esc_html__('ID, title, description, link, images, price and availability always come from the product engine.', 'wc-product-engine'); ?></p></div>
                <div class="wpe-feeds-table-wrap">
                    <table class="wpe-feeds-table wpe-mapping-table">
                        <thead><tr>
                            <th scope="col"><?php echo esc_html__('Attribute', 'wc-product-engine'); ?></th>
                            <th scope="col"><?php echo esc_html__('Source', 'wc-product-engine'); ?></th>
                            <th scope="col"><?php echo esc_html__('Value', 'wc-product-engine'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (FeedConfig::MAPPED_ATTRIBUTES as $attr => $sources) : $rule = $config['mapping'][$attr]; ?>
                            <tr data-mapping="<?php echo esc_attr($attr); ?>">
                                <th scope="row"><code><?php echo esc_html($attr); ?></code></th>
                                <td>
                                    <label class="screen-reader-text" for="wpe-map-<?php echo esc_attr($attr); ?>-source"><?php echo esc_html(sprintf(/* translators: %s: attribute */ __('%s source', 'wc-product-engine'), $attr)); ?></label>
                                    <select class="wpe-input" id="wpe-map-<?php echo esc_attr($attr); ?>-source" data-path="config.mapping.<?php echo esc_attr($attr); ?>.source"<?php echo $ro; ?>>
                                        <?php foreach ($sources as $src) : ?>
                                            <option value="<?php echo esc_attr($src); ?>" <?php selected($rule['source'], $src); ?>><?php echo esc_html($this->sourceLabel($src, $attr)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <label class="screen-reader-text" for="wpe-map-<?php echo esc_attr($attr); ?>-value"><?php echo esc_html(sprintf(/* translators: %s: attribute */ __('%s value', 'wc-product-engine'), $attr)); ?></label>
                                    <input type="text" class="wpe-input" id="wpe-map-<?php echo esc_attr($attr); ?>-value" data-path="config.mapping.<?php echo esc_attr($attr); ?>.value" value="<?php echo esc_attr($rule['value']); ?>" placeholder="<?php echo esc_attr($this->valueHint($attr)); ?>"<?php echo $ro; ?>>
                                    <p class="wpe-field-error" data-error-for="config.mapping.<?php echo esc_attr($attr); ?>" hidden></p>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php $this->field('identifier', __('No GTIN or MPN', 'wc-product-engine'), $this->toggle('wpe-f-idx', 'config.identifier_exists_fallback', !empty($config['identifier_exists_fallback']), $ro), __('Send identifier_exists = no when an item has neither a GTIN nor brand + MPN.', 'wc-product-engine')); ?>
                <div class="wpe-field wpe-category-map">
                    <span class="wpe-field__label"><?php echo esc_html__('Google product category by store category', 'wc-product-engine'); ?></span>
                    <p class="wpe-help"><?php echo esc_html__('The nearest mapped category of a product wins. Use the Google taxonomy ID or full path.', 'wc-product-engine'); ?></p>
                    <div id="wpe-category-map-rows">
                        <?php foreach ($config['category_map'] as $termId => $value) : ?>
                            <?php $this->categoryMapRow($categories, (int)$termId, (string)$value, $ro); ?>
                        <?php endforeach; ?>
                    </div>
                    <template id="wpe-category-map-template"><?php $this->categoryMapRow($categories, 0, '', ''); ?></template>
                    <?php if ($canManage) : ?>
                        <button type="button" class="wpe-btn is-secondary wpe-btn--sm" id="wpe-category-map-add"><?php echo esc_html__('Add category mapping', 'wc-product-engine'); ?></button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wpe-card wpe-settings-group">
                <div class="wpe-settings-group__header"><h3><?php echo esc_html__('Price, images and shipping', 'wc-product-engine'); ?></h3></div>
                <div class="wpe-field wpe-feed-block">
                    <span class="wpe-field__label"><?php echo esc_html__('Currency', 'wc-product-engine'); ?></span>
                    <p><code><?php echo esc_html(FeedConfig::storeCurrency()); ?></code> <span class="wpe-help"><?php echo esc_html__('Store currency; feeds do not convert prices.', 'wc-product-engine'); ?></span></p>
                    <p class="wpe-notice is-warning" id="wpe-tax-warning" hidden><?php echo esc_html__('This market expects prices that include tax, but the store prices exclude it. Merchant Center may reject the prices.', 'wc-product-engine'); ?></p>
                </div>
                <?php
                $this->field('sale', __('Sale prices', 'wc-product-engine'), $this->toggle('wpe-f-sale', 'config.price.include_sale', !empty($config['price']['include_sale']), $ro), __('Send sale_price and its date range while a sale is active.', 'wc-product-engine'));
                $this->field('images', __('Additional images', 'wc-product-engine'), sprintf('<input type="number" class="wpe-input wpe-input--short" id="wpe-f-images" data-path="config.images.max_additional" min="0" max="10" value="%d"%s>', (int)$config['images']['max_additional'], $ro), __('Up to 10. Variation images come first.', 'wc-product-engine'));
                $this->field('shipping_mode', __('Shipping', 'wc-product-engine'), $this->select('wpe-f-ship-mode', 'config.shipping.mode', [
                    'account' => __('Use Merchant Center shipping settings', 'wc-product-engine'),
                    'flat'    => __('Flat rate in the feed', 'wc-product-engine'),
                ], $config['shipping']['mode'], $ro));
                ?>
                <div class="wpe-feeds-flat" id="wpe-flat-shipping"<?php echo $config['shipping']['mode'] === 'flat' ? '' : ' hidden'; ?>>
                    <?php
                    $this->field('ship_country', __('Shipping country', 'wc-product-engine'), sprintf('<input type="text" class="wpe-input wpe-input--short" id="wpe-f-ship-country" data-path="config.shipping.country" maxlength="2" value="%s"%s>', esc_attr($config['shipping']['country']), $ro));
                    $this->field('ship_service', __('Service', 'wc-product-engine'), sprintf('<input type="text" class="wpe-input" id="wpe-f-ship-service" data-path="config.shipping.service" value="%s"%s>', esc_attr($config['shipping']['service']), $ro));
                    $this->field('ship_price', __('Price', 'wc-product-engine'), sprintf('<input type="text" class="wpe-input wpe-input--short" id="wpe-f-ship-price" data-path="config.shipping.price" inputmode="decimal" value="%s"%s>', esc_attr($config['shipping']['price']), $ro));
                    ?>
                </div>
                <?php $this->field('weight', __('Shipping weight', 'wc-product-engine'), $this->toggle('wpe-f-weight', 'config.shipping.export_weight', !empty($config['shipping']['export_weight']), $ro), __('Export product weight as shipping_weight.', 'wc-product-engine')); ?>
            </div>

            <div class="wpe-card wpe-settings-group">
                <div class="wpe-settings-group__header"><h3><?php echo esc_html__('Market', 'wc-product-engine'); ?></h3></div>
                <?php
                $this->field('country', __('Target country', 'wc-product-engine'), sprintf('<input type="text" class="wpe-input wpe-input--short" id="wpe-f-country" data-path="config.market.country" maxlength="2" value="%s"%s>', esc_attr($config['market']['country']), $ro), __('Two-letter code, e.g. US.', 'wc-product-engine'));
                $this->field('language', __('Language', 'wc-product-engine'), sprintf('<input type="text" class="wpe-input wpe-input--short" id="wpe-f-language" data-path="config.market.language" maxlength="2" value="%s"%s>', esc_attr($config['market']['language']), $ro), __('Two-letter code, e.g. en.', 'wc-product-engine'));
                ?>
            </div>

            <div class="wpe-card wpe-settings-group">
                <div class="wpe-settings-group__header"><h3><?php echo esc_html__('Updates', 'wc-product-engine'); ?></h3>
                    <p class="wpe-help"><?php echo esc_html__('Changed products are re-rendered by the worker; a new file is published once changes settle. Merchant Center fetches the file on its own schedule (at most daily).', 'wc-product-engine'); ?></p></div>
                <?php
                $s = $config['schedule'];
                $this->field('mode', __('Publishing', 'wc-product-engine'), $this->select('wpe-f-mode', 'config.schedule.mode', [
                    'auto'   => __('Automatic (after changes settle)', 'wc-product-engine'),
                    'manual' => __('Manual only (Publish now)', 'wc-product-engine'),
                ], $s['mode'], $ro));
                $this->field('quiet', __('Quiet window (seconds)', 'wc-product-engine'), sprintf('<input type="number" class="wpe-input wpe-input--short" id="wpe-f-quiet" data-path="config.schedule.quiet_seconds" min="30" max="3600" value="%d"%s>', (int)$s['quiet_seconds'], $ro), __('Publish after no change for this long.', 'wc-product-engine'));
                $this->field('max_wait', __('Max wait (seconds)', 'wc-product-engine'), sprintf('<input type="number" class="wpe-input wpe-input--short" id="wpe-f-maxwait" data-path="config.schedule.max_wait_seconds" min="60" max="86400" value="%d"%s>', (int)$s['max_wait_seconds'], $ro), __('Publish at the latest this long after the first change.', 'wc-product-engine'));
                $this->field('min_interval', __('Minimum interval (seconds)', 'wc-product-engine'), sprintf('<input type="number" class="wpe-input wpe-input--short" id="wpe-f-interval" data-path="config.schedule.min_interval_seconds" min="60" max="86400" value="%d"%s>', (int)$s['min_interval_seconds'], $ro), __('Never publish more often than this.', 'wc-product-engine'));
                $this->field('drop', __('Item drop guard (%)', 'wc-product-engine'), sprintf('<input type="number" class="wpe-input wpe-input--short" id="wpe-f-drop" data-path="config.schedule.max_drop_percent" min="1" max="100" value="%d"%s>', (int)$s['max_drop_percent'], $ro), __('Keep the last file when the item count drops by more than this.', 'wc-product-engine'));
                ?>
            </div>

            <?php if ($canManage) : ?>
                <div class="wpe-feeds-savebar">
                    <span class="wpe-help" id="wpe-feed-rebuild-hint" hidden><?php echo esc_html__('Saving will rebuild all items.', 'wc-product-engine'); ?></span>
                    <button type="submit" class="wpe-btn is-primary" id="wpe-feed-save"><?php echo esc_html($isNew ? __('Create feed', 'wc-product-engine') : __('Save changes', 'wc-product-engine')); ?></button>
                </div>
            <?php endif; ?>
        </form>

        <?php if (!$isNew) : ?>
            <section class="wpe-panel" data-feed-panel="preview" hidden aria-labelledby="wpe-h-preview">
                <div class="wpe-card">
                    <h3 id="wpe-h-preview"><?php echo esc_html__('Preview (first 20 items)', 'wc-product-engine'); ?></h3>
                    <div id="wpe-preview-body"><p class="wpe-help"><?php echo esc_html__('Loading…', 'wc-product-engine'); ?></p></div>
                </div>
            </section>
            <section class="wpe-panel" data-feed-panel="diagnostics" hidden aria-labelledby="wpe-h-diag">
                <div class="wpe-card">
                    <h3 id="wpe-h-diag"><?php echo esc_html__('Diagnostics', 'wc-product-engine'); ?></h3>
                    <div id="wpe-diagnostics-body"><p class="wpe-help"><?php echo esc_html__('Loading…', 'wc-product-engine'); ?></p></div>
                </div>
            </section>
        <?php endif; ?>

        <script type="application/json" id="wpe-feed-data"><?php echo wp_json_encode([
            'feed'            => $view,
            'taxExclusiveMarkets' => ['US', 'CA', 'IN'],
            'storeTaxInclusive' => get_option('woocommerce_calc_taxes') === 'yes' && get_option('woocommerce_prices_include_tax') === 'yes',
            'renderPaths'     => ['platform', 'format', 'config.variation_mode', 'config.title', 'config.filters', 'config.mapping', 'config.category_map',
                'config.identifier_exists_fallback', 'config.price', 'config.images', 'config.shipping', 'config.market'],
        ]); ?></script>
        <?php
    }

    private function renderStatusCard(array $view, bool $canManage): void
    {
        $stats = $view['stats'];
        $published = (array)($stats['published'] ?? []);
        $build = (array)($stats['build'] ?? []);
        ?>
        <div class="wpe-card wpe-feed-status" id="wpe-feed-status">
            <div class="wpe-feed-status__head">
                <h2><?php echo esc_html($view['name']); ?></h2>
                <?php echo $this->statusBadge($view['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in statusBadge ?>
            </div>
            <dl class="wpe-dl">
                <?php if ($canManage && $view['url']) : ?>
                    <dt><?php echo esc_html__('Feed URL', 'wc-product-engine'); ?></dt>
                    <dd class="wpe-feed-url">
                        <input type="text" class="wpe-input" id="wpe-feed-url" readonly value="<?php echo esc_attr($view['url']); ?>" aria-label="<?php echo esc_attr__('Feed URL', 'wc-product-engine'); ?>">
                        <button type="button" class="wpe-btn is-secondary wpe-btn--sm" data-copy="#wpe-feed-url"><?php echo esc_html__('Copy URL', 'wc-product-engine'); ?></button>
                        <button type="button" class="wpe-btn is-ghost wpe-btn--sm" id="wpe-feed-rotate"><?php echo esc_html__('New URL', 'wc-product-engine'); ?></button>
                    </dd>
                <?php endif; ?>
                <dt><?php echo esc_html__('Last file', 'wc-product-engine'); ?></dt>
                <dd id="wpe-feed-last-file"><?php
                    echo $published
                        ? esc_html(sprintf(
                            /* translators: 1: item count, 2: size, 3: date */
                            __('%1$s items · %2$s · %3$s UTC', 'wc-product-engine'),
                            number_format_i18n((int)$published['items']),
                            size_format((int)$published['bytes'], 1),
                            (string)$published['at']
                        ))
                        : esc_html__('Not published yet', 'wc-product-engine');
                ?></dd>
                <dt><?php echo esc_html__('Progress', 'wc-product-engine'); ?></dt>
                <dd id="wpe-feed-progress"><?php
                    echo $build
                        ? esc_html(sprintf(/* translators: 1: done, 2: total */ __('Building: %1$s of %2$s products', 'wc-product-engine'), number_format_i18n((int)($build['done'] ?? 0)), number_format_i18n((int)($build['total'] ?? 0))))
                        : ($view['rebuild_pending'] ? esc_html__('Full rebuild queued for the worker', 'wc-product-engine') : esc_html__('Up to date with the item store', 'wc-product-engine'));
                ?></dd>
                <?php if (!empty($view['last_error'])) : ?>
                    <dt><?php echo esc_html__('Last error', 'wc-product-engine'); ?></dt>
                    <dd class="wpe-feed-error" id="wpe-feed-error"><?php echo esc_html((string)$view['last_error']); ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ($canManage) : ?>
                <div class="wpe-feed-status__actions">
                    <button type="button" class="wpe-btn is-secondary" data-regenerate="publish"><?php echo esc_html__('Publish now', 'wc-product-engine'); ?></button>
                    <button type="button" class="wpe-btn is-secondary" data-regenerate="rebuild"><?php echo esc_html__('Full rebuild', 'wc-product-engine'); ?></button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderForbidden(): void
    {
        echo '<div class="wpe-notice is-error">' . esc_html__('You can view feeds but not create or change them.', 'wc-product-engine') . '</div>';
    }

    private function renderMissing(): void
    {
        echo '<div class="wpe-notice is-error">' . esc_html__('This feed does not exist.', 'wc-product-engine') . ' <a href="' . esc_url(self::url()) . '">' . esc_html__('All feeds', 'wc-product-engine') . '</a></div>';
    }

    /* ------------------------------------------------------------------ */
    /* Form helpers                                                       */
    /* ------------------------------------------------------------------ */

    private function field(string $key, string $label, string $control, string $help = ''): void
    {
        preg_match('/id="([^"]+)"/', $control, $m);
        $path = preg_match('/data-path="([^"]+)"/', $control, $p) ? $p[1] : $key;
        ?>
        <div class="wpe-field wpe-feed-field">
            <label class="wpe-field__label" for="<?php echo esc_attr($m[1] ?? ''); ?>"><?php echo esc_html($label); ?></label>
            <div class="wpe-feed-field__control">
                <?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts ?>
                <?php if ($help !== '') : ?><p class="wpe-help"><?php echo esc_html($help); ?></p><?php endif; ?>
                <p class="wpe-field-error" data-error-for="<?php echo esc_attr($path); ?>" hidden></p>
            </div>
        </div>
        <?php
    }

    private function select(string $id, string $path, array $options, string $value, string $ro): string
    {
        $html = sprintf('<select class="wpe-input" id="%s" data-path="%s"%s>', esc_attr($id), esc_attr($path), $ro);
        foreach ($options as $v => $label) {
            $html .= sprintf('<option value="%s"%s>%s</option>', esc_attr((string)$v), selected($value, (string)$v, false), esc_html($label));
        }
        return $html . '</select>';
    }

    private function multiSelect(string $id, string $path, array $options, array $values, string $ro): string
    {
        $html = sprintf('<select class="wpe-input wpe-input--multi" id="%s" data-path="%s" data-type="ids" multiple size="6"%s>', esc_attr($id), esc_attr($path), $ro);
        foreach ($options as $v => $label) {
            $html .= sprintf('<option value="%d"%s>%s</option>', (int)$v, in_array((int)$v, array_map('intval', $values), true) ? ' selected' : '', esc_html($label));
        }
        return $html . '</select>';
    }

    private function toggle(string $id, string $path, bool $checked, string $ro): string
    {
        return sprintf(
            '<span class="wpe-switch"><input type="checkbox" role="switch" id="%s" data-path="%s"%s%s><span class="wpe-switch__track" aria-hidden="true"></span></span>',
            esc_attr($id),
            esc_attr($path),
            $checked ? ' checked' : '',
            $ro
        );
    }

    private function categoryMapRow(array $categories, int $termId, string $value, string $ro): void
    {
        ?>
        <div class="wpe-category-map__row" data-category-map-row>
            <select class="wpe-input" data-category-map-term aria-label="<?php echo esc_attr__('Store category', 'wc-product-engine'); ?>"<?php echo $ro; ?>>
                <option value=""><?php echo esc_html__('Choose a category', 'wc-product-engine'); ?></option>
                <?php foreach ($categories as $id => $label) : ?>
                    <option value="<?php echo (int)$id; ?>" <?php selected($termId, (int)$id); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" class="wpe-input" data-category-map-value value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr__('e.g. 212 or Apparel & Accessories > Clothing', 'wc-product-engine'); ?>" aria-label="<?php echo esc_attr__('Google product category', 'wc-product-engine'); ?>"<?php echo $ro; ?>>
            <?php if ($ro === '') : ?>
                <button type="button" class="wpe-btn is-ghost wpe-btn--sm" data-category-map-remove aria-label="<?php echo esc_attr__('Remove mapping', 'wc-product-engine'); ?>">&times;</button>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @return array<int,string> term_id => "Parent > Child" for categories with products */
    private function categoryOptions(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 2000]);
        if (is_wp_error($terms)) {
            return [];
        }
        $byId = [];
        foreach ($terms as $t) {
            $byId[(int)$t->term_id] = $t;
        }
        $out = [];
        foreach ($byId as $id => $t) {
            $names = [html_entity_decode($t->name)];
            $parent = (int)$t->parent;
            $guard = 0;
            while ($parent > 0 && isset($byId[$parent]) && $guard++ < 10) {
                array_unshift($names, html_entity_decode($byId[$parent]->name));
                $parent = (int)$byId[$parent]->parent;
            }
            $out[$id] = implode(' > ', $names);
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    private function sourceLabel(string $source, string $attr): string
    {
        return match ($source) {
            'default'  => __('Automatic', 'wc-product-engine'),
            'static'   => __('Fixed value', 'wc-product-engine'),
            'field'    => __('Product field', 'wc-product-engine'),
            'taxonomy' => __('Taxonomy', 'wc-product-engine'),
            'option'   => __('Product option', 'wc-product-engine'),
            'meta'     => __('Custom field (meta)', 'wc-product-engine'),
            'none'     => __('Do not send', 'wc-product-engine'),
            default    => $source,
        };
    }

    private function valueHint(string $attr): string
    {
        return match ($attr) {
            'brand'     => __('Brand name, taxonomy, option or meta key', 'wc-product-engine'),
            'condition' => 'new | refurbished | used',
            'gender'    => 'male | female | unisex',
            'age_group' => 'newborn | infant | toddler | kids | adult',
            'mpn'       => 'sku | parent_sku',
            default     => '',
        };
    }

    /** @return array<string,string> */
    public function statusLabels(): array
    {
        return [
            'new'      => __('New', 'wc-product-engine'),
            'building' => __('Building', 'wc-product-engine'),
            'ready'    => __('Ready', 'wc-product-engine'),
            'error'    => __('Error', 'wc-product-engine'),
            'disabled' => __('Disabled', 'wc-product-engine'),
        ];
    }

    public function statusBadge(string $status): string
    {
        $class = match ($status) {
            'ready'    => 'is-ok',
            'error'    => 'is-error',
            'building', 'new' => 'is-warning',
            default    => 'is-muted',
        };
        return sprintf('<span class="wpe-badge %s" data-status-badge>%s</span>', esc_attr($class), esc_html($this->statusLabels()[$status] ?? $status));
    }
}
