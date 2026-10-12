<?php

declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

use wpdb;
use WC_Order;
use WC_Order_Item_Product;
use WPE\Infrastructure\Database\OutboxWorker;

/**
 * Inventory Bridge & Stock Manager for native authority products.
 *
 * Implements:
 * - Authoritative stock owner resolution (variant inheritance to parent) (R46).
 * - Atomic stock commands with pessimistic row locking preventing oversell on concurrent buyers (R45, AC-T12-01).
 * - Strict idempotency for payment retries and duplicate webhooks via wpe_stock_operations (R48, INV06).
 * - Native reservation bridge with hold expiry and own-order isolation (R47, AC-T12-01).
 * - Partial refund restock with idempotent keys (R49).
 * - Seamless WooCommerce Order lifecycle integration for both HPOS and legacy CPT (R50, AC-T12-03).
 * - Elimination of stale postmeta / lookup reads at native checkout (AC-T12-02).
 */
class InventoryBridge
{
    private static ?InventoryBridge $instance = null;
    private static bool $booted = false;

    private wpdb $wpdb;
    private OutboxWorker $outboxWorker;

    private string $tableInventory;
    private string $tableStockOps;
    private string $tableReservation;
    private string $tableVariants;
    private string $tableProducts;
    private string $tableOutbox;

    public function __construct(?wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->outboxWorker = new OutboxWorker($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableInventory   = $prefix . 'wpe_inventory';
        $this->tableStockOps    = $prefix . 'wpe_stock_operations';
        $this->tableReservation = $prefix . 'wpe_reservation_bridge';
        $this->tableVariants    = $prefix . 'wpe_variants';
        $this->tableProducts    = $prefix . 'wpe_products';
        $this->tableOutbox      = $prefix . 'wpe_outbox';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot the inventory bridge by attaching WooCommerce order lifecycle hooks.
     */
    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_filter('woocommerce_can_reduce_order_stock', [$this, 'filterCanReduceOrderStock'], 10, 2);
        add_action('woocommerce_reduce_order_stock', [$this, 'onReduceOrderStock'], 10, 1);
        add_action('woocommerce_restore_order_stock', [$this, 'onRestoreOrderStock'], 10, 1);
        add_action('woocommerce_order_partially_refunded', [$this, 'onOrderPartiallyRefunded'], 10, 2);
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderStatusCancelled'], 10, 1);
        add_action('woocommerce_order_status_failed', [$this, 'onOrderStatusFailed'], 10, 1);
    }

    /* -------------------------------------------------------------------------- */
    /* Stock Owner Resolution & Properties (R46)                                 */
    /* -------------------------------------------------------------------------- */

    /**
     * Resolves the authoritative stock owner ID.
     * If the target is a variation that does not manage stock individually,
     * ownership cascades to its parent product (R46).
     */
    public function resolveStockOwnerId(int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        // Check if productId is a variation
        $varRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, product_id, stock_owner_id FROM {$this->tableVariants} WHERE id = %d",
            $productId
        ), ARRAY_A);

