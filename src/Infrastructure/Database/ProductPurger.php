<?php

namespace WPE\Infrastructure\Database;

use RuntimeException;

/**
 * ProductPurger — set-based removal of every native row owned by permanently deleted products (P039).
 *
 * One call removes, for the given products and all their variants: the aggregate rows, options,
 * option values, variant values, terms, meta, links (both directions), downloads, media links,
 * SKU registry, inventory, object state, external IDs, catalog rows of every generation and the
 * Shopify import fingerprints, and retires their queued outbox events.
 *
 * It does not open a transaction (the caller decides the unit of work), does not touch WordPress
 * post shells, and keeps order-lifecycle rows (`wpe_stock_operations`, `wpe_reservation_bridge`,
 * 08 §9) and media assets (shared, cleaned by the media tools).
 */
class ProductPurger
{
    private \wpdb $wpdb;
    private OutboxWorker $outboxWorker;
    private string $prefix;

    public function __construct(?\wpdb $wpdb = null, ?OutboxWorker $outboxWorker = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->outboxWorker = $outboxWorker ?? new OutboxWorker($this->wpdb);
        $this->prefix = $this->wpdb->prefix;
    }

    /**
     * Variant IDs of the given products (native rows).
     *
     * @param int[] $productIds
     * @return int[]
     */
    public function variantIds(array $productIds): array
    {
        $in = $this->idList($productIds);
        if ($in === '') {
            return [];
        }
        return array_map('intval', $this->wpdb->get_col(
            "SELECT id FROM {$this->prefix}wpe_variants WHERE product_id IN ({$in})"
        ) ?: []);
    }

    /**
     * Remove all native rows of the given products and their variants.
     *
     * @param int[] $productIds
     * @param int[]|null $variantIds Pre-fetched variant IDs (null: looked up)
     * @return array{products:int, variants:int, rows:int, outbox_retired:int}
     */
    public function purge(array $productIds, ?array $variantIds = null): array
    {
        $productIds = $this->ids($productIds);
        if ($productIds === []) {
            return ['products' => 0, 'variants' => 0, 'rows' => 0, 'outbox_retired' => 0];
        }
        $variantIds = $this->ids($variantIds ?? $this->variantIds($productIds));
        $owners = $this->ids(array_merge($productIds, $variantIds));

        $inP = $this->idList($productIds);
        $inO = $this->idList($owners);
        $p = $this->prefix;

        $optionIds = array_map('intval', $this->wpdb->get_col(
            "SELECT id FROM {$p}wpe_options WHERE product_id IN ({$inP})"
        ) ?: []);
        $inOpt = $this->idList($optionIds);

        $statements = [];
        if ($variantIds !== []) {
            $inV = $this->idList($variantIds);
            $statements[] = "DELETE FROM {$p}wpe_variant_values WHERE variant_id IN ({$inV})";
            $statements[] = "DELETE FROM {$p}wpe_variant_media WHERE variant_id IN ({$inV})";
        }
        if ($inOpt !== '') {
            // Also catches value rows left behind by variants removed earlier.
            $statements[] = "DELETE FROM {$p}wpe_variant_values WHERE option_id IN ({$inOpt})";
            $statements[] = "DELETE FROM {$p}wpe_option_values WHERE option_id IN ({$inOpt})";
            $statements[] = "DELETE FROM {$p}wpe_options WHERE id IN ({$inOpt})";
        }
        $statements[] = "DELETE FROM {$p}wpe_catalog_facets WHERE product_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_catalog WHERE product_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_product_terms WHERE product_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_product_media WHERE product_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_product_links WHERE product_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_product_links WHERE target_id IN ({$inO})";
        $statements[] = "DELETE FROM {$p}wpe_downloads WHERE object_id IN ({$inO})";
        $statements[] = "DELETE FROM {$p}wpe_meta WHERE object_id IN ({$inO})";
        $statements[] = "DELETE FROM {$p}wpe_sku_registry WHERE object_id IN ({$inO})";
        $statements[] = "DELETE FROM {$p}wpe_inventory WHERE owner_id IN ({$inO})";
        $statements[] = "DELETE FROM {$p}wpe_object_state WHERE object_id IN ({$inO})";
        $statements[] = "DELETE FROM {$p}wpe_object_state WHERE parent_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_external_ids WHERE object_id IN ({$inO})";
        $fpKeys = implode(',', array_map(static fn(int $id): string => "'shopify_import_fp:{$id}'", $productIds));
        $statements[] = "DELETE FROM {$p}wpe_control WHERE `key` IN ({$fpKeys})";
        $statements[] = "DELETE FROM {$p}wpe_variants WHERE product_id IN ({$inP})";
        $statements[] = "DELETE FROM {$p}wpe_products WHERE id IN ({$inP})";

        $rows = 0;
        foreach ($statements as $sql) {
            $res = $this->wpdb->query($sql);
            if ($res === false) {
                throw new RuntimeException('Product purge failed: ' . $this->wpdb->last_error);
            }
            $rows += (int)$res;
        }

        // Nothing is left to project for a purged aggregate; retire its queued events.
        $retired = $this->outboxWorker->retireAggregateEvents($owners);

        return [
            'products'       => count($productIds),
            'variants'       => count($variantIds),
            'rows'           => $rows,
            'outbox_retired' => $retired,
        ];
    }

    /** @return int[] */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    private function idList(array $ids): string
    {
        return implode(',', $this->ids($ids));
    }
}
