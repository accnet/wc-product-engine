<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Application\Migration\RollbackRunner;
use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Migration\LegacyCapture;

/**
 * Integration Tests for T40: Rollback orchestration and deactivation safety.
 *
 * Verifies:
 * - R161: Rollback after new products and orders preserves latest data, prices, stock, and order line item dereferencing.
 * - R162: Write barrier and in-flight checkout stock mutation draining.
 * - R163: Rollback timeout safety (clean abort without switching authority or leaving corrupt state).
 * - R164: Deactivation safety verification blocking unsafe deactivation with native-only data.
 * - AC-T40-01: Zero data or order reference loss; timed-out rollback does not switch authority.
 * - AC-T40-02: Safe fallback requires full reconciliation, rejecting simple flag flips to stale CPTs.
 * - AC-T40-03: Standalone recovery runbook for forced plugin directory deletion.
 */
class T40Test
{
    private \wpdb $wpdb;
    private RollbackRunner $runner;
    private LegacyMirror $mirror;
    private LegacyCapture $capture;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableMeta;
    private string $tableProductTerms;
    private string $tableStockOperations;
    private string $tableAudit;
    private string $tableControl;

    private array $cleanProductIds = [];
    private array $cleanOrderIds = [];

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
        $this->tableProductTerms    = "{$prefix}wpe_product_terms";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";
        $this->tableAudit           = "{$prefix}wpe_audit";
        $this->tableControl         = "{$prefix}wpe_control";

