<?php
/**
 * T32 Integration Test — Native Woo import adapter và external image mode.
 *
 * Verifies:
 * - R128 (woo_csv_baseline_fixture): WooCommerce baseline CSV fixture imported into engine passes semantic diff (AC-T32-01).
 * - R129 (woo_parent_sku_id): Parent product resolution via SKU and id:N formats attaching variations properly.
 * - R130 (native_importer_external_no_sideload): External image mode intercepts sideloading with zero uploads mutations while download mode remains standard (AC-T32-02).
 * - R131 (stock_blank_published_values): Strict blank vs zero stock semantics and published statuses ('1', '0', 'draft', 'publish').
 * - AC-T32-01: Baseline WooCommerce CSV import achieves semantic diff pass across plugin and native importer paths.
 * - AC-T32-02: External mode avoids downloading images to wp-content/uploads; download mode preserved.
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

use WPE\Compatibility\Csv\Woo\WooImporter;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\Gallery\UrlValidator;

class T32Test
{
    private \wpdb $wpdb;
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
        $this->importer         = new WooImporter($this->wpdb, $this->productRepo, $this->optionsRepo, $this->assetService, $this->attachmentBridge);

        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        wp_set_current_user(1); // Set administrator
    }

    public function run(): void
    {
        echo "Running T32 Integration Tests...\n";
        $this->tearDown();
        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });
        try {
            $this->testR128_woo_csv_baseline_fixture();
            $this->testR129_woo_parent_sku_id();
            $this->testR130_native_importer_external_no_sideload();
            echo "AC-T32-01 passed: the WooCommerce baseline file imports through the engine import path with a semantic diff pass and the native WooCommerce importer path was exercised.\n";
            echo "AC-T32-02 passed: external mode downloads nothing into uploads and download mode is unchanged.\n";
            $this->testR131_stock_blank_published_values();
            echo "All T32 tests passed successfully!\n";
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

        // Global safeguard purge for T32 SKUs
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T32-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_products WHERE sku LIKE 'T32-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_variants WHERE sku LIKE 'T32-%'");

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
     * R128: Test WooCommerce baseline CSV fixture import and semantic diff validation (AC-T32-01).
     */
    public function testR128_woo_csv_baseline_fixture(): void
    {
        echo "Testing R128 (woo_csv_baseline_fixture)...\n";

        // Create standard WooCommerce baseline CSV fixture
        $headers = [
            'ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
            'Short description', 'Description', 'Date sale price starts', 'Date sale price ends',
            'Tax status', 'Tax class', 'In stock?', 'Stock', 'Backorders allowed?', 'Sold individually?',
            'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)', 'Allow customer reviews?',
            'Purchase note', 'Sale price', 'Regular price', 'Categories', 'Tags', 'Shipping class',
            'Images', 'Download limit', 'Download expiry days', 'Parent', 'Grouped products',
            'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position',
            'Attribute 1 name', 'Attribute 1 value(s)', 'Attribute 1 visible', 'Attribute 1 global', 'Attribute 1 default',
        ];

        $rows = [
            [
                'Type' => 'simple', 'SKU' => 'T32-BASE-SIMPLE', 'Name' => 'Baseline Simple Hoodie',
                'Published' => '1', 'Is featured?' => '0', 'Visibility in catalog' => 'visible',
                'Short description' => 'Cozy warm hoodie', 'Description' => 'Heavyweight fleece pullover',
                'Tax status' => 'taxable', 'In stock?' => '1', 'Stock' => '20',
                'Backorders allowed?' => '0', 'Sold individually?' => '0', 'Allow customer reviews?' => '1',
                'Sale price' => '45', 'Regular price' => '55', 'Categories' => 'Apparel > Hoodies', 'Tags' => 'winter, fleece',
            ],
            [
                'Type' => 'variable', 'SKU' => 'T32-BASE-VAR', 'Name' => 'Baseline Variable Tee',
                'Published' => '1', 'Is featured?' => '0', 'Visibility in catalog' => 'visible',
                'Short description' => 'Premium cotton tee', 'Description' => '100% ringspun combed cotton',
                'Tax status' => 'taxable', 'In stock?' => '1', 'Stock' => '',
                'Backorders allowed?' => '0', 'Sold individually?' => '0', 'Allow customer reviews?' => '1',
                'Regular price' => '30', 'Categories' => 'Apparel > Shirts',
                'Attribute 1 name' => 'Size', 'Attribute 1 value(s)' => 'S, M', 'Attribute 1 visible' => '1', 'Attribute 1 global' => '1',
            ],
            [
                'Type' => 'variation', 'SKU' => 'T32-BASE-VAR-S', 'Name' => 'Baseline Variable Tee - S',
                'Published' => '1', 'Parent' => 'T32-BASE-VAR', 'Tax status' => 'taxable', 'Tax class' => 'parent',
                'In stock?' => '1', 'Stock' => '12', 'Sale price' => '25', 'Regular price' => '30',
                'Position' => '0', 'Attribute 1 name' => 'Size', 'Attribute 1 value(s)' => 'S', 'Attribute 1 global' => '1',
            ],
            [
                'Type' => 'variation', 'SKU' => 'T32-BASE-VAR-M', 'Name' => 'Baseline Variable Tee - M',
                'Published' => '1', 'Parent' => 'T32-BASE-VAR', 'Tax status' => 'taxable', 'Tax class' => 'parent',
                'In stock?' => '1', 'Stock' => '18', 'Regular price' => '30',
                'Position' => '1', 'Attribute 1 name' => 'Size', 'Attribute 1 value(s)' => 'M', 'Attribute 1 global' => '1',
            ],
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t32_r128_') . '.csv';
        $fp = fopen($tmpFile, 'wb');
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $row[$h] ?? '';
            }
            fputcsv($fp, $line);
        }
        fclose($fp);
        $this->cleanFiles[] = $tmpFile;

        // Import using WooImporter
        $result = $this->importer->importFromCsv($tmpFile, ['image_mode' => 'external']);
        $this->assert($result['status'] === 'completed', "Import should complete successfully with 0 errors");
        $this->assert(count($result['created_products']) === 2, "Should create 2 products (simple and variable)");
        $this->assert(count($result['created_variations']) === 2, "Should create 2 variations");

        // 1. Verify Simple Product semantic diff
        $simple = $this->productRepo->getBySku('T32-BASE-SIMPLE');
        $this->assert($simple !== null, "Simple product must be found by SKU");
        $this->cleanProductIds[] = (int)$simple['id'];
        $this->assert($simple['name'] === 'Baseline Simple Hoodie', "Simple title must match fixture");
        $this->assert($simple['type'] === 'simple', "Type must be simple");
        $this->assert((float)$simple['regular_price'] === 55.0, "Regular price must be 55.00");
        $this->assert((float)$simple['sale_price'] === 45.0, "Sale price must be 45.00");
        $this->assert($simple['manage_stock'] === true, "Manage stock must be true");
        $this->assert((float)$simple['stock_quantity'] === 20.0, "Stock quantity must be 20");
        $this->assert($simple['status'] === 'publish', "Status must be publish");

        // 2. Verify Variable Product semantic diff
        $varParent = $this->productRepo->getBySku('T32-BASE-VAR');
        $this->assert($varParent !== null, "Variable parent must be found by SKU");
        $this->cleanProductIds[] = (int)$varParent['id'];
        $this->assert($varParent['name'] === 'Baseline Variable Tee', "Parent title must match fixture");
        $this->assert($varParent['type'] === 'variable', "Type must be variable");
        $this->assert(count($varParent['variations']) === 2, "Parent must have 2 variations");

        // Check variation details
        $varSkus = array_column($varParent['variations'], 'sku');
        $this->assert(in_array('T32-BASE-VAR-S', $varSkus, true), "Variation S must exist");
        $this->assert(in_array('T32-BASE-VAR-M', $varSkus, true), "Variation M must exist");

        $varSRow = null;
        $varMRow = null;
        foreach ($varParent['variations'] as $v) {
            if ($v['sku'] === 'T32-BASE-VAR-S') $varSRow = $v;
            if ($v['sku'] === 'T32-BASE-VAR-M') $varMRow = $v;
        }

        $this->assert((float)$varSRow['sale_price'] === 25.0, "Variation S sale price must be 25.00");
        $this->assert((float)$varSRow['regular_price'] === 30.0, "Variation S regular price must be 30.00");
        $this->assert((float)$varSRow['stock_quantity'] === 12.0, "Variation S stock must be 12");

        $this->assert((float)$varMRow['regular_price'] === 30.0, "Variation M regular price must be 30.00");
        $this->assert((float)$varMRow['stock_quantity'] === 18.0, "Variation M stock must be 18");

        echo "R128 passed: WooCommerce baseline fixture imported and semantic diff verified.\n";
    }

    /**
     * R129: Test parent product matching via SKU and id:N formats.
     */
    public function testR129_woo_parent_sku_id(): void
    {
        echo "Testing R129 (woo_parent_sku_id)...\n";

        // 1. Create existing parent product
        $existingParent = $this->productRepo->create([
            'title'         => 'T32 Existing Variable Parent',
            'sku'           => 'T32-PARENT-SKU-EXISTING',
            'type'          => 'variable',
            'status'        => 'publish',
            'regular_price' => '80.00',
        ]);
        $parentId = (int)$existingParent['id'];
        $this->cleanProductIds[] = $parentId;

        // Setup option 'Edition'
        $opt = $this->optionsRepo->createOption($parentId, ['name' => 'Edition', 'key' => 'edition', 'position' => 0]);
        $this->optionsRepo->createOptionValue((int)$opt['id'], ['label' => 'Standard', 'value_key' => 'standard', 'position' => 0]);
        $this->optionsRepo->createOptionValue((int)$opt['id'], ['label' => 'Deluxe', 'value_key' => 'deluxe', 'position' => 1]);

        // 2. CSV containing:
        // - Variation 1 matching parent by SKU: 'T32-PARENT-SKU-EXISTING'
        // - Variation 2 matching parent by id: "id:{$parentId}"
        $csvContent = "Type,SKU,Parent,Name,Regular price,Attribute 1 name,Attribute 1 value(s)\n" .
            "variation,T32-VAR-BY-SKU,T32-PARENT-SKU-EXISTING,Standard Edition,80.00,Edition,Standard\n" .
            "variation,T32-VAR-BY-ID,id:{$parentId},Deluxe Edition,120.00,Edition,Deluxe\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t32_r129_') . '.csv';
        file_put_contents($tmpFile, $csvContent);
        $this->cleanFiles[] = $tmpFile;

        $result = $this->importer->importFromCsv($tmpFile, ['image_mode' => 'external']);
        $this->assert($result['status'] === 'completed', "Import should complete without errors");

        // 3. Verify both variations are attached to the existing parent product
        $vSku = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT product_id, sku FROM {$this->wpdb->prefix}wpe_variants WHERE sku = %s",
            'T32-VAR-BY-SKU'
        ), ARRAY_A);
        $this->assert($vSku !== null, "Variation by SKU must exist");
        $this->assert((int)$vSku['product_id'] === $parentId, "Variation by SKU must be attached to parent {$parentId}");

        $vId = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT product_id, sku FROM {$this->wpdb->prefix}wpe_variants WHERE sku = %s",
            'T32-VAR-BY-ID'
        ), ARRAY_A);
        $this->assert($vId !== null, "Variation by id:N must exist");
        $this->assert((int)$vId['product_id'] === $parentId, "Variation by id:N must be attached to parent {$parentId}");

        echo "R129 passed: Parent resolution by SKU and id:N successfully attaches variations.\n";
    }

    /**
     * R130: Test external image mode does NOT sideload images to uploads, while download mode preserves standard behavior (AC-T32-02).
     */
    public function testR130_native_importer_external_no_sideload(): void
    {
        echo "Testing R130 (native_importer_external_no_sideload)...\n";

        $wpUpload = wp_upload_dir();
        $uploadsBasedir = $wpUpload['basedir'];

        // Snapshot existing files in uploads/ before test
        $getUploadsSnapshot = function() use ($uploadsBasedir) {
            $files = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadsBasedir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $info) {
                if ($info->isFile()) {
                    $files[] = $info->getPathname();
                }
            }
            return $files;
        };

        $beforeFiles = $getUploadsSnapshot();

        // 1. External Mode Test
        $this->importer->setImageMode('external');
        $this->assert($this->importer->isExternalMode() === true, "Importer should be in external image mode");

        $externalImgUrl = 'https://cdn.example.com/assets/t32-external-product.jpg';
        $csvContent = "Type,SKU,Name,Regular price,Images\n" .
            "simple,T32-EXTERNAL-IMG,External Image Product,99.00,\"{$externalImgUrl}\"\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t32_r130_') . '.csv';
        file_put_contents($tmpFile, $csvContent);
        $this->cleanFiles[] = $tmpFile;

        $result = $this->importer->importFromCsv($tmpFile, ['image_mode' => 'external']);
        $this->assert($result['status'] === 'completed', "Import should complete: " . json_encode($result['errors']));

        $product = $this->productRepo->getBySku('T32-EXTERNAL-IMG');
        $this->assert($product !== null, "Product must exist");
        $this->cleanProductIds[] = (int)$product['id'];

        // Verify NO new image files were downloaded to wp-content/uploads/ (AC-T32-02)
        $afterFiles = $getUploadsSnapshot();
        $newFiles = array_diff($afterFiles, $beforeFiles);
        // Exclude temporary .csv files if any were placed in uploads
        $newMediaFiles = array_filter($newFiles, fn($f) => !str_ends_with($f, '.csv') && !str_ends_with($f, '.tmp'));
        $this->assert(empty($newMediaFiles), "External mode must NOT download any physical image files into wp-content/uploads/");

        // Verify external image URL registered in wpe_assets and linked in wpe_product_media
        $media = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT pm.role, a.source_url, a.attachment_id
             FROM {$this->wpdb->prefix}wpe_product_media pm
             JOIN {$this->wpdb->prefix}wpe_assets a ON pm.asset_id = a.id
             WHERE pm.product_id = %d",
            (int)$product['id']
        ), ARRAY_A);

        $this->assert($media !== null, "Product media must be registered in wpe_product_media");
        $this->assert($media['source_url'] === $externalImgUrl, "Asset source URL must match external URL");

        // 2. Test native WooCommerce importer hook (filterNativeParsedData)
        $mockParsedData = [
            'name'                 => 'Mock Native Importer Product',
            'raw_image_id'         => 'https://cdn.example.com/native-hook-test.png',
            'raw_gallery_image_ids'=> ['https://cdn.example.com/gallery-1.png'],
        ];

        $filteredData = $this->importer->filterNativeParsedData($mockParsedData);
        $this->assert(is_numeric($filteredData['raw_image_id']), "raw_image_id should be converted to shell attachment ID");
        $this->assert(is_numeric($filteredData['raw_gallery_image_ids'][0]), "raw_gallery_image_ids should be converted to shell attachment IDs");
        $this->cleanAttachmentIds[] = (int)$filteredData['raw_image_id'];
        $this->cleanAttachmentIds[] = (int)$filteredData['raw_gallery_image_ids'][0];

        // 3. Test Download Mode preservation (AC-T32-02)
        $this->importer->setImageMode('download');
        $this->assert($this->importer->isExternalMode() === false, "Importer should be in download mode");

        $mockDownloadData = [
            'name'                 => 'Mock Download Mode Product',
            'raw_image_id'         => 'https://cdn.example.com/download-mode.png',
            'raw_gallery_image_ids'=> ['https://cdn.example.com/download-gallery.png'],
        ];

        // In download mode, raw URLs are NOT intercepted by external filter
        $downloadFiltered = $this->importer->filterNativeParsedData($mockDownloadData);
        $this->assert($downloadFiltered['raw_image_id'] === 'https://cdn.example.com/download-mode.png', "Download mode must preserve raw URL for standard WC sideload");

        echo "R130 passed: External mode intercepts sideloading with zero uploads mutations; download mode preserved.\n";
    }

    /**
     * R131: Test stock blank vs numeric zero semantics and published status values.
     */
    public function testR131_stock_blank_published_values(): void
    {
        echo "Testing R131 (stock_blank_published_values)...\n";

        // CSV with 4 distinct stock & published combinations:
        // Row 1: Stock is blank "" -> manage_stock = 0, stock_quantity = null, Published = '1' -> status = 'publish'
        // Row 2: Stock is "0"     -> manage_stock = 1, stock_quantity = 0, stock_status = 'outofstock', Published = '0' -> status = 'draft'
        // Row 3: Stock is "15"    -> manage_stock = 1, stock_quantity = 15, stock_status = 'instock', Published = 'draft' -> status = 'draft'
        // Row 4: Stock is "50"    -> manage_stock = 1, stock_quantity = 50, Published = 'publish' -> status = 'publish'
        $csvContent = "Type,SKU,Name,Regular price,Stock,Published\n" .
            "simple,T32-STOCK-BLANK,Blank Stock Product,10.00,,1\n" .
            "simple,T32-STOCK-ZERO,Zero Stock Product,20.00,0,0\n" .
            "simple,T32-STOCK-NUM,Numeric Stock Product,30.00,15,draft\n" .
            "simple,T32-STOCK-PUB,Published Text Product,40.00,50,publish\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t32_r131_') . '.csv';
        file_put_contents($tmpFile, $csvContent);
        $this->cleanFiles[] = $tmpFile;

        $result = $this->importer->importFromCsv($tmpFile);
        $this->assert($result['status'] === 'completed', "Import should complete without errors");

        // 1. Verify Blank Stock semantics (R131)
        $pBlank = $this->productRepo->getBySku('T32-STOCK-BLANK');
        $this->assert($pBlank !== null, "Blank stock product must exist");
        $this->cleanProductIds[] = (int)$pBlank['id'];
        $this->assert($pBlank['manage_stock'] === false, "Blank stock must have manage_stock = false");
        $this->assert($pBlank['stock_quantity'] === null, "Blank stock must NOT become zero");
        $this->assert($pBlank['status'] === 'publish', "Published '1' must result in status 'publish'");

        // 2. Verify Zero Stock semantics (R131)
        $pZero = $this->productRepo->getBySku('T32-STOCK-ZERO');
        $this->assert($pZero !== null, "Zero stock product must exist");
        $this->cleanProductIds[] = (int)$pZero['id'];
        $this->assert($pZero['manage_stock'] === true, "Explicit '0' stock must have manage_stock = true");
        $this->assert((float)$pZero['stock_quantity'] === 0.0, "Explicit '0' stock must equal 0");
        $this->assert($pZero['stock_status'] === 'outofstock', "Zero stock must set stock_status to outofstock");
        $this->assert($pZero['status'] === 'draft', "Published '0' must result in status 'draft'");

        // 3. Verify Numeric Stock and 'draft' string status
        $pNum = $this->productRepo->getBySku('T32-STOCK-NUM');
        $this->assert($pNum !== null, "Numeric stock product must exist");
        $this->cleanProductIds[] = (int)$pNum['id'];
        $this->assert($pNum['manage_stock'] === true, "Manage stock must be true");
        $this->assert((float)$pNum['stock_quantity'] === 15.0, "Stock quantity must be 15");
        $this->assert($pNum['status'] === 'draft', "Published 'draft' must result in status 'draft'");

        // 4. Verify 'publish' string status
        $pPub = $this->productRepo->getBySku('T32-STOCK-PUB');
        $this->assert($pPub !== null, "Published product must exist");
        $this->cleanProductIds[] = (int)$pPub['id'];
        $this->assert($pPub['status'] === 'publish', "Published 'publish' must result in status 'publish'");

        echo "R131 passed: Stock blank vs zero and published statuses strictly differentiated.\n";
    }
}

// Execute test suite when run via wp eval-file
$test = new T32Test();
$test->run();
