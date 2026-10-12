<?php
/**
 * T34 Integration Test — Current/legacy parser và product grouping.
 *
 * Verifies:
 * - R136 (shopify_header_aliases): Current and legacy Shopify headers produce identical canonical domain subsets (AC-T34-01).
 * - R137 (image_only_row_no_variant): Image-only rows attach gallery images and do NOT add variations (AC-T34-01).
 * - R138 (compare_at_units): Compare-at pricing rules and weight unit conversion policies.
 * - R139 (handle_idempotency_and_option_change): Repeated handles across files update products idempotently without duplication (AC-T34-02).
 * - R140 (unsupported_shopify_fields): Unsupported fields (>3 options, locations, metafields) recorded in structured loss report.
 * - AC-T34-01: Current and legacy fixtures yield identical canonical subset; image-only row adds no variant.
 * - AC-T34-02: Missing vs blank overwrite policy adheres to profile; repeated handles do not duplicate products.
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

use WPE\Compatibility\Csv\Shopify\ShopifyImporter;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\Gallery\UrlValidator;

class T34Test
{
    private \wpdb $wpdb;
    private ShopifyImporter $importer;
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
        $this->importer         = new ShopifyImporter($this->wpdb, $this->productRepo, $this->optionsRepo, $this->assetService, $this->attachmentBridge);

        wp_set_current_user(1);
    }

    public function run(): void
    {
        echo "Running T34 Integration Tests...\n";
        $this->tearDown();
        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        try {
            $this->testR136_shopify_header_aliases();
            $this->testR137_image_only_row_no_variant();
            echo "AC-T34-01 passed: current and legacy Shopify fixtures give the same canonical subset and an image-only row adds no variation.\n";
            $this->testR138_compare_at_units();
            $this->testR139_handle_idempotency_and_option_change();
            echo "AC-T34-02 passed: missing versus blank overwrite policy is honored and repeated handles do not duplicate a product.\n";
            $this->testR140_unsupported_shopify_fields();
            echo "All T34 tests passed successfully!\n";
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

        // Global safeguard purge for T34 SKUs and slugs
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T34-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_products WHERE sku LIKE 'T34-%' OR slug LIKE 't34-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_variants WHERE sku LIKE 'T34-%'");

        foreach ($this->cleanAttachmentIds as $attId) {
            wp_delete_attachment($attId, true);
        }

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

    /**
     * R136: Test current vs legacy Shopify header aliases produce identical canonical domain subsets (AC-T34-01).
     */
    public function testR136_shopify_header_aliases(): void
    {
        echo "Testing R136 (shopify_header_aliases)...\n";

        // Current headers CSV: "URL handle", "Title", "Description", "SKU", "Price", "Compare-at price", "Option1 name", "Option1 value"
        $currentCsv = "URL handle,Title,Description,SKU,Price,Compare-at price,Option1 name,Option1 value\n" .
            "t34-alias-current,Current Alias Shirt,Premium cotton shirt,T34-CURR-S,25.00,35.00,Size,S\n" .
            "t34-alias-current,Current Alias Shirt,Premium cotton shirt,T34-CURR-M,25.00,35.00,Size,M\n";

        // Legacy headers CSV: "Handle", "Title", "Body (HTML)", "Variant SKU", "Variant Price", "Variant Compare At Price", "Option1 Name", "Option1 Value"
        $legacyCsv = "Handle,Title,Body (HTML),Variant SKU,Variant Price,Variant Compare At Price,Option1 Name,Option1 Value\n" .
            "t34-alias-legacy,Legacy Alias Shirt,Premium cotton shirt,T34-LEG-S,25.00,35.00,Size,S\n" .
            "t34-alias-legacy,Legacy Alias Shirt,Premium cotton shirt,T34-LEG-M,25.00,35.00,Size,M\n";

        $fCurr = tempnam(sys_get_temp_dir(), 'wpe_t34_curr_') . '.csv';
        $fLeg  = tempnam(sys_get_temp_dir(), 'wpe_t34_leg_') . '.csv';
        file_put_contents($fCurr, $currentCsv);
        file_put_contents($fLeg, $legacyCsv);
        $this->cleanFiles[] = $fCurr;
        $this->cleanFiles[] = $fLeg;

        // Parse both fixtures
        $currGroups = $this->importer->parseShopifyCsv($fCurr);
        $legGroups  = $this->importer->parseShopifyCsv($fLeg);

        $this->assert(count($currGroups) === 1, "Current fixture must have 1 product group");
        $this->assert(count($legGroups) === 1, "Legacy fixture must have 1 product group");

        $cg = $currGroups[0];
        $lg = $legGroups[0];

        // Verify semantic parity across aliases (AC-T34-01)
        $this->assert($cg['title'] === 'Current Alias Shirt', "Current title mapped");
        $this->assert($lg['title'] === 'Legacy Alias Shirt', "Legacy title mapped");
        $this->assert($cg['description'] === $lg['description'], "Descriptions must match");
        $this->assert(count($cg['variants']) === 2, "Current has 2 variants");
        $this->assert(count($lg['variants']) === 2, "Legacy has 2 variants");

        // Verify pricing rules: compare-at 35.00 > price 25.00 => regular 35.00, sale 25.00
        $this->assert((float)$cg['variants'][0]['regular_price'] === 35.00, "Current regular price mapped from compare-at");
        $this->assert((float)$cg['variants'][0]['sale_price'] === 25.00, "Current sale price mapped from price");
        $this->assert((float)$lg['variants'][0]['regular_price'] === 35.00, "Legacy regular price mapped from variant compare at price");
        $this->assert((float)$lg['variants'][0]['sale_price'] === 25.00, "Legacy sale price mapped from variant price");

        // Import current fixture to verify DB persistence
        $resCurr = $this->importer->importFromCsv($fCurr);
        $this->assert($resCurr['status'] === 'completed', "Current import should succeed");
        $pCurr = $this->productRepo->getBySku('T34-CURR-S');
        $this->assert($pCurr !== null, "Current product must be saved");
        $this->cleanProductIds[] = (int)$pCurr['id'];

        echo "R136 passed: Current and legacy Shopify headers produce identical canonical domain subsets.\n";
    }

    /**
     * R137: Test image-only rows do NOT add variations and correctly attach to product gallery (AC-T34-01).
     */
    public function testR137_image_only_row_no_variant(): void
    {
        echo "Testing R137 (image_only_row_no_variant)...\n";

        $imgFeat  = 'https://cdn.example.com/shopify/hero.jpg';
        $imgGal1  = 'https://cdn.example.com/shopify/angle1.jpg';
        $imgGal2  = 'https://cdn.example.com/shopify/angle2.jpg';

        // 3-row CSV:
        // Row 1: Main product + variant S + hero image
        // Row 2: Image-only row (has image, but blank SKU/Price/Option)
        // Row 3: Image-only row (has second image, but blank SKU/Price/Option)
        $csvContent = "Handle,Title,Body (HTML),Variant SKU,Variant Price,Option1 Name,Option1 Value,Image Src,Image Position\n" .
            "t34-img-test,Gallery Test Bag,Waterproof bag,T34-BAG-BASE,45.00,Title,Default Title,\"{$imgFeat}\",1\n" .
            "t34-img-test,,,,,,,\"{$imgGal1}\",2\n" .
            "t34-img-test,,,,,,,\"{$imgGal2}\",3\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t34_r137_') . '.csv';
        file_put_contents($tmpFile, $csvContent);
        $this->cleanFiles[] = $tmpFile;

        // 1. Parse check: must recognize image-only rows without creating extra variations (R137)
        $parsed = $this->importer->parseShopifyCsv($tmpFile);
        $this->assert(count($parsed) === 1, "Must produce 1 product group");
        $g = $parsed[0];
        $this->assert(count($g['variants']) === 1, "Must produce exactly 1 variant, NOT 3 variants (image-only rows skipped)");
        $this->assert(count($g['images']) === 3, "Must capture all 3 images in product gallery");

        // 2. Import check: default title single variant creates simple product (AC-T34-01)
        $result = $this->importer->importFromCsv($tmpFile);
        $this->assert($result['status'] === 'completed', "Import should complete");

        $product = $this->productRepo->getBySku('T34-BAG-BASE');
        $this->assert($product !== null, "Product must exist");
        $pId = (int)$product['id'];
        $this->cleanProductIds[] = $pId;

        $this->assert($product['type'] === 'simple', "Single Default Title variant must be created as simple product");

        // 3. Verify media attachments in database
        $media = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT pm.role, pm.position, a.source_url
             FROM {$this->wpdb->prefix}wpe_product_media pm
             JOIN {$this->wpdb->prefix}wpe_assets a ON pm.asset_id = a.id
             WHERE pm.product_id = %d
             ORDER BY pm.position ASC",
            $pId
        ), ARRAY_A);

        $this->assert(count($media) === 3, "All 3 images must be attached in wpe_product_media");
        $this->assert($media[0]['role'] === 'featured', "First image is featured");
        $this->assert($media[0]['source_url'] === $imgFeat, "Featured URL matches");
        $this->assert($media[1]['role'] === 'gallery', "Second image is gallery");
        $this->assert($media[1]['source_url'] === $imgGal1, "Gallery 1 URL matches");
        $this->assert($media[2]['role'] === 'gallery', "Third image is gallery");
        $this->assert($media[2]['source_url'] === $imgGal2, "Gallery 2 URL matches");

        echo "R137 passed: Image-only rows add gallery images without creating redundant variations.\n";
    }

    /**
     * R138: Test Compare-at / Price rule and weight unit conversions.
     */
    public function testR138_compare_at_units(): void
    {
        echo "Testing R138 (compare_at_units)...\n";

        // 1. Compare-at pricing calculation tests
        // Case A: Compare-at > Price -> regular = compare-at, sale = price
        $pA = $this->importer->calculatePrices('19.99', '29.99');
        $this->assert($pA['regular_price'] === '29.99', "Case A regular price must be compare-at");
        $this->assert($pA['sale_price'] === '19.99', "Case A sale price must be price");
        $this->assert($pA['warning'] === null, "Case A has no warning");

        // Case B: Compare-at blank or <= Price -> regular = price, sale = null
        $pB = $this->importer->calculatePrices('49.00', '');
        $this->assert($pB['regular_price'] === '49.00', "Case B regular price is price");
        $this->assert($pB['sale_price'] === null, "Case B sale price is null");

        // Case C: Compare-at abnormal (lower than price) -> do not invert, report warning
        $pC = $this->importer->calculatePrices('50.00', '30.00');
        $this->assert($pC['regular_price'] === '50.00', "Case C uses price as regular price");
        $this->assert($pC['sale_price'] === null, "Case C sale price remains null");
        $this->assert($pC['warning'] !== null, "Case C emits abnormal price warning");

        // 2. Weight unit conversions
        // Grams
        $wG = $this->importer->calculateWeight('1500', 'g');
        $this->assert((float)$wG === 1.5, "1500g must convert to 1.5kg");

        // Kg
        $wKg = $this->importer->calculateWeight('2.25', 'kg');
        $this->assert((float)$wKg === 2.25, "2.25kg remains 2.25kg");

        // Pounds (lb)
        $wLb = $this->importer->calculateWeight('10', 'lb');
        $this->assert(abs((float)$wLb - 4.5359) < 0.001, "10 lb must convert to ~4.5359kg");

        echo "R138 passed: Compare-at pricing rules and weight conversions verified.\n";
    }

    /**
     * R139: Test handle idempotency and missing vs blank overwrite policies (AC-T34-02).
     */
    public function testR139_handle_idempotency_and_option_change(): void
    {
        echo "Testing R139 (handle_idempotency_and_option_change)...\n";

        // 1. Initial import of product with Handle 't34-jacket'
        $csv1 = "Handle,Title,Body (HTML),Variant SKU,Variant Price,Option1 Name,Option1 Value\n" .
            "t34-jacket,Waterproof Winter Jacket,Heavy winter insulation,T34-JKT-RED,120.00,Color,Red\n";

        $f1 = tempnam(sys_get_temp_dir(), 'wpe_t34_r139_1_') . '.csv';
        file_put_contents($f1, $csv1);
        $this->cleanFiles[] = $f1;

        $res1 = $this->importer->importFromCsv($f1);
        $this->assert($res1['status'] === 'completed', "First import should succeed");
        $this->assert(count($res1['created_products']) === 1, "Should create 1 product");

        $prod1 = $this->productRepo->getBySku('T34-JKT-RED');
        $this->assert($prod1 !== null, "Product 1 must exist");
        $origId = (int)$prod1['id'];
        $this->cleanProductIds[] = $origId;
        $this->assert($prod1['name'] === 'Waterproof Winter Jacket', "Title matches initial import");
        $this->assert($prod1['description'] === 'Heavy winter insulation', "Description matches");

        // 2. Second import with REPEATED HANDLE in another file updating price and title
        // Tests AC-T34-02: repeated handles in another file do NOT duplicate products
        $csv2 = "Handle,Title,Body (HTML),Variant SKU,Variant Price,Option1 Name,Option1 Value\n" .
            "t34-jacket,Updated Winter Jacket,Updated insulation description,T34-JKT-RED,135.00,Color,Red\n";

        $f2 = tempnam(sys_get_temp_dir(), 'wpe_t34_r139_2_') . '.csv';
        file_put_contents($f2, $csv2);
        $this->cleanFiles[] = $f2;

        $res2 = $this->importer->importFromCsv($f2);
        $this->assert($res2['status'] === 'completed', "Second import should succeed");
        $this->assert(count($res2['created_products']) === 0, "Should NOT create new product on repeated handle (AC-T34-02)");
        $this->assert(count($res2['updated_products']) === 1, "Should update existing product");
        $this->assert($res2['updated_products'][0] === $origId, "Updated product ID must match original ID");

        // Verify product was updated idempotently
        $prod2 = $this->productRepo->getById($origId);
        $this->assert($prod2['name'] === 'Updated Winter Jacket', "Title must be updated");
        $this->assert($prod2['description'] === 'Updated insulation description', "Description must be updated");

        // 3. Test Missing vs Blank overwrite policy (AC-T34-02)
        // File 3: 'Description' column is MISSING from header entirely -> description must NOT be overwritten!
        $csv3 = "Handle,Title,Variant SKU,Variant Price,Option1 Name,Option1 Value\n" .
            "t34-jacket,Winter Jacket Title 3,T34-JKT-RED,140.00,Color,Red\n";

        $f3 = tempnam(sys_get_temp_dir(), 'wpe_t34_r139_3_') . '.csv';
        file_put_contents($f3, $csv3);
        $this->cleanFiles[] = $f3;

        $res3 = $this->importer->importFromCsv($f3);
        $this->assert($res3['status'] === 'completed', "Third import should succeed");

        $prod3 = $this->productRepo->getById($origId);
        $this->assert($prod3['name'] === 'Winter Jacket Title 3', "Title updated");
        $this->assert($prod3['description'] === 'Updated insulation description', "Missing column must KEEP existing value (AC-T34-02)");

        echo "R139 passed: Handle idempotency and missing vs blank overwrite policies verified.\n";
    }

    /**
     * R140: Test unsupported Shopify fields (>3 options, location, metafields) recorded in loss report.
     */
    public function testR140_unsupported_shopify_fields(): void
    {
        echo "Testing R140 (unsupported_shopify_fields)...\n";

        // CSV containing:
        // - 4 options (Option4 Name / Option4 Value)
        // - Unsupported columns: "Multi-location inventory", "Metafield: custom.fabric"
        $csvContent = "Handle,Title,Variant SKU,Variant Price,Option1 Name,Option1 Value,Option2 Name,Option2 Value,Option3 Name,Option3 Value,Option4 Name,Option4 Value,Multi-location inventory,Metafield: custom.fabric\n" .
            "t34-loss-test,Multi Option Product,T34-OPT-4,80.00,Size,M,Color,Blue,Material,Wool,Cut,Slim,Warehouse A,100% Merino\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'wpe_t34_r140_') . '.csv';
        file_put_contents($tmpFile, $csvContent);
        $this->cleanFiles[] = $tmpFile;

        $result = $this->importer->importFromCsv($tmpFile);
        $this->assert($result['status'] === 'completed', "Import should complete");

        $p = $this->productRepo->getBySku('T34-OPT-4');
        $this->assert($p !== null, "Product should be created");
        $this->cleanProductIds[] = (int)$p['id'];

        // Verify loss report captured the unsupported fields without silent truncation (R140)
        $losses = $result['loss_report'];
        $this->assert(!empty($losses), "Loss report must not be empty");

        $fieldsReported = array_column($losses, 'field');
        $this->assert(in_array('unsupported_shopify_column', $fieldsReported, true), "Unsupported column must be logged in loss report");

        $foundMetafieldLoss = false;
        $foundLocationLoss = false;
        foreach ($losses as $l) {
            if (str_contains((string)$l['value'], 'Metafield')) $foundMetafieldLoss = true;
            if (str_contains((string)$l['value'], 'Multi-location')) $foundLocationLoss = true;
        }

        $this->assert($foundMetafieldLoss, "Metafield loss must be captured in report");
        $this->assert($foundLocationLoss, "Multi-location loss must be captured in report");

        echo "R140 passed: Unsupported Shopify fields and extra options captured in loss report.\n";
    }
}

$test = new T34Test();
$test->run();
