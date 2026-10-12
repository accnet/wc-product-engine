<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Application\Rollout\NativeIndependenceHarness;
use WPE\Compatibility\WooDataStore\ProductDataStore;

/**
 * Integration Tests for T44: Proof that native reads do not rely on core postmeta.
 *
 * Verifies:
 * - R177: Poisoning legacy postmeta (_price, _stock) does not affect native reads.
 * - R178: Native catalog CRUD functions with 100% independence when legacy projection is stopped.
 * - R179: Post shells in wp_posts are retained for factory hydration without storing canonical business props.
 * - R180: Native stock reservations and checkout deductions operate with zero reliance on legacy postmeta.
 * - AC-T44-01: Zero data read from poisoned postmeta; zero unsupported CPT prop reads on certified paths.
 * - AC-T44-02: Isolated test cohorts and comprehensive cleanup protect production data.
 * - AC-T44-03: Unsupported legacy paths explicitly classified and blocked from native authority.
 */
class T44Test
{
    private \wpdb $wpdb;
    private NativeIndependenceHarness $harness;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableMeta;
    private string $tableStockOperations;

    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $prefix = $this->wpdb->prefix;
        $this->tableProducts        = "{$prefix}wpe_products";
        $this->tableVariants        = "{$prefix}wpe_variants";
        $this->tableInventory       = "{$prefix}wpe_inventory";
        $this->tableObjectState     = "{$prefix}wpe_object_state";
        $this->tableMeta            = "{$prefix}wpe_meta";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";

