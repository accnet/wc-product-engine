<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

use RuntimeException;

/**
 * Full rebuilds by generation (D08), incremental product refreshes, and publishing a validated
 * file from the item store (D09).
 *
 * Work is bounded: a build step processes products until its time budget runs out and keeps its
 * cursor in the feed's stats, so a cron tick, the CLI or an admin request can each continue it.
 * Every step of a feed runs under a per-feed MySQL named lock.
 */
final class FeedBuilder
{
    /** Products per batch: variable products carry many variations, so keep batches small. */
    public const BATCH = 25;
    public const RUN_RETENTION = 50;

    private \wpdb $wpdb;
    private FeedRepository $feeds;
    private FeedItemStore $store;
    private FeedItemSource $source;

    public function __construct(?\wpdb $wpdb = null, ?FeedRepository $feeds = null, ?FeedItemStore $store = null, ?FeedItemSource $source = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->feeds = $feeds ?? new FeedRepository($this->wpdb);
        $this->store = $store ?? new FeedItemStore($this->wpdb);
        $this->source = $source ?? new FeedItemSource($this->wpdb);
    }

    public function feeds(): FeedRepository
    {
        return $this->feeds;
    }

    public function store(): FeedItemStore
    {
        return $this->store;
    }

    public function source(): FeedItemSource
    {
        return $this->source;
    }

    /* ------------------------------------------------------------------ */
    /* Locking                                                            */
    /* ------------------------------------------------------------------ */

