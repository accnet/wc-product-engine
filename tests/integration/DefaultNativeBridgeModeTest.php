<?php
/**
 * P033 Integration Test — Default native_bridge mode and feature flag suite on install.
 *
 * Verifies:
 * 1. Fresh install (no wpe_feature_flags, no wpe_control system_mode/authority_epoch/cohort_active_all)
 *    initializes native_bridge mode, epoch 1, and all engine + Product Page flags (media_enabled = false).
 * 2. OperationsPage health/mode, PluginBootstrap runtime gates, AuthorityResolver, and SelectorAdapter
 *    all report native_bridge + enabled Product Page flags out of the box (both before and after option save).
 * 3. Re-activation never overwrites customized flags or explicit control/rollback state.
 * 4. Standard WooCommerce product creation immediately writes to native storage under native_bridge
 *    and mirrors to legacy postmeta via OutboxProjectionWorker.
 */

use WPE\Admin\OperationsPage;
use WPE\Application\Migration\OutboxProjectionWorker;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Support\AuthorityResolver;

global $wpdb;
$prefix = $wpdb->prefix;
$controlTable = "{$prefix}wpe_control";
$bootstrap = PluginBootstrap::instance();

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, null);
$origRouting = get_option(PluginBootstrap::OPTION_ROUTING, null);
$controlKeys = ['system_mode', 'authority_epoch', 'cohort_active_all'];
$origControl = [];
foreach ($controlKeys as $ck) {
    $origControl[$ck] = $wpdb->get_row(
        $wpdb->prepare("SELECT `key`, `value`, `version`, `updated_at` FROM {$controlTable} WHERE `key` = %s", $ck),
        ARRAY_A
    );
}

$createdProductId = 0;

$check = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new \RuntimeException("DefaultNativeBridgeModeTest failed: {$msg}");
    }
};

