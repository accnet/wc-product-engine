<?php

declare(strict_types=1);

namespace WPE\Compatibility\Csv\Woo;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * WooExporter
 *
 * Implements:
 * - Native WooCommerce baseline CSV dialect mapping & schema matching (R132, AC-T33-01).
 * - Full engine -> Woo CSV -> engine round-trip subset equality (R133, AC-T33-01).
 * - Native WooCommerce CSV exporter hooks (woocommerce_product_export_row_data, etc.) (R134).
 * - Strict image URL ordering (featured first, gallery next, variation primary per row) and loss matrix (R135).
 * - ID preservation policy and rejection of arbitrary new ID creation (AC-T33-02).
 * - Multi-gallery variation and custom alt loss reporting without invalid custom columns (R135).
 */
class WooExporter
{
    private static ?WooExporter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableAssets;
    private string $tableSkuRegistry;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;

    private string $exportDir;
    private bool $hooksRegistered = false;

    public const BASELINE_COLUMNS = [
        'ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
        'Short description', 'Description', 'Date sale price starts', 'Date sale price ends',
        'Tax status', 'Tax class', 'In stock?', 'Stock', 'Backorders allowed?', 'Sold individually?',
        'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)', 'Allow customer reviews?',
        'Purchase note', 'Sale price', 'Regular price', 'Categories', 'Tags', 'Shipping class',
        'Images', 'Download limit', 'Download expiry days', 'Parent', 'Grouped products',
        'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position',
    ];

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $attachmentBridge = null,
        ?string $customExportDir = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts      = "{$prefix}wpe_products";
        $this->tableVariants      = "{$prefix}wpe_variants";
        $this->tableInventory     = "{$prefix}wpe_inventory";
        $this->tableProductMedia  = "{$prefix}wpe_product_media";
        $this->tableVariantMedia  = "{$prefix}wpe_variant_media";
        $this->tableAssets        = "{$prefix}wpe_assets";
        $this->tableSkuRegistry   = "{$prefix}wpe_sku_registry";
        $this->tableOptions       = "{$prefix}wpe_options";
        $this->tableOptionValues  = "{$prefix}wpe_option_values";
        $this->tableVariantValues = "{$prefix}wpe_variant_values";

        $this->productRepo      = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo      = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->assetService     = $assetService ?? new AssetService($this->wpdb);
        $this->attachmentBridge = $attachmentBridge ?? AttachmentBridge::instance($this->wpdb);

        if ($customExportDir !== null) {
            $this->exportDir = rtrim($customExportDir, '/\\');
        } else {
            $wpUpload = wp_upload_dir();
            $this->exportDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-woo-exports';
        }

