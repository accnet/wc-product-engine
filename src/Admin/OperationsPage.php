<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Application\Migration\RollbackRunner;
use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Migration\LegacyCapture;
use WPE\Application\Migration\BackfillRunner;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\ImportExport\SnapshotExporter;
use WPE\Support\Singleton;
use WPE\Support\Capabilities;

/**
 * Class OperationsPage
 *
 * Implements the Store Operations Management screen, Health Dashboard,
 * and unified operations action dispatcher.
 *
 * Requirements:
 * - R165: Health status reflects true catalog parity; blocks green status on unresolved mismatch/DLQ/lag.
 * - R166: Full parity between UI actions and CLI commands via unified command dispatching.
 * - R167: Strict capability authorization (wpe_manage_operations) and audit logging for every mutation.
 * - R168: Worker starvation alert detection (>120s without checkpoint/heartbeat).
 * - AC-T41-01: No green status on unresolved mismatches or DLQ; resume/retry visible for operators.
 * - AC-T41-02: Zero leaks of filesystem paths, signed tokens, or private secrets to unauthorized actors.
 */
class OperationsPage
{
    use Singleton;

    private \wpdb $wpdb;
    private LegacyMirror $mirror;
    private LegacyCapture $capture;
    private RollbackRunner $rollbackRunner;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableMeta;
    private string $tableOutbox;
    private string $tableJobs;
    private string $tableAudit;
    private string $tableControl;

    public function __construct(?\wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableInventory   = "{$prefix}wpe_inventory";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableMeta        = "{$prefix}wpe_meta";
        $this->tableOutbox      = "{$prefix}wpe_outbox";
        $this->tableJobs        = "{$prefix}wpe_jobs";
        $this->tableAudit       = "{$prefix}wpe_audit";
        $this->tableControl     = "{$prefix}wpe_control";

        $this->capture = new LegacyCapture($this->wpdb);
        $this->mirror  = new LegacyMirror($this->wpdb, $this->capture);
        $this->rollbackRunner = new RollbackRunner($this->wpdb, $this->mirror, $this->capture);
    }

