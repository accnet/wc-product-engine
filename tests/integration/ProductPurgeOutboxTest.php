<?php
/**
 * Integration Test — permanent product purge retires the aggregate's queued outbox events.
 *
 * Verifies:
 * 1. ProductRepository::delete($id, true) marks the product's and its variants' pending/failed
 *    outbox events completed (last_error "retired: aggregate_purged"), so they never linger as
 *    pending work and degrade the health report.
 * 2. Events leased by a worker ('processing') and events of other aggregates are left untouched.
 * 3. Soft delete (trash) keeps pending events so the worker still projects the trash state.
 */

use WPE\Infrastructure\Database\ProductRepository;

global $wpdb;
$prefix = $wpdb->prefix;
$outbox = "{$prefix}wpe_outbox";

$check = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new \RuntimeException("ProductPurgeOutboxTest failed: {$msg}");
    }
};

$repo = new ProductRepository($wpdb);
$createdIds = [];
$foreignEventId = wp_generate_uuid4();
$foreignAggregateId = 99999973;

$newProduct = static function (string $label) use ($repo, &$createdIds): int {
    $created = $repo->create([
        'title'         => "PurgeOutbox {$label}",
        'slug'          => 'purge-outbox-' . strtolower($label) . '-' . wp_rand(10000, 99999),
        'type'          => 'simple',
        'status'        => 'publish',
        'sku'           => 'PURGE-OBX-' . strtoupper($label) . '-' . wp_rand(10000, 99999),
        'regular_price' => '10.00',
    ]);
    $id = (int)$created['id'];
    $createdIds[] = $id;
    return $id;
};

$states = static function (int $aggregateId) use ($wpdb, $outbox): array {
    return $wpdb->get_results($wpdb->prepare(
        "SELECT event_id, state, last_error FROM {$outbox} WHERE aggregate_id = %d ORDER BY created_at",
        $aggregateId
    ), ARRAY_A);
};

try {
    echo "=== ProductPurgeOutboxTest ===\n";

    // 1. Hard purge retires pending + failed, keeps leased events
    $pid = $newProduct('Hard');
    $repo->update($pid, ['regular_price' => '12.00']);
    $events = $states($pid);
    $check(count($events) === 2, 'expected 2 outbox events before purge, got ' . count($events));

    // Simulate one failed and one leased event on the same aggregate
    $wpdb->update($outbox, ['state' => 'failed', 'last_error' => 'boom'], ['event_id' => $events[1]['event_id']]);
    $leasedId = wp_generate_uuid4();
    $now = gmdate('Y-m-d H:i:s.u');
    $wpdb->insert($outbox, [
        'event_id'     => $leasedId,
        'aggregate_id' => $pid,
        'revision'     => 99,
        'epoch'        => 1,
        'event_type'   => 'product.updated',
        'payload'      => '{}',
        'state'        => 'processing',
        'attempts'     => 1,
        'available_at' => $now,
        'lease_owner'  => 'purge_test_worker',
        'lease_until'  => gmdate('Y-m-d H:i:s.u', time() + 60),
        'created_at'   => $now,
    ]);

    // Unrelated aggregate must stay pending
    $wpdb->insert($outbox, [
        'event_id'     => $foreignEventId,
        'aggregate_id' => $foreignAggregateId,
        'revision'     => 1,
        'epoch'        => 1,
        'event_type'   => 'product.created',
        'payload'      => '{}',
        'state'        => 'pending',
        'attempts'     => 0,
        'available_at' => $now,
        'created_at'   => $now,
    ]);

    $check($repo->delete($pid, true) === true, 'hard delete must succeed');

    foreach ($states($pid) as $ev) {
        if ($ev['event_id'] === $leasedId) {
            $check($ev['state'] === 'processing', 'leased event must stay processing');
            continue;
        }
        $check($ev['state'] === 'completed', "event {$ev['event_id']} must be completed, got {$ev['state']}");
        $check($ev['last_error'] === 'retired: aggregate_purged', 'retired event must carry purge reason');
    }
    $foreignState = $wpdb->get_var($wpdb->prepare("SELECT state FROM {$outbox} WHERE event_id = %s", $foreignEventId));
    $check($foreignState === 'pending', 'other aggregates must remain pending');
    echo "  [OK] Hard purge retires pending/failed events, leaves leased and foreign events untouched\n";

    // 2. Soft delete keeps events pending
    $softPid = $newProduct('Soft');
    $check($repo->delete($softPid, false) === true, 'soft delete must succeed');
    $pendingSoft = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$outbox} WHERE aggregate_id = %d AND state = 'pending'",
        $softPid
    ));
    $check($pendingSoft >= 1, 'soft delete must keep pending events for projection');
    echo "  [OK] Soft delete keeps pending events for the projection worker\n";

    echo "ProductPurgeOutboxTest: PASS\n";
} finally {
    foreach ($createdIds as $id) {
        try {
            $repo->delete($id, true);
        } catch (\Throwable $e) {
            // already purged
        }
        $wpdb->delete($outbox, ['aggregate_id' => $id]);
        $wpdb->delete("{$prefix}wpe_audit", ['target_id' => $id]);
        if (get_post($id)) {
            wp_delete_post($id, true);
        }
    }
    $wpdb->delete($outbox, ['event_id' => $foreignEventId]);
}
