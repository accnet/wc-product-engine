<?php
/**
 * Variation shell projection under the native data store: WC_Product_Variation create / update /
 * trash / delete keep the wp_posts shell (post_parent, post_status, title, menu_order) in step.
 *
 * Regression for 2026-11-21: native variations kept the allocator's auto-draft shell with
 * post_parent 0, so core lookups by parent missed them and force deletes left the shell behind.
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/VariationShellProjectionTest.php
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
}


// P048: pin the cohort mode instead of inheriting the shared test DB's (written for the default native_bridge mode).
require_once __DIR__ . '/support/CohortModeFixture.php';
WpeCohortModeFixture::pin('native_bridge');
global $wpdb;
$prefix = $wpdb->prefix;
$fail = static function (string $msg): void {
    throw new \RuntimeException('VariationShellProjection failed: ' . $msg);
};

if (!\WPE\Bootstrap\PluginBootstrap::instance()->isNativeRuntimeActive()) {
    echo "SKIP: native runtime is not active\n";
    return;
}

$shell = static fn(int $id): ?array => $wpdb->get_row($wpdb->prepare(
    "SELECT post_type, post_status, post_parent, post_title, menu_order, post_date_gmt FROM {$wpdb->posts} WHERE ID = %d",
    $id
), ARRAY_A);

$title = 'Variation Shell Probe ' . wp_generate_password(8, false);
$attr = new \WC_Product_Attribute();
$attr->set_name('Slot');
$attr->set_options(['One', 'Two']);
$attr->set_visible(true);
$attr->set_variation(true);
$parent = new \WC_Product_Variable();
$parent->set_name($title);
$parent->set_status('publish');
$parent->set_attributes([$attr]);
$pid = (int)$parent->save();
// Every write below targets ids created here: refuse unless the parent is the native row just made.
if ($pid <= 0 || $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) !== $title) {
    $fail('parent probe was not stored natively');
}

try {
    $ids = [];
    foreach (['One' => 3, 'Two' => 7] as $slot => $order) {
        $v = new \WC_Product_Variation();
        $v->set_parent_id($pid);
        $v->set_attributes(['slot' => $slot]);
        $v->set_regular_price('10.00');
        $v->set_status('publish');
        $v->set_menu_order($order);
        $ids[$slot] = (int)$v->save();
    }

    // 1. Create projects parent, status, title, menu_order and a real date.
    foreach ($ids as $slot => $vid) {
        $s = $shell($vid);
        $expectedOrder = $slot === 'One' ? 3 : 7;
        if (($s['post_type'] ?? '') !== 'product_variation' || (int)$s['post_parent'] !== $pid
            || $s['post_status'] !== 'publish' || $s['post_title'] !== 'Variation for ' . $title
            || (int)$s['menu_order'] !== $expectedOrder || str_starts_with((string)$s['post_date_gmt'], '0000')) {
            $fail("variation {$vid} shell not projected on create: " . wp_json_encode($s));
        }
    }
    $children = get_children(['post_parent' => $pid, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids']);
    if (count($children) !== 2) {
        $fail('core get_children() sees ' . count($children) . ' variations, expected 2');
    }
    echo "  [OK] create projects post_parent, status, title, menu_order and date; get_children() finds both\n";

    // 2. Update projects status and menu_order.
    $v = wc_get_product($ids['One']);
    $v->set_status('private');
    $v->set_menu_order(9);
    $v->save();
    $s = $shell($ids['One']);
    if ($s['post_status'] !== 'private' || (int)$s['menu_order'] !== 9) {
        $fail('update not projected: ' . wp_json_encode($s));
    }
    echo "  [OK] update projects status and menu_order\n";

    // 2b. Trashing / restoring the parent carries its variation shells, as the CPT store does.
    wp_trash_post($pid);
    if (($shell($ids['Two'])['post_status'] ?? '') !== 'trash') {
        $fail('parent trash left variation shell ' . wp_json_encode($shell($ids['Two'])));
    }
    wp_untrash_post($pid);
    $nativeTwo = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}wpe_variants WHERE id = %d", $ids['Two']));
    if (($shell($ids['Two'])['post_status'] ?? '') !== $nativeTwo || $nativeTwo === 'trash') {
        $fail("parent untrash: shell " . wp_json_encode($shell($ids['Two'])) . " native {$nativeTwo}");
    }
    echo "  [OK] parent trash / untrash carries the variation shells\n";

    // 3. Soft delete trashes the shell with the native row.
    $v = wc_get_product($ids['One']);
    $v->delete(false);
    $s = $shell($ids['One']);
    $nativeStatus = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$prefix}wpe_variants WHERE id = %d", $ids['One']));
    if (($s['post_status'] ?? '') !== 'trash' || $nativeStatus !== 'trash') {
        $fail("trash not projected: shell=" . wp_json_encode($s) . " native={$nativeStatus}");
    }
    echo "  [OK] delete(false) trashes the shell and the native row\n";

    // 4. Force delete removes the shell with the native row.
    $v = wc_get_product($ids['Two']);
    $v->delete(true);
    $left = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}wpe_variants WHERE id = %d", $ids['Two']));
    if ($shell($ids['Two']) !== null || $left !== 0) {
        $fail('force delete left shell=' . wp_json_encode($shell($ids['Two'])) . " native_rows={$left}");
    }
    echo "  [OK] delete(true) removes the shell and the native row\n";
} finally {
    wp_delete_post($pid, true);
}

echo "ALL VARIATION SHELL PROJECTION ASSERTIONS PASSED\n";
