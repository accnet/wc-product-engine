<?php
/**
 * P034 Integration Test — Streamlined Single-Mode Operations & 1-Click Legacy Import.
 *
 * Verifies:
 * 1. Settings Overview omits redundant Default routing card; the Operations tab (the only operations UI)
 *    exposes outbox, rebuild, import_legacy and isolate rollback in .wpe-card--danger with zero inline styles
 *    and no pre-cutover audit/migrate buttons.
 * 2. LegacyCapture::isNativeAuthority() recognizes native, native_bridge, and full_native, blocking
 *    captureLegacyProduct() from overwriting native_bridge aggregates.
 * 3. OperationsPage::handleAction('import_legacy') imports eligible legacy WooCommerce products directly
 *    into native_bridge + wpe_catalog while keeping unsupported types (external) and LegacyOnlyProps (_customs_*)
 *    safely on legacy CPT, and 'outbox' drains native mutations to raw wp_postmeta.
 */

use WPE\Admin\OperationsPage;
use WPE\Admin\SettingsPage;
use WPE\Application\Migration\LegacyCapture;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Support\AuthorityResolver;

global $wpdb;
$prefix = $wpdb->prefix;
$ops = OperationsPage::getInstance();
$capture = LegacyCapture::getInstance();
$bootstrap = PluginBootstrap::instance();

$origUser = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if (!empty($admins)) {
    wp_set_current_user((int)$admins[0]);
}

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, null);
$createdPostIds = [];

$check = static function (bool $cond, string $msg): void {
    if (!$cond) {
        throw new \RuntimeException("SingleModeOperationsTest failed: {$msg}");
    }
};

