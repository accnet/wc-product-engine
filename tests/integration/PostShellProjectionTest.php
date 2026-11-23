<?php
/**
 * Integration Test — native create/update projects the WP post shell after commit (03 §5 "Cấp ID và shell").
 *
 * Verifies, under native authority:
 * - WC_Product::save() on a new product publishes its preallocated auto-draft shell with title, slug,
 *   excerpt, and a non-zero post_date_gmt, so core discovery (get_post_status, secondary WP_Query) sees it.
 * - Updating name/status re-projects the shell; a price-only update leaves shell fields unchanged.
 * - Categories, tags, product_type, product_visibility (featured/hidden/outofstock), reviews_allowed
 *   (comment_status), upsells, and the wc_product_meta_lookup row are projected and read back via CRUD.
 *
 * Uses explicit checks (not assert()) because zend.assertions may be disabled in CLI.
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

use WPE\Bootstrap\PluginBootstrap;
use WPE\Support\AuthorityResolver;

global $wpdb;

$failures  = 0;
$check     = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . ": {$label}\n";
    if (!$ok) {
        $failures++;
    }
};
$shellRow  = static function (int $id) use ($wpdb): array {
    return (array)$wpdb->get_row($wpdb->prepare(
        "SELECT post_status, post_title, post_name, post_excerpt, post_date_gmt FROM {$wpdb->posts} WHERE ID = %d",
        $id
    ), ARRAY_A);
};

$origFlags = get_option(PluginBootstrap::OPTION_FLAGS, false);
$productId = 0;
$catId     = 0;
$tagId     = 0;

try {
    echo "=== PostShellProjectionTest ===\n";

    update_option(PluginBootstrap::OPTION_FLAGS, array_merge(
        PluginBootstrap::DEFAULT_FLAGS,
        is_array($origFlags) ? $origFlags : [],
        ['native_writes_enabled' => true, 'cohort_mode' => 'native_bridge']
    ));
    PluginBootstrap::instance()->loadFlags();

    $product = new WC_Product_Simple();
    $product->set_name('WPE Shell Projection ' . wp_rand(1000, 9999));
    $product->set_status('publish');
    $product->set_regular_price('19.00');
    $product->set_short_description('Shell projection excerpt');
    $catId = (int)(wp_insert_term('WPE Shell Cat ' . wp_rand(1000, 9999), 'product_cat')['term_id'] ?? 0);
    $tagId = (int)(wp_insert_term('WPE Shell Tag ' . wp_rand(1000, 9999), 'product_tag')['term_id'] ?? 0);
    $product->set_category_ids([$catId]);
    $product->set_tag_ids([$tagId]);
    $product->set_featured(true);
    $product->set_catalog_visibility('hidden');
    $product->set_reviews_allowed(false);
    $upsellTarget = (int)($wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' LIMIT 1") ?: 0);
    if ($upsellTarget > 0) {
        $product->set_upsell_ids([$upsellTarget]);
    }
    $productId = (int)$product->save();

    $check($productId > 0, 'native product is created');
    $check(AuthorityResolver::isNativeObject($productId), 'product is under native authority');

    $row = $shellRow($productId);
    $check(($row['post_status'] ?? '') === 'publish', 'shell status projected to publish (was auto-draft)');
    $check(($row['post_title'] ?? '') === $product->get_name(), 'shell title projected');
    $check(($row['post_name'] ?? '') === sanitize_title($product->get_name()), 'shell slug projected');
    $check(($row['post_excerpt'] ?? '') === 'Shell projection excerpt', 'shell excerpt projected');
    $check(!str_starts_with((string)($row['post_date_gmt'] ?? '0000'), '0000-00-00'), 'shell post_date_gmt is set');

    clean_post_cache($productId);
    $check(get_post_status($productId) === 'publish', 'get_post_status() reports publish');
    $query = new WP_Query(['post_type' => 'product', 'post__in' => [$productId], 'fields' => 'ids', 'no_found_rows' => true]);
    $check(in_array($productId, array_map('intval', $query->posts), true), 'secondary WP_Query discovers the product');

    $slugs = static fn(string $tax): array => wp_get_object_terms($productId, $tax, ['fields' => 'ids']);
    $check(in_array($catId, array_map('intval', $slugs('product_cat')), true), 'product_cat relationship projected');
    $check(in_array($tagId, array_map('intval', $slugs('product_tag')), true), 'product_tag relationship projected');
    $check(wp_get_object_terms($productId, 'product_type', ['fields' => 'slugs']) === ['simple'], 'product_type term projected');
    $visibility = wp_get_object_terms($productId, 'product_visibility', ['fields' => 'slugs']);
    sort($visibility);
    $check($visibility === ['exclude-from-catalog', 'exclude-from-search', 'featured'], 'product_visibility terms match WooCommerce rules');
    $check((string)$wpdb->get_var($wpdb->prepare("SELECT comment_status FROM {$wpdb->posts} WHERE ID = %d", $productId)) === 'closed', 'reviews_allowed=false projects comment_status closed');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wpe_product_terms WHERE product_id = %d", $productId)) >= 4, 'wpe_product_terms registry synced');
    $lookup = $wpdb->get_row($wpdb->prepare("SELECT min_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id = %d", $productId), ARRAY_A);
    $check(is_array($lookup) && (float)$lookup['min_price'] === 19.0, 'wc_product_meta_lookup row projected');

    $fresh = wc_get_product($productId);
    $check($fresh->get_category_ids() === [$catId], 'CRUD reads category_ids back');
    $check($fresh->get_tag_ids() === [$tagId], 'CRUD reads tag_ids back');
    $check($fresh->get_reviews_allowed() === false, 'CRUD reads reviews_allowed back');
    if ($upsellTarget > 0) {
        $check($fresh->get_upsell_ids() === [$upsellTarget], 'upsell links persisted on create');
    }

    $fresh->set_catalog_visibility('visible');
    $fresh->set_featured(false);
    $fresh->set_stock_status('outofstock');
    $fresh->set_reviews_allowed(true);
    $fresh->save();
    $check(wp_get_object_terms($productId, 'product_visibility', ['fields' => 'slugs']) === ['outofstock'], 'update re-projects product_visibility');
    $check((string)$wpdb->get_var($wpdb->prepare("SELECT comment_status FROM {$wpdb->posts} WHERE ID = %d", $productId)) === 'open', 'update re-projects comment_status');

    $reloaded = wc_get_product($productId);
    $reloaded->set_name('WPE Shell Projection Renamed');
    $reloaded->set_status('draft');
    $reloaded->save();
    $row = $shellRow($productId);
    $check(($row['post_title'] ?? '') === 'WPE Shell Projection Renamed', 'update re-projects title');
    $check(($row['post_status'] ?? '') === 'draft', 'update re-projects status');

    $before   = $shellRow($productId);
    $reloaded = wc_get_product($productId);
    $reloaded->set_regular_price('21.00');
    $reloaded->save();
    $check($shellRow($productId) === $before, 'price-only update leaves shell fields unchanged');

} catch (Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($productId > 0) {
        $p = wc_get_product($productId);
        if ($p) {
            $p->delete(true);
        }
        wp_delete_post($productId, true);
    }
    if ($catId > 0) {
        wp_delete_term($catId, 'product_cat');
    }
    if ($tagId > 0) {
        wp_delete_term($tagId, 'product_tag');
    }
    if ($origFlags === false) {
        delete_option(PluginBootstrap::OPTION_FLAGS);
    } else {
        update_option(PluginBootstrap::OPTION_FLAGS, $origFlags);
    }
    PluginBootstrap::instance()->loadFlags();
}

echo $failures === 0 ? "PASSED: post shell projection verified.\n" : "FAILED: {$failures} check(s).\n";
exit($failures === 0 ? 0 : 1);
