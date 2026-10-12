<?php
/**
 * T04 Integration Test — Fixture and differential test harness verification.
 *
 * Verifies R11 (fixture_invariants), R12 (diff_detects_wrong_price_stock_variant),
 * and R13 (snapshot_not_hiding_errors).
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

class T04Test
{
    private \WPE\Bootstrap\FixtureHarness $harness;

    public function __construct()
    {
        $this->harness = new \WPE\Bootstrap\FixtureHarness(54321);
    }

    public function run(): int
    {
        echo "=== Running T04 Integration Tests ===\n";

        $r11 = $this->testFixtureInvariants();
        $r12 = $this->testDiffDetectsWrongPriceStockVariant();
        $r13 = $this->testSnapshotNotHidingErrors();

        echo "\n=== Test Summary ===\n";
        echo "R11 (fixture_invariants): " . ($r11 ? "PASSED" : "FAILED") . "\n";
        echo "R12 (diff_detects_wrong_price_stock_variant): " . ($r12 ? "PASSED" : "FAILED") . "\n";
        echo "R13 (snapshot_not_hiding_errors): " . ($r13 ? "PASSED" : "FAILED") . "\n";

        if ($r11 && $r12 && $r13) {
            echo "All T04 tests passed successfully.\n";
            return 0;
        }

        echo "T04 tests failed.\n";
        return 1;
    }

    /**
     * R11: Verify fixture generator adheres to domain invariants.
     */
    private function testFixtureInvariants(): bool
    {
        echo "[R11] Verifying fixture generator invariants...\n";

        $simple = $this->harness->generateProductFixture('simple');
        if ($simple['type'] !== 'simple' || empty($simple['sku']) || empty($simple['regular_price'])) {
            echo "  [FAIL] Simple product fixture missing invariant fields.\n";
            return false;
        }
        echo "  [OK] Simple product fixture invariants valid.\n";

        $variable = $this->harness->generateProductFixture('variable');
        if ($variable['type'] !== 'variable' || empty($variable['variations'])) {
            echo "  [FAIL] Variable product fixture missing variations.\n";
            return false;
        }

        // Check variation hierarchy and SKU uniqueness
        $skus = [$variable['sku']];
        foreach ($variable['variations'] as $v) {
            if ($v['parent_id'] !== $variable['id']) {
                echo "  [FAIL] Variation parent_id does not match parent id.\n";
                return false;
            }
            if (in_array($v['sku'], $skus, true)) {
                echo "  [FAIL] Duplicate SKU detected: {$v['sku']}\n";
                return false;
            }
            $skus[] = $v['sku'];
        }
        echo "  [OK] Variable product fixture has " . count($variable['variations']) . " unique variations with valid parent hierarchy.\n";

        // Check scale manifest (AC-T04-02)
        $manifest_100k = $this->harness->getScaleManifest('100k');
        $manifest_1m = $this->harness->getScaleManifest('1M');
        if ($manifest_100k['parent_products'] !== 100000 || $manifest_1m['parent_products'] !== 1000000) {
            echo "  [FAIL] Scale manifest counts incorrect.\n";
            return false;
        }
        echo "  [OK] Scale datasets defined with reproducible seeds (100k: seed {$manifest_100k['seed']}, 1M: seed {$manifest_1m['seed']}).\n";

        return true;
    }

    /**
     * R12: Verify differential comparator detects price, stock, and variant changes.
     */
    private function testDiffDetectsWrongPriceStockVariant(): bool
    {
        echo "[R12] Verifying differential comparator detects price/stock/variant mutations...\n";

        $base = $this->harness->generateProductFixture('variable');

        // Test 1: Price difference
        $cand_price = $base;
        $cand_price['regular_price'] = '999999.00';
        $res1 = $this->harness->compareSnapshots($base, $cand_price);
        if ($res1['identical'] !== false || empty($res1['differences'])) {
            echo "  [FAIL] Comparator did not detect regular_price difference.\n";
            return false;
        }
        echo "  [OK] Detected price mismatch.\n";

        // Test 2: Stock difference
        $cand_stock = $base;
        $cand_stock['stock_quantity'] = '5.000000';
        $res2 = $this->harness->compareSnapshots($base, $cand_stock);
        if ($res2['identical'] !== false || empty($res2['differences'])) {
            echo "  [FAIL] Comparator did not detect stock_quantity difference.\n";
            return false;
        }
        echo "  [OK] Detected stock quantity mismatch.\n";

        // Test 3: Gallery order difference
        $cand_gallery = $base;
        $cand_gallery['gallery_image_ids'] = array_reverse($base['gallery_image_ids']);
        $res3 = $this->harness->compareSnapshots($base, $cand_gallery);
        if ($res3['identical'] !== false) {
            echo "  [FAIL] Comparator did not detect gallery order mismatch.\n";
            return false;
        }
        echo "  [OK] Detected gallery order mismatch.\n";

        // Test 4: Variation SKU difference
        $cand_var = $base;
        $cand_var['variations'][0]['sku'] = 'CORRUPTED-SKU';
        $res4 = $this->harness->compareSnapshots($base, $cand_var);
        if ($res4['identical'] !== false) {
            echo "  [FAIL] Comparator did not detect variation SKU mutation.\n";
            return false;
        }
        echo "  [OK] Detected variant mutation.\n";

        return true;
    }

    /**
     * R13: Verify timestamps are ignored while true semantic discrepancies are never hidden.
     */
    private function testSnapshotNotHidingErrors(): bool
    {
        echo "[R13] Verifying volatile timestamps are normalized while semantic discrepancies are preserved...\n";

        $base = $this->harness->generateProductFixture('variable');

        // Candidate has different timestamps but identical semantic payload
        $cand_time = $base;
        $cand_time['created_at'] = '2026-10-12T10:00:00Z';
        $cand_time['updated_at'] = '2026-10-12T12:34:56Z';

        $res1 = $this->harness->compareSnapshots($base, $cand_time);
        if ($res1['identical'] !== true) {
            echo "  [FAIL] Timestamp difference falsely triggered semantic discrepancy.\n";
            return false;
        }
        echo "  [OK] Volatile timestamps successfully ignored when semantics are identical.\n";

        // Candidate has different timestamps AND a modified SKU
        $cand_both = $cand_time;
        $cand_both['sku'] = 'CHANGED-SKU';

        $res2 = $this->harness->compareSnapshots($base, $cand_both);
        if ($res2['identical'] !== false) {
            echo "  [FAIL] Real discrepancy was masked by normalizer.\n";
            return false;
        }
        echo "  [OK] Real semantic discrepancy properly surfaced even with timestamp changes.\n";

        return true;
    }
}

$test = new T04Test();
$exit_code = $test->run();
exit($exit_code);
