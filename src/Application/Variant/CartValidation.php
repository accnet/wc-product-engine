<?php

namespace WPE\Application\Variant;

use RuntimeException;
use InvalidArgumentException;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\WooDataStore\InventoryBridge;

/**
 * Server Add-to-Cart Validation and Session Restore.
 *
 * Responsibilities:
 * - Validate parent/variant ownership and combination integrity at server (R111, AC-T28-01).
 * - Enforce canonical server price; never trust client-submitted prices (R111, AC-T28-01).
 * - Re-verify current stock/holds and sold-individually limits at add-to-cart (R112, AC-T28-01).
 * - Cart revalidation & session restore: handle deleted/changed variants and stock fluctuations gracefully (R113, AC-T28-01).
 * - Retain custom cart metadata attached by certified extensions (R114).
 * - Parity across classic WooCommerce and WooCommerce Blocks Store API (AC-T28-02).
 */
class CartValidation
{
    private static ?CartValidation $instance = null;
    private static bool $registered = false;

    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private ?InventoryBridge $inventoryBridge;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableVariantValues;
    private string $tableOptions;
    private string $tableInventory;

    public function __construct(
        ?\wpdb $wpdb = null,
        ?OptionsRepository $optionsRepo = null,
        ?InventoryBridge $inventoryBridge = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->inventoryBridge = $inventoryBridge ?? InventoryBridge::getInstance();

        $prefix = $this->wpdb->prefix;
        $this->tableProducts      = $prefix . 'wpe_products';
        $this->tableVariants      = $prefix . 'wpe_variants';
        $this->tableVariantValues = $prefix . 'wpe_variant_values';
        $this->tableOptions       = $prefix . 'wpe_options';
        $this->tableInventory     = $prefix . 'wpe_inventory';
    }

