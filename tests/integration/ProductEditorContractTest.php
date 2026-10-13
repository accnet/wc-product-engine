<?php

/**
 * P005.1 — Contract tests for ProductEditorService against real data.
 *
 * Covers the defects that were hidden while assertions were compiled out:
 *  - `taxonomies` key consumed by the editor
 *  - variation combinations contain only options used for variation
 *  - bulk-edit preview contract ({type,value} price actions, per-field `changes`, scope)
 *  - the save result reports the projection outcome instead of swallowing it
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

// assert() is compiled out when zend.assertions=-1; fail fast instead of passing vacuously.
if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorService;

class ProductEditorContractTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private array $productIds = [];
    private array $variantPostIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
    }

    public function run(): void
    {
        echo "Running ProductEditor contract tests...\n";
        try {
            $productId = $this->createProduct();
            $this->saveFixture($productId);
            $this->testTaxonomiesKey($productId);
            $this->testCombinationExcludesDescriptive($productId);
            $this->testCombinationPreviewMatchesExisting($productId);
            $this->testBulkPreviewContract($productId);
            $this->testBulkPreviewScope($productId);
            $this->testSaveReportsProjection($productId);
            $this->cleanup();
            echo "All ProductEditor contract tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function check(bool $cond, string $message): void
    {
        $this->checks++;
        if (!$cond) {
            throw new \AssertionError($message);
        }
    }

    private function createProduct(): int
    {
        $id = wp_insert_post([
            'post_title'  => 'Contract Test Product ' . uniqid(),
            'post_status' => 'publish',
            'post_type'   => 'product',
        ]);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create product');
        }
        wp_set_object_terms($id, 'variable', 'product_type');
        $this->productIds[] = (int)$id;
        return (int)$id;
    }

    private function fixturePayload(int $version): array
    {
        $variations = [];
        foreach ([['S', 'Red', 20.00], ['S', 'Blue', 20.00], ['M', 'Red', 25.00], ['M', 'Blue', 25.00]] as $row) {
            $variations[] = [
                'combination'    => ['Size' => $row[0], 'Color' => $row[1]],
                'sku'            => 'CT-' . $row[0] . '-' . $row[1] . '-' . uniqid(),
                'regular_price'  => $row[2],
                'sale_price'     => null,
                'status'         => 'publish',
                'manage_stock'   => 0,
                'stock_quantity' => null,
            ];
        }
        // One variation that manages its own stock, to prove the stock guard in the preview.
        $variations[0]['manage_stock'] = 1;
        $variations[0]['stock_quantity'] = 10;

        return [
            'expected_version'       => $version,
            'descriptive_attributes' => [
                ['name' => 'Material', 'taxonomy' => null, 'is_visible' => 1, 'position' => 0, 'values' => ['Cotton', 'Polyester']],
                ['name' => 'Brand', 'taxonomy' => null, 'is_visible' => 1, 'position' => 1, 'values' => ['AcceNet']],
            ],
            'variation_options'      => [
                ['name' => 'Size', 'position' => 0, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]],
                ['name' => 'Color', 'position' => 1, 'values' => [['label' => 'Red', 'position' => 0], ['label' => 'Blue', 'position' => 1]]],
            ],
            'variations'             => $variations,
        ];
    }

    private function saveFixture(int $productId): void
    {
        $version = $this->service->getProductEditorData($productId)['product']['version'];
        $this->lastSave = $this->service->saveProductVariants($productId, $this->fixturePayload($version));
        $this->check($this->lastSave['success'] === true, 'Fixture save must succeed');
    }

    private array $lastSave = [];

    private function testTaxonomiesKey(int $productId): void
    {
        echo ' - taxonomies key...';
        $data = $this->service->getProductEditorData($productId);
        $this->check(array_key_exists('taxonomies', $data) && is_array($data['taxonomies']), '`taxonomies` must be an array');
        $this->check($data['taxonomies'] === $data['global_taxonomies'], '`global_taxonomies` must stay an alias');
        foreach ($data['taxonomies'] as $t) {
            foreach (['label', 'name', 'slug', 'terms'] as $k) {
                $this->check(array_key_exists($k, $t), "taxonomy entry must have `$k`");
            }
        }
        echo " OK\n";
    }

    private function testCombinationExcludesDescriptive(int $productId): void
    {
        echo ' - combination excludes descriptive attributes...';
        $data = $this->service->getProductEditorData($productId);
        $this->check(count($data['descriptive_attributes']) === 2, 'Fixture must have 2 descriptive attributes');
        $this->check(count($data['variations']) === 4, 'Fixture must have 4 variations');
        foreach ($data['variations'] as $v) {
            $keys = array_keys($v['combination']);
            sort($keys);
            $this->check($keys === ['Color', 'Size'], 'combination must only hold variation options, got ' . implode(',', $keys));
            $this->check(!in_array('*', $v['combination'], true), 'combination must not contain wildcard values');
            $this->check($v['attributes'] === $v['combination'], '`attributes` must equal `combination`');
        }
        echo " OK\n";
    }

    private function testCombinationPreviewMatchesExisting(int $productId): void
    {
        echo ' - generator recognises existing combinations...';
        $options = [
            ['name' => 'Size', 'values' => [['label' => 'S'], ['label' => 'M'], ['label' => 'L']]],
            ['name' => 'Color', 'values' => [['label' => 'Red'], ['label' => 'Blue']]],
        ];
        $preview = $this->service->previewCombinations($productId, $options);
        $this->check($preview['total_possible'] === 6, 'total must be 3 x 2');
        $this->check($preview['existing_count'] === 4, 'existing must be 4 even with descriptive attributes present, got ' . $preview['existing_count']);
        $this->check($preview['new_count'] === 2, 'new must be 2');
        foreach ($preview['new_combinations'] as $c) {
            $this->check($c['combination']['Size'] === 'L', 'only Size L may be new');
        }
        echo " OK\n";
    }

    private function testBulkPreviewContract(int $productId): void
    {
        echo ' - bulk preview contract...';
        $data = $this->service->getProductEditorData($productId);
        $byCombo = [];
        foreach ($data['variations'] as $v) {
            $byCombo[$v['combination']['Size'] . '/' . $v['combination']['Color']] = $v;
        }
        $sRed = $byCombo['S/Red'];   // 20.00, manages stock (10)
        $mRed = $byCombo['M/Red'];   // 25.00, parent stock

        $p = $this->service->previewBulkEdit($productId, [$sRed['id']], ['regular_price' => ['type' => 'fixed', 'value' => 88.0]]);
        $this->check($p['affected_count'] === 1, 'one row affected');
        $c = $p['diffs'][0]['changes'];
        $this->check($c['regular_price'] === ['before' => 20.0, 'after' => 88.0], 'fixed price change');
        $this->check(array_keys($c) === ['regular_price'], 'only the changed field is listed, got ' . implode(',', array_keys($c)));

        $p = $this->service->previewBulkEdit($productId, [$mRed['id']], ['regular_price' => ['type' => 'increase_percent', 'value' => 10]]);
        $this->check($p['diffs'][0]['changes']['regular_price']['after'] === 27.5, 'increase 10 percent of 25 is 27.5');

        $p = $this->service->previewBulkEdit($productId, [$mRed['id']], ['regular_price' => ['type' => 'decrease_fixed', 'value' => 30]]);
        $this->check($p['diffs'][0]['changes']['regular_price']['after'] === '', 'a non-positive price becomes empty');

        $p = $this->service->previewBulkEdit($productId, [$mRed['id']], ['regular_price' => 12.5]);
        $this->check($p['diffs'][0]['changes']['regular_price']['after'] === 12.5, 'a plain number means fixed price (compat)');

        $p = $this->service->previewBulkEdit($productId, [$mRed['id']], ['sale_price' => ['type' => 'decrease_percent', 'value' => 20]]);
        $this->check(!isset($p['diffs'][0]['changes']['sale_price']), 'sale price from empty stays empty, so no change is listed');

        // Stock guard: quantity is meaningless for a variation that uses the parent stock.
        $p = $this->service->previewBulkEdit($productId, [$mRed['id']], ['stock_quantity' => 5]);
        $this->check(!isset($p['diffs'][0]['changes']['stock_quantity']), 'quantity must not change on a parent-stock variation');
        $p = $this->service->previewBulkEdit($productId, [$sRed['id']], ['stock_quantity' => 5]);
        $this->check($p['diffs'][0]['changes']['stock_quantity']['after'] === 5.0, 'quantity changes on an own-stock variation');

        $p = $this->service->previewBulkEdit($productId, [$sRed['id']], ['status' => 'private']);
        $this->check($p['diffs'][0]['changes']['status'] === ['before' => 'publish', 'after' => 'private'], 'status change');
        echo " OK\n";
    }

    private function testBulkPreviewScope(int $productId): void
    {
        echo ' - bulk preview scope...';
        $ids = array_column($this->service->getProductEditorData($productId)['variations'], 'id');
        $p = $this->service->previewBulkEdit($productId, [$ids[0], $ids[1], 999999999], ['status' => 'private']);
        $this->check($p['affected_count'] === 2, 'unknown ids are skipped, only selected rows count');
        $this->check(array_column($p['diffs'], 'variant_id') === [$ids[0], $ids[1]], 'only the selected rows are in the preview');
        $before = $this->service->getProductEditorData($productId)['variations'];
        $this->check(array_column($before, 'status') === array_fill(0, 4, 'publish'), 'a preview must not change stored data');
        echo " OK\n";
    }

    private function testSaveReportsProjection(int $productId): void
    {
        echo ' - save reports projection outcome...';
        $this->check(isset($this->lastSave['projection']) && is_array($this->lastSave['projection']), 'save result must include `projection`');
        foreach (['legacy_mirror', 'catalog_index'] as $k) {
            $this->check(isset($this->lastSave['projection'][$k]) && is_string($this->lastSave['projection'][$k]), "projection must report `$k`");
            $this->check($this->lastSave['projection'][$k] !== 'unknown', "projection `$k` must be resolved, not unknown");
        }
        // The reported mirror status must agree with what actually happened to the WooCommerce shell.
        $variantId = (int)$this->service->getProductEditorData($productId)['variations'][0]['id'];
        $this->variantPostIds[] = $variantId;
        $post = get_post($variantId);
        $mirrored = $this->lastSave['projection']['legacy_mirror'] === 'mirrored';
        $this->check($mirrored === ((int)$post->post_parent === $productId), 'projection status must match the shell parent (status ' . $this->lastSave['projection']['legacy_mirror'] . ', parent ' . $post->post_parent . ')');
        echo " OK\n";
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->productIds as $id) {
            $variantIds = $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d", $id));
            $this->variantPostIds = array_merge($this->variantPostIds, array_map('intval', $variantIds));
            if (!empty($variantIds)) {
                $idList = implode(',', array_map('intval', $variantIds));
                $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$idList})");
            }
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
        foreach (array_unique($this->variantPostIds) as $vid) {
            wp_delete_post($vid, true);
        }
        $this->productIds = [];
        $this->variantPostIds = [];
    }
}

(new ProductEditorContractTest())->run();
