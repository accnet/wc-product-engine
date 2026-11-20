<?php

declare(strict_types=1);

namespace WPE\Support;

use WC_Product;
use wpdb;
use WPE\Bootstrap\PluginBootstrap;

/**
 * AuthorityResolver — Unified runtime helper for resolving whether a product or variation
 * is governed by native engine storage ('native', 'native_bridge', 'full_native') or by
 * WooCommerce legacy CPT storage ('legacy').
 */
class AuthorityResolver
{
    public const NATIVE_AUTHORITIES = ['native', 'native_bridge', 'full_native'];
    public const SUPPORTED_PRODUCT_TYPES = ['simple', 'variable', 'variation'];

    public static function isNativeAuthorityValue(?string $authority): bool
    {
        return $authority !== null && in_array($authority, self::NATIVE_AUTHORITIES, true);
    }

    public static function isSupportedProductType(?string $type): bool
    {
        return $type !== null && in_array($type, self::SUPPORTED_PRODUCT_TYPES, true);
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
        $prefix = $wpdb->prefix;

        // Unsupported WP product types (grouped, external, custom) always stay on legacy CPT
        $wpPostType = $wpdb->get_var($wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d",
            $objectId
        ));
        if ($wpPostType === 'product') {
            $terms = function_exists('get_the_terms') ? get_the_terms($objectId, 'product_type') : false;
            if (is_array($terms) && !empty($terms)) {
                $slug = (string)($terms[0]->slug ?? '');
                if ($slug !== '' && !self::isSupportedProductType($slug)) {
                    return 'legacy';
                }
            }
        }

        $cohortMode = (string)($bootstrap->getFlags()['cohort_mode'] ?? 'legacy');

        // Explicit routing table (product ID / category canary rules) takes precedence in canary mode
        $routing = get_option(PluginBootstrap::OPTION_ROUTING, []);
        if (is_array($routing) && isset($routing['products'][$objectId])) {
            return (string)$routing['products'][$objectId];
        }

        // Check wpe_object_state
        $state = $wpdb->get_row($wpdb->prepare(
            "SELECT authority, parent_id, tombstone FROM {$prefix}wpe_object_state WHERE object_id = %d",
            $objectId
        ), ARRAY_A);

        if ($state) {
            if (!empty($state['tombstone'])) {
                return 'legacy';
            }

            $auth = (string)($state['authority'] ?? 'legacy');
            if ($auth === 'legacy') {
                return 'legacy';
            }

            // In cohort_canary mode, only explicitly cut-over objects ('native_bridge'/'full_native')
            // or objects matching routing rules are native; pre-staged 'native' import rows wait for cutover.
            if ($cohortMode === 'cohort_canary') {
                if (in_array($auth, ['native_bridge', 'full_native'], true)) {
                    return $auth;
                }
                $parentId = (int)($state['parent_id'] ?? 0);
                if ($parentId > 0 && is_array($routing) && isset($routing['products'][$parentId])) {
                    return (string)$routing['products'][$parentId];
                }
                return $bootstrap->resolveProductAuthority($parentId > 0 ? $parentId : $objectId);
            }

            if (self::isNativeAuthorityValue($auth)) {
                // Verify backing native row actually exists so orphan state rows do not crash reads
                $hasNativeRow = self::hasNativeRecord($objectId, $wpdb);
                if ($hasNativeRow) {
                    return $auth;
                }
                if ($auth === 'full_native') {
                    // Under sealed full_native, never fall back silently (INV12 / R181)
                    return 'full_native';
                }
                return 'legacy';
            }
        }

        // Fallback to category/global cohort routing rule, provided backing native row exists
        $resolved = $bootstrap->resolveProductAuthority($objectId);
        if (self::isNativeAuthorityValue($resolved)) {
            if (self::hasNativeRecord($objectId, $wpdb) || $resolved === 'full_native') {
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

    /**
     * Determine whether a WC_Product instance should be handled by the native DataStore.
     */
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
        $mode = (string)($flags['cohort_mode'] ?? 'legacy');
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
        $wpdb = $db ?? $GLOBALS['wpdb'];
        $prefix = $wpdb->prefix;

        $inProducts = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$prefix}wpe_products WHERE id = %d",
            $objectId
        ));
        if ($inProducts > 0) {
            return true;
        }

        $inVariants = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$prefix}wpe_variants WHERE id = %d",
            $objectId
        ));
        return $inVariants > 0;
    }
}
