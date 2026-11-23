<?php

declare(strict_types=1);

namespace WPE\Support;

use WC_Product;
use WP_Post;
use wpdb;
use WPE\Bootstrap\PluginBootstrap;

/**
 * AuthorityResolver — Unified runtime helper for resolving whether a product or variation
 * is governed by native engine storage ('native', 'native_bridge', 'full_native') or by
 * WooCommerce legacy CPT storage ('legacy').
 *
 * Performance characteristics (P028):
 * - Single-object resolution combines object_state, native product type, and native variant check
 *   into one primary-key round-trip (and inspects WP_Post memory cache before querying wp_posts).
 * - Archive loops call primeObjects(int[] $ids) once to preload all IDs in two bulk IN(...) queries
 *   into the non-persistent 'wpe_runtime' cache group, automatically evicted on clean_post_cache,
 *   wp_cache_flush, and flag/routing option updates.
 */
class AuthorityResolver
{
    public const NATIVE_AUTHORITIES = ['native', 'native_bridge', 'full_native'];
    public const SUPPORTED_PRODUCT_TYPES = ['simple', 'variable', 'variation'];
    public const CACHE_GROUP = 'wpe_runtime';

    private static bool $hooksRegistered = false;

    public static function ensureRuntimeCacheGroup(): void
    {
        if (self::$hooksRegistered) {
            return;
        }
        self::$hooksRegistered = true;

        if (function_exists('wp_cache_add_non_persistent_groups')) {
            wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        }
        if (function_exists('add_action')) {
            add_action('clean_post_cache', [self::class, 'evictObject'], 5, 1);
            add_action('update_option_' . PluginBootstrap::OPTION_FLAGS, [self::class, 'flushAll'], 5, 0);
            add_action('update_option_' . PluginBootstrap::OPTION_ROUTING, [self::class, 'flushAll'], 5, 0);
        }
    }

    public static function evictObject($objectId): void
    {
        $id = (int)$objectId;
        if ($id <= 0) {
            return;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('auth_probe_' . $id, self::CACHE_GROUP);
            wp_cache_delete('prod_bundle_' . $id, self::CACHE_GROUP);
        }
        if (function_exists('wc_get_container') && class_exists(\Automattic\WooCommerce\Internal\Caches\ProductCache::class)) {
            try {
                wc_get_container()->get(\Automattic\WooCommerce\Internal\Caches\ProductCache::class)->remove($id);
            } catch (\Throwable $e) {
            }
        }
    }

    public static function flushAll(): void
    {
        if (function_exists('wp_cache_flush_group')) {
            @wp_cache_flush_group(self::CACHE_GROUP);
        }
        if (function_exists('wc_get_container') && class_exists(\Automattic\WooCommerce\Internal\Caches\ProductCache::class)) {
            try {
                wc_get_container()->get(\Automattic\WooCommerce\Internal\Caches\ProductCache::class)->flush();
            } catch (\Throwable $e) {
            }
        }
    }

    public static function isNativeAuthorityValue(?string $authority): bool
    {
        return $authority !== null && in_array($authority, self::NATIVE_AUTHORITIES, true);
    }

    public static function isSupportedProductType(?string $type): bool
    {
        return $type !== null && in_array($type, self::SUPPORTED_PRODUCT_TYPES, true);
    }

