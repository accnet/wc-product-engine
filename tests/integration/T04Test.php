<?php
/**
 * T04 Integration Test — Fixture and differential test harness verification.
 *
 * Verifies R11 (fixture_invariants), R12 (diff_detects_wrong_price_stock_variant),
 * and R13 (snapshot_not_hiding_errors).
 * Also verifies R212 (test_residue_zero): the residue gate and DB snapshot/restore isolation.
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
        $r212 = $this->testResidueZero();
        $ac = $this->testAcceptanceCriteria();

        echo "\n=== Test Summary ===\n";
        echo "R11 (fixture_invariants): " . ($r11 ? "PASSED" : "FAILED") . "\n";
        echo "R12 (diff_detects_wrong_price_stock_variant): " . ($r12 ? "PASSED" : "FAILED") . "\n";
        echo "R13 (snapshot_not_hiding_errors): " . ($r13 ? "PASSED" : "FAILED") . "\n";
        echo "R212 (test_residue_zero): " . ($r212 ? "PASSED" : "FAILED") . "\n";

        foreach ($ac as $id => $row) {
            echo "{$id} ({$row[0]}): " . ($row[1] ? "PASSED" : "FAILED") . "\n";
        }

        if ($r11 && $r12 && $r13 && $r212 && !in_array(false, array_column($ac, 1), true)) {
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

    /**
     * AC-T04-01/02: comparator separates semantic from volatile differences; datasets are reproducible with a manifest.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private function testAcceptanceCriteria(): array
    {
        echo "[AC-T04] Verifying fixture and comparator acceptance criteria...\n";
        $base = $this->harness->generateProductFixture('simple', ['id' => 424200, 'gallery_image_ids' => ['11', '12', '13'], 'stock_quantity' => '7.000000']);

        // AC-T04-01: volatile timestamps are ignored; IDs, stock and gallery order are never normalized away.
        $volatile = $base;
        $volatile['updated_at'] = '2030-01-01T00:00:00Z';
        $volatile['created_at'] = '2029-01-01T00:00:00Z';
        $ok1 = $this->harness->compareSnapshots($base, $volatile)['identical'] === true;
        $mutations = [
            'id' => ['id' => '424201'],
            'stock_quantity' => ['stock_quantity' => '6.000000'],
            'gallery_image_ids' => ['gallery_image_ids' => ['12', '11', '13']],
        ];
        foreach ($mutations as $field => $change) {
            $diff = $this->harness->compareSnapshots($base, array_merge($volatile, $change))['differences'];
            $ok1 = $ok1 && in_array($field, array_column($diff, 'field'), true);
            $normalized = $this->harness->normalizeSnapshot($base);
            $ok1 = $ok1 && ($normalized[$field] ?? null) === $base[$field];
        }
        echo $ok1 ? "  [OK] Volatile timestamps ignored; id, stock and gallery order mutations all surfaced.\n" : "  [FAIL] Comparator hides or invents differences.\n";

        // AC-T04-02: same seed reproduces the small dataset exactly; scale datasets have a manifest with seed and counts.
        $a = (new \WPE\Bootstrap\FixtureHarness(777))->generateProductFixture('variable');
        $b = (new \WPE\Bootstrap\FixtureHarness(777))->generateProductFixture('variable');
        $c = (new \WPE\Bootstrap\FixtureHarness(778))->generateProductFixture('variable');
        $ok2 = $a === $b && $a !== $c;
        foreach (['100k', '1M'] as $scale) {
            $manifest = $this->harness->getScaleManifest($scale);
            $ok2 = $ok2 && $manifest['scale'] === $scale && is_int($manifest['seed']) && $manifest['seed'] > 0
                && $manifest['parent_products'] > 0 && abs(array_sum($manifest['distribution']) - 1.0) < 1e-9
                && $this->harness->getScaleManifest($scale) === $manifest;
        }
        $ok2 = $ok2 && $this->harness->getScaleManifest('100k')['seed'] !== $this->harness->getScaleManifest('1M')['seed'];
        echo $ok2 ? "  [OK] Small dataset reproducible per seed; scale manifests (definition only, not seeded) carry seed and counts.\n" : "  [FAIL] Dataset is not reproducible or manifest incomplete.\n";

        return [
            'AC-T04-01' => ['comparator_semantic_vs_volatile', $ok1],
            'AC-T04-02' => ['reproducible_datasets_manifest_seed', $ok2],
        ];
    }

    /**
     * Run scripts/test-isolation.mjs and return [exit code, stdout+stderr].
     *
     * @return array{0:int,1:string}
     */
    private function isolationCli(string $args): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];
        exec('cd ' . escapeshellarg($root) . ' && node scripts/test-isolation.mjs ' . $args . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    /**
     * R212: tests leave no junk rows; the residue gate detects injected junk, cleanup is guarded,
     * and a DB snapshot/restore round trip removes whatever a test wrote.
     */
    private function testResidueZero(): bool
    {
        echo "[R212] Verifying test residue is zero and the isolation tooling works...\n";
        global $wpdb;
        $control = $wpdb->prefix . 'wpe_control';
        $marker = 'mirror_stopped_cohort_r999_probe_' . $this->harness->namespaced('residue');
        $count = static fn(array $r): int => array_sum(array_map('count', $r));

        try {
            // 1. Database starts clean, and PHP and Node agree on what "junk" means.
            $php = \WPE\Bootstrap\FixtureHarness::residueReport();
            [$code, $out] = $this->isolationCli('residue --json');
            if ($count($php) !== 0 || $code !== 0) {
                echo "  [FAIL] Junk rows already present: " . json_encode(array_map('count', $php)) . " / node exit {$code}\n";
                return false;
            }
            echo "  [OK] Residue gate reports zero junk rows (PHP and Node agree).\n";

            // 2. The gate detects injected junk in both implementations.
            $wpdb->insert($control, ['key' => $marker, 'value' => '{}', 'version' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $php = \WPE\Bootstrap\FixtureHarness::residueReport();
            [$code, $out] = $this->isolationCli('residue --json');
            if (!in_array($marker, $php['test_control_keys'], true) || $code !== 1 || strpos($out, $marker) === false) {
                echo "  [FAIL] Injected junk key was not detected (node exit {$code}).\n";
                return false;
            }
            echo "  [OK] Injected junk is detected by the PHP and Node residue gates.\n";

            // 3. Cleanup is a dry run by default and refuses --apply without a backup.
            [$code] = $this->isolationCli('cleanup-residue');
            $stillThere = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$control} WHERE `key` = %s", $marker));
            if ($code !== 0 || $stillThere !== 1) {
                echo "  [FAIL] Dry-run cleanup changed data.\n";
                return false;
            }
            [$code, $out] = $this->isolationCli('cleanup-residue --apply');
            $stillThere = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$control} WHERE `key` = %s", $marker));
            if ($code === 0 || $stillThere !== 1) {
                echo "  [FAIL] cleanup --apply without --backup was not refused (exit {$code}).\n";
                return false;
            }
            echo "  [OK] Cleanup is dry-run by default and --apply without a backup is refused.\n";
            $wpdb->delete($control, ['key' => $marker]);

            // 4. Snapshot -> write -> restore removes what the test wrote.
            $snapshot = sys_get_temp_dir() . '/wpe-r212-' . uniqid('', true) . '.sql';
            [$code] = $this->isolationCli('snapshot ' . escapeshellarg($snapshot));
            if ($code !== 0) {
                echo "  [FAIL] Snapshot failed.\n";
                return false;
            }
            $wpdb->insert($control, ['key' => $marker, 'value' => '{}', 'version' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            [$code] = $this->isolationCli('restore ' . escapeshellarg($snapshot));
            @unlink($snapshot);
            $leftover = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$control} WHERE `key` = %s", $marker));
            if ($code !== 0 || $leftover !== 0) {
                echo "  [FAIL] Restore did not remove the row written after the snapshot (exit {$code}, rows {$leftover}).\n";
                return false;
            }
            echo "  [OK] Snapshot/restore round trip removes rows a test wrote after the snapshot.\n";
        } finally {
            $wpdb->delete($control, ['key' => $marker]);
        }

        $after = \WPE\Bootstrap\FixtureHarness::residueReport();
        if ($count($after) !== 0) {
            echo "  [FAIL] Junk rows remain after the test.\n";
            return false;
        }
        echo "  [OK] R212 passed: residue is zero before and after; gate, guarded cleanup and restore verified.\n";
        return true;
    }
}

$test = new T04Test();
$exit_code = $test->run();
exit($exit_code);
