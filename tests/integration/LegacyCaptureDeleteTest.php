<?php
/**
 * Integration Test — P054: legacy capture during permanent deletes.
 *
 * Run on a test_* copy of the site DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/LegacyCaptureDeleteTest.php
 *
 *  A. Deleting a captured legacy product writes no native product rows during the delete and leaves
 *     no live native row: ProductLifecycle purges it when the native runtime is active, otherwise the
 *     capture tombstone marks it.
 *  B. Deleting a product whose native rows were removed first (a purge, a test cleanup) does not
 *     recreate them.
 *  C. Deleting one variation of a legacy variable product captures the parent exactly once, after the
 *     variation is gone; the parent keeps its other variation.
 */

use WPE\Application\Migration\LegacyCapture;

global $wpdb;

if (strpos((string)DB_NAME, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run outside a test_* database (DB_NAME=" . DB_NAME . ").\n");
    exit(1);
}

// Capture runs for legacy-authority products (pre-cutover modes), like T37.
require_once __DIR__ . '/support/CohortModeFixture.php';
WpeCohortModeFixture::pin('cohort_canary');

// zend.assertions is -1 for WP-CLI on this host, so checks throw instead of using assert().
$must = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$checks = 0;
$ok = static function (string $msg) use (&$checks): void {
    $checks++;
    echo "  [OK] {$msg}\n";
};

$p = $wpdb->prefix;
$capture = LegacyCapture::getInstance();
$capture->registerHooks();
wp_set_current_user(1);

$created = [];
$sqlLog = [];
$logger = static function ($sql) use (&$sqlLog) {
    $sqlLog[] = (string)$sql;
    return $sql;
};
$productWrites = static function (array $log) use ($p): int {
    return count(array_filter($log, static fn(string $q): bool => preg_match('/^\s*(INSERT|REPLACE|UPDATE)\b[^;]*`?' . preg_quote($p, '/') . 'wpe_products`?\s/i', $q) === 1));
};
$nativeRow = static function (int $id) use ($wpdb, $p) {
    return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}wpe_products WHERE id = %d", $id));
};
$legacySimple = static function (string $title) use (&$created): int {
    $id = (int)wp_insert_post(['post_title' => $title, 'post_status' => 'publish', 'post_type' => 'product']);
    if ($id <= 1) {
        throw new RuntimeException('Fixture post not created');
    }
    $created[] = $id;
    update_post_meta($id, '_sku', 'P054-' . $id);
    update_post_meta($id, '_regular_price', '12.00');
    update_post_meta($id, '_price', '12.00');
    update_post_meta($id, '_manage_stock', 'yes');
    update_post_meta($id, '_stock', '4');
    update_post_meta($id, '_stock_status', 'instock');
    return $id;
};

try {
    echo "=== P054 LegacyCaptureDeleteTest ===\n";

    /* A. captured legacy product, deleted */
    $a = $legacySimple('P054 Delete A');
    $res = $capture->captureLegacyProduct($a, 'initial_sync');
    $must(($res['status'] ?? '') === 'captured', 'Fixture A was not captured: ' . wp_json_encode($res));
    $sqlLog = [];
    add_filter('query', $logger);
    wp_delete_post($a, true);
    remove_filter('query', $logger);
    $writes = $productWrites($sqlLog);
    $state = $wpdb->get_row($wpdb->prepare("SELECT tombstone FROM {$p}wpe_object_state WHERE object_id = %d", $a), ARRAY_A);
    $must($writes === 0, "Deleting a captured legacy product wrote wpe_products {$writes} times");
    $must($nativeRow($a) === null || (!empty($state) && (int)$state['tombstone'] === 1), 'Deleted product left a live native row: ' . wp_json_encode($state));
    $ok('deleting a captured legacy product writes no wpe_products rows during the delete and leaves no live native row (' . ($nativeRow($a) === null ? 'purged' : 'tombstoned') . ')');

    /* B. native rows removed first, then the post */
    $b = $legacySimple('P054 Delete B');
    $capture->captureLegacyProduct($b, 'initial_sync');
    $must($nativeRow($b) !== null, 'Fixture B was not captured');
    (new \WPE\Infrastructure\Database\ProductPurger($wpdb))->purge([$b]);
    $must($nativeRow($b) === null, 'Purge did not remove fixture B');
    wp_delete_post($b, true);
    $must($nativeRow($b) === null, 'Deleting the post recreated the purged native row of fixture B');
    $ok('deleting a post whose native rows were purged first does not recreate them');

    /* C. one variation deleted, parent stays */
    $c = (int)wp_insert_post(['post_title' => 'P054 Delete C', 'post_status' => 'publish', 'post_type' => 'product']);
    $created[] = $c;
    $vars = [];
    foreach (['10.00', '11.00'] as $i => $price) {
        $v = (int)wp_insert_post(['post_title' => "P054 Delete C var {$i}", 'post_status' => 'publish', 'post_type' => 'product_variation', 'post_parent' => $c]);
        update_post_meta($v, '_sku', "P054-C-{$c}-{$i}");
        update_post_meta($v, '_regular_price', $price);
        update_post_meta($v, '_price', $price);
        $vars[] = $v;
    }
    $res = $capture->captureLegacyProduct($c, 'initial_sync');
    $must(($res['status'] ?? '') === 'captured', 'Fixture C was not captured: ' . wp_json_encode($res));
    $captureReads = static fn(array $log): int => count(array_filter($log, static fn(string $q): bool => preg_match('/^SELECT \* FROM `?' . preg_quote($GLOBALS['wpdb']->posts, '/') . '`? WHERE ID = ' . $c . '\b/', trim($q)) === 1));
    $sqlLog = [];
    add_filter('query', $logger);
    wp_delete_post($vars[0], true);
    remove_filter('query', $logger);
    $reads = $captureReads($sqlLog);
    $must($reads === 1, "Deleting one variation should capture the parent once, captured {$reads} times");
    $must($nativeRow($c) !== null, 'Parent native row missing after deleting one variation');
    $must(get_post($vars[1]) instanceof WP_Post && get_post_type($c) === 'product', 'Parent or remaining variation lost');
    $ok('deleting one variation captures its parent exactly once, after the variation is gone');

    echo "ALL P054 LEGACY CAPTURE DELETE ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    remove_filter('query', $logger);
    $purger = new \WPE\Infrastructure\Database\ProductPurger($wpdb);
    foreach ($created as $id) {
        $children = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $id)));
        $purger->purge([$id]);
        foreach (array_merge($children, [$id]) as $postId) {
            if (get_post($postId)) {
                wp_delete_post($postId, true);
            }
            $wpdb->delete("{$p}wpe_object_state", ['object_id' => $postId]);
            $wpdb->delete("{$p}wpe_sku_registry", ['object_id' => $postId]);
            $wpdb->delete("{$p}wpe_inventory", ['owner_id' => $postId]);
            $wpdb->delete("{$p}wpe_audit", ['target_id' => $postId]);
        }
        $purger->purge([$id]);
    }
    $capture->clearDirtyAggregates();
}