    /**
     * Bulk-prime authority and native type probes for an array of product/variation IDs (P028).
     *
     * @param int[] $objectIds
     */
    public static function primeObjects(array $objectIds, ?wpdb $db = null): void
    {
        self::ensureRuntimeCacheGroup();
        $ids = array_values(array_unique(array_filter(array_map('intval', $objectIds), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || !function_exists('wp_cache_set')) {
            return;
        }

        $wpdb = $db ?? $GLOBALS['wpdb'];
        $prefix = $wpdb->prefix;
        $inList = Sql::intList($ids);

        $states = [];
        $stateRows = $wpdb->get_results(
            "SELECT object_id, authority, parent_id, tombstone FROM {$prefix}wpe_object_state WHERE object_id IN ({$inList})",
            ARRAY_A
        ) ?: [];
        foreach ($stateRows as $sr) {
            $states[(int)$sr['object_id']] = $sr;
        }

        $prodTypes = [];
        $prodRows = $wpdb->get_results(
            "SELECT id, type FROM {$prefix}wpe_products WHERE id IN ({$inList}) AND deleted_at IS NULL",
            ARRAY_A
        ) ?: [];
        foreach ($prodRows as $pr) {
            $prodTypes[(int)$pr['id']] = (string)$pr['type'];
        }

        $varParents = [];
        $varRows = $wpdb->get_results(
            "SELECT id, product_id FROM {$prefix}wpe_variants WHERE id IN ({$inList}) AND deleted_at IS NULL",
            ARRAY_A
        ) ?: [];
        foreach ($varRows as $vr) {
            $varParents[(int)$vr['id']] = (int)$vr['product_id'];
        }

        $wpPostTypes = [];
        $missingPostIds = [];
        foreach ($ids as $id) {
            $cachedPost = function_exists('wp_cache_get') ? wp_cache_get($id, 'posts') : false;
            if (is_object($cachedPost) && isset($cachedPost->post_type)) {
                $wpPostTypes[$id] = (string)$cachedPost->post_type;
            } else {
                $missingPostIds[] = $id;
            }
        }
        if ($missingPostIds !== []) {
            $missingIn = Sql::intList($missingPostIds);
            $postRows = $wpdb->get_results(
                "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ({$missingIn})",
                ARRAY_A
            ) ?: [];
            foreach ($postRows as $pr) {
                $wpPostTypes[(int)$pr['ID']] = (string)$pr['post_type'];
            }
        }

        foreach ($ids as $id) {
            $st = $states[$id] ?? null;
            $probe = [
                'has_state'         => $st !== null,
                'authority'         => $st ? (string)$st['authority'] : null,
                'parent_id'         => $st && $st['parent_id'] !== null ? (int)$st['parent_id'] : 0,
                'tombstone'         => $st ? (int)$st['tombstone'] : 0,
                'native_prod_type'  => $prodTypes[$id] ?? null,
                'native_var_parent' => $varParents[$id] ?? null,
                'wp_post_type'      => $wpPostTypes[$id] ?? null,
            ];
            wp_cache_set('auth_probe_' . $id, $probe, self::CACHE_GROUP);
        }
    }

    /**
     * Fetch combined primary-key probe for one object ID (primed cache hit or 1 SQL PK query).
     *
     * @return array{has_state: bool, authority: ?string, parent_id: int, tombstone: int, native_prod_type: ?string, native_var_parent: ?int, wp_post_type: ?string}
     */
    public static function probeObject(int $objectId, ?wpdb $db = null): array
    {
        self::ensureRuntimeCacheGroup();
        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get('auth_probe_' . $objectId, self::CACHE_GROUP);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $wpdb = $db ?? $GLOBALS['wpdb'];
        $prefix = $wpdb->prefix;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT os.object_id AS state_oid, os.authority, os.parent_id, os.tombstone,
                    (SELECT p.type FROM {$prefix}wpe_products p WHERE p.id = %d AND p.deleted_at IS NULL LIMIT 1) AS native_prod_type,
                    (SELECT v.product_id FROM {$prefix}wpe_variants v WHERE v.id = %d AND v.deleted_at IS NULL LIMIT 1) AS native_var_parent,
                    (SELECT wp.post_type FROM {$wpdb->posts} wp WHERE wp.ID = %d LIMIT 1) AS wp_post_type
             FROM (SELECT 1) d
             LEFT JOIN {$prefix}wpe_object_state os ON os.object_id = %d",
            $objectId,
            $objectId,
            $objectId,
            $objectId
        ), ARRAY_A);

        return [
            'has_state'         => !empty($row['state_oid']),
            'authority'         => !empty($row['state_oid']) ? (string)$row['authority'] : null,
            'parent_id'         => !empty($row['parent_id']) ? (int)$row['parent_id'] : 0,
            'tombstone'         => !empty($row['tombstone']) ? (int)$row['tombstone'] : 0,
            'native_prod_type'  => isset($row['native_prod_type']) && $row['native_prod_type'] !== null ? (string)$row['native_prod_type'] : null,
            'native_var_parent' => isset($row['native_var_parent']) && $row['native_var_parent'] !== null ? (int)$row['native_var_parent'] : null,
            'wp_post_type'      => isset($row['wp_post_type']) && $row['wp_post_type'] !== null ? (string)$row['wp_post_type'] : null,
        ];
    }

    /**
     * Fast native product type lookup ('simple', 'variable', 'variation', or false).
     *
     * @return string|false
     */
    public static function getNativeProductType(int $objectId, ?wpdb $db = null)
    {
        if ($objectId <= 0) {
            return false;
        }
        $probe = self::probeObject($objectId, $db);
        if ($probe['native_prod_type'] !== null && $probe['native_prod_type'] !== '') {
            return $probe['native_prod_type'];
        }
        if ($probe['native_var_parent'] !== null) {
            return 'variation';
        }
        return false;
    }

