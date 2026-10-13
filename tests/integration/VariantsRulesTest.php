<?php

/**
 * P010.6 — Variants rules: selective generator, stable variation IDs, protection of values in use, inventory modes
 * (own, shared, untracked), stock policy preview and the bulk scope with token and one write per stock owner.
 *
 * Runs against the real ProductEditorService on a disposable product. Validation cases must leave the database
 * untouched (the product version is compared before and after).
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\EditorValidationException;
use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Admin\ProductEditor\VariantRulesService;

class VariantsRulesTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private VariantRulesService $rules;
    private array $productIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
        $this->rules = new VariantRulesService($wpdb);
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
        echo "Running VariantsRules tests...\n";
        try {
            $this->pureRules();
            $this->flow();
            $this->cleanup();
            echo "All VariantsRules tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function createProduct(): int
    {
        $id = wp_insert_post(['post_title' => 'Variants Rules Test ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
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

    private function invRow(int $ownerId): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->wpdb->prefix}wpe_inventory WHERE owner_id = %d", $ownerId), ARRAY_A) ?: null;
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

    private function colorOption(array $labels, array $extra = []): array
    {
        return $extra + ['name' => 'Color', 'values' => array_map(static fn($l) => is_array($l) ? $l : ['label' => $l], $labels)];
    }

    private function sizeOption(array $labels, array $extra = []): array
    {
        return $extra + ['name' => 'Size', 'values' => array_map(static fn($l) => is_array($l) ? $l : ['label' => $l], $labels)];
    }

    /** Rows of the editor data keyed by "Color/Size" label (current labels). */
    private function byLabel(array $data): array
    {
        $out = [];
        foreach ($data['variations'] as $v) {
            $out[implode('/', $v['combination'])] = $v;
        }
        return $out;
    }

    private function liveCount(int $productId): int
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d AND deleted_at IS NULL", $productId));
    }

    private function pureRules(): void
    {
        echo ' - pure rules...';
        $this->check(VariantRulesService::comboKey(['Color' => 'Black', 'Size' => 'M']) === VariantRulesService::comboKey(['size' => ' m ', 'COLOR' => 'BLACK']), 'combination key ignores case, spaces and order');
        $this->check(VariantRulesService::comboKey(['Color' => 'Black']) !== VariantRulesService::comboKey(['Color' => 'White']), 'different values differ');
        $this->check(VariantRulesService::inventoryMode(true, true) === 'own' && VariantRulesService::inventoryMode(true, false) === 'own', 'a variation that manages stock is own');
        $this->check(VariantRulesService::inventoryMode(false, true) === 'shared', 'inherits product stock');
        $this->check(VariantRulesService::inventoryMode(false, false) === 'untracked', 'no stock anywhere is untracked');

        $rows = [
            ['id' => 11, 'sku' => 'AA-1', 'status' => 'publish', 'pricing_mode' => 'shared', 'inventory_mode' => 'own', 'combination' => ['Color' => 'Black', 'Size' => 'S'], 'effective_price' => ['state' => 'priced'], 'image_asset_id' => null],
            ['id' => 12, 'sku' => 'AA-2', 'status' => 'private', 'pricing_mode' => 'own', 'inventory_mode' => 'shared', 'combination' => ['Color' => 'Black', 'Size' => 'M'], 'effective_price' => ['state' => 'unpriced'], 'image_asset_id' => '5'],
            ['id' => 13, 'sku' => 'BB-3', 'status' => 'publish', 'pricing_mode' => 'own', 'inventory_mode' => 'shared', 'combination' => ['Color' => 'White', 'Size' => 'M'], 'effective_price' => ['state' => 'priced'], 'image_asset_id' => null],
            ['id' => 14, 'sku' => 'BB-4', 'status' => 'publish', 'pricing_mode' => 'own', 'inventory_mode' => 'untracked', 'combination' => ['Color' => 'White', 'Size' => 'L'], 'effective_price' => ['state' => 'priced'], 'image_asset_id' => null],
        ];
        $scope = fn(array $s) => $this->rules->resolveScope(1, 3, $s, $rows);
        $this->check($scope(['type' => 'ids', 'ids' => [13, 11]])['ids'] === [11, 13], 'ids come back in saved order');
        $this->check($scope(['type' => 'ids', 'ids' => ['12', 12]])['count'] === 1, 'duplicate ids collapse');
        $this->check($scope(['type' => 'filter', 'filter' => ['search' => 'aa-']])['ids'] === [11, 12], 'search matches the SKU');
        $this->check($scope(['type' => 'filter', 'filter' => ['search' => 'white']])['ids'] === [13, 14], 'search matches the combination');
        $this->check($scope(['type' => 'filter', 'filter' => ['options' => ['color' => ['Black'], 'Size' => 'm']]])['ids'] === [12], 'option filter is case-insensitive and combined');
        $this->check($scope(['type' => 'filter', 'filter' => ['pricing_mode' => 'shared']])['ids'] === [11], 'pricing mode filter');
        $this->check($scope(['type' => 'filter', 'filter' => ['inventory_mode' => 'shared']])['ids'] === [12, 13], 'inventory mode filter');
        $this->check($scope(['type' => 'filter', 'filter' => ['price_state' => 'missing']])['ids'] === [12], 'missing price filter');
        $this->check($scope(['type' => 'filter', 'filter' => ['has_image' => true]])['ids'] === [12], 'image filter');
        $this->check($scope(['type' => 'filter', 'filter' => ['status' => 'private']])['count'] === 1, 'status filter');
        $this->check($scope(['type' => 'filter', 'filter' => []])['count'] === 4, 'an empty filter is the whole saved set');
        $this->expectInvalid(fn() => $scope(['type' => 'ids', 'ids' => [99]]), 'unknown_variation', '/scope/ids', 'unknown id');
        $this->expectInvalid(fn() => $scope(['type' => 'ids', 'ids' => ['x']]), 'invalid_value', '/scope/ids/0', 'bad id');
        $this->expectInvalid(fn() => $scope(['type' => 'nope']), 'invalid_value', '/scope/type', 'bad type');

        $resolved = $scope(['type' => 'ids', 'ids' => [11, 12]]);
        $this->rules->assertScopeToken(1, 3, [11, 12], $resolved['token']);
        foreach ([[[11, 12, 13], 3, 'grown ids'], [[11], 3, 'shrunk ids'], [[11, 12], 4, 'another product version']] as [$ids, $ver, $label]) {
            $this->expectInvalid(fn() => $this->rules->assertScopeToken(1, $ver, $ids, $resolved['token']), 'bulk_token_invalid', '/scope/token', $label);
        }
        $this->expectInvalid(fn() => $this->rules->assertScopeToken(2, 3, [11, 12], $resolved['token']), 'bulk_token_invalid', '/scope/token', 'another product');
        $this->expectInvalid(fn() => $this->rules->assertScopeToken(1, 3, [11, 12], 'garbage'), 'bulk_token_invalid', '/scope/token', 'garbage token');
        $expired = (time() - 10) . '.' . substr($resolved['token'], strpos($resolved['token'], '.') + 1);
        $this->expectInvalid(fn() => $this->rules->assertScopeToken(1, 3, [11, 12], $expired), 'bulk_token_invalid', '/scope/token', 'forged expiry');

        $groups = VariantRulesService::stockOwnerGroups($rows, 1);
        $this->check(count($groups) === 2, 'own rows each have an owner, shared rows share the product owner, untracked has none');
        $shared = array_values(array_filter($groups, fn($g) => $g['owner_kind'] === 'product'));
        $this->check(count($shared) === 1 && $shared[0]['owner_id'] === 1 && $shared[0]['variation_ids'] === [12, 13] && $shared[0]['writes'] === 1, 'the product owner is written once for both shared rows');
        echo " OK\n";
    }

    private function flow(): void
    {
        $productId = $this->createProduct();
        $this->service->getProductEditorData($productId);
        $prefix = $this->wpdb->prefix;

        // ---- selective generator
        echo ' - selective generator...';
        $options = [$this->colorOption(['Black', 'White', 'Red']), $this->sizeOption(['S', 'M'])];
        $all = $this->service->previewCombinations($productId, $options);
        $this->check($all['total_possible'] === 6 && $all['new_count'] === 6 && $all['existing_count'] === 0, 'six combinations, none saved yet');
        $sel = $this->service->previewCombinations($productId, $options, [
            ['Color' => 'Black', 'Size' => 'S'],
            ['color' => 'white', 'size' => 'm'],
            ['Color' => 'Black', 'Size' => 'S'],
            ['Color' => 'Green', 'Size' => 'S'],
        ])['selection'];
        $this->check($sel['requested'] === 4 && $sel['create_count'] === 2 && $sel['expected_total'] === 2, 'two valid new combinations out of four');
        $this->check(count($sel['invalid']) === 2 && $sel['invalid'][0]['reason'] === 'duplicate_selection' && $sel['invalid'][1]['reason'] === 'unknown_value', 'a duplicate and an unknown value are reported');
        $this->check($sel['will_create'][1]['combination'] === ['Color' => 'White', 'Size' => 'M'], 'the preview normalizes the selection to the real labels');
        $big = [$this->colorOption(array_map(fn($i) => "c{$i}", range(1, 50))), $this->sizeOption(array_map(fn($i) => "s{$i}", range(1, 50)))];
        $this->expectInvalid(fn() => $this->service->previewCombinations($productId, $big), 'too_many_combinations', '/variation_options', 'too many combinations');
        echo " OK\n";

        echo ' - generate only the selection, keep the rest...';
        $res = $this->service->saveProductVariants($productId, [
            'expected_version'  => $this->version($productId),
            'variation_options' => $options,
            'variations'        => [
                ['combination' => ['Color' => 'Black', 'Size' => 'S'], 'status' => 'publish'],
                ['combination' => ['Color' => 'White', 'Size' => 'M'], 'status' => 'publish'],
            ],
        ]);
        $this->check($res['success'] === true && $this->liveCount($productId) === 2, 'only the two selected variations exist, not all six');
        $rows = $this->byLabel($res['data']);
        $blackS = $rows['Black/S'];
        $whiteM = $rows['White/M'];
        $after = $this->service->previewCombinations($productId, $options, [['Color' => 'Black', 'Size' => 'S'], ['Color' => 'Red', 'Size' => 'M']]);
        $this->check($after['existing_count'] === 2 && $after['new_count'] === 4 && $after['selection']['create_count'] === 1 && count($after['selection']['already_exist']) === 1, 'saved combinations are shown as existing');
        $this->check($after['selection']['already_exist'][0]['variant_id'] === $blackS['id'] && $after['selection']['expected_total'] === 3, 'existing keeps its id and the expected total counts the new one');
        echo " OK\n";

        // ---- stable ids
        echo ' - stable variation IDs across option edits...';
        $valueIds = $this->wpdb->get_results("SELECT ov.id, ov.label FROM {$prefix}wpe_option_values ov JOIN {$prefix}wpe_options o ON o.id = ov.option_id WHERE o.product_id = {$productId}", OBJECT_K);
        $valueIdByLabel = [];
        foreach ($valueIds as $id => $row) {
            $valueIdByLabel[$row->label] = (int)$id;
        }
        $optionIds = $this->wpdb->get_results("SELECT id, name FROM {$prefix}wpe_options WHERE product_id = {$productId}", OBJECT_K);
        $colorId = 0;
        $sizeId = 0;
        foreach ($optionIds as $id => $row) {
            if ($row->name === 'Color') {
                $colorId = (int)$id;
            } else {
                $sizeId = (int)$id;
            }
        }
        $before = $this->wpdb->get_results("SELECT variant_id, option_id, value_id FROM {$prefix}wpe_variant_values WHERE variant_id IN ({$blackS['id']},{$whiteM['id']}) ORDER BY variant_id, option_id", ARRAY_A);

        // Rename Black to Noir (by id), reverse the value order, rename the Size option (by id), set a default.
        $res = $this->service->saveProductVariants($productId, [
            'expected_version'  => $this->version($productId),
            'variation_options' => [
                $this->colorOption([['id' => $valueIdByLabel['Red'], 'label' => 'Red'], ['id' => $valueIdByLabel['White'], 'label' => 'White'], ['id' => $valueIdByLabel['Black'], 'label' => 'Noir']], ['id' => $colorId, 'default_value' => 'White']),
                $this->sizeOption(['M', 'S'], ['id' => $sizeId, 'name' => 'Dimension']),
            ],
            'variations'        => [
                ['id' => $blackS['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']],
                ['id' => $whiteM['id'], 'combination' => ['Color' => 'White', 'Dimension' => 'M']],
            ],
        ]);
        $this->check($this->liveCount($productId) === 2, 'renaming and reordering creates and deletes no variation');
        $after = $this->wpdb->get_results("SELECT variant_id, option_id, value_id FROM {$prefix}wpe_variant_values WHERE variant_id IN ({$blackS['id']},{$whiteM['id']}) ORDER BY variant_id, option_id", ARRAY_A);
        $this->check($before === $after, 'variations keep the same option and value ids after the rename');
        $rows = $this->byLabel($res['data']);
        $this->check(isset($rows['Noir/S']) && $rows['Noir/S']['id'] === $blackS['id'] && $rows['White/M']['id'] === $whiteM['id'], 'variation IDs are unchanged and show the new labels');
        $colorData = null;
        foreach ($res['data']['variation_options'] as $o) {
            if ($o['id'] === $colorId) {
                $colorData = $o;
            }
        }
        $this->check($colorData !== null && $colorData['values'][2]['id'] === $valueIdByLabel['Black'] && $colorData['values'][2]['label'] === 'Noir', 'the renamed value keeps its id');
        $this->check(array_column($colorData['values'], 'label') === ['Red', 'White', 'Noir'], 'value order follows the submitted order');
        $this->check($colorData['default_value_id'] === $valueIdByLabel['White'], 'the default value is stored by label');
        echo " OK\n";

        echo ' - a row without id reuses the saved combination...';
        $res = $this->service->saveProductVariants($productId, [
            'expected_version' => $this->version($productId),
            'variations'       => [
                ['combination' => ['color' => 'noir', 'dimension' => 's'], 'status' => 'private'],
                ['id' => $whiteM['id'], 'combination' => ['Color' => 'White', 'Dimension' => 'M']],
            ],
        ]);
        $this->check($this->liveCount($productId) === 2, 'no duplicate variation was created');
        $rows = $this->byLabel($res['data']);
        $this->check($rows['Noir/S']['id'] === $blackS['id'] && $rows['Noir/S']['status'] === 'private', 'the saved variation of that combination is the one updated');
        echo " OK\n";

        echo ' - options edits alone never create or delete variations...';
        $res = $this->service->saveProductVariants($productId, [
            'expected_version'  => $this->version($productId),
            'variation_options' => [
                $this->colorOption([['id' => $valueIdByLabel['Red'], 'label' => 'Red'], ['id' => $valueIdByLabel['White'], 'label' => 'White'], ['id' => $valueIdByLabel['Black'], 'label' => 'Noir'], ['label' => 'Green']], ['id' => $colorId, 'default_value' => null]),
                $this->sizeOption(['M', 'S', 'L'], ['id' => $sizeId, 'name' => 'Dimension']),
            ],
        ]);
        $this->check($this->liveCount($productId) === 2 && count($res['data']['variations']) === 2, 'new values do not generate variations');
        $this->check(array_column($res['data']['variations'], 'id') === [$blackS['id'], $whiteM['id']] || array_column($res['data']['variations'], 'id') === [$whiteM['id'], $blackS['id']], 'variation ids stay the same');
        $colorData = null;
        foreach ($res['data']['variation_options'] as $o) {
            if ($o['id'] === $colorId) {
                $colorData = $o;
            }
        }
        $this->check($colorData['default_value_id'] === null, 'a null default clears it');
        echo " OK\n";

        // ---- values in use are protected
        echo ' - a value or option in use cannot be removed silently...';
        $v0 = $this->version($productId);
        $dropWhite = [
            $this->colorOption([['id' => $valueIdByLabel['Red'], 'label' => 'Red'], ['id' => $valueIdByLabel['Black'], 'label' => 'Noir']], ['id' => $colorId]),
            $this->sizeOption(['M', 'S', 'L'], ['id' => $sizeId, 'name' => 'Dimension']),
        ];
        $e = $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $dropWhite]), 'value_in_use', '/variation_options/0', 'removing a used value');
        $this->check(($e->context()['in_use']['/variation_options/0'] ?? []) === [$whiteM['id']], 'the blocking variation is named: ' . json_encode($e->context()));
        $this->check($this->version($productId) === $v0 && $this->liveCount($productId) === 2, 'nothing was written');
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => [$this->colorOption(['Red', 'White', 'Noir'], ['id' => $colorId])]]),
            'option_in_use',
            '/variation_options',
            'removing a used option'
        );
        $this->expectInvalid(
            fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $dropWhite, 'variations' => [
                ['id' => $blackS['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']],
                ['id' => $whiteM['id'], 'combination' => ['Color' => 'White', 'Dimension' => 'M']],
            ]]),
            'unknown_value',
            '/variations/1/combination/Color',
            'a variation that still names the removed value'
        );
        echo " OK\n";

        echo ' - duplicate and unknown combinations are rejected...';
        $opts = [
            $this->colorOption([['id' => $valueIdByLabel['Red'], 'label' => 'Red'], ['id' => $valueIdByLabel['White'], 'label' => 'White'], ['id' => $valueIdByLabel['Black'], 'label' => 'Noir']], ['id' => $colorId]),
            $this->sizeOption(['M', 'S', 'L'], ['id' => $sizeId, 'name' => 'Dimension']),
        ];
        $keep = [['id' => $blackS['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']], ['id' => $whiteM['id'], 'combination' => ['Color' => 'White', 'Dimension' => 'M']]];
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $opts, 'variations' => array_merge($keep, [['combination' => ['Color' => 'Red', 'Dimension' => 'L']], ['combination' => ['color' => 'RED', 'dimension' => 'l']]])]), 'duplicate_combination', '/variations/3/combination', 'two new rows with one combination');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $opts, 'variations' => [$keep[0], ['id' => $whiteM['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']]]]), 'duplicate_combination', '/variations/1/combination', 'two saved rows describing one combination');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $opts, 'variations' => array_merge($keep, [['combination' => ['Color' => 'Purple', 'Dimension' => 'L']]])]), 'unknown_value', '/variations/2/combination/Color', 'unknown value');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $opts, 'variations' => array_merge($keep, [['combination' => ['Material' => 'Wool']]])]), 'unknown_option', '/variations/2/combination/Material', 'unknown option');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => $opts, 'variations' => [$keep[0], $keep[0]]]), 'duplicate_variation', '/variations/1/id', 'one saved variation submitted twice');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => [$this->colorOption([['label' => 'A'], ['label' => 'a']])]]), 'duplicate_value', '/variation_options/0/values/1', 'duplicate value label');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => [$this->colorOption([['id' => 999999999, 'label' => 'A']], ['id' => $colorId])]]), 'value_not_in_option', '/variation_options/0/values/0/id', 'a value id of another option');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v0, 'variation_options' => [$this->colorOption(['A'], ['default_value' => 'B'])]]), 'default_value_invalid', '/variation_options/0/default_value', 'default value outside the values');
        $this->check($this->version($productId) === $v0 && $this->liveCount($productId) === 2, 'rejected requests changed nothing');
        echo " OK\n";

        echo ' - removing a value together with its variation is allowed...';
        $res = $this->service->saveProductVariants($productId, [
            'expected_version'  => $v0,
            'variation_options' => $dropWhite,
            'variations'        => [['id' => $blackS['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']]],
        ]);
        $this->check($res['success'] === true && $this->liveCount($productId) === 1, 'the variation was removed explicitly, so its value could go');
        $this->check(!(bool)$this->wpdb->get_var("SELECT COUNT(1) FROM {$prefix}wpe_option_values WHERE id = " . (int)$valueIdByLabel['White']), 'the unused value is gone');
        echo " OK\n";

        // ---- inventory modes
        echo ' - inventory modes own, shared and untracked...';
        $opts = [$this->colorOption([['id' => $valueIdByLabel['Red'], 'label' => 'Red'], ['id' => $valueIdByLabel['Black'], 'label' => 'Noir']], ['id' => $colorId]), $this->sizeOption(['M', 'S', 'L'], ['id' => $sizeId, 'name' => 'Dimension'])];
        $res = $this->service->saveProductVariants($productId, [
            'expected_version'  => $this->version($productId),
            'variation_options' => $opts,
            'product'           => ['stock' => ['manage_stock' => true, 'stock_quantity' => 20]],
            'variations'        => [
                ['id' => $blackS['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S'], 'inventory_mode' => 'own', 'stock_quantity' => 5],
                ['combination' => ['Color' => 'Noir', 'Dimension' => 'M'], 'inventory_mode' => 'shared'],
                ['combination' => ['Color' => 'Red', 'Dimension' => 'L'], 'manage_stock' => false],
            ],
        ]);
        $rows = $this->byLabel($res['data']);
        $own = $rows['Noir/S'];
        $sharedA = $rows['Noir/M'];
        $sharedB = $rows['Red/L'];
        $this->check($own['inventory_mode'] === 'own' && $own['stock_quantity'] === 5.0 && $own['inventory']['stock_owner_id'] === $own['id'], 'own variation has its own owner and quantity');
        $this->check($sharedA['inventory_mode'] === 'shared' && $sharedA['inventory']['stock_owner_id'] === $productId, 'shared variation points at the product as owner');
        $this->check($sharedB['inventory_mode'] === 'shared', 'the legacy flag manage_stock=false inherits the product stock');
        $this->check((float)$this->invRow($productId)['quantity'] === 20.0, 'the product holds the shared quantity');
        $ownVersion = (int)$this->invRow($own['id'])['version'];

        // Absent keys keep the stored values.
        $res = $this->service->saveProductVariants($productId, [
            'expected_version' => $this->version($productId),
            'variations'       => [
                ['id' => $own['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']],
                ['id' => $sharedA['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'M']],
                ['id' => $sharedB['id'], 'combination' => ['Color' => 'Red', 'Dimension' => 'L']],
            ],
        ]);
        $rows = $this->byLabel($res['data']);
        $this->check($rows['Noir/S']['inventory_mode'] === 'own' && $rows['Noir/S']['stock_quantity'] === 5.0, 'a request without stock keys keeps an own stock');
        $this->check($rows['Noir/M']['inventory_mode'] === 'shared', 'and keeps a shared row shared');
        $this->check((int)$this->invRow($own['id'])['version'] === $ownVersion + 1, 'the inventory row keeps its history and bumps its version');
        $this->check($this->invRow($own['id'])['low_stock_amount'] === null, 'inventory is updated in place, not replaced');

        $v1 = $this->version($productId);
        $row = fn(array $extra) => ['id' => $own['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']] + $extra;
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v1, 'variations' => [$row(['inventory_mode' => 'untracked'])]]), 'untracked_conflicts_with_product_stock', '/variations/0/inventory_mode', 'untracked while the product manages stock');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v1, 'variations' => [$row(['inventory_mode' => 'own', 'stock_quantity' => ''])]]), 'quantity_required', '/variations/0/stock_quantity', 'own without quantity');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v1, 'variations' => [$row(['inventory_mode' => 'sideways'])]]), 'invalid_inventory_mode', '/variations/0/inventory_mode', 'unknown mode');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v1, 'variations' => [$row(['stock_status' => 'maybe'])]]), 'invalid_value', '/variations/0/stock_status', 'bad stock status');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v1, 'variations' => [$row(['backorders' => 'sometimes'])]]), 'invalid_value', '/variations/0/backorders', 'bad backorders');
        $this->expectInvalid(fn() => $this->service->saveProductVariants($productId, ['expected_version' => $v1, 'product' => ['stock' => ['manage_stock' => false]], 'variations' => [$row(['inventory_mode' => 'shared'])]]), 'shared_requires_product_stock', '/variations/0/inventory_mode', 'shared when the same save turns product stock off');
        $this->check($this->version($productId) === $v1, 'invalid stock requests wrote nothing');
        echo " OK\n";

        echo ' - stock policy preview...';
        $off = $this->service->previewStockPolicy($productId, ['manage_stock' => false]);
        $this->check($off['effect'] === 'shared_become_untracked' && $off['requires_confirmation'] === true && count($off['affected_variations']) === 2, 'turning product stock off affects the shared variations');
        $this->check($off['own_stock_unchanged_count'] === 1 && $off['affected_variations'][0]['from'] === 'shared' && $off['affected_variations'][0]['to'] === 'untracked', 'own stock is untouched and the change is described');
        $same = $this->service->previewStockPolicy($productId, ['manage_stock' => true, 'stock_quantity' => 30]);
        $this->check($same['effect'] === 'none' && $same['requires_confirmation'] === false && $same['proposed']['stock_quantity'] === 30.0, 'a quantity change alone needs no confirmation');
        $res = $this->service->saveProductVariants($productId, ['expected_version' => $this->version($productId), 'product' => ['stock' => ['manage_stock' => false]], 'variations' => [
            ['id' => $own['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']],
            ['id' => $sharedA['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'M']],
            ['id' => $sharedB['id'], 'combination' => ['Color' => 'Red', 'Dimension' => 'L'], 'inventory_mode' => 'untracked'],
        ]]);
        $rows = $this->byLabel($res['data']);
        $this->check($rows['Noir/M']['inventory_mode'] === 'untracked' && $rows['Red/L']['inventory_mode'] === 'untracked' && $rows['Noir/S']['inventory_mode'] === 'own', 'with product stock off the inherited rows are untracked and own stays own');
        $on = $this->service->previewStockPolicy($productId, ['manage_stock' => true, 'stock_quantity' => 9]);
        $this->check($on['effect'] === 'untracked_become_shared' && count($on['affected_variations']) === 2, 'turning it back on is previewed too');
        $this->service->saveProductVariants($productId, ['expected_version' => $this->version($productId), 'product' => ['stock' => ['manage_stock' => true, 'stock_quantity' => 20]], 'variations' => [
            ['id' => $own['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S']],
            ['id' => $sharedA['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'M']],
            ['id' => $sharedB['id'], 'combination' => ['Color' => 'Red', 'Dimension' => 'L']],
        ]]);
        echo " OK\n";

        // ---- bulk scope
        echo ' - bulk scope, token and one write per stock owner...';
        $this->service->saveProductVariants($productId, ['expected_version' => $this->version($productId), 'common_price' => ['regular' => '50'], 'variations' => [
            ['id' => $own['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'S'], 'pricing_mode' => 'own', 'regular_price' => '45'],
            ['id' => $sharedA['id'], 'combination' => ['Color' => 'Noir', 'Dimension' => 'M'], 'pricing_mode' => 'shared'],
            ['id' => $sharedB['id'], 'combination' => ['Color' => 'Red', 'Dimension' => 'L'], 'pricing_mode' => 'shared'],
        ]]);
        $data = $this->service->getProductEditorData($productId);
        $rows = $this->byLabel($data);
        $this->check($rows['Noir/M']['pricing_mode'] === 'shared' && $rows['Red/L']['pricing_mode'] === 'shared' && $rows['Noir/S']['pricing_mode'] === 'own', 'two rows follow the common price, one has its own');

        $preview = $this->service->previewBulk($productId, ['type' => 'filter', 'filter' => ['inventory_mode' => 'shared']], ['stock_quantity' => 7]);
        $this->check($preview['scope']['type'] === 'filter' && $preview['scope']['count'] === 2, 'a filter is resolved on the server over the full saved set');
        $this->check(count($preview['stock_groups']) === 1 && $preview['stock_groups'][0]['owner_kind'] === 'product' && count($preview['stock_groups'][0]['variation_ids']) === 2 && $preview['stock_groups'][0]['writes'] === 1, 'both shared rows are one group, one write');
        $this->check(count($preview['stock_writes']) === 1 && $preview['stock_writes'][0]['quantity_before'] === 20.0 && $preview['stock_writes'][0]['quantity_after'] === 7.0, 'the preview states the single shared write');

        $all = $this->service->previewBulk($productId, ['type' => 'ids', 'ids' => array_column($data['variations'], 'id')], ['stock_quantity' => 7]);
        $this->check(count($all['stock_groups']) === 2 && count($all['stock_writes']) === 2, 'own row plus the shared owner: two owners, two writes');

        // Tampering and staleness
        $ids = $all['scope']['ids'];
        $tampered = array_slice($ids, 0, 2);
        $this->expectInvalid(fn() => $this->service->applyBulkEdit($productId, $tampered, $all['scope']['token'], ['stock_quantity' => 7]), 'bulk_token_invalid', '/scope/token', 'ids that are not the previewed ones');
        $this->expectInvalid(fn() => $this->service->applyBulkEdit($productId, [...$ids, 999999999], $all['scope']['token'], ['stock_quantity' => 7]), 'bulk_token_invalid', '/scope/token', 'a grown scope');

        $productInvBefore = (int)$this->invRow($productId)['version'];
        $res = $this->service->applyBulkEdit($productId, $ids, $all['scope']['token'], ['stock_quantity' => 7]);
        $this->check($res['success'] === true && $res['bulk']['count'] === 3, 'apply succeeds for the previewed scope');
        $rows = $this->byLabel($res['data']);
        $this->check($rows['Noir/S']['stock_quantity'] === 5.0 || $rows['Noir/S']['stock_quantity'] === 7.0, 'own quantity row exists');
        $this->check($rows['Noir/S']['stock_quantity'] === 7.0, 'the own variation took the quantity');
        $this->check((float)$this->invRow($productId)['quantity'] === 7.0, 'the shared owner took the quantity');
        $this->check((int)$this->invRow($productId)['version'] === $productInvBefore + 1, 'the shared owner was written exactly once for two variations');
        $this->expectInvalid(fn() => $this->service->applyBulkEdit($productId, $ids, $all['scope']['token'], ['stock_quantity' => 3]), 'bulk_token_invalid', '/scope/token', 'a preview token is single-version: stale after the save');

        // A bulk price on a shared row turns it into an own-price row, and never touches the others.
        $data = $this->service->getProductEditorData($productId);
        $rows = $this->byLabel($data);
        $target = $rows['Noir/M'];
        $other = $rows['Red/L'];
        $pv = $this->service->previewBulk($productId, ['type' => 'ids', 'ids' => [$target['id']]], ['regular_price' => ['type' => 'fixed', 'value' => 60]]);
        $change = $pv['diffs'][0]['changes'];
        $this->check(isset($change['pricing_mode']) && $change['pricing_mode']['before'] === 'shared' && $change['pricing_mode']['after'] === 'own', 'the preview shows the switch from shared to own price');
        $res = $this->service->applyBulkEdit($productId, $pv['scope']['ids'], $pv['scope']['token'], ['regular_price' => ['type' => 'fixed', 'value' => 60]]);
        $rows = $this->byLabel($res['data']);
        $this->check($rows['Noir/M']['pricing_mode'] === 'own' && (float)$rows['Noir/M']['regular_price'] === 60.0, 'the edited row has its own price');
        $this->check($rows['Red/L']['pricing_mode'] === 'shared' && (float)$rows['Red/L']['regular_price'] === 50.0 && $rows['Red/L']['id'] === $other['id'], 'a row outside the scope keeps the common price');
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

(new VariantsRulesTest())->run();
