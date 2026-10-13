<?php

declare(strict_types=1);

namespace WPE\Compatibility\Csv\Shopify;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Migration\LegacyMirror;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * ShopifyBatchImporter
 *
 * Implements high-performance, bounded-memory O(1) streaming batch import for large Shopify CSV files.
 * Features:
 * - Chunked processing via persistent byte offsets (fseek) preventing PHP/Nginx timeouts.
 * - Real-time checkpoint persistence in wp_wpe_jobs table.
 * - Seamless Pause and Resume from last checkpoint.
 * - Cancellation support at batch boundary.
 * - Persistent streaming log file creation and live console updates.
 * - Automatic catalog index generation rebuild upon completion.
 */
class ShopifyBatchImporter
{
    private static ?ShopifyBatchImporter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;
    private ShopifyImporter $shopifyImporter;

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
    private string $tableObjectState;
    private string $tableJobs;

    private string $logBaseDir;

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $attachmentBridge = null,
        ?ShopifyImporter $shopifyImporter = null
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
        $this->tableObjectState   = "{$prefix}wpe_object_state";
        $this->tableJobs          = "{$prefix}wpe_jobs";

        $this->productRepo      = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo      = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->assetService     = $assetService ?? new AssetService($this->wpdb);
        $this->attachmentBridge = $attachmentBridge ?? AttachmentBridge::instance($this->wpdb);
        $this->shopifyImporter  = $shopifyImporter ?? ShopifyImporter::getInstance();

