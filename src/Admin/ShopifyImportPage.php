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
            wp_die(esc_html__('Bạn không có quyền thực hiện chức năng import này.', 'wc-product-engine'));
        }

        $batchImporter = ShopifyBatchImporter::getInstance();
        $activeJob = $batchImporter->getActiveJob();
        ?>
        <div class="wrap wpe-import-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-upload" style="font-size: 28px; width: 30px; height: 30px; line-height: 1;"></span>
                <?php echo esc_html__('WC Product Engine — Import CSV Shopify', 'wc-product-engine'); ?>
            </h1>
            <p class="description" style="font-size: 14px; margin-top: 5px;">
                <?php echo esc_html__('Nhập danh mục sản phẩm từ tệp CSV định dạng Shopify. Hỗ trợ tệp lớn hàng trăm MB không bị gián đoạn, chia nhỏ lô xử lý theo byte offset, có chức năng Tạm dừng, Tiếp tục và nhật ký trực tiếp.', 'wc-product-engine'); ?>
            </p>
            <hr class="wp-header-end">

            <!-- Active / Resumable Job Banner (if exists) -->
            <div id="wpe-active-job-banner" class="notice notice-warning inline" style="<?php echo $activeJob ? 'display: block;' : 'display: none;'; ?> margin: 15px 0;">
                <p>
                    <strong><?php echo esc_html__('Phát hiện tác vụ import trước đó chưa hoàn tất:', 'wc-product-engine'); ?></strong>
                    <span id="wpe-active-job-details">
                        <?php if ($activeJob): ?>
                            <?php printf(
                                esc_html__('Tệp: %s (%s) — Trạng thái: %s — Đã xử lý: %d sản phẩm, %d biến thể (%s%%)', 'wc-product-engine'),
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
                        <?php echo esc_html__('Tiếp tục tiến trình này', 'wc-product-engine'); ?>
                    </button>
                    <button type="button" id="wpe-discard-active-job-btn" class="button button-secondary" style="margin-left: 5px;" data-job-id="<?php echo esc_attr($activeJob['job_id'] ?? ''); ?>">
                        <?php echo esc_html__('Hủy tác vụ cũ', 'wc-product-engine'); ?>
                    </button>
                </p>
            </div>

            <!-- Top Grid: Configuration & File Selection -->
            <div id="wpe-import-setup-section" style="display: <?php echo ($activeJob && $activeJob['status'] === 'in_progress') ? 'none' : 'block'; ?>;">
                <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 15px;">
                    <!-- Left: File Selector -->
                    <div style="flex: 3; min-width: 340px;">
                        <div class="card" style="padding: 20px; max-width: 100%;">
                            <h2><?php echo esc_html__('1. Chọn tệp CSV Shopify', 'wc-product-engine'); ?></h2>

                            <!-- Dropzone -->
                            <div id="wpe-dropzone" style="border: 2px dashed #c3c4c7; border-radius: 6px; padding: 30px; text-align: center; background: #fafafa; cursor: pointer; transition: all 0.2s ease;">
                                <span class="dashicons dashicons-cloud-upload" style="font-size: 48px; width: 48px; height: 48px; color: #2271b1;"></span>
                                <p style="font-size: 15px; margin: 10px 0 5px 0;">
                                    <strong><?php echo esc_html__('Kéo thả tệp CSV vào đây hoặc bấm để chọn tệp', 'wc-product-engine'); ?></strong>
                                </p>
                                <p class="description" style="margin: 0;">
                                    <?php echo esc_html__('Hỗ trợ UTF-8, UTF-8 BOM, dung lượng lớn không giới hạn.', 'wc-product-engine'); ?>
                                </p>
                                <input type="file" id="wpe-csv-file-input" accept=".csv" style="display: none;">
                            </div>

                            <!-- Or Server Path -->
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0;">
                                <label for="wpe-server-path-input">
                                    <strong><?php echo esc_html__('Hoặc đường dẫn tệp trực tiếp trên máy chủ:', 'wc-product-engine'); ?></strong>
                                </label>
                                <div style="display: flex; gap: 10px; margin-top: 5px;">
                                    <input type="text" id="wpe-server-path-input" class="large-text" placeholder="/path/to/theme-performance-shop-product-data.csv" value="/mnt/c/Users/tuttn/Downloads/theme-performance-shop-product-data.csv">
                                    <button type="button" id="wpe-use-server-file-btn" class="button button-secondary">
                                        <?php echo esc_html__('Kiểm tra tệp', 'wc-product-engine'); ?>
                                    </button>
                                </div>
                                <p class="description">
                                    <?php echo esc_html__('Dành cho tệp CSV rất lớn đã tải sẵn trên server mà không cần upload qua trình duyệt.', 'wc-product-engine'); ?>
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
                            <h2><?php echo esc_html__('2. Cấu hình xử lý', 'wc-product-engine'); ?></h2>

                            <table class="form-table" role="presentation" style="margin-top: 0;">
                                <tbody>
                                    <tr>
                                        <th scope="row" style="padding: 10px 0;">
                                            <label for="wpe-opt-update-mode"><?php echo esc_html__('Chế độ cập nhật', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td style="padding: 10px 0;">
                                            <select id="wpe-opt-update-mode" class="regular-text" style="width: 100%;">
                                                <option value="merge" selected>
                                                    <?php echo esc_html__('Gộp & Cập nhật (Merge - Giữ nguyên biến thể khác)', 'wc-product-engine'); ?>
                                                </option>
                                                <option value="replace">
                                                    <?php echo esc_html__('Ghi đè toàn bộ (Replace)', 'wc-product-engine'); ?>
                                                </option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row" style="padding: 10px 0;">
                                            <label for="wpe-opt-image-mode"><?php echo esc_html__('Xử lý hình ảnh', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td style="padding: 10px 0;">
                                            <select id="wpe-opt-image-mode" class="regular-text" style="width: 100%;">
                                                <option value="external" selected>
                                                    <?php echo esc_html__('CDN ngoài (External CDN - Nhanh, không tốn ổ đĩa)', 'wc-product-engine'); ?>
                                                </option>
                                                <option value="download">
                                                    <?php echo esc_html__('Tải về thư viện Media (Chậm hơn)', 'wc-product-engine'); ?>
                                                </option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row" style="padding: 10px 0;">
                                            <label for="wpe-opt-batch-size"><?php echo esc_html__('Kích thước lô (Batch)', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td style="padding: 10px 0;">
                                            <select id="wpe-opt-batch-size" class="regular-text" style="width: 100%;">
                                                <option value="15">15 <?php echo esc_html__('sản phẩm / request', 'wc-product-engine'); ?></option>
                                                <option value="25" selected>25 <?php echo esc_html__('sản phẩm / request (Khuyên dùng)', 'wc-product-engine'); ?></option>
                                                <option value="50">50 <?php echo esc_html__('sản phẩm / request (Máy chủ mạnh)', 'wc-product-engine'); ?></option>
                                                <option value="100">100 <?php echo esc_html__('sản phẩm / request', 'wc-product-engine'); ?></option>
                                            </select>
                                            <p class="description" style="margin-top: 5px;">
                                                <?php echo esc_html__('Mỗi request HTTP xử lý 1 lô. Thời gian ~2-3 giây/lô, loại bỏ hoàn toàn lỗi 504 Timeout.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div style="margin-top: 20px;">
                                <button type="button" id="wpe-start-import-btn" class="button button-primary button-hero" style="width: 100%; text-align: center;" disabled>
                                    <span class="dashicons dashicons-controls-play" style="vertical-align: text-top; font-size: 20px;"></span>
                                    <?php echo esc_html__('Bắt đầu Import', 'wc-product-engine'); ?>
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
                                <?php echo esc_html__('Tiến trình Import sản phẩm', 'wc-product-engine'); ?>
                            </h2>
                            <p class="description" style="margin: 5px 0 0 0;" id="wpe-job-status-desc">
                                <?php echo esc_html__('Đang xử lý dữ liệu theo lô...', 'wc-product-engine'); ?>
                            </p>
                        </div>

                        <!-- Action Controls: Pause / Resume / Cancel / Download Log -->
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" id="wpe-pause-btn" class="button button-secondary">
                                <span class="dashicons dashicons-controls-pause" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Tạm dừng', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-resume-btn" class="button button-primary" style="display: none;">
                                <span class="dashicons dashicons-controls-play" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Tiếp tục', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-cancel-btn" class="button button-link-delete">
                                <span class="dashicons dashicons-dismiss" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Hủy bỏ', 'wc-product-engine'); ?>
                            </button>
                            <button type="button" id="wpe-download-log-btn" class="button button-secondary">
                                <span class="dashicons dashicons-download" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Tải file Log (.log)', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div style="margin: 20px 0 10px 0;">
                        <div style="display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 5px;">
                            <span><?php echo esc_html__('Tiến độ hoàn thành:', 'wc-product-engine'); ?></span>
                            <span id="wpe-percent-text">0.0%</span>
                        </div>
                        <div style="width: 100%; height: 24px; background: #e0e0e0; border-radius: 12px; overflow: hidden; position: relative;">
                            <div id="wpe-progress-bar-fill" style="width: 0%; height: 100%; background: linear-gradient(90deg, #2271b1, #00a32a); transition: width 0.3s ease; border-radius: 12px;"></div>
                        </div>
                    </div>

                    <!-- Counter Metrics -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin: 15px 0 5px 0;">
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Sản phẩm đã xử lý', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #135e96; margin-top: 4px;" id="wpe-metric-products">0</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Biến thể đã tạo', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #135e96; margin-top: 4px;" id="wpe-metric-variants">0</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Tốc độ xử lý', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #008a20; margin-top: 4px;" id="wpe-metric-speed">0 sp/s</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Thời gian đã chạy', 'wc-product-engine'); ?></div>
                            <div style="font-size: 22px; font-weight: bold; color: #50575e; margin-top: 4px;" id="wpe-metric-time">00:00</div>
                        </div>
                        <div style="background: #f0f6fc; padding: 12px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #50575e;"><?php echo esc_html__('Lỗi ghi nhận', 'wc-product-engine'); ?></div>
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
                                <?php echo esc_html__('Tự động cuộn', 'wc-product-engine'); ?>
                            </label>
                            <button type="button" id="wpe-clear-console-btn" class="button button-small" style="background: #333; color: #eee; border-color: #555;">
                                <?php echo esc_html__('Xóa màn hình', 'wc-product-engine'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable Terminal Body -->
                    <div id="wpe-terminal-body" style="height: 320px; overflow-y: auto; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-all; padding: 5px;">
[00:00:00] [INFO] Sẵn sàng khởi động tiến trình import...
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