        $this->capture = new LegacyCapture($this->wpdb);
        $this->mirror  = new LegacyMirror($this->wpdb, $this->capture);
        $this->runner  = new RollbackRunner($this->wpdb, $this->mirror, $this->capture);
    }

    public function run(): void
    {
        echo "Running T40 Integration Tests...\n";

        try {
            $this->setUp();
            $this->testR161_rollback_after_new_products_orders();
            $this->tearDown();

            $this->setUp();
            $this->testR162_rollback_inflight_checkout();
            $this->tearDown();

            $this->setUp();
            $this->testR163_rollback_timeout_no_switch();
            $this->tearDown();

            $this->setUp();
            $this->testR164_deactivation_with_native_only_data();
            $this->tearDown();

            $this->setUp();
            $this->testAC_T40_01_rollback_after_mutations_and_timeout();
            $this->tearDown();

            $this->setUp();
            $this->testAC_T40_02_safe_fallback_rejects_stale_cpt_flip();
            $this->tearDown();

            $this->setUp();
            $this->testAC_T40_03_forced_deletion_recovery_runbook();
            $this->tearDown();

            echo "All T40 tests passed successfully!\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
    }

    private function setUp(): void
    {
        $this->cleanProductIds = [];
        $this->cleanOrderIds = [];
        $this->runner->releaseWriteBarrier();
    }

    private function tearDown(): void
    {
        $this->runner->releaseWriteBarrier();

        if (!empty($this->cleanOrderIds)) {
            foreach ($this->cleanOrderIds as $orderId) {
                wp_delete_post($orderId, true);
            }
        }
        $this->cleanOrderIds = [];

        if (!empty($this->cleanProductIds)) {
            $allIds = $this->cleanProductIds;
            // Also find children variations
            foreach ($this->cleanProductIds as $pid) {
                $childIds = $this->wpdb->get_col($this->wpdb->prepare(
                    "SELECT id FROM {$this->tableVariants} WHERE product_id = %d",
                    $pid
                ));
                foreach ($childIds as $cid) {
                    $allIds[] = (int)$cid;
                }
            }

            foreach ($allIds as $id) {
                $this->wpdb->delete($this->tableProducts, ['id' => $id]);
                $this->wpdb->delete($this->tableVariants, ['id' => $id]);
                $this->wpdb->delete($this->tableInventory, ['owner_id' => $id]);
                $this->wpdb->delete($this->tableObjectState, ['object_id' => $id]);
                $this->wpdb->delete($this->tableMeta, ['object_id' => $id]);
                $this->wpdb->delete($this->tableProductTerms, ['product_id' => $id]);
                $this->wpdb->delete($this->tableStockOperations, ['owner_id' => $id]);
                $this->wpdb->delete($this->tableAudit, ['target_id' => $id]);
                wp_delete_post($id, true);
            }
        }
        $this->cleanProductIds = [];
    }

    private function createTestNativeProduct(string $title, float $price, int $stock = 25): int
    {
        $shellId = wp_insert_post([
            'post_title'   => 'Draft Shell',
            'post_status'  => 'draft',
            'post_type'    => 'product',
        ]);

        $this->cleanProductIds[] = $shellId;
        $now = gmdate('Y-m-d H:i:s.u');

        $sku = 'SKU-T40-' . strtoupper(wp_generate_password(6, false, false));
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
            'weight'             => 1.0,
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

    public function testR161_rollback_after_new_products_orders(): void
    {
        echo "Testing R161 (rollback_after_new_products_orders)...\n";

        // 1. Create native product (initial price $99.99, stock 50)
        $productId = $this->createTestNativeProduct('R161 Rollback Product', 99.99, 50);

        // 2. Add variation
        $varShellId = wp_insert_post([
            'post_title'   => 'Variation Shell',
            'post_status'  => 'draft',
            'post_type'    => 'product_variation',
            'post_parent'  => $productId,
        ]);
        $now = gmdate('Y-m-d H:i:s.u');
        $combKey = hash('sha256', "var_t40_{$varShellId}", true);
        $this->wpdb->insert($this->tableVariants, [
            'id'                     => $varShellId,
            'product_id'             => $productId,
            'status'                 => 'publish',
            'sku'                    => 'SKU-T40-V1',
            'combination_key'        => $combKey,
            'active_combination_key' => $combKey,
            'regular_price'          => 49.99,
            'sale_price'             => null,
            'description'            => 'Var desc',
            'extra_props'            => '{}',
            'created_at'             => $now,
            'updated_at'             => $now,
            'version'                => 1,
        ]);
        $this->wpdb->replace($this->tableInventory, [
            'owner_id'         => $varShellId,
            'manage_stock'     => 1,
            'quantity'         => 20,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => null,
            'updated_at'       => $now,
            'version'          => 1,
        ]);
        $this->wpdb->replace($this->tableObjectState, [
            'object_id'       => $varShellId,
            'kind'            => 'variation',
            'parent_id'       => $productId,
            'authority'       => 'native',
            'epoch'           => 1,
            'legacy_revision' => 0,
            'native_revision' => 1,
            'legacy_applied'  => 0,
            'native_applied'  => 0,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // 3. Native Mutation: price edit from 99.99 to 119.99, revision -> 2
        $this->wpdb->update($this->tableProducts, [
            'regular_price' => 119.99,
            'updated_at'    => gmdate('Y-m-d H:i:s.u'),
            'version'       => 2,
        ], ['id' => $productId]);

        $this->wpdb->update($this->tableObjectState, [
            'native_revision' => 2,
            'updated_at'      => gmdate('Y-m-d H:i:s.u'),
        ], ['object_id' => $productId]);

        // 4. Simulate a real WooCommerce order referencing this product & variation
        $order = wc_create_order();
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException("Could not create test WC_Order");
        }
        $orderId = $order->get_id();
        $this->cleanOrderIds[] = $orderId;

        $item1 = new \WC_Order_Item_Product();
        $item1->set_product_id($productId);
        $item1->set_quantity(2);
        $item1->set_subtotal(239.98);
        $item1->set_total(239.98);
        $order->add_item($item1);

        $item2 = new \WC_Order_Item_Product();
        $item2->set_product_id($productId);
        $item2->set_variation_id($varShellId);
        $item2->set_quantity(1);
        $item2->set_subtotal(49.99);
        $item2->set_total(49.99);
        $order->add_item($item2);

        $order->calculate_totals();
        $order->save();

        // 5. Native sale stock deduction: -2 units (50 -> 48)
        $this->wpdb->update($this->tableInventory, [
            'quantity' => 48,
        ], ['owner_id' => $productId]);

        $this->wpdb->insert($this->tableStockOperations, [
            'operation_key'  => hash('sha256', "sale_{$orderId}_{$productId}", true),
            'owner_id'       => $productId,
            'order_id'       => $orderId,
            'order_item_id'  => $item1->get_id(),
            'kind'           => 'stock_reduce',
            'delta'          => -2,
            'quantity_after' => 48,
            'created_at'     => gmdate('Y-m-d H:i:s.u'),
        ]);

        // 6. Simulate partial refund: +1 unit restock (48 -> 49)
        $this->wpdb->update($this->tableInventory, [
            'quantity' => 49,
        ], ['owner_id' => $productId]);

        $this->wpdb->insert($this->tableStockOperations, [
            'operation_key'  => hash('sha256', "refund_{$orderId}_{$productId}", true),
            'owner_id'       => $productId,
            'order_id'       => $orderId,
            'order_item_id'  => $item1->get_id(),
            'kind'           => 'stock_refund_restock',
            'delta'          => 1,
            'quantity_after' => 49,
            'created_at'     => gmdate('Y-m-d H:i:s.u'),
        ]);

        // 7. Execute Rollback
        $res = $this->runner->executeRollback([$productId]);
        if ($res['status'] !== 'completed') {
            throw new \RuntimeException("Rollback execution failed: status={$res['status']}");
        }

        // 8. Verify authority switched to legacy
        $authority = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT authority FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));
        if ($authority !== 'legacy') {
            throw new \RuntimeException("Authority was not switched to legacy: got '{$authority}'");
        }

        // 9. Verify legacy postmeta has CURRENT edited price (119.99, NOT initial 99.99!)
        $regPrice = (float)get_post_meta($productId, '_regular_price', true);
        if ($regPrice !== 119.99) {
            throw new \RuntimeException("Rollback failed to preserve current native price: expected 119.99, got {$regPrice}");
        }

        // 10. Verify legacy postmeta has current inventory quantity (49)
        $stock = (float)get_post_meta($productId, '_stock', true);
        if ($stock !== 49.0) {
            throw new \RuntimeException("Rollback failed to preserve current stock after sale/refund: expected 49, got {$stock}");
        }

        // 11. Verify variation postmeta projected
        $varPrice = (float)get_post_meta($varShellId, '_price', true);
        if ($varPrice !== 49.99) {
            throw new \RuntimeException("Variation price was not projected: expected 49.99, got {$varPrice}");
        }

        // 12. Verify WC Order line items still dereference valid products
        $reloadedOrder = wc_get_order($orderId);
        $items = $reloadedOrder->get_items();
        if (count($items) !== 2) {
            throw new \RuntimeException("Order items count mismatch: expected 2, got " . count($items));
        }

        foreach ($items as $item) {
            $prod = $item->get_product();
            if (!$prod instanceof \WC_Product) {
                throw new \RuntimeException("Order item failed to dereference valid WC_Product after rollback");
            }
        }

        // 13. Verify historical stock operations preserved intact
        $opCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableStockOperations} WHERE owner_id = %d",
            $productId
        ));
        if ($opCount !== 2) {
            throw new \RuntimeException("Historical stock operations were not preserved: expected 2, got {$opCount}");
        }

        echo "  ✓ R161 passed: rollback after native create/edit/sale/refund preserved all data and order references\n";
    }

    public function testR162_rollback_inflight_checkout(): void
    {
        echo "Testing R162 (rollback_inflight_checkout)...\n";

        $productId = $this->createTestNativeProduct('R162 Inflight Product', 50.0, 10);

        // 1. Acquire write barrier
        $barrierId = $this->runner->acquireWriteBarrier([$productId]);
        if (!$this->runner->isWriteBlocked($productId)) {
            throw new \RuntimeException("Write barrier is not blocking the product");
        }

        // Scoped barrier check: unrelated product is not blocked
        if ($this->runner->isWriteBlocked(9876543)) {
            throw new \RuntimeException("Scoped barrier blocked unrelated product ID");
        }

        // 2. Record in-flight checkout stock mutation
        $orderKey = 'order_inflight_test_' . wp_generate_password(6, false, false);
        $this->runner->recordInflightCheckout($productId, $orderKey);

        if ($this->runner->getInflightCheckoutsCount($productId) !== 1) {
            throw new \RuntimeException("In-flight checkout count mismatch: expected 1");
        }

        // 3. Attempt drain with very small budget (50ms) -> should fail (return false)
        $drained = $this->runner->drainInflightCheckouts(50);
        if ($drained) {
            throw new \RuntimeException("drainInflightCheckouts should not succeed while checkout is still in-flight");
        }

        // 4. Resolve in-flight checkout
        $this->runner->resolveInflightCheckout($productId, $orderKey);
        if ($this->runner->getInflightCheckoutsCount($productId) !== 0) {
            throw new \RuntimeException("In-flight checkout was not resolved");
        }

        // 5. Attempt drain again -> succeeds
        $drained = $this->runner->drainInflightCheckouts(100);
        if (!$drained) {
            throw new \RuntimeException("drainInflightCheckouts failed after checkout resolved");
        }

        // 6. Release write barrier
        $this->runner->releaseWriteBarrier();
        if ($this->runner->isWriteBlocked($productId)) {
            throw new \RuntimeException("Write barrier was not released");
        }

        echo "  ✓ R162 passed: write barrier gracefully blocks and drains in-flight checkout stock mutations\n";
    }

    public function testR163_rollback_timeout_no_switch(): void
    {
        echo "Testing R163 (rollback_timeout_no_switch)...\n";

        $productId = $this->createTestNativeProduct('R163 Timeout Product', 85.0, 15);

        // Execute rollback with simulated timeout
        $res = $this->runner->executeRollback([$productId], [
            'simulate_timeout' => true,
            'timeout_seconds'  => 0.01,
        ]);

        // 1. Verify rollback aborted
        if ($res['status'] !== 'timeout_aborted') {
            throw new \RuntimeException("Rollback did not abort on timeout: status={$res['status']}");
        }

        // 2. Verify write barrier was released on abort (no stuck barrier)
        if ($this->runner->isWriteBlocked($productId)) {
            throw new \RuntimeException("Write barrier remained stuck active after timeout abort");
        }

        // 3. Verify authority is STILL native (AC-T40-01: timed-out rollback does not switch authority!)
        $auth = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT authority FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));
        if ($auth !== 'native') {
            throw new \RuntimeException("Authority was incorrectly switched to '{$auth}' after timeout!");
        }

        $epoch = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT epoch FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));
        if ($epoch !== 1) {
            throw new \RuntimeException("Epoch was altered after timeout abort: epoch={$epoch}");
        }

        // 4. Verify audit alert was logged
        $alertCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableAudit} WHERE target_id = %d AND operation = 'rollback_reconcile_timeout'",
            $productId
        ));
        if ($alertCount < 1) {
            throw new \RuntimeException("rollback_reconcile_timeout alert was not recorded in wpe_audit");
        }

        echo "  ✓ R163 passed: timed-out rollback safely aborted without switching authority or epoch\n";
    }

    public function testR164_deactivation_with_native_only_data(): void
    {
        echo "Testing R164 (deactivation_with_native_only_data)...\n";

        $productId = $this->createTestNativeProduct('R164 Deactivation Product', 30.0, 10);

        // When a product has native authority, deactivation safety must fail
        $safety = $this->runner->validateDeactivationSafety([$productId]);
        if ($safety['safe_to_deactivate']) {
            throw new \RuntimeException("validateDeactivationSafety passed despite active native authority!");
        }
        if (!$safety['maintenance_required']) {
            throw new \RuntimeException("validateDeactivationSafety did not flag maintenance_required");
        }

        // Execute rollback for this product
        $this->runner->executeRollback([$productId]);

        // After rollback, deactivation safety must pass
        $safetyAfter = $this->runner->validateDeactivationSafety([$productId]);
        if (!$safetyAfter['safe_to_deactivate']) {
            throw new \RuntimeException("validateDeactivationSafety failed after full rollback: " . $safetyAfter['message']);
        }
        if ($safetyAfter['maintenance_required']) {
            throw new \RuntimeException("validateDeactivationSafety still requires maintenance after rollback");
        }

        echo "  ✓ R164 passed: deactivation safety verification blocks unsafe deactivation with native-only data\n";
    }

    public function testAC_T40_01_rollback_after_mutations_and_timeout(): void
    {
        echo "Testing AC-T40-01 (rollback after mutations and timeout behavior)...\n";

        $productId = $this->createTestNativeProduct('AC-T40-01 Mutation Product', 70.0, 40);

        // Timeout simulation must NOT switch authority
        $resTimeout = $this->runner->executeRollback([$productId], ['simulate_timeout' => true]);
        if ($resTimeout['status'] !== 'timeout_aborted') {
            throw new \RuntimeException("Timeout did not abort rollback");
        }
        $auth = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT authority FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));
        if ($auth !== 'native') {
            throw new \RuntimeException("Timed-out rollback switched authority to {$auth}");
        }

        // Full rollback succeeds and preserves data
        $resRollback = $this->runner->executeRollback([$productId]);
        if ($resRollback['status'] !== 'completed') {
            throw new \RuntimeException("Full rollback failed");
        }

        $authAfter = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT authority FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));
        if ($authAfter !== 'legacy') {
            throw new \RuntimeException("Authority was not switched to legacy after successful rollback");
        }

        echo "  ✓ AC-T40-01 passed: rollback preserves current mutations and timed-out rollback preserves authority\n";
    }

    public function testAC_T40_02_safe_fallback_rejects_stale_cpt_flip(): void
    {
        echo "Testing AC-T40-02 (safe fallback rejects stale CPT flip)...\n";

        $productId = $this->createTestNativeProduct('AC-T40-02 Stale CPT Product', 200.0, 10);

        // Attempt to call switchAuthorityToLegacy without active Restore Readiness Marker
        $threw = false;
        try {
            $this->runner->switchAuthorityToLegacy([$productId], 2);
        } catch (\RuntimeException $e) {
            $threw = true;
            if (strpos($e->getMessage(), 'Unsafe fallback blocked') === false) {
                throw new \RuntimeException("Unexpected exception message: " . $e->getMessage());
            }
        }

        if (!$threw) {
            throw new \RuntimeException("switchAuthorityToLegacy allowed switching authority without reconciliation marker!");
        }

        echo "  ✓ AC-T40-02 passed: safe fallback strictly blocks simple flag flip to stale CPTs\n";
    }

    public function testAC_T40_03_forced_deletion_recovery_runbook(): void
    {
        echo "Testing AC-T40-03 (forced deletion recovery runbook)...\n";

        $runbook = $this->runner->getForcedDeletionRecoveryRunbook();

        if (empty($runbook['title']) || empty($runbook['steps']) || empty($runbook['recovery_sql'])) {
            throw new \RuntimeException("Forced deletion recovery runbook is incomplete");
        }

        $sql = $runbook['recovery_sql'];
        if (strpos($sql, '_regular_price') === false || strpos($sql, '_stock') === false) {
            throw new \RuntimeException("Recovery SQL missing key postmeta synchronizations");
        }

        echo "  ✓ AC-T40-03 passed: standalone recovery runbook and SQL script generated for forced deletion scenario\n";
    }
}

// Execute test
$test = new T40Test();
$test->run();
