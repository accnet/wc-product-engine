<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;

/**
 * ShopifyImportPage
 *
 * Provides the interactive, resilient Shopify CSV import screen:
 * - Large file handling without timeouts via chunked batching.
 * - Pause and Resume capability with persisted byte-offset checkpoints.
 * - Live terminal log console & downloadable log file.
 * - Direct server file path or client-side upload support.
 */
class ShopifyImportPage
{
    private static ?ShopifyImportPage $instance = null;
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

    public function renderPage(): void
    {
        if (!current_user_can('wpe_import_products') && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this import operation.', 'wc-product-engine'));
        }

        $batchImporter = ShopifyBatchImporter::getInstance();
        $activeJob = $batchImporter->getActiveJob();
        ?>
        <div class="wrap wpe-import-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-upload" style="font-size: 28px; width: 30px; height: 30px; line-height: 1;"></span>
                <?php echo esc_html__('WC Product Engine — Shopify CSV Import', 'wc-product-engine'); ?>
            </h1>
            <p class="description" style="font-size: 14px; margin-top: 5px;">
                <?php echo esc_html__('Import product catalogs from Shopify-formatted CSV files. Resilient against interruptions on large files, chunked batch execution by byte offsets with Pause, Resume, and real-time live console logging.', 'wc-product-engine'); ?>
            </p>
            <hr class="wp-header-end">

            <!-- Active / Resumable Job Banner (if exists) -->
            <div id="wpe-active-job-banner" class="notice notice-warning inline" style="<?php echo $activeJob ? 'display: block;' : 'display: none;'; ?> margin: 15px 0;">
                <p>
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
                    <button type="button" id="wpe-resume-active-job-btn" class="button button-primary" style="margin-left: 10px;" data-job-id="<?php echo esc_attr($activeJob['job_id'] ?? ''); ?>">
                        <?php echo esc_html__('Resume this job', 'wc-product-engine'); ?>
                    </button>
                    <button type="button" id="wpe-discard-active-job-btn" class="button button-secondary" style="margin-left: 5px;" data-job-id="<?php echo esc_attr($activeJob['job_id'] ?? ''); ?>">
                        <?php echo esc_html__('Discard previous job', 'wc-product-engine'); ?>
                    </button>
                </p>
            </div>

            <!-- Top Grid: Configuration & File Selection -->
            <div id="wpe-import-setup-section" style="display: <?php echo ($activeJob && $activeJob['status'] === 'in_progress') ? 'none' : 'block'; ?>;">
                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                    <!-- Left: File Selector -->
                    <div style="flex: 3; min-width: 340px;">
                        <div class="card" style="padding: 20px; max-width: 100%;">
                            <h2><?php echo esc_html__('1. Select Shopify CSV File', 'wc-product-engine'); ?></h2>

                            <!-- Dropzone -->
                            <div id="wpe-dropzone" style="border: 2px dashed #c3c4c7; border-radius: 6px; padding: 30px; text-align: center; background: #fafafa; cursor: pointer; transition: all 0.2s ease;">
                                <span class="dashicons dashicons-cloud-upload" style="font-size: 48px; width: 48px; height: 48px; color: #2271b1;"></span>
                                <p style="font-size: 15px; margin: 10px 0 5px 0;">
                                    <strong><?php echo esc_html__('Drag & drop CSV file here or click to browse', 'wc-product-engine'); ?></strong>
                                </p>
                                <p class="description" style="margin: 0;">
                                    <?php echo esc_html__('Supports UTF-8, UTF-8 BOM, unlimited file size.', 'wc-product-engine'); ?>
                                </p>
                                <input type="file" id="wpe-csv-file-input" accept=".csv" style="display: none;">
                            </div>

                            <!-- Or Server Path -->
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                                <label for="wpe-server-path-input">
                                    <strong><?php echo esc_html__('Or direct file path on server:', 'wc-product-engine'); ?></strong>
                                </label>
                                <div style="display: flex; gap: 10px; margin-top: 5px;">
                                    <input type="text" id="wpe-server-path-input" class="large-text" placeholder="/path/to/theme-performance-shop-product-data.csv" value="/mnt/c/Users/tuttn/Downloads/theme-performance-shop-product-data.csv">
                                    <button type="button" id="wpe-use-server-file-btn" class="button button-secondary">
                                        <?php echo esc_html__('Validate File', 'wc-product-engine'); ?>
                                    </button>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__('For very large CSV files already present on the server without uploading via browser.', 'wc-product-engine'); ?>
                                </p>
                            </div>

                            <!-- File Selected Info -->
                            <div id="wpe-selected-file-info" style="display: none; margin-top: 15px; padding: 12px; background: #e7f3fe; border-left: 4px solid #2271b1; border-radius: 3px;">
                                <div style="font-weight: bold; font-size: 14px;" id="wpe-selected-filename"></div>
                                <div style="font-size: 12px; color: #50575e; margin-top: 3px;" id="wpe-selected-meta"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Import Options -->
                    <div style="flex: 2; min-width: 280px;">
                        <div class="card" style="padding: 20px; max-width: 100%;">
                            <h2><?php echo esc_html__('2. Processing Configuration', 'wc-product-engine'); ?></h2>

                            <table class="form-table" role="presentation" style="margin-top: 0;">
                                <tbody>
                                    <tr>
                                        <th scope="row" style="padding: 10px 0;">
                                            <label for="wpe-opt-update-mode"><?php echo esc_html__('Update Mode', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td style="padding: 10px 0;">
                                            <select id="wpe-opt-update-mode" class="regular-text" style="width: 100%;">
                                                <option value="merge" selected>
                                                    <?php echo esc_html__('Merge & Update (Preserve unmentioned variants)', 'wc-product-engine'); ?>
                                                </option>
                                                <option value="replace">
                                                    <?php echo esc_html__('Full Replace', 'wc-product-engine'); ?>
                                                </option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row" style="padding: 10px 0;">
                                            <label for="wpe-opt-image-mode"><?php echo esc_html__('Media Handling', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td style="padding: 10px 0;">
                                            <select id="wpe-opt-image-mode" class="regular-text" style="width: 100%;">
                                                <option value="external" selected>
                                                    <?php echo esc_html__('External CDN (Fast, zero local disk usage)', 'wc-product-engine'); ?>
                                                </option>
                                                <option value="download">
                                                    <?php echo esc_html__('Download to Media Library (Slower)', 'wc-product-engine'); ?>
                                                </option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row" style="padding: 10px 0;">
                                            <label for="wpe-opt-batch-size"><?php echo esc_html__('Batch Size', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td style="padding: 10px 0;">
                                            <select id="wpe-opt-batch-size" class="regular-text" style="width: 100%;">
                                                <option value="15">15 <?php echo esc_html__('products / request', 'wc-product-engine'); ?></option>
                                                <option value="25" selected>25 <?php echo esc_html__('products / request (Recommended)', 'wc-product-engine'); ?></option>
                                                <option value="50">50 <?php echo esc_html__('products / request (Fast server)', 'wc-product-engine'); ?></option>
                                                <option value="100">100 <?php echo esc_html__('products / request', 'wc-product-engine'); ?></option>
                                            </select>
                                            <p class="description" style="margin-top: 5px;">
                                                <?php echo esc_html__('Each HTTP request processes 1 chunk batch (~2-3s per batch), eliminating 504 Timeout errors.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div style="margin-top: 20px;">
                                <button type="button" id="wpe-start-import-btn" class="button button-primary button-hero" style="width: 100%; text-align: center;" disabled>
                                    <span class="dashicons dashicons-controls-play" style="vertical-align: text-top; font-size: 20px;"></span>
                                    <?php echo esc_html__('Start Import', 'wc-product-engine'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress & Dashboard Section -->
            <div id="wpe-import-progress-section" style="display: none; margin-top: 20px;">
                <div class="card" style="padding: 20px; max-width: 100%;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h2 style="margin: 0; display: inline-flex; align-items: center; gap: 8px;">
                                <span class="spinner is-active" id="wpe-progress-spinner" style="float: none; margin: 0;"></span>
                                <?php echo esc_html__('Product Import Progress', 'wc-product-engine'); ?>
                            </h2>
                            <p class="description" style="margin: 5px 0 0 0;" id="wpe-job-status-desc">
                                <?php echo esc_html__('Processing batch chunks...', 'wc-product-engine'); ?>
                            </p>
                        </div>

                        <!-- Action Controls: Pause / Resume / Cancel / Download Log -->
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" id="wpe-pause-btn" class="button button-secondary">
                                <span class="dashicons dashicons-controls-pause" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Pause', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-resume-btn" class="button button-primary" style="display: none;">
                                <span class="dashicons dashicons-controls-play" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Resume', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-cancel-btn" class="button button-link-delete">
                                <span class="dashicons dashicons-dismiss" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Cancel', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-download-log-btn" class="button button-secondary">
                                <span class="dashicons dashicons-download" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Download Log File (.log)', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div style="margin: 20px 0 10px 0;">
                        <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 5px;">
                            <span><?php echo esc_html__('Completion Progress:', 'wc-product-engine'); ?></span>
                            <span id="wpe-percent-text">0.0%</span>
                        </div>
                        <div style="width: 100%; height: 24px; background: #e0e0e0; border-radius: 12px; overflow: hidden; position: relative;">
                            <div id="wpe-progress-bar-fill" style="width: 0%; height: 100%; background: linear-gradient(90deg, #2271b1, #00a32a); transition: width 0.3s ease; border-radius: 12px;"></div>
                        </div>
                    </div>

                    <!-- Counter Metrics -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin: 15px 0 5px 0;">
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Products Processed', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #135e96; margin-top: 4px;" id="wpe-metric-products">0</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Variants Created', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #135e96; margin-top: 4px;" id="wpe-metric-variants">0</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Processing Speed', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #008a20; margin-top: 4px;" id="wpe-metric-speed">0 prod/s</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Elapsed Time', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #50575e; margin-top: 4px;" id="wpe-metric-time">00:00</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Errors Encountered', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #00a32a; margin-top: 4px;" id="wpe-metric-errors">0</div>
                        </div>
                    </div>
                </div>

                <!-- Live Terminal Console -->
                <div class="card" style="padding: 15px; margin-top: 20px; background: #1e1e1e; color: #d4d4d4; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; padding-bottom: 10px; margin-bottom: 10px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #ff5f56;"></span>
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #ffbd2e;"></span>
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #27c93f;"></span>
                            <strong style="color: #fff; margin-left: 5px; font-family: monospace; font-size: 13px;">Live Import Console Log</strong>
                        </div>
                        <div style="display: flex; gap: 15px; align-items: center;">
                            <label style="color: #aaa; font-size: 12px; cursor: pointer;">
                                <input type="checkbox" id="wpe-terminal-autoscroll" checked style="margin-right: 4px;">
                                <?php echo esc_html__('Auto-scroll', 'wc-product-engine'); ?>
                            </label>
                            <button type="button" id="wpe-clear-console-btn" class="button button-small" style="background: #333; color: #eee; border-color: #555;">
                                <?php echo esc_html__('Clear Console', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable Terminal Body -->
                    <div id="wpe-terminal-body" style="height: 320px; overflow-y: auto; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-all; padding: 5px;">
[00:00:00] [INFO] Ready to initialize import process...
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
