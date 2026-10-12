<?php
/**
 * T25 Integration Test — Server resolver và allowed-values engine.
 *
 * Verifies:
 * - R99 (partial_allowed_values): Partial selection allowed values from compatible variants without Cartesian products.
 * - R100 (wildcard_precedence_diff): Wildcard (ANY) precedence matching WooCommerce baseline order.
 * - R101 (stock_price_revision): Stock inheritance, scheduled price, free vs missing price, and revision echo.
 * - R102 (unknown_option_wrong_product): Graceful rejection of unknown options, invalid values, and non-variable products.
 * - AC-T25-01: None, partial, exact, and ambiguous states verified with inherited stock and price semantics.
 * - AC-T25-02: Duplicate overlapping Any wildcards detected and reported as ambiguous without random selection.
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

use WPE\Application\Variant\VariantResolver;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\OptionsRepository;

class T25Test
{
    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private VariantResolver $resolver;
    private PostIdAllocator $allocator;

    private array $cleanProductIds = [];
    private array $cleanOptionIds = [];
    private array $cleanValueIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->optionsRepo = new OptionsRepository($this->wpdb);
        $this->resolver = new VariantResolver($this->wpdb, $this->optionsRepo);
        $this->allocator = new PostIdAllocator($this->wpdb);
    }

    public function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE variant_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE id IN ({$ids}) OR product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanOptionIds)) {
            $optIds = implode(',', array_map('intval', $this->cleanOptionIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE option_id IN ({$optIds})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN ({$optIds})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_options WHERE id IN ({$optIds})");
            $this->cleanOptionIds = [];
        }

        if (!empty($this->cleanValueIds)) {
            $valIds = implode(',', array_map('intval', $this->cleanValueIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE id IN ({$valIds})");
            $this->cleanValueIds = [];
        }
    }

    public function runAll(): void
    {
        echo "Running T25 Integration Tests...\n";

        try {
            $this->testR99_partial_allowed_values();
            $this->testR100_wildcard_precedence_diff();
            $this->testR101_stock_price_revision();
            $this->testR102_unknown_option_wrong_product();
            $this->testAC_T25_01_StatesAndFixtures();
            $this->testAC_T25_02_DuplicateOverlappingAnyReported();

            echo "All T25 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    /**
     * R99: Partial selection allowed values from compatible variants without Cartesian products.
     */
    public function testR99_partial_allowed_values(): void
    {
        echo " - testR99_partial_allowed_values...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'VAR-TSHIRT', 'slug' => 'var-tshirt-' . $parentId, 'title' => 'Variable T-Shirt',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 3
        ]);

        // Create options: Color and Size
        $colorOpt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $sizeOpt  = $this->optionsRepo->createOption($parentId, ['key' => 'pa_size', 'name' => 'Size', 'position' => 1]);
        $colorOptId = (int)$colorOpt['id'];
        $sizeOptId  = (int)$sizeOpt['id'];
        $this->cleanOptionIds = array_merge($this->cleanOptionIds, [$colorOptId, $sizeOptId]);

        $valRed  = $this->optionsRepo->createOptionValue($colorOptId, ['value_key' => 'red', 'label' => 'Red']);
        $valBlue = $this->optionsRepo->createOptionValue($colorOptId, ['value_key' => 'blue', 'label' => 'Blue']);
        $valS    = $this->optionsRepo->createOptionValue($sizeOptId, ['value_key' => 's', 'label' => 'S']);
        $valM    = $this->optionsRepo->createOptionValue($sizeOptId, ['value_key' => 'm', 'label' => 'M']);
        $valL    = $this->optionsRepo->createOptionValue($sizeOptId, ['value_key' => 'l', 'label' => 'L']);
        $this->cleanValueIds = array_merge($this->cleanValueIds, [
            (int)$valRed['id'], (int)$valBlue['id'], (int)$valS['id'], (int)$valM['id'], (int)$valL['id']
        ]);

        // Create 3 variations:
        // Var 1: Red + S
        // Var 2: Red + M
        // Var 3: Blue + L
        // (Red+L, Blue+S, Blue+M do NOT exist!)
        $var1Id = $this->allocator->allocateShell('product_variation');
        $var2Id = $this->allocator->allocateShell('product_variation');
        $var3Id = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$var1Id, $var2Id, $var3Id]);

        foreach ([$var1Id => 'TSHIRT-RED-S', $var2Id => 'TSHIRT-RED-M', $var3Id => 'TSHIRT-BLUE-L'] as $vId => $sku) {
            $this->wpdb->insert("{$prefix}wpe_variants", [
                'id' => $vId, 'product_id' => $parentId, 'sku' => $sku, 'regular_price' => '25.000000',
                'status' => 'publish', 'combination_key' => hash('sha256', (string)$vId, true), 'description' => '',
                'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
            ]);
        }

        $this->optionsRepo->assignVariantCombination($parentId, $var1Id, [$colorOptId => (int)$valRed['id'], $sizeOptId => (int)$valS['id']]);
        $this->optionsRepo->assignVariantCombination($parentId, $var2Id, [$colorOptId => (int)$valRed['id'], $sizeOptId => (int)$valM['id']]);
        $this->optionsRepo->assignVariantCombination($parentId, $var3Id, [$colorOptId => (int)$valBlue['id'], $sizeOptId => (int)$valL['id']]);

        // 1. Partial selection: Color = Red
        $resRed = $this->resolver->resolve($parentId, [$colorOptId => (int)$valRed['id']]);
        $this->assert($resRed['state'] === 'partial', "State must be partial for incomplete selection");
        $this->assert($resRed['variant'] === null, "Variant must be null in partial state");

        $allowedSizesForRed = $resRed['allowed_values'][(string)$sizeOptId];
        $this->assert(in_array((string)$valS['id'], $allowedSizesForRed, true), "Size S must be allowed for Red");
        $this->assert(in_array((string)$valM['id'], $allowedSizesForRed, true), "Size M must be allowed for Red");
        $this->assert(!in_array((string)$valL['id'], $allowedSizesForRed, true), "Size L must NOT be allowed for Red (no Cartesian generation)");

        // 2. Partial selection: Size = L
        $resL = $this->resolver->resolve($parentId, [$sizeOptId => (int)$valL['id']]);
        $allowedColorsForL = $resL['allowed_values'][(string)$colorOptId];
        $this->assert(in_array((string)$valBlue['id'], $allowedColorsForL, true), "Color Blue must be allowed for Size L");
        $this->assert(!in_array((string)$valRed['id'], $allowedColorsForL, true), "Color Red must NOT be allowed for Size L");

        echo " OK\n";
    }

    /**
     * R100: Wildcard (ANY) precedence matching WooCommerce baseline order.
     */
    public function testR100_wildcard_precedence_diff(): void
    {
        echo " - testR100_wildcard_precedence_diff...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'WILD-HOODIE', 'slug' => 'wild-hoodie-' . $parentId, 'title' => 'Wildcard Hoodie',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 5
        ]);

        $colorOpt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $sizeOpt  = $this->optionsRepo->createOption($parentId, ['key' => 'pa_size', 'name' => 'Size', 'position' => 1]);
        $colorOptId = (int)$colorOpt['id'];
        $sizeOptId  = (int)$sizeOpt['id'];
        $this->cleanOptionIds = array_merge($this->cleanOptionIds, [$colorOptId, $sizeOptId]);

        $valBlack = $this->optionsRepo->createOptionValue($colorOptId, ['value_key' => 'black', 'label' => 'Black']);
        $valM     = $this->optionsRepo->createOptionValue($sizeOptId, ['value_key' => 'm', 'label' => 'M']);
        $valXL    = $this->optionsRepo->createOptionValue($sizeOptId, ['value_key' => 'xl', 'label' => 'XL']);
        $this->cleanValueIds = array_merge($this->cleanValueIds, [(int)$valBlack['id'], (int)$valM['id'], (int)$valXL['id']]);

        // Var 1: Exact (Color=Black, Size=M) at position 0
        $varExactId = $this->allocator->allocateShell('product_variation');
        // Var 2: Wildcard (Color=Black, Size=ANY) at position 1
        $varWildId  = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$varExactId, $varWildId]);

        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varExactId, 'product_id' => $parentId, 'sku' => 'HOODIE-BLK-M-EXACT', 'regular_price' => '50.000000',
            'position' => 0, 'status' => 'publish', 'combination_key' => hash('sha256', 'exact', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varWildId, 'product_id' => $parentId, 'sku' => 'HOODIE-BLK-ANY-WILD', 'regular_price' => '55.000000',
            'position' => 1, 'status' => 'publish', 'combination_key' => hash('sha256', 'wild', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $this->optionsRepo->assignVariantCombination($parentId, $varExactId, [$colorOptId => (int)$valBlack['id'], $sizeOptId => (int)$valM['id']]);
        $this->optionsRepo->assignVariantCombination($parentId, $varWildId, [$colorOptId => (int)$valBlack['id'], $sizeOptId => '*']);

        // Selection 1: Black + M -> Should resolve to Exact variation (higher specificity)
        $resExact = $this->resolver->resolve($parentId, [$colorOptId => (int)$valBlack['id'], $sizeOptId => (int)$valM['id']]);
        $this->assert($resExact['state'] === 'exact', "State must be exact");
        $this->assert($resExact['variant']['id'] === (string)$varExactId, "Black-M must resolve to exact variant (HOODIE-BLK-M-EXACT)");
        $this->assert($resExact['variant']['price'] === '50.000000', "Must return exact variant price 50.00");

        // Selection 2: Black + XL -> Exact variant does not match; Wildcard matches
        $resWild = $this->resolver->resolve($parentId, [$colorOptId => (int)$valBlack['id'], $sizeOptId => (int)$valXL['id']]);
        $this->assert($resWild['state'] === 'exact', "State must be exact for wildcard match");
        $this->assert($resWild['variant']['id'] === (string)$varWildId, "Black-XL must resolve to wildcard variant (HOODIE-BLK-ANY-WILD)");
        $this->assert($resWild['variant']['price'] === '55.000000', "Must return wildcard variant price 55.00");

        echo " OK\n";
    }

    /**
     * R101: Stock inheritance, scheduled price, free vs missing price, and revision echo.
     */
    public function testR101_stock_price_revision(): void
    {
        echo " - testR101_stock_price_revision...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'STOCK-PROD', 'slug' => 'stock-prod-' . $parentId, 'title' => 'Stock Test Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 18
        ]);

        // Parent inventory: 10 units in stock
        $this->wpdb->insert("{$prefix}wpe_inventory", [
            'owner_id' => $parentId, 'manage_stock' => 1, 'quantity' => 10,
            'stock_status' => 'instock', 'backorders' => 'no'
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_model', 'name' => 'Model', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $valFree     = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'free-sample', 'label' => 'Free Sample']);
        $valMissing  = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'unpriced', 'label' => 'Unpriced']);
        $valInherit  = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'inherit-stock', 'label' => 'Inherit Stock']);
        $valSale     = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'on-sale', 'label' => 'On Sale']);
        $this->cleanValueIds = array_merge($this->cleanValueIds, [
            (int)$valFree['id'], (int)$valMissing['id'], (int)$valInherit['id'], (int)$valSale['id']
        ]);

        $vFreeId    = $this->allocator->allocateShell('product_variation');
        $vMissingId = $this->allocator->allocateShell('product_variation');
        $vInheritId = $this->allocator->allocateShell('product_variation');
        $vSaleId    = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$vFreeId, $vMissingId, $vInheritId, $vSaleId]);

        // Var Free: Price is explicitly 0 (AC-T25-01)
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $vFreeId, 'product_id' => $parentId, 'sku' => 'VAR-FREE', 'regular_price' => '0.000000',
            'status' => 'publish', 'combination_key' => hash('sha256', 'free', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->optionsRepo->assignVariantCombination($parentId, $vFreeId, [$optId => (int)$valFree['id']]);

        // Var Missing: regular_price and sale_price are NULL
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $vMissingId, 'product_id' => $parentId, 'sku' => 'VAR-MISSING', 'regular_price' => null, 'sale_price' => null,
            'status' => 'publish', 'combination_key' => hash('sha256', 'missing', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->optionsRepo->assignVariantCombination($parentId, $vMissingId, [$optId => (int)$valMissing['id']]);

        // Var Inherit: Inherits parent stock (manage_stock = 0)
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $vInheritId, 'product_id' => $parentId, 'sku' => 'VAR-INHERIT', 'regular_price' => '40.000000',
            'status' => 'publish', 'combination_key' => hash('sha256', 'inherit', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->optionsRepo->assignVariantCombination($parentId, $vInheritId, [$optId => (int)$valInherit['id']]);

        // Var Sale: Scheduled active sale
        $pastDate   = gmdate('Y-m-d H:i:s', time() - 3600);
        $futureDate = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $vSaleId, 'product_id' => $parentId, 'sku' => 'VAR-SALE', 'regular_price' => '100.000000',
            'sale_price' => '75.000000', 'sale_from' => $pastDate, 'sale_to' => $futureDate,
            'status' => 'publish', 'combination_key' => hash('sha256', 'sale', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->optionsRepo->assignVariantCombination($parentId, $vSaleId, [$optId => (int)$valSale['id']]);

        // 1. Revision and Request Sequence
        $resSeq = $this->resolver->resolve($parentId, [$optId => (int)$valFree['id']], 1, '18', 42);
        $this->assert($resSeq['request_sequence'] === 42, "Request sequence must be echoed back");
        $this->assert($resSeq['product_revision'] === '18', "Product revision must match parent product version");

        // 2. Free item (price = 0, purchasable = true)
        $resFree = $this->resolver->resolve($parentId, [$optId => (int)$valFree['id']]);
        $this->assert($resFree['variant']['price'] === '0.000000', "Free item price must be 0.000000");
        $this->assert($resFree['variant']['display_price'] === '0.00', "Free item display price must be 0.00");
        $this->assert($resFree['variant']['purchasable'] === true, "Free item must be purchasable (AC-T25-01)");
        $this->assert($resFree['variant']['reason'] === null, "Free item reason must be null");

        // 3. Missing price (price = null, purchasable = false, reason = missing_price)
        $resMissing = $this->resolver->resolve($parentId, [$optId => (int)$valMissing['id']]);
        $this->assert($resMissing['variant']['price'] === null, "Missing price item price must be null");
        $this->assert($resMissing['variant']['purchasable'] === false, "Missing price item must NOT be purchasable (AC-T25-01)");
        $this->assert($resMissing['variant']['reason'] === 'missing_price', "Missing price reason must be 'missing_price'");

        // 4. Inherited stock check
        $resInherit = $this->resolver->resolve($parentId, [$optId => (int)$valInherit['id']]);
        $this->assert($resInherit['variant']['stock_owner_id'] === (string)$parentId, "Stock owner must be parent ID for inherited stock");
        $this->assert($resInherit['variant']['available'] === true, "Must be available when parent has stock");

        // Drain parent stock to 0
        $this->wpdb->update("{$prefix}wpe_inventory", ['quantity' => 0, 'stock_status' => 'outofstock'], ['owner_id' => $parentId]);
        $resOutOfStock = $this->resolver->resolve($parentId, [$optId => (int)$valInherit['id']]);
        $this->assert($resOutOfStock['variant']['available'] === false, "Inherited variant must be unavailable when parent stock is 0");
        $this->assert($resOutOfStock['variant']['purchasable'] === false, "Inherited variant must be unpurchasable when parent stock is 0");
        $this->assert($resOutOfStock['variant']['reason'] === 'out_of_stock', "Reason must be 'out_of_stock'");

        // 5. Scheduled Sale price check
        $resSale = $this->resolver->resolve($parentId, [$optId => (int)$valSale['id']]);
        $this->assert($resSale['variant']['price'] === '75.000000', "Active sale price must resolve to 75.000000");
        $this->assert($resSale['variant']['display_price'] === '75.00', "Active sale display price must resolve to 75.00");

        echo " OK\n";
    }

    /**
     * R102: Graceful rejection of unknown options, invalid values, and non-variable products.
     */
    public function testR102_unknown_option_wrong_product(): void
    {
        echo " - testR102_unknown_option_wrong_product...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $simpleId = $this->allocator->allocateShell('product');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$parentId, $simpleId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'NORM-PROD', 'slug' => 'norm-prod-' . $parentId, 'title' => 'Norm Variable Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $simpleId, 'sku' => 'SIMPLE-PROD', 'slug' => 'simple-prod-' . $simpleId, 'title' => 'Simple Product',
            'type' => 'simple', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $val = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'green', 'label' => 'Green']);
        $this->cleanValueIds[] = (int)$val['id'];

        // 1. Unknown option ID (999999)
        $resUnknownOpt = $this->resolver->resolve($parentId, [999999 => (int)$val['id']]);
        $this->assert($resUnknownOpt['state'] === 'none', "State must be none for unknown option");
        $this->assert($resUnknownOpt['reason'] === 'unknown_option', "Reason must be 'unknown_option'");

        // 2. Unknown option key string ('attribute_non_existent')
        $resUnknownKey = $this->resolver->resolve($parentId, ['attribute_non_existent' => 'green']);
        $this->assert($resUnknownKey['state'] === 'none', "State must be none for unknown option key");
        $this->assert($resUnknownKey['reason'] === 'unknown_option', "Reason must be 'unknown_option'");

        // 3. Invalid value ID for valid option
        $resInvalidVal = $this->resolver->resolve($parentId, [$optId => 888888]);
        $this->assert($resInvalidVal['state'] === 'none', "State must be none for invalid value");
        $this->assert($resInvalidVal['reason'] === 'invalid_value', "Reason must be 'invalid_value'");

        // 4. Non-existent product ID
        $resInvalidProd = $this->resolver->resolve(99999999, [$optId => (int)$val['id']]);
        $this->assert($resInvalidProd['state'] === 'none', "State must be none for non-existent product");
        $this->assert($resInvalidProd['reason'] === 'invalid_product', "Reason must be 'invalid_product'");

        // 5. Calling resolver on a Simple product (not variable)
        $resSimple = $this->resolver->resolve($simpleId, [$optId => (int)$val['id']]);
        $this->assert($resSimple['state'] === 'none', "State must be none for simple product");
        $this->assert($resSimple['reason'] === 'not_variable_product', "Reason must be 'not_variable_product'");

        echo " OK\n";
    }

    /**
     * AC-T25-01: None, partial, exact, and ambiguous states verified with inherited stock and price semantics.
     */
    public function testAC_T25_01_StatesAndFixtures(): void
    {
        echo " - testAC_T25_01_StatesAndFixtures...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'STATE-PROD', 'slug' => 'state-prod-' . $parentId, 'title' => 'State Test Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $optA = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $optB = $this->optionsRepo->createOption($parentId, ['key' => 'pa_size', 'name' => 'Size', 'position' => 1]);
        $optAId = (int)$optA['id'];
        $optBId = (int)$optB['id'];
        $this->cleanOptionIds = array_merge($this->cleanOptionIds, [$optAId, $optBId]);

        $valRed = $this->optionsRepo->createOptionValue($optAId, ['value_key' => 'red', 'label' => 'Red']);
        $valS   = $this->optionsRepo->createOptionValue($optBId, ['value_key' => 's', 'label' => 'S']);
        $valL   = $this->optionsRepo->createOptionValue($optBId, ['value_key' => 'l', 'label' => 'L']);
        $this->cleanValueIds = array_merge($this->cleanValueIds, [(int)$valRed['id'], (int)$valS['id'], (int)$valL['id']]);

        // Only one variation: Red + S
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $varId;

        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varId, 'product_id' => $parentId, 'sku' => 'STATE-RED-S', 'regular_price' => '30.000000',
            'status' => 'publish', 'combination_key' => hash('sha256', 'red-s', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->optionsRepo->assignVariantCombination($parentId, $varId, [$optAId => (int)$valRed['id'], $optBId => (int)$valS['id']]);

        // State 1: Partial
        $resPartial = $this->resolver->resolve($parentId, [$optAId => (int)$valRed['id']]);
        $this->assert($resPartial['state'] === 'partial', "State must be partial when only Color is selected");

        // State 2: Exact
        $resExact = $this->resolver->resolve($parentId, [$optAId => (int)$valRed['id'], $optBId => (int)$valS['id']]);
        $this->assert($resExact['state'] === 'exact', "State must be exact when Color and Size match");
        $this->assert($resExact['variant']['id'] === (string)$varId, "Must resolve to Var ID");

        // State 3: None (combination Red + L does not exist)
        $resNone = $this->resolver->resolve($parentId, [$optAId => (int)$valRed['id'], $optBId => (int)$valL['id']]);
        $this->assert($resNone['state'] === 'none', "State must be none when combination does not exist");
        $this->assert($resNone['reason'] === 'no_matching_variation', "Reason must be 'no_matching_variation'");

        echo " OK\n";
    }

    /**
     * AC-T25-02: Duplicate overlapping Any wildcards detected and reported as ambiguous without random selection.
     */
    public function testAC_T25_02_DuplicateOverlappingAnyReported(): void
    {
        echo " - testAC_T25_02_DuplicateOverlappingAnyReported...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'AMBIG-PROD', 'slug' => 'ambig-prod-' . $parentId, 'title' => 'Ambiguous Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $valRed = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'red', 'label' => 'Red']);
        $this->cleanValueIds[] = (int)$valRed['id'];

        // Create 2 variations with IDENTICAL wildcard combination: Color = ANY
        $var1Id = $this->allocator->allocateShell('product_variation');
        $var2Id = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$var1Id, $var2Id]);

        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $var1Id, 'product_id' => $parentId, 'sku' => 'AMBIG-VAR-1', 'regular_price' => '20.000000',
            'position' => 0, 'status' => 'publish', 'combination_key' => hash('sha256', 'ambig1', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $var2Id, 'product_id' => $parentId, 'sku' => 'AMBIG-VAR-2', 'regular_price' => '25.000000',
            'position' => 0, 'status' => 'publish', 'combination_key' => hash('sha256', 'ambig2', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        // Manually insert identical Any records in wpe_variant_values (overlapping Any)
        $this->wpdb->insert("{$prefix}wpe_variant_values", ['variant_id' => $var1Id, 'option_id' => $optId, 'value_id' => null, 'is_any' => 1]);
        $this->wpdb->insert("{$prefix}wpe_variant_values", ['variant_id' => $var2Id, 'option_id' => $optId, 'value_id' => null, 'is_any' => 1]);

        $this->resolver->clearConflictAuditLog();

        // Resolve selection Color = Red
        $resAmbig = $this->resolver->resolve($parentId, [$optId => (int)$valRed['id']]);

        // Assert: Detected as ambiguous, variant is null (not randomly chosen)
        $this->assert($resAmbig['state'] === 'ambiguous', "Must return 'ambiguous' state for duplicate overlapping Any (AC-T25-02)");
        $this->assert($resAmbig['variant'] === null, "Variant must be null when ambiguous");
        $this->assert($resAmbig['reason'] === 'ambiguous_match', "Reason must be 'ambiguous_match'");

        // Assert: Conflict logged in audit log
        $auditLog = $this->resolver->getConflictAuditLog();
        $this->assert(count($auditLog) === 1, "Conflict audit log must record the ambiguity event");
        $this->assert($auditLog[0]['product_id'] === $parentId, "Logged event must match product ID");
        $this->assert(count($auditLog[0]['conflicting_variant_ids']) === 2, "Logged event must reference conflicting variant IDs");

        echo " OK\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException("Assertion failed: " . $message);
        }
    }
}

$test = new T25Test();
$test->runAll();
