<?php
/**
 * Integration Test — P040 storefront query budget.
 *
 * Run on a test_* copy of the site DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/StorefrontQueryBudgetTest.php
 *
 * P040.1:
 *  - SelectorAdapter inventory signatures read wpe_inventory by primary key (no full scan) and give
 *    the same signature as the previous `owner_id = %d OR owner_id IN (subquery)` form.
 *  - Both selector cache keys still change when parent or variant inventory changes.
 *  - getProductCacheKey() runs its fingerprint query once per request; invalidateCache() resets it.
 *  - MetaBridge skips the authority lookup for cached non-product posts and still serves native meta.
 *  - AuthorityResolver probes are kept per request only when the request memo is on, and are evicted
 *    by clean_post_cache.
 */

use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Support\AuthorityResolver;

global $wpdb;
$prefix = $wpdb->prefix;

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

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$createdProductIds = [];
$createdVariantIds = [];
$createdAttachmentIds = [];
$checks = 0;

$sqlLog = [];
$queryFilter = static function ($sql) use (&$sqlLog) {
    $sqlLog[] = (string)$sql;
    return $sql;
};
$countMatching = static function (array $log, string $needle): int {
    return count(array_filter($log, static fn(string $q): bool => strpos($q, $needle) !== false));
};
$callPrivate = static function (object $obj, string $method, ...$args) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
};
$ok = static function (string $msg) use (&$checks): void {
    $checks++;
    echo "  [OK] {$msg}\n";
};

