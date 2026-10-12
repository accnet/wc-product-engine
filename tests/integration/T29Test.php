<?php
/**
 * T29 Integration Test — Streaming parser, staging và dry-run validation.
 *
 * Verifies:
 * - R115 (csv_multiline_quotes_bom): Multiline quotes, UTF-8 BOM, dialect sniffing, missing vs blank semantics.
 * - R116 (dryrun_no_side_effects): Dry-run validation performs zero catalog mutations (INV11).
 * - R117 (group_shuffled_rows): Shuffled variation and parent rows correctly grouped via group_key.
 * - R118 (large_csv_memory): Streaming parser maintains bounded O(1) memory for large CSVs.
 * - R119 (private_upload_permissions): Private upload directory sandboxing, path traversal denial, actor capabilities.
 * - AC-T29-01: Zero catalog mutation during dry-run; malformed rows capture exact row/column errors.
 * - AC-T29-02: Modified file or mismatched snapshot hash rejects commit; large file memory remains bounded.
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

use WPE\Application\ImportExport\CsvStager;

class T29Test
{
    private \wpdb $wpdb;
    private CsvStager $stager;
    private string $testUploadDir;

    private array $cleanJobIds = [];
    private array $cleanFiles = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $wpUpload = wp_upload_dir();
        $this->testUploadDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-test-imports';
        $this->stager = new CsvStager($this->wpdb, $this->testUploadDir);

        wp_set_current_user(1); // Set administrator
    }

    public function run(): void
    {
        echo "Running T29 Integration Tests...\n";
        try {
            $this->testR115_csv_multiline_quotes_bom();
            $this->testR116_dryrun_no_side_effects();
            $this->testR117_group_shuffled_rows();
            $this->testR118_large_csv_memory();
            $this->testR119_private_upload_permissions();
            $this->testAC_T29_01_malformed_row_exact_errors();
            $this->testAC_T29_02_commit_hash_verification();
            echo "All T29 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    private function tearDown(): void
    {
        foreach ($this->cleanJobIds as $jobId) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_import_rows', ['job_id' => $jobId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_jobs', ['id' => $jobId]);
        }

        foreach ($this->cleanFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        // Clean test directory
        if (is_dir($this->testUploadDir)) {
            $files = glob($this->testUploadDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->testUploadDir);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    /**
     * R115: Streaming parser with UTF-8 BOM, multiline quotes, escaped quotes,
     * and preservation of missing vs blank column semantics.
     */
    public function testR115_csv_multiline_quotes_bom(): void
    {
        echo " - R115 (csv_multiline_quotes_bom)...";

        // Create CSV with UTF-8 BOM, multiline quoted cells, and missing standard columns
        // Notice 'Sale price' is omitted from header (absent), 'Short description' is empty (blank)
        $csvContent = "\xEF\xBB\xBF" .
            "Type,SKU,Name,Short description,Description,Regular price\n" .
            "simple,T29-R115-01,\"Multiline \"\"Widget\"\" Title\",,\"Line 1 of description.\nLine 2 with \"\"quotes\"\" and commas, here.\nLine 3.\",29.99\n" .
            "simple,T29-R115-02,Standard Widget,Brief summary,One line description,19.99\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_r115_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        // Sniffing
        $sniffed = $this->stager->sniffDialectAndDelimiter($tempFile);
        $this->assert($sniffed['has_bom'] === true, "Must detect UTF-8 BOM");
        $this->assert($sniffed['delimiter'] === ',', "Must detect comma delimiter");
        $this->assert($sniffed['format'] === 'woo', "Must detect woo dialect");
        $this->assert($sniffed['headers'][0] === 'Type', "First header must strip BOM cleanly");

        // Staging
        $staged = $this->stager->storeUploadedFile($tempFile, 'r115_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        $rows = $this->stager->getJobRows($jobId);
        $this->assert(count($rows) === 2, "Must stage 2 product rows");

        // Row 1 checks
        $row1Data = json_decode((string)$rows[0]['raw_fields'], true);
        $fields1 = $row1Data['fields'];
        $states1 = $row1Data['states'];

        $this->assert(str_contains($fields1['Description'], "Line 1 of description."), "Must preserve multiline description");
        $this->assert(str_contains($fields1['Description'], "\nLine 2 with \"quotes\""), "Must preserve newlines and unescape quotes");
        $this->assert($fields1['Short description'] === '', "Short description must be empty string");
        $this->assert($states1['Short description'] === 'blank', "Short description must have state 'blank'");
        $this->assert($states1['Description'] === 'value', "Description must have state 'value'");
        $this->assert($states1['Sale price'] === 'absent', "Omitted standard column 'Sale price' must have state 'absent'");
        $this->assert(in_array('Sale price', $row1Data['absent'], true), "'Sale price' must be listed in absent list");

        echo " OK\n";
    }

    /**
     * R116: Dry-run validation has ZERO side effects on catalog database (INV11).
     */
    public function testR116_dryrun_no_side_effects(): void
    {
        echo " - R116 (dryrun_no_side_effects)...";

        $csvContent = "Type,SKU,Name,Regular price,Images\n" .
            "simple,T29-R116-01,Dry Run Test Product,45.00,https://cdn.example.com/p1.jpg\n" .
            "variable,T29-R116-PARENT,Parent Variable,0.00,\n" .
            "variation,T29-R116-VAR1,Variation 1,20.00,https://cdn.example.com/var1.jpg\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_r116_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'r116_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Baseline counts of all catalog tables
        $pCountBefore   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_products");
        $vCountBefore   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variants");
        $iCountBefore   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_inventory");
        $oCountBefore   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_options");
        $ovCountBefore  = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_option_values");
        $aCountBefore   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_assets");
        $postsBefore    = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}posts");
        $postmetaBefore = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}postmeta");
        $termsBefore    = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}terms");

        // Execute dry-run validation
        $report = $this->stager->validateJob($jobId);

        $this->assert($report['status'] === 'validated', "Job status must be 'validated'");
        $this->assert(!empty($report['snapshot_hash']), "Report must include immutable snapshot_hash");

        // Verify counts after dry-run
        $pCountAfter   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_products");
        $vCountAfter   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variants");
        $iCountAfter   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_inventory");
        $oCountAfter   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_options");
        $ovCountAfter  = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_option_values");
        $aCountAfter   = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_assets");
        $postsAfter    = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}posts");
        $postmetaAfter = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}postmeta");
        $termsAfter    = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}terms");

        $this->assert($pCountBefore === $pCountAfter, "wpe_products count must not change during dry-run");
        $this->assert($vCountBefore === $vCountAfter, "wpe_variants count must not change during dry-run");
        $this->assert($iCountBefore === $iCountAfter, "wpe_inventory count must not change during dry-run");
        $this->assert($oCountBefore === $oCountAfter, "wpe_options count must not change during dry-run");
        $this->assert($ovCountBefore === $ovCountAfter, "wpe_option_values count must not change during dry-run");
        $this->assert($aCountBefore === $aCountAfter, "wpe_assets count must not change during dry-run");
        $this->assert($postsBefore === $postsAfter, "wp_posts count must not change during dry-run");
        $this->assert($postmetaBefore === $postmetaAfter, "wp_postmeta count must not change during dry-run");
        $this->assert($termsBefore === $termsAfter, "wp_terms count must not change during dry-run");

        echo " OK\n";
    }

    /**
     * R117: Shuffled parent and child rows grouped on DB via group_key.
     * Variations appearing before the parent row are associated to the same group.
     */
    public function testR117_group_shuffled_rows(): void
    {
        echo " - R117 (group_shuffled_rows)...";

        // Child variations appear on row 2 and 3, parent appears on row 4!
        $csvContent = "Type,SKU,Parent,Name,Regular price\n" .
            "variation,T29-SHUFFLE-V1,T29-SHUFFLE-PARENT,Variation 1,15.00\n" .
            "variation,T29-SHUFFLE-V2,T29-SHUFFLE-PARENT,Variation 2,20.00\n" .
            "variable,T29-SHUFFLE-PARENT,,Parent Variable Shirt,0.00\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_r117_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'r117_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Query grouped rows
        $rowsGrouped = $this->stager->getJobRows($jobId, 'T29-SHUFFLE-PARENT');
        $this->assert(count($rowsGrouped) === 3, "All 3 rows must be grouped under 'T29-SHUFFLE-PARENT'");

        // Validate job: variations should successfully link to parent despite appearing earlier
        $report = $this->stager->validateJob($jobId);
        $this->assert(empty($report['errors']), "There should be no missing_parent error for shuffled parent");
        $this->assert($report['counts']['valid_rows'] === 3, "All 3 rows must be valid");

        echo " OK\n";
    }

    /**
     * R118: Large CSV streaming with bounded memory consumption O(1).
     */
    public function testR118_large_csv_memory(): void
    {
        echo " - R118 (large_csv_memory)...";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_r118_') . '.csv';
        $fp = fopen($tempFile, 'wb');
        fputcsv($fp, ['Type', 'SKU', 'Name', 'Regular price', 'Description']);

        $rowCount = 2000; // 2000 rows
        for ($i = 1; $i <= $rowCount; $i++) {
            fputcsv($fp, [
                'simple',
                "T29-STREAM-SKU-{$i}",
                "Streamed Product {$i}",
                sprintf('%.2f', 10 + ($i % 50)),
                "Description for product {$i} with some sample text to test streaming throughput."
            ]);
        }
        fclose($fp);
        $this->cleanFiles[] = $tempFile;

        $memBefore = memory_get_usage(true);

        $staged = $this->stager->storeUploadedFile($tempFile, 'r118_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        $this->assert($receipt['total_rows'] === $rowCount, "Must stage all {$rowCount} rows");

        $report = $this->stager->validateJob($jobId);
        $this->assert($report['counts']['total_rows'] === $rowCount, "Validation must process all {$rowCount} rows");

        $memAfter = memory_get_usage(true);
        $memDiffMb = ($memAfter - $memBefore) / (1024 * 1024);

        // Memory delta must be small and bounded (< 15 MB)
        $this->assert($memDiffMb < 15, "Streaming memory growth must remain bounded O(1), measured delta: {$memDiffMb} MB");

        echo " OK\n";
    }

    /**
     * R119: Private upload directory enforcement, sandboxing, and capabilities.
     */
    public function testR119_private_upload_permissions(): void
    {
        echo " - R119 (private_upload_permissions)...";

        // 1. Path traversal denial
        $traversalRejected = false;
        try {
            $this->stager->resolveStagedPath('../../../../etc/passwd');
        } catch (\InvalidArgumentException $e) {
            $traversalRejected = true;
        }
        $this->assert($traversalRejected === true, "Must reject directory traversal sequences");

        // 2. Traversal with embedded slash
        $slashRejected = false;
        try {
            $this->stager->resolveStagedPath('valid_id/../../wp-config.php');
        } catch (\InvalidArgumentException $e) {
            $slashRejected = true;
        }
        $this->assert($slashRejected === true, "Must reject file ID with forward slashes");

        // 3. File size limit rejection
        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_r119_') . '.csv';
        file_put_contents($tempFile, str_repeat("A", 2048));
        $this->cleanFiles[] = $tempFile;

        $smallStager = new CsvStager($this->wpdb, $this->testUploadDir, 1024); // max 1KB
        $sizeRejected = false;
        try {
            $smallStager->storeUploadedFile($tempFile, 'too_large.csv');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 413) {
                $sizeRejected = true;
            }
        }
        $this->assert($sizeRejected === true, "Must reject files exceeding max allowed size with 413");

        // 4. Capability check: non-admin user
        wp_set_current_user(0); // Guest
        $authRejected = false;
        try {
            $this->stager->storeUploadedFile($tempFile, 'auth_test.csv');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 403) {
                $authRejected = true;
            }
        }
        $this->assert($authRejected === true, "Must reject unprivileged user with 403");

        wp_set_current_user(1); // Restore admin

        echo " OK\n";
    }

    /**
     * AC-T29-01: Dry-run zero mutation; malformed rows capture exact row/column errors.
     */
    public function testAC_T29_01_malformed_row_exact_errors(): void
    {
        echo " - AC-T29-01 (malformed_row_exact_errors)...";

        // Row 2: Valid
        // Row 3: Column count mismatch (6 columns instead of 5)
        // Row 4: Invalid product type ('space_station')
        // Row 5: Duplicate SKU with Row 2 ('AC29-SKU-1')
        // Row 6: Invalid Image URL
        $csvContent = "Type,SKU,Name,Regular price,Images\n" .
            "simple,AC29-SKU-1,First Product,10.00,https://cdn.example.com/p1.png\n" .
            "simple,AC29-SKU-2,Second,20.00,extra_column_value,http://cdn.example.com/p2.png\n" .
            "space_station,AC29-SKU-3,Third Product,30.00,https://cdn.example.com/p3.png\n" .
            "simple,AC29-SKU-1,Duplicate SKU Item,40.00,https://cdn.example.com/p4.png\n" .
            "simple,AC29-SKU-5,Bad Image,50.00,not_a_valid_url\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_ac01_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'ac01_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        $report = $this->stager->validateJob($jobId);

        $this->assert($report['counts']['total_rows'] === 5, "Total rows must be 5");
        $this->assert($report['counts']['valid_rows'] === 1, "Only row 2 is completely valid");
        $this->assert($report['counts']['invalid_rows'] === 4, "Rows 3, 4, 5, 6 must be invalid");

        $errors = $report['errors'];

        // Check exact error on row 3: column count mismatch
        $errRow3 = array_values(array_filter($errors, fn($e) => $e['row_number'] === 3));
        $this->assert(!empty($errRow3), "Must capture error on row 3");
        $this->assert($errRow3[0]['code'] === 'column_count_mismatch', "Row 3 code must be column_count_mismatch");

        // Check exact error on row 4: invalid product type
        $errRow4 = array_values(array_filter($errors, fn($e) => $e['row_number'] === 4));
        $this->assert(!empty($errRow4), "Must capture error on row 4");
        $this->assert($errRow4[0]['code'] === 'invalid_product_type', "Row 4 code must be invalid_product_type");
        $this->assert($errRow4[0]['column'] === 'Type', "Row 4 column must be 'Type'");

        // Check exact error on row 5: duplicate SKU in file
        $errRow5 = array_values(array_filter($errors, fn($e) => $e['row_number'] === 5));
        $this->assert(!empty($errRow5), "Must capture error on row 5");
        $this->assert($errRow5[0]['code'] === 'duplicate_sku_in_file', "Row 5 code must be duplicate_sku_in_file");
        $this->assert($errRow5[0]['column'] === 'SKU', "Row 5 column must be 'SKU'");

        // Check exact error on row 6: invalid image URL
        $errRow6 = array_values(array_filter($errors, fn($e) => $e['row_number'] === 6));
        $this->assert(!empty($errRow6), "Must capture error on row 6");
        $this->assert($errRow6[0]['code'] === 'invalid_image_url', "Row 6 code must be invalid_image_url");

        echo " OK\n";
    }

    /**
     * AC-T29-02: Modified file or mismatched snapshot hash causes commit verification to reject.
     */
    public function testAC_T29_02_commit_hash_verification(): void
    {
        echo " - AC-T29-02 (commit_hash_verification)...";

        $csvContent = "Type,SKU,Name,Regular price\n" .
            "simple,AC29-COMMIT-01,Valid Item,12.50\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_test_ac02_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'ac02_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Run dry-run validation to establish snapshot hash
        $report = $this->stager->validateJob($jobId);
        $snapshotHash = $report['snapshot_hash'];

        // 1. Valid snapshot hash matches
        $resValid = $this->stager->verifyCommitPrerequisites($jobId, $snapshotHash);
        $this->assert($resValid['eligible'] === true, "Valid snapshot hash must pass commit prerequisites");

        // 2. Mismatched snapshot hash
        $resMismatch = $this->stager->verifyCommitPrerequisites($jobId, 'wrong_snapshot_hash_123456');
        $this->assert($resMismatch['eligible'] === false, "Mismatched snapshot hash must be rejected");
        $this->assert($resMismatch['code'] === 'stale_version', "Error code must be stale_version");

        // 3. Tamper with file on disk after validation
        file_put_contents($staged['file_path'], "simple,AC29-COMMIT-01,Tampered Name,999.00\n", FILE_APPEND);

        $resTampered = $this->stager->verifyCommitPrerequisites($jobId, $snapshotHash);
        $this->assert($resTampered['eligible'] === false, "Tampered file on disk must be rejected");
        $this->assert($resTampered['code'] === 'stale_version', "Tampered file error code must be stale_version");
        $this->assert(str_contains($resTampered['error'], 'modified after'), "Error message must state file was modified");

        echo " OK\n";
    }
}

$test = new T29Test();
$test->run();
