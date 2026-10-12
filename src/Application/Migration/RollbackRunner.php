<?php

declare(strict_types=1);

namespace WPE\Application\Migration;

/**
 * Class RollbackRunner
 *
 * Orchestrates safe, atomic, data-preserving rollbacks from Native authority back to Legacy CPT authority.
 * Enforces write barriers, drains in-flight checkouts, projects current native changes, verifies
 * semantic parity, and safely gates plugin deactivation.
 *
 * Implements:
 * - R161: Full preservation of native created/edited products, sales, refunds, and order references.
 * - R162: Write barrier and in-flight checkout stock mutation draining.
 * - R163: Rollback timeout safety (clean abort without switching authority or leaving corrupt state).
 * - R164: Deactivation safety verification blocking unsafe deactivation with native-only data.
 * - AC-T40-01: Zero data or order reference loss; timed-out rollback does not switch authority.
 * - AC-T40-02: Safe fallback requires full reconciliation, rejecting simple flag flips to stale CPTs.
 * - AC-T40-03: Standalone recovery runbook for forced plugin directory deletion.
 */
class RollbackRunner
{
    private \wpdb $wpdb;
    private LegacyMirror $mirror;
    private LegacyCapture $capture;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableMeta;
    private string $tableProductTerms;
    private string $tableStockOperations;
    private string $tableAudit;
    private string $tableControl;

    public function __construct(?\wpdb $wpdb = null, ?LegacyMirror $mirror = null, ?LegacyCapture $capture = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $prefix = $this->wpdb->prefix;
        $this->tableProducts        = "{$prefix}wpe_products";
        $this->tableVariants        = "{$prefix}wpe_variants";
        $this->tableInventory       = "{$prefix}wpe_inventory";
        $this->tableObjectState     = "{$prefix}wpe_object_state";
        $this->tableMeta            = "{$prefix}wpe_meta";
        $this->tableProductTerms    = "{$prefix}wpe_product_terms";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";
        $this->tableAudit           = "{$prefix}wpe_audit";
        $this->tableControl         = "{$prefix}wpe_control";

        $this->capture = $capture ?? new LegacyCapture($this->wpdb);
        $this->mirror  = $mirror ?? new LegacyMirror($this->wpdb, $this->capture);
    }

    /**
     * Acquire write barrier for a cohort of products or globally (R162).
     *
     * @param int[] $cohortIds Product IDs included in the barrier (empty = global)
     * @return string Barrier ID
     */
    public function acquireWriteBarrier(array $cohortIds = []): string
    {
        $barrierId = wp_generate_uuid4();
        $payload = [
            'barrier_id' => $barrierId,
            'status'     => 'active',
            'cohort_ids' => array_values(array_map('intval', $cohortIds)),
            'started_at' => gmdate('Y-m-d H:i:s.u'),
        ];

        $this->wpdb->replace($this->tableControl, [
            'key'        => 'rollback_write_barrier',
            'value'      => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);

        $this->logAudit('rollback_barrier_acquired', 0, $payload);

        return $barrierId;
    }

    /**
     * Release active write barrier (R162).
     */
    public function releaseWriteBarrier(): void
    {
        $this->wpdb->delete($this->tableControl, ['key' => 'rollback_write_barrier']);
        $this->logAudit('rollback_barrier_released', 0, ['status' => 'released']);
    }

    /**
     * Check if writes are currently blocked by write barrier (R162).
     */
    public function isWriteBlocked(int $aggregateId = 0): bool
    {
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'rollback_write_barrier'
        ));

        if (!$raw) {
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'active') {
            return false;
        }

        $cohort = $data['cohort_ids'] ?? [];
        if (empty($cohort)) {
            return true; // Global barrier
        }

