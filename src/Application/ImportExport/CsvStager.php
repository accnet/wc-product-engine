<?php

declare(strict_types=1);

namespace WPE\Application\ImportExport;

use wpdb;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * CSV Pipeline Stager, Streaming Parser, and Dry-Run Validator (T29).
 *
 * Implements:
 * - Streaming UTF-8/BOM/quoted multiline CSV parser with dialect/delimiter sniffing (R115).
 * - Preserves missing vs blank column semantics (absent, blank, value) (R115).
 * - Sandboxed private upload storage with traversal protection & capability checks (R119).
 * - Shuffled parent/child row grouping on DB/disk via group_key (R117).
 * - Bounded streaming memory consumption O(1) for large CSV files (R118, AC-T29-02).
 * - Dry-run validation of SKU, options, images, and types with ZERO catalog mutations (R116, AC-T29-01).
 * - Exact row/column error tracking for malformed rows (AC-T29-01).
 * - Immutable file hash and validated snapshot hash guarding commit integrity (AC-T29-02).
 */
class CsvStager
{
    private static ?CsvStager $instance = null;

    private wpdb $wpdb;
    private string $tableImportRows;
    private string $tableJobs;
    private string $tableProducts;
    private string $tableVariants;
    private string $tableSkuRegistry;
    private string $uploadDir;

    private int $maxFileSize;       // bytes (default 500 MB)
    private int $maxFieldLength;    // bytes (default 64 KB)
    private int $maxRows;           // maximum rows allowed
    private int $batchSize;         // batch size for streaming DB inserts

    public const WOO_STANDARD_COLUMNS = [
        'ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
        'Short description', 'Description', 'Date sale price starts', 'Date sale price ends',
        'Tax status', 'Tax class', 'In stock?', 'Stock', 'Backorders allowed?', 'Sold individually?',
        'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)', 'Allow customer reviews?',
        'Purchase note', 'Sale price', 'Regular price', 'Categories', 'Tags', 'Shipping class',
        'Images', 'Download limit', 'Download expiry days', 'Parent', 'Grouped products',
        'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position',
    ];

    public const SHOPIFY_STANDARD_COLUMNS = [
        'URL handle', 'Title', 'Description', 'SKU', 'Price', 'Compare-at price',
        'Option1 name', 'Option1 value', 'Option2 name', 'Option2 value', 'Option3 name', 'Option3 value',
        'Product image URL', 'Image position', 'Image alt text', 'Variant image URL',
    ];

    public const SHOPIFY_LEGACY_ALIASES = [
        'Handle' => 'URL handle',
        'Body (HTML)' => 'Description',
        'Variant SKU' => 'SKU',
        'Variant Price' => 'Price',
        'Image Src' => 'Product image URL',
        'Variant Image' => 'Variant image URL',
    ];

    public const VALID_WOO_TYPES = [
        'simple', 'variable', 'variation', 'grouped', 'external',
    ];

