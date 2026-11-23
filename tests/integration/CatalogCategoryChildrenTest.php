<?php
/**
 * P032 — Catalog adapter honours include_children for product_cat (WordPress default).
 *
 * A product filed only under Child must be returned by the engine for its Parent category through
 * the product_cat query var (slug), a tax_query by term_id, and a tax_query by term_taxonomy_id;
 * with include_children=false it must not be. Queried through CatalogAdapters directly so the
 * result comes from the engine, not from the core WP_Query fallback.
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/CatalogCategoryChildrenTest.php
 */

use WPE\Application\Catalog\CatalogAdapters;
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
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('CatalogCategoryChildren failed: ' . $msg);
    }
    $checks++;
};

$uid = strtolower(wp_generate_password(6, false));
$parent = wp_insert_term("P032 Parent {$uid}", 'product_cat');
$child = is_wp_error($parent) ? $parent : wp_insert_term("P032 Child {$uid}", 'product_cat', ['parent' => (int)$parent['term_id']]);
if (is_wp_error($parent) || is_wp_error($child)) {
    throw new \RuntimeException('CatalogCategoryChildren failed: could not create categories');
}
$parentId = (int)$parent['term_id'];
$childId = (int)$child['term_id'];
$parentSlug = get_term($parentId, 'product_cat')->slug;
$parentTt = (int)get_term($parentId, 'product_cat')->term_taxonomy_id;

$title = "P032 Children Probe {$uid}";
$created = (new ProductRepository($wpdb))->create([
    'title' => $title, 'slug' => sanitize_title($title), 'type' => 'simple', 'status' => 'publish', 'regular_price' => '5.00',
]);
$pid = is_array($created) ? (int)($created['id'] ?? 0) : 0;

try {
    $check($pid > 0 && $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) === $title,
        'fixture product not created');
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $pid, 'post_type' => 'product']);
    clean_post_cache($pid);
    wp_set_object_terms($pid, [$childId], 'product_cat');
    $projector = new CatalogProjector($wpdb);
    $projector->projectProduct($pid, $projector->getActiveGeneration());

    $adapter = new CatalogAdapters();
    $finds = static function (array $queryVars) use ($adapter, $pid): bool {
        wp_cache_flush();
        $res = $adapter->executeAdaptedQuery($queryVars + ['post_type' => 'product', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC']);
        return in_array($pid, array_map('intval', $res['ids']), true);
    };

    $check($finds(['product_cat' => $parentSlug]), 'parent slug (product_cat query var) must include the child product');
    $check($finds(['tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [$parentId]]]]),
        'tax_query term_id must include children by default');
    $check($finds(['tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_taxonomy_id', 'terms' => [$parentTt]]]]),
        'tax_query term_taxonomy_id must resolve and include children');
    $check(!$finds(['tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [$parentId], 'include_children' => false]]]),
        'include_children=false must not include the child product');
    $check($finds(['product_cat' => get_term($childId, 'product_cat')->slug]), 'child slug must find the product');
    echo "  [OK] parent category includes child products (slug, term_id, term_taxonomy_id); include_children=false excludes them\n";
} finally {
    if ($pid > 0) {
        wp_delete_post($pid, true);
    }
    wp_delete_term($childId, 'product_cat');
    wp_delete_term($parentId, 'product_cat');
    wp_cache_flush();
}

echo "ALL CATALOG CATEGORY CHILDREN ASSERTIONS PASSED ({$checks} checks)\n";
