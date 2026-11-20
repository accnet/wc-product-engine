<?php
/**
 * Integration Test — P027.3 End-to-End Governed Rollout:
 * mode (legacy -> shadow -> indexed) -> gates -> canary cutover (native_bridge) -> native write + outbox mirror -> rollback.
 */

use WPE\Admin\OperationsPage;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Support\AuthorityResolver;

global $wpdb;
$prefix = $wpdb->prefix;
$ops = OperationsPage::getInstance();

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$origRouting = get_option(PluginBootstrap::OPTION_ROUTING, false);
$origSysMode = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$prefix}wpe_control WHERE `key` = %s", 'system_mode'));
$origAuthEpoch = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$prefix}wpe_control WHERE `key` = %s", 'authority_epoch'));

$canaryPid = 0;
$orphanDraftId = 0;
$cohortId = 'p0273_canary_' . wp_rand(1000, 9999);

try {
    echo "=== P027.3 NativeBridgeRolloutTest ===\n";

    // Ensure clean starting mode
    update_option(PluginBootstrap::OPTION_ROUTING, ['products' => [], 'categories' => []]);
    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'legacy',
    ]));
    PluginBootstrap::instance()->loadFlags();
    $wpdb->delete("{$prefix}wpe_control", ['key' => 'system_mode']);

    // 1. Pre-cutover mode transitions & guard against direct native_bridge jump
    $resShadow = $ops->handleAction('mode', ['mode' => 'shadow'], 0);
    assert($resShadow['system_mode'] === 'shadow' && $resShadow['query_enabled'] === false, 'Switched to shadow');

    $resIndexed = $ops->handleAction('mode', ['mode' => 'indexed'], 0);
    assert($resIndexed['system_mode'] === 'indexed' && $resIndexed['query_enabled'] === true, 'Switched to indexed and enabled query flag');

    $blockedDirect = false;
    try {
        $ops->handleAction('mode', ['mode' => 'native_bridge'], 0);
    } catch (\InvalidArgumentException $e) {
        $blockedDirect = true;
    }
    assert($blockedDirect === true, 'Direct mode jump to native_bridge must be rejected');
    echo "  [OK] Pre-cutover mode transitions (shadow -> indexed) work and block direct native_bridge jump\n";

    // 2. Hygiene dry-run vs targeted orphan auto-draft check
    $orphanDraftId = wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'auto-draft',
        'post_title'  => 'P027.3 Orphan Auto Draft',
    ]);
    assert($orphanDraftId > 0, 'Created orphan auto-draft post');

    $dryHygiene = $ops->handleAction('hygiene', ['apply' => false], 0);
    assert($dryHygiene['status'] === 'dry_run' && $dryHygiene['orphan_auto_draft_count'] >= 1, 'Dry-run detects orphan auto-draft');
    assert(get_post($orphanDraftId) !== null, 'Dry-run did not delete post');
    wp_delete_post($orphanDraftId, true);
    $orphanDraftId = 0;
    echo "  [OK] Hygiene dry-run accurately reports residue without mutating DB\n";

    // 3. Create canary fixture product in both legacy shell and wpe_products (initially authority = 'legacy')
    $repo = new ProductRepository($wpdb);
    $sku = 'P0273-CAN-' . wp_rand(10000, 99999);
    $created = $repo->create([
        'title'          => 'P027.3 Canary Product',
        'slug'           => 'p0273-canary-' . wp_rand(1000, 9999),
        'type'           => 'simple',
        'status'         => 'publish',
        'sku'            => $sku,
        'regular_price'  => '200.00',
        'manage_stock'   => 1,
        'stock_quantity' => '30',
        'stock_status'   => 'instock',
    ]);
    $canaryPid = (int)$created['id'];
    $wpdb->update($wpdb->posts, ['post_status' => 'publish', 'post_title' => 'P027.3 Canary Product'], ['ID' => $canaryPid]);
    wp_set_object_terms($canaryPid, 'simple', 'product_type');
    clean_post_cache($canaryPid);

    $curEpoch = (int)($origAuthEpoch ?: 2);
    $wpdb->update("{$prefix}wpe_object_state", [
        'authority' => 'legacy',
        'epoch'     => $curEpoch,
    ], ['object_id' => $canaryPid]);

    // Clear initial create outbox event so test tracks post-cutover write cleanly
    $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $canaryPid]);

    // 4. Evaluate Readiness Gates via OperationsPage (CLI vs Web actor token hygiene AC-T41-02)
    $adminUsers = get_users(['role' => 'administrator', 'number' => 1]);
    $adminId = !empty($adminUsers) ? (int)$adminUsers[0]->ID : 0;
    if ($adminId > 0) {
        $webGateRes = $ops->handleAction('gates', ['cohort_id' => $cohortId], $adminId);
        assert($webGateRes['gate_receipt_token'] === null, 'Web actor response must not leak raw HMAC receipt token');
        foreach ($webGateRes['gates'] as $gInfo) {
            assert(!isset($gInfo['artifact']), 'Web actor response must not leak server filesystem paths');
        }
    }

    $cliGateRes = $ops->handleAction('gates', ['cohort_id' => $cohortId], 0);
    assert($cliGateRes['all_passed'] === true && !empty($cliGateRes['gate_receipt_token']), 'CLI gates passed 12/12 and issued token');
    echo "  [OK] Gates action passes 12/12, issues CLI receipt token, and strips paths/tokens for web actors\n";

    // 5. Execute governed canary cutover to native_bridge
    $cutRes = $ops->handleAction('cutover', [
        'cohort_id'          => $cohortId,
        'gate_receipt_token' => $cliGateRes['gate_receipt_token'],
        'product_ids'        => [$canaryPid],
    ], 0);
    assert($cutRes['status'] === 'completed', 'Cutover completed');

    $stateCut = $wpdb->get_row($wpdb->prepare(
        "SELECT authority, epoch FROM {$prefix}wpe_object_state WHERE object_id = %d",
        $canaryPid
    ), ARRAY_A);
    assert($stateCut['authority'] === 'native_bridge', 'Object authority flipped to native_bridge');
    assert(PluginBootstrap::instance()->isNativeRuntimeActive() === true, 'PluginBootstrap native runtime is now active');
    assert(AuthorityResolver::isNativeObject($canaryPid) === true, 'Canary product resolves as native');

    // Hook CohortRoutingDataStore as PluginBootstrap does on live requests
    add_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);

    // Mutate price via WooCommerce CRUD under native_bridge, then drain outbox to mirror to wp_postmeta
    $wcProd = wc_get_product($canaryPid);
    $wcProd->set_regular_price('249.00');
    $wcProd->save();

    $outboxRes = $ops->handleAction('outbox', ['product_ids' => [$canaryPid]], 0);
    assert($outboxRes['mirrored'] >= 1 && $outboxRes['failed'] === 0, 'Outbox mirrored native_bridge mutation');

    $mirroredLegacyPrice = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price' ORDER BY meta_id ASC LIMIT 1",
        $canaryPid
    ));
    assert($mirroredLegacyPrice === 249.0, "Expected mirrored postmeta _regular_price 249.0, got {$mirroredLegacyPrice}");
    echo "  [OK] Canary cutover synchronized state, routed WC CRUD to native_bridge, and mirrored back to postmeta\n";

    // 6. Execute governed rollback back to legacy
    $rbRes = $ops->handleAction('rollback', ['cohort_ids' => [$canaryPid]], 0);
    assert($rbRes['status'] === 'completed', 'Rollback completed');

    $stateRb = $wpdb->get_row($wpdb->prepare(
        "SELECT authority, epoch FROM {$prefix}wpe_object_state WHERE object_id = %d",
        $canaryPid
    ), ARRAY_A);
    assert($stateRb['authority'] === 'legacy', 'Rollback restored object authority to legacy');
    assert((int)$stateRb['epoch'] > (int)$stateCut['epoch'], 'Rollback incremented epoch monotonically');
    assert(AuthorityResolver::isNativeObject($canaryPid) === false, 'Rolled-back product resolves as legacy again');
    echo "  [OK] Governed rollback reconciled data, bumped epoch, and restored legacy routing\n";

    echo "ALL P027.3 ASSERTIONS PASSED\n";
} finally {
    remove_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);

    if ($orphanDraftId > 0) {
        wp_delete_post($orphanDraftId, true);
    }
    if ($canaryPid > 0) {
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_audit", ['target_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_catalog_facets", ['product_id' => $canaryPid]);
        $wpdb->delete("{$prefix}wpe_products", ['id' => $canaryPid]);
        $wpdb->delete($prefix . 'wc_product_meta_lookup', ['product_id' => $canaryPid]);
        wp_delete_post($canaryPid, true);
    }

    // Clean cutover/rollback jobs and control keys created during test
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}wpe_control WHERE `key` LIKE %s", '%' . $wpdb->esc_like($cohortId) . '%'));
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}wpe_jobs WHERE config LIKE %s", '%' . $wpdb->esc_like($cohortId) . '%'));

    if ($origSysMode === null) {
        $wpdb->delete("{$prefix}wpe_control", ['key' => 'system_mode']);
    } else {
        $wpdb->replace("{$prefix}wpe_control", [
            'key'        => 'system_mode',
            'value'      => (string)$origSysMode,
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
    if ($origAuthEpoch !== null) {
        $wpdb->replace("{$prefix}wpe_control", [
            'key'        => 'authority_epoch',
            'value'      => (string)$origAuthEpoch,
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
    if ($origFlags === false) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    if ($origRouting === false) {
        delete_option(PluginBootstrap::OPTION_ROUTING);
    } else {
        update_option(PluginBootstrap::OPTION_ROUTING, $origRouting);
    }
    PluginBootstrap::instance()->loadFlags();
}