    public function __construct(
        ?wpdb $wpdb = null,
        ?string $customUploadDir = null,
        int $maxFileSize = 524288000,
        int $maxFieldLength = 65536,
        int $maxRows = 1000000,
        int $batchSize = 250
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableImportRows = "{$prefix}wpe_import_rows";
        $this->tableJobs       = "{$prefix}wpe_jobs";
        $this->tableProducts   = "{$prefix}wpe_products";
        $this->tableVariants   = "{$prefix}wpe_variants";
        $this->tableSkuRegistry= "{$prefix}wpe_sku_registry";

        $this->maxFileSize    = $maxFileSize;
        $this->maxFieldLength = $maxFieldLength;
        $this->maxRows        = $maxRows;
        $this->batchSize      = $batchSize;

        if ($customUploadDir !== null) {
            $this->uploadDir = rtrim($customUploadDir, '/\\');
        } else {
            $wpUpload = wp_upload_dir();
            $this->uploadDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-imports';
        }

        $this->ensureSecureDirectory();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get private staging upload directory.
     */
    public function getUploadDir(): string
    {
        return $this->uploadDir;
    }

    /**
     * Ensure private staging directory exists and is secured against direct HTTP access (R119).
     */
    public function ensureSecureDirectory(): void
    {
        if (!is_dir($this->uploadDir)) {
            wp_mkdir_p($this->uploadDir);
        }

        $htaccessPath = $this->uploadDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "# Block direct HTTP access to staged files\n" .
                "<IfModule mod_authz_core.c>\n" .
                "Require all denied\n" .
                "</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n" .
                "Deny from all\n" .
                "</IfModule>\n";
            @file_put_contents($htaccessPath, $htaccessContent);
        }

        $indexPath = $this->uploadDir . '/index.php';
        if (!file_exists($indexPath)) {
            @file_put_contents($indexPath, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Store uploaded CSV file into private staging directory after security and size checks (R119).
     *
     * @param string $sourcePath Path to uploaded temporary file.
     * @param string $originalFilename Original name of file.
     * @param int|null $actorId Actor ID for permission check.
     * @return array Staged file metadata.
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function storeUploadedFile(string $sourcePath, string $originalFilename, ?int $actorId = null): array
    {
        // 1. Check capability (wpe_import_products or manage_woocommerce)
        if ($actorId !== null) {
            $canImport = user_can($actorId, 'wpe_import_products') || user_can($actorId, 'manage_woocommerce');
        } else {
            $canImport = current_user_can('wpe_import_products') || current_user_can('manage_woocommerce');
        }

        if (!$canImport) {
            throw new \RuntimeException('Permission denied: actor lacks import capability', 403);
        }

        // 2. Validate source path exists and is readable
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('Source file does not exist or is not readable: ' . $sourcePath);
        }

        // 3. Check file size limit
        $fileSize = filesize($sourcePath);
        if ($fileSize > $this->maxFileSize) {
            throw new \RuntimeException("File exceeds maximum allowed size of {$this->maxFileSize} bytes", 413);
        }

        // 4. Generate unique staged file ID (UUID)
        $stagedFileId = wp_generate_uuid4();
        $destinationPath = $this->uploadDir . DIRECTORY_SEPARATOR . $stagedFileId . '.csv';

        // 5. Copy file into secure staging directory
        $copied = @copy($sourcePath, $destinationPath);
        if (!$copied) {
            throw new \RuntimeException('Failed to copy file into private staging directory');
        }

        chmod($destinationPath, 0640);

        // 6. Compute SHA-256 hash
        $fileHash = hash_file('sha256', $destinationPath);

        return [
            'staged_file_id'   => $stagedFileId,
            'original_filename'=> sanitize_file_name($originalFilename),
            'file_path'        => $destinationPath,
            'file_hash'        => $fileHash,
            'size'             => $fileSize,
        ];
    }

    /**
     * Resolve staged file ID to absolute path with strict path traversal & sandboxing protection (R119).
     *
     * @param string $stagedFileId File ID (e.g. UUID).
     * @return string Validated absolute path inside staging directory.
     * @throws \InvalidArgumentException If path traversal is detected or ID is malformed.
     * @throws \RuntimeException If file does not exist or resolves outside sandbox.
     */
    public function resolveStagedPath(string $stagedFileId): string
    {
        // Reject null bytes, traversal sequences, or directory separators in ID
        if (str_contains($stagedFileId, "\0") ||
            str_contains($stagedFileId, '..') ||
            str_contains($stagedFileId, '/') ||
            str_contains($stagedFileId, '\\')
        ) {
            throw new \InvalidArgumentException('Invalid staged file ID: path traversal detected');
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]+(\.csv)?$/', $stagedFileId)) {
            throw new \InvalidArgumentException('Invalid staged file ID format');
        }

        $filename = str_ends_with($stagedFileId, '.csv') ? $stagedFileId : $stagedFileId . '.csv';
        $fullPath = $this->uploadDir . DIRECTORY_SEPARATOR . $filename;

        $realBase = realpath($this->uploadDir);
        $realFile = realpath($fullPath);

        if ($realBase === false || $realFile === false) {
            throw new \RuntimeException("Staged file not found: {$stagedFileId}", 404);
        }

        // Strict sandboxing: realpath must reside inside staging directory
        if (!str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Access denied: staged file path is outside allowed private directory', 403);
        }

        return $realFile;
    }

    /**
     * Sniff dialect (woo vs shopify), delimiter (,, ;, \t), encoding, and UTF-8 BOM (R115).
     *
     * @param string $filePath Absolute file path.
     * @return array Dialect info.
     */
    public function sniffDialectAndDelimiter(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open file: {$filePath}");
        }

        // 1. Check for UTF-8 BOM (\xEF\xBB\xBF)
        $bomCheck = fread($handle, 3);
        $hasBom = ($bomCheck === "\xEF\xBB\xBF");
        if (!$hasBom) {
            rewind($handle);
        }

        // 2. Read first 8KB sample for delimiter and dialect sniffing
        $sample = fread($handle, 8192);
        fclose($handle);

        if ($sample === '' || $sample === false) {
            return [
                'delimiter'   => ',',
                'has_bom'     => $hasBom,
                'format'      => 'woo',
                'headers'     => [],
                'column_count'=> 0,
            ];
        }

        // Find first line
        $firstLine = strtok($sample, "\r\n");
        if ($firstLine === false) {
            $firstLine = $sample;
        }

        // Delimiter candidate evaluation
        $candidates = [',', ';', "\t"];
        $bestDelimiter = ',';
        $maxColumns = 0;

        foreach ($candidates as $cand) {
            $parsed = str_getcsv($firstLine, $cand, '"', '\\');
            $cols = count($parsed);
            if ($cols > $maxColumns) {
                $maxColumns = $cols;
                $bestDelimiter = $cand;
            }
        }

        // Re-read first line with best delimiter
        $headers = str_getcsv($firstLine, $bestDelimiter, '"', '\\');
        $headers = array_map(function ($h) {
            $trimmed = trim((string)$h);
            // Strip BOM if present on first header
            if (str_starts_with($trimmed, "\xEF\xBB\xBF")) {
                $trimmed = substr($trimmed, 3);
            }
            return $trimmed;
        }, $headers);

        // Filter out empty headers at end
        while (!empty($headers) && end($headers) === '') {
            array_pop($headers);
        }

        // 3. Detect format: shopify vs woo
        $shopifyHits = 0;
        $wooHits = 0;

        $shopifyKeywords = ['URL handle', 'Handle', 'Option1 name', 'Option1 Name', 'Variant SKU', 'Body (HTML)', 'Image Src'];
        $wooKeywords = ['SKU', 'ID', 'Type', 'Parent', 'Attribute 1 name', 'Published', 'Regular price'];

        foreach ($headers as $h) {
            if (in_array($h, $shopifyKeywords, true)) {
                $shopifyHits++;
            }
            if (in_array($h, $wooKeywords, true)) {
                $wooHits++;
            }
        }

        $detectedFormat = ($shopifyHits > $wooHits) ? 'shopify' : 'woo';

        return [
            'delimiter'   => $bestDelimiter,
            'has_bom'     => $hasBom,
            'format'      => $detectedFormat,
            'headers'     => $headers,
            'column_count'=> count($headers),
        ];
    }

