<?php

/**
 * LOG-01 regression tests: which WP_Query instances CatalogAdapters serves from the catalog index.
 *
 * - Secondary product queries (themes, widgets, sitemaps, other plugins, WC REST) keep core semantics.
 * - posts_per_page -1 / nopaging / > 100, post_status other than publish, any meta_query, and
 *   query vars the index cannot express are reported unsupported instead of being truncated,
 *   turned into an impossible status filter, or silently dropped.
 * - WooCommerce's $_GET price filter (applied in posts_clauses, absent from query vars) is unsupported.
 * - orderby values the index cannot reproduce (rand, relevance, title DESC...) are unsupported
 *   instead of falling back to price ascending; 'date ID' (WooCommerce "latest") sorts by date.
 *
 * Read-only: no product, option or flag is written.
 *
 * Run: wp eval-file tests/integration/CatalogAdapterScopeTest.php
 */

if (!defined('ABSPATH')) {
    $wp_load = (getenv('WPE_WP_PATH') ?: '/var/www/site1.localhost') . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Application\Catalog\CatalogAdapters;

class CatalogAdapterScopeTest
{
    private CatalogAdapters $adapters;
    private int $checks = 0;

    public function run(): void
    {
        echo "Running CatalogAdapters scope tests (LOG-01)...\n";
        $this->adapters = new CatalogAdapters(null, null, false);

        try {
            $this->testUnsupportedShapes();
            $this->testSupportedArchiveShapes();
            $this->testOrderbyMapping();
            $this->testSecondaryQueriesUntouched();
            $this->testUnboundedSecondaryQueryReturnsEverything();
            echo "All CatalogAdapters scope tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            exit(1);
        }
    }

    private function testUnsupportedShapes(): void
    {
        echo ' unsupported query shapes are reported...';
        $cases = [
            'posts_per_page -1'      => ['posts_per_page' => -1],
            'nopaging'               => ['nopaging' => true],
            'posts_per_page 2000'    => ['posts_per_page' => 2000],
            'post_status any'        => ['post_status' => 'any'],
            'post_status draft'      => ['post_status' => 'draft'],
            'post_status list'       => ['post_status' => ['publish', 'private']],
            'meta_query _price'      => ['meta_query' => [['key' => '_price', 'value' => 10, 'compare' => '>']]],
            'nested meta_query'      => ['meta_query' => ['relation' => 'OR', [['key' => '_stock_status', 'value' => 'instock']]]],
            'author'                 => ['author' => 1],
            'date_query'             => ['date_query' => [['after' => '2026-01-01']]],
            'orderby rand'           => ['orderby' => 'rand'],
            'orderby relevance'      => ['orderby' => 'relevance', 's' => 'hat'],
            'search default orderby' => ['s' => 'hat'],
            'orderby post__in'       => ['orderby' => 'post__in'],
            'orderby array'          => ['orderby' => ['menu_order' => 'ASC', 'title' => 'ASC']],
            'meta_value other key'   => ['orderby' => 'meta_value_num', 'meta_key' => '_weight', 'order' => 'ASC'],
            'title DESC'             => ['orderby' => 'title', 'order' => 'DESC'],
            'menu_order DESC'        => ['orderby' => 'menu_order', 'order' => 'DESC'],
            'popularity ASC'         => ['orderby' => 'popularity', 'order' => 'ASC'],
            'WC price filter'        => ['wpe_wc_price_filter' => true],
        ];
        foreach ($cases as $label => $vars) {
            $support = $this->adapters->evaluateQuerySupport($vars);
            $this->check(!$support['supported'], "{$label} must be unsupported");
            $this->check($support['reasons'] !== [], "{$label} must name a reason");
        }
        echo " ok\n";
    }

    private function testSupportedArchiveShapes(): void
    {
        echo ' WooCommerce archive shapes stay supported...';
        $cases = [
            'default catalog'   => ['orderby' => 'menu_order title', 'order' => 'ASC', 'posts_per_page' => 16],
            'latest'            => ['orderby' => 'date ID', 'order' => 'DESC', 'posts_per_page' => 16],
            'price asc'         => ['orderby' => 'price', 'order' => 'ASC'],
            'price desc'        => ['orderby' => 'price', 'order' => 'DESC'],
            'popularity'        => ['orderby' => 'popularity', 'order' => 'DESC'],
            'rating'            => ['orderby' => 'rating', 'order' => 'DESC'],
            'publish status'    => ['post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'],
            'no orderby'        => ['posts_per_page' => 100],
            'category tax'      => ['tax_query' => [['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => ['hats']]]],
        ];
        foreach ($cases as $label => $vars) {
            $support = $this->adapters->evaluateQuerySupport($vars);
            $this->check($support['supported'], "{$label} must be supported: " . implode('; ', $support['reasons']));
        }
        echo " ok\n";
    }

    private function testOrderbyMapping(): void
    {
        echo ' orderby maps to the matching catalog sort...';
        $cases = [
            ['date ID', 'DESC', 'date_desc'],
            ['date ID', 'ASC', 'date_asc'],
            ['', 'DESC', 'date_desc'],
            ['menu_order title', 'ASC', 'menu_order'],
            ['title', 'ASC', 'title_asc'],
            ['price', 'ASC', 'price_asc'],
            ['price', 'DESC', 'price_desc'],
            ['popularity', 'DESC', 'popularity'],
        ];
        foreach ($cases as [$orderby, $order, $expected]) {
            $sort = $this->adapters->adaptWcQueryArgs(['orderby' => $orderby, 'order' => $order])['sort'] ?? null;
            $this->check($sort === $expected, "orderby '{$orderby}' {$order} must map to {$expected}, got " . var_export($sort, true));
        }
        $sort = $this->adapters->adaptWcQueryArgs(['orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC'])['sort'] ?? null;
        $this->check($sort === 'price_asc', "meta_value_num on _price must map to price_asc, got " . var_export($sort, true));
        echo " ok\n";
    }

    private function testSecondaryQueriesUntouched(): void
    {
        echo ' secondary product queries are left to WP_Query...';
        $secondary = new \WP_Query();
        $secondary->init();
        $secondary->query_vars = wp_parse_args(['post_type' => 'product', 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC'], $secondary->fill_query_vars([]));
        $this->check(!$secondary->is_main_query(), 'Fixture query must not be the main query');
        $this->check($this->adapters->handlePostsPreQuery(null, $secondary) === null, 'A secondary product query must return null (core)');

        // WooCommerce's product query is still served (or deliberately declined) by the adapter, not skipped by scope.
        $wc = new \WP_Query();
        $wc->init();
        $wc->query_vars = wp_parse_args(['post_type' => 'product', 'wc_query' => 'product_query', 'posts_per_page' => -1], $wc->fill_query_vars([]));
        $this->check($this->adapters->handlePostsPreQuery(null, $wc) === null, 'An unbounded wc_query must fall back to core (non-strict)');
        echo " ok\n";
    }

    private function testUnboundedSecondaryQueryReturnsEverything(): void
    {
        echo ' posts_per_page -1 returns every published product with the adapter hooked...';
        global $wpdb;
        $expected = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");

        $hook = [$this->adapters, 'handlePostsPreQuery'];
        add_filter('posts_pre_query', $hook, 10, 2);
        try {
            $q = new \WP_Query([
                'post_type'        => 'product',
                'post_status'      => 'publish',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => false,
            ]);
            $this->check(count($q->posts) === $expected, "Expected {$expected} products, got " . count($q->posts));
        } finally {
            remove_filter('posts_pre_query', $hook, 10);
        }
        echo " ok ({$expected} products)\n";
    }

    private function check(bool $condition, string $message): void
    {
        $this->checks++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

$test = new CatalogAdapterScopeTest();
$test->run();
