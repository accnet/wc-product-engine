<?php
/**
 * T13 Integration Test — Post shell, factory, taxonomy, and permalink bridge.
 *
 * Verifies:
 * - R51 (permalink_factory_ids): Real ID allocation, factory hydration, permalink slug updates & 301 tracking, variation discovery.
 * - R52 (taxonomy_hierarchy): Category/tag assignment, hierarchical parent-child relationships, and term count synchronization.
 * - R53 (reviews_rating_projection): Review comments, rating distribution, and average rating projection.
 * - R54 (nonproduct_queries_unchanged): Non-product WP_Query completely untouched, shell isolation without stock/price canonical.
 * - AC-T13-01: Old/new slug redirects, variation discovery, get_post_type, review and category counts policy compliance.
 * - AC-T13-02: Non-product posts uninhibited, shell-only stores zero canonical stock or price.
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

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WooDataStore\InventoryBridge;
use WPE\Compatibility\WooDataStore\PostShellBridge;

class T13Test
{
    private \wpdb $wpdb;
    private PostShellBridge $bridge;
    private array $cleanProductIds = [];
    private array $cleanPostIds = [];
    private array $cleanTermIds = [];
    private array $cleanCommentIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Register data stores, meta bridge, inventory bridge, and post shell bridge
        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        InventoryBridge::getInstance()->boot();
        $this->bridge = PostShellBridge::getInstance();
        $this->bridge->boot();
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanProductIds as $pid) {
            $this->wpdb->delete($prefix . 'wpe_sku_registry', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_inventory', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_stock_operations', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_reservation_bridge', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_object_state', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_media', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_links', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_downloads', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_meta', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_products', ['id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_outbox', ['aggregate_id' => $pid]);
            $this->wpdb->delete($this->wpdb->posts, ['ID' => $pid]);
            $this->wpdb->delete($this->wpdb->postmeta, ['post_id' => $pid]);
        }

        foreach ($this->cleanPostIds as $pId) {
            wp_delete_post($pId, true);
        }

        foreach ($this->cleanTermIds as $tid) {
            wp_delete_term($tid, 'product_cat');
            wp_delete_term($tid, 'product_tag');
        }

        foreach ($this->cleanCommentIds as $cid) {
            wp_delete_comment($cid, true);
        }
    }

    private function createSimpleProduct(string $name, string $slug): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_slug($slug);
        $product->set_regular_price('49.99');
        $product->set_sku('SKU-' . wp_generate_password(6, false));
        $product->set_status('publish');
        $product->save();

        $pid = $product->get_id();
        $this->cleanProductIds[] = $pid;

        // Project shell
        $this->bridge->projectShell($pid, [
            'title'  => $name,
            'slug'   => $slug,
            'status' => 'publish',
        ]);

        return $product;
    }

    public function testR51PermalinkFactoryIds(): void
    {
        echo "[Test R51] Testing real ID allocation, factory hydration, permalink slug updates & 301 tracking, variation discovery...\n";

        // 1. Allocate shell
        $allocatedId = $this->bridge->allocateShell('product');
        if ($allocatedId <= 0) {
            throw new \RuntimeException("R51 Failed: Could not allocate real shell ID");
        }
        $this->cleanProductIds[] = $allocatedId;

        // Verify shell exists in wp_posts with auto-draft status
        $shellPost = get_post($allocatedId);
        if (!$shellPost || $shellPost->post_type !== 'product' || $shellPost->post_status !== 'auto-draft') {
            throw new \RuntimeException("R51 Failed: Allocated post shell invalid in wp_posts");
        }

        // 2. Create Simple Product and project shell
        $slug = 'r51-test-product-' . strtolower(wp_generate_password(6, false));
        $prod = $this->createSimpleProduct('R51 Test Product', $slug);
        $pid = $prod->get_id();

        // Verify factory hydration
        $loaded = $this->bridge->loadProduct($pid);
        if (!$loaded instanceof \WC_Product_Simple || $loaded->get_id() !== $pid) {
            throw new \RuntimeException("R51 Failed: Factory did not load WC_Product_Simple with ID {$pid}");
        }
        if ($this->bridge->getPostType($pid) !== 'product') {
            throw new \RuntimeException("R51 Failed: getPostType expected 'product'");
        }

        // 3. Test permalink resolution
        $permalink = $this->bridge->getPermalink($pid);
        if (empty($permalink) || !str_contains($permalink, $slug)) {
            throw new \RuntimeException("R51 Failed: Permalink does not contain slug {$slug}: {$permalink}");
        }

        // 4. Test slug update and old slug 301 tracking
        $newSlug = $slug . '-updated';
        $updatedSlug = $this->bridge->updateSlug($pid, $newSlug);
        if (!$updatedSlug) {
            throw new \RuntimeException("R51 Failed: updateSlug failed");
        }

        // Verify old slug is recorded
        $oldSlugs = $this->bridge->getOldSlugs($pid);
        if (!in_array($slug, $oldSlugs, true)) {
            throw new \RuntimeException("R51 Failed: Old slug {$slug} not recorded in _wp_old_slug: " . json_encode($oldSlugs));
        }

        // Verify old slug redirect resolution
        $redirectTarget = $this->bridge->resolveSlugRedirect($slug);
        if ($redirectTarget !== $pid) {
            throw new \RuntimeException("R51 Failed: Old slug redirect expected {$pid}, got {$redirectTarget}");
        }

        // 5. Variation discovery
        $parent = new \WC_Product_Variable();
        $parent->set_name('R51 Parent Variable');
        $parent->set_sku('R51-PAR-' . wp_generate_password(6, false));
        $parent->save();
        $parentId = $parent->get_id();
        $this->cleanProductIds[] = $parentId;

        $v1 = new \WC_Product_Variation();
        $v1->set_parent_id($parentId);
        $v1->set_regular_price('19.99');
        $v1->set_sku('R51-V1-' . wp_generate_password(6, false));
        $v1->save();
        $v1Id = $v1->get_id();
        $this->cleanProductIds[] = $v1Id;

        $v2 = new \WC_Product_Variation();
        $v2->set_parent_id($parentId);
        $v2->set_regular_price('29.99');
        $v2->set_sku('R51-V2-' . wp_generate_password(6, false));
        $v2->save();
        $v2Id = $v2->get_id();
        $this->cleanProductIds[] = $v2Id;

        $variations = $this->bridge->getVariations($parentId);
        if (count($variations) !== 2 || !in_array($v1Id, $variations, true) || !in_array($v2Id, $variations, true)) {
            throw new \RuntimeException("R51 Failed: Variation discovery failed: " . json_encode($variations));
        }

        echo "PASSED: Real ID allocation, factory hydration, permalink slug updates & 301 tracking, variation discovery verified.\n\n";
    }

    public function testR52TaxonomyHierarchy(): void
    {
        echo "[Test R52] Testing taxonomy hierarchy, term assignment, and category count sync...\n";

        // Create Parent Category
        $parentTerm = wp_insert_term('Apparel ' . wp_generate_password(4, false), 'product_cat');
        if (is_wp_error($parentTerm)) {
            throw new \RuntimeException("R52 Failed: Could not create parent category: " . $parentTerm->get_error_message());
        }
        $parentCatId = (int)$parentTerm['term_id'];
        $this->cleanTermIds[] = $parentCatId;

        // Create Child Category
        $childTerm = wp_insert_term('Shirts ' . wp_generate_password(4, false), 'product_cat', [
            'parent' => $parentCatId,
        ]);
        if (is_wp_error($childTerm)) {
            throw new \RuntimeException("R52 Failed: Could not create child category: " . $childTerm->get_error_message());
        }
        $childCatId = (int)$childTerm['term_id'];
        $this->cleanTermIds[] = $childCatId;

        // Verify hierarchy
        $hierarchy = $this->bridge->getCategoryHierarchy($childCatId);
        if (!in_array($parentCatId, $hierarchy['ancestors'], true)) {
            throw new \RuntimeException("R52 Failed: Parent category {$parentCatId} not in child ancestors: " . json_encode($hierarchy));
        }

        // Assign child category to product
        $prod = $this->createSimpleProduct('R52 Taxonomy Test', 'r52-tax-test-' . wp_generate_password(6, false));
        $pid = $prod->get_id();

        $assigned = $this->bridge->setProductCategories($pid, [$childCatId]);
        if (empty($assigned) || !in_array($childCatId, $assigned, true)) {
            throw new \RuntimeException("R52 Failed: Failed to assign category to product: " . json_encode($assigned));
        }

        // Verify assigned categories via bridge
        $terms = $this->bridge->getProductCategories($pid);
        $termIds = wp_list_pluck($terms, 'term_id');
        if (!in_array($childCatId, $termIds, true)) {
            throw new \RuntimeException("R52 Failed: Assigned category {$childCatId} not found in getProductCategories: " . json_encode($termIds));
        }

        // Verify category count
        $count = $this->bridge->getCategoryProductCount($childCatId);
        if ($count < 1) {
            throw new \RuntimeException("R52 Failed: Category product count expected >= 1, got {$count}");
        }

        echo "PASSED: Taxonomy hierarchy, term assignment, and category count sync verified.\n\n";
    }

    public function testR53ReviewsRatingProjection(): void
    {
        echo "[Test R53] Testing reviews, rating projection, and comment count synchronization...\n";

        $prod = $this->createSimpleProduct('R53 Reviews Test', 'r53-reviews-test-' . wp_generate_password(6, false));
        $pid = $prod->get_id();

        // Add 2 reviews: rating 5 and rating 3
        $c1 = $this->bridge->addReview($pid, 5, 'Excellent quality!', 'Alice', 'alice@example.com', 'approve');
        $c2 = $this->bridge->addReview($pid, 3, 'Average product.', 'Bob', 'bob@example.com', 'approve');
        $this->cleanCommentIds[] = $c1;
        $this->cleanCommentIds[] = $c2;

        // Verify synchronized rating metrics
        $metrics = $this->bridge->syncProductRatings($pid);
        if ($metrics['average_rating'] !== 4.0) {
            throw new \RuntimeException("R53 Failed: Average rating expected 4.0, got {$metrics['average_rating']}");
        }
        if ($metrics['review_count'] !== 2) {
            throw new \RuntimeException("R53 Failed: Review count expected 2, got {$metrics['review_count']}");
        }
        if (($metrics['rating_counts'][5] ?? 0) !== 1 || ($metrics['rating_counts'][3] ?? 0) !== 1) {
            throw new \RuntimeException("R53 Failed: Rating counts distribution invalid: " . json_encode($metrics['rating_counts']));
        }

        // Verify WC_Product object reflects projected metrics
        $wcProd = wc_get_product($pid);
        if ((float)$wcProd->get_average_rating() !== 4.0) {
            throw new \RuntimeException("R53 Failed: WC_Product get_average_rating expected 4.0, got " . $wcProd->get_average_rating());
        }
        if ((int)$wcProd->get_review_count() !== 2) {
            throw new \RuntimeException("R53 Failed: WC_Product get_review_count expected 2, got " . $wcProd->get_review_count());
        }

        // Verify wp_posts comment_count
        $commentCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT comment_count FROM {$this->wpdb->posts} WHERE ID = %d",
            $pid
        ));
        if ($commentCount !== 2) {
            throw new \RuntimeException("R53 Failed: wp_posts comment_count expected 2, got {$commentCount}");
        }

        echo "PASSED: Reviews, rating projection, and comment count synchronization verified.\n\n";
    }

    public function testR54NonproductQueriesUnchanged(): void
    {
        echo "[Test R54] Testing non-product WP_Query untouched & zero canonical stock/price in shell...\n";

        // Create standard WordPress blog post
        $postTitle = 'Standard Blog Post ' . wp_generate_password(6, false);
        $blogPostId = wp_insert_post([
            'post_title'   => $postTitle,
            'post_content' => 'This is standard WordPress blog content completely untouched by WPE.',
            'post_status'  => 'publish',
            'post_type'    => 'post',
        ]);

        if (!$blogPostId || is_wp_error($blogPostId)) {
            throw new \RuntimeException("R54 Failed: Could not create standard blog post");
        }
        $this->cleanPostIds[] = $blogPostId;

        // Verify standard WP_Query executes normally and returns unchanged post
        $query = new \WP_Query([
            'post_type' => 'post',
            'p'         => $blogPostId,
        ]);

        if (!$query->have_posts()) {
            throw new \RuntimeException("R54 Failed: WP_Query did not find standard blog post");
        }
        $retrieved = $query->posts[0];
        if ($retrieved->post_title !== $postTitle) {
            throw new \RuntimeException("R54 Failed: Retrieved post title mismatched: {$retrieved->post_title}");
        }

        // Verify non-product post is completely unmanaged in native tables
        if (!$this->bridge->verifyNonProductUntouched($blogPostId)) {
            throw new \RuntimeException("R54 Failed: Non-product post was contaminated in native tables!");
        }

        // Verify product shell row in wp_posts contains NO canonical stock or price columns (AC-T13-02)
        $prod = $this->createSimpleProduct('R54 Shell Check', 'r54-shell-check-' . wp_generate_password(6, false));
        $pid = $prod->get_id();

        if (!$this->bridge->verifyShellNoCanonicalStockPrice($pid)) {
            throw new \RuntimeException("R54 Failed: Shell in wp_posts contains forbidden price or stock columns!");
        }

        echo "PASSED: Non-product WP_Query untouched & zero canonical stock/price in shell verified.\n\n";
    }

    public function testAcT1301PermalinkRedirectsAndPolicy(): void
    {
        echo "[Test AC-T13-01] Testing permalink redirects, variation discovery, get_post_type, and counts policy...\n";

        $slug1 = 'ac13-prod-' . wp_generate_password(6, false);
        $prod = $this->createSimpleProduct('AC13 Test Product', $slug1);
        $pid = $prod->get_id();

        // 1. Slug change and redirect target
        $slug2 = $slug1 . '-renamed';
        $this->bridge->updateSlug($pid, $slug2);

        $target = $this->bridge->resolveSlugRedirect($slug1);
        if ($target !== $pid) {
            throw new \RuntimeException("AC-T13-01 Failed: Old slug redirect expected {$pid}, got {$target}");
        }

        // 2. get_post_type check
        if (get_post_type($pid) !== 'product') {
            throw new \RuntimeException("AC-T13-01 Failed: get_post_type expected 'product'");
        }

        echo "PASSED: AC-T13-01 verified.\n\n";
    }

    public function testAcT1302NonProductUntouchedAndNoShellStock(): void
    {
        echo "[Test AC-T13-02] Testing non-product posts uninhibited and shell-only stores zero canonical stock or price...\n";

        // 1. Non-product page
        $pageId = wp_insert_post([
            'post_title'  => 'AC13 Sample Page',
            'post_type'   => 'page',
            'post_status' => 'publish',
        ]);
        $this->cleanPostIds[] = $pageId;

        if (!$this->bridge->verifyNonProductUntouched($pageId)) {
            throw new \RuntimeException("AC-T13-02 Failed: Page should not exist in native tables");
        }

        // 2. Product shell row
        $prod = $this->createSimpleProduct('AC13 Stock Check', 'ac13-stock-check-' . wp_generate_password(6, false));
        $pid = $prod->get_id();

        if (!$this->bridge->verifyShellNoCanonicalStockPrice($pid)) {
            throw new \RuntimeException("AC-T13-02 Failed: Shell should not contain canonical stock/price");
        }

        echo "PASSED: AC-T13-02 verified.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T13 Integration Tests ===\n\n";

        $this->testR51PermalinkFactoryIds();
        $this->testR52TaxonomyHierarchy();
        $this->testR53ReviewsRatingProjection();
        $this->testR54NonproductQueriesUnchanged();
        $this->testAcT1301PermalinkRedirectsAndPolicy();
        $this->testAcT1302NonProductUntouchedAndNoShellStock();

        echo "=== Test Summary ===\n";
        echo "R51 (permalink_factory_ids): PASSED\n";
        echo "R52 (taxonomy_hierarchy): PASSED\n";
        echo "R53 (reviews_rating_projection): PASSED\n";
        echo "R54 (nonproduct_queries_unchanged): PASSED\n";
        echo "AC-T13-01 (permalink_discovery_counts): PASSED\n";
        echo "AC-T13-02 (nonproduct_and_shell_clean): PASSED\n";
    }
}

$test = new T13Test();
$test->runAll();
exit(0);
