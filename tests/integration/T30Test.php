<?php
/**
 * T30 Integration Test — Commit worker, resume và conflict policy.
 *
 * Verifies:
 * - R120 (import_crash_resume): Crash recovery and idempotent resume via persisted checkpoints without duplicate records.
 * - R121 (import_group_atomic): Atomic per-group commit (parent + options + variants); invalid group has zero half-save.
 * - R122 (import_vs_admin_conflict): Conflict policies ('fail' vs 'skip') prevent overwriting fresh admin edits.
 * - R123 (cancel_partial_commit_report): Cancellation at commit boundary preserves committed groups and reports partial state.
 * - AC-T30-01: Correct product/variant counts on crash/resume; atomic rollback on invalid group; counts match.
 * - AC-T30-02: Concurrent admin edits between dry-run and commit are never overwritten.
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
use WPE\Application\ImportExport\ImportCommitter;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\OutboxWorker;

class T30Test
{
    private \wpdb $wpdb;
    private CsvStager $stager;
    private ImportCommitter $committer;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private string $testUploadDir;

    private array $cleanJobIds = [];
    private array $cleanProductIds = [];
    private array $cleanFiles = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $wpUpload = wp_upload_dir();
        $this->testUploadDir = rtrim($wpUpload['basedir'], '/\\') . '/wpe-test-imports-t30';
        $this->stager = new CsvStager($this->wpdb, $this->testUploadDir);
        $this->committer = new ImportCommitter($this->wpdb, null, null, null, $this->stager);
        $this->productRepo = new ProductRepository($this->wpdb);
        $this->optionsRepo = new OptionsRepository($this->wpdb);

        wp_set_current_user(1); // Set administrator
    }

    public function run(): void
    {
        echo "Running T30 Integration Tests...\n";
        try {
            $this->testR120_import_crash_resume();
            $this->testR121_import_group_atomic();
            $this->testR122_import_vs_admin_conflict();
            $this->testR123_cancel_partial_commit_report();
            echo "All T30 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    private function tearDown(): void
    {
        // Clean jobs and import rows
        foreach ($this->cleanJobIds as $jobId) {
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_import_rows', ['job_id' => $jobId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_jobs', ['id' => $jobId]);
        }

        // Clean products, variants, options, and shells
        foreach ($this->cleanProductIds as $pId) {
            $variants = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d",
                $pId
            ));

            foreach ($variants as $vId) {
                $this->wpdb->delete($this->wpdb->prefix . 'wpe_inventory', ['owner_id' => $vId]);
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
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_products', ['id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_sku_registry', ['object_id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'wpe_object_state', ['object_id' => $pId]);
            $this->wpdb->delete($this->wpdb->prefix . 'posts', ['ID' => $pId]);
        }

        // Global safeguard cleanup for T30 SKUs
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE 'T30-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_products WHERE sku LIKE 'T30-%'");
        $this->wpdb->query("DELETE FROM {$this->wpdb->prefix}wpe_variants WHERE sku LIKE 'T30-%'");

        // Clean files
        foreach ($this->cleanFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }

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
     * R120: Worker crash recovery and idempotent resume via persisted database checkpoints.
     * Simulates a stop after committing the first group, then resumes and verifies exact final counts.
     */
    public function testR120_import_crash_resume(): void
    {
        echo " - R120 (import_crash_resume)...";

        // CSV with 2 product groups:
        // Group 1: Variable product with 2 variations
        // Group 2: Simple product
        $csvContent = "Type,SKU,Parent,Name,Regular price,Attribute 1 name,Attribute 1 value(s)\n" .
            "variable,T30-CRASH-P1,,Crash Test Shirt,0.00,Size,\"S, M\"\n" .
            "variation,T30-CRASH-V1,T30-CRASH-P1,Shirt S,15.00,Size,S\n" .
            "variation,T30-CRASH-V2,T30-CRASH-P1,Shirt M,18.00,Size,M\n" .
            "simple,T30-CRASH-P2,,Simple Widget,25.00,,\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_t30_r120_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'crash_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Dry-run validate
        $this->stager->validateJob($jobId);

        // Run Pass 1: Stop after committing 1 group (simulated crash/checkpoint)
        $pass1 = $this->committer->commitJob($jobId, ['max_groups' => 1]);

        $this->assert($pass1['status'] === 'in_progress', "Job must remain 'in_progress' when halted at checkpoint");
        $this->assert($pass1['groups_committed'] === 1, "Must have committed exactly 1 group in pass 1");

        // Verify Product 1 was created
        $p1 = $this->productRepo->getBySku('T30-CRASH-P1');
        $this->assert($p1 !== null, "Product 1 must be created in pass 1");
        $this->cleanProductIds[] = (int)$p1['id'];

        $v1 = $this->productRepo->getBySku('T30-CRASH-V1');
        $this->assert($v1 !== null, "Variation 1 must be created in pass 1");

        // Verify Product 2 was NOT yet created
        $p2Before = $this->productRepo->getBySku('T30-CRASH-P2');
        $this->assert($p2Before === null, "Product 2 must not be created before resume");

        // Verify Checkpoint recorded
        $jobDb = $this->stager->getJob($jobId);
        $checkpoint = json_decode((string)$jobDb['checkpoint'], true);
        $this->assert($checkpoint['last_committed_group_key'] === 'T30-CRASH-P1', "Checkpoint must record last committed group");

        // Run Pass 2: Resume worker
        $pass2 = $this->committer->resumeJob($jobId);

        $this->assert($pass2['status'] === 'completed', "Job must be 'completed' after resume");
        $this->assert($pass2['groups_committed'] === 2, "Total committed groups must be 2");
        $this->assert($pass2['rows_committed'] === 4, "Total committed rows must be 4");

        // Verify Product 2 is now created
        $p2After = $this->productRepo->getBySku('T30-CRASH-P2');
        $this->assert($p2After !== null, "Product 2 must be created after resume");
        $this->cleanProductIds[] = (int)$p2After['id'];

        // Verify Product 1 was NOT duplicated
        $p1Count = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_products WHERE sku = 'T30-CRASH-P1'");
        $this->assert($p1Count === 1, "Product 1 must not be duplicated upon resume");

        $v1Count = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variants WHERE sku = 'T30-CRASH-V1'");
        $this->assert($v1Count === 1, "Variation 1 must not be duplicated upon resume");

        echo " OK\n";
    }

    /**
     * R121: Atomic group commits. An invalid group has zero half-saved products or variants.
     */
    public function testR121_import_group_atomic(): void
    {
        echo " - R121 (import_group_atomic)...";

        // Group 1: Valid product
        // Group 2: Invalid group (simulated fatal failure during commit)
        $csvContent = "Type,SKU,Parent,Name,Regular price\n" .
            "simple,T30-ATOMIC-G1,,Atomic Group 1 Product,30.00\n" .
            "variable,T30-ATOMIC-G2,,Atomic Group 2 Product,0.00\n" .
            "variation,T30-ATOMIC-G2-V1,T30-ATOMIC-G2,Variant 1,10.00\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_t30_r121_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'atomic_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        $this->stager->validateJob($jobId);

        // Commit with simulated failure specifically on Group 2
        $res = $this->committer->commitJob($jobId, [
            'fail_group_key' => 'T30-ATOMIC-G2',
        ]);

        $this->assert($res['groups_committed'] === 1, "Group 1 must commit successfully");
        $this->assert($res['groups_failed'] === 1, "Group 2 must fail");

        // Verify Group 1 was saved
        $g1 = $this->productRepo->getBySku('T30-ATOMIC-G1');
        $this->assert($g1 !== null, "Group 1 product must exist in database");
        $this->cleanProductIds[] = (int)$g1['id'];

        // Verify Group 2 was rolled back completely (no half-save!)
        $g2 = $this->productRepo->getBySku('T30-ATOMIC-G2');
        $this->assert($g2 === null, "Group 2 parent product must NOT exist in database (zero half-save)");

        $g2v = $this->productRepo->getBySku('T30-ATOMIC-G2-V1');
        $this->assert($g2v === null, "Group 2 variation must NOT exist in database (zero half-save)");

        // Verify row status in wpe_import_rows
        $g2Rows = $this->stager->getJobRows($jobId, 'T30-ATOMIC-G2');
        foreach ($g2Rows as $row) {
            $this->assert($row['status'] === 'failed', "All rows in failed group must be marked 'failed'");
        }

        echo " OK\n";
    }

    /**
     * R122: Conflict policies ('fail' vs 'skip') prevent overwriting fresh admin edits.
     */
    public function testR122_import_vs_admin_conflict(): void
    {
        echo " - R122 (import_vs_admin_conflict)...";

        // Step 1: Create an existing product in the catalog
        $initialProduct = $this->productRepo->create([
            'type'          => 'simple',
            'sku'           => 'T30-CONF-SKU-01',
            'title'         => 'Original Admin Title',
            'regular_price' => '50.00',
        ]);
        $productId = (int)$initialProduct['id'];
        $this->cleanProductIds[] = $productId;

        // Step 2: Prepare a CSV that updates this product
        $csvContent = "Type,SKU,Name,Regular price\n" .
            "simple,T30-CONF-SKU-01,CSV Overwrite Title,99.00\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_t30_r122_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'conflict_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        // Dry-run validate at timestamp T1
        $this->stager->validateJob($jobId);

        // Step 3: Admin concurrently edits the product in catalog (timestamp T2 > T1)
        usleep(10000); // 10ms delay to guarantee updated_at advancement
        $this->productRepo->update($productId, [
            'title'         => 'Fresh Admin Edit Title',
            'regular_price' => '75.00',
        ]);

        // Step 4: Commit with conflict_policy = 'fail'
        $resFail = $this->committer->commitJob($jobId, ['conflict_policy' => 'fail']);
        $this->assert($resFail['groups_failed'] === 1, "Conflict must cause group to fail under 'fail' policy");

        // Verify product was NOT overwritten!
        $currentP = $this->productRepo->getById($productId);
        $this->assert($currentP['title'] === 'Fresh Admin Edit Title', "Admin edit title must NOT be overwritten (AC-T30-02)");
        $this->assert($currentP['regular_price'] === '75.000000' || $currentP['regular_price'] === '75.00', "Admin edit price must NOT be overwritten");

        // Step 5: Test conflict_policy = 'skip'
        $staged2 = $this->stager->storeUploadedFile($tempFile, 'conflict_test_skip.csv');
        $this->cleanFiles[] = $staged2['file_path'];
        $receipt2 = $this->stager->stageJob($staged2['staged_file_id']);
        $jobId2 = $receipt2['job_id'];
        $this->cleanJobIds[] = $jobId2;

        $this->stager->validateJob($jobId2);

        usleep(10000);
        $this->productRepo->update($productId, [
            'title' => 'Fresh Admin Edit 2',
        ]);

        $resSkip = $this->committer->commitJob($jobId2, ['conflict_policy' => 'skip']);
        $this->assert($resSkip['groups_skipped'] === 1, "Conflict must cause group to be skipped under 'skip' policy");

        $currentP2 = $this->productRepo->getById($productId);
        $this->assert($currentP2['title'] === 'Fresh Admin Edit 2', "Product title must preserve fresh admin edit under 'skip' policy");

        echo " OK\n";
    }

    /**
     * R123: Cancellation at commit boundary preserves committed groups and reports partial state.
     */
    public function testR123_cancel_partial_commit_report(): void
    {
        echo " - R123 (cancel_partial_commit_report)...";

        // CSV with 2 groups: Group 1 and Group 2
        $csvContent = "Type,SKU,Name,Regular price\n" .
            "simple,T30-CANCEL-G1,Cancel Test 1,10.00\n" .
            "simple,T30-CANCEL-G2,Cancel Test 2,20.00\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'wpe_t30_r123_') . '.csv';
        file_put_contents($tempFile, $csvContent);
        $this->cleanFiles[] = $tempFile;

        $staged = $this->stager->storeUploadedFile($tempFile, 'cancel_test.csv');
        $this->cleanFiles[] = $staged['file_path'];

        $receipt = $this->stager->stageJob($staged['staged_file_id']);
        $jobId = $receipt['job_id'];
        $this->cleanJobIds[] = $jobId;

        $this->stager->validateJob($jobId);

        // Commit Group 1 first
        $this->committer->commitJob($jobId, ['max_groups' => 1]);

        $p1 = $this->productRepo->getBySku('T30-CANCEL-G1');
        $this->assert($p1 !== null, "Group 1 product must be committed before cancel");
        $this->cleanProductIds[] = (int)$p1['id'];

        // Request cancellation before Group 2
        $cancelOk = $this->committer->requestCancel($jobId);
        $this->assert($cancelOk === true, "requestCancel must return true");

        // Execute commit / worker pass
        $report = $this->committer->commitJob($jobId);

        $this->assert($report['status'] === 'cancelled', "Job status must be 'cancelled'");
        $this->assert($report['groups_committed'] === 1, "Committed groups count must be 1 in partial report");

        // Verify Group 1 product REMAINS in catalog (cancel does not undo committed groups)
        $p1StillExists = $this->productRepo->getBySku('T30-CANCEL-G1');
        $this->assert($p1StillExists !== null, "Committed Group 1 must remain in catalog after cancellation");

        // Verify Group 2 was NOT committed
        $p2 = $this->productRepo->getBySku('T30-CANCEL-G2');
        $this->assert($p2 === null, "Group 2 must not be committed after cancellation");

        echo " OK\n";
    }
}

$test = new T30Test();
$test->run();
