<?php

declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

/**
 * Keeps WooCommerce's wc_product_meta_lookup table in step with the legacy postmeta projection.
 *
 * LegacyMirror writes product and variation postmeta directly, which skips the WooCommerce data
 * store and therefore its lookup table update. WooCommerce reads that table for price sorting,
 * the price filter, on-sale and stock queries, so stale or missing rows give wrong shop results
 * (audit F04).
 *
 * Rows are built with WooCommerce's own WC_Product_Data_Store_CPT::get_data_for_lookup_table(),
 * so the column rules (on-sale, stock, ratings, COGS, global unique id) stay WooCommerce's. One
 * difference is handled here: WooCommerce stores one `_price` row per child price on a variable
 * parent and reads min/max from them, while the legacy projection stores a single row, so the
 * parent's min/max price are taken from its variations' rows. Meta is primed in one query and rows
 * are written with a multi-row REPLACE, so a large variable product costs a few queries.
 */
final class LookupTableProjector extends \WC_Product_Data_Store_CPT
{
    private const TABLE = 'wc_product_meta_lookup';
    private const CHUNK = 200;

    /**
     * Rebuild the lookup rows of a product and its live variations.
     *
     * @param int[] $variationIds
     * @return int number of rows written
     */
    public function projectProduct(int $productId, array $variationIds = []): int
    {
        global $wpdb;

        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds), static fn(int $id): bool => $id > 0 && $id !== $productId)));
        if ($productId <= 0 || !isset($wpdb->wc_product_meta_lookup)) {
            return 0;
        }

        /**
         * Whether the engine refreshes wc_product_meta_lookup after projecting legacy postmeta.
         *
         * @param bool  $enabled
         * @param int[] $ids Product and variation IDs about to be projected.
         */
        if (!apply_filters('wpe_project_product_meta_lookup', true, array_merge([$productId], $variationIds))) {
            return 0;
        }

        update_meta_cache('post', array_merge([$productId], $variationIds));

        $rows = [];
        $childPrices = [];
        foreach ($variationIds as $id) {
            $row = $this->get_data_for_lookup_table($id, self::TABLE);
            if (empty($row)) {
                continue;
            }
            $rows[$id] = $row;
            foreach (['min_price', 'max_price'] as $col) {
                if (isset($row[$col]) && is_numeric($row[$col])) {
                    $childPrices[] = (float)$row[$col];
                }
            }
        }

        $parent = $this->get_data_for_lookup_table($productId, self::TABLE);
        if (!empty($parent)) {
            if ($childPrices !== []) {
                $parent['min_price'] = wc_format_decimal(min($childPrices));
                $parent['max_price'] = wc_format_decimal(max($childPrices));
            }
            $rows = [$productId => $parent] + $rows;
        }

        return $this->writeRows($rows);
    }

    /**
     * Rebuild the lookup rows of every live engine product, in batches (WP-CLI).
     *
     * @param callable(int $done):void|null $progress
     * @return array{products:int, rows:int}
     */
    public function rebuildAll(int $batchSize = 100, ?callable $progress = null): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $batchSize = max(1, $batchSize);
        $lastId = 0;
        $result = ['products' => 0, 'rows' => 0];

        while (true) {
            $productIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$p}wpe_products WHERE id > %d AND deleted_at IS NULL ORDER BY id ASC LIMIT %d",
                $lastId,
                $batchSize
            )) ?: []);
            if ($productIds === []) {
                break;
            }

            $children = [];
            foreach ($wpdb->get_results(
                "SELECT id, product_id FROM {$p}wpe_variants WHERE deleted_at IS NULL AND product_id IN (" . \WPE\Support\Sql::intList($productIds) . ')',
                ARRAY_A
            ) ?: [] as $v) {
                $children[(int)$v['product_id']][] = (int)$v['id'];
            }

            foreach ($productIds as $productId) {
                $result['rows'] += $this->projectProduct($productId, $children[$productId] ?? []);
                $result['products']++;
                // Keep memory flat over large catalogs.
                wp_cache_delete($productId, 'post_meta');
                foreach ($children[$productId] ?? [] as $vid) {
                    wp_cache_delete($vid, 'post_meta');
                }
            }

            $lastId = (int)end($productIds);
            if ($progress) {
                $progress($result['products']);
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows product ID => lookup row
     */
    private function writeRows(array $rows): int
    {
        global $wpdb;

        $written = 0;
        foreach (array_chunk($rows, self::CHUNK, true) as $chunk) {
            $columns = array_keys(reset($chunk));
            $values = [];
            foreach ($chunk as $row) {
                $cells = [];
                foreach ($columns as $col) {
                    $value = $row[$col] ?? null;
                    $cells[] = $value === null ? 'NULL' : $wpdb->prepare('%s', (string)$value);
                }
                $values[] = '(' . implode(',', $cells) . ')';
            }

            $sql = "REPLACE INTO {$wpdb->wc_product_meta_lookup} (`" . implode('`,`', $columns) . '`) VALUES ' . implode(',', $values);
            if ($wpdb->query($sql) === false) {
                throw new \RuntimeException('wc_product_meta_lookup projection failed: ' . $wpdb->last_error);
            }

            foreach ($chunk as $id => $row) {
                wp_cache_set('lookup_table', $row, 'object_' . $id);
            }
            $written += count($chunk);
        }

        return $written;
    }
}