    /**
     * Stage CSV file into wpe_import_rows and create a staged job (R115, R117, R118).
     *
     * Streaming line-by-line parser maintains constant O(1) memory (R118).
     * Groups shuffled rows on DB via group_key (R117).
     * Preserves missing vs blank column states (R115).
     *
     * @param string $stagedFileId Staged file UUID.
     * @param array $config Configuration options (format, update_mode, image_mode, profile).
     * @return array Staged job receipt.
     */
    public function stageJob(string $stagedFileId, array $config = []): array
    {
        $filePath = $this->resolveStagedPath($stagedFileId);
        $fileHash = hash_file('sha256', $filePath);

        $sniffed = $this->sniffDialectAndDelimiter($filePath);
        $format = $config['format'] ?? $sniffed['format'] ?? 'woo';
        if ($format === 'auto') {
            $format = $sniffed['format'];
        }
        $delimiter = $config['delimiter'] ?? $sniffed['delimiter'];
        $profile = $config['profile'] ?? 'default';
        $updateMode = $config['update_mode'] ?? 'merge';
        $imageMode = $config['image_mode'] ?? 'external';
        $dryRun = (bool)($config['dry_run'] ?? true);

        $jobId = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');

        $jobConfig = [
            'staged_file_id' => $stagedFileId,
            'file_path'      => $filePath,
            'file_hash'      => $fileHash,
            'format'         => $format,
            'profile'        => $profile,
            'update_mode'    => $updateMode,
            'image_mode'     => $imageMode,
            'dry_run'        => $dryRun,
            'delimiter'      => $delimiter,
            'has_bom'        => $sniffed['has_bom'],
        ];

        // Create job entry in wpe_jobs
        $this->wpdb->insert(
            $this->tableJobs,
            [
                'id'              => $jobId,
                'kind'            => 'import',
                'status'          => 'staged',
                'config'          => wp_json_encode($jobConfig),
                'checkpoint'      => wp_json_encode(['last_row_staged' => 0]),
                'counts'          => wp_json_encode(['total_rows' => 0, 'staged_rows' => 0]),
                'cancel_requested'=> 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        // Open CSV stream
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open staged file: {$filePath}");
        }

        // Skip UTF-8 BOM if present
        if ($sniffed['has_bom']) {
            fseek($handle, 3);
        }

        // Read header line
        $rawHeaders = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($rawHeaders === false || empty($rawHeaders)) {
            fclose($handle);
            throw new \RuntimeException("CSV file contains no header row");
        }

        $headers = [];
        foreach ($rawHeaders as $idx => $h) {
            $clean = trim((string)$h);
            if ($idx === 0 && str_starts_with($clean, "\xEF\xBB\xBF")) {
                $clean = substr($clean, 3);
            }
            $headers[$idx] = $clean;
        }

        // Remove trailing empty headers
        while (!empty($headers) && end($headers) === '') {
            array_pop($headers);
        }
        $expectedColCount = count($headers);

        $standardColumns = ($format === 'shopify') ? self::SHOPIFY_STANDARD_COLUMNS : self::WOO_STANDARD_COLUMNS;

        $batch = [];
        $rowNumber = 1; // 1 is header row
        $totalRows = 0;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rowNumber++;
            $totalRows++;

            if ($totalRows > $this->maxRows) {
                fclose($handle);
                throw new \RuntimeException("File exceeds maximum allowed row limit of {$this->maxRows}", 422);
            }

            // Parse row and preserve missing vs blank column semantics (R115)
            $parsedRow = $this->parseRowCells($cells, $headers, $expectedColCount, $standardColumns, $rowNumber, $format);

            // Shuffled row grouping (R117): assign group_key
            $groupKey = $this->calculateGroupKey($parsedRow['fields'], $format, $rowNumber);

            $contentHashHex = hash('sha256', wp_json_encode($cells));

            $batch[] = [
                'row_number'       => $rowNumber,
                'group_key'        => substr($groupKey, 0, 191),
                'raw_fields'       => wp_json_encode($parsedRow),
                'mapped_payload'   => null,
                'status'           => empty($parsedRow['errors']) ? 'staged' : 'invalid',
                'errors'           => wp_json_encode($parsedRow['errors']),
                'warnings'         => wp_json_encode($parsedRow['warnings']),
                'content_hash_hex' => $contentHashHex,
            ];

            // Flush batch to database to maintain bounded O(1) memory (R118)
            if (count($batch) >= $this->batchSize) {
                $this->insertRowBatch($jobId, $batch);
                $batch = [];
            }
        }

        fclose($handle);

        if (!empty($batch)) {
            $this->insertRowBatch($jobId, $batch);
            $batch = [];
        }

        // Update job status & counts
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->tableJobs,
            [
                'counts'     => wp_json_encode(['total_rows' => $totalRows, 'staged_rows' => $totalRows]),
                'checkpoint' => wp_json_encode(['last_row_staged' => $totalRows]),
                'updated_at' => $now,
            ],
            ['id' => $jobId],
            ['%s', '%s', '%s'],
            ['%s']
        );