    public function withLock(int $feedId, callable $fn): mixed
    {
        $name = substr($this->wpdb->prefix . 'wpe_feed_' . $feedId, 0, 64);
        if ((int)$this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== 1) {
            throw new RuntimeException('Another process is working on this feed. Try again shortly.', 409);
        }
        try {
            return $fn();
        } finally {
            $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Full rebuild                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Advance a full rebuild for up to $seconds (0 = until done). Starts one when a rebuild was
     * requested. On completion switches generations and publishes.
     *
     * @return array{status:string,done:int,total:int,published?:array}
     */
    public function buildStep(int $feedId, float $seconds = 0.0): array
    {
        return $this->withLock($feedId, function () use ($feedId, $seconds): array {
            $feed = $this->feeds->get($feedId);
            if (!$feed) {
                throw new RuntimeException('Feed not found.', 404);
            }

            if ((int)$feed['build_generation'] === 0) {
                $feed = $this->startBuild($feed);
            }
            $gen = (int)$feed['build_generation'];
            $build = (array)($feed['stats']['build'] ?? []);
            $cursor = (int)($build['cursor'] ?? 0);
            $done = (int)($build['done'] ?? 0);
            $total = (int)($build['total'] ?? 0);
            $mapper = new FeedMapper($feed);
            $needs = FeedMapper::needs($feed['config']);
            $started = microtime(true);

            while (true) {
                $ids = $this->source->nextProductIds($cursor, self::BATCH);
                if ($ids === []) {
                    return $this->finishBuild($feed, $done, $total);
                }
                $records = $this->source->load($ids, $needs);
                $batch = [];
                foreach ($ids as $id) {
                    $batch[$id] = $mapper->map($id, $records[$id] ?? null);
                }
                $this->store->replaceProducts($feedId, $gen, $batch);
                $cursor = (int)end($ids);
                $done += count($ids);
                $this->feeds->mergeStats($feedId, ['build' => array_replace($build, ['cursor' => $cursor, 'done' => $done, 'updated_at' => gmdate('Y-m-d H:i:s')])]);
                $this->updateRun((string)($build['run_id'] ?? ''), 'running', ['done' => $done, 'total' => $total]);
                self::flushRuntimeCache();
                if ($seconds > 0 && (microtime(true) - $started) >= $seconds) {
                    return ['status' => 'building', 'done' => $done, 'total' => $total];
                }
            }
        });
    }

    private function startBuild(array $feed): array
    {
        $gen = max((int)$feed['active_generation'], $this->store->maxGeneration((int)$feed['id'])) + 1;
        $this->store->deleteGeneration((int)$feed['id'], $gen);
        $runId = $this->startRun((int)$feed['id'], 'feed_build', ['reason' => (string)($feed['rebuild_reason'] ?? 'manual')]);
        $this->feeds->updateRuntime((int)$feed['id'], [
            'build_generation' => $gen,
            'status'           => FeedRepository::STATUS_BUILDING,
            'rebuild_reason'   => null,
            'last_error'       => null,
        ]);
        $this->feeds->mergeStats((int)$feed['id'], ['build' => [
            'generation' => $gen,
            'cursor'     => 0,
            'done'       => 0,
            'total'      => $this->source->countProducts(),
            'reason'     => (string)($feed['rebuild_reason'] ?? 'manual'),
            'started_at' => gmdate('Y-m-d H:i:s'),
            'run_id'     => $runId,
            'config_hash' => bin2hex((string)$feed['config_hash_raw']),
        ]]);
        return $this->feeds->get((int)$feed['id']);
    }

    private function finishBuild(array $feed, int $done, int $total): array
    {
        $feedId = (int)$feed['id'];
        $gen = (int)$feed['build_generation'];
        $build = (array)($feed['stats']['build'] ?? []);

        // A config edit during the build makes this generation stale: start over.
        if (($build['config_hash'] ?? '') !== bin2hex((string)$feed['config_hash_raw'])) {
            $this->feeds->updateRuntime($feedId, ['build_generation' => 0, 'rebuild_reason' => 'config_changed']);
            $this->store->deleteGeneration($feedId, $gen);
            $this->finishRun((string)($build['run_id'] ?? ''), 'cancelled', ['done' => $done, 'total' => $total], 'config_changed');
            return ['status' => 'restarted', 'done' => $done, 'total' => $total];
        }

        $old = (int)$feed['active_generation'];
        $this->feeds->updateRuntime($feedId, [
            'active_generation' => $gen,
            'build_generation'  => 0,
            'status'            => FeedRepository::STATUS_READY,
        ]);
        $this->feeds->mergeStats($feedId, [
            'build'      => null,
            'store_hash' => bin2hex(FeedConfig::storeHash()),
            'last_build' => ['generation' => $gen, 'products' => $done, 'finished_at' => gmdate('Y-m-d H:i:s'), 'reason' => $build['reason'] ?? ''],
            'reconcile'  => ['cursor' => 0, 'checked' => 0, 'drift' => 0, 'cycle_started_at' => gmdate('Y-m-d H:i:s')],
        ]);
        if ($old > 0 && $old !== $gen) {
            $this->store->deleteGeneration($feedId, $old);
        }
        $this->finishRun((string)($build['run_id'] ?? ''), 'completed', ['done' => $done, 'total' => $total] + $this->store->counts($feedId, $gen));

        $published = $this->publishLocked($feedId, true);
        return ['status' => 'completed', 'done' => $done, 'total' => $total, 'published' => $published];
    }

    /* ------------------------------------------------------------------ */
    /* Incremental refresh                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Re-render the items of some products in the active generation (caller holds the lock).
     *
     * @param int[] $productIds
     * @return int items whose file-visible content changed
     */
    public function refreshProducts(array $feed, array $productIds): int
    {
        $gen = (int)$feed['active_generation'];
        if ($gen <= 0 || $productIds === []) {
            return 0;
        }
        $mapper = new FeedMapper($feed);
        $needs = FeedMapper::needs($feed['config']);
        $changed = 0;
        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::BATCH) as $chunk) {
            $records = $this->source->load($chunk, $needs);
            $batch = [];
            foreach ($chunk as $id) {
                $batch[$id] = $mapper->map($id, $records[$id] ?? null);
            }
            $changed += $this->store->replaceProducts((int)$feed['id'], $gen, $batch);
        }
        if ($changed > 0) {
            $this->feeds->noteChanges((int)$feed['id']);
        }
        return $changed;
    }

    /* ------------------------------------------------------------------ */
    /* Publish                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Write the active generation to a file and replace the published one when it passes the gate.
     *
     * @param bool $force Skip the item-drop guard (explicit operator publish or a just-finished rebuild)
     * @return array{status:string,items?:int,bytes?:int,reason?:string}
     */
    public function publish(int $feedId, bool $force = false): array
    {
        return $this->withLock($feedId, fn(): array => $this->publishLocked($feedId, $force));
    }

