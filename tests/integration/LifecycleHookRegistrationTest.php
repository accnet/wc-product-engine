<?php
/**
 * Lifecycle hooks are wired by the real bootstrap: core trash / untrash / delete of a native
 * product shell keeps wpe_products, variation shells and the catalog index in step.
 *
 * Regression for 2026-11-21: ProductLifecycle::register() was never called at runtime (only the
 * T15-T20 tests called it), so wp_trash_post() left native rows "publish" and still indexed.
 *
 * Deliberately does NOT call ProductLifecycle::register().
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/LifecycleHookRegistrationTest.php
 */

use WPE\Application\Catalog\CatalogProjector;
use WPE\Infrastructure\Database\ProductRepository;

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
}

global $wpdb;
$prefix = $wpdb->prefix;
$fail = static function (string $msg): void {
    throw new \RuntimeException('LifecycleHookRegistration failed: ' . $msg);
};

if (!\WPE\Bootstrap\PluginBootstrap::instance()->isNativeRuntimeActive()) {
    echo "SKIP: native runtime is not active (hooks are only wired under native cohort modes)\n";
    return;
}

// 1. Hooks registered by bootstrap.
foreach (['wp_trash_post', 'untrashed_post', 'before_delete_post'] as $hook) {
    $found = false;
    foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $cb) {
            $fn = $cb['function'];
            if (is_array($fn) && is_object($fn[0]) && $fn[0] instanceof \WPE\Compatibility\WooDataStore\ProductLifecycle) {
                $found = true;
            }
        }
    }
    if (!$found) {
        $fail("ProductLifecycle is not hooked on {$hook}");
    }
}
echo "  [OK] bootstrap hooks ProductLifecycle on wp_trash_post, untrashed_post, before_delete_post\n";

$repo = new ProductRepository($wpdb);
$projector = new CatalogProjector($wpdb);
$gen = $projector->getActiveGeneration();

$created = [];
$makeProduct = static function (string $type) use ($repo, $wpdb, $prefix, $fail, &$created): int {
    $title = 'Lifecycle Hook Probe ' . $type . ' ' . wp_generate_password(8, false);
    $data = [
        'title'         => $title,
        'slug'          => sanitize_title($title),
        'type'          => $type,
        'status'        => 'publish',
        'regular_price' => '12.00',
    ];
    if ($type === 'variable') {
        unset($data['regular_price']);
        $data['variations'] = [
            ['sku' => 'LHP-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '12.00',
             'combination_key' => hash('sha256', $title . '1'), 'attributes' => ['slot' => '1'], 'position' => 1],
            ['sku' => 'LHP-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '14.00',
             'combination_key' => hash('sha256', $title . '2'), 'attributes' => ['slot' => '2'], 'position' => 2],
        ];
    }
    $res = $repo->create($data);
    $pid = is_array($res) ? (int)($res['id'] ?? 0) : 0;
    // Every later write targets $pid: refuse unless it is the row just created.
    if ($pid <= 0 || $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) !== $title) {
        $fail('could not create probe product: ' . wp_json_encode($res));
    }
    $created[] = $pid;
    $wpdb->update($wpdb->posts, ['post_status' => 'publish', 'post_title' => $title], ['ID' => $pid, 'post_type' => 'product']);
    clean_post_cache($pid);
    return $pid;
};
$native = static fn(int $pid): ?array => $wpdb->get_row($wpdb->prepare(
    "SELECT status, deleted_at FROM {$prefix}wpe_products WHERE id = %d",
    $pid
), ARRAY_A);
$indexedStatus = static fn(int $pid): ?string => $wpdb->get_var($wpdb->prepare(
    "SELECT status FROM {$prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
    $gen,
    $pid
));