        return [
            'job_id'        => $jobId,
            'staged_file_id'=> $stagedFileId,
            'file_hash'     => $fileHash,
            'format'        => $format,
            'delimiter'     => $delimiter,
            'total_rows'    => $totalRows,
            'status'        => 'staged',
        ];
    }

    /**
     * Dry-run validate staged rows without mutating the catalog database (INV11, R116, AC-T29-01).
     *
     * Validates:
     * - SKU format & duplication within file and catalog conflicts.
     * - Product types and required fields.
     * - Options count and combination validity (Shopify max 3 options).
     * - Image URLs (syntactic validity, zero sideloading/downloads).
     * - Parent-child linkage for variations.
     * - Malformed rows with exact row and column errors (AC-T29-01).
     *
     * @param string $jobId Job UUID.
     * @return array Dry-run summary report and immutable snapshot hash.
     */
    public function validateJob(string $jobId): array
    {
        $job = $this->getJob($jobId);
        if (!$job) {
            throw new \RuntimeException("Job not found: {$jobId}", 404);
        }

        $config = json_decode((string)$job['config'], true) ?? [];
        $filePath = $config['file_path'] ?? null;
        $storedFileHash = $config['file_hash'] ?? null;

        // Verify staged file still exists and hash matches
        if (!$filePath || !file_exists($filePath)) {
            throw new \RuntimeException("Staged file not found on disk", 404);
        }

        $currentFileHash = hash_file('sha256', $filePath);
        if ($currentFileHash !== $storedFileHash) {
            throw new \RuntimeException("Staged file content modified since staging", 412);
        }

        $format = $config['format'] ?? 'woo';
        $profile = $config['profile'] ?? 'default';
        $updateMode = $config['update_mode'] ?? 'merge';

        // Read all rows in keyset chunks to preserve O(1) memory (R118)
        $chunkSize = $this->batchSize;
        $lastRowNumber = 0;

        $seenSkus = [];        // sku_normalized => row_number (detect duplicates in file)
        $stagedParents = [];   // group_key / sku => true (track parents available in file)

        $allErrors = [];
        $allWarnings = [];

        $totalRows = 0;
        $validRows = 0;
        $invalidRows = 0;
        $warningRows = 0;
        $parentCount = 0;
        $variationCount = 0;

        // Phase 1: First pass to register all parents declared in file
        while (true) {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT `row_number`, group_key, raw_fields, status FROM {$this->tableImportRows} WHERE job_id = %s AND `row_number` > %d ORDER BY `row_number` ASC LIMIT %d",
                $jobId,
                $lastRowNumber,
                $chunkSize
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $r) {
                $lastRowNumber = (int)$r['row_number'];
                $totalRows++;
                $parsed = json_decode((string)$r['raw_fields'], true) ?? [];
                $fields = $parsed['fields'] ?? [];

                $type = strtolower(trim($fields['Type'] ?? ''));
                $parent = trim($fields['Parent'] ?? '');

                if ($format === 'shopify') {
                    $handle = trim($fields['URL handle'] ?? $fields['Handle'] ?? '');
                    if ($handle !== '') {
                        $stagedParents[$handle] = true;
                    }
                } else {
                    if ($type !== 'variation' && $parent === '') {
                        $sku = trim($fields['SKU'] ?? '');
                        $id = trim($fields['ID'] ?? '');
                        if ($sku !== '') {
                            $stagedParents[$sku] = true;
                        }
                        if ($id !== '') {
                            $stagedParents["id:{$id}"] = true;
                        }
                        $groupKey = (string)$r['group_key'];
                        $stagedParents[$groupKey] = true;
                    }
                }
            }
            unset($rows);
        }

        // Phase 2: Detailed validation with keyset pagination
        $lastRowNumber = 0;
        while (true) {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT `row_number`, group_key, raw_fields, status, errors, warnings FROM {$this->tableImportRows} WHERE job_id = %s AND `row_number` > %d ORDER BY `row_number` ASC LIMIT %d",
                $jobId,
                $lastRowNumber,
                $chunkSize
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $r) {
                $rowNum = (int)$r['row_number'];
                $lastRowNumber = $rowNum;
                $groupKey = (string)$r['group_key'];

                $parsed = json_decode((string)$r['raw_fields'], true) ?? [];
                $fields = $parsed['fields'] ?? [];
                $states = $parsed['states'] ?? [];
                $rowErrors = json_decode((string)$r['errors'], true) ?? [];
                $rowWarnings = json_decode((string)$r['warnings'], true) ?? [];

                // 1. Validate Product Type
                if ($format === 'woo') {
                    $type = strtolower(trim($fields['Type'] ?? 'simple'));
                    if ($type === '') {
                        $type = 'simple';
                    }
                    if (!in_array($type, self::VALID_WOO_TYPES, true)) {
                        $rowErrors[] = [
                            'row_number' => $rowNum,
                            'column'     => 'Type',
                            'code'       => 'invalid_product_type',
                            'message'    => "Unsupported product type '{$type}' on row {$rowNum}",
                        ];
                    }
                    if ($type === 'variation') {
                        $variationCount++;
                    } else {
                        $parentCount++;
                    }
                } else {
                    // Shopify
                    $parentCount++;
                }

                // 2. Validate SKU format and duplication (INV04)
                $sku = trim($fields['SKU'] ?? '');
                if ($sku !== '') {
                    $skuNorm = mb_strtolower($sku, 'UTF-8');
                    if (isset($seenSkus[$skuNorm])) {
                        $prevRow = $seenSkus[$skuNorm];
                        $rowErrors[] = [
                            'row_number' => $rowNum,
                            'column'     => 'SKU',
                            'code'       => 'duplicate_sku_in_file',
                            'message'    => "Duplicate SKU '{$sku}' found in file (first seen on row {$prevRow})",
                        ];
                    } else {
                        $seenSkus[$skuNorm] = $rowNum;
                    }

                    // Check catalog SKU collision
                    $existingSku = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT object_id, object_kind FROM {$this->tableSkuRegistry} WHERE normalized_sku = %s",
                        $skuNorm
                    ));

                    if ($existingSku) {
                        // SKU exists in catalog
                        if ($updateMode === 'merge') {
                            $rowWarnings[] = [
                                'row_number' => $rowNum,
                                'column'     => 'SKU',
                                'code'       => 'sku_exists_update_target',
                                'message'    => "SKU '{$sku}' already exists in catalog; will update {$existingSku->object_kind} ID {$existingSku->object_id}",
                            ];
                        }
                    }
                }

                // 3. Validate Options / Attributes count & schema
                if ($format === 'shopify') {
                    // Shopify allows maximum 3 options
                    for ($optIdx = 4; $optIdx <= 10; $optIdx++) {
                        $optCol = "Option{$optIdx} name";
                        if (!empty($fields[$optCol])) {
                            $rowErrors[] = [
                                'row_number' => $rowNum,
                                'column'     => $optCol,
                                'code'       => 'max_options_exceeded',
                                'message'    => "Shopify dialect allows maximum 3 options; found '{$optCol}' on row {$rowNum}",
                            ];
                        }
                    }
                }

                // 4. Validate Parent-child linkage for variations
                if ($format === 'woo') {
                    $type = strtolower(trim($fields['Type'] ?? ''));
                    $parentRef = trim($fields['Parent'] ?? '');
                    if ($type === 'variation' || $parentRef !== '') {
                        $parentFound = false;
                        if ($parentRef !== '') {
                            if (isset($stagedParents[$parentRef]) || isset($stagedParents[$groupKey])) {
                                $parentFound = true;
                            } else {
                                // Check DB
                                if (str_starts_with($parentRef, 'id:')) {
                                    $pId = (int)substr($parentRef, 3);
                                    $pRow = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->tableProducts} WHERE id = %d", $pId));
                                    if ($pRow) {
                                        $parentFound = true;
                                    }
                                } else {
                                    $pRow = $this->wpdb->get_var($this->wpdb->prepare("SELECT id FROM {$this->tableProducts} WHERE sku = %s", $parentRef));
                                    if ($pRow) {
                                        $parentFound = true;
                                    }
                                }
                            }
                        } else {
                            // Variation with empty Parent column: check if parent exists in group
                            if (isset($stagedParents[$groupKey])) {
                                $parentFound = true;
                            }
                        }

                        if (!$parentFound) {
                            $rowErrors[] = [
                                'row_number' => $rowNum,
                                'column'     => 'Parent',
                                'code'       => 'missing_parent',
                                'message'    => "Parent product '{$parentRef}' for variation on row {$rowNum} not found in file or catalog",
                            ];
                        }
                    }
                }

                // 5. Validate Image URLs without sideloading or downloading (INV11, R116)
                $imageFields = ($format === 'shopify')
                    ? ['Product image URL', 'Variant image URL', 'Image Src', 'Variant Image']
                    : ['Images'];

                foreach ($imageFields as $imgCol) {
                    if (!empty($fields[$imgCol])) {
                        $rawImgs = explode(',', (string)$fields[$imgCol]);
                        foreach ($rawImgs as $rawUrl) {
                            $cleanUrl = trim($rawUrl);
                            if ($cleanUrl !== '' && !filter_var($cleanUrl, FILTER_VALIDATE_URL)) {
                                $rowErrors[] = [
                                    'row_number' => $rowNum,
                                    'column'     => $imgCol,
                                    'code'       => 'invalid_image_url',
                                    'message'    => "Malformed image URL '{$cleanUrl}' on row {$rowNum}",
                                ];
                            }
                        }
                    }
                }

                // Determine final row status
                $rowStatus = empty($rowErrors) ? 'valid' : 'invalid';
                if ($rowStatus === 'valid') {
                    $validRows++;
                } else {
                    $invalidRows++;
                }

                if (!empty($rowWarnings)) {
                    $warningRows++;
                }

                foreach ($rowErrors as $err) {
                    $allErrors[] = $err;
                }
                foreach ($rowWarnings as $warn) {
                    $allWarnings[] = $warn;
                }

                // Update row status, errors, warnings in DB
                $this->wpdb->update(
                    $this->tableImportRows,
                    [
                        'status'   => $rowStatus,
                        'errors'   => wp_json_encode($rowErrors),
                        'warnings' => wp_json_encode($rowWarnings),
                    ],
                    [
                        'job_id'     => $jobId,
                        'row_number' => $rowNum,
                    ],
                    ['%s', '%s', '%s'],
                    ['%s', '%d']
                );
            }
            unset($rows);
        }

        // Compute immutable snapshot hash (AC-T29-02)
        $snapshotHash = hash('sha256', "{$currentFileHash}:{$format}:{$profile}:{$updateMode}:{$totalRows}:{$validRows}");
        $now = gmdate('Y-m-d H:i:s.u');

        $counts = [
            'total_rows'      => $totalRows,
            'valid_rows'      => $validRows,
            'invalid_rows'    => $invalidRows,
            'warning_rows'    => $warningRows,
            'parent_products' => $parentCount,
            'variation_rows'  => $variationCount,
        ];

        $checkpoint = [
            'validated_snapshot_hash' => $snapshotHash,
            'validated_at'            => $now,
            'last_row_staged'         => $totalRows,
        ];

        $this->wpdb->update(
            $this->tableJobs,
            [
                'status'     => 'validated',
                'checkpoint' => wp_json_encode($checkpoint),
                'counts'     => wp_json_encode($counts),
                'updated_at' => $now,
            ],
            ['id' => $jobId],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );

        return [
            'job_id'        => $jobId,
            'file_hash'     => $currentFileHash,
            'snapshot_hash' => $snapshotHash,
            'status'        => 'validated',
            'counts'        => $counts,
            'errors'        => $allErrors,
            'warnings'      => $allWarnings,
        ];
    }

    /**
     * Verify commit prerequisites guarding against file or config mutation after validation (AC-T29-02).
     *
     * @param string $jobId Job UUID.
     * @param string $incomingSnapshotHash Hash sent in commit request.
     * @param string $conflictPolicy Conflict policy ('fail' or 'skip').
     * @return array Verification result with eligibility status and error if any.
     */
    public function verifyCommitPrerequisites(string $jobId, string $incomingSnapshotHash, string $conflictPolicy = 'fail'): array
    {
        $job = $this->getJob($jobId);
        if (!$job) {
            return [
                'eligible' => false,
                'code'     => 'not_found',
                'error'    => "Job {$jobId} not found",
            ];
        }

        if ($job['status'] !== 'validated') {
            return [
                'eligible' => false,
                'code'     => 'precondition_required',
                'error'    => "Job status is '{$job['status']}', expected 'validated'",
            ];
        }

        $config = json_decode((string)$job['config'], true) ?? [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?? [];

        $filePath = $config['file_path'] ?? null;
        $storedFileHash = $config['file_hash'] ?? null;
        $storedSnapshotHash = $checkpoint['validated_snapshot_hash'] ?? null;

        // 1. Check staged file still exists
        if (!$filePath || !file_exists($filePath)) {
            return [
                'eligible' => false,
                'code'     => 'not_found',
                'error'    => 'Staged CSV file missing on disk',
            ];
        }

        // 2. Check if file was tampered with after validation (AC-T29-02)
        $currentFileHash = hash_file('sha256', $filePath);
        if ($currentFileHash !== $storedFileHash) {
            return [
                'eligible' => false,
                'code'     => 'stale_version',
                'error'    => 'File content has been modified after dry-run validation',
            ];
        }

        // 3. Check validated snapshot hash match (AC-T29-02)
        if (!$storedSnapshotHash || $storedSnapshotHash !== $incomingSnapshotHash) {
            return [
                'eligible' => false,
                'code'     => 'stale_version',
                'error'    => 'Validated snapshot hash mismatch; re-run dry-run validation',
            ];
        }

        return [
            'eligible'        => true,
            'job_id'          => $jobId,
            'snapshot_hash'   => $incomingSnapshotHash,
            'conflict_policy' => $conflictPolicy,
        ];
    }

    /**
     * Get single job record by ID.
     */
    public function getJob(string $jobId): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Fetch staged rows for a job, optionally filtered by group_key.
     */
    public function getJobRows(string $jobId, ?string $groupKey = null, int $limit = 50, int $offset = 0): array
    {
        if ($groupKey !== null) {
            return $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM {$this->tableImportRows} WHERE job_id = %s AND group_key = %s ORDER BY `row_number` ASC LIMIT %d OFFSET %d",
                $jobId,
                $groupKey,
                $limit,
                $offset
            ), ARRAY_A);
        }

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->tableImportRows} WHERE job_id = %s ORDER BY `row_number` ASC LIMIT %d OFFSET %d",
            $jobId,
            $limit,
            $offset
        ), ARRAY_A);
    }

    /**
     * Register REST API routes for imports according to OpenAPI wpe-v1 contract.
     */
    public function registerRestRoutes(): void
    {
        // POST /wpe/v1/admin/imports
        register_rest_route('wpe/v1', '/admin/imports', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRestCreateImport'],
            'permission_callback' => [$this, 'checkAdminPermissions'],
        ]);

        // POST /wpe/v1/admin/imports/{job}/commit
        register_rest_route('wpe/v1', '/admin/imports/(?P<job>[a-zA-Z0-9_\-]+)/commit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRestCommitImport'],
            'permission_callback' => [$this, 'checkAdminPermissions'],
        ]);

        // GET /wpe/v1/admin/jobs/{id}
        register_rest_route('wpe/v1', '/admin/jobs/(?P<id>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleRestGetJob'],
            'permission_callback' => [$this, 'checkAdminPermissions'],
        ]);
    }

    public function checkAdminPermissions(): bool
    {
        return current_user_can('manage_woocommerce') ||
               current_user_can('wpe_import_products') ||
               current_user_can('wpe_manage_operations');
    }

    public function handleRestCreateImport(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $stagedFileId = $params['staged_file_id'] ?? null;

        if (!$stagedFileId || !is_string($stagedFileId)) {
            return new WP_REST_Response([
                'code'    => 'invalid_request',
                'message' => 'Missing or invalid required parameter: staged_file_id',
            ], 400);
        }

        try {
            $staged = $this->stageJob($stagedFileId, $params);
            $dryRun = (bool)($params['dry_run'] ?? true);

            if ($dryRun) {
                $validation = $this->validateJob($staged['job_id']);
                return new WP_REST_Response([
                    'job_id'        => $staged['job_id'],
                    'status'        => 'validated',
                    'snapshot_hash' => $validation['snapshot_hash'],
                    'counts'        => $validation['counts'],
                    'errors'        => $validation['errors'],
                    'warnings'      => $validation['warnings'],
                ], 202);
            }

            return new WP_REST_Response([
                'job_id' => $staged['job_id'],
                'status' => 'staged',
            ], 202);
        } catch (\InvalidArgumentException $e) {
            return new WP_REST_Response(['code' => 'invalid_request', 'message' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return new WP_REST_Response(['code' => 'import_error', 'message' => $e->getMessage()], $code);
        }
    }

    public function handleRestCommitImport(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = $request->get_param('job');
        $params = $request->get_json_params() ?: $request->get_params();
        $snapshotHash = $params['validated_snapshot_hash'] ?? null;
        $conflictPolicy = $params['conflict_policy'] ?? 'fail';

        if (!$snapshotHash || !is_string($snapshotHash)) {
            return new WP_REST_Response([
                'code'    => 'invalid_request',
                'message' => 'Missing or invalid validated_snapshot_hash',
            ], 400);
        }

        $check = $this->verifyCommitPrerequisites($jobId, $snapshotHash, $conflictPolicy);
        if (!$check['eligible']) {
            $statusCode = ($check['code'] === 'stale_version') ? 412 : (($check['code'] === 'not_found') ? 404 : 428);
            return new WP_REST_Response([
                'code'    => $check['code'],
                'message' => $check['error'],
            ], $statusCode);
        }

        return new WP_REST_Response([
            'job_id' => $jobId,
            'status' => 'accepted',
        ], 202);
    }

    public function handleRestGetJob(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = $request->get_param('id');
        $job = $this->getJob($jobId);

        if (!$job) {
            return new WP_REST_Response([
                'code'    => 'not_found',
                'message' => "Job {$jobId} not found",
            ], 404);
        }

        $counts = json_decode((string)$job['counts'], true) ?: new \stdClass();
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?: new \stdClass();

        return new WP_REST_Response([
            'id'          => $job['id'],
            'kind'        => $job['kind'],
            'status'      => $job['status'],
            'counts'      => $counts,
            'checkpoint'  => $checkpoint,
            'errors'      => [],
            'report_href' => null,
        ], 200);
    }

    /**
     * Parse individual CSV row cells, preserving missing vs blank semantics and catching malformed errors (R115, AC-T29-01).
     */
    private function parseRowCells(
        array $cells,
        array $headers,
        int $expectedColCount,
        array $standardColumns,
        int $rowNumber,
        string $format
    ): array {
        $errors = [];
        $warnings = [];

        $actualCount = count($cells);
        if ($actualCount !== $expectedColCount) {
            $errors[] = [
                'row_number' => $rowNumber,
                'column'     => 'column_count',
                'code'       => 'column_count_mismatch',
                'message'    => "Row {$rowNumber} has {$actualCount} columns; expected {$expectedColCount} based on header row",
            ];
        }

        $fields = [];
        $states = [];
        $blanks = [];

        foreach ($headers as $idx => $headerName) {
            $val = $cells[$idx] ?? null;

            // Check field length limit (R115, AC-T29-01)
            if ($val !== null && strlen($val) > $this->maxFieldLength) {
                $errors[] = [
                    'row_number' => $rowNumber,
                    'column'     => $headerName,
                    'code'       => 'field_too_long',
                    'message'    => "Field '{$headerName}' on row {$rowNumber} exceeds max allowed length of {$this->maxFieldLength} bytes",
                ];
            }

            if ($val === null || trim($val) === '') {
                $fields[$headerName] = '';
                $states[$headerName] = 'blank';
                $blanks[] = $headerName;
            } else {
                $cleanVal = trim($val);
                $fields[$headerName] = $cleanVal;
                $states[$headerName] = 'value';

                // Check formula injection (10 §6.1)
                $firstChar = $cleanVal[0] ?? '';
                if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
                    $warnings[] = [
                        'row_number' => $rowNumber,
                        'column'     => $headerName,
                        'code'       => 'formula_like_cell',
                        'message'    => "Cell '{$headerName}' starts with potential formula character '{$firstChar}'",
                    ];
                }
            }
        }

        // Identify absent standard columns
        $absent = [];
        foreach ($standardColumns as $stdCol) {
            if (!isset($fields[$stdCol])) {
                $states[$stdCol] = 'absent';
                $absent[] = $stdCol;
            }
        }

        return [
            'fields'   => $fields,
            'states'   => $states,
            'absent'   => $absent,
            'blanks'   => $blanks,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Calculate group_key for DB/disk grouping of shuffled parent and variation rows (R117).
     */
    private function calculateGroupKey(array $fields, string $format, int $rowNumber): string
    {
        if ($format === 'shopify') {
            $handle = trim($fields['URL handle'] ?? $fields['Handle'] ?? '');
            if ($handle !== '') {
                return $handle;
            }
            $sku = trim($fields['SKU'] ?? $fields['Variant SKU'] ?? '');
            return ($sku !== '') ? $sku : "row:{$rowNumber}";
        }

        // WooCommerce format
        $type = strtolower(trim($fields['Type'] ?? ''));
        $parent = trim($fields['Parent'] ?? '');

        if ($type === 'variation' || $parent !== '') {
            if ($parent !== '') {
                return $parent;
            }
            $sku = trim($fields['SKU'] ?? '');
            return ($sku !== '') ? $sku : "row:{$rowNumber}";
        }

        // Parent or simple product
        $sku = trim($fields['SKU'] ?? '');
        if ($sku !== '') {
            return $sku;
        }

        $id = trim($fields['ID'] ?? '');
        if ($id !== '') {
            return "id:{$id}";
        }

        return "row:{$rowNumber}";
    }

    /**
     * Batch insert rows into wpe_import_rows with backticks on row_number (R118).
     */
    private function insertRowBatch(string $jobId, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $placeholders = [];
        $values = [];

        foreach ($batch as $row) {
            $placeholders[] = "(%s, %d, %s, %s, %s, %s, %s, %s, UNHEX(%s))";
            $values[] = $jobId;
            $values[] = $row['row_number'];
            $values[] = $row['group_key'];
            $values[] = $row['raw_fields'];
            $values[] = $row['mapped_payload'] ?? null;
            $values[] = $row['status'];
            $values[] = $row['errors'];
            $values[] = $row['warnings'];
            $values[] = $row['content_hash_hex'];
        }

        $sql = "INSERT INTO {$this->tableImportRows} (job_id, `row_number`, group_key, raw_fields, mapped_payload, status, errors, warnings, content_hash) VALUES " . implode(', ', $placeholders);

        $prepared = $this->wpdb->prepare($sql, $values);
        $res = $this->wpdb->query($prepared);

        if ($res === false) {
            throw new \RuntimeException("Database error inserting import rows batch: " . $this->wpdb->last_error);
        }
    }
}
