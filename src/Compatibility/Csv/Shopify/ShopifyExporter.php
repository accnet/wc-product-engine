<?php

declare(strict_types=1);

namespace WPE\Compatibility\Csv\Shopify;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * Exception thrown when strict export validation fails.
 */
class StrictExportException extends RuntimeException
{
    private array $losses;
    private array $report;

    public function __construct(string $message, array $losses = [], array $report = [], int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->losses = $losses;
        $this->report = $report;
    }

    public function getLosses(): array
    {
        return $this->losses;
    }

    public function getReport(): array
    {
        return $this->report;
    }
}

/**
 * ShopifyExporter
 *
 * Implements:
 * - Shopify Current & Legacy profile CSV export without WPE_* columns (R141, AC-T35-01).
 * - Exact image/variant association and gallery image-only rows (R142, AC-T35-01).
 * - Strict vs lossy policies for >3 options and structured loss reporting (R143, AC-T35-01).
 * - Preservation of native IDs and engine fields in sidecar archive, never extra CSV columns (R144, AC-T35-02).
 * - Explicit subset declaration stating Shopify CSV does not guarantee native ID preservation (AC-T35-02).
 */
class ShopifyExporter
{
    private static ?ShopifyExporter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;

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

    /**
     * Standard Shopify Current Headers (2024+ format)
     */
    public const CURRENT_HEADERS = [
        'URL handle',
        'Title',
        'Description',
        'Vendor',
        'Product category',
        'Type',
        'Tags',
        'Published on online store',
        'Status',
        'Option1 name',
        'Option1 value',
        'Option2 name',
        'Option2 value',
        'Option3 name',
        'Option3 value',
        'SKU',
        'Variant grams',
        'Variant inventory tracker',
        'Variant inventory qty',
        'Variant inventory policy',
        'Variant fulfillment service',
        'Price',
        'Compare-at price',
        'Variant requires shipping',
        'Variant taxable',
        'Variant barcode',
        'Product image URL',
        'Image position',
        'Image alt text',
        'Variant image URL',
        'Variant weight unit',
        'Variant tax code',
        'Cost per item',
    ];

    /**
     * Standard Shopify Legacy Headers
     */
    public const LEGACY_HEADERS = [
        'Handle',
        'Title',
        'Body (HTML)',
        'Vendor',
        'Type',
        'Tags',
        'Published',
        'Option1 Name',
        'Option1 Value',
        'Option2 Name',
        'Option2 Value',
        'Option3 Name',
        'Option3 Value',
        'Variant SKU',
        'Variant Grams',
        'Variant Inventory Tracker',
        'Variant Inventory Qty',
        'Variant Inventory Policy',
        'Variant Fulfillment Service',
        'Variant Price',
        'Variant Compare At Price',
        'Variant Requires Shipping',
        'Variant Taxable',
        'Variant Barcode',
        'Image Src',
        'Image Position',
        'Image Alt Text',
        'Gift Card',
        'SEO Title',
        'SEO Description',
        'Variant Image',
        'Variant Weight Unit',
        'Variant Tax Code',
        'Cost per item',
        'Status',
    ];

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null,
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

        $this->productRepo  = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo  = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->assetService = $assetService ?? new AssetService($this->wpdb);