try {
    echo "=== P034 SingleModeOperationsTest ===\n";

    update_option(PluginBootstrap::OPTION_FLAGS, PluginBootstrap::DEFAULT_FLAGS);
    $bootstrap->loadFlags();
    AuthorityResolver::flushAll();

    // 1. UI rendering checks: Settings Overview, Operations & Health tab, and standalone OperationsPage
    $_GET['page'] = 'wc-product-engine';
    $_GET['tab'] = 'overview';
    ob_start();
    SettingsPage::render();
    $overviewHtml = (string)ob_get_clean();

    $check(!str_contains($overviewHtml, '<h3>Default routing</h3>'), 'Overview tab must not render redundant Default routing card');

    $_GET['tab'] = 'operations';
    ob_start();
    SettingsPage::render();
    $opsTabHtml = (string)ob_get_clean();

    $check(str_contains($opsTabHtml, 'data-action="outbox"'), 'Operations tab must include outbox action button');
    $check(str_contains($opsTabHtml, 'data-action="rebuild"'), 'Operations tab must include rebuild action button');
    $check(str_contains($opsTabHtml, 'data-action="import_legacy"'), 'Operations tab must include import_legacy action button');
    $check(str_contains($opsTabHtml, 'wpe-card--danger') && str_contains($opsTabHtml, 'data-action="rollback"'), 'Operations tab must isolate rollback inside .wpe-card--danger');
    $check(!str_contains($opsTabHtml, 'data-action="audit"'), 'Operations tab must not expose pre-cutover shadow diff audit button');
    $check(!str_contains($opsTabHtml, 'data-action="migrate"'), 'Operations tab must not expose pre-cutover keyset backfill button');
    $check(!preg_match('/\sstyle\s*=/i', $opsTabHtml), 'Operations tab must have zero inline style attributes');

    // The standalone wpe-operations screen was removed; Settings → Operations & Health is the only UI.
    $check(!method_exists($ops, 'renderPage'), 'Standalone OperationsPage screen must be removed (no renderPage)');
    $check(!str_contains((string)file_get_contents(WPE_PLUGIN_DIR . 'src/Admin/OperationsPage.php'), "add_submenu_page"), 'OperationsPage must not register an admin menu page');
    echo "  [OK] Overview and Operations screens render streamlined single-mode controls with zero inline styles.\n";

    // 2. LegacyCapture::isNativeAuthority() protects native_bridge and full_native products
    $guardedProd = new \WC_Product_Simple();
    $guardedProd->set_name('P034 Guarded Native Bridge Product');
    $guardedProd->set_status('publish');
    $guardedProd->set_sku('P034-GUARD-' . wp_generate_password(6, false));
    $guardedProd->set_regular_price('55.00');
    $guardedId = (int)$guardedProd->save();
    $createdPostIds[] = $guardedId;

    $wpdb->update("{$prefix}wpe_object_state", ['authority' => 'native_bridge'], ['object_id' => $guardedId]);
    $check($capture->isNativeAuthority($guardedId) === true, 'LegacyCapture::isNativeAuthority must return true for native_bridge');

    // Poison legacy wp_posts title and ensure captureLegacyProduct refuses to overwrite native_bridge row
    $wpdb->update($wpdb->posts, ['post_title' => 'POISONED LEGACY TITLE'], ['ID' => $guardedId]);
    $capBlocked = $capture->captureLegacyProduct($guardedId, 'test_guard');
    $nativeTitle = (string)$wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $guardedId));
    $check(($capBlocked['status'] ?? '') === 'blocked_native_authority' && $nativeTitle === 'P034 Guarded Native Bridge Product', 'captureLegacyProduct must not overwrite native_bridge product');
    echo "  [OK] LegacyCapture::isNativeAuthority protects native_bridge products against legacy overwrite.\n";

    // 3. One-step import_legacy action imports eligible legacy products and skips unsupported / customs products
    $insertRawLegacy = static function (string $title, string $sku, string $price, string $typeSlug, array $extraMeta = []) use ($wpdb, &$createdPostIds): int {
        $postId = wp_insert_post([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_name'   => sanitize_title($title . '-' . wp_generate_password(4, false)),
        ]);
        $createdPostIds[] = (int)$postId;
        wp_set_object_terms($postId, $typeSlug, 'product_type');
        update_post_meta($postId, '_sku', $sku);
        update_post_meta($postId, '_regular_price', $price);
        update_post_meta($postId, '_price', $price);
        update_post_meta($postId, '_stock_status', 'instock');
        foreach ($extraMeta as $mk => $mv) {
            update_post_meta($postId, $mk, $mv);
        }
        // Ensure it starts purely legacy (remove any native rows if hooks created any)
        $wpdb->delete("{$wpdb->prefix}wpe_products", ['id' => $postId]);
        $wpdb->delete("{$wpdb->prefix}wpe_inventory", ['owner_id' => $postId]);
        $wpdb->delete("{$wpdb->prefix}wpe_object_state", ['object_id' => $postId]);
        AuthorityResolver::evictObject((int)$postId);
        return (int)$postId;
    };

    // Temporarily disable native_writes while inserting raw legacy fixtures so MetaBridge doesn't intercept update_post_meta
    $tempFlags = $bootstrap->getFlags();
    $tempFlags['native_writes_enabled'] = false;
    $tempFlags['cohort_mode'] = 'legacy';
    update_option(PluginBootstrap::OPTION_FLAGS, $tempFlags);
    $bootstrap->loadFlags();

    $skuSimple = 'P034-LEG-SMP-' . wp_generate_password(6, false);
    $legacySimpleId = $insertRawLegacy('P034 Legacy Simple', $skuSimple, '29.00', 'simple');
    $legacyExternalId = $insertRawLegacy('P034 Legacy External', 'P034-LEG-EXT-' . wp_generate_password(6, false), '19.00', 'external');
    $legacyCustomsId = $insertRawLegacy('P034 Legacy Customs', 'P034-LEG-CUS-' . wp_generate_password(6, false), '39.00', 'simple', [
        '_customs_country_of_origin' => 'VN',
    ]);

    // Restore native_bridge flags and run 1-click import_legacy
    update_option(PluginBootstrap::OPTION_FLAGS, PluginBootstrap::DEFAULT_FLAGS);
    $bootstrap->loadFlags();
    AuthorityResolver::flushAll();

    $check(AuthorityResolver::resolveObjectAuthority($legacySimpleId) === 'legacy', 'Unmigrated simple product starts at legacy authority');

    // Run import_legacy with large enough limit to reach newly inserted post IDs
    $totalLegacyCandidates = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$prefix}wpe_object_state os ON os.object_id = p.ID
         WHERE p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft') AND (os.object_id IS NULL OR os.authority = 'legacy')"
    );
    $importRes = $ops->handleAction('import_legacy', ['limit' => max(200, $totalLegacyCandidates + 10)], 0);

    $check(($importRes['status'] ?? '') === 'completed' && ($importRes['imported'] ?? 0) >= 1, 'import_legacy must import at least the eligible simple product');
    $check(($importRes['skipped_unsupported'] ?? 0) >= 2, 'import_legacy must skip external and customs products');
    $check(AuthorityResolver::resolveObjectAuthority($legacySimpleId) === 'native_bridge', 'Imported simple product must now resolve to native_bridge');
    $check(AuthorityResolver::resolveObjectAuthority($legacyExternalId) === 'legacy', 'External product must remain on legacy authority');
    $check(AuthorityResolver::resolveObjectAuthority($legacyCustomsId) === 'legacy', 'Customs product must remain on legacy authority');

    $activeGen = (int)($importRes['generation'] ?? 0);
    $inCatalog = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
        $activeGen,
        $legacySimpleId
    ));
    $check($inCatalog === 1, 'Imported simple product must be projected into active wpe_catalog generation');

    // Verify subsequent native edit + UI outbox action mirrors to raw postmeta
    $reloaded = wc_get_product($legacySimpleId);
    $reloaded->set_regular_price('88.50');
    $reloaded->save();

    $outboxRes = $ops->handleAction('outbox', ['product_ids' => [$legacySimpleId]], 0);
    $rawMirroredPrice = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price' LIMIT 1",
        $legacySimpleId
    ));
    $check(($outboxRes['status'] ?? '') === 'completed' && (float)$rawMirroredPrice === 88.50, 'Outbox action mirrors updated native_bridge price to raw wp_postmeta');
    echo "  [OK] import_legacy promotes eligible Woo products to native_bridge + catalog index and skips unsupported/customs products.\n";

    echo "ALL P034 SingleModeOperationsTest CHECKS PASSED\n";
} finally {
    foreach ($createdPostIds as $pid) {
        if ($pid > 0) {
            $wpdb->delete("{$prefix}wpe_products", ['id' => $pid]);
            $wpdb->delete("{$prefix}wpe_variants", ['product_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_product_terms", ['product_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $pid]);
            $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $pid]);
            wp_delete_post($pid, true);
        }
    }

    if ($origFlags === null) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    $bootstrap->loadFlags();
    AuthorityResolver::flushAll();
    wp_set_current_user($origUser);
}
