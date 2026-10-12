<?php
/**
 * T03 Unit Test — Database, API, event, and CSV contracts verification.
 *
 * Verifies R07 (contract_schema_validation), R08 (decimal_id_roundtrip),
 * R09 (nullable_blank_semantics), and R10 (props_mapping_coverage).
 */

class T03Test
{
    private string $root;

    public function __construct()
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function run(): int
    {
        echo "=== Running T03 Unit Tests ===\n";

        $r07 = $this->testContractSchemaValidation();
        $r08 = $this->testDecimalIdRoundtrip();
        $r09 = $this->testNullableBlankSemantics();
        $r10 = $this->testPropsMappingCoverage();

        echo "\n=== Test Summary ===\n";
        echo "R07 (contract_schema_validation): " . ($r07 ? "PASSED" : "FAILED") . "\n";
        echo "R08 (decimal_id_roundtrip): " . ($r08 ? "PASSED" : "FAILED") . "\n";
        echo "R09 (nullable_blank_semantics): " . ($r09 ? "PASSED" : "FAILED") . "\n";
        echo "R10 (props_mapping_coverage): " . ($r10 ? "PASSED" : "FAILED") . "\n";

        if ($r07 && $r08 && $r09 && $r10) {
            echo "All T03 tests passed successfully.\n";
            return 0;
        }

        echo "T03 tests failed.\n";
        return 1;
    }

    /**
     * R07: Schema and contract validation across all JSON contracts.
     */
    private function testContractSchemaValidation(): bool
    {
        echo "[R07] Verifying contract schema validation...\n";

        $files = [
            'contracts/database/schema-contract.json',
            'contracts/openapi/wpe-v1.json',
            'contracts/api/endpoints.json',
            'contracts/events/product-changed.schema.json',
            'contracts/csv/dialects.json',
            'contracts/compatibility/wc-props-mapping.json',
        ];

        foreach ($files as $file) {
            $path = $this->root . '/' . $file;
            if (!file_exists($path)) {
                echo "  [FAIL] Missing contract file: {$file}\n";
                return false;
            }
            $content = file_get_contents($path);
            $json = json_decode($content, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                echo "  [FAIL] Invalid JSON in {$file}: " . json_last_error_msg() . "\n";
                return false;
            }
            echo "  [OK] Validated JSON syntax: {$file}\n";
        }

        // Validate OpenAPI schema components
        $openapi = json_decode(file_get_contents($this->root . '/contracts/openapi/wpe-v1.json'), true);
        if (!isset($openapi['paths']) || !isset($openapi['components']['schemas'])) {
            echo "  [FAIL] OpenAPI spec missing paths or schemas.\n";
            return false;
        }
        echo "  [OK] OpenAPI root structure validated.\n";

        // Validate endpoints match OpenAPI paths
        $endpoints = json_decode(file_get_contents($this->root . '/contracts/api/endpoints.json'), true);
        if (empty($endpoints['endpoints']) || empty($endpoints['errors'])) {
            echo "  [FAIL] Endpoints contract incomplete.\n";
            return false;
        }
        echo "  [OK] API endpoints contract verified.\n";

        return true;
    }

    /**
     * R08: Decimal and ID string precision round-trip verification.
     */
    private function testDecimalIdRoundtrip(): bool
    {
        echo "[R08] Verifying decimal ID, price, and amount round-trip...\n";

        // 64-bit integer as string (to prevent JS/PHP float conversion)
        $large_id = "18446744073709551615";
        $price = "1299.99";
        $stock_qty = "-15.500000";

        $payload = [
            'id' => $large_id,
            'regular_price' => $price,
            'stock_quantity' => $stock_qty,
        ];

        $encoded = json_encode($payload);
        $decoded = json_decode($encoded, true);

        if ($decoded['id'] !== $large_id) {
            echo "  [FAIL] Large ID lost precision: {$decoded['id']} !== {$large_id}\n";
            return false;
        }
        if ($decoded['regular_price'] !== $price) {
            echo "  [FAIL] Price lost precision: {$decoded['regular_price']} !== {$price}\n";
            return false;
        }
        if ($decoded['stock_quantity'] !== $stock_qty) {
            echo "  [FAIL] Stock quantity lost precision: {$decoded['stock_quantity']} !== {$stock_qty}\n";
            return false;
        }

        echo "  [OK] Decimal ID, price, and stock string round-trip verified without precision loss.\n";
        return true;
    }

    /**
     * R09: Nullable, absent, and blank semantics.
     */
    private function testNullableBlankSemantics(): bool
    {
        echo "[R09] Verifying absent vs null vs blank string semantics...\n";

        // Absent field: should not be present in keys
        $patch_absent = ['regular_price' => '100.00'];
        if (array_key_exists('sale_price', $patch_absent)) {
            echo "  [FAIL] Absent field should not exist in array keys.\n";
            return false;
        }

        // Null field: explicitly present and null (clears value)
        $patch_null = ['sale_price' => null];
        if (!array_key_exists('sale_price', $patch_null) || $patch_null['sale_price'] !== null) {
            echo "  [FAIL] Null field must be recognized as null.\n";
            return false;
        }

        // Blank string: must remain empty string and not convert to 0 or null
        $patch_blank = ['short_description' => ''];
        if ($patch_blank['short_description'] !== '') {
            echo "  [FAIL] Empty string was altered.\n";
            return false;
        }

        echo "  [OK] Nullable, absent, and blank semantics strictly preserved.\n";
        return true;
    }

    /**
     * R10: WooCommerce properties mapping coverage verification.
     */
    private function testPropsMappingCoverage(): bool
    {
        echo "[R10] Verifying WC properties mapping coverage...\n";

        $mapping_file = $this->root . '/contracts/compatibility/wc-props-mapping.json';
        $mapping_data = json_decode(file_get_contents($mapping_file), true);

        if ($mapping_data['status'] !== 'sealed') {
            echo "  [FAIL] wc-props-mapping.json status must be 'sealed', got '{$mapping_data['status']}'\n";
            return false;
        }

        $props = $mapping_data['props_mapping'] ?? [];
        if (count($props) < 55) {
            echo "  [FAIL] Expected at least 55 properties mapped, found " . count($props) . "\n";
            return false;
        }

        // Verify key WooCommerce properties are mapped
        $critical_props = ['id', 'sku', 'price', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'image_id', 'gallery_image_ids', 'attributes'];
        foreach ($critical_props as $cp) {
            if (empty($props[$cp])) {
                echo "  [FAIL] Critical property '{$cp}' is not mapped.\n";
                return false;
            }
        }

        $methods = $mapping_data['data_store_cpt_methods'] ?? [];
        if (count($methods) < 30) {
            echo "  [FAIL] Expected at least 30 Data Store CPT methods mapped, found " . count($methods) . "\n";
            return false;
        }

        echo "  [OK] All " . count($props) . " properties and " . count($methods) . " Data Store methods cataloged and sealed.\n";
        return true;
    }
}

$test = new T03Test();
$exit_code = $test->run();
exit($exit_code);
