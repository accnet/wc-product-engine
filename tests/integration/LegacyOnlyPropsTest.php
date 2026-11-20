<?php
/**
 * P021: WooCommerce 11.3 customs props are legacy-only and fail closed under native authority.
 *
 * - Every runtime get_data() prop of simple, variable and variation products is mapped or excluded.
 * - The native data store refuses to save a non-empty customs prop and writes nothing.
 * - Cutover refuses products (or parents of variations) that carry customs data.
 *
 * Run: wp --path=<isolated root> eval-file tests/integration/LegacyOnlyPropsTest.php
 * It creates products, so do not point it at a database other sessions write to.
 */

use WPE\Application\Rollout\CutoverRunner;
use WPE\Compatibility\WooDataStore\LegacyOnlyProps;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\ProductVariationDataStore;

final class LegacyOnlyPropsTest
{
    private int $checks = 0;
    /** @var list<int> */
    private array $cleanup = [];

    public function run(): int
    {
        try {
            $this->testMappingCoverage();
            $this->testDataStoreRefuses();
            $this->testCutoverRefuses();
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
            return 1;
        } finally {
            foreach (array_reverse($this->cleanup) as $id) {
                wp_delete_post($id, true);
            }
        }
        echo "All legacy-only props tests passed ({$this->checks} checks).\n";
        return 0;
    }

    private function ok(bool $cond, string $label): void
    {
        if (!$cond) {
            throw new \RuntimeException($label);
        }
        $this->checks++;
        echo "  [OK] {$label}\n";
    }

    private function testMappingCoverage(): void
    {
        echo "[P021] mapping coverage against the live WooCommerce runtime\n";
        $map = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/contracts/compatibility/wc-props-mapping.json'), true);
        $known = array_merge(array_keys($map['props_mapping'] ?? []), array_keys($map['excluded_props'] ?? []), ['meta_data']);
        $runtime = [];
        foreach ([new \WC_Product_Simple(), new \WC_Product_Variable(), new \WC_Product_Variation()] as $p) {
            $runtime = array_merge($runtime, array_keys($p->get_data()));
        }
        $missing = array_values(array_diff(array_unique($runtime), $known));
        $this->ok($missing === [], 'every simple/variable/variation prop is mapped or excluded' . ($missing ? ': missing ' . implode(',', $missing) : ''));
        $this->ok(array_keys($map['excluded_props'] ?? []) === array_keys(LegacyOnlyProps::PROPS), 'excluded props equal the guarded legacy-only list');
        foreach ($map['excluded_props'] as $prop => $row) {
            $this->ok(($row['policy'] ?? '') === 'legacy_only_blocks_native', "{$prop} policy is legacy_only_blocks_native");
        }
    }

    private function testDataStoreRefuses(): void
    {
        echo "[P021] native data store refuses legacy-only props\n";
        global $wpdb;
        $table = $wpdb->prefix . 'wpe_products';
        $vtable = $wpdb->prefix . 'wpe_variants';
        $count = static fn (string $t): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");

        $plain = new \WC_Product_Simple();
        $plain->set_name('P021 plain');
        $this->ok(LegacyOnlyProps::nonEmpty($plain) === [], 'a product without customs data has no legacy-only props');

        $p = new \WC_Product_Simple();
        $p->set_name('P021 customs');
        $p->set_customs_description('Cotton shirt');
        $this->ok(LegacyOnlyProps::nonEmpty($p) === ['customs_description'], 'a customs description is detected');

        $before = $count($table);
        $code = null;
        try {
            (new ProductDataStore())->create($p);
        } catch (\WC_Data_Exception $e) {
            $code = $e->getErrorCode();
        }
        $this->ok($code === 'wpe_legacy_only_prop', 'native create refuses it with wpe_legacy_only_prop');
        $this->ok($count($table) === $before && $p->get_id() === 0, 'native create wrote nothing');

        $code = null;
        try {
            (new ProductDataStore())->update($p);
        } catch (\WC_Data_Exception $e) {
            $code = $e->getErrorCode();
        }
        $this->ok($code === 'wpe_legacy_only_prop', 'native update refuses it');

        $v = new \WC_Product_Variation();
        $v->set_parent_id(1);
        $v->set_customs_country_of_origin('VN');
        $vBefore = $count($vtable);
        $code = null;
        try {
            (new ProductVariationDataStore())->create($v);
        } catch (\WC_Data_Exception $e) {
            $code = $e->getErrorCode();
        }
        $this->ok($code === 'wpe_legacy_only_prop' && $count($vtable) === $vBefore, 'native variation create refuses it and writes nothing');
    }

    private function testCutoverRefuses(): void
    {
        echo "[P021] cutover keeps products with customs data on legacy\n";
        global $wpdb;
        // WooCommerce's own CPT store writes these products, as on a legacy-authority site.
        $cpt = static function (\WC_Product $p): int {
            $p->set_status('draft');
            $store = new \WC_Product_Data_Store_CPT();
            $p->get_id() ? $store->update($p) : $store->create($p);
            return (int) $p->get_id();
        };

        $plain = new \WC_Product_Simple();
        $plain->set_name('P021 plain legacy');
        $plainId = $cpt($plain);
        $this->cleanup[] = $plainId;

        $withData = new \WC_Product_Simple();
        $withData->set_name('P021 customs legacy');
        $withData->set_customs_commodity_code('6109100010');
        $dataId = $cpt($withData);
        $this->cleanup[] = $dataId;

        $parent = new \WC_Product_Variable();
        $parent->set_name('P021 variable legacy');
        $parentId = $cpt($parent);
        $this->cleanup[] = $parentId;
        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parentId);
        $variation->set_customs_description('Linen');
        $store = new \WC_Product_Variation_Data_Store_CPT();
        $store->create($variation);
        $this->cleanup[] = (int) $variation->get_id();

        $this->ok(get_post_meta($dataId, '_customs_commodity_code', true) !== '', 'WooCommerce stored the commodity code in postmeta');
        $this->ok(LegacyOnlyProps::productsWithData($wpdb, [$plainId]) === [], 'a product without customs data is not flagged');
        $this->ok(LegacyOnlyProps::productsWithData($wpdb, [$dataId]) === [$dataId], 'a product with customs data is flagged');
        $this->ok(LegacyOnlyProps::productsWithData($wpdb, [$parentId]) === [$parentId], 'a variation with customs data flags its parent');

        $runner = new CutoverRunner($wpdb);
        $msg = '';
        try {
            $runner->startCutover('p021-test', 'not-a-token', ['product_ids' => [$plainId, $dataId]]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
        }
        $this->ok(str_contains($msg, 'customs data') && str_contains($msg, (string) $dataId), 'cutover is rejected and names the product');

        $msg = '';
        try {
            $runner->startCutover('p021-test', 'not-a-token', ['product_ids' => [$plainId]]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
        }
        $this->ok(!str_contains($msg, 'customs data'), 'a cohort without customs data passes the pre-check (and meets the gate check next)');
    }
}

exit((new LegacyOnlyPropsTest())->run());
