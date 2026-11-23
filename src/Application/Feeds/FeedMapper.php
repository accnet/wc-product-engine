<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * Turns FeedItemSource records into feed items: a normalized payload (attribute => value) plus
 * issue codes, eligibility and the next instant a scheduled price change makes the item stale.
 *
 * The payload is format-neutral; FeedWriter renders it as Google RSS or TSV.
 */
final class FeedMapper
{
    /** Attribute order of every payload (and of TSV columns). */
    public const ATTRIBUTE_ORDER = [
        'id', 'item_group_id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
        'availability', 'price', 'sale_price', 'sale_price_effective_date', 'condition', 'brand', 'gtin',
        'mpn', 'identifier_exists', 'google_product_category', 'product_type', 'color', 'size', 'material',
        'pattern', 'gender', 'age_group', 'shipping_weight', 'shipping', 'custom_label_0', 'custom_label_1',
        'custom_label_2', 'custom_label_3', 'custom_label_4',
    ];

    /** Attributes whose payload value is a list. */
    public const LIST_ATTRIBUTES = ['additional_image_link', 'shipping'];

    private array $feed;
    private array $config;
    private string $currency;
    private int $decimals;
    private string $weightUnit;

    public function __construct(array $feed)
    {
        $this->feed = $feed;
        $this->config = FeedConfig::mergeDefaults((array)($feed['config'] ?? []));
        $this->currency = FeedConfig::storeCurrency();
        $this->decimals = function_exists('wc_get_price_decimals') ? (int)wc_get_price_decimals() : 2;
        $unit = (string)get_option('woocommerce_weight_unit', 'kg');
        $this->weightUnit = $unit === 'lbs' ? 'lb' : $unit;
    }

    /**
     * Taxonomies and meta keys the mapping reads beyond the defaults.
     *
     * @return array{taxonomies:string[],meta_keys:string[]}
     */
    public static function needs(array $config): array
    {
        $config = FeedConfig::mergeDefaults($config);
        $tax = [];
        $meta = [];
        foreach ($config['mapping'] as $rule) {
            if ($rule['source'] === 'taxonomy') {
                $tax[] = $rule['value'];
            } elseif ($rule['source'] === 'meta') {
                $meta[] = $rule['value'];
            }
        }
        return ['taxonomies' => array_values(array_unique($tax)), 'meta_keys' => array_values(array_unique($meta))];
    }

    /** Columns of a TSV feed with this configuration: every attribute the mapping can emit. */
    public function columns(): array
    {
        $cols = [];
        foreach (self::ATTRIBUTE_ORDER as $attr) {
            if (isset(FeedConfig::MAPPED_ATTRIBUTES[$attr]) && $this->config['mapping'][$attr]['source'] === 'none') {
                continue;
            }
            if ($attr === 'item_group_id' && $this->config['variation_mode'] !== 'variants') {
                continue;
            }
            if ($attr === 'shipping' && $this->config['shipping']['mode'] !== 'flat') {
                continue;
            }
            if ($attr === 'shipping_weight' && empty($this->config['shipping']['export_weight'])) {
                continue;
            }
            $cols[] = $attr;
        }
        return $cols;
    }

    /**
     * Items for one product. A record of null means the product is gone: no items.
     *
     * @return array<int,array{product_id:int,variant_id:int,item_id:string,payload:array,issues:string[],eligible:bool,next_change:?string}>
     */
    public function map(int $productId, ?array $record): array
    {
        if ($record === null) {
            return [];
        }

        $productExclusions = $this->productExclusions($record);
        // Products the feed's filters leave out (status, visibility, category) store no items, so a
        // narrow feed on a large catalog keeps a small item store.
        if (array_intersect($productExclusions, ['excluded_status', 'excluded_visibility', 'excluded_category']) !== []) {
            return [];
        }
        $type = $record['type'];

        if ($type === 'simple' || ($type === 'variable' && $this->config['variation_mode'] === 'parent')) {
            return [$this->finish($this->productItem($record), $productExclusions)];
        }
        if ($type !== 'variable') {
            return [$this->finish($this->placeholder($record, 'unsupported_type'), [])];
        }

        $items = [];
        foreach ($record['variants'] as $variant) {
            $exclusions = $productExclusions;
            if ($variant['status'] !== 'publish') {
                $exclusions[] = 'excluded_variation_status';
            }
            $items[] = $this->finish($this->variantItem($record, $variant), $exclusions);
        }
        return $items;
    }

