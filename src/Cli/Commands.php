<?php

declare(strict_types=1);

namespace WPE\Cli;

use WPE\Admin\OperationsPage;

/**
 * Class Commands
 *
 * WP-CLI command integration for WC Product Engine operations.
 * Delegates directly to OperationsPage command handler to ensure 100%
 * parity between CLI operations and WordPress Admin UI actions (R166, AC-T41-01).
 *
 * Usage:
 *   wp wpe health
 *   wp wpe migrate [--batch-size=<int>] [--start-id=<int>] [--max-id=<int>]
 *   wp wpe rebuild
 *   wp wpe audit [--limit=<int>]
 *   wp wpe rollback [--cohort-ids=<ids>] [--timeout=<sec>]
 *   wp wpe export [--format=<woo|shopify>] [--profile=<machine|spreadsheet_safe>]
 */
class Commands
{
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('wpe health', [self::class, 'health']);
        \WP_CLI::add_command('wpe migrate', [self::class, 'migrate']);
        \WP_CLI::add_command('wpe rebuild', [self::class, 'rebuild']);
        \WP_CLI::add_command('wpe audit', [self::class, 'audit']);
        \WP_CLI::add_command('wpe rollback', [self::class, 'rollback']);
        \WP_CLI::add_command('wpe export', [self::class, 'export']);
        \WP_CLI::add_command('wpe media', [self::class, 'media']);
    }

    /**
     * Display current engine health, status counters, and alerts (R165, AC-T41-01).
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render format ('json', 'table', 'yaml'). Default 'table'.
     */
    public static function health(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $health = $ops->getHealthReport();

        $format = $assocArgs['format'] ?? 'table';

        if ($format === 'json') {
            \WP_CLI::log(json_encode($health, JSON_PRETTY_PRINT));
            return;
        }

        \WP_CLI::log("=== WC Product Engine Health Status ===");
        \WP_CLI::log("Status:           " . strtoupper($health['status']));
        \WP_CLI::log("System Mode:      " . $health['system_mode']);
        \WP_CLI::log("Authority Epoch:  " . $health['authority_epoch']);
        \WP_CLI::log("Native Products:  " . $health['counts']['native_products'] . " (Authority: " . $health['counts']['native_authority'] . ")");
        \WP_CLI::log("Legacy Products:  " . $health['counts']['legacy_products'] . " (Authority: " . $health['counts']['legacy_authority'] . ")");
        \WP_CLI::log("Mismatches:       " . $health['mismatches']);
        \WP_CLI::log("DLQ Failures:     " . $health['dlq_count']);
        \WP_CLI::log("Outbox Pending:   " . $health['outbox_pending']);
        \WP_CLI::log("Projection Lag:   " . ($health['projection_lag']['pending_projections'] ?? 0) . " pending");

        if (!empty($health['worker_starvation']['alert'])) {
            \WP_CLI::warning("Worker Starvation Alert: Active job " . $health['worker_starvation']['starved_job_id'] . " has had no heartbeat for >120s!");
        }

        if ($health['is_healthy']) {
            \WP_CLI::success("Engine is healthy.");
        } else {
            \WP_CLI::warning("Engine status is " . $health['status'] . ". Review mismatches or DLQ queue.");
        }
    }

    /**
     * Run keyset-based catalog backfill batch.
     *
     * ## OPTIONS
     *
     * [--batch-size=<int>]
     * : Batch size per chunk. Default 100.
     *
     * [--start-id=<int>]
     * : Starting product ID.
     *
     * [--max-id=<int>]
     * : Maximum product ID.
     */
    public static function migrate(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $params = [
            'batch_size' => (int)($assocArgs['batch_size'] ?? 100),
            'start_id'   => (int)($assocArgs['start-id'] ?? 0),
            'max_id'     => (int)($assocArgs['max-id'] ?? 0),
        ];

        $res = $ops->handleAction('migrate', $params, 0);
        \WP_CLI::success("Backfill batch completed: Job ID " . ($res['job_id'] ?? 'unknown'));
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
    }

    /**
     * Rebuild catalog and facet index generation.
     */
    public static function rebuild(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $res = $ops->handleAction('rebuild', [], 0);
        \WP_CLI::success("Catalog index rebuild completed: Generation " . ($res['generation'] ?? ''));
    }

    /**
     * Run shadow diff audit between legacy and native catalogs (R165).
     *
     * ## OPTIONS
     *
     * [--limit=<int>]
     * : Maximum products to check. Default 100.
     */
    public static function audit(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $params = ['limit' => (int)($assocArgs['limit'] ?? 100)];
        $res = $ops->handleAction('audit', $params, 0);

        \WP_CLI::log("Shadow Diff Audit Results:");
        \WP_CLI::log("Checked:    " . $res['total_checked']);
        \WP_CLI::log("Mismatches: " . $res['mismatch_count']);
        \WP_CLI::log("Quiescent:  " . ($res['is_quiescent'] ? 'Yes' : 'No'));

        if ($res['is_quiescent']) {
            \WP_CLI::success("Catalogs are in complete quiescent parity.");
        } else {
            \WP_CLI::warning("Found " . $res['mismatch_count'] . " mismatches.");
        }
    }

    /**
     * Execute orchestrated rollback from native back to legacy authority.
     *
     * ## OPTIONS
     *
     * [--cohort-ids=<ids>]
     * : Comma-separated list of product IDs to rollback. Default all native.
     *
     * [--timeout=<sec>]
     * : Timeout budget in seconds. Default 30.
     */
    public static function rollback(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $cohortIds = [];
        if (!empty($assocArgs['cohort-ids'])) {
            $cohortIds = array_map('intval', explode(',', $assocArgs['cohort-ids']));
        }

        $params = [
            'cohort_ids'      => $cohortIds,
            'timeout_seconds' => (float)($assocArgs['timeout'] ?? 30.0),
        ];

        $res = $ops->handleAction('rollback', $params, 0);
        if (($res['status'] ?? '') === 'completed') {
            \WP_CLI::success("Rollback completed successfully.");
        } else {
            \WP_CLI::error("Rollback aborted: " . ($res['status'] ?? 'unknown'));
        }
    }

    /**
     * Start snapshot export job.
     */
    public static function export(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $params = [
            'format'  => $assocArgs['format'] ?? 'woo',
            'profile' => $assocArgs['profile'] ?? 'machine',
        ];

        $res = $ops->handleAction('export', $params, 0);
        \WP_CLI::success("Export job dispatched: " . ($res['job_id'] ?? 'unknown'));
    }

    /**
     * Per-product media recovery and orphan cleanup commands (P011.11).
     *
     * Usage:
     *   wp wpe media report [--limit=<int>]
     *   wp wpe media clean-uploads [--limit=<int>]
     *   wp wpe media delete-file <asset-id>
     */
    public static function media(array $args, array $assocArgs): void
    {
        $sub = $args[0] ?? 'report';
        $cleanup = new \WPE\Application\Media\OrphanCleanup();
        $limit = isset($assocArgs['limit']) ? max(1, (int)$assocArgs['limit']) : 100;

        if ($sub === 'report') {
            $report = $cleanup->report();
            \WP_CLI::log(json_encode($report, JSON_PRETTY_PRINT));
            return;
        }

        if ($sub === 'clean-uploads') {
            $res = $cleanup->cleanStaleUploads(null, $limit);
            \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
            \WP_CLI::success("Cleaned {$res['cleaned']} stale upload intents.");
            return;
        }

        if ($sub === 'delete-file') {
            $assetId = isset($args[1]) ? (int)$args[1] : 0;
            if ($assetId <= 0) {
                \WP_CLI::error('Usage: wp wpe media delete-file <asset-id>');
                return;
            }
            try {
                $res = $cleanup->deleteFile($assetId);
                \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
                \WP_CLI::success("Deleted managed file for asset {$assetId}.");
            } catch (\Throwable $e) {
                \WP_CLI::error($e->getMessage());
            }
            return;
        }

        \WP_CLI::error("Unknown media subcommand '{$sub}'. Use: report, clean-uploads, delete-file.");
    }
}
