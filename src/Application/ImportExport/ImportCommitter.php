<?php

declare(strict_types=1);

namespace WPE\Application\ImportExport;

use wpdb;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\OutboxWorker;
use RuntimeException;
use InvalidArgumentException;

/**
 * CSV Import Committer, Chunk Checkpointer, and Crash/Resume Worker (T30).
 *
 * Implements:
 * - Domain command reuse adhering to native authority (no raw SQL bypassing validation/outbox/events).
 * - Atomic per-product group commits (parent + options + variants); no half-saved products (R121, AC-T30-01).
 * - Worker crash recovery and idempotent resume via persisted database checkpoints (R120, AC-T30-01).
 * - Strict conflict policy (fail vs skip) preventing overwrite of fresh admin edits (R122, AC-T30-02).
 * - Cancellation at commit boundary with accurate partial commit reports (R123).
 * - Actor permissions recheck at commit boundary.
 */
class ImportCommitter
{
    private static ?ImportCommitter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private OutboxWorker $outboxWorker;
    private CsvStager $csvStager;

    private string $tableImportRows;
    private string $tableJobs;
    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableSkuRegistry;

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?OutboxWorker $outboxWorker = null,
        ?CsvStager $csvStager = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableImportRows = "{$prefix}wpe_import_rows";
        $this->tableJobs       = "{$prefix}wpe_jobs";
        $this->tableProducts   = "{$prefix}wpe_products";
        $this->tableVariants   = "{$prefix}wpe_variants";
        $this->tableInventory  = "{$prefix}wpe_inventory";
        $this->tableSkuRegistry= "{$prefix}wpe_sku_registry";