        $wpUpload = wp_upload_dir();
        $this->logBaseDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-imports/logs';
        if (!is_dir($this->logBaseDir)) {
            wp_mkdir_p($this->logBaseDir);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize an import job from a staged or local CSV file.
     *
     * @param string $filePath Absolute path to CSV file.
     * @param array $options Configuration options (update_mode, image_mode, batch_size, original_filename).
     * @return array Job summary.
     */
    public function initJob(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException("File not found or unreadable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        $fileHash = hash_file('sha256', $filePath);

        // Sniff CSV headers and BOM
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        $bom = fread($handle, 3);
        $hasBom = ($bom === "\xEF\xBB\xBF");
        if (!$hasBom) {
            rewind($handle);
        }

        $rawHeaders = fgetcsv($handle);
        if ($rawHeaders === false || empty($rawHeaders)) {
            fclose($handle);
            throw new RuntimeException("CSV file contains no header row");
        }

        $headers = [];
        $unsupportedColumns = [];
        foreach ($rawHeaders as $idx => $rawH) {
            $cleaned = trim((string)$rawH);
            if ($idx === 0 && str_starts_with($cleaned, "\xEF\xBB\xBF")) {
                $cleaned = substr($cleaned, 3);
            }
            $normKey = $this->shopifyImporter->normalizeHeader($cleaned);
            $headers[$idx] = [
                'raw'  => $cleaned,
                'norm' => $normKey,
            ];

            foreach (ShopifyImporter::UNSUPPORTED_COLUMNS as $unsupportedPattern) {
                if (str_contains(strtolower($cleaned), $unsupportedPattern)) {
                    $unsupportedColumns[] = $cleaned;
                    break;
                }
            }
        }

        $dataStartOffset = ftell($handle);
        fclose($handle);

        $jobId = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');

        $logFilePath = $this->logBaseDir . "/import_{$jobId}.log";
        $initialLog = sprintf(
            "[%s] [INFO] === Import Job Initialized ===\n" .
            "[%s] [INFO] Job ID: %s\n" .
            "[%s] [INFO] File: %s (Size: %s, SHA256: %s)\n" .
            "[%s] [INFO] Format: Shopify CSV, Headers: %d columns\n" .
            "[%s] [INFO] Update Mode: %s, Image Mode: %s\n\n",
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'), $jobId,
            gmdate('Y-m-d H:i:s'), basename($options['original_filename'] ?? $filePath), size_format($fileSize), substr($fileHash, 0, 16) . '...',
            gmdate('Y-m-d H:i:s'), count($headers),
            gmdate('Y-m-d H:i:s'), $options['update_mode'] ?? 'merge', $options['image_mode'] ?? 'external'
        );
        @file_put_contents($logFilePath, $initialLog);

        $config = [
            'file_path'          => $filePath,
            'original_filename'  => $options['original_filename'] ?? basename($filePath),
            'file_size'          => $fileSize,
            'file_hash'          => $fileHash,
            'has_bom'            => $hasBom,
            'data_start_offset'  => $dataStartOffset,
            'headers'            => $headers,
            'unsupported_cols'   => array_values(array_unique($unsupportedColumns)),
            'update_mode'        => $options['update_mode'] ?? 'merge',
            'image_mode'         => $options['image_mode'] ?? 'external',
            'batch_size'         => (int)($options['batch_size'] ?? 25),
            'log_file'           => $logFilePath,
        ];

        $checkpoint = [
            'byte_offset'         => $dataStartOffset,
            'current_row'         => 1, // 1 is header row
            'last_handle'         => '',
            'products_processed'  => 0,
            'variants_processed'  => 0,
            'errors_count'        => 0,
        ];

        $counts = [
            'file_size'           => $fileSize,
            'bytes_processed'     => $dataStartOffset,
            'products_processed'  => 0,
            'variants_processed'  => 0,
            'errors'              => 0,
        ];

        $this->wpdb->insert($this->tableJobs, [
            'id'               => $jobId,
            'kind'             => 'shopify_import',
            'status'           => 'pending',
            'config'           => wp_json_encode($config),
            'checkpoint'       => wp_json_encode($checkpoint),
            'counts'           => wp_json_encode($counts),
            'cancel_requested' => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return [
            'job_id'            => $jobId,
            'status'            => 'pending',
            'file_size'         => $fileSize,
            'file_size_fmt'     => size_format($fileSize),
            'headers_count'     => count($headers),
            'unsupported_cols'  => $config['unsupported_cols'],
            'initial_log'       => $initialLog,
        ];
    }

    /**
     * Process a single batch chunk of products.
     *
     * @param string $jobId Job UUID.
     * @param int $batchSize Number of products to process in this batch (default 25).
     * @return array Batch execution result with progress and live logs.
     */
    public function processBatch(string $jobId, int $batchSize = 25): array
    {
        $job = $this->getJob($jobId);
        if (!$job) {
            throw new RuntimeException("Import job not found: {$jobId}", 404);
        }

        if ($job['cancel_requested'] == 1 || $job['status'] === 'cancelled') {
            return [
                'status'    => 'cancelled',
                'message'   => 'Import was cancelled by operator.',
                'job_id'    => $jobId,
                'completed' => true,
            ];
        }

        if ($job['status'] === 'paused') {
            return [
                'status'    => 'paused',
                'message'   => 'Import is currently paused. Click Resume to continue.',
                'job_id'    => $jobId,
                'completed' => false,
            ];
        }

        $config     = json_decode((string)$job['config'], true) ?? [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?? [];
        $counts     = json_decode((string)$job['counts'], true) ?? [];

        $filePath   = $config['file_path'] ?? '';
        $fileSize   = (int)($config['file_size'] ?? 0);
        $headers    = $config['headers'] ?? [];
        $logFile    = $config['log_file'] ?? '';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("CSV source file not accessible: {$filePath}", 404);
        }

        $byteOffset        = (int)($checkpoint['byte_offset'] ?? 0);
        $currentRow        = (int)($checkpoint['current_row'] ?? 1);
        $productsProcessed = (int)($checkpoint['products_processed'] ?? 0);
        $variantsProcessed = (int)($checkpoint['variants_processed'] ?? 0);
        $errorsCount       = (int)($checkpoint['errors_count'] ?? 0);

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Failed to open CSV stream: {$filePath}");
        }

        fseek($handle, $byteOffset);

        $productGroups = [];
        $currentGroup = null;
        $eof = false;
        $batchLogs = [];

        // Read lines until $batchSize product aggregates are collected or EOF
        while (true) {
            $lineStartOffset = ftell($handle);
            $cells = fgetcsv($handle);

            if ($cells === false) {
                // EOF reached
                if ($currentGroup !== null) {
                    $productGroups[] = $currentGroup;
                    $currentGroup = null;
                }
                $eof = true;
                $byteOffset = $fileSize;
                break;
            }

            $currentRow++;

            // Skip empty rows
            if (empty(array_filter($cells, fn($c) => trim((string)$c) !== ''))) {
                continue;
            }

            $row = [];
            foreach ($headers as $idx => $hInfo) {
                $row[$hInfo['norm']] = isset($cells[$idx]) ? trim((string)$cells[$idx]) : '';
            }

            $rowHandle = trim($row['handle'] ?? '');
            if ($rowHandle === '') {
                $rowHandle = 'product_' . $currentRow;
            }

            // Check if this row begins a NEW product aggregate
            if ($currentGroup !== null && $currentGroup['handle'] !== $rowHandle) {
                $productGroups[] = $currentGroup;
                $currentGroup = null;

                // Check if we have collected enough products for this batch
                if (count($productGroups) >= $batchSize) {
                    // Rewind to start of this new product's line so next batch picks it up cleanly
                    fseek($handle, $lineStartOffset);
                    $byteOffset = $lineStartOffset;
                    $currentRow--;
                    break;
                }
            }

            // Initialize or append to current product group
            if ($currentGroup === null) {
                $currentGroup = [
                    'handle'              => $rowHandle,
                    'title'               => $row['title'] ?? '',
                    'description'         => $row['description'] ?? '',
                    'vendor'              => $row['vendor'] ?? '',
                    'product_type'        => $row['product_type'] ?? '',
                    'tags'                => $row['tags'] ?? '',
                    'status'              => $this->mapStatus($row['status'] ?? $row['published'] ?? ''),
                    'options'             => [],
                    'option_names'        => [],
                    'images'              => [],
                    'variants'            => [],
                    'unsupported_columns' => $config['unsupported_cols'] ?? [],
                    'raw_headers'         => array_column($headers, 'norm'),
                ];
            } else {
                if ($currentGroup['title'] === '' && !empty($row['title'])) {
                    $currentGroup['title'] = $row['title'];
                }
                if ($currentGroup['description'] === '' && !empty($row['description'])) {
                    $currentGroup['description'] = $row['description'];
                }
            }

            // Image collection
            $imgUrl = trim($row['product_image_url'] ?? '');
            if ($imgUrl !== '') {
                $imgPos = isset($row['image_position']) && is_numeric($row['image_position']) ? (int)$row['image_position'] : count($currentGroup['images']) + 1;
                $currentGroup['images'][] = [
                    'url'      => $imgUrl,
                    'position' => $imgPos,
                    'alt'      => trim($row['image_alt_text'] ?? ''),
                ];
            }

            // Image-only row recognition
            $hasSku = !empty($row['sku']);
            $hasPrice = isset($row['price']) && $row['price'] !== '';
            $hasOptionVal = !empty($row['option1_value']) || !empty($row['option2_value']) || !empty($row['option3_value']);

            if (!$hasSku && !$hasPrice && !$hasOptionVal) {
                if ($imgUrl !== '' || !empty($currentGroup['variants'])) {
                    continue;
                }
            }

            // Options discovery
            for ($optIdx = 1; $optIdx <= 3; $optIdx++) {
                $optNameKey = "option{$optIdx}_name";
                $optValKey  = "option{$optIdx}_value";

                if (!empty($row[$optNameKey])) {
                    $currentGroup['option_names'][$optIdx] = trim($row[$optNameKey]);
                }
                $optName = $currentGroup['option_names'][$optIdx] ?? null;

                if ($optName !== null && $optName !== '') {
                    if (!isset($currentGroup['options'][$optName])) {
                        $currentGroup['options'][$optName] = [];
                    }
                    if (!empty($row[$optValKey])) {
                        $val = trim($row[$optValKey]);
                        if (!in_array($val, $currentGroup['options'][$optName], true)) {
                            $currentGroup['options'][$optName][] = $val;
                        }
                    }
                }
            }

            // Pricing & weights
            $priceData = $this->shopifyImporter->calculatePrices($row['price'] ?? '', $row['compare_at_price'] ?? '');
            $weightKg  = $this->shopifyImporter->calculateWeight($row['grams'] ?? '', $row['weight_unit'] ?? '');
            $varImgUrl = trim($row['variant_image_url'] ?? '');

            $attributes = [];
            for ($optIdx = 1; $optIdx <= 3; $optIdx++) {
                $optNameKey = "option{$optIdx}_name";
                $optValKey  = "option{$optIdx}_value";
                $optName = !empty($row[$optNameKey]) ? trim($row[$optNameKey]) : ($currentGroup['option_names'][$optIdx] ?? null);
                if ($optName !== null && $optName !== '' && !empty($row[$optValKey])) {
                    $attributes[$optName] = trim($row[$optValKey]);
                }
            }

            $stockQuantity = null;
            $manageStock = 0;
            if (isset($row['inventory_qty']) && $row['inventory_qty'] !== '') {
                $manageStock = 1;
                $stockQuantity = (string)(float)$row['inventory_qty'];
            }

            $currentGroup['variants'][] = [
                'sku'              => $row['sku'] !== '' ? trim($row['sku']) : null,
                'regular_price'    => $priceData['regular_price'],
                'sale_price'       => $priceData['sale_price'],
                'price_warning'    => $priceData['warning'],
                'weight'           => $weightKg,
                'manage_stock'     => $manageStock,
                'stock_quantity'   => $stockQuantity,
                'stock_status'     => ($manageStock === 1 && (float)$stockQuantity <= 0) ? 'outofstock' : 'instock',
                'attributes'       => $attributes,
                'image_url'        => $varImgUrl !== '' ? $varImgUrl : null,
                'row_number'       => $currentRow,
            ];
        }

        fclose($handle);

        // Process collected product aggregates
        $batchProductCount = 0;
        $batchVariantCount = 0;

        foreach ($productGroups as $group) {
            $handleKey = $group['handle'];
            $timeStr = gmdate('H:i:s');

            try {
                $importRes = $this->importSingleProductGroup($group, $config['update_mode'] ?? 'merge');
                $batchProductCount++;
                $batchVariantCount += $importRes['variant_count'];

                $msg = sprintf(
                    "[%s] [SUCCESS] Imported product #%d \"%s\" (Handle: %s, Type: %s, Variants: %d, Images: %d)",
                    $timeStr,
                    $importRes['product_id'],
                    $importRes['title'],
                    $handleKey,
                    $importRes['type'],
                    $importRes['variant_count'],
                    count($group['images'])
                );
                $batchLogs[] = ['level' => 'success', 'message' => $msg];
            } catch (\Throwable $e) {
                $errorsCount++;
                $msg = sprintf("[%s] [ERROR] Failed to import handle \"%s\": %s", $timeStr, $handleKey, $e->getMessage());
                $batchLogs[] = ['level' => 'error', 'message' => $msg];
            }
        }

        // Append batch logs to persistent log file
        if (!empty($batchLogs) && !empty($logFile)) {
            $logLines = array_map(fn($l) => $l['message'] . "\n", $batchLogs);
            @file_put_contents($logFile, implode('', $logLines), FILE_APPEND);
        }

        $productsProcessed += $batchProductCount;
        $variantsProcessed += $batchVariantCount;

        // Update checkpoint & counts in database
        $checkpoint['byte_offset']        = $byteOffset;
        $checkpoint['current_row']        = $currentRow;
        $checkpoint['products_processed'] = $productsProcessed;
        $checkpoint['variants_processed'] = $variantsProcessed;
        $checkpoint['errors_count']       = $errorsCount;

        $counts['bytes_processed']    = $byteOffset;
        $counts['products_processed'] = $productsProcessed;
        $counts['variants_processed'] = $variantsProcessed;
        $counts['errors']             = $errorsCount;

        $newStatus = $eof ? 'completed' : 'in_progress';
        $now = gmdate('Y-m-d H:i:s.u');

        // If completed, rebuild catalog index generation automatically
        $rebuildReport = null;
        if ($eof) {
            $endTimeStr = gmdate('H:i:s');
            $completionMsg = sprintf(
                "\n[%s] [INFO] === Import Complete ===\n" .
                "[%s] [INFO] Total Products Imported: %d\n" .
                "[%s] [INFO] Total Variations Created: %d\n" .
                "[%s] [INFO] Total Errors: %d\n" .
                "[%s] [INFO] Rebuilding Catalog Index Generation...\n",
                $endTimeStr,
                $endTimeStr, $productsProcessed,
                $endTimeStr, $variantsProcessed,
                $endTimeStr, $errorsCount,
                $endTimeStr
            );
            $batchLogs[] = ['level' => 'info', 'message' => $completionMsg];
            @file_put_contents($logFile, $completionMsg, FILE_APPEND);

            try {
                $projector = new CatalogProjector($this->wpdb);
                $gen = $projector->buildNewGeneration();
                $genMsg = sprintf("[%s] [SUCCESS] Catalog Index Rebuilt successfully! Generation: %d\n", gmdate('H:i:s'), $gen);
                $batchLogs[] = ['level' => 'success', 'message' => $genMsg];
                @file_put_contents($logFile, $genMsg, FILE_APPEND);
                $rebuildReport = ['generation' => $gen];
            } catch (\Throwable $pE) {
                $warnMsg = sprintf("[%s] [WARNING] Catalog rebuild failed: %s\n", gmdate('H:i:s'), $pE->getMessage());
                $batchLogs[] = ['level' => 'warning', 'message' => $warnMsg];
                @file_put_contents($logFile, $warnMsg, FILE_APPEND);
            }
        }

        $this->wpdb->update(
            $this->tableJobs,
            [
                'status'     => $newStatus,
                'checkpoint' => wp_json_encode($checkpoint),
                'counts'     => wp_json_encode($counts),
                'updated_at' => $now,
            ],
            ['id' => $jobId]
        );

        $percent = $fileSize > 0 ? min(100.0, round(($byteOffset / $fileSize) * 100, 1)) : ($eof ? 100.0 : 0.0);

        return [
            'status'             => $newStatus,
            'completed'          => $eof,
            'percent'            => $percent,
            'job_id'             => $jobId,
            'batch_products'     => $batchProductCount,
            'batch_variants'     => $batchVariantCount,
            'products_processed' => $productsProcessed,
            'variants_processed' => $variantsProcessed,
            'errors_count'       => $errorsCount,
            'byte_offset'        => $byteOffset,
            'file_size'          => $fileSize,
            'logs'               => $batchLogs,
            'rebuild_report'     => $rebuildReport,
        ];
    }

    /**
     * Pause an active import job.
     */
    public function pauseJob(string $jobId): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->tableJobs,
            ['status' => 'paused', 'updated_at' => $now],
            ['id' => $jobId]
        );

        $job = $this->getJob($jobId);
        $config = json_decode((string)($job['config'] ?? '{}'), true);
        if (!empty($config['log_file'])) {
            @file_put_contents($config['log_file'], sprintf("[%s] [INFO] === Import Paused by Operator ===\n", gmdate('Y-m-d H:i:s')), FILE_APPEND);
        }

        return ['status' => 'paused', 'job_id' => $jobId];
    }

    /**
     * Resume a paused import job.
     */
    public function resumeJob(string $jobId): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->tableJobs,
            ['status' => 'in_progress', 'updated_at' => $now],
            ['id' => $jobId]
        );