    private function publishLocked(int $feedId, bool $force): array
    {
        $feed = $this->feeds->get($feedId);
        if (!$feed) {
            throw new RuntimeException('Feed not found.', 404);
        }
        $gen = (int)$feed['active_generation'];
        if ($gen <= 0) {
            return ['status' => 'skipped', 'reason' => 'no_items_built'];
        }

        $runId = $this->startRun($feedId, 'feed_publish', []);
        $changesSnapshot = $feed['changes_last_at'];
        $dir = $this->feeds->feedDir($feed);
        $ext = $feed['format'] === 'xml' ? 'xml' : 'tsv';
        $final = $dir . '/feed.' . $ext;
        $part = $dir . '/feed.' . $ext . '.part';

        $mapper = new FeedMapper($feed);
        $writer = new FeedWriter($feed['format'], $mapper->columns());
        $writer->open($part, [
            'title'       => $feed['name'],
            'link'        => home_url('/'),
            'description' => get_bloginfo('name'),
        ]);

        $issues = [];
        $samples = [];
        $total = 0;
        $this->store->stream($feedId, $gen, function (array $row) use ($writer, &$issues, &$samples, &$total): void {
            $total++;
            foreach ($row['issues'] as $code) {
                $issues[$code] = ($issues[$code] ?? 0) + 1;
                if (count($samples[$code] ?? []) < 50) {
                    $samples[$code][] = ['product_id' => $row['product_id'], 'variant_id' => $row['variant_id'], 'item_id' => $row['item_id']];
                }
            }
            if ($row['eligible']) {
                $writer->write($row['payload']);
            }
        });
        $written = $writer->close();

        $reason = FeedWriter::verify($part, $feed['format'], $written['items']);
        if ($reason === null && $written['items'] === 0) {
            $reason = 'no_eligible_items';
        }
        $previous = (int)($feed['stats']['published']['items'] ?? 0);
        $maxDrop = (int)($feed['config']['schedule']['max_drop_percent'] ?? FeedConfig::DEFAULT_MAX_DROP_PERCENT);
        if ($reason === null && !$force && $previous > 0 && $written['items'] < $previous * (100 - $maxDrop) / 100) {
            $reason = sprintf('item_drop: %d -> %d exceeds %d%%', $previous, $written['items'], $maxDrop);
        }

        $diagnostics = ['issues' => $issues, 'samples' => $samples, 'total' => $total, 'eligible' => $written['items'], 'at' => gmdate('Y-m-d H:i:s')];

        if ($reason !== null) {
            @unlink($part);
            $this->feeds->updateRuntime($feedId, ['status' => FeedRepository::STATUS_ERROR, 'last_error' => 'Publish gate: ' . $reason]);
            $this->feeds->mergeStats($feedId, ['diagnostics' => $diagnostics, 'last_gate_failure' => ['reason' => $reason, 'at' => gmdate('Y-m-d H:i:s')]]);
            $this->finishRun($runId, 'failed', $written, mb_substr($reason, 0, 64));
            return ['status' => 'rejected', 'reason' => $reason, 'items' => $written['items']];
        }

        if (!@rename($part, $final)) {
            @unlink($part);
            $this->finishRun($runId, 'failed', $written, 'rename_failed');
            throw new RuntimeException('Could not replace the published feed file.');
        }
        $gz = null;
        if (!empty($feed['config']['compress'])) {
            $gz = $this->gzip($final);
        } elseif (is_file($final . '.gz')) {
            @unlink($final . '.gz');
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $this->feeds->updateRuntime($feedId, ['status' => FeedRepository::STATUS_READY, 'last_published_at' => $now, 'last_error' => null]);
        // Clear the debounce window unless a change arrived while this file was written.
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->prefix}wpe_feeds SET changes_first_at = NULL, changes_last_at = NULL
             WHERE id = %d AND (changes_last_at IS NULL OR changes_last_at <= %s)",
            $feedId,
            (string)($changesSnapshot ?? $now)
        ));
        $published = [
            'items'      => $written['items'],
            'bytes'      => $written['bytes'],
            'gz_bytes'   => $gz,
            'file'       => basename($final),
            'etag'       => substr(hash_file('sha256', $final), 0, 32),
            'at'         => gmdate('Y-m-d H:i:s'),
            'generation' => $gen,
        ];
        $this->feeds->mergeStats($feedId, ['published' => $published, 'diagnostics' => $diagnostics]);
        $this->finishRun($runId, 'completed', $written);

        return ['status' => 'published'] + $written;
    }

    /** Path of the published file of a feed, or null. */
    public function publishedFile(array $feed, bool $gz = false): ?string
    {
        $ext = $feed['format'] === 'xml' ? 'xml' : 'tsv';
        $path = $this->feeds->feedDir($feed) . '/feed.' . $ext . ($gz ? '.gz' : '');
        return is_file($path) ? $path : null;
    }

    private function gzip(string $path): ?int
    {
        $tmp = $path . '.gz.part';
        $in = fopen($path, 'rb');
        $out = gzopen($tmp, 'wb6');
        if ($in === false || $out === false) {
            return null;
        }
        while (!feof($in)) {
            gzwrite($out, (string)fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
        if (!@rename($tmp, $path . '.gz')) {
            @unlink($tmp);
            return null;
        }
        clearstatcache(true, $path . '.gz');
        return (int)filesize($path . '.gz');
    }

    /* ------------------------------------------------------------------ */
    /* Run history (wpe_jobs)                                             */
    /* ------------------------------------------------------------------ */

    private function startRun(int $feedId, string $kind, array $counts): string
    {
        $id = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_jobs', [
            'id'         => $id,
            'kind'       => $kind,
            'status'     => 'running',
            'config'     => self::runConfig($feedId),
            'checkpoint' => '{}',
            'counts'     => (string)wp_json_encode($counts),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function updateRun(string $runId, string $status, array $counts): void
    {
        if ($runId === '') {
            return;
        }
        $this->wpdb->update($this->wpdb->prefix . 'wpe_jobs', [
            'status' => $status, 'counts' => (string)wp_json_encode($counts), 'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['id' => $runId]);
    }

    private function finishRun(string $runId, string $status, array $counts, ?string $error = null): void
    {
        if ($runId === '') {
            return;
        }
        $this->wpdb->update($this->wpdb->prefix . 'wpe_jobs', [
            'status'     => $status,
            'counts'     => (string)wp_json_encode($counts),
            'error_code' => $error,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ], ['id' => $runId]);

        $feedConfig = (string)$this->wpdb->get_var($this->wpdb->prepare("SELECT config FROM {$this->wpdb->prefix}wpe_jobs WHERE id = %s", $runId));
        $keep = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->wpdb->prefix}wpe_jobs WHERE kind IN ('feed_build','feed_publish') AND config = %s ORDER BY created_at DESC LIMIT %d",
            $feedConfig,
            self::RUN_RETENTION
        )) ?: [];
        if (count($keep) === self::RUN_RETENTION) {
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->wpdb->prefix}wpe_jobs WHERE kind IN ('feed_build','feed_publish') AND config = %s AND id NOT IN ("
                . implode(',', array_fill(0, count($keep), '%s')) . ')',
                $feedConfig,
                ...$keep
            ));
        }
    }

    /** @return array<int,array> latest runs of a feed */
    public function runs(int $feedId, int $limit = 20): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, kind, status, counts, error_code, created_at, updated_at FROM {$this->wpdb->prefix}wpe_jobs
             WHERE kind IN ('feed_build','feed_publish') AND config = %s ORDER BY created_at DESC LIMIT %d",
            self::runConfig($feedId),
            $limit
        ), ARRAY_A) ?: [];
        return array_map(static function (array $r): array {
            $r['counts'] = json_decode((string)$r['counts'], true) ?: [];
            $r['kind'] = $r['kind'] === 'feed_build' ? 'build' : 'publish';
            return $r;
        }, $rows);
    }

    /** Delete the run history of a feed (feed deletion). */
    public function deleteRuns(int $feedId): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->wpdb->prefix}wpe_jobs WHERE kind IN ('feed_build','feed_publish') AND config = %s",
            self::runConfig($feedId)
        ));
    }

    public static function runConfig(int $feedId): string
    {
        return '{"feed_id":' . $feedId . '}';
    }

    /**
     * Drop the in-process object cache between CLI batches so long builds keep flat memory.
     */
    private static function flushRuntimeCache(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        } elseif (!wp_using_ext_object_cache()) {
            wp_cache_flush();
        }
    }
}