        if ($customExportDir !== null) {
            $this->exportDir = rtrim($customExportDir, '/\\');
        } else {
            $wpUpload = wp_upload_dir();
            $this->exportDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-shopify-exports';
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
     * Export products into Shopify CSV format.
     *
     * @param int[] $productIds Array of product IDs to export, or empty array for all active products
     * @param array $options Configuration options:
     *                       - 'profile': 'current' (default) or 'legacy'
     *                       - 'mode': 'strict' (default) or 'lossy'
     *                       - 'sidecar_archive': bool (default true)
     *                       - 'spreadsheet_safe': bool (default true)
     *                       - 'file_name': string (optional)
     *                       - 'throw_on_error': bool (default true)
     * @return array Export summary receipt
     */
    public function exportProducts(array $productIds = [], array $options = []): array
    {
        $profile         = $options['profile'] ?? 'current';
        $mode            = $options['mode'] ?? 'strict';
        $sidecarArchive  = $options['sidecar_archive'] ?? true;
        $spreadsheetSafe = $options['spreadsheet_safe'] ?? true;
        $throwOnError    = $options['throw_on_error'] ?? true;

        if (!in_array($profile, ['current', 'legacy'], true)) {
            throw new InvalidArgumentException("Invalid profile '{$profile}'. Must be 'current' or 'legacy'.");
        }
        if (!in_array($mode, ['strict', 'lossy'], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Must be 'strict' or 'lossy'.");
        }

        // Subset declaration (AC-T35-02)
        $subsetDeclaration = [
            'format'                 => 'shopify',
            'profile'                => $profile,
            'schema_version'         => $profile === 'current' ? '2024-standard' : 'legacy-baseline',
            'preserves_native_ids'   => false,
            'notice'                 => 'Shopify product CSV is a domain subset; native aggregate and variant IDs are NOT preserved in Shopify CSV columns',
            'lossy_unsupported_keys' => [
                'internal_ids'       => 'Shopify CSV does not support native WPE aggregate, product, or variant IDs',
                'options_limit'      => 'Maximum 3 options per product aggregate supported by Shopify specification',
                'extra_gallery'      => 'Variant secondary gallery images not supported (primary variant image mapped)',
                'meta_fields'        => 'Custom engine metadata, download files, and revision epochs not written to CSV',
            ],
            'sidecar_archive'        => $sidecarArchive,
        ];

        // 1. Resolve product IDs
        if (empty($productIds)) {
            $productIds = array_map('intval', $this->wpdb->get_col(
                "SELECT id FROM {$this->tableProducts} WHERE deleted_at IS NULL AND status != 'trash' ORDER BY id ASC"
            ));
        }

        if (empty($productIds)) {
            return [
                'status'             => 'completed',
                'total_products'     => 0,
                'total_variations'   => 0,
                'total_rows'         => 0,
                'total_image_rows'   => 0,
                'losses'             => [],
                'subset_declaration' => $subsetDeclaration,
            ];
        }

        // 2. Fetch aggregates and validate strict constraints (>3 options - R143, AC-T35-01)
        $aggregates = [];
        $strictErrors = [];
        $losses = [];

        foreach ($productIds as $pId) {
            $agg = $this->productRepo->getById((int)$pId);
            if (!$agg) {
                continue;
            }

            $optionsList = $this->optionsRepo->getOptionsByProductId((int)$pId);
            $optionCount = count($optionsList);

            if ($optionCount > 3) {
                $lossItem = [
                    'field'   => 'more_than_three_options',
                    'path'    => "products[{$agg['slug']}].options",
                    'reason'  => sprintf('Product %s has %d options, exceeding Shopify limit of 3 options', $agg['slug'], $optionCount),
                    'value'   => array_column($optionsList, 'name'),
                ];
                $losses[] = $lossItem;

                if ($mode === 'strict') {
                    $strictErrors[] = $lossItem;
                }
            }

            $agg['engine_options'] = $optionsList;
            $aggregates[] = $agg;
        }

        if (!empty($strictErrors)) {
            $errorMsg = sprintf(
                'Strict export failed: %d product(s) have more than 3 options (Shopify maximum is 3).',
                count($strictErrors)
            );

            if ($throwOnError) {
                throw new StrictExportException($errorMsg, $strictErrors, [
                    'status'             => 'failed',
                    'error'              => $errorMsg,
                    'losses'             => $strictErrors,
                    'subset_declaration' => $subsetDeclaration,
                ]);
            }

            return [
                'status'             => 'failed',
                'error'              => $errorMsg,
                'losses'             => $strictErrors,
                'subset_declaration' => $subsetDeclaration,
            ];
        }

        // 3. Prepare CSV file path
        $fileName = $options['file_name'] ?? sprintf('shopify-export-%s-%s-%s.csv', $profile, date('Ymd-His'), bin2hex(random_bytes(4)));
        $filePath = $this->exportDir . '/' . $fileName;

        $fp = fopen($filePath, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open CSV file for writing: {$filePath}");
        }

        // Write UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");

        // Write Headers (R141, R144: NO WPE_* or extra native columns!)
        $headers = $profile === 'current' ? self::CURRENT_HEADERS : self::LEGACY_HEADERS;
        fputcsv($fp, $headers);

        $totalProducts   = 0;
        $totalVariations = 0;
        $totalRows       = 0;
        $totalImageRows  = 0;
        $sidecarData     = [];

        // 4. Serialize product aggregates
        foreach ($aggregates as $agg) {
            $totalProducts++;
            $productId = (int)$agg['id'];
            $handle    = $agg['slug'];

            $galleryImages = $this->getProductGalleryImages($productId);
            $optionsList   = $agg['engine_options'] ?? [];
            $exportedOpts  = array_slice($optionsList, 0, 3);
            $isVariable    = ($agg['type'] === 'variable') || (!empty($agg['variations']));

            $sidecarProduct = [
                'id'                 => $productId,
                'slug'               => $handle,
                'sku'                => $agg['sku'],
                'type'               => $agg['type'],
                'all_options'        => $optionsList,
                'unsupported_fields' => [
                    'version'           => $agg['version'] ?? 1,
                    'short_description' => $agg['short_description'] ?? '',
                ],
                'variants'           => [],
            ];

            if (!$isVariable) {
                // SIMPLE PRODUCT: 1 main variant row + extra gallery image rows
                $mainRow = $this->buildSimpleProductRow(
                    $agg,
                    $galleryImages,
                    $headers,
                    $profile,
                    $spreadsheetSafe
                );
                fputcsv($fp, $mainRow);
                $totalRows++;

                // Extra gallery images as image-only rows (R137, R142)
                for ($imgIdx = 1; $imgIdx < count($galleryImages); $imgIdx++) {
                    $imgRow = $this->buildImageOnlyRow(
                        $handle,
                        $galleryImages[$imgIdx],
                        $imgIdx + 1,
                        $headers,
                        $profile,
                        $spreadsheetSafe
                    );
                    fputcsv($fp, $imgRow);
                    $totalRows++;
                    $totalImageRows++;
                }
            } else {
                // VARIABLE PRODUCT
                $variants = $this->getVariantsForProduct($productId);
                $totalVariations += count($variants);

                $varCount = count($variants);
                $imgCount = count($galleryImages);

                // Export variant rows
                foreach ($variants as $vIdx => $v) {
                    $sidecarProduct['variants'][] = [
                        'id'              => (int)$v['id'],
                        'sku'             => $v['sku'],
                        'combination_key' => bin2hex($v['combination_key'] ?? ''),
                        'attributes'      => $v['attributes'] ?? [],
                    ];

                    // Check for secondary variant images loss (R135, R142)
                    $variantMedia = $this->getVariantMedia((int)$v['id']);
                    $primaryVarImg = null;
                    $secondaryVarImgs = [];
                    foreach ($variantMedia as $vm) {
                        if (!empty($vm['is_primary'])) {
                            if ($primaryVarImg === null) {
                                $primaryVarImg = $vm;
                            }
                        } else {
                            $secondaryVarImgs[] = $vm['source_url'];
                        }
                    }
                    if ($primaryVarImg === null && !empty($variantMedia)) {
                        $primaryVarImg = $variantMedia[0];
                    }
                    if (!empty($secondaryVarImgs)) {
                        $losses[] = [
                            'field'   => 'variant_secondary_gallery',
                            'path'    => "products[{$handle}].variants[{$v['sku']}].media",
                            'reason'  => 'Shopify CSV supports only single primary variant image; secondary variant gallery omitted from CSV',
                            'value'   => $secondaryVarImgs,
                        ];
                    }

                    // Assign product gallery image for this row if available
                    $rowProductImg = ($vIdx < $imgCount) ? $galleryImages[$vIdx] : null;
                    $rowImgPos     = ($vIdx < $imgCount) ? ($vIdx + 1) : null;

                    $vRow = $this->buildVariableVariantRow(
                        $agg,
                        $v,
                        $vIdx,
                        $exportedOpts,
                        $rowProductImg,
                        $rowImgPos,
                        $primaryVarImg,
                        $headers,
                        $profile,
                        $spreadsheetSafe
                    );
                    fputcsv($fp, $vRow);
                    $totalRows++;
                }

                // If more gallery images than variants, output remaining as image-only rows (R137, R142)
                for ($imgIdx = $varCount; $imgIdx < $imgCount; $imgIdx++) {
                    $imgRow = $this->buildImageOnlyRow(
                        $handle,
                        $galleryImages[$imgIdx],
                        $imgIdx + 1,
                        $headers,
                        $profile,
                        $spreadsheetSafe
                    );
                    fputcsv($fp, $imgRow);
                    $totalRows++;
                    $totalImageRows++;
                }
            }

            $sidecarData[$handle] = $sidecarProduct;
        }

        fclose($fp);

        // 5. Write Sidecar Archive if enabled (R144, AC-T35-02)
        $sidecarPath = null;
        if ($sidecarArchive) {
            $sidecarPath = preg_replace('/\.csv$/i', '.sidecar.json', $filePath);
            $sidecarPayload = [
                'format'             => 'shopify_sidecar',
                'csv_file'           => basename($filePath),
                'generated_at'       => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'profile'            => $profile,
                'subset_declaration' => $subsetDeclaration,
                'products'           => $sidecarData,
            ];
            file_put_contents($sidecarPath, json_encode($sidecarPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // 6. Write Loss Report if any information was unrepresented
        $lossReportPath = null;
        if (!empty($losses)) {
            $lossReportPath = preg_replace('/\.csv$/i', '-loss-report.json', $filePath);
            $lossReportPayload = [
                'total_losses' => count($losses),
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'losses'       => $losses,
            ];
            file_put_contents($lossReportPath, json_encode($lossReportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return [
            'status'             => 'completed',
            'file_path'          => $filePath,
            'file_sha256'        => hash_file('sha256', $filePath),
            'sidecar_path'       => $sidecarPath,
            'loss_report_path'   => $lossReportPath,
            'total_products'     => $totalProducts,
            'total_variations'   => $totalVariations,
            'total_rows'         => $totalRows,
            'total_image_rows'   => $totalImageRows,
            'profile'            => $profile,
            'mode'               => $mode,
            'subset_declaration' => $subsetDeclaration,
            'losses'             => $losses,
        ];
    }

    /**
     * Build row for a simple product.
     */
    private function buildSimpleProductRow(
        array $agg,
        array $galleryImages,
        array $headers,
        string $profile,
        bool $spreadsheetSafe
    ): array {
        $row = array_fill_keys($headers, '');

        $handle = $agg['slug'];
        $title  = $agg['title'];
        $desc   = $agg['description'] ?? '';
        $sku    = $agg['sku'] ?? '';

        // Price mapping (R138 inverse): compare-at > price
        $priceData = $this->formatPrices($agg['regular_price'] ?? null, $agg['sale_price'] ?? null);

        // Inventory mapping
        $inv = $this->getInventory((int)$agg['id']);
        $weightGrams = $this->formatGrams($agg['weight'] ?? null);

        // Status mapping
        $statusStr = $this->formatStatus($agg['status'] ?? 'draft');
        $published = ($statusStr === 'active') ? 'TRUE' : 'FALSE';

        if ($profile === 'current') {
            $row['URL handle']                  = $handle;
            $row['Title']                       = $title;
            $row['Description']                 = $desc;
            $row['Vendor']                      = '';
            $row['Product category']            = '';
            $row['Type']                        = '';
            $row['Tags']                        = '';
            $row['Published on online store']   = $published;
            $row['Status']                      = $statusStr;
            $row['Option1 name']                = 'Title';
            $row['Option1 value']               = 'Default Title';
            $row['SKU']                         = $sku;
            $row['Variant grams']               = $weightGrams;
            $row['Variant inventory tracker']   = $inv['tracker'];
            $row['Variant inventory qty']       = $inv['qty'];
            $row['Variant inventory policy']    = $inv['policy'];
            $row['Variant fulfillment service'] = 'manual';
            $row['Price']                       = $priceData['price'];
            $row['Compare-at price']            = $priceData['compare_at'];
            $row['Variant requires shipping']   = 'TRUE';
            $row['Variant taxable']             = 'TRUE';
            $row['Variant barcode']             = '';

            if (!empty($galleryImages)) {
                $row['Product image URL']       = $galleryImages[0]['url'];
                $row['Image position']          = 1;
                $row['Image alt text']          = $galleryImages[0]['alt'];
            }
            $row['Variant image URL']           = '';
            $row['Variant weight unit']         = 'kg';
        } else {
            // Legacy profile
            $row['Handle']                      = $handle;
            $row['Title']                       = $title;
            $row['Body (HTML)']                 = $desc;
            $row['Vendor']                      = '';
            $row['Type']                        = '';
            $row['Tags']                        = '';
            $row['Published']                   = $published;
            $row['Option1 Name']                = 'Title';
            $row['Option1 Value']               = 'Default Title';
            $row['Variant SKU']                 = $sku;
            $row['Variant Grams']               = $weightGrams;
            $row['Variant Inventory Tracker']   = $inv['tracker'];
            $row['Variant Inventory Qty']       = $inv['qty'];
            $row['Variant Inventory Policy']    = $inv['policy'];
            $row['Variant Fulfillment Service'] = 'manual';
            $row['Variant Price']               = $priceData['price'];
            $row['Variant Compare At Price']    = $priceData['compare_at'];
            $row['Variant Requires Shipping']   = 'TRUE';
            $row['Variant Taxable']             = 'TRUE';
            $row['Variant Barcode']             = '';

            if (!empty($galleryImages)) {
                $row['Image Src']               = $galleryImages[0]['url'];
                $row['Image Position']          = 1;
                $row['Image Alt Text']          = $galleryImages[0]['alt'];
            }
            $row['Variant Image']               = '';
            $row['Variant Weight Unit']         = 'kg';
            $row['Status']                      = $statusStr;
        }

        return $this->finalizeRow($row, $headers, $spreadsheetSafe);
    }

    /**
     * Build row for a variable product variant.
     */
    private function buildVariableVariantRow(
        array $agg,
        array $v,
        int $vIdx,
        array $exportedOpts,
        ?array $rowProductImg,
        ?int $rowImgPos,
        ?array $primaryVarImg,
        array $headers,
        string $profile,
        bool $spreadsheetSafe
    ): array {
        $row = array_fill_keys($headers, '');

        $handle    = $agg['slug'];
        $title     = ($vIdx === 0) ? $agg['title'] : '';
        $desc      = ($vIdx === 0) ? ($agg['description'] ?? '') : '';
        $sku       = $v['sku'] ?? '';
        $statusStr = $this->formatStatus($v['status'] ?? $agg['status'] ?? 'publish');
        $published = ($statusStr === 'active') ? 'TRUE' : 'FALSE';

        // Price mapping
        $priceData = $this->formatPrices($v['regular_price'] ?? null, $v['sale_price'] ?? null);

        // Inventory mapping
        $inv = $this->getInventory((int)$v['id']);
        $weightGrams = $this->formatGrams($v['weight'] ?? $agg['weight'] ?? null);

        // Option values mapping (up to 3 options)
        $optMap = [];
        foreach ($exportedOpts as $idx => $opt) {
            $optNum = $idx + 1;
            $optName = $opt['name'];
            $optVal = $v['attributes'][$optName] ?? ($v['attributes'][$opt['id']] ?? '');
            $optMap[$optNum] = [
                'name'  => $optName,
                'value' => $optVal,
            ];
        }

        $prodImgUrl = $rowProductImg['url'] ?? '';
        $prodImgPos = $rowImgPos ?? '';
        $prodImgAlt = $rowProductImg['alt'] ?? '';

        $varImgUrl = $primaryVarImg['source_url'] ?? '';

        if ($profile === 'current') {
            $row['URL handle']                  = $handle;
            $row['Title']                       = $title;
            $row['Description']                 = $desc;
            $row['Vendor']                      = '';
            $row['Product category']            = '';
            $row['Type']                        = '';
            $row['Tags']                        = '';
            $row['Published on online store']   = ($vIdx === 0) ? $published : '';
            $row['Status']                      = ($vIdx === 0) ? $statusStr : '';

            for ($i = 1; $i <= 3; $i++) {
                if (isset($optMap[$i])) {
                    $row["Option{$i} name"]  = $optMap[$i]['name'];
                    $row["Option{$i} value"] = $optMap[$i]['value'];
                }
            }

            $row['SKU']                         = $sku;
            $row['Variant grams']               = $weightGrams;
            $row['Variant inventory tracker']   = $inv['tracker'];
            $row['Variant inventory qty']       = $inv['qty'];
            $row['Variant inventory policy']    = $inv['policy'];
            $row['Variant fulfillment service'] = 'manual';
            $row['Price']                       = $priceData['price'];
            $row['Compare-at price']            = $priceData['compare_at'];
            $row['Variant requires shipping']   = 'TRUE';
            $row['Variant taxable']             = 'TRUE';
            $row['Variant barcode']             = '';

            $row['Product image URL']           = $prodImgUrl;
            $row['Image position']              = $prodImgPos;
            $row['Image alt text']              = $prodImgAlt;

            $row['Variant image URL']           = $varImgUrl;
            $row['Variant weight unit']         = 'kg';
        } else {
            // Legacy profile
            $row['Handle']                      = $handle;
            $row['Title']                       = $title;
            $row['Body (HTML)']                 = $desc;
            $row['Vendor']                      = '';
            $row['Type']                        = '';
            $row['Tags']                        = '';
            $row['Published']                   = ($vIdx === 0) ? $published : '';

            for ($i = 1; $i <= 3; $i++) {
                if (isset($optMap[$i])) {
                    $row["Option{$i} Name"]  = $optMap[$i]['name'];
                    $row["Option{$i} Value"] = $optMap[$i]['value'];
                }
            }

            $row['Variant SKU']                 = $sku;
            $row['Variant Grams']               = $weightGrams;
            $row['Variant Inventory Tracker']   = $inv['tracker'];
            $row['Variant Inventory Qty']       = $inv['qty'];
            $row['Variant Inventory Policy']    = $inv['policy'];
            $row['Variant Fulfillment Service'] = 'manual';
            $row['Variant Price']               = $priceData['price'];
            $row['Variant Compare At Price']    = $priceData['compare_at'];
            $row['Variant Requires Shipping']   = 'TRUE';
            $row['Variant Taxable']             = 'TRUE';
            $row['Variant Barcode']             = '';

            $row['Image Src']                   = $prodImgUrl;
            $row['Image Position']              = $prodImgPos;
            $row['Image Alt Text']              = $prodImgAlt;

            $row['Variant Image']               = $varImgUrl;
            $row['Variant Weight Unit']         = 'kg';
            $row['Status']                      = ($vIdx === 0) ? $statusStr : '';
        }

        return $this->finalizeRow($row, $headers, $spreadsheetSafe);
    }

    /**
     * Build an image-only continuation row for product gallery (R137, R142).
     */
    private function buildImageOnlyRow(
        string $handle,
        array $image,
        int $position,
        array $headers,
        string $profile,
        bool $spreadsheetSafe
    ): array {
        $row = array_fill_keys($headers, '');

        if ($profile === 'current') {
            $row['URL handle']        = $handle;
            $row['Product image URL'] = $image['url'];
            $row['Image position']    = $position;
            $row['Image alt text']    = $image['alt'];
        } else {
            $row['Handle']            = $handle;
            $row['Image Src']         = $image['url'];
            $row['Image Position']    = $position;
            $row['Image Alt Text']    = $image['alt'];
        }

        return $this->finalizeRow($row, $headers, $spreadsheetSafe);
    }

    /**
     * Finalize row and apply spreadsheet-safe formula escaping if requested.
     */
    private function finalizeRow(array $row, array $headers, bool $spreadsheetSafe): array
    {
        $ordered = [];
        foreach ($headers as $h) {
            $val = (string)($row[$h] ?? '');
            if ($spreadsheetSafe && $val !== '') {
                $c = $val[0];
                if (in_array($c, ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $val = "'" . $val;
                }
            }
            $ordered[] = $val;
        }
        return $ordered;
    }

    /**
     * Format regular and sale price into Shopify price and compare-at.
     */
    public function formatPrices($regularPrice, $salePrice): array
    {
        $reg = ($regularPrice !== null && $regularPrice !== '') ? (float)$regularPrice : null;
        $sale = ($salePrice !== null && $salePrice !== '') ? (float)$salePrice : null;

        if ($sale !== null && $reg !== null && $reg > $sale) {
            return [
                'price'      => sprintf('%.2f', $sale),
                'compare_at' => sprintf('%.2f', $reg),
            ];
        }

        if ($reg !== null) {
            return [
                'price'      => sprintf('%.2f', $reg),
                'compare_at' => '',
            ];
        }

        if ($sale !== null) {
            return [
                'price'      => sprintf('%.2f', $sale),
                'compare_at' => '',
            ];
        }

        return [
            'price'      => '',
            'compare_at' => '',
        ];
    }

    /**
     * Convert weight in kg to grams.
     */
    public function formatGrams($weightKg): string
    {
        if ($weightKg === null || $weightKg === '') {
            return '';
        }
        $val = (float)$weightKg;
        if ($val <= 0) {
            return '';
        }
        return (string)round($val * 1000);
    }

    /**
     * Map WPE status to Shopify status.
     */
    public function formatStatus(string $status): string
    {
        switch (strtolower(trim($status))) {
            case 'publish':
            case 'published':
            case 'active':
                return 'active';
            case 'draft':
            case 'pending':
                return 'draft';
            case 'trash':
            case 'archived':
            case 'private':
            default:
                return 'archived';
        }
    }

    /**
     * Fetch inventory data for owner.
     */
    private function getInventory(int $ownerId): array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ), ARRAY_A);

        if (!$row || empty($row['manage_stock'])) {
            return [
                'tracker' => '',
                'qty'     => '',
                'policy'  => 'deny',
            ];
        }

        $qty = ($row['quantity'] !== null) ? (string)(int)$row['quantity'] : '0';
        $policy = !empty($row['backorders_allowed']) ? 'continue' : 'deny';

        return [
            'tracker' => 'shopify',
            'qty'     => $qty,
            'policy'  => $policy,
        ];
    }

    /**
     * Retrieve ordered gallery images for product (featured first, then gallery).
     */
    public function getProductGalleryImages(int $productId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT pm.role, pm.position, pm.alt_override, a.source_url, a.alt_text
             FROM {$this->tableProductMedia} pm
             JOIN {$this->tableAssets} a ON pm.asset_id = a.id
             WHERE pm.product_id = %d
             ORDER BY CASE WHEN pm.role = 'featured' THEN 0 ELSE 1 END ASC, pm.position ASC",
            $productId
        ), ARRAY_A);

        $images = [];
        foreach ($rows as $r) {
            $images[] = [
                'url'      => $r['source_url'],
                'position' => (int)$r['position'],
                'alt'      => !empty($r['alt_override']) ? $r['alt_override'] : $r['alt_text'],
            ];
        }

        return $images;
    }

    /**
     * Retrieve variant media.
     */
    public function getVariantMedia(int $variantId): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT vm.position, vm.is_primary, vm.alt_override, a.source_url, a.alt_text
             FROM {$this->tableVariantMedia} vm
             JOIN {$this->tableAssets} a ON vm.asset_id = a.id
             WHERE vm.variant_id = %d
             ORDER BY vm.position ASC",
            $variantId
        ), ARRAY_A);
    }

    /**
     * Retrieve variants for product with attribute values.
     */
    private function getVariantsForProduct(int $productId): array
    {
        $variants = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC",
            $productId
        ), ARRAY_A);

        foreach ($variants as &$v) {
            $vId = (int)$v['id'];
            $attrs = [];

            $valRows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT o.name as option_name, o.id as option_id, ov.label as value_label, vv.is_any
                 FROM {$this->tableVariantValues} vv
                 JOIN {$this->tableOptions} o ON vv.option_id = o.id
                 LEFT JOIN {$this->tableOptionValues} ov ON vv.value_id = ov.id
                 WHERE vv.variant_id = %d
                 ORDER BY o.position ASC, o.id ASC",
                $vId
            ), ARRAY_A);

            foreach ($valRows as $vr) {
                $label = !empty($vr['is_any']) ? 'Any' : ($vr['value_label'] ?? '');
                $attrs[$vr['option_name']] = $label;
                $attrs[$vr['option_id']]   = $label;
            }

            $v['attributes'] = $attrs;
        }

        return $variants;
    }
}
