<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

use WPE\Application\Variant\EffectivePriceResolver;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\ImageSurfaces;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Support\AuthorityResolver;

/**
 * Loads the product data feed items are built from, in batches (D01).
 *
 * Native and native_bridge products are read straight from the `wpe_*` tables: prices through
 * EffectivePriceResolver, availability through the same inventory rule as the catalog index, media
 * from product/variant media and assets. Postmeta is never read for them. Products whose authority
 * is legacy are read through WooCommerce CRUD. Permalinks come from the post shell (D02).
 *
 * The result is a format-neutral "source record" per product that FeedMapper turns into items.
 */
final class FeedItemSource
{
    private \wpdb $wpdb;
    private AssetService $assets;
    private string $p;

    /** @var array<int,array{name:string,parent:int}> term_id => name/parent, for category paths */
    private array $termCache = [];

    public function __construct(?\wpdb $wpdb = null, ?AssetService $assets = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->assets = $assets ?? new AssetService($this->wpdb, new MediaRepository($this->wpdb));
        $this->p = $this->wpdb->prefix;
    }

    /**
     * Product IDs after $afterId, in ID order, from post shells of type product.
     *
     * @return int[]
     */
    public function nextProductIds(int $afterId, int $limit): array
    {
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT ID FROM {$this->wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('auto-draft', 'trash') AND ID > %d ORDER BY ID ASC LIMIT %d",
            $afterId,
            $limit
        )) ?: []);
    }

    /** Number of product shells (the reconciliation and progress denominator). */
    public function countProducts(): int
    {
        return (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('auto-draft', 'trash')"
        );
    }

    /**
     * Load source records for products. Missing IDs (deleted products) are absent from the result.
     *
     * @param int[] $productIds
     * @param array{taxonomies?:string[],meta_keys?:string[]} $needs Extra taxonomies/meta keys the mapping reads
     * @return array<int,array> product_id => source record
     */
    public function load(array $productIds, array $needs = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        _prime_post_caches($ids, false, false);
        AuthorityResolver::primeObjects($ids, $this->wpdb);

        $native = [];
        $legacy = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'product') {
                continue;
            }
            if (AuthorityResolver::isNativeAuthorityValue(AuthorityResolver::resolveObjectAuthority($id, $this->wpdb))) {
                $native[] = $id;
            } else {
                $legacy[] = $id;
            }
        }

        $taxonomies = array_values(array_unique(array_merge(['product_cat', 'product_tag', 'product_brand'], (array)($needs['taxonomies'] ?? []))));
        $taxonomies = array_values(array_filter($taxonomies, 'taxonomy_exists'));
        $terms = $this->loadTerms($ids, $taxonomies);
        $metaKeys = array_values(array_unique(array_filter(array_map('strval', (array)($needs['meta_keys'] ?? [])))));

        $records = $this->loadNative($native, $metaKeys) + $this->loadLegacy($legacy, $metaKeys);
        foreach ($records as $id => &$rec) {
            $rec['terms'] = $terms[$id] ?? [];
            $catIds = array_map(static fn(array $t): int => $t['term_id'], $rec['terms']['product_cat'] ?? []);
            $rec['category_ids'] = $this->withAncestors($catIds);
            $rec['category_path'] = $this->deepestPath($catIds);
            $rec['deepest_category_chain'] = $this->deepestChain($catIds);
            $rec['permalink'] = (string)get_permalink($id);
        }
        unset($rec);

        ksort($records);
        return $records;
    }

    /* ------------------------------------------------------------------ */
    /* Native                                                             */
    /* ------------------------------------------------------------------ */

    private function loadNative(array $ids, array $metaKeys): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $now = gmdate('Y-m-d H:i:s.u');

        $products = $this->wpdb->get_results(
            "SELECT * FROM {$this->p}wpe_products WHERE id IN ({$in}) AND deleted_at IS NULL",
            ARRAY_A
        ) ?: [];
        if ($products === []) {
            return [];
        }

        $variants = $this->wpdb->get_results(
            "SELECT * FROM {$this->p}wpe_variants WHERE product_id IN ({$in}) AND deleted_at IS NULL ORDER BY product_id, position, id",
            ARRAY_A
        ) ?: [];

        $ownerIds = [];
        foreach ($products as $row) {
            $ownerIds[] = (int)($row['stock_owner_id'] ?: $row['id']);
        }
        foreach ($variants as $v) {
            $ownerIds[] = (int)($v['stock_owner_id'] ?: $v['id']);
        }
        $inventory = [];
        if ($ownerIds !== []) {
            foreach ($this->wpdb->get_results(
                "SELECT * FROM {$this->p}wpe_inventory WHERE owner_id IN (" . implode(',', array_unique($ownerIds)) . ')',
                ARRAY_A
            ) ?: [] as $inv) {
                $inventory[(int)$inv['owner_id']] = $inv;
            }
        }

        // Options and values per product.
        $options = [];
        foreach ($this->wpdb->get_results(
            "SELECT o.id, o.product_id, o.`key`, o.name, o.taxonomy, o.position, o.used_for_variation,
                    ov.id AS value_id, ov.value_key, ov.label, ov.position AS value_position
             FROM {$this->p}wpe_options o
             LEFT JOIN {$this->p}wpe_option_values ov ON ov.option_id = o.id
             WHERE o.product_id IN ({$in})
             ORDER BY o.product_id, o.position, o.id, ov.position, ov.id",
            ARRAY_A
        ) ?: [] as $r) {
            $pid = (int)$r['product_id'];
            $oid = (int)$r['id'];
            $options[$pid][$oid] ??= [
                'id'                 => $oid,
                'key'                => (string)$r['key'],
                'name'               => (string)$r['name'],
                'taxonomy'           => $r['taxonomy'],
                'used_for_variation' => (int)$r['used_for_variation'] === 1,
                'values'             => [],
            ];
            if ($r['value_id'] !== null) {
                $options[$pid][$oid]['values'][(int)$r['value_id']] = ['key' => (string)$r['value_key'], 'label' => (string)$r['label']];
            }
        }

        // Variant option values.
        $variantValues = [];
        if ($variants !== []) {
            $vin = implode(',', array_map(static fn(array $v): int => (int)$v['id'], $variants));
            foreach ($this->wpdb->get_results(
                "SELECT variant_id, option_id, value_id, is_any FROM {$this->p}wpe_variant_values WHERE variant_id IN ({$vin})",
                ARRAY_A
            ) ?: [] as $r) {
                $variantValues[(int)$r['variant_id']][(int)$r['option_id']] = (int)$r['is_any'] === 1 ? null : (int)$r['value_id'];
            }
        }

        $productMedia = $this->loadProductMedia($in);
        $variantMedia = $variants !== [] ? $this->loadVariantMedia(implode(',', array_map(static fn(array $v): int => (int)$v['id'], $variants))) : [];

        $meta = [];
        if ($metaKeys !== []) {
            $objIds = array_merge($ids, array_map(static fn(array $v): int => (int)$v['id'], $variants));
            $keysIn = implode(',', array_map(fn(string $k): string => $this->wpdb->prepare('%s', $k), $metaKeys));
            foreach ($this->wpdb->get_results(
                "SELECT object_id, meta_key, meta_value FROM {$this->p}wpe_meta WHERE object_id IN (" . implode(',', $objIds) . ") AND meta_key IN ({$keysIn})",
                ARRAY_A
            ) ?: [] as $m) {
                $meta[(int)$m['object_id']][(string)$m['meta_key']] ??= (string)maybe_unserialize($m['meta_value']);
            }
        }

        $variantsByProduct = [];
        foreach ($variants as $v) {
            $variantsByProduct[(int)$v['product_id']][] = $v;
        }

        $records = [];
        foreach ($products as $row) {
            $pid = (int)$row['id'];
            $extra = $this->json($row['extra_props']);
            $productOptions = $options[$pid] ?? [];

            $productLevelValues = [];
            foreach ($productOptions as $opt) {
                if (!$opt['used_for_variation']) {
                    $productLevelValues[$opt['key']] = array_values(array_map(static fn(array $v): string => $v['label'], $opt['values']));
                }
            }

            $record = [
                'product_id'        => $pid,
                'authority'         => 'native',
                'type'              => (string)$row['type'],
                'status'            => (string)$row['status'],
                'visibility'        => (string)$row['catalog_visibility'],
                'title'             => (string)$row['title'],
                'description'       => (string)$row['description'],
                'short_description' => (string)$row['short_description'],
                'sku'               => $row['sku'] !== null ? (string)$row['sku'] : '',
                'gtin'              => (string)($extra['global_unique_id'] ?? ''),
                'weight'            => $row['weight'] !== null ? (float)$row['weight'] : null,
                'options'           => array_values($productOptions),
                'product_values'    => $productLevelValues,
                'meta'              => $meta[$pid] ?? [],
                'images'            => $productMedia[$pid] ?? ['featured' => null, 'gallery' => []],
                'price'             => null,
                'stock'             => null,
                'variants'          => [],
            ];

            if ($record['type'] === 'simple') {
                $record['price'] = EffectivePriceResolver::resolve(array_merge($row, ['extra_props' => $extra]), null, $now)
                    + ['from' => $row['sale_from'] ?? ($extra['date_on_sale_from'] ?? null), 'to' => $row['sale_to'] ?? ($extra['date_on_sale_to'] ?? null)];
                $record['stock'] = $this->availability($inventory[(int)($row['stock_owner_id'] ?: $pid)] ?? null);
            }

            foreach ($variantsByProduct[$pid] ?? [] as $v) {
                $vid = (int)$v['id'];
                $vExtra = $this->json($v['extra_props']);
                $values = [];
                foreach ($variantValues[$vid] ?? [] as $optId => $valueId) {
                    $opt = $productOptions[$optId] ?? null;
                    if (!$opt || $valueId === null || !isset($opt['values'][$valueId])) {
                        continue;
                    }
                    $values[$opt['key']] = [
                        'option_name' => $opt['name'],
                        'key'         => $opt['values'][$valueId]['key'],
                        'label'       => $opt['values'][$valueId]['label'],
                    ];
                }
                $record['variants'][] = [
                    'id'          => $vid,
                    'status'      => (string)$v['status'],
                    'sku'         => $v['sku'] !== null ? (string)$v['sku'] : '',
                    'gtin'        => (string)($vExtra['global_unique_id'] ?? ''),
                    'description' => (string)$v['description'],
                    'weight'      => $v['weight'] !== null ? (float)$v['weight'] : null,
                    'price'       => EffectivePriceResolver::resolve($v, null, $now) + ['from' => $v['sale_from'] ?? ($vExtra['date_on_sale_from'] ?? null), 'to' => $v['sale_to'] ?? ($vExtra['date_on_sale_to'] ?? null)],
                    'stock'       => $this->availability($inventory[(int)($v['stock_owner_id'] ?: $vid)] ?? null),
                    'values'      => $values,
                    'meta'        => $meta[$vid] ?? [],
                    'images'      => $variantMedia[$vid] ?? ['primary' => null, 'gallery' => []],
                    'link_args'   => $this->linkArgs($values),
                ];
            }

            $records[$pid] = $record;
        }

        return $records;
    }

    /**
     * Availability with the same rule as the catalog index (CatalogProjector), plus backorder.
     *
     * @return array{availability:string,quantity:?float}
     */
    private function availability(?array $inv): array
    {
        $manage = !empty($inv['manage_stock']);
        $qty = $inv && $inv['quantity'] !== null ? (float)$inv['quantity'] : null;
        $status = (string)($inv['stock_status'] ?? 'instock');
        $backorders = (string)($inv['backorders'] ?? 'no');

        if ($status === 'onbackorder') {
            return ['availability' => 'backorder', 'quantity' => $qty];
        }
        if ($status !== 'instock') {
            return ['availability' => 'out_of_stock', 'quantity' => $qty];
        }
        if ($manage && ($qty ?? 0.0) <= 0) {
            return ['availability' => $backorders !== 'no' ? 'backorder' : 'out_of_stock', 'quantity' => $qty];
        }
        return ['availability' => 'in_stock', 'quantity' => $qty];
    }

    /** @return array<int,array{featured:?string,gallery:string[]}> */
    private function loadProductMedia(string $in): array
    {
        $out = [];
        foreach ($this->wpdb->get_results(
            "SELECT pm.product_id, pm.role, pm.position, a.source_url, a.provider, a.health
             FROM {$this->p}wpe_product_media pm
             JOIN {$this->p}wpe_assets a ON a.id = pm.asset_id
             WHERE pm.product_id IN ({$in})
             ORDER BY pm.product_id, pm.role, pm.position",
            ARRAY_A
        ) ?: [] as $r) {
            $pid = (int)$r['product_id'];
            $out[$pid] ??= ['featured' => null, 'gallery' => []];
            $url = $this->publicImageUrl($r);
            if ($url === null) {
                continue;
            }
            if ($r['role'] === 'featured') {
                $out[$pid]['featured'] ??= $url;
            } elseif ($r['role'] === 'gallery') {
                $out[$pid]['gallery'][] = $url;
            }
        }
        return $out;
    }

    /** @return array<int,array{primary:?string,gallery:string[]}> */
    private function loadVariantMedia(string $vin): array
    {
        $out = [];
        foreach ($this->wpdb->get_results(
            "SELECT vm.variant_id, vm.is_primary, vm.position, a.source_url, a.provider, a.health
             FROM {$this->p}wpe_variant_media vm
             JOIN {$this->p}wpe_assets a ON a.id = vm.asset_id
             WHERE vm.variant_id IN ({$vin})
             ORDER BY vm.variant_id, vm.position",
            ARRAY_A
        ) ?: [] as $r) {
            $vid = (int)$r['variant_id'];
            $out[$vid] ??= ['primary' => null, 'gallery' => []];
            $url = $this->publicImageUrl($r);
            if ($url === null) {
                continue;
            }
            if ((int)$r['is_primary'] === 1 && $out[$vid]['primary'] === null) {
                $out[$vid]['primary'] = $url;
            } else {
                $out[$vid]['gallery'][] = $url;
            }
        }
        return $out;
    }

    /**
     * Stable public URL of an asset: provider export URL without signatures/tokens. Broken assets
     * and non-HTTP URLs are skipped.
     */
    private function publicImageUrl(array $asset): ?string
    {
        if (($asset['health'] ?? 'healthy') === 'broken') {
            return null;
        }
        $url = (string)$asset['source_url'];
        $url = $this->assets->getProvider((string)($asset['provider'] ?? 'default'))->getStableExportUrl($url);
        $url = ImageSurfaces::sanitizePublicUrl($url);
        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    /* ------------------------------------------------------------------ */
    /* Legacy (WooCommerce CRUD)                                          */
    /* ------------------------------------------------------------------ */

    private function loadLegacy(array $ids, array $metaKeys): array
    {
        $records = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $type = $product->get_type();
            $record = [
                'product_id'        => $id,
                'authority'         => 'legacy',
                'type'              => $type,
                'status'            => $product->get_status(),
                'visibility'        => $product->get_catalog_visibility(),
                'title'             => $product->get_name(),
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'sku'               => (string)$product->get_sku(),
                'gtin'              => method_exists($product, 'get_global_unique_id') ? (string)$product->get_global_unique_id() : '',
                'weight'            => $product->get_weight() !== '' ? (float)$product->get_weight() : null,
                'options'           => [],
                'product_values'    => [],
                'meta'              => $this->legacyMeta($id, $metaKeys),
                'images'            => [
                    'featured' => $this->attachmentUrl((int)$product->get_image_id()),
                    'gallery'  => array_values(array_filter(array_map(fn($aid) => $this->attachmentUrl((int)$aid), $product->get_gallery_image_ids()))),
                ],
                'price'             => null,
                'stock'             => null,
                'variants'          => [],
            ];

            foreach ($product->get_attributes() as $attr) {
                if (!$attr instanceof \WC_Product_Attribute) {
                    continue;
                }
                $key = $attr->is_taxonomy() ? preg_replace('/^pa_/', '', $attr->get_name()) : sanitize_title($attr->get_name());
                $labels = $attr->is_taxonomy()
                    ? array_values(array_map(static fn($t) => $t->name, (array)$attr->get_terms()))
                    : array_values(array_map('strval', $attr->get_options()));
                $record['options'][] = [
                    'id' => 0, 'key' => (string)$key, 'name' => wc_attribute_label($attr->get_name()),
                    'taxonomy' => $attr->is_taxonomy() ? $attr->get_name() : null,
                    'used_for_variation' => $attr->get_variation(), 'values' => [],
                ];
                if (!$attr->get_variation()) {
                    $record['product_values'][(string)$key] = $labels;
                }
            }

            if ($type === 'simple') {
                $record['price'] = $this->legacyPrice($product);
                $record['stock'] = $this->legacyStock($product);
            } elseif ($type === 'variable') {
                foreach ($product->get_children() as $childId) {
                    $variation = wc_get_product($childId);
                    if (!$variation instanceof \WC_Product_Variation) {
                        continue;
                    }
                    $values = [];
                    $linkArgs = [];
                    foreach ($variation->get_attributes() as $attrName => $slug) {
                        if ($slug === '' || $slug === null) {
                            continue;
                        }
                        $taxonomy = taxonomy_exists((string)$attrName) ? (string)$attrName : null;
                        $label = $taxonomy ? (get_term_by('slug', (string)$slug, $taxonomy)->name ?? (string)$slug) : (string)$slug;
                        $key = $taxonomy ? preg_replace('/^pa_/', '', $taxonomy) : sanitize_title((string)$attrName);
                        $values[(string)$key] = ['option_name' => wc_attribute_label((string)$attrName, $product), 'key' => (string)$slug, 'label' => (string)$label];
                        $linkArgs['attribute_' . sanitize_title((string)$attrName)] = (string)$slug;
                    }
                    $record['variants'][] = [
                        'id'          => (int)$childId,
                        'status'      => $variation->get_status() === 'publish' ? 'publish' : 'private',
                        'sku'         => (string)$variation->get_sku('edit'),
                        'gtin'        => method_exists($variation, 'get_global_unique_id') ? (string)$variation->get_global_unique_id() : '',
                        'description' => $variation->get_description(),
                        'weight'      => $variation->get_weight('edit') !== '' ? (float)$variation->get_weight('edit') : null,
                        'price'       => $this->legacyPrice($variation),
                        'stock'       => $this->legacyStock($variation),
                        'values'      => $values,
                        'meta'        => $this->legacyMeta((int)$childId, $metaKeys),
                        'images'      => ['primary' => $this->attachmentUrl((int)$variation->get_image_id('edit')), 'gallery' => []],
                        'link_args'   => $linkArgs,
                    ];
                }
            }

            $records[$id] = $record;
        }
        return $records;
    }

    private function legacyPrice(\WC_Product $product): array
    {
        $from = $product->get_date_on_sale_from('edit');
        $to = $product->get_date_on_sale_to('edit');
        $row = [
            'regular_price' => $product->get_regular_price('edit') !== '' ? $product->get_regular_price('edit') : null,
            'sale_price'    => $product->get_sale_price('edit') !== '' ? $product->get_sale_price('edit') : null,
            'sale_from'     => $from ? gmdate('Y-m-d H:i:s', $from->getTimestamp()) : null,
            'sale_to'       => $to ? gmdate('Y-m-d H:i:s', $to->getTimestamp()) : null,
        ];
        return EffectivePriceResolver::resolve($row) + ['from' => $row['sale_from'], 'to' => $row['sale_to']];
    }

    private function legacyStock(\WC_Product $product): array
    {
        return $this->availability([
            'manage_stock' => $product->get_manage_stock() ? 1 : 0,
            'quantity'     => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'backorders'   => $product->get_backorders(),
        ]);
    }

    private function legacyMeta(int $id, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = get_post_meta($id, $k, true);
            if ($v !== '' && is_scalar($v)) {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }

    private function attachmentUrl(int $attachmentId): ?string
    {
        if ($attachmentId <= 0) {
            return null;
        }
        $url = (string)wp_get_attachment_url($attachmentId);
        $url = ImageSurfaces::sanitizePublicUrl($url);
        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    /* ------------------------------------------------------------------ */
    /* Terms                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array<string,array<int,array{term_id:int,name:string,slug:string}>>> product => taxonomy => terms
     */
    private function loadTerms(array $ids, array $taxonomies): array
    {
        if ($taxonomies === []) {
            return [];
        }
        $terms = wp_get_object_terms($ids, $taxonomies, ['fields' => 'all_with_object_id', 'update_term_meta_cache' => false]);
        if (is_wp_error($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $t) {
            $out[(int)$t->object_id][$t->taxonomy][] = ['term_id' => (int)$t->term_id, 'name' => html_entity_decode((string)$t->name), 'slug' => (string)$t->slug];
            if ($t->taxonomy === 'product_cat') {
                $this->termCache[(int)$t->term_id] = ['name' => html_entity_decode((string)$t->name), 'parent' => (int)$t->parent];
            }
        }
        return $out;
    }

    private function term(int $termId): ?array
    {
        if (!isset($this->termCache[$termId])) {
            $t = get_term($termId, 'product_cat');
            if (!$t || is_wp_error($t)) {
                return null;
            }
            $this->termCache[$termId] = ['name' => html_entity_decode((string)$t->name), 'parent' => (int)$t->parent];
        }
        return $this->termCache[$termId];
    }

    /** @return int[] category IDs with every ancestor */
    private function withAncestors(array $catIds): array
    {
        $all = [];
        foreach ($catIds as $id) {
            $guard = 0;
            while ($id > 0 && !isset($all[$id]) && $guard++ < 20) {
                $all[$id] = true;
                $id = (int)($this->term($id)['parent'] ?? 0);
            }
        }
        return array_map('intval', array_keys($all));
    }

    /** @return int[] root → leaf chain of the deepest assigned category */
    private function deepestChain(array $catIds): array
    {
        $best = [];
        foreach ($catIds as $id) {
            $chain = [];
            $guard = 0;
            while ($id > 0 && $guard++ < 20) {
                array_unshift($chain, $id);
                $id = (int)($this->term($id)['parent'] ?? 0);
            }
            if (count($chain) > count($best) || (count($chain) === count($best) && $chain && $best && end($chain) < end($best))) {
                $best = $chain;
            }
        }
        return $best;
    }

    private function deepestPath(array $catIds): string
    {
        $names = array_filter(array_map(fn(int $id): string => (string)($this->term($id)['name'] ?? ''), $this->deepestChain($catIds)));
        return implode(' > ', $names);
    }

    /** @param array<string,array{key:string}> $values */
    private function linkArgs(array $values): array
    {
        $args = [];
        foreach ($values as $optKey => $v) {
            $args['attribute_' . sanitize_title((string)$optKey)] = (string)$v['key'];
        }
        return $args;
    }

    private function json(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $d = json_decode((string)$raw, true);
        return is_array($d) ? $d : [];
    }
}
