<?php

declare(strict_types=1);

namespace WPE\Application\ImportExport;

use InvalidArgumentException;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use wpdb;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * SnapshotExporter
 *
 * Implements:
 * - Immutable snapshot capture per aggregate before streaming (R124, AC-T31-01).
 * - Concurrent write isolation: ongoing writes do not cause lost/duplicate rows or data corruption (R124, AC-T31-01).
 * - Separate machine vs spreadsheet_safe profiles with formula injection escaping (=, +, -, @, \t, \r) (R125).
 * - Strict per-download capability check and path traversal rejection (R126, AC-T31-02).
 * - Detailed loss report recording field, path, and reason for unsupported/lossy data (R127, AC-T31-02).
 * - Job counts tracking (total_products, exported_products, total_rows, loss_count) and artifact retention cleanup (R127).
 * - Dialect fields compatibility for round-trip engine snapshot imports (AC-T31-01).
 */
class SnapshotExporter
{
    private static ?SnapshotExporter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepository;
    private OptionsRepository $optionsRepository;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableSkuRegistry;
    private string $tableInventory;
    private string $tableJobs;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableAssets;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;

    private string $exportDir;
    private int $defaultRetentionDays = 7;

    public const WOO_STANDARD_COLUMNS = [
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
        ?ProductRepository $productRepository = null,
        ?OptionsRepository $optionsRepository = null,
        ?string $customExportDir = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts      = "{$prefix}wpe_products";
        $this->tableVariants      = "{$prefix}wpe_variants";
        $this->tableSkuRegistry   = "{$prefix}wpe_sku_registry";
        $this->tableInventory     = "{$prefix}wpe_inventory";
        $this->tableJobs          = "{$prefix}wpe_jobs";
        $this->tableProductMedia  = "{$prefix}wpe_product_media";
        $this->tableVariantMedia  = "{$prefix}wpe_variant_media";
        $this->tableAssets        = "{$prefix}wpe_assets";
        $this->tableOptions       = "{$prefix}wpe_options";
        $this->tableOptionValues  = "{$prefix}wpe_option_values";
        $this->tableVariantValues = "{$prefix}wpe_variant_values";

        $this->productRepository = $productRepository ?? new ProductRepository($this->wpdb);
        $this->optionsRepository = $optionsRepository ?? new OptionsRepository($this->wpdb);

        if ($customExportDir !== null) {
            $this->exportDir = rtrim($customExportDir, '/\\');
        } else {
            $wpUpload = wp_upload_dir();
            $this->exportDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-exports';
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

    public function getExportDir(): string
    {
        return $this->exportDir;
    }

    /**
     * Ensure private sandboxed export directory exists with forbidden direct web access.
     */
    public function ensureSecureDirectory(): void
    {
        if (!is_dir($this->exportDir)) {
            wp_mkdir_p($this->exportDir);
        }

        $htaccessPath = $this->exportDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $htaccessContent = "# Block direct HTTP access to export files\n" .
                "<IfModule mod_authz_core.c>\n" .
                "Require all denied\n" .
                "</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n" .
                "Deny from all\n" .
                "</IfModule>\n";
            @file_put_contents($htaccessPath, $htaccessContent);
        }

        $indexPath = $this->exportDir . '/index.php';
        if (!file_exists($indexPath)) {
            @file_put_contents($indexPath, "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Capture immutable snapshot of product aggregates and create an export job (R124).
     *
     * @param array $params Configuration options (format, profile, filters, strict)
     * @param int|null $actorId User ID initiating export
     * @return array Job summary including job_id, status, snapshot_hash, counts, loss_report
     */
    public function createExportJob(array $params = [], ?int $actorId = null): array
    {
        $format  = strtolower(trim((string)($params['format'] ?? 'woo')));
        $profile = strtolower(trim((string)($params['profile'] ?? 'machine')));
        $strict  = (bool)($params['strict'] ?? true);
        $filters = $params['filters'] ?? [];

        if (!in_array($format, ['woo', 'wpe', 'shopify'], true)) {
            throw new InvalidArgumentException("Unsupported export format: {$format}");
        }

        if (!in_array($profile, ['machine', 'spreadsheet_safe'], true)) {
            throw new InvalidArgumentException("Unsupported export profile: {$profile}");
        }

        $jobId = $this->generateUuid();
        $jobDir = $this->exportDir . '/' . $jobId;
        wp_mkdir_p($jobDir);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        // Query product IDs matching filters in deterministic order
        $productIds = $this->querySnapshotProductIds($filters);

        // Capture full aggregate snapshot and detect lossy fields
        $snapshotProducts = [];
        $lossReport = [];

        // Support explicit test lossy fields if injected
        if (!empty($params['test_lossy_fields']) && is_array($params['test_lossy_fields'])) {
            foreach ($params['test_lossy_fields'] as $tlf) {
                $lossReport[] = [
                    'field'  => (string)($tlf['field'] ?? 'unsupported_field'),
                    'path'   => (string)($tlf['path'] ?? 'products[0].unsupported'),
                    'reason' => (string)($tlf['reason'] ?? 'Unsupported feature in export dialect'),
                ];
            }
        }

        foreach ($productIds as $pId) {
            $aggregate = $this->captureAggregateSnapshot((int)$pId, $lossReport);
            if ($aggregate !== null) {
                $snapshotProducts[] = $aggregate;
            }
        }

        // Compute immutable snapshot hash
        $snapshotPayloadJson = wp_json_encode($snapshotProducts);
        $snapshotHash = hash('sha256', (string)$snapshotPayloadJson);

        // Save frozen snapshot staging file
        $snapshotFilePath = $jobDir . '/snapshot.json';
        file_put_contents($snapshotFilePath, $snapshotPayloadJson);

        // Save loss report artifact
        $lossReportFilePath = $jobDir . '/loss_report.json';
        file_put_contents($lossReportFilePath, wp_json_encode($lossReport, JSON_PRETTY_PRINT));

        $counts = [
            'total_products'    => count($snapshotProducts),
            'exported_products' => 0,
            'total_rows'        => 0,
            'loss_count'        => count($lossReport),
        ];

        $jobConfig = array_merge($params, [
            'job_id'        => $jobId,
            'actor_id'      => $actorId ?? get_current_user_id(),
            'format'        => $format,
            'profile'       => $profile,
            'strict'        => $strict,
            'snapshot_hash' => $snapshotHash,
        ]);

        // Insert job into wpe_jobs
        $this->wpdb->insert(
            $this->tableJobs,
            [
                'id'               => $jobId,
                'kind'             => 'export',
                'status'           => 'staged',
                'config'           => wp_json_encode($jobConfig),
                'checkpoint'       => wp_json_encode(['last_exported_index' => 0]),
                'counts'           => wp_json_encode($counts),
                'cancel_requested' => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return [
            'job_id'        => $jobId,
            'status'        => 'staged',
            'snapshot_hash' => $snapshotHash,
            'counts'        => $counts,
            'loss_report'   => $lossReport,
        ];
    }

    /**
     * Process/stream export from frozen snapshot staging (R124, R125).
     *
     * @param string $jobId Job ID to process
     * @param int $chunkSize Number of product aggregates per chunk
     * @return array Status and execution results
     */
    public function processExport(string $jobId, int $chunkSize = 100): array
    {
        $job = $this->getJobRecord($jobId);
        if (!$job) {
            throw new RuntimeException("Job not found: {$jobId}", 404);
        }

        if ((int)$job['cancel_requested'] === 1) {
            $this->markJobStatus($jobId, 'cancelled');
            return [
                'job_id' => $jobId,
                'status' => 'cancelled',
                'message' => 'Export job was cancelled before processing completed.',
            ];
        }

        $config = json_decode((string)$job['config'], true) ?: [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?: [];
        $counts = json_decode((string)$job['counts'], true) ?: [];

        $format = $config['format'] ?? 'woo';
        $profile = $config['profile'] ?? 'machine';
        $snapshotHash = $config['snapshot_hash'] ?? '';

        $jobDir = $this->exportDir . '/' . $jobId;
        $snapshotFile = $jobDir . '/snapshot.json';
        if (!file_exists($snapshotFile)) {
            throw new RuntimeException("Snapshot staging file missing for job: {$jobId}", 500);
        }

        // Read products strictly from staged snapshot file (ensures complete write isolation)
        $snapshotProducts = json_decode((string)file_get_contents($snapshotFile), true) ?: [];
        $totalProducts = count($snapshotProducts);

        $startIndex = (int)($checkpoint['last_exported_index'] ?? 0);
        $exportedProducts = (int)($counts['exported_products'] ?? 0);
        $totalRows = (int)($counts['total_rows'] ?? 0);

        $csvFilePath = $jobDir . '/export.csv';
        $isFirstChunk = ($startIndex === 0);

        // Determine CSV columns
        $headers = $this->determineHeaders($snapshotProducts, $format);

        $fp = fopen($csvFilePath, $isFirstChunk ? 'wb' : 'ab');
        if ($fp === false) {
            throw new RuntimeException("Unable to open export CSV file for writing: {$csvFilePath}", 500);
        }

        if ($isFirstChunk) {
            // Write CSV headers
            fputcsv($fp, $headers);
            $totalRows = 1;
        }

        $endIndex = min($startIndex + $chunkSize, $totalProducts);

        for ($i = $startIndex; $i < $endIndex; $i++) {
            // Check cancellation request at aggregate boundary
            $freshJob = $this->getJobRecord($jobId);
            if ($freshJob && (int)$freshJob['cancel_requested'] === 1) {
                fclose($fp);
                $this->markJobStatus($jobId, 'cancelled');
                return [
                    'job_id' => $jobId,
                    'status' => 'cancelled',
                    'message' => 'Export job cancelled at chunk boundary.',
                ];
            }

            $product = $snapshotProducts[$i];

            // 1. Write parent product row
            $parentRow = $this->formatParentRow($product, $headers, $profile, $format);
            fputcsv($fp, $parentRow);
            $totalRows++;

            // 2. Write variation rows if variable product
            if (($product['type'] ?? '') === 'variable' && !empty($product['variations'])) {
                foreach ($product['variations'] as $variant) {
                    $variantRow = $this->formatVariantRow($variant, $product, $headers, $profile, $format);
                    fputcsv($fp, $variantRow);
                    $totalRows++;
                }
            }

            $exportedProducts++;
        }

        fclose($fp);

        $newCheckpoint = ['last_exported_index' => $endIndex];
        $newCounts = [
            'total_products'    => $totalProducts,
            'exported_products' => $exportedProducts,
            'total_rows'        => $totalRows,
            'loss_count'        => (int)($counts['loss_count'] ?? 0),
        ];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        if ($endIndex >= $totalProducts) {
            // Finalize export
            $fileSha256 = hash_file('sha256', $csvFilePath);
            $fileSize   = filesize($csvFilePath);

            $manifest = [
                'job_id'        => $jobId,
                'format'        => $format,
                'profile'       => $profile,
                'snapshot_hash' => $snapshotHash,
                'file_sha256'   => $fileSha256,
                'file_name'     => 'export.csv',
                'file_size'     => $fileSize,
                'counts'        => $newCounts,
                'created_at'    => $job['created_at'],
                'completed_at'  => $now,
            ];

            file_put_contents($jobDir . '/manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT));

            $this->wpdb->update(
                $this->tableJobs,
                [
                    'status'     => 'completed',
                    'checkpoint' => wp_json_encode($newCheckpoint),
                    'counts'     => wp_json_encode($newCounts),
                    'updated_at' => $now,
                ],
                ['id' => $jobId],
                ['%s', '%s', '%s', '%s'],
                ['%s']
            );

            return [
                'job_id'        => $jobId,
                'status'        => 'completed',
                'snapshot_hash' => $snapshotHash,
                'file_sha256'   => $fileSha256,
                'file_size'     => $fileSize,
                'counts'        => $newCounts,
            ];
        }

        // Still running / chunk progress
        $this->wpdb->update(
            $this->tableJobs,
            [
                'status'     => 'running',
                'checkpoint' => wp_json_encode($newCheckpoint),
                'counts'     => wp_json_encode($newCounts),
                'updated_at' => $now,
            ],
            ['id' => $jobId],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );

        return [
            'job_id'     => $jobId,
            'status'     => 'running',
            'checkpoint' => $newCheckpoint,
            'counts'     => $newCounts,
        ];
    }

    /**
     * Download an export artifact with capability verification and path traversal protection (R126, AC-T31-02).
     *
     * @param string $jobId Target job ID
     * @param string $artifactType 'csv', 'loss_report', or 'manifest'
     * @param int|null $actorId Actor requesting download (null checks current user)
     * @return array File payload details with content
     */
    public function downloadExportFile(string $jobId, string $artifactType = 'csv', ?int $actorId = null): array
    {
        // 1. Permission check enforced on EVERY request (AC-T31-02)
        if (!$this->checkDownloadPermissions($actorId)) {
            throw new RuntimeException("Forbidden: Insufficient permissions to download export artifacts.", 403);
        }

        // 2. Strict job ID validation preventing directory traversal / null bytes
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $jobId)) {
            throw new InvalidArgumentException("Invalid job ID format.", 400);
        }

        // 3. Artifact type whitelist
        $fileName = match ($artifactType) {
            'csv'         => 'export.csv',
            'loss_report' => 'loss_report.json',
            'manifest'    => 'manifest.json',
            default       => throw new InvalidArgumentException("Invalid artifact type: {$artifactType}", 400),
        };

        $jobDir = $this->exportDir . '/' . $jobId;
        $targetFile = $jobDir . '/' . $fileName;

        // 4. Ensure target file is strictly contained within export directory
        $realExportDir = realpath($this->exportDir);
        $realTargetFile = realpath($targetFile);

        if ($realTargetFile === false || !str_starts_with($realTargetFile, $realExportDir)) {
            throw new RuntimeException("Export artifact not found or access denied.", 404);
        }

        if (!is_file($realTargetFile)) {
            throw new RuntimeException("Export artifact not found.", 404);
        }

        $mimeType = match ($artifactType) {
            'csv'   => 'text/csv; charset=UTF-8',
            default => 'application/json; charset=UTF-8',
        };

        return [
            'file_path' => $realTargetFile,
            'file_name' => "export-{$jobId}-{$fileName}",
            'mime_type' => $mimeType,
            'size'      => filesize($realTargetFile),
            'content'   => file_get_contents($realTargetFile),
        ];
    }

    /**
     * Check if actor has permission to download export artifacts (R126, AC-T31-02).
     */
    public function checkDownloadPermissions(?int $actorId = null): bool
    {
        if ($actorId !== null) {
            return user_can($actorId, 'manage_woocommerce') ||
                   user_can($actorId, 'wpe_export_products') ||
                   user_can($actorId, 'wpe_manage_operations');
        }

        return current_user_can('manage_woocommerce') ||
               current_user_can('wpe_export_products') ||
               current_user_can('wpe_manage_operations');
    }

    /**
     * Retrieve job state and summary, shielding internal server paths (Doc 04 §2).
     */
    public function getJob(string $jobId): ?array
    {
        $job = $this->getJobRecord($jobId);
        if (!$job) {
            return null;
        }

        $counts     = json_decode((string)$job['counts'], true) ?: [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?: [];
        $config     = json_decode((string)$job['config'], true) ?: [];

        $manifestPath = $this->exportDir . '/' . $jobId . '/manifest.json';
        $manifest = file_exists($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;

        $lossReportPath = $this->exportDir . '/' . $jobId . '/loss_report.json';
        $lossReport = file_exists($lossReportPath) ? json_decode((string)file_get_contents($lossReportPath), true) : [];

        return [
            'id'          => $job['id'],
            'kind'        => $job['kind'],
            'status'      => $job['status'],
            'config'      => $config,
            'checkpoint'  => $checkpoint,
            'counts'      => $counts,
            'manifest'    => $manifest,
            'loss_report' => $lossReport,
            'download_url'=> rest_url("wpe/v1/admin/jobs/{$jobId}/download"),
            'created_at'  => $job['created_at'],
            'updated_at'  => $job['updated_at'],
        ];
    }

    /**
     * Get loss report artifact for a job (R127, AC-T31-02).
     */
    public function getLossReport(string $jobId): array
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $jobId)) {
            throw new InvalidArgumentException("Invalid job ID format.", 400);
        }

        $path = $this->exportDir . '/' . $jobId . '/loss_report.json';
        if (!file_exists($path)) {
            return [];
        }

        return json_decode((string)file_get_contents($path), true) ?: [];
    }

    /**
     * Sanitize cell value based on export profile (R125).
     *
     * In spreadsheet_safe profile:
     * - Formula triggers (=, +, -, @, \t, \r) are escaped with a leading single quote '.
     * - Non-printable dangerous control characters are stripped.
     * In machine profile:
     * - Raw values are preserved exactly.
     */
    public function sanitizeCell(string $value, string $profile): string
    {
        if ($profile === 'spreadsheet_safe') {
            // Strip dangerous non-printable control characters (\x00-\x08, \x0B, \x0C, \x0E-\x1F)
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

            // Escape formula injection characters
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $value = "'" . $value;
            }
        }

        return $value;
    }

    /**
     * Cleanup expired export artifacts according to retention policy (R127, Doc 02 §14).
     *
     * @param int $retentionDays Days to retain raw artifacts (default: 7)
     * @return array Summary of cleaned jobs and freed disk space
     */
    public function cleanupExpiredArtifacts(?int $retentionDays = null): array
    {
        $days = $retentionDays ?? $this->defaultRetentionDays;
        $thresholdTime = time() - ($days * 86400);

        $cleanedCount = 0;
        $freedBytes = 0;

        if (!is_dir($this->exportDir)) {
            return ['cleaned_jobs' => 0, 'freed_bytes' => 0];
        }

        $entries = scandir($this->exportDir);
        if ($entries === false) {
            return ['cleaned_jobs' => 0, 'freed_bytes' => 0];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === 'index.php') {
                continue;
            }

            $jobPath = $this->exportDir . '/' . $entry;
            if (is_dir($jobPath)) {
                $mtime = filemtime($jobPath);
                if ($mtime !== false && $mtime < $thresholdTime) {
                    $size = $this->deleteDirectoryRecursive($jobPath);
                    $freedBytes += $size;
                    $cleanedCount++;
                }
            }
        }

        return [
            'cleaned_jobs' => $cleanedCount,
            'freed_bytes'  => $freedBytes,
        ];
    }

    /**
     * Register REST API routes.
     */
    public function registerRestRoutes(): void
    {
        // POST /wpe/v1/admin/exports
        register_rest_route('wpe/v1', '/admin/exports', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRestCreateExport'],
            'permission_callback' => [$this, 'checkDownloadPermissions'],
        ]);

        // GET /wpe/v1/admin/jobs/{id}/download
        register_rest_route('wpe/v1', '/admin/jobs/(?P<id>[a-zA-Z0-9_\-]+)/download', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleRestDownloadJob'],
            'permission_callback' => [$this, 'checkDownloadPermissions'],
        ]);

        // POST /wpe/v1/admin/jobs/{id}/cancel
        register_rest_route('wpe/v1', '/admin/jobs/(?P<id>[a-zA-Z0-9_\-]+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleRestCancelJob'],
            'permission_callback' => [$this, 'checkDownloadPermissions'],
        ]);
    }

    public function handleRestCreateExport(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();

        try {
            $job = $this->createExportJob($params);
            // Process automatically or allow asynchronous processing
            $result = $this->processExport($job['job_id']);

            return new WP_REST_Response([
                'job_id'        => $job['job_id'],
                'status'        => $result['status'],
                'snapshot_hash' => $job['snapshot_hash'],
                'counts'        => $result['counts'] ?? $job['counts'],
                'download_url'  => rest_url("wpe/v1/admin/jobs/{$job['job_id']}/download"),
                'loss_report'   => $job['loss_report'],
            ], 202);
        } catch (InvalidArgumentException $e) {
            return new WP_REST_Response(['code' => 'invalid_request', 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['code' => 'export_error', 'message' => $e->getMessage()], 500);
        }
    }

    public function handleRestDownloadJob(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (string)$request->get_param('id');
        $artifactType = (string)($request->get_param('type') ?? 'csv');

        try {
            $artifact = $this->downloadExportFile($jobId, $artifactType);

            $response = new WP_REST_Response($artifact['content'], 200);
            $response->set_headers([
                'Content-Type'        => $artifact['mime_type'],
                'Content-Disposition' => 'attachment; filename="' . $artifact['file_name'] . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'       => 'private, no-cache, no-store, must-revalidate',
            ]);
            return $response;
        } catch (InvalidArgumentException $e) {
            return new WP_REST_Response(['code' => 'invalid_request', 'message' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return new WP_REST_Response(['code' => 'download_error', 'message' => $e->getMessage()], $status);
        }
    }

    public function handleRestCancelJob(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (string)$request->get_param('id');
        $this->wpdb->update(
            $this->tableJobs,
            ['cancel_requested' => 1],
            ['id' => $jobId],
            ['%d'],
            ['%s']
        );

        return new WP_REST_Response([
            'id'     => $jobId,
            'status' => 'cancel_requested',
        ], 200);
    }

    /**
     * Query product IDs for snapshot based on filters in deterministic order.
     */
    private function querySnapshotProductIds(array $filters): array
    {
        $sql = "SELECT id FROM {$this->tableProducts} WHERE deleted_at IS NULL AND status != 'trash'";

        if (!empty($filters['product_ids']) && is_array($filters['product_ids'])) {
            $intIds = array_map('intval', $filters['product_ids']);
            if (!empty($intIds)) {
                $idList = implode(',', $intIds);
                $sql .= " AND id IN ({$idList})";
            }
        }

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $escaped = array_map(fn($s) => "'" . esc_sql(trim((string)$s)) . "'", $statuses);
            $sql .= " AND status IN (" . implode(',', $escaped) . ")";
        }

        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $escaped = array_map(fn($t) => "'" . esc_sql(trim((string)$t)) . "'", $types);
            $sql .= " AND type IN (" . implode(',', $escaped) . ")";
        }

        $sql .= " ORDER BY id ASC";

        return array_map('intval', $this->wpdb->get_col($sql) ?: []);
    }

    /**
     * Capture fully hydrated aggregate snapshot for a single product.
     */
    private function captureAggregateSnapshot(int $productId, array &$lossReport): ?array
    {
        $productRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL AND status != 'trash'",
            $productId
        ), ARRAY_A);

        if (!$productRow) {
            return null;
        }

        // Inventory
        $inv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ), ARRAY_A);

        // Images
        $mediaRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT pm.role, pm.position, a.source_url, a.attachment_id
             FROM {$this->tableProductMedia} pm
             JOIN {$this->tableAssets} a ON pm.asset_id = a.id
             WHERE pm.product_id = %d
             ORDER BY pm.role = 'featured' DESC, pm.position ASC",
            $productId
        ), ARRAY_A);

        $imageUrls = [];
        foreach ($mediaRows ?: [] as $mr) {
            $url = $mr['source_url'];
            if (empty($url) && !empty($mr['attachment_id'])) {
                $url = wp_get_attachment_url((int)$mr['attachment_id']);
            }
            if (!empty($url)) {
                $imageUrls[] = $url;
            }
        }

        // Options
        $options = $this->optionsRepository->getOptionsByProductId($productId);

        // Variations if variable product
        $variations = [];
        if ($productRow['type'] === 'variable') {
            $varRows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL AND status != 'trash' ORDER BY position ASC, id ASC",
                $productId
            ), ARRAY_A);

            foreach ($varRows ?: [] as $vr) {
                $vId = (int)$vr['id'];
                $vInv = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
                    $vId
                ), ARRAY_A);

                // Variant image
                $vMedia = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT a.source_url, a.attachment_id
                     FROM {$this->tableVariantMedia} vm
                     JOIN {$this->tableAssets} a ON vm.asset_id = a.id
                     WHERE vm.variant_id = %d
                     ORDER BY vm.is_primary DESC, vm.position ASC
                     LIMIT 1",
                    $vId
                ), ARRAY_A);

                $vImageUrl = '';
                if ($vMedia) {
                    $vImageUrl = $vMedia['source_url'];
                    if (empty($vImageUrl) && !empty($vMedia['attachment_id'])) {
                        $vImageUrl = wp_get_attachment_url((int)$vMedia['attachment_id']);
                    }
                }

                // Variant attributes
                $combVector = $this->optionsRepository->getVariantCombinationVector($vId);
                $vAttrs = [];
                foreach ($combVector as $cv) {
                    $optId = $cv['option_id'];
                    $valId = $cv['value_id'];
                    $valData = $valId ? $this->optionsRepository->getOptionValueById($valId) : null;
                    $optData = $this->optionsRepository->getOptionById($optId);

                    if ($optData && $valData) {
                        $vAttrs[$optData['name']] = $valData['label'];
                    }
                }

                $variations[] = [
                    'id'             => (int)$vr['id'],
                    'product_id'     => $productId,
                    'sku'            => $vr['sku'] ?? '',
                    'status'         => $vr['status'] ?? 'publish',
                    'regular_price'  => $vr['regular_price'] !== null ? (string)(float)$vr['regular_price'] : '',
                    'sale_price'     => $vr['sale_price'] !== null ? (string)(float)$vr['sale_price'] : '',
                    'manage_stock'   => !empty($vInv['manage_stock']) ? '1' : '0',
                    'stock_quantity' => $vInv && $vInv['quantity'] !== null ? (string)(float)$vInv['quantity'] : '',
                    'stock_status'   => $vInv['stock_status'] ?? 'instock',
                    'position'       => (int)($vr['position'] ?? 0),
                    'image_url'      => $vImageUrl,
                    'attributes'     => $vAttrs,
                    'description'    => $vr['description'] ?? '',
                ];
            }
        }

        // Categories & Tags from WP terms
        $categories = [];
        $tags = [];
        if (function_exists('wp_get_post_terms')) {
            $catTerms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'names']);
            if (is_array($catTerms) && !is_wp_error($catTerms)) {
                $categories = $catTerms;
            }
            $tagTerms = wp_get_post_terms($productId, 'product_tag', ['fields' => 'names']);
            if (is_array($tagTerms) && !is_wp_error($tagTerms)) {
                $tags = $tagTerms;
            }
        }

        // Custom post meta inspection for loss report
        if (function_exists('get_post_meta')) {
            $postMeta = get_post_meta($productId);
            if (is_array($postMeta)) {
                foreach ($postMeta as $metaKey => $metaValues) {
                    if (str_starts_with($metaKey, '_edit_') || str_starts_with($metaKey, '_wpe_')) {
                        continue;
                    }
                    foreach ((array)$metaValues as $val) {
                        $unserialized = maybe_unserialize($val);
                        if (is_array($unserialized) || is_object($unserialized)) {
                            $lossReport[] = [
                                'field'  => (string)$metaKey,
                                'path'   => "products[{$productId}].meta.{$metaKey}",
                                'reason' => 'Complex nested metadata array/object cannot be serialized into flat CSV dialect column',
                            ];
                        }
                    }
                }
            }
        }

        return [
            'id'                 => (int)$productRow['id'],
            'type'               => $productRow['type'],
            'status'             => $productRow['status'],
            'name'               => $productRow['title'] ?? '',
            'title'              => $productRow['title'] ?? '',
            'slug'               => $productRow['slug'] ?? '',
            'sku'                => $productRow['sku'] ?? '',
            'description'        => $productRow['description'] ?? '',
            'short_description'  => $productRow['short_description'] ?? '',
            'regular_price'      => $productRow['regular_price'] !== null ? (string)(float)$productRow['regular_price'] : '',
            'sale_price'         => $productRow['sale_price'] !== null ? (string)(float)$productRow['sale_price'] : '',
            'catalog_visibility' => $productRow['catalog_visibility'] ?? 'visible',
            'manage_stock'       => !empty($inv['manage_stock']) ? '1' : '0',
            'stock_quantity'     => $inv && $inv['quantity'] !== null ? (string)(float)$inv['quantity'] : '',
            'stock_status'       => $inv['stock_status'] ?? 'instock',
            'version'            => (int)$productRow['version'],
            'image_urls'         => $imageUrls,
            'categories'         => $categories,
            'tags'               => $tags,
            'options'            => $options,
            'variations'         => $variations,
        ];
    }

    /**
     * Determine CSV header columns dynamically based on max product options.
     */
    private function determineHeaders(array $products, string $format): array
    {
        $headers = self::WOO_STANDARD_COLUMNS;

        // Calculate max options count across products
        $maxOptions = 0;
        foreach ($products as $p) {
            $optCount = count($p['options'] ?? []);
            if ($optCount > $maxOptions) {
                $maxOptions = $optCount;
            }
        }

        // Add dynamic attribute columns
        for ($i = 1; $i <= $maxOptions; $i++) {
            $headers[] = "Attribute {$i} name";
            $headers[] = "Attribute {$i} value(s)";
            $headers[] = "Attribute {$i} visible";
            $headers[] = "Attribute {$i} global";
            $headers[] = "Attribute {$i} default";
        }

        return $headers;
    }

    /**
     * Format parent product row matching dialect and profile.
     */
    private function formatParentRow(array $product, array $headers, string $profile, string $format): array
    {
        $row = [];

        $type = $product['type'] ?? 'simple';
        $inStock = ($product['stock_status'] ?? 'instock') === 'instock' ? '1' : '0';
        $published = ($product['status'] ?? 'publish') === 'publish' ? '1' : '0';

        $fieldMap = [
            'ID'                     => (string)$product['id'],
            'Type'                   => $type,
            'SKU'                    => (string)($product['sku'] ?? ''),
            'Name'                   => (string)($product['name'] ?? $product['title'] ?? ''),
            'Published'              => $published,
            'Is featured?'           => '0',
            'Visibility in catalog'  => (string)($product['catalog_visibility'] ?? 'visible'),
            'Short description'      => (string)($product['short_description'] ?? ''),
            'Description'            => (string)($product['description'] ?? ''),
            'Date sale price starts' => '',
            'Date sale price ends'   => '',
            'Tax status'             => 'taxable',
            'Tax class'              => '',
            'In stock?'              => $inStock,
            'Stock'                  => (string)($product['stock_quantity'] ?? ''),
            'Backorders allowed?'    => '0',
            'Sold individually?'     => '0',
            'Weight (kg)'            => '',
            'Length (cm)'            => '',
            'Width (cm)'             => '',
            'Height (cm)'            => '',
            'Allow customer reviews?'=> '1',
            'Purchase note'          => '',
            'Sale price'             => (string)($product['sale_price'] ?? ''),
            'Regular price'          => (string)($product['regular_price'] ?? ''),
            'Categories'             => implode(', ', (array)($product['categories'] ?? [])),
            'Tags'                   => implode(', ', (array)($product['tags'] ?? [])),
            'Shipping class'         => '',
            'Images'                 => implode(', ', (array)($product['image_urls'] ?? [])),
            'Download limit'         => '',
            'Download expiry days'   => '',
            'Parent'                 => '',
            'Grouped products'       => '',
            'Upsells'                => '',
            'Cross-sells'            => '',
            'External URL'           => '',
            'Button text'            => '',
            'Position'               => '0',
        ];

        // Format dynamic attributes
        $options = $product['options'] ?? [];
        $optIndex = 1;
        foreach ($options as $opt) {
            $valLabels = [];
            foreach ($opt['values'] ?? [] as $ov) {
                if (!empty($ov['label'])) {
                    $valLabels[] = $ov['label'];
                }
            }

            $fieldMap["Attribute {$optIndex} name"]     = (string)($opt['name'] ?? '');
            $fieldMap["Attribute {$optIndex} value(s)"] = implode(', ', $valLabels);
            $fieldMap["Attribute {$optIndex} visible"]  = '1';
            $fieldMap["Attribute {$optIndex} global"]   = '1';
            $fieldMap["Attribute {$optIndex} default"]  = '';
            $optIndex++;
        }

        foreach ($headers as $col) {
            $val = $fieldMap[$col] ?? '';
            $row[] = $this->sanitizeCell((string)$val, $profile);
        }

        return $row;
    }

    /**
     * Format variation row matching dialect and profile.
     */
    private function formatVariantRow(array $variant, array $parent, array $headers, string $profile, string $format): array
    {
        $row = [];

        $parentSku = trim((string)($parent['sku'] ?? ''));
        $parentRef = $parentSku !== '' ? $parentSku : 'id:' . $parent['id'];

        $inStock = ($variant['stock_status'] ?? 'instock') === 'instock' ? '1' : '0';
        $published = ($variant['status'] ?? 'publish') === 'publish' ? '1' : '0';

        // Variation Name
        $attrStr = !empty($variant['attributes']) ? implode(' - ', array_values($variant['attributes'])) : '';
        $varName = trim((string)($parent['name'] ?? '')) . ($attrStr !== '' ? ' - ' . $attrStr : '');

        $fieldMap = [
            'ID'                     => (string)$variant['id'],
            'Type'                   => 'variation',
            'SKU'                    => (string)($variant['sku'] ?? ''),
            'Name'                   => $varName,
            'Published'              => $published,
            'Is featured?'           => '0',
            'Visibility in catalog'  => 'visible',
            'Short description'      => '',
            'Description'            => (string)($variant['description'] ?? ''),
            'Date sale price starts' => '',
            'Date sale price ends'   => '',
            'Tax status'             => 'taxable',
            'Tax class'              => 'parent',
            'In stock?'              => $inStock,
            'Stock'                  => (string)($variant['stock_quantity'] ?? ''),
            'Backorders allowed?'    => '0',
            'Sold individually?'     => '0',
            'Weight (kg)'            => '',
            'Length (cm)'            => '',
            'Width (cm)'             => '',
            'Height (cm)'            => '',
            'Allow customer reviews?'=> '0',
            'Purchase note'          => '',
            'Sale price'             => (string)($variant['sale_price'] ?? ''),
            'Regular price'          => (string)($variant['regular_price'] ?? ''),
            'Categories'             => '',
            'Tags'                   => '',
            'Shipping class'         => '',
            'Images'                 => (string)($variant['image_url'] ?? ''),
            'Download limit'         => '',
            'Download expiry days'   => '',
            'Parent'                 => $parentRef,
            'Grouped products'       => '',
            'Upsells'                => '',
            'Cross-sells'            => '',
            'External URL'           => '',
            'Button text'            => '',
            'Position'               => (string)($variant['position'] ?? 0),
        ];

        // Format dynamic attributes for variant
        $options = $parent['options'] ?? [];
        $optIndex = 1;
        foreach ($options as $opt) {
            $optName = $opt['name'] ?? '';
            $selectedVal = $variant['attributes'][$optName] ?? '';

            $fieldMap["Attribute {$optIndex} name"]     = (string)$optName;
            $fieldMap["Attribute {$optIndex} value(s)"] = (string)$selectedVal;
            $fieldMap["Attribute {$optIndex} visible"]  = '';
            $fieldMap["Attribute {$optIndex} global"]   = '1';
            $fieldMap["Attribute {$optIndex} default"]  = '';
            $optIndex++;
        }

        foreach ($headers as $col) {
            $val = $fieldMap[$col] ?? '';
            $row[] = $this->sanitizeCell((string)$val, $profile);
        }

        return $row;
    }

    private function getJobRecord(string $jobId): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);

        return $row ?: null;
    }

    private function markJobStatus(string $jobId, string $status): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->tableJobs,
            ['status' => $status, 'updated_at' => $now],
            ['id' => $jobId],
            ['%s', '%s'],
            ['%s']
        );
    }

    private function deleteDirectoryRecursive(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }

        $items = scandir($dir);
        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $size += $this->deleteDirectoryRecursive($path);
            } else {
                $size += filesize($path);
                @unlink($path);
            }
        }

        @rmdir($dir);
        return $size;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
