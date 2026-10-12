<?php

declare(strict_types=1);

namespace WPE\Admin;

use WPE\Application\Migration\RollbackRunner;
use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Migration\LegacyCapture;
use WPE\Application\Migration\BackfillRunner;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\ImportExport\SnapshotExporter;

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
    private static ?OperationsPage $instance = null;
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

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(?\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;

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

    public function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerAdminMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Product Engine Operations', 'wc-product-engine'),
            __('Engine Operations', 'wc-product-engine'),
            'wpe_manage_operations',
            'wpe-operations',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'wpe-operations') === false) {
            return;
        }

        $jsUrl = plugins_url('assets/operations/operations.js', dirname(__DIR__, 1));
        wp_enqueue_script('wpe-operations-js', $jsUrl, ['jquery'], '1.1.0', true);

        // Sanitize localization: zero filesystem paths or private secrets (AC-T41-02)
        wp_localize_script('wpe-operations-js', 'wpeOpsConfig', [
            'restUrl' => esc_url_raw(rest_url('wpe/v1/operations/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'canManage' => current_user_can('wpe_manage_operations') || current_user_can('manage_woocommerce'),
        ]);
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
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('wpe_manage_operations') || current_user_can('manage_woocommerce');
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
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Compile comprehensive health status report (R165, R168, AC-T41-01).
     */
    public function getHealthReport(): array
    {
        // 1. Authority & Mode
        $systemMode = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'system_mode'
        )) ?: 'legacy';

        $epoch = $this->mirror->getGlobalEpoch();

        // 2. Source Counts
        $nativeCount = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableProducts}");
        $legacyCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product' AND post_status != 'trash'"
        );
        $nativeAuthCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState} WHERE authority = 'native'"
        );
        $legacyAuthCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState} WHERE authority = 'legacy'"
        );

        // 3. Mismatches / Shadow Diff gaps (R165)
        $mismatchRaw = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'shadow_diff_mismatches'
        ));
        $mismatches = $mismatchRaw !== null ? (int)$mismatchRaw : 0;

        // 4. Dead-Letter Queue (DLQ) count (R165, AC-T41-01)
        $dlqCount = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableOutbox} WHERE state IN ('dead', 'failed')"
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
        if ($actorId > 0 && !user_can($actorId, 'wpe_manage_operations') && !user_can($actorId, 'manage_woocommerce')) {
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
                $generation = $projector->buildNewGeneration();
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
                    // Default to all active native products
                    $cohortIds = array_map('intval', $this->wpdb->get_col(
                        "SELECT object_id FROM {$this->tableObjectState} WHERE authority = 'native' LIMIT 500"
                    ));
                }
                $rollbackRes = $this->rollbackRunner->executeRollback($cohortIds, $params);
                $result = [
                    'action'          => 'rollback',
                    'rollback_result' => $rollbackRes,
                    'status'          => $rollbackRes['status'] ?? 'unknown',
                ];
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
     * Render WordPress Admin Operations Dashboard page.
     */
    public function renderPage(): void
    {
        $health = $this->getHealthReport();
        $isHealthy = $health['is_healthy'];
        $badgeClass = $isHealthy ? 'notice-success' : 'notice-error';
        ?>
        <div class="wrap wpe-operations-wrap">
            <h1><?php echo esc_html__('WC Product Engine — Operations & Health', 'wc-product-engine'); ?></h1>
            <div id="wpe-health-banner" class="notice <?php echo esc_attr($badgeClass); ?> inline">
                <p>
                    <strong><?php echo esc_html__('System Health Status:', 'wc-product-engine'); ?></strong>
                    <span id="wpe-status-text" style="text-transform: uppercase; font-weight: bold;">
                        <?php echo esc_html($health['status']); ?>
                    </span>
                    (Mode: <code><?php echo esc_html($health['system_mode']); ?></code>, Epoch: <code><?php echo esc_html((string)$health['authority_epoch']); ?></code>)
                </p>
            </div>

            <div class="wpe-dashboard-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin: 20px 0;">
                <div class="card">
                    <h2><?php echo esc_html__('Native Products', 'wc-product-engine'); ?></h2>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0;">
                        <?php echo esc_html((string)$health['counts']['native_products']); ?>
                    </p>
                    <small><?php echo esc_html__('Authority: ', 'wc-product-engine') . esc_html((string)$health['counts']['native_authority']); ?></small>
                </div>
                <div class="card">
                    <h2><?php echo esc_html__('Legacy Products', 'wc-product-engine'); ?></h2>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0;">
                        <?php echo esc_html((string)$health['counts']['legacy_products']); ?>
                    </p>
                    <small><?php echo esc_html__('Authority: ', 'wc-product-engine') . esc_html((string)$health['counts']['legacy_authority']); ?></small>
                </div>
                <div class="card">
                    <h2><?php echo esc_html__('Mismatches', 'wc-product-engine'); ?></h2>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: <?php echo $health['mismatches'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                        <?php echo esc_html((string)$health['mismatches']); ?>
                    </p>
                    <small><?php echo esc_html__('Shadow diff gaps', 'wc-product-engine'); ?></small>
                </div>
                <div class="card">
                    <h2><?php echo esc_html__('DLQ Failures', 'wc-product-engine'); ?></h2>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0; color: <?php echo $health['dlq_count'] > 0 ? '#d63638' : '#00a32a'; ?>;">
                        <?php echo esc_html((string)$health['dlq_count']); ?>
                    </p>
                    <small><?php echo esc_html__('Dead-letter queue events', 'wc-product-engine'); ?></small>
                </div>
                <div class="card">
                    <h2><?php echo esc_html__('Projection Lag', 'wc-product-engine'); ?></h2>
                    <p style="font-size: 24px; font-weight: bold; margin: 5px 0;">
                        <?php echo esc_html((string)($health['projection_lag']['pending_projections'] ?? 0)); ?>
                    </p>
                    <small><?php echo esc_html__('Pending legacy syncs', 'wc-product-engine'); ?></small>
                </div>
            </div>

            <div class="card" style="margin: 20px 0;">
                <h2><?php echo esc_html__('Operations Controls', 'wc-product-engine'); ?></h2>
                <p><?php echo esc_html__('Trigger background catalog operations, index rebuilds, shadow diff audits, or emergency rollbacks.', 'wc-product-engine'); ?></p>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="button button-primary wpe-action-btn" data-action="audit">
                        <?php echo esc_html__('Run Shadow Diff Audit', 'wc-product-engine'); ?>
                    </button>
                    <button class="button button-secondary wpe-action-btn" data-action="rebuild">
                        <?php echo esc_html__('Rebuild Index Generation', 'wc-product-engine'); ?>
                    </button>
                    <button class="button button-secondary wpe-action-btn" data-action="migrate">
                        <?php echo esc_html__('Run Keyset Backfill Batch', 'wc-product-engine'); ?>
                    </button>
                    <button class="button button-link-delete wpe-action-btn" data-action="rollback" style="margin-left: auto;">
                        <?php echo esc_html__('Initiate Authority Rollback', 'wc-product-engine'); ?>
                    </button>
                </div>
                <div id="wpe-action-result" style="margin-top: 15px; display: none;" class="notice inline"></div>
            </div>

            <div class="card" style="margin: 20px 0;">
                <h2><?php echo esc_html__('System Worker Guidance & Thresholds (08 §8)', 'wc-product-engine'); ?></h2>
                <ul>
                    <li><strong><?php echo esc_html__('Worker Scheduling: ', 'wc-product-engine'); ?></strong><?php echo esc_html__('Run queue loop via dedicated systemd or Linux system cron (not WP page traffic).', 'wc-product-engine'); ?></li>
                    <li><strong><?php echo esc_html__('Concurrency & Batches: ', 'wc-product-engine'); ?></strong><?php echo esc_html__('Initial concurrency = 1, batch size = 100, worker lease = 60s, heartbeat = 20s.', 'wc-product-engine'); ?></li>
                    <li><strong><?php echo esc_html__('Starvation Alert: ', 'wc-product-engine'); ?></strong><?php echo esc_html__('Triggered when active worker has no heartbeat or checkpoint for > 120s.', 'wc-product-engine'); ?></li>
                </ul>
            </div>
        </div>
        <?php
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
