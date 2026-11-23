<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * The feed worker tick (`wp wpe feeds tick`, system cron every minute), 08 §3.
 *
 * Per enabled feed, within a time budget:
 *   1. continue or start a full rebuild when one is running or requested (nothing else that tick);
 *   2. request a rebuild when store settings that change rendering changed, or the dirty set is too
 *      large (D07);
 *   3. re-render dirty products (On Change, D04) and items whose scheduled price change is due;
 *   4. reconcile the next slice of products (P035.7);
 *   5. publish when the debounce window allows it (D06) and the feed updates automatically.
 */
final class FeedScheduler
{
    public const HEARTBEAT_KEY = 'feed_worker_heartbeat';
    public const DIRTY_BATCH = 500;
    public const DIRTY_ESCALATION_RATIO = 0.20;
    public const DIRTY_ESCALATION_MIN = 200;
    public const DRIFT_ESCALATION_RATIO = 0.01;
    /** One-minute ticks per reconciliation cycle (about 24 hours). */
    public const RECONCILE_TICKS_PER_CYCLE = 1440;
    public const RECONCILE_MIN_SLICE = 20;

    private FeedBuilder $builder;
    private FeedRepository $feeds;
    private FeedItemStore $store;
    private \wpdb $wpdb;

    /** @var int|null Fixed clock for tests (Unix time). */
    private ?int $now = null;

    public function __construct(?FeedBuilder $builder = null)
    {
        $this->builder = $builder ?? new FeedBuilder();
        $this->feeds = $this->builder->feeds();
        $this->store = $this->builder->store();
        $this->wpdb = $GLOBALS['wpdb'];
    }

    public function setClock(?int $unixTime): void
    {
        $this->now = $unixTime;
    }

    private function time(): int
    {
        return $this->now ?? time();
    }

    private function nowString(): string
    {
        return gmdate('Y-m-d H:i:s', $this->time());
    }

    /**
     * Run one tick over every enabled feed. The in-request fallback (FeedAutoRunner) passes
     * $recordHeartbeat = false so a missing system cron stays visible in health.
     *
     * @return array{heartbeat:string,feeds:array<int,array>}
     */
    public function tick(float $seconds = 50.0, bool $recordHeartbeat = true): array
    {
        FeedChangeTracker::flush();
        $started = microtime(true);
        if ($recordHeartbeat) {
            $this->heartbeat();
        }

        $out = [];
        foreach ($this->feeds->enabledFeeds() as $feed) {
            $remaining = $seconds - (microtime(true) - $started);
            if ($remaining <= 0.5) {
                $out[$feed['id']] = ['skipped' => 'time_budget'];
                continue;
            }
            try {
                $out[$feed['id']] = $this->tickFeed((int)$feed['id'], $remaining);
            } catch (\Throwable $e) {
                $out[$feed['id']] = ['error' => (int)$e->getCode() === 409 ? 'locked' : $e->getMessage()];
                if ((int)$e->getCode() !== 409) {
                    $this->feeds->updateRuntime((int)$feed['id'], ['status' => FeedRepository::STATUS_ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 255)]);
                }
            }
        }