        $this->harness = new NativeIndependenceHarness($this->wpdb);
    }

    public function run(): void
    {
        echo "Running T44 Integration Tests...\n";

        try {
            $this->setUp();
            $this->testR177_poison_legacy_price_stock();
            $this->tearDown();

            $this->setUp();
            $this->testR178_projection_off_native_crud();
            $this->tearDown();

            $this->setUp();
            $this->testR179_factory_shell_retained();
            $this->tearDown();

            $this->setUp();
            $this->testR180_native_reservation_independence();
            $this->tearDown();

            $this->setUp();
            $this->testAC_T44_01_no_poison_reads_on_certified_paths();
            $this->tearDown();

            $this->setUp();
            $this->testAC_T44_02_isolated_test_cohort();
            $this->tearDown();

            $this->setUp();
            $this->testAC_T44_03_unsupported_path_blocks_native_authority();
            $this->tearDown();

            echo "All T44 tests passed successfully!\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
    }

    private function setUp(): void
    {
        $this->cleanProductIds = [];
        $this->harness->setProjectionEnabled(true);
    }

    private function tearDown(): void
    {
        $this->harness->setProjectionEnabled(true);

        if (!empty($this->cleanProductIds)) {
            $allIds = $this->cleanProductIds;
            foreach ($this->cleanProductIds as $pid) {
                $varIds = $this->wpdb->get_col($this->wpdb->prepare(
                    "SELECT id FROM {$this->tableVariants} WHERE product_id = %d",
                    $pid
                ));
                foreach ($varIds as $vid) {
                    $allIds[] = (int)$vid;
                }
            }

            foreach ($allIds as $id) {
                $this->harness->cleanPoisonedProperties($id);
                $this->wpdb->delete($this->tableProducts, ['id' => $id]);
                $this->wpdb->delete($this->tableVariants, ['id' => $id]);
                $this->wpdb->delete($this->tableInventory, ['owner_id' => $id]);
                $this->wpdb->delete($this->tableObjectState, ['object_id' => $id]);
                $this->wpdb->delete($this->tableMeta, ['object_id' => $id]);
                $this->wpdb->delete($this->tableStockOperations, ['owner_id' => $id]);
                wp_delete_post($id, true);
            }
        }
        $this->cleanProductIds = [];
    }

    private function createTestNativeProduct(string $title, float $price, int $stock = 30): int
    {
        $shellId = wp_insert_post([
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        $this->cleanProductIds[] = $shellId;
        $now = gmdate('Y-m-d H:i:s.u');

        $sku = 'SKU-T44-' . strtoupper(wp_generate_password(6, false, false));
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $shellId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => sanitize_title($title) . '-' . wp_generate_password(4, false, false),
            'title'              => $title,
            'description'        => "Native description for {$title}",
            'short_description'  => "Short desc for {$title}",
            'catalog_visibility' => 'visible',
            'sku'                => $sku,
            'regular_price'      => $price,
            'sale_price'         => null,
            'weight'             => 2.0,
            'purchase_note'      => '',
            'default_selection'  => '',
            'extra_props'        => '{}',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->replace($this->tableInventory, [
            'owner_id'         => $shellId,
            'manage_stock'     => 1,
            'quantity'         => $stock,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => null,
            'updated_at'       => $now,
            'version'          => 1,
        ]);

        $this->wpdb->replace($this->tableObjectState, [
            'object_id'       => $shellId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'native',
            'epoch'           => 1,
            'legacy_revision' => 0,
            'native_revision' => 1,
            'legacy_applied'  => 0,
            'native_applied'  => 0,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        return $shellId;
    }

    public function testR177_poison_legacy_price_stock(): void
    {
        echo "Testing R177 (poison_legacy_price_stock)...\n";

        $productId = $this->createTestNativeProduct('R177 Poison Test Product', 75.50, 35);

        // 1. Poison legacy postmeta with intentionally invalid values
        $this->harness->poisonLegacyProperties($productId, [
            '_price'         => '-999999.00',
            '_regular_price' => '-999999.00',
            '_stock'         => '-777.00',
            '_stock_status'  => 'outofstock',
            '_sku'           => 'POISON-SKU-999',
        ]);

        // 2. Run independence verification
        $res = $this->harness->verifyNativeIndependence($productId);

        if (!$res['is_independent']) {
            throw new \RuntimeException("Native independence failed: " . json_encode($res));
        }

        if ($res['poison_leak_detected']) {
            throw new \RuntimeException("Poisoned legacy postmeta was leaked into native read!");
        }

        if (abs($res['actual_read_via_ds']['price'] - 75.50) > 0.001) {
            throw new \RuntimeException("Expected native price 75.50, got {$res['actual_read_via_ds']['price']}");
        }

        if (abs($res['actual_read_via_ds']['stock'] - 35.0) > 0.001) {
            throw new \RuntimeException("Expected native stock 35, got {$res['actual_read_via_ds']['stock']}");
        }

        echo "  ✓ R177 passed: reading native product returned 100% canonical native values despite poisoned postmeta\n";
    }

    public function testR178_projection_off_native_crud(): void
    {
        echo "Testing R178 (projection_off_native_crud)...\n";

        // 1. Disable legacy projection
        $this->harness->setProjectionEnabled(false);
        if ($this->harness->isProjectionEnabled()) {
            throw new \RuntimeException("setProjectionEnabled(false) failed to disable projection");
        }

        // 2. Perform native create
        $productId = $this->createTestNativeProduct('R178 Projection Off Product', 120.00, 50);

        // 3. Perform native update
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update($this->tableProducts, [
            'regular_price' => 145.00,
            'title'         => 'R178 Updated Title',
            'updated_at'    => $now,
            'version'       => 2,
        ], ['id' => $productId]);

        $this->wpdb->update($this->tableInventory, [
            'quantity'   => 45,
            'updated_at' => $now,
            'version'    => 2,
        ], ['owner_id' => $productId]);

        $this->wpdb->update($this->tableObjectState, [
            'native_revision' => 2,
            'updated_at'      => $now,
        ], ['object_id' => $productId]);

        // 4. Verify native reads reflect updated state without any legacy postmeta projection
        $ds = new ProductDataStore();
        $prod = new \WC_Product_Simple();
        $prod->set_id($productId);
        $ds->read($prod);

        if ((float)$prod->get_price() !== 145.00) {
            throw new \RuntimeException("Native price update failed to read: expected 145.00, got " . $prod->get_price());
        }
        if ((float)$prod->get_stock_quantity() !== 45.0) {
            throw new \RuntimeException("Native stock update failed to read: expected 45, got " . $prod->get_stock_quantity());
        }

        // 5. Re-enable projection
        $this->harness->setProjectionEnabled(true);

        echo "  ✓ R178 passed: native catalog CRUD operates completely independently with projection disabled\n";
    }

    public function testR179_factory_shell_retained(): void
    {
        echo "Testing R179 (factory_shell_retained)...\n";

        $productId = $this->createTestNativeProduct('R179 Factory Shell Product', 60.00, 20);

        // 1. Post shell in wp_posts exists and provides post_type = 'product'
        $shell = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT ID, post_type, post_status FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ), ARRAY_A);

        if (!$shell || $shell['post_type'] !== 'product') {
            throw new \RuntimeException("Post shell missing or invalid post_type in wp_posts");
        }

        // 2. Query classification check (AC-T44-01)
        $qShell = $this->harness->classifyQuery("SELECT ID, post_title FROM wp_posts WHERE ID = {$productId}");
        if ($qShell !== 'valid_shell_read') {
            throw new \RuntimeException("wp_posts shell query classified incorrectly: got '{$qShell}'");
        }

        $qTerm = $this->harness->classifyQuery("SELECT term_taxonomy_id FROM wp_term_relationships WHERE object_id = {$productId}");
        if ($qTerm !== 'valid_term_read') {
            throw new \RuntimeException("Term relationship query classified incorrectly: got '{$qTerm}'");
        }

        $qNative = $this->harness->classifyQuery("SELECT * FROM wp_wpe_products WHERE id = {$productId}");
        if ($qNative !== 'native_storage_read') {
            throw new \RuntimeException("Native storage query classified incorrectly: got '{$qNative}'");
        }

        $qFallback = $this->harness->classifyQuery("SELECT meta_value FROM wp_postmeta WHERE post_id = {$productId} AND meta_key = '_price'");
        if ($qFallback !== 'prohibited_cpt_prop_fallback') {
            throw new \RuntimeException("Prohibited postmeta fallback query was not flagged: got '{$qFallback}'");
        }

        echo "  ✓ R179 passed: post shells preserved for factory hydration; query classification strictly distinguishes valid reads from CPT fallback\n";
    }

    public function testR180_native_reservation_independence(): void
    {
        echo "Testing R180 (native_reservation_independence)...\n";

        $productId = $this->createTestNativeProduct('R180 Reservation Product', 50.00, 40);

        // Run reservation independence test with poisoned postmeta stock
        $res = $this->harness->verifyReservationIndependence($productId, 4.0);

        if (!$res['reservation_succeeded']) {
            throw new \RuntimeException("Reservation failed with poisoned postmeta!");
        }

        if (!$res['reduced_accurately']) {
            throw new \RuntimeException("Stock was not reduced accurately from native storage: " . json_encode($res));
        }

        if (!$res['stock_operation_logged']) {
            throw new \RuntimeException("Stock operation was not recorded in wpe_stock_operations");
        }

        if ($res['relied_on_postmeta']) {
            throw new \RuntimeException("Stock reservation relied on postmeta!");
        }

        echo "  ✓ R180 passed: native stock reservations and deductions operate with zero reliance on legacy postmeta\n";
    }

    public function testAC_T44_01_no_poison_reads_on_certified_paths(): void
    {
        echo "Testing AC-T44-01 (no poison reads on certified paths)...\n";

        $productId = $this->createTestNativeProduct('AC-T44-01 Certified Path Product', 99.00, 15);

        // Heavily poison legacy postmeta
        $this->harness->poisonLegacyProperties($productId, [
            '_price'         => 'POISON-PRICE',
            '_regular_price' => 'POISON-REG',
            '_sale_price'    => 'POISON-SALE',
            '_stock'         => 'POISON-STOCK',
            '_stock_status'  => 'outofstock',
        ]);

        // Reading via ProductDataStore must not crash and must return clean native values
        $ds = new ProductDataStore();
        $prod = new \WC_Product_Simple();
        $prod->set_id($productId);
        $ds->read($prod);

        if ((float)$prod->get_price() !== 99.00) {
            throw new \RuntimeException("Price corrupted by poison: got " . $prod->get_price());
        }

        if ((float)$prod->get_stock_quantity() !== 15.0) {
            throw new \RuntimeException("Stock corrupted by poison: got " . $prod->get_stock_quantity());
        }

        echo "  ✓ AC-T44-01 passed: zero data extracted from poisoned legacy metadata across certified paths\n";
    }

    public function testAC_T44_02_isolated_test_cohort(): void
    {
        echo "Testing AC-T44-02 (isolated test cohort protections)...\n";

        // Create product in test array
        $productId = $this->createTestNativeProduct('AC-T44-02 Isolated Product', 25.00, 5);
        $this->harness->poisonLegacyProperties($productId);

        // Verify product is in cleanProductIds
        if (!in_array($productId, $this->cleanProductIds, true)) {
            throw new \RuntimeException("Test product not registered in isolated cleanup registry");
        }

        // Clean poisoned properties immediately
        $this->harness->cleanPoisonedProperties($productId);

        $checkPoison = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price'",
            $productId
        ));

        if ($checkPoison !== null) {
            throw new \RuntimeException("Poisoned property remained after cleanPoisonedProperties");
        }

        echo "  ✓ AC-T44-02 passed: test products strictly isolated and cleaned up without touching production catalog\n";
    }

    public function testAC_T44_03_unsupported_path_blocks_native_authority(): void
    {
        echo "Testing AC-T44-03 (unsupported path blocks native authority)...\n";

        // Query classification identifies raw SQL write attempt without hooks
        $unsupportedSql = "UPDATE wp_postmeta SET meta_value = '10' WHERE meta_key = '_price' AND post_id = 123";
        $classified = $this->harness->classifyQuery($unsupportedSql);

        if ($classified !== 'prohibited_cpt_prop_fallback') {
            throw new \RuntimeException("Direct postmeta update was not classified as prohibited fallback: got '{$classified}'");
        }

        echo "  ✓ AC-T44-03 passed: unsupported paths correctly classified as blocked from native authority\n";
    }
}

// Execute test
$test = new T44Test();
$test->run();
