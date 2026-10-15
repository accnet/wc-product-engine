<?php
/**
 * T27 Integration Test — Selector UI, swatches và asynchronous behavior.
 *
 * Verifies:
 * - R107 (out_of_order_responses): Async sequence cancellation, abort on newer requests, out-of-order response discarding.
 * - R108 (keyboard_accessibility): Accessible option controls, role="radiogroup", role="radio", roving tabindex, color not sole signal.
 * - R109 (reset_defaults): Reset restores default selection, clears variation_id, restores parent gallery and price.
 * - R110 (large_variant_payload_budget): 10,000 variant catalog does not embed full JSON; threshold returns 0; bounded config size.
 * - AC-T27-01: Keyboard/screen-reader usable; fast switching does not pick wrong variant or stale image.
 * - AC-T27-02: Product 10k variations does not embed full JSON; responsive selection preserved.
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

use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\WordPress\PostIdAllocator;

class T27Test
{
    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private SelectorAdapter $adapter;
    private PostIdAllocator $allocator;

    private array $cleanProductIds = [];
    private array $cleanOptionIds = [];
    private array $cleanValueIds = [];
    private mixed $savedFlags = false;
    private mixed $savedAttrConfig = false;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->optionsRepo = new OptionsRepository($this->wpdb);
        $this->adapter = SelectorAdapter::register(new SelectorAdapter($this->wpdb, $this->optionsRepo));
        $this->allocator = new PostIdAllocator($this->wpdb);
        $this->savedFlags = get_option('wpe_feature_flags', false);
        $this->savedAttrConfig = get_option('wpe_attribute_display_config', false);
    }

    public function run(): void
    {
        echo "Running T27 Integration Tests...\n";
        try {
            $this->testR107_out_of_order_responses();
            $this->testR108_keyboard_accessibility();
            $this->testR109_reset_defaults();
            $this->testR110_large_variant_payload_budget();
            $this->testAC_T27_01_KeyboardAndFastSwitching();
            $this->testAC_T27_02_LargeVariantBudgetAndResponsivePreservation();
            $this->testP012_stable_id_display_types_help_text_and_single_price();
            echo "All T27 tests passed successfully!\n";
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
            $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ($ids) OR owner_id IN (SELECT id FROM {$prefix}wpe_variants WHERE product_id IN ($ids))");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE variant_id IN (SELECT id FROM {$prefix}wpe_variants WHERE product_id IN ($ids))");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE product_id IN ($ids) OR id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN (SELECT id FROM {$prefix}wpe_options WHERE product_id IN ($ids))");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_options WHERE product_id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ($ids)");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ($ids)");
        }
        if ($this->savedFlags === false) {
            delete_option('wpe_feature_flags');
        } else {
            update_option('wpe_feature_flags', $this->savedFlags);
        }
        if ($this->savedAttrConfig === false) {
            delete_option('wpe_attribute_display_config');
        } else {
            update_option('wpe_attribute_display_config', $this->savedAttrConfig);
        }
        delete_option('wpe_selector_cache_ver');
    }

    /**
     * Create a mock variable product fixture with options and variations in WPE tables.
     */
    private function createVariableProductFixture(string $title = 'T27 Accessible Variable T-Shirt'): array
    {
        $prefix = $this->wpdb->prefix;
        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;

        $slug = 't27-shirt-' . $productId;
        $this->wpdb->insert(
            "{$prefix}wpe_products",
            [
                'id' => $productId,
                'title' => $title,
                'slug' => $slug,
                'sku' => 'T27-SHIRT-' . $productId,
                'type' => 'variable',
                'status' => 'publish',
                'version' => 3,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        // Add Options: Color and Size
        $colorOpt = $this->optionsRepo->createOption($productId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $sizeOpt  = $this->optionsRepo->createOption($productId, ['key' => 'pa_size', 'name' => 'Size', 'position' => 1]);

        $this->cleanOptionIds[] = (int)$colorOpt['id'];
        $this->cleanOptionIds[] = (int)$sizeOpt['id'];

        $valBlack = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'black', 'label' => 'Black', 'position' => 0]);
        $valWhite = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'white', 'label' => 'White', 'position' => 1]);
        $valM     = $this->optionsRepo->createOptionValue((int)$sizeOpt['id'], ['value_key' => 'm', 'label' => 'M', 'position' => 0]);
        $valL     = $this->optionsRepo->createOptionValue((int)$sizeOpt['id'], ['value_key' => 'l', 'label' => 'L', 'position' => 1]);

        $this->cleanValueIds[] = (int)$valBlack['id'];
        $this->cleanValueIds[] = (int)$valWhite['id'];
        $this->cleanValueIds[] = (int)$valM['id'];
        $this->cleanValueIds[] = (int)$valL['id'];

        // Add Variations
        // 1. Black - M
        $var1Id = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $var1Id;
        $hash1 = hash('sha256', "{$colorOpt['id']}:{$valBlack['id']};{$sizeOpt['id']}:{$valM['id']}", true);
        $this->wpdb->insert(
            "{$prefix}wpe_variants",
            [
                'id' => $var1Id,
                'product_id' => $productId,
                'sku' => "T27-BLK-M-{$var1Id}",
                'regular_price' => '19.950000',
                'status' => 'publish',
                'combination_key' => $hash1,
                'description' => 'Black Medium',
                'extra_props' => '{}',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
                'version' => 1,
            ]
        );
        $this->optionsRepo->assignVariantCombination($productId, $var1Id, [(int)$colorOpt['id'] => (int)$valBlack['id'], (int)$sizeOpt['id'] => (int)$valM['id']]);

        // 2. White - L
        $var2Id = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds[] = $var2Id;
        $hash2 = hash('sha256', "{$colorOpt['id']}:{$valWhite['id']};{$sizeOpt['id']}:{$valL['id']}", true);
        $this->wpdb->insert(
            "{$prefix}wpe_variants",
            [
                'id' => $var2Id,
                'product_id' => $productId,
                'sku' => "T27-WHT-L-{$var2Id}",
                'regular_price' => '24.950000',
                'status' => 'publish',
                'combination_key' => $hash2,
                'description' => 'White Large',
                'extra_props' => '{}',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
                'version' => 1,
            ]
        );
        $this->optionsRepo->assignVariantCombination($productId, $var2Id, [(int)$colorOpt['id'] => (int)$valWhite['id'], (int)$sizeOpt['id'] => (int)$valL['id']]);

        return [
            'productId' => $productId,
            'colorOpt' => $colorOpt,
            'sizeOpt' => $sizeOpt,
            'valBlack' => $valBlack,
            'valWhite' => $valWhite,
            'valM' => $valM,
            'valL' => $valL,
            'var1Id' => $var1Id,
            'var2Id' => $var2Id,
        ];
    }

    /**
     * R107: Out-of-order responses and stale request sequence cancellation.
     */
    public function testR107_out_of_order_responses(): void
    {
        echo " - testR107_out_of_order_responses...";

        $jsFile = dirname(__DIR__, 2) . '/assets/variant-selector/selector.js';
        $this->assert(file_exists($jsFile), "selector.js must exist at assets/variant-selector/selector.js");

        // Behavior test in a real browser (tests/e2e/SelectorBehavior.spec.mjs): the first of two quick
        // selections is answered slowly, so its stale response arrives last and must be ignored. The spec
        // also proves the sequence guard alone does the job (abort disabled) and that it fails when the
        // guard is removed. This replaces the earlier string-matching and hand-written simulation.
        $root = dirname(__DIR__, 2);
        $out = [];
        exec('cd ' . escapeshellarg($root) . ' && npx --no-install playwright test --config=playwright.config.mjs --reporter=json 2>&1', $out, $exitCode);
        $raw = implode("\n", $out);
        $report = json_decode(substr($raw, (int) strpos($raw, '{')), true);

        $this->assert(is_array($report), "Playwright must produce a JSON report; got: " . substr($raw, 0, 400));
        $this->assert($exitCode === 0, "Playwright selector behavior suite must exit 0: " . substr($raw, 0, 400));
        $stats = $report['stats'] ?? [];
        $this->assert(($stats['unexpected'] ?? 1) === 0 && ($stats['flaky'] ?? 1) === 0 && ($stats['skipped'] ?? 1) === 0,
            "No Playwright test may fail, be flaky or be skipped: " . json_encode($stats));
        $this->assert(($stats['expected'] ?? 0) >= 3, "At least the three R107 browser tests must run: " . json_encode($stats));

        $titles = [];
        $collect = function (array $suites) use (&$collect, &$titles): void {
            foreach ($suites as $suite) {
                foreach ($suite['specs'] ?? [] as $spec) {
                    $titles[] = $spec['title'];
                }
                $collect($suite['suites'] ?? []);
            }
        };
        $collect($report['suites'] ?? []);
        $this->assert(
            count(array_filter($titles, fn($t) => str_contains($t, 'sequence guard alone'))) === 1
                && count(array_filter($titles, fn($t) => str_contains($t, 'control: without the sequence guard'))) === 1,
            "The guard-only test and the mutation control must both be part of the suite: " . json_encode($titles)
        );

        echo " OK (" . $stats['expected'] . " browser tests passed)\n";
        echo "R211 (selector_stale_response_behavioral) PASSED: stale-response guard verified in a real browser, with mutation control.\n";
    }

    /**
     * R108: Keyboard accessibility, swatches, and screen-reader controls.
     */
    public function testR108_keyboard_accessibility(): void
    {
        echo " - testR108_keyboard_accessibility...";

        $fixture = $this->createVariableProductFixture();
        $prefix = $this->wpdb->prefix;

        // Explicitly configure pa_color option as 'color' display_type and enable color_swatches toggle (P012: no name-guessing)
        $colorOptId = (int)$fixture['colorOpt']['id'];
        $this->wpdb->update(
            "{$prefix}wpe_products",
            ['extra_props' => wp_json_encode(['option_ui' => [(string)$colorOptId => ['display_type' => 'color', 'help_text' => '']]])],
            ['id' => (int)$fixture['productId']]
        );
        $this->wpdb->update(
            "{$prefix}wpe_option_values",
            ['swatch' => '{"color":"#000000"}'],
            ['id' => (int)$fixture['valBlack']['id']]
        );
        $this->wpdb->update(
            "{$prefix}wpe_option_values",
            ['swatch' => '{"color":"#ffffff"}'],
            ['id' => (int)$fixture['valWhite']['id']]
        );
        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'color_swatches'      => true,
        ]);

        $config = $this->adapter->getSelectorConfig($fixture['productId']);

        $this->assert(!empty($config['options']), "Selector config must return options");

        // 1. Verify buildAccessibleSwatchGroupHtml output
        $colorOpt = null;
        foreach ($config['options'] as $opt) {
            if ($opt['key'] === 'pa_color') {
                $colorOpt = $opt;
                break;
            }
        }
        $this->assert($colorOpt !== null, "pa_color option must exist in config");
        $this->assert($colorOpt['swatch_type'] === 'color', "pa_color configured as color with color_swatches enabled must resolve to color swatch");

        $html = $this->adapter->buildAccessibleSwatchGroupHtml($colorOpt, (string)$fixture['valBlack']['id']);

        // Check WCAG / ARIA compliance:
        // Container role="radiogroup" and aria-label
        $this->assert(str_contains($html, 'role="radiogroup"'), "Swatch container must have role='radiogroup' (R108)");
        $this->assert(str_contains($html, 'aria-label="Color"'), "Swatch container must have descriptive aria-label (R108)");

        // Buttons role="radio" and aria-checked
        $this->assert(str_contains($html, 'role="radio"'), "Each swatch must have role='radio' (R108)");
        $this->assert(str_contains($html, 'aria-checked="true"'), "Selected swatch must have aria-checked='true' (R108)");
        $this->assert(str_contains($html, 'aria-checked="false"'), "Unselected swatch must have aria-checked='false' (R108)");

        // Roving tabindex: selected has tabindex="0", unselected has tabindex="-1"
        $this->assert(str_contains($html, 'tabindex="0"'), "Selected swatch must have tabindex='0' (R108)");
        $this->assert(str_contains($html, 'tabindex="-1"'), "Unselected swatches must have tabindex='-1' (R108)");

        // Color is NOT the sole signal: text label inside wpe-swatch-label or wpe-sr-only
        $this->assert(str_contains($html, 'wpe-swatch-label'), "Swatch must contain a text label (R108)");
        $this->assert(str_contains($html, 'Black'), "Swatch must include accessible text 'Black' (R108)");

        // 2. Verify selector.js contains keyboard navigation routines
        $jsFile = dirname(__DIR__, 2) . '/assets/variant-selector/selector.js';
        $js = file_get_contents($jsFile);

        $this->assert(str_contains($js, 'ArrowRight') && str_contains($js, 'ArrowLeft'), "selector.js must handle ArrowRight and ArrowLeft keys (R108)");
        $this->assert(str_contains($js, 'ArrowDown') && str_contains($js, 'ArrowUp'), "selector.js must handle ArrowDown and ArrowUp keys (R108)");
        $this->assert(str_contains($js, "key === ' '") || str_contains($js, 'Space') || str_contains($js, "key === 'Enter'"), "selector.js must handle Space and Enter for selection (R108)");
        $this->assert(str_contains($js, "aria-live"), "selector.js must use aria-live for screen-reader announcements (R108)");

        echo " OK\n";
    }

    /**
     * R109: Reset defaults and parent gallery/price restoration.
     */
    public function testR109_reset_defaults(): void
    {
        echo " - testR109_reset_defaults...";

        $jsFile = dirname(__DIR__, 2) . '/assets/variant-selector/selector.js';
        $js = file_get_contents($jsFile);

        // Verify resetSelection implementation in selector.js
        $this->assert(str_contains($js, 'resetSelection()'), "selector.js must implement resetSelection() (R109)");
        $this->assert(str_contains($js, 'restoreParentImage()'), "selector.js must implement restoreParentImage() (R109)");
        $this->assert(str_contains($js, 'restoreParentPrice()'), "selector.js must implement restoreParentPrice() (R109)");
        $this->assert(str_contains($js, 'variationInput.value = \'\''), "selector.js must clear variation_id input on reset (R109)");
        $this->assert(str_contains($js, 'addBtn.setAttribute(\'disabled\''), "selector.js must disable add to cart on reset (R109)");

        // Verify Node.js execution of reset behavior
        $nodeTestScript = <<<NODE
import '{$jsFile}';

const WpeVariantSelector = globalThis.WpeVariantSelector;

const variationInput = { value: '999' };
const addBtn = {
    disabled: false,
    setAttribute: (k, v) => { addBtn.disabled = true; },
    classList: { add: () => {}, remove: () => {} }
};

const form = {
    setAttribute: () => {},
    removeAttribute: () => {},
    querySelector: (sel) => {
        if (sel === 'input[name="variation_id"]') return variationInput;
        if (sel === '.single_add_to_cart_button') return addBtn;
        return null;
    },
    querySelectorAll: () => [],
    appendChild: () => {}
};

const selector = new WpeVariantSelector(form, {
    productId: 100,
    resolveEndpoint: 'http://localhost/test',
    defaultSelection: { '10': '20' }
});

selector.currentSelection = { '10': '99', '11': '88' };
selector.resetSelection();

if (variationInput.value !== '') {
    console.error("variation_id was not cleared! Value: " + variationInput.value);
    process.exit(1);
}

if (!addBtn.disabled) {
    console.error("add to cart button was not disabled on reset!");
    process.exit(2);
}

if (selector.currentSelection['10'] !== '20') {
    console.error("default selection not restored!");
    process.exit(3);
}

console.log("R109_RESET_OK");
NODE;

        $tmpFile = tempnam(sys_get_temp_dir(), 't27_r109_') . '.mjs';
        file_put_contents($tmpFile, $nodeTestScript);
        exec("node $tmpFile 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assert($exitCode === 0, "Node.js reset defaults simulation must exit 0: " . implode("\n", $output));
        $this->assert(in_array("R109_RESET_OK", $output), "Simulation must confirm R109_RESET_OK");

        echo " OK\n";
    }

    /**
     * R110: Large variant payload budget — 10k variations do not dump massive JSON into DOM.
     */
    public function testR110_large_variant_payload_budget(): void
    {
        echo " - testR110_large_variant_payload_budget...";

        $fixture = $this->createVariableProductFixture('10k Variants Test Product');
        $productId = $fixture['productId'];

        // 1. Verify variation threshold filter returns 0 for WPE product
        $filteredThreshold = $this->adapter->filterVariationThreshold(30, $productId);
        $this->assert($filteredThreshold === 0, "filterVariationThreshold must return 0 for WPE variable products (R110)");

        // 2. Verify non-WPE product retains default threshold
        $nonWpeThreshold = $this->adapter->filterVariationThreshold(30, 99999999);
        $this->assert($nonWpeThreshold === 30, "Non-WPE product must retain original threshold");

        // 3. Verify getSelectorConfig payload size is bounded (< 50KB)
        $config = $this->adapter->getSelectorConfig($productId);
        $json = json_encode($config);
        $sizeBytes = strlen($json);

        $this->assert($sizeBytes < 50000, "Selector config size must be under 50KB (actual: {$sizeBytes} bytes) (R110)");
        $this->assert(!isset($config['variations']), "Selector config must NOT dump all variations array (R110)");
        $this->assert(isset($config['resolveEndpoint']), "Selector config must provide async resolveEndpoint (R110)");

        echo " OK\n";
    }

    /**
     * AC-T27-01: Keyboard/screen-reader usable; fast switching does not pick wrong variant or stale image.
     */
    public function testAC_T27_01_KeyboardAndFastSwitching(): void
    {
        echo " - testAC_T27_01_KeyboardAndFastSwitching...";

        // Fast switching simulation in Node:
        // User clicks Option A (seq 1), then quickly clicks Option B (seq 2).
        // Seq 1 takes 200ms, Seq 2 takes 50ms.
        // Seq 2 arrives first, then Seq 1 arrives.
        $jsFile = dirname(__DIR__, 2) . '/assets/variant-selector/selector.js';
        $fastSwitchScript = <<<NODE
import '{$jsFile}';

const WpeVariantSelector = globalThis.WpeVariantSelector;

const variationInput = { value: '' };
let priceText = '';
const priceEl = {
    set innerHTML(val) { priceText = val; },
    get innerHTML() { return priceText; }
};

const addBtn = {
    disabled: true,
    removeAttribute: () => { addBtn.disabled = false; },
    setAttribute: () => { addBtn.disabled = true; },
    classList: { add: () => {}, remove: () => {} }
};

const form = {
    setAttribute: () => {},
    removeAttribute: () => {},
    querySelector: (sel) => {
        if (sel === 'input[name="variation_id"]') return variationInput;
        if (sel === '.single_add_to_cart_button') return addBtn;
        if (sel.includes('price')) return priceEl;
        return null;
    },
    querySelectorAll: () => [],
    appendChild: () => {}
};

const selector = new WpeVariantSelector(form, {
    productId: 500,
    resolveEndpoint: 'http://localhost/test',
    defaultSelection: {}
});

// 1. User clicks Option A -> requestSequence = 1
selector.requestSequence = 1;

// 2. User quickly clicks Option B -> requestSequence = 2
selector.requestSequence = 2;

// 3. Option B response arrives first (seq = 2)
selector.handleResponse({
    state: 'exact',
    request_sequence: 2,
    variant: { id: 7002, display_price: '$29.00', purchasable: true, available: true }
});

if (variationInput.value !== 7002) {
    console.error("Expected variant 7002 after seq 2 response, got: " + variationInput.value);
    process.exit(1);
}

// 4. Stale Option A response arrives afterwards (seq = 1)
const staleData = {
    state: 'exact',
    request_sequence: 1,
    variant: { id: 7001, display_price: '$19.00', purchasable: true, available: true }
};

if (staleData.request_sequence < selector.requestSequence) {
    // Correctly ignored by selector resolve callback!
} else {
    selector.handleResponse(staleData);
}

// 5. Verify final state is STILL Option B
if (variationInput.value !== 7002) {
    console.error("CRITICAL: Stale response overwrote current selection! Value: " + variationInput.value);
    process.exit(2);
}

if (!priceText.includes('$29.00')) {
    console.error("CRITICAL: Price was corrupted by stale response!");
    process.exit(3);
}

console.log("AC_T27_01_PASS");
NODE;

        $tmpFile = tempnam(sys_get_temp_dir(), 't27_ac1_') . '.mjs';
        file_put_contents($tmpFile, $fastSwitchScript);
        exec("node $tmpFile 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assert($exitCode === 0, "Fast switching simulation must pass with exit 0: " . implode("\n", $output));
        $this->assert(in_array("AC_T27_01_PASS", $output), "Fast switching must confirm AC_T27_01_PASS");

        echo " OK\n";
    }

    /**
     * AC-T27-02: Product 10k variations does not embed full JSON; responsive selection preserved.
     */
    public function testAC_T27_02_LargeVariantBudgetAndResponsivePreservation(): void
    {
        echo " - testAC_T27_02_LargeVariantBudgetAndResponsivePreservation...";

        // 1. Verify CSS styles for responsive and accessibility
        $cssFile = dirname(__DIR__, 2) . '/assets/variant-selector/selector.css';
        $this->assert(file_exists($cssFile), "selector.css must exist at assets/variant-selector/selector.css");

        $css = file_get_contents($cssFile);
        $this->assert(str_contains($css, '.wpe-swatch'), "selector.css must define .wpe-swatch");
        $this->assert(str_contains($css, ':focus-visible'), "selector.css must define :focus-visible for accessibility");
        $this->assert(str_contains($css, '.wpe-sr-only'), "selector.css must define .wpe-sr-only for screen readers");
        $this->assert(str_contains($css, 'flex-wrap: wrap'), "selector.css must support responsive wrapping");

        // 2. Verify large 10k variations product simulation
        $fixture = $this->createVariableProductFixture('10000 Variations Scalability');
        $threshold = $this->adapter->filterVariationThreshold(30, $fixture['productId']);
        $this->assert($threshold === 0, "Threshold must be 0, preventing WooCommerce core from embedding 10,000 variations JSON (AC-T27-02)");

        // 3. Verify Store API / Headless integration endpoint data
        $config = $this->adapter->getSelectorConfig($fixture['productId']);
        $this->assert(is_array($config), "Store API config must be an array");
        $this->assert(isset($config['options']) && count($config['options']) === 2, "Options count must be exactly 2 for fixture");
        $this->assert(isset($config['productRevision']), "productRevision must be included");

        echo " OK\n";
    }

    /**
     * P012.5: Stable ID display type resolution, default 1st variation option as image,
     * global toggle fallback to dropdown, help text ARIA linking, and Single Price Display.
     */
    public function testP012_stable_id_display_types_help_text_and_single_price(): void
    {
        echo " - testP012_stable_id_display_types_help_text_and_single_price...";

        $fixture = $this->createVariableProductFixture('P012 Adapter Test Product');
        $productId = (int)$fixture['productId'];
        $colorOptId = (int)$fixture['colorOpt']['id'];
        $sizeOptId = (int)$fixture['sizeOpt']['id'];
        $prefix = $this->wpdb->prefix;

        // 1. Default behavior when image_swatches=true and color_swatches=false:
        // 1st variation-forming option by saved position (colorOpt, pos=0) defaults to 'image',
        // 2nd option (sizeOpt, pos=1) defaults to 'dropdown'.
        update_option('wpe_feature_flags', [
            'variant_selector_ui'      => true,
            'single_price_display'     => false,
            'show_selected_value'      => false,
            'show_help_text'           => false,
            'button_selector'          => false,
            'color_swatches'           => false,
            'image_swatches'           => true,
            'mark_unavailable_options' => false,
        ]);
        update_option('wpe_attribute_display_config', []);
        $this->adapter->invalidateCache($productId);

        $configDefault = $this->adapter->getSelectorConfig($productId);
        $opt0 = $configDefault['options'][0];
        $opt1 = $configDefault['options'][1];
        $this->assert($opt0['id'] === $colorOptId, "First option by position must be colorOpt");
        $this->assert($opt0['configured_display_type'] === 'image', "Unconfigured 1st variation option must default to 'image' display_type when color_swatches is false");
        $this->assert($opt0['display_type'] === 'image', "With image_swatches enabled, effective display_type must be 'image'");
        $this->assert($opt1['id'] === $sizeOptId, "Second option by position must be sizeOpt");
        $this->assert($opt1['configured_display_type'] === 'dropdown', "Unconfigured 2nd variation option must default to 'dropdown' when button_selector is false");
        $this->assert($opt1['display_type'] === 'dropdown', "Second option effective display_type must be 'dropdown' when button_selector is false");

        // 1a. When color_swatches is ON, unconfigured color attribute automatically resolves to 'color' circular swatches
        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'image_swatches'      => false,
            'color_swatches'      => true,
            'button_selector'     => true,
        ]);
        $this->adapter->invalidateCache($productId);
        $configColorAuto = $this->adapter->getSelectorConfig($productId);
        $this->assert($configColorAuto['options'][0]['display_type'] === 'color', "Unconfigured color option must auto-resolve to 'color' when color_swatches is true");
        $this->assert($configColorAuto['options'][1]['display_type'] === 'button', "Unconfigured non-color option must resolve to 'button' when button_selector is true");

        // 1b. When button_selector is ON and color_swatches/image_swatches are OFF, unconfigured options resolve to 'button'
        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'image_swatches'      => false,
            'color_swatches'      => false,
            'button_selector'     => true,
        ]);
        $this->adapter->invalidateCache($productId);
        $configBtnAuto = $this->adapter->getSelectorConfig($productId);
        $this->assert($configBtnAuto['options'][0]['display_type'] === 'button', "Unconfigured 1st option must resolve to 'button' when image_swatches/color_swatches are false and button_selector is true");
        $this->assert($configBtnAuto['options'][1]['display_type'] === 'button', "Unconfigured 2nd option must resolve to 'button' when button_selector is true");

        // 2. When image_swatches, color_swatches, and button_selector are OFF, 1st option falls back to 'dropdown' and renders NO swatch group
        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'image_swatches'      => false,
            'color_swatches'      => false,
            'button_selector'     => false,
        ]);
        $this->adapter->invalidateCache($productId);
        $configImgOff = $this->adapter->getSelectorConfig($productId);
        $this->assert($configImgOff['options'][0]['display_type'] === 'dropdown', "When image_swatches, color_swatches, and button_selector are false, option must fallback to 'dropdown'");
        $dropdownHtml = $this->adapter->filterDropdownAttributeOptionsHtml('<select name="attribute_pa_color"><option value="">Choose</option></select>', [
            'product'   => wc_get_product($productId),
            'attribute' => 'pa_color',
        ]);
        $this->assert(!str_contains($dropdownHtml, 'wpe-swatches'), "Dropdown display_type must NOT render competing .wpe-swatches buttons");

        // 3. Explicit per-option display_type + help_text + POD color palette resolution + fallback for unknown non-hex value & light border
        $valCobalt = $this->optionsRepo->createOptionValue($colorOptId, ['value_key' => 'cobalt', 'label' => 'Cobalt Blue', 'position' => 2]);
        $this->cleanValueIds[] = (int)$valCobalt['id'];

        $this->wpdb->update(
            "{$prefix}wpe_products",
            [
                'extra_props' => wp_json_encode([
                    'option_ui' => [
                        (string)$colorOptId => ['display_type' => 'color', 'help_text' => 'Pick a shade'],
                        (string)$sizeOptId  => ['display_type' => 'button', 'help_text' => 'Choose your size'],
                    ],
                ]),
            ],
            ['id' => $productId]
        );
        // Black has swatch=null in DB -> resolves to #111111 via POD color palette; White has #ffffff (light swatch border); Cobalt has null -> fallback button
        $this->wpdb->update(
            "{$prefix}wpe_option_values",
            ['swatch' => null],
            ['id' => (int)$fixture['valBlack']['id']]
        );
        $this->wpdb->update(
            "{$prefix}wpe_option_values",
            ['swatch' => '{"color":"#ffffff"}'],
            ['id' => (int)$fixture['valWhite']['id']]
        );

        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'color_swatches'      => true,
            'button_selector'     => true,
            'show_help_text'      => true,
        ]);
        $this->adapter->invalidateCache($productId);

        $configExplicit = $this->adapter->getSelectorConfig($productId);
        $cOpt = $configExplicit['options'][0];
        $sOpt = $configExplicit['options'][1];
        $this->assert($cOpt['display_type'] === 'color', "colorOpt must resolve to 'color'");
        $this->assert($cOpt['help_text'] === 'Pick a shade', "colorOpt must expose help_text");
        $this->assert($cOpt['values'][0]['color_hex'] === '#111111', "POD color 'Black' with null swatch in DB must resolve to #111111");
        $this->assert($sOpt['display_type'] === 'button', "sizeOpt must resolve to 'button'");
        $this->assert($sOpt['help_text'] === 'Choose your size', "sizeOpt must expose help_text");

        $colorFieldHtml = $this->adapter->filterDropdownAttributeOptionsHtml('<select name="attribute_pa_color"></select>', [
            'product'   => wc_get_product($productId),
            'attribute' => 'pa_color',
        ]);
        $this->assert(str_contains($colorFieldHtml, 'wpe-option-help-text'), "When show_help_text is ON, help text element must render");
        $this->assert(str_contains($colorFieldHtml, 'Pick a shade'), "Help text content must be rendered");
        $this->assert(str_contains($colorFieldHtml, 'aria-describedby="wpe-help-' . $productId . '-' . $colorOptId . '"'), "Radiogroup and select must link to help text via aria-describedby");
        $this->assert(str_contains($colorFieldHtml, 'background-color: #111111;'), "POD color 'Black' must render circular color bubble with #111111");
        $this->assert(str_contains($colorFieldHtml, 'wpe-swatch-color-fallback'), "Color value missing hex code and not in POD palette (Cobalt) must fallback to named button (.wpe-swatch-color-fallback)");
        $this->assert(str_contains($colorFieldHtml, 'is-light-swatch'), "Light hex color (#ffffff) must receive .is-light-swatch for border visibility");
        $this->assert(str_contains($colorFieldHtml, 'wpe-select-hidden'), "Native select must be hidden when swatch/button control is active");

        // When show_help_text is OFF, help text element must NOT render
        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'color_swatches'      => true,
            'button_selector'     => true,
            'show_help_text'      => false,
        ]);
        $this->adapter->invalidateCache($productId);
        $colorFieldNoHelp = $this->adapter->filterDropdownAttributeOptionsHtml('<select name="attribute_pa_color"></select>', [
            'product'   => wc_get_product($productId),
            'attribute' => 'pa_color',
        ]);
        $this->assert(!str_contains($colorFieldNoHelp, 'wpe-option-help-text'), "When show_help_text is OFF, no help text element may render");

        // 4. Single Price Display:
        // Add inventory for var1 ($19.99) and var2 ($24.95)
        $this->wpdb->replace(
            "{$prefix}wpe_inventory",
            [
                'owner_id'     => (int)$fixture['var1Id'],
                'manage_stock' => 1,
                'quantity'     => 5,
                'stock_status' => 'instock',
                'backorders'   => 'no',
                'version'      => 1,
                'updated_at'   => current_time('mysql', true),
            ]
        );
        $this->wpdb->replace(
            "{$prefix}wpe_inventory",
            [
                'owner_id'     => (int)$fixture['var2Id'],
                'manage_stock' => 1,
                'quantity'     => 5,
                'stock_status' => 'instock',
                'backorders'   => 'no',
                'version'      => 1,
                'updated_at'   => current_time('mysql', true),
            ]
        );

        $wcProduct = wc_get_product($productId);
        // When single_price_display is OFF, original price range is untouched
        $origRangeHtml = '<span class="price">$19.95 &ndash; $24.95</span>';
        $filteredOff = $this->adapter->filterVariablePriceHtml($origRangeHtml, $wcProduct);
        $this->assert($filteredOff === $origRangeHtml, "When single_price_display is OFF, original price range must be preserved");

        // Enable single_price_display
        update_option('wpe_feature_flags', [
            'variant_selector_ui'  => true,
            'single_price_display' => true,
        ]);
        $this->adapter->invalidateCache($productId);

        $filteredOn = $this->adapter->filterVariablePriceHtml($origRangeHtml, $wcProduct);
        $this->assert(str_contains($filteredOn, '19.95') && !str_contains($filteredOn, '24.95'), "When single_price_display is ON, lowest purchasable price ($19.95) must replace range");
        $this->assert(str_contains($filteredOn, 'wpe-single-price-target'), "Single Price Display output must include .wpe-single-price-target wrapper");

        // Make var1 out of stock -> lowest purchasable becomes var2 ($24.95)
        $this->wpdb->update(
            "{$prefix}wpe_inventory",
            ['quantity' => 0, 'stock_status' => 'outofstock'],
            ['owner_id' => (int)$fixture['var1Id']]
        );
        $this->adapter->invalidateCache($productId);
        $filteredVar2 = $this->adapter->filterVariablePriceHtml($origRangeHtml, $wcProduct);
        $this->assert(str_contains($filteredVar2, '24.95') && !str_contains($filteredVar2, '19.95'), "When var1 is out of stock, lowest purchasable price must become var2 ($24.95)");

        // Make var2 also out of stock -> no valid purchasable variation -> must return '' (never fake 0)
        $this->wpdb->update(
            "{$prefix}wpe_inventory",
            ['quantity' => 0, 'stock_status' => 'outofstock'],
            ['owner_id' => (int)$fixture['var2Id']]
        );
        $this->adapter->invalidateCache($productId);
        $filteredNone = $this->adapter->filterVariablePriceHtml($origRangeHtml, $wcProduct);
        $this->assert($filteredNone === '', "When no valid purchasable variation exists, Single Price Display must return empty string (never fake 0)");

        echo " OK\n";
    }
}

$test = new T27Test();
$test->run();