    private function productItem(array $r): array
    {
        $variants = array_values(array_filter($r['variants'], static fn(array $v): bool => $v['status'] === 'publish'));
        $price = $r['price'];
        $stock = $r['stock'];
        $nextChange = $price['next_change'] ?? null;

        if ($r['type'] === 'variable') {
            // One item per parent: lowest active price among priced variations, in stock if any is.
            $price = null;
            $available = [];
            foreach ($variants as $v) {
                $nc = $v['price']['next_change'] ?? null;
                if ($nc !== null && ($nextChange === null || $nc < $nextChange)) {
                    $nextChange = $nc;
                }
                if (($v['price']['active_price'] ?? null) === null) {
                    continue;
                }
                $available[] = $v['stock']['availability'];
                if ($price === null || (float)$v['price']['active_price'] < (float)$price['active_price']) {
                    $price = $v['price'];
                }
            }
            $stock = ['availability' => in_array('in_stock', $available, true) ? 'in_stock' : (in_array('backorder', $available, true) ? 'backorder' : 'out_of_stock')];
        }

        $issues = [];
        $payload = [
            'id'    => $this->itemId((string)$r['sku'], (int)$r['product_id'], $issues),
            'title' => $this->truncate($r['title'], FeedValidator::MAX_TITLE, 'title_truncated', $issues),
            'description' => $this->description([$r['description'], $r['short_description']], $r['title'], $issues),
            'link'  => $r['permalink'],
            'image_link' => (string)($r['images']['featured'] ?? ''),
            'additional_image_link' => array_slice($r['images']['gallery'], 0, (int)$this->config['images']['max_additional']),
            'availability' => (string)($stock['availability'] ?? 'out_of_stock'),
        ];
        $payload += $this->priceFields($price);
        $payload += $this->commonFields($r, null, $issues);
        $payload += $this->shippingFields($r['weight']);

        return [
            'product_id'  => (int)$r['product_id'],
            'variant_id'  => 0,
            'payload'     => $payload,
            'issues'      => $issues,
            'next_change' => $nextChange,
            'record'      => $r,
        ];
    }

    private function variantItem(array $r, array $v): array
    {
        $issues = [];
        $title = $r['title'];
        if (!empty($this->config['title']['append_variant_values']) && $v['values'] !== []) {
            $title .= ' - ' . implode(' / ', array_map(static fn(array $x): string => $x['label'], $v['values']));
        }

        // Variation primary image first, else the product's featured image; additional images are
        // the variation gallery, then the product's featured (when not primary) and gallery.
        $image = $v['images']['primary'] ?? null;
        $extra = (array)($v['images']['gallery'] ?? []);
        if ($image === null) {
            $image = $r['images']['featured'] ?? null;
        } elseif (!empty($r['images']['featured'])) {
            $extra[] = $r['images']['featured'];
        }
        $extra = array_merge($extra, (array)$r['images']['gallery']);
        $extra = array_values(array_unique(array_filter($extra, static fn($u) => $u !== null && $u !== $image)));

        $payload = [
            'id'            => $this->itemId((string)$v['sku'], (int)$v['id'], $issues),
            'item_group_id' => $r['sku'] !== '' && strlen($r['sku']) <= FeedValidator::MAX_ID ? $r['sku'] : 'wc_' . $r['product_id'],
            'title'         => $this->truncate($title, FeedValidator::MAX_TITLE, 'title_truncated', $issues),
            'description'   => $this->description([$v['description'], $r['description'], $r['short_description']], $r['title'], $issues),
            'link'          => $v['link_args'] !== [] ? add_query_arg(array_map('rawurlencode', $v['link_args']), $r['permalink']) : $r['permalink'],
            'image_link'    => (string)$image,
            'additional_image_link' => array_slice($extra, 0, (int)$this->config['images']['max_additional']),
            'availability'  => (string)$v['stock']['availability'],
        ];
        $payload += $this->priceFields($v['price']);
        $payload += $this->commonFields($r, $v, $issues);
        $payload += $this->shippingFields($v['weight'] ?? $r['weight']);

        return [
            'product_id'  => (int)$r['product_id'],
            'variant_id'  => (int)$v['id'],
            'payload'     => $payload,
            'issues'      => $issues,
            'next_change' => $v['price']['next_change'] ?? null,
            'record'      => $r,
        ];
    }

    private function placeholder(array $r, string $issue): array
    {
        return [
            'product_id'  => (int)$r['product_id'],
            'variant_id'  => 0,
            'payload'     => ['id' => $r['sku'] !== '' ? $r['sku'] : 'wc_' . $r['product_id'], 'title' => $r['title']],
            'issues'      => [$issue],
            'next_change' => null,
            'record'      => $r,
        ];
    }

