<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * On Change mode (D04, D05): collects the products a request changed and marks them dirty for
 * every enabled feed. Nothing is rendered here; the worker re-renders dirty products.
 *
 * IDs are kept in memory and flushed at shutdown (or every 500 IDs), so a bulk import costs a few
 * upserts instead of one per write. A mark lost to a crash is caught by reconciliation.
 */
final class FeedChangeTracker
{
    public const FLUSH_AT = 500;

    private static bool $registered = false;

    /** @var array<int,true> object IDs (products or variations) changed in this request */
    private static array $pending = [];

    /** @var array<int,true> term IDs whose products must be marked */
    private static array $pendingTerms = [];

    private static ?array $feedIds = null;

    /** True once this request wrote dirty marks (FeedAutoRunner skips its throttle then). */
    private static bool $marked = false;

    public static function register(): void
    {
        if (self::$registered || !function_exists('add_action')) {
            return;
        }
        self::$registered = true;

        // Native writes: every aggregate change goes through the outbox.
        add_action('wpe_aggregate_changed', [self::class, 'queue'], 10, 1);
        add_action('wpe_product_purged', [self::class, 'queue'], 10, 1);
        add_action('wpe_product_media_changed', [self::class, 'queue'], 10, 1);

        // Terms assigned or removed.
        $onTerms = static function ($objectId, $a = null, $b = null, $taxonomy = null): void {
            if (self::isProductTaxonomy((string)$taxonomy)) {
                self::queue((int)$objectId);
            }
        };
        add_action('set_object_terms', static fn($objectId, $terms, $ttIds, $taxonomy) => $onTerms($objectId, null, null, $taxonomy), 30, 4);
        add_action('deleted_term_relationships', static fn($objectId, $ttIds, $taxonomy) => $onTerms($objectId, null, null, $taxonomy), 30, 3);

        // A renamed or moved term changes product_type/brand of every product in it.
        add_action('edited_term', static function ($termId, $ttId, $taxonomy): void {
            if (in_array((string)$taxonomy, ['product_cat', 'product_tag', 'product_brand'], true) || str_starts_with((string)$taxonomy, 'pa_')) {
                self::$pendingTerms[(int)$termId] = true;
            }
        }, 30, 3);

        // WooCommerce CRUD (covers legacy-authority products; native ones are deduplicated).
        foreach (['woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_update_product_variation',
            'woocommerce_new_product_variation', 'woocommerce_delete_product', 'woocommerce_delete_product_variation',
            'woocommerce_trash_product', 'woocommerce_trash_product_variation'] as $hook) {
            add_action($hook, static fn($id) => self::queue((int)$id), 30, 1);
        }
        foreach (['woocommerce_product_set_stock', 'woocommerce_variation_set_stock'] as $hook) {
            add_action($hook, static function ($product): void {
                if ($product instanceof \WC_Product) {
                    self::queue((int)$product->get_id());
                }
            }, 30, 1);
        }
        foreach (['trashed_post', 'untrashed_post', 'deleted_post'] as $hook) {
            add_action($hook, static function ($postId): void {
                $type = get_post_type((int)$postId);
                if ($type === 'product' || $type === 'product_variation' || $type === false) {
                    self::queue((int)$postId);
                }
            }, 30, 1);
        }

        add_action('shutdown', [self::class, 'flush'], 20);
    }

    /** Queue a product or variation ID. */
    public static function queue($objectId): void
    {
        $id = (int)$objectId;
        if ($id <= 0) {
            return;
        }
        self::$pending[$id] = true;
        if (count(self::$pending) >= self::FLUSH_AT) {
            self::flush();
        }
    }

    /** Test hook: forget cached feed IDs after creating or deleting feeds. */
    public static function reset(): void
    {
        self::$pending = [];
        self::$pendingTerms = [];
        self::$feedIds = null;
        self::$marked = false;
    }

    public static function markedInRequest(): bool
    {
        return self::$marked;
    }

    /**
     * Write queued marks for every enabled feed.
     */
    public static function flush(): void
    {
        if (self::$pending === [] && self::$pendingTerms === []) {
            return;
        }
        $objects = array_keys(self::$pending);
        $terms = array_keys(self::$pendingTerms);
        self::$pending = [];
        self::$pendingTerms = [];

        try {
            global $wpdb;
            self::$feedIds ??= (new FeedRepository($wpdb))->enabledFeedIds();
            if (self::$feedIds === []) {
                return;
            }

            $productIds = self::parentIds($wpdb, $objects);
            if ($terms !== []) {
                $productIds = array_merge($productIds, self::productsInTerms($wpdb, $terms));
            }
            if ($productIds !== []) {
                (new FeedItemStore($wpdb))->markDirty(self::$feedIds, $productIds);
                self::$marked = true;
            }
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostic; reconciliation recovers lost marks
            error_log('[WPE] feed change tracking failed: ' . $e->getMessage());
        }
    }

    /**
     * Map variation IDs to their parent product; other IDs are products.
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function parentIds(\wpdb $wpdb, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = array_fill_keys($ids, true);
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($wpdb->get_results("SELECT id, product_id FROM {$wpdb->prefix}wpe_variants WHERE id IN ({$in})", ARRAY_A) ?: [] as $r) {
                unset($out[(int)$r['id']]);
                $out[(int)$r['product_id']] = true;
            }
            foreach ($wpdb->get_results("SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'product_variation'", ARRAY_A) ?: [] as $r) {
                unset($out[(int)$r['ID']]);
                if ((int)$r['post_parent'] > 0) {
                    $out[(int)$r['post_parent']] = true;
                }
            }
        }
        return array_map('intval', array_keys($out));
    }

    /**
     * Products assigned to the terms (and, for categories, to their descendants).
     *
     * @param int[] $termIds
     * @return int[]
     */
    private static function productsInTerms(\wpdb $wpdb, array $termIds): array
    {
        $all = [];
        foreach ($termIds as $termId) {
            $all[$termId] = true;
            $term = get_term($termId);
            if ($term && !is_wp_error($term) && is_taxonomy_hierarchical($term->taxonomy)) {
                foreach ((array)get_term_children($termId, $term->taxonomy) as $child) {
                    $all[(int)$child] = true;
                }
            }
        }
        $in = implode(',', array_map('intval', array_keys($all)));
        return array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tt.term_id IN ({$in})"
        ) ?: []);
    }

    private static function isProductTaxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, ['product_cat', 'product_tag', 'product_brand', 'product_visibility'], true)
            || str_starts_with($taxonomy, 'pa_');
    }
}
