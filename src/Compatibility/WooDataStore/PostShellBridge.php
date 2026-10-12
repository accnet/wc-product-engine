<?php

declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

use wpdb;
use WC_Product;
use WPE\Compatibility\WordPress\PostIdAllocator;

/**
 * Post Shell, Factory, Taxonomy, and Permalink Bridge.
 *
 * Implements:
 * - Real WordPress post shell allocation and projection to wp_posts (R51, AC-T13-01).
 * - Permalink resolution and old-slug 301 redirects (_wp_old_slug) (R51, AC-T13-01).
 * - WooCommerce Product Factory compatibility (wc_get_product, get_post_type) (R51).
 * - Hierarchical taxonomy management and category product count synchronization (R52).
 * - Reviews, rating counts, and average rating projection from standard WP comments (R53).
 * - Strict non-product posts isolation and zero canonical stock/price in shell (R54, AC-T13-02).
 */
class PostShellBridge
{
    private static ?PostShellBridge $instance = null;
    private static bool $booted = false;

    private wpdb $wpdb;
    private PostIdAllocator $idAllocator;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableMeta;

    public function __construct(?wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->idAllocator = new PostIdAllocator($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts = $prefix . 'wpe_products';
        $this->tableVariants = $prefix . 'wpe_variants';
        $this->tableMeta     = $prefix . 'wpe_meta';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot hooks for old slug redirects and review transitions.
     */
    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        // Old slug redirect filter
        add_filter('old_slug_redirect_post_id', [$this, 'filterOldSlugRedirectPostId'], 10, 1);

        // Automatic review rating recalculation on comment state changes
        add_action('comment_post', [$this, 'onCommentChanged'], 20, 2);
        add_action('wp_set_comment_status', [$this, 'onCommentStatusChanged'], 20, 2);
        add_action('deleted_comment', [$this, 'onCommentDeleted'], 20, 2);
    }

    /* -------------------------------------------------------------------------- */
    /* Real ID Allocation & Shell Projection (R51, AC-T13-01, AC-T13-02)          */
    /* -------------------------------------------------------------------------- */

    /**
     * Allocate a real WordPress post shell for a product or variation.
     */
    public function allocateShell(string $postType = 'product'): int
    {
        return $this->idAllocator->allocateShell($postType);
    }

    /**
     * Project aggregate attributes into the WordPress shell row in wp_posts.
     * Enforces that canonical price and stock are NEVER stored in wp_posts (AC-T13-02).
     */
    public function projectShell(int $productId, array $data): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $postData = [
            'ID'                => $productId,
            'post_modified'     => $now,
            'post_modified_gmt' => $now,
        ];

        if (isset($data['title'])) {
            $postData['post_title'] = (string)$data['title'];
        }

        if (isset($data['slug']) && $data['slug'] !== '') {
            $this->updateSlug($productId, (string)$data['slug']);
            // updateSlug already updates post_name and tracks _wp_old_slug
        }

        if (isset($data['status'])) {
            $postData['post_status'] = (string)$data['status'];
        }

        if (isset($data['parent_id'])) {
            $postData['post_parent'] = (int)$data['parent_id'];
        }

        if (isset($data['description'])) {
            $postData['post_content'] = (string)$data['description'];
        }

        if (isset($data['short_description'])) {
            $postData['post_excerpt'] = (string)$data['short_description'];
        }

        if (isset($data['menu_order'])) {
            $postData['menu_order'] = (int)$data['menu_order'];
        }

        if (isset($data['post_type'])) {
            $postData['post_type'] = (string)$data['post_type'];
        }

        $updated = $this->wpdb->update($this->wpdb->posts, $postData, ['ID' => $productId]);
        clean_post_cache($productId);

        return $updated !== false;
    }

    /* -------------------------------------------------------------------------- */
    /* Permalinks & Old Slug Redirects (R51, AC-T13-01)                           */
    /* -------------------------------------------------------------------------- */

