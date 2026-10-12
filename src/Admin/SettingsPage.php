<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Bootstrap\PluginBootstrap;
use WPE\Application\Catalog\CatalogProjector;

/**
 * SettingsPage
 *
 * Provides the main administration settings screen for WC Product Engine:
 * - Feature Flags management (cohort_mode, query_enabled, media_enabled, resolver_enabled, native_writes_enabled).
 * - Instant Catalog Generation Rebuild trigger.
 * - System Health & Engine metrics overview.
 */
class SettingsPage
{
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

    public function renderPage(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Bạn không có quyền truy cập trang này.', 'wc-product-engine'));
        }

        $bootstrap = PluginBootstrap::instance();
        $flags = $bootstrap->getFlags();

        // Catalog stats
        $prefix = $this->wpdb->prefix;
        $nativeProducts = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpe_products WHERE deleted_at IS NULL");
        $nativeVariants = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpe_variants WHERE deleted_at IS NULL");
        $currentGen = (int)$this->wpdb->get_var("SELECT value FROM {$prefix}wpe_control WHERE `key` = 'catalog_generation'") ?: 1;
        $systemMode = $this->wpdb->get_var("SELECT value FROM {$prefix}wpe_control WHERE `key` = 'system_mode'") ?: 'legacy';

        ?>
        <div class="wrap wpe-settings-wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-performance" style="font-size: 28px; width: 30px; height: 30px; line-height: 1;"></span>
                <?php echo esc_html__('WC Product Engine — Cài đặt chung', 'wc-product-engine'); ?>
            </h1>
            <hr class="wp-header-end">

            <div id="wpe-settings-notice" class="notice inline" style="display: none; margin: 15px 0;"></div>

            <!-- System Status Banner -->
            <div class="wpe-status-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin: 20px 0;">
                <div class="card" style="margin: 0; padding: 15px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html__('Thế hệ Catalog (Generation)', 'wc-product-engine'); ?></h3>
                    <p style="font-size: 28px; font-weight: bold; margin: 5px 0; color: #2271b1;" id="wpe-current-gen">
                        #<?php echo esc_html((string)$currentGen); ?>
                    </p>
                    <small><?php echo esc_html__('Chỉ mục tốc độ cao hiện tại', 'wc-product-engine'); ?></small>
                </div>

                <div class="card" style="margin: 0; padding: 15px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html__('Sản phẩm Native', 'wc-product-engine'); ?></h3>
                    <p style="font-size: 28px; font-weight: bold; margin: 5px 0; color: #135e96;">
                        <?php echo esc_html(number_format_i18n($nativeProducts)); ?>
                    </p>
                    <small><?php echo esc_html__('Trong bảng wpe_products', 'wc-product-engine'); ?></small>
                </div>

                <div class="card" style="margin: 0; padding: 15px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html__('Biến thể Native', 'wc-product-engine'); ?></h3>
                    <p style="font-size: 28px; font-weight: bold; margin: 5px 0; color: #135e96;">
                        <?php echo esc_html(number_format_i18n($nativeVariants)); ?>
                    </p>
                    <small><?php echo esc_html__('Trong bảng wpe_variants', 'wc-product-engine'); ?></small>
                </div>

                <div class="card" style="margin: 0; padding: 15px;">
                    <h3 style="margin-top: 0;"><?php echo esc_html__('Chế độ hoạt động', 'wc-product-engine'); ?></h3>
                    <p style="font-size: 22px; font-weight: bold; margin: 5px 0; text-transform: uppercase; color: #008a20;">
                        <?php echo esc_html($flags['cohort_mode'] ?? 'legacy'); ?>
                    </p>
                    <small><?php echo esc_html__('Thẩm quyền đọc/ghi hiện tại', 'wc-product-engine'); ?></small>
                </div>
            </div>

