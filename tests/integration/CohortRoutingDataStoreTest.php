<?php
/**
 * Integration Test — P027.1 CohortRoutingDataStore, AuthorityResolver, MetaBridge & Mixed-Order Stock.
 */

use WPE\Application\Migration\LegacyCapture;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\WooDataStore\CohortRoutingDataStore;
use WPE\Compatibility\WooDataStore\InventoryBridge;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Support\AuthorityResolver;

global $wpdb;
$prefix = $wpdb->prefix;

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$origRouting = get_option(PluginBootstrap::OPTION_ROUTING, false);
$createdNativeId = 0;
$createdLegacyId = 0;
$createdExternalId = 0;
$createdOrderId = 0;

try {
    echo "=== P027.1 CohortRoutingDataStoreTest ===\n";

    // 1. Create a legacy product via standard CPT BEFORE switching to cohort_canary
    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'legacy',
    ]));
    PluginBootstrap::instance()->loadFlags();

    $legacyProd = new WC_Product_Simple();
    $legacyProd->set_name('P027 Legacy Simple');
    $legacyProd->set_regular_price('45.00');
    $legacyProd->set_sku('P027-LEG-' . wp_rand(10000, 99999));
    $legacyProd->set_manage_stock(true);
    $legacyProd->set_stock_quantity(10);
    $legacyProd->set_status('publish');
    $createdLegacyId = $legacyProd->save();
    assert($createdLegacyId > 0, 'Legacy product must be created');

    $extProd = new WC_Product_External();
    $extProd->set_name('P027 External Product');
    $extProd->set_regular_price('99.00');
    $extProd->set_product_url('https://example.com');
    $extProd->set_status('publish');
    $createdExternalId = $extProd->save();
    assert($createdExternalId > 0, 'External product must be created');

    // 2. Create a native product in wpe_products + wpe_inventory + wpe_object_state (authority = 'native_bridge')
    $repo = new ProductRepository($wpdb);
    $nativeSku = 'P027-NAT-' . wp_rand(10000, 99999);
    $createdNativeRes = $repo->create([
        'title'          => 'P027 Native Bridge Simple',
        'slug'           => 'p027-native-bridge-' . wp_rand(1000, 9999),
        'type'           => 'simple',
        'status'         => 'publish',
        'sku'            => $nativeSku,
        'regular_price'  => '120.00',
        'manage_stock'   => 1,
        'stock_quantity' => '20',
        'stock_status'   => 'instock',
    ]);
    $createdNativeId = is_array($createdNativeRes) ? (int)($createdNativeRes['id'] ?? $createdNativeRes['product_id'] ?? 0) : (int)$createdNativeRes;
    assert($createdNativeId > 0, 'Native product must be created');

    // Ensure post shell is publish so WC factory recognizes it
    $wpdb->update($wpdb->posts, [
        'post_status' => 'publish',
        'post_title'  => 'P027 Native Bridge Simple',
    ], ['ID' => $createdNativeId]);
    wp_set_object_terms($createdNativeId, 'simple', 'product_type');
    clean_post_cache($createdNativeId);

    $now = gmdate('Y-m-d H:i:s.u');
    $wpdb->replace("{$prefix}wpe_object_state", [
        'object_id'       => $createdNativeId,
        'kind'            => 'product',
        'parent_id'       => null,
        'authority'       => 'native_bridge',
        'epoch'           => 2,
        'legacy_revision' => 1,
        'native_revision' => 1,
        'legacy_applied'  => 1,
        'native_applied'  => 1,
        'tombstone'       => 0,
        'updated_at'      => $now,
    ]);
    $wpdb->replace("{$prefix}wpe_object_state", [
        'object_id'       => $createdLegacyId,
        'kind'            => 'product',
        'parent_id'       => null,
        'authority'       => 'legacy',
        'epoch'           => 1,
        'legacy_revision' => 1,
        'native_revision' => 0,
        'legacy_applied'  => 1,
        'native_applied'  => 0,
        'tombstone'       => 0,
        'updated_at'      => $now,
    ]);

    // 3. Activate cohort_canary routing for $createdNativeId and swap WooCommerce DataStores
    update_option(PluginBootstrap::OPTION_ROUTING, [
        'products'   => [$createdNativeId => 'native_bridge'],
        'categories' => [],
    ]);
    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'cohort_canary',
    ]));
    PluginBootstrap::instance()->loadFlags();

    add_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);
    MetaBridge::register();
    InventoryBridge::getInstance()->boot();

    // Reset WC_Data_Store cached instances by loading fresh product instances
    assert(AuthorityResolver::isNativeObject($createdNativeId) === true, 'Canary product must resolve as native');
    assert(AuthorityResolver::isNativeObject($createdLegacyId) === false, 'Legacy product must resolve as legacy');
    assert(AuthorityResolver::isNativeObject($createdExternalId) === false, 'External product must stay legacy');
    echo "  [OK] AuthorityResolver distinguishes native_bridge canary, legacy, and external products\n";

    // 4. Side-by-side read & update through wc_get_product()
    $loadedNative = wc_get_product($createdNativeId);
    $loadedLegacy = wc_get_product($createdLegacyId);
    assert($loadedNative instanceof WC_Product_Simple, 'Loaded native product');
    assert($loadedLegacy instanceof WC_Product_Simple, 'Loaded legacy product');
    assert((float)$loadedNative->get_regular_price() === 120.0, 'Native price read from wpe_products');
    assert((float)$loadedLegacy->get_regular_price() === 45.0, 'Legacy price read from wp_postmeta');

    $loadedNative->set_regular_price('135.00');
    $loadedNative->save();
    $loadedLegacy->set_regular_price('55.00');
    $loadedLegacy->save();

    $dbNativePrice = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT regular_price FROM {$prefix}wpe_products WHERE id = %d",
        $createdNativeId
    ));
    $dbLegacyPrice = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price' ORDER BY meta_id ASC LIMIT 1",
        $createdLegacyId
    ));
    assert($dbNativePrice === 135.0, 'Native update persisted to wpe_products');
    assert($dbLegacyPrice === 55.0, 'Legacy update persisted to wp_postmeta');
    echo "  [OK] CohortRoutingDataStore routes side-by-side native and legacy CRUD in same request\n";

    // 5. MetaBridge recognizes native_bridge AND respects native_projection origin guard
    assert((float)get_post_meta($createdNativeId, '_regular_price', true) === 135.0, 'MetaBridge reads _regular_price under native_bridge');
    LegacyCapture::withOrigin('native_projection', function () use ($createdNativeId) {
        assert(MetaBridge::instance()->isNativeAuthority($createdNativeId) === false, 'MetaBridge yields during native_projection');
    });
    echo "  [OK] MetaBridge supports native_bridge and yields under native_projection origin guard\n";

    // 6. Mixed-order stock reduction & restoration via real WC status transitions (1 native_bridge + 1 legacy)
    $order = wc_create_order();
    $order->add_product(wc_get_product($createdNativeId), 3); // 20 -> 17
    $order->add_product(wc_get_product($createdLegacyId), 2); // 10 -> 8
    $order->save();
    $createdOrderId = $order->get_id();

    $order->update_status('processing');

    $nativeQtyAfterReduce = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT quantity FROM {$prefix}wpe_inventory WHERE owner_id = %d",
        $createdNativeId
    ));
    $legacyQtyAfterReduce = (float)wc_get_product($createdLegacyId)->get_stock_quantity();
    assert($nativeQtyAfterReduce === 17.0, "Expected native stock 17.0, got {$nativeQtyAfterReduce}");
    assert($legacyQtyAfterReduce === 8.0, "Expected legacy stock 8.0, got {$legacyQtyAfterReduce}");

    $order->update_status('cancelled');

    $nativeQtyAfterRestore = (float)$wpdb->get_var($wpdb->prepare(
        "SELECT quantity FROM {$prefix}wpe_inventory WHERE owner_id = %d",
        $createdNativeId
    ));
    $legacyQtyAfterRestore = (float)wc_get_product($createdLegacyId)->get_stock_quantity();
    assert($nativeQtyAfterRestore === 20.0, "Expected restored native stock 20.0, got {$nativeQtyAfterRestore}");
    assert($legacyQtyAfterRestore === 10.0, "Expected restored legacy stock 10.0, got {$legacyQtyAfterRestore}");
    echo "  [OK] Mixed-cohort order reduces and restores native and legacy stock without double-mutation\n";

    echo "ALL P027.1 ASSERTIONS PASSED\n";
} finally {
    remove_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);
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

    if ($createdOrderId > 0) {
        $o = wc_get_order($createdOrderId);
        if ($o) {
            $o->delete(true);
        }
        $wpdb->delete("{$prefix}wpe_stock_operations", ['order_id' => $createdOrderId]);
        $wpdb->delete("{$prefix}wpe_reservation_bridge", ['order_id' => $createdOrderId]);
    }
    foreach ([$createdNativeId, $createdLegacyId, $createdExternalId] as $pid) {
        if ($pid > 0) {
            $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_audit", ['target_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_products", ['id' => $pid]);
            wp_delete_post($pid, true);
        }
    }
}