        $job = $this->getJob($jobId);
        $config = json_decode((string)($job['config'] ?? '{}'), true);
        if (!empty($config['log_file'])) {
            @file_put_contents($config['log_file'], sprintf("[%s] [INFO] === Import Resumed by Operator ===\n", gmdate('Y-m-d H:i:s')), FILE_APPEND);
        }

        return ['status' => 'in_progress', 'job_id' => $jobId];
    }

    /**
     * Cancel an import job.
     */
    public function cancelJob(string $jobId): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->tableJobs,
            ['status' => 'cancelled', 'cancel_requested' => 1, 'updated_at' => $now],
            ['id' => $jobId]
        );

        $job = $this->getJob($jobId);
        $config = json_decode((string)($job['config'] ?? '{}'), true);
        if (!empty($config['log_file'])) {
            @file_put_contents($config['log_file'], sprintf("[%s] [WARNING] === Import Cancelled by Operator ===\n", gmdate('Y-m-d H:i:s')), FILE_APPEND);
        }

        return ['status' => 'cancelled', 'job_id' => $jobId];
    }

    /**
     * Get active or recently paused job to restore UI state.
     */
    public function getActiveJob(): ?array
    {
        $row = $this->wpdb->get_row(
            "SELECT * FROM {$this->tableJobs} WHERE kind = 'shopify_import' AND status IN ('pending', 'in_progress', 'paused') ORDER BY created_at DESC LIMIT 1",
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $config = json_decode((string)$row['config'], true) ?? [];
        $checkpoint = json_decode((string)$row['checkpoint'], true) ?? [];
        $counts = json_decode((string)$row['counts'], true) ?? [];

        $fileSize = (int)($config['file_size'] ?? 0);
        $byteOffset = (int)($checkpoint['byte_offset'] ?? 0);
        $percent = $fileSize > 0 ? min(100.0, round(($byteOffset / $fileSize) * 100, 1)) : 0.0;

        return [
            'job_id'             => $row['id'],
            'status'             => $row['status'],
            'original_filename'  => $config['original_filename'] ?? basename($config['file_path'] ?? ''),
            'file_size'          => $fileSize,
            'file_size_fmt'      => size_format($fileSize),
            'percent'            => $percent,
            'products_processed' => (int)($checkpoint['products_processed'] ?? 0),
            'variants_processed' => (int)($checkpoint['variants_processed'] ?? 0),
            'errors_count'       => (int)($checkpoint['errors_count'] ?? 0),
            'update_mode'        => $config['update_mode'] ?? 'merge',
            'created_at'         => $row['created_at'],
        ];
    }

    /**
     * Get log file path or content for download.
     */
    public function getLogFile(string $jobId): ?string
    {
        $job = $this->getJob($jobId);
        if (!$job) {
            return null;
        }

        $config = json_decode((string)$job['config'], true) ?? [];
        $logFile = $config['log_file'] ?? ($this->logBaseDir . "/import_{$jobId}.log");

        if (file_exists($logFile)) {
            return $logFile;
        }

        return null;
    }

    /**
     * Import a single product aggregate atomically.
     */
    private function importSingleProductGroup(array $group, string $updateMode): array
    {
        $handle = $group['handle'];

        // Determine Product Type: Simple vs Variable
        $isDefaultTitle = false;
        if (count($group['variants']) === 1) {
            $attrs = $group['variants'][0]['attributes'];
            if (count($attrs) === 1 && isset($attrs['Title']) && $attrs['Title'] === 'Default Title') {
                $isDefaultTitle = true;
            }
        }

        $isVariable = (count($group['variants']) > 1) || (!empty($group['options']) && !$isDefaultTitle);
        $productType = $isVariable ? 'variable' : 'simple';

        // Resolve existing product by Handle or SKU
        $existingProductId = $this->resolveProductByHandleOrSku($handle, $group['variants']);

        $title = $group['title'] !== '' ? $group['title'] : ucfirst(str_replace('-', ' ', $handle));
        $pPayload = [
            'title'  => $title,
            'slug'   => $handle,
            'type'   => $productType,
            'status' => $group['status'],
        ];

        if (in_array('description', $group['raw_headers'], true)) {
            $pPayload['description'] = $group['description'];
        }

        if (!$isVariable && !empty($group['variants'])) {
            $v0 = $group['variants'][0];
            $pPayload['sku']            = $v0['sku'];
            $pPayload['regular_price']  = $v0['regular_price'];
            $pPayload['sale_price']     = $v0['sale_price'];
            $pPayload['manage_stock']   = $v0['manage_stock'];
            $pPayload['stock_quantity'] = $v0['stock_quantity'];
            $pPayload['stock_status']   = $v0['stock_status'];
        }

        // Create or update parent product
        if ($existingProductId !== null) {
            $this->productRepo->update($existingProductId, $pPayload);
            $productId = $existingProductId;
        } else {
            $created = $this->productRepo->create($pPayload);
            $productId = (int)$created['id'];
        }

        // Attach images
        if (!empty($group['images'])) {
            $this->attachProductImages($productId, $group['images']);
        }

        $variantsCreated = 0;

        // If variable, sync options & variations
        if ($isVariable) {
            $this->syncShopifyOptions($productId, $group['options']);

            foreach ($group['variants'] as $vData) {
                $vSku = $vData['sku'];
                $vId = $vSku ? $this->resolveVariantIdBySku($vSku) : null;

                $vPayload = [
                    'sku'            => $vSku,
                    'regular_price'  => $vData['regular_price'],
                    'sale_price'     => $vData['sale_price'],
                    'manage_stock'   => $vData['manage_stock'],
                    'stock_quantity' => $vData['stock_quantity'],
                    'stock_status'   => $vData['stock_status'],
                    'attributes'     => $vData['attributes'],
                ];

                if ($vId !== null) {
                    $vPayload['id'] = $vId;
                }

                $this->productRepo->update($productId, ['variations' => [$vPayload]]);

                $savedVariant = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND sku = %s",
                    $productId,
                    $vSku
                ), ARRAY_A);

                if ($savedVariant) {
                    $savedVarId = (int)$savedVariant['id'];
                    $variantsCreated++;

                    if (!empty($vData['attributes'])) {
                        $this->assignVariantCombinationVector($productId, $savedVarId, $vData['attributes']);
                    }

                    if (!empty($vData['image_url'])) {
                        $this->attachVariantImage($savedVarId, $vData['image_url']);
                    }
                }
            }
        }

        // Mirror canonical state to legacy WooCommerce tables
        try {
            $state = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT native_revision, native_applied, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
                $productId
            ), ARRAY_A);
            $rev = $state ? max((int)$state['native_revision'], (int)$state['native_applied'] + 1) : 1;
            // Carry the authority epoch the mirror reads: the object state epoch when a row exists, otherwise the
            // global authority epoch (the value its epoch fence compares against), never a literal or another counter.
            $workerEpoch = \WPE\Application\Migration\LegacyMirror::getInstance()->getAggregateEpoch($productId);
            LegacyMirror::getInstance()->mirrorAggregate($productId, $rev, $workerEpoch);
        } catch (\Throwable $mE) {
            // Non-fatal legacy mirror catch
        }

        return [
            'product_id'    => $productId,
            'title'         => $title,
            'type'          => $productType,
            'variant_count' => $variantsCreated,
        ];
    }

    private function mapStatus(string $rawStatus): string
    {
        $s = strtolower(trim($rawStatus));
        if ($s === 'active' || $s === 'true' || $s === '1' || $s === 'publish') {
            return 'publish';
        }
        if ($s === 'draft' || $s === 'false' || $s === '0' || $s === 'archived') {
            return 'draft';
        }
        return 'publish';
    }

    private function resolveProductByHandleOrSku(string $handle, array $variants): ?int
    {
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id FROM {$this->tableProducts} WHERE slug = %s AND deleted_at IS NULL",
            $handle
        ), ARRAY_A);

        if ($existing) {
            return (int)$existing['id'];
        }

        foreach ($variants as $v) {
            if (!empty($v['sku'])) {
                $p = $this->productRepo->getBySku($v['sku']);
                if ($p) {
                    return (int)$p['id'];
                }
            }
        }

        return null;
    }

    private function resolveVariantIdBySku(string $sku): ?int
    {
        $v = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE sku = %s AND deleted_at IS NULL",
            $sku
        ), ARRAY_A);
        return $v ? (int)$v['id'] : null;
    }

    private function attachProductImages(int $productId, array $images): void
    {
        $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId]);

        $pos = 0;
        foreach ($images as $img) {
            $url = esc_url_raw(trim($img['url']));
            if ($url === '') {
                continue;
            }

            $asset = $this->assetService->registerAsset($url);
            $role = ($pos === 0) ? 'featured' : 'gallery';
            $altOverride = !empty($img['alt']) ? trim($img['alt']) : null;

            $this->assetService->attachProductImage($productId, (int)$asset['id'], $role, $pos, $altOverride);
            $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
            update_post_meta($attachmentId, '_wc_attachment_source', $url);
            $pos++;
        }
    }

    private function attachVariantImage(int $variantId, string $url): void
    {
        $clean = esc_url_raw(trim($url));
        if ($clean === '') {
            return;
        }

        $asset = $this->assetService->registerAsset($clean);
        $this->assetService->attachVariantImage($variantId, (int)$asset['id'], 0, true);
        $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
        update_post_meta($attachmentId, '_wc_attachment_source', $clean);
    }

    private function syncShopifyOptions(int $productId, array $options): void
    {
        $existingOpts = $this->optionsRepo->getOptionsByProductId($productId);
        $existingByName = [];
        foreach ($existingOpts as $eo) {
            $existingByName[strtolower($eo['name'])] = $eo;
        }

        $pos = 0;
        foreach ($options as $optName => $values) {
            $lower = strtolower($optName);
            if (isset($existingByName[$lower])) {
                $optId = (int)$existingByName[$lower]['id'];
            } else {
                $created = $this->optionsRepo->createOption($productId, [
                    'name'     => $optName,
                    'key'      => sanitize_title($optName),
                    'position' => $pos,
                ]);
                $optId = (int)$created['id'];
            }

            $existingVals = $this->optionsRepo->getOptionValues($optId);
            $valLabels = array_map('strtolower', array_column($existingVals, 'label'));

            $vPos = count($existingVals);
            foreach ($values as $valLabel) {
                if (!in_array(strtolower($valLabel), $valLabels, true)) {
                    $this->optionsRepo->createOptionValue($optId, [
                        'label'     => $valLabel,
                        'value_key' => sanitize_title($valLabel),
                        'position'  => $vPos++,
                    ]);
                }
            }
            $pos++;
        }
    }

    private function assignVariantCombinationVector(int $productId, int $variantId, array $attributes): void
    {
        $options = $this->optionsRepo->getOptionsByProductId($productId);
        $selections = [];

        foreach ($options as $opt) {
            $optName = $opt['name'];
            $optId = (int)$opt['id'];

            if (isset($attributes[$optName])) {
                $targetLabel = strtolower(trim($attributes[$optName]));
                foreach ($opt['values'] as $ov) {
                    if (strtolower($ov['label']) === $targetLabel || strtolower($ov['value_key']) === $targetLabel) {
                        $selections[$optId] = (int)$ov['id'];
                        break;
                    }
                }
            }
        }

        if (!empty($selections)) {
            $this->optionsRepo->assignVariantCombination($productId, $variantId, $selections);
        }
    }

    private function getJob(string $jobId): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);
    }
}
