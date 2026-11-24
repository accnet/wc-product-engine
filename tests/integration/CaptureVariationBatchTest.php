<?php
/**
 * Integration Test — P046 legacy capture variation sync with batched reads and writes.
 *
 * Run on a test_* copy of the site DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/CaptureVariationBatchTest.php
 *
 *  - captureLegacyProduct() writes the same wpe_variants / wpe_inventory / wpe_sku_registry rows as
 *    the previous per-variation rules, for: missing, empty, "0", duplicated-row and shared SKUs,
 *    empty prices, unmanaged stock with no quantity, out of stock, an existing variant (update path)
 *    next to new ones (insert path).
 *  - A second capture leaves the rows stable.
 *  - Query count grows by at most one per variation.
 */

use WPE\Application\Migration\LegacyCapture;

global $wpdb;

if (strpos((string)DB_NAME, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run outside a test_* database (DB_NAME=" . DB_NAME . ").\n");
    exit(1);
}

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
$pm = $wpdb->postmeta;
$tag = 'p046-' . strtolower(wp_generate_password(6, false, false));
$createdProducts = [];

/** Legacy variable product; $variations = list of [meta_key => value|list of values]. */
$createLegacy = static function (string $title, array $variations) use ($wpdb, $pm, &$createdProducts): array {
    $pid = (int)wp_insert_post([
        'post_title'  => $title,
        'post_name'   => sanitize_title($title),
        'post_status' => 'publish',
        'post_type'   => 'product',
    ]);
    if ($pid <= 1) {
        throw new RuntimeException('Fixture product not created');
    }
    $createdProducts[] = $pid;
    $wpdb->insert($pm, ['post_id' => $pid, 'meta_key' => '_sku', 'meta_value' => strtoupper($title)]);
    $vids = [];
    foreach ($variations as $i => $meta) {
        $vid = (int)wp_insert_post([
            'post_title'  => "Variation {$i} for {$title}",
            'post_status' => 'publish',
            'post_type'   => 'product_variation',
            'post_parent' => $pid,
        ]);
        foreach ($meta as $key => $values) {
            foreach ((array)$values as $value) {
                $wpdb->insert($pm, ['post_id' => $vid, 'meta_key' => $key, 'meta_value' => $value]);
            }
        }
        clean_post_cache($vid);
        $vids[] = $vid;
    }
    clean_post_cache($pid);
    sort($vids);
    return [$pid, $vids];
};

/** Expected rows under the previous per-variation rules. */
$expected = static function (int $pid, array $vids, array $preExisting) use ($wpdb, $pm): array {
    $variants = [];
    $inventory = [];
    $registry = [];
    foreach ($vids as $vid) {
        $map = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$pm} WHERE post_id = %d ORDER BY meta_id ASC", $vid), ARRAY_A) as $r) {
            $map[$r['meta_key']] = $r['meta_value'];
        }
        $sku = $map['_sku'] ?? null;
        $num = static fn(string $k) => isset($map[$k]) && $map[$k] !== '' ? (float)$map[$k] : null;
        $combKey = hash('sha256', "var_{$vid}", true);
        $base = $preExisting[$vid] ?? [
            'product_id'             => $pid,
            'status'                 => 'publish',
            'combination_key'        => $combKey,
            'active_combination_key' => $combKey,
        ];
        $variants[$vid] = [
            'product_id'             => (int)$base['product_id'],
            'status'                 => (string)$base['status'],
            'sku'                    => $sku !== '' ? $sku : null,
            'combination_key'        => bin2hex((string)$base['combination_key']),
            'active_combination_key' => bin2hex((string)$base['active_combination_key']),
            'regular_price'          => $num('_regular_price'),
            'sale_price'             => $num('_sale_price'),
        ];
        $inventory[$vid] = [
            'manage_stock'     => (!empty($map['_manage_stock']) && $map['_manage_stock'] === 'yes') ? 1 : 0,
            'quantity'         => $num('_stock'),
            'backorders'       => 'no',
            'stock_status'     => $map['_stock_status'] ?? 'instock',
            'low_stock_amount' => null,
            'version'          => 1,
        ];
        if (!empty($sku)) {
            // Sequential REPLACE: drop rows that clash on the PK or on uniq_object_id, then insert.
            $norm = mb_strtolower(trim($sku), 'UTF-8');
            foreach ($registry as $k => $row) {
                if ($k === $norm || $row['object_id'] === $vid) {
                    unset($registry[$k]);
                }
            }
            $registry[$norm] = ['object_id' => $vid, 'object_kind' => 'variation', 'original_sku' => $sku];
        }
    }
    ksort($registry, SORT_STRING);
    return [$variants, $inventory, $registry];
};

