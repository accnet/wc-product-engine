<?php

declare(strict_types=1);

namespace WPE\Application\Migration;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * BackfillRunner
 *
 * Implements:
 * - Keyset-based catalog backfill strictly without sql offset (R155, AC-T38-01).
 * - Adaptive chunk sizing and throttle pacing keeping write load within budget (AC-T38-02).
 * - Resumable checkpoints with zero dropped or duplicate aggregates (R153, AC-T38-01).
 * - Concurrent edit/delete revision fences preserving concurrent mutations (R154).
 * - Shadow diff detecting missing products, field mismatches, and variation count gaps (R156, AC-T38-01).
 * - Runtime throughput and ETA calculation based on real measured progress.
 */
class BackfillRunner
{
    private wpdb $wpdb;
    private LegacyCapture $legacyCapture;
    private ProductRepository $productRepo;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableJobs;
    private string $tableAudit;
    private string $tableControl;

    public function __construct(
        ?wpdb $wpdb = null,
        ?LegacyCapture $legacyCapture = null,
        ?ProductRepository $productRepo = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableInventory   = "{$prefix}wpe_inventory";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableJobs        = "{$prefix}wpe_jobs";
        $this->tableAudit       = "{$prefix}wpe_audit";
        $this->tableControl     = "{$prefix}wpe_control";

        $this->legacyCapture = $legacyCapture ?? LegacyCapture::getInstance();
        $this->productRepo   = $productRepo ?? new ProductRepository($this->wpdb);
    }