try {
    // 2. Trash -> native trashed and dropped from the index.
    $pid = $makeProduct('simple');
    $projector->projectProduct($pid, $gen);
    if ($indexedStatus($pid) !== 'publish') {
        $fail('probe not indexed before trash');
    }
    if (!wp_trash_post($pid)) {
        $fail('wp_trash_post failed');
    }
    $row = $native($pid);
    if (($row['status'] ?? '') !== 'trash' || empty($row['deleted_at'])) {
        $fail('native row not trashed: ' . wp_json_encode($row));
    }
    if ($indexedStatus($pid) !== null) {
        $fail('trashed product still in catalog index');
    }
    echo "  [OK] wp_trash_post trashes the native product and removes it from the index\n";

    // 3. Untrash -> native follows the status core restored (draft by default since WP 5.6).
    wp_untrash_post($pid);
    $shellStatus = (string)get_post_status($pid);
    $row = $native($pid);
    if (($row['status'] ?? '') !== $shellStatus || $row['deleted_at'] !== null) {
        $fail("native status " . wp_json_encode($row) . " does not follow restored shell status {$shellStatus}");
    }
    if ($indexedStatus($pid) !== $shellStatus) {
        $fail('restored product not re-indexed with status ' . $shellStatus);
    }
    echo "  [OK] wp_untrash_post restores native status '{$shellStatus}' and re-indexes it\n";

    // 4. Force delete of a variable product -> native rows, variation shells and index rows gone.
    // Built through the WC API, as wp-admin does, so variation shells exist.
    $vTitle = 'Lifecycle Hook Probe variable ' . wp_generate_password(8, false);
    $attr = new \WC_Product_Attribute();
    $attr->set_name('Slot');
    $attr->set_options(['One', 'Two']);
    $attr->set_visible(true);
    $attr->set_variation(true);
    $variable = new \WC_Product_Variable();
    $variable->set_name($vTitle);
    $variable->set_status('publish');
    $variable->set_attributes([$attr]);
    $vid = (int)$variable->save();
    if ($vid <= 0 || $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $vid)) !== $vTitle) {
        $fail('variable probe was not stored natively');
    }
    $created[] = $vid;
    foreach (['One' => '12.00', 'Two' => '14.00'] as $slot => $price) {
        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($vid);
        $variation->set_attributes(['slot' => $slot]);
        $variation->set_regular_price($price);
        $variation->set_status('publish');
        $variation->save();
    }
    $projector->projectProduct($vid, $gen);
    $variationIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation'
           AND (post_parent = %d OR ID IN (SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d))",
        $vid,
        $vid
    )));
    if (count($variationIds) < 2) {
        $fail('variable probe has ' . count($variationIds) . ' variation shells, expected 2');
    }
    wp_delete_post($vid, true);
    if ($native($vid) !== null) {
        $fail('native product row survived wp_delete_post');
    }
    $leftVariants = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}wpe_variants WHERE product_id = %d", $vid));
    $leftShells = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (" . implode(',', array_merge([$vid], $variationIds)) . ")
            OR (post_parent = %d AND post_type = 'product_variation')",
        $vid
    ));
    if ($leftVariants !== 0 || $leftShells !== 0 || $indexedStatus($vid) !== null) {
        $fail("delete left variants={$leftVariants} shells={$leftShells} indexed=" . var_export($indexedStatus($vid), true));
    }
    echo '  [OK] wp_delete_post removes native rows, ' . count($variationIds) . " variation shells and the index row\n";

    // 5. Non-product posts are untouched.
    $postId = (int)wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Lifecycle Hook Probe post']);
    if ($postId <= 0) {
        $fail('could not create probe post');
    }
    wp_trash_post($postId);
    if (get_post_status($postId) !== 'trash') {
        $fail('ordinary post trash broken');
    }
    wp_delete_post($postId, true);
    echo "  [OK] ordinary posts trash and delete normally\n";
} finally {
    foreach ($created as $id) {
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$prefix}wpe_products WHERE id = %d", $id))) {
            wp_delete_post($id, true);
        }
    }
}

echo "ALL LIFECYCLE HOOK REGISTRATION ASSERTIONS PASSED\n";
