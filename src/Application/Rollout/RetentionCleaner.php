<?php

declare(strict_types=1);

namespace WPE\Application\Rollout;

use RuntimeException;
use InvalidArgumentException;
use WPE\Application\Migration\RollbackRunner;
use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Migration\LegacyCapture;

/**
 * RetentionCleaner — Retention, garbage collection, and legacy cleanup orchestrator.
 *
 * Implements requirements:
 * - R185: GC strictly preserves shared references, post shells, order items, shared media, and stock operations.
 * - R186: Resumable chunked cleanup with database checkpoints in wpe_jobs.
 * - R187: Cleanup strictly gated on verified restore drill pass and expired rollback window.
 * - R188: Rollback after mirror stopped requires write barrier and full re-projection of current native data.
 * - R206: Cleanup requires an explicit product scope; an empty scope never means the whole catalog.
 * - R207: Cleanup requires a native row and a sealed full_native authority for every targeted product.
 * - R208: Cleanup is refused until the operator-configured rollback window (default 30 days) has elapsed.
 * - R209: The restore drill gate is recorded per cohort; there is no global shortcut.
 * - AC-T46-01: Dry-run planner reports IDs, bytes, rollback impact while protecting shared assets.
 * - AC-T46-02: Cleanup is strictly manual / operator-triggered; never automatic on upgrade/activate/deactivate.
 * - AC-T46-03: Reprojection with write barrier before authority switch when mirror was stopped.
 */
class RetentionCleaner
{
    public const REDUNDANT_OWNED_POSTMETA = [
        '_price',
        '_regular_price',
        '_sale_price',
        '_stock',
        '_stock_status',
        '_manage_stock',
        '_sku',
        '_weight',
    ];

    /** Rollback window length in days; operators change it with the wpe_rollback_window_days option (R208). */
    public const DEFAULT_ROLLBACK_WINDOW_DAYS = 30;
    public const ROLLBACK_WINDOW_OPTION = 'wpe_rollback_window_days';

    private \wpdb $wpdb;
    private RollbackRunner $rollbackRunner;
    private LegacyMirror $mirror;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableJobs;
    private string $tableControl;
    private string $tableAudit;
    private string $tableStockOperations;
    private string $tableAssets;

    public function __construct(
        ?\wpdb $wpdb = null,
        ?RollbackRunner $rollbackRunner = null,
        ?LegacyMirror $mirror = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];

        $prefix = $this->wpdb->prefix;
        $this->tableProducts        = "{$prefix}wpe_products";
        $this->tableVariants        = "{$prefix}wpe_variants";
        $this->tableInventory       = "{$prefix}wpe_inventory";
        $this->tableObjectState     = "{$prefix}wpe_object_state";
        $this->tableJobs            = "{$prefix}wpe_jobs";
        $this->tableControl         = "{$prefix}wpe_control";
        $this->tableAudit           = "{$prefix}wpe_audit";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";
        $this->tableAssets          = "{$prefix}wpe_assets";