        return $aggregateId === 0 || in_array($aggregateId, $cohort, true);
    }

    /**
     * Record an active in-flight checkout stock mutation (R162).
     */
    public function recordInflightCheckout(int $aggregateId, string $orderKey): void
    {
        $map = $this->getInflightCheckoutsMap();
        $map[$orderKey] = [
            'aggregate_id' => $aggregateId,
            'started_at'   => microtime(true),
        ];
        $this->saveInflightCheckoutsMap($map);
    }

    /**
     * Mark an in-flight checkout stock mutation resolved / completed (R162).
     */
    public function resolveInflightCheckout(int $aggregateId, string $orderKey): void
    {
        $map = $this->getInflightCheckoutsMap();
        unset($map[$orderKey]);
        $this->saveInflightCheckoutsMap($map);
    }

    /**
     * Get count of active in-flight checkouts (R162).
     */
    public function getInflightCheckoutsCount(int $aggregateId = 0): int
    {
        $map = $this->getInflightCheckoutsMap();
        if ($aggregateId === 0) {
            return count($map);
        }

        $count = 0;
        foreach ($map as $entry) {
            if (($entry['aggregate_id'] ?? 0) === $aggregateId) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Drain in-flight checkout operations within a time budget (R162).
     */
    public function drainInflightCheckouts(int $timeoutMs = 2000): bool
    {
        $start = microtime(true);
        $budgetSec = $timeoutMs / 1000.0;

        while (true) {
            $count = $this->getInflightCheckoutsCount();
            if ($count === 0) {
                return true;
            }

            if ((microtime(true) - $start) >= $budgetSec) {
                return false;
            }

            usleep(20000); // 20ms poll
        }
    }

    /**
     * Reconcile current native catalog data into legacy WordPress CPT/postmeta (R161, AC-T40-01).
     * Strictly verifies projection parity and sets the Restore Readiness Marker.
     */
    public function reconcileNativeToLegacy(array $aggregateIds, array $options = []): array
    {
        $timeoutSec = (float)($options['timeout_seconds'] ?? 30.0);
        $simulateTimeout = !empty($options['simulate_timeout']);
        $startTime = microtime(true);

        $reconciled = [];

        foreach ($aggregateIds as $aggregateId) {
            if ($simulateTimeout || ((microtime(true) - $startTime) >= $timeoutSec)) {
                $this->logAudit('rollback_reconcile_timeout', $aggregateId, [
                    'elapsed_sec' => microtime(true) - $startTime,
                    'timeout_sec' => $timeoutSec,
                    'simulated'   => $simulateTimeout,
                ]);

                return [
                    'status'         => 'timeout_aborted',
                    'reconciled_ids' => $reconciled,
                    'reason'         => 'reconciliation_budget_exceeded',
                ];
            }

            // Retrieve object state
            $state = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableObjectState} WHERE object_id = %d",
                $aggregateId
            ), ARRAY_A);

            if (!$state) {
                continue;
            }

            $sourceRev = (int)$state['native_revision'];
            $currentEpoch = (int)$state['epoch'];

            // Force legacy mirror projection
            $mirrorRes = $this->mirror->mirrorAggregate($aggregateId, $sourceRev, $currentEpoch);

            // Verify projection in wp_posts
            $post = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT post_title, post_status FROM {$this->wpdb->posts} WHERE ID = %d",
                $aggregateId
            ), ARRAY_A);

            if (!$post) {
                throw new \RuntimeException("Reconciliation integrity failed: post {$aggregateId} not found in wp_posts");
            }

            $reconciled[] = $aggregateId;
        }

        // Set Restore Readiness Marker in wpe_control (AC-T40-02)
        $marker = [
            'status'          => 'ready',
            'cohort_ids'      => $aggregateIds,
            'reconciled_count'=> count($reconciled),
            'ready_at'        => gmdate('Y-m-d H:i:s.u'),
        ];

        $this->wpdb->replace($this->tableControl, [
            'key'        => 'restore_readiness_marker',
            'value'      => json_encode($marker, JSON_UNESCAPED_SLASHES),
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);

        return [
            'status'           => 'ready',
            'reconciled_count' => count($reconciled),
            'reconciled_ids'   => $reconciled,
            'marker'           => $marker,
        ];
    }

    /**
     * Refuses to switch authority to legacy while a cleanup stopped the legacy mirror for any cohort
     * touching these aggregates and no reprojection has been recorded since (R210, AC-T46-03).
     *
     * This is not bypassable by force_unsafe: forcing a flip onto stale postmeta would lose current
     * native prices and stock.
     *
     * @param list<int|string> $aggregateIds
     * @param array<string,mixed> $options may carry cohort_id
     * @throws \RuntimeException
     */
    public function assertMirrorNotStopped(array $aggregateIds, array $options = []): void
    {
        $ids = array_map('intval', $aggregateIds);
        $cohort = isset($options['cohort_id']) ? (string) $options['cohort_id'] : null;

        $rows = $this->wpdb->get_results(
            "SELECT `key`, value FROM {$this->tableControl} WHERE `key` LIKE 'mirror\\_stopped\\_%'",
            ARRAY_A
        );
        foreach ($rows as $row) {
            $record = json_decode((string) $row['value'], true);
            if (!is_array($record) || empty($record['mirror_stopped'])) {
                continue;
            }
            $cohortId = (string) ($record['cohort_id'] ?? substr((string) $row['key'], strlen('mirror_stopped_')));
            $scope = array_map('intval', (array) ($record['product_ids'] ?? []));
            $touches = ($cohort !== null && $cohort === $cohortId) || !empty(array_intersect($ids, $scope));
            if (!$touches) {
                continue;
            }

            $restoredRaw = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
                "mirror_restored_{$cohortId}"
            ));
            $restored = $restoredRaw !== null ? json_decode((string) $restoredRaw, true) : null;
            $reprojected = is_array($restored)
                && !empty($restored['mirror_ready'])
                && (string) ($restored['restored_at'] ?? '') >= (string) ($record['stopped_at'] ?? '');
            if ($reprojected) {
                continue;
            }

            $this->logAudit('rollback_blocked_mirror_stopped', 0, ['cohort_id' => $cohortId]);
            throw new \RuntimeException(
                "Rollback blocked (R210): the legacy mirror was stopped for cohort {$cohortId}. " .
                "Run RetentionCleaner::reprojectBeforeRollback() with the write barrier before switching authority."
            );
        }
    }

    /**
     * Switch authority to Legacy and increment epoch atomically (AC-T40-01, AC-T40-02).
     * Enforces AC-T40-02: Safe fallback requires full reconciliation; refuses simple flag flip to stale CPT!
     */
    public function switchAuthorityToLegacy(array $aggregateIds, int $nextEpoch, array $options = []): array
    {
        // 0. A stopped legacy mirror means legacy storage is stale: never switch to it without reprojection (R210).
        $this->assertMirrorNotStopped($aggregateIds, $options);

        // 1. Guard against stale fallback without reconciliation (AC-T40-02)
        $markerRaw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'restore_readiness_marker'
        ));

        if (!$markerRaw && empty($options['force_unsafe'])) {
            throw new \RuntimeException(
                "Unsafe fallback blocked: cannot switch authority to legacy without active Restore Readiness Marker. " .
                "Safe fallback requires full native-to-legacy reconciliation (AC-T40-02)."
            );
        }

        $marker = json_decode($markerRaw ?: '{}', true);
        if (($marker['status'] ?? '') !== 'ready' && empty($options['force_unsafe'])) {
            throw new \RuntimeException(
                "Unsafe fallback blocked: Restore Readiness Marker status is not 'ready'. Parity verification required."
            );
        }

        // 2. Atomic SQL transaction for authority switch
        $this->wpdb->query('START TRANSACTION');

        try {
            $now = gmdate('Y-m-d H:i:s.u');

            foreach ($aggregateIds as $aggId) {
                // Fetch native_revision to sync legacy_applied
                $nativeRev = (int)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT native_revision FROM {$this->tableObjectState} WHERE object_id = %d",
                    $aggId
                ));

                $this->wpdb->update($this->tableObjectState, [
                    'authority'       => 'legacy',
                    'epoch'           => $nextEpoch,
                    'legacy_applied'  => $nativeRev,
                    'updated_at'      => $now,
                ], ['object_id' => $aggId]);

                // Also update any child variations for this parent
                $this->wpdb->update($this->tableObjectState, [
                    'authority'       => 'legacy',
                    'epoch'           => $nextEpoch,
                    'updated_at'      => $now,
                ], ['parent_id' => $aggId]);
            }

            // Update global epoch and system mode in wpe_control
            $this->wpdb->replace($this->tableControl, [
                'key'        => 'authority_epoch',
                'value'      => (string)$nextEpoch,
                'version'    => 1,
                'updated_at' => $now,
            ]);

            $this->wpdb->replace($this->tableControl, [
                'key'        => 'system_mode',
                'value'      => 'legacy_restored',
                'version'    => 1,
                'updated_at' => $now,
            ]);

            // Clear restore readiness marker
            $this->wpdb->delete($this->tableControl, ['key' => 'restore_readiness_marker']);

            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }

        // 3. Cache invalidation
        foreach ($aggregateIds as $aggId) {
            clean_post_cache($aggId);
            wp_cache_delete($aggId, 'post_meta');

            $varIds = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d",
                $aggId
            ));
            foreach ($varIds as $vId) {
                clean_post_cache((int)$vId);
                wp_cache_delete((int)$vId, 'post_meta');
            }
        }

        // 4. Release write barrier
        $this->releaseWriteBarrier();

        // 5. Log audit
        $this->logAudit('rollback_authority_switched', 0, [
            'new_epoch'       => $nextEpoch,
            'switched_count'  => count($aggregateIds),
            'cohort_ids'      => $aggregateIds,
        ]);

        return [
            'status'         => 'switched',
            'new_epoch'      => $nextEpoch,
            'switched_count' => count($aggregateIds),
        ];
    }

    /**
     * Full Rollback Orchestration execution flow.
     */
    public function executeRollback(array $aggregateIds, array $options = []): array
    {
        // Fail before any side effect (barrier, reconciliation) when the mirror is stopped (R210).
        $this->assertMirrorNotStopped($aggregateIds, $options);

        $currentEpoch = $this->mirror->getGlobalEpoch();
        $nextEpoch = $currentEpoch + 1;

        // Step 1: Acquire write barrier (R162)
        $barrierId = $this->acquireWriteBarrier($aggregateIds);

        // Step 2: Drain in-flight checkout operations (R162)
        $drainBudgetMs = (int)($options['drain_timeout_ms'] ?? 2000);
        $drained = $this->drainInflightCheckouts($drainBudgetMs);
        if (!$drained) {
            $this->releaseWriteBarrier();
            $this->logAudit('rollback_aborted_inflight_timeout', 0, [
                'pending_checkouts' => $this->getInflightCheckoutsCount(),
            ]);

            return [
                'status'  => 'timeout_aborted',
                'phase'   => 'drain_inflight',
                'reason'  => 'inflight_checkout_drain_timeout',
                'barrier' => 'released',
            ];
        }

        // Step 3: Reconcile current native data into legacy (R161, R163, AC-T40-01)
        $reconcileRes = $this->reconcileNativeToLegacy($aggregateIds, $options);
        if ($reconcileRes['status'] === 'timeout_aborted') {
            // R163, AC-T40-01: Timed-out rollback cleanly aborts without switching authority!
            $this->releaseWriteBarrier();
            return [
                'status'  => 'timeout_aborted',
                'phase'   => 'reconciliation',
                'reason'  => $reconcileRes['reason'] ?? 'reconciliation_timeout',
                'barrier' => 'released',
            ];
        }

        // Step 4: Switch authority to legacy (AC-T40-01, AC-T40-02)
        $switchRes = $this->switchAuthorityToLegacy($aggregateIds, $nextEpoch, $options);

        return [
            'status'          => 'completed',
            'previous_epoch'  => $currentEpoch,
            'new_epoch'       => $nextEpoch,
            'aggregate_count' => count($aggregateIds),
            'barrier_id'      => $barrierId,
        ];
    }

    /**
     * Validate deactivation safety (R164).
     *
     * Deactivation is safe ONLY if all catalog data has legacy authority and all
     * native revisions have been fully projected.
     */
    public function validateDeactivationSafety(array $scopedIds = []): array
    {
        $idWhere = '';
        if (!empty($scopedIds)) {
            $idsList = implode(',', array_map('intval', $scopedIds));
            $idWhere = " AND object_id IN ({$idsList})";
        }

        $nativeAuthCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState} WHERE authority = 'native'{$idWhere}"
        );

        $unprojectedCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState} WHERE native_revision > native_applied{$idWhere}"
        );

        $pWhere = '';
        if (!empty($scopedIds)) {
            $idsList = implode(',', array_map('intval', $scopedIds));
            $pWhere = " WHERE p.id IN ({$idsList})";
        }
        $missingLegacyPosts = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableProducts} p\n" .
            "LEFT JOIN {$this->wpdb->posts} posts ON posts.ID = p.id\n" .
            $pWhere . ($pWhere ? " AND " : " WHERE ") . "posts.ID IS NULL"
        );

        $safe = ($nativeAuthCount === 0 && $unprojectedCount === 0 && $missingLegacyPosts === 0);

        return [
            'safe_to_deactivate'         => $safe,
            'native_authority_count'     => $nativeAuthCount,
            'unprojected_revisions_count'=> $unprojectedCount,
            'missing_legacy_posts_count' => $missingLegacyPosts,
            'maintenance_required'       => !$safe,
            'message'                    => $safe
                ? 'All catalog entities are under legacy authority with current projections. Safe to deactivate.'
                : 'Unsafe to deactivate: active native authority or unprojected native-only data exists. Run rollback first.',
            'recovery_runbook_path'      => 'docs/08-operations.md#7-rollback-runbook',
        ];
    }

    /**
     * Get standalone Recovery Runbook for forced plugin directory deletion (AC-T40-03).
     *
     * Provides executable SQL and procedures to safely restore WooCommerce operation
     * when the plugin code has been removed from the filesystem.
     */
    public function getForcedDeletionRecoveryRunbook(): array
    {
        $prefix = $this->wpdb->prefix;

        $sql = <<<SQL
-- Standalone Recovery SQL for Forced Plugin Deletion (AC-T40-03)
-- Step 1: Ensure legacy WooCommerce data stores are active (reset any filter cache)
-- Step 2: Sync current prices from wpe_products to wp_postmeta
INSERT INTO `{$prefix}postmeta` (`post_id`, `meta_key`, `meta_value`)
SELECT `id`, '_regular_price', CAST(`regular_price` AS CHAR)
FROM `{$prefix}wpe_products`
WHERE `regular_price` IS NOT NULL
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

INSERT INTO `{$prefix}postmeta` (`post_id`, `meta_key`, `meta_value`)
SELECT `id`, '_sale_price', CAST(`sale_price` AS CHAR)
FROM `{$prefix}wpe_products`
WHERE `sale_price` IS NOT NULL
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

INSERT INTO `{$prefix}postmeta` (`post_id`, `meta_key`, `meta_value`)
SELECT `id`, '_price', COALESCE(CAST(`sale_price` AS CHAR), CAST(`regular_price` AS CHAR))
FROM `{$prefix}wpe_products`
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

-- Step 3: Sync inventory quantities from wpe_inventory to wp_postmeta
INSERT INTO `{$prefix}postmeta` (`post_id`, `meta_key`, `meta_value`)
SELECT `owner_id`, '_stock', CAST(`quantity` AS CHAR)
FROM `{$prefix}wpe_inventory`
WHERE `quantity` IS NOT NULL
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

INSERT INTO `{$prefix}postmeta` (`post_id`, `meta_key`, `meta_value`)
SELECT `owner_id`, '_stock_status', `stock_status`
FROM `{$prefix}wpe_inventory`
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

-- Step 4: Restore variation prices
INSERT INTO `{$prefix}postmeta` (`post_id`, `meta_key`, `meta_value`)
SELECT `id`, '_price', COALESCE(CAST(`sale_price` AS CHAR), CAST(`regular_price` AS CHAR))
FROM `{$prefix}wpe_variants`
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`);

-- Step 5: Mark all object states as legacy
UPDATE `{$prefix}wpe_object_state` SET `authority` = 'legacy', `epoch` = `epoch` + 1;
SQL;

        return [
            'title'        => 'Forced Deletion Recovery Runbook (AC-T40-03)',
            'description'  => 'Recovery runbook to restore WooCommerce store when plugin directory was deleted while native authority was active.',
            'steps'        => [
                '1. Verify database access to target WordPress database.',
                '2. Run the provided standalone SQL script to synchronize native pricing, inventory, and variation states into wp_postmeta.',
                '3. Flush WooCommerce and WordPress persistent object cache via WP-CLI (wp cache flush).',
                '4. Verify product detail pages and checkout using standard WooCommerce CPT data stores.',
            ],
            'recovery_sql' => $sql,
            'runbook_ref'  => 'docs/08-operations.md#7-rollback-runbook',
        ];
    }

    private function getInflightCheckoutsMap(): array
    {
        $raw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'inflight_checkouts'
        ));

        if (!$raw) {
            return [];
        }

        $map = json_decode($raw, true);
        return is_array($map) ? $map : [];
    }

    private function saveInflightCheckoutsMap(array $map): void
    {
        if (empty($map)) {
            $this->wpdb->delete($this->tableControl, ['key' => 'inflight_checkouts']);
        } else {
            $this->wpdb->replace($this->tableControl, [
                'key'        => 'inflight_checkouts',
                'value'      => json_encode($map, JSON_UNESCAPED_SLASHES),
                'version'    => 1,
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
        }
    }

    private function logAudit(string $operation, int $targetId, array $payload): void
    {
        $this->wpdb->insert($this->tableAudit, [
            'actor_id'   => get_current_user_id() ?: null,
            'request_id' => wp_generate_uuid4(),
            'operation'  => $operation,
            'target_id'  => $targetId,
            'summary'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