$actual = static function (array $vids) use ($wpdb, $p): array {
    $in = implode(',', array_map('intval', $vids));
    $variants = [];
    foreach ($wpdb->get_results("SELECT id, product_id, status, sku, HEX(combination_key) ck, HEX(active_combination_key) ack, regular_price, sale_price FROM {$p}wpe_variants WHERE id IN ({$in})", ARRAY_A) as $r) {
        $variants[(int)$r['id']] = [
            'product_id'             => (int)$r['product_id'],
            'status'                 => (string)$r['status'],
            'sku'                    => $r['sku'],
            'combination_key'        => strtolower((string)$r['ck']),
            'active_combination_key' => strtolower((string)$r['ack']),
            'regular_price'          => $r['regular_price'] !== null ? (float)$r['regular_price'] : null,
            'sale_price'             => $r['sale_price'] !== null ? (float)$r['sale_price'] : null,
        ];
    }
    $inventory = [];
    foreach ($wpdb->get_results("SELECT * FROM {$p}wpe_inventory WHERE owner_id IN ({$in})", ARRAY_A) as $r) {
        $inventory[(int)$r['owner_id']] = [
            'manage_stock'     => (int)$r['manage_stock'],
            'quantity'         => $r['quantity'] !== null ? (float)$r['quantity'] : null,
            'backorders'       => (string)$r['backorders'],
            'stock_status'     => (string)$r['stock_status'],
            'low_stock_amount' => $r['low_stock_amount'] !== null ? (float)$r['low_stock_amount'] : null,
            'version'          => (int)$r['version'],
        ];
    }
    $registry = [];
    foreach ($wpdb->get_results("SELECT normalized_sku, object_id, object_kind, original_sku FROM {$p}wpe_sku_registry WHERE object_id IN ({$in})", ARRAY_A) as $r) {
        $registry[(string)$r['normalized_sku']] = ['object_id' => (int)$r['object_id'], 'object_kind' => (string)$r['object_kind'], 'original_sku' => (string)$r['original_sku']];
    }
    ksort($variants);
    ksort($inventory);
    ksort($registry, SORT_STRING);
    return [$variants, $inventory, $registry];
};

$capture = LegacyCapture::getInstance();
$count = 0;
$counter = static function ($sql) use (&$count) {
    $count++;
    return $sql;
};
$run = static function (int $pid) use ($capture, $counter, &$count, $must): int {
    $count = 0;
    add_filter('query', $counter);
    try {
        $res = $capture->captureLegacyProduct($pid, 'p046_test');
    } finally {
        remove_filter('query', $counter);
    }
    $must(($res['status'] ?? '') === 'captured', 'Capture did not run: ' . wp_json_encode($res));
    return $count;
};

