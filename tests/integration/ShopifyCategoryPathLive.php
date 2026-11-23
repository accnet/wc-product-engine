<?php
/**
 * P032.3 — Live check of the category tree after the Shopify Product category backfill (read-only).
 *
 * 1. Every parent product_cat term: the engine total for its archive (include_children) equals the
 *    number of distinct visible published products filed anywhere in its subtree.
 * 2. No product filed under a non-default category with a parent (i.e. a mapped path leaf or any
 *    nested category) still carries the default category next to it, unless it also has another
 *    top-level category (hand-set categories are kept by design).
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/ShopifyCategoryPathLive.php
 */

use WPE\Application\Catalog\CatalogAdapters;

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
}

global $wpdb;
$prefix = $wpdb->prefix;
$fail = static function (string $msg): void {
    throw new \RuntimeException('ShopifyCategoryPathLive failed: ' . $msg);
};

$parents = array_map('intval', $wpdb->get_col(
    "SELECT DISTINCT tt.parent FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = 'product_cat' AND tt.parent > 0"
));
if ($parents === []) {
    $fail('no nested product categories found');
}

$adapter = new CatalogAdapters();
foreach ($parents as $parentId) {
    $subtree = array_merge([$parentId], array_map('intval', (array)get_term_children($parentId, 'product_cat')));
    $in = implode(',', $subtree);
    // Visible published: native status/visibility when native, else the shell's status.
    $expected = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT w.ID) FROM {$wpdb->posts} w
         JOIN {$wpdb->term_relationships} r ON r.object_id = w.ID
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'product_cat'
         LEFT JOIN {$prefix}wpe_products p ON p.id = w.ID AND p.deleted_at IS NULL
         WHERE w.post_type = 'product' AND tt.term_id IN ({$in})
           AND COALESCE(p.status COLLATE utf8mb4_unicode_ci, w.post_status COLLATE utf8mb4_unicode_ci) = 'publish'
           AND COALESCE(p.catalog_visibility COLLATE utf8mb4_unicode_ci, 'visible') IN ('visible', 'catalog')"
    );
    wp_cache_flush();
    $slug = get_term($parentId, 'product_cat')->slug;
    $res = $adapter->executeAdaptedQuery(['post_type' => 'product', 'product_cat' => $slug, 'posts_per_page' => 12]);
    if ((int)$res['total'] !== $expected) {
        $fail("category '{$slug}': engine total {$res['total']}, subtree has {$expected}");
    }
    echo "  [OK] '{$slug}' lists its whole subtree ({$expected} products)\n";
}

$default = (int)get_option('default_product_cat', 0);
$mixed = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT r1.object_id)
     FROM {$wpdb->term_relationships} r1
     JOIN {$wpdb->term_taxonomy} t1 ON t1.term_taxonomy_id = r1.term_taxonomy_id AND t1.taxonomy = 'product_cat' AND t1.term_id = %d
     JOIN {$wpdb->term_relationships} r2 ON r2.object_id = r1.object_id
     JOIN {$wpdb->term_taxonomy} t2 ON t2.term_taxonomy_id = r2.term_taxonomy_id AND t2.taxonomy = 'product_cat' AND t2.parent > 0
     WHERE NOT EXISTS (
       SELECT 1 FROM {$wpdb->term_relationships} r3
       JOIN {$wpdb->term_taxonomy} t3 ON t3.term_taxonomy_id = r3.term_taxonomy_id AND t3.taxonomy = 'product_cat'
       WHERE r3.object_id = r1.object_id AND t3.parent = 0 AND t3.term_id <> %d
     )",
    $default,
    $default
));
if ($mixed !== 0) {
    $fail("{$mixed} products are filed under a nested category and still carry the default category");
}
echo "  [OK] no product keeps the default category next to a mapped nested category\n";

echo "ALL SHOPIFY CATEGORY PATH LIVE CHECKS PASSED\n";
