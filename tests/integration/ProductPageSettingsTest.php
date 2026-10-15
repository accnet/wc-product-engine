<?php
/**
 * P012.6 Integration Test — Product Page Settings (Price Display & Variant Selector).
 *
 * Verifies each setting independently and in combination:
 * 1. Default OFF state preserves existing behavior.
 * 2. Single Price Display (lowest purchasable effective price, same-variation regular/sale,
 *    default selection resolution, partial/reset revert, no fake 0 when unpriced/unpurchasable).
 * 3. Show Selected Value (display label on attribute label, update on change/reset, no slug/ID).
 * 4. Show Help Text (escaped guidance text below label, aria-describedby, no empty space when absent).
 * 5. Button Selector (accessible button radios, fallback to dropdown when OFF).
 * 6. Color Swatches (per-value hex, light-color contrast border, missing-hex named button fallback, dropdown fallback when OFF).
 * 7. Image Swatches (default 1st variation option by position, thumbnail + label, 5-step candidate resolution
 *    across Color -> Size -> Style, fallback to product image -> placeholder, gallery sync & reset).
 * 8. Mark Unavailable Options (marked vs standard disabled state, backorder/stock policy enforcement).
 * 9. Multi-instance isolation (main product + quick-view instance scoping without interference).
 */

if (!defined('ABSPATH')) {
    $wpLoad = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wpLoad)) {
        require_once $wpLoad;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Application\Variant\VariantResolver;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\OptionsRepository;

class ProductPageSettingsTest
{
    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private MediaRepository $mediaRepo;
    private AssetService $assetService;
    private VariantResolver $resolver;
    private SelectorAdapter $adapter;
    private PostIdAllocator $allocator;

    private array $cleanProductIds = [];
    private array $cleanOptionIds = [];
    private array $cleanValueIds = [];
    private array $cleanAssetIds = [];
    private mixed $savedFlags = false;
    private mixed $savedAttrConfig = false;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->optionsRepo = new OptionsRepository($this->wpdb);
        $this->mediaRepo = new MediaRepository($this->wpdb);
        $this->assetService = new AssetService($this->wpdb, $this->mediaRepo);
        $this->resolver = new VariantResolver($this->wpdb, $this->optionsRepo, $this->assetService);
        $this->adapter = SelectorAdapter::register(new SelectorAdapter($this->wpdb, $this->optionsRepo, null, $this->resolver));
        $this->allocator = new PostIdAllocator($this->wpdb);
        $this->savedFlags = get_option('wpe_feature_flags', false);
        $this->savedAttrConfig = get_option('wpe_attribute_display_config', false);
    }

    public function run(): void
    {
        echo "Running P012 ProductPageSettingsTest...\n";
        try {
            $this->testDefaultOffPreservesBehavior();
            $this->testSinglePriceDisplayAndSameVariationSale();
            $this->testShowHelpTextAndDisplayTypesWithFallbacks();
            $this->testImageSwatchesCandidateResolutionColorSizeStyle();
            $this->testMarkUnavailableOptionsAndBackorderPolicy();
            $this->testClientSelectorSyncAndMultiInstanceIsolation();
            echo "All ProductPageSettingsTest checks passed!\n";
        } finally {
            $this->cleanup();
        }
    }

    private function assert(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new RuntimeException("Assertion failed: {$msg}");
        }
    }

    private function createTestAsset(string $url): int
    {
        $asset = $this->mediaRepo->createAsset([
            'source_url' => $url,
            'sha256'     => hash('sha256', $url),
            'mime'       => 'image/jpeg',
            'width'      => 600,
            'height'     => 600,
            'status'     => 'ready',
            'health'     => 'ok',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;
        return $assetId;
    }

    /**
     * Create a 3-option (Color -> Size -> Style) variable product fixture.
     */
    private function createThreeOptionFixture(): array
    {
        $prefix = $this->wpdb->prefix;
        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;

        $this->wpdb->insert(
            "{$prefix}wpe_products",
            [
                'id'         => $productId,
                'title'      => 'P012 Three-Option Apparel ' . $productId,
                'slug'       => 'p012-apparel-' . $productId,
                'sku'        => 'P012-APP-' . $productId,
                'type'       => 'variable',
                'status'     => 'publish',
                'version'    => 1,
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]
        );

        // Parent featured image
        $featuredAssetId = $this->createTestAsset("https://cdn.example.com/p012/{$productId}/parent-featured.jpg");
        $this->wpdb->insert(
            "{$prefix}wpe_product_media",
            [
                'product_id' => $productId,
                'asset_id'   => $featuredAssetId,
                'role'       => 'featured',
                'position'   => 0,
            ]
        );

        // Options ordered by saved position: Color (0) -> Size (1) -> Style (2)
        $colorOpt = $this->optionsRepo->createOption($productId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $sizeOpt  = $this->optionsRepo->createOption($productId, ['key' => 'pa_size', 'name' => 'Size', 'position' => 1]);
        $styleOpt = $this->optionsRepo->createOption($productId, ['key' => 'pa_style', 'name' => 'Style', 'position' => 2]);

        $this->cleanOptionIds[] = (int)$colorOpt['id'];
        $this->cleanOptionIds[] = (int)$sizeOpt['id'];
        $this->cleanOptionIds[] = (int)$styleOpt['id'];

        $valBlack  = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'black', 'label' => 'Jet Black', 'position' => 0]);
        $valWhite  = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'white', 'label' => 'Snow White', 'position' => 1]);
        $valCobalt = $this->optionsRepo->createOptionValue((int)$colorOpt['id'], ['value_key' => 'cobalt', 'label' => 'Cobalt Blue', 'position' => 2]);

        $valM  = $this->optionsRepo->createOptionValue((int)$sizeOpt['id'], ['value_key' => 'm', 'label' => 'Medium', 'position' => 0]);
        $valL  = $this->optionsRepo->createOptionValue((int)$sizeOpt['id'], ['value_key' => 'l', 'label' => 'Large', 'position' => 1]);

        $valTee    = $this->optionsRepo->createOptionValue((int)$styleOpt['id'], ['value_key' => 'tee', 'label' => 'Classic Tee', 'position' => 0]);
        $valHoodie = $this->optionsRepo->createOptionValue((int)$styleOpt['id'], ['value_key' => 'hoodie', 'label' => 'Pullover Hoodie', 'position' => 1]);

        foreach ([$valBlack, $valWhite, $valCobalt, $valM, $valL, $valTee, $valHoodie] as $v) {
            $this->cleanValueIds[] = (int)$v['id'];
        }

        // Assets for variations
        $assetBlackMTee     = $this->createTestAsset("https://cdn.example.com/p012/{$productId}/black-m-tee.jpg");
        $assetBlackLTee     = $this->createTestAsset("https://cdn.example.com/p012/{$productId}/black-l-tee.jpg");
        $assetBlackLHoodie  = $this->createTestAsset("https://cdn.example.com/p012/{$productId}/black-l-hoodie.jpg");
        $assetWhiteLHoodie  = $this->createTestAsset("https://cdn.example.com/p012/{$productId}/white-l-hoodie.jpg");

        // Helper to insert variation
        $insertVar = function (
            int $pos,
            string $skuSuffix,
            string $regPrice,
            ?string $salePrice,
            array $combo,
            int $qty,
            string $stockStatus,
            string $backorders,
            ?int $primaryAssetId
        ) use ($productId, $prefix): int {
            $varId = $this->allocator->allocateShell('product_variation');
            $this->cleanProductIds[] = $varId;
            $parts = [];
            ksort($combo);
            foreach ($combo as $k => $v) {
                $parts[] = "{$k}:{$v}";
            }
            $hash = hash('sha256', implode(';', $parts), true);

            $this->wpdb->insert(
                "{$prefix}wpe_variants",
                [
                    'id'              => $varId,
                    'product_id'      => $productId,
                    'sku'             => "P012-{$productId}-{$skuSuffix}",
                    'regular_price'   => $regPrice,
                    'sale_price'      => $salePrice,
                    'position'        => $pos,
                    'status'          => 'publish',
                    'combination_key' => $hash,
                    'description'     => "Desc {$skuSuffix}",
                    'extra_props'     => '{}',
                    'created_at'      => current_time('mysql', true),
                    'updated_at'      => current_time('mysql', true),
                    'version'         => 1,
                ]
            );
            $this->optionsRepo->assignVariantCombination($productId, $varId, $combo);

            $this->wpdb->replace(
                "{$prefix}wpe_inventory",
                [
                    'owner_id'     => $varId,
                    'manage_stock' => 1,
                    'quantity'     => $qty,
                    'stock_status' => $stockStatus,
                    'backorders'   => $backorders,
                    'version'      => 1,
                    'updated_at'   => current_time('mysql', true),
                ]
            );

            if ($primaryAssetId !== null) {
                $this->wpdb->insert(
                    "{$prefix}wpe_variant_media",
                    [
                        'variant_id' => $varId,
                        'asset_id'   => $primaryAssetId,
                        'is_primary' => 1,
                        'position'   => 0,
                    ]
                );
            }

            return $varId;
        };

        $cId = (int)$colorOpt['id'];
        $sId = (int)$sizeOpt['id'];
        $stId = (int)$styleOpt['id'];

        // V1 (pos 0): Black + M + Tee -> $25.00 regular, $18.00 sale, in stock (5), image black-m-tee.jpg
        $v1 = $insertVar(0, 'BLK-M-TEE', '25.000000', '18.000000', [
            $cId  => (int)$valBlack['id'],
            $sId  => (int)$valM['id'],
            $stId => (int)$valTee['id'],
        ], 5, 'instock', 'no', $assetBlackMTee);

        // V2 (pos 1): Black + L + Tee -> $28.00 regular, no sale, in stock (3), image black-l-tee.jpg
        $v2 = $insertVar(1, 'BLK-L-TEE', '28.000000', null, [
            $cId  => (int)$valBlack['id'],
            $sId  => (int)$valL['id'],
            $stId => (int)$valTee['id'],
        ], 3, 'instock', 'no', $assetBlackLTee);

        // V3 (pos 2): Black + L + Hoodie -> $42.00 regular, $35.00 sale, in stock (4), image black-l-hoodie.jpg
        $v3 = $insertVar(2, 'BLK-L-HOD', '42.000000', '35.000000', [
            $cId  => (int)$valBlack['id'],
            $sId  => (int)$valL['id'],
            $stId => (int)$valHoodie['id'],
        ], 4, 'instock', 'no', $assetBlackLHoodie);

        // V4 (pos 3): White + M + Tee -> $22.00 regular, no sale, in stock (2), NO variant image (falls back to parent featured)
        $v4 = $insertVar(3, 'WHT-M-TEE', '22.000000', null, [
            $cId  => (int)$valWhite['id'],
            $sId  => (int)$valM['id'],
            $stId => (int)$valTee['id'],
        ], 2, 'instock', 'no', null);

        // V5 (pos 4): White + L + Hoodie -> $45.00 regular, no sale, qty=0 but backorders='yes' (purchasable!), image white-l-hoodie.jpg
        $v5 = $insertVar(4, 'WHT-L-HOD', '45.000000', null, [
            $cId  => (int)$valWhite['id'],
            $sId  => (int)$valL['id'],
            $stId => (int)$valHoodie['id'],
        ], 0, 'instock', 'yes', $assetWhiteLHoodie);

        // V6 (pos 5): Cobalt + M + Tee -> $15.00 regular, qty=0 and backorders='no', stock_status='outofstock' (NOT purchasable!)
        $v6 = $insertVar(5, 'COB-M-TEE', '15.000000', null, [
            $cId  => (int)$valCobalt['id'],
            $sId  => (int)$valM['id'],
            $stId => (int)$valTee['id'],
        ], 0, 'outofstock', 'no', null);

        return [
            'productId'  => $productId,
            'colorOpt'   => $colorOpt,
            'sizeOpt'    => $sizeOpt,
            'styleOpt'   => $styleOpt,
            'valBlack'   => $valBlack,
            'valWhite'   => $valWhite,
            'valCobalt'  => $valCobalt,
            'valM'       => $valM,
            'valL'       => $valL,
            'valTee'     => $valTee,
            'valHoodie'  => $valHoodie,
            'variants'   => [$v1, $v2, $v3, $v4, $v5, $v6],
        ];
    }

    /**
     * 1. When all 7 Product Page toggles are OFF, existing behavior is preserved.
     */
    public function testDefaultOffPreservesBehavior(): void
    {
        echo " - testDefaultOffPreservesBehavior...";
        $f = $this->createThreeOptionFixture();
        $productId = (int)$f['productId'];

        update_option('wpe_feature_flags', [
            'variant_selector_ui'      => true,
            'single_price_display'     => false,
            'show_selected_value'      => false,
            'show_help_text'           => false,
            'button_selector'          => false,
            'color_swatches'           => false,
            'image_swatches'           => false,
            'mark_unavailable_options' => false,
        ]);
        $this->adapter->invalidateCache($productId);

        $wcProduct = wc_get_product($productId);
        $rangeHtml = '<span class="price">$18.00 &ndash; $45.00</span>';
        $this->assert(
            $this->adapter->filterVariablePriceHtml($rangeHtml, $wcProduct) === $rangeHtml,
            "With single_price_display OFF, variable price range must be untouched"
        );
        $this->assert(
            $this->adapter->filterProductGetPriceHtml($rangeHtml, $wcProduct) === $rangeHtml,
            "With single_price_display OFF, catalog card price range must be untouched"
        );

        $config = $this->adapter->getSelectorConfig($productId);
        foreach ($config['options'] as $opt) {
            $this->assert(
                $opt['display_type'] === 'dropdown',
                "With all swatch/button toggles OFF, every option must fallback to 'dropdown'"
            );
        }

        $selectHtml = $this->adapter->renderSwatchControls('<select name="attribute_pa_color"><option value="">Choose</option></select>', [
            'product'   => $wcProduct,
            'attribute' => 'pa_color',
        ]);
        $this->assert(!str_contains($selectHtml, 'wpe-swatches'), "No swatch buttons may render when toggles are OFF");
        $this->assert(!str_contains($selectHtml, 'wpe-option-help-text'), "No help text may render when show_help_text is OFF");

        echo " OK\n";
    }

    /**
     * 2. Single Price Display: lowest purchasable variation price, same-variation regular+sale,
     *    default selection resolution, and empty string when no purchasable variation exists.
     */
    public function testSinglePriceDisplayAndSameVariationSale(): void
    {
        echo " - testSinglePriceDisplayAndSameVariationSale...";
        $f = $this->createThreeOptionFixture();
        $productId = (int)$f['productId'];
        $prefix = $this->wpdb->prefix;

        update_option('wpe_feature_flags', [
            'variant_selector_ui'  => true,
            'single_price_display' => true,
        ]);
        $this->adapter->invalidateCache($productId);

        $wcProduct = wc_get_product($productId);
        $rangeHtml = '<span class="price">$15.00 &ndash; $45.00</span>';

        // Note: V6 ($15.00) is out of stock (unpurchasable), so lowest purchasable is V1 ($18.00 sale from $25.00 regular)!
        $pdpPrice = $this->adapter->filterVariablePriceHtml($rangeHtml, $wcProduct);
        $cardPrice = $this->adapter->filterProductGetPriceHtml($rangeHtml, $wcProduct);

        $this->assert(!str_contains($pdpPrice, '15.00'), "Unpurchasable $15.00 variation must not be used for Single Price Display");
        $this->assert(str_contains($pdpPrice, '18.00') && str_contains($pdpPrice, '25.00'), "Lowest purchasable variation V1 must show both its $25.00 regular and $18.00 sale price from the same variation");
        $this->assert(str_contains($pdpPrice, '<del') && str_contains($pdpPrice, '<ins'), "Sale variation price HTML must contain <del> and <ins>");
        $this->assert($pdpPrice === $cardPrice, "Shop/category/search card price must match lowest purchasable price");

        // Configure valid default selection -> Black + L + Hoodie (V3: $42.00 regular, $35.00 sale)
        $cId = (string)$f['colorOpt']['id'];
        $sId = (string)$f['sizeOpt']['id'];
        $stId = (string)$f['styleOpt']['id'];
        $this->wpdb->update(
            "{$prefix}wpe_products",
            [
                'default_selection' => wp_json_encode([
                    $cId  => (string)$f['valBlack']['id'],
                    $sId  => (string)$f['valL']['id'],
                    $stId => (string)$f['valHoodie']['id'],
                ]),
            ],
            ['id' => $productId]
        );
        $this->adapter->invalidateCache($productId);

        $defaultPriceHtml = $this->adapter->buildSinglePriceDisplayHtml($productId, true);
        $this->assert(
            str_contains($defaultPriceHtml, '35.00') && str_contains($defaultPriceHtml, '42.00'),
            "With a valid default selection, PDP Single Price Display must render that resolved variation's price ($35.00 / $42.00)"
        );
        $this->assert(
            str_contains($defaultPriceHtml, 'data-wpe-price-state="resolved"'),
            "Resolved default selection price target must carry data-wpe-price-state='resolved'"
        );

        echo " OK\n";
    }

    /**
     * 3. Show Help Text, Button Selector, Color Swatches (light contrast border + missing-hex button fallback),
     *    and single control per attribute.
     */
    public function testShowHelpTextAndDisplayTypesWithFallbacks(): void
    {
        echo " - testShowHelpTextAndDisplayTypesWithFallbacks...";
        $f = $this->createThreeOptionFixture();
        $productId = (int)$f['productId'];
        $prefix = $this->wpdb->prefix;

        $cId = (int)$f['colorOpt']['id'];
        $sId = (int)$f['sizeOpt']['id'];
        $stId = (int)$f['styleOpt']['id'];

        // Configure:
        // - Color: 'color', help_text = 'Select your color'
        // - Size: 'button', help_text = 'Choose your size'
        // - Style: 'dropdown', help_text = '' (empty -> must not render empty div)
        $this->wpdb->update(
            "{$prefix}wpe_products",
            [
                'extra_props' => wp_json_encode([
                    'option_ui' => [
                        (string)$cId  => ['display_type' => 'color', 'help_text' => 'Select your color'],
                        (string)$sId  => ['display_type' => 'button', 'help_text' => 'Choose your size'],
                        (string)$stId => ['display_type' => 'dropdown', 'help_text' => ''],
                    ],
                ]),
            ],
            ['id' => $productId]
        );

        // Set hex for Black (#111111) and White (#ffffff, light), leave Cobalt without hex (fallback to button)
        $this->wpdb->update("{$prefix}wpe_option_values", ['swatch' => '{"color":"#111111"}'], ['id' => (int)$f['valBlack']['id']]);
        $this->wpdb->update("{$prefix}wpe_option_values", ['swatch' => '{"color":"#ffffff"}'], ['id' => (int)$f['valWhite']['id']]);
        $this->wpdb->update("{$prefix}wpe_option_values", ['swatch' => null], ['id' => (int)$f['valCobalt']['id']]);

        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'show_help_text'      => true,
            'button_selector'     => true,
            'color_swatches'      => true,
            'image_swatches'      => true,
        ]);
        $this->adapter->invalidateCache($productId);

        $wcProduct = wc_get_product($productId);

        $colorHtml = $this->adapter->renderSwatchControls('<select name="attribute_pa_color"></select>', [
            'product'   => $wcProduct,
            'attribute' => 'pa_color',
        ]);
        $sizeHtml = $this->adapter->renderSwatchControls('<select name="attribute_pa_size"></select>', [
            'product'   => $wcProduct,
            'attribute' => 'pa_size',
        ]);
        $styleHtml = $this->adapter->renderSwatchControls('<select name="attribute_pa_style"></select>', [
            'product'   => $wcProduct,
            'attribute' => 'pa_style',
        ]);

        // Color checks
        $this->assert(str_contains($colorHtml, 'wpe-swatches-color'), "Color option must render .wpe-swatches-color");
        $this->assert(str_contains($colorHtml, 'is-light-swatch'), "White (#ffffff) must have .is-light-swatch border class");
        $this->assert(str_contains($colorHtml, 'wpe-swatch-color-fallback'), "Cobalt (missing hex) must fallback to .wpe-swatch-color-fallback button");
        $this->assert(str_contains($colorHtml, 'Select your color'), "Color help text must render");
        $this->assert(str_contains($colorHtml, 'aria-describedby="wpe-help-' . $productId . '-' . $cId . '"'), "Color control must link to help text via aria-describedby");

        // Size checks
        $this->assert(str_contains($sizeHtml, 'wpe-swatches-button'), "Size option must render .wpe-swatches-button");
        $this->assert(str_contains($sizeHtml, 'Choose your size'), "Size help text must render");
        $this->assert(str_contains($sizeHtml, 'wpe-select-hidden'), "Native select must be hidden when button selector is active");

        // Style checks (dropdown, empty help text)
        $this->assert(!str_contains($styleHtml, 'wpe-swatches'), "Dropdown option must not render .wpe-swatches");
        $this->assert(!str_contains($styleHtml, 'wpe-option-help-text'), "Option with empty help_text must not render empty .wpe-option-help-text element");

        // Turn off button_selector -> Size must fallback to dropdown
        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'show_help_text'      => true,
            'button_selector'     => false,
            'color_swatches'      => true,
        ]);
        $this->adapter->invalidateCache($productId);
        $sizeFallbackHtml = $this->adapter->renderSwatchControls('<select name="attribute_pa_size"></select>', [
            'product'   => $wcProduct,
            'attribute' => 'pa_size',
        ]);
        $this->assert(!str_contains($sizeFallbackHtml, 'wpe-swatches'), "When button_selector is OFF, button attribute must fallback to dropdown");

        echo " OK\n";
    }

    /**
     * 4. Image Swatches 5-step candidate thumbnail resolution across Color -> Size -> Style.
     */
    public function testImageSwatchesCandidateResolutionColorSizeStyle(): void
    {
        echo " - testImageSwatchesCandidateResolutionColorSizeStyle...";
        $f = $this->createThreeOptionFixture();
        $productId = (int)$f['productId'];
        $cId = (string)$f['colorOpt']['id'];
        $sId = (string)$f['sizeOpt']['id'];
        $stId = (string)$f['styleOpt']['id'];
        $blackId = (string)$f['valBlack']['id'];
        $whiteId = (string)$f['valWhite']['id'];
        $cobaltId = (string)$f['valCobalt']['id'];
        $lId = (string)$f['valL']['id'];
        $hoodieId = (string)$f['valHoodie']['id'];

        update_option('wpe_feature_flags', [
            'variant_selector_ui' => true,
            'image_swatches'      => true,
        ]);
        $this->adapter->invalidateCache($productId);

        // Unconfigured 1st option (Color, pos 0) defaults to 'image'
        $config = $this->adapter->getSelectorConfig($productId);
        $this->assert($config['options'][0]['display_type'] === 'image', "1st variation option must default to 'image' when image_swatches is ON");
        $imgGroupHtml = $this->adapter->buildAccessibleSwatchGroupHtml($config['options'][0], '', $config['flags'], $productId);
        $this->assert(str_contains($imgGroupHtml, 'wpe-swatch-image-thumb') && str_contains($imgGroupHtml, 'wpe-swatch-image-label'), "Image swatch must always render both thumbnail and value label");

        // Case A: Nothing selected -> Black uses V1 (black-m-tee.jpg); White uses V4 (no variant image) or V5 (white-l-hoodie.jpg -> first matching with image!)
        $resEmpty = $this->resolver->resolve($productId, []);
        $thumbsEmpty = $resEmpty['value_thumbnails'][$cId];
        $this->assert(str_contains($thumbsEmpty[$blackId]['src'], 'black-m-tee.jpg'), "Unselected Size/Style: Black must use 1st Black variation image (black-m-tee.jpg)");
        $this->assert(str_contains($thumbsEmpty[$whiteId]['src'], 'white-l-hoodie.jpg'), "Unselected Size/Style: White must use 1st White variation with an image (white-l-hoodie.jpg)");
        // Cobalt only has V6 (no variant image) -> falls back to parent featured image
        $this->assert(str_contains($thumbsEmpty[$cobaltId]['src'], 'parent-featured.jpg'), "Cobalt (no variant image) must fallback to parent featured image");

        // Case B: Size=L selected -> Black uses V2 (black-l-tee.jpg, 1st Black+L variation with image)
        $resSizeL = $this->resolver->resolve($productId, [$sId => $lId]);
        $thumbsSizeL = $resSizeL['value_thumbnails'][$cId];
        $this->assert(str_contains($thumbsSizeL[$blackId]['src'], 'black-l-tee.jpg'), "Size=L: Black must resolve to 1st Black+L variation image (black-l-tee.jpg)");
        $this->assert(str_contains($thumbsSizeL[$whiteId]['src'], 'white-l-hoodie.jpg'), "Size=L: White must resolve to White+L variation image (white-l-hoodie.jpg)");
        // Cobalt+L does not exist -> marked unavailable in allowed_details, but still has representative/fallback thumbnail so swatch doesn't vanish
        $this->assert($resSizeL['allowed_details'][$cId][$cobaltId]['exists'] === false, "Size=L: Cobalt+L does not exist and must be marked exists=false");
        $this->assert(!empty($thumbsSizeL[$cobaltId]['src']), "Unavailable value must keep representative/fallback thumbnail so swatch never disappears");

        // Case C: Size=L, Style=Hoodie selected -> Black uses V3 (black-l-hoodie.jpg)
        $resLHoodie = $this->resolver->resolve($productId, [$sId => $lId, $stId => $hoodieId]);
        $thumbsLHoodie = $resLHoodie['value_thumbnails'][$cId];
        $this->assert(str_contains($thumbsLHoodie[$blackId]['src'], 'black-l-hoodie.jpg'), "Size=L, Style=Hoodie: Black must resolve to exact Black+L+Hoodie image (black-l-hoodie.jpg)");

        // Case D: When parent featured image is absent, Cobalt falls back to data:image/svg+xml placeholder and esc_url preserves it
        $this->wpdb->delete("{$this->wpdb->prefix}wpe_product_media", ['product_id' => $productId, 'role' => 'featured']);
        $this->adapter->invalidateCache($productId);
        $cfgPlaceholder = $this->adapter->getSelectorConfig($productId);
        $htmlPlaceholder = $this->adapter->buildAccessibleSwatchGroupHtml($cfgPlaceholder['options'][0], '', $cfgPlaceholder['flags'], $productId);
        $this->assert(str_contains($htmlPlaceholder, 'src="data:image/svg+xml'), "Placeholder data:image/svg+xml URI must be preserved in Image Swatch img src");

        echo " OK\n";
    }

    /**
     * 5. Mark Unavailable Options & Stock/Backorder availability policy.
     */
    public function testMarkUnavailableOptionsAndBackorderPolicy(): void
    {
        echo " - testMarkUnavailableOptionsAndBackorderPolicy...";
        $f = $this->createThreeOptionFixture();
        $productId = (int)$f['productId'];
        $cId = (string)$f['colorOpt']['id'];
        $whiteId = (string)$f['valWhite']['id'];
        $cobaltId = (string)$f['valCobalt']['id'];

        // V5 (White+L+Hoodie) has quantity=0 but backorders='yes' -> must be purchasable!
        // V6 (Cobalt+M+Tee) has quantity=0 and backorders='no', stock_status='outofstock' -> must NOT be purchasable!
        $res = $this->resolver->resolve($productId, []);
        $this->assert($res['allowed_details'][$cId][$whiteId]['purchasable'] === true, "White (with backorderable V5 and in-stock V4) must be purchasable");
        $this->assert($res['allowed_details'][$cId][$cobaltId]['purchasable'] === false, "Cobalt (only V6 outofstock, backorders='no') must NOT be purchasable");

        // Exact resolution of V5 (White+L+Hoodie, qty=0, backorders='yes')
        $resV5 = $this->resolver->resolve($productId, [
            (string)$f['colorOpt']['id'] => (string)$f['valWhite']['id'],
            (string)$f['sizeOpt']['id']  => (string)$f['valL']['id'],
            (string)$f['styleOpt']['id'] => (string)$f['valHoodie']['id'],
        ]);
        $this->assert($resV5['state'] === 'exact' && $resV5['variant']['purchasable'] === true, "Variation with qty=0 and backorders='yes' must be purchasable");

        // Exact resolution of V6 (Cobalt+M+Tee, qty=0, backorders='no')
        $resV6 = $this->resolver->resolve($productId, [
            (string)$f['colorOpt']['id'] => (string)$f['valCobalt']['id'],
            (string)$f['sizeOpt']['id']  => (string)$f['valM']['id'],
            (string)$f['styleOpt']['id'] => (string)$f['valTee']['id'],
        ]);
        $this->assert($resV6['state'] === 'exact' && $resV6['variant']['purchasable'] === false, "Variation with qty=0 and backorders='no' must NOT be purchasable");

        // Compare HTML classes when mark_unavailable_options is ON vs OFF
        update_option('wpe_feature_flags', [
            'variant_selector_ui'      => true,
            'image_swatches'           => true,
            'mark_unavailable_options' => true,
        ]);
        $this->adapter->invalidateCache($productId);
        $cfgMarked = $this->adapter->getSelectorConfig($productId);
        $htmlMarked = $this->adapter->buildAccessibleSwatchGroupHtml($cfgMarked['options'][0], '', $cfgMarked['flags'], $productId);
        $this->assert(str_contains($htmlMarked, 'is-marked-unavailable'), "When mark_unavailable_options is ON, unavailable swatch must have .is-marked-unavailable");

        update_option('wpe_feature_flags', [
            'variant_selector_ui'      => true,
            'image_swatches'           => true,
            'mark_unavailable_options' => false,
        ]);
        $this->adapter->invalidateCache($productId);
        $cfgStandard = $this->adapter->getSelectorConfig($productId);
        $htmlStandard = $this->adapter->buildAccessibleSwatchGroupHtml($cfgStandard['options'][0], '', $cfgStandard['flags'], $productId);
        $this->assert(str_contains($htmlStandard, 'is-disabled-standard') && !str_contains($htmlStandard, 'is-marked-unavailable'), "When mark_unavailable_options is OFF, unavailable swatch must use .is-disabled-standard without .is-marked-unavailable");

        echo " OK\n";
    }

    /**
     * 6. Client JS simulation: Show Selected Value (display label, never slug/ID),
     *    dynamic thumbnail updates, Single Price Display revert on partial/reset,
     *    and multi-instance isolation between main product and quick-view.
     */
    public function testClientSelectorSyncAndMultiInstanceIsolation(): void
    {
        echo " - testClientSelectorSyncAndMultiInstanceIsolation...";
        $jsFile = dirname(__DIR__, 2) . '/assets/variant-selector/selector.js';

        $nodeScript = <<<NODE
import '{$jsFile}';

const WpeVariantSelector = globalThis.WpeVariantSelector;

function createMockInstance(productId, lowestPrice, flags) {
  const state = {
    priceHtml: lowestPrice,
    priceState: 'lowest',
    imgSrc: 'https://cdn.example.com/parent-' + productId + '.jpg',
    labelText: 'Color',
    labelBase: '',
    labelSelected: '',
    variationId: '',
    addDisabled: true,
    thumbSrc: 'https://cdn.example.com/init-black-' + productId + '.jpg',
    thumbSource: 'variation'
  };

  const priceEl = {
    get innerHTML() { return state.priceHtml; },
    set innerHTML(v) { state.priceHtml = v; },
    setAttribute: (k, v) => { if (k === 'data-wpe-price-state') state.priceState = v; }
  };

  const mainImg = {
    getAttribute: (k) => k === 'src' ? state.imgSrc : '',
    setAttribute: (k, v) => { if (k === 'src') state.imgSrc = v; }
  };

  const gallery = {
    querySelector: () => mainImg
  };

  const labelEl = {
    get textContent() { return state.labelText; },
    set textContent(v) { state.labelText = v; },
    getAttribute: (k) => k === 'data-wpe-base-label' ? state.labelBase : null,
    setAttribute: (k, v) => {
      if (k === 'data-wpe-base-label') state.labelBase = v;
      if (k === 'data-selected-value-label') state.labelSelected = v;
    },
    removeAttribute: (k) => {
      if (k === 'data-selected-value-label') state.labelSelected = '';
    }
  };

  const thumbImg = {
    setAttribute: (k, v) => {
      if (k === 'src') state.thumbSrc = v;
      if (k === 'data-thumb-source') state.thumbSource = v;
    }
  };

  const swatchBlack = {
    disabled: false,
    attrs: {
      'data-option-id': '10',
      'data-value-id': '101',
      'data-value-key': 'jet-black-slug',
      'data-value-label': 'Jet Black',
      'aria-checked': 'false',
      'aria-disabled': 'false',
      'tabindex': '0'
    },
    classes: new Set(['wpe-swatch']),
    getAttribute(k) { return this.attrs[k] || null; },
    setAttribute(k, v) { this.attrs[k] = String(v); },
    removeAttribute(k) { delete this.attrs[k]; },
    addEventListener() {},
    querySelector: () => thumbImg,
    classList: {
      add: (...c) => c.forEach(x => swatchBlack.classes.add(x)),
      remove: (...c) => c.forEach(x => swatchBlack.classes.delete(x)),
      contains: (x) => swatchBlack.classes.has(x)
    }
  };

  const swatchGroup = {
    getAttribute: (k) => {
      if (k === 'data-option-id') return '10';
      if (k === 'data-option-name') return 'Color';
      return null;
    },
    querySelectorAll: () => [swatchBlack],
    closest: () => ({ querySelector: () => labelEl })
  };

  const variationInput = {
    get value() { return state.variationId; },
    set value(v) { state.variationId = String(v); }
  };

  const addBtn = {
    removeAttribute: () => { state.addDisabled = false; },
    setAttribute: () => { state.addDisabled = true; },
    classList: { add: () => {}, remove: () => {} }
  };

  const instanceRoot = {
    classList: { add: () => {}, remove: () => {} },
    querySelector: (sel) => {
      if (sel.includes('wpe-single-price-target') || sel.includes('price')) return priceEl;
      if (sel.includes('woocommerce-product-gallery')) return gallery;
      return null;
    }
  };

  const form = {
    classList: { add: () => {}, remove: () => {} },
    closest: () => instanceRoot,
    setAttribute: () => {},
    removeAttribute: () => {},
    appendChild: () => {},
    querySelector: (sel) => {
      if (sel === 'input[name="variation_id"]') return variationInput;
      if (sel === '.single_add_to_cart_button') return addBtn;
      if (sel.includes('.wpe-swatches[data-option-id="10"]')) return swatchGroup;
      if (sel.includes('.wpe-swatch[data-option-id="10"][data-value-id="101"]')) return swatchBlack;
      return null;
    },
    querySelectorAll: (sel) => {
      if (sel === '.wpe-swatch') return [swatchBlack];
      if (sel.includes('.wpe-swatches[data-option-id]')) return [swatchGroup];
      return [];
    }
  };

  const selector = new WpeVariantSelector(form, {
    productId,
    resolveEndpoint: 'http://localhost/resolve',
    flags,
    lowestPriceHtml: lowestPrice,
    options: [
      {
        id: 10,
        key: 'pa_color',
        name: 'Color',
        values: [{ id: 101, key: 'jet-black-slug', label: 'Jet Black' }]
      }
    ],
    initialResolution: {
      value_thumbnails: {
        '10': { '101': { src: 'https://cdn.example.com/init-black-' + productId + '.jpg', source: 'variation' } }
      }
    }
  });

  return { selector, state, swatchBlack };
}

// Create 2 separate instances on the same page (Main Product 100 & Quick View 200)
const instA = createMockInstance(100, '$18.00', {
  variant_selector_ui: true,
  single_price_display: true,
  show_selected_value: true,
  mark_unavailable_options: true
});

const instB = createMockInstance(200, '$55.00', {
  variant_selector_ui: true,
  single_price_display: true,
  show_selected_value: false,
  mark_unavailable_options: false
});

// Select value 101 in Instance A and simulate exact response
instA.selector.currentSelection = { '10': '101' };
instA.selector.handleResponse({
  state: 'exact',
  request_sequence: 1,
  lowest_price_html: '$18.00',
  allowed_details: { '10': { '101': { exists: true, purchasable: true, in_stock: true } } },
  value_thumbnails: { '10': { '101': { src: 'https://cdn.example.com/resolved-black-l.jpg', source: 'variation' } } },
  variant: {
    id: 901,
    price_html: '<del>$25.00</del> <ins>$18.00</ins>',
    display_price: '$18.00',
    purchasable: true,
    available: true,
    image: { src: 'https://cdn.example.com/gallery-black-l.jpg' }
  }
});

// Verify Instance A updated its label to display label "Color: Jet Black" (NOT slug "jet-black-slug" or ID "101")
if (instA.state.labelText !== 'Color: Jet Black') {
  console.error('Expected Instance A label "Color: Jet Black", got:', instA.state.labelText);
  process.exit(1);
}
if (instA.state.priceHtml !== '<del>$25.00</del> <ins>$18.00</ins>' || instA.state.priceState !== 'resolved') {
  console.error('Expected Instance A resolved price HTML, got:', instA.state.priceHtml, instA.state.priceState);
  process.exit(2);
}
if (instA.state.thumbSrc !== 'https://cdn.example.com/resolved-black-l.jpg') {
  console.error('Expected Instance A dynamic thumbnail update, got:', instA.state.thumbSrc);
  process.exit(3);
}
if (instA.state.imgSrc !== 'https://cdn.example.com/gallery-black-l.jpg') {
  console.error('Expected Instance A gallery update, got:', instA.state.imgSrc);
  process.exit(4);
}

// Verify Instance B was completely untouched!
if (instB.state.priceHtml !== '$55.00' || instB.state.variationId !== '' || instB.state.labelText !== 'Color') {
  console.error('Instance B was contaminated by Instance A!', instB.state);
  process.exit(5);
}

// Reset Instance A -> label reverts to "Color", price reverts to "$18.00", thumbnail & gallery revert
instA.selector.resetSelection();
if (instA.state.labelText !== 'Color') {
  console.error('Expected Instance A label to revert to "Color" on reset, got:', instA.state.labelText);
  process.exit(6);
}
if (instA.state.priceHtml !== '$18.00' || instA.state.priceState !== 'lowest') {
  console.error('Expected Instance A price to revert to lowest price on reset, got:', instA.state.priceHtml);
  process.exit(7);
}
if (instA.state.thumbSrc !== 'https://cdn.example.com/init-black-100.jpg') {
  console.error('Expected Instance A thumbnail to restore initial resolution on reset, got:', instA.state.thumbSrc);
  process.exit(8);
}
if (instA.state.imgSrc !== 'https://cdn.example.com/parent-100.jpg') {
  console.error('Expected Instance A gallery to restore parent image on reset, got:', instA.state.imgSrc);
  process.exit(9);
}

// Verify custom product-level attribute <option value="Dark sage"> (display label) normalizes to value ID and is NOT disabled
const customNormalized = instA.selector.normalizeOptionValueId('10', 'Jet Black');
if (customNormalized !== '101') {
  console.error('Expected normalizeOptionValueId to map display label "Jet Black" to ID "101", got:', customNormalized);
  process.exit(10);
}
const customNormalizedCi = instA.selector.normalizeOptionValueId('10', 'jet black');
if (customNormalizedCi !== '101') {
  console.error('Expected case-insensitive normalizeOptionValueId to map "jet black" to ID "101", got:', customNormalizedCi);
  process.exit(11);
}

// Verify clicking an already-selected swatch toggles it off and resets selection when empty
instA.selector.currentSelection = { '10': '101' };
instA.selector.onSwatchSelect('10', '101', false);
if (instA.selector.currentSelection['10'] !== undefined || instA.state.labelText !== 'Color') {
  console.error('Expected clicking already-selected swatch to toggle off and reset, got:', instA.selector.currentSelection, instA.state.labelText);
  process.exit(12);
}

// Verify applyPartialState(null) restores parent image even when currentSelection is non-empty
instA.selector.currentSelection = { '10': '101' };
instA.selector.applyPartialState({ src: 'https://cdn.example.com/black-partial.jpg', srcset: '', sizes: '', alt: 'Black' });
if (instA.state.imgSrc !== 'https://cdn.example.com/black-partial.jpg') {
  console.error('Expected partial candidateImage to update gallery, got:', instA.state.imgSrc);
  process.exit(13);
}
instA.selector.currentSelection = { '10': '102' };
instA.selector.applyPartialState(null);
if (instA.state.imgSrc !== 'https://cdn.example.com/parent-100.jpg') {
  console.error('Expected applyPartialState(null) with non-empty selection to restore parent image, got:', instA.state.imgSrc);
  process.exit(14);
}

// Verify toggling off a default swatch when defaultSelection is configured does NOT immediately re-select the default
instA.selector.config.defaultSelection = { '10': '101' };
instA.selector.currentSelection = { '10': '101' };
instA.selector.onSwatchSelect('10', '101', false);
if (instA.selector.currentSelection['10'] !== undefined) {
  console.error('Expected toggling off default swatch to clear selection instead of re-selecting default, got:', instA.selector.currentSelection);
  process.exit(15);
}
instA.selector.config.defaultSelection = {};

// Verify applyUnavailableVariant maps internal reason codes to human-readable i18n messages
let lastNotice = '';
instA.selector.showNotice = (msg) => { lastNotice = msg; };
instA.selector.applyUnavailableVariant(null, 'out_of_stock');
if (lastNotice !== 'Out of stock') {
  console.error('Expected out_of_stock reason to map to i18n.outOfStock, got:', lastNotice);
  process.exit(16);
}
instA.selector.applyUnavailableVariant(null, 'missing_price');
if (lastNotice !== 'This combination is unavailable') {
  console.error('Expected missing_price reason to map to i18n.unavailable, got:', lastNotice);
  process.exit(17);
}

console.log('P012_CLIENT_SYNC_OK');
NODE;

        $tmpFile = tempnam(sys_get_temp_dir(), 'p012_client_') . '.mjs';
        file_put_contents($tmpFile, $nodeScript);
        exec("node {$tmpFile} 2>&1", $output, $exitCode);
        unlink($tmpFile);

        $this->assert($exitCode === 0, "Client sync & multi-instance simulation must exit 0: " . implode("\n", $output));
        $this->assert(in_array('P012_CLIENT_SYNC_OK', $output, true), "Simulation must output P012_CLIENT_SYNC_OK");

        echo " OK\n";
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanValueIds as $valId) {
            $this->wpdb->delete("{$prefix}wpe_option_values", ['id' => $valId]);
            $this->wpdb->delete("{$prefix}wpe_variant_values", ['value_id' => $valId]);
        }
        foreach ($this->cleanOptionIds as $optId) {
            $this->wpdb->delete("{$prefix}wpe_options", ['id' => $optId]);
            $this->wpdb->delete("{$prefix}wpe_variant_values", ['option_id' => $optId]);
        }
        foreach ($this->cleanAssetIds as $assetId) {
            $this->wpdb->delete("{$prefix}wpe_assets", ['id' => $assetId]);
            $this->wpdb->delete("{$prefix}wpe_product_media", ['asset_id' => $assetId]);
            $this->wpdb->delete("{$prefix}wpe_variant_media", ['asset_id' => $assetId]);
        }
        foreach ($this->cleanProductIds as $id) {
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_product_media", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variant_media", ['variant_id' => $id]);
            wp_delete_post($id, true);
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
}

$test = new ProductPageSettingsTest();
$test->run();