try {
    echo "=== P046 CaptureVariationBatchTest ===\n";

    $shared = strtoupper($tag) . '-SHARED';
    [$pid, $vids] = $createLegacy("{$tag}-edge", [
        ['_sku' => strtoupper($tag) . '-A', '_regular_price' => '20', '_sale_price' => '15', '_price' => '15', '_manage_stock' => 'yes', '_stock' => '5', '_stock_status' => 'instock'],
        ['_regular_price' => '21', '_manage_stock' => 'no', '_stock_status' => 'instock'],
        ['_sku' => '', '_regular_price' => '', '_sale_price' => '', '_manage_stock' => 'no', '_stock' => '', '_stock_status' => 'outofstock'],
        ['_sku' => [strtoupper($tag) . '-OLD', '  ' . strtoupper($tag) . '-Dup  '], '_regular_price' => ['10', '12'], '_manage_stock' => 'yes', '_stock' => ['1', '0']],
        ['_sku' => $shared, '_regular_price' => '30', '_manage_stock' => 'yes', '_stock' => '2', '_stock_status' => 'instock'],
        ['_sku' => strtolower($shared), '_regular_price' => '31', '_manage_stock' => 'yes', '_stock' => '3', '_stock_status' => 'onbackorder'],
        ['_sku' => '0', '_regular_price' => '0', '_sale_price' => '0', '_stock_status' => 'instock'],
    ]);

    // One variation already native (update path keeps product_id/status/keys, rewrites sku/prices).
    $existingVid = $vids[1];
    $oldKey = hash('sha256', "p046-existing-{$existingVid}", true);
    $wpdb->insert("{$p}wpe_variants", [
        'id' => $existingVid, 'product_id' => $pid, 'status' => 'private', 'sku' => 'P046-STALE',
        'combination_key' => $oldKey, 'active_combination_key' => $oldKey,
        'regular_price' => 99, 'sale_price' => 98, 'description' => '', 'extra_props' => '{}',
        'created_at' => gmdate('Y-m-d H:i:s.u'), 'updated_at' => gmdate('Y-m-d H:i:s.u'), 'version' => 1,
    ]);
    $preExisting = [$existingVid => ['product_id' => $pid, 'status' => 'private', 'combination_key' => $oldKey, 'active_combination_key' => $oldKey]];

    $run($pid);
    $exp = $expected($pid, $vids, $preExisting);
    $got = $actual($vids);
    foreach (['wpe_variants', 'wpe_inventory', 'wpe_sku_registry'] as $i => $table) {
        $must($got[$i] == $exp[$i], "{$table} rows differ from the per-variation rules:\nexpected " . wp_json_encode($exp[$i]) . "\nactual   " . wp_json_encode($got[$i]));
    }
    $must(count($got[2]) === 3, 'Expected 3 variation SKU rows (A, Dup, shared->later), got ' . wp_json_encode(array_keys($got[2])));
    $ok('first capture: variants, inventory and SKU registry match the per-variation rules (7 edge-case variations, 1 update + 6 inserts)');

    $run($pid);
    $again = $actual($vids);
    $must($again == $got, 'Second capture changed the rows: ' . wp_json_encode([$got, $again]));
    $ok('second capture (all update paths) keeps the rows stable');

    // Query growth per variation.
    $plain = static fn(int $n, string $s) => array_map(static fn(int $i) => ['_sku' => strtoupper("{$s}-{$i}"), '_regular_price' => (string)(10 + $i), '_manage_stock' => 'yes', '_stock' => '4', '_stock_status' => 'instock'], range(1, $n));
    [$small] = $createLegacy("{$tag}-small", $plain(4, "{$tag}-s"));
    [$large] = $createLegacy("{$tag}-large", $plain(16, "{$tag}-l"));
    $run($small);
    $run($large);
    $qSmall = $run($small);
    $qLarge = $run($large);
    $perVariation = ($qLarge - $qSmall) / 12;
    $must($perVariation <= 1.0, "Capture grows by {$perVariation} queries per variation ({$qSmall} for 4, {$qLarge} for 16); expected at most 1");
    $ok("capture query count grows by {$perVariation} per variation ({$qSmall} queries for 4 variations, {$qLarge} for 16)");

    echo "ALL P046 CAPTURE VARIATION BATCH ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    foreach ($createdProducts as $pid) {
        if ($pid <= 1 || strpos((string)get_the_title($pid), $tag) !== 0) {
            continue;
        }
        $ids = array_merge([$pid], array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $pid))));
        foreach ($ids as $id) {
            foreach (['wpe_products' => 'id', 'wpe_variants' => 'id', 'wpe_inventory' => 'owner_id', 'wpe_sku_registry' => 'object_id', 'wpe_object_state' => 'object_id', 'wpe_audit' => 'target_id', 'wpe_product_terms' => 'product_id'] as $t => $col) {
                $wpdb->delete("{$p}{$t}", [$col => $id]);
            }
            wp_delete_post($id, true);
        }
    }
    $capture->clearDirtyAggregates();
}