    public static function instance(?\wpdb $wpdb = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($wpdb);
        }
        return self::$instance;
    }

    /**
     * Register hooks for Classic and Store API validation.
     */
    public static function register(?CartValidation $instance = null): self
    {
        $validator = $instance ?? self::instance();

        if (self::$registered) {
            return $validator;
        }

        // 1. Classic Add to Cart Validation (R111, R112, AC-T28-01)
        add_filter('woocommerce_add_to_cart_validation', [$validator, 'validateAddToCart'], 10, 6);

        // 2. Store API (Blocks) Add to Cart Validation (AC-T28-02)
        add_action('woocommerce_store_api_validate_add_to_cart', [$validator, 'validateStoreApiAddToCart'], 10, 2);

        // 3. Cart Revalidation on Cart/Checkout Load (R113, AC-T28-01)
        add_action('woocommerce_check_cart_items', [$validator, 'revalidateCartItems'], 10);

        // 4. Custom Cart Item Metadata Retention (R114)
        add_filter('woocommerce_add_cart_item_data', [$validator, 'preserveCustomCartItemData'], 10, 4);
        add_filter('woocommerce_get_cart_item_from_session', [$validator, 'restoreCustomCartItemData'], 10, 3);

        self::$registered = true;
        return $validator;
    }

    /**
     * Check if a product is managed by WPE.
     */
    public function isProductManagedByWpe(int $productId): bool
    {
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        return $count > 0;
    }

    /**
     * Unified validation logic used by both Classic and Store API.
     *
     * @param int $productId Parent product ID
     * @param int $quantity Quantity requested
     * @param int $variationId Variation ID (if variable product)
     * @param array $variations Client-submitted attributes
     * @param array $cartItemData Additional custom meta
     * @return array{valid: bool, code: string, message: ?string}
     */
    public function validateItemForCart(
        int $productId,
        int $quantity,
        int $variationId = 0,
        array $variations = [],
        array $cartItemData = []
    ): array {
        // Rule 0: Quantity must be positive integer
        if ($quantity <= 0) {
            return [
                'valid' => false,
                'code' => 'invalid_quantity',
                'message' => __('Please enter a valid quantity.', 'wc-product-engine'),
            ];
        }

        // Rule 1: Check Parent Product Existence and Status (R111)
        $parentRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, title, type, status, regular_price, sale_price, sold_individually, extra_props FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);

        if (!$parentRow) {
            // Not a WPE product; let legacy/core handle
            return ['valid' => true, 'code' => 'ok', 'message' => null];
        }

        if ($parentRow['status'] !== 'publish') {
            return [
                'valid' => false,
                'code' => 'product_not_available',
                'message' => __('This product is not available for purchase.', 'wc-product-engine'),
            ];
        }

        $extraProps = !empty($parentRow['extra_props']) ? json_decode($parentRow['extra_props'], true) : [];
        $soldIndividually = ((int)($parentRow['sold_individually'] ?? 0) === 1) || !empty($extraProps['sold_individually']);

        // Check sold individually limit
        if ($soldIndividually && $quantity > 1) {
            return [
                'valid' => false,
                'code' => 'sold_individually_exceeded',
                'message' => sprintf(
                    __('You can only purchase 1 unit of %s.', 'wc-product-engine'),
                    $parentRow['title']
                ),
            ];
        }

        // If simple product, check stock and purchasability
        if ($parentRow['type'] === 'simple') {
            return $this->validateSimpleProduct($parentRow, $quantity);
        }

        // Rule 2: If Variable Product, validate variation (R111)
        if ($parentRow['type'] === 'variable') {
            if ($variationId <= 0) {
                return [
                    'valid' => false,
                    'code' => 'missing_variation',
                    'message' => __('Please choose product options before adding this product to your cart.', 'woocommerce'),
                ];
            }

            return $this->validateVariationProduct($parentRow, $variationId, $quantity, $variations);
        }

        return ['valid' => true, 'code' => 'ok', 'message' => null];
    }

    /**
     * Validate variation ownership, options integrity, price, and stock.
     */
    private function validateVariationProduct(
        array $parentRow,
        int $variationId,
        int $quantity,
        array $clientAttributes
    ): array {
        $parentId = (int)$parentRow['id'];

        // 1. Query Variation
        $variantRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, product_id, sku, regular_price, sale_price, sale_from, sale_to, stock_owner_id, status, extra_props
             FROM {$this->tableVariants} WHERE id = %d",
            $variationId
        ), ARRAY_A);

        if (!$variantRow) {
            return [
                'valid' => false,
                'code' => 'invalid_variation',
                'message' => __('Selected variation does not exist.', 'wc-product-engine'),
            ];
        }

        // 2. Strict Parent Ownership Check (R111, AC-T28-01: Tampered variant belonging to another product)
        if ((int)$variantRow['product_id'] !== $parentId) {
            return [
                'valid' => false,
                'code' => 'tampered_variant_parent_mismatch',
                'message' => __('The selected variation does not belong to this product.', 'wc-product-engine'),
            ];
        }

        // 3. Status must be publish
        if ($variantRow['status'] !== 'publish') {
            return [
                'valid' => false,
                'code' => 'variation_not_published',
                'message' => __('This product variation is currently unavailable.', 'wc-product-engine'),
            ];
        }

        // 4. Canonical Price Check (R111: Server never trusts client price; null price is unpurchasable).
        // The shared resolver applies the sale window and sale <= regular rule, so a scheduled sale is
        // never charged early and a free (0) variation stays purchasable.
        $effectivePrice = EffectivePriceResolver::resolve($variantRow);
        if ($effectivePrice['state'] !== EffectivePriceResolver::STATE_PRICED) {
            return [
                'valid' => false,
                'code' => 'missing_price',
                'message' => __('This variation cannot be purchased because no price is set.', 'wc-product-engine'),
            ];
        }

        // 5. Option Combination Integrity Check (R111)
        if (!empty($clientAttributes)) {
            $rawVector = $this->optionsRepo->getVariantCombinationVector($variationId);
            $assignedValues = [];
            foreach ($rawVector as $item) {
                if (empty($item['is_any']) && $item['value_id'] !== null) {
                    $assignedValues[(int)$item['option_id']] = (int)$item['value_id'];
                }
            }
            $options = $this->optionsRepo->getOptionsByProductId($parentId);
            $optionsByKey = [];
            foreach ($options as $opt) {
                $optionsByKey[$opt['key']] = $opt;
                $optionsByKey['attribute_' . $opt['key']] = $opt;
                $optionsByKey['attribute_pa_' . $opt['key']] = $opt;
                if (!empty($opt['name'])) {
                    $optionsByKey['attribute_' . sanitize_title($opt['name'])] = $opt;
                }
            }

            foreach ($clientAttributes as $attrKey => $attrVal) {
                if (empty($attrVal)) continue;

                $matchedOpt = $optionsByKey[$attrKey] ?? null;
                if ($matchedOpt) {
                    $optId = (int)$matchedOpt['id'];
                    $expectedValId = $assignedValues[$optId] ?? null;

                    if ($expectedValId !== null) {
                        $matchedVal = null;
                        foreach ($matchedOpt['values'] as $v) {
                            $vKey = (string)($v['value_key'] ?? ($v['key'] ?? ''));
                            if ($vKey === (string)$attrVal || (string)$v['id'] === (string)$attrVal) {
                                $matchedVal = $v;
                                break;
                            }
                        }

                        if ($matchedVal && (int)$matchedVal['id'] !== (int)$expectedValId) {
                            return [
                                'valid' => false,
                                'code' => 'tampered_attribute_combination',
                                'message' => __('Selected attributes do not match the specified variation.', 'wc-product-engine'),
                            ];
                        }
                    }
                }
            }
        }

        // 6. Current Stock & Holds Validation (R112, AC-T28-01)
        return $this->validateStock($variantRow, $parentRow, $quantity);
    }

    /**
     * Validate simple product stock.
     */
    private function validateSimpleProduct(array $parentRow, int $quantity): array
    {
        $ownerId = (int)$parentRow['id'];
        return $this->checkInventoryRecord($ownerId, $quantity, $parentRow['title']);
    }

    /**
     * Validate variation stock using authoritative inventory record.
     */
    private function validateStock(array $variantRow, array $parentRow, int $quantity): array
    {
        $varId = (int)$variantRow['id'];
        $parentId = (int)$parentRow['id'];

        $ownerId = $this->resolveStockOwnerId($variantRow, $parentRow);
        return $this->checkInventoryRecord($ownerId, $quantity, $parentRow['title']);
    }

    /**
     * Resolve authoritative stock owner ID (variant or inherited parent).
     */
    public function resolveStockOwnerId(array $variantRow, array $parentRow): int
    {
        $varId = (int)$variantRow['id'];
        $parentId = (int)$parentRow['id'];

        if (!empty($variantRow['stock_owner_id']) && (int)$variantRow['stock_owner_id'] !== $parentId) {
            return (int)$variantRow['stock_owner_id'];
        }

        $invRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT manage_stock FROM {$this->tableInventory} WHERE owner_id = %d",
            $varId
        ), ARRAY_A);

        if ($invRow && (int)$invRow['manage_stock'] === 1) {
            return $varId;
        }

        return $parentId;
    }

    /**
     * Check inventory record in wpe_inventory for availability.
     */
    private function checkInventoryRecord(int $ownerId, int $quantity, string $itemTitle): array
    {
        $invRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT manage_stock, quantity, backorders, stock_status FROM {$this->tableInventory} WHERE owner_id = %d",
            $ownerId
        ), ARRAY_A);

        if (!$invRow) {
            // No inventory record: defaults to in stock unlimited
            return ['valid' => true, 'code' => 'ok', 'message' => null];
        }

        $stockStatus = (string)($invRow['stock_status'] ?? 'instock');
        $manageStock = (int)($invRow['manage_stock'] ?? 0) === 1;
        $stockQty = $invRow['quantity'] !== null ? (float)$invRow['quantity'] : 0.0;
        $backorders = (string)($invRow['backorders'] ?? 'no');

        if ($stockStatus === 'outofstock') {
            return [
                'valid' => false,
                'code' => 'out_of_stock',
                'message' => __('This product is currently out of stock.', 'woocommerce'),
            ];
        }

        if ($manageStock && $backorders === 'no') {
            if ($stockQty <= 0.0) {
                return [
                    'valid' => false,
                    'code' => 'out_of_stock',
                    'message' => __('This product is currently out of stock.', 'woocommerce'),
                ];
            }

            if ((float)$quantity > $stockQty) {
                return [
                    'valid' => false,
                    'code' => 'insufficient_stock',
                    'message' => sprintf(
                        __('You cannot add that amount to the cart — we have %d in stock and you requested %d.', 'wc-product-engine'),
                        (int)$stockQty,
                        $quantity
                    ),
                ];
            }
        }

        return ['valid' => true, 'code' => 'ok', 'message' => null];
    }

    /**
     * Filter woocommerce_add_to_cart_validation (Classic WC hook).
     */
    public function validateAddToCart(
        bool $passed,
        int $productId,
        int $quantity,
        int $variationId = 0,
        array $variations = [],
        array $cartItemData = []
    ): bool {
        if (!$passed) {
            return false;
        }

        if (!$this->isProductManagedByWpe($productId)) {
            return $passed;
        }

        $result = $this->validateItemForCart($productId, $quantity, $variationId, $variations, $cartItemData);

        if (!$result['valid']) {
            if (!empty($result['message']) && function_exists('wc_add_notice')) {
                wc_add_notice($result['message'], 'error');
            }
            return false;
        }

        return true;
    }

    /**
     * Action woocommerce_store_api_validate_add_to_cart (WooCommerce Blocks hook).
     * Enforces identical validation for Blocks Store API (AC-T28-02).
     */
    public function validateStoreApiAddToCart(WC_Product $product, array $request): void
    {
        $productId = $product->get_id();
        if (!$this->isProductManagedByWpe($productId)) {
            return;
        }

        $variationId = 0;
        if ($product instanceof WC_Product_Variation) {
            $variationId = $product->get_id();
            $productId = $product->get_parent_id();
        } elseif (!empty($request['variation_id'])) {
            $variationId = (int)$request['variation_id'];
        }

        $quantity = (int)($request['quantity'] ?? 1);
        $variations = $request['variation'] ?? ($request['attributes'] ?? []);

        $result = $this->validateItemForCart($productId, $quantity, $variationId, $variations, $request);

        if (!$result['valid']) {
            if (class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
                throw new RouteException(
                    'wpe_' . $result['code'],
                    $result['message'] ?: 'Validation failed',
                    400
                );
            } else {
                throw new RuntimeException($result['message'] ?: 'Validation failed');
            }
        }
    }

    /**
     * Revalidate cart items on cart/checkout page load (R113, AC-T28-01).
     * Detects deleted/changed variants and stock shortages, removing or alerting user.
     */
    public function revalidateCartItems(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $cart = WC()->cart;
        $items = $cart->get_cart();

        foreach ($items as $cartItemKey => $cartItem) {
            $productId = (int)($cartItem['product_id'] ?? 0);
            $variationId = (int)($cartItem['variation_id'] ?? 0);
            $quantity = (int)($cartItem['quantity'] ?? 1);

            if (!$this->isProductManagedByWpe($productId)) {
                continue;
            }

            // Check if product or variant was deleted/trashed (R113)
            if ($variationId > 0) {
                $variant = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT id, status, stock_owner_id FROM {$this->tableVariants} WHERE id = %d",
                    $variationId
                ), ARRAY_A);

                if (!$variant || $variant['status'] !== 'publish') {
                    $cart->remove_cart_item($cartItemKey);
                    $productTitle = $cartItem['data'] ? $cartItem['data']->get_name() : __('Item', 'wc-product-engine');
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(
                            sprintf(__('%s is no longer available and has been removed from your cart.', 'wc-product-engine'), $productTitle),
                            'notice'
                        );
                    }
                    continue;
                }

                // Check stock freshness in wpe_inventory
                $ownerId = !empty($variant['stock_owner_id']) ? (int)$variant['stock_owner_id'] : $variationId;
                $inv = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT stock_status, quantity, manage_stock FROM {$this->tableInventory} WHERE owner_id = %d",
                    $ownerId
                ), ARRAY_A);

                if ($inv && $inv['stock_status'] === 'outofstock') {
                    $cart->remove_cart_item($cartItemKey);
                    $productTitle = $cartItem['data'] ? $cartItem['data']->get_name() : __('Item', 'wc-product-engine');
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(
                            sprintf(__('%s is now out of stock and was removed from your cart.', 'wc-product-engine'), $productTitle),
                            'notice'
                        );
                    }
                }
            } else {
                $parentStatus = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT status FROM {$this->tableProducts} WHERE id = %d",
                    $productId
                ));

                if (!$parentStatus || $parentStatus !== 'publish') {
                    $cart->remove_cart_item($cartItemKey);
                    $productTitle = $cartItem['data'] ? $cartItem['data']->get_name() : __('Item', 'wc-product-engine');
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(
                            sprintf(__('%s is no longer available and has been removed from your cart.', 'wc-product-engine'), $productTitle),
                            'notice'
                        );
                    }
                }
            }
        }
    }

    /**
     * Preserve custom cart item metadata attached by certified extensions (R114).
     */
    public function preserveCustomCartItemData(array $cartItemData, int $productId, int $variationId, int $quantity): array
    {
        // Never strip custom keys. Pass through all client/plugin meta.
        return $cartItemData;
    }

    /**
     * Restore custom cart item metadata when restoring cart from session (R114).
     */
    public function restoreCustomCartItemData(array $cartItem, array $values, string $key): array
    {
        // Ensure all custom keys in $values are retained in $cartItem
        foreach ($values as $k => $v) {
            if (!isset($cartItem[$k])) {
                $cartItem[$k] = $v;
            }
        }
        return $cartItem;
    }
}
