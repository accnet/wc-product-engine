<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

use RuntimeException;

/**
 * Persistence of feed definitions (`wpe_feeds`), their URL tokens and runtime state, plus the
 * private directory that holds published files.
 *
 * Config edits bump `version` (CAS for the admin API). Runtime updates by the worker (status,
 * generations, debounce timestamps, stats) never bump it, so an operator's save is not rejected
 * because a tick ran in between.
 */
final class FeedRepository
{
    public const STATUS_NEW = 'new';
    public const STATUS_BUILDING = 'building';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    private \wpdb $wpdb;
    private string $table;
    private string $tableItems;
    private string $tableDirty;

    public function __construct(?\wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;
        $this->table = "{$prefix}wpe_feeds";
        $this->tableItems = "{$prefix}wpe_feed_items";
        $this->tableDirty = "{$prefix}wpe_feed_dirty";
    }

    /**
     * Create a feed. New feeds start with a rebuild request so the worker builds their items.
     *
     * @throws FeedValidationException
     */
    public function create(array $input): array
    {
        $identity = FeedConfig::normalizeIdentity($input);
        $config = FeedConfig::normalize((array)($input['config'] ?? []));
        $now = $this->now();

        $res = $this->wpdb->insert($this->table, [
            'feed_key'          => $this->uniqueKey($identity['name']),
            'name'              => $identity['name'],
            'platform'          => $identity['platform'],
            'format'            => $identity['format'],
            'enabled'           => !empty($input['enabled']) ? 1 : 0,
            'status'            => self::STATUS_NEW,
            'config'            => (string)wp_json_encode($config),
            'config_hash'       => FeedConfig::renderHash($identity['platform'], $identity['format'], $config),
            'token_seed'        => random_bytes(32),
            'version'           => 1,
            'active_generation' => 0,
            'build_generation'  => 0,
            'rebuild_reason'    => 'created',
            'stats'             => (string)wp_json_encode(['dir' => bin2hex(random_bytes(16))]),
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        if ($res === false) {
            throw new RuntimeException('Failed to create feed: ' . $this->wpdb->last_error);
        }

        return $this->get((int)$this->wpdb->insert_id) ?? throw new RuntimeException('Feed vanished after insert.');
    }

    public function get(int $id): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);
        return $row ? $this->hydrate($row) : null;
    }

