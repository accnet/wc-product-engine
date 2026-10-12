<?php
/**
 * T33 Integration Test — Woo export adapter và round-trip.
 *
 * Verifies:
 * - R132 (woo_export_into_baseline): Products exported by WooExporter adhere to native WooCommerce baseline CSV format and columns.
 * - R133 (woo_csv_roundtrip_subset): Engine product -> export Woo CSV -> import via WooImporter produces equivalent subset (AC-T33-01).
 * - R134 (native_exporter_hooks): WooExporter registers hooks (woocommerce_product_export_row_data, etc.) adapting native WC CSV exporter.
 * - R135 (image_url_order_and_loss): Featured first, gallery next; variation primary per row; multi-gallery logged in loss report without illegal columns.
 * - AC-T33-01: Engine -> Woo -> engine achieves subset equality and identity preservation.
 * - AC-T33-02: CSV import rejects arbitrary new numeric ID injection; preserves existing database IDs.
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

use WPE\Compatibility\Csv\Woo\WooExporter;
use WPE\Compatibility\Csv\Woo\WooImporter;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\Gallery\UrlValidator;

class T33Test
{
    private \wpdb $wpdb;
    private WooExporter $exporter;
    private WooImporter $importer;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;

    private array $cleanProductIds = [];
    private array $cleanFiles = [];
    private array $cleanAssetIds = [];
    private array $cleanAttachmentIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->productRepo      = new ProductRepository($this->wpdb);
        $this->optionsRepo      = new OptionsRepository($this->wpdb);
        $this->assetService     = new AssetService($this->wpdb);
        $this->attachmentBridge = AttachmentBridge::instance($this->wpdb);
        $this->exporter         = new WooExporter($this->wpdb, $this->productRepo, $this->optionsRepo, $this->assetService, $this->attachmentBridge);
        $this->importer         = new WooImporter($this->wpdb, $this->productRepo, $this->optionsRepo, $this->assetService, $this->attachmentBridge);

        wp_set_current_user(1);
    }

    public function run(): void
    {
        echo "Running T33 Integration Tests...\n";
        $this->tearDown();
        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        try {
            $this->testR132_woo_export_into_baseline();
            $this->testR133_woo_csv_roundtrip_subset();
            $this->testR134_native_exporter_hooks();
            $this->testR135_image_url_order_and_loss();
            $this->testAC_T33_02_no_arbitrary_numeric_id_injection();
            echo "All T33 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    private function tearDown(): void
    {
        // Clean products, variants, options, and shells
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

        // Global safeguard purge for T33 SKUs
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T33-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_products WHERE sku LIKE 'T33-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_variants WHERE sku LIKE 'T33-%'");

        // Clean test attachments
        foreach ($this->cleanAttachmentIds as $attId) {
            wp_delete_attachment($attId, true);
        }

        // Clean test assets
        foreach ($this->cleanAssetIds as $aId) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_assets', ['id' => $aId]);
        }

        // Clean test CSV files
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

    /**
     * R132: Test export into baseline WooCommerce CSV schema and column structure.
     */
    public function testR132_woo_export_into_baseline(): void
    {
        echo "Testing R132 (woo_export_into_baseline)...\n";

        // 1. Create a simple product and a variable product
        $simple = $this->productRepo->create([
            'title'          => 'T33 Baseline Export Simple',
            'sku'            => 'T33-EXP-SIMPLE',
            'type'           => 'simple',
            'status'         => 'publish',
            'regular_price'  => '49.99',
            'sale_price'     => '39.99',
            'manage_stock'   => 1,
            'stock_quantity' => 25,
            'description'    => 'A great test jacket',
        ]);
        $simpleId = (int)$simple['id'];
        $this->cleanProductIds[] = $simpleId;

        // 2. Export only this product
        $tmpCsv = tempnam(sys_get_temp_dir(), 'wpe_t33_r132_') . '.csv';
        $this->cleanFiles[] = $tmpCsv;

        $exportResult = $this->exporter->exportToCsv($tmpCsv, [
            'product_ids' => [$simpleId],
            'include_ids' => true,
        ]);

        $this->assert($exportResult['status'] === 'completed', "Export should succeed");
        $this->assert($exportResult['total_products'] === 1, "Should export 1 product");
        $this->assert($exportResult['total_rows'] === 1, "Should export 1 row");

        // 3. Inspect CSV columns and content
        $fp = fopen($tmpCsv, 'rb');
        $headers = fgetcsv($fp);
        $dataRow = fgetcsv($fp);
        fclose($fp);

        // Check standard columns presence
        foreach (WooExporter::BASELINE_COLUMNS as $col) {
            $this->assert(in_array($col, $headers, true), "Column {$col} must exist in exported CSV");
        }

        // Map row to associative array
        $rowMap = array_combine($headers, $dataRow);
        $this->assert($rowMap['ID'] === (string)$simpleId, "ID must match");
        $this->assert($rowMap['Type'] === 'simple', "Type must be simple");
        $this->assert($rowMap['SKU'] === 'T33-EXP-SIMPLE', "SKU must match");
        $this->assert($rowMap['Name'] === 'T33 Baseline Export Simple', "Name must match");
        $this->assert($rowMap['Published'] === '1', "Published must be 1");
        $this->assert((float)$rowMap['Regular price'] === 49.99, "Regular price must be 49.99");
        $this->assert((float)$rowMap['Sale price'] === 39.99, "Sale price must be 39.99");
        $this->assert($rowMap['Stock'] === '25', "Stock must be 25");
        $this->assert($rowMap['In stock?'] === '1', "In stock must be 1");

        echo "R132 passed: Woo export generates valid baseline WooCommerce CSV.\n";
    }

    /**
     * R133: Test engine -> Woo export -> Woo import round-trip subset equality (AC-T33-01).
     */
    public function testR133_woo_csv_roundtrip_subset(): void
    {
        echo "Testing R133 (woo_csv_roundtrip_subset)...\n";

        // 1. Create variable product with 2 variations and options
        $varParent = $this->productRepo->create([
            'title'          => 'T33 Roundtrip Hoodie',
            'sku'            => 'T33-RT-VAR',
            'type'           => 'variable',
            'status'         => 'publish',
            'regular_price'  => '70.00',
            'description'    => 'Cozy hoodie for roundtrip test',
        ]);
        $parentId = (int)$varParent['id'];
        $this->cleanProductIds[] = $parentId;

        // Create option 'Color'
        $opt = $this->optionsRepo->createOption($parentId, [
            'name'     => 'Color',
            'key'      => 'color',
            'position' => 0,
        ]);
        $valRed = $this->optionsRepo->createOptionValue((int)$opt['id'], ['label' => 'Red', 'value_key' => 'red', 'position' => 0]);
        $valBlue = $this->optionsRepo->createOptionValue((int)$opt['id'], ['label' => 'Blue', 'value_key' => 'blue', 'position' => 1]);

        // Add 2 variations
        $this->productRepo->update($parentId, [
            'variations' => [
                [
                    'sku'            => 'T33-RT-VAR-RED',
                    'regular_price'  => '70.00',
                    'sale_price'     => '60.00',
                    'manage_stock'   => 1,
                    'stock_quantity' => 15,
                    'status'         => 'publish',
                    'attributes'     => ['Color' => 'Red'],
                ],
                [
                    'sku'            => 'T33-RT-VAR-BLUE',
                    'regular_price'  => '75.00',
                    'manage_stock'   => 1,
                    'stock_quantity' => 8,
                    'status'         => 'publish',
                    'attributes'     => ['Color' => 'Blue'],
                ],
            ],
        ]);

        // Link variant option values combination
        $variants = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, sku FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d",
            $parentId
        ), ARRAY_A);

        foreach ($variants as $vr) {
            $colorVal = ($vr['sku'] === 'T33-RT-VAR-RED') ? (int)$valRed['id'] : (int)$valBlue['id'];
            $this->optionsRepo->assignVariantCombination($parentId, (int)$vr['id'], [(int)$opt['id'] => $colorVal]);
        }

        // 2. Export product to CSV
        $exportFile = tempnam(sys_get_temp_dir(), 'wpe_t33_r133_export_') . '.csv';
        $this->cleanFiles[] = $exportFile;

        $exportResult = $this->exporter->exportToCsv($exportFile, [
            'product_ids' => [$parentId],
            'include_ids' => true,
        ]);

        $this->assert($exportResult['status'] === 'completed', "Export should succeed");
        $this->assert($exportResult['total_rows'] === 3, "Should export 3 rows (1 parent + 2 variations)");

        // 3. Purge existing product from database before import to simulate cross-session round-trip
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_variant_values', ['variant_id' => (int)$variants[0]['id']]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_variant_values', ['variant_id' => (int)$variants[1]['id']]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_variants', ['product_id' => $parentId]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_inventory', ['owner_id' => (int)$variants[0]['id']]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_inventory', ['owner_id' => (int)$variants[1]['id']]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_inventory', ['owner_id' => $parentId]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_option_values', ['option_id' => (int)$opt['id']]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_options', ['product_id' => $parentId]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_products', ['id' => $parentId]);
        $this->wpdb->delete($this->wpdb->prefix . 'wpe_sku_registry', ['object_id' => $parentId]);
        foreach ($variants as $vr) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_sku_registry', ['object_id' => (int)$vr['id']]);
        }
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T33-RT%'");

        // 4. Import the exported CSV back using WooImporter
        $importResult = $this->importer->importFromCsv($exportFile, ['image_mode' => 'external']);
        $this->assert($importResult['status'] === 'completed', "Roundtrip import should succeed: " . json_encode($importResult['errors']));

        // 5. Verify reconstructed product matches canonical subset equality (AC-T33-01)
        $reimportedParent = $this->productRepo->getBySku('T33-RT-VAR');
        $this->assert($reimportedParent !== null, "Parent product must be restored by SKU");
        $this->cleanProductIds[] = (int)$reimportedParent['id'];

        $this->assert($reimportedParent['type'] === 'variable', "Restored type must be variable");
        $this->assert($reimportedParent['name'] === 'T33 Roundtrip Hoodie', "Title must match");
        $this->assert(count($reimportedParent['variations']) === 2, "Must restore exactly 2 variations");

        $vSkus = array_column($reimportedParent['variations'], 'sku');
        $this->assert(in_array('T33-RT-VAR-RED', $vSkus, true), "Variation RED must exist");
        $this->assert(in_array('T33-RT-VAR-BLUE', $vSkus, true), "Variation BLUE must exist");

        // Check variation details
        $redVar = null;
        $blueVar = null;
        foreach ($reimportedParent['variations'] as $v) {
            if ($v['sku'] === 'T33-RT-VAR-RED') $redVar = $v;
            if ($v['sku'] === 'T33-RT-VAR-BLUE') $blueVar = $v;
        }

        $this->assert((float)$redVar['sale_price'] === 60.0, "RED sale price must be 60");
        $this->assert((float)$redVar['regular_price'] === 70.0, "RED regular price must be 70");
        $this->assert((float)$redVar['stock_quantity'] === 15.0, "RED stock must be 15");

        $this->assert((float)$blueVar['regular_price'] === 75.0, "BLUE regular price must be 75");
        $this->assert((float)$blueVar['stock_quantity'] === 8.0, "BLUE stock must be 8");

        echo "R133 passed: Engine -> Woo export -> Woo import round-trip subset equality verified.\n";
    }

    /**
     * R134: Test integration hooks with native WooCommerce CSV Exporter.
     */
    public function testR134_native_exporter_hooks(): void
    {
        echo "Testing R134 (native_exporter_hooks)...\n";

        // Register exporter hooks
        $this->exporter->registerHooks();

        // 1. Create a product with external image in wpe_product_media
        $product = $this->productRepo->create([
            'title'         => 'T34 Native Hook Product',
            'sku'           => 'T33-HOOK-PROD',
            'type'          => 'simple',
            'status'        => 'publish',
            'regular_price' => '35.00',
        ]);
        $productId = (int)$product['id'];
        $this->cleanProductIds[] = $productId;

        $testCdnUrl = 'https://cdn.example.com/images/t33-native-hook.jpg';
        $asset = $this->assetService->registerAsset($testCdnUrl);
        $this->cleanAssetIds[] = (int)$asset['id'];
        $this->assetService->attachProductImage($productId, (int)$asset['id'], 'featured', 0);

        // 2. Mock a native WC_Product object for this ID
        $wcProduct = wc_get_product($productId);
        if (!$wcProduct) {
            // Allocate a dummy post shell if not yet created
            $shellId = wp_insert_post([
                'import_id'   => $productId,
                'post_type'   => 'product',
                'post_status' => 'publish',
                'post_title'  => 'T34 Native Hook Product',
            ]);
            $this->cleanProductIds[] = $shellId;
            $wcProduct = wc_get_product($shellId);
        }

        // 3. Test filterNativeExportRowData hook
        $mockRow = [
            'id'     => $productId,
            'type'   => 'simple',
            'sku'    => 'T33-HOOK-PROD',
            'name'   => 'T34 Native Hook Product',
            'images' => '',
        ];

        $filteredRow = $this->exporter->filterNativeExportRowData($mockRow, $wcProduct);
        $this->assert(!empty($filteredRow['images']), "Filtered row images must not be empty");
        $this->assert(str_contains($filteredRow['images'], $testCdnUrl), "Filtered images must contain CDN URL");

        // 4. Test filterNativeImagesColumn hook
        $filteredImages = $this->exporter->filterNativeImagesColumn('', $wcProduct);
        $this->assert(str_contains($filteredImages, $testCdnUrl), "Images column filter must return CDN URL");

        // 5. Test filterNativeSkipMetaKeys hook
        $skippedKeys = $this->exporter->filterNativeSkipMetaKeys([], $wcProduct);
        $this->assert(in_array('_wpe_object_state', $skippedKeys, true), "WPE internal meta keys must be skipped");
        $this->assert(in_array('_wpe_version', $skippedKeys, true), "WPE version must be skipped");

        echo "R134 passed: Native WooCommerce CSV Exporter hooks successfully registered and verified.\n";
    }

    /**
     * R135: Test image URL ordering (featured first, gallery next) and multi-gallery variation loss report.
     */
    public function testR135_image_url_order_and_loss(): void
    {
        echo "Testing R135 (image_url_order_and_loss)...\n";

        // 1. Create parent product with featured and gallery images
        $parent = $this->productRepo->create([
            'title'         => 'T33 Multi Image Product',
            'sku'           => 'T33-IMG-PARENT',
            'type'          => 'variable',
            'status'        => 'publish',
            'regular_price' => '100.00',
        ]);
        $parentId = (int)$parent['id'];
        $this->cleanProductIds[] = $parentId;

        $featUrl = 'https://cdn.example.com/assets/feat.jpg';
        $galUrl1 = 'https://cdn.example.com/assets/gal1.jpg';
        $galUrl2 = 'https://cdn.example.com/assets/gal2.jpg';

        $aFeat = $this->assetService->registerAsset($featUrl);
        $aGal1 = $this->assetService->registerAsset($galUrl1);
        $aGal2 = $this->assetService->registerAsset($galUrl2);
        $this->cleanAssetIds[] = (int)$aFeat['id'];
        $this->cleanAssetIds[] = (int)$aGal1['id'];
        $this->cleanAssetIds[] = (int)$aGal2['id'];

        $this->assetService->attachProductImage($parentId, (int)$aFeat['id'], 'featured', 0);
        $this->assetService->attachProductImage($parentId, (int)$aGal1['id'], 'gallery', 1);
        $this->assetService->attachProductImage($parentId, (int)$aGal2['id'], 'gallery', 2);

        // 2. Add variation with MULTI-GALLERY images (primary + 1 extra gallery image)
        $this->productRepo->update($parentId, [
            'variations' => [
                [
                    'sku'           => 'T33-IMG-VAR-MULTI',
                    'regular_price' => '100.00',
                    'status'        => 'publish',
                ],
            ],
        ]);

        $varRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d AND sku = %s",
            $parentId,
            'T33-IMG-VAR-MULTI'
        ), ARRAY_A);
        $variantId = (int)$varRow['id'];

        $varPrimaryUrl = 'https://cdn.example.com/assets/var-primary.jpg';
        $varExtraUrl   = 'https://cdn.example.com/assets/var-extra.jpg';
        $aVar1 = $this->assetService->registerAsset($varPrimaryUrl);
        $aVar2 = $this->assetService->registerAsset($varExtraUrl);
        $this->cleanAssetIds[] = (int)$aVar1['id'];
        $this->cleanAssetIds[] = (int)$aVar2['id'];

        // Variation primary image
        $this->assetService->attachVariantImage($variantId, (int)$aVar1['id'], 0, true);
        // Variation secondary gallery image (unsupported by native Woo CSV!)
        $this->assetService->attachVariantImage($variantId, (int)$aVar2['id'], 1, false);

        // 3. Export to CSV
        $tmpCsv = tempnam(sys_get_temp_dir(), 'wpe_t33_r135_') . '.csv';
        $this->cleanFiles[] = $tmpCsv;

        $result = $this->exporter->exportToCsv($tmpCsv, [
            'product_ids' => [$parentId],
        ]);

        $this->assert($result['status'] === 'completed', "Export should succeed");
        $this->assert($result['loss_count'] >= 1, "Loss report must capture multi-gallery variation image");
        $this->cleanFiles[] = $result['loss_report_path'];

        // 4. Verify parent image order in CSV: featUrl must be first, followed by galUrl1 and galUrl2
        $fp = fopen($tmpCsv, 'rb');
        $headers = fgetcsv($fp);
        $parentData = fgetcsv($fp);
        $variantData = fgetcsv($fp);
        fclose($fp);

        $parentMap = array_combine($headers, $parentData);
        $variantMap = array_combine($headers, $variantData);

        // Parent images ordering (R135)
        $expectedParentImages = "{$featUrl}, {$galUrl1}, {$galUrl2}";
        $this->assert($parentMap['Images'] === $expectedParentImages, "Parent images must follow featured first, then gallery order");

        // Variation primary image placed in Images column, extra image NOT creating illegal columns (R135)
        $this->assert($variantMap['Images'] === $varPrimaryUrl, "Variation row must only contain primary image in native Images column");
        $this->assert(!in_array('Variant Gallery', $headers, true), "CSV must NOT contain illegal custom columns");

        // Loss report inspection
        $lossReport = $result['loss_report'];
        $foundMultiGalleryLoss = false;
        foreach ($lossReport as $entry) {
            if ($entry['field'] === 'variant_multi_gallery') {
                $foundMultiGalleryLoss = true;
                $this->assert(in_array($varExtraUrl, $entry['value'], true), "Loss report must record extra variation image URL");
            }
        }
        $this->assert($foundMultiGalleryLoss, "Loss report must have recorded variant_multi_gallery");

        echo "R135 passed: Image URL ordering and variation multi-gallery loss report verified.\n";
    }

    /**
     * AC-T33-02: Test that import rejects arbitrary new numeric ID injection.
     */
    public function testAC_T33_02_no_arbitrary_numeric_id_injection(): void
    {
        echo "Testing AC-T33-02 (no_arbitrary_numeric_id_injection)...\n";

        // Attempt to import a CSV row specifying an arbitrary non-existent ID 999999
        $arbitraryId = 999999;
        $csvContent = "ID,Type,SKU,Name,Regular price\n" .
            "{$arbitraryId},simple,T33-ARBITRARY-ID,Arbitrary ID Product,19.99\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t33_ac02_') . '.csv';
        file_put_contents($tmpFile, $csvContent);
        $this->cleanFiles[] = $tmpFile;

        $result = $this->importer->importFromCsv($tmpFile);
        $this->assert($result['status'] === 'completed', "Import should complete");

        // Verify product was created, but NOT with arbitrary ID 999999
        $product = $this->productRepo->getBySku('T33-ARBITRARY-ID');
        $this->assert($product !== null, "Product should be created");
        $this->cleanProductIds[] = (int)$product['id'];

        $this->assert((int)$product['id'] !== $arbitraryId, "Engine must NOT adopt arbitrary new numeric ID 999999 from CSV (AC-T33-02)");

        echo "AC-T33-02 passed: Arbitrary numeric ID injection strictly avoided; system allocates authoritative shell ID.\n";
    }
}

$test = new T33Test();
$test->run();
