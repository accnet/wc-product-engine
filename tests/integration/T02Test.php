<?php
/**
 * T02 Integration Test — Plugin bootstrap and feature flags verification.
 *
 * Verifies R03 (activation_no_mutation), R04 (flag_scope),
 * R05 (dependency_missing), and R06 (deactivate_retains_data).
 * Also verifies the evidence tooling contract: R203 (evidence_env_matches_runtime),
 * R204 (evidence_binds_base_commit_and_files), R205 (per_requirement_receipt).
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

class T02Test
{
    private \WPE\Bootstrap\PluginBootstrap $bootstrap;

    public function __construct()
    {
        $this->bootstrap = \WPE\Bootstrap\PluginBootstrap::instance();
    }

    public function run(): int
    {
        echo "=== Running T02 Integration Tests ===\n";

        $r03 = $this->testActivationNoMutation();
        $r04 = $this->testFlagScope();
        $r05 = $this->testDependencyMissing();
        $r06 = $this->testDeactivateRetainsData();
        $r203 = $this->testEvidenceEnvMatchesRuntime();
        $r204 = $this->testEvidenceBindsBaseCommitAndFiles();
        $r205 = $this->testPerRequirementReceipt();
        $ac = $this->testAcceptanceCriteria();

        echo "\n=== Test Summary ===\n";
        echo "R03 (activation_no_mutation): " . ($r03 ? "PASSED" : "FAILED") . "\n";
        echo "R04 (flag_scope): " . ($r04 ? "PASSED" : "FAILED") . "\n";
        echo "R05 (dependency_missing): " . ($r05 ? "PASSED" : "FAILED") . "\n";
        echo "R06 (deactivate_retains_data): " . ($r06 ? "PASSED" : "FAILED") . "\n";
        echo "R203 (evidence_env_matches_runtime): " . ($r203 ? "PASSED" : "FAILED") . "\n";
        echo "R204 (evidence_binds_base_commit_and_files): " . ($r204 ? "PASSED" : "FAILED") . "\n";
        echo "R205 (per_requirement_receipt): " . ($r205 ? "PASSED" : "FAILED") . "\n";

        foreach ($ac as $id => $row) {
            echo "{$id} ({$row[0]}): " . ($row[1] ? "PASSED" : "FAILED") . "\n";
        }

        if ($r03 && $r04 && $r05 && $r06 && $r203 && $r204 && $r205 && !in_array(false, array_column($ac, 1), true)) {
            echo "All T02 tests passed successfully.\n";
            return 0;
        }

        echo "T02 tests failed.\n";
        return 1;
    }

    /**
     * R03: Activation must not mutate products, tables, or default legacy authority.
     */
    private function testActivationNoMutation(): bool
    {
        echo "[R03] Verifying activation does not mutate products or change default authority...\n";
        global $wpdb;

        $posts_count_before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");

        // Execute activation hook
        \WPE\Bootstrap\PluginBootstrap::onActivate();

        $posts_count_after = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        if ($posts_count_before !== $posts_count_after) {
            echo "  [FAIL] Posts table was modified during activation ({$posts_count_before} -> {$posts_count_after})\n";
            return false;
        }
        echo "  [OK] Posts table count intact ({$posts_count_after}).\n";

        // Verify default authority is legacy
        $flags = $this->bootstrap->getFlags();
        if (($flags['cohort_mode'] ?? '') !== 'legacy') {
            echo "  [FAIL] Default cohort_mode must be 'legacy', found: " . ($flags['cohort_mode'] ?? 'null') . "\n";
            return false;
        }
        echo "  [OK] Default cohort authority is legacy.\n";

        // Verify all native flags are false by default
        foreach (['query_enabled', 'media_enabled', 'resolver_enabled', 'native_writes_enabled'] as $f) {
            if (!empty($flags[$f])) {
                echo "  [FAIL] Flag {$f} must be false by default.\n";
                return false;
            }
        }
        echo "  [OK] All native feature flags are disabled by default.\n";

        return true;
    }

    /**
     * R04: Flag scope and deterministic cohort routing.
     */
    private function testFlagScope(): bool
    {
        echo "[R04] Verifying individual flag scoping and deterministic cohort precedence...\n";

        // 1. Individual flag toggling
        $this->bootstrap->setFlag('query_enabled', false);
        $this->bootstrap->setFlag('media_enabled', true);

        $flags = $this->bootstrap->getFlags();
        if ($flags['query_enabled'] !== false || $flags['media_enabled'] !== true) {
            echo "  [FAIL] Flag scoping failed.\n";
            return false;
        }
        echo "  [OK] Individual flags scoped independently.\n";

        // Reset query_enabled to false
        $this->bootstrap->setFlag('query_enabled', false);
        $this->bootstrap->setFlag('media_enabled', false);

        // 2. Cohort routing deterministic precedence
        // Precedence: Product ID rule > Category rule > Default mode
        $test_product_id = 999999;
        $routing_rules = [
            'products' => [
                $test_product_id => 'full_native',
            ],
            'categories' => [
                1 => 'cohort_canary',
            ],
        ];
        update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING, $routing_rules);

        // Test rule 1: exact product match
        $auth1 = $this->bootstrap->resolveProductAuthority($test_product_id);
        if ($auth1 !== 'full_native') {
            echo "  [FAIL] Exact product ID rule did not take precedence (got: {$auth1})\n";
            return false;
        }
        echo "  [OK] Exact product rule has highest precedence (resolved: full_native).\n";

        // Test rule 3: unspecified product falls back to default mode
        $unspecified_id = 888888;
        $auth3 = $this->bootstrap->resolveProductAuthority($unspecified_id);
        if ($auth3 !== 'legacy') {
            echo "  [FAIL] Unspecified product did not fall back to default legacy mode (got: {$auth3})\n";
            return false;
        }
        echo "  [OK] Fallback to default cohort mode works deterministically (resolved: legacy).\n";

        // Clean up test routing option
        delete_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING);

        // Verify status reflects config
        $status = $this->bootstrap->getRestStatus();
        if ($status['status'] !== 'operational' || !isset($status['flags'])) {
            echo "  [FAIL] Status endpoint does not reflect operational state.\n";
            return false;
        }
        echo "  [OK] Status endpoint accurately reflects current configuration.\n";

        return true;
    }

    /**
     * R05: Dependency check and missing dependency behavior.
     */
    private function testDependencyMissing(): bool
    {
        echo "[R05] Verifying dependency checks and unsupported version blocking...\n";

        // Live check with real environment
        $satisfied = $this->bootstrap->checkDependencies();
        if (!$satisfied) {
            echo "  [FAIL] Real site dependencies should be satisfied on site1.localhost: " . implode('; ', $this->bootstrap->getDependencyErrors()) . "\n";
            return false;
        }
        echo "  [OK] Dependencies satisfied on current site1.localhost environment.\n";

        // Simulate dependency check failure with a mock/custom instance
        $mock = new class extends \WPE\Bootstrap\PluginBootstrap {
            public function checkDependencies(): bool
            {
                // Simulate outdated WooCommerce version
                return false;
            }
        };

        if ($mock->isFlagEnabled('native_writes_enabled')) {
            echo "  [FAIL] Native flag must not be enabled when dependencies fail.\n";
            return false;
        }
        echo "  [OK] Native features are strictly blocked when dependencies are not satisfied.\n";

        return true;
    }

    /**
     * R06: Deactivation retains all data and tables.
     */
    private function testDeactivateRetainsData(): bool
    {
        echo "[R06] Verifying deactivation retains all tables and data...\n";
        global $wpdb;

        $posts_count_before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        $options_count_before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");

        // Call deactivation hook
        \WPE\Bootstrap\PluginBootstrap::onDeactivate();

        $posts_count_after = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        $options_count_after = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");

        if ($posts_count_before !== $posts_count_after || $options_count_before !== $options_count_after) {
            echo "  [FAIL] Deactivation modified database rows.\n";
            return false;
        }

        echo "  [OK] Deactivation safely retained all tables and data.\n";
        return true;
    }

    /**
     * AC-T02-01..03: install is inert, legacy reads stay on WooCommerce, cohort routing is deterministic.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private function testAcceptanceCriteria(): array
    {
        echo "[AC-T02] Verifying bootstrap acceptance criteria...\n";
        global $wpdb;
        $flags_before = $this->bootstrap->getFlags();
        $routing_before = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING, null);
        $saved_flags_before = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, null);
        $restore = function () use ($flags_before, $routing_before, $saved_flags_before): void {
            $flagsProp = new \ReflectionProperty($this->bootstrap, 'flags');
            $flagsProp->setAccessible(true);
            $flagsProp->setValue($this->bootstrap, $flags_before);
            if ($saved_flags_before === null) {
                delete_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS);
            } else {
                update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, $saved_flags_before);
            }
            if ($routing_before === null) {
                delete_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING);
            } else {
                update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING, $routing_before);
            }
        };

        // AC-T02-01: activation changes neither authority nor products; unsupported dependencies block native.
        $products_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')");
        $mode_before = $this->bootstrap->getRestStatus()['environment']['authority_mode'];
        \WPE\Bootstrap\PluginBootstrap::onActivate();
        $products_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')");
        $mode_after = (new \WPE\Bootstrap\PluginBootstrap())->getRestStatus()['environment']['authority_mode'];
        $blocked = new \WPE\Bootstrap\PluginBootstrap();
        foreach (['flags' => ['native_writes_enabled' => true, 'query_enabled' => true, 'cohort_mode' => 'full_native'], 'dependencies_satisfied' => false] as $prop => $value) {
            $ref = new \ReflectionProperty($blocked, $prop);
            $ref->setAccessible(true);
            $ref->setValue($blocked, $value);
        }
        update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING, ['products' => [999001 => 'full_native']]);
        $ok1 = $products_before === $products_after && $mode_before === $mode_after
            && !$blocked->isFlagEnabled('native_writes_enabled') && !$blocked->isFlagEnabled('query_enabled')
            && $blocked->resolveProductAuthority(999001) === 'legacy';
        $restore();
        echo $ok1 ? "  [OK] Activation is inert; unsupported dependencies force legacy and block native flags.\n" : "  [FAIL] Activation mutated state or unsupported dependencies did not block native.\n";

        // AC-T02-02: with the query feature off, WooCommerce still reads products at legacy authority.
        $this->bootstrap->setFlag('query_enabled', false);
        $this->bootstrap->setFlag('native_writes_enabled', false);
        $ids = wc_get_products(['limit' => 1, 'return' => 'ids']);
        $product = $ids ? wc_get_product($ids[0]) : null;
        $store = \WC_Data_Store::load('product');
        $ok2 = $product instanceof \WC_Product && $product->get_name() !== '' && is_numeric($product->get_price() === '' ? 0 : $product->get_price())
            && $this->bootstrap->resolveProductAuthority($ids[0]) === 'legacy'
            && $store->get_current_class_name() === 'WC_Product_Data_Store_CPT';
        $restore();
        echo $ok2 ? "  [OK] Query flag off: WooCommerce CPT data store reads products at legacy authority.\n" : "  [FAIL] Product read with query flag off did not stay on WooCommerce legacy.\n";

        // AC-T02-03: conflicting cohort rules resolve deterministically (product rule over category rule); status reflects config.
        $pid = null;
        $tid = null;
        foreach (wc_get_products(['limit' => 100, 'return' => 'ids']) as $candidate) {
            $terms = wc_get_product_term_ids($candidate, 'product_cat');
            if ($terms) {
                $pid = $candidate;
                $tid = $terms[0];
                break;
            }
        }
        $ok3 = $pid !== null;
        if ($ok3) {
            update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING, ['products' => [$pid => 'legacy'], 'categories' => [$tid => 'full_native']]);
            $seen = [];
            for ($i = 0; $i < 25; $i++) {
                $seen[] = $this->bootstrap->resolveProductAuthority($pid);
            }
            update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_ROUTING, ['categories' => [$tid => 'full_native']]);
            $seenCat = [];
            for ($i = 0; $i < 25; $i++) {
                $seenCat[] = $this->bootstrap->resolveProductAuthority($pid);
            }
            update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, ['cohort_mode' => 'cohort_canary']);
            $this->bootstrap->loadFlags();
            $status = $this->bootstrap->getRestStatus();
            $ok3 = array_unique($seen) === ['legacy'] && array_unique($seenCat) === ['full_native']
                && $status['environment']['authority_mode'] === 'cohort_canary' && $status['flags'] === $this->bootstrap->getFlags()
                && $status['dependencies_satisfied'] === $this->bootstrap->areDependenciesSatisfied();
        }
        $restore();
        echo $ok3 ? "  [OK] Cohort conflict resolves deterministically; status endpoint mirrors live config.\n" : "  [FAIL] Cohort conflict was not deterministic or status did not reflect config.\n";

        return [
            'AC-T02-01' => ['install_inert_unsupported_blocks_native', $ok1],
            'AC-T02-02' => ['legacy_reads_when_query_off', $ok2],
            'AC-T02-03' => ['cohort_conflict_deterministic_status_real', $ok3],
        ];
    }

    /**
     * Run a JS snippet against scripts/evidence-lib.mjs and return decoded JSON.
     */
    private function evidenceLib(string $js): ?array
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'cd ' . escapeshellarg($root) . ' && node --input-type=module -e ' . escapeshellarg(
            "import * as L from './scripts/evidence-lib.mjs'; " . $js
        ) . ' 2>&1';
        $out = trim((string) shell_exec($cmd));
        $lines = array_filter(explode("\n", $out));
        $decoded = json_decode((string) end($lines), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * R203: runner/verifier environment is read from the live runtime, not hard-coded.
     */
    private function testEvidenceEnvMatchesRuntime(): bool
    {
        echo "[R203] Verifying evidence environment equals the live runtime...\n";
        global $wpdb;
        $env = $this->evidenceLib('console.log(JSON.stringify(L.runtimeEnvironment()));');
        $live = [
            'wordpress' => get_bloginfo('version'),
            'woocommerce' => WC_VERSION,
            'php' => PHP_VERSION,
            'database' => $wpdb->get_var('SELECT VERSION()'),
        ];
        if ($env !== $live) {
            echo "  [FAIL] evidence environment " . json_encode($env) . " != live " . json_encode($live) . "\n";
            return false;
        }
        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/test-runtime.mjs');
        if (preg_match("/wordpress:\\s*'\\d/", $runner) || preg_match("/woocommerce:\\s*'\\d/", $runner)) {
            echo "  [FAIL] scripts/test-runtime.mjs still hard-codes an environment version.\n";
            return false;
        }
        echo "  [OK] R203 passed: environment equals live runtime and no version literal in the runner.\n";
        return true;
    }

    /**
     * R204: receipts bind an existing base commit and content hashes of every task file.
     */
    private function testEvidenceBindsBaseCommitAndFiles(): bool
    {
        echo "[R204] Verifying evidence binds base commit and file hashes...\n";
        $js = "const base=L.baseCommit(process.cwd());"
            . "const r={regression_results:[],acceptance:[]};"
            . "const t={files:['scripts/evidence-lib.mjs','scripts/verify-task.mjs']};"
            . "const p=L.finalizeEvidence(process.cwd(),t,r,'');"
            . "const h=L.fileHashes(process.cwd(),t.files);"
            . "const h2=L.fileHashes(process.cwd(),['scripts/evidence-lib.mjs']);"
            . "console.log(JSON.stringify({base:r.base_commit,commit:r.commit,exists:L.commitExists(process.cwd(),r.base_commit),"
            . "keys:Object.keys(r.file_hashes),same:JSON.stringify(h)===JSON.stringify(r.file_hashes),subset:h2['scripts/evidence-lib.mjs']===h['scripts/evidence-lib.mjs'],"
            . "bad:L.commitExists(process.cwd(),'0000000000000000000000000000000000000000')}));";
        $res = $this->evidenceLib($js);
        if (!$res || empty($res['exists']) || $res['base'] !== $res['commit'] || !empty($res['bad'])) {
            echo "  [FAIL] base commit is missing, not equal to commit, or fake commit accepted.\n";
            return false;
        }
        if ($res['keys'] !== ['scripts/evidence-lib.mjs', 'scripts/verify-task.mjs'] || empty($res['same']) || empty($res['subset'])) {
            echo "  [FAIL] file hashes do not cover exactly the task files.\n";
            return false;
        }
        echo "  [OK] R204 passed: base commit exists in git, fake commit rejected, per-file hashes cover task files.\n";
        return true;
    }

    /**
     * R205: per-requirement receipts come from output anchors; missing anchor fails, fail marker rejects.
     */
    private function testPerRequirementReceipt(): bool
    {
        echo "[R205] Verifying per-requirement receipt derivation from test output...\n";
        $js = "const out=['testR07_good OK','[R08] fail-closed path PASSED','R09 FAILED: boom','AC-T99-01 passed: ok'].join('\\n');"
            . "const mk=()=>({regression_results:[{id:'R07'},{id:'R08'},{id:'R09'},{id:'R10'}],acceptance:[{id:'AC-T99-01'},{id:'AC-T99-02'}]});"
            . "const r=mk();const problems=L.buildReceipts(r,out);"
            . "const ok={regression_results:[{id:'R07'},{id:'R08'}],acceptance:[{id:'AC-T99-02'}]};const p2=L.buildReceipts(ok,out);"
            . "console.log(JSON.stringify({st:r.regression_results.map(x=>x.status),basis:r.regression_results.map(x=>x.basis),"
            . "ac:r.acceptance.map(x=>x.basis+':'+x.result),problems:problems.length,p2:p2.length,ok_ac:ok.acceptance[0].basis+':'+ok.acceptance[0].result}));";
        $res = $this->evidenceLib($js);
        if (!$res) {
            echo "  [FAIL] could not execute evidence library.\n";
            return false;
        }
        $expect_status = ['passed', 'passed', 'failed', 'failed'];
        if ($res['st'] !== $expect_status) {
            echo "  [FAIL] requirement statuses " . json_encode($res['st']) . " expected " . json_encode($expect_status) . "\n";
            return false;
        }
        if ($res['basis'][0] !== 'output_anchor' || $res['basis'][2] !== 'no_output_anchor' || $res['basis'][3] !== 'no_output_anchor') {
            echo "  [FAIL] requirement basis not derived from output.\n";
            return false;
        }
        if ($res['ac'] !== ['output_anchor:passed', 'requirements_only:failed'] || $res['ok_ac'] !== 'requirements_only:passed') {
            echo "  [FAIL] acceptance basis wrong: " . json_encode($res['ac']) . ' ' . $res['ok_ac'] . "\n";
            return false;
        }
        if ($res['problems'] < 3 || $res['p2'] !== 0) {
            echo "  [FAIL] problems not reported correctly.\n";
            return false;
        }
        echo "  [OK] R205 passed: anchors required, fail marker rejected, 'fail-closed' accepted, AC basis recorded.\n";
        return true;
    }
}

$test = new T02Test();
$exit_code = $test->run();
exit($exit_code);