    public function getByKey(string $feedKey): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE feed_key = %s", $feedKey), ARRAY_A);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array{search?:string,platform?:string,status?:string,orderby?:string,order?:string} $args
     * @return array<int,array>
     */
    public function list(array $args = []): array
    {
        $where = ['1=1'];
        $params = [];
        $search = trim((string)($args['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'name LIKE %s';
            $params[] = '%' . $this->wpdb->esc_like($search) . '%';
        }
        $platform = (string)($args['platform'] ?? '');
        if (in_array($platform, FeedConfig::PLATFORMS, true)) {
            $where[] = 'platform = %s';
            $params[] = $platform;
        }
        $status = (string)($args['status'] ?? '');
        if ($status === 'disabled') {
            $where[] = 'enabled = 0';
        } elseif (in_array($status, [self::STATUS_NEW, self::STATUS_BUILDING, self::STATUS_READY, self::STATUS_ERROR], true)) {
            $where[] = 'status = %s AND enabled = 1';
            $params[] = $status;
        }
        $orderby = match ((string)($args['orderby'] ?? 'name')) {
            'published' => 'last_published_at',
            'created'   => 'created_at',
            'updated'   => 'updated_at',
            default     => 'name',
        };
        $order = strtolower((string)($args['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . " ORDER BY {$orderby} {$order}, id ASC LIMIT 500";
        $rows = $params ? $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A) : $this->wpdb->get_results($sql, ARRAY_A);

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /** @return array<int,array> Enabled feeds, for the worker and change tracking. */
    public function enabledFeeds(): array
    {
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->table} WHERE enabled = 1 ORDER BY id ASC", ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /** @return int[] */
    public function enabledFeedIds(): array
    {
        return array_map('intval', $this->wpdb->get_col("SELECT id FROM {$this->table} WHERE enabled = 1") ?: []);
    }

    /**
     * Apply an operator edit with optimistic concurrency.
     *
     * @return array{feed:array,rebuild:bool}
     * @throws FeedValidationException|RuntimeException 412 on a stale version, 404 when missing
     */
    public function update(int $id, array $input, ?int $expectedVersion): array
    {
        $current = $this->get($id);
        if (!$current) {
            throw new RuntimeException('Feed not found.', 404);
        }
        if ($expectedVersion !== null && $expectedVersion !== (int)$current['version']) {
            throw new RuntimeException('The feed was changed by someone else. Reload and try again.', 412);
        }

        $identity = FeedConfig::normalizeIdentity($input, $current);
        $config = array_key_exists('config', $input)
            ? FeedConfig::normalize((array)$input['config'], $current['config'])
            : $current['config'];
        $hash = FeedConfig::renderHash($identity['platform'], $identity['format'], $config);
        $rebuild = !hash_equals((string)$current['config_hash_raw'], $hash);

        $data = [
            'name'        => $identity['name'],
            'platform'    => $identity['platform'],
            'format'      => $identity['format'],
            'config'      => (string)wp_json_encode($config),
            'config_hash' => $hash,
            'version'     => (int)$current['version'] + 1,
            'updated_at'  => $this->now(),
        ];
        if (array_key_exists('enabled', $input)) {
            $data['enabled'] = !empty($input['enabled']) ? 1 : 0;
        }
        if ($rebuild) {
            $data['rebuild_reason'] = 'config_changed';
        }

        $affected = $this->wpdb->update($this->table, $data, ['id' => $id, 'version' => (int)$current['version']]);
        if ($affected === false) {
            throw new RuntimeException('Failed to update feed: ' . $this->wpdb->last_error);
        }
        if ($affected === 0) {
            throw new RuntimeException('The feed was changed by someone else. Reload and try again.', 412);
        }

        // Re-enabling a feed whose items are stale: catch up through a rebuild.
        $feed = $this->get($id);
        if ($feed && !empty($data['enabled']) && empty($current['enabled']) && (int)$feed['active_generation'] > 0) {
            $this->requestRebuild($id, 'enabled');
            $feed = $this->get($id);
            $rebuild = true;
        }

        return ['feed' => $feed, 'rebuild' => $rebuild];
    }

    /**
     * Delete a feed with its items, dirty marks and published files.
     */
    public function delete(int $id): bool
    {
        $feed = $this->get($id);
        if (!$feed) {
            return false;
        }
        $this->wpdb->delete($this->tableItems, ['feed_id' => $id]);
        $this->wpdb->delete($this->tableDirty, ['feed_id' => $id]);
        $this->wpdb->delete($this->table, ['id' => $id]);
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->wpdb->prefix}wpe_jobs WHERE kind IN ('feed_build','feed_publish') AND config = %s",
            '{"feed_id":' . $id . '}'
        ));
        $this->removeDir($this->feedDir($feed));
        return true;
    }

    /**
     * Copy a feed's definition. The copy is disabled and gets its own key, token and directory.
     */
    public function duplicate(int $id): array
    {
        $feed = $this->get($id);
        if (!$feed) {
            throw new RuntimeException('Feed not found.', 404);
        }
        /* translators: %s: name of the copied feed */
        $name = mb_substr(sprintf(__('%s (copy)', 'wc-product-engine'), $feed['name']), 0, 191);
        return $this->create([
            'name'     => $name,
            'platform' => $feed['platform'],
            'format'   => $feed['format'],
            'enabled'  => false,
            'config'   => $feed['config'],
        ]);
    }

    public function rotateToken(int $id): array
    {
        $feed = $this->get($id);
        if (!$feed) {
            throw new RuntimeException('Feed not found.', 404);
        }
        $this->wpdb->update($this->table, [
            'token_seed' => random_bytes(32),
            'version'    => (int)$feed['version'] + 1,
            'updated_at' => $this->now(),
        ], ['id' => $id]);
        return $this->get($id) ?? throw new RuntimeException('Feed not found.', 404);
    }

    /**
     * URL token: HMAC of the stored random seed with the site's auth salt. The token itself is
     * never stored, and changing the seed revokes every earlier URL.
     */
    public function token(array $feed): string
    {
        return hash_hmac('sha256', $feed['feed_key'] . '|' . bin2hex((string)$feed['token_seed_raw']), wp_salt('auth'));
    }

    public function verifyToken(array $feed, string $token): bool
    {
        return strlen($token) === 64 && hash_equals($this->token($feed), strtolower($token));
    }

    /**
     * Public URL Merchant Center fetches. Pretty when permalinks are on, query form otherwise.
     */
    public function publicUrl(array $feed, bool $gzip = false): string
    {
        $ext = $feed['format'] === 'xml' ? 'xml' : 'tsv';
        $token = $this->token($feed);
        if ((string)get_option('permalink_structure', '') !== '') {
            return home_url(sprintf('/wpe-feed/%s/%s.%s%s', $feed['feed_key'], $token, $ext, $gzip ? '.gz' : ''));
        }
        $args = ['wpe_feed' => $feed['feed_key'], 'token' => $token, 'format' => $ext];
        if ($gzip) {
            $args['gz'] = '1';
        }
        return add_query_arg($args, home_url('/'));
    }

    /**
     * Ask the worker for a full rebuild (D07, D08).
     */
    public function requestRebuild(int $id, string $reason): void
    {
        $this->wpdb->update($this->table, ['rebuild_reason' => mb_substr($reason, 0, 64), 'updated_at' => $this->now()], ['id' => $id]);
    }

    /**
     * Worker-side update of runtime columns. Never bumps `version`.
     *
     * @param array<string,mixed> $fields
     */
    public function updateRuntime(int $id, array $fields): void
    {
        $allowed = ['status', 'active_generation', 'build_generation', 'rebuild_reason', 'changes_first_at',
            'changes_last_at', 'last_published_at', 'last_error'];
        $data = array_intersect_key($fields, array_flip($allowed));
        if (isset($data['last_error']) && is_string($data['last_error'])) {
            $data['last_error'] = mb_substr($data['last_error'], 0, 255);
        }
        if ($data === []) {
            return;
        }
        // wpdb::update cannot write NULL through formats; build the statement explicitly.
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            if ($val === null) {
                $sets[] = "`{$col}` = NULL";
            } else {
                $sets[] = "`{$col}` = %s";
                $params[] = (string)$val;
            }
        }
        $params[] = $id;
        $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = %d", ...$params));
    }

    /**
     * Merge keys into the stats JSON (worker-side, no version bump).
     */
    public function mergeStats(int $id, array $stats): array
    {
        $raw = (string)$this->wpdb->get_var($this->wpdb->prepare("SELECT stats FROM {$this->table} WHERE id = %d", $id));
        $current = json_decode($raw, true);
        $merged = array_replace(is_array($current) ? $current : [], $stats);
        $this->wpdb->update($this->table, ['stats' => (string)wp_json_encode($merged)], ['id' => $id]);
        return $merged;
    }

    /**
     * Mark that rendered items changed: opens or extends the debounce window (D06).
     */
    public function noteChanges(int $id, ?string $at = null): void
    {
        $at = $at ?? $this->now();
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table} SET changes_first_at = COALESCE(changes_first_at, %s), changes_last_at = %s WHERE id = %d",
            $at,
            $at,
            $id
        ));
    }

    /**
     * Base directory of published feed files (inside uploads, one unguessable sub-directory per feed).
     */
    public function baseDir(): string
    {
        $upload = wp_upload_dir(null, false);
        $dir = rtrim((string)$upload['basedir'], '/\\') . '/wpe-feeds';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        foreach (['index.php' => "<?php\n// Silence is golden.\n", '.htaccess' => "Require all denied\nDeny from all\n"] as $file => $content) {
            if (!file_exists($dir . '/' . $file)) {
                @file_put_contents($dir . '/' . $file, $content);
            }
        }
        return $dir;
    }

    public function feedDir(array $feed): string
    {
        $suffix = preg_replace('/[^a-f0-9]/', '', (string)($feed['stats']['dir'] ?? '')) ?: 'x';
        $dir = $this->baseDir() . '/' . (int)$feed['id'] . '-' . $suffix;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    /**
     * Admin/REST representation. The URL (which carries the token) is included only when asked for.
     */
    public function view(array $feed, bool $withUrl): array
    {
        $stats = $feed['stats'];
        unset($stats['dir'], $stats['reconcile']);
        return [
            'id'                => (int)$feed['id'],
            'feed_key'          => $feed['feed_key'],
            'name'              => $feed['name'],
            'platform'          => $feed['platform'],
            'format'            => $feed['format'],
            'enabled'           => (bool)$feed['enabled'],
            'status'            => $feed['enabled'] ? $feed['status'] : 'disabled',
            'version'           => (int)$feed['version'],
            'url'               => $withUrl ? $this->publicUrl($feed) : null,
            'url_gz'            => ($withUrl && !empty($feed['config']['compress'])) ? $this->publicUrl($feed, true) : null,
            'config'            => $feed['config'],
            'stats'             => $stats,
            'rebuild_pending'   => $feed['rebuild_reason'] !== null && $feed['rebuild_reason'] !== '',
            'building'          => (int)$feed['build_generation'] > 0,
            'last_published_at' => $feed['last_published_at'],
            'last_error'        => $feed['last_error'],
            'created_at'        => $feed['created_at'],
            'updated_at'        => $feed['updated_at'],
        ];
    }

    private function hydrate(array $row): array
    {
        $config = json_decode((string)$row['config'], true);
        $stats = json_decode((string)$row['stats'], true);
        $row['id'] = (int)$row['id'];
        $row['enabled'] = (int)$row['enabled'] === 1;
        $row['version'] = (int)$row['version'];
        $row['active_generation'] = (int)$row['active_generation'];
        $row['build_generation'] = (int)$row['build_generation'];
        $row['config'] = FeedConfig::mergeDefaults(is_array($config) ? $config : []);
        $row['stats'] = is_array($stats) ? $stats : [];
        $row['config_hash_raw'] = (string)$row['config_hash'];
        $row['token_seed_raw'] = (string)$row['token_seed'];
        unset($row['config_hash'], $row['token_seed']);
        return $row;
    }

    private function uniqueKey(string $name): string
    {
        $base = substr(sanitize_title($name), 0, 40) ?: 'feed';
        for ($i = 0; $i < 10; $i++) {
            $key = $base . '-' . strtolower(wp_generate_password(6, false, false));
            if (!$this->wpdb->get_var($this->wpdb->prepare("SELECT 1 FROM {$this->table} WHERE feed_key = %s", $key))) {
                return $key;
            }
        }
        return $base . '-' . bin2hex(random_bytes(6));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with($dir, $this->baseDir() . '/')) {
            return;
        }
        foreach ((array)glob($dir . '/{,.}[!.,!..]*', GLOB_BRACE) as $file) {
            if (is_file((string)$file)) {
                @unlink((string)$file);
            }
        }
        @rmdir($dir);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s.u');
    }
}
