<?php
/**
 * Integration Test — P027.2 OutboxProjectionWorker, authority_epoch alignment & native_bridge mirroring.
 */

use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Migration\OutboxProjectionWorker;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Infrastructure\Database\ProductRepository;

global $wpdb;
$prefix = $wpdb->prefix;

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$mirror = LegacyMirror::getInstance();
$origGlobalEpoch = $mirror->getGlobalEpoch();
$createdPid = 0;
$fakeGhostId = 99999991;
$staleEventIds = [];

try {
    echo "=== P027.2 OutboxProjectionWorkerTest ===\n";

    // Drain or snapshot pre-existing pending count so test only inspects its own events
    $mirror->setGlobalEpoch(max($origGlobalEpoch + 2, 4));
    $targetEpoch = $mirror->getGlobalEpoch();

    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'native_bridge',
    ]));
    PluginBootstrap::instance()->loadFlags();

    $repo = new ProductRepository($wpdb);
    $sku = 'P0272-BRIDGE-' . wp_rand(10000, 99999);
    $created = $repo->create([
        'title'          => 'P027.2 Bridge Product v1',
        'slug'           => 'p0272-bridge-' . wp_rand(1000, 9999),
        'type'           => 'simple',
        'status'         => 'publish',
        'sku'            => $sku,
        'regular_price'  => '80.00',
        'manage_stock'   => 1,
        'stock_quantity' => '15',
        'stock_status'   => 'instock',
    ]);
    $createdPid = (int)$created['id'];
    assert($createdPid > 0, 'Created native_bridge product');

    // Perform 2 rapid updates before worker runs
    $repo->update($createdPid, ['regular_price' => '95.00']);
    $repo->update($createdPid, ['regular_price' => '110.00', 'stock_quantity' => '25']);

    // Verify outbox stamped targetEpoch (not stale current_epoch=1) and native_applied stayed 0
    $eventEpochs = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT epoch FROM {$prefix}wpe_outbox WHERE aggregate_id = %d AND state = 'pending'",
        $createdPid
    )));
    assert(count($eventEpochs) === 3, 'Expected 3 pending outbox events (1 create + 2 updates)');
    foreach ($eventEpochs as $ep) {
        assert($ep === $targetEpoch, "Expected outbox epoch {$targetEpoch}, got {$ep}");
    }

    $stateBefore = $wpdb->get_row($wpdb->prepare(
        "SELECT authority, epoch, native_revision, native_applied FROM {$prefix}wpe_object_state WHERE object_id = %d",
        $createdPid
    ), ARRAY_A);
    assert($stateBefore['authority'] === 'native_bridge', 'Initial authority must be native_bridge');
    assert((int)$stateBefore['native_revision'] === 3, 'native_revision must be 3');
    assert((int)$stateBefore['native_applied'] === 0, 'native_applied must stay 0 until worker mirrors');
    assert($mirror->getProjectionLag()['pending_projections'] >= 1, 'getProjectionLag must detect unmirrored native_bridge row');
    echo "  [OK] ProductRepository stamps authority_epoch and tracks unmirrored native_revision under native_bridge\n";

    // Also insert a ghost deleted-aggregate outbox event to verify clean retirement
    $ghostEventId = wp_generate_uuid4();
    $staleEventIds[] = $ghostEventId;
    $now = gmdate('Y-m-d H:i:s.u');
    $wpdb->insert("{$prefix}wpe_outbox", [
        'event_id'     => $ghostEventId,
        'aggregate_id' => $fakeGhostId,
        'revision'     => 1,
        'epoch'        => 1,
        'event_type'   => 'product.updated',
        'payload'      => wp_json_encode(['product_id' => $fakeGhostId]),
        'state'        => 'pending',
        'attempts'     => 0,
        'available_at' => $now,
        'created_at'   => $now,
    ]);

    // Run OutboxProjectionWorker scoped strictly to our test aggregates so baseline DB rows stay untouched
    $worker = new OutboxProjectionWorker($wpdb);
    $drainRes = $worker->drainAll(100, 10, [$createdPid, $fakeGhostId]);
    assert($drainRes['failed'] === 0, 'Zero failed outbox events');
    assert($drainRes['mirrored'] === 1, 'Expected exactly 1 coalesced mirror execution');
    assert($drainRes['retired_stale'] === 1, 'Deleted ghost aggregate event was retired cleanly');
    assert($drainRes['remaining_pending'] === 0, 'All scoped outbox events drained');

    // Verify object_state preserved 'native_bridge' and updated native_applied = 3
    $stateAfter = $wpdb->get_row($wpdb->prepare(
        "SELECT authority, epoch, native_revision, native_applied FROM {$prefix}wpe_object_state WHERE object_id = %d",
        $createdPid
    ), ARRAY_A);
    assert($stateAfter['authority'] === 'native_bridge', "Expected authority 'native_bridge' preserved after mirror, got {$stateAfter['authority']}");
    assert((int)$stateAfter['native_applied'] >= 3, 'native_applied caught up to native_revision');

    // Verify wp_postmeta actually received the mirrored latest price (110.00) and SKU
    $mirroredPrice = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price' ORDER BY meta_id ASC LIMIT 1",
        $createdPid
    ));
    $mirroredSku = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_sku' ORDER BY meta_id ASC LIMIT 1",
        $createdPid
    ));
    assert($mirroredPrice === 110.0, "Expected mirrored _regular_price 110.0, got {$mirroredPrice}");
    assert($mirroredSku === $sku, "Expected mirrored _sku {$sku}, got {$mirroredSku}");
    echo "  [OK] OutboxProjectionWorker coalesces events, mirrors to wp_postmeta, preserves native_bridge, and retires ghost events\n";

    echo "ALL P027.2 ASSERTIONS PASSED\n";
} finally {
    $mirror->setGlobalEpoch($origGlobalEpoch);
    if ($origFlags === false) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    PluginBootstrap::instance()->loadFlags();

    foreach ($staleEventIds as $eid) {
        $wpdb->delete("{$prefix}wpe_outbox", ['event_id' => $eid]);
    }
    if ($createdPid > 0) {
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_audit", ['target_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_catalog_facets", ['product_id' => $createdPid]);
        $wpdb->delete("{$prefix}wpe_products", ['id' => $createdPid]);
        $wpdb->delete($prefix . 'wc_product_meta_lookup', ['product_id' => $createdPid]);
        wp_delete_post($createdPid, true);
    }
}
