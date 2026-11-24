<?php
/**
 * Detect rows a test leaves behind (P054).
 *
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/support/leak-check.php snapshot <file>
 *   ... run the test ...
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/support/leak-check.php compare <file>
 *
 * Records the IDs of native products, native variants and product/variation posts. `compare` exits 1
 * and lists the new IDs when any appeared since the snapshot. Test databases (test_*) only.
 */

if (strpos((string)DB_NAME, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run outside a test_* database (DB_NAME=" . DB_NAME . ").\n");
    exit(1);
}

global $wpdb;
$action = (string)($args[0] ?? '');
$file = (string)($args[1] ?? '');
if (!in_array($action, ['snapshot', 'compare'], true) || $file === '') {
    fwrite(STDERR, "Usage: leak-check.php snapshot|compare <file>\n");
    exit(1);
}

$read = static function () use ($wpdb): array {
    $p = $wpdb->prefix;
    return [
        'wpe_products' => array_map('intval', $wpdb->get_col("SELECT id FROM {$p}wpe_products")),
        'wpe_variants' => array_map('intval', $wpdb->get_col("SELECT id FROM {$p}wpe_variants")),
        'product_posts' => array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')")),
    ];
};

if ($action === 'snapshot') {
    $state = $read();
    file_put_contents($file, json_encode(['db' => DB_NAME, 'at' => gmdate('c'), 'ids' => $state]));
    echo 'snapshot: ' . json_encode(array_map('count', $state)) . "\n";
    exit(0);
}

$before = json_decode((string)@file_get_contents($file), true);
if (!is_array($before) || ($before['db'] ?? '') !== DB_NAME) {
    fwrite(STDERR, "No snapshot for " . DB_NAME . " in {$file}\n");
    exit(1);
}
$now = $read();
$leaks = [];
foreach ($now as $kind => $ids) {
    $new = array_values(array_diff($ids, $before['ids'][$kind] ?? []));
    if ($new !== []) {
        $leaks[$kind] = $new;
    }
}
if ($leaks !== []) {
    fwrite(STDERR, 'leaked rows: ' . json_encode($leaks) . "\n");
    exit(1);
}
echo "no leaked rows (" . json_encode(array_map('count', $now)) . ")\n";