        $capture = new LegacyCapture($this->wpdb);
        $this->mirror = $mirror ?? new LegacyMirror($this->wpdb, $capture);
        $this->rollbackRunner = $rollbackRunner ?? new RollbackRunner($this->wpdb, $this->mirror, $capture);
    }

    /**
     * Dry-run cleanup planner (AC-T46-01).
     * Calculates candidate items, bytes, rollback impact, and protected items without mutating DB.
     */
    public function planCleanup(string $cohortId, array $options = []): array
    {
        $productIds = $this->normalizeProductIds($options['product_ids'] ?? []);
        // R206: an empty scope plans nothing instead of silently meaning "the whole catalog".
        $whereSql = empty($productIds) ? "1=0" : "post_id IN (" . implode(',', $productIds) . ")";

        $escapedKeys = "'" . implode("','", self::REDUNDANT_OWNED_POSTMETA) . "'";

        // 1. Count redundant owned postmeta rows
        $redundantCount = (int)$this->wpdb->get_var(
            "SELECT count(*) FROM {$this->wpdb->postmeta} WHERE {$whereSql} AND meta_key IN ({$escapedKeys})"
        );

        // Estimate bytes (avg ~128 bytes per postmeta row + index)
        $estimatedBytes = $redundantCount * 128;

        // 2. Count protected items (R185)
        $protectedShells = 0;
        $protectedOrderRefs = 0;
        $protectedMedia = 0;
        $protectedStockOps = 0;

        if (!empty($productIds)) {
            $inIds = implode(',', $productIds);
            $protectedShells = (int)$this->wpdb->get_var(
                "SELECT count(*) FROM {$this->wpdb->posts} WHERE ID IN ({$inIds}) AND post_type IN ('product', 'product_variation')"
            );
            $protectedStockOps = (int)$this->wpdb->get_var(
                "SELECT count(*) FROM {$this->tableStockOperations} WHERE owner_id IN ({$inIds})"
            );
        }

        $tableOrderItems = "{$this->wpdb->prefix}woocommerce_order_items";
        $orderItemTableExists = $this->wpdb->get_var("SHOW TABLES LIKE '{$tableOrderItems}'") === $tableOrderItems;
        if ($orderItemTableExists) {
            $protectedOrderRefs = (int)$this->wpdb->get_var("SELECT count(*) FROM {$tableOrderItems}");
        }

        $protectedMedia = (int)$this->wpdb->get_var("SELECT count(*) FROM {$this->tableAssets}");

        // 3. Check restore drill gate (R187) and every other cleanup guard (R206-R209)
        $restoreGate = $this->checkRestoreDrillGate($cohortId);
        $blockers = $this->cleanupBlockers($cohortId, $productIds);

        return [
            'cohort_id'               => $cohortId,
            'dry_run'                 => true,
            'target_products'         => count($productIds),
            'target_product_ids'      => $productIds,
            'blockers'                => $blockers,
            'rollback_window_days'    => $this->getRollbackWindowDays(),
            'redundant_postmeta_rows' => $redundantCount,
            'estimated_bytes_saved'   => $estimatedBytes,
            'restore_drill_gate'      => $restoreGate,
            'protected_items'         => [
                'post_shells_preserved'       => $protectedShells,
                'order_references_preserved'  => $protectedOrderRefs,
                'media_assets_preserved'      => $protectedMedia,
                'stock_operations_preserved'  => $protectedStockOps,
            ],
            'rollback_impact'         => [
                'notice'             => 'Cleaning redundant postmeta stops real-time legacy mirror.',
                'requirement'        => 'Future rollback will strictly require reprojectBeforeRollback() with write barrier before authority switch (AC-T46-03).',
                'safe_to_clean'      => empty($blockers),
            ],
        ];
    }

    /**
     * Executes cleanup in resumable chunks (R185, R186, R187).
     *
     * @throws RuntimeException if restore gate not satisfied (R187)
     */
    public function executeCleanup(string $cohortId, array $options = []): array
    {
        $productIds = $this->normalizeProductIds($options['product_ids'] ?? []);

        // 1. Every guard must pass before anything is touched (R187, R206-R209)
        $this->assertCleanupAllowed($cohortId, $productIds);

        $jobId = $options['job_id'] ?? wp_generate_uuid4();
        $chunkSize = max(1, (int)($options['chunk_size'] ?? 50));
        $faultAtChunk = $options['fault_at_chunk'] ?? null;
        $now = gmdate('Y-m-d H:i:s.u');

        $totalProducts = count($productIds);
        $totalChunks = (int)ceil($totalProducts / $chunkSize);

        // Initialize or fetch job
        $existingJob = $this->getJob($jobId);
        $startChunk = 0;
        $cleanedRowsTotal = 0;

        if ($existingJob) {
            $cp = json_decode((string)$existingJob['checkpoint'], true) ?? [];
            $startChunk = (int)($cp['chunk_index'] ?? 0);
            $cleanedRowsTotal = (int)($cp['cleaned_rows'] ?? 0);
        } else {
            $this->wpdb->insert($this->tableJobs, [
                'id'               => $jobId,
                'kind'             => 'cleanup',
                'status'           => 'running',
                'config'           => (string)wp_json_encode(['cohort_id' => $cohortId, 'chunk_size' => $chunkSize, 'product_ids' => $productIds]),
                'checkpoint'       => (string)wp_json_encode(['chunk_index' => 0, 'cleaned_rows' => 0]),
                'counts'           => (string)wp_json_encode(['total' => $totalProducts, 'processed' => 0]),
                'lease_owner'      => 'cleaner_' . getmypid(),
                'lease_until'      => gmdate('Y-m-d H:i:s.u', time() + 300),
                'cancel_requested' => 0,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        $escapedKeys = "'" . implode("','", self::REDUNDANT_OWNED_POSTMETA) . "'";

        // Execute chunks
        for ($c = $startChunk; $c < $totalChunks; $c++) {
            if ($faultAtChunk !== null && $c === (int)$faultAtChunk) {
                // Record checkpoint before simulated crash
                $this->wpdb->update($this->tableJobs, [
                    'status'     => 'failed',
                    'error_code' => 'simulated_cleanup_fault',
                    'checkpoint' => (string)wp_json_encode(['chunk_index' => $c, 'cleaned_rows' => $cleanedRowsTotal]),
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ], ['id' => $jobId]);
                throw new RuntimeException("Simulated crash at cleanup chunk {$c}");
            }

            $offset = $c * $chunkSize;
            $chunkProductIds = array_slice($productIds, $offset, $chunkSize);
            if (!empty($chunkProductIds)) {
                $inChunk = implode(',', $chunkProductIds);
                // Prune only redundant owned postmeta rows; NEVER delete posts, attachments, or order items (R185)
                $deleted = $this->wpdb->query(
                    "DELETE FROM {$this->wpdb->postmeta} WHERE post_id IN ({$inChunk}) AND meta_key IN ({$escapedKeys})"
                );
                $cleanedRowsTotal += (int)$deleted;
            }

            // Persist checkpoint in wpe_jobs (R186)
            $this->wpdb->update($this->tableJobs, [
                'checkpoint' => (string)wp_json_encode([
                    'chunk_index'  => $c + 1,
                    'cleaned_rows' => $cleanedRowsTotal,
                ]),
                'counts'     => (string)wp_json_encode([
                    'total'     => $totalProducts,
                    'processed' => min($totalProducts, ($c + 1) * $chunkSize),
                ]),
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
            ], ['id' => $jobId]);
        }

        // Mark completed
        $this->wpdb->update($this->tableJobs, [
            'status'     => 'completed',
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['id' => $jobId]);

        // Record in control that legacy mirror is now stopped for this cohort
        $this->wpdb->replace($this->tableControl, [
            'key'        => "mirror_stopped_{$cohortId}",
            'value'      => (string)wp_json_encode([
                'cohort_id'        => $cohortId,
                'mirror_stopped'   => true,
                'postmeta_cleaned' => true,
                'product_ids'      => $productIds,
                'stopped_at'       => gmdate('Y-m-d H:i:s.u'),
            ]),
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);

        $this->logAudit('cleanup_completed', 0, [
            'cohort_id'    => $cohortId,
            'cleaned_rows' => $cleanedRowsTotal,
            'products'     => $totalProducts,
        ]);

        return [
            'job_id'       => $jobId,
            'status'       => 'completed',
            'cohort_id'    => $cohortId,
            'cleaned_rows' => $cleanedRowsTotal,
            'chunks_run'   => $totalChunks - $startChunk,
        ];
    }

    /**
     * Resumes cleanup from the last checkpoint after failure or interruption (R186).
     */
    public function resumeCleanup(string $jobId, array $options = []): array
    {
        $job = $this->getJob($jobId);
        if (!$job) {
            throw new InvalidArgumentException("Cleanup job not found: {$jobId}");
        }

        if ($job['status'] === 'completed') {
            return ['job_id' => $jobId, 'status' => 'already_completed'];
        }

        $config = json_decode((string)$job['config'], true) ?? [];
        $cohortId = (string)($config['cohort_id'] ?? 'all');

        $options['job_id'] = $jobId;
        $options['chunk_size'] = $config['chunk_size'] ?? 50;
        // The scope is fixed when the job is created; a resume can never widen it (R206).
        $options['product_ids'] = $config['product_ids'] ?? [];

        return $this->executeCleanup($cohortId, $options);
    }

    /**
     * Reprojects current native catalog into legacy storage with write barrier before rollback (R188, AC-T46-03).
     */
    public function reprojectBeforeRollback(string $cohortId, array $options = []): array
    {
        // 0. Scope: explicit product_ids, or the exact set recorded when the mirror was stopped (R206)
        $productIds = $this->normalizeProductIds($options['product_ids'] ?? []);
        if (empty($productIds)) {
            $stopped = $this->getMirrorStoppedRecord($cohortId);
            $productIds = $this->normalizeProductIds($stopped['product_ids'] ?? []);
        }
        if (empty($productIds)) {
            throw new RuntimeException("Reprojection Scope Violation (R206): reprojectBeforeRollback requires explicit product_ids or a recorded mirror_stopped scope; an empty scope is never the whole catalog.");
        }
        $missingNative = $this->productsWithoutNativeRow($productIds);
        if (!empty($missingNative)) {
            throw new RuntimeException("Reprojection Native Row Violation (R207): no native row for product(s) " . implode(',', $missingNative) . ".");
        }

        // 1. Acquire write barrier to freeze mutations (R188, AC-T46-03)
        $barrierId = $this->rollbackRunner->acquireWriteBarrier($productIds);

        $now = gmdate('Y-m-d H:i:s.u');
        $reprojectedCount = 0;

        try {
            // 2. Fetch current native products and reproject to legacy postmeta
            $whereSql = "id IN (" . implode(',', $productIds) . ")";

            $nativeProducts = $this->wpdb->get_results(
                "SELECT id, sku, regular_price, sale_price FROM {$this->tableProducts} WHERE {$whereSql}",
                ARRAY_A
            );

            foreach ($nativeProducts as $p) {
                $pid = (int)$p['id'];
                $regPrice = sprintf('%.2f', (float)$p['regular_price']);
                $price = $p['sale_price'] !== null ? sprintf('%.2f', (float)$p['sale_price']) : $regPrice;

                // Update wp_postmeta
                update_post_meta($pid, '_price', $price);
                update_post_meta($pid, '_regular_price', $regPrice);
                if ($p['sale_price'] !== null) {
                    update_post_meta($pid, '_sale_price', sprintf('%.2f', (float)$p['sale_price']));
                }
                update_post_meta($pid, '_sku', $p['sku']);

                // Fetch current native stock and reproject
                $inv = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT quantity, stock_status, manage_stock FROM {$this->tableInventory} WHERE owner_id = %d",
                    $pid
                ), ARRAY_A);

                if ($inv) {
                    update_post_meta($pid, '_stock', (string)(float)$inv['quantity']);
                    update_post_meta($pid, '_stock_status', $inv['stock_status']);
                    update_post_meta($pid, '_manage_stock', !empty($inv['manage_stock']) ? 'yes' : 'no');
                }

                $reprojectedCount++;
            }

            // 3. Record mirror restoration in wpe_control
            $this->wpdb->replace($this->tableControl, [
                'key'        => "mirror_restored_{$cohortId}",
                'value'      => (string)wp_json_encode([
                    'cohort_id'          => $cohortId,
                    'reprojected_count'  => $reprojectedCount,
                    'product_ids'        => $productIds,
                    'barrier_id'         => $barrierId,
                    'mirror_ready'       => true,
                    'restored_at'        => $now,
                ]),
                'version'    => 1,
                'updated_at' => $now,
            ]);

            $this->logAudit('reproject_before_rollback_complete', 0, [
                'cohort_id'   => $cohortId,
                'barrier_id'  => $barrierId,
                'reprojected' => $reprojectedCount,
            ]);
        } catch (\Throwable $e) {
            $this->rollbackRunner->releaseWriteBarrier();
            throw $e;
        }

        return [
            'barrier_id'        => $barrierId,
            'reprojected_count' => $reprojectedCount,
            'mirror_ready'      => true,
            'safe_to_switch'    => true,
        ];
    }

    /**
     * Checks if verified restore drill gate is satisfied (R187).
     */
    public function checkRestoreDrillGate(string $cohortId): array
    {
        // 1. The per-cohort gate flag in wpe_control is the only source (R209)
        $manualGate = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            "restore_drill_gate_{$cohortId}"
        ));
        if ($manualGate !== null) {
            $mData = json_decode((string)$manualGate, true);
            $passed = !empty($mData['passed']);
            return [
                'passed' => $passed,
                'reason' => $passed ? 'Cohort restore drill gate explicitly verified' : 'Cohort restore drill gate explicitly marked unverified',
            ];
        }

        // 2. No cohort record means no verified drill. There is deliberately no global fallback to
        //    T49 evidence: a drill proven for one cohort says nothing about another (R209).
        return [
            'passed' => false,
            'reason' => "No restore drill recorded for cohort {$cohortId}",
        ];
    }

    /**
     * Sets restore drill gate status (for test simulation).
     */
    public function setRestoreDrillGate(string $cohortId, bool $passed): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->replace($this->tableControl, [
            'key'        => "restore_drill_gate_{$cohortId}",
            'value'      => (string)wp_json_encode([
                'cohort_id' => $cohortId,
                'passed'    => $passed,
                'set_at'    => $now,
            ]),
            'version'    => 1,
            'updated_at' => $now,
        ]);
    }

    // --- Cleanup guards (R206-R209) ---

    /**
     * @param mixed $ids
     * @return list<int>
     */
    private function normalizeProductIds($ids): array
    {
        $out = [];
        foreach ((array) $ids as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $out[$int] = $int;
            }
        }
        return array_values($out);
    }

    /** Rollback window in days, operator-configurable (R208). */
    public function getRollbackWindowDays(): int
    {
        $days = (int) get_option(self::ROLLBACK_WINDOW_OPTION, self::DEFAULT_ROLLBACK_WINDOW_DAYS);
        return $days >= 1 ? $days : self::DEFAULT_ROLLBACK_WINDOW_DAYS;
    }

    /**
     * @param list<int> $productIds
     * @return list<int>
     */
    private function productsWithoutNativeRow(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $in = implode(',', $productIds);
        $have = array_map('intval', $this->wpdb->get_col(
            "SELECT id FROM {$this->tableProducts} WHERE id IN ({$in}) AND deleted_at IS NULL"
        ));
        return array_values(array_diff($productIds, $have));
    }

    /** @return array<string,mixed>|null */
    private function getMirrorStoppedRecord(string $cohortId): ?array
    {
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            "mirror_stopped_{$cohortId}"
        ));
        $decoded = $raw !== null ? json_decode((string) $raw, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lists every reason cleanup must not run, in the order the guards are applied.
     *
     * @param list<int> $productIds
     * @return list<array{code:string,message:string}>
     */
    public function cleanupBlockers(string $cohortId, array $productIds): array
    {
        $blockers = [];

        // R206: explicit scope
        if (empty($productIds)) {
            return [['code' => 'R206', 'message' => 'Cleanup requires explicit product_ids; an empty scope is never interpreted as the whole catalog.']];
        }

        // R207: native row + sealed authority
        $missing = $this->productsWithoutNativeRow($productIds);
        if (!empty($missing)) {
            $blockers[] = ['code' => 'R207', 'message' => 'No native row for product(s) ' . implode(',', $missing) . '; legacy data is the only copy.'];
        }
        $in = implode(',', $productIds);
        $sealedIds = array_map('intval', $this->wpdb->get_col(
            "SELECT object_id FROM {$this->tableObjectState} WHERE object_id IN ({$in}) AND authority = 'full_native' AND tombstone = 0"
        ));
        $unsealed = array_values(array_diff($productIds, $sealedIds));
        if (!empty($unsealed)) {
            $blockers[] = ['code' => 'R207', 'message' => 'Authority is not sealed (full_native) for product(s) ' . implode(',', $unsealed) . '.'];
        }
        $sealRaw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            "full_native_no_fallback_{$cohortId}"
        ));
        $seal = $sealRaw !== null ? json_decode((string) $sealRaw, true) : null;
        if (!is_array($seal) || empty($seal['active']) || empty($seal['sealed_at'])) {
            $blockers[] = ['code' => 'R207', 'message' => "No active authority seal recorded for cohort {$cohortId}."];
        } else {
            // R208: rollback window measured from the seal
            $sealedAt = strtotime((string) $seal['sealed_at'] . ' UTC');
            $days = $this->getRollbackWindowDays();
            if ($sealedAt === false || time() < $sealedAt + $days * 86400) {
                $blockers[] = ['code' => 'R208', 'message' => "Rollback window of {$days} day(s) has not elapsed since the authority seal."];
            }
        }

        // R187 / R209: restore drill, per cohort
        $gate = $this->checkRestoreDrillGate($cohortId);
        if (!$gate['passed']) {
            $blockers[] = ['code' => 'R187', 'message' => $gate['reason']];
        }

        return $blockers;
    }

    /**
     * @param list<int> $productIds
     * @throws RuntimeException with the first violated guard
     */
    private function assertCleanupAllowed(string $cohortId, array $productIds): void
    {
        $blockers = $this->cleanupBlockers($cohortId, $productIds);
        if (empty($blockers)) {
            return;
        }
        $first = $blockers[0];
        $label = match ($first['code']) {
            'R206' => 'Cleanup Scope Violation',
            'R207' => 'Cleanup Native Row Violation',
            'R208' => 'Cleanup Rollback Window Violation',
            default => 'Cleanup Gate Violation',
        };
        throw new RuntimeException("{$label} ({$first['code']}): {$first['message']}");
    }

    // --- Helpers ---

    private function getJob(string $jobId): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, kind, status, config, checkpoint, counts, lease_owner, lease_until FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);
    }

    private function logAudit(string $operation, int $targetId, array $summary): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->insert($this->tableAudit, [
            'request_id' => wp_generate_uuid4(),
            'actor_id'   => get_current_user_id() ?: null,
            'operation'  => $operation,
            'target_id'  => $targetId,
            'summary'    => (string)wp_json_encode($summary),
            'created_at' => $now,
        ]);
    }
}
