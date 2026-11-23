<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;
use WPE\Support\Singleton;

/**
 * ShopifyImportPage
 *
 * Provides the interactive, resilient CSV Import screen:
 * - Large file handling without timeouts via chunked batching.
 * - Pause and Resume capability with persisted byte-offset checkpoints.
 * - Live terminal log console & downloadable log file.
 * - Direct server file path or client-side upload support.
 */
class ShopifyImportPage
{
    use Singleton;

    private \wpdb $wpdb;

    public function __construct(?\wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
    }

    public static function render(): void
    {
        self::getInstance()->renderPage();
    }

    public function renderPage(): void
    {
        if (!current_user_can('wpe_import_products') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this import operation.', 'wc-product-engine'));
        }

        $batchImporter = ShopifyBatchImporter::getInstance();
        $activeJob = $batchImporter->getActiveJob();
        $isRunning = $activeJob && ($activeJob['status'] ?? '') === 'in_progress';
        ?>
        <div class="wrap wpe-ui wpe-import-wrap">
            <header class="wpe-page-header">
                <p class="wpe-page-header__eyebrow"><?php echo esc_html__('WC Product Engine', 'wc-product-engine'); ?></p>
                <h1 class="wp-heading-inline"><?php echo esc_html__('CSV Import', 'wc-product-engine'); ?></h1>
                <p class="wpe-help wpe-import-lead">
                    <?php echo esc_html__('Import product catalogs from Shopify-formatted CSV files. Resilient against interruptions on large files, chunked batch execution by byte offsets with Pause, Resume, and real-time live console logging.', 'wc-product-engine'); ?>
                </p>
            </header>
            <hr class="wp-header-end">

            <!-- Active / Resumable Job Banner (if exists) -->
            <div id="wpe-active-job-banner" class="wpe-notice is-warning wpe-import-banner<?php echo $activeJob ? '' : ' wpe-hidden'; ?>">
                <div class="wpe-import-banner__text">
                    <strong><?php echo esc_html__('Unfinished import job detected:', 'wc-product-engine'); ?></strong>
                    <span id="wpe-active-job-details">
                        <?php if ($activeJob): ?>
                            <?php printf(
                                esc_html__('File: %s (%s) — Status: %s — Processed: %d products, %d variants (%s%%)', 'wc-product-engine'),
                                '<code>' . esc_html($activeJob['original_filename']) . '</code>',
                                esc_html($activeJob['file_size_fmt']),
                                '<strong>' . esc_html(strtoupper($activeJob['status'])) . '</strong>',
                                (int)$activeJob['products_processed'],
                                (int)$activeJob['variants_processed'],
                                (string)$activeJob['percent']
                            ); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="wpe-import-banner__actions">
                    <button type="button" id="wpe-resume-active-job-btn" class="wpe-btn is-primary" data-job-id="<?php echo esc_attr($activeJob['job_id'] ?? ''); ?>">
                        <?php echo esc_html__('Resume this job', 'wc-product-engine'); ?>
                    </button>
                    <button type="button" id="wpe-discard-active-job-btn" class="wpe-btn is-secondary" data-job-id="<?php echo esc_attr($activeJob['job_id'] ?? ''); ?>">
                        <?php echo esc_html__('Discard previous job', 'wc-product-engine'); ?>
                    </button>
                </div>
            </div>

            <!-- Top Grid: Configuration & File Selection -->
            <div id="wpe-import-setup-section" class="<?php echo $isRunning ? 'wpe-hidden' : ''; ?>">
                <div class="wpe-import-grid">
                    <!-- Left: File Selector -->
                    <section class="wpe-card wpe-import-card" aria-labelledby="wpe-import-h-file">
                        <h2 id="wpe-import-h-file"><span class="wpe-import-step" aria-hidden="true">1</span><?php echo esc_html__('Select Shopify CSV File', 'wc-product-engine'); ?></h2>

                        <!-- Dropzone -->
                        <div id="wpe-dropzone" class="wpe-import-dropzone">
                            <span class="dashicons dashicons-cloud-upload" aria-hidden="true"></span>
                            <p class="wpe-import-dropzone-title">
                                <strong><?php echo esc_html__('Drag & drop CSV file here or click to browse', 'wc-product-engine'); ?></strong>
                            </p>
                            <p class="wpe-help wpe-import-dropzone-hint">
                                <?php echo esc_html__('Supports UTF-8, UTF-8 BOM, unlimited file size.', 'wc-product-engine'); ?>
                            </p>
                            <input type="file" id="wpe-csv-file-input" class="wpe-hidden" accept=".csv">
                        </div>

                        <?php if (current_user_can('manage_options')) : ?>
                        <!-- Or Server Path (administrators only, allowed directories only) -->
                        <div class="wpe-import-server-path">
                            <label class="wpe-field__label" for="wpe-server-path-input">
                                <?php echo esc_html__('Or direct file path on server:', 'wc-product-engine'); ?>
                            </label>
                            <div class="wpe-import-server-path-row">
                                <input type="text" id="wpe-server-path-input" class="wpe-input" placeholder="/path/to/theme-performance-shop-product-data.csv" value="" aria-describedby="wpe-server-path-desc">
                                <button type="button" id="wpe-use-server-file-btn" class="wpe-btn is-secondary">
                                    <?php echo esc_html__('Validate File', 'wc-product-engine'); ?>
                                </button>
                            </div>
                            <p class="wpe-help" id="wpe-server-path-desc">
                                <?php echo esc_html__('For very large CSV files already present on the server without uploading via browser. The file must be a .csv inside wp-content/uploads/wpe-imports/ (or a directory added with the wpe_import_server_path_roots filter).', 'wc-product-engine'); ?>
                            </p>
                        </div>
                        <?php endif; ?>

                        <!-- File Selected Info -->
                        <div id="wpe-selected-file-info" class="wpe-import-selected-file wpe-hidden">
                            <div class="wpe-import-selected-name" id="wpe-selected-filename"></div>
                            <div class="wpe-import-selected-meta" id="wpe-selected-meta"></div>
                        </div>
                    </section>

                    <!-- Right: Import Options -->
                    <section class="wpe-card wpe-import-card" aria-labelledby="wpe-import-h-config">
                        <h2 id="wpe-import-h-config"><span class="wpe-import-step" aria-hidden="true">2</span><?php echo esc_html__('Processing Configuration', 'wc-product-engine'); ?></h2>

                        <div class="wpe-import-fields">
                            <div class="wpe-field">
                                <label class="wpe-field__label" for="wpe-opt-update-mode"><?php echo esc_html__('Update Mode', 'wc-product-engine'); ?></label>
                                <select id="wpe-opt-update-mode" class="wpe-input wpe-import-select">
                                    <option value="merge" selected>
                                        <?php echo esc_html__('Merge & Update (Preserve unmentioned variants)', 'wc-product-engine'); ?>
                                    </option>
                                    <option value="replace">
                                        <?php echo esc_html__('Full Replace', 'wc-product-engine'); ?>
                                    </option>
                                </select>
                            </div>

                            <div class="wpe-field">
                                <label class="wpe-field__label" for="wpe-opt-image-mode"><?php echo esc_html__('Media Handling', 'wc-product-engine'); ?></label>
                                <select id="wpe-opt-image-mode" class="wpe-input wpe-import-select">
                                    <option value="external" selected>
                                        <?php echo esc_html__('External CDN (Fast, zero local disk usage)', 'wc-product-engine'); ?>
                                    </option>
                                    <option value="download">
                                        <?php echo esc_html__('Download to Media Library (Slower)', 'wc-product-engine'); ?>
                                    </option>
                                </select>
                            </div>

                            <div class="wpe-field">
                                <label class="wpe-field__label" for="wpe-opt-category-mode"><?php echo esc_html__('Product Category', 'wc-product-engine'); ?></label>
                                <select id="wpe-opt-category-mode" class="wpe-input wpe-import-select" aria-describedby="wpe-opt-category-mode-desc">
                                    <option value="none" selected>
                                        <?php echo esc_html__('Do not map (default category only)', 'wc-product-engine'); ?>
                                    </option>
                                    <option value="path">
                                        <?php echo esc_html__('Create category tree from path (A > B > C)', 'wc-product-engine'); ?>
                                    </option>
                                </select>
                                <p class="wpe-help" id="wpe-opt-category-mode-desc">
                                    <?php echo esc_html__('Uses the Shopify "Product category" column. Products are filed under the last level; parent categories list them too.', 'wc-product-engine'); ?>
                                </p>
                            </div>

                            <div class="wpe-field">
                                <label class="wpe-field__label" for="wpe-opt-batch-size"><?php echo esc_html__('Batch Size', 'wc-product-engine'); ?></label>
                                <select id="wpe-opt-batch-size" class="wpe-input wpe-import-select" aria-describedby="wpe-opt-batch-size-desc">
                                    <option value="15">15 <?php echo esc_html__('products / request', 'wc-product-engine'); ?></option>
                                    <option value="25" selected>25 <?php echo esc_html__('products / request (Recommended)', 'wc-product-engine'); ?></option>
                                    <option value="50">50 <?php echo esc_html__('products / request (Fast server)', 'wc-product-engine'); ?></option>
                                    <option value="100">100 <?php echo esc_html__('products / request', 'wc-product-engine'); ?></option>
                                </select>
                                <p class="wpe-help" id="wpe-opt-batch-size-desc">
                                    <?php echo esc_html__('Each HTTP request processes 1 chunk batch (~2-3s per batch), eliminating 504 Timeout errors.', 'wc-product-engine'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="wpe-import-start-row">
                            <button type="button" id="wpe-start-import-btn" class="wpe-btn is-primary is-large wpe-import-start-btn" disabled>
                                <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                                <?php echo esc_html__('Start Import', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Progress & Dashboard Section -->
            <div id="wpe-import-progress-section" class="wpe-import-progress-section wpe-hidden">
                <div class="wpe-card wpe-import-card">
                    <div class="wpe-import-progress-header">
                        <div>
                            <h2 class="wpe-import-progress-title">
                                <span class="spinner is-active wpe-import-spinner" id="wpe-progress-spinner"></span>
                                <?php echo esc_html__('Product Import Progress', 'wc-product-engine'); ?>
                            </h2>
                            <p class="wpe-help wpe-import-status-desc" id="wpe-job-status-desc" role="status">
                                <?php echo esc_html__('Processing batch chunks...', 'wc-product-engine'); ?>
                            </p>
                        </div>

                        <!-- Action Controls: Pause / Resume / Cancel / Download Log -->
                        <div class="wpe-import-actions">
                            <button type="button" id="wpe-pause-btn" class="wpe-btn is-secondary">
                                <span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
                                <?php echo esc_html__('Pause', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-resume-btn" class="wpe-btn is-primary wpe-hidden">
                                <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                                <?php echo esc_html__('Resume', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-cancel-btn" class="wpe-btn is-danger">
                                <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
                                <?php echo esc_html__('Cancel', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-download-log-btn" class="wpe-btn is-secondary">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php echo esc_html__('Download Log File (.log)', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="wpe-import-progress-wrap">
                        <div class="wpe-import-progress-label">
                            <span><?php echo esc_html__('Completion Progress:', 'wc-product-engine'); ?></span>
                            <span id="wpe-percent-text">0.0%</span>
                        </div>
                        <div class="wpe-import-progress-track" role="presentation">
                            <div id="wpe-progress-bar-fill" class="wpe-import-progress-fill"></div>
                        </div>
                    </div>

                    <!-- Counter Metrics -->
                    <div class="wpe-import-metrics">
                        <div class="wpe-import-metric">
                            <div class="wpe-import-metric-label"><?php echo esc_html__('Products Processed', 'wc-product-engine'); ?></div>
                            <div class="wpe-import-metric-value wpe-import-metric-value--blue" id="wpe-metric-products">0</div>
                        </div>
                        <div class="wpe-import-metric">
                            <div class="wpe-import-metric-label"><?php echo esc_html__('Variants Created', 'wc-product-engine'); ?></div>
                            <div class="wpe-import-metric-value wpe-import-metric-value--blue" id="wpe-metric-variants">0</div>
                        </div>
                        <div class="wpe-import-metric">
                            <div class="wpe-import-metric-label"><?php echo esc_html__('Processing Speed', 'wc-product-engine'); ?></div>
                            <div class="wpe-import-metric-value wpe-import-metric-value--green" id="wpe-metric-speed">0 prod/s</div>
                        </div>
                        <div class="wpe-import-metric">
                            <div class="wpe-import-metric-label"><?php echo esc_html__('Elapsed Time', 'wc-product-engine'); ?></div>
                            <div class="wpe-import-metric-value wpe-import-metric-value--muted" id="wpe-metric-time">00:00</div>
                        </div>
                        <div class="wpe-import-metric">
                            <div class="wpe-import-metric-label"><?php echo esc_html__('Errors Encountered', 'wc-product-engine'); ?></div>
                            <div class="wpe-import-metric-value wpe-import-metric-value--success" id="wpe-metric-errors">0</div>
                        </div>
                    </div>
                </div>

                <!-- Live Terminal Console -->
                <div class="wpe-terminal-card">
                    <div class="wpe-terminal-header">
                        <div class="wpe-terminal-title-group">
                            <span class="wpe-terminal-dot wpe-terminal-dot--red" aria-hidden="true"></span>
                            <span class="wpe-terminal-dot wpe-terminal-dot--yellow" aria-hidden="true"></span>
                            <span class="wpe-terminal-dot wpe-terminal-dot--green" aria-hidden="true"></span>
                            <strong class="wpe-terminal-title">Live Import Console Log</strong>
                        </div>
                        <div class="wpe-terminal-controls">
                            <label class="wpe-terminal-autoscroll-label">
                                <input type="checkbox" id="wpe-terminal-autoscroll" checked class="wpe-terminal-checkbox">
                                <?php echo esc_html__('Auto-scroll', 'wc-product-engine'); ?>
                            </label>
                            <button type="button" id="wpe-clear-console-btn" class="wpe-btn wpe-btn--sm wpe-terminal-clear-btn">
                                <?php echo esc_html__('Clear Console', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable Terminal Body -->
                    <div id="wpe-terminal-body" class="wpe-terminal-body">
[00:00:00] [INFO] Ready to initialize import process...
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
