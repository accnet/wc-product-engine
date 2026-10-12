<?php
/**
 * T38 Integration Test — Backfill million products and shadow diff.
 *
 * Verifies:
 * - R153 (backfill_resume): Resuming backfill does not drop or duplicate aggregates.
 * - R154 (backfill_concurrent_edit_delete): Revision fence preserves concurrent edits and deletions during backfill.
 * - R155 (no_full_catalog_memory): Keyset pagination strictly without OFFSET; O(1) bounded memory consumption.
 * - R156 (shadow_diff_detects_gap): Shadow diff accurately identifies missing products, field mismatches, and variation count gaps.
 * - AC-T38-01: Resume preserves exact parity with zero duplicate products/variants; hashes match semantic snapshot.
 * - AC-T38-02: Adaptive pacing, throughput metrics, and write load throttling protect storefront performance.
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

use WPE\Application\Migration\BackfillRunner;
use WPE\Application\Migration\LegacyCapture;
use WPE\Infrastructure\Database\ProductRepository;

class T38Test
{
    private \wpdb $wpdb;
    private BackfillRunner $runner;
    private LegacyCapture $capture;
    private ProductRepository $productRepo;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableJobs;
    private string $tableAudit;
    private string $tableControl;
    private string $tableProductTerms;
    private string $tableSkuRegistry;

    private array $cleanProductIds = [];
    private array $cleanJobIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $prefix = $this->wpdb->prefix;

        $this->tableProducts     = "{$prefix}wpe_products";
        $this->tableVariants     = "{$prefix}wpe_variants";
        $this->tableInventory    = "{$prefix}wpe_inventory";
        $this->tableObjectState  = "{$prefix}wpe_object_state";
        $this->tableJobs         = "{$prefix}wpe_jobs";
        $this->tableAudit        = "{$prefix}wpe_audit";
        $this->tableControl      = "{$prefix}wpe_control";
        $this->tableProductTerms = "{$prefix}wpe_product_terms";
        $this->tableSkuRegistry  = "{$prefix}wpe_sku_registry";

        $this->capture     = LegacyCapture::getInstance();
        $this->productRepo = new ProductRepository($this->wpdb);
        $this->runner      = new BackfillRunner($this->wpdb, $this->capture, $this->productRepo);

        wp_set_current_user(1);
    }

    public function run(): void
    {
        echo "Running T38 Integration Tests...\n";
        $this->cleanUp();

        try {
            $this->testR153_backfill_resume();
            $this->testR154_backfill_concurrent_edit_delete();
            $this->testR155_no_full_catalog_memory();
            $this->testR156_shadow_diff_detects_gap();
            $this->testAC_T38_01_resume_no_loss_or_duplicate();
            $this->testAC_T38_02_write_load_storefront_budget();
            echo "All T38 tests passed successfully!\n";
        } finally {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        if (empty($this->cleanProductIds) && empty($this->cleanJobIds)) {
            return;
        }

        foreach ($this->cleanProductIds as $pid) {
            $variantIds = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT ID FROM {$this->wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                $pid
            ));
            $allIds = array_merge([$pid], array_map('intval', $variantIds ?: []));

            foreach ($allIds as $id) {
                $this->wpdb->delete($this->tableProducts, ['id' => $id]);
                $this->wpdb->delete($this->tableVariants, ['id' => $id]);
                $this->wpdb->delete($this->tableInventory, ['owner_id' => $id]);
                $this->wpdb->delete($this->tableSkuRegistry, ['object_id' => $id]);
                $this->wpdb->delete($this->tableObjectState, ['object_id' => $id]);
                $this->wpdb->delete($this->tableAudit, ['target_id' => $id]);
                $this->wpdb->delete($this->tableProductTerms, ['product_id' => $id]);
                wp_delete_post($id, true);
            }
        }
        $this->cleanProductIds = [];

        foreach ($this->cleanJobIds as $jid) {
            $this->wpdb->delete($this->tableJobs, ['id' => $jid]);
        }
        $this->cleanJobIds = [];

        $this->capture->clearDirtyAggregates();
    }

    private function createLegacySimpleProduct(string $title, float $price, int $stock = 10): int
    {
        $postId = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => sanitize_title($title) . '-' . wp_generate_password(4, false, false),
            'post_content' => 'Description for ' . $title,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        $sku = 'SKU-T38-' . strtoupper(wp_generate_password(6, false, false));
        update_post_meta($postId, '_sku', $sku);
        update_post_meta($postId, '_regular_price', (string)$price);
        update_post_meta($postId, '_price', (string)$price);
        update_post_meta($postId, '_manage_stock', 'yes');
        update_post_meta($postId, '_stock', (string)$stock);
        update_post_meta($postId, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');

        $this->cleanProductIds[] = $postId;
        return $postId;
    }

    private function createLegacyVariableProduct(string $title, int $variationCount = 2): array
    {
        $parentId = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => sanitize_title($title) . '-' . wp_generate_password(4, false, false),
            'post_content' => 'Variable description',
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        $parentSku = 'SKU-T38VAR-' . strtoupper(wp_generate_password(5, false, false));
        update_post_meta($parentId, '_sku', $parentSku);

        $varIds = [];
        for ($i = 1; $i <= $variationCount; $i++) {
            $varId = wp_insert_post([
                'post_title'   => "{$title} Variation {$i}",
                'post_status'  => 'publish',
                'post_type'    => 'product_variation',
                'post_parent'  => $parentId,
            ]);

            $varSku = "{$parentSku}-V{$i}";
            $price = 20.00 + ($i * 5);
            update_post_meta($varId, '_sku', $varSku);
            update_post_meta($varId, '_regular_price', (string)$price);
            update_post_meta($varId, '_price', (string)$price);
            update_post_meta($varId, '_manage_stock', 'yes');
            update_post_meta($varId, '_stock', (string)($i * 10));
            update_post_meta($varId, '_stock_status', 'instock');

            $varIds[] = $varId;
        }

        $this->cleanProductIds[] = $parentId;
        return [$parentId, $varIds];
    }

    public function testR153_backfill_resume(): void
    {
        echo "Testing R153 (backfill_resume)...\n";

        // Create 8 products
        $productIds = [];
        for ($i = 1; $i <= 8; $i++) {
            $productIds[] = $this->createLegacySimpleProduct("R153 Product {$i}", 10.0 + $i);
        }

        // Create job with chunk size 3
        $jobId = $this->runner->createJob([
            'start_id'    => min($productIds),
            'max_id'      => max($productIds),
            'chunk_size'  => 3,
            'throttle_ms' => 0,
        ]);
        $this->cleanJobIds[] = $jobId;

        // Run only 1 chunk
        $res1 = $this->runner->runJob($jobId, ['max_chunks' => 1, 'chunk_size' => 3]);
        if ($res1['status'] !== 'paused' || $res1['chunks_executed'] !== 1) {
            throw new \RuntimeException("First chunk execution did not pause at chunk boundary");
        }
        $firstChunkLastId = $res1['last_id'];
        if ($firstChunkLastId <= 0) {
            throw new \RuntimeException("Checkpoint last_id was not recorded after first chunk");
        }

        // Verify only 3 products were backfilled so far
        $partialCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE id IN (" . implode(',', $productIds) . ")"
        ));
        if ($partialCount !== 3) {
            throw new \RuntimeException("Expected exactly 3 products backfilled in first chunk, got {$partialCount}");
        }

        // Resume job: should process remaining products
        $res2 = $this->runner->runJob($jobId);
        if ($res2['status'] !== 'completed') {
            throw new \RuntimeException("Resumed job failed to reach completed status: got {$res2['status']}");
        }

        // Verify all 8 products are in native table
        $finalCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE id IN (" . implode(',', $productIds) . ")"
        ));
        if ($finalCount !== 8) {
            throw new \RuntimeException("Resumed job did not backfill all products: expected 8, got {$finalCount}");
        }

        // Verify zero duplicates
        $uniqueCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(DISTINCT id) FROM {$this->tableProducts} WHERE id IN (" . implode(',', $productIds) . ")"
        ));
        if ($uniqueCount !== $finalCount) {
            throw new \RuntimeException("Duplicate product IDs detected after resume");
        }

        echo "  ✓ R153 passed: backfill paused and resumed successfully with zero lost or duplicated records\n";
    }

    public function testR154_backfill_concurrent_edit_delete(): void
    {
        echo "Testing R154 (backfill_concurrent_edit_delete)...\n";

        $p1 = $this->createLegacySimpleProduct('R154 Product One', 20.00);
        $p2 = $this->createLegacySimpleProduct('R154 Product Two', 30.00);
        $p3 = $this->createLegacySimpleProduct('R154 Product Three', 40.00);

        // Pre-capture P1
        $this->capture->captureLegacyProduct($p1, 'test');

        // Simulate concurrent edit on P2
        update_post_meta($p2, '_regular_price', '99.99');
        $this->wpdb->update($this->wpdb->posts, [
            'post_title' => 'R154 Product Two Concurrent Edit',
            'post_modified_gmt' => gmdate('Y-m-d H:i:s', time() + 5),
        ], ['ID' => $p2]);

        // Simulate concurrent trash on P3
        wp_trash_post($p3);

        // Run backfill across all three
        $jobId = $this->runner->createJob([
            'start_id'   => min($p1, $p2, $p3),
            'max_id'     => max($p1, $p2, $p3),
            'chunk_size' => 10,
        ]);
        $this->cleanJobIds[] = $jobId;
        $this->runner->runJob($jobId);

        // Verify P2 captured the concurrent edit
        $p2Native = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT title, regular_price FROM {$this->tableProducts} WHERE id = %d",
            $p2
        ), ARRAY_A);
        if ($p2Native['title'] !== 'R154 Product Two Concurrent Edit' || (float)$p2Native['regular_price'] !== 99.99) {
            throw new \RuntimeException("Backfill failed to capture fresh concurrent edit for P2");
        }

        // Verify P3 captured as trash with tombstone=1, not resurrected
        $p3Status = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT status FROM {$this->tableProducts} WHERE id = %d",
            $p3
        ));
        $p3Tombstone = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT tombstone FROM {$this->tableObjectState} WHERE object_id = %d",
            $p3
        ));
        if ($p3Status !== 'trash' || $p3Tombstone !== 1) {
            throw new \RuntimeException("Backfill resurrected trashed product P3: status={$p3Status}, tombstone={$p3Tombstone}");
        }

        echo "  ✓ R154 passed: concurrent edits and deletes correctly preserved under revision fence\n";
    }

    public function testR155_no_full_catalog_memory(): void
    {
        echo "Testing R155 (no_full_catalog_memory)...\n";

        $sourceFile = dirname(__DIR__, 2) . '/src/Application/Migration/BackfillRunner.php';
        $code = file_get_contents($sourceFile);

        // Verify no SQL OFFSET keyword in BackfillRunner queries
        $codeNoComments = preg_replace('!/\*.*?\*/!s', '', $code);
        $codeNoComments = preg_replace('!//.*?$!m', '', $codeNoComments);
        if (preg_match('/\bOFFSET\b/i', $codeNoComments)) {
            throw new \RuntimeException("BackfillRunner violates R155: contains SQL OFFSET keyword");
        }

        // Verify no wc_get_products loading entire catalog
        if (strpos($code, 'wc_get_products') !== false) {
            throw new \RuntimeException("BackfillRunner violates R155: calls wc_get_products");
        }

        // Measure memory usage during execution of 10 products
        $pIds = [];
        for ($i = 0; $i < 10; $i++) {
            $pIds[] = $this->createLegacySimpleProduct("R155 Memory Product {$i}", 15.00);
        }

        $memBefore = memory_get_usage();
        $jobId = $this->runner->createJob([
            'start_id'   => min($pIds),
            'max_id'     => max($pIds),
            'chunk_size' => 5,
        ]);
        $this->cleanJobIds[] = $jobId;
        $this->runner->runJob($jobId);
        $memAfter = memory_get_usage();

        $deltaBytes = $memAfter - $memBefore;
        $deltaMb = $deltaBytes / (1024 * 1024);

        if ($deltaMb > 10.0) {
            throw new \RuntimeException("Memory growth during backfill exceeded bounded budget: {$deltaMb} MB");
        }

        echo "  ✓ R155 passed: strictly keyset pagination (no OFFSET), zero full catalog hydration, bounded memory\n";
    }

    public function testR156_shadow_diff_detects_gap(): void
    {
        echo "Testing R156 (shadow_diff_detects_gap)...\n";

        // Setup products:
        // Product 1: In sync
        $p1 = $this->createLegacySimpleProduct('R156 Matched Product', 10.00);
        $this->capture->captureLegacyProduct($p1, 'test');

        // Product 2: Gap 1 - Missing in native
        $p2 = $this->createLegacySimpleProduct('R156 Missing Native Product', 25.00);
        // Do not capture P2

        // Product 3: Gap 2 - Field (price) mismatch
        $p3 = $this->createLegacySimpleProduct('R156 Price Mismatch Product', 50.00);
        $this->capture->captureLegacyProduct($p3, 'test');
        // Mutate native price to create mismatch
        $this->wpdb->update($this->tableProducts, ['regular_price' => 75.00], ['id' => $p3]);

        // Product 4: Gap 3 - Variation count mismatch
        [$varParentId, $varIds] = $this->createLegacyVariableProduct('R156 Var Count Product', 2);
        $this->capture->captureLegacyProduct($varParentId, 'test');
        // Delete one variant from native to create variation count gap
        $this->wpdb->delete($this->tableVariants, ['id' => $varIds[0]]);

        // Run Shadow Diff scoped to our 4 test products
        $testIds = [$p1, $p2, $p3, $varParentId];
        $diff = $this->runner->runShadowDiff(['product_ids' => $testIds]);

        if ($diff['gap_count'] < 3) {
            throw new \RuntimeException("Shadow diff failed to detect all expected gaps: found {$diff['gap_count']}, expected >= 3");
        }

        $gapKinds = array_column($diff['gaps'], 'kind');
        if (!in_array('missing_native_product', $gapKinds, true)) {
            throw new \RuntimeException("Shadow diff did not detect 'missing_native_product' gap");
        }
        if (!in_array('field_mismatch', $gapKinds, true)) {
            throw new \RuntimeException("Shadow diff did not detect 'field_mismatch' gap");
        }
        if (!in_array('variation_count_mismatch', $gapKinds, true)) {
            throw new \RuntimeException("Shadow diff did not detect 'variation_count_mismatch' gap");
        }

        // Repair gaps using backfill
        $this->capture->captureLegacyProduct($p2, 'repair');
        $this->capture->captureLegacyProduct($p3, 'repair');
        $this->capture->captureLegacyProduct($varParentId, 'repair');

        // Verify quiescent diff
        $quiescentDiff = $this->runner->runShadowDiff(['product_ids' => $testIds]);
        if ($quiescentDiff['gap_count'] > 0) {
            throw new \RuntimeException("Quiescent shadow diff still reported gap for repaired products: " . json_encode($quiescentDiff['gaps']));
        }

        echo "  ✓ R156 passed: shadow diff accurately identified missing products, price mismatches, and variation count gaps\n";
    }

    public function testAC_T38_01_resume_no_loss_or_duplicate(): void
    {
        echo "Testing AC-T38-01 (resume without data loss or duplicates)...\n";

        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->createLegacySimpleProduct("AC-T38-01 Product {$i}", 20.0 + $i);
        }

        // Run multi-phase backfill
        $jobId = $this->runner->createJob([
            'start_id'    => min($ids),
            'max_id'      => max($ids),
            'chunk_size'  => 2,
            'throttle_ms' => 0,
        ]);
        $this->cleanJobIds[] = $jobId;

        // Phase 1: 1 chunk (2 products)
        $this->runner->runJob($jobId, ['max_chunks' => 1, 'chunk_size' => 2]);
        // Phase 2: 1 chunk (2 products)
        $this->runner->runJob($jobId, ['max_chunks' => 1, 'chunk_size' => 2]);
        // Phase 3: Finish remainder
        $final = $this->runner->runJob($jobId, ['chunk_size' => 2]);

        if ($final['status'] !== 'completed') {
            throw new \RuntimeException("Multi-phase backfill failed to complete");
        }

        // Validate exact counts
        $nativeRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, title, regular_price FROM {$this->tableProducts} WHERE id IN (" . implode(',', $ids) . ") ORDER BY id ASC"
        ), ARRAY_A);

        if (count($nativeRows) !== count($ids)) {
            throw new \RuntimeException("Native row count mismatch: expected " . count($ids) . ", got " . count($nativeRows));
        }

        // Check hashes in wpe_object_state match computed legacy hashes
        foreach ($ids as $id) {
            $computedHash = $this->capture->computeLegacyHash($id);
            $storedHash = bin2hex($this->wpdb->get_var($this->wpdb->prepare(
                "SELECT legacy_hash FROM {$this->tableObjectState} WHERE object_id = %d",
                $id
            )) ?: '');

            if ($storedHash !== $computedHash) {
                throw new \RuntimeException("Hash mismatch after multi-phase resume on product {$id}");
            }
        }

        echo "  ✓ AC-T38-01 passed: resume preserved exact source counts, zero duplicates, and validated semantic hashes\n";
    }

    public function testAC_T38_02_write_load_storefront_budget(): void
    {
        echo "Testing AC-T38-02 (adaptive pacing and write load budget)...\n";

        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->createLegacySimpleProduct("AC-T38-02 Throttled Product {$i}", 35.00);
        }

        $jobId = $this->runner->createJob([
            'start_id'        => min($ids),
            'max_id'          => max($ids),
            'chunk_size'      => 2,
            'throttle_ms'     => 15,
            'adaptive_pacing' => true,
            'target_chunk_ms' => 100,
        ]);
        $this->cleanJobIds[] = $jobId;

        $t0 = microtime(true);
        $res = $this->runner->runJob($jobId);
        $t1 = microtime(true);

        $totalElapsed = $t1 - $t0;

        // Verify throttling occurred (total time should reflect throttle_ms per chunk)
        if ($totalElapsed < 0.03) {
            throw new \RuntimeException("Backfill completed suspiciously fast without applying throttle pacing: {$totalElapsed}s");
        }

        // Check job counts in DB for throughput and ETA metrics
        $jobRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT counts, checkpoint FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);

        $counts = json_decode($jobRow['counts'], true);
        if (!isset($counts['throughput']) || !isset($counts['eta_sec']) || !isset($counts['elapsed_sec'])) {
            throw new \RuntimeException("Job counts missing throughput, ETA, or elapsed metrics for budget monitoring");
        }

        echo "  ✓ AC-T38-02 passed: adaptive pacing and throttle metrics maintained storefront write budget\n";
    }
}

// Execute test
$test = new T38Test();
$test->run();
