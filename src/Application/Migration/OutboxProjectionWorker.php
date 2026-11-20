<?php

declare(strict_types=1);

namespace WPE\Application\Migration;

use wpdb;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Infrastructure\Database\OutboxWorker;
use WPE\Support\Singleton;

/**
 * OutboxProjectionWorker — Deduplicating outbox consumer for native_bridge & indexed modes.
 *
 * Consumes due events from wpe_outbox, coalesces multiple rapid mutations per product aggregate
 * into a single LegacyMirror projection (when under 'native' / 'native_bridge' authority) and a
 * single incremental CatalogProjector refresh, and cleanly retires obsolete pre-epoch or deleted-product
 * events so the queue never spins on stale historical rows.
 */
class OutboxProjectionWorker
{
    use Singleton;

    private wpdb $wpdb;
    private OutboxWorker $outboxWorker;
    private LegacyMirror $mirror;
    private CatalogProjector $projector;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableObjectState;
    private string $tableCatalog;

    public function __construct(
        ?wpdb $wpdb = null,
        ?OutboxWorker $outboxWorker = null,
        ?LegacyMirror $mirror = null,
        ?CatalogProjector $projector = null
    ) {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->outboxWorker = $outboxWorker ?? new OutboxWorker($this->wpdb);
        $this->mirror = $mirror ?? LegacyMirror::getInstance();
        $this->projector = $projector ?? new CatalogProjector($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableCatalog     = "{$prefix}wpe_catalog";
    }

    /**
     * Process one leased batch of outbox events.
     *
     * @param int $batchSize
     * @param int $leaseSeconds
     * @param string|null $workerId
     * @param int[] $onlyAggregateIds Optional filter restricting acquisition to specific aggregate IDs.
     */
    public function processBatch(int $batchSize = 100, int $leaseSeconds = 60, ?string $workerId = null, array $onlyAggregateIds = []): array
    {
        $workerId = $workerId ?? ('outbox_proj_' . getmypid() . '_' . wp_rand(1000, 9999));
        if (!empty($onlyAggregateIds)) {
            $events = $this->acquireScopedBatch($workerId, array_values(array_map('intval', $onlyAggregateIds)), max(1, $batchSize), max(10, $leaseSeconds));
        } else {
            $events = $this->outboxWorker->acquireBatch($workerId, max(1, $batchSize), max(10, $leaseSeconds));
        }

        $summary = [
            'acquired'          => count($events),
            'completed'         => 0,
            'mirrored'          => 0,
            'projected_catalog' => 0,
            'retired_stale'     => 0,
            'failed'            => 0,
        ];

        if (empty($events)) {
            return $summary;
        }

        // Group acquired events by parent product aggregate ID
        $groups = [];
        foreach ($events as $ev) {
            $parentId = $this->resolveParentProductId($ev);
            $groups[$parentId][] = $ev;
        }

        $activeGen = $this->projector->getActiveGeneration();
        $hasCatalogGen = $activeGen > 0 && (bool)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM {$this->tableCatalog} WHERE generation = %d LIMIT 1",
            $activeGen
        ));

