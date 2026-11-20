<?php

declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

use WC_Data_Exception;
use WC_Product;

/**
 * WooCommerce product props the native store does not hold yet (contracts/compatibility/wc-props-mapping.json,
 * `excluded_props`, policy `legacy_only_blocks_native`).
 *
 * Products that carry one of them stay on the WooCommerce CPT store: the native data store refuses to save them
 * and a cutover refuses to move them, so the value is never silently dropped (P021, OD-18).
 */
final class LegacyOnlyProps
{
    /** Prop name => postmeta key written by WC_Product_Data_Store_CPT. Added in WooCommerce 11.3.0. */
    public const PROPS = [
        'customs_commodity_code'    => '_customs_commodity_code',
        'customs_country_of_origin' => '_customs_country_of_origin',
        'customs_description'       => '_customs_description',
    ];

    /**
     * Props of the product that hold a value the native store would lose.
     *
     * @return list<string>
     */
    public static function nonEmpty(WC_Product $product): array
    {
        $found = [];
        foreach (array_keys(self::PROPS) as $prop) {
            $getter = 'get_' . $prop;
            if (!method_exists($product, $getter)) {
                continue;
            }
            $value = $product->{$getter}('edit');
            if ($value !== null && $value !== '') {
                $found[] = $prop;
            }
        }
        return $found;
    }

    /**
     * Throws before any native write when the product carries a legacy-only prop.
     *
     * @throws WC_Data_Exception
     */
    public static function assertNativeSavable(WC_Product $product): void
    {
        $found = self::nonEmpty($product);
        if ($found === []) {
            return;
        }
        throw new WC_Data_Exception(
            'wpe_legacy_only_prop',
            sprintf(
                'Product %d has %s, which the native product store does not support yet. Keep this product on legacy authority.',
                (int) $product->get_id(),
                implode(', ', $found)
            ),
            400
        );
    }

    /**
     * Products (or parents of variations) with a non-empty legacy-only postmeta value.
     *
     * @param list<int> $productIds Restrict to these products and their variations; empty means the whole catalog.
     * @return list<int> Product IDs, at most $limit.
     */
    public static function productsWithData(\wpdb $wpdb, array $productIds = [], int $limit = 20): array
    {
        $keys = "'" . implode("','", array_map('esc_sql', array_values(self::PROPS))) . "'";
        $scope = '';
        $ids = array_values(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $in = implode(',', $ids);
            $scope = " AND (p.ID IN ({$in}) OR p.post_parent IN ({$in}))";
        }
        $rows = $wpdb->get_col(
            "SELECT DISTINCT IF(p.post_type = 'product_variation', p.post_parent, p.ID)
               FROM {$wpdb->postmeta} m
               JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE m.meta_key IN ({$keys}) AND m.meta_value <> ''
                AND p.post_type IN ('product', 'product_variation'){$scope}
              LIMIT " . max(1, $limit)
        );
        return array_map('intval', (array) $rows);
    }
}
