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
use WPE\Support\Singleton;

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
    use Singleton;


    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;
    private ShopifyImporter $shopifyImporter;

    /**
     * Image URL => [asset_id, attachment_id] for the product being imported.
     * Reset per product (P015 D03 as narrowed by P016 D02).
     *
     * @var array<string, array{asset_id: int, attachment_id: int}>
     */
    private array $productAssetCache = [];

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

    /**
     * Inspect a CSV file on disk to confirm it is readable, non-empty, and has valid Shopify headers.
     * Uses O(1) memory by reading only the header row and confirming at least one data row exists.
     *
     * @param string $filePath Absolute path to CSV file.
     * @return array{file_path: string, filename: string, file_size: int, file_size_fmt: string, has_bom: bool, header_count: int}
     */
    public function inspectCsvFile(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('Server file not found or not readable.');
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new InvalidArgumentException('CSV file is empty.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open CSV file for reading.');
        }

        try {
            $bom = fread($handle, 3);
            $hasBom = ($bom === "\xEF\xBB\xBF");
            if (!$hasBom) {
                rewind($handle);
            }

            $rawHeaders = fgetcsv($handle);
            if ($rawHeaders === false || empty($rawHeaders)) {
                throw new InvalidArgumentException('CSV file contains no header row.');
            }

            $normalized = [];
            foreach ($rawHeaders as $idx => $rawH) {
                $cleaned = trim((string)$rawH);
                if ($idx === 0 && str_starts_with($cleaned, "\xEF\xBB\xBF")) {
                    $cleaned = substr($cleaned, 3);
                }
                if ($cleaned !== '') {
                    $normalized[] = $this->shopifyImporter->normalizeHeader($cleaned);
                }
            }

            if ($normalized === []) {
                throw new InvalidArgumentException('CSV file contains no header columns.');
            }

            if (!in_array('handle', $normalized, true) && !in_array('title', $normalized, true)) {
                throw new InvalidArgumentException('CSV header does not match Shopify format (missing Handle or Title column).');
            }

            $hasDataRow = false;
            while (($row = fgetcsv($handle)) !== false) {
                if (count(array_filter($row, static fn($cell): bool => trim((string)$cell) !== '')) > 0) {
                    $hasDataRow = true;
                    break;
                }
            }

            if (!$hasDataRow) {
                throw new InvalidArgumentException('CSV file contains headers only and no product rows.');
            }
        } finally {
            fclose($handle);
        }

        return [
            'file_path'     => $filePath,
            'filename'      => basename($filePath),
            'file_size'     => $fileSize,
            'file_size_fmt' => size_format($fileSize),
            'has_bom'       => $hasBom,
            'header_count'  => count($rawHeaders),
        ];
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
            throw new InvalidArgumentException("File not found or unreadable: " . basename($filePath));
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
        $ignoredColumns = [];
        $categoryMode = self::normalizeCategoryMode($options['category_mode'] ?? 'none');
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
            // Present but not mapped to WooCommerce data (docs/10 §7): reported, not dropped silently.
            if (in_array($normKey, self::IGNORED_COLUMN_KEYS, true) && !($normKey === 'category' && $categoryMode === 'path')) {
                $ignoredColumns[] = $cleaned;
            }
        }

        $dataStartOffset = ftell($handle);
        fclose($handle);

        $jobId = wp_generate_uuid4();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

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
        if ($categoryMode === 'path') {
            $initialLog .= sprintf("[%s] [INFO] Product category: mapped to a WooCommerce category tree (leaf assigned)\n\n", gmdate('Y-m-d H:i:s'));
        }
        $ignoredColumns = array_values(array_unique($ignoredColumns));
        if ($ignoredColumns !== []) {
            $initialLog .= sprintf(
                "[%s] [WARNING] Columns not imported (no WooCommerce mapping): %s. Products without a category get the default category.\n\n",
                gmdate('Y-m-d H:i:s'),
                implode(', ', $ignoredColumns)
            );
        }
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
            'ignored_cols'       => $ignoredColumns,
            'category_mode'      => $categoryMode,
            'update_mode'        => $options['update_mode'] ?? 'merge',
            'image_mode'         => $options['image_mode'] ?? 'external',
            'batch_size'         => (int)($options['batch_size'] ?? 25),
            'skip_unchanged'     => (bool)($options['skip_unchanged'] ?? true),
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

        $inserted = $this->wpdb->insert($this->tableJobs, [
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

        if ($inserted === false) {
            throw new RuntimeException(
                'Failed to create import job: ' . ($this->wpdb->last_error ?: 'unknown database error'),
                500
            );
        }

        return [
            'job_id'            => $jobId,
            'status'            => 'pending',
            'file_size'         => $fileSize,
            'file_size_fmt'     => size_format($fileSize),
            'headers_count'     => count($headers),
            'unsupported_cols'  => $config['unsupported_cols'],
            'ignored_cols'      => $config['ignored_cols'],
            'category_mode'     => $config['category_mode'],
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

        // One batch per job at a time. A browser retry after a gateway timeout
        // must not re-run a batch that is still executing on another worker.
        $lockName = 'wpe_import_' . $jobId;
        if ((int)$this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lockName)) !== 1) {
            $busyCounts = json_decode((string)$job['counts'], true) ?? [];
            $busySize   = (int)($busyCounts['file_size'] ?? 0);
            $busyOffset = (int)($busyCounts['bytes_processed'] ?? 0);
            return [
                'status'             => 'in_progress',
                'busy'               => true,
                'completed'          => false,
                'percent'            => $busySize > 0 ? min(100.0, round(($busyOffset / $busySize) * 100, 1)) : 0.0,
                'job_id'             => $jobId,
                'products_processed' => (int)($busyCounts['products_processed'] ?? 0),
                'variants_processed' => (int)($busyCounts['variants_processed'] ?? 0),
                'errors_count'       => (int)($busyCounts['errors'] ?? 0),
                'logs'               => [[
                    'level'   => 'info',
                    'message' => '[SYSTEM] Previous batch is still running on the server, waiting...',
                ]],
            ];
        }

        try {
            return $this->runLockedBatch($jobId, $job, $batchSize);
        } finally {
            $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /**
     * Max seconds of product writes per request; keeps each batch well under
     * the web server's gateway timeout regardless of variants per product.
     */
    private const BATCH_TIME_BUDGET_SECONDS = 25;

    private function runLockedBatch(string $jobId, array $job, int $batchSize): array
    {
        $batchStartedAt = microtime(true);

        // Re-read under the lock: another worker may have advanced the checkpoint.
        $job = $this->getJob($jobId) ?? $job;

        $config     = json_decode((string)$job['config'], true) ?? [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?? [];
        $counts     = json_decode((string)$job['counts'], true) ?? [];

        $filePath   = $config['file_path'] ?? '';
        $fileSize   = (int)($config['file_size'] ?? 0);
        $headers    = $config['headers'] ?? [];
        $logFile    = $config['log_file'] ?? '';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("CSV source file not accessible: " . basename($filePath), 404);
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
                    'start_offset'        => $lineStartOffset,
                    'start_row'           => $currentRow - 1,
                    'handle'              => $rowHandle,
                    'title'               => $row['title'] ?? '',
                    'description'         => $row['description'] ?? '',
                    'vendor'              => $row['vendor'] ?? '',
                    'product_type'        => $row['product_type'] ?? '',
                    'tags'                => $row['tags'] ?? '',
                    'category'            => $row['category'] ?? '',
                    'category_mode'       => self::normalizeCategoryMode($config['category_mode'] ?? 'none'),
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
                if (($currentGroup['category'] ?? '') === '' && !empty($row['category'])) {
                    $currentGroup['category'] = $row['category'];
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
            $hasSku = ($row['sku'] ?? '') !== '';
            $hasPrice = isset($row['price']) && $row['price'] !== '';
            $hasOptionVal = ($row['option1_value'] ?? '') !== '' || ($row['option2_value'] ?? '') !== '' || ($row['option3_value'] ?? '') !== '';

            if (!$hasSku && !$hasPrice && !$hasOptionVal) {
                if ($imgUrl !== '' || !empty($currentGroup['variants'])) {
                    continue;
                }
            }

            // Options discovery
            for ($optIdx = 1; $optIdx <= 3; $optIdx++) {
                $optNameKey = "option{$optIdx}_name";
                $optValKey  = "option{$optIdx}_value";

                if (($row[$optNameKey] ?? '') !== '') {
                    $currentGroup['option_names'][$optIdx] = trim($row[$optNameKey]);
                }
                $optName = $currentGroup['option_names'][$optIdx] ?? null;

                if ($optName !== null && $optName !== '') {
                    if (!isset($currentGroup['options'][$optName])) {
                        $currentGroup['options'][$optName] = [];
                    }
                    if (($row[$optValKey] ?? '') !== '') {
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
                $optName = ($row[$optNameKey] ?? '') !== '' ? trim($row[$optNameKey]) : ($currentGroup['option_names'][$optIdx] ?? null);
                if ($optName !== null && $optName !== '' && ($row[$optValKey] ?? '') !== '') {
                    $attributes[$optName] = trim($row[$optValKey]);
                }
            }

            $stockQuantity = null;
            $manageStock = 0;
            if (isset($row['inventory_qty']) && $row['inventory_qty'] !== '') {
                $manageStock = 1;
                $stockQuantity = (string)(float)$row['inventory_qty'];
            }
            $inventoryPolicy = strtolower(trim($row['inventory_policy'] ?? ''));
            $backorders = ($inventoryPolicy === 'continue') ? 'yes' : 'no';
            $stockStatus = ($manageStock === 1 && (float)$stockQuantity <= 0 && $backorders === 'no') ? 'outofstock' : 'instock';

            $currentGroup['variants'][] = [
                // A file without a SKU column imports like empty SKU cells (P019 D04).
                'sku'              => ($row['sku'] ?? '') !== '' ? trim($row['sku']) : null,
                'regular_price'    => $priceData['regular_price'],
                'sale_price'       => $priceData['sale_price'],
                'price_warning'    => $priceData['warning'],
                'weight'           => $weightKg,
                'manage_stock'     => $manageStock,
                'stock_quantity'   => $stockQuantity,
                'stock_status'     => $stockStatus,
                'backorders'       => $backorders,
                'attributes'       => $attributes,
                'image_url'        => $varImgUrl !== '' ? $varImgUrl : null,
                'row_number'       => $currentRow,
            ];
        }

        fclose($handle);

        // Process collected product aggregates
        $batchProductCount = 0;
        $batchVariantCount = 0;
        $groupsAttempted   = 0;
        $batchSkippedCount = 0;
        // Jobs created before P017 have no flag; they keep importing every product.
        $skipUnchanged     = !empty($config['skip_unchanged']);

        foreach ($productGroups as $group) {
            // Time budget: stop at a product boundary and resume from this
            // group's first line next request. Always import at least one.
            if ($groupsAttempted > 0
                && (microtime(true) - $batchStartedAt) >= self::BATCH_TIME_BUDGET_SECONDS
            ) {
                $byteOffset = (int)$group['start_offset'];
                $currentRow = (int)$group['start_row'];
                $eof = false;
                break;
            }
            $groupsAttempted++;

            $handleKey = $group['handle'];
            $timeStr = gmdate('H:i:s');

            if (count($group['images']) > 1) {
                usort($group['images'], static fn(array $a, array $b): int => ($a['position'] <=> $b['position']));
            }

            $fingerprint = $this->productFingerprint($group, $config);

            if ($skipUnchanged) {
                try {
                    $unchanged = $this->findUnchangedProduct($group, $fingerprint);
                } catch (\Throwable $e) {
                    $unchanged = null;
                }
                if ($unchanged !== null) {
                    $batchProductCount++;
                    $batchSkippedCount++;
                    $batchVariantCount += $unchanged['variant_count'];
                    $batchLogs[] = ['level' => 'info', 'message' => sprintf(
                        '[%s] [SKIP] Unchanged product #%d "%s" (Handle: %s, Variants: %d)',
                        $timeStr,
                        $unchanged['product_id'],
                        $unchanged['title'],
                        $handleKey,
                        $unchanged['variant_count']
                    )];
                    continue;
                }
            }

            try {
                $importRes = $this->importSingleProductGroup($group, $config['update_mode'] ?? 'merge');
                $batchProductCount++;
                $batchVariantCount += $importRes['variant_count'];
                $this->recordImportFingerprint($importRes, $fingerprint);
                $this->projectIntoActiveCatalog((int)$importRes['product_id'], $handleKey, $timeStr, $batchLogs);

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

                if (!empty($importRes['warnings'])) {
                    foreach ($importRes['warnings'] as $warnText) {
                        $wMsg = sprintf("[%s] [WARNING] Handle \"%s\": %s", $timeStr, $handleKey, $warnText);
                        $batchLogs[] = ['level' => 'warning', 'message' => $wMsg];
                    }
                }
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
        $productsSkipped    = (int)($counts['products_skipped'] ?? 0) + $batchSkippedCount;

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
        $counts['products_skipped']   = $productsSkipped;

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
                "[%s] [INFO] Unchanged Products Skipped: %d\n",
                $endTimeStr,
                $endTimeStr, $productsProcessed,
                $endTimeStr, $variantsProcessed,
                $endTimeStr, $errorsCount,
                $endTimeStr, $productsSkipped
            );
            $batchLogs[] = ['level' => 'info', 'message' => $completionMsg];
            @file_put_contents($logFile, $completionMsg, FILE_APPEND);

            try {
                $projector = $this->catalogProjector();
                $activeGen = $projector->getActiveGeneration();
                if ($activeGen > 0) {
                    // Written products were projected as they were imported (P019 D01).
                    $genMsg = sprintf("[%s] [SUCCESS] Catalog index updated per product in generation %d\n", gmdate('H:i:s'), $activeGen);
                    $rebuildReport = ['generation' => $activeGen, 'mode' => 'incremental'];
                } else {
                    // No catalog yet: build the first generation (P019 D02).
                    $gen = $projector->buildNewGeneration();
                    $genMsg = sprintf("[%s] [SUCCESS] Catalog Index Rebuilt successfully! Generation: %d\n", gmdate('H:i:s'), $gen);
                    $rebuildReport = ['generation' => $gen, 'mode' => 'full'];
                }
                $batchLogs[] = ['level' => 'success', 'message' => $genMsg];
                @file_put_contents($logFile, $genMsg, FILE_APPEND);
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
            'batch_skipped'      => $batchSkippedCount,
            'products_skipped'   => $productsSkipped,
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
        $this->productAssetCache = [];
        // Options are written only through optionsRepo while a product is imported,
        // so its per-product read cache stays consistent (P015 D06).
        $this->optionsRepo->enableOptionsCache();
        try {
            return $this->importProductGroup($group, $updateMode);
        } finally {
            $this->optionsRepo->disableOptionsCache();
        }
    }

    private function importProductGroup(array $group, string $updateMode): array
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
            $pPayload['weight']         = $v0['weight'] ?? null;
            $pPayload['manage_stock']   = $v0['manage_stock'];
            $pPayload['stock_quantity'] = $v0['stock_quantity'];
            $pPayload['stock_status']   = $v0['stock_status'];
            $pPayload['backorders']     = $v0['backorders'] ?? 'no';
        }

        // Create or update parent product
        if ($existingProductId !== null) {
            $this->productRepo->update($existingProductId, $pPayload, ['hydrate' => false]);
            $productId = $existingProductId;
        } else {
            $created = $this->productRepo->create($pPayload);
            $productId = (int)$created['id'];
        }

        $warnings = [];

        // Attach images
        if (!empty($group['images'])) {
            $this->attachProductImages($productId, $group['images'], $warnings);
        }

        $variantsCreated = 0;

        // If variable, sync options, then write every variation in one repository
        // transaction (P015 D04): one version bump, one outbox event, no re-hydration.
        if ($isVariable) {
            $this->syncShopifyOptions($productId, $group['options']);

            $existingByKey = $this->loadActiveVariantKeys($productId);
            $skuIds = $this->resolveVariantIdsBySku(array_column($group['variants'], 'sku'));

            // Rows that address the same variant (same SKU, else same attributes)
            // collapse into one payload; the later row wins, as when each row was
            // written separately.
            $payloads = [];
            $rowsByKey = [];
            foreach ($group['variants'] as $vIdx => $vData) {
                if (!empty($vData['price_warning'])) {
                    $warnings[] = "Row {$vData['row_number']}: {$vData['price_warning']}";
                }

                $vSku = $vData['sku'];
                $hasSku = $vSku !== null && $vSku !== '';
                $vId = $hasSku ? ($skuIds[$vSku] ?? null) : null;
                if ($vId === null && !empty($vData['attributes'])) {
                    $vId = $this->matchVariantByAttributes($productId, $vData['attributes'], $existingByKey);
                }

                $vPayload = [
                    'sku'            => $vSku,
                    'regular_price'  => $vData['regular_price'],
                    'sale_price'     => $vData['sale_price'],
                    'weight'         => $vData['weight'] ?? null,
                    'manage_stock'   => $vData['manage_stock'],
                    'stock_quantity' => $vData['stock_quantity'],
                    'stock_status'   => $vData['stock_status'],
                    'backorders'     => $vData['backorders'] ?? 'no',
                    'position'       => $vIdx,
                    'attributes'     => $vData['attributes'],
                ];
                if ($vId !== null) {
                    $vPayload['id'] = $vId;
                }

                $dedupeKey = $hasSku ? 'sku:' . $vSku : 'attr:' . $this->attributeSignature($vData['attributes']);
                unset($payloads[$dedupeKey], $rowsByKey[$dedupeKey]);
                $payloads[$dedupeKey] = $vPayload;
                $rowsByKey[$dedupeKey] = $vData;
            }

            $saved = $this->productRepo->update(
                $productId,
                ['variations' => array_values($payloads)],
                ['hydrate' => false]
            );

            $rows = array_values($rowsByKey);
            foreach ($saved['variations'] as $i => $savedVariant) {
                $savedVarId = (int)($savedVariant['id'] ?? 0);
                $vData = $rows[$i] ?? null;
                if ($savedVarId <= 0 || $vData === null) {
                    continue;
                }
                $variantsCreated++;

                if (!empty($vData['attributes'])) {
                    $this->assignVariantCombinationVector($productId, $savedVarId, $vData['attributes']);
                }

                if (!empty($vData['image_url'])) {
                    $this->attachVariantImage($savedVarId, $vData['image_url'], $vData['row_number'] ?? 0, $warnings);
                }
            }
        }

        // Mirror canonical state to legacy WooCommerce tables
        $mirrored = false;
        try {
            $state = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT native_revision, native_applied, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
                $productId
            ), ARRAY_A);
            $rev = $state ? max((int)$state['native_revision'], (int)$state['native_applied'] + 1) : 1;
            // Carry the authority epoch the mirror reads: the object state epoch when a row exists, otherwise the
            // global authority epoch (the value its epoch fence compares against), never a literal or another counter.
            $workerEpoch = \WPE\Application\Migration\LegacyMirror::getInstance()->getAggregateEpoch($productId);
            $mirrorRes = LegacyMirror::getInstance()->mirrorAggregate($productId, $rev, $workerEpoch);
            $mirrored = ($mirrorRes['status'] ?? '') === 'mirrored';
        } catch (\Throwable $mE) {
            // Non-fatal legacy mirror catch
        }

        $this->applyProductTaxonomy(
            $productId,
            (string)($group['tags'] ?? ''),
            ($group['category_mode'] ?? 'none') === 'path' ? (string)($group['category'] ?? '') : '',
            $warnings
        );

        return [
            'product_id'    => $productId,
            'title'         => $title,
            'type'          => $productType,
            'variant_count' => $variantsCreated,
            'warnings'      => $warnings,
            'mirrored'      => $mirrored,
        ];
    }

    /** Normalized header keys present in Shopify files that have no WooCommerce mapping (P031 D04). */
    private const IGNORED_COLUMN_KEYS = ['category', 'product_type', 'vendor'];

    /**
     * Tags -> product_tag and default category when the product has none (P031, docs/10 §7).
     *
     * Terms are WordPress-owned and written on the shell before the product is projected into the
     * catalog. A non-empty Tags cell replaces the product's tags; an empty one leaves them. The
     * default category is added only when the product has no product_cat at all.
     */
    private function applyProductTaxonomy(int $productId, string $rawTags, string $categoryPath, array &$warnings): void
    {
        if ($productId <= 0 || get_post_type($productId) !== 'product') {
            return;
        }

        $tags = [];
        foreach (explode(',', $rawTags) as $tag) {
            $tag = trim(html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // Case-insensitive de-dup keeps the first spelling, as WordPress term lookup is case-insensitive.
            if ($tag !== '' && !isset($tags[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = $tag;
            }
        }
        if ($tags !== []) {
            $res = wp_set_object_terms($productId, array_values($tags), 'product_tag');
            if (is_wp_error($res)) {
                $warnings[] = 'Tags not saved: ' . $res->get_error_message();
            }
        }

        if (trim($categoryPath) !== '') {
            try {
                $this->applyCategoryPath($productId, $categoryPath);
            } catch (\Throwable $e) {
                $warnings[] = 'Category path not saved: ' . $e->getMessage();
            }
        }

        $hasCategory = wp_get_object_terms($productId, 'product_cat', ['fields' => 'ids']);
        if (!is_wp_error($hasCategory) && $hasCategory === []) {
            $default = (int)get_option('default_product_cat', 0);
            if ($default > 0 && term_exists($default, 'product_cat')) {
                $res = wp_set_object_terms($productId, [$default], 'product_cat');
                if (is_wp_error($res)) {
                    $warnings[] = 'Default category not saved: ' . $res->get_error_message();
                }
            }
        }
    }

    /** @var array<string, int> Resolved category paths -> leaf term ID (per request). */
    private array $categoryPathCache = [];

    public static function normalizeCategoryMode($mode): string
    {
        return $mode === 'path' ? 'path' : 'none';
    }

    /**
     * File a product under the leaf of a Shopify `A > B > C` category path (P032, docs/10 §7).
     *
     * Segments are matched by name under their parent (WordPress term_exists) or created. The
     * product's categories become the leaf plus every existing category except the default one.
     *
     * @return int|null Leaf term ID, or null when the path is empty.
     */
    public function applyCategoryPath(int $productId, string $rawPath): ?int
    {
        $leaf = $this->ensureCategoryPath($rawPath);
        if ($leaf === null || $productId <= 0) {
            return $leaf;
        }

        $default = (int)get_option('default_product_cat', 0);
        $current = wp_get_object_terms($productId, 'product_cat', ['fields' => 'ids']);
        $current = is_wp_error($current) ? [] : array_map('intval', $current);
        $keep = array_values(array_filter($current, static fn(int $id): bool => $id !== $default && $id !== $leaf));
        $target = array_merge($keep, [$leaf]);

        sort($current);
        $sortedTarget = $target;
        sort($sortedTarget);
        if ($current !== $sortedTarget) {
            $res = wp_set_object_terms($productId, $target, 'product_cat');
            if (is_wp_error($res)) {
                throw new RuntimeException($res->get_error_message());
            }
        }
        return $leaf;
    }

    /**
     * Find or create each segment of `A > B > C` under its parent; return the leaf term ID.
     */
    private function ensureCategoryPath(string $rawPath): ?int
    {
        $decoded = $rawPath;
        // Exports can double-escape (`&amp;amp;`); decode until stable.
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        $segments = array_values(array_filter(
            array_map(static fn(string $seg): string => trim(preg_replace('/\s+/u', ' ', $seg) ?? $seg), explode('>', $decoded)),
            static fn(string $seg): bool => $seg !== ''
        ));
        if ($segments === []) {
            return null;
        }

        $cacheKey = mb_strtolower(implode(' > ', $segments));
        if (isset($this->categoryPathCache[$cacheKey]) && term_exists($this->categoryPathCache[$cacheKey], 'product_cat')) {
            return $this->categoryPathCache[$cacheKey];
        }

        $parent = 0;
        foreach ($segments as $segment) {
            $found = term_exists($segment, 'product_cat', $parent);
            if (is_array($found) && !empty($found['term_id'])) {
                $parent = (int)$found['term_id'];
                continue;
            }
            $created = wp_insert_term($segment, 'product_cat', ['parent' => $parent]);
            if (is_wp_error($created)) {
                // A concurrent request may have created it: use the existing term.
                $existing = $created->get_error_data('term_exists');
                if (!$existing) {
                    throw new RuntimeException($created->get_error_message());
                }
                $parent = (int)$existing;
                continue;
            }
            $parent = (int)$created['term_id'];
        }

        return $this->categoryPathCache[$cacheKey] = $parent;
    }

    private ?CatalogProjector $catalogProjector = null;

    private function catalogProjector(): CatalogProjector
    {
        return $this->catalogProjector ??= new CatalogProjector($this->wpdb);
    }

    /**
     * Upsert one imported product into the active catalog generation, as
     * scheduled price changes do (P019 D01). Without an active generation the
     * job builds one at the end (D02). Failures only add a warning.
     */
    private function projectIntoActiveCatalog(int $productId, string $handle, string $timeStr, array &$batchLogs): void
    {
        if ($productId <= 0) {
            return;
        }
        try {
            $projector = $this->catalogProjector();
            $activeGen = $projector->getActiveGeneration();
            if ($activeGen > 0) {
                $projector->projectProduct($productId, $activeGen);
            }
        } catch (\Throwable $e) {
            $batchLogs[] = ['level' => 'warning', 'message' => sprintf(
                '[%s] [WARNING] Handle "%s": catalog projection failed: %s',
                $timeStr,
                $handle,
                $e->getMessage()
            )];
        }
    }

    /** Bump when the CSV-to-product mapping changes so stored fingerprints never match (P017 D03). */
    /** 2: imports write tags and the default category (P031), so format-1 fingerprints must not skip. */
    private const FINGERPRINT_FORMAT = 2;

    /** wpe_control key prefix for per-product import fingerprints (P017 D05). */
    private const FINGERPRINT_KEY_PREFIX = 'shopify_import_fp:';

    /**
     * Hash of everything the import of one product group depends on: the parsed
     * rows without file positions, the job's modes and the importer version.
     */
    private function productFingerprint(array $group, array $config): string
    {
        unset($group['start_offset'], $group['start_row']);
        foreach ($group['variants'] as $i => $variant) {
            unset($group['variants'][$i]['row_number']);
        }

        return hash('sha256', (string)wp_json_encode([
            'format'      => self::FINGERPRINT_FORMAT,
            'plugin'      => defined('WPE_VERSION') ? (string)WPE_VERSION : '',
            'update_mode' => (string)($config['update_mode'] ?? 'merge'),
            'image_mode'  => (string)($config['image_mode'] ?? 'external'),
            'group'       => $group,
        ]));
    }

    /**
     * Digest of every native row an import writes for this product: the product,
     * its variants (tombstones included), inventory, options and values, variant
     * values, product and variant media, terms and meta. Any later edit, stock
     * change or other import changes it, so the next import of the same file
     * rewrites the product instead of skipping it (P017 D04).
     */
    private function nativeStateDigest(int $productId): string
    {
        $p = $this->wpdb->prefix;
        $variantIds = "SELECT id FROM {$this->tableVariants} WHERE product_id = %d";
        $optionIds  = "SELECT id FROM {$this->tableOptions} WHERE product_id = %d";
        $reads = [
            "SELECT * FROM {$this->tableProducts} WHERE id = %d",
            "SELECT * FROM {$this->tableVariants} WHERE product_id = %d ORDER BY id",
            "SELECT * FROM {$this->tableInventory} WHERE owner_id IN (SELECT %d UNION {$variantIds}) ORDER BY owner_id",
            "SELECT * FROM {$this->tableOptions} WHERE product_id = %d ORDER BY id",
            "SELECT * FROM {$this->tableOptionValues} WHERE option_id IN ({$optionIds}) ORDER BY id",
            "SELECT * FROM {$this->tableVariantValues} WHERE variant_id IN ({$variantIds}) ORDER BY variant_id, option_id",
            "SELECT * FROM {$this->tableProductMedia} WHERE product_id = %d ORDER BY role, position, asset_id",
            "SELECT * FROM {$this->tableVariantMedia} WHERE variant_id IN ({$variantIds}) ORDER BY variant_id, asset_id",
            "SELECT * FROM {$p}wpe_product_terms WHERE product_id = %d ORDER BY term_taxonomy_id",
            "SELECT * FROM {$p}wpe_meta WHERE object_id = %d ORDER BY meta_id",
        ];

        $state = [];
        foreach ($reads as $sql) {
            $args = array_fill(0, substr_count($sql, '%d'), $productId);
            $state[] = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$args), ARRAY_A);
        }

        return hash('sha256', (string)wp_json_encode($state));
    }

    /**
     * Return the stored import of this group when it can be skipped: same
     * fingerprint, native state untouched since, and no pending mirror (P017 D04).
     */
    private function findUnchangedProduct(array $group, string $fingerprint): ?array
    {
        $productId = $this->resolveProductByHandleOrSku($group['handle'], $group['variants']);
        if ($productId === null) {
            return null;
        }

        $stored = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->wpdb->prefix}wpe_control WHERE `key` = %s",
            self::FINGERPRINT_KEY_PREFIX . $productId
        ));
        $record = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($record) || !hash_equals((string)($record['hash'] ?? ''), $fingerprint)) {
            return null;
        }

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT p.deleted_at, s.native_revision, s.native_applied
             FROM {$this->tableProducts} p
             LEFT JOIN {$this->tableObjectState} s ON s.object_id = p.id
             WHERE p.id = %d",
            $productId
        ), ARRAY_A);
        if (!$row || $row['deleted_at'] !== null || $row['native_revision'] === null
            || (int)$row['native_applied'] < (int)$row['native_revision']
        ) {
            return null;
        }

        if (!hash_equals((string)($record['state'] ?? ''), $this->nativeStateDigest($productId))) {
            return null;
        }

        return [
            'product_id'    => $productId,
            'title'         => (string)($record['title'] ?? ''),
            'variant_count' => (int)($record['variant_count'] ?? 0),
        ];
    }

    /**
     * Store the fingerprint after a clean import (written and mirrored, no
     * warnings); otherwise drop any older one so the product is retried (P017 D05).
     */
    private function recordImportFingerprint(array $importRes, string $fingerprint): void
    {
        $productId = (int)($importRes['product_id'] ?? 0);
        if ($productId <= 0) {
            return;
        }
        $key = self::FINGERPRINT_KEY_PREFIX . $productId;

        if (empty($importRes['mirrored']) || !empty($importRes['warnings'])) {
            $this->wpdb->delete("{$this->wpdb->prefix}wpe_control", ['key' => $key]);
            return;
        }

        $this->wpdb->replace("{$this->wpdb->prefix}wpe_control", [
            'key'        => $key,
            'value'      => (string)wp_json_encode([
                'hash'          => $fingerprint,
                'state'         => $this->nativeStateDigest($productId),
                'variant_count' => (int)$importRes['variant_count'],
                'type'          => (string)$importRes['type'],
                'title'         => (string)$importRes['title'],
            ]),
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
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

    private function buildOptionSelections(int $productId, array $attributes): array
    {
        $options = $this->optionsRepo->getOptionsByProductId($productId);
        $selections = [];

        foreach ($options as $opt) {
            $optName = $opt['name'];
            $optId = (int)$opt['id'];

            if (isset($attributes[$optName])) {
                $targetLabel = strtolower(trim((string)$attributes[$optName]));
                foreach ($opt['values'] as $ov) {
                    if (strtolower(trim((string)$ov['label'])) === $targetLabel || strtolower(trim((string)$ov['value_key'])) === $targetLabel) {
                        $selections[$optId] = (int)$ov['id'];
                        break;
                    }
                }
            }
        }

        return $selections;
    }

    /**
     * Active variants of a product keyed by HEX(active_combination_key).
     *
     * @return array<string, int>
     */
    private function loadActiveVariantKeys(int $productId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, HEX(active_combination_key) AS k FROM {$this->tableVariants}
             WHERE product_id = %d AND deleted_at IS NULL AND active_combination_key IS NOT NULL",
            $productId
        ), ARRAY_A);

        $map = [];
        foreach ((array)$rows as $r) {
            $map[strtoupper((string)$r['k'])] = (int)$r['id'];
        }
        return $map;
    }

    /**
     * Active variation ids for SKUs, in two indexed-or-batched queries.
     *
     * Exact SKUs are looked up through wpe_sku_registry (primary key). SKUs not
     * found there fall back to one batched wpe_variants.sku lookup, which keeps
     * the column's case-insensitive matching the importer always had (P015 D07).
     *
     * @param array<int, string|null> $skus
     * @return array<string, int> SKU as given => variant id
     */
    private function resolveVariantIdsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(
            array_map(static fn($s) => $s === null ? '' : (string)$s, $skus),
            static fn(string $s): bool => $s !== ''
        )));
        if (empty($skus)) {
            return [];
        }

        $found = [];
        $in = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT r.normalized_sku AS sku, r.object_id AS id
             FROM {$this->tableSkuRegistry} r
             JOIN {$this->tableVariants} v ON v.id = r.object_id AND v.deleted_at IS NULL
             WHERE r.object_kind = 'variation' AND r.normalized_sku IN ({$in})",
            ...$skus
        ), ARRAY_A);
        foreach ((array)$rows as $r) {
            $found[(string)$r['sku']] = (int)$r['id'];
        }

        $missing = array_values(array_diff($skus, array_keys($found)));
        if (!empty($missing)) {
            $in = implode(',', array_fill(0, count($missing), '%s'));
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT sku, id FROM {$this->tableVariants} WHERE deleted_at IS NULL AND sku IN ({$in}) ORDER BY id ASC",
                ...$missing
            ), ARRAY_A);
            foreach ((array)$rows as $r) {
                foreach ($missing as $sku) {
                    if (!isset($found[$sku]) && mb_strtolower($sku) === mb_strtolower((string)$r['sku'])) {
                        $found[$sku] = (int)$r['id'];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Existing variant for an attribute set: by option-vector key, else by the
     * attribute-hash key a variant gets before its vector is assigned.
     *
     * @param array<string, int> $existingByKey From loadActiveVariantKeys().
     */
    private function matchVariantByAttributes(int $productId, array $attributes, array $existingByKey): ?int
    {
        $selections = $this->buildOptionSelections($productId, $attributes);
        if (!empty($selections)) {
            try {
                $vector = $this->optionsRepo->buildCombinationVector($productId, $selections);
                $vectorHex = strtoupper($this->optionsRepo->buildCombinationKey($vector));
                if (isset($existingByKey[$vectorHex])) {
                    return $existingByKey[$vectorHex];
                }
            } catch (\Throwable $e) {
                // Fall back to the attribute hash below.
            }
        }

        $attrHex = strtoupper(hash('sha256', $this->attributeSignature($attributes)));
        return $existingByKey[$attrHex] ?? null;
    }

    /**
     * Canonical JSON of an attribute set (same encoding ProductRepository hashes).
     */
    private function attributeSignature(array $attributes): string
    {
        ksort($attributes);
        return (string)json_encode($attributes, JSON_UNESCAPED_UNICODE);
    }

    private function attachProductImages(int $productId, array $images, array &$warnings = []): void
    {
        $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId]);

        $pos = 0;
        foreach ($images as $img) {
            $rawUrl = trim((string)($img['url'] ?? ''));
            if (!preg_match('#^https?://#i', $rawUrl)) {
                if ($rawUrl !== '') {
                    $warnings[] = "Skipped non-HTTP product image value: " . substr($rawUrl, 0, 80);
                }
                continue;
            }
            $url = esc_url_raw($rawUrl);
            if ($url === '') {
                continue;
            }

            try {
                $assetId = $this->resolveProductAsset($url);
                $role = ($pos === 0) ? 'featured' : 'gallery';
                $altOverride = !empty($img['alt']) ? trim($img['alt']) : null;

                $this->assetService->attachProductImage($productId, $assetId, $role, $pos, $altOverride);
                $this->ensureProductAttachment($url);
                $pos++;
            } catch (\Throwable $e) {
                $warnings[] = "Skipped invalid product image URL ({$rawUrl}): " . $e->getMessage();
            }
        }
    }

    private function attachVariantImage(int $variantId, string $url, int $rowNum = 0, array &$warnings = []): void
    {
        $rawUrl = trim($url);
        if (!preg_match('#^https?://#i', $rawUrl)) {
            if ($rawUrl !== '') {
                $prefix = $rowNum > 0 ? "Row {$rowNum}: " : '';
                $warnings[] = "{$prefix}Skipped invalid Variant Image value (not an HTTP/HTTPS URL): " . substr(strip_tags($rawUrl), 0, 80);
            }
            return;
        }
        $clean = esc_url_raw($rawUrl);
        if ($clean === '') {
            return;
        }

        try {
            $assetId = $this->resolveProductAsset($clean);
            $this->assetService->attachVariantImage($variantId, $assetId, 0, true);
            $this->ensureProductAttachment($clean);
        } catch (\Throwable $e) {
            $prefix = $rowNum > 0 ? "Row {$rowNum}: " : '';
            $warnings[] = "{$prefix}Skipped invalid Variant Image URL ({$rawUrl}): " . $e->getMessage();
        }
    }

    /**
     * Asset id for an image URL, registering it on first use within this product.
     */
    private function resolveProductAsset(string $url): int
    {
        if (!isset($this->productAssetCache[$url])) {
            $asset = $this->assetService->registerAsset($url);
            $this->productAssetCache[$url] = ['asset_id' => (int)$asset['id'], 'attachment_id' => 0];
        }
        return $this->productAssetCache[$url]['asset_id'];
    }

    /**
     * Make sure the URL's asset has its WordPress attachment and source meta,
     * once per URL within this product.
     */
    private function ensureProductAttachment(string $url): void
    {
        if (!empty($this->productAssetCache[$url]['attachment_id'])) {
            return;
        }
        $assetId = $this->resolveProductAsset($url);
        $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId($assetId);
        update_post_meta($attachmentId, '_wc_attachment_source', $url);
        $this->productAssetCache[$url]['attachment_id'] = $attachmentId;
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
            $valKeys   = array_map('strtolower', array_column($existingVals, 'value_key'));

            $vPos = count($existingVals);
            foreach ($values as $valLabel) {
                $lowerLabel = strtolower($valLabel);
                if (!in_array($lowerLabel, $valLabels, true)) {
                    $baseKey = sanitize_title($valLabel);
                    if ($baseKey === '') {
                        $baseKey = 'val-' . ($vPos + 1);
                    }
                    $candidateKey = $baseKey;
                    $suffix = 2;
                    while (in_array(strtolower($candidateKey), $valKeys, true)) {
                        $candidateKey = $baseKey . '-' . $suffix;
                        $suffix++;
                    }
                    $this->optionsRepo->createOptionValue($optId, [
                        'label'     => $valLabel,
                        'value_key' => $candidateKey,
                        'position'  => $vPos++,
                    ]);
                    $valLabels[] = $lowerLabel;
                    $valKeys[]   = strtolower($candidateKey);
                }
            }
            $pos++;
        }
    }

    private function assignVariantCombinationVector(int $productId, int $variantId, array $attributes): void
    {
        $selections = $this->buildOptionSelections($productId, $attributes);
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
