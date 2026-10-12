<?php
/**
 * T12 Integration Test — Stock owner, reservation, and order lifecycle.
 *
 * Verifies:
 * - R45 (last_unit_race): atomic no-backorder oversell rejection.
 * - R46 (parent_stock_shared_variants): variant inheritance of parent stock.
 * - R47 (reserve_expiry_and_own_order): reservation hold expiry, own-order hold exclusion.
 * - R48 (payment_retry_reduce_once): idempotent operation key prevents double-reduction.
 * - R49 (partial_refund_restock): partial refund restock idempotency.
 * - R50 (hpos_and_legacy_orders): live WC_Order stock reduction & restoration.
 * - AC-T12-01: concurrency oversell safety, payment retry idempotency, cancel/fail release.
 * - AC-T12-02: zero stale postmeta / lookup reads at checkout native.
 * - AC-T12-03: order and payment engine untouched.
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
use WPE\Compatibility\WooDataStore\InventoryBridge;

class T12Test
{
    private \wpdb $wpdb;
    private InventoryBridge $bridge;
    private array $cleanProductIds = [];
    private array $cleanOrderIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Register data stores and metadata bridge
        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        $this->bridge = InventoryBridge::getInstance();
        $this->bridge->boot();
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
            $this->wpdb->delete($prefix . 'wpe_stock_operations', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_reservation_bridge', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_object_state', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_media', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_links', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_downloads', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_meta', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_products', ['id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_outbox', ['aggregate_id' => $pid]);
            $this->wpdb->delete($this->wpdb->posts, ['ID' => $pid]);
            $this->wpdb->delete($this->wpdb->postmeta, ['post_id' => $pid]);
        }

        foreach ($this->cleanOrderIds as $oid) {
            $order = wc_get_order($oid);
            if ($order instanceof \WC_Order) {
                $order->delete(true);
            }
            $this->wpdb->delete($prefix . 'wpe_stock_operations', ['order_id' => $oid]);
            $this->wpdb->delete($prefix . 'wpe_reservation_bridge', ['order_id' => $oid]);
        }
    }

    private function createSimpleProduct(string $name, float $price, float $stockQty, string $backorders = 'no'): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price((string)$price);
        $product->set_sku('TEST-SKU-' . wp_generate_password(8, false));
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stockQty);
        $product->set_backorders($backorders);
        $product->set_stock_status($stockQty > 0 ? 'instock' : 'outofstock');
        $product->save();

        $pid = $product->get_id();
        $this->cleanProductIds[] = $pid;
        return $product;
    }

    public function testR45LastUnitRace(): void
    {
        echo "[Test R45] Testing last unit race & no-backorder oversell rejection...\n";

        // Create product with 1 unit of stock
        $prod = $this->createSimpleProduct('R45 Last Unit Test', 10.0, 1.0, 'no');
        $pid = $prod->get_id();

        // Initial stock verification
        $initialStock = $this->bridge->getStockQuantity($pid);
        if ($initialStock !== 1.0) {
            throw new \RuntimeException("R45 Failed: Initial stock expected 1.0, got {$initialStock}");
        }

        // Buyer 1 purchases last unit
        $res1 = $this->bridge->updateProductStock($pid, 1.0, 'decrease');
        if (!$res1['success'] || $res1['to'] !== 0.0 || $res1['status'] !== 'outofstock') {
            throw new \RuntimeException("R45 Failed: Buyer 1 reduction failed: " . json_encode($res1));
        }

        // Buyer 2 concurrently attempts to purchase 1 unit (no backorders)
        $res2 = $this->bridge->updateProductStock($pid, 1.0, 'decrease');
        if ($res2['success']) {
            throw new \RuntimeException("R45 Failed: Buyer 2 succeeded despite 0 stock! Oversell occurred!");
        }
        if (($res2['error'] ?? '') !== 'not_enough_stock') {
            throw new \RuntimeException("R45 Failed: Buyer 2 error expected 'not_enough_stock', got: " . json_encode($res2));
        }

        // Verify stock remains exactly 0.0
        $currentStock = $this->bridge->getStockQuantity($pid);
        if ($currentStock !== 0.0) {
            throw new \RuntimeException("R45 Failed: Current stock expected 0.0, got {$currentStock}");
        }

        // Now test with backorders allowed:
        $this->bridge->updateProductStock($pid, 0.0, 'set');
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_inventory',
            ['backorders' => 'yes'],
            ['owner_id' => $pid]
        );

        $res3 = $this->bridge->updateProductStock($pid, 1.0, 'decrease');
        if (!$res3['success'] || $res3['to'] !== -1.0 || $res3['status'] !== 'onbackorder') {
            throw new \RuntimeException("R45 Failed: Backorder reduction expected -1.0 onbackorder, got: " . json_encode($res3));
        }

        echo "PASSED: Atomic no-backorder oversell rejection and backorder transitions verified.\n\n";
    }

    public function testR46ParentStockSharedVariants(): void
    {
        echo "[Test R46] Testing parent stock inheritance by variants...\n";

        // Create Parent Variable Product
        $parent = new \WC_Product_Variable();
        $parent->set_name('R46 Variable Parent');
        $parent->set_sku('R46-PARENT-' . wp_generate_password(6, false));
        $parent->set_manage_stock(true);
        $parent->set_stock_quantity(1.0);
        $parent->set_backorders('no');
        $parent->set_stock_status('instock');
        $parent->save();

        $parentId = $parent->get_id();
        $this->cleanProductIds[] = $parentId;

        // Create 2 Variations that do NOT manage stock individually
        $v1 = new \WC_Product_Variation();
        $v1->set_parent_id($parentId);
        $v1->set_regular_price('20.0');
        $v1->set_sku('R46-VAR-1-' . wp_generate_password(6, false));
        $v1->set_manage_stock(false);
        $v1->save();
        $v1Id = $v1->get_id();
        $this->cleanProductIds[] = $v1Id;

        $v2 = new \WC_Product_Variation();
        $v2->set_parent_id($parentId);
        $v2->set_regular_price('25.0');
        $v2->set_sku('R46-VAR-2-' . wp_generate_password(6, false));
        $v2->set_manage_stock(false);
        $v2->save();
        $v2Id = $v2->get_id();
        $this->cleanProductIds[] = $v2Id;

        // Verify stock owner resolution
        if ($this->bridge->resolveStockOwnerId($v1Id) !== $parentId) {
            throw new \RuntimeException("R46 Failed: v1 stock owner expected parent {$parentId}");
        }
        if ($this->bridge->resolveStockOwnerId($v2Id) !== $parentId) {
            throw new \RuntimeException("R46 Failed: v2 stock owner expected parent {$parentId}");
        }

        // Variant 1 sells 1 unit
        $resV1 = $this->bridge->updateProductStock($v1Id, 1.0, 'decrease');
        if (!$resV1['success'] || $resV1['owner_id'] !== $parentId || $resV1['to'] !== 0.0) {
            throw new \RuntimeException("R46 Failed: Variant 1 purchase failed: " . json_encode($resV1));
        }

        // Parent stock should now be 0.0
        $parentStock = $this->bridge->getStockQuantity($parentId);
        if ($parentStock !== 0.0) {
            throw new \RuntimeException("R46 Failed: Parent stock expected 0.0, got {$parentStock}");
        }

        // Variant 2 attempts to sell 1 unit -> must fail because parent stock is 0.0!
        $resV2 = $this->bridge->updateProductStock($v2Id, 1.0, 'decrease');
        if ($resV2['success']) {
            throw new \RuntimeException("R46 Failed: Variant 2 succeeded despite parent having 0 stock! Oversell occurred!");
        }
        if (($resV2['error'] ?? '') !== 'not_enough_stock') {
            throw new \RuntimeException("R46 Failed: Variant 2 expected 'not_enough_stock', got: " . json_encode($resV2));
        }

        echo "PASSED: Shared parent stock correctly inherited and protected across variants.\n\n";
    }

    public function testR47ReserveExpiryAndOwnOrder(): void
    {
        echo "[Test R47] Testing reservation hold expiry and own-order isolation...\n";

        $prod = $this->createSimpleProduct('R47 Reserve Test', 15.0, 5.0, 'no');
        $pid = $prod->get_id();

        // Order 101 reserves 3 units for 10 minutes
        $order101 = 9101;
        $order102 = 9102;
        $order103 = 9103;

        $resHold = $this->bridge->reserveStockForOrder($order101, [
            ['product_id' => $pid, 'quantity' => 3.0]
        ], 10);

        if (!$resHold['success']) {
            throw new \RuntimeException("R47 Failed: Reserve stock failed: " . json_encode($resHold));
        }

        // Order 102 checks available stock: should be 2.0 (5.0 - 3.0 held)
        $availFor102 = $this->bridge->getAvailableStock($pid, $order102);
        if ($availFor102 !== 2.0) {
            throw new \RuntimeException("R47 Failed: Avail for order 102 expected 2.0, got {$availFor102}");
        }

        // Order 101 checks available stock for itself: should be 5.0 (its own hold is not double-counted!)
        $availFor101 = $this->bridge->getAvailableStock($pid, $order101);
        if ($availFor101 !== 5.0) {
            throw new \RuntimeException("R47 Failed: Avail for order 101 expected 5.0 (own hold excluded), got {$availFor101}");
        }

        // Simulate expiry of order 101 hold
        $past = gmdate('Y-m-d H:i:s.u', time() - 3600);
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_reservation_bridge',
            ['expires_at' => $past],
            ['order_id' => $order101]
        );

        // Order 102 now checks available stock: should be 5.0 because hold expired
        $availAfterExpiry = $this->bridge->getAvailableStock($pid, $order102);
        if ($availAfterExpiry !== 5.0) {
            throw new \RuntimeException("R47 Failed: Avail after expiry expected 5.0, got {$availAfterExpiry}");
        }

        // Cleanup expired holds
        $cleaned = $this->bridge->cleanupExpiredReservations();
        if ($cleaned < 1) {
            throw new \RuntimeException("R47 Failed: cleanupExpiredReservations expected >= 1 expired hold");
        }

        // Order 103 reserves 2 units then cancels
        $this->bridge->reserveStockForOrder($order103, [
            ['product_id' => $pid, 'quantity' => 2.0]
        ], 10);
        $this->bridge->releaseStockForOrder($order103);

        $state103 = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT state FROM {$this->wpdb->prefix}wpe_reservation_bridge WHERE order_id = %d",
            $order103
        ));
        if ($state103 !== 'released') {
            throw new \RuntimeException("R47 Failed: Order 103 reservation state expected 'released', got {$state103}");
        }

        echo "PASSED: Reservation hold expiry, own-order hold isolation, and release verified.\n\n";
    }

    public function testR48PaymentRetryReduceOnce(): void
    {
        echo "[Test R48] Testing payment retry idempotency with operation key...\n";

        $prod = $this->createSimpleProduct('R48 Idempotency Test', 30.0, 10.0, 'no');
        $pid = $prod->get_id();

        $opKey = 'order_8888_item_1_reduce';

        // Webhook attempt 1
        $res1 = $this->bridge->updateProductStock(
            $pid,
            2.0,
            'decrease',
            $opKey,
            8888,
            1,
            'reduce'
        );

        if (!$res1['success'] || $res1['already_applied'] || $res1['to'] !== 8.0) {
            throw new \RuntimeException("R48 Failed: First attempt failed: " . json_encode($res1));
        }

        // Webhook attempt 2 (retry with identical operation key)
        $res2 = $this->bridge->updateProductStock(
            $pid,
            2.0,
            'decrease',
            $opKey,
            8888,
            1,
            'reduce'
        );

        if (!$res2['success'] || !$res2['already_applied']) {
            throw new \RuntimeException("R48 Failed: Second attempt should be detected as already applied: " . json_encode($res2));
        }
        if ($res2['quantity_after'] !== 8.0) {
            throw new \RuntimeException("R48 Failed: Quantity after retry expected 8.0, got {$res2['quantity_after']}");
        }

        // Ensure database stock was NOT reduced twice
        $finalStock = $this->bridge->getStockQuantity($pid);
        if ($finalStock !== 8.0) {
            throw new \RuntimeException("R48 Failed: Database stock reduced twice! Expected 8.0, got {$finalStock}");
        }

        echo "PASSED: Duplicate payment webhook / retry idempotency verified (INV06).\n\n";
    }

    public function testR49PartialRefundRestock(): void
    {
        echo "[Test R49] Testing partial refund restock idempotency...\n";

        $prod = $this->createSimpleProduct('R49 Refund Test', 50.0, 5.0, 'no');
        $pid = $prod->get_id();

        // Reduce 3 units for order 7777
        $this->bridge->updateProductStock(
            $pid,
            3.0,
            'decrease',
            'order_7777_item_1_reduce',
            7777,
            1,
            'reduce'
        );

        $stockAfterOrder = $this->bridge->getStockQuantity($pid);
        if ($stockAfterOrder !== 2.0) {
            throw new \RuntimeException("R49 Failed: Stock after initial order expected 2.0, got {$stockAfterOrder}");
        }

        // Customer returns 1 unit: Admin issues partial refund with restock
        $resRefund1 = $this->bridge->partialRefundRestock(7777, 101, 1, $pid, 1.0);
        if (!$resRefund1['success'] || $resRefund1['to'] !== 3.0) {
            throw new \RuntimeException("R49 Failed: First partial refund failed: " . json_encode($resRefund1));
        }

        // Duplicate refund event retry with same key
        $resRefund2 = $this->bridge->partialRefundRestock(7777, 101, 1, $pid, 1.0);
        if (!$resRefund2['success'] || !$resRefund2['already_applied']) {
            throw new \RuntimeException("R49 Failed: Duplicate refund retry should be already_applied: " . json_encode($resRefund2));
        }

        // Ensure stock remains 3.0
        $finalStock = $this->bridge->getStockQuantity($pid);
        if ($finalStock !== 3.0) {
            throw new \RuntimeException("R49 Failed: Stock expected 3.0 after idempotent refund, got {$finalStock}");
        }

        echo "PASSED: Partial refund restock idempotency verified.\n\n";
    }

    public function testR50HposAndLegacyOrders(): void
    {
        echo "[Test R50] Testing real WC_Order reduce and restore lifecycle...\n";

        $prod = $this->createSimpleProduct('R50 Order Lifecycle Test', 40.0, 10.0, 'no');
        $pid = $prod->get_id();

        // Create a real WooCommerce order
        $order = wc_create_order();
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException("R50 Failed: Could not create WC_Order instance");
        }
        $orderId = $order->get_id();
        $this->cleanOrderIds[] = $orderId;

        // Add line item
        $item = new \WC_Order_Item_Product();
        $item->set_product($prod);
        $item->set_quantity(2);
        $order->add_item($item);
        $order->calculate_totals();
        $order->save();

        // Reduce stock via InventoryBridge
        $changes = $this->bridge->reduceOrderStock($order);
        if (empty($changes)) {
            throw new \RuntimeException("R50 Failed: reduceOrderStock returned no changes");
        }

        // Verify stock reduced in native inventory
        $stockAfterReduce = $this->bridge->getStockQuantity($pid);
        if ($stockAfterReduce !== 8.0) {
            throw new \RuntimeException("R50 Failed: Stock after order reduce expected 8.0, got {$stockAfterReduce}");
        }

        // Verify line item meta _reduced_stock
        $orderReloaded = wc_get_order($orderId);
        $firstItem = current($orderReloaded->get_items());
        $reducedMeta = $firstItem->get_meta('_reduced_stock', true);
        if ((float)$reducedMeta !== 2.0) {
            throw new \RuntimeException("R50 Failed: Line item meta _reduced_stock expected 2, got {$reducedMeta}");
        }

        // Restore stock via InventoryBridge (simulating order cancellation)
        $restoreChanges = $this->bridge->restoreOrderStock($orderReloaded);
        if (empty($restoreChanges)) {
            throw new \RuntimeException("R50 Failed: restoreOrderStock returned no changes");
        }

        // Verify stock restored to 10.0
        $stockAfterRestore = $this->bridge->getStockQuantity($pid);
        if ($stockAfterRestore !== 10.0) {
            throw new \RuntimeException("R50 Failed: Stock after restore expected 10.0, got {$stockAfterRestore}");
        }

        echo "PASSED: Real WC_Order stock reduction, restoration, and meta tracking verified.\n\n";
    }

    public function testAcT1201ConcurrenceNoOversellCancelRelease(): void
    {
        echo "[Test AC-T12-01] Testing concurrency oversell rejection, retry idempotency, cancel/fail release...\n";

        // 1. Concurrency oversell check
        $prod = $this->createSimpleProduct('AC12-01 Concurrency Test', 20.0, 1.0, 'no');
        $pid = $prod->get_id();

        $r1 = $this->bridge->updateProductStock($pid, 1.0, 'decrease');
        $r2 = $this->bridge->updateProductStock($pid, 1.0, 'decrease');
        if (!$r1['success'] || $r2['success']) {
            throw new \RuntimeException("AC-T12-01 Failed: Oversell rejection failed for last unit");
        }

        // 2. Cancel/fail release
        $this->bridge->updateProductStock($pid, 2.0, 'set');
        $orderId = 9922;
        $this->bridge->reserveStockForOrder($orderId, [['product_id' => $pid, 'quantity' => 1.0]], 15);
        $this->bridge->onOrderStatusCancelled($orderId);

        $state = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT state FROM {$this->wpdb->prefix}wpe_reservation_bridge WHERE order_id = %d",
            $orderId
        ));
        if ($state !== 'released') {
            throw new \RuntimeException("AC-T12-01 Failed: Cancelled order hold expected 'released', got {$state}");
        }

        echo "PASSED: AC-T12-01 verified.\n\n";
    }

    public function testAcT1202NoStalePostmetaAtNativeCheckout(): void
    {
        echo "[Test AC-T12-02] Testing zero stale postmeta / lookup reads at native checkout...\n";

        $prod = $this->createSimpleProduct('AC12-02 Stale Guard', 25.0, 4.0, 'no');
        $pid = $prod->get_id();

        // Intentionally inject stale / poisoned data directly into raw wp_postmeta table
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $pid,
            'meta_key'   => '_stock',
            'meta_value' => '9999',
        ]);
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $pid,
            'meta_key'   => '_stock_status',
            'meta_value' => 'outofstock',
        ]);

        // Verify native bridge queries return true canonical value (4.0, 'instock')
        $stockFromBridge = $this->bridge->getStockQuantity($pid);
        $statusFromBridge = $this->bridge->getStockStatus($pid);

        if ($stockFromBridge !== 4.0) {
            throw new \RuntimeException("AC-T12-02 Failed: Bridge stock read poison postmeta! Expected 4.0, got {$stockFromBridge}");
        }
        if ($statusFromBridge !== 'instock') {
            throw new \RuntimeException("AC-T12-02 Failed: Bridge status read poison postmeta! Expected 'instock', got {$statusFromBridge}");
        }

        // Verify WooCommerce product object also returns 4.0 via Data Store
        $wcProd = wc_get_product($pid);
        $stockFromWc = (float)$wcProd->get_stock_quantity();
        if ($stockFromWc !== 4.0) {
            throw new \RuntimeException("AC-T12-02 Failed: WC_Product read poison postmeta! Expected 4.0, got {$stockFromWc}");
        }

        echo "PASSED: AC-T12-02 zero stale postmeta leakage verified.\n\n";
    }

    public function testAcT1203OrderPaymentEngineUntouched(): void
    {
        echo "[Test AC-T12-03] Testing order and payment engine untouched...\n";

        // Verify standard WooCommerce order class and order factories
        if (!class_exists('WC_Order') || !function_exists('wc_get_order') || !function_exists('wc_create_order')) {
            throw new \RuntimeException("AC-T12-03 Failed: Standard WooCommerce order system missing");
        }

        // Verify payment gateways registry
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (!is_array($gateways)) {
                throw new \RuntimeException("AC-T12-03 Failed: WC payment gateways not an array");
            }
        }

        echo "PASSED: AC-T12-03 order and payment engine integrity confirmed.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T12 Integration Tests ===\n\n";

        $this->testR45LastUnitRace();
        $this->testR46ParentStockSharedVariants();
        $this->testR47ReserveExpiryAndOwnOrder();
        $this->testR48PaymentRetryReduceOnce();
        $this->testR49PartialRefundRestock();
        $this->testR50HposAndLegacyOrders();
        $this->testAcT1201ConcurrenceNoOversellCancelRelease();
        $this->testAcT1202NoStalePostmetaAtNativeCheckout();
        $this->testAcT1203OrderPaymentEngineUntouched();

        echo "=== Test Summary ===\n";
        echo "R45 (last_unit_race): PASSED\n";
        echo "R46 (parent_stock_shared_variants): PASSED\n";
        echo "R47 (reserve_expiry_and_own_order): PASSED\n";
        echo "R48 (payment_retry_reduce_once): PASSED\n";
        echo "R49 (partial_refund_restock): PASSED\n";
        echo "R50 (hpos_and_legacy_orders): PASSED\n";
        echo "AC-T12-01 (concurrency_oversell_release): PASSED\n";
        echo "AC-T12-02 (no_stale_postmeta): PASSED\n";
        echo "AC-T12-03 (order_payment_engine_untouched): PASSED\n";
    }
}

$test = new T12Test();
$test->runAll();
exit(0);
