<?php

/**
 * P010.2 — EffectivePriceResolver and its adopters.
 *
 * Part A exercises the pure rule set (null vs 0, sale windows, sale vs regular, source/mode).
 * Part B proves the adopters follow the same rule on real rows:
 *  - CartValidation no longer accepts a scheduled sale before its window opens;
 *  - VariantResolver DTO price uses UTC windows and the same rule;
 *  - MetaBridge `_price` matches the effective price;
 *  - CatalogProjector min/max/on_sale/next_price_change read the variation sale columns.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Variant\CartValidation;
use WPE\Application\Variant\EffectivePriceResolver as R;
use WPE\Application\Variant\VariantResolver;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\OptionsRepository;

class EffectivePriceResolverTest
{
    private wpdb $wpdb;
    private int $checks = 0;
    private array $ids = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    private function check(bool $cond, string $msg): void
    {
        $this->checks++;
        if (!$cond) {
            throw new RuntimeException("Assertion failed: {$msg}");
        }
    }

    public function run(): void
    {
        echo "Running EffectivePriceResolver tests...\n";
        try {
            $this->pureRules();
            $this->adopters();
        } finally {
            $this->cleanup();
        }
        echo "OK ({$this->checks} checks)\n";
    }

    private function pureRules(): void
    {
        $now = '2026-06-15 12:00:00';

        // null vs 0 vs empty
        $r = R::resolve(['regular_price' => null, 'sale_price' => null], null, $now);
        $this->check($r['state'] === 'unpriced' && $r['source'] === 'missing' && $r['active_price'] === null, 'null price is unpriced');
        $r = R::resolve(['regular_price' => '', 'sale_price' => ''], null, $now);
        $this->check($r['state'] === 'unpriced', 'empty string is unpriced, not zero');
        $r = R::resolve(['regular_price' => '0', 'sale_price' => null], null, $now);
        $this->check($r['state'] === 'priced' && $r['active_price'] === '0.000000' && $r['regular'] === '0.000000', 'zero is a real free price');
        $r = R::resolve(['regular_price' => '0.000000', 'sale_price' => null], null, $now);
        $this->check($r['state'] === 'priced' && $r['source'] === 'own', 'zero price is priced with own source');
        $r = R::resolve(['regular_price' => 'abc', 'sale_price' => null], null, $now);
        $this->check($r['state'] === 'unpriced', 'non-numeric is unpriced');
        $r = R::resolve(['regular_price' => '19.95', 'sale_price' => null], null, $now);
        $this->check($r['active_price'] === '19.950000', 'amounts normalize to six decimals');

        // sale windows (both ends inclusive)
        $row = ['regular_price' => '100', 'sale_price' => '75'];
        $r = R::resolve($row + ['sale_from' => null, 'sale_to' => null], null, $now);
        $this->check($r['sale_active'] && $r['on_sale'] && $r['active_price'] === '75.000000' && $r['next_change'] === null, 'open window sale is active');
        $r = R::resolve($row + ['sale_from' => '2026-06-15 11:00:00', 'sale_to' => '2026-06-15 13:00:00'], null, $now);
        $this->check($r['sale_active'] && $r['next_change'] === '2026-06-15 13:00:00.000000', 'inside window active, next change is end');
        $r = R::resolve($row + ['sale_from' => '2026-06-15 13:00:00', 'sale_to' => '2026-06-16 00:00:00'], null, $now);
        $this->check(!$r['sale_active'] && !$r['on_sale'] && $r['active_price'] === '100.000000' && $r['next_change'] === '2026-06-15 13:00:00.000000', 'scheduled sale is not active early');
        $r = R::resolve($row + ['sale_from' => '2026-06-14 00:00:00', 'sale_to' => '2026-06-15 11:59:59'], null, $now);
        $this->check(!$r['sale_active'] && $r['active_price'] === '100.000000' && $r['next_change'] === null, 'expired sale falls back to regular');
        $r = R::resolve($row + ['sale_from' => '2026-06-15 12:00:00', 'sale_to' => null], null, $now);
        $this->check($r['sale_active'], 'sale starts at the from instant');
        $r = R::resolve($row + ['sale_from' => null, 'sale_to' => '2026-06-15 12:00:00'], null, $now);
        $this->check($r['sale_active'], 'sale still active at the to instant');
        $r = R::resolve($row + ['sale_from' => '2026-06-15 12:00:00.000000', 'sale_to' => null], null, '2026-06-15 11:59:59.999999');
        $this->check(!$r['sale_active'], 'microsecond before from is inactive');

        // sale vs regular
        $r = R::resolve(['regular_price' => '10', 'sale_price' => '12'], null, $now);
        $this->check(!$r['sale_active'] && $r['active_price'] === '10.000000', 'sale above regular is ignored');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => '10'], null, $now);
        $this->check($r['sale_active'] && !$r['on_sale'] && $r['active_price'] === '10.000000', 'sale equal to regular is not a discount');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => '0'], null, $now);
        $this->check($r['sale_active'] && $r['on_sale'] && $r['active_price'] === '0.000000', 'sale of zero is a real value');

        // sale without regular
        $r = R::resolve(['regular_price' => null, 'sale_price' => '5', 'sale_from' => null, 'sale_to' => null], null, $now);
        $this->check($r['state'] === 'priced' && $r['active_price'] === '5.000000' && !$r['on_sale'], 'sale-only row is priced');
        $r = R::resolve(['regular_price' => null, 'sale_price' => '5', 'sale_from' => '2026-06-16 00:00:00', 'sale_to' => null], null, $now);
        $this->check($r['state'] === 'unpriced', 'sale-only row outside its window is unpriced');

        // mode and source
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => '{}'], null, $now);
        $this->check($r['mode'] === 'own' && $r['source'] === 'own', 'absent pricing mode means own');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => '{"pricing_mode":"shared"}'], null, $now);
        $this->check($r['mode'] === 'shared' && $r['source'] === 'shared', 'shared mode reports shared source');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => '{"pricing_mode":"weird"}'], null, $now);
        $this->check($r['mode'] === 'own', 'unknown mode falls back to own');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => ['pricing_mode' => 'shared']], null, $now);
        $this->check($r['mode'] === 'shared', 'extra_props may be an array');
        $r = R::resolve(['regular_price' => null, 'sale_price' => null, 'extra_props' => '{"pricing_mode":"shared"}'], null, $now);
        $this->check($r['state'] === 'unpriced' && $r['source'] === 'missing', 'shared row with no price is missing, not hidden');

        // common price override only for shared rows
        $common = ['regular' => '50', 'sale' => '40', 'from' => null, 'to' => null];
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => '{"pricing_mode":"shared"}'], $common, $now);
        $this->check($r['active_price'] === '40.000000' && $r['regular'] === '50.000000' && $r['source'] === 'shared', 'shared row follows the common price');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => '{"pricing_mode":"own"}'], $common, $now);
        $this->check($r['active_price'] === '10.000000' && $r['source'] === 'own', 'own row ignores the common price');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null], $common, $now);
        $this->check($r['active_price'] === '10.000000', 'row without mode ignores the common price');
        $r = R::resolve(['regular_price' => '10', 'sale_price' => null, 'extra_props' => '{"pricing_mode":"shared"}'], ['regular' => '0', 'sale' => null, 'from' => null, 'to' => null], $now);
        $this->check($r['active_price'] === '0.000000' && $r['state'] === 'priced', 'common price of zero is honoured');

        // legacy extra_props date keys, columns win
        $r = R::resolve(['regular_price' => '100', 'sale_price' => '50', 'extra_props' => '{"date_on_sale_from":"2026-06-16 00:00:00"}'], null, $now);
        $this->check(!$r['sale_active'], 'legacy date_on_sale_from is honoured');
        $r = R::resolve(['regular_price' => '100', 'sale_price' => '50', 'sale_from' => '2026-06-14 00:00:00', 'sale_to' => null, 'extra_props' => '{"date_on_sale_from":"2026-06-16 00:00:00"}'], null, $now);
        $this->check($r['sale_active'], 'column window wins over legacy extra');
    }

    private function adopters(): void
    {
        $prefix = $this->wpdb->prefix;
        $allocator = new PostIdAllocator($this->wpdb);
        $optionsRepo = new OptionsRepository($this->wpdb);
        $nowLocal = current_time('mysql');

        $parentId = $allocator->allocateShell('product');
        $this->ids[] = $parentId;
        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'P010T-' . $parentId, 'slug' => 'p010t-' . $parentId, 'title' => 'P010 price test',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => $nowLocal, 'updated_at' => $nowLocal, 'version' => 1,
        ]);
        $this->wpdb->insert("{$prefix}wpe_inventory", ['owner_id' => $parentId, 'manage_stock' => 0, 'stock_status' => 'instock', 'backorders' => 'no']);

        $future = gmdate('Y-m-d H:i:s', time() + 7200);
        $far = gmdate('Y-m-d H:i:s', time() + 14400);
        $past = gmdate('Y-m-d H:i:s', time() - 7200);

        $defs = [
            'early'   => ['regular_price' => '100.000000', 'sale_price' => '50.000000', 'sale_from' => $future, 'sale_to' => $far],
            'onlysale' => ['regular_price' => null, 'sale_price' => '50.000000', 'sale_from' => $future, 'sale_to' => $far],
            'free'    => ['regular_price' => '0.000000', 'sale_price' => null, 'sale_from' => null, 'sale_to' => null],
            'none'    => ['regular_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null],
            'active'  => ['regular_price' => '100.000000', 'sale_price' => '60.000000', 'sale_from' => $past, 'sale_to' => $far],
        ];
        $vid = [];
        $position = 0;
        foreach ($defs as $name => $cols) {
            $id = $allocator->allocateShell('product_variation');
            $this->ids[] = $id;
            $vid[$name] = $id;
            $this->wpdb->insert("{$prefix}wpe_variants", $cols + [
                'id' => $id, 'product_id' => $parentId, 'sku' => 'P010T-' . $name . '-' . $id, 'status' => 'publish',
                'combination_key' => hash('sha256', 'p010t' . $id, true), 'description' => '', 'extra_props' => '{}',
                'position' => $position++, 'created_at' => $nowLocal, 'updated_at' => $nowLocal, 'version' => 1,
            ]);
        }

        // --- CartValidation
        $cart = new CartValidation($this->wpdb, $optionsRepo);
        $res = $cart->validateItemForCart($parentId, 1, $vid['early']);
        $this->check($res['valid'] === true, 'priced variation with a scheduled sale is purchasable at its regular price: ' . json_encode($res));
        $res = $cart->validateItemForCart($parentId, 1, $vid['onlysale']);
        $this->check($res['valid'] === false && $res['code'] === 'missing_price', 'scheduled sale cannot stand in for a missing regular price');
        $res = $cart->validateItemForCart($parentId, 1, $vid['none']);
        $this->check($res['valid'] === false && $res['code'] === 'missing_price', 'unpriced variation is rejected');
        $res = $cart->validateItemForCart($parentId, 1, $vid['free']);
        $this->check($res['valid'] === true, 'free variation is purchasable: ' . json_encode($res));

        // --- VariantResolver DTO
        $resolver = new VariantResolver($this->wpdb, $optionsRepo);
        $build = new ReflectionMethod($resolver, 'buildVariantDto');
        $build->setAccessible(true);
        $dto = function (string $name) use ($build, $resolver, $parentId, $vid, $prefix) {
            $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$prefix}wpe_variants WHERE id = %d", $vid[$name]), ARRAY_A);
            return $build->invoke($resolver, $parentId, $row);
        };
        $d = $dto('early');
        $this->check($d['price'] === '100.000000', 'resolver DTO keeps the regular price before the sale window');
        $d = $dto('active');
        $this->check($d['price'] === '60.000000', 'resolver DTO applies an open sale');
        $d = $dto('free');
        $this->check($d['price'] === '0.000000' && $d['purchasable'] === true, 'resolver DTO keeps free purchasable');
        $d = $dto('none');
        $this->check($d['price'] === null && $d['reason'] === 'missing_price', 'resolver DTO reports missing price');
        $d = $dto('onlysale');
        $this->check($d['price'] === null && $d['purchasable'] === false, 'resolver DTO does not use a future sale as the only price');

        // --- MetaBridge `_price`
        $bridge = new MetaBridge($this->wpdb);
        $price = static function (int $id) use ($bridge) {
            $v = $bridge->getPostMeta($id, '_price', true);
            return is_array($v) ? $v[0] : '';
        };
        $this->check($price($vid['early']) === '100.000000', 'MetaBridge _price ignores a not-yet-active sale, got ' . var_export($price($vid['early']), true));
        $this->check($price($vid['active']) === '60.000000', 'MetaBridge _price uses an active sale');
        $this->check($price($vid['free']) === '0.000000', 'MetaBridge _price keeps free as zero');
        $this->check($price($vid['none']) === '', 'MetaBridge _price is empty when unpriced');

        // --- CatalogProjector (same generation row removed afterwards)
        $projector = new CatalogProjector($this->wpdb);
        $gen = $projector->getActiveGeneration();
        $projector->projectProduct($parentId, $gen);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT min_price, max_price, on_sale, next_price_change FROM {$prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
            $gen,
            $parentId
        ), ARRAY_A);
        $this->check($row !== null, 'projected catalog row exists');
        $this->check($row['min_price'] === '0.000000', 'catalog min price counts the free variation, got ' . var_export($row['min_price'], true));
        $this->check($row['max_price'] === '100.000000', 'catalog max price ignores the scheduled sale, got ' . var_export($row['max_price'], true));
        $this->check((int)$row['on_sale'] === 1, 'catalog on_sale reflects the active sale variation');
        $this->check($row['next_price_change'] !== null && strtotime($row['next_price_change'] . ' UTC') <= time() + 7300, 'catalog next change is the earliest scheduled boundary');
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        if ($this->ids) {
            $list = implode(',', array_map('intval', $this->ids));
            $gen = (new CatalogProjector($this->wpdb))->getActiveGeneration();
            $this->wpdb->query("DELETE FROM {$prefix}wpe_catalog WHERE product_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_catalog_facets WHERE product_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE variant_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE id IN ({$list}) OR product_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$list})");
            foreach ($this->ids as $id) {
                wp_delete_post($id, true);
            }
            $this->ids = [];
        }
    }
}

(new EffectivePriceResolverTest())->run();
