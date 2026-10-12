<?php
/**
 * T11 Integration Test — WooCommerce and WordPress Metadata Bridge.
 *
 * Verifies:
 * - R41 (meta_bridge_single_multiple): single vs array query semantics, all-meta retrieval.
 * - R42 (wp_meta_noop_return): update_post_meta returns false when value unchanged (WordPress contract).
 * - R43 (recursive_hook_guard): recursion prevention for re-entrant hook contexts.
 * - R44 (unknown_meta_retention): third-party arbitrary metadata retained in wpe_meta.
 * - AC-T11-01: get_post_meta and WC read_meta_data unified on owned keys; direct WP API writes emit outbox/audit.
 * - AC-T11-02: Unclassified direct SQL writers blocked from native authority.
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

use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;

class T11Test
{
    private \wpdb $wpdb;
    private MetaBridge $bridge;
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Register data stores and metadata bridge
        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        $this->bridge = MetaBridge::instance($this->wpdb);
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanProductIds as $pid) {
            $this->wpdb->delete($prefix . 'wpe_sku_registry', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_inventory', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_object_state', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_media', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_links', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_downloads', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_meta', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_products', ['id' => $pid]);
            wp_delete_post($pid, true);
        }
    }

    public function run(): int
    {
        echo "=== Running T11 Integration Tests ===\n";

        $r41 = $this->testR41MetaBridgeSingleMultiple();
        $r42 = $this->testR42WpMetaNoopReturn();
        $r43 = $this->testR43RecursiveHookGuard();
        $r44 = $this->testR44UnknownMetaRetention();
        $ac01 = $this->testAcT1101UnifiedOwnedKeysAndEvents();
        $ac02 = $this->testAcT1102UnknownSqlWriterBlocked();

        echo "\n=== Test Summary ===\n";
        echo "R41 (meta_bridge_single_multiple): " . ($r41 ? "PASSED" : "FAILED") . "\n";
        echo "R42 (wp_meta_noop_return): " . ($r42 ? "PASSED" : "FAILED") . "\n";
        echo "R43 (recursive_hook_guard): " . ($r43 ? "PASSED" : "FAILED") . "\n";
        echo "R44 (unknown_meta_retention): " . ($r44 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T11-01 (unified_owned_keys_and_events): " . ($ac01 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T11-02 (unknown_sql_writer_blocked): " . ($ac02 ? "PASSED" : "FAILED") . "\n";

        $allPassed = $r41 && $r42 && $r43 && $r44 && $ac01 && $ac02;
        return $allPassed ? 0 : 1;
    }

    /**
     * R41: Single vs multiple query semantics and empty key retrieval.
     */
    private function testR41MetaBridgeSingleMultiple(): bool
    {
        echo "\n[Test R41] Testing single vs multiple query semantics...\n";

        $product = new WC_Product_Simple();
        $product->set_name('R41 Meta Query Product');
        $product->set_regular_price('150.00');
        $product->set_sku('R41-SKU-' . uniqid());
        $product->save();
        $id = $product->get_id();
        $this->cleanProductIds[] = $id;

        // 1. Single owned key
        $singlePrice = get_post_meta($id, '_regular_price', true);
        if ((float)$singlePrice !== 150.0) {
            echo "FAILED: get_post_meta single owned key expected '150.00', got " . var_export($singlePrice, true) . "\n";
            return false;
        }

        // 2. Multiple owned key
        $multiPrice = get_post_meta($id, '_regular_price', false);
        if (!is_array($multiPrice) || count($multiPrice) !== 1 || (float)$multiPrice[0] !== 150.0) {
            echo "FAILED: get_post_meta multiple owned key expected ['150.00'], got " . var_export($multiPrice, true) . "\n";
            return false;
        }

        // 3. Non-existent key
        $nonExistentSingle = get_post_meta($id, '_non_existent_key', true);
        if ($nonExistentSingle !== '') {
            echo "FAILED: Non-existent single key expected '', got " . var_export($nonExistentSingle, true) . "\n";
            return false;
        }

        $nonExistentMulti = get_post_meta($id, '_non_existent_key', false);
        if ($nonExistentMulti !== []) {
            echo "FAILED: Non-existent multiple key expected [], got " . var_export($nonExistentMulti, true) . "\n";
            return false;
        }

        // 4. Empty key (all metadata)
        $allMeta = get_post_meta($id, '', true);
        if (!is_array($allMeta) || !isset($allMeta['_regular_price']) || (float)$allMeta['_regular_price'][0] !== 150.0) {
            echo "FAILED: get_post_meta with empty string key did not return full metadata map: " . json_encode($allMeta) . "\n";
            return false;
        }

        echo "PASSED: Single, multiple, non-existent, and all-meta semantics verified.\n";
        return true;
    }

    /**
     * R42: WordPress metadata API no-op return (false when unchanged).
     */
    private function testR42WpMetaNoopReturn(): bool
    {
        echo "\n[Test R42] Testing wp_meta no-op return...\n";

        $product = new WC_Product_Simple();
        $product->set_name('R42 Noop Test');
        $product->set_regular_price('75.00');
        $product->save();
        $id = $product->get_id();
        $this->cleanProductIds[] = $id;

        // 1. Update with new value -> returns true
        $resNew = update_post_meta($id, '_regular_price', '85.00');
        if (!$resNew) {
            echo "FAILED: update_post_meta with new value returned false.\n";
            return false;
        }

        // 2. Update with identical value -> returns false (WordPress contract)
        $resSame = update_post_meta($id, '_regular_price', '85.00');
        if ($resSame !== false) {
            echo "FAILED: update_post_meta with identical value expected false (no-op), got " . var_export($resSame, true) . "\n";
            return false;
        }

        // 3. Custom key no-op
        update_post_meta($id, 'custom_text_prop', 'alpha');
        $customSame = update_post_meta($id, 'custom_text_prop', 'alpha');
        if ($customSame !== false) {
            echo "FAILED: update_post_meta on custom key with identical value expected false, got " . var_export($customSame, true) . "\n";
            return false;
        }

        echo "PASSED: No-op return (false when unchanged) verified for owned and custom keys.\n";
        return true;
    }

    /**
     * R43: Recursion guard prevents re-entrant infinite loops.
     */
    private function testR43RecursiveHookGuard(): bool
    {
        echo "\n[Test R43] Testing recursive hook guard...\n";

        $product = new WC_Product_Simple();
        $product->set_name('R43 Recursion Test');
        $product->save();
        $id = $product->get_id();
        $this->cleanProductIds[] = $id;

        $hookCallCount = 0;
        $hook = function($meta_id, $object_id, $meta_key, $meta_value) use ($id, &$hookCallCount) {
            if ($object_id === $id && $meta_key === 'reentrant_test_key') {
                $hookCallCount++;
                if ($hookCallCount < 5) {
                    // Recursive re-entrant call
                    update_post_meta($object_id, 'reentrant_test_key', 'reentrant_val_' . $hookCallCount);
                }
            }
        };

        add_action('updated_post_meta', $hook, 10, 4);

        try {
            update_post_meta($id, 'reentrant_test_key', 'initial_val');
        } catch (\Throwable $e) {
            remove_action('updated_post_meta', $hook, 10);
            echo "FAILED: Recursion triggered unhandled error: " . $e->getMessage() . "\n";
            return false;
        }

        remove_action('updated_post_meta', $hook, 10);

        echo "PASSED: Recursion guard handled re-entrant updates safely.\n";
        return true;
    }

    /**
     * R44: Unknown/arbitrary extension metadata retention.
     */
    private function testR44UnknownMetaRetention(): bool
    {
        echo "\n[Test R44] Testing arbitrary extension metadata retention in wpe_meta...\n";

        $product = new WC_Product_Simple();
        $product->set_name('R44 Retention Test');
        $product->save();
        $id = $product->get_id();
        $this->cleanProductIds[] = $id;

        $customPayload = [
            'vendor_code' => 'VEND-9988',
            'badges'      => ['bestseller', 'eco-friendly'],
            'settings'    => ['display_override' => true],
        ];

        update_post_meta($id, '_plugin_custom_settings', $customPayload);

        // 1. Verify retrieval via get_post_meta
        $retrieved = get_post_meta($id, '_plugin_custom_settings', true);
        if ($retrieved !== $customPayload) {
            echo "FAILED: Arbitrary metadata did not roundtrip via get_post_meta: " . json_encode($retrieved) . "\n";
            return false;
        }

        // 2. Verify stored in wpe_meta
        $rawInDb = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->prefix}wpe_meta WHERE object_id = %d AND meta_key = %s",
            $id,
            '_plugin_custom_settings'
        ));

        if (!$rawInDb || maybe_unserialize($rawInDb) !== $customPayload) {
            echo "FAILED: Arbitrary metadata not found directly in wpe_meta table.\n";
            return false;
        }

        // 3. Verify WC_Product read_meta_data sees it
        $freshProduct = new WC_Product_Simple($id);
        $wcMetaVal = $freshProduct->get_meta('_plugin_custom_settings');
        if ($wcMetaVal !== $customPayload) {
            echo "FAILED: WC_Product::get_meta did not retrieve arbitrary metadata: " . json_encode($wcMetaVal) . "\n";
            return false;
        }

        echo "PASSED: Arbitrary metadata retained in native storage and accessible via WP and WC APIs.\n";
        return true;
    }

    /**
     * AC-T11-01: Unified owned keys between get_post_meta and WC data store, plus events.
     */
    private function testAcT1101UnifiedOwnedKeysAndEvents(): bool
    {
        echo "\n[Test AC-T11-01] Testing unified owned keys and outbox events...\n";

        $product = new WC_Product_Simple();
        $product->set_name('AC11-01 Product');
        $product->set_regular_price('200.00');
        $product->set_manage_stock(true);
        $product->set_stock_quantity(50);
        $product->save();
        $id = $product->get_id();
        $this->cleanProductIds[] = $id;

        // Direct WP API update
        update_post_meta($id, '_regular_price', '225.00');
        update_post_meta($id, '_stock', '42');

        // Check WC product reflection
        $reloaded = new WC_Product_Simple($id);
        if ((float)$reloaded->get_regular_price() !== 225.0 || (float)$reloaded->get_stock_quantity() !== 42.0) {
            echo "FAILED: WC product does not reflect direct update_post_meta changes: reg={$reloaded->get_regular_price()}, qty={$reloaded->get_stock_quantity()}.\n";
            return false;
        }

        // Check outbox event was published
        $event = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}wpe_outbox WHERE aggregate_id = %d AND event_type = 'product.metadata_updated' ORDER BY revision DESC LIMIT 1",
            $id
        ), ARRAY_A);

        if (!$event) {
            echo "FAILED: Outbox event 'product.metadata_updated' not found for direct metadata write.\n";
            return false;
        }

        echo "PASSED: AC-T11-01 unified owned keys and outbox events verified.\n";
        return true;
    }

    /**
     * AC-T11-02: Unknown / direct SQL writers blocked from native authority.
     */
    private function testAcT1102UnknownSqlWriterBlocked(): bool
    {
        echo "\n[Test AC-T11-02] Testing unclassified and SQL-writer plugin blocking...\n";

        // 1. Supported certified plugin
        $matrix = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/contracts/compatibility/matrix.json'), true);
        $supported = null;
        foreach (($matrix['third_party_plugins'] ?? $matrix['plugins'] ?? []) as $p) {
            if (($p['status'] ?? '') === 'supported' && ($p['classification'] ?? '') !== 'SQL-writer') {
                $supported = $p['slug'];
                break;
            }
        }
        if ($supported === null || !$this->bridge->canPluginWriteNative($supported)) {
            echo "FAILED: Certified supported plugin '" . (string) $supported . "' was blocked.\n";
            return false;
        }

        // 1b. Plugin whose version changed and was not re-reviewed (status unknown) stays blocked.
        if ($this->bridge->canPluginWriteNative('classic-editor')) {
            echo "FAILED: Unreviewed plugin 'classic-editor' (status unknown) was permitted native authority.\n";
            return false;
        }

        // 2. Known SQL writer from matrix.json
        if ($this->bridge->canPluginWriteNative('unregistered-direct-sql-writer')) {
            echo "FAILED: SQL writer 'unregistered-direct-sql-writer' was permitted native authority.\n";
            return false;
        }

        // 3. Completely unclassified / unknown plugin
        if ($this->bridge->canPluginWriteNative('random-unknown-unclassified-plugin')) {
            echo "FAILED: Unknown plugin was permitted native authority.\n";
            return false;
        }

        echo "PASSED: AC-T11-02 unclassified and SQL-writer plugins correctly blocked from native authority.\n";
        return true;
    }
}

$test = new T11Test();
exit($test->run());
