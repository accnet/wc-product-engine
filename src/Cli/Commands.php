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
        \WP_CLI::add_command('wpe lookup', [self::class, 'lookup']);
        \WP_CLI::add_command('wpe mode', [self::class, 'mode']);
        \WP_CLI::add_command('wpe outbox', [self::class, 'outbox']);
        \WP_CLI::add_command('wpe import-legacy', [self::class, 'importLegacy']);
        \WP_CLI::add_command('wpe gates', [self::class, 'gates']);
        \WP_CLI::add_command('wpe cutover', [self::class, 'cutover']);
        \WP_CLI::add_command('wpe hygiene', [self::class, 'hygiene']);
        \WP_CLI::add_command('wpe feeds', [self::class, 'feeds']);
    }

    /**
     * Product feeds (P035, 10 §12).
     *
     * Usage:
     *   wp wpe feeds list
     *   wp wpe feeds tick [--seconds=<int>] [--quiet]      (system cron, every minute)
     *   wp wpe feeds build <id> [--seconds=<int>]          (full rebuild; 0 = until done)
     *   wp wpe feeds publish <id> [--force]
     *   wp wpe feeds status <id>
     */
    public static function feeds(array $args, array $assocArgs): void
    {
        $sub = (string)($args[0] ?? 'list');
        $builder = new \WPE\Application\Feeds\FeedBuilder();
        $repo = $builder->feeds();
        $id = (int)($args[1] ?? 0);

        if ($sub === 'list') {
            $rows = array_map(static fn(array $f): array => [
                'id' => $f['id'], 'name' => $f['name'], 'platform' => $f['platform'], 'format' => $f['format'],
                'enabled' => $f['enabled'] ? 'yes' : 'no', 'status' => $f['status'],
                'items' => (int)($f['stats']['published']['items'] ?? 0), 'published' => (string)$f['last_published_at'],
            ], $repo->list());
            \WP_CLI\Utils\format_items('table', $rows, ['id', 'name', 'platform', 'format', 'enabled', 'status', 'items', 'published']);
            return;
        }

        if ($sub === 'tick') {
            if (!class_exists(\WPE\Application\Feeds\FeedScheduler::class)) {
                \WP_CLI::error('Feed scheduler is not available.');
            }
            $res = (new \WPE\Application\Feeds\FeedScheduler($builder))->tick((float)($assocArgs['seconds'] ?? 50));
            if (empty($assocArgs['quiet'])) {
                \WP_CLI::log((string)json_encode($res, JSON_PRETTY_PRINT));
            }
            return;
        }

        if ($id <= 0 || !$repo->get($id)) {
            \WP_CLI::error('Pass an existing feed ID.');
        }

        if ($sub === 'build') {
            $feed = $repo->get($id);
            if ((int)$feed['build_generation'] === 0) {
                $repo->requestRebuild($id, 'cli');
            }
            $seconds = (float)($assocArgs['seconds'] ?? 0);
            do {
                $res = $builder->buildStep($id, $seconds > 0 ? $seconds : 30.0);
                \WP_CLI::log(sprintf('%s: %d/%d products', $res['status'], $res['done'], $res['total']));
            } while ($seconds <= 0 && $res['status'] === 'building');
            if (isset($res['published'])) {
                \WP_CLI::log((string)json_encode($res['published']));
            }
            \WP_CLI::success('Build step finished: ' . $res['status']);
            return;
        }

        if ($sub === 'publish') {
            $res = $builder->publish($id, !empty($assocArgs['force']));
            \WP_CLI::log((string)json_encode($res));
            $res['status'] === 'published' ? \WP_CLI::success('Published.') : \WP_CLI::warning('Not published: ' . ($res['reason'] ?? $res['status']));
            return;
        }

        if ($sub === 'status') {
            \WP_CLI::log((string)json_encode($repo->view($repo->get($id), false) + ['runs' => $builder->runs($id, 5)], JSON_PRETTY_PRINT));
            return;
        }

        \WP_CLI::error("Unknown subcommand '{$sub}'.");
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
     *
     * ## OPTIONS
     *
     * [--if-requested]
     * : Only rebuild when the admin queued a rebuild (large catalogs). Meant for system cron, e.g.
     *   `* * * * * wp --path=<root> wpe rebuild --if-requested`.
     */
    public static function rebuild(array $args, array $assocArgs): void
    {
        if (!empty($assocArgs['if-requested'])) {
            $projector = new \WPE\Application\Catalog\CatalogProjector();
            $request = $projector->pendingRebuildRequest();
            if ($request === null) {
                \WP_CLI::log('No catalog rebuild requested.');
                return;
            }
            \WP_CLI::log('Rebuild requested at ' . ($request['requested_at'] ?? '?') . '; rebuilding...');
            try {
                $gen = $projector->rebuild();
            } catch (\RuntimeException $e) {
                if ((int)$e->getCode() === 409) {
                    \WP_CLI::log($e->getMessage());
                    return;
                }
                throw $e;
            }
            \WP_CLI::success("Catalog index rebuild completed: Generation {$gen}");
            return;
        }

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
     *   wp wpe media backfill-attachments [--limit=<int>]
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

        if ($sub === 'backfill-attachments') {
            // Creates the attachment posts that display filters no longer create on page views (audit F07).
            $res = \WPE\Compatibility\Gallery\AttachmentBridge::instance()->backfillMissingAttachments($limit);
            \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
            if ($res['failed'] > 0) {
                \WP_CLI::error("{$res['failed']} attachment(s) could not be created; see the PHP error log.");
            }
            \WP_CLI::success("Created {$res['created']} of {$res['checked']} missing attachments.");
            return;
        }

        \WP_CLI::error("Unknown media subcommand '{$sub}'. Use: report, clean-uploads, delete-file, backfill-attachments.");
    }

    /**
     * Rebuild WooCommerce's wc_product_meta_lookup rows for engine products (audit F04).
     *
     * Run once after installing this fix, and again whenever the lookup table was emptied or
     * regenerated by WooCommerce's own tool (which reads a single _price row on variable parents).
     *
     * Usage:
     *   wp wpe lookup rebuild [--batch=<int>]
     */
    public static function lookup(array $args, array $assocArgs): void
    {
        $sub = $args[0] ?? '';
        if ($sub !== 'rebuild') {
            \WP_CLI::error("Unknown lookup subcommand '{$sub}'. Use: rebuild.");
        }
        if (!class_exists(\WC_Product_Data_Store_CPT::class)) {
            \WP_CLI::error('WooCommerce is not active.');
        }
        $batch = isset($assocArgs['batch']) ? max(1, (int)$assocArgs['batch']) : 100;
        $res = (new \WPE\Compatibility\WooDataStore\LookupTableProjector())->rebuildAll($batch, static function (int $done): void {
            \WP_CLI::log("Projected {$done} products...");
        });
        \WP_CLI::success("Rebuilt {$res['rows']} lookup rows for {$res['products']} products.");
    }

    /**
     * Inspect or switch pre-cutover system mode (legacy | shadow | indexed).
     *
     * Usage:
     *   wp wpe mode [<legacy|shadow|indexed>]
     */
    public static function mode(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $target = $args[0] ?? '';
        $res = $ops->handleAction('mode', ['mode' => $target], 0);
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
        if ($target !== '') {
            \WP_CLI::success("System mode switched to '{$res['system_mode']}'.");
        }
    }

    /**
     * Drain outbox projections (LegacyMirror + incremental CatalogProjector).
     *
     * Usage:
     *   wp wpe outbox [--batch-size=<int>] [--max-batches=<int>] [--product-ids=<ids>]
     */
    public static function outbox(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $productIds = !empty($assocArgs['product-ids'])
            ? array_map('intval', explode(',', (string)$assocArgs['product-ids']))
            : [];
        $res = $ops->handleAction('outbox', [
            'batch_size'  => (int)($assocArgs['batch-size'] ?? 100),
            'max_batches' => (int)($assocArgs['max-batches'] ?? 100),
            'product_ids' => $productIds,
        ], 0);
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
        \WP_CLI::success(sprintf(
            "Outbox drained: %d completed (%d mirrored, %d retired stale), %d remaining.",
            $res['completed'] ?? 0,
            $res['mirrored'] ?? 0,
            $res['retired_stale'] ?? 0,
            $res['remaining_pending'] ?? 0
        ));
    }

    /**
     * One-step import of eligible unmigrated WooCommerce simple/variable products directly into native_bridge (P034).
     *
     * Usage:
     *   wp wpe import-legacy [--limit=<int>]
     */
    public static function importLegacy(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $limit = isset($assocArgs['limit']) ? max(1, (int)$assocArgs['limit']) : 500;
        $res = $ops->handleAction('import_legacy', ['limit' => $limit], 0);
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
        \WP_CLI::success(sprintf(
            "Imported %d legacy product(s) into native_bridge (%d unsupported skipped, %d eligible remaining).",
            $res['imported'] ?? 0,
            $res['skipped_unsupported'] ?? 0,
            $res['remaining_eligible'] ?? 0
        ));
    }

    /**
     * Validate G01–G12 readiness gates and emit a short-lived gate_receipt_token.
     *
     * Usage:
     *   wp wpe gates [--cohort=<all|id>] [--format=<table|json>]
     */
    public static function gates(array $args, array $assocArgs): void
    {
        $ops = OperationsPage::getInstance();
        $cohortId = (string)($assocArgs['cohort'] ?? 'all');
        $res = $ops->handleAction('gates', ['cohort_id' => $cohortId], 0);

        if (($assocArgs['format'] ?? 'table') === 'json') {
            \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
            return;
        }

        \WP_CLI::log("=== Readiness Gates ({$res['passed_count']}/{$res['total_gates']}) — Cohort: {$res['cohort_id']} ===");
        foreach (($res['gates'] ?? []) as $gId => $g) {
            \WP_CLI::log(sprintf("  %-16s %-10s %s", $gId, strtoupper((string)$g['status']), $g['name'] ?? ''));
        }
        if ($res['all_passed']) {
            \WP_CLI::log("Token: " . ($res['gate_receipt_token'] ?? ''));
            \WP_CLI::success("All readiness gates passed.");
        } else {
            \WP_CLI::error("Gates blocked: " . implode(', ', $res['failed_gates'] ?? []));
        }
    }

    /**
     * Execute governed cutover to native_bridge using a verified gate_receipt_token.
     *
     * Usage:
     *   wp wpe cutover --token=<receipt> [--cohort=<all|id>] [--product-ids=<ids>]
     */
    public static function cutover(array $args, array $assocArgs): void
    {
        $token = (string)($assocArgs['token'] ?? '');
        if ($token === '') {
            \WP_CLI::error("Usage: wp wpe cutover --token=<receipt> [--cohort=<all|id>] [--product-ids=<ids>]");
            return;
        }
        $cohortId = (string)($assocArgs['cohort'] ?? 'all');
        $productIds = !empty($assocArgs['product-ids'])
            ? array_map('intval', explode(',', (string)$assocArgs['product-ids']))
            : [];

        $ops = OperationsPage::getInstance();
        $res = $ops->handleAction('cutover', [
            'cohort_id'          => $cohortId,
            'gate_receipt_token' => $token,
            'product_ids'        => $productIds,
        ], 0);
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
        \WP_CLI::success("Cutover completed for cohort '{$cohortId}' at epoch " . ($res['new_epoch'] ?? '?') . ".");
    }

    /**
     * Inspect (dry-run default) or clean pre-cutover orphan shells and state rows.
     *
     * Usage:
     *   wp wpe hygiene [--apply]
     */
    public static function hygiene(array $args, array $assocArgs): void
    {
        $apply = !empty($assocArgs['apply']);
        $ops = OperationsPage::getInstance();
        $res = $ops->handleAction('hygiene', ['apply' => $apply], 0);
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT));
        if ($apply) {
            \WP_CLI::success("Pre-cutover hygiene cleanup applied.");
        } else {
            \WP_CLI::log("Dry-run only. Re-run with --apply to remove unreferenced residue.");
        }
    }
}