    /**
     * Order payload keys, drop empty values, validate and set eligibility.
     */
    private function finish(array $item, array $exclusions): array
    {
        $ordered = [];
        foreach (self::ATTRIBUTE_ORDER as $attr) {
            if (!array_key_exists($attr, $item['payload'])) {
                continue;
            }
            $val = $item['payload'][$attr];
            if ($val === null || $val === '' || $val === []) {
                continue;
            }
            $ordered[$attr] = $val;
        }

        $issues = $item['issues'];
        if (!in_array('unsupported_type', $issues, true)) {
            $issues = array_merge($issues, FeedValidator::validate($ordered));
        }
        $issues = array_merge($issues, $exclusions);
        if (!empty($this->config['filters']['require_image']) && in_array('missing_image', $issues, true)) {
            $issues = array_values(array_diff($issues, ['missing_image']));
            $issues[] = 'excluded_no_image';
        }
        if (empty($this->config['filters']['include_out_of_stock']) && ($ordered['availability'] ?? '') === 'out_of_stock') {
            $issues[] = 'excluded_out_of_stock';
        }
        $issues = array_values(array_unique($issues));
        sort($issues);

        return [
            'product_id'  => $item['product_id'],
            'variant_id'  => $item['variant_id'],
            'item_id'     => mb_substr((string)($ordered['id'] ?? ('wc_' . ($item['variant_id'] ?: $item['product_id']))), 0, 100),
            'payload'     => $ordered,
            'issues'      => $issues,
            'eligible'    => FeedValidator::isEligible($issues),
            'next_change' => $item['next_change'],
        ];
    }

    /** @return string[] */
    private function productExclusions(array $r): array
    {
        $out = [];
        if ($r['status'] !== 'publish') {
            $out[] = 'excluded_status';
        }
        if (in_array($r['visibility'], ['hidden'], true)) {
            $out[] = 'excluded_visibility';
        }
        if ($r['permalink'] === '' || $r['permalink'] === false) {
            $out[] = 'missing_shell';
        }
        $cats = $r['category_ids'];
        $include = $this->config['filters']['include_categories'];
        $exclude = $this->config['filters']['exclude_categories'];
        if (($include !== [] && array_intersect($include, $cats) === []) || ($exclude !== [] && array_intersect($exclude, $cats) !== [])) {
            $out[] = 'excluded_category';
        }
        return $out;
    }

    private function priceFields(?array $price): array
    {
        if ($price === null || ($price['active_price'] ?? null) === null) {
            return [];
        }
        $regular = $price['regular'] ?? null;
        $out = ['price' => $this->money($regular !== null ? (float)$regular : (float)$price['active_price'])];
        if (!empty($this->config['price']['include_sale']) && !empty($price['on_sale']) && $price['sale'] !== null) {
            $out['sale_price'] = $this->money((float)$price['sale']);
            $from = $this->timestamp($price['from'] ?? null);
            $to = $this->timestamp($price['to'] ?? null);
            if ($from !== null && $to !== null && $to > $from) {
                $out['sale_price_effective_date'] = gmdate('Y-m-d\TH:i:s\Z', $from) . '/' . gmdate('Y-m-d\TH:i:s\Z', $to);
            }
        }
        return $out;
    }

    private function commonFields(array $r, ?array $v, array &$issues): array
    {
        $out = [];
        foreach (FeedConfig::MAPPED_ATTRIBUTES as $attr => $_) {
            $out[$attr] = $this->resolveMapped($attr, $this->config['mapping'][$attr], $r, $v);
        }

        $out['condition'] = in_array($out['condition'], FeedConfig::CONDITIONS, true) ? $out['condition'] : 'new';
        if (($out['gtin'] ?? '') !== '') {
            $out['gtin'] = preg_replace('/\D/', '', (string)$out['gtin']);
        }
        $hasGtin = ($out['gtin'] ?? '') !== '';
        $hasMpnBrand = ($out['mpn'] ?? '') !== '' && ($out['brand'] ?? '') !== '';
        if (!$hasGtin && !$hasMpnBrand) {
            if (!empty($this->config['identifier_exists_fallback'])) {
                $out['identifier_exists'] = 'no';
                $issues[] = 'identifier_exists_no';
            } else {
                $issues[] = 'missing_identifier';
            }
        }
        foreach ($out as $k => $val) {
            if (is_string($val)) {
                $out[$k] = mb_substr(trim($val), 0, $k === 'product_type' || $k === 'google_product_category' ? 750 : 150);
            }
        }
        return $out;
    }