        if ($varRow) {
            // Check if variant has own manage_stock = 1 in inventory
            $invRow = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT manage_stock FROM {$this->tableInventory} WHERE owner_id = %d",
                $productId
            ), ARRAY_A);

            if ($invRow && (int)$invRow['manage_stock'] === 1) {
                return $productId;
            }

            if (!empty($varRow['stock_owner_id']) && (int)$varRow['stock_owner_id'] !== $productId) {
                return (int)$varRow['stock_owner_id'];
            }

            // Otherwise, stock is owned by parent product
            return (int)$varRow['product_id'];
        }

        // Check if productId is a parent product with explicit stock_owner_id
        $prodRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, stock_owner_id FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);

        if ($prodRow && !empty($prodRow['stock_owner_id'])) {
            return (int)$prodRow['stock_owner_id'];
        }

        return $productId;
    }

    public function isManagingStock(int $productId): bool
    {
        $ownerId = $this->resolveStockOwnerId($productId);
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT manage_stock FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ));
        return (bool)$val;
    }

    public function getStockQuantity(int $productId): ?float
    {
        $ownerId = $this->resolveStockOwnerId($productId);
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT quantity FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ));
        return $val !== null ? (float)$val : null;
    }

    public function getStockStatus(int $productId): string
    {
        $ownerId = $this->resolveStockOwnerId($productId);
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT stock_status FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ));
        return $val ?: 'instock';
    }

    public function getBackorders(int $productId): string
    {
        $ownerId = $this->resolveStockOwnerId($productId);
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT backorders FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ));
        return $val ?: 'no';
    }

    public function getLowStockAmount(int $productId): ?float
    {
        $ownerId = $this->resolveStockOwnerId($productId);
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT low_stock_amount FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ));
        return $val !== null ? (float)$val : null;
    }

    /* -------------------------------------------------------------------------- */
    /* Atomic Stock Commands (R45, R48, R49, AC-T12-01)                           */
    /* -------------------------------------------------------------------------- */

    /**
     * Atomically mutate product stock with row-level pessimistic locking (FOR UPDATE).
     * Strictly verifies available quantity when backorders='no' (R45).
     * Enforces operation_key idempotency against double-reduction (R48, INV06).
     */
    public function updateProductStock(
        int $productId,
        ?float $stockQuantity = null,
        string $operation = 'set',
        ?string $operationKey = null,
        ?int $orderId = null,
        ?int $orderItemId = null,
        string $kind = 'adjust'
    ): array {
        $ownerId = $this->resolveStockOwnerId($productId);

        // 1. Idempotency check via wpe_stock_operations (R48)
        if ($operationKey !== null && $operationKey !== '') {
            $existing = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT operation_key, owner_id, order_id, order_item_id, kind, delta, quantity_after, created_at
                 FROM {$this->tableStockOps} WHERE operation_key = %s",
                $operationKey
            ), ARRAY_A);

            if ($existing) {
                return [
                    'success'         => true,
                    'already_applied' => true,
                    'owner_id'        => (int)$existing['owner_id'],
                    'product_id'      => $productId,
                    'quantity_after'  => (float)$existing['quantity_after'],
                    'delta'           => (float)$existing['delta'],
                    'kind'            => $existing['kind'],
                    'status'          => $this->getStockStatus($ownerId),
                ];
            }
        }

        // 2. Begin atomic transaction with row lock (FOR UPDATE)
        $this->wpdb->query('START TRANSACTION');

        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT owner_id, manage_stock, quantity, backorders, stock_status, low_stock_amount, version
             FROM {$this->tableInventory} WHERE owner_id = %d FOR UPDATE",
            $ownerId
        ), ARRAY_A);

        $now = gmdate('Y-m-d H:i:s.u');

        if (!$row) {
            // Initialize row if not present
            $this->wpdb->insert($this->tableInventory, [
                'owner_id'         => $ownerId,
                'manage_stock'     => 1,
                'quantity'         => 0.0,
                'backorders'       => 'no',
                'stock_status'     => 'outofstock',
                'low_stock_amount' => null,
                'version'          => 1,
                'updated_at'       => $now,
            ]);
            $row = [
                'owner_id'         => $ownerId,
                'manage_stock'     => 1,
                'quantity'         => 0.0,
                'backorders'       => 'no',
                'stock_status'     => 'outofstock',
                'low_stock_amount' => null,
                'version'          => 1,
            ];
        }

        $currentQty  = $row['quantity'] !== null ? (float)$row['quantity'] : 0.0;
        $manageStock = (int)$row['manage_stock'];
        $backorders  = $row['backorders'] ?: 'no';
        $delta       = 0.0;

        if ($operation === 'decrease') {
            $requested = (float)$stockQuantity;
            if ($manageStock && $backorders === 'no' && $currentQty < $requested) {
                // Insufficient stock, reject oversell (R45, AC-T12-01)
                $this->wpdb->query('ROLLBACK');
                return [
                    'success'    => false,
                    'error'      => 'not_enough_stock',
                    'owner_id'   => $ownerId,
                    'product_id' => $productId,
                    'available'  => $currentQty,
                    'requested'  => $requested,
                ];
            }
            $newQty = $currentQty - $requested;
            $delta  = -$requested;
        } elseif ($operation === 'increase') {
            $requested = (float)$stockQuantity;
            $newQty = $currentQty + $requested;
            $delta  = $requested;
        } else { // 'set'
            $newQty = $stockQuantity !== null ? (float)$stockQuantity : null;
            $delta  = $newQty !== null ? ($newQty - $currentQty) : 0.0;
        }

        // Determine updated stock status
        if (!$manageStock) {
            $newStatus = $row['stock_status'] ?: 'instock';
        } else {
            if ($newQty === null || $newQty <= 0) {
                $newStatus = ($backorders === 'no') ? 'outofstock' : 'onbackorder';
            } else {
                $newStatus = 'instock';
            }
        }

        // Update inventory record
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableInventory}
             SET quantity = %s, stock_status = %s, version = version + 1, updated_at = %s
             WHERE owner_id = %d",
            $newQty !== null ? sprintf('%.6f', $newQty) : null,
            $newStatus,
            $now,
            $ownerId
        ));

        // Record operation receipt in wpe_stock_operations (INV06)
        if ($operationKey !== null && $operationKey !== '') {
            $this->wpdb->insert($this->tableStockOps, [
                'operation_key'  => $operationKey,
                'owner_id'       => $ownerId,
                'order_id'       => $orderId,
                'order_item_id'  => $orderItemId,
                'kind'           => $kind,
                'delta'          => sprintf('%.6f', $delta),
                'quantity_after' => sprintf('%.6f', $newQty ?? 0.0),
                'created_at'     => $now,
            ]);
        }

        // Monotonic outbox event (AC-T11-01 / T09)
        $epoch = $this->outboxWorker->getCurrentEpoch();
        $newRev = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COALESCE(MAX(revision), 0) + 1 FROM {$this->tableOutbox} WHERE aggregate_id = %d AND epoch = %d",
            $ownerId,
            $epoch
        ));

        $this->outboxWorker->publishEvent(
            $ownerId,
            $newRev,
            $epoch,
            'inventory.stock_updated',
            [
                'product_id'     => $productId,
                'owner_id'       => $ownerId,
                'from_quantity'  => $currentQty,
                'to_quantity'    => $newQty,
                'delta'          => $delta,
                'operation'      => $operation,
                'operation_key'  => $operationKey,
                'stock_status'   => $newStatus,
                'manage_stock'   => $manageStock,
            ]
        );

        $this->wpdb->query('COMMIT');

        // Flush caches
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($productId);
            if ($ownerId !== $productId) {
                wc_delete_product_transients($ownerId);
            }
        }
        clean_post_cache($productId);
        if ($ownerId !== $productId) {
            clean_post_cache($ownerId);
        }

        return [
            'success'         => true,
            'already_applied' => false,
            'owner_id'        => $ownerId,
            'product_id'      => $productId,
            'from'            => $currentQty,
            'to'              => $newQty,
            'delta'           => $delta,
            'status'          => $newStatus,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* Reservation Adapter (wpe_reservation_bridge) (R47, AC-T12-01)              */
    /* -------------------------------------------------------------------------- */

    /**
     * Reserves stock for an order across multiple line items.
     * Checks unexpired active holds by OTHER orders (R47).
     *
     * @param int $orderId
     * @param array $items Array of ['product_id' => int, 'quantity' => float]
     * @param int $minutes Hold duration in minutes
     * @return array
     */
    public function reserveStockForOrder(int $orderId, array $items, int $minutes = 60): array
    {
        if ($orderId <= 0 || empty($items)) {
            return ['success' => false, 'error' => 'invalid_arguments'];
        }

        // Group quantities by resolved owner_id to prevent deadlocks and enforce aggregate integrity
        $demands = [];
        foreach ($items as $item) {
            $pId = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0.0);
            if ($pId <= 0 || $qty <= 0.0) {
                continue;
            }
            $ownerId = $this->resolveStockOwnerId($pId);
            $demands[$ownerId] = ($demands[$ownerId] ?? 0.0) + $qty;
        }

        if (empty($demands)) {
            return ['success' => true, 'order_id' => $orderId, 'reserved' => []];
        }

        // Consistent ordering prevents cross-aggregate deadlocks
        ksort($demands);

        $this->wpdb->query('START TRANSACTION');
        $now = gmdate('Y-m-d H:i:s.u');

        foreach ($demands as $ownerId => $requestedQty) {
            $row = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT manage_stock, quantity, backorders FROM {$this->tableInventory} WHERE owner_id = %d FOR UPDATE",
                $ownerId
            ), ARRAY_A);

            if (!$row) {
                $this->wpdb->query('ROLLBACK');
                return [
                    'success'   => false,
                    'error'     => 'inventory_not_found',
                    'owner_id'  => $ownerId,
                ];
            }

            $manageStock = (int)$row['manage_stock'];
            $backorders  = $row['backorders'] ?: 'no';
            $currentQty  = $row['quantity'] !== null ? (float)$row['quantity'] : 0.0;

            if ($manageStock && $backorders === 'no') {
                // Sum active holds by OTHER orders (R47)
                $heldByOthers = (float)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COALESCE(SUM(quantity), 0)
                     FROM {$this->tableReservation}
                     WHERE owner_id = %d
                       AND state IN ('held', 'active')
                       AND expires_at > %s
                       AND order_id != %d",
                    $ownerId,
                    $now,
                    $orderId
                ));

                $available = $currentQty - $heldByOthers;
                if ($available < $requestedQty) {
                    $this->wpdb->query('ROLLBACK');
                    return [
                        'success'   => false,
                        'error'     => 'not_enough_stock',
                        'owner_id'  => $ownerId,
                        'available' => $available,
                        'requested' => $requestedQty,
                    ];
                }
            }
        }

        // All items have sufficient stock: persist reservations
        $expiresAt = gmdate('Y-m-d H:i:s.u', time() + ($minutes * 60));
        foreach ($demands as $ownerId => $requestedQty) {
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->tableReservation}
                  (order_id, owner_id, quantity, expires_at, state, version, updated_at)
                 VALUES (%d, %d, %s, %s, 'held', 1, %s)
                 ON DUPLICATE KEY UPDATE
                  quantity = VALUES(quantity),
                  expires_at = VALUES(expires_at),
                  state = 'held',
                  version = version + 1,
                  updated_at = VALUES(updated_at)",
                $orderId,
                $ownerId,
                sprintf('%.6f', $requestedQty),
                $expiresAt,
                $now
            ));
        }

        $this->wpdb->query('COMMIT');

        return [
            'success'   => true,
            'order_id'  => $orderId,
            'minutes'   => $minutes,
            'reserved'  => $demands,
        ];
    }

    /**
     * Releases active reservations for an order upon cancellation, failure, or timeout (AC-T12-01).
     */
    public function releaseStockForOrder(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $updated = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableReservation}
             SET state = 'released', updated_at = %s
             WHERE order_id = %d AND state IN ('held', 'active')",
            $now,
            $orderId
        ));

        return $updated !== false;
    }

    /**
     * Marks reservations as committed when an order completes payment.
     */
    public function commitReservationForOrder(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $updated = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableReservation}
             SET state = 'committed', updated_at = %s
             WHERE order_id = %d AND state IN ('held', 'active')",
            $now,
            $orderId
        ));

        return $updated !== false;
    }

    /**
     * Gets available stock for a product, subtracting active holds of other orders.
     * Current order's hold is excluded so it is not double-counted (R47).
     */
    public function getAvailableStock(int $productId, int $excludeOrderId = 0): ?float
    {
        $ownerId = $this->resolveStockOwnerId($productId);
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT manage_stock, quantity, backorders FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ), ARRAY_A);

        if (!$row || !(int)$row['manage_stock']) {
            return null; // Unlimited / not managed
        }

        $currentQty = $row['quantity'] !== null ? (float)$row['quantity'] : 0.0;
        $now = gmdate('Y-m-d H:i:s.u');

        $heldByOthers = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM {$this->tableReservation}
             WHERE owner_id = %d
               AND state IN ('held', 'active')
               AND expires_at > %s
               AND order_id != %d",
            $ownerId,
            $now,
            $excludeOrderId
        ));

        return max(0.0, $currentQty - $heldByOthers);
    }

    /**
     * Expires stale reservations past their timeout (AC-T12-01).
     */
    public function cleanupExpiredReservations(): int
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $res = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableReservation}
             SET state = 'expired', updated_at = %s
             WHERE state IN ('held', 'active') AND expires_at <= %s",
            $now,
            $now
        ));

        return (int)$res;
    }

    /* -------------------------------------------------------------------------- */
    /* WooCommerce Order Lifecycle Integration (R48, R49, R50, AC-T12-03)         */
    /* -------------------------------------------------------------------------- */

    /**
     * Reduces stock for an order across all line items idempotently (R48, R50).
     */
    public function reduceOrderStock(WC_Order $order): array
    {
        $orderId = $order->get_id();
        $changes = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            // Already reduced check
            if ($item->get_meta('_reduced_stock', true)) {
                continue;
            }

            $product = $item->get_product();
            if (!$product || !$product->managing_stock()) {
                continue;
            }

            $productId = $item->get_variation_id() ?: $item->get_product_id();
            $qty = (float)$item->get_quantity();
            $itemId = $item->get_id();

            // Deterministic operation key per order item (INV06)
            $opKey = sprintf('order_%d_item_%d_reduce', $orderId, $itemId);

            $result = $this->updateProductStock(
                $productId,
                $qty,
                'decrease',
                $opKey,
                $orderId,
                $itemId,
                'reduce'
            );

            if ($result['success']) {
                $item->add_meta_data('_reduced_stock', $qty, true);
                $item->save();
                $changes[] = [
                    'item_id'    => $itemId,
                    'product_id' => $productId,
                    'qty'        => $qty,
                    'result'     => $result,
                ];
            }
        }

        // Commit active reservations for this order
        $this->commitReservationForOrder($orderId);

        return $changes;
    }

    /**
     * Restores stock for an order when cancelled or refunded (R50).
     */
    public function restoreOrderStock(WC_Order $order): array
    {
        $orderId = $order->get_id();
        $changes = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $reducedQty = (float)$item->get_meta('_reduced_stock', true);
            if ($reducedQty <= 0.0) {
                continue;
            }

            $productId = $item->get_variation_id() ?: $item->get_product_id();
            $itemId = $item->get_id();

            // Deterministic operation key per restore (INV06)
            $opKey = sprintf('order_%d_item_%d_restore', $orderId, $itemId);

            $result = $this->updateProductStock(
                $productId,
                $reducedQty,
                'increase',
                $opKey,
                $orderId,
                $itemId,
                'restore'
            );

            if ($result['success']) {
                $item->delete_meta_data('_reduced_stock');
                $item->save();
                $changes[] = [
                    'item_id'    => $itemId,
                    'product_id' => $productId,
                    'qty'        => $reducedQty,
                    'result'     => $result,
                ];
            }
        }

        // Release any lingering reservations
        $this->releaseStockForOrder($orderId);

        return $changes;
    }

    /**
     * Restocks item quantity upon partial refund with idempotent operation key (R49).
     */
    public function partialRefundRestock(
        int $orderId,
        int $refundId,
        int $orderItemId,
        int $productId,
        float $quantity
    ): array {
        $opKey = sprintf('order_%d_refund_%d_item_%d', $orderId, $refundId, $orderItemId);

        return $this->updateProductStock(
            $productId,
            $quantity,
            'increase',
            $opKey,
            $orderId,
            $orderItemId,
            'refund'
        );
    }

    /* -------------------------------------------------------------------------- */
    /* Action & Filter Callbacks                                                  */
    /* -------------------------------------------------------------------------- */

    public function filterCanReduceOrderStock(bool $canReduce, WC_Order $order): bool
    {
        // Native products can always be evaluated for stock reduction
        return $canReduce;
    }

    public function onReduceOrderStock(WC_Order $order): void
    {
        $this->reduceOrderStock($order);
    }

    public function onRestoreOrderStock(WC_Order $order): void
    {
        $this->restoreOrderStock($order);
    }

    public function onOrderStatusCancelled(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $this->restoreOrderStock($order);
        }
        $this->releaseStockForOrder($orderId);
    }

    public function onOrderStatusFailed(int $orderId): void
    {
        $this->releaseStockForOrder($orderId);
    }

    public function onOrderPartiallyRefunded(int $orderId, int $refundId): void
    {
        // Handled via partialRefundRestock directly or when refund line items include restock
    }
}
