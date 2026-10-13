<?php

/**
 * P010.5 — Derived product type and previewed Simple/Variable transitions around ProductLifecycle.
 *
 * Covers: type derivation, the Variable to Simple preview and decisions (receiver, explicit confirmation), what the
 * receiver variation hands to the product (price and schedule, SKU, dimensions, own stock without summing, image),
 * tombstones that stay restorable, rollback inside the caller's transaction, and Simple to Variable keeping SKU and
 * stock with the price proposed as common price.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\EditorValidationException;
use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Admin\ProductEditor\ProductTypeTransition;
use WPE\Infrastructure\Database\MediaRepository;

class ProductTypeTransitionTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private ProductTypeTransition $tt;
    private array $productIds = [];
    private array $assetIds = [];
    private int $checks = 0;
    private string $tag;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
        $this->tt = new ProductTypeTransition($wpdb);
        $this->tag = 'PTT' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    private function check(bool $cond, string $msg): void
    {
        $this->checks++;
        if (!$cond) {
            throw new \AssertionError($msg);
        }
    }

    public function run(): void
    {
        echo "Running ProductTypeTransition tests...\n";
        try {
            $this->derivation();
            $this->flow();
            $this->cleanup();
            echo "All ProductTypeTransition tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function createProduct(): int
    {
        $id = wp_insert_post(['post_title' => 'Type Transition Test ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create product');
        }
        wp_set_object_terms($id, 'variable', 'product_type');
        $this->productIds[] = (int)$id;
        return (int)$id;
    }

    private function row(string $table, string $col, int $id): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->wpdb->prefix}{$table} WHERE {$col} = %d", $id), ARRAY_A) ?: null;
    }

    private function product(int $id): array
    {
        return $this->row('wpe_products', 'id', $id);
    }

    private function rows(string $sql, array $args = []): array
    {
        return $this->wpdb->get_results($args ? $this->wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
    }

    private function expectRejected(callable $fn, array $reasons, string $label): EditorValidationException
    {
        try {
            $fn();
        } catch (EditorValidationException $e) {
            $got = array_column($e->details(), 'reason');
            sort($got);
            sort($reasons);
            $this->check($got === $reasons, "{$label}: expected " . json_encode($reasons) . ', got ' . json_encode($got));
            return $e;
        }
        throw new \AssertionError("{$label}: expected a rejection");
    }

    private function derivation(): void
    {
        echo ' - type derivation...';
        $this->check(ProductTypeTransition::deriveType(null) === 'simple', 'no options is simple');
        $this->check(ProductTypeTransition::deriveType([]) === 'simple', 'empty options is simple');
        $this->check(ProductTypeTransition::deriveType([['name' => '  ']]) === 'simple', 'blank option name does not count');
        $this->check(ProductTypeTransition::deriveType([['name' => 'Color']]) === 'variable', 'one variation option is variable');
        echo " OK\n";
    }

    private function flow(): void
    {
        $prefix = $this->wpdb->prefix;
        $media = new MediaRepository($this->wpdb);
        $pid = $this->createProduct();
        $this->service->getProductEditorData($pid);

        $past = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $future = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $skuParent = $this->tag . '-PARENT';
        $skuBlack = $this->tag . '-BLACK';
        $skuWhite = $this->tag . '-WHITE';

        $res = $this->service->saveProductVariants($pid, [
            'expected_version'  => (int)$this->product($pid)['version'],
            'product'           => ['sku' => $skuParent, 'stock' => ['manage_stock' => true, 'stock_quantity' => 100, 'stock_status' => 'instock']],
            'variation_options' => [['name' => 'Color', 'values' => [['label' => 'Black'], ['label' => 'White'], ['label' => 'Red']]]],
            'variations'        => [
                ['combination' => ['Color' => 'Black'], 'sku' => $skuBlack, 'regular_price' => '50', 'manage_stock' => 1, 'stock_quantity' => 5],
                ['combination' => ['Color' => 'White'], 'sku' => $skuWhite, 'regular_price' => '70', 'sale_price' => '60', 'sale_from' => $past, 'sale_to' => $future,
                 'weight' => '2', 'length' => '3', 'manage_stock' => 1, 'stock_quantity' => 12],
                ['combination' => ['Color' => 'Red']],
            ],
        ]);
        $this->check($res['success'] === true, 'fixture save succeeds');
        $byColor = [];
        foreach ($res['data']['variations'] as $v) {
            $byColor[$v['combination']['Color']] = (int)$v['id'];
        }
        [$black, $white, $red] = [$byColor['Black'], $byColor['White'], $byColor['Red']];
        $allIds = [$black, $white, $red];

        // Images: product featured A1 and gallery A2, the receiver has its own image A3.
        $asset = function (string $n) use ($media): int {
            $a = $media->createAsset(['source_url' => 'https://example.com/' . $this->tag . '-' . $n . '.jpg']);
            $this->assetIds[] = (int)$a['id'];
            return (int)$a['id'];
        };
        $a1 = $asset('a1');
        $a2 = $asset('a2');
        $a3 = $asset('a3');
        $this->wpdb->insert("{$prefix}wpe_product_media", ['product_id' => $pid, 'asset_id' => $a1, 'role' => 'featured', 'position' => 0]);
        $this->wpdb->insert("{$prefix}wpe_product_media", ['product_id' => $pid, 'asset_id' => $a2, 'role' => 'gallery', 'position' => 1]);
        $this->wpdb->insert("{$prefix}wpe_variant_media", ['variant_id' => $white, 'asset_id' => $a3, 'position' => 0, 'is_primary' => 1]);

        // A marker and a stale common price in the product extra props.
        $extra = json_decode((string)$this->product($pid)['extra_props'], true) ?: [];
        $extra['p010_marker'] = 'keep-me';
        $extra['common_price'] = ['regular' => '1.000000', 'sale' => null, 'from' => null, 'to' => null];
        $this->wpdb->update("{$prefix}wpe_products", ['extra_props' => json_encode($extra)], ['id' => $pid]);

        $prod = $this->product($pid);
        $this->check($prod['type'] === 'variable', 'fixture is a variable product');

        // ---- derive states
        echo ' - derived state...';
        $d = $this->tt->derive($pid, ['variation_options' => [['name' => 'Color']], 'variations' => []]);
        $this->check($d['derived'] === 'variable' && $d['state'] === 'incomplete', 'options without variations are incomplete');
        $d = $this->tt->derive($pid, ['variation_options' => [['name' => 'Color']]]);
        $this->check($d['state'] === 'variable' && $d['variation_count'] === 3, 'saved variations count when the request has none');
        $d = $this->tt->derive($pid, ['variation_options' => []]);
        $this->check($d['derived'] === 'simple' && $d['state'] === 'simple', 'no variation option derives simple');
        $d = $this->tt->derive($pid, []);
        $this->check($d['derived'] === 'variable', 'saved options are used when the request has no option list');
        echo " OK\n";

        // ---- preview
        echo ' - preview Variable to Simple...';
        $pv = $this->tt->preview($pid, 'simple');
        $this->check($pv['changed'] === true && $pv['receiver_required'] === true && $pv['requires_confirmation'] === true, 'several variations need a receiver and confirmation');
        $this->check(array_column($pv['variations'], 'id') === $allIds && $pv['will_tombstone'] === $allIds, 'preview lists every variation in saved order');
        $this->check($pv['proposed_receiver_id'] === $black, 'the first saved variation is proposed');
        $this->check($pv['stock_policy'] === 'receiver_only' && $pv['restorable'] === true, 'preview states stock policy and restorability');
        $labels = array_column($pv['variations'], 'label');
        $this->check($labels === ['Black', 'White', 'Red'], 'preview labels variations, got ' . json_encode($labels));
        $this->check($pv['variations'][1]['active_price'] === '60.000000' && $pv['variations'][2]['priced'] === false, 'preview shows prices and missing price');
        $this->check($this->tt->preview($pid, 'variable')['changed'] === false, 'same type is no change');
        echo " OK\n";

        // ---- plan decisions
        echo ' - plan needs explicit decisions...';
        $none = ['variation_options' => []];
        $e = $this->expectRejected(fn() => $this->tt->plan($pid, $prod, $none), ['receiver_required', 'confirmation_required'], 'no decision');
        $this->check(($e->context()['preview']['receiver_required'] ?? false) === true, 'rejection carries the preview');
        $this->expectRejected(fn() => $this->tt->plan($pid, $prod, $none + ['type_transition' => ['receiver_id' => 99999999, 'confirm_tombstone' => true]]), ['receiver_invalid'], 'unknown receiver');
        $this->expectRejected(fn() => $this->tt->plan($pid, $prod, $none + ['type_transition' => ['receiver_id' => $white]]), ['confirmation_required'], 'missing confirmation');
        $this->expectRejected(fn() => $this->tt->plan($pid, $prod, $none + ['type_transition' => ['receiver_id' => $white, 'confirm_tombstone' => 'yes']]), ['confirmation_required'], 'truthy string is not a confirmation');
        $this->expectRejected(fn() => $this->tt->plan($pid, $prod, $none + ['variations' => [['combination' => ['Color' => 'Black']]]]), ['variations_without_options'], 'variations without options');
        $this->check($this->tt->plan($pid, $prod, [])['changed'] === false, 'a request without option list never changes the type');
        $this->check($this->tt->plan($pid, $prod, ['variation_options' => [['name' => 'Color']]])['changed'] === false, 'same derived type is no change');
        $this->check($this->product($pid)['type'] === 'variable', 'rejected plans changed nothing');
        echo " OK\n";

        $plan = $this->tt->plan($pid, $prod, $none + ['type_transition' => ['receiver_id' => $white, 'confirm_tombstone' => true]]);
        $this->check($plan['changed'] && $plan['to'] === 'simple' && $plan['receiver_id'] === $white && $plan['tombstone_ids'] === $allIds, 'valid plan names the receiver and every tombstone');

        // ---- rollback inside the caller's transaction
        echo ' - rollback leaves everything as it was...';
        $versionBefore = (int)$prod['version'];
        $this->wpdb->query('START TRANSACTION');
        $this->tt->apply($pid, $plan, gmdate('Y-m-d H:i:s.u'));
        $this->check($this->product($pid)['type'] === 'simple', 'type is flipped inside the open transaction');
        $this->wpdb->query('ROLLBACK');
        $this->check($this->product($pid)['type'] === 'variable', 'rollback restores the type');
        $alive = (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(1) FROM {$prefix}wpe_variants WHERE product_id = %d AND deleted_at IS NULL", $pid));
        $this->check($alive === 3, 'rollback restores the variations');
        $this->check($this->product($pid)['sku'] === $skuParent && count($this->rows("SELECT * FROM {$prefix}wpe_sku_registry WHERE original_sku = %s", [$skuBlack])) === 1, 'rollback restores SKU and registry');
        echo " OK\n";

        // ---- apply
        echo ' - receiver becomes the product...';
        $this->wpdb->query('START TRANSACTION');
        $report = $this->tt->apply($pid, $plan, gmdate('Y-m-d H:i:s.u'));
        $this->wpdb->query('COMMIT');
        $this->tt->complete($pid, $plan);
        $p = $this->product($pid);
        $this->check($p['type'] === 'simple', 'product is simple');
        $this->check((int)$p['version'] === $versionBefore, 'apply leaves the version to the caller');
        $this->wpdb->query($this->wpdb->prepare("UPDATE {$prefix}wpe_products SET version = version + 1 WHERE id = %d", $pid)); // the caller owns the version
        $this->check($p['regular_price'] === '70.000000' && $p['sale_price'] === '60.000000' && $p['sale_from'] !== null && $p['sale_to'] !== null, 'price and schedule come from the receiver');
        $this->check($p['weight'] === '2.000000' && $p['length'] === '3.000000', 'dimensions come from the receiver');
        $this->check($p['sku'] === $skuWhite, 'SKU comes from the receiver');
        $reg = $this->rows("SELECT object_id, object_kind, original_sku FROM {$prefix}wpe_sku_registry WHERE original_sku IN (%s, %s, %s, %s)", [$skuParent, $skuBlack, $skuWhite, 'none']);
        $this->check(count($reg) === 1 && (int)$reg[0]['object_id'] === $pid && $reg[0]['object_kind'] === 'product' && $reg[0]['original_sku'] === $skuWhite, 'only the product holds the receiver SKU, variation SKUs are released: ' . json_encode($reg));
        $inv = $this->row('wpe_inventory', 'owner_id', $pid);
        $this->check((float)$inv['quantity'] === 12.0 && (int)$inv['manage_stock'] === 1, 'the receiver own stock replaces product stock and nothing is summed');
        $gone = $this->rows("SELECT id, status, deleted_at, active_combination_key FROM {$prefix}wpe_variants WHERE product_id = %d ORDER BY id", [$pid]);
        $this->check(count($gone) === 3, 'tombstones keep the variation rows (restorable)');
        foreach ($gone as $g) {
            $this->check($g['deleted_at'] !== null && $g['status'] === 'trash' && $g['active_combination_key'] === null, 'variation ' . $g['id'] . ' is tombstoned');
        }
        $this->check(sort($report['tombstoned']) && $report['tombstoned'] === $allIds && $report['receiver_id'] === $white && $report['warnings'] === [], 'report names tombstones and receiver');
        $media = $this->rows("SELECT asset_id, role, position FROM {$prefix}wpe_product_media WHERE product_id = %d ORDER BY role, position", [$pid]);
        $this->check(
            $media === [
                ['asset_id' => (string)$a1, 'role' => 'gallery', 'position' => '1'],
                ['asset_id' => (string)$a2, 'role' => 'gallery', 'position' => '2'],
                ['asset_id' => (string)$a3, 'role' => 'featured', 'position' => '0'],
            ] || $media === [
                ['asset_id' => (string)$a3, 'role' => 'featured', 'position' => '0'],
                ['asset_id' => (string)$a1, 'role' => 'gallery', 'position' => '1'],
                ['asset_id' => (string)$a2, 'role' => 'gallery', 'position' => '2'],
            ],
            'receiver image is featured, the old featured image leads the gallery: ' . json_encode($media)
        );
        $extra = json_decode((string)$p['extra_props'], true);
        $this->check(!array_key_exists('common_price', $extra) && ($extra['p010_marker'] ?? null) === 'keep-me', 'common price removed, other extra props kept');
        $wc = wc_get_product($pid);
        $this->check($wc && $wc->get_type() === 'simple', 'WooCommerce sees a simple product after completion');
        echo " OK\n";

        // ---- Simple to Variable
        echo ' - Simple to Variable keeps SKU and stock, proposes the price...';
        $simple = $this->product($pid);
        $pvv = $this->tt->preview($pid, 'variable');
        $this->check($pvv['common_price_proposal']['regular'] === '70.000000' && $pvv['common_price_proposal']['sale'] === '60.000000' && $pvv['common_price_proposal']['from'] !== null, 'preview proposes the simple price and schedule');
        $this->check($pvv['requires_variations'] === true && $pvv['keeps_on_product'] === ['sku', 'stock'], 'preview says SKU and stock stay');
        $stay = $this->tt->plan($pid, $simple, ['variation_options' => [['name' => 'Size']]]);
        $this->check($stay['changed'] === false && $stay['state'] === 'incomplete' && $stay['to'] === 'simple', 'without variations the product stays simple and is incomplete');
        $flip = $this->tt->plan($pid, $simple, ['variation_options' => [['name' => 'Size']], 'variations' => [['combination' => ['Size' => 'S']]]]);
        $this->check($flip['changed'] && $flip['to'] === 'variable' && $flip['common_price_patch']['regular'] === '70.000000', 'with variations the type flips and carries the price proposal');
        $this->wpdb->query('START TRANSACTION');
        $this->tt->apply($pid, $flip, gmdate('Y-m-d H:i:s.u'));
        $this->wpdb->query('COMMIT');
        $this->tt->complete($pid, $flip);
        $this->wpdb->query($this->wpdb->prepare("UPDATE {$prefix}wpe_products SET version = version + 1 WHERE id = %d", $pid)); // the caller owns the version
        $p = $this->product($pid);
        $this->check($p['type'] === 'variable' && $p['regular_price'] === null && $p['sale_price'] === null && $p['sale_from'] === null, 'parent price columns are cleared');
        $this->check($p['sku'] === $skuWhite, 'product SKU stays on the product');
        $this->check((float)$this->row('wpe_inventory', 'owner_id', $pid)['quantity'] === 12.0, 'product stock stays as shared stock');
        $this->check(count($this->rows("SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d AND deleted_at IS NULL", [$pid])) === 0, 'nothing is duplicated into combinations');
        $unpriced = $this->tt->preview($pid, 'simple');
        $this->check($unpriced['receiver_required'] === false && $unpriced['will_tombstone'] === [] && $unpriced['proposed_receiver_id'] === null, 'a variable product without variations needs no receiver');
        echo " OK\n";

        echo ' - single variation and empty variable product...';
        $plan0 = $this->tt->plan($pid, $this->product($pid), ['variation_options' => []]);
        $this->check($plan0['changed'] && $plan0['receiver_id'] === null && $plan0['tombstone_ids'] === [], 'no variations: flips to simple without a decision');
        $this->wpdb->query('START TRANSACTION');
        $r0 = $this->tt->apply($pid, $plan0, gmdate('Y-m-d H:i:s.u'));
        $this->wpdb->query('COMMIT');
        $this->tt->complete($pid, $plan0);
        $this->check($this->product($pid)['type'] === 'simple' && $r0['tombstoned'] === [], 'empty variable product became simple');
        $this->check($this->product($pid)['sku'] === $skuWhite, 'SKU unchanged when there is no receiver');
        echo " OK\n";
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        $variantIds = [];
        foreach ($this->productIds as $id) {
            $variantIds = array_merge($variantIds, array_map('intval', $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d", $id))));
            $optionIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$prefix}wpe_options WHERE product_id = %d", $id)));
            if ($optionIds) {
                $list = implode(',', $optionIds);
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE option_id IN ({$list})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN ({$list})");
            }
        }
        if ($variantIds) {
            $list = implode(',', $variantIds);
            $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_sku_registry WHERE object_id IN ({$list})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$list})");
        }
        foreach ($this->productIds as $id) {
            $this->wpdb->delete("{$prefix}wpe_product_media", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
        foreach (array_unique($variantIds) as $vid) {
            wp_delete_post($vid, true);
        }
        foreach ($this->assetIds as $aid) {
            $this->wpdb->delete("{$prefix}wpe_assets", ['id' => $aid]);
        }
        $this->productIds = [];
        $this->assetIds = [];
    }
}

(new ProductTypeTransitionTest())->run();
