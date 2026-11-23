<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * Feed updates without a system cron (P036, 10 §12, 08 §3).
 *
 * The system cron `wp wpe feeds tick` stays the primary runner. While its heartbeat is missing or
 * stale and feed work is pending, this runs one bounded FeedScheduler tick at the end of a web
 * request, after the response was sent (`fastcgi_finish_request()`), so product changes reach the
 * published feed and new feeds get built. Same scheduler, same per-feed locks, same debounce and
 * publish gate; one PHP process at a time (global named lock), at most every THROTTLE_SECONDS
 * unless the request itself changed products. It never writes the worker heartbeat.
 *
 * Opt out: define('WPE_FEEDS_AUTORUN', false) or the `wpe_feeds_autorun` filter.
 */
final class FeedAutoRunner
{
    public const SECONDS = 20.0;
    public const THROTTLE_SECONDS = 30;
    public const THROTTLE_KEY = 'wpe_feed_autorun';
    /** `wpe_control` row with the last fallback run (P038); never the worker heartbeat. */
    public const LAST_RUN_KEY = 'feed_autorun_last';
    /** Same staleness as the worker warning (FeedsPage::WORKER_STALE_SECONDS). */
    public const WORKER_STALE_SECONDS = 300;

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || !function_exists('add_action') || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        self::$registered = true;
        // After FeedChangeTracker::flush (priority 20), so this request's marks count.
        add_action('shutdown', [self::class, 'onShutdown'], 30);
    }

    public static function onShutdown(): void
    {
        try {
            if (!self::enabled() || !self::shouldRun()) {
                return;
            }
            if (!self::finishResponse()) {
                return;
            }
            self::run();
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostic; the worker or the next request retries
            error_log('[WPE] feed autorun failed: ' . $e->getMessage());
        }
    }

    public static function enabled(): bool
    {
        if (defined('WPE_FEEDS_AUTORUN') && !constant('WPE_FEEDS_AUTORUN')) {
            return false;
        }
        return (bool)apply_filters('wpe_feeds_autorun', true);
    }

    /**
     * Cheap gate: worker absent, throttle passed (or this request changed products), work pending.
     */
    public static function shouldRun(?FeedScheduler $scheduler = null): bool
    {
        global $wpdb;
        // The throttle comes first so most requests cost one transient read (Redis on production).
        if (!FeedChangeTracker::markedInRequest() && get_transient(self::THROTTLE_KEY)) {
            return false;
        }
        set_transient(self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS);
        $hb = FeedScheduler::lastHeartbeat($wpdb);
        if ($hb !== null && $hb['age'] <= self::WORKER_STALE_SECONDS) {
            return false;
        }
        return self::pendingWork($scheduler) !== [];
    }

    /**
     * Feeds with work a tick would do now: a build requested or running, dirty products, a due
     * scheduled price change, or a debounced publish that is due.
     *
     * @return array<int,string> feed id => reason
     */
    public static function pendingWork(?FeedScheduler $scheduler = null): array
    {
        global $wpdb;
        $scheduler ??= new FeedScheduler();
        $store = new FeedItemStore($wpdb);
        $now = gmdate('Y-m-d H:i:s.u');
        $out = [];
        foreach ((new FeedRepository($wpdb))->enabledFeeds() as $feed) {
            $id = (int)$feed['id'];
            if ((int)$feed['build_generation'] > 0 || !empty($feed['rebuild_reason']) || (int)$feed['active_generation'] === 0) {
                $out[$id] = 'build';
            } elseif ($store->dirtyCount($id) > 0) {
                $out[$id] = 'dirty';
            } elseif ($store->dueProducts($id, (int)$feed['active_generation'], $now, 1) !== []) {
                $out[$id] = 'due_price';
            } elseif ($scheduler->publishBlocker($feed) === null) {
                $out[$id] = 'publish';
            }
        }
        return $out;
    }

    /**
     * One bounded tick under a global lock. Returns the tick result, or null when another
     * process holds the lock.
     */
    public static function run(float $seconds = self::SECONDS): ?array
    {
        global $wpdb;
        $lock = substr($wpdb->prefix . 'wpe_feed_autorun', 0, 64);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return null;
        }
        try {
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit((int)ceil($seconds) + 60); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host
            }
            $started = microtime(true);
            $result = (new FeedScheduler())->tick($seconds, false);
            $value = (string)wp_json_encode(['at' => gmdate('Y-m-d H:i:s'), 'duration' => round(microtime(true) - $started, 2), 'feeds' => count($result['feeds'])]);
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}wpe_control (`key`, value, version, updated_at) VALUES (%s, %s, 1, %s)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), version = version + 1, updated_at = VALUES(updated_at)",
                self::LAST_RUN_KEY,
                $value,
                gmdate('Y-m-d H:i:s.u')
            ));
            return $result;
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * Last fallback run: ['at' => 'Y-m-d H:i:s', 'age' => seconds, ...] or null.
     */
    public static function lastRun(?\wpdb $wpdb = null): ?array
    {
        $wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $raw = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$wpdb->prefix}wpe_control WHERE `key` = %s", self::LAST_RUN_KEY));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['at'])) {
            return null;
        }
        $data['age'] = max(0, time() - (int)strtotime($data['at'] . ' UTC'));
        return $data;
    }

    /** Send the response to the client before working; false when the SAPI cannot. */
    private static function finishResponse(): bool
    {
        if (function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }
        if (function_exists('litespeed_finish_request')) {
            return (bool)litespeed_finish_request();
        }
        return false;
    }
}