    /**
     * Resolve effective authority ('legacy', 'native', 'native_bridge', 'full_native') for an existing object ID.
     */
    public static function resolveObjectAuthority(int $objectId, ?wpdb $db = null): string
    {
        if ($objectId <= 0) {
            return self::defaultNewObjectAuthority();
        }

        $bootstrap = PluginBootstrap::instance();
        if (!$bootstrap->areDependenciesSatisfied()) {
            return 'legacy';
        }

        $wpdb = $db ?? $GLOBALS['wpdb'];

        // Inspect WP post cache first (handles both stdClass from _prime_post_caches and WP_Post)
        $wpPostType = null;
        if (function_exists('wp_cache_get')) {
            $cachedPost = wp_cache_get($objectId, 'posts');
            if (is_object($cachedPost) && isset($cachedPost->post_type)) {
                $wpPostType = (string)$cachedPost->post_type;
            }
        }

        $probe = self::probeObject($objectId, $wpdb);
        if ($wpPostType === null) {
            $wpPostType = $probe['wp_post_type'] ?? null;
        }

        if ($wpPostType === 'product') {
            $terms = function_exists('get_the_terms') ? get_the_terms($objectId, 'product_type') : false;
            if (is_array($terms) && !empty($terms)) {
                $slug = (string)($terms[0]->slug ?? '');
                if ($slug !== '' && !self::isSupportedProductType($slug)) {
                    return 'legacy';
                }
            }
        }

        $cohortMode = (string)($bootstrap->getFlags()['cohort_mode'] ?? PluginBootstrap::DEFAULT_FLAGS['cohort_mode']);
        $routing = get_option(PluginBootstrap::OPTION_ROUTING, []);
        if (is_array($routing) && isset($routing['products'][$objectId])) {
            return (string)$routing['products'][$objectId];
        }

        $hasNativeRecord = ($probe['native_prod_type'] !== null || $probe['native_var_parent'] !== null);

        if ($probe['has_state']) {
            if ($probe['tombstone'] !== 0) {
                return 'legacy';
            }

            $auth = (string)($probe['authority'] ?? 'legacy');
            if ($auth === 'legacy') {
                return 'legacy';
            }

            if ($cohortMode === 'cohort_canary') {
                if (in_array($auth, ['native_bridge', 'full_native'], true)) {
                    return $auth;
                }
                $parentId = $probe['parent_id'] > 0 ? $probe['parent_id'] : (int)($probe['native_var_parent'] ?? 0);
                if ($parentId > 0 && is_array($routing) && isset($routing['products'][$parentId])) {
                    return (string)$routing['products'][$parentId];
                }
                return $bootstrap->resolveProductAuthority($parentId > 0 ? $parentId : $objectId);
            }

            if (self::isNativeAuthorityValue($auth)) {
                if ($hasNativeRecord) {
                    return $auth;
                }
                if ($auth === 'full_native') {
                    return 'full_native';
                }
                return 'legacy';
            }
        }

        $resolved = $bootstrap->resolveProductAuthority($objectId);
        if (self::isNativeAuthorityValue($resolved)) {
            if ($hasNativeRecord || $resolved === 'full_native') {
                return $resolved;
            }
            return 'legacy';
        }

        return 'legacy';
    }

    public static function isNativeObject(int $objectId, ?wpdb $db = null): bool
    {
        return self::isNativeAuthorityValue(self::resolveObjectAuthority($objectId, $db));
    }

    public static function shouldUseNativeForProduct(WC_Product $product, string $operation = 'read', ?wpdb $db = null): bool
    {
        $type = $product->get_type();
        if (!self::isSupportedProductType($type)) {
            return false;
        }

        $id = (int)$product->get_id();
        if ($operation === 'create' && $id <= 0) {
            if ($type === 'variation') {
                $parentId = (int)$product->get_parent_id();
                if ($parentId > 0) {
                    return self::isNativeObject($parentId, $db);
                }
            }
            return self::isNativeAuthorityValue(self::defaultNewObjectAuthority());
        }

        return self::isNativeObject($id, $db);
    }

    public static function defaultNewObjectAuthority(): string
    {
        $flags = PluginBootstrap::instance()->getFlags();
        $mode = (string)($flags['cohort_mode'] ?? PluginBootstrap::DEFAULT_FLAGS['cohort_mode']);
        if (in_array($mode, ['native_bridge', 'full_native'], true)) {
            return $mode;
        }
        return 'legacy';
    }

    public static function hasNativeRecord(int $objectId, ?wpdb $db = null): bool
    {
        if ($objectId <= 0) {
            return false;
        }
        $probe = self::probeObject($objectId, $db);
        return $probe['native_prod_type'] !== null || $probe['native_var_parent'] !== null;
    }
}