    /**
     * Updates product slug and records previous slug in _wp_old_slug for 301 redirection.
     */
    public function updateSlug(int $productId, string $newSlug): bool
    {
        $newSlug = sanitize_title($newSlug);
        if ($newSlug === '') {
            return false;
        }

        $currentSlug = (string)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT post_name FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ));

        if ($currentSlug !== '' && $currentSlug !== $newSlug) {
            // Check if currentSlug is already tracked in _wp_old_slug
            $existingOld = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_old_slug' AND meta_value = %s",
                $productId,
                $currentSlug
            ));

            if (!$existingOld) {
                $this->wpdb->insert($this->wpdb->postmeta, [
                    'post_id'    => $productId,
                    'meta_key'   => '_wp_old_slug',
                    'meta_value' => $currentSlug,
                ]);
            }
        }

        $res = $this->wpdb->update(
            $this->wpdb->posts,
            ['post_name' => $newSlug],
            ['ID' => $productId]
        );

        clean_post_cache($productId);
        return $res !== false;
    }

    /**
     * Returns all historical slugs for a product.
     */
    public function getOldSlugs(int $productId): array
    {
        $rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_old_slug'",
            $productId
        ));
        return is_array($rows) ? $rows : [];
    }

    /**
     * Resolves a slug redirect target ID for an old slug.
     */
    public function resolveSlugRedirect(string $requestedSlug): ?int
    {
        $slug = sanitize_title($requestedSlug);
        $postId = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT p.ID
             FROM {$this->wpdb->postmeta} pm
             JOIN {$this->wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wp_old_slug'
               AND pm.meta_value = %s
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status = 'publish'
             LIMIT 1",
            $slug
        ));

        return $postId ? (int)$postId : null;
    }

    public function filterOldSlugRedirectPostId(?int $postId): ?int
    {
        if ($postId) {
            return $postId;
        }

        $queryVar = get_query_var('name');
        if (!empty($queryVar)) {
            $redirectTarget = $this->resolveSlugRedirect((string)$queryVar);
            if ($redirectTarget) {
                return $redirectTarget;
            }
        }

        return $postId;
    }

    public function getPermalink(int $productId): string
    {
        return (string)get_permalink($productId);
    }

    /* -------------------------------------------------------------------------- */
    /* Product Factory & Variation Discovery (R51, AC-T13-01)                     */
    /* -------------------------------------------------------------------------- */

    /**
     * Determines post type from the authoritative shell.
     */
    public function getPostType(int $productId): ?string
    {
        return get_post_type($productId) ?: null;
    }

    /**
     * Loads a WooCommerce product instance via the standard factory.
     */
    public function loadProduct(int $productId): ?WC_Product
    {
        $prod = wc_get_product($productId);
        return $prod instanceof WC_Product ? $prod : null;
    }

    /**
     * Discovers active variations for a parent variable product.
     */
    public function getVariations(int $parentId): array
    {
        $rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants}
             WHERE product_id = %d AND status != 'trash' AND deleted_at IS NULL
             ORDER BY position ASC, id ASC",
            $parentId
        ));

        return array_map('intval', $rows ?: []);
    }

    /* -------------------------------------------------------------------------- */
    /* Taxonomy & Hierarchy Bridge (R52, AC-T13-01)                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Assigns product categories and triggers count sync.
     */
    public function setProductCategories(int $productId, array $categoryIds): array
    {
        $termIds = array_map('intval', $categoryIds);
        $res = wp_set_object_terms($productId, $termIds, 'product_cat');
        $this->syncCategoryCounts($termIds);
        return is_array($res) ? array_map('intval', $res) : [];
    }

    /**
     * Retrieves assigned category term objects with hierarchy relations.
     */
    public function getProductCategories(int $productId): array
    {
        $terms = wp_get_object_terms($productId, 'product_cat');
        return is_array($terms) ? $terms : [];
    }

    /**
     * Assigns product tags.
     */
    public function setProductTags(int $productId, array $tagIds): array
    {
        $termIds = array_map('intval', $tagIds);
        $res = wp_set_object_terms($productId, $termIds, 'product_tag');
        return is_array($res) ? array_map('intval', $res) : [];
    }

    /**
     * Retrieves assigned tags.
     */
    public function getProductTags(int $productId): array
    {
        $terms = wp_get_object_terms($productId, 'product_tag');
        return is_array($terms) ? $terms : [];
    }

    /**
     * Retrieves ancestral and child hierarchy for a given category.
     */
    public function getCategoryHierarchy(int $categoryId): array
    {
        $ancestors = get_ancestors($categoryId, 'product_cat', 'taxonomy');
        $children = get_term_children($categoryId, 'product_cat');

        return [
            'term_id'   => $categoryId,
            'ancestors' => is_array($ancestors) ? array_map('intval', $ancestors) : [],
            'children'  => is_array($children) && !is_wp_error($children) ? array_map('intval', $children) : [],
        ];
    }

    /**
     * Synchronizes term counts for product_cat.
     */
    public function syncCategoryCounts(array $termIds = []): void
    {
        if (!empty($termIds)) {
            wp_update_term_count_now(array_map('intval', $termIds), 'product_cat');
        }
    }

    public function getCategoryProductCount(int $categoryId): int
    {
        $term = get_term($categoryId, 'product_cat');
        return ($term && !is_wp_error($term)) ? (int)$term->count : 0;
    }

    /* -------------------------------------------------------------------------- */
    /* Reviews & Rating Projection (R53, AC-T13-01)                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Adds an approved review with rating to a product and projects the summary.
     */
    public function addReview(
        int $productId,
        int $rating,
        string $content,
        string $author,
        string $email = '',
        string $status = 'approve'
    ): int {
        $approvedVal = ($status === 'approve') ? 1 : 0;
        $commentData = [
            'comment_post_ID'      => $productId,
            'comment_author'       => $author,
            'comment_author_email' => $email,
            'comment_content'      => $content,
            'comment_type'         => 'review',
            'comment_approved'     => $approvedVal,
            'comment_date'         => current_time('mysql'),
            'comment_date_gmt'     => current_time('mysql', 1),
        ];

        $commentId = wp_insert_comment($commentData);
        if (!$commentId) {
            throw new \RuntimeException("Failed to insert review comment for product {$productId}");
        }

        add_comment_meta($commentId, 'rating', max(1, min(5, $rating)), true);

        // Synchronize rating projection
        $this->syncProductRatings($productId);

        return (int)$commentId;
    }

    /**
     * Recalculates average rating, review count, and rating distribution for a product.
     * Projects the result to native wpe_products extra_props and wp_posts comment_count.
     */
    public function syncProductRatings(int $productId): array
    {
        if ($productId <= 0) {
            return ['average_rating' => 0.0, 'review_count' => 0, 'rating_counts' => []];
        }

        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT cm.meta_value AS rating
             FROM {$this->wpdb->comments} c
             JOIN {$this->wpdb->commentmeta} cm ON c.comment_ID = cm.comment_id AND cm.meta_key = 'rating'
             WHERE c.comment_post_ID = %d
               AND c.comment_approved = '1'
               AND c.comment_type = 'review'",
            $productId
        ), ARRAY_A);

        $totalRatings = 0;
        $sumRatings   = 0;
        $ratingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($results as $row) {
            $r = (int)$row['rating'];
            if ($r >= 1 && $r <= 5) {
                $totalRatings++;
                $sumRatings += $r;
                $ratingCounts[$r]++;
            }
        }

        $avgRating = $totalRatings > 0 ? round($sumRatings / $totalRatings, 2) : 0.0;

        // Also get total approved comments (reviews)
        $approvedReviewsCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->comments}
             WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type = 'review'",
            $productId
        ));

        // Update native wpe_products.extra_props
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT extra_props FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);

        if ($row) {
            $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
            $extra['average_rating'] = $avgRating;
            $extra['review_count']   = $approvedReviewsCount;
            $extra['rating_counts']  = $ratingCounts;

            $this->wpdb->update(
                $this->tableProducts,
                ['extra_props' => json_encode($extra, JSON_UNESCAPED_UNICODE)],
                ['id' => $productId]
            );
        }

        // Update shell wp_posts.comment_count
        $this->wpdb->update(
            $this->wpdb->posts,
            ['comment_count' => $approvedReviewsCount],
            ['ID' => $productId]
        );

        if (class_exists('WC_Comments')) {
            \WC_Comments::clear_transients($productId);
        }
        clean_post_cache($productId);

        return [
            'average_rating' => $avgRating,
            'review_count'   => $approvedReviewsCount,
            'rating_counts'  => $ratingCounts,
        ];
    }

    public function onCommentChanged(int $commentId, $commentApproved): void
    {
        $comment = get_comment($commentId);
        if ($comment && $comment->comment_type === 'review') {
            $this->syncProductRatings((int)$comment->comment_post_ID);
        }
    }

    public function onCommentStatusChanged(int $commentId, string $status): void
    {
        $comment = get_comment($commentId);
        if ($comment && $comment->comment_type === 'review') {
            $this->syncProductRatings((int)$comment->comment_post_ID);
        }
    }

    public function onCommentDeleted(int $commentId, $comment): void
    {
        if ($comment && $comment->comment_type === 'review') {
            $this->syncProductRatings((int)$comment->comment_post_ID);
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Non-Product Isolation & Shell Verification (R54, AC-T13-02)                */
    /* -------------------------------------------------------------------------- */

    /**
     * Checks if a post ID represents a product or variation.
     */
    public function isProductPost(int $postId): bool
    {
        $type = get_post_type($postId);
        return in_array($type, ['product', 'product_variation'], true);
    }

    /**
     * Verifies that non-product posts (standard posts, pages) are completely unmanaged.
     */
    public function verifyNonProductUntouched(int $postId): bool
    {
        $post = get_post($postId);
        if (!$post || $this->isProductPost($postId)) {
            return false;
        }

        // Confirm non-product post has no entries in native tables
        $inNativeProd = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableProducts} WHERE id = %d",
            $postId
        ));
        $inNativeVar = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableVariants} WHERE id = %d",
            $postId
        ));

        return ($inNativeProd === 0 && $inNativeVar === 0);
    }

    /**
     * Verifies that the wp_posts shell row contains NO canonical price or stock columns (AC-T13-02).
     */
    public function verifyShellNoCanonicalStockPrice(int $productId): bool
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ), ARRAY_A);

        if (!$row) {
            return false;
        }

        // Check that none of the columns in wp_posts are price or stock
        $forbiddenKeys = ['price', 'regular_price', 'sale_price', 'stock', 'stock_quantity', 'inventory'];
        foreach ($forbiddenKeys as $k) {
            if (array_key_exists($k, $row)) {
                return false;
            }
        }

        return true;
    }
}