try {
    echo "=== P040 StorefrontQueryBudgetTest ===\n";

    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(PluginBootstrap::DEFAULT_FLAGS, [
        'native_writes_enabled' => true,
        'cohort_mode'           => 'native_bridge',
    ]));
    PluginBootstrap::instance()->loadFlags();
    add_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);

    $repo = new ProductRepository($wpdb);
    $vars = [];
    for ($i = 1; $i <= 6; $i++) {
        $vars[] = [
            'sku'             => sprintf('P040-V-%d-%d', $i, wp_rand(10000, 99999)),
            'status'          => 'publish',
            'combination_key' => hash('sha256', "p040-v{$i}-" . wp_rand()),
            'attributes'      => ['size' => 'S' . $i],
            'regular_price'   => (string)(30 + $i),
            'manage_stock'    => 1,
            'stock_quantity'  => '10',
            'position'        => $i,
        ];
    }
    $created = $repo->create([
        'title'      => 'P040 Budget Variable',
        'slug'       => 'p040-budget-var-' . wp_rand(1000, 9999),
        'type'       => 'variable',
        'status'     => 'publish',
        'variations' => $vars,
    ]);
    $pid = (int)($created['id'] ?? 0);
    $must($pid > 1, 'Fixture product was not created');
    $createdProductIds[] = $pid;
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $pid]);
    wp_set_object_terms($pid, 'variable', 'product_type');
    clean_post_cache($pid);

    $vIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d ORDER BY id ASC",
        $pid
    )));
    $must(count($vIds) === 6, 'Expected 6 fixture variants, got ' . count($vIds));
    $createdVariantIds = $vIds;

    // Parent inventory row so the signature covers both the product and its variants.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$prefix}wpe_inventory (owner_id, manage_stock, quantity, backorders, stock_status, version, updated_at)
         VALUES (%d, 0, NULL, 'no', 'instock', 1, %s)",
        $pid,
        gmdate('Y-m-d H:i:s.u')
    ));

    $inventoryRows = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpe_inventory");
    echo "  wpe_inventory rows: {$inventoryRows}\n";

    /* ---------------------------------------------------------------- */
    /* 1. Inventory signatures: primary-key access, same content        */
    /* ---------------------------------------------------------------- */
    $adapter = new SelectorAdapter($wpdb);

    $legacyInvSig = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT GROUP_CONCAT(CONCAT_WS(':', i.owner_id, i.manage_stock, COALESCE(i.quantity, 'null'), i.stock_status, i.backorders) ORDER BY i.owner_id SEPARATOR '|')
         FROM {$prefix}wpe_inventory i
         WHERE i.owner_id = %d OR i.owner_id IN (SELECT v2.id FROM {$prefix}wpe_variants v2 WHERE v2.product_id = %d)",
        $pid,
        $pid
    ));
    $must(substr_count($legacyInvSig, '|') === 6, "Legacy signature should list 7 inventory rows: {$legacyInvSig}");

    foreach (['computeLowestPriceCacheKey' => 'lowest', 'computeProductCacheKey' => 'config'] as $method => $label) {
        $sqlLog = [];
        add_filter('query', $queryFilter);
        $callPrivate($adapter, $method, $pid);
        remove_filter('query', $queryFilter);

        $sigQueries = array_values(array_filter($sqlLog, static fn(string $q): bool => strpos($q, 'AS inv_sig') !== false));
        $must(count($sigQueries) === 1, "{$label}: expected one fingerprint query, got " . count($sigQueries));
        $sigSql = $sigQueries[0];

        $plan = $wpdb->get_results('EXPLAIN ' . $sigSql, ARRAY_A) ?: [];
        foreach ($plan as $row) {
            $must(!($row['table'] === 'i' && strtoupper((string)$row['type']) === 'ALL'), "{$label}: wpe_inventory is scanned (type=ALL): " . wp_json_encode($row));
        }
        $invPlan = array_values(array_filter($plan, static fn(array $r): bool => $r['table'] === 'i'));
        $must($invPlan !== [] && in_array(strtoupper((string)$invPlan[0]['type']), ['EQ_REF', 'REF', 'CONST'], true), "{$label}: wpe_inventory should be read by primary key: " . wp_json_encode($invPlan));

        $row = $wpdb->get_row($sigSql, ARRAY_A);
        $must((string)$row['inv_sig'] === $legacyInvSig, "{$label}: signature changed.\nold: {$legacyInvSig}\nnew: {$row['inv_sig']}");
        $ok("{$label} key reads wpe_inventory by primary key ({$invPlan[0]['type']}) and keeps the previous signature");
    }

    /* ---------------------------------------------------------------- */
    /* 2. Keys still follow parent and variant inventory changes        */
    /* ---------------------------------------------------------------- */
    foreach (['computeLowestPriceCacheKey' => 'lowest', 'computeProductCacheKey' => 'config'] as $method => $label) {
        $k0 = $callPrivate($adapter, $method, $pid);
        $wpdb->update("{$prefix}wpe_inventory", ['quantity' => '3.000000'], ['owner_id' => $vIds[2]]);
        $k1 = $callPrivate($adapter, $method, $pid);
        $wpdb->update("{$prefix}wpe_inventory", ['stock_status' => 'outofstock'], ['owner_id' => $pid]);
        $k2 = $callPrivate($adapter, $method, $pid);
        $wpdb->update("{$prefix}wpe_inventory", ['quantity' => '10.000000'], ['owner_id' => $vIds[2]]);
        $wpdb->update("{$prefix}wpe_inventory", ['stock_status' => 'instock'], ['owner_id' => $pid]);
        $k3 = $callPrivate($adapter, $method, $pid);
        $must($k0 !== $k1, "{$label}: key ignores variant inventory change");
        $must($k1 !== $k2, "{$label}: key ignores parent inventory change");
        $must($k3 === $k0, "{$label}: key is not deterministic after restoring inventory");
        $ok("{$label} key changes with variant and parent inventory and is deterministic");
    }

    /* ---------------------------------------------------------------- */
    /* 3. getProductCacheKey runs once per request                      */
    /* ---------------------------------------------------------------- */
    $adapter = new SelectorAdapter($wpdb);
    $sqlLog = [];
    add_filter('query', $queryFilter);
    $cfg1 = $adapter->getSelectorConfig($pid);
    $adapter->getSelectorConfig($pid);
    $callPrivate($adapter, 'getProductCacheKey', $pid);
    $firstRound = $countMatching($sqlLog, 'AS opt_sig');
    $adapter->invalidateCache($pid);
    $cfg2 = $adapter->getSelectorConfig($pid);
    $afterInvalidate = $countMatching($sqlLog, 'AS opt_sig');
    remove_filter('query', $queryFilter);
    $must($firstRound === 1, "Expected 1 product fingerprint query for 3 lookups, got {$firstRound}");
    $must($afterInvalidate === 2, "Expected a fresh fingerprint after invalidateCache(), got {$afterInvalidate} total");
    $must($cfg1 === $cfg2 && (int)($cfg1['productId'] ?? 0) === $pid, 'Selector config changed after invalidateCache() or lost the product: ' . wp_json_encode($cfg1));
    $ok('product cache key fingerprint runs once per request and again after invalidateCache()');

    /* ---------------------------------------------------------------- */
    /* 4. MetaBridge: non-product posts skip the authority lookup        */
    /* ---------------------------------------------------------------- */
    $attId = (int)wp_insert_attachment([
        'post_title'     => 'P040 attachment',
        'post_mime_type' => 'image/jpeg',
        'post_status'    => 'inherit',
    ], 'p040/does-not-exist.jpg');
    $must($attId > 0, 'Attachment fixture not created');
    $createdAttachmentIds[] = $attId;
    clean_post_cache($attId);
    get_post($attId); // as a template would before reading meta
    wp_cache_delete($attId, 'post_meta');

    $sqlLog = [];
    add_filter('query', $queryFilter);
    $file = get_post_meta($attId, '_wp_attached_file', true);
    get_post_meta($attId, '_wp_attachment_metadata', true);
    remove_filter('query', $queryFilter);
    $wpeQueries = $countMatching($sqlLog, $prefix . 'wpe_');
    $must($file === 'p040/does-not-exist.jpg', "Attachment meta not read from postmeta: " . var_export($file, true));
    $must($wpeQueries === 0, "Cached attachment still queried engine tables {$wpeQueries} times: " . implode("\n", $sqlLog));
    $ok('cached attachment meta reads issue no engine queries and still return postmeta');

    $vid = $vIds[0];
    clean_post_cache($vid);
    get_post($vid);
    $nativeRegular = (string)$wpdb->get_var($wpdb->prepare("SELECT regular_price FROM {$prefix}wpe_variants WHERE id = %d", $vid));
    $metaRegular = (string)get_post_meta($vid, '_regular_price', true);
    $must($metaRegular !== '' && (float)$metaRegular === (float)$nativeRegular, "Variation meta not served natively: meta={$metaRegular} native={$nativeRegular}");
    $ok("native variation meta still served from wpe_variants (_regular_price {$metaRegular})");

    /* ---------------------------------------------------------------- */
    /* 5. Probe request memo                                            */
    /* ---------------------------------------------------------------- */
    $must(AuthorityResolver::isRequestMemoEnabled() === false, 'Request memo must be off under WP-CLI by default');
    $ok('request memo is off by default under WP-CLI');

    $probeCount = static fn(array $log): int => count(array_filter($log, static fn(string $q): bool => strpos($q, 'AS native_var_parent') !== false));

    AuthorityResolver::evictObject($vid);
    $sqlLog = [];
    add_filter('query', $queryFilter);
    AuthorityResolver::probeObject($vid);
    AuthorityResolver::probeObject($vid);
    remove_filter('query', $queryFilter);
    $must($probeCount($sqlLog) === 2, 'Without the memo each probe must query, got ' . $probeCount($sqlLog));
    $ok('memo off: every probe reads wpe_object_state fresh');

    add_filter('wpe_authority_request_memo', '__return_true');
    AuthorityResolver::evictObject($vid);
    $sqlLog = [];
    add_filter('query', $queryFilter);
    $p1 = AuthorityResolver::probeObject($vid);
    $p2 = AuthorityResolver::probeObject($vid);
    $native = AuthorityResolver::isNativeObject($vid);
    $afterHits = $probeCount($sqlLog);
    clean_post_cache($vid);
    AuthorityResolver::probeObject($vid);
    $afterEvict = $probeCount($sqlLog);
    remove_filter('query', $queryFilter);
    remove_filter('wpe_authority_request_memo', '__return_true');
    $must($p1 === $p2 && $p1['native_var_parent'] === $pid, 'Memoized probe differs or lost the parent: ' . wp_json_encode($p1));
    $must($native === true, 'Fixture variation should resolve as native');
    $must($afterHits === 1, "Memo on: expected 1 probe query, got {$afterHits}");
    $must($afterEvict === 2, "clean_post_cache must evict the memoized probe, got {$afterEvict} queries");
    $ok('memo on: one probe query per object, evicted by clean_post_cache');

    /* ---------------------------------------------------------------- */
    /* 6. P040.2: variation family priming                              */
    /* ---------------------------------------------------------------- */
    $customKey = '_p040_note';
    $mediaRepo = new \WPE\Infrastructure\Database\MediaRepository($wpdb);
    $mediaRepo->addMeta($vIds[1], 'variation', $customKey, 'first');

    $snapshot = static function (int $id): array {
        $v = new WC_Product_Variation($id);
        $data = $v->get_data();
        $meta = [];
        foreach ($v->get_meta_data() as $m) {
            $meta[] = [$m->key, $m->value];
        }
        unset($data['meta_data']);
        foreach ($data as $k => $val) {
            if ($val instanceof WC_DateTime) {
                $data[$k] = $val->getTimestamp();
            }
        }
        $data['downloads'] = array_keys($v->get_downloads());
        return ['data' => $data, 'meta' => $meta, 'attributes' => $v->get_attributes(), 'parent' => $v->get_parent_data()];
    };

    AuthorityResolver::flushAll();
    $plain = [];
    foreach ($vIds as $id) {
        $plain[$id] = $snapshot($id);
    }

    add_filter('wpe_authority_request_memo', '__return_true');
    AuthorityResolver::flushAll();
    $sqlLog = [];
    add_filter('query', $queryFilter);
    $primed = [];
    $perVariation = [];
    foreach ($vIds as $i => $id) {
        $before = count($sqlLog);
        $primed[$id] = $snapshot($id);
        $perVariation[] = count($sqlLog) - $before;
    }
    remove_filter('query', $queryFilter);
    $variantRowReads = $countMatching($sqlLog, $prefix . 'wpe_variants WHERE id =');
    $total = count($sqlLog);
    $later = array_sum(array_slice($perVariation, 1));
    foreach ($vIds as $id) {
        $must($primed[$id] == $plain[$id], "Primed variation {$id} differs from a direct read:\nprimed " . wp_json_encode($primed[$id]) . "\nplain  " . wp_json_encode($plain[$id]));
    }
    $ok('primed variations read the same data, meta, attributes and parent data as direct reads');
    $must($variantRowReads === 0, "Primed reads still query wpe_variants by id {$variantRowReads} times");
    $must($later <= 2 * (count($vIds) - 1), "Siblings after the first should need (almost) no queries, got {$later}: " . implode(',', $perVariation) . "\n" . implode("\n", $sqlLog));
    $ok("6 variations read in {$total} queries (" . implode(',', $perVariation) . '), no per-variation row reads');

    // Writes followed by eviction are visible within the same request.
    $wpdb->update("{$prefix}wpe_variants", ['regular_price' => '77.000000'], ['id' => $vIds[3]]);
    AuthorityResolver::evictObject($pid);
    $afterParentEvict = new WC_Product_Variation($vIds[3]);
    $must((float)$afterParentEvict->get_regular_price() === 77.0, 'Evicting the parent must drop its variation bundles, got ' . $afterParentEvict->get_regular_price());

    $wpdb->update("{$prefix}wpe_products", ['title' => 'P040 Budget Variable Renamed'], ['id' => $pid]);
    AuthorityResolver::evictObject($pid);
    $renamed = new WC_Product_Variation($vIds[4]);
    $must(($renamed->get_parent_data()['title'] ?? '') === 'P040 Budget Variable Renamed', 'Evicting the parent must drop the cached parent data');
    $wpdb->update("{$prefix}wpe_products", ['title' => 'P040 Budget Variable'], ['id' => $pid]);
    $ok('evicting the parent drops the family: new variant price and parent title are read');

    $metaRows = $mediaRepo->getMeta($vIds[1], $customKey);
    $must($metaRows === ['first'], 'Memoized meta read: ' . wp_json_encode($metaRows));
    $metaId = (int)$wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$prefix}wpe_meta WHERE object_id = %d AND meta_key = %s", $vIds[1], $customKey));
    $mediaRepo->updateMeta($metaId, 'second');
    $must($mediaRepo->getMeta($vIds[1], $customKey, true) === 'second', 'updateMeta() must drop the memoized meta map');
    $must((new WC_Product_Variation($vIds[1]))->get_meta($customKey) === 'second', 'updateMeta() must drop the variation bundle meta');
    $mediaRepo->addMeta($vIds[1], 'variation', $customKey, 'third');
    $must($mediaRepo->getMeta($vIds[1], $customKey) === ['second', 'third'], 'addMeta() must drop the memoized meta map');
    $mediaRepo->deleteMeta($metaId);
    $must($mediaRepo->getMeta($vIds[1], $customKey) === ['third'], 'deleteMeta() must drop the memoized meta map');
    $ok('wpe_meta writes through MediaRepository drop the meta map and variation bundle');

    AuthorityResolver::flushAll();
    $sqlLog = [];
    add_filter('query', $queryFilter);
    foreach (['_wp_page_template', 'taxonomy', '_wp_page_template', '_p040_missing', 'taxonomy'] as $k) {
        $mediaRepo->getMeta($pid, $k, true);
    }
    $mediaRepo->getMeta($pid);
    remove_filter('query', $queryFilter);
    $metaQueries = $countMatching($sqlLog, $prefix . 'wpe_meta');
    $must($metaQueries === 1, "Expected one wpe_meta query for 6 reads of one object, got {$metaQueries}");
    $ok('memo on: six meta reads of one object run one wpe_meta query');
    remove_filter('wpe_authority_request_memo', '__return_true');

    AuthorityResolver::flushAll();
    $sqlLog = [];
    add_filter('query', $queryFilter);
    new WC_Product_Variation($vIds[0]);
    new WC_Product_Variation($vIds[0]);
    remove_filter('query', $queryFilter);
    $must($countMatching($sqlLog, $prefix . 'wpe_variants WHERE id =') === 2, 'Memo off: variations must be read directly each time');
    $must($countMatching($sqlLog, 'WHERE product_id = ' . $pid . ' ORDER BY id ASC LIMIT') === 0, 'Memo off: the family must not be primed');
    $ok('memo off (WP-CLI default): variations are read directly, no family priming');

    echo "ALL P040 STOREFRONT QUERY BUDGET ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    remove_filter('query', $queryFilter);
    remove_filter('wpe_authority_request_memo', '__return_true');
    remove_filter('woocommerce_data_stores', [PluginBootstrap::instance(), 'filterDataStores'], 99);
    AuthorityResolver::flushAll();

    if ($origFlags === false) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    PluginBootstrap::instance()->loadFlags();

    foreach ($createdAttachmentIds as $aid) {
        if (get_post_type($aid) === 'attachment' && get_the_title($aid) === 'P040 attachment') {
            wp_delete_attachment($aid, true);
        }
    }
    foreach ($createdVariantIds as $vid) {
        $wpdb->delete("{$prefix}wpe_variant_values", ['variant_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_meta", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $vid]);
        $wpdb->delete("{$prefix}wpe_variants", ['id' => $vid]);
        if (get_post_type($vid) === 'product_variation') {
            wp_delete_post($vid, true);
        }
    }
    foreach ($createdProductIds as $pid) {
        if ($pid <= 1) {
            continue;
        }
        $optionIds = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}wpe_options WHERE product_id = %d", $pid)));
        foreach ($optionIds as $oid) {
            $wpdb->delete("{$prefix}wpe_option_values", ['option_id' => $oid]);
        }
        $wpdb->delete("{$prefix}wpe_options", ['product_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_catalog_facets", ['product_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_object_state", ['object_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_audit", ['target_id' => $pid]);
        $wpdb->delete("{$prefix}wpe_products", ['id' => $pid]);
        if (get_post_type($pid) === 'product' && get_the_title($pid) === 'P040 Budget Variable') {
            wp_delete_post($pid, true);
        }
    }
}
