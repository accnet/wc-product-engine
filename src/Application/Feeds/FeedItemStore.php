<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * Per-feed item store (`wpe_feed_items`) and dirty marks (`wpe_feed_dirty`), D03/D04.
 *
 * Items are kept per generation. Incremental updates replace one product's items in the active
 * generation and report whether anything that reaches the file changed; a full rebuild fills a new
 * generation that FeedBuilder then switches to.
 */
final class FeedItemStore
{
    private \wpdb $wpdb;
    private string $items;
    private string $dirty;

    public function __construct(?\wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->items = $this->wpdb->prefix . 'wpe_feed_items';
        $this->dirty = $this->wpdb->prefix . 'wpe_feed_dirty';
    }

    /**
     * Hash of what reaches the published file: payload and eligibility (issues alone do not).
     */
    public static function itemHash(array $item): string
    {
        return hash('sha256', ($item['eligible'] ? '1' : '0') . '|' . (string)wp_json_encode($item['payload']), true);
    }

    /**
     * Replace the items of several products in a generation.
     *
     * @param array<int,array<int,array>> $itemsByProduct product_id => mapped items ([] removes the product)
     * @return int Number of items added, removed or changed in a way that reaches the file
     */
    public function replaceProducts(int $feedId, int $generation, array $itemsByProduct): int
    {
        if ($itemsByProduct === []) {
            return 0;
        }
        $pids = array_map('intval', array_keys($itemsByProduct));
        $existing = [];
        foreach ($this->wpdb->get_results($this->wpdb->prepare(
            "SELECT product_id, variant_id, eligible, payload_hash, issues, next_change FROM {$this->items}
             WHERE feed_id = %d AND generation = %d AND product_id IN (" . implode(',', $pids) . ')',
            $feedId,
            $generation
        ), ARRAY_A) ?: [] as $row) {
            $existing[(int)$row['product_id']][(int)$row['variant_id']] = $row;
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $changed = 0;
        $upserts = [];
        $deletes = [];

        foreach ($itemsByProduct as $pid => $items) {
            $pid = (int)$pid;
            $seen = [];
            foreach ($items as $item) {
                $vid = (int)$item['variant_id'];
                $seen[$vid] = true;
                $hash = self::itemHash($item);
                $issues = (string)wp_json_encode(array_values($item['issues']));
                $old = $existing[$pid][$vid] ?? null;
                if ($old !== null && hash_equals((string)$old['payload_hash'], $hash)
                    && (string)$old['issues'] === $issues && (string)($old['next_change'] ?? '') === (string)($item['next_change'] ?? '')) {
                    continue;
                }
                if ($old === null || !hash_equals((string)$old['payload_hash'], $hash)) {
                    // A new or changed ineligible item never reaches the file.
                    if ($item['eligible'] || ($old !== null && (int)$old['eligible'] === 1)) {
                        $changed++;
                    }
                }
                $upserts[] = [$feedId, $generation, $pid, $vid, (string)$item['item_id'], $item['eligible'] ? 1 : 0,
                    (string)wp_json_encode($item['payload']), $hash, $issues, $item['next_change'], $now];
            }
            foreach ($existing[$pid] ?? [] as $vid => $old) {
                if (!isset($seen[$vid])) {
                    $deletes[] = [$pid, (int)$vid];
                    if ((int)$old['eligible'] === 1) {
                        $changed++;
                    }
                }
            }
        }

        foreach (array_chunk($upserts, 200) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $u) {
                $values[] = $u[9] === null ? '(%d,%d,%d,%d,%s,%d,%s,%s,%s,NULL,%s)' : '(%d,%d,%d,%d,%s,%d,%s,%s,%s,%s,%s)';
                foreach ($u as $i => $v) {
                    if ($i === 9 && $v === null) {
                        continue;
                    }
                    $params[] = $v;
                }
            }
            $res = $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->items} (feed_id, generation, product_id, variant_id, item_id, eligible, payload, payload_hash, issues, next_change, updated_at)
                 VALUES " . implode(',', $values) . "
                 ON DUPLICATE KEY UPDATE item_id = VALUES(item_id), eligible = VALUES(eligible), payload = VALUES(payload),
                   payload_hash = VALUES(payload_hash), issues = VALUES(issues), next_change = VALUES(next_change), updated_at = VALUES(updated_at)",
                ...$params
            ));
            if ($res === false) {
                throw new \RuntimeException('Failed to store feed items: ' . $this->wpdb->last_error);
            }
        }
        foreach ($deletes as [$pid, $vid]) {
            $this->wpdb->delete($this->items, ['feed_id' => $feedId, 'generation' => $generation, 'product_id' => $pid, 'variant_id' => $vid]);
        }

        return $changed;
    }

    /**
     * Stream every item of a generation in key order without loading the whole set.
     *
     * @param callable(array):void $fn receives rows with product_id, variant_id, item_id, eligible, payload (decoded), issues (decoded)
     */
    public function stream(int $feedId, int $generation, callable $fn, int $batch = 1000): int
    {
        $lastP = -1;
        $lastV = -1;
        $count = 0;
        do {
            $rows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT product_id, variant_id, item_id, eligible, payload, issues FROM {$this->items}
                 WHERE feed_id = %d AND generation = %d AND (product_id > %d OR (product_id = %d AND variant_id > %d))
                 ORDER BY product_id, variant_id LIMIT %d",
                $feedId,
                $generation,
                $lastP,
                $lastP,
                $lastV,
                $batch
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $lastP = (int)$row['product_id'];
                $lastV = (int)$row['variant_id'];
                $row['product_id'] = $lastP;
                $row['variant_id'] = $lastV;
                $row['eligible'] = (int)$row['eligible'] === 1;
                $row['payload'] = json_decode((string)$row['payload'], true) ?: [];
                $row['issues'] = json_decode((string)$row['issues'], true) ?: [];
                $fn($row);
                $count++;
            }
        } while (count($rows) === $batch);
        return $count;
    }

    /** @return array<int,array> first $limit items of a generation (preview) */
    public function first(int $feedId, int $generation, int $limit, bool $eligibleOnly = false): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT product_id, variant_id, item_id, eligible, payload, issues FROM {$this->items}
             WHERE feed_id = %d AND generation = %d" . ($eligibleOnly ? ' AND eligible = 1' : '') . "
             ORDER BY product_id, variant_id LIMIT %d",
            $feedId,
            $generation,
            $limit
        ), ARRAY_A) ?: [];
        return array_map(static function (array $r): array {
            $r['product_id'] = (int)$r['product_id'];
            $r['variant_id'] = (int)$r['variant_id'];
            $r['eligible'] = (int)$r['eligible'] === 1;
            $r['payload'] = json_decode((string)$r['payload'], true) ?: [];
            $r['issues'] = json_decode((string)$r['issues'], true) ?: [];
            return $r;
        }, $rows);
    }

    /** @return array{total:int,eligible:int} */
    public function counts(int $feedId, int $generation): array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT COUNT(*) AS total, COALESCE(SUM(eligible), 0) AS eligible FROM {$this->items} WHERE feed_id = %d AND generation = %d",
            $feedId,
            $generation
        ), ARRAY_A);
        return ['total' => (int)($row['total'] ?? 0), 'eligible' => (int)($row['eligible'] ?? 0)];
    }

    /** @return int[] products of a generation within (after, upto] */
    public function productIdsInRange(int $feedId, int $generation, int $after, int $upto): array
    {
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT DISTINCT product_id FROM {$this->items} WHERE feed_id = %d AND generation = %d AND product_id > %d AND product_id <= %d",
            $feedId,
            $generation,
            $after,
            $upto
        )) ?: []);
    }

    /** Items of products within (after, upto] (reconciliation denominator). */
    public function countProducts(int $feedId, int $generation, int $after, int $upto): int
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->items} WHERE feed_id = %d AND generation = %d AND product_id > %d AND product_id <= %d",
            $feedId,
            $generation,
            $after,
            $upto
        ));
    }

    /** @return int[] products whose items have a scheduled price change due */
    public function dueProducts(int $feedId, int $generation, string $now, int $limit): array
    {
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT DISTINCT product_id FROM {$this->items} WHERE feed_id = %d AND generation = %d AND next_change IS NOT NULL AND next_change <= %s LIMIT %d",
            $feedId,
            $generation,
            $now,
            $limit
        )) ?: []);
    }

    /** Delete a generation in chunks (bounded statements on large feeds). */
    public function deleteGeneration(int $feedId, int $generation): void
    {
        do {
            $n = (int)$this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->items} WHERE feed_id = %d AND generation = %d LIMIT 5000",
                $feedId,
                $generation
            ));
        } while ($n === 5000);
    }

    /** Delete every generation other than $keep. */
    public function deleteOtherGenerations(int $feedId, int $keep): void
    {
        foreach (array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT DISTINCT generation FROM {$this->items} WHERE feed_id = %d AND generation <> %d",
            $feedId,
            $keep
        )) ?: []) as $gen) {
            $this->deleteGeneration($feedId, $gen);
        }
    }

    public function maxGeneration(int $feedId): int
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COALESCE(MAX(generation), 0) FROM {$this->items} WHERE feed_id = %d", $feedId));
    }

    /* ------------------------------------------------------------------ */
    /* Dirty marks                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Upsert dirty marks for products in each feed (idempotent).
     *
     * @param int[] $feedIds
     * @param int[] $productIds
     */
    public function markDirty(array $feedIds, array $productIds, ?string $at = null): void
    {
        $feedIds = array_values(array_unique(array_filter(array_map('intval', $feedIds))));
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($feedIds === [] || $productIds === []) {
            return;
        }
        $at = $at ?? gmdate('Y-m-d H:i:s.u');
        $rows = [];
        foreach ($feedIds as $f) {
            foreach ($productIds as $p) {
                $rows[] = [$f, $p];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as [$f, $p]) {
                $values[] = '(%d,%d,%s,%s)';
                array_push($params, $f, $p, $at, $at);
            }
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->dirty} (feed_id, product_id, first_marked_at, last_marked_at) VALUES " . implode(',', $values) . "
                 ON DUPLICATE KEY UPDATE last_marked_at = VALUES(last_marked_at)",
                ...$params
            ));
        }
    }

    /** @return array<int,array{product_id:int,last_marked_at:string}> */
    public function dirtyBatch(int $feedId, int $limit): array
    {
        return array_map(static fn(array $r): array => ['product_id' => (int)$r['product_id'], 'last_marked_at' => (string)$r['last_marked_at']],
            $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT product_id, last_marked_at FROM {$this->dirty} WHERE feed_id = %d ORDER BY last_marked_at, product_id LIMIT %d",
                $feedId,
                $limit
            ), ARRAY_A) ?: []);
    }

    public function dirtyCount(int $feedId): int
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->dirty} WHERE feed_id = %d", $feedId));
    }

    /**
     * Clear processed marks, but keep a mark that was touched again after it was read.
     *
     * @param array<int,array{product_id:int,last_marked_at:string}> $marks
     */
    public function clearDirty(int $feedId, array $marks): void
    {
        foreach ($marks as $m) {
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->dirty} WHERE feed_id = %d AND product_id = %d AND last_marked_at <= %s",
                $feedId,
                $m['product_id'],
                $m['last_marked_at']
            ));
        }
    }

    public function clearAllDirty(int $feedId): void
    {
        $this->wpdb->delete($this->dirty, ['feed_id' => $feedId]);
    }
}