    private function resolveMapped(string $attr, array $rule, array $r, ?array $v): ?string
    {
        $value = (string)$rule['value'];
        switch ($rule['source']) {
            case 'none':
                return null;
            case 'static':
                return $value;
            case 'field':
                return match ($value) {
                    'sku'               => $v !== null && $v['sku'] !== '' ? $v['sku'] : $r['sku'],
                    'parent_sku'        => $r['sku'],
                    'title'             => $r['title'],
                    'short_description' => $this->plain($r['short_description']),
                    'product_id'        => (string)$r['product_id'],
                    default             => null,
                };
            case 'taxonomy':
                $names = array_map(static fn(array $t): string => $t['name'], $r['terms'][$value] ?? []);
                if ($value === 'product_cat' && $attr === 'product_type') {
                    return $r['category_path'];
                }
                return $names !== [] ? implode(', ', $names) : null;
            case 'option':
                return $this->optionValue($value, $r, $v);
            case 'meta':
                return ($v['meta'][$value] ?? '') !== '' ? $v['meta'][$value] : (($r['meta'][$value] ?? '') !== '' ? $r['meta'][$value] : null);
        }

        // default source
        switch ($attr) {
            case 'brand':
                $brands = array_map(static fn(array $t): string => $t['name'], $r['terms']['product_brand'] ?? []);
                return $brands !== [] ? implode(', ', $brands) : $this->optionValue('brand', $r, $v);
            case 'gtin':
                return $v !== null && $v['gtin'] !== '' ? $v['gtin'] : ($r['gtin'] !== '' ? $r['gtin'] : null);
            case 'mpn':
                return $v !== null && $v['sku'] !== '' ? $v['sku'] : ($r['sku'] !== '' ? $r['sku'] : null);
            case 'condition':
                return 'new';
            case 'google_product_category':
                foreach (array_reverse($r['deepest_category_chain']) as $termId) {
                    if (isset($this->config['category_map'][(string)$termId])) {
                        return $this->config['category_map'][(string)$termId];
                    }
                }
                return null;
            case 'product_type':
                return $r['category_path'] !== '' ? $r['category_path'] : null;
            case 'color':
                return $this->optionValue('color', $r, $v) ?? $this->optionValue('colour', $r, $v);
            case 'size':
            case 'material':
            case 'pattern':
                return $this->optionValue($attr, $r, $v);
        }
        return null;
    }

    /**
     * Value of an option for an item: the variation's value, else the product-level option labels.
     * Matches the option key or the slug of its name.
     */
    private function optionValue(string $key, array $r, ?array $v): ?string
    {
        $slug = sanitize_title($key);
        if ($v !== null) {
            foreach ($v['values'] as $optKey => $val) {
                if (sanitize_title((string)$optKey) === $slug || sanitize_title((string)$val['option_name']) === $slug) {
                    return $val['label'];
                }
            }
        }
        foreach ($r['product_values'] as $optKey => $labels) {
            if (sanitize_title((string)$optKey) === $slug && $labels !== []) {
                return implode(', ', $labels);
            }
        }
        foreach ($r['options'] as $opt) {
            if (sanitize_title((string)$opt['name']) === $slug && !$opt['used_for_variation'] && isset($r['product_values'][$opt['key']])) {
                return implode(', ', $r['product_values'][$opt['key']]);
            }
        }
        return null;
    }

    private function shippingFields(?float $weight): array
    {
        $out = [];
        if (!empty($this->config['shipping']['export_weight']) && $weight !== null && $weight > 0) {
            $out['shipping_weight'] = rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.') . ' ' . $this->weightUnit;
        }
        if ($this->config['shipping']['mode'] === 'flat') {
            $out['shipping'] = [[
                'country' => $this->config['shipping']['country'],
                'service' => $this->config['shipping']['service'],
                'price'   => $this->money((float)$this->config['shipping']['price']),
            ]];
        }
        return $out;
    }

    private function itemId(string $sku, int $objectId, array &$issues): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return 'wc_' . $objectId;
        }
        if (mb_strlen($sku) > FeedValidator::MAX_ID) {
            $issues[] = 'id_fallback';
            return 'wc_' . $objectId;
        }
        return $sku;
    }

    private function description(array $candidates, string $title, array &$issues): string
    {
        foreach ($candidates as $c) {
            $plain = $this->plain((string)$c);
            if ($plain !== '') {
                return $this->truncate($plain, FeedValidator::MAX_DESCRIPTION, 'description_truncated', $issues);
            }
        }
        $issues[] = 'description_from_title';
        return $this->truncate($title, FeedValidator::MAX_DESCRIPTION, 'description_truncated', $issues);
    }

    private function plain(string $html): string
    {
        $text = wp_strip_all_tags(strip_shortcodes($html), true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    private function truncate(string $text, int $max, string $issue, array &$issues): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $issues[] = $issue;
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string)$value . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', (string)$value) ? '' : ' UTC'));
        return $ts === false ? null : $ts;
    }

    private function money(float $amount): string
    {
        return number_format($amount, $this->decimals, '.', '') . ' ' . $this->currency;
    }
}