        $this->outboxWorker = $outboxWorker ?? new OutboxWorker($this->wpdb);
        $this->productRepo  = $productRepo  ?? new ProductRepository($this->wpdb, null, $this->outboxWorker);
        $this->optionsRepo  = $optionsRepo  ?? new OptionsRepository($this->wpdb);
        $this->csvStager    = $csvStager    ?? new CsvStager($this->wpdb);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Commit a validated import job group by group with chunk checkpoints (T30).
     *
     * @param string $jobId Job UUID.
     * @param array $options Options (conflict_policy: 'fail'|'skip', max_groups: int, fail_group_key: string).
     * @return array Commit report.
     * @throws RuntimeException
     */
    public function commitJob(string $jobId, array $options = []): array
    {
        // 1. Recheck actor permissions at commit boundary
        $canImport = current_user_can('wpe_import_products') || current_user_can('manage_woocommerce');
        if (!$canImport) {
            throw new RuntimeException('Permission denied: actor lacks import capability at commit time', 403);
        }

        // 2. Fetch job and verify status
        $job = $this->csvStager->getJob($jobId);
        if (!$job) {
            throw new RuntimeException("Job not found: {$jobId}", 404);
        }

        if (!in_array($job['status'], ['validated', 'in_progress'], true)) {
            throw new RuntimeException("Job status is '{$job['status']}'; expected 'validated' or 'in_progress'", 428);
        }

        $config = json_decode((string)$job['config'], true) ?? [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?? [];
        $counts = json_decode((string)$job['counts'], true) ?? [];

        $conflictPolicy = $options['conflict_policy'] ?? $config['conflict_policy'] ?? 'fail';
        $maxGroups = isset($options['max_groups']) ? (int)$options['max_groups'] : null;
        $failGroupKey = $options['fail_group_key'] ?? null; // Testing hook for simulated crash/failure

        // Mark job in_progress
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->tableJobs,
            [
                'status'     => 'in_progress',
                'updated_at' => $now,
            ],
            ['id' => $jobId],
            ['%s', '%s'],
            ['%s']
        );

        // Initialize / retrieve counts
        $groupsCommitted = (int)($counts['groups_committed'] ?? 0);
        $groupsFailed    = (int)($counts['groups_failed'] ?? 0);
        $groupsSkipped   = (int)($counts['groups_skipped'] ?? 0);
        $rowsCommitted   = (int)($counts['rows_committed'] ?? 0);
        $rowsFailed      = (int)($counts['rows_failed'] ?? 0);
        $rowsSkipped     = (int)($counts['rows_skipped'] ?? 0);

        // 3. Find distinct groups that have pending rows (status != 'committed' and status != 'skipped')
        $groupRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT DISTINCT group_key, MIN(`row_number`) as min_row
             FROM {$this->tableImportRows}
             WHERE job_id = %s AND status NOT IN ('committed', 'skipped')
             GROUP BY group_key
             ORDER BY min_row ASC",
            $jobId
        ), ARRAY_A);

        $groupsProcessedInThisRun = 0;
        $wasCancelled = false;

        foreach ($groupRows as $g) {
            $groupKey = (string)$g['group_key'];

            // 4. Check cancellation boundary (R123)
            $cancelRequested = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT cancel_requested FROM {$this->tableJobs} WHERE id = %s",
                $jobId
            ));

            if ($cancelRequested === 1) {
                $wasCancelled = true;
                break;
            }

            // Optional max_groups threshold (for batch worker or simulated crash)
            if ($maxGroups !== null && $groupsProcessedInThisRun >= $maxGroups) {
                break;
            }

            // Fetch all rows for this product group
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM {$this->tableImportRows}
                 WHERE job_id = %s AND group_key = %s
                 ORDER BY `row_number` ASC",
                $jobId,
                $groupKey
            ), ARRAY_A);

            if (empty($rows)) {
                continue;
            }

            // Process product group atomically
            $groupResult = $this->processProductGroup(
                $jobId,
                $groupKey,
                $rows,
                $conflictPolicy,
                $config,
                $checkpoint,
                $failGroupKey
            );

            $groupsProcessedInThisRun++;

            if ($groupResult['status'] === 'committed') {
                $groupsCommitted++;
                $rowsCommitted += count($rows);
            } elseif ($groupResult['status'] === 'skipped') {
                $groupsSkipped++;
                $rowsSkipped += count($rows);
            } else {
                $groupsFailed++;
                $rowsFailed += count($rows);
            }

            // Persist checkpoint after each group boundary (R120)
            $checkpoint['last_committed_group_key'] = $groupKey;
            $checkpoint['groups_committed'] = $groupsCommitted;

            $counts['groups_committed'] = $groupsCommitted;
            $counts['groups_failed']    = $groupsFailed;
            $counts['groups_skipped']   = $groupsSkipped;
            $counts['rows_committed']   = $rowsCommitted;
            $counts['rows_failed']      = $rowsFailed;
            $counts['rows_skipped']     = $rowsSkipped;

            $this->wpdb->update(
                $this->tableJobs,
                [
                    'checkpoint' => wp_json_encode($checkpoint),
                    'counts'     => wp_json_encode($counts),
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ],
                ['id' => $jobId],
                ['%s', '%s', '%s'],
                ['%s']
            );
        }

        // 5. Finalize Job Status
        $now = gmdate('Y-m-d H:i:s.u');
        if ($wasCancelled) {
            $finalStatus = 'cancelled';
            // Mark remaining uncommitted rows as cancelled
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableImportRows}
                 SET status = 'cancelled'
                 WHERE job_id = %s AND status NOT IN ('committed', 'skipped', 'failed')",
                $jobId
            ));
        } else {
            // Check if any unprocessed rows remain
            $remaining = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tableImportRows}
                 WHERE job_id = %s AND status NOT IN ('committed', 'skipped', 'failed')",
                $jobId
            ));

            if ($remaining > 0) {
                // Stopped before completing all groups (e.g. max_groups limit)
                $finalStatus = 'in_progress';
            } else {
                $finalStatus = ($groupsCommitted === 0 && $groupsFailed > 0) ? 'failed' : 'completed';
            }
        }

        $this->wpdb->update(
            $this->tableJobs,
            [
                'status'     => $finalStatus,
                'counts'     => wp_json_encode($counts),
                'checkpoint' => wp_json_encode($checkpoint),
                'updated_at' => $now,
            ],
            ['id' => $jobId],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );

        return [
            'job_id'           => $jobId,
            'status'           => $finalStatus,
            'groups_committed' => $groupsCommitted,
            'groups_failed'    => $groupsFailed,
            'groups_skipped'   => $groupsSkipped,
            'rows_committed'   => $rowsCommitted,
            'rows_failed'      => $rowsFailed,
            'rows_skipped'     => $rowsSkipped,
            'counts'           => $counts,
            'checkpoint'       => $checkpoint,
        ];
    }

    /**
     * Resume an in-progress or paused import job from its last persisted checkpoint (R120).
     */
    public function resumeJob(string $jobId, array $options = []): array
    {
        return $this->commitJob($jobId, $options);
    }

    /**
     * Request cancellation of an in-progress import job at the next commit boundary (R123).
     */
    public function requestCancel(string $jobId): bool
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $res = $this->wpdb->update(
            $this->tableJobs,
            [
                'cancel_requested' => 1,
                'updated_at'       => $now,
            ],
            ['id' => $jobId],
            ['%d', '%s'],
            ['%s']
        );

        return $res !== false;
    }

    /**
     * Process a single product group atomically (parent + options + variants) (R121).
     *
     * Invariant: If any row or command fails, the entire group rolls back completely (no half-save).
     */
    private function processProductGroup(
        string $jobId,
        string $groupKey,
        array $rows,
        string $conflictPolicy,
        array $config,
        array $checkpoint,
        ?string $failGroupKey = null
    ): array {
        $format = $config['format'] ?? 'woo';

        // 1. Check if any row in this group was marked invalid during dry-run validation
        foreach ($rows as $r) {
            if ($r['status'] === 'invalid') {
                $this->markGroupRows($jobId, $groupKey, 'failed', 'Row validation failed during dry-run');
                return ['status' => 'failed', 'error' => 'One or more rows in group invalid'];
            }
        }

        // 2. Separate parent row and variation rows
        $parentRow = null;
        $variationRows = [];

        foreach ($rows as $r) {
            $parsed = json_decode((string)$r['raw_fields'], true) ?? [];
            $fields = $parsed['fields'] ?? [];
            $type = strtolower(trim($fields['Type'] ?? ''));
            $parent = trim($fields['Parent'] ?? '');

            if ($format === 'shopify') {
                // Shopify: first row with non-empty Title or parent row is parent
                if ($parentRow === null) {
                    $parentRow = $r;
                } else {
                    $variationRows[] = $r;
                }
            } else {
                // WooCommerce
                if ($type !== 'variation' && $parent === '') {
                    $parentRow = $r;
                } else {
                    $variationRows[] = $r;
                }
            }
        }

        // If no parent row in file, check if parent exists in database
        $targetProductId = null;
        if ($parentRow !== null) {
            $parentFields = (json_decode((string)$parentRow['raw_fields'], true) ?? [])['fields'] ?? [];
            $parentSku = trim($parentFields['SKU'] ?? '');
            if ($parentSku !== '') {
                $existing = $this->productRepo->getBySku($parentSku);
                if ($existing) {
                    $targetProductId = (int)$existing['id'];
                }
            }
            if ($targetProductId === null && !empty($parentFields['ID'])) {
                $existingById = $this->productRepo->getById((int)$parentFields['ID']);
                if ($existingById) {
                    $targetProductId = (int)$existingById['id'];
                }
            }
        } else {
            // Variations targeting existing DB parent
            $firstVarFields = (json_decode((string)$variationRows[0]['raw_fields'], true) ?? [])['fields'] ?? [];
            $parentRef = trim($firstVarFields['Parent'] ?? $groupKey);
            if (str_starts_with($parentRef, 'id:')) {
                $targetProductId = (int)substr($parentRef, 3);
            } else {
                $existing = $this->productRepo->getBySku($parentRef);
                if ($existing) {
                    $targetProductId = (int)$existing['id'];
                }
            }

            if ($targetProductId === null) {
                $this->markGroupRows($jobId, $groupKey, 'failed', "Parent product '{$parentRef}' not found in catalog");
                return ['status' => 'failed', 'error' => "Parent not found"];
            }
        }

        // 3. Conflict Check (R122, AC-T30-02)
        // If product already exists in catalog, verify it was not modified concurrently after validation
        if ($targetProductId !== null) {
            $dbProduct = $this->productRepo->getById($targetProductId);
            if ($dbProduct) {
                $validatedAt = $checkpoint['validated_at'] ?? null;
                $dbUpdatedAt = $dbProduct['updated_at'] ?? null;

                $mapped = json_decode((string)($parentRow['mapped_payload'] ?? '{}'), true) ?? [];
                $existingVersion = isset($mapped['existing_version']) ? (int)$mapped['existing_version'] : null;

                $conflict = false;
                if ($existingVersion !== null && (int)$dbProduct['version'] !== $existingVersion) {
                    $conflict = true;
                } elseif ($validatedAt !== null && $dbUpdatedAt !== null && $dbUpdatedAt > $validatedAt) {
                    $conflict = true;
                }

                if ($conflict) {
                    if ($conflictPolicy === 'skip') {
                        $this->markGroupRows($jobId, $groupKey, 'skipped', 'Skipped due to concurrent admin edit after validation');
                        return ['status' => 'skipped', 'reason' => 'conflict_skip'];
                    } else {
                        // 'fail'
                        $this->markGroupRows($jobId, $groupKey, 'failed', 'Conflict: product modified by another actor after validation');
                        return ['status' => 'failed', 'reason' => 'conflict_fail'];
                    }
                }
            }
        }

        // 4. Atomic Execution inside Database Transaction (R121)
        $this->wpdb->query("START TRANSACTION");

        try {
            // Testing simulator hook for crash/failure injection
            if ($failGroupKey !== null && $groupKey === $failGroupKey) {
                throw new RuntimeException("Simulated atomic failure for group {$groupKey}");
            }

            $productId = $targetProductId;
            $createdVariantIds = [];

            if ($parentRow !== null) {
                $pData = $this->mapParentRowToDomainData($parentRow, !empty($variationRows), $format);

                // Add variations array if variable product
                if (!empty($variationRows)) {
                    $pData['type'] = 'variable';
                    $pData['variations'] = [];
                    foreach ($variationRows as $vRow) {
                        $pData['variations'][] = $this->mapVariationRowToDomainData($vRow, $format);
                    }
                }

                if ($productId !== null) {
                    // Update existing product
                    $updated = $this->productRepo->update($productId, $pData);
                    $productId = (int)$updated['id'];
                } else {
                    // Create new product
                    $created = $this->productRepo->create($pData);
                    $productId = (int)$created['id'];
                    if (!empty($created['variations'])) {
                        foreach ($created['variations'] as $v) {
                            $createdVariantIds[] = (int)$v['id'];
                        }
                    }
                }

                // Setup options and option values in OptionsRepository if options are defined
                $this->syncProductOptions($productId, $parentRow, $variationRows, $format);
            } else {
                // Adding variations to existing product
                $vList = [];
                foreach ($variationRows as $vRow) {
                    $vList[] = $this->mapVariationRowToDomainData($vRow, $format);
                }
                $this->productRepo->update($productId, ['variations' => $vList]);
            }

            // Commit transaction
            $this->wpdb->query("COMMIT");

            // Update row statuses in wpe_import_rows to 'committed'
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableImportRows}
                 SET status = 'committed', target_id = %d
                 WHERE job_id = %s AND group_key = %s",
                $productId,
                $jobId,
                $groupKey
            ));

            return ['status' => 'committed', 'product_id' => $productId];
        } catch (\Throwable $e) {
            // Rollback entire group on any failure (R121, AC-T30-01)
            $this->wpdb->query("ROLLBACK");

            $this->markGroupRows($jobId, $groupKey, 'failed', $e->getMessage());

            return [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Mark all rows in a group with a status and error message.
     */
    private function markGroupRows(string $jobId, string $groupKey, string $status, string $message): void
    {
        $errJson = wp_json_encode([[
            'group_key' => $groupKey,
            'code'      => ($status === 'skipped') ? 'conflict_skipped' : 'group_commit_error',
            'message'   => $message,
        ]]);

        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableImportRows}
             SET status = %s, errors = %s
             WHERE job_id = %s AND group_key = %s",
            $status,
            $errJson,
            $jobId,
            $groupKey
        ));
    }

    /**
     * Map parent CSV row to domain product aggregate data array.
     */
    private function mapParentRowToDomainData(array $row, bool $hasVariations, string $format): array
    {
        $parsed = json_decode((string)$row['raw_fields'], true) ?? [];
        $fields = $parsed['fields'] ?? [];

        $title = $fields['Name'] ?? $fields['Title'] ?? 'Imported Product';
        $sku   = !empty($fields['SKU']) ? trim($fields['SKU']) : null;
        $desc  = $fields['Description'] ?? '';
        $short = $fields['Short description'] ?? '';

        $regPrice  = !empty($fields['Regular price']) ? trim($fields['Regular price']) : (!empty($fields['Price']) ? trim($fields['Price']) : null);
        $salePrice = !empty($fields['Sale price']) ? trim($fields['Sale price']) : null;

        $type = $hasVariations ? 'variable' : strtolower(trim($fields['Type'] ?? 'simple'));
        if ($type === '') {
            $type = 'simple';
        }

        $stock = !empty($fields['Stock']) ? trim($fields['Stock']) : null;
        $inStock = strtolower(trim($fields['In stock?'] ?? '1'));
        $stockStatus = ($inStock === '0' || $inStock === 'outofstock') ? 'outofstock' : 'instock';

        $published = trim($fields['Published'] ?? '1');
        $status = ($published === '0' || $published === 'draft') ? 'draft' : 'publish';

        return [
            'type'               => $type,
            'title'              => $title,
            'name'               => $title,
            'sku'                => $sku,
            'description'        => $desc,
            'short_description'  => $short,
            'regular_price'      => $regPrice,
            'sale_price'         => $salePrice,
            'status'             => $status,
            'catalog_visibility' => 'visible',
            'manage_stock'       => $stock !== null ? 1 : 0,
            'stock_quantity'     => $stock,
            'stock_status'       => $stockStatus,
            'virtual'            => 0,
            'downloadable'       => 0,
        ];
    }

    /**
     * Map variation CSV row to variation data array.
     */
    private function mapVariationRowToDomainData(array $row, string $format): array
    {
        $parsed = json_decode((string)$row['raw_fields'], true) ?? [];
        $fields = $parsed['fields'] ?? [];

        $sku = !empty($fields['SKU']) ? trim($fields['SKU']) : (!empty($fields['Variant SKU']) ? trim($fields['Variant SKU']) : null);
        $regPrice = !empty($fields['Regular price']) ? trim($fields['Regular price']) : (!empty($fields['Variant Price']) ? trim($fields['Variant Price']) : (!empty($fields['Price']) ? trim($fields['Price']) : null));
        $salePrice = !empty($fields['Sale price']) ? trim($fields['Sale price']) : null;

        $attributes = $this->extractAttributesFromRow($fields, $format);

        return [
            'sku'           => $sku,
            'regular_price' => $regPrice,
            'sale_price'    => $salePrice,
            'attributes'    => $attributes,
            'status'        => 'publish',
            'description'   => $fields['Description'] ?? '',
        ];
    }

    /**
     * Extract attributes from row fields based on dialect.
     */
    private function extractAttributesFromRow(array $fields, string $format): array
    {
        $attrs = [];

        if ($format === 'shopify') {
            for ($i = 1; $i <= 3; $i++) {
                $nameKey = "Option{$i} name";
                $valKey  = "Option{$i} value";
                if (!empty($fields[$nameKey]) && !empty($fields[$valKey])) {
                    $attrs[trim($fields[$nameKey])] = trim($fields[$valKey]);
                }
            }
        } else {
            // WooCommerce: Attribute 1 name, Attribute 1 value(s)
            for ($i = 1; $i <= 10; $i++) {
                $nameKey = "Attribute {$i} name";
                $valKey  = "Attribute {$i} value(s)";
                if (!empty($fields[$nameKey]) && !empty($fields[$valKey])) {
                    $slug = sanitize_title(trim($fields[$nameKey]));
                    $attrs["pa_{$slug}"] = trim($fields[$valKey]);
                }
            }
        }

        return $attrs;
    }

    /**
     * Sync product options and option values in OptionsRepository for variable products.
     */
    private function syncProductOptions(int $productId, array $parentRow, array $variationRows, string $format): void
    {
        $parsedParent = json_decode((string)$parentRow['raw_fields'], true) ?? [];
        $pFields = $parsedParent['fields'] ?? [];

        $discoveredOptions = []; // option_name => [val1, val2]

        if ($format === 'shopify') {
            for ($i = 1; $i <= 3; $i++) {
                $nameKey = "Option{$i} name";
                if (!empty($pFields[$nameKey])) {
                    $discoveredOptions[trim($pFields[$nameKey])] = [];
                }
            }
        } else {
            for ($i = 1; $i <= 10; $i++) {
                $nameKey = "Attribute {$i} name";
                $valKey  = "Attribute {$i} value(s)";
                if (!empty($pFields[$nameKey])) {
                    $optName = trim($pFields[$nameKey]);
                    $rawVals = !empty($pFields[$valKey]) ? explode(',', $pFields[$valKey]) : [];
                    $discoveredOptions[$optName] = array_map('trim', $rawVals);
                }
            }
        }

        // Collect any values from variation rows
        foreach ($variationRows as $vRow) {
            $vFields = (json_decode((string)$vRow['raw_fields'], true) ?? [])['fields'] ?? [];
            if ($format === 'shopify') {
                for ($i = 1; $i <= 3; $i++) {
                    $nameKey = "Option{$i} name";
                    $valKey  = "Option{$i} value";
                    if (!empty($vFields[$nameKey]) && !empty($vFields[$valKey])) {
                        $discoveredOptions[trim($vFields[$nameKey])][] = trim($vFields[$valKey]);
                    }
                }
            } else {
                for ($i = 1; $i <= 10; $i++) {
                    $nameKey = "Attribute {$i} name";
                    $valKey  = "Attribute {$i} value(s)";
                    if (!empty($vFields[$nameKey]) && !empty($vFields[$valKey])) {
                        $discoveredOptions[trim($vFields[$nameKey])][] = trim($vFields[$valKey]);
                    }
                }
            }
        }

        $pos = 1;
        foreach ($discoveredOptions as $optName => $vals) {
            $vals = array_unique(array_filter($vals));
            $optSlug = sanitize_title($optName);
            $option = $this->optionsRepo->createOption($productId, [
                'name'     => $optName,
                'key'      => "pa_{$optSlug}",
                'position' => $pos++,
            ]);

            $vPos = 1;
            foreach ($vals as $valLabel) {
                $this->optionsRepo->createOptionValue((int)$option['id'], [
                    'label'     => $valLabel,
                    'value_key' => sanitize_title($valLabel),
                    'position'  => $vPos++,
                ]);
            }
        }
    }
}
