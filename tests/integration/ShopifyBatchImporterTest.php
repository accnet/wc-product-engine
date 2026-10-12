<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;

class ShopifyBatchImporterTest
{
    private wpdb $wpdb;
    private ShopifyBatchImporter $importer;
    private string $testCsvPath;
    private array $cleanJobIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->importer = ShopifyBatchImporter::getInstance();
    }

    public function run(): void
    {
        echo "Running ShopifyBatchImporter Integration Tests...\n";

        try {
            $this->setUp();
            $this->testInitJob();
            $this->testProcessBatchAndCheckpoint();
            $this->testPauseAndResume();
            $this->testCancel();
            $this->tearDown();

            echo "All ShopifyBatchImporter tests passed successfully!\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
    }

    private function setUp(): void
    {
        $this->cleanJobIds = [];
        \WPE\Compatibility\Gallery\UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });

        // Create temporary small Shopify CSV fixture
        $this->testCsvPath = sys_get_temp_dir() . '/test_shopify_batch_' . uniqid() . '.csv';

        $csvContent = implode("\n", [
            'Handle,Title,Body (HTML),Vendor,Type,Tags,Published,Option1 Name,Option1 Value,Option2 Name,Option2 Value,Option3 Name,Option3 Value,Variant SKU,Variant Grams,Variant Inventory Tracker,Variant Inventory Qty,Variant Inventory Policy,Variant Fulfillment Service,Variant Price,Variant Compare At Price,Variant Requires Shipping,Variant Taxable,Variant Barcode,Image Src,Image Position,Image Alt Text,Gift Card,SEO Title,SEO Description,Google Shopping / Google Product Category,Google Shopping / Gender,Google Shopping / Age Group,Google Shopping / MPN,Google Shopping / AdWords Grouping,Google Shopping / AdWords Labels,Google Shopping / Condition,Google Shopping / Custom Product,Google Shopping / Custom Label 0,Google Shopping / Custom Label 1,Google Shopping / Custom Label 2,Google Shopping / Custom Label 3,Google Shopping / Custom Label 4,Variant Image,Variant Weight Unit,Variant Tax Code,Cost per item,Status',
            'batch-test-p1,Batch Test Product 1,<p>Description 1</p>,Vendor A,Apparel,tag1,true,Size,Small,Color,Red,,,TEST-P1-SM-RED,200,shopify,15,deny,manual,29.99,39.99,true,true,,,https://cdn.example.com/p1.jpg,1,P1 Alt,false,,,,,,,,,,,,,,,https://cdn.example.com/p1-red.jpg,g,,20.00,active',
            'batch-test-p1,,,,,,,,Size,Medium,Color,Red,,,TEST-P1-MD-RED,200,shopify,20,deny,manual,29.99,39.99,true,true,,,https://cdn.example.com/p1-extra.jpg,2,P1 Gallery,false,,,,,,,,,,,,,,,https://cdn.example.com/p1-red.jpg,g,,20.00,active',
            'batch-test-p2,Batch Test Product 2,<p>Description 2</p>,Vendor B,Footwear,tag2,true,Title,Default Title,,,,TEST-P2-SMP,500,shopify,5,deny,manual,49.99,,true,true,,,https://cdn.example.com/p2.jpg,1,P2 Alt,false,,,,,,,,,,,,,,,,g,,30.00,active',
        ]);

        file_put_contents($this->testCsvPath, $csvContent);
    }

    private function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;
        $this->wpdb->query("DELETE FROM {$prefix}wpe_sku_registry WHERE normalized_sku LIKE 'test-p1%' OR normalized_sku LIKE 'test-p2%' OR original_sku LIKE 'TEST-P1%' OR original_sku LIKE 'TEST-P2%'");

        if (file_exists($this->testCsvPath)) {
            @unlink($this->testCsvPath);
        }

        foreach ($this->cleanJobIds as $jid) {
            $this->wpdb->delete("{$prefix}wpe_jobs", ['id' => $jid]);
            $logFile = WP_CONTENT_DIR . "/uploads/wpe-imports/logs/import_{$jid}.log";
            if (file_exists($logFile)) {
                @unlink($logFile);
            }
        }
        $this->cleanJobIds = [];

        // Clean test products
        $p1 = $this->wpdb->get_var("SELECT id FROM {$prefix}wpe_products WHERE slug = 'batch-test-p1'");
        if ($p1) {
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $p1]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $p1]);
        }
        $p2 = $this->wpdb->get_var("SELECT id FROM {$prefix}wpe_products WHERE slug = 'batch-test-p2'");
        if ($p2) {
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $p2]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $p2]);
        }

        \WPE\Compatibility\Gallery\UrlValidator::setDnsResolver(null);
    }

    public function testInitJob(): void
    {
        echo "Testing initJob...\n";

        $res = $this->importer->initJob($this->testCsvPath, [
            'update_mode' => 'merge',
            'image_mode'  => 'external',
            'batch_size'  => 1,
        ]);

        $this->cleanJobIds[] = $res['job_id'];

        if (empty($res['job_id'])) {
            throw new RuntimeException("initJob failed to return job_id");
        }
        if ($res['status'] !== 'pending') {
            throw new RuntimeException("Expected pending status, got {$res['status']}");
        }

        $logPath = $this->importer->getLogFile($res['job_id']);
        if (!$logPath || !file_exists($logPath)) {
            throw new RuntimeException("Log file was not created on job init");
        }

        echo "  ✓ initJob passed: Job created, headers sniffed, log file initialized.\n";
    }

    public function testProcessBatchAndCheckpoint(): void
    {
        echo "Testing processBatch and checkpoint persistence...\n";

        $init = $this->importer->initJob($this->testCsvPath, ['batch_size' => 1]);
        $jobId = $init['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Process Batch 1 (should process batch-test-p1)
        $batch1 = $this->importer->processBatch($jobId, 1);

        if ($batch1['products_processed'] !== 1) {
            throw new RuntimeException("Batch 1 expected 1 product processed, got {$batch1['products_processed']}");
        }
        if ($batch1['variants_processed'] !== 2) {
            throw new RuntimeException("Batch 1 expected 2 variants processed for P1, got {$batch1['variants_processed']}");
        }
        if ($batch1['completed']) {
            throw new RuntimeException("Batch 1 marked completed early, expected more products");
        }
        if ($batch1['byte_offset'] <= 0) {
            throw new RuntimeException("Byte offset did not advance");
        }

        // Process Batch 2 (should process batch-test-p2 and reach EOF)
        $batch2 = $this->importer->processBatch($jobId, 1);

        if ($batch2['products_processed'] !== 2) {
            throw new RuntimeException("Batch 2 expected 2 products processed total, got {$batch2['products_processed']}");
        }
        if (!$batch2['completed']) {
            throw new RuntimeException("Batch 2 expected completed=true upon EOF");
        }
        if ($batch2['status'] !== 'completed') {
            throw new RuntimeException("Status expected 'completed', got {$batch2['status']}");
        }

        // Verify product was created in database
        $prefix = $this->wpdb->prefix;
        $p1 = $this->wpdb->get_row("SELECT * FROM {$prefix}wpe_products WHERE slug = 'batch-test-p1'", ARRAY_A);
        if (!$p1) {
            throw new RuntimeException("Product batch-test-p1 was not inserted into wpe_products");
        }
        if ($p1['type'] !== 'variable') {
            throw new RuntimeException("Product batch-test-p1 expected variable, got {$p1['type']}");
        }

        $p2 = $this->wpdb->get_row("SELECT * FROM {$prefix}wpe_products WHERE slug = 'batch-test-p2'", ARRAY_A);
        if (!$p2) {
            throw new RuntimeException("Product batch-test-p2 was not inserted into wpe_products");
        }
        if ($p2['type'] !== 'simple') {
            throw new RuntimeException("Product batch-test-p2 (Default Title) expected simple, got {$p2['type']}");
        }

        echo "  ✓ processBatch passed: Byte offsets advanced, checkpoint saved, products imported with correct types.\n";
    }

    public function testPauseAndResume(): void
    {
        echo "Testing pauseJob and resumeJob...\n";

        $init = $this->importer->initJob($this->testCsvPath, ['batch_size' => 1]);
        $jobId = $init['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Process 1 batch
        $this->importer->processBatch($jobId, 1);

        // Pause
        $pauseRes = $this->importer->pauseJob($jobId);
        if ($pauseRes['status'] !== 'paused') {
            throw new RuntimeException("pauseJob did not return paused status");
        }

        $activeJob = $this->importer->getActiveJob();
        if (!$activeJob || $activeJob['job_id'] !== $jobId || $activeJob['status'] !== 'paused') {
            throw new RuntimeException("getActiveJob did not report paused job");
        }

        // While paused, processBatch should not advance
        $pausedBatch = $this->importer->processBatch($jobId, 1);
        if ($pausedBatch['status'] !== 'paused') {
            throw new RuntimeException("processBatch did not respect paused status");
        }

        // Resume
        $resumeRes = $this->importer->resumeJob($jobId);
        if ($resumeRes['status'] !== 'in_progress') {
            throw new RuntimeException("resumeJob did not return in_progress status");
        }

        // Now processBatch should succeed and complete
        $nextBatch = $this->importer->processBatch($jobId, 1);
        if (!$nextBatch['completed']) {
            throw new RuntimeException("Resumed batch did not complete");
        }

        echo "  ✓ pauseJob and resumeJob passed: State transitions and checkpoint resumption verified.\n";
    }

    public function testCancel(): void
    {
        echo "Testing cancelJob...\n";

        $init = $this->importer->initJob($this->testCsvPath, ['batch_size' => 1]);
        $jobId = $init['job_id'];
        $this->cleanJobIds[] = $jobId;

        $cancelRes = $this->importer->cancelJob($jobId);
        if ($cancelRes['status'] !== 'cancelled') {
            throw new RuntimeException("cancelJob failed: status={$cancelRes['status']}");
        }

        $res = $this->importer->processBatch($jobId, 1);
        if ($res['status'] !== 'cancelled') {
            throw new RuntimeException("processBatch did not recognize cancelled status");
        }

        echo "  ✓ cancelJob passed: Cancel requested flag respected.\n";
    }
}

$test = new ShopifyBatchImporterTest();
$test->run();