        $this->ensureDirectory($this->exportDir);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n<FilesMatch \"\\.(csv|json)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>\n");
        }
    }

    /**
     * Register hooks with native WooCommerce CSV Exporter (R134).
     */
    public function registerHooks(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        // Hook 1: Filter export row data for products managed by WPE
        add_filter('woocommerce_product_export_row_data', [$this, 'filterNativeExportRowData'], 10, 3);

        // Hook 2: Filter image column value in native exporter
        add_filter('woocommerce_product_export_product_column_images', [$this, 'filterNativeImagesColumn'], 10, 3);

        // Hook 3: Skip sensitive or internal meta keys from native export
        add_filter('woocommerce_product_export_skip_meta_keys', [$this, 'filterNativeSkipMetaKeys'], 10, 2);

        $this->hooksRegistered = true;
    }

    /**
     * Native exporter filter: intercept row data to ensure accurate WPE native authority values (R134).
     *
     * @param array $row Row data prepared by WC exporter
     * @param object $product WC_Product instance
     * @param object $exporter WC_Product_CSV_Exporter instance
     * @return array Modified row data
     */
    public function filterNativeExportRowData(array $row, $product, $exporter = null): array
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $row;
        }

        $productId = (int)$product->get_id();
        if ($productId <= 0) {
            return $row;
        }

        // Check if product is managed by WPE
        $wpeProduct = $this->productRepo->getById($productId);
        if (!$wpeProduct) {
            return $row;
        }

        // Ensure canonical images from wpe_product_media or wpe_variant_media
        $imageUrls = $this->getProductImageUrls($productId, $wpeProduct['type'] === 'variation');
        if (!empty($imageUrls)) {
            $row['images'] = implode(', ', $imageUrls);
        }

        // Ensure Parent field prioritizes parent SKU if variation (R132)
        if ($wpeProduct['type'] === 'variation' && !empty($wpeProduct['parent_id'])) {
            $parent = $this->productRepo->getById((int)$wpeProduct['parent_id']);
            if ($parent) {
                $row['parent_id'] = !empty($parent['sku']) ? $parent['sku'] : 'id:' . $parent['id'];
            }
        }

        return $row;
    }

    /**
     * Native exporter filter: provide CDN image URLs for WPE products (R134).
     */
    public function filterNativeImagesColumn(string $value, $product, string $columnId = 'images'): string
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $value;
        }

        $productId = (int)$product->get_id();
        $wpeProduct = $this->productRepo->getById($productId);
        if (!$wpeProduct) {
            return $value;
        }

        $imageUrls = $this->getProductImageUrls($productId, $wpeProduct['type'] === 'variation');
        if (!empty($imageUrls)) {
            return implode(', ', $imageUrls);
        }

        return $value;
    }

    /**
     * Filter meta keys to prevent leaking internal WPE properties in native export.
     */
    public function filterNativeSkipMetaKeys(array $keys, $product): array
    {
        $keys[] = '_wpe_object_state';
        $keys[] = '_wpe_version';
        $keys[] = '_wpe_epoch';
        $keys[] = '_wpe_combination_key';
        return $keys;
    }

    /**
     * Export products into standard WooCommerce baseline CSV format (R132, R133, R135, AC-T33-01).
     *
     * @param string|null $destinationPath Output file path (or generated if null)
     * @param array $options Options:
     *   - 'product_ids' => array of product IDs to export (null for all)
     *   - 'include_ids' => bool (default true: include ID column for same-site matching)
     *   - 'profile'     => 'machine' | 'spreadsheet_safe' (default 'machine')
     * @return array Export summary with row_count, product_count, loss_report, file_path
     */
    public function exportToCsv(?string $destinationPath = null, array $options = []): array
    {
        $includeIds = $options['include_ids'] ?? true;
        $profile = $options['profile'] ?? 'machine';
        $targetProductIds = $options['product_ids'] ?? null;

        $filePath = $destinationPath;
        if ($filePath === null) {
            $filename = 'woo-export-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false) . '.csv';
            $filePath = $this->exportDir . '/' . $filename;
        }

        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Failed to open file for export: {$filePath}");
        }

        // 1. Fetch aggregates and calculate dynamic attribute column requirements
        $aggregates = $this->loadExportAggregates($targetProductIds);

        $maxAttributes = 1;
        foreach ($aggregates as $agg) {
            $optCount = count($agg['options'] ?? []);
            if ($optCount > $maxAttributes) {
                $maxAttributes = $optCount;
            }
            if (!empty($agg['variations'])) {
                foreach ($agg['variations'] as $v) {
                    $attrCount = count($v['attributes'] ?? []);
                    if ($attrCount > $maxAttributes) {
                        $maxAttributes = $attrCount;
                    }
                }
            }
        }

        // 2. Build complete standard WooCommerce CSV headers
        $headers = self::BASELINE_COLUMNS;
        for ($i = 1; $i <= $maxAttributes; $i++) {
            $headers[] = "Attribute {$i} name";
            $headers[] = "Attribute {$i} value(s)";
            $headers[] = "Attribute {$i} visible";
            $headers[] = "Attribute {$i} global";
            $headers[] = "Attribute {$i} default";
        }

        fputcsv($handle, $headers);

        $totalProducts = 0;
        $totalVariations = 0;
        $totalRows = 0;
        $losses = [];

        // 3. Stream aggregate rows: parent first, then variations (R132, R133)
        foreach ($aggregates as $agg) {
            $totalProducts++;
            $productId = (int)$agg['id'];

            // Format parent row
            $parentRow = $this->formatParentRow($agg, $headers, $includeIds, $profile, $losses);
            fputcsv($handle, $parentRow);
            $totalRows++;

            // Format variation rows
            if (!empty($agg['variations'])) {
                $parentRef = !empty($agg['sku']) ? $agg['sku'] : "id:{$productId}";

                foreach ($agg['variations'] as $v) {
                    $totalVariations++;
                    $variantRow = $this->formatVariationRow($v, $agg, $parentRef, $headers, $includeIds, $profile, $losses);
                    fputcsv($handle, $variantRow);
                    $totalRows++;
                }
            }
        }

        fclose($handle);

        // 4. Save loss report if any information could not be natively represented (R135)
        $lossReportPath = null;
        if (!empty($losses)) {
            $lossReportPath = preg_replace('/\.csv$/i', '-loss-report.json', $filePath);
            file_put_contents($lossReportPath, json_encode([
                'total_losses' => count($losses),
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'losses'       => $losses,
            ], JSON_PRETTY_PRINT));
        }

        return [
            'status'           => 'completed',
            'file_path'        => $filePath,
            'file_sha256'      => hash_file('sha256', $filePath),
            'total_products'   => $totalProducts,
            'total_variations' => $totalVariations,
            'total_rows'       => $totalRows,
            'loss_count'       => count($losses),
            'loss_report_path' => $lossReportPath,
            'loss_report'      => $losses,
        ];
    }

    /**
     * Load full aggregates to export.
     */
    private function loadExportAggregates(?array $productIds = null): array
    {
        $sql = "SELECT p.* FROM {$this->tableProducts} p WHERE p.deleted_at IS NULL AND p.status != 'trash'";
        if (!empty($productIds)) {
            $idList = implode(',', array_map('intval', $productIds));
            $sql .= " AND p.id IN ({$idList})";
        }
        $sql .= " ORDER BY p.id ASC";

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            return [];
        }

        $aggregates = [];
        foreach ($rows as $row) {
            $pId = (int)$row['id'];
            $agg = $this->productRepo->getById($pId);
            if ($agg) {
                // Attach options and option values
                $agg['options'] = $this->optionsRepo->getOptionsByProductId($pId);

                // Attach media with source URLs
                $agg['media'] = $this->getProductMediaWithUrls($pId);

                // Attach variation media
                if (!empty($agg['variations'])) {
                    foreach ($agg['variations'] as &$v) {
                        $vId = (int)$v['id'];
                        $v['media'] = $this->getVariantMediaWithUrls($vId);
                        // Also load option values for variation
                        $v['attributes'] = $this->getVariantAttributesMap($pId, $vId);
                    }
                    unset($v);
                }

                $aggregates[] = $agg;
            }
        }

        return $aggregates;
    }

    /**
     * Format parent product row adhering to WooCommerce baseline schema (R132, R135).
     */
    private function formatParentRow(array $agg, array $headers, bool $includeIds, string $profile, array &$losses): array
    {
        $productId = (int)$agg['id'];
        $row = array_fill_keys($headers, '');

        if ($includeIds) {
            $row['ID'] = (string)$productId;
        }

        $row['Type'] = $agg['type'];
        $row['SKU'] = $agg['sku'] ?? '';
        $row['Name'] = $agg['title'] ?? $agg['name'] ?? '';
        $row['Published'] = ($agg['status'] === 'publish') ? '1' : '0';
        $row['Is featured?'] = !empty($agg['featured']) ? '1' : '0';
        $row['Visibility in catalog'] = $agg['catalog_visibility'] ?? 'visible';
        $row['Short description'] = $agg['short_description'] ?? '';
        $row['Description'] = $agg['description'] ?? '';
        $row['Tax status'] = $agg['tax_status'] ?? 'taxable';
        $row['Tax class'] = $agg['tax_class'] ?? '';

        // Stock semantics (R131)
        if (!empty($agg['manage_stock'])) {
            $row['In stock?'] = ($agg['stock_status'] === 'outofstock') ? '0' : '1';
            $row['Stock'] = ($agg['stock_quantity'] !== null) ? (string)(float)$agg['stock_quantity'] : '';
        } else {
            $row['In stock?'] = ($agg['stock_status'] === 'outofstock') ? '0' : '1';
            $row['Stock'] = ''; // Blank stock = unmanaged
        }

        $row['Backorders allowed?'] = !empty($agg['backorders']) ? '1' : '0';
        $row['Sold individually?'] = !empty($agg['sold_individually']) ? '1' : '0';
        $row['Allow customer reviews?'] = '1';

        $row['Sale price'] = ($agg['sale_price'] !== null) ? sprintf('%.2f', (float)$agg['sale_price']) : '';
        $row['Regular price'] = ($agg['regular_price'] !== null) ? sprintf('%.2f', (float)$agg['regular_price']) : '';

        // Terms
        $categories = $this->getProductTermNames($productId, 'product_cat');
        $tags = $this->getProductTermNames($productId, 'product_tag');
        $row['Categories'] = implode(', ', $categories);
        $row['Tags'] = implode(', ', $tags);

        // Images: featured first, then gallery (R135)
        $mediaUrls = [];
        if (!empty($agg['media'])) {
            foreach ($agg['media'] as $m) {
                if (!empty($m['source_url'])) {
                    $mediaUrls[] = $m['source_url'];
                    if (!empty($m['alt_override'])) {
                        // Custom alt override cannot be represented in native Woo CSV (R135)
                        $losses[] = [
                            'field'   => 'image_alt_override',
                            'path'    => "products[{$productId}].media[{$m['asset_id']}].alt_override",
                            'reason'  => 'Native WooCommerce CSV does not support custom image alt overrides per product',
                            'value'   => $m['alt_override'],
                        ];
                    }
                }
            }
        }
        $row['Images'] = implode(', ', $mediaUrls);

        // Dynamic attributes
        if (!empty($agg['options'])) {
            $attrIndex = 1;
            foreach ($agg['options'] as $opt) {
                $values = array_column($opt['values'] ?? [], 'label');
                $row["Attribute {$attrIndex} name"]     = $opt['name'];
                $row["Attribute {$attrIndex} value(s)"] = implode(', ', $values);
                $row["Attribute {$attrIndex} visible"]  = '1';
                $row["Attribute {$attrIndex} global"]   = !empty($opt['is_global']) ? '1' : '0';
                $row["Attribute {$attrIndex} default"]  = $opt['default_value'] ?? '';
                $attrIndex++;
            }
        }

        // Apply formula escaping if spreadsheet_safe profile
        if ($profile === 'spreadsheet_safe') {
            foreach ($row as $k => $v) {
                $row[$k] = $this->escapeFormula($v);
            }
        }

        return array_values($row);
    }

    /**
     * Format variation row adhering to WooCommerce baseline schema (R132, R133, R135).
     */
    private function formatVariationRow(array $v, array $parentAgg, string $parentRef, array $headers, bool $includeIds, string $profile, array &$losses): array
    {
        $variantId = (int)$v['id'];
        $row = array_fill_keys($headers, '');

        if ($includeIds) {
            $row['ID'] = (string)$variantId;
        }

        $row['Type'] = 'variation';
        $row['SKU'] = $v['sku'] ?? '';
        $row['Name'] = $parentAgg['title'] ?? $parentAgg['name'] ?? '';
        $row['Published'] = ($v['status'] === 'publish') ? '1' : '0';
        $row['Visibility in catalog'] = 'visible';
        $row['Parent'] = $parentRef;
        $row['Tax status'] = 'taxable';
        $row['Tax class'] = 'parent';

        // Stock semantics (R131)
        $vStockStatus = $v['stock_status'] ?? (!empty($v['manage_stock']) && (float)($v['stock_quantity'] ?? 0) <= 0 ? 'outofstock' : 'instock');
        if (!empty($v['manage_stock'])) {
            $row['In stock?'] = ($vStockStatus === 'outofstock') ? '0' : '1';
            $row['Stock'] = ($v['stock_quantity'] !== null) ? (string)(float)$v['stock_quantity'] : '';
        } else {
            $row['In stock?'] = ($vStockStatus === 'outofstock') ? '0' : '1';
            $row['Stock'] = '';
        }

        $row['Sale price'] = ($v['sale_price'] !== null) ? sprintf('%.2f', (float)$v['sale_price']) : '';
        $row['Regular price'] = ($v['regular_price'] !== null) ? sprintf('%.2f', (float)$v['regular_price']) : '';
        $row['Position'] = (string)($v['position'] ?? 0);
        $row['Description'] = $v['description'] ?? '';

        // Variation image mapping (R135):
        // In native WooCommerce, variation only has 1 image.
        // First image is placed in Images column; additional gallery images are logged in loss report.
        if (!empty($v['media'])) {
            $varImgUrls = [];
            foreach ($v['media'] as $vm) {
                if (!empty($vm['source_url'])) {
                    $varImgUrls[] = $vm['source_url'];
                }
            }

            if (!empty($varImgUrls)) {
                $row['Images'] = $varImgUrls[0]; // Primary variation image

                if (count($varImgUrls) > 1) {
                    $excessUrls = array_slice($varImgUrls, 1);
                    $losses[] = [
                        'field'   => 'variant_multi_gallery',
                        'path'    => "products[{$parentAgg['id']}].variations[{$variantId}].gallery",
                        'reason'  => 'Native WooCommerce CSV does not support multi-gallery variation images; additional images recorded in loss report',
                        'value'   => $excessUrls,
                    ];
                }
            }
        }

        // Variation attributes
        $varAttrs = $v['attributes'] ?? [];
        if (!empty($parentAgg['options'])) {
            $attrIndex = 1;
            foreach ($parentAgg['options'] as $opt) {
                $optName = $opt['name'];
                $val = $varAttrs[$optName] ?? '';
                $row["Attribute {$attrIndex} name"]     = $optName;
                $row["Attribute {$attrIndex} value(s)"] = $val;
                $row["Attribute {$attrIndex} visible"]  = '';
                $row["Attribute {$attrIndex} global"]   = !empty($opt['is_global']) ? '1' : '0';
                $row["Attribute {$attrIndex} default"]  = '';
                $attrIndex++;
            }
        }

        if ($profile === 'spreadsheet_safe') {
            foreach ($row as $k => $val) {
                $row[$k] = $this->escapeFormula($val);
            }
        }

        return array_values($row);
    }

    /**
     * Formula injection escaping for spreadsheet_safe profile.
     */
    private function escapeFormula(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    private function getProductMediaWithUrls(int $productId): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT pm.asset_id, pm.role, pm.position, pm.alt_override, a.source_url
             FROM {$this->tableProductMedia} pm
             JOIN {$this->tableAssets} a ON pm.asset_id = a.id
             WHERE pm.product_id = %d
             ORDER BY pm.position ASC, pm.asset_id ASC",
            $productId
        ), ARRAY_A) ?: [];
    }

    private function getVariantMediaWithUrls(int $variantId): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT vm.asset_id, vm.position, vm.is_primary, a.source_url
             FROM {$this->tableVariantMedia} vm
             JOIN {$this->tableAssets} a ON vm.asset_id = a.id
             WHERE vm.variant_id = %d
             ORDER BY vm.is_primary DESC, vm.position ASC",
            $variantId
        ), ARRAY_A) ?: [];
    }

    private function getProductImageUrls(int $productId, bool $isVariation = false): array
    {
        if ($isVariation) {
            $rows = $this->getVariantMediaWithUrls($productId);
        } else {
            $rows = $this->getProductMediaWithUrls($productId);
        }
        return array_values(array_filter(array_column($rows, 'source_url')));
    }

    private function getVariantAttributesMap(int $productId, int $variantId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.name, ov.label
             FROM {$this->tableVariantValues} vv
             JOIN {$this->tableOptionValues} ov ON vv.value_id = ov.id
             JOIN {$this->tableOptions} o ON ov.option_id = o.id
             WHERE vv.variant_id = %d AND o.product_id = %d",
            $variantId,
            $productId
        ), ARRAY_A);

        $map = [];
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $map[$r['name']] = $r['label'];
            }
        }
        return $map;
    }

    private function getProductTermNames(int $productId, string $taxonomy): array
    {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }
        $terms = wp_get_object_terms($productId, $taxonomy, ['fields' => 'names']);
        return (!is_wp_error($terms) && is_array($terms)) ? $terms : [];
    }
}
