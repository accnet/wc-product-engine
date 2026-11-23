<?php
/**
 * P031.3 — Live category coverage check (read-only).
 *
 * 1. No product outside trash/auto-draft lacks a product_cat.
 * 2. Every published product in the active catalog generation has a category facet.
 * 3. The default category archive lists every product that carries the default category
 *    (WP_Query total through the engine adapter equals the term relationship count).
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/ProductCategoryCoverageLive.php
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
}

global $wpdb;
$prefix = $wpdb->prefix;
$fail = static function (string $msg): void {
    throw new \RuntimeException('ProductCategoryCoverage failed: ' . $msg);
};

$withoutCategory = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} w
     WHERE w.post_type = 'product' AND w.post_status NOT IN ('trash', 'auto-draft')
       AND NOT EXISTS (
         SELECT 1 FROM {$wpdb->term_relationships} r
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
         WHERE r.object_id = w.ID AND tt.taxonomy = 'product_cat'
       )"
);
if ($withoutCategory !== 0) {
    $fail("{$withoutCategory} products have no product_cat");
}
echo "  [OK] every product has at least one product_cat\n";

$gen = (new \WPE\Application\Catalog\CatalogProjector($wpdb))->getActiveGeneration();
$indexed = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}wpe_catalog WHERE generation = %d AND status = 'publish'",
    $gen
));
$noFacet = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}wpe_catalog c
     WHERE c.generation = %d AND c.status = 'publish'
       AND NOT EXISTS (
         SELECT 1 FROM {$prefix}wpe_catalog_facets f
         WHERE f.generation = c.generation AND f.product_id = c.product_id AND f.facet_key = 'category'
       )",
    $gen
));
if ($noFacet !== 0) {
    $fail("{$noFacet} of {$indexed} indexed products have no category facet (generation {$gen})");
}
echo "  [OK] all {$indexed} indexed products have a category facet (generation {$gen})\n";

$default = (int)get_option('default_product_cat', 0);
$term = get_term($default, 'product_cat');
if (!$term || is_wp_error($term)) {
    $fail('default category missing');
}
$expected = (int)$wpdb->get_var($wpdb->prepare(
    // Published = native status when the product is native (the storefront authority), else the shell's.
    "SELECT COUNT(DISTINCT w.ID) FROM {$wpdb->posts} w
     JOIN {$wpdb->term_relationships} r ON r.object_id = w.ID
     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
     LEFT JOIN {$prefix}wpe_products p ON p.id = w.ID AND p.deleted_at IS NULL
     WHERE w.post_type = 'product' AND tt.term_id = %d AND tt.taxonomy = 'product_cat'
       AND COALESCE(p.status COLLATE utf8mb4_unicode_ci, w.post_status COLLATE utf8mb4_unicode_ci) = 'publish'
       AND COALESCE(p.catalog_visibility COLLATE utf8mb4_unicode_ci, 'visible') IN ('visible', 'catalog')",
    $default
));
wp_cache_flush();
$q = new WP_Query([
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => 12,
    'wc_query'       => 'product_query',
    'tax_query'      => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [$default]]],
]);
if ((int)$q->found_posts !== $expected || count($q->posts) === 0) {
    $fail("default category archive found {$q->found_posts} (page " . count($q->posts) . "), expected {$expected}");
}
echo "  [OK] '{$term->slug}' archive query finds all {$expected} products\n";

echo "ALL PRODUCT CATEGORY COVERAGE CHECKS PASSED\n";
