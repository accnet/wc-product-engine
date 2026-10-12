<?php
/**
 * T07 Unit Test — Options, values, and combination integrity verification.
 *
 * Verifies R23 (unicode_value_key), R24 (hash_collision_simulated),
 * R25 (rename_reorder_preserve_id), R26 (any_vs_empty), R27 (no_cartesian_variants),
 * AC-T07-01 (parent bounds and defaults validation), and AC-T07-02 (duplicate combination rejection).
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\DuplicateCombinationException;
use WPE\Infrastructure\Database\CombinationHashCollisionException;

class T07Test
{
    private \wpdb $wpdb;
    private OptionsRepository $optRepo;
    private ProductRepository $prodRepo;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->optRepo = new OptionsRepository($wpdb);
        $this->prodRepo = new ProductRepository($wpdb);
    }

    public function run(): int
    {
        echo "=== Running T07 Unit Tests ===\n";

        $r23 = $this->testUnicodeValueKey();
        $r24 = $this->testHashCollisionSimulated();
        $r25 = $this->testRenameReorderPreserveId();
        $r26 = $this->testAnyVsEmpty();
        $r27 = $this->testNoCartesianVariants();
        $ac01 = $this->testParentBoundsAndDefaultsValidation();
        $ac02 = $this->testDuplicateCombinationRejection();

        echo "\n=== Test Summary ===\n";
        echo "R23 (unicode_value_key): " . ($r23 ? "PASSED" : "FAILED") . "\n";
        echo "R24 (hash_collision_simulated): " . ($r24 ? "PASSED" : "FAILED") . "\n";
        echo "R25 (rename_reorder_preserve_id): " . ($r25 ? "PASSED" : "FAILED") . "\n";
        echo "R26 (any_vs_empty): " . ($r26 ? "PASSED" : "FAILED") . "\n";
        echo "R27 (no_cartesian_variants): " . ($r27 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T07-01 (parent_bounds_defaults): " . ($ac01 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T07-02 (duplicate_combination_report): " . ($ac02 ? "PASSED" : "FAILED") . "\n";

        if ($r23 && $r24 && $r25 && $r26 && $r27 && $ac01 && $ac02) {
            echo "All T07 tests passed successfully.\n";
            return 0;
        }

        echo "T07 tests failed.\n";
        return 1;
    }

    /**
     * R23: Verify Unicode labels and keys preservation without corruption.
     */
    private function testUnicodeValueKey(): bool
    {
        echo "[R23] Verifying Unicode labels and keys...\n";

        $prod = $this->createTestProduct('Unicode Test Product');
        $pId = (int)$prod['id'];

        $opt = $this->optRepo->createOption($pId, [
            'name' => 'Màu Sắc (Color 🇻🇳)',
            'key'  => 'mau-sac',
        ]);

        $val1 = $this->optRepo->createOptionValue((int)$opt['id'], [
            'label'     => 'Đỏ Thẫm 🔴',
            'value_key' => 'do-tham-red',
        ]);

        $val2 = $this->optRepo->createOptionValue((int)$opt['id'], [
            'label' => 'Xanh Biển Sâu 🌊',
        ]);

        if ($val1['label'] !== 'Đỏ Thẫm 🔴' || $val1['value_key'] !== 'do-tham-red') {
            echo "  [FAIL] Unicode value label or key corrupted for val1.\n";
            return false;
        }

        if ($val2['label'] !== 'Xanh Biển Sâu 🌊') {
            echo "  [FAIL] Unicode value label corrupted for val2.\n";
            return false;
        }

        // Test vector building with Unicode label
        $vector = $this->optRepo->buildCombinationVector($pId, [
            $opt['id'] => 'Đỏ Thẫm 🔴'
        ]);

        if (empty($vector) || $vector[0]['value_id'] !== (int)$val1['id']) {
            echo "  [FAIL] Vector matching with Unicode label failed.\n";
            return false;
        }

        echo "  [OK] Unicode labels and keys preserved and resolved cleanly.\n";
        return true;
    }

    /**
     * R24: Verify simulated hash collision triggers vector comparison rather than treating hash as equal.
     */
    private function testHashCollisionSimulated(): bool
    {
        echo "[R24] Verifying vector comparison upon simulated hash collision...\n";

        $prod = $this->createTestVariableProduct('Collision Test Product');
        $pId = (int)$prod['id'];
        $var1 = (int)$prod['variations'][0]['id'];
        $var2 = (int)$prod['variations'][1]['id'];

        $opts = $this->optRepo->getOptionsByProductId($pId);
        $optColor = $opts[0];
        $valRed = $optColor['values'][0];
        $valBlue = $optColor['values'][1];

        $fixedHash = str_repeat('e', 64);
        $simulator = fn($vec) => $fixedHash;

        // Assign to variant 1 with fixed simulated hash
        $this->optRepo->assignVariantCombination($pId, $var1, [$optColor['id'] => $valRed['id']], $simulator);

        // Attempt to assign differing vector (valBlue) to variant 2 with identical simulated hash
        $caught = false;
        try {
            $this->optRepo->assignVariantCombination($pId, $var2, [$optColor['id'] => $valBlue['id']], $simulator);
        } catch (CombinationHashCollisionException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected CombinationHashCollisionException for differing vectors with colliding hash.\n";
            return false;
        }

        echo "  [OK] Hash collision triggered full vector comparison and rejected false equality.\n";
        return true;
    }

    /**
     * R25: Verify rename and reorder preserves option/value IDs.
     */
    private function testRenameReorderPreserveId(): bool
    {
        echo "[R25] Verifying rename and reorder preserves option and value IDs...\n";

        $prod = $this->createTestProduct('Preserve ID Product');
        $pId = (int)$prod['id'];

        $opt = $this->optRepo->createOption($pId, [
            'name'     => 'Original Option Name',
            'key'      => 'orig-key',
            'position' => 0,
        ]);
        $optId = (int)$opt['id'];

        $val = $this->optRepo->createOptionValue($optId, [
            'label'    => 'Original Value Label',
            'position' => 0,
        ]);
        $valId = (int)$val['id'];

        // Rename option
        $renamedOpt = $this->optRepo->updateOption($optId, [
            'name' => 'Renamed Option Name'
        ]);

        if ((int)$renamedOpt['id'] !== $optId || $renamedOpt['name'] !== 'Renamed Option Name') {
            echo "  [FAIL] Option rename altered ID or failed to update name.\n";
            return false;
        }

        // Rename value
        $renamedVal = $this->optRepo->updateOptionValue($valId, [
            'label' => 'Renamed Value Label'
        ]);

        if ((int)$renamedVal['id'] !== $valId || $renamedVal['label'] !== 'Renamed Value Label') {
            echo "  [FAIL] Value rename altered ID or failed to update label.\n";
            return false;
        }

        // Reorder option
        $reorderedOpt = $this->optRepo->updateOption($optId, [
            'position' => 5
        ]);

        if ((int)$reorderedOpt['id'] !== $optId || (int)$reorderedOpt['position'] !== 5) {
            echo "  [FAIL] Option reorder altered ID or failed to update position.\n";
            return false;
        }

        echo "  [OK] Option and value IDs strictly preserved across rename and reorder.\n";
        return true;
    }

    /**
     * R26: Verify distinction between wildcard ANY and empty string value.
     */
    private function testAnyVsEmpty(): bool
    {
        echo "[R26] Verifying ANY wildcard vs empty string value semantics...\n";

        $prod = $this->createTestProduct('Any Vs Empty Product');
        $pId = (int)$prod['id'];

        $opt = $this->optRepo->createOption($pId, [
            'name' => 'Custom Engraving',
            'key'  => 'engraving',
        ]);
        $optId = (int)$opt['id'];

        // Create an explicit empty string value
        $valEmpty = $this->optRepo->createOptionValue($optId, [
            'label'     => 'None / Blank',
            'value_key' => '', // Empty string value
        ]);

        // Vector 1: Empty string value (is_any = 0, value_id = $valEmpty['id'])
        $vectorEmpty = $this->optRepo->buildCombinationVector($pId, [
            $optId => $valEmpty['id']
        ]);

        // Vector 2: Wildcard ANY (is_any = 1, value_id = null)
        $vectorAny = $this->optRepo->buildCombinationVector($pId, [
            $optId => '*'
        ]);

        if ($vectorEmpty[0]['is_any'] !== 0 || $vectorEmpty[0]['value_id'] !== (int)$valEmpty['id']) {
            echo "  [FAIL] Vector for empty string value incorrectly configured.\n";
            return false;
        }

        if ($vectorAny[0]['is_any'] !== 1 || $vectorAny[0]['value_id'] !== null) {
            echo "  [FAIL] Vector for wildcard ANY incorrectly configured.\n";
            return false;
        }

        $keyEmpty = $this->optRepo->buildCombinationKey($vectorEmpty);
        $keyAny   = $this->optRepo->buildCombinationKey($vectorAny);

        if ($keyEmpty === $keyAny) {
            echo "  [FAIL] Combination keys for empty string and wildcard ANY collided.\n";
            return false;
        }

        echo "  [OK] Wildcard ANY and empty string produce distinct vectors and keys.\n";
        return true;
    }

    /**
     * R27: Verify adding options does not generate Cartesian product variants.
     */
    private function testNoCartesianVariants(): bool
    {
        echo "[R27] Verifying adding options does not generate Cartesian variants...\n";

        $prod = $this->createTestVariableProduct('No Cartesian Product');
        $pId = (int)$prod['id'];

        // Initial variants count
        $initialCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d",
            $pId
        ));

        // Add an extra option with 3 values
        $this->optRepo->createOption($pId, [
            'name'   => 'Material Type',
            'key'    => 'material',
            'values' => ['Cotton', 'Polyester', 'Silk'],
        ]);

        // Count variants again
        $afterCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d",
            $pId
        ));

        if ($afterCount !== $initialCount) {
            echo "  [FAIL] Variant count changed from {$initialCount} to {$afterCount} after adding option!\n";
            return false;
        }

        echo "  [OK] Option created without implicit Cartesian variant generation (variant count: {$afterCount}).\n";
        return true;
    }

    /**
     * AC-T07-01: Verify rejection of values belonging to other parents and default value validation.
     */
    private function testParentBoundsAndDefaultsValidation(): bool
    {
        echo "[AC-T07-01] Verifying parent boundary bounds and default value validation...\n";

        $prod1 = $this->createTestProduct('Parent Product 1');
        $prod2 = $this->createTestProduct('Parent Product 2');

        $opt1 = $this->optRepo->createOption((int)$prod1['id'], [
            'name'   => 'Size P1',
            'values' => ['S', 'M'],
        ]);

        $opt2 = $this->optRepo->createOption((int)$prod2['id'], [
            'name'   => 'Size P2',
            'values' => ['L', 'XL'],
        ]);

        $valFromProd2 = $opt2['values'][0]; // Belongs to prod2

        // Attempt to build combination vector on prod1 using value from prod2
        $caught = false;
        try {
            $this->optRepo->buildCombinationVector((int)$prod1['id'], [
                $opt1['id'] => $valFromProd2['id']
            ]);
        } catch (\InvalidArgumentException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected InvalidArgumentException when using value belonging to another product.\n";
            return false;
        }

        // Test invalid default_value_id validation
        $caughtDefault = false;
        try {
            $this->optRepo->updateOption((int)$opt1['id'], [
                'default_value_id' => $valFromProd2['id'] // Does not belong to opt1
            ]);
        } catch (\InvalidArgumentException $e) {
            $caughtDefault = true;
        }

        if (!$caughtDefault) {
            echo "  [FAIL] Expected InvalidArgumentException for invalid default_value_id.\n";
            return false;
        }

        echo "  [OK] Parent boundary enforcement and default_value_id validation passed.\n";
        return true;
    }

    /**
     * AC-T07-02: Verify duplicate combinations are reported and not silently overwritten.
     */
    private function testDuplicateCombinationRejection(): bool
    {
        echo "[AC-T07-02] Verifying duplicate combinations are reported and rejected...\n";

        $prod = $this->createTestVariableProduct('Duplicate Combination Product');
        $pId = (int)$prod['id'];
        $var1 = (int)$prod['variations'][0]['id'];
        $var2 = (int)$prod['variations'][1]['id'];

        $opts = $this->optRepo->getOptionsByProductId($pId);
        $optColor = $opts[0];
        $valRed = $optColor['values'][0];

        // Assign to variant 1
        $this->optRepo->assignVariantCombination($pId, $var1, [$optColor['id'] => $valRed['id']]);

        // Attempt to assign exact same combination to variant 2
        $caught = false;
        try {
            $this->optRepo->assignVariantCombination($pId, $var2, [$optColor['id'] => $valRed['id']]);
        } catch (DuplicateCombinationException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected DuplicateCombinationException for identical combination on active variants.\n";
            return false;
        }

        echo "  [OK] Duplicate combination rejected with DuplicateCombinationException; no silent overwrite.\n";
        return true;
    }

    private function createTestProduct(string $title): array
    {
        $sku = 'T07-TEST-' . mt_rand(100000, 999999);
        return $this->prodRepo->create([
            'name'          => $title,
            'type'          => 'simple',
            'sku'           => $sku,
            'regular_price' => '100000.000000',
        ]);
    }

    private function createTestVariableProduct(string $title): array
    {
        $sku = 'T07-VAR-' . mt_rand(100000, 999999);
        $prod = $this->prodRepo->create([
            'name'          => $title,
            'type'          => 'variable',
            'sku'           => $sku,
            'regular_price' => '150000.000000',
            'variations'    => [
                [
                    'sku'           => $sku . '-V1',
                    'regular_price' => '150000.000000',
                    'attributes'    => ['Color' => 'Red'],
                ],
                [
                    'sku'           => $sku . '-V2',
                    'regular_price' => '150000.000000',
                    'attributes'    => ['Color' => 'Blue'],
                ],
            ]
        ]);

        // Also setup options table for this product
        $this->optRepo->createOption((int)$prod['id'], [
            'name'   => 'Color',
            'key'    => 'color',
            'values' => ['Red', 'Blue'],
        ]);

        return $prod;
    }
}

$test = new T07Test();
exit($test->run());
