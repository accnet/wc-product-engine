<?php

/**
 * P010.4 — Product information block: product SKU, price and sale schedule, stock configuration, weight and
 * dimensions, tax and flags, descriptive attributes and their validation.
 *
 * Validation cases must leave the database untouched; each one compares the product version and the stored row
 * before and after.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\EditorValidationException;
use WPE\Admin\ProductEditor\ProductEditorService;

class ProductInfoBlockTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private array $productIds = [];
    private array $fakeRegistryIds = [];
    private int $checks = 0;
    private string $tag;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
        $this->tag = 'PIB' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
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
        echo "Running ProductInfoBlock tests...\n";
        try {
            $this->flow();
            $this->cleanup();
            echo "All ProductInfoBlock tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function createProduct(string $type): int
    {
        $id = wp_insert_post(['post_title' => 'Product Info Test ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create product');
        }
        $this->productIds[] = (int)$id;
        $this->service->getProductEditorData((int)$id); // create the native row
        $this->setType((int)$id, $type);
        return (int)$id;
    }

    private function setType(int $id, string $type): void
    {
        $this->wpdb->update("{$this->wpdb->prefix}wpe_products", ['type' => $type], ['id' => $id]);
    }

    private function productRow(int $id): array
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $id), ARRAY_A);
    }

    private function invRow(int $id): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->wpdb->prefix}wpe_inventory WHERE owner_id = %d", $id), ARRAY_A) ?: null;
    }

    private function registryRows(int $id): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->wpdb->prefix}wpe_sku_registry WHERE object_id = %d", $id), ARRAY_A);
    }

    private function snapshot(int $id): string
    {
        return json_encode([$this->productRow($id), $this->invRow($id), $this->registryRows($id)]);
    }

    private function expectInvalid(int $productId, array $payload, string $reason, string $pointer, string $label): void
    {
        $before = $this->snapshot($productId);
        $payload = ['expected_version' => (int)$this->productRow($productId)['version']] + $payload;
        try {
            $this->service->saveProductVariants($productId, $payload);
        } catch (EditorValidationException $e) {
            $found = false;
            foreach ($e->details() as $d) {
                if ($d['reason'] === $reason && $d['pointer'] === $pointer) {
                    $found = true;
                }
            }
            $this->check($found, "{$label}: expected {$reason} at {$pointer}, got " . json_encode($e->details()));
            $this->check($this->snapshot($productId) === $before, "{$label}: rejected save must write nothing");
            return;
        }
        throw new \AssertionError("{$label}: expected validation to fail");
    }

    private function save(int $productId, array $payload): array
    {
        $res = $this->service->saveProductVariants($productId, ['expected_version' => (int)$this->productRow($productId)['version']] + $payload);
        $this->check($res['success'] === true, 'save succeeds');
        return $res['data'];
    }

    private function flow(): void
    {
        $prefix = $this->wpdb->prefix;
        $sku = $this->tag . '-MAIN';
        $from = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $to = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);

        $simple = $this->createProduct('simple');

        // ---- Save 1: everything the block manages on a simple product
        echo ' - simple product information saves and reads back...';
        $v0 = (int)$this->productRow($simple)['version'];
        $data = $this->save($simple, [
            'product' => [
                'sku' => $sku, 'regular_price' => '25', 'sale_price' => '20', 'sale_from' => $from, 'sale_to' => $to,
                'weight' => '1.5', 'length' => '10', 'width' => '5', 'height' => '2',
                'tax_status' => 'none', 'tax_class' => 'reduced-rate', 'sold_individually' => true, 'virtual' => false,
                'stock' => ['manage_stock' => true, 'stock_quantity' => 7, 'backorders' => 'notify', 'stock_status' => 'instock', 'low_stock_amount' => 2],
            ],
            'descriptive_attributes' => [
                ['name' => 'Material', 'values' => ['Cotton']],
                ['name' => 'Brand', 'values' => ['AcceNet']],
                ['name' => 'Fit', 'values' => ['Regular', 'Slim']],
            ],
        ]);
        $p = $data['product'];
        $this->check($p['version'] === $v0 + 1, 'product version bumps once');
        $this->check($p['sku'] === $sku, 'SKU is stored');
        $this->check($p['regular_price'] === '25.000000' && $p['sale_price'] === '20.000000', 'prices read as six decimals');
        $this->check(substr((string)$p['sale_from'], 0, 10) === gmdate('Y-m-d', time() - 3600) && $p['sale_to'] !== null, 'sale schedule stored as UTC');
        $this->check($p['weight'] === '1.500000' && $p['length'] === '10.000000' && $p['width'] === '5.000000' && $p['height'] === '2.000000', 'weight and dimensions');
        $this->check($p['tax_status'] === 'none' && $p['tax_class'] === 'reduced-rate' && $p['sold_individually'] === true && $p['virtual'] === false, 'tax and flags');
        $this->check($p['stock']['manage_stock'] === true && $p['stock']['stock_quantity'] === 7.0 && $p['stock']['backorders'] === 'notify' && $p['stock']['low_stock_amount'] === 2.0, 'stock configuration');
        $this->check($p['parent_stock']['stock_quantity'] === 7.0, 'older parent_stock key is still returned');
        $reg = $this->registryRows($simple);
        $this->check(count($reg) === 1 && $reg[0]['original_sku'] === $sku && $reg[0]['object_kind'] === 'product', 'product SKU is registered once as product');
        $names = array_column($data['descriptive_attributes'], 'name');
        $this->check($names === ['Material', 'Brand', 'Fit'], 'descriptive attributes saved in order');
        $this->check($this->productRow($simple)['sale_from'] !== null, 'sale window is in the product columns');
        echo " OK\n";

        // ---- Validation (no write)
        echo ' - validation rejects without writing...';
        $this->expectInvalid($simple, ['product' => ['sale_price' => '30', 'regular_price' => '25']], 'sale_above_regular', '/product/sale_price', 'sale above regular');
        $this->expectInvalid($simple, ['product' => ['regular_price' => '-1']], 'invalid_amount', '/product/regular_price', 'negative price');
        $this->expectInvalid($simple, ['product' => ['weight' => '-3']], 'invalid_measure', '/product/weight', 'negative weight');
        $this->expectInvalid($simple, ['product' => ['height' => 'tall']], 'invalid_measure', '/product/height', 'text dimension');
        $this->expectInvalid($simple, ['product' => ['tax_status' => 'sometimes']], 'invalid_value', '/product/tax_status', 'tax status');
        $this->expectInvalid($simple, ['product' => ['sold_individually' => 'maybe']], 'invalid_value', '/product/sold_individually', 'bad flag');
        $this->expectInvalid($simple, ['product' => ['stock' => ['manage_stock' => true, 'stock_quantity' => null]]], 'quantity_required', '/product/stock/stock_quantity', 'managed stock needs quantity');
        $this->expectInvalid($simple, ['product' => ['stock' => ['backorders' => 'always']]], 'invalid_value', '/product/stock/backorders', 'backorders');
        $this->expectInvalid($simple, ['product' => ['stock' => ['stock_status' => 'gone']]], 'invalid_value', '/product/stock/stock_status', 'stock status');
        $this->expectInvalid($simple, ['product' => ['stock' => ['low_stock_amount' => -1]]], 'invalid_value', '/product/stock/low_stock_amount', 'low stock');
        $this->expectInvalid($simple, ['product' => ['sku' => str_repeat('x', 192)]], 'sku_too_long', '/product/sku', 'long SKU');
        $this->expectInvalid($simple, ['descriptive_attributes' => [['name' => 'Brand', 'values' => ['a']], ['name' => 'brand', 'values' => ['b']]]], 'duplicate_option_name', '/descriptive_attributes/1/name', 'duplicate names');
        $this->expectInvalid($simple, ['descriptive_attributes' => [['name' => '  ', 'values' => ['a']]]], 'name_required', '/descriptive_attributes/0/name', 'empty name');
        $this->expectInvalid($simple, ['descriptive_attributes' => [['name' => 'Fit', 'values' => ['  ']]]], 'values_required', '/descriptive_attributes/0/values', 'no values');
        $this->expectInvalid(
            $simple,
            ['descriptive_attributes' => [['name' => 'Color', 'values' => ['x']]], 'variation_options' => [['name' => 'color', 'values' => [['label' => 'Red']]]]],
            'duplicate_option_name',
            '/descriptive_attributes/0/name',
            'attribute name equals an option name'
        );
        echo " OK\n";

        echo ' - SKU clashes...';
        $other = $this->createProduct('simple');
        $otherSku = $this->tag . '-OTHER';
        $this->wpdb->update("{$prefix}wpe_products", ['sku' => $otherSku], ['id' => $other]);
        $this->wpdb->insert("{$prefix}wpe_sku_registry", ['normalized_sku' => $otherSku, 'object_id' => $other, 'object_kind' => 'product', 'original_sku' => $otherSku]);
        $this->expectInvalid($simple, ['product' => ['sku' => strtolower($otherSku)]], 'sku_in_use', '/product/sku', 'clash with another product, case-insensitive');
        $columnOnly = $this->tag . '-COL';
        $this->wpdb->update("{$prefix}wpe_products", ['sku' => $columnOnly], ['id' => $other]);
        $this->expectInvalid($simple, ['product' => ['sku' => $columnOnly]], 'sku_in_use', '/product/sku', 'clash with a product SKU that is only in the column');
        $fakeVariation = 2000000000 + random_int(1, 99999);
        $this->fakeRegistryIds[] = $fakeVariation;
        $varSku = $this->tag . '-VAR';
        $this->wpdb->insert("{$prefix}wpe_sku_registry", ['normalized_sku' => $varSku, 'object_id' => $fakeVariation, 'object_kind' => 'variation', 'original_sku' => $varSku]);
        $this->expectInvalid($simple, ['product' => ['sku' => $varSku]], 'sku_in_use_by_variation', '/product/sku', 'product SKU may not equal a variation SKU');
        $this->expectInvalid(
            $simple,
            ['product' => ['sku' => $this->tag . '-NEW'], 'variations' => [['combination' => ['Size' => 'S'], 'sku' => strtolower($this->tag . '-NEW')]]],
            'sku_in_use_by_variation',
            '/product/sku',
            'product SKU equals a SKU of the same request variations'
        );
        echo " OK\n";

        // ---- Save 2: partial update keeps untouched fields, clears nullable ones, renames SKU
        echo ' - partial update, clear and rename...';
        $newSku = $this->tag . '-RENAMED';
        $data = $this->save($simple, ['product' => ['weight' => '', 'sku' => $newSku, 'regular_price' => '0', 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'stock' => ['manage_stock' => false]]]);
        $p = $data['product'];
        $this->check($p['weight'] === null, 'empty weight clears to null');
        $this->check($p['length'] === '10.000000' && $p['tax_class'] === 'reduced-rate', 'untouched fields keep their value');
        $this->check($p['regular_price'] === '0.000000', 'a price of zero is kept as zero, not null');
        $this->check($p['sale_price'] === null && $p['sale_from'] === null, 'sale cleared with its schedule');
        $this->check($p['stock']['manage_stock'] === false && $p['stock']['stock_quantity'] === null, 'unmanaged stock has no quantity');
        $this->check($p['stock']['backorders'] === 'notify', 'backorders setting survives switching management off');
        $reg = $this->registryRows($simple);
        $this->check(count($reg) === 1 && $reg[0]['original_sku'] === $newSku && (string)$reg[0]['normalized_sku'] === $newSku, 'registry follows the renamed SKU with one row');
        $data = $this->save($simple, ['product' => ['sku' => '']]);
        $this->check($data['product']['sku'] === '' && count($this->registryRows($simple)) === 0, 'clearing the SKU removes the registry row');
        $data = $this->save($simple, ['product' => ['regular_price' => '', 'sku' => $sku]]);
        $this->check($data['product']['regular_price'] === null, 'an empty price is not set, not zero');
        echo " OK\n";

        // ---- Variable product: shared stock, price fields rejected
        echo ' - variable product keeps shared stock and rejects price fields...';
        $this->setType($simple, 'variable');
        $this->expectInvalid($simple, ['product' => ['regular_price' => '10']], 'price_not_applicable', '/product/regular_price', 'price on variable');
        $data = $this->save($simple, ['product' => ['stock' => ['manage_stock' => true, 'stock_quantity' => 40, 'stock_status' => 'instock']]]);
        $this->check($data['product']['stock']['stock_quantity'] === 40.0 && $data['product']['regular_price'] === null, 'shared stock saved, price fields are empty for a variable product');
        echo " OK\n";

        // ---- Option positions must not drift when a save carries no option lists
        echo ' - option positions stay stable...';
        $positions = fn() => $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT name, position FROM {$prefix}wpe_options WHERE product_id = %d ORDER BY position ASC",
            $simple
        ), ARRAY_A);
        $got = $positions();
        $this->check(array_column($got, 'position') === ['0', '1', '2'] && array_column($got, 'name') === ['Material', 'Brand', 'Fit'], 'positions are consecutive after saves that carried no options: ' . json_encode($got));
        $this->save($simple, ['variation_options' => [['name' => 'Size', 'values' => [['label' => 'S'], ['label' => 'M']]]]]);
        $got = $positions();
        $this->check(array_column($got, 'name') === ['Material', 'Brand', 'Fit', 'Size'] && array_column($got, 'position') === ['0', '1', '2', '3'], 'a group missing from the request is renumbered in place: ' . json_encode($got));
        echo " OK\n";
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->productIds as $id) {
            $optionIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$prefix}wpe_options WHERE product_id = %d", $id)));
            if ($optionIds) {
                $list = implode(',', $optionIds);
                $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE option_id IN ({$list})");
                $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN ({$list})");
            }
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_catalog", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
        foreach ($this->fakeRegistryIds as $id) {
            $this->wpdb->delete("{$prefix}wpe_sku_registry", ['object_id' => $id]);
        }
        $this->productIds = [];
        $this->fakeRegistryIds = [];
    }
}

(new ProductInfoBlockTest())->run();