try {
    echo "=== P033 DefaultNativeBridgeModeTest ===\n";

    // 1. Pre-activation / missing option & missing control rows fallback check
    delete_option(PluginBootstrap::OPTION_FLAGS);
    delete_option(PluginBootstrap::OPTION_ROUTING);
    foreach ($controlKeys as $ck) {
        $wpdb->delete($controlTable, ['key' => $ck]);
    }
    $bootstrap->loadFlags();
    AuthorityResolver::flushAll();

    $check($bootstrap->getSystemMode() === 'native_bridge', 'Fallback getSystemMode() without control row must be native_bridge');
    $check($bootstrap->isNativeRuntimeActive() === true, 'Native runtime must be active under default flags');
    $check(AuthorityResolver::defaultNewObjectAuthority() === 'native_bridge', 'Default new object authority must be native_bridge');

    $selectorFlagsBeforeSave = SelectorAdapter::instance()->getProductPageFlags();
    foreach ([
        'single_price_display',
        'show_selected_value',
        'show_help_text',
        'button_selector',
        'color_swatches',
        'image_swatches',
        'mark_unavailable_options',
        'auto_select_first_values',
    ] as $pf) {
        $check($selectorFlagsBeforeSave[$pf] === true, "SelectorAdapter flag {$pf} must default to true when option is absent");
    }
    echo "  [OK] Missing option and control rows resolve to native_bridge and enabled flags.\n";

    // 2. Fresh activation seeds wpe_feature_flags and wpe_control
    PluginBootstrap::onActivate();
    $bootstrap->loadFlags();
    $storedFlags = get_option(PluginBootstrap::OPTION_FLAGS, null);
    $check(is_array($storedFlags), 'onActivate() must persist wpe_feature_flags on fresh install');
    $check(($storedFlags['cohort_mode'] ?? '') === 'native_bridge', 'Stored cohort_mode must be native_bridge');
    $check(($storedFlags['media_enabled'] ?? true) === false, 'Stored media_enabled must be false');
    foreach ([
        'variants_ui_enabled',
        'query_enabled',
        'resolver_enabled',
        'native_writes_enabled',
        'single_price_display',
        'show_selected_value',
        'show_help_text',
        'button_selector',
        'color_swatches',
        'image_swatches',
        'mark_unavailable_options',
        'auto_select_first_values',
    ] as $key) {
        $check(($storedFlags[$key] ?? false) === true, "Stored flag {$key} must be true");
    }

    $sysModeVal = (string)$wpdb->get_var($wpdb->prepare("SELECT `value` FROM {$controlTable} WHERE `key` = %s", 'system_mode'));
    $epochVal = (string)$wpdb->get_var($wpdb->prepare("SELECT `value` FROM {$controlTable} WHERE `key` = %s", 'authority_epoch'));
    $cohortJson = (string)$wpdb->get_var($wpdb->prepare("SELECT `value` FROM {$controlTable} WHERE `key` = %s", 'cohort_active_all'));
    $cohortData = json_decode($cohortJson, true);

    $check($sysModeVal === 'native_bridge', "wpe_control['system_mode'] must be native_bridge, got: {$sysModeVal}");
    $check($epochVal === '1', "wpe_control['authority_epoch'] must be 1 on fresh install, got: {$epochVal}");
    $check(is_array($cohortData) && ($cohortData['mode'] ?? '') === 'native_bridge' && !empty($cohortData['active']), 'cohort_active_all must be active native_bridge');

    $ops = OperationsPage::getInstance();
    $health = $ops->getHealthReport();
    $modeRes = $ops->handleAction('mode', ['mode' => ''], 0);
    $check($health['system_mode'] === 'native_bridge', 'Health report system_mode must be native_bridge');
    $check($modeRes['system_mode'] === 'native_bridge' && $modeRes['cohort_mode'] === 'native_bridge', 'Operations mode action must report native_bridge');
    echo "  [OK] Fresh activation persists DEFAULT_FLAGS and native_bridge control state.\n";

    // 3. Immediate native_bridge CRUD & outbox mirror out-of-the-box
    $sku = 'P033-DEF-' . wp_generate_password(6, false);
    $prod = new \WC_Product_Simple();
    $prod->set_name('P033 Default Native Bridge Product');
    $prod->set_status('publish');
    $prod->set_sku($sku);
    $prod->set_regular_price('39.50');
    $createdProductId = (int)$prod->save();
    $check($createdProductId > 0, 'Saved new WC_Product_Simple');

    $nativeRow = $wpdb->get_row(
        $wpdb->prepare("SELECT id, sku, regular_price FROM {$prefix}wpe_products WHERE id = %d", $createdProductId),
        ARRAY_A
    );
    $stateAuth = (string)$wpdb->get_var(
        $wpdb->prepare("SELECT authority FROM {$prefix}wpe_object_state WHERE object_id = %d", $createdProductId)
    );
    $check(is_array($nativeRow) && $nativeRow['sku'] === $sku, 'New product must be written directly to wpe_products');
    $check(in_array($stateAuth, ['native', 'native_bridge'], true), "Object state authority must be native/native_bridge, got: {$stateAuth}");
    $check(AuthorityResolver::resolveObjectAuthority($createdProductId) === $stateAuth, 'AuthorityResolver resolves new product to native authority');

    $worker = new OutboxProjectionWorker($wpdb);
    $worker->drainAll(50, 5, [$createdProductId]);
    $mirroredSku = (string)get_post_meta($createdProductId, '_sku', true);
    $rawPostmetaSku = (string)$wpdb->get_var(
        $wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_sku' LIMIT 1", $createdProductId)
    );
    $check($mirroredSku === $sku && $rawPostmetaSku === $sku, 'Outbox worker mirrors new product SKU to raw postmeta under default native_bridge');
    echo "  [OK] Out-of-the-box WC_Product CRUD writes natively and mirrors cleanly.\n";

    // 4. Re-activation does not overwrite customized flags or explicit control state
    $customFlags = $storedFlags;
    $customFlags['single_price_display'] = false;
    $customFlags['cohort_mode'] = 'legacy';
    update_option(PluginBootstrap::OPTION_FLAGS, $customFlags);
    $wpdb->replace($controlTable, [
        'key'        => 'system_mode',
        'value'      => 'legacy_restored',
        'version'    => 2,
        'updated_at' => gmdate('Y-m-d H:i:s.u'),
    ]);
    $wpdb->replace($controlTable, [
        'key'        => 'authority_epoch',
        'value'      => '9',
        'version'    => 2,
        'updated_at' => gmdate('Y-m-d H:i:s.u'),
    ]);

    PluginBootstrap::onActivate();
    $afterReactivateFlags = get_option(PluginBootstrap::OPTION_FLAGS, null);
    $afterSysMode = (string)$wpdb->get_var($wpdb->prepare("SELECT `value` FROM {$controlTable} WHERE `key` = %s", 'system_mode'));
    $afterEpoch = (string)$wpdb->get_var($wpdb->prepare("SELECT `value` FROM {$controlTable} WHERE `key` = %s", 'authority_epoch'));

    $check($afterReactivateFlags === $customFlags, 'Re-activation must not overwrite customized flags');
    $check($afterSysMode === 'legacy_restored', 'Re-activation must not overwrite explicit system_mode');
    $check($afterEpoch === '9', 'Re-activation must not overwrite authority_epoch');
    echo "  [OK] Re-activation preserves customized flags and control records.\n";

    echo "ALL P033 DefaultNativeBridgeModeTest CHECKS PASSED\n";
} finally {
    if ($createdProductId > 0) {
        $wpdb->delete("{$prefix}wpe_products", ['id' => $createdProductId]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $createdProductId]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $createdProductId]);
        $wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $createdProductId]);
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $createdProductId]);
        wp_delete_post($createdProductId, true);
    }

    if ($origFlags === null) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }

    if ($origRouting === null) {
        delete_option(PluginBootstrap::OPTION_ROUTING);
    } else {
        update_option(PluginBootstrap::OPTION_ROUTING, $origRouting);
    }

    foreach ($controlKeys as $ck) {
        $wpdb->delete($controlTable, ['key' => $ck]);
        if (is_array($origControl[$ck])) {
            $wpdb->insert($controlTable, $origControl[$ck]);
        }
    }

    $bootstrap->loadFlags();
    AuthorityResolver::flushAll();
}
