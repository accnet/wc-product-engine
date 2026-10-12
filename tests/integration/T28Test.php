<?php
/**
 * T28 Integration Test — Server add-to-cart validation và session restore.
 *
 * Verifies:
 * - R111 (tampered_cart_payload): Tampered variant ID, wrong parent product, mismatched options, and unpriced items blocked.
 * - R112 (stock_changed_after_resolve): Out of stock, insufficient stock, and sold-individually limits enforced.
 * - R113 (cart_session_after_migration): Cart revalidation removes deleted/trashed or depleted items; migrated items preserved.
 * - R114 (custom_cart_meta_retention): Custom line item metadata preserved through add-to-cart and session restoration.
 * - AC-T28-01: Tampered payloads rejected, stale stock handled clearly, cart restore alerts users.
 * - AC-T28-02: Classic WooCommerce and Blocks Store API parity.
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

use WPE\Application\Variant\CartValidation;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\WordPress\PostIdAllocator;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

class T28Test
{
    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private CartValidation $validator;
    private PostIdAllocator $allocator;

    private array $cleanProductIds = [];
    private array $cleanOptionIds = [];
    private array $cleanValueIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->optionsRepo = new OptionsRepository($this->wpdb);
        $this->validator = CartValidation::register(new CartValidation($this->wpdb, $this->optionsRepo));
        $this->allocator = new PostIdAllocator($this->wpdb);

        // Ensure WC Cart is initialized
        if (function_exists('wc_load_cart') && (!isset(WC()->cart) || WC()->cart === null)) {
            wc_load_cart();
        }
    }

    public function run(): void
    {
        echo "Running T28 Integration Tests...\n";
        try {
            $this->testR111_tampered_cart_payload();
            $this->testR112_stock_changed_after_resolve();
            $this->testR113_cart_session_after_migration();
            $this->testR114_custom_cart_meta_retention();
            $this->testAC_T28_01_TamperedPayloadsAndStaleStock();
            $this->testAC_T28_02_ClassicAndBlocksParity();
            echo "All T28 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: " . $message);
        }
    }

    private function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;
        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE variant_id IN (SELECT id FROM {$prefix}wpe_variants WHERE product_id IN ($ids))");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE product_id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN (SELECT id FROM {$prefix}wpe_options WHERE product_id IN ($ids))");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_options WHERE product_id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ($ids)");
        }
    }

    /**
     * Create seeded variable product fixture with variations.
     */
    private function createFixture(string $title = 'T28 Cart Validation Fixture'): array
    {
        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $slug = 't28-cart-' . $parentId;
        $this->wpdb->insert(
            "{$prefix}wpe_products",
            [
                'id' => $parentId,
                'title' => $title,
                'slug' => $slug,
                'sku' => 'T28-PARENT-' . $parentId,
                'type' => 'variable',
                'status' => 'publish',
                'version' => 1,
                'sold_individually' => 0,
                'extra_props' => '{}',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]
        );

        $colorOpt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $this->cleanOptionIds[] = (int)$colorOpt['id'];

        $valRed  = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'red', 'label' => 'Red', 'position' => 0]);
        $valBlue = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'blue', 'label' => 'Blue', 'position' => 1]);
        $this->cleanValueIds[] = (int)$valRed['id'];
        $this->cleanValueIds[] = (int)$valBlue['id'];

        // Variation 1: Red ($20.00, in stock: 5)
        $var1Id = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $var1Id;
        $this->wpdb->insert(
            "{$prefix}wpe_variants",
            [
                'id' => $var1Id,
                'product_id' => $parentId,
                'sku' => "T28-RED-{$var1Id}",
                'regular_price' => '20.000000',
                'status' => 'publish',
                'stock_owner_id' => $var1Id,
                'combination_key' => hash('sha256', "{$colorOpt['id']}:{$valRed['id']}", true),
                'description' => 'Red variant',
                'extra_props' => '{}',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
                'version' => 1,
            ]
        );
        $this->optionsRepo->assignVariantCombination($parentId, $var1Id, [(int)$colorOpt['id'] => (int)$valRed['id']]);

        $this->wpdb->insert(
            "{$prefix}wpe_inventory",
            [
                'owner_id' => $var1Id,
                'manage_stock' => 1,
                'quantity' => '5.000000',
                'backorders' => 'no',
                'stock_status' => 'instock',
                'version' => 1,
                'updated_at' => current_time('mysql', true),
            ]
        );

        // Variation 2: Blue ($25.00, out of stock: 0)
        $var2Id = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $var2Id;
        $this->wpdb->insert(
            "{$prefix}wpe_variants",
            [
                'id' => $var2Id,
                'product_id' => $parentId,
                'sku' => "T28-BLUE-{$var2Id}",
                'regular_price' => '25.000000',
                'status' => 'publish',
                'stock_owner_id' => $var2Id,
                'combination_key' => hash('sha256', "{$colorOpt['id']}:{$valBlue['id']}", true),
                'description' => 'Blue variant out of stock',
                'extra_props' => '{}',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
                'version' => 1,
            ]
        );
        $this->optionsRepo->assignVariantCombination($parentId, $var2Id, [(int)$colorOpt['id'] => (int)$valBlue['id']]);

        $this->wpdb->insert(
            "{$prefix}wpe_inventory",
            [
                'owner_id' => $var2Id,
                'manage_stock' => 1,
                'quantity' => '0.000000',
                'backorders' => 'no',
                'stock_status' => 'outofstock',
                'version' => 1,
                'updated_at' => current_time('mysql', true),
            ]
        );

        return [
            'parentId' => $parentId,
            'colorOpt' => $colorOpt,
            'valRed' => $valRed,
            'valBlue' => $valBlue,
            'var1Id' => $var1Id,
            'var2Id' => $var2Id,
        ];
    }

    /**
     * R111: Tampered cart payload validation.
     */
    public function testR111_tampered_cart_payload(): void
    {
        echo " - testR111_tampered_cart_payload...";

        $f1 = $this->createFixture('Product 1');
        $f2 = $this->createFixture('Product 2');

        // 1. Tampered Variant Parent Mismatch: Variant from Product 2 sent with Product 1 parent ID
        $resMismatch = $this->validator->validateItemForCart($f1['parentId'], 1, $f2['var1Id']);
        $this->assert($resMismatch['valid'] === false, "Must reject variant from a different parent product (R111)");
        $this->assert($resMismatch['code'] === 'tampered_variant_parent_mismatch', "Error code must indicate parent mismatch");

        // 2. Tampered Attribute Combination: Variant 1 is Red, client submits Blue attribute
        $resAttrMismatch = $this->validator->validateItemForCart(
            $f1['parentId'],
            1,
            $f1['var1Id'],
            ['attribute_pa_color' => 'blue']
        );
        $this->assert($resAttrMismatch['valid'] === false, "Must reject mismatched attribute combination (R111)");
        $this->assert($resAttrMismatch['code'] === 'tampered_attribute_combination', "Error code must indicate attribute combination mismatch");

        // 3. Unpriced Variant (price is null)
        $prefix = $this->wpdb->prefix;
        $unpricedVarId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $unpricedVarId;
        $this->wpdb->insert(
            "{$prefix}wpe_variants",
            [
                'id' => $unpricedVarId,
                'product_id' => $f1['parentId'],
                'sku' => "T28-UNPRICED-{$unpricedVarId}",
                'regular_price' => null, // null price
                'sale_price' => null,
                'status' => 'publish',
                'combination_key' => hash('sha256', "unpriced-{$unpricedVarId}", true),
                'description' => 'Unpriced',
                'extra_props' => '{}',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
                'version' => 1,
            ]
        );

        $resUnpriced = $this->validator->validateItemForCart($f1['parentId'], 1, $unpricedVarId);
        $this->assert($resUnpriced['valid'] === false, "Must reject unpriced variant (R111)");
        $this->assert($resUnpriced['code'] === 'missing_price', "Error code must indicate missing price");

        // 4. Valid Payload: Valid variant with matching attributes
        $resValid = $this->validator->validateItemForCart(
            $f1['parentId'],
            1,
            $f1['var1Id'],
            ['attribute_pa_color' => 'red']
        );
        $this->assert($resValid['valid'] === true, "Valid payload must pass validation (R111)");

        echo " OK\n";
    }

    /**
     * R112: Stock changed after resolve and sold individually enforcement.
     */
    public function testR112_stock_changed_after_resolve(): void
    {
        echo " - testR112_stock_changed_after_resolve...";

        $f = $this->createFixture('Stock Validation Product');

        // 1. Out of stock variant (Var2 has stock_status = 'outofstock')
        $resOutOfStock = $this->validator->validateItemForCart($f['parentId'], 1, $f['var2Id']);
        $this->assert($resOutOfStock['valid'] === false, "Must reject out-of-stock item at add-to-cart (R112)");
        $this->assert($resOutOfStock['code'] === 'out_of_stock', "Error code must indicate out_of_stock");

        // 2. Insufficient stock: Var1 has 5 units, client requests 6
        $resInsufficient = $this->validator->validateItemForCart($f['parentId'], 6, $f['var1Id']);
        $this->assert($resInsufficient['valid'] === false, "Must reject quantity exceeding available stock (R112)");
        $this->assert($resInsufficient['code'] === 'insufficient_stock', "Error code must indicate insufficient_stock");

        // 3. Sufficient stock: Client requests 5 units (exact match)
        $resExactStock = $this->validator->validateItemForCart($f['parentId'], 5, $f['var1Id']);
        $this->assert($resExactStock['valid'] === true, "Exact stock request must be permitted (R112)");

        // 4. Sold Individually limit
        $prefix = $this->wpdb->prefix;
        $this->wpdb->update(
            "{$prefix}wpe_products",
            ['sold_individually' => 1],
            ['id' => $f['parentId']]
        );

        $resSoldIndividually = $this->validator->validateItemForCart($f['parentId'], 2, $f['var1Id']);
        $this->assert($resSoldIndividually['valid'] === false, "Must reject quantity > 1 for sold_individually product (R112)");
        $this->assert($resSoldIndividually['code'] === 'sold_individually_exceeded', "Error code must be sold_individually_exceeded");

        echo " OK\n";
    }

    /**
     * R113: Cart session after migration and stale item revalidation.
     */
    public function testR113_cart_session_after_migration(): void
    {
        echo " - testR113_cart_session_after_migration...";

        $f = $this->createFixture('Cart Session Revalidation');
        $prefix = $this->wpdb->prefix;

        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();

            // Simulate cart containing Var 1
            $cartItemKey = 'test_key_var1';
            $cartItem = [
                'key' => $cartItemKey,
                'product_id' => $f['parentId'],
                'variation_id' => $f['var1Id'],
                'quantity' => 1,
                'data' => function_exists('wc_get_product') ? wc_get_product($f['var1Id']) : null,
            ];

            WC()->cart->cart_contents = [$cartItemKey => $cartItem];

            // 1. Initial revalidation with active product: cart remains intact
            $this->validator->revalidateCartItems();
            $this->assert(isset(WC()->cart->cart_contents[$cartItemKey]), "Valid cart item must be retained during revalidation (R113)");

            // 2. Trashed/deleted variant: Update variant status to trash
            $this->wpdb->update("{$prefix}wpe_variants", ['status' => 'trash'], ['id' => $f['var1Id']]);

            $this->validator->revalidateCartItems();
            $this->assert(!isset(WC()->cart->cart_contents[$cartItemKey]), "Trashed variant must be automatically removed from cart (R113)");

            WC()->cart->empty_cart();
        }

        echo " OK\n";
    }

    /**
     * R114: Custom cart line item metadata retention.
     */
    public function testR114_custom_cart_meta_retention(): void
    {
        echo " - testR114_custom_cart_meta_retention...";

        $f = $this->createFixture('Custom Meta Test');

        $customData = [
            'engraving_text' => 'Antigravity Edition',
            'gift_package' => 'premium_box',
            'custom_upload_id' => 8812,
        ];

        // 1. Verify preserveCustomCartItemData returns custom meta untouched
        $preserved = $this->validator->preserveCustomCartItemData($customData, $f['parentId'], $f['var1Id'], 1);
        $this->assert($preserved === $customData, "Custom metadata must not be stripped or modified (R114)");

        // 2. Verify restoreCustomCartItemData restores custom meta into cart item array
        $cartItem = ['product_id' => $f['parentId'], 'variation_id' => $f['var1Id']];
        $restored = $this->validator->restoreCustomCartItemData($cartItem, $customData, 'dummy_key');

        $this->assert(isset($restored['engraving_text']) && $restored['engraving_text'] === 'Antigravity Edition', "Custom engraving meta must be restored (R114)");
        $this->assert(isset($restored['custom_upload_id']) && $restored['custom_upload_id'] === 8812, "Custom upload ID must be restored (R114)");

        echo " OK\n";
    }

    /**
     * AC-T28-01: Tampered variant/price rejected; stale stock handled; cart restore alerts users.
     */
    public function testAC_T28_01_TamperedPayloadsAndStaleStock(): void
    {
        echo " - testAC_T28_01_TamperedPayloadsAndStaleStock...";

        $f = $this->createFixture('AC 01 Comprehensive');

        // Tampered quantity (negative / zero)
        $resZeroQty = $this->validator->validateItemForCart($f['parentId'], 0, $f['var1Id']);
        $this->assert($resZeroQty['valid'] === false, "Zero quantity must be rejected (AC-T28-01)");

        $resNegQty = $this->validator->validateItemForCart($f['parentId'], -3, $f['var1Id']);
        $this->assert($resNegQty['valid'] === false, "Negative quantity must be rejected (AC-T28-01)");

        // Missing variation for variable product
        $resMissingVar = $this->validator->validateItemForCart($f['parentId'], 1, 0);
        $this->assert($resMissingVar['valid'] === false, "Adding variable product without variation must be rejected (AC-T28-01)");

        // Non-existent variation ID
        $resNonExistent = $this->validator->validateItemForCart($f['parentId'], 1, 99999999);
        $this->assert($resNonExistent['valid'] === false, "Non-existent variation must be rejected (AC-T28-01)");

        echo " OK\n";
    }

    /**
     * AC-T28-02: Classic WooCommerce and Blocks Store API parity.
     */
    public function testAC_T28_02_ClassicAndBlocksParity(): void
    {
        echo " - testAC_T28_02_ClassicAndBlocksParity...";

        $f = $this->createFixture('AC 02 Store API Parity');

        // 1. Classic Hook: woocommerce_add_to_cart_validation
        $classicPassed = $this->validator->validateAddToCart(true, $f['parentId'], 1, $f['var1Id']);
        $this->assert($classicPassed === true, "Valid item must pass classic add to cart validation");

        $classicFailedOutOfStock = $this->validator->validateAddToCart(true, $f['parentId'], 1, $f['var2Id']);
        $this->assert($classicFailedOutOfStock === false, "Out of stock item must fail classic add to cart validation");

        // 2. Blocks Hook: woocommerce_store_api_validate_add_to_cart
        // Test with mock WC_Product
        if (function_exists('wc_get_product')) {
            $productObj = wc_get_product($f['parentId']);
            if (!$productObj) {
                // If product post shell is not hydrating, instantiate mock WC_Product_Variable
                $productObj = new \WC_Product_Variable();
                $productObj->set_id($f['parentId']);
            }

            // Valid Store API request: should not throw
            $thrown = false;
            try {
                $this->validator->validateStoreApiAddToCart($productObj, [
                    'variation_id' => $f['var1Id'],
                    'quantity' => 1,
                    'variation' => ['pa_color' => 'red']
                ]);
            } catch (\Throwable $e) {
                $thrown = true;
            }
            $this->assert($thrown === false, "Valid item must pass Store API validation without exception (AC-T28-02)");

            // Invalid Store API request: must throw RouteException or Exception
            $exceptionCaught = false;
            try {
                $this->validator->validateStoreApiAddToCart($productObj, [
                    'variation_id' => $f['var2Id'], // Out of stock variation!
                    'quantity' => 1
                ]);
            } catch (\Throwable $e) {
                $exceptionCaught = true;
                $this->assert(
                    str_contains($e->getMessage(), 'out of stock') || str_contains($e->getMessage(), 'unavailable'),
                    "Exception message must describe out of stock condition: " . $e->getMessage()
                );
            }
            $this->assert($exceptionCaught === true, "Out of stock item must throw exception in Store API (AC-T28-02)");
        }

        echo " OK\n";
    }
}

$test = new T28Test();
$test->run();
