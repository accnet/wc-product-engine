<?php
/**
 * T35 Integration Test — Native Shopify exporter và loss policy.
 *
 * Verifies:
 * - R141 (shopify_export_headers): Shopify exporter produces valid profile headers (current/legacy) without WPE_* columns.
 * - R142 (variant_image_association): Primary variant image mapped per row; product gallery and image-only continuation rows serialized correctly.
 * - R143 (more_than_three_options_loss): Maximum 3 options enforced; strict mode fails with structured report; lossy mode logs omitted options.
 * - R144 (no_extra_native_columns): Zero extra native/WPE_* columns in CSV; IDs and unsupported engine fields archived in sidecar JSON.
 * - AC-T35-01: Schema snapshot Shopify profile validates; exact image/variant association; >3 options strict fails with report.
 * - AC-T35-02: Shopify CSV export has subset declaration, does NOT claim Shopify preserves native variant IDs.
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

use WPE\Compatibility\Csv\Shopify\ShopifyExporter;
use WPE\Compatibility\Csv\Shopify\StrictExportException;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\UrlValidator;

class T35Test
{
    private \wpdb $wpdb;
    private ShopifyExporter $exporter;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;

    private array $cleanProductIds = [];
    private array $cleanFiles = [];
    private array $cleanAssetIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->productRepo  = new ProductRepository($this->wpdb);
        $this->optionsRepo  = new OptionsRepository($this->wpdb);
        $this->assetService = new AssetService($this->wpdb);
        $this->exporter     = new ShopifyExporter($this->wpdb, $this->productRepo, $this->optionsRepo, $this->assetService);

        wp_set_current_user(1);
    }

    public function run(): void
    {
        echo "Running T35 Integration Tests...\n";
        $this->tearDown();
        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        try {
            $this->testR141_shopify_export_headers();
            $this->testR142_variant_image_association();
            $this->testR143_more_than_three_options_loss();
            $this->testR144_no_extra_native_columns();
            $this->testAC_T35_01_schema_snapshot_and_image_association();
            $this->testAC_T35_02_subset_declaration_and_no_native_id_preservation();
            echo "All T35 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    private function tearDown(): void
    {
        foreach ($this->cleanProductIds as $pId) {
            $variants = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d",
                $pId
            ));

            foreach ($variants as $vId) {
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_inventory', ['owner_id' => $vId]);
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_variant_values', ['variant_id' => $vId]);
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_variant_media', ['variant_id' => $vId]);
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_variants', ['id' => $vId]);
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_sku_registry', ['object_id' => $vId]);
                $this->wpdb->delete($this->wpdb->prefix . 'posts', ['ID' => $vId]);
            }

            $options = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_options WHERE product_id = %d",
                $pId
            ));
            foreach ($options as $optId) {
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_option_values', ['option_id' => $optId]);
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_options', ['id' => $optId]);
            }

            $this->wpdb->delete($this->wpdb->prefix . 'wpe_inventory', ['owner_id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_product_media', ['product_id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_products', ['id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_sku_registry', ['object_id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_object_state', ['object_id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'posts', ['ID' => $pId]);
        }

        // Global safeguard purge for T35 SKUs and slugs
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T35-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_products WHERE sku LIKE 'T35-%' OR slug LIKE 't35-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_variants WHERE sku LIKE 'T35-%'");

        foreach ($this->cleanAssetIds as $aId) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_assets', ['id' => $aId]);
        }

        foreach ($this->cleanFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        UrlValidator::setDnsResolver(null);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function readCsvRows(string $filePath): array
    {
        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open CSV: {$filePath}");
        }
        $bom = fread($fp, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fp);
        }
        $rows = [];
        while (($row = fgetcsv($fp)) !== false) {
            $rows[] = $row;
        }
        fclose($fp);
        return $rows;
    }

    /**
     * R141: Test Shopify export headers match current/legacy profiles without WPE_* prefix (R141).
     */
    public function testR141_shopify_export_headers(): void
    {
        echo "Testing R141 (shopify_export_headers)...\n";

        // Create a simple product fixture
        $created = $this->productRepo->create([
            'title'         => 'T35 Simple Mug',
            'slug'          => 't35-simple-mug',
            'type'          => 'simple',
            'status'        => 'publish',
            'sku'           => 'T35-MUG-001',
            'regular_price' => 15.00,
            'sale_price'    => 12.00,
            'manage_stock'  => 1,
            'stock_quantity'=> 50,
        ]);
        $pId = (int)$created['id'];
        $this->cleanProductIds[] = $pId;

        // 1. Export in Current profile
        $resCurr = $this->exporter->exportProducts([$pId], ['profile' => 'current']);
        $this->assert($resCurr['status'] === 'completed', "Current export should complete");
        $this->cleanFiles[] = $resCurr['file_path'];
        if ($resCurr['sidecar_path']) {
            $this->cleanFiles[] = $resCurr['sidecar_path'];
        }

        // Verify Current CSV headers
        $fpCurr = fopen($resCurr['file_path'], 'rb');
        // skip BOM
        fseek($fpCurr, 3);
        $headersCurr = fgetcsv($fpCurr);
        fclose($fpCurr);

        $this->assert($headersCurr === ShopifyExporter::CURRENT_HEADERS, "Current headers must match CURRENT_HEADERS definition");
        foreach ($headersCurr as $col) {
            $this->assert(!str_starts_with(strtoupper($col), 'WPE_'), "No column should start with WPE_ in current profile");
            $this->assert($col !== 'id' && $col !== 'product_id', "No internal ID column in current profile");
        }

        // 2. Export in Legacy profile
        $resLeg = $this->exporter->exportProducts([$pId], ['profile' => 'legacy']);
        $this->assert($resLeg['status'] === 'completed', "Legacy export should complete");
        $this->cleanFiles[] = $resLeg['file_path'];
        if ($resLeg['sidecar_path']) {
            $this->cleanFiles[] = $resLeg['sidecar_path'];
        }

        // Verify Legacy CSV headers
        $fpLeg = fopen($resLeg['file_path'], 'rb');
        fseek($fpLeg, 3);
        $headersLeg = fgetcsv($fpLeg);
        fclose($fpLeg);

        $this->assert($headersLeg === ShopifyExporter::LEGACY_HEADERS, "Legacy headers must match LEGACY_HEADERS definition");
        foreach ($headersLeg as $col) {
            $this->assert(!str_starts_with(strtoupper($col), 'WPE_'), "No column should start with WPE_ in legacy profile");
            $this->assert($col !== 'id' && $col !== 'product_id', "No internal ID column in legacy profile");
        }

        echo "R141 passed: Shopify current and legacy profile headers validated without WPE_* columns.\n";
    }

    /**
     * R142: Test variant image association and product gallery image-only continuation rows (R142, AC-T35-01).
     */
    public function testR142_variant_image_association(): void
    {
        echo "Testing R142 (variant_image_association)...\n";

        // Create variable product
        $created = $this->productRepo->create([
            'title'       => 'T35 Canvas Sneaker',
            'slug'        => 't35-canvas-sneaker',
            'type'        => 'variable',
            'status'      => 'publish',
            'description' => 'Comfortable canvas sneakers',
        ]);
        $pId = (int)$created['id'];
        $this->cleanProductIds[] = $pId;

        // Create option: Color
        $optColor = $this->optionsRepo->createOption($pId, ['name' => 'Color', 'key' => 'color', 'position' => 0]);
        $valRed   = $this->optionsRepo->createOptionValue((int)$optColor['id'], ['label' => 'Red', 'value_key' => 'red']);
        $valBlue  = $this->optionsRepo->createOptionValue((int)$optColor['id'], ['label' => 'Blue', 'value_key' => 'blue']);

        // Create 2 variants: Red and Blue
        $this->productRepo->update($pId, [
            'variations' => [
                [
                    'sku'            => 'T35-SNK-RED',
                    'regular_price'  => 60.00,
                    'manage_stock'   => 1,
                    'stock_quantity' => 10,
                    'status'         => 'publish',
                    'attributes'     => ['Color' => 'Red'],
                ],
                [
                    'sku'            => 'T35-SNK-BLUE',
                    'regular_price'  => 60.00,
                    'manage_stock'   => 1,
                    'stock_quantity' => 15,
                    'status'         => 'publish',
                    'attributes'     => ['Color' => 'Blue'],
                ],
            ],
        ]);

        $variants = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, sku FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d ORDER BY position ASC, id ASC",
            $pId
        ), ARRAY_A);

        $vRedId = (int)$variants[0]['id'];
        $vBlueId = (int)$variants[1]['id'];

        $this->optionsRepo->assignVariantCombination($pId, $vRedId, [(int)$optColor['id'] => (int)$valRed['id']]);
        $this->optionsRepo->assignVariantCombination($pId, $vBlueId, [(int)$optColor['id'] => (int)$valBlue['id']]);

        // Register assets
        $heroAsset  = $this->assetService->registerAsset('https://cdn.example.com/t35/hero.jpg', ['alt_text' => 'Sneaker Hero']);
        $angle1Asset= $this->assetService->registerAsset('https://cdn.example.com/t35/angle1.jpg', ['alt_text' => 'Sneaker Angle 1']);
        $angle2Asset= $this->assetService->registerAsset('https://cdn.example.com/t35/angle2.jpg', ['alt_text' => 'Sneaker Angle 2']);
        $redAsset   = $this->assetService->registerAsset('https://cdn.example.com/t35/red-shoe.jpg', ['alt_text' => 'Red Sneaker View']);
        $blueAsset  = $this->assetService->registerAsset('https://cdn.example.com/t35/blue-shoe.jpg', ['alt_text' => 'Blue Sneaker View']);

        $this->cleanAssetIds[] = (int)$heroAsset['id'];
        $this->cleanAssetIds[] = (int)$angle1Asset['id'];
        $this->cleanAssetIds[] = (int)$angle2Asset['id'];
        $this->cleanAssetIds[] = (int)$redAsset['id'];
        $this->cleanAssetIds[] = (int)$blueAsset['id'];

        // Attach 3 gallery images to product: 1 featured + 2 gallery
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_product_media', [
            'product_id' => $pId, 'asset_id' => $heroAsset['id'], 'role' => 'featured', 'position' => 0
        ]);
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_product_media', [
            'product_id' => $pId, 'asset_id' => $angle1Asset['id'], 'role' => 'gallery', 'position' => 1
        ]);
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_product_media', [
            'product_id' => $pId, 'asset_id' => $angle2Asset['id'], 'role' => 'gallery', 'position' => 2
        ]);

        // Attach primary variant images to each variant (is_primary = 1)
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_variant_media', [
            'variant_id' => $vRedId, 'asset_id' => $redAsset['id'], 'position' => 0, 'is_primary' => 1
        ]);
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_variant_media', [
            'variant_id' => $vBlueId, 'asset_id' => $blueAsset['id'], 'position' => 0, 'is_primary' => 1
        ]);

        // Export to Shopify CSV (Current profile)
        $res = $this->exporter->exportProducts([$pId], ['profile' => 'current']);
        $this->cleanFiles[] = $res['file_path'];
        if ($res['sidecar_path']) {
            $this->cleanFiles[] = $res['sidecar_path'];
        }

        $this->assert($res['total_variations'] === 2, "Exported 2 variations");
        $this->assert($res['total_rows'] === 3, "Total rows must be 3 (2 variant rows + 1 extra gallery image row)");
        $this->assert($res['total_image_rows'] === 1, "Must have 1 image-only continuation row");

        $rows = $this->readCsvRows($res['file_path']);
        $header = $rows[0];
        $hMap = array_flip($header);

        $row1 = $rows[1]; // Variant 1 (Red)
        $row2 = $rows[2]; // Variant 2 (Blue)
        $row3 = $rows[3]; // Image-only row (angle2.jpg)

        // Row 1 checks:
        $this->assert($row1[$hMap['URL handle']] === 't35-canvas-sneaker', "Row 1 handle matches");
        $this->assert($row1[$hMap['SKU']] === 'T35-SNK-RED', "Row 1 SKU is Red");
        $this->assert($row1[$hMap['Option1 value']] === 'Red', "Row 1 option value is Red");
        $this->assert($row1[$hMap['Product image URL']] === 'https://cdn.example.com/t35/hero.jpg', "Row 1 product image is hero");
        $this->assert((int)$row1[$hMap['Image position']] === 1, "Row 1 image position is 1");
        $this->assert($row1[$hMap['Variant image URL']] === 'https://cdn.example.com/t35/red-shoe.jpg', "Row 1 variant image is red shoe");

        // Row 2 checks:
        $this->assert($row2[$hMap['URL handle']] === 't35-canvas-sneaker', "Row 2 handle matches");
        $this->assert($row2[$hMap['SKU']] === 'T35-SNK-BLUE', "Row 2 SKU is Blue");
        $this->assert($row2[$hMap['Option1 value']] === 'Blue', "Row 2 option value is Blue");
        $this->assert($row2[$hMap['Product image URL']] === 'https://cdn.example.com/t35/angle1.jpg', "Row 2 product image is angle 1");
        $this->assert((int)$row2[$hMap['Image position']] === 2, "Row 2 image position is 2");
        $this->assert($row2[$hMap['Variant image URL']] === 'https://cdn.example.com/t35/blue-shoe.jpg', "Row 2 variant image is blue shoe");

        // Row 3 checks (Image-only row):
        $this->assert($row3[$hMap['URL handle']] === 't35-canvas-sneaker', "Row 3 handle matches");
        $this->assert($row3[$hMap['Product image URL']] === 'https://cdn.example.com/t35/angle2.jpg', "Row 3 product image is angle 2");
        $this->assert((int)$row3[$hMap['Image position']] === 3, "Row 3 image position is 3");
        $this->assert($row3[$hMap['SKU']] === '', "Row 3 SKU must be blank (image-only row)");
        $this->assert($row3[$hMap['Price']] === '', "Row 3 Price must be blank");
        $this->assert($row3[$hMap['Option1 value']] === '', "Row 3 Option1 value must be blank");
        $this->assert($row3[$hMap['Variant image URL']] === '', "Row 3 Variant image URL must be blank");

        echo "R142 passed: Variant images and gallery image-only rows correctly associated.\n";
    }

    /**
     * R143: Test >3 options maximum constraint, strict rejection vs lossy export report (R143, AC-T35-01).
     */
    public function testR143_more_than_three_options_loss(): void
    {
        echo "Testing R143 (more_than_three_options_loss)...\n";

        // Create a product with 4 options: Color, Size, Material, Fit
        $created = $this->productRepo->create([
            'title'  => 'T35 4-Option Technical Jacket',
            'slug'   => 't35-4opt-jacket',
            'type'   => 'variable',
            'status' => 'publish',
        ]);
        $pId = (int)$created['id'];
        $this->cleanProductIds[] = $pId;

        $opt1 = $this->optionsRepo->createOption($pId, ['name' => 'Color', 'key' => 'color', 'position' => 0]);
        $opt2 = $this->optionsRepo->createOption($pId, ['name' => 'Size', 'key' => 'size', 'position' => 1]);
        $opt3 = $this->optionsRepo->createOption($pId, ['name' => 'Material', 'key' => 'material', 'position' => 2]);
        $opt4 = $this->optionsRepo->createOption($pId, ['name' => 'Fit', 'key' => 'fit', 'position' => 3]);

        $v1_1 = $this->optionsRepo->createOptionValue((int)$opt1['id'], ['label' => 'Black', 'value_key' => 'black']);
        $v2_1 = $this->optionsRepo->createOptionValue((int)$opt2['id'], ['label' => 'L', 'value_key' => 'l']);
        $v3_1 = $this->optionsRepo->createOptionValue((int)$opt3['id'], ['label' => 'Gore-Tex', 'value_key' => 'gore-tex']);
        $v4_1 = $this->optionsRepo->createOptionValue((int)$opt4['id'], ['label' => 'Slim', 'value_key' => 'slim']);

        $this->productRepo->update($pId, [
            'variations' => [
                [
                    'sku'            => 'T35-JKT-4OPT-1',
                    'regular_price'  => 250.00,
                    'status'         => 'publish',
                    'attributes'     => ['Color' => 'Black', 'Size' => 'L', 'Material' => 'Gore-Tex', 'Fit' => 'Slim'],
                ],
            ],
        ]);

        $vId = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d LIMIT 1",
            $pId
        ));

        $this->optionsRepo->assignVariantCombination($pId, $vId, [
            (int)$opt1['id'] => (int)$v1_1['id'],
            (int)$opt2['id'] => (int)$v2_1['id'],
            (int)$opt3['id'] => (int)$v3_1['id'],
            (int)$opt4['id'] => (int)$v4_1['id'],
        ]);

        // 1. Strict mode: MUST throw StrictExportException with loss details (AC-T35-01)
        $strictFailed = false;
        try {
            $this->exporter->exportProducts([$pId], ['mode' => 'strict']);
        } catch (StrictExportException $ex) {
            $strictFailed = true;
            $losses = $ex->getLosses();
            $this->assert(!empty($losses), "Strict exception must contain losses array");
            $this->assert($losses[0]['field'] === 'more_than_three_options', "Field must be more_than_three_options");
            $this->assert(in_array('Fit', $losses[0]['value'], true), "Loss details must list option names including Fit");
        }
        $this->assert($strictFailed, "Strict mode must fail when product has >3 options (AC-T35-01)");

        // 2. Lossy mode: MUST succeed, output first 3 options in CSV, and record loss report (R143)
        $resLossy = $this->exporter->exportProducts([$pId], ['mode' => 'lossy']);
        $this->cleanFiles[] = $resLossy['file_path'];
        if ($resLossy['sidecar_path']) {
            $this->cleanFiles[] = $resLossy['sidecar_path'];
        }
        if ($resLossy['loss_report_path']) {
            $this->cleanFiles[] = $resLossy['loss_report_path'];
        }

        $this->assert($resLossy['status'] === 'completed', "Lossy export should complete");
        $this->assert(!empty($resLossy['losses']), "Losses must be reported in lossy mode");
        $this->assert($resLossy['loss_report_path'] !== null && file_exists($resLossy['loss_report_path']), "Loss report file must exist");

        // Verify CSV contents in lossy mode: only 3 options in row
        $rows = $this->readCsvRows($resLossy['file_path']);
        $header = $rows[0];
        $hMap = array_flip($header);
        $vRow = $rows[1];

        $this->assert($vRow[$hMap['Option1 name']] === 'Color', "Option 1 name Color");
        $this->assert($vRow[$hMap['Option1 value']] === 'Black', "Option 1 value Black");
        $this->assert($vRow[$hMap['Option2 name']] === 'Size', "Option 2 name Size");
        $this->assert($vRow[$hMap['Option2 value']] === 'L', "Option 2 value L");
        $this->assert($vRow[$hMap['Option3 name']] === 'Material', "Option 3 name Material");
        $this->assert($vRow[$hMap['Option3 value']] === 'Gore-Tex', "Option 3 value Gore-Tex");

        // Check sidecar JSON: contains ALL 4 options
        $this->assert($resLossy['sidecar_path'] !== null && file_exists($resLossy['sidecar_path']), "Sidecar archive exists");
        $sidecarJson = json_decode(file_get_contents($resLossy['sidecar_path']), true);
        $this->assert(isset($sidecarJson['products']['t35-4opt-jacket']['all_options']), "Sidecar contains all_options");
        $this->assert(count($sidecarJson['products']['t35-4opt-jacket']['all_options']) === 4, "Sidecar preserves all 4 options");

        echo "R143 passed: >3 options strictly fails with report; lossy mode exports 3 options and archives extra options.\n";
    }

    /**
     * R144: Test zero extra native columns in CSV and full sidecar preservation (R144, AC-T35-02).
     */
    public function testR144_no_extra_native_columns(): void
    {
        echo "Testing R144 (no_extra_native_columns)...\n";

        $created = $this->productRepo->create([
            'title'         => 'T35 Pure Product',
            'slug'          => 't35-pure-product',
            'type'          => 'simple',
            'status'        => 'publish',
            'sku'           => 'T35-PURE-01',
            'regular_price' => 29.99,
        ]);
        $pId = (int)$created['id'];
        $this->cleanProductIds[] = $pId;

        $res = $this->exporter->exportProducts([$pId], ['profile' => 'current', 'sidecar_archive' => true]);
        $this->cleanFiles[] = $res['file_path'];
        if ($res['sidecar_path']) {
            $this->cleanFiles[] = $res['sidecar_path'];
        }

        // 1. Inspect CSV headers
        $rows = $this->readCsvRows($res['file_path']);
        $headers = $rows[0];

        $disallowed = [
            'id', 'wpe_id', 'product_id', 'variant_id', 'post_id', 'version',
            'combination_key', 'extra_props', 'downloads', 'wpe_object_state'
        ];

        foreach ($headers as $col) {
            $lower = strtolower($col);
            $this->assert(!str_starts_with($lower, 'wpe_'), "Column {$col} must not start with wpe_");
            $this->assert(!in_array($lower, $disallowed, true), "Column {$col} is an internal engine field and must not be in CSV");
        }

        // 2. Inspect Sidecar JSON
        $this->assert($res['sidecar_path'] !== null, "Sidecar path must be set");
        $sidecar = json_decode(file_get_contents($res['sidecar_path']), true);
        $this->assert(isset($sidecar['products']['t35-pure-product']['id']), "Sidecar retains native product ID");
        $this->assert($sidecar['products']['t35-pure-product']['id'] === $pId, "Retained ID matches database ID");

        echo "R144 passed: No extra native columns injected into CSV; engine fields preserved in sidecar archive.\n";
    }

    /**
     * AC-T35-01: Schema snapshot Shopify profile validates; exact image/variant association; >3 options strict fails.
     */
    public function testAC_T35_01_schema_snapshot_and_image_association(): void
    {
        echo "Testing AC-T35-01 (schema_snapshot_and_image_association)...\n";

        // Read dialects.json contract
        $dialectsFile = dirname(__DIR__, 2) . '/contracts/csv/dialects.json';
        $this->assert(file_exists($dialectsFile), "dialects.json exists");
        $dialects = json_decode(file_get_contents($dialectsFile), true);
        $expectedCurrent = $dialects['shopify']['current_headers'];
        $expectedLegacyAliases = $dialects['shopify']['legacy_aliases'];

        // Verify CURRENT_HEADERS contains all current_headers from dialects.json
        foreach ($expectedCurrent as $reqH) {
            $this->assert(in_array($reqH, ShopifyExporter::CURRENT_HEADERS, true), "CURRENT_HEADERS must include dialect header {$reqH}");
        }

        // Verify LEGACY_HEADERS contains legacy aliases
        foreach ($expectedLegacyAliases as $legacyAlias => $canonical) {
            $this->assert(in_array($legacyAlias, ShopifyExporter::LEGACY_HEADERS, true), "LEGACY_HEADERS must include alias {$legacyAlias}");
        }

        echo "AC-T35-01 passed: Schema snapshot Shopify profiles match canonical dialect contract.\n";
    }

    /**
     * AC-T35-02: Subset declaration and no native ID preservation claims.
     */
    public function testAC_T35_02_subset_declaration_and_no_native_id_preservation(): void
    {
        echo "Testing AC-T35-02 (subset_declaration_and_no_native_id_preservation)...\n";

        $res = $this->exporter->exportProducts([], ['profile' => 'current', 'mode' => 'lossy']);
        if (!empty($res['file_path'])) {
            $this->cleanFiles[] = $res['file_path'];
        }
        if (!empty($res['sidecar_path'])) {
            $this->cleanFiles[] = $res['sidecar_path'];
        }
        if (!empty($res['loss_report_path'])) {
            $this->cleanFiles[] = $res['loss_report_path'];
        }
        $decl = $res['subset_declaration'];

        $this->assert($decl !== null, "Subset declaration must be present in export result");
        $this->assert($decl['format'] === 'shopify', "Format is shopify");
        $this->assert($decl['preserves_native_ids'] === false, "Must declare preserves_native_ids is FALSE");
        $this->assert(isset($decl['lossy_unsupported_keys']['internal_ids']), "Must declare internal_ids loss");

        echo "AC-T35-02 passed: Subset declaration explicitly notes non-preservation of native variant IDs in Shopify CSV.\n";
    }
}

$test = new T35Test();
$test->run();