            <!-- Main Form -->
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <!-- Left Column: Settings Form -->
                <div style="flex: 2; min-width: 320px;">
                    <div class="card" style="padding: 20px; max-width: 100%;">
                        <h2><?php echo esc_html__('Cấu hình Thẩm quyền & Tính năng', 'wc-product-engine'); ?></h2>
                        <form id="wpe-settings-form">
                            <table class="form-table" role="presentation">
                                <tbody>
                                    <tr>
                                        <th scope="row">
                                            <label for="cohort_mode"><?php echo esc_html__('Chế độ thẩm quyền (Authority Mode)', 'wc-product-engine'); ?></label>
                                        </th>
                                        <td>
                                            <select name="cohort_mode" id="cohort_mode" class="regular-text">
                                                <option value="legacy" <?php selected($flags['cohort_mode'] ?? '', 'legacy'); ?>>
                                                    <?php echo esc_html__('Legacy (WooCommerce CPT tiêu chuẩn)', 'wc-product-engine'); ?>
                                                </option>
                                                <option value="cohort_canary" <?php selected($flags['cohort_mode'] ?? '', 'cohort_canary'); ?>>
                                                    <?php echo esc_html__('Cohort Canary (Chuyển đổi từng nhóm ID)', 'wc-product-engine'); ?>
                                                </option>
                                                <option value="full_native" <?php selected($flags['cohort_mode'] ?? '', 'full_native'); ?>>
                                                    <?php echo esc_html__('Full Native (Tất cả sản phẩm đọc/ghi native)', 'wc-product-engine'); ?>
                                                </option>
                                            </select>
                                            <p class="description">
                                                <?php echo esc_html__('Điều khiển thẩm quyền quyết định nguồn dữ liệu catalog của WooCommerce.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Fast Catalog Query', 'wc-product-engine'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="query_enabled" value="1" <?php checked(!empty($flags['query_enabled'])); ?>>
                                                <strong><?php echo esc_html__('Bật bộ truy vấn Catalog siêu tốc', 'wc-product-engine'); ?></strong>
                                            </label>
                                            <p class="description">
                                                <?php echo esc_html__('Tăng tốc truy vấn Shop, phân trang Keyset và lọc Facet không dùng WP_Query postmeta nặng.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php echo esc_html__('CDN Media & Gallery Bridge', 'wc-product-engine'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="media_enabled" value="1" <?php checked(!empty($flags['media_enabled'])); ?>>
                                                <strong><?php echo esc_html__('Bật xử lý ảnh CDN bên ngoài & Attachment ảo', 'wc-product-engine'); ?></strong>
                                            </label>
                                            <p class="description">
                                                <?php echo esc_html__('Hỗ trợ URL ảnh CDN trực tiếp mà không cần tải file về ổ đĩa cục bộ.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Fast Variant Resolver', 'wc-product-engine'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="resolver_enabled" value="1" <?php checked(!empty($flags['resolver_enabled'])); ?>>
                                                <strong><?php echo esc_html__('Bật bộ giải quyết biến thể tốc độ cao (Resolver API)', 'wc-product-engine'); ?></strong>
                                            </label>
                                            <p class="description">
                                                <?php echo esc_html__('Xử lý chọn thuộc tính biến thể bất đồng bộ ngay trên máy chủ không làm đơ trình duyệt.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Native Writes', 'wc-product-engine'); ?></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="native_writes_enabled" value="1" <?php checked(!empty($flags['native_writes_enabled'])); ?>>
                                                <strong><?php echo esc_html__('Bật ghi trực tiếp vào Native Data Store', 'wc-product-engine'); ?></strong>
                                            </label>
                                            <p class="description">
                                                <?php echo esc_html__('Kích hoạt data store native thay thế postmeta khi lưu sản phẩm.', 'wc-product-engine'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <p class="submit">
                                <button type="submit" id="wpe-save-settings-btn" class="button button-primary">
                                    <span class="dashicons dashicons-saved" style="vertical-align: text-top; font-size: 18px;"></span>
                                    <?php echo esc_html__('Lưu cài đặt', 'wc-product-engine'); ?>
                                </button>
                                <span class="spinner" id="wpe-save-spinner" style="float: none;"></span>
                            </p>
                        </form>
                    </div>
                </div>

                <!-- Right Column: Quick Actions & Tools -->
                <div style="flex: 1; min-width: 280px;">
                    <div class="card" style="padding: 20px;">
                        <h2><?php echo esc_html__('Công cụ Tái tạo Chỉ mục', 'wc-product-engine'); ?></h2>
                        <p><?php echo esc_html__('Khi thêm sản phẩm mới hoặc import xong, bấm nút dưới để tính toán lại toàn bộ chỉ mục tìm kiếm và facet ngay lập tức.', 'wc-product-engine'); ?></p>
                        <p>
                            <button type="button" id="wpe-rebuild-gen-btn" class="button button-secondary button-large" style="width: 100%; text-align: center;">
                                <span class="dashicons dashicons-update" style="vertical-align: text-top;"></span>
                                <?php echo esc_html__('Tái tạo Catalog Index Generation', 'wc-product-engine'); ?>
                            </button>
                        </p>
                        <div id="wpe-rebuild-status" style="margin-top: 10px; font-size: 13px;"></div>
                    </div>

                    <div class="card" style="padding: 20px; margin-top: 20px;">
                        <h2><?php echo esc_html__('Đường dẫn nhanh', 'wc-product-engine'); ?></h2>
                        <ul style="line-height: 2;">
                            <li>
                                <span class="dashicons dashicons-upload" style="color: #2271b1;"></span>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wpe-shopify-import')); ?>">
                                    <strong><?php echo esc_html__('Import CSV Shopify', 'wc-product-engine'); ?></strong>
                                </a>
                                — <?php echo esc_html__('Nhập danh mục lớn, có tạm dừng/tiếp tục', 'wc-product-engine'); ?>
                            </li>
                            <li>
                                <span class="dashicons dashicons-admin-tools" style="color: #2271b1;"></span>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wpe-operations')); ?>">
                                    <strong><?php echo esc_html__('Vận hành & Giám sát', 'wc-product-engine'); ?></strong>
                                </a>
                                — <?php echo esc_html__('Kiểm tra sức khỏe hệ thống, Shadow Diff, Rollback', 'wc-product-engine'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