        foreach ($groups as $productId => $groupEvents) {
            if ($productId <= 0) {
                foreach ($groupEvents as $ev) {
                    $this->outboxWorker->completeEvent((string)$ev['event_id']);
                    $summary['completed']++;
                    $summary['retired_stale']++;
                }
                continue;
            }

            $product = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id, version, status, deleted_at FROM {$this->tableProducts} WHERE id = %d",
                $productId
            ), ARRAY_A);

            if (!$product) {
                // Aggregate was permanently deleted; retire obsolete events cleanly
                foreach ($groupEvents as $ev) {
                    $this->outboxWorker->completeEvent((string)$ev['event_id']);
                    $summary['completed']++;
                    $summary['retired_stale']++;
                }
                continue;
            }

            $state = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT authority, epoch, native_revision, native_applied, tombstone FROM {$this->tableObjectState} WHERE object_id = %d",
                $productId
            ), ARRAY_A);

            $effectiveEpoch = $state ? (int)$state['epoch'] : $this->mirror->getGlobalEpoch();
            $authority = $state ? (string)$state['authority'] : 'legacy';

            $maxEventRev = 0;
            $maxEventEpoch = 0;
            foreach ($groupEvents as $ev) {
                $maxEventRev = max($maxEventRev, (int)($ev['revision'] ?? 0));
                $maxEventEpoch = max($maxEventEpoch, (int)($ev['epoch'] ?? 0));
            }

            $hasUnmirroredState = !$state || ((int)$state['native_revision'] > (int)$state['native_applied']);
            $allEventsPreEpoch = ($maxEventEpoch < $effectiveEpoch);

            // Historical pre-epoch events whose aggregate state is already fully applied can be retired without re-writing postmeta
            if ($allEventsPreEpoch && !$hasUnmirroredState) {
                foreach ($groupEvents as $ev) {
                    $this->outboxWorker->completeEvent((string)$ev['event_id']);
                    $summary['completed']++;
                    $summary['retired_stale']++;
                }
                continue;
            }

            try {
                if (in_array($authority, ['native', 'native_bridge'], true)) {
                    $nativeApplied = $state ? (int)$state['native_applied'] : 0;
                    $targetRev = max((int)$product['version'], $maxEventRev, $nativeApplied + 1);

                    if (!empty($product['deleted_at']) || ($product['status'] ?? '') === 'trash') {
                        // Project trash status onto legacy post shell inside origin guard
                        LegacyCapture::withOrigin('native_projection', function () use ($productId) {
                            $this->wpdb->update($this->wpdb->posts, ['post_status' => 'trash'], ['ID' => $productId]);
                            clean_post_cache($productId);
                        });
                        if ($state) {
                            $this->wpdb->update(
                                $this->tableObjectState,
                                ['native_applied' => max((int)$state['native_revision'], $targetRev), 'updated_at' => gmdate('Y-m-d H:i:s.u')],
                                ['object_id' => $productId]
                            );
                        }
                    } else {
                        $mirrorRes = $this->mirror->mirrorAggregate($productId, $targetRev, $effectiveEpoch);
                        if (($mirrorRes['status'] ?? '') === 'mirrored') {
                            $summary['mirrored']++;
                        }
                    }
                }

                if ($hasCatalogGen) {
                    $this->projector->projectProduct($productId, $activeGen);
                    $summary['projected_catalog']++;
                }

                foreach ($groupEvents as $ev) {
                    $this->outboxWorker->completeEvent((string)$ev['event_id']);
                    $summary['completed']++;
                }
            } catch (\Throwable $e) {
                foreach ($groupEvents as $ev) {
                    $this->outboxWorker->failEvent((string)$ev['event_id'], $e->getMessage());
                    $summary['failed']++;
                }
            }
        }

        return $summary;
    }

    /**
     * Drain outbox in bounded batches until empty or maxBatches reached.
     *
     * @param int $batchSize
     * @param int $maxBatches
     * @param int[] $onlyAggregateIds
     */
    public function drainAll(int $batchSize = 100, int $maxBatches = 100, array $onlyAggregateIds = []): array
    {
        $totals = [
            'batches'           => 0,
            'acquired'          => 0,
            'completed'         => 0,
            'mirrored'          => 0,
            'projected_catalog' => 0,
            'retired_stale'     => 0,
            'failed'            => 0,
        ];

        for ($i = 0; $i < max(1, $maxBatches); $i++) {
            $res = $this->processBatch($batchSize, 60, null, $onlyAggregateIds);
            if ($res['acquired'] === 0) {
                break;
            }
            $totals['batches']++;
            $totals['acquired']          += $res['acquired'];
            $totals['completed']         += $res['completed'];
            $totals['mirrored']          += $res['mirrored'];
            $totals['projected_catalog'] += $res['projected_catalog'];
            $totals['retired_stale']     += $res['retired_stale'];
            $totals['failed']            += $res['failed'];
        }

        if (!empty($onlyAggregateIds)) {
            $inIds = implode(',', array_map('intval', $onlyAggregateIds));
            $totals['remaining_pending'] = (int)$this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_outbox WHERE state = 'pending' AND aggregate_id IN ({$inIds})"
            );
        } else {
            $totals['remaining_pending'] = (int)$this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_outbox WHERE state = 'pending'"
            );
        }

        return $totals;
    }

    private function acquireScopedBatch(string $workerId, array $aggregateIds, int $batchSize, int $leaseSeconds): array
    {
        $ids = array_values(array_filter(array_map('intval', $aggregateIds), static fn(int $v): bool => $v > 0));
        if (empty($ids)) {
            return [];
        }
        $inSql = implode(',', $ids);
        $now = gmdate('Y-m-d H:i:s.u');
        $leaseUntil = gmdate('Y-m-d H:i:s.u', time() + $leaseSeconds);
        $tableOutbox = "{$this->wpdb->prefix}wpe_outbox";

        $candidates = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT event_id FROM {$tableOutbox}
             WHERE aggregate_id IN ({$inSql})
               AND ((state IN ('pending', 'failed') AND available_at <= %s)
                 OR (state = 'processing' AND lease_until < %s))
             ORDER BY available_at ASC, created_at ASC
             LIMIT %d",
            $now,
            $now,
            $batchSize
        ));

        if (empty($candidates)) {
            return [];
        }

        $acquired = [];
        foreach ($candidates as $eventId) {
            $updated = $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$tableOutbox}
                 SET state = 'processing', lease_owner = %s, lease_until = %s, attempts = attempts + 1
                 WHERE event_id = %s
                   AND ((state IN ('pending', 'failed') AND available_at <= %s) OR lease_until < %s)",
                $workerId,
                $leaseUntil,
                $eventId,
                $now,
                $now
            ));
            if ($updated > 0) {
                $row = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT * FROM {$tableOutbox} WHERE event_id = %s",
                    $eventId
                ), ARRAY_A);
                if ($row) {
                    $row['payload'] = json_decode((string)$row['payload'], true);
                    $acquired[] = $row;
                }
            }
        }

        return $acquired;
    }

    private function resolveParentProductId(array $event): int
    {
        $aggId = (int)($event['aggregate_id'] ?? 0);
        if ($aggId <= 0) {
            return 0;
        }

        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (!empty($payload['parent_id'])) {
            return (int)$payload['parent_id'];
        }

        $inProd = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableProducts} WHERE id = %d",
            $aggId
        ));
        if ($inProd > 0) {
            return $aggId;
        }

        // Check if aggregate_id is a variation ID
        $parentFromVar = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT product_id FROM {$this->tableVariants} WHERE id = %d",
            $aggId
        ));
        if ($parentFromVar) {
            return (int)$parentFromVar;
        }

        return $aggId;
    }
}