    /**
     * Registers the operations REST routes. The admin UI lives in Settings →
     * Operations & Health (SettingsPage); the former standalone `wpe-operations`
     * screen was removed and its slug redirects there (PluginBootstrap).
     */
    public function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('wpe/v1', '/operations/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'restGetHealth'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('wpe/v1', '/operations/action', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restHandleAction'],
            'permission_callback' => [$this, 'checkActionPermission'],
        ]);
    }

    /** Health report: read-only, shop managers included. */
    public function checkPermission(): bool
    {
        return Capabilities::canViewOperations();
    }

    /** Mutating actions: wpe_manage_operations only (audit SEC-02). */
    public function checkActionPermission(): bool
    {
        return Capabilities::canRunOperations();
    }

    public function restGetHealth(\WP_REST_Request $request): \WP_REST_Response
    {
        $report = $this->getHealthReport();
        return new \WP_REST_Response($report, 200);
    }

    public function restHandleAction(\WP_REST_Request $request): \WP_REST_Response
    {
        $action = (string)$request->get_param('action');
        $params = (array)($request->get_param('params') ?? []);

        try {
            $result = $this->handleAction($action, $params, get_current_user_id());
            return new \WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => \WPE\Support\RestErrors::safeMessage($e, 'operations action', __('The operation failed.', 'wc-product-engine')),
            ], 400);
        }
    }

    /**
     * Compile comprehensive health status report (R165, R168, AC-T41-01).
     */
    public function getHealthReport(): array
    {
        // 1. Authority & Mode
        $rawMode = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'system_mode'
        ));
        $systemMode = class_exists(\WPE\Bootstrap\PluginBootstrap::class)
            ? \WPE\Bootstrap\PluginBootstrap::instance()->getSystemMode()
            : 'native_bridge';
        if (is_string($rawMode) && $rawMode !== '') {
            $decodedMode = json_decode($rawMode, true);
            if (is_string($decodedMode) && $decodedMode !== '') {
                $systemMode = $decodedMode;
            } elseif (is_array($decodedMode) && !empty($decodedMode['mode'])) {
                $systemMode = (string)$decodedMode['mode'];
            } else {
                $systemMode = trim($rawMode, "\" \t\n\r\0\x0B") ?: $systemMode;
            }
        }

        $epoch = $this->mirror->getGlobalEpoch();

        // 2. Source Counts (exclude empty WordPress auto-draft shells and soft-deleted tombstones)
        $nativeCount = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableProducts} WHERE deleted_at IS NULL");
        $legacyCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash', 'auto-draft')"
        );
        $nativeAuthCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState} WHERE kind = 'product' AND authority IN ('native', 'native_bridge', 'full_native') AND tombstone = 0"
        );
        $legacyAuthCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState} WHERE kind = 'product' AND authority = 'legacy' AND tombstone = 0"
        );

        // 3. Mismatches / Shadow Diff gaps (R165)
        $mismatchRaw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'shadow_diff_mismatches'
        ));
        $mismatches = $mismatchRaw !== null ? (int)$mismatchRaw : 0;

        // 4. Dead-Letter Queue (DLQ) count (R165, AC-T41-01)
        $dlqCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableOutbox} WHERE state IN ('dead', 'failed', 'dead_letter')"
        );

        // 5. Projection & Outbox Lag
        $projLag = $this->mirror->getProjectionLag();
        $outboxPending = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableOutbox} WHERE state = 'pending'"
        );

        // 6. Worker Starvation Detection (R168, 08 §8: no heartbeat/checkpoint > 120s)
        $starvationAlert = false;
        $starvedJobId = null;

        $runningJobs = $this->wpdb->get_results(
            "SELECT id, kind, updated_at FROM {$this->tableJobs} WHERE status = 'running'",
            ARRAY_A
        );

        $now = time();
        foreach ($runningJobs as $job) {
            $updatedTime = strtotime($job['updated_at']);
            if ($updatedTime > 0 && ($now - $updatedTime) > 120) {
                $starvationAlert = true;
                $starvedJobId = $job['id'];
                break;
            }
        }

        // 7. Recent / Resumable Checkpoints (AC-T41-01)
        $recentJobs = $this->wpdb->get_results(
            "SELECT id, kind, status, checkpoint, counts, error_code, updated_at\n" .
            "FROM {$this->tableJobs}\n" .
            "ORDER BY created_at DESC LIMIT 5",
            ARRAY_A
        );

        $checkpoints = [];
        foreach ($recentJobs as $j) {
            $checkpoints[] = [
                'id'         => $j['id'],
                'kind'       => $j['kind'],
                'status'     => $j['status'],
                'can_resume' => in_array($j['status'], ['paused', 'failed'], true),
                'error'      => $j['error_code'] ?: null,
                'updated_at' => $j['updated_at'],
            ];
        }

        // 8. Overall Health Status Calculation (R165, AC-T41-01)
        // Strictly NOT green when there is an unresolved mismatch, DLQ, starvation, or excessive lag!
        $isHealthy = (
            $mismatches === 0 &&
            $dlqCount === 0 &&
            !$starvationAlert &&
            ($projLag['status'] ?? '') === 'healthy' &&
            $outboxPending < 50
        );

        $statusLabel = $isHealthy ? 'healthy' : 'unhealthy';
        if (!$isHealthy && $dlqCount === 0 && $mismatches === 0) {
            $statusLabel = 'degraded';
        }

        return [
            'status'            => $statusLabel,
            'is_healthy'        => $isHealthy,
            'system_mode'       => $systemMode,
            'authority_epoch'   => $epoch,
            'counts'            => [
                'native_products'       => $nativeCount,
                'legacy_products'       => $legacyCount,
                'native_authority'      => $nativeAuthCount,
                'legacy_authority'      => $legacyAuthCount,
            ],
            'mismatches'        => $mismatches,
            'dlq_count'         => $dlqCount,
            'outbox_pending'    => $outboxPending,
            'projection_lag'    => $projLag,
            'worker_starvation' => [
                'alert'          => $starvationAlert,
                'starved_job_id' => $starvedJobId,
                'threshold_sec'  => 120,
            ],
            'checkpoints'       => $checkpoints,
            'timestamp'         => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Unified command execution handler powering both UI actions and WP-CLI commands (R166, R167, AC-T41-01).
     *
     * @param string $action Action name: 'migrate', 'rebuild', 'audit', 'rollback', 'export', 'resume_job'
     * @param array $params Action parameters
     * @param int $actorId WordPress user ID or 0 for CLI
     * @return array Sanitized operation result
     */
    public function handleAction(string $action, array $params = [], int $actorId = 0): array
    {
        // 1. Authorization check for non-CLI actors (R167, AC-T41-02)
        // WP-CLI runs as actor 0; any web actor needs the dedicated capability (audit SEC-02).
        if ($actorId > 0 && !Capabilities::userCanRunOperations($actorId)) {
            throw new \RuntimeException("Unauthorized: user {$actorId} lacks wpe_manage_operations capability.");
        }

        // 2. Audit logging start (R167)
        $requestId = wp_generate_uuid4();
        $this->logAudit('operations_action_started', 0, [
            'action'     => $action,
            'request_id' => $requestId,
            'actor_id'   => $actorId,
        ], $actorId);

        $result = [];

        // 3. Dispatch to canonical domain services (R166)
        switch ($action) {
            case 'migrate':
            case 'backfill':
                $runner = new BackfillRunner($this->wpdb);
                $batchSize = (int)($params['batch_size'] ?? 100);
                $jobId = $runner->createJob([
                    'batch_size' => $batchSize,
                    'start_id'   => (int)($params['start_id'] ?? 0),
                    'max_id'     => (int)($params['max_id'] ?? 0),
                ]);
                $batchRes = $runner->runBatch($jobId);
                $result = [
                    'action'     => 'migrate',
                    'job_id'     => $jobId,
                    'batch_res'  => $batchRes,
                    'status'     => 'dispatched',
                ];
                break;

            case 'rebuild':
            case 'rebuild_index':
                $projector = new CatalogProjector($this->wpdb);
                // Web actors hand a large rebuild to the background worker; WP-CLI (actor 0) runs it here (audit F12).
                if ($actorId > 0 && $projector->shouldRebuildInBackground()) {
                    $projector->requestRebuild($actorId);
                    $result = [
                        'action' => 'rebuild',
                        'status' => 'queued',
                    ];
                    break;
                }
                $generation = $projector->rebuild();
                $result = [
                    'action'        => 'rebuild',
                    'generation'    => $generation,
                    'status'        => 'completed',
                ];
                break;

            case 'audit':
            case 'shadow_diff':
                $runner = new BackfillRunner($this->wpdb);
                $limit = (int)($params['limit'] ?? 100);
                $diff = $runner->runShadowDiff(array_merge($params, ['max_products' => $limit]));

                // Update mismatches counter in wpe_control
                $mismatchCount = count($diff['mismatches'] ?? []);
                $this->wpdb->replace($this->tableControl, [
                    'key'        => 'shadow_diff_mismatches',
                    'value'      => (string)$mismatchCount,
                    'version'    => 1,
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ]);

                $result = [
                    'action'           => 'audit',
                    'total_checked'    => $diff['total_checked'] ?? 0,
                    'mismatch_count'   => $mismatchCount,
                    'mismatches'       => $diff['mismatches'] ?? [],
                    'is_quiescent'     => ($mismatchCount === 0),
                    'status'           => 'completed',
                ];
                break;

            case 'rollback':
                $cohortIds = array_map('intval', (array)($params['cohort_ids'] ?? []));
                if (empty($cohortIds)) {
                    // Default to all active native / native_bridge / full_native products
                    $cohortIds = array_map('intval', $this->wpdb->get_col(
                        "SELECT object_id FROM {$this->tableObjectState}
                         WHERE kind = 'product' AND authority IN ('native', 'native_bridge', 'full_native') AND tombstone = 0
                         LIMIT 500"
                    ));
                }
                $rollbackRes = $this->rollbackRunner->executeRollback($cohortIds, $params);
                $result = [
                    'action'          => 'rollback',
                    'rollback_result' => $rollbackRes,
                    'status'          => $rollbackRes['status'] ?? 'unknown',
                ];
                break;

            case 'mode':
                $targetMode = trim((string)($params['mode'] ?? ''));
                if ($targetMode === '') {
                    $result = [
                        'action'      => 'mode',
                        'system_mode' => \WPE\Bootstrap\PluginBootstrap::instance()->getSystemMode(),
                        'cohort_mode' => \WPE\Bootstrap\PluginBootstrap::instance()->getFlags()['cohort_mode'] ?? \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS['cohort_mode'],
                        'status'      => 'ok',
                    ];
                    break;
                }
                if (!in_array($targetMode, ['legacy', 'shadow', 'indexed'], true)) {
                    throw new \InvalidArgumentException(
                        "Direct switch to '{$targetMode}' is prohibited. Pre-cutover modes are: legacy, shadow, indexed. Use 'gates' and 'cutover' for native_bridge."
                    );
                }
                if (\WPE\Bootstrap\PluginBootstrap::instance()->isNativeRuntimeActive()) {
                    throw new \RuntimeException(
                        "Cannot switch system_mode to '{$targetMode}' while native cohort authority is active. Run 'rollback' first."
                    );
                }
                $this->wpdb->replace($this->tableControl, [
                    'key'        => 'system_mode',
                    'value'      => $targetMode,
                    'version'    => 1,
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ]);
                \WPE\Bootstrap\PluginBootstrap::instance()->setFlag('query_enabled', $targetMode === 'indexed');
                $result = [
                    'action'        => 'mode',
                    'system_mode'   => $targetMode,
                    'query_enabled' => ($targetMode === 'indexed'),
                    'status'        => 'completed',
                ];
                break;

            case 'outbox':
                $worker = new \WPE\Application\Migration\OutboxProjectionWorker($this->wpdb);
                $defaultBatchSize = $actorId > 0 ? 200 : 100;
                $defaultMaxBatches = $actorId > 0 ? 25 : 100;
                $batchSize = max(1, (int)($params['batch_size'] ?? $defaultBatchSize));
                $maxBatches = max(1, (int)($params['max_batches'] ?? $defaultMaxBatches));
                $productIds = array_values(array_filter(array_map('intval', (array)($params['product_ids'] ?? []))));
                $drainRes = $worker->drainAll($batchSize, $maxBatches, $productIds);

                // When the outbox queue is empty, reconcile any residual wpe_object_state lag rows
                // (e.g. aggregates whose outbox events were retired on delete or missed a projection mark).
                if (empty($productIds) && (int)($drainRes['remaining_pending'] ?? 0) === 0) {
                    $lagRows = $this->wpdb->get_results($this->wpdb->prepare(
                        "SELECT os.object_id, os.kind, os.parent_id,
                                (p.id IS NOT NULL) AS has_active_product,
                                (v.id IS NOT NULL) AS has_active_variant
                         FROM {$this->tableObjectState} os
                         LEFT JOIN {$this->tableProducts} p ON p.id = os.object_id AND p.deleted_at IS NULL
                         LEFT JOIN {$this->tableVariants} v ON v.id = os.object_id AND v.deleted_at IS NULL
                         WHERE os.authority IN ('native', 'native_bridge')
                           AND os.native_revision > os.native_applied
                         LIMIT %d",
                        $batchSize
                    ), ARRAY_A) ?: [];

                    $now = gmdate('Y-m-d H:i:s.u');
                    foreach ($lagRows as $lr) {
                        $oid = (int)$lr['object_id'];
                        if (!empty($lr['has_active_product'])) {
                            $this->mirror->mirrorAggregate($oid);
                            $drainRes['mirrored'] = (int)($drainRes['mirrored'] ?? 0) + 1;
                        } elseif (!empty($lr['has_active_variant']) && (int)($lr['parent_id'] ?? 0) > 0) {
                            $this->mirror->mirrorAggregate((int)$lr['parent_id']);
                            $this->wpdb->query($this->wpdb->prepare(
                                "UPDATE {$this->tableObjectState} SET native_applied = native_revision, updated_at = %s WHERE object_id = %d",
                                $now,
                                $oid
                            ));
                            $drainRes['mirrored'] = (int)($drainRes['mirrored'] ?? 0) + 1;
                        } else {
                            // Deleted aggregate/variant: advance native_applied and mark tombstone so lag clears cleanly.
                            $this->wpdb->query($this->wpdb->prepare(
                                "UPDATE {$this->tableObjectState} SET native_applied = native_revision, tombstone = 1, updated_at = %s WHERE object_id = %d",
                                $now,
                                $oid
                            ));
                            $drainRes['retired_stale'] = (int)($drainRes['retired_stale'] ?? 0) + 1;
                        }
                    }
                }

                $result = array_merge(['action' => 'outbox', 'status' => 'completed'], $drainRes);
                break;

            case 'import_legacy':
                $result = $this->importLegacyProducts($params, $actorId);
                break;

            case 'gates':
                $cohortId = trim((string)($params['cohort_id'] ?? 'all')) ?: 'all';
                $gates = new \WPE\Application\Rollout\ReadinessGates($this->wpdb);
                $manifest = $gates->buildCohortManifest($cohortId, $params);
                $gateRes = $gates->validateAllGates($manifest);
                // Strip filesystem paths from gate entries so REST callers never receive server paths (AC-T41-02)
                $cleanGates = [];
                foreach (($gateRes['gates'] ?? []) as $gId => $gInfo) {
                    unset($gInfo['artifact']);
                    $cleanGates[$gId] = $gInfo;
                }
                $result = [
                    'action'             => 'gates',
                    'status'             => $gateRes['all_passed'] ? 'passed' : 'blocked',
                    'all_passed'         => $gateRes['all_passed'],
                    'passed_count'       => $gateRes['passed_count'],
                    'total_gates'        => $gateRes['total_gates'],
                    'failed_gates'       => $gateRes['failed_gates'],
                    'gates'              => $cleanGates,
                    'cohort_id'          => $manifest['cohort_id'],
                    'cohort_hash'        => $manifest['cohort_hash'],
                    'epoch'              => $manifest['epoch'],
                    'total_products'     => $manifest['total_products'],
                    'total_variants'     => $manifest['total_variants'],
                    'gate_receipt_token' => ($actorId === 0) ? $gateRes['gate_receipt_token'] : null,
                ];
                break;

            case 'cutover':
                $cohortId = trim((string)($params['cohort_id'] ?? 'all')) ?: 'all';
                $token = trim((string)($params['gate_receipt_token'] ?? ''));
                if ($token === '') {
                    throw new \InvalidArgumentException("Missing gate_receipt_token for cutover.");
                }
                $productIds = array_values(array_filter(array_map('intval', (array)($params['product_ids'] ?? []))));
                $runner = new \WPE\Application\Rollout\CutoverRunner($this->wpdb);
                $jobId = $runner->startCutover($cohortId, $token, [
                    'product_ids' => $productIds,
                    'target_mode' => 'native_bridge',
                ]);
                $execRes = $runner->executeCheckpoint($jobId);
                $result = array_merge(['action' => 'cutover'], $execRes);
                break;

            case 'hygiene':
                $apply = !empty($params['apply']);
                $result = $this->runPreCutoverHygiene($apply);
                break;

            case 'export':
                $exporter = new SnapshotExporter($this->wpdb);
                $jobId = $exporter->startJob($params);
                $result = [
                    'action' => 'export',
                    'job_id' => $jobId,
                    'status' => 'dispatched',
                ];
                break;

            case 'resume_job':
                $jobId = (string)($params['job_id'] ?? '');
                if (!$jobId) {
                    throw new \InvalidArgumentException("Job ID required for resume_job");
                }
                $this->wpdb->update($this->tableJobs, [
                    'status'     => 'running',
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ], ['id' => $jobId]);

                $result = [
                    'action' => 'resume_job',
                    'job_id' => $jobId,
                    'status' => 'resumed',
                ];
                break;

            default:
                throw new \InvalidArgumentException("Unknown operations action: '{$action}'");
        }

        // 4. Audit logging completion (R167)
        $this->logAudit('operations_action_completed', 0, [
            'action'     => $action,
            'request_id' => $requestId,
            'status'     => $result['status'] ?? 'ok',
        ], $actorId);

        // 5. Sanitize result: zero secret tokens or local filesystem paths (AC-T41-02)
        return $this->sanitizeOutput($result);
    }

    /**
     * Sanitize output to prevent leaking local filesystem paths or secrets (AC-T41-02).
     */
    public function sanitizeOutput(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $clean[$key] = $this->sanitizeOutput($val);
            } elseif (is_string($val)) {
                // Strip absolute local filesystem paths
                $val = preg_replace('#(/var/www/[^\s]+|/home/[^\s]+)#', '[REDACTED_PATH]', $val);
                // Strip secret tokens / query signatures
                $val = preg_replace('#(token|secret|signature|auth)=[^&\s]+#i', '$1=[REDACTED]', $val);
                $clean[$key] = $val;
            } else {
                $clean[$key] = $val;
            }
        }
        return $clean;
    }

    /**
     * One-step import of eligible unmigrated WooCommerce simple/variable products directly into native_bridge (P034).
     * Unsupported product types (grouped, external, bundles, subscriptions) and LegacyOnlyProps (_customs_*)
     * remain untouched on WooCommerce CPT.
     */
    private function importLegacyProducts(array $params, int $actorId = 0): array
    {
        $defaultLimit = $actorId > 0 ? 200 : 500;
        $limit = max(1, (int)($params['limit'] ?? $defaultLimit));

        $candidateIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT p.ID
             FROM {$this->wpdb->posts} p
             LEFT JOIN {$this->tableObjectState} os ON os.object_id = p.ID
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (os.object_id IS NULL OR os.authority = 'legacy')
             ORDER BY p.ID ASC
             LIMIT %d",
            $limit
        )) ?: []);

        $legacyOnlyBlocked = $candidateIds !== []
            ? array_flip(\WPE\Compatibility\WooDataStore\LegacyOnlyProps::productsWithData($this->wpdb, $candidateIds, count($candidateIds) + 1))
            : [];

        $epoch = max(1, $this->mirror->getGlobalEpoch());
        $now = gmdate('Y-m-d H:i:s.u');
        $projector = new CatalogProjector($this->wpdb);
        $activeGen = $projector->getActiveGeneration();
        $hasCatalogRows = $activeGen > 0 && (bool)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM {$this->wpdb->prefix}wpe_catalog WHERE generation = %d LIMIT 1",
            $activeGen
        ));

        $imported = 0;
        $skippedUnsupported = 0;
        $errors = 0;

        foreach ($candidateIds as $productId) {
            if (isset($legacyOnlyBlocked[$productId])) {
                $skippedUnsupported++;
                continue;
            }

            if (function_exists('get_the_terms')) {
                $terms = get_the_terms($productId, 'product_type');
                if (is_array($terms) && !empty($terms)) {
                    $typeSlug = (string)($terms[0]->slug ?? '');
                    if ($typeSlug !== '' && !in_array($typeSlug, ['simple', 'variable'], true)) {
                        $skippedUnsupported++;
                        continue;
                    }
                }
            }

            try {
                $cap = $this->capture->captureLegacyProduct($productId, 'import_legacy');
                if (($cap['status'] ?? '') !== 'captured') {
                    if (($cap['status'] ?? '') === 'blocked_native_authority') {
                        continue;
                    }
                    $skippedUnsupported++;
                    continue;
                }

                $this->wpdb->update($this->tableObjectState, [
                    'authority'      => 'native_bridge',
                    'epoch'          => $epoch,
                    'native_applied' => 1,
                    'tombstone'      => 0,
                    'updated_at'     => $now,
                ], ['object_id' => $productId]);

                $varIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
                    "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL",
                    $productId
                )) ?: []);

                foreach ($varIds as $vid) {
                    $this->wpdb->query($this->wpdb->prepare(
                        "INSERT INTO {$this->tableObjectState}
                         (object_id, kind, parent_id, authority, epoch, legacy_revision, native_revision, legacy_applied, native_applied, tombstone, updated_at)
                         VALUES (%d, 'variation', %d, 'native_bridge', %d, 1, 1, 1, 1, 0, %s)
                         ON DUPLICATE KEY UPDATE
                         parent_id = VALUES(parent_id), authority = 'native_bridge', epoch = GREATEST(epoch, VALUES(epoch)), native_applied = 1, tombstone = 0, updated_at = VALUES(updated_at)",
                        $vid,
                        $productId,
                        $epoch,
                        $now
                    ));
                    \WPE\Support\AuthorityResolver::evictObject($vid);
                }

                \WPE\Support\AuthorityResolver::evictObject($productId);

                if ($hasCatalogRows) {
                    $projector->projectProduct($productId);
                }

                $imported++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        if ($imported > 0) {
            $this->wpdb->replace($this->tableControl, [
                'key'        => 'system_mode',
                'value'      => 'native_bridge',
                'version'    => 1,
                'updated_at' => $now,
            ]);
            if (class_exists(\WPE\Bootstrap\PluginBootstrap::class)) {
                $flags = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS);
                if (!is_array($flags)) {
                    $flags = \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS;
                }
                $flags['native_writes_enabled'] = true;
                $flags['cohort_mode'] = 'native_bridge';
                update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, $flags);
                \WPE\Bootstrap\PluginBootstrap::instance()->loadFlags();
            }

            if (!$hasCatalogRows) {
                $activeGen = $projector->rebuild();
            }
        }

        $remainingCandidates = array_map('intval', $this->wpdb->get_col(
            "SELECT p.ID
             FROM {$this->wpdb->posts} p
             LEFT JOIN {$this->tableObjectState} os ON os.object_id = p.ID
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash', 'auto-draft')
               AND (os.object_id IS NULL OR os.authority = 'legacy')"
        ) ?: []);
        $remainingBlocked = $remainingCandidates !== []
            ? array_flip(\WPE\Compatibility\WooDataStore\LegacyOnlyProps::productsWithData($this->wpdb, $remainingCandidates, count($remainingCandidates) + 1))
            : [];
        $remainingEligible = 0;
        foreach ($remainingCandidates as $cid) {
            if (isset($remainingBlocked[$cid])) {
                continue;
            }
            if (function_exists('get_the_terms')) {
                $terms = get_the_terms($cid, 'product_type');
                if (is_array($terms) && !empty($terms)) {
                    $slug = (string)($terms[0]->slug ?? '');
                    if ($slug !== '' && !in_array($slug, ['simple', 'variable'], true)) {
                        continue;
                    }
                }
            }
            $remainingEligible++;
        }

        return [
            'action'              => 'import_legacy',
            'status'              => 'completed',
            'imported'            => $imported,
            'skipped_unsupported' => $skippedUnsupported,
            'errors'              => $errors,
            'remaining_eligible'  => $remainingEligible,
            'generation'          => $activeGen,
        ];
    }

    /**
     * Inspect or clean pre-cutover test/import residue without touching real products or order references (D06).
     */
    private function runPreCutoverHygiene(bool $apply = false): array
    {
        $prefix = $this->wpdb->prefix;
        $orderItemMeta = "{$prefix}woocommerce_order_itemmeta";
        $hasOrderItemMeta = ($this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $orderItemMeta)) === $orderItemMeta);
        $orderRefGuardProd = $hasOrderItemMeta
            ? "AND wp.id NOT IN (SELECT CAST(meta_value AS UNSIGNED) FROM {$orderItemMeta} WHERE meta_key IN ('_product_id', '_variation_id'))"
            : '';
        $orderRefGuardPost = $hasOrderItemMeta
            ? "AND p.ID NOT IN (SELECT CAST(meta_value AS UNSIGNED) FROM {$orderItemMeta} WHERE meta_key IN ('_product_id', '_variation_id'))"
            : '';

        // 1. Native rows missing a published/draft/private/pending/trash WP post shell (e.g. deleted shell or stranded auto-draft)
        $shelllessNativeIds = array_values(array_map('intval', $this->wpdb->get_col(
            "SELECT wp.id FROM {$this->tableProducts} wp
             LEFT JOIN {$this->wpdb->posts} p ON p.ID = wp.id
             WHERE (p.ID IS NULL OR p.post_status = 'auto-draft') {$orderRefGuardProd}"
        )));

        // 2. Empty WP auto-draft shells not backing any active native product/variant
        $orphanAutoDraftIds = array_values(array_map('intval', $this->wpdb->get_col(
            "SELECT p.ID FROM {$this->wpdb->posts} p
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status = 'auto-draft'
               AND p.ID NOT IN (SELECT id FROM {$this->tableProducts})
               AND p.ID NOT IN (SELECT id FROM {$this->tableVariants})
               {$orderRefGuardPost}"
        )));

        // 3. Orphan object_state rows referencing nonexistent objects
        $orphanStateIds = array_values(array_map('intval', $this->wpdb->get_col(
            "SELECT os.object_id FROM {$this->tableObjectState} os
             WHERE os.object_id NOT IN (SELECT id FROM {$this->tableProducts})
               AND os.object_id NOT IN (SELECT id FROM {$this->tableVariants})
               AND os.object_id NOT IN (SELECT ID FROM {$this->wpdb->posts})"
        )));

        $summary = [
            'action'                   => 'hygiene',
            'applied'                  => $apply,
            'shellless_native_count'   => count($shelllessNativeIds),
            'orphan_auto_draft_count'  => count($orphanAutoDraftIds),
            'orphan_object_state_count'=> count($orphanStateIds),
            'status'                   => $apply ? 'completed' : 'dry_run',
        ];

        if (!$apply) {
            return $summary;
        }

        if (!empty($shelllessNativeIds)) {
            $inP = implode(',', $shelllessNativeIds);
            $varIds = array_values(array_map('intval', $this->wpdb->get_col(
                "SELECT id FROM {$this->tableVariants} WHERE product_id IN ({$inP})"
            )));
            $allOwners = array_values(array_unique(array_merge($shelllessNativeIds, $varIds)));
            $inOwners = implode(',', $allOwners);

            if (!empty($varIds)) {
                $inV = implode(',', $varIds);
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE variant_id IN ({$inV})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$inV})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_catalog_facets WHERE variant_id IN ({$inV})");
                $this->wpdb->query("DELETE FROM {$this->tableVariants} WHERE id IN ({$inV})");
            }

            $this->wpdb->query("DELETE ov FROM {$prefix}wpe_option_values ov INNER JOIN {$prefix}wpe_options o ON o.id = ov.option_id WHERE o.product_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_options WHERE product_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_catalog_facets WHERE product_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_catalog WHERE product_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_terms WHERE product_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_links WHERE product_id IN ({$inP}) OR target_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$this->tableInventory} WHERE owner_id IN ({$inOwners})");
            $this->wpdb->query("DELETE FROM {$this->tableObjectState} WHERE object_id IN ({$inOwners}) OR parent_id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_sku_registry WHERE object_id IN ({$inOwners})");
            $this->wpdb->query("DELETE FROM {$this->tableMeta} WHERE object_id IN ({$inOwners})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_downloads WHERE object_id IN ({$inOwners})");
            $this->wpdb->query("DELETE FROM {$this->tableProducts} WHERE id IN ({$inP})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->postmeta} WHERE post_id IN ({$inOwners})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->term_relationships} WHERE object_id IN ({$inOwners})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ({$inOwners}) AND post_status = 'auto-draft'");

            foreach ($shelllessNativeIds as $spid) {
                $this->wpdb->delete($this->tableControl, ['key' => 'shopify_import_fp:' . $spid]);
            }
        }

        if (!empty($orphanAutoDraftIds)) {
            foreach (array_chunk($orphanAutoDraftIds, 200) as $chunk) {
                $inO = implode(',', $chunk);
                $this->wpdb->query("DELETE FROM {$this->wpdb->postmeta} WHERE post_id IN ({$inO})");
                $this->wpdb->query("DELETE FROM {$this->wpdb->term_relationships} WHERE object_id IN ({$inO})");
                $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ({$inO}) AND post_status = 'auto-draft'");
            }
        }

        // Re-evaluate orphan object_state rows after product cleanup
        $this->wpdb->query(
            "DELETE FROM {$this->tableObjectState}
             WHERE object_id NOT IN (SELECT id FROM {$this->tableProducts})
               AND object_id NOT IN (SELECT id FROM {$this->tableVariants})
               AND object_id NOT IN (SELECT ID FROM {$this->wpdb->posts})"
        );

        $this->logAudit('pre_cutover_hygiene_applied', 0, $summary);
        return $summary;
    }

    private function logAudit(string $operation, int $targetId, array $payload, int $actorId = 0): void
    {
        $this->wpdb->insert($this->tableAudit, [
            'actor_id'   => $actorId ?: (get_current_user_id() ?: null),
            'request_id' => wp_generate_uuid4(),
            'operation'  => $operation,
            'target_id'  => $targetId,
            'summary'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