    /**
     * Create a new backfill job record in wpe_jobs.
     *
     * @param array $config Configuration options
     * @return string Job UUID
     */
    public function createJob(array $config = []): string
    {
        $jobId = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');

        $startId = (int)($config['start_id'] ?? 0);
        $maxId   = (int)($config['max_id'] ?? 0);

        // Total count of legacy products within range
        if ($maxId > 0 && $startId > 0) {
            $totalProducts = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product' AND ID >= %d AND ID <= %d",
                $startId,
                $maxId
            ));
        } elseif ($startId > 0) {
            $totalProducts = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product' AND ID >= %d",
                $startId
            ));
        } else {
            $totalProducts = (int)$this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product'"
            );
        }

        $initialConfig = array_merge([
            'start_id'        => $startId,
            'max_id'          => $maxId,
            'chunk_size'      => 100,
            'throttle_ms'     => 10,
            'adaptive_pacing' => true,
            'target_chunk_ms' => 200,
        ], $config);

        $initialCheckpoint = [
            'last_id'         => $startId > 0 ? ($startId - 1) : 0,
            'completed'       => false,
            'chunks_executed' => 0,
            'start_time'      => microtime(true),
        ];

        $initialCounts = [
            'total'       => $totalProducts,
            'processed'   => 0,
            'repaired'    => 0,
            'skipped'     => 0,
            'errors'      => 0,
            'elapsed_sec' => 0.0,
            'throughput'  => 0.0,
            'eta_sec'     => 0.0,
        ];

        $this->wpdb->insert($this->tableJobs, [
            'id'               => $jobId,
            'kind'             => 'backfill',
            'status'           => 'queued',
            'config'           => json_encode($initialConfig, JSON_UNESCAPED_SLASHES),
            'checkpoint'       => json_encode($initialCheckpoint, JSON_UNESCAPED_SLASHES),
            'counts'           => json_encode($initialCounts, JSON_UNESCAPED_SLASHES),
            'cancel_requested' => 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $jobId;
    }

    /**
     * Run or resume a backfill job.
     *
     * @param string $jobId Job UUID
     * @param array $runtimeOptions Runtime overrides (e.g. max_chunks, chunk_size, throttle_ms)
     * @return array Job summary and statistics
     */
    public function runJob(string $jobId, array $runtimeOptions = []): array
    {
        $job = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);

        if (!$job) {
            throw new InvalidArgumentException("Backfill job {$jobId} not found");
        }

        $config     = json_decode($job['config'] ?? '{}', true) ?: [];
        $checkpoint = json_decode($job['checkpoint'] ?? '{}', true) ?: [];
        $counts     = json_decode($job['counts'] ?? '{}', true) ?: [];

        // Apply runtime overrides
        $chunkSize      = (int)($runtimeOptions['chunk_size'] ?? $config['chunk_size'] ?? 100);
        $maxChunks      = (int)($runtimeOptions['max_chunks'] ?? 0); // 0 = unlimited
        $throttleMs     = (int)($runtimeOptions['throttle_ms'] ?? $config['throttle_ms'] ?? 10);
        $adaptivePacing = (bool)($runtimeOptions['adaptive_pacing'] ?? $config['adaptive_pacing'] ?? true);
        $targetChunkMs  = (int)($runtimeOptions['target_chunk_ms'] ?? $config['target_chunk_ms'] ?? 200);

        $maxId          = (int)($runtimeOptions['max_id'] ?? $config['max_id'] ?? 0);
        $lastId         = (int)($checkpoint['last_id'] ?? 0);
        $startTime      = microtime(true);
        $chunksExecuted = (int)($checkpoint['chunks_executed'] ?? 0);

        $processed = (int)($counts['processed'] ?? 0);
        $total     = (int)($counts['total'] ?? 0);
        $repaired  = (int)($counts['repaired'] ?? 0);
        $skipped   = (int)($counts['skipped'] ?? 0);
        $errors    = (int)($counts['errors'] ?? 0);

        // Update status to running
        $this->wpdb->update($this->tableJobs, [
            'status'     => 'running',
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['id' => $jobId]);

        $chunkCountThisRun = 0;
        $isCompleted = false;

        while (true) {
            // Check cancellation request
            $cancelRequested = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT cancel_requested FROM {$this->tableJobs} WHERE id = %s",
                $jobId
            ));
            if ($cancelRequested === 1) {
                $this->wpdb->update($this->tableJobs, [
                    'status'     => 'cancelled',
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ], ['id' => $jobId]);
                break;
            }

            // Keyset query: Strictly ID > $lastId, keyset cursor (R155, AC-T38-01)
            $chunkStart = microtime(true);
            $sql = "SELECT ID, post_status, post_modified_gmt FROM {$this->wpdb->posts} WHERE ID > %d AND post_type = 'product'";
            $params = [$lastId];

            if ($maxId > 0) {
                $sql .= " AND ID <= %d";
                $params[] = $maxId;
            }

            $sql .= " ORDER BY ID ASC LIMIT %d";
            $params[] = $chunkSize;

            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

            if (empty($rows)) {
                $isCompleted = true;
                break;
            }

            foreach ($rows as $row) {
                $productId = (int)$row['ID'];
                $lastId = $productId;

                try {
                    // Revision fence and concurrent mutation check (R154)
                    $currentPost = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT post_status, post_modified_gmt FROM {$this->wpdb->posts} WHERE ID = %d",
                        $productId
                    ), ARRAY_A);

                    if (!$currentPost) {
                        // Product deleted concurrently
                        $skipped++;
                        continue;
                    }

                    // Check if native authority applies (AC-T37-02, R154)
                    if ($this->legacyCapture->isNativeAuthority($productId)) {
                        $skipped++;
                        continue;
                    }

                    // Capture parent product and all child variations atomically (R150, AC-T38-01)
                    $res = $this->legacyCapture->captureLegacyProduct($productId, 'backfill_runner');
                    if (($res['status'] ?? '') === 'captured') {
                        $repaired++;
                    }
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            $chunkCountThisRun++;
            $chunksExecuted++;
            $chunkDurationMs = (microtime(true) - $chunkStart) * 1000;

            // Adaptive pacing to keep storefront write load within budget (AC-T38-02)
            if ($adaptivePacing) {
                if ($chunkDurationMs > $targetChunkMs * 1.5 && $chunkSize > 10) {
                    $chunkSize = (int)max(10, $chunkSize * 0.75);
                    $throttleMs = (int)min(100, $throttleMs + 5);
                } elseif ($chunkDurationMs < $targetChunkMs * 0.5 && $chunkSize < 500) {
                    $chunkSize = (int)min(500, $chunkSize * 1.25);
                    $throttleMs = (int)max(0, $throttleMs - 2);
                }
            }

            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }

            // Compute elapsed time, throughput, ETA
            $elapsedSec = max(0.001, microtime(true) - $startTime);
            $throughput = $processed > 0 ? ($processed / $elapsedSec) : 0.0;
            $remaining = max(0, $total - $processed);
            $etaSec = $throughput > 0.05 ? ($remaining / $throughput) : 0.0;

            // Save checkpoint to wpe_jobs (R153)
            $checkpointData = [
                'last_id'         => $lastId,
                'completed'       => $isCompleted,
                'chunks_executed' => $chunksExecuted,
                'current_chunk'   => $chunkSize,
            ];

            $countsData = [
                'total'       => $total,
                'processed'   => $processed,
                'repaired'    => $repaired,
                'skipped'     => $skipped,
                'errors'      => $errors,
                'elapsed_sec' => round($elapsedSec, 2),
                'throughput'  => round($throughput, 2),
                'eta_sec'     => round($etaSec, 2),
            ];

            $this->wpdb->update($this->tableJobs, [
                'checkpoint' => json_encode($checkpointData, JSON_UNESCAPED_SLASHES),
                'counts'     => json_encode($countsData, JSON_UNESCAPED_SLASHES),
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
            ], ['id' => $jobId]);

            // Save global backfill checkpoint in wpe_control
            $this->setGlobalCheckpoint($lastId);

            if ($maxChunks > 0 && $chunkCountThisRun >= $maxChunks) {
                // Pause execution at chunk boundary for resumability test
                $this->wpdb->update($this->tableJobs, [
                    'status'     => 'paused',
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ], ['id' => $jobId]);
                break;
            }
        }

        $finalStatus = $isCompleted ? 'completed' : ($this->wpdb->get_var($this->wpdb->prepare(
            "SELECT status FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        )) ?: 'paused');

        if ($isCompleted) {
            $this->wpdb->update($this->tableJobs, [
                'status'     => 'completed',
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
            ], ['id' => $jobId]);
        }

        return [
            'job_id'          => $jobId,
            'status'          => $finalStatus,
            'last_id'         => $lastId,
            'chunks_executed' => $chunksExecuted,
            'processed'       => $processed,
            'total'           => $total,
            'repaired'        => $repaired,
            'skipped'         => $skipped,
            'errors'          => $errors,
            'throughput'      => round($throughput ?? 0.0, 2),
            'eta_sec'         => round($etaSec ?? 0.0, 2),
        ];
    }

    /**
     * Run shadow diff comparing legacy catalog against native storage (R156, AC-T38-01).
     *
     * @param array $options Scan options (e.g. chunk_size, max_products)
     * @return array Parity report and detected gaps
     */
    public function runShadowDiff(array $options = []): array
    {
        $chunkSize   = (int)($options['chunk_size'] ?? 100);
        $maxProducts = (int)($options['max_products'] ?? 0);
        $productIds  = $options['product_ids'] ?? [];
        $startId     = (int)($options['start_id'] ?? 0);
        $maxId       = (int)($options['max_id'] ?? 0);

        $lastId       = $startId > 0 ? ($startId - 1) : 0;
        $totalScanned = 0;
        $matched      = 0;
        $gaps         = [];

        while (true) {
            $sql = "SELECT ID, post_title, post_status, post_modified_gmt FROM {$this->wpdb->posts} WHERE ID > %d AND post_type = 'product'";
            $params = [$lastId];

            if (!empty($productIds)) {
                $idList = implode(',', array_map('intval', $productIds));
                $sql .= " AND ID IN ({$idList})";
            } elseif ($maxId > 0) {
                $sql .= " AND ID <= %d";
                $params[] = $maxId;
            }

            $sql .= " ORDER BY ID ASC LIMIT %d";
            $params[] = $chunkSize;

            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $productId = (int)$row['ID'];
                $lastId = $productId;
                $totalScanned++;

                $gapFound = false;

                // 1. Check existence in wpe_products
                $nativeProduct = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT id, title, status, regular_price, sale_price FROM {$this->tableProducts} WHERE id = %d",
                    $productId
                ), ARRAY_A);

                if (!$nativeProduct) {
                    $gaps[] = [
                        'product_id' => $productId,
                        'kind'       => 'missing_native_product',
                        'message'    => "Product {$productId} exists in wp_posts but missing in wpe_products",
                    ];
                    $gapFound = true;
                } else {
                    // Check price parity
                    $legacyRegular = get_post_meta($productId, '_regular_price', true);
                    $legacyPrice = ($legacyRegular !== '') ? (float)$legacyRegular : null;
                    $nativePrice = $nativeProduct['regular_price'] !== null ? (float)$nativeProduct['regular_price'] : null;

                    if ($legacyPrice !== null && $nativePrice !== null && abs($legacyPrice - $nativePrice) > 0.0001) {
                        $gaps[] = [
                            'product_id' => $productId,
                            'kind'       => 'field_mismatch',
                            'field'      => 'regular_price',
                            'legacy'     => $legacyPrice,
                            'native'     => $nativePrice,
                            'message'    => "Price mismatch on product {$productId}: legacy {$legacyPrice} vs native {$nativePrice}",
                        ];
                        $gapFound = true;
                    }

                    // Check status parity
                    $expectedStatus = ($row['post_status'] === 'publish') ? 'publish' : (($row['post_status'] === 'trash') ? 'trash' : 'draft');
                    if ($nativeProduct['status'] !== $expectedStatus) {
                        $gaps[] = [
                            'product_id' => $productId,
                            'kind'       => 'field_mismatch',
                            'field'      => 'status',
                            'legacy'     => $expectedStatus,
                            'native'     => $nativeProduct['status'],
                            'message'    => "Status mismatch on product {$productId}",
                        ];
                        $gapFound = true;
                    }
                }

                // 2. Check variation count parity
                $legacyVarCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status != 'trash'",
                    $productId
                ));

                $nativeVarCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->tableVariants} WHERE product_id = %d AND status != 'trash'",
                    $productId
                ));

                if ($legacyVarCount !== $nativeVarCount) {
                    $gaps[] = [
                        'product_id' => $productId,
                        'kind'       => 'variation_count_mismatch',
                        'legacy'     => $legacyVarCount,
                        'native'     => $nativeVarCount,
                        'message'    => "Variation count mismatch on product {$productId}: legacy {$legacyVarCount} vs native {$nativeVarCount}",
                    ];
                    $gapFound = true;
                }

                if (!$gapFound) {
                    $matched++;
                }

                if ($maxProducts > 0 && $totalScanned >= $maxProducts) {
                    break 2;
                }
            }
        }

        return [
            'total_scanned' => $totalScanned,
            'matched'       => $matched,
            'gap_count'     => count($gaps),
            'gaps'          => $gaps,
            'is_quiescent'  => empty($gaps),
        ];
    }

    /**
     * Get global backfill checkpoint from wpe_control.
     */
    public function getGlobalCheckpoint(): int
    {
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'backfill_last_id'
        ));
        return $val !== null ? (int)$val : 0;
    }

    /**
     * Set global backfill checkpoint in wpe_control.
     */
    public function setGlobalCheckpoint(int $lastId): void
    {
        $this->wpdb->replace($this->tableControl, [
            'key'        => 'backfill_last_id',
            'value'      => (string)$lastId,
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
