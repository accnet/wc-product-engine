<?php
/**
 * Catalog shell integrity: a native product whose wp_posts shell is missing must not be indexed.
 *
 * Regression for 2026-11-21: 71 imported products lost their shells, stayed in wpe_catalog, and the
 * shop's first page resolved to 12 null posts ("No products were found").
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/CatalogShellIntegrityTest.php
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
    throw new \RuntimeException('CatalogShellIntegrity failed: ' . $msg);
};

$repo = new ProductRepository($wpdb);
$projector = new CatalogProjector($wpdb);
$gen = $projector->getActiveGeneration();
if ($gen <= 0) {
    $fail('no active catalog generation');
}

$probeTitle = 'Shell Integrity Probe ' . wp_generate_password(8, false);
$created = $repo->create([
    'title'         => $probeTitle,
    'slug'          => sanitize_title($probeTitle),
    'type'          => 'simple',
    'status'        => 'publish',
    'regular_price' => '10.00',
]);
$pid = is_array($created) ? (int)($created['id'] ?? 0) : 0;
// Every write below targets $pid, so refuse to run unless it is the row this test just created.
$isProbe = $pid > 0 && $wpdb->get_var($wpdb->prepare(
    "SELECT title FROM {$prefix}wpe_products WHERE id = %d",
    $pid
)) === $probeTitle;
if (!$isProbe) {
    $fail('could not create the probe product (create() returned ' . wp_json_encode($created) . ')');
}
$inIndex = static fn(): bool => (bool)$wpdb->get_var($wpdb->prepare(
    "SELECT 1 FROM {$prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
    $gen,
    $pid
));

try {
    if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE ID = %d", $pid))) {
        $fail('fixture product has no shell after create');
    }
    $wpdb->update($wpdb->posts, ['post_type' => 'product', 'post_status' => 'publish'], ['ID' => $pid]);

    $projector->projectProduct($pid, $gen);
    if (!$inIndex()) {
        $fail('product with a shell was not indexed');
    }
    echo "  [OK] product with a shell is indexed\n";

    // Lose the shell: the next projection must drop the row and its facets.
    $wpdb->delete($wpdb->posts, ['ID' => $pid]);
    clean_post_cache($pid);
    $projector->projectProduct($pid, $gen);
    if ($inIndex()) {
        $fail('shell-less product stayed in the index');
    }
    $facets = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}wpe_catalog_facets WHERE generation = %d AND product_id = %d",
        $gen,
        $pid
    ));
    if ($facets !== 0) {
        $fail("shell-less product kept {$facets} facets");
    }
    echo "  [OK] shell-less product and its facets are removed from the index\n";

    // Not reported as unindexed work, so the catch-up fence never loops on it.
    $audit = $projector->auditSourceVersions($gen);
    $stale = array_map('intval', array_column($audit['stale_products'] ?? [], 'product_id'));
    if (in_array($pid, $stale, true)) {
        $fail('shell-less product reported as stale by auditSourceVersions');
    }
    echo "  [OK] shell-less product is not reported as stale\n";
} finally {
    // P054: remove the native rows; a soft delete left an orphan native row behind every run.
    (new \WPE\Infrastructure\Database\ProductPurger($wpdb))->purge([$pid]);
    $wpdb->delete("{$prefix}wpe_catalog", ['generation' => $gen, 'product_id' => $pid]);
    $wpdb->delete("{$prefix}wpe_catalog_facets", ['generation' => $gen, 'product_id' => $pid]);
    $wpdb->delete($wpdb->posts, ['ID' => $pid, 'post_type' => 'product']);
}

echo "ALL CATALOG SHELL INTEGRITY ASSERTIONS PASSED\n";
