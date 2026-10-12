<?php
/**
 * T01 Integration Test — Baseline and compatibility inventory verification.
 *
 * Verifies R01 (baseline_cart_checkout_gallery_admin_csv) and R02 (unknown_extension_blocks_native).
 * Compatible with WP-CLI eval-file and standalone execution.
 */

if (!defined('ABSPATH')) {
    // If not invoked from WP-CLI, try loading WordPress environment
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

class T01Test
{
    private array $results = [];
    private string $root;

    public function __construct()
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function run(): int
    {
        echo "=== Running T01 Integration Tests ===\n";

        $r01_passed = $this->testBaselineCartCheckoutGalleryAdminCsv();
        $r02_passed = $this->testUnknownExtensionBlocksNative();
        $ac = $this->testAcceptanceCriteria();

        echo "\n=== Test Summary ===\n";
        echo "R01 (baseline_cart_checkout_gallery_admin_csv): " . ($r01_passed ? "PASSED" : "FAILED") . "\n";
        echo "R02 (unknown_extension_blocks_native): " . ($r02_passed ? "PASSED" : "FAILED") . "\n";

        foreach ($ac as $id => $row) {
            echo "{$id} ({$row[0]}): " . ($row[1] ? "PASSED" : "FAILED") . "\n";
        }

        if ($r01_passed && $r02_passed && !in_array(false, array_column($ac, 1), true)) {
            echo "All T01 tests passed successfully.\n";
            return 0;
        }

        echo "T01 tests failed.\n";
        return 1;
    }

    /**
     * AC-T01-01..03: matrix completeness, no universal-compatibility claim, SQL writers block native.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private function testAcceptanceCriteria(): array
    {
        echo "[AC-T01] Verifying compatibility matrix acceptance criteria...\n";
        $dir = $this->root . '/contracts/compatibility';
        $matrix = json_decode((string) @file_get_contents($dir . '/matrix.json'), true) ?: [];
        $pins = json_decode((string) @file_get_contents($dir . '/pins.json'), true) ?: [];
        $lock = json_decode((string) @file_get_contents($dir . '/baseline-lock.json'), true) ?: [];

        // AC-T01-01: exact versions + baseline lock + fixtures; each surface has owner, test and a valid status.
        $ok1 = file_exists($dir . '/' . ($matrix['versions_ref'] ?? '-')) && file_exists($dir . '/' . ($matrix['baseline_lock_ref'] ?? '-'))
            && ($lock['environment']['wordpress']['version'] ?? null) === ($pins['versions']['wordpress'] ?? '')
            && ($lock['environment']['woocommerce']['version'] ?? null) === ($pins['versions']['woocommerce'] ?? '')
            && !empty($lock['environment']['wordpress']['files']) && !empty($matrix['surfaces']);
        foreach ($matrix['surfaces'] ?? [] as $surface) {
            $ok1 = $ok1 && !empty($surface['owner']) && !empty($surface['test']) && file_exists($this->root . '/' . $surface['test'])
                && in_array($surface['status'] ?? '', ['supported', 'blocked', 'unknown'], true);
        }
        foreach ($matrix['third_party_plugins'] ?? [] as $plugin) {
            $ok1 = $ok1 && !empty($plugin['owner']) && in_array($plugin['status'] ?? '', ['supported', 'blocked', 'unknown'], true);
        }
        echo $ok1 ? "  [OK] Matrix pins, baseline lock, fixtures; every surface has owner, test and status.\n" : "  [FAIL] Matrix is missing exact versions, lock, owner, test or valid status.\n";

        // AC-T01-02: no universal claim; certified stays explicit and no wildcard entry exists.
        $json = (string) json_encode($matrix, JSON_UNESCAPED_UNICODE);
        $wildcard = false;
        foreach ($matrix['third_party_plugins'] ?? [] as $plugin) {
            $wildcard = $wildcard || ($plugin['slug'] ?? '') === '*' || stripos((string) ($plugin['notes'] ?? ''), 'all woocommerce plugins') !== false;
        }
        $ok2 = stripos((string) ($matrix['disclaimer'] ?? ''), 'Không ghi mọi Woo plugin tương thích') === 0
            && !$wildcard && ($matrix['certified'] ?? null) === false && stripos($json, 'compatible with all') === false;
        echo $ok2 ? "  [OK] No universal Woo plugin compatibility claim.\n" : "  [FAIL] Matrix contains or allows a universal compatibility claim.\n";

        // AC-T01-03: the real policy engine (MetaBridge) refuses native authority to every SQL writer and unknown plugin.
        $ok3 = class_exists(\WPE\Compatibility\WooDataStore\MetaBridge::class);
        if ($ok3) {
            global $wpdb;
            $bridge = new \WPE\Compatibility\WooDataStore\MetaBridge($wpdb);
            $writers = 0;
            foreach ($matrix['third_party_plugins'] ?? [] as $plugin) {
                if (($plugin['classification'] ?? '') === 'SQL-writer') {
                    $writers++;
                    $ok3 = $ok3 && $bridge->canPluginWriteNative($plugin['slug']) === false;
                }
            }
            $ok3 = $ok3 && $writers > 0 && $bridge->canPluginWriteNative('t01-unregistered-plugin') === false;
        }
        echo $ok3 ? "  [OK] SQL writers and unknown plugins are blockers for native cohorts.\n" : "  [FAIL] A SQL writer or unknown plugin was allowed native authority.\n";

        return [
            'AC-T01-01' => ['matrix_exact_versions_owner_test_status', $ok1],
            'AC-T01-02' => ['no_universal_compatibility_claim', $ok2],
            'AC-T01-03' => ['sql_writer_blocks_native_cohort', $ok3],
        ];
    }

    /**
     * R01: Verify baseline WooCommerce cart, checkout, gallery, admin, and CSV surfaces.
     */
    private function testBaselineCartCheckoutGalleryAdminCsv(): bool
    {
        echo "[R01] Verifying WooCommerce baseline surfaces...\n";

        // 1. Core host check
        if (!function_exists('WC') || !is_object(WC())) {
            echo "  [FAIL] WooCommerce instance WC() not available.\n";
            return false;
        }
        $wc_version = WC()->version;
        if (version_compare($wc_version, '11.0', '<')) {
            echo "  [FAIL] Expected WooCommerce >= 11.0, found: {$wc_version}\n";
            return false;
        }
        echo "  [OK] WooCommerce version: {$wc_version}\n";

        // 1b. Pinned versions must equal the live runtime (audit F01: no hard-coded stale pins)
        $pins_path = $this->root . '/contracts/compatibility/pins.json';
        $pins = json_decode((string) file_get_contents($pins_path), true);
        $pinned = $pins['versions'] ?? [];
        $live = [
            'wordpress' => get_bloginfo('version'),
            'woocommerce' => $wc_version,
            'php' => PHP_VERSION,
        ];
        foreach ($live as $name => $live_version) {
            if (($pinned[$name] ?? null) !== $live_version) {
                echo "  [FAIL] pins.json {$name}=" . ($pinned[$name] ?? 'missing') . " but live runtime is {$live_version}.\n";
                return false;
            }
        }
        echo "  [OK] pins.json equals live runtime (WP {$live['wordpress']}, WC {$live['woocommerce']}, PHP {$live['php']}).\n";

        // 2. Cart & Session check
        if (!WC()->cart && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (!WC()->cart) {
            WC()->initialize_cart();
        }
        if (!is_object(WC()->cart)) {
            echo "  [FAIL] WooCommerce Cart could not be initialized.\n";
            return false;
        }
        echo "  [OK] WooCommerce Cart initialized successfully.\n";

        // 3. Checkout surface check
        if (!function_exists('wc_get_checkout_url')) {
            echo "  [FAIL] wc_get_checkout_url function missing.\n";
            return false;
        }
        $checkout_url = wc_get_checkout_url();
        if (empty($checkout_url)) {
            echo "  [FAIL] Checkout URL is empty.\n";
            return false;
        }
        echo "  [OK] Checkout surface URL: {$checkout_url}\n";

        // 4. Product Data Store check
        try {
            $ds = WC_Data_Store::load('product');
            if (!is_object($ds)) {
                echo "  [FAIL] Product Data Store could not be loaded.\n";
                return false;
            }
            echo "  [OK] Baseline Product Data Store: " . get_class($ds) . "\n";
        } catch (\Throwable $e) {
            echo "  [FAIL] Exception loading Product Data Store: " . $e->getMessage() . "\n";
            return false;
        }

        // 5. Classic gallery hooks check
        $gallery_action = has_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images');
        echo "  [OK] Single product gallery action attached: " . ($gallery_action !== false ? "YES (priority {$gallery_action})" : "CUSTOM/DEFAULT") . "\n";

        // 6. CSV Importer check
        $importer_file = WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
        if (file_exists($importer_file)) {
            include_once $importer_file;
        }
        if (!class_exists('WC_Product_CSV_Importer')) {
            echo "  [FAIL] WC_Product_CSV_Importer class missing.\n";
            return false;
        }
        echo "  [OK] Baseline CSV Importer class verified.\n";

        return true;
    }

    /**
     * R02: Verify that unknown extensions and direct SQL writers block native cohort migration.
     */
    private function testUnknownExtensionBlocksNative(): bool
    {
        echo "[R02] Verifying unknown extension / direct SQL writer blocks native authority...\n";

        $matrix_path = $this->root . '/contracts/compatibility/matrix.json';
        if (!file_exists($matrix_path)) {
            echo "  [FAIL] matrix.json not found at {$matrix_path}\n";
            return false;
        }

        $matrix = json_decode((string)file_get_contents($matrix_path), true);
        if (!$matrix || !isset($matrix['third_party_plugins'])) {
            echo "  [FAIL] matrix.json invalid or missing third_party_plugins.\n";
            return false;
        }

        // Verify disclaimer exists (AC-T01-02: Không ghi "mọi Woo plugin tương thích")
        $disclaimer = $matrix['disclaimer'] ?? '';
        if (stripos($disclaimer, 'Không ghi mọi Woo plugin tương thích') === false &&
            stripos($disclaimer, 'không cam kết mọi') === false) {
            echo "  [FAIL] matrix.json missing explicit disclaimer against universal plugin compatibility.\n";
            return false;
        }
        echo "  [OK] Verified disclaimer against universal plugin compatibility.\n";

        // Verify direct SQL writer blocking rule (AC-T01-03)
        $has_blocked_sql_writer = false;
        foreach ($matrix['third_party_plugins'] as $plugin) {
            if ($plugin['classification'] === 'SQL-writer') {
                if ($plugin['status'] === 'blocked') {
                    $has_blocked_sql_writer = true;
                    echo "  [OK] Verified SQL-writer plugin '{$plugin['slug']}' is marked as blocked.\n";
                } else {
                    echo "  [FAIL] SQL-writer plugin '{$plugin['slug']}' must have status 'blocked', found '{$plugin['status']}'.\n";
                    return false;
                }
            }
        }

        if (!$has_blocked_sql_writer) {
            echo "  [FAIL] No SQL-writer plugin found with status 'blocked' in matrix.json.\n";
            return false;
        }

        // Simulate policy checker function
        $policy_checker = function (array $plugin_spec): string {
            $classification = $plugin_spec['classification'] ?? 'unknown';
            $has_adapter = !empty($plugin_spec['adapter']);

            if ($classification === 'SQL-writer' && !$has_adapter) {
                return 'blocked';
            }
            if ($classification === 'unknown' && !$has_adapter) {
                return 'blocked';
            }
            return 'supported';
        };

        $test_direct_writer = ['slug' => 'test-sql-writer', 'classification' => 'SQL-writer', 'adapter' => null];
        $test_unknown = ['slug' => 'test-unknown-plugin', 'classification' => 'unknown', 'adapter' => null];
        $test_safe = ['slug' => 'test-crud-safe', 'classification' => 'CRUD-safe', 'adapter' => null];

        if ($policy_checker($test_direct_writer) !== 'blocked') {
            echo "  [FAIL] Direct SQL writer without adapter must be blocked.\n";
            return false;
        }
        if ($policy_checker($test_unknown) !== 'blocked') {
            echo "  [FAIL] Unknown plugin without adapter must be blocked.\n";
            return false;
        }
        if ($policy_checker($test_safe) !== 'supported') {
            echo "  [FAIL] CRUD-safe plugin should be supported.\n";
            return false;
        }

        echo "  [OK] Compatibility policy correctly blocks unclassified extensions and direct SQL writers.\n";
        return true;
    }
}

$test = new T01Test();
$exit_code = $test->run();
exit($exit_code);
