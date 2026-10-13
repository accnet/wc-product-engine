<?php

/**
 * P010.3 — Common price, per-variation pricing mode, materialization, reference price and the preview of removing a
 * common price that variations still use.
 *
 * Runs against the real ProductEditorService on a disposable product. Validation cases must leave the database
 * untouched (the version and the price columns are compared before and after).
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\EditorValidationException;
use WPE\Admin\ProductEditor\PricingService;
use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Application\Variant\CartValidation;

class PricingModeTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private array $productIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
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
        echo "Running PricingMode tests...\n";
        try {
            $this->pureValidation();
            $this->flow();
            $this->cleanup();
            echo "All PricingMode tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function createProduct(): int
    {
        $id = wp_insert_post(['post_title' => 'Pricing Mode Test ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create product');
        }
        wp_set_object_terms($id, 'variable', 'product_type');
        $this->productIds[] = (int)$id;
        return (int)$id;
    }

    private function version(int $productId): int
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT version FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $productId));
    }

    private function variantRow(int $id): array
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->wpdb->prefix}wpe_variants WHERE id = %d", $id), ARRAY_A);
    }

    private function expectInvalid(callable $fn, string $reason, string $pointer, string $label): EditorValidationException
    {
        try {
            $fn();
        } catch (EditorValidationException $e) {
            $found = false;
            foreach ($e->details() as $d) {
                if ($d['reason'] === $reason && $d['pointer'] === $pointer) {
                    $found = true;
                }
            }
            $this->check($found, "{$label}: expected {$reason} at {$pointer}, got " . json_encode($e->details()));
            return $e;
        }
        throw new \AssertionError("{$label}: expected validation to fail");
    }

    private function pureValidation(): void
    {
        echo ' - value normalization...';
        $this->check(PricingService::normalizeAmount('', '/x') === null, 'empty amount is no price');
        $this->check(PricingService::normalizeAmount(null, '/x') === null, 'null amount is no price');
        $this->check(PricingService::normalizeAmount('0', '/x') === '0.000000', 'zero stays a price');
        $this->check(PricingService::normalizeAmount(19.9, '/x') === '19.900000', 'amount has six decimals');
        $this->expectInvalid(fn() => PricingService::normalizeAmount('-1', '/p'), 'invalid_amount', '/p', 'negative');
        $this->expectInvalid(fn() => PricingService::normalizeAmount('abc', '/p'), 'invalid_amount', '/p', 'text');
        $this->check(PricingService::normalizeInstant('2030-01-02T03:04:05Z', '/d') === '2030-01-02 03:04:05', 'ISO instant becomes UTC');
        $this->check(PricingService::normalizeInstant('2030-01-02T10:00:00+07:00', '/d') === '2030-01-02 03:00:00', 'offset is converted to UTC');
        $this->check(PricingService::normalizeInstant('2030-01-02 03:04:05', '/d') === '2030-01-02 03:04:05', 'zone-less value is UTC');
        $this->expectInvalid(fn() => PricingService::normalizeInstant('not a date', '/d'), 'invalid_date', '/d', 'bad date');
        $this->check(PricingService::normalizeCommonPrice(null) === null, 'null common price');
        $this->check(PricingService::normalizeCommonPrice(['regular' => '', 'sale' => null]) === null, 'all-empty common price means none');
        $this->expectInvalid(fn() => PricingService::normalizeCommonPrice(['regular' => '10', 'sale' => '12']), 'sale_above_regular', '/common_price/sale', 'sale above regular');
        $this->expectInvalid(fn() => PricingService::normalizeCommonPrice(['sale' => '5']), 'sale_requires_regular', '/common_price/sale', 'sale without regular');
        $this->expectInvalid(fn() => PricingService::normalizeCommonPrice(['regular' => '10', 'from' => '2030-01-01 00:00:00']), 'schedule_requires_sale', '/common_price/from', 'schedule without sale');
        $this->expectInvalid(
            fn() => PricingService::normalizeCommonPrice(['regular' => '10', 'sale' => '5', 'from' => '2030-02-01 00:00:00', 'to' => '2030-01-01 00:00:00']),
            'sale_window_invalid',
            '/common_price/to',
            'inverted window'
        );
        $c = PricingService::normalizeCommonPrice(['regular' => '0', 'sale' => null]);
        $this->check($c !== null && $c['regular'] === '0.000000', 'a common price of zero is kept');
        echo " OK\n";
    }

    private function payload(int $version, array $variations, array $extra = []): array
    {
        return $extra + [
            'expected_version'  => $version,
            'variation_options' => [[
                'name' => 'Color', 'position' => 0,
                'values' => [['label' => 'Black', 'position' => 0], ['label' => 'White', 'position' => 1], ['label' => 'Red', 'position' => 2], ['label' => 'Blue', 'position' => 3]],
            ]],
            'variations'        => $variations,
        ];
    }

    private function var(string $color, array $fields = [], int $id = 0): array
    {
        return $fields + ['id' => $id ?: null, 'combination' => ['Color' => $color], 'sku' => 'PM-' . strtolower($color) . '-' . substr(md5((string)microtime(true) . $color), 0, 6), 'status' => 'publish'];
    }

    private function flow(): void
    {
        $productId = $this->createProduct();
        $this->service->getProductEditorData($productId); // create the native row
        $prefix = $this->wpdb->prefix;

        echo ' - reference price without variations...';
        $svc = new PricingService($this->wpdb);
        $ref = $svc->referencePrice($productId);
        $this->check($ref['state'] === 'none' && $ref['variant_id'] === null && $ref['applies'] === true, 'no variations means no reference price');
        echo " OK\n";

        // Marker proves that unrelated extra props survive a common price write.
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$prefix}wpe_products WHERE id = %d", $productId), ARRAY_A);
        $extra = json_decode((string)$row['extra_props'], true) ?: [];
        $extra['p010_marker'] = 'keep-me';
        $this->wpdb->update("{$prefix}wpe_products", ['extra_props' => json_encode($extra)], ['id' => $productId]);

        $from = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $to = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $common1 = ['regular' => '50', 'sale' => '40', 'from' => $from, 'to' => $to];

        // ---- Save 1: common price, A new (default shared), B own, C explicit shared
        echo ' - save with common price and mixed modes...';
        $res = $this->service->saveProductVariants($productId, $this->payload($this->version($productId), [
            $this->var('Black'),
            $this->var('White', ['pricing_mode' => 'own', 'regular_price' => '70']),
            $this->var('Red', ['pricing_mode' => 'shared', 'regular_price' => '999']),
        ], ['common_price' => $common1]));
        $this->check($res['success'] === true, 'first save succeeds');
        $data = $res['data'];
        $this->check($data['common_price']['regular'] === '50.000000' && $data['common_price']['sale'] === '40.000000', 'common price is returned');
        $this->check(substr($data['common_price']['from'], 0, 10) === gmdate('Y-m-d', time() - 3600), 'ISO sale start stored as UTC');
        $byColor = [];
        foreach ($data['variations'] as $v) {
            $byColor[array_key_first($v['combination'])  ? $v['combination']['Color'] : ''] = $v;
        }
        $a = $byColor['Black'];
        $b = $byColor['White'];
        $c = $byColor['Red'];
        $this->check($a['pricing_mode'] === 'shared', 'new variation defaults to shared when a common price exists');
        $this->check($c['pricing_mode'] === 'shared', 'explicit shared is kept');
        $this->check($b['pricing_mode'] === 'own', 'explicit own is kept');
        $rowA = $this->variantRow($a['id']);
        $rowC = $this->variantRow($c['id']);
        $rowB = $this->variantRow($b['id']);
        $this->check($rowA['regular_price'] === '50.000000' && $rowA['sale_price'] === '40.000000', 'shared variation carries the common price columns');
        $this->check($rowC['regular_price'] === '50.000000', 'a submitted price is ignored for a shared variation');
        $this->check($rowA['sale_from'] !== null && $rowA['sale_to'] !== null, 'shared variation carries the sale schedule');
        $this->check($rowB['regular_price'] === '70.000000' && $rowB['sale_price'] === null, 'own variation keeps its own price');
        $this->check($a['effective_price']['active_price'] === '40.000000' && $a['effective_price']['source'] === 'shared' && $a['effective_price']['sale_active'] === true, 'effective price of shared row is the active sale');
        $this->check($b['effective_price']['active_price'] === '70.000000' && $b['effective_price']['source'] === 'own', 'effective price of own row');
        $storedExtra = json_decode((string)$this->wpdb->get_var($this->wpdb->prepare("SELECT extra_props FROM {$prefix}wpe_products WHERE id = %d", $productId)), true);
        $this->check(($storedExtra['p010_marker'] ?? null) === 'keep-me', 'unrelated product extra props are preserved');
        $this->check(($storedExtra['common_price']['regular'] ?? null) === '50.000000', 'common price lives in product extra props');
        $this->check($data['reference_price']['applies'] === false, 'reference price does not apply when a common price exists');
        echo " OK\n";

        // ---- Validation must not write
        echo ' - validation rejects without writing...';
        $snap = fn() => [$this->version($productId), $this->variantRow($a['id'])['regular_price'], $this->variantRow($b['id'])['regular_price']];
        $before = $snap();
        $v = $this->version($productId);
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v, 'common_price' => ['regular' => '10', 'sale' => '20']]),
            'sale_above_regular',
            '/common_price/sale',
            'common sale above regular'
        );
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v, 'variations' => [['id' => $b['id'], 'combination' => ['Color' => 'White'], 'pricing_mode' => 'weird']]]),
            'invalid_pricing_mode',
            '/variations/0/pricing_mode',
            'unknown mode'
        );
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v, 'variations' => [['id' => $b['id'], 'combination' => ['Color' => 'White'], 'sale_price' => '99', 'regular_price' => '70']]]),
            'sale_above_regular',
            '/variations/0/sale_price',
            'own sale above regular'
        );
        $e = $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v, 'common_price' => null]),
            'common_price_in_use',
            '/common_price',
            'removal without variations list'
        );
        $this->check(($e->context()['preview']['affected_count'] ?? 0) === 2, 'removal error carries the preview of the two shared variations');
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, $this->payload($v, [
                $this->var('Black', ['id' => $a['id']]),
                $this->var('White', ['id' => $b['id']]),
                $this->var('Red', ['id' => $c['id']]),
            ], ['common_price' => null])),
            'common_price_in_use',
            '/variations/0/pricing_mode',
            'removal while variations stay shared'
        );
        $this->check($snap() === $before, 'failed validation wrote nothing');
        echo " OK\n";

        echo ' - removal preview and new shared without common...';
        $preview = $svc->previewCommonPriceRemoval($productId);
        $this->check($preview['in_use'] === true && $preview['requires_action'] === true && $preview['affected_count'] === 2, 'preview counts shared variations');
        $labels = array_column($preview['affected'], 'label');
        sort($labels);
        $this->check($labels === ['Black', 'Red'], 'preview labels the affected variations, got ' . json_encode($labels));
        $this->check($preview['affected'][0]['active_price'] === '40.000000', 'preview shows the current effective price');
        echo " OK\n";

        // ---- Save 2: changing the common price without a variations list updates shared rows only
        echo ' - changing the common price updates shared rows only...';
        $verBefore = [$this->variantRow($a['id'])['version'], $this->variantRow($b['id'])['version']];
        $res = $this->service->saveProductVariants($productId, [
            'expected_version' => $this->version($productId),
            'common_price'     => ['regular' => '60'],
        ]);
        $this->check($res['success'] === true, 'common price update succeeds');
        $rowA = $this->variantRow($a['id']);
        $rowB = $this->variantRow($b['id']);
        $this->check($rowA['regular_price'] === '60.000000' && $rowA['sale_price'] === null && $rowA['sale_from'] === null, 'shared variation follows the new common price and drops the old schedule');
        $this->check($this->variantRow($c['id'])['regular_price'] === '60.000000', 'second shared variation follows too');
        $this->check($rowB['regular_price'] === '70.000000' && $rowB['version'] === $verBefore[1], 'own variation is not touched');
        $this->check((int)$rowA['version'] === (int)$verBefore[0] + 1, 'materialized variation version is bumped');
        echo " OK\n";

        // ---- Removal rules
        echo ' - removing the common price needs a decision per variation...';
        $v = $this->version($productId);
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, $this->payload($v, [
                $this->var('Black', ['id' => $a['id'], 'pricing_mode' => 'own']),
                $this->var('White', ['id' => $b['id']]),
                $this->var('Red', ['id' => $c['id'], 'pricing_mode' => 'own', 'regular_price' => '']),
            ], ['common_price' => null])),
            'price_required',
            '/variations/2/regular_price',
            'own without any price needs confirmation'
        );
        $res = $this->service->saveProductVariants($productId, $this->payload($v, [
            $this->var('Black', ['id' => $a['id'], 'pricing_mode' => 'own']),
            $this->var('White', ['id' => $b['id']]),
            $this->var('Red', ['id' => $c['id'], 'pricing_mode' => 'own', 'regular_price' => '', 'confirm_unpriced' => true]),
        ], ['common_price' => null]));
        $this->check($res['success'] === true, 'removal with explicit decisions succeeds');
        $this->check($res['data']['common_price'] === null, 'common price is gone');
        $rowA = $this->variantRow($a['id']);
        $rowC = $this->variantRow($c['id']);
        $this->check($rowA['regular_price'] === '60.000000', 'converted variation keeps its materialized price instead of losing it');
        $this->check($rowC['regular_price'] === null && $rowC['sale_price'] === null, 'confirmed unpriced variation is empty, not zero');
        $storedExtra = json_decode((string)$this->wpdb->get_var($this->wpdb->prepare("SELECT extra_props FROM {$prefix}wpe_products WHERE id = %d", $productId)), true);
        $this->check(!array_key_exists('common_price', $storedExtra) && ($storedExtra['p010_marker'] ?? null) === 'keep-me', 'common_price key removed, marker kept');
        $this->check($svc->previewCommonPriceRemoval($productId)['in_use'] === false, 'preview reports no common price in use');

        $cart = new CartValidation($this->wpdb);
        $ok = $cart->validateItemForCart($productId, 1, (int)$a['id']);
        $this->check($ok['valid'] === true, 'cart accepts the priced converted variation: ' . json_encode($ok));
        $bad = $cart->validateItemForCart($productId, 1, (int)$c['id']);
        $this->check($bad['valid'] === false && $bad['code'] === 'missing_price', 'cart rejects the unpriced variation');
        echo " OK\n";

        echo ' - shared mode needs a common price...';
        $v = $this->version($productId);
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, $this->payload($v, [
                $this->var('Black', ['id' => $a['id']]),
                $this->var('Blue', ['pricing_mode' => 'shared']),
            ])),
            'shared_requires_common_price',
            '/variations/1/pricing_mode',
            'shared without common'
        );
        echo " OK\n";

        // ---- Save 4: reference price comes from the first saved variation, unpriced does not skip ahead
        echo ' - reference price uses the first saved variation...';
        $res = $this->service->saveProductVariants($productId, $this->payload($this->version($productId), [
            $this->var('Red', ['id' => $c['id']]),
            $this->var('Black', ['id' => $a['id']]),
            $this->var('White', ['id' => $b['id']]),
            $this->var('Blue'),
        ]));
        $this->check($res['success'] === true, 'reorder save succeeds');
        $d = null;
        foreach ($res['data']['variations'] as $v) {
            if (($v['combination']['Color'] ?? '') === 'Blue') {
                $d = $v;
            }
        }
        $this->check($d !== null && $d['pricing_mode'] === 'own', 'new variation is own when no common price exists');
        $ref = $res['data']['reference_price'];
        $this->check($ref['applies'] === true, 'reference price applies without a common price');
        $this->check($ref['state'] === 'unpriced' && $ref['variant_id'] === (int)$c['id'] && $ref['label'] === 'Red', 'first variation is unpriced and is not skipped: ' . json_encode($ref));
        $this->check($ref['active_price'] === null && $ref['regular'] === null, 'unpriced reference carries no amount');
        $this->check($this->variantRow($a['id'])['regular_price'] === '60.000000', 'reference price never changes another variation');
        $this->check($res['data']['common_price'] === null, 'reference price is not stored as a common price');

        $res = $this->service->saveProductVariants($productId, $this->payload($this->version($productId), [
            $this->var('Black', ['id' => $a['id'], 'regular_price' => '0']),
            $this->var('Red', ['id' => $c['id']]),
            $this->var('White', ['id' => $b['id']]),
            $this->var('Blue', ['id' => $d['id']]),
        ]));
        $ref = $res['data']['reference_price'];
        $this->check($ref['state'] === 'ok' && $ref['variant_id'] === (int)$a['id'] && $ref['label'] === 'Black', 'a first variation priced at zero is a real reference price');
        $this->check($ref['regular'] === '0.000000' && $ref['active_price'] === '0.000000', 'zero reference is zero, not empty');
        $this->check($this->variantRow($a['id'])['regular_price'] === '0.000000', 'zero is stored as zero');
        $this->check($this->variantRow($c['id'])['regular_price'] === null, 'unpriced variation stays empty');
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
        $this->productIds = [];
    }
}

(new PricingModeTest())->run();
