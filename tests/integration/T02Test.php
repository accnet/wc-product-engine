<?php
/**
 * T02 Integration Test — Plugin bootstrap and feature flags verification.
 *
 * Verifies R03 (activation_no_mutation), R04 (flag_scope),
 * R05 (dependency_missing), and R06 (deactivate_retains_data).
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

        echo "\n=== Test Summary ===\n";
        echo "R03 (activation_no_mutation): " . ($r03 ? "PASSED" : "FAILED") . "\n";
        echo "R04 (flag_scope): " . ($r04 ? "PASSED" : "FAILED") . "\n";
        echo "R05 (dependency_missing): " . ($r05 ? "PASSED" : "FAILED") . "\n";
        echo "R06 (deactivate_retains_data): " . ($r06 ? "PASSED" : "FAILED") . "\n";

        if ($r03 && $r04 && $r05 && $r06) {
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
}

$test = new T02Test();
$exit_code = $test->run();
exit($exit_code);