        if ($recordHeartbeat) {
            $this->heartbeat(['last_duration' => round(microtime(true) - $started, 2), 'feeds' => count($out)]);
        }
        return ['heartbeat' => $this->nowString(), 'feeds' => $out];
    }

    /**
     * One feed's share of a tick.
     */
    public function tickFeed(int $feedId, float $seconds): array
    {
        $feed = $this->feeds->get($feedId);
        if (!$feed || !$feed['enabled']) {
            return ['skipped' => 'disabled'];
        }

        // 1. Full rebuild running or requested.
        if ((int)$feed['build_generation'] > 0 || !empty($feed['rebuild_reason']) || (int)$feed['active_generation'] === 0) {
            if (empty($feed['rebuild_reason']) && (int)$feed['build_generation'] === 0) {
                $this->feeds->requestRebuild($feedId, 'initial');
            }
            // Dirty marks are left alone during a build and re-rendered against the new generation
            // on the next tick (D08); re-rendering a product the build already covered is harmless.
            return ['build' => $this->builder->buildStep($feedId, max(1.0, $seconds - 1.0))];
        }

        // 2. Escalations.
        $storeHash = bin2hex(FeedConfig::storeHash());
        if (($feed['stats']['store_hash'] ?? $storeHash) !== $storeHash) {
            $this->feeds->requestRebuild($feedId, 'store_settings_changed');
            return ['rebuild_requested' => 'store_settings_changed'];
        }
        $total = (int)($feed['stats']['published']['items'] ?? 0) ?: $this->store->counts($feedId, (int)$feed['active_generation'])['total'];
        $dirty = $this->store->dirtyCount($feedId);
        if ($dirty > max(self::DIRTY_ESCALATION_MIN, (int)ceil($total * self::DIRTY_ESCALATION_RATIO))) {
            $this->feeds->requestRebuild($feedId, 'dirty_threshold');
            return ['rebuild_requested' => 'dirty_threshold', 'dirty' => $dirty];
        }

        $started = microtime(true);
        $result = $this->builder->withLock($feedId, function () use ($feedId, $seconds, $started): array {
            $feed = $this->feeds->get($feedId);
            $r = ['dirty_products' => 0, 'due_products' => 0, 'changed_items' => 0];

            // 3a. Dirty products.
            while ((microtime(true) - $started) < $seconds * 0.6) {
                $marks = $this->store->dirtyBatch($feedId, self::DIRTY_BATCH);
                if ($marks === []) {
                    break;
                }
                $r['changed_items'] += $this->builder->refreshProducts($feed, array_column($marks, 'product_id'));
                $this->store->clearDirty($feedId, $marks);
                $r['dirty_products'] += count($marks);
                if (count($marks) < self::DIRTY_BATCH) {
                    break;
                }
            }

            // 3b. Scheduled price changes now due.
            $due = $this->store->dueProducts($feedId, (int)$feed['active_generation'], gmdate('Y-m-d H:i:s.u', $this->time()), self::DIRTY_BATCH);
            if ($due !== []) {
                $r['changed_items'] += $this->builder->refreshProducts($feed, $due);
                $r['due_products'] = count($due);
            }

            // 4. Reconciliation slice.
            $remaining = $seconds - (microtime(true) - $started);
            if ($remaining > 1.0) {
                $r['reconcile'] = $this->reconcileSlice($feed, $remaining * 0.8);
            }
            return $r;
        });

        // 5. Debounced publish.
        $result['publish'] = $this->maybePublish($feedId);
        return $result;
    }

    /**
     * Publish when items changed and the debounce rules allow it (D06).
     */
    public function maybePublish(int $feedId): array|string
    {
        $feed = $this->feeds->get($feedId);
        if (!$feed) {
            return 'manual';
        }
        $blocker = $this->publishBlocker($feed);
        return $blocker ?? $this->builder->publish($feedId);
    }

    /**
     * Why a debounced publish may not run now (`manual`, `no_changes`, `debouncing`,
     * `min_interval`, `gate_backoff`), or null when it is due.
     */
    public function publishBlocker(array $feed): ?string
    {
        if (($feed['config']['schedule']['mode'] ?? 'auto') !== 'auto') {
            return 'manual';
        }
        if ($feed['changes_first_at'] === null) {
            return 'no_changes';
        }
        $s = $feed['config']['schedule'];
        $now = $this->time();
        $first = strtotime($feed['changes_first_at'] . ' UTC');
        $last = strtotime((string)$feed['changes_last_at'] . ' UTC');
        $quiet = ($now - $last) >= (int)$s['quiet_seconds'];
        $waitedEnough = ($now - $first) >= (int)$s['max_wait_seconds'];
        if (!$quiet && !$waitedEnough) {
            return 'debouncing';
        }
        if ($feed['last_published_at'] !== null && ($now - strtotime($feed['last_published_at'] . ' UTC')) < (int)$s['min_interval_seconds']) {
            return 'min_interval';
        }
        // A rejected publish is retried no sooner than the minimum interval, not every tick.
        $failedAt = (string)($feed['stats']['last_gate_failure']['at'] ?? '');
        if ($failedAt !== '' && $feed['status'] === FeedRepository::STATUS_ERROR
            && ($now - strtotime($failedAt . ' UTC')) < (int)$s['min_interval_seconds']) {
            return 'gate_backoff';
        }
        return null;
    }

    /**
     * Scheduled Reconciliation (D07): re-render the next slice of products, drop items whose
     * product is gone, and at the end of a cycle request a rebuild when drift exceeded the limit.
     *
     * The slice covers the catalog in about a day of one-minute ticks. Drift counts items whose
     * file-visible content differed from the store although no change signal arrived.
     */
    public function reconcileSlice(array $feed, float $seconds): array
    {
        $feedId = (int)$feed['id'];
        $gen = (int)$feed['active_generation'];
        $source = $this->builder->source();
        $state = (array)($feed['stats']['reconcile'] ?? []);
        $cursor = (int)($state['cursor'] ?? 0);
        $checked = (int)($state['checked'] ?? 0);
        $drift = (int)($state['drift'] ?? 0);

        $total = (int)($state['products'] ?? 0) ?: $source->countProducts();
        $slice = (int)apply_filters('wpe_feed_reconcile_slice', max(self::RECONCILE_MIN_SLICE, (int)ceil($total / self::RECONCILE_TICKS_PER_CYCLE)), $feed);
        $started = microtime(true);
        $result = ['products' => 0, 'drift' => 0, 'removed' => 0];

        while ((microtime(true) - $started) < $seconds) {
            $ids = $source->nextProductIds($cursor, $slice);
            $upto = $ids === [] ? PHP_INT_MAX : (int)end($ids);
            // Products that have items but no longer a shell in this range (deleted outside WordPress).
            $orphans = array_values(array_diff($this->store->productIdsInRange($feedId, $gen, $cursor, $upto), $ids));
            if ($ids !== [] || $orphans !== []) {
                $before = $this->store->counts($feedId, $gen)['total'];
                $changed = $this->builder->refreshProducts($feed, array_merge($ids, $orphans));
                $result['products'] += count($ids);
                $result['drift'] += $changed;
                $result['removed'] += max(0, $before - $this->store->counts($feedId, $gen)['total']);
                $drift += $changed;
                $checked += $this->store->countProducts($feedId, $gen, $cursor, $upto);
            }

            if ($ids === []) {
                // Cycle complete.
                $ratio = $checked > 0 ? $drift / $checked : 0.0;
                $cycle = ['checked' => $checked, 'drift' => $drift, 'ratio' => round($ratio, 4), 'finished_at' => $this->nowString()];
                $this->feeds->mergeStats($feedId, ['reconcile' => ['cursor' => 0, 'checked' => 0, 'drift' => 0,
                    'cycle_started_at' => $this->nowString(), 'last_cycle' => $cycle]]);
                if ($ratio > self::DRIFT_ESCALATION_RATIO) {
                    $this->feeds->requestRebuild($feedId, 'reconcile_drift');
                    $result['rebuild_requested'] = 'reconcile_drift';
                }
                $result['cycle_completed'] = $cycle;
                return $result;
            }

            $cursor = $upto;
            $this->feeds->mergeStats($feedId, ['reconcile' => array_replace($state, [
                'cursor' => $cursor, 'checked' => $checked, 'drift' => $drift, 'products' => $total,
            ])]);
            $state = (array)($this->feeds->get($feedId)['stats']['reconcile'] ?? []);
            if (!apply_filters('wpe_feed_reconcile_continue', false, $feed)) {
                break;
            }
        }

        $result['cursor'] = $cursor;
        return $result;
    }

    /* ------------------------------------------------------------------ */
    /* Heartbeat                                                          */
    /* ------------------------------------------------------------------ */

    private function heartbeat(array $extra = []): void
    {
        $value = (string)wp_json_encode(['at' => $this->nowString(), 'pid' => getmypid()] + $extra);
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->wpdb->prefix}wpe_control (`key`, value, version, updated_at) VALUES (%s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE value = VALUES(value), version = version + 1, updated_at = VALUES(updated_at)",
            self::HEARTBEAT_KEY,
            $value,
            gmdate('Y-m-d H:i:s.u')
        ));
    }

    /**
     * Last worker heartbeat: ['at' => 'Y-m-d H:i:s', 'age' => seconds] or null.
     */
    public static function lastHeartbeat(?\wpdb $wpdb = null): ?array
    {
        $wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $raw = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$wpdb->prefix}wpe_control WHERE `key` = %s", self::HEARTBEAT_KEY));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['at'])) {
            return null;
        }
        $data['age'] = max(0, time() - (int)strtotime($data['at'] . ' UTC'));
        return $data;
    }
}
