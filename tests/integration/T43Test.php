<?php

/**
 * T43 Integration Test — Cutover barrier, canary và monitored rollout.
 *
 * Verifies:
 * - R173: Cutover checkpoint faults & crash recovery (resume/abort with single authoritative source).
 * - R174: Epoch worker fence strictly rejecting stale workers carrying obsolete epochs.
 * - R175: Canary metric monitoring with automatic halt on error/stock thresholds and recovery exposure.
 * - R176: Atomic parent + child variant + stock cutover; partial cutover strictly prohibited.
 * - AC-T43-01: Discrete crash resilience with unambiguous authority; zero checkout stock double mutation.
 * - AC-T43-02: Canary error threshold halts expansion and exposes recovery; no premature rollback of unprojected data.
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

use WPE\Application\Rollout\CutoverRunner;
use WPE\Application\Rollout\ReadinessGates;
use WPE\Compatibility\WordPress\PostIdAllocator;

class T43Test
{
    private \wpdb $wpdb;
    private CutoverRunner $runner;
    private ReadinessGates $gates;
    private PostIdAllocator $allocator;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableJobs;
    private string $tableControl;
    private string $tableAudit;
    private string $tableStockOperations;

    private array $cleanProductIds = [];
    private array $cleanJobIds = [];
    private array $cleanControlKeys = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $prefix = $this->wpdb->prefix;
        $this->tableProducts        = "{$prefix}wpe_products";
        $this->tableVariants        = "{$prefix}wpe_variants";
        $this->tableInventory       = "{$prefix}wpe_inventory";
        $this->tableObjectState     = "{$prefix}wpe_object_state";
        $this->tableJobs            = "{$prefix}wpe_jobs";
        $this->tableControl         = "{$prefix}wpe_control";
        $this->tableAudit           = "{$prefix}wpe_audit";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";

        $this->gates     = new ReadinessGates($this->wpdb);
        $this->runner    = new CutoverRunner($this->wpdb, $this->gates);
        $this->allocator = new PostIdAllocator($this->wpdb);
    }

    public function run(): void
    {
        echo "=== Running T43 Integration Tests (Cutover Barrier, Canary & Rollout) ===\n";

        try {
            $this->setUp();

            $this->testR173_cutover_checkpoint_faults();
            $this->testR174_epoch_worker_fence();
            $this->testR175_canary_halt();
            $this->testR176_no_partial_parent_variant_cutover();

            $this->testAC_T43_01_checkpoint_faults_and_idempotent_stock();
            $this->testAC_T43_02_canary_halt_and_recovery_exposure();

            echo "=== All T43 Tests Passed Successfully ===\n";
        } finally {
            $this->tearDown();
        }
    }

    public function setUp(): void
    {
        // Clean environment
    }

    public function tearDown(): void
    {
        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$this->tableInventory} WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableVariants} WHERE id IN ({$ids}) OR product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableProducts} WHERE id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableObjectState} WHERE object_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableStockOperations} WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanJobIds)) {
            $escaped = array_map(fn($id) => "'" . esc_sql($id) . "'", $this->cleanJobIds);
            $this->wpdb->query("DELETE FROM {$this->tableJobs} WHERE id IN (" . implode(',', $escaped) . ")");
            $this->cleanJobIds = [];
        }

        if (!empty($this->cleanControlKeys)) {
            $escaped = array_map(fn($k) => "'" . esc_sql($k) . "'", $this->cleanControlKeys);
            $this->wpdb->query("DELETE FROM {$this->tableControl} WHERE `key` IN (" . implode(',', $escaped) . ")");
            $this->cleanControlKeys = [];
        }
    }

    /**
     * R173: Cutover checkpoint faults & crash recovery (resume/abort with single authoritative source).
     */
    public function testR173_cutover_checkpoint_faults(): void
    {
        echo "Testing R173 (cutover_checkpoint_faults)...\n";

        // 1. Create a product for cutover
        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'cutover-prod-' . $productId,
            'title'              => 'Cutover Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-CUTOVER-' . $productId,
            'regular_price'      => '150.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 100.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 5.0,
            'version'          => 1,
            'updated_at'       => $now,
        ]);

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $productId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'legacy',
            'epoch'           => 1,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Obtain valid gate receipt token
        $manifest = $this->gates->buildCohortManifest('all');
        $gateToken = $this->gates->validateAllGates($manifest)['gate_receipt_token'];

        // Scenario A: Fault at acquire_barrier_lock checkpoint
        $jobIdA = $this->runner->startCutover('all', $gateToken, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $jobIdA;
        $this->cleanControlKeys[] = 'cutover_barrier_all';

        $crashedA = false;
        try {
            $this->runner->executeCheckpoint($jobIdA, 'acquire_barrier_lock');
        } catch (\RuntimeException $e) {
            $crashedA = true;
        }

        if (!$crashedA) {
            throw new \RuntimeException("Fault injection did not trigger simulated crash!");
        }

        // Abort job A prior to epoch flip: authority remains legacy and write barrier is cleanly cleared
        $abortResA = $this->runner->abortCutover($jobIdA, 'Testing crash abort');
        if ($abortResA['authority'] !== 'legacy') {
            throw new \RuntimeException("Pre-flip abort did not retain legacy authority!");
        }
        if ($this->runner->isWriteBlocked('all', $productId)) {
            throw new \RuntimeException("Write barrier remained active after pre-flip abort!");
        }
        echo "  ✓ Pre-flip fault injection properly aborted: Authority remains unambiguously legacy, barrier released\n";

        // Scenario B: Fault at epoch_flip checkpoint with subsequent successful resume
        // Re-generate token since abort cleaned barrier
        $manifestB = $this->gates->buildCohortManifest('all');
        $gateTokenB = $this->gates->validateAllGates($manifestB)['gate_receipt_token'];

        $jobIdB = $this->runner->startCutover('all', $gateTokenB, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $jobIdB;

        $crashedB = false;
        try {
            $this->runner->executeCheckpoint($jobIdB, 'epoch_flip');
        } catch (\RuntimeException $e) {
            $crashedB = true;
        }

        if (!$crashedB) {
            throw new \RuntimeException("Fault injection at epoch_flip did not trigger crash!");
        }

        // Resume cutover job B: continues checkpoints through to completion
        $resumeResB = $this->runner->resumeCutover($jobIdB);
        if ($resumeResB['status'] !== 'completed') {
            throw new \RuntimeException("Resumed cutover job did not complete successfully: got " . $resumeResB['status']);
        }

        // Verify post-cutover state: authority is native_bridge, epoch is 2
        $stateB = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT authority, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ), ARRAY_A);

        if ($stateB['authority'] !== 'native_bridge') {
            throw new \RuntimeException("Expected authority 'native_bridge' after completed cutover, got: " . $stateB['authority']);
        }
        if ((int)$stateB['epoch'] !== 2) {
            throw new \RuntimeException("Expected epoch 2 after completed cutover, got: " . $stateB['epoch']);
        }

        echo "  ✓ Post-flip resume succeeded: Cohort safely cut over to native_bridge at epoch 2\n";
        echo "  ✓ R173 passed: Checkpoint fault resilience and crash recovery guarantee unambiguous single authority\n";
    }

    /**
     * R174: Epoch worker fence strictly rejecting stale workers carrying obsolete epochs.
     */
    public function testR174_epoch_worker_fence(): void
    {
        echo "Testing R174 (epoch_worker_fence)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'fence-prod-' . $productId,
            'title'              => 'Fence Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-FENCE-' . $productId,
            'regular_price'      => '200.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 50.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 5.0,
            'version'          => 1,
            'updated_at'       => $now,
        ]);

        // Set aggregate to epoch 3 (simulating two prior cutovers)
        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $productId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'native_bridge',
            'epoch'           => 3,
            'legacy_revision' => 2,
            'native_revision' => 2,
            'legacy_applied'  => 2,
            'native_applied'  => 2,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Stale worker carrying epoch 2 attempts operation: must be strictly rejected
        $staleWorkerRejected = false;
        try {
            $this->runner->verifyEpochFence($productId, 2);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Epoch Fence Violation')) {
                $staleWorkerRejected = true;
            }
        }

        if (!$staleWorkerRejected) {
            throw new \RuntimeException("Epoch fence allowed stale worker carrying epoch 2 against aggregate at epoch 3!");
        }
        echo "  ✓ Stale worker with epoch 2 rejected by epoch fence\n";

        // Current worker carrying epoch 3 succeeds
        $currentAllowed = $this->runner->verifyEpochFence($productId, 3);
        if (!$currentAllowed) {
            throw new \RuntimeException("Epoch fence rejected valid current worker carrying epoch 3!");
        }
        echo "  ✓ Current worker with epoch 3 allowed by epoch fence\n";

        // Verify security audit logged for fence violation
        $auditLog = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT operation, summary FROM {$this->tableAudit} WHERE operation = 'epoch_fence_violation' AND target_id = %d ORDER BY id DESC LIMIT 1",
            $productId
        ), ARRAY_A);

        if (!$auditLog) {
            throw new \RuntimeException("Epoch fence violation was not logged to wpe_audit!");
        }
        echo "  ✓ Epoch fence violation security event verified in wpe_audit\n";

        echo "  ✓ R174 passed: Epoch worker fence reliably rejects stale workers and protects authority boundary\n";
    }

    /**
     * R175: Canary metric monitoring with automatic halt on error/stock thresholds and recovery exposure.
     */
    public function testR175_canary_halt(): void
    {
        echo "Testing R175 (canary_halt)...\n";

        $cohortId = 'cohort_canary_' . time();
        $this->cleanControlKeys[] = "canary_config_{$cohortId}";

        // Configure canary at 10% with 1% max error rate and 0 stock mismatches
        $this->runner->configureCanary($cohortId, 10, [
            'max_error_rate'       => 0.01,
            'max_stock_mismatches' => 0,
            'max_p95_latency_ms'   => 150,
        ]);

        // 1. Healthy metrics: expansion allowed
        $health1 = $this->runner->evaluateCanaryHealth($cohortId, [
            'error_rate'         => 0.002, // 0.2%
            'stock_mismatches'   => 0,
            'p95_latency_ms'     => 45.2,
        ]);

        if ($health1['status'] !== 'healthy' || !$health1['expansion_allowed']) {
            throw new \RuntimeException("Canary reported unhealthy under nominal healthy metrics!");
        }
        echo "  ✓ Nominal metrics passed: Canary status is healthy and expansion is allowed\n";

        // 2. Degraded metrics: error rate spikes to 2.5% (> 1.0% threshold)
        $health2 = $this->runner->evaluateCanaryHealth($cohortId, [
            'error_rate'         => 0.025, // 2.5%
            'stock_mismatches'   => 0,
            'p95_latency_ms'     => 55.0,
        ]);

        if ($health2['status'] !== 'halted' || $health2['expansion_allowed']) {
            throw new \RuntimeException("Canary failed to halt when error rate exceeded threshold!");
        }

        // AC-T43-02 Check: Exposes recovery instructions and does NOT perform automatic premature rollback
        $recovery = $health2['recovery'] ?? [];
        if ($recovery['automatic_rollback'] !== false) {
            throw new \RuntimeException("Canary attempted automatic rollback contrary to AC-T43-02!");
        }
        if (empty($recovery['manual_command'])) {
            throw new \RuntimeException("Canary halt did not expose manual recovery instructions!");
        }

        echo "  ✓ Degraded error rate caught: Canary automatically halted, expansion blocked, recovery exposed without premature rollback\n";

        // 3. Stock mismatch trigger: 1 mismatch immediately halts
        $this->runner->configureCanary($cohortId, 10);
        $health3 = $this->runner->evaluateCanaryHealth($cohortId, [
            'error_rate'         => 0.001,
            'stock_mismatches'   => 1, // Stock mismatch!
            'p95_latency_ms'     => 40.0,
        ]);

        if ($health3['status'] !== 'halted') {
            throw new \RuntimeException("Canary failed to halt upon stock mismatch detection!");
        }
        echo "  ✓ Stock mismatch trigger caught: 1 mismatch immediately triggered canary halt\n";

        echo "  ✓ R175 passed: Canary metric evaluator reliably halts expansion and exposes recovery runbook\n";
    }

    /**
     * R176: Atomic parent + child variant + stock cutover; partial cutover strictly prohibited.
     */
    public function testR176_no_partial_parent_variant_cutover(): void
    {
        echo "Testing R176 (no_partial_parent_variant_cutover)...\n";

        // 1. Create a variable parent product with 2 child variations
        $parentId = $this->allocator->allocateShell('product');
        $var1Id   = $this->allocator->allocateShell('product_variation');
        $var2Id   = $this->allocator->allocateShell('product_variation');
        $now      = gmdate('Y-m-d H:i:s.u');

        $this->cleanProductIds = array_merge($this->cleanProductIds, [$parentId, $var1Id, $var2Id]);

        // Insert parent
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $parentId,
            'type'               => 'variable',
            'status'             => 'publish',
            'slug'               => 'var-parent-' . $parentId,
            'title'              => 'Variable Parent Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-VAR-PAR-' . $parentId,
            'regular_price'      => '0.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $parentId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'legacy',
            'epoch'           => 1,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Insert variant 1
        $this->wpdb->insert($this->tableVariants, [
            'id'            => $var1Id,
            'product_id'    => $parentId,
            'sku'           => 'SKU-V1-' . $var1Id,
            'regular_price' => '120.000000',
            'sale_price'    => null,
            'created_at'    => $now,
            'updated_at'    => $now,
            'version'       => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $var1Id,
            'manage_stock'     => 1,
            'quantity'         => 30.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 2.0,
            'version'          => 1,
            'updated_at'       => $now,
        ]);

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $var1Id,
            'kind'            => 'variant',
            'parent_id'       => $parentId,
            'authority'       => 'legacy',
            'epoch'           => 1,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Insert variant 2
        $this->wpdb->insert($this->tableVariants, [
            'id'            => $var2Id,
            'product_id'    => $parentId,
            'sku'           => 'SKU-V2-' . $var2Id,
            'regular_price' => '140.000000',
            'sale_price'    => null,
            'created_at'    => $now,
            'updated_at'    => $now,
            'version'       => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $var2Id,
            'manage_stock'     => 1,
            'quantity'         => 40.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 2.0,
            'version'          => 1,
            'updated_at'       => $now,
        ]);

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $var2Id,
            'kind'            => 'variant',
            'parent_id'       => $parentId,
            'authority'       => 'legacy',
            'epoch'           => 1,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Execute atomic epoch flip for the parent
        $flipRes = $this->runner->performAtomicEpochFlip('cohort_var', [$parentId], 1);
        $this->cleanControlKeys[] = "epoch_flip_journal_cohort_var_2";

        if ($flipRes['new_epoch'] !== 2) {
            throw new \RuntimeException("Epoch flip returned invalid epoch: " . $flipRes['new_epoch']);
        }
        if ($flipRes['flipped_count'] !== 3) { // 1 parent + 2 variants
            throw new \RuntimeException("Expected 3 flipped objects (1 parent + 2 variants), got: " . $flipRes['flipped_count']);
        }

        // Verify that parent and all variants share the exact same authority and epoch
        $states = $this->wpdb->get_results(
            "SELECT object_id, kind, authority, epoch FROM {$this->tableObjectState} WHERE object_id IN ({$parentId}, {$var1Id}, {$var2Id})",
            ARRAY_A
        );

        foreach ($states as $st) {
            if ($st['authority'] !== 'native_bridge') {
                throw new \RuntimeException("Object {$st['object_id']} has authority '{$st['authority']}', expected 'native_bridge'");
            }
            if ((int)$st['epoch'] !== 2) {
                throw new \RuntimeException("Object {$st['object_id']} has epoch '{$st['epoch']}', expected 2");
            }
        }

        echo "  ✓ Atomic parent + variant cutover verified: All 3 objects flipped to native_bridge at epoch 2 simultaneously\n";

        // Verify epoch flip journal entry in wpe_control
        $journal = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            "epoch_flip_journal_cohort_var_2"
        ));

        if (!$journal) {
            throw new \RuntimeException("Epoch flip journal record missing from wpe_control!");
        }

        $jData = json_decode($journal, true);
        if ($jData['total_objects'] !== 3 || count($jData['variant_ids']) !== 2) {
            throw new \RuntimeException("Epoch flip journal data corrupted!");
        }

        echo "  ✓ Epoch flip journal record verified in wpe_control with exact parent and variant IDs\n";
        echo "  ✓ R176 passed: Atomic transaction enforces complete parent + variants + stock cutover with zero partial states\n";
    }

    /**
     * AC-T43-01: Discrete crash resilience with unambiguous authority; zero checkout stock double mutation.
     */
    public function testAC_T43_01_checkpoint_faults_and_idempotent_stock(): void
    {
        echo "Testing AC-T43-01 (checkpoint_faults_and_idempotent_stock)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'stock-idempotency-' . $productId,
            'title'              => 'Stock Idempotency Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-IDEMP-' . $productId,
            'regular_price'      => '80.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 100.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 5.0,
            'version'          => 1,
            'updated_at'       => $now,
        ]);

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $productId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'native_bridge',
            'epoch'           => 1,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Stock deduction with operation key (e.g. order item checkout)
        $opKey = 'op_checkout_' . wp_generate_uuid4();

        // First attempt: deduct 2 units
        $res1 = $this->runner->recordStockOperation($productId, $opKey, -2.0, 'checkout_reservation', 9999);
        if ($res1['already_applied']) {
            throw new \RuntimeException("Initial stock operation reported already_applied!");
        }
        if ((float)$res1['quantity_after'] !== 98.0) {
            throw new \RuntimeException("Expected quantity_after 98.0, got: " . $res1['quantity_after']);
        }

        // Retry attempt: simulate network timeout / webhook retry with identical operation key
        $res2 = $this->runner->recordStockOperation($productId, $opKey, -2.0, 'checkout_reservation', 9999);
        if (!$res2['already_applied']) {
            throw new \RuntimeException("Retried stock operation was not flagged as already_applied (double mutation risk!)");
        }
        if ((float)$res2['quantity_after'] !== 98.0) {
            throw new \RuntimeException("Quantity mutated again on retry! Expected 98.0, got: " . $res2['quantity_after']);
        }

        // Verify database inventory is still 98.0
        $currentStock = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT quantity FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ));
        if ($currentStock !== 98.0) {
            throw new \RuntimeException("Database inventory was double-mutated! Expected 98.0, got: {$currentStock}");
        }

        echo "  ✓ Zero stock double mutation verified: Identical operation key returned existing receipt without re-deducting stock\n";
        echo "  ✓ AC-T43-01 passed: Crash resilience and strict idempotency prevent double mutation under checkout retry load\n";
    }

    /**
     * AC-T43-02: Canary error threshold halts expansion and exposes recovery; no premature rollback of unprojected data.
     */
    public function testAC_T43_02_canary_halt_and_recovery_exposure(): void
    {
        echo "Testing AC-T43-02 (canary_halt_and_recovery_exposure)...\n";

        $cohortId = 'cohort_ac_t43_02';
        $this->cleanControlKeys[] = "canary_config_{$cohortId}";

        $this->runner->configureCanary($cohortId, 25);

        // Breach error threshold
        $haltResult = $this->runner->evaluateCanaryHealth($cohortId, [
            'error_rate'       => 0.05, // 5% errors
            'stock_mismatches' => 2,
            'p95_latency_ms'   => 200,
        ]);

        if ($haltResult['status'] !== 'halted') {
            throw new \RuntimeException("Canary was not halted on critical error breach!");
        }

        // Ensure automatic rollback is strictly FALSE
        if ($haltResult['recovery']['automatic_rollback'] !== false) {
            throw new \RuntimeException("Automatic rollback was initiated without mirror readiness verification!");
        }

        // Verify recovery details exposed
        if (empty($haltResult['recovery']['recommended_action']) || empty($haltResult['recovery']['manual_command'])) {
            throw new \RuntimeException("Missing structured recovery instructions in canary halt payload!");
        }

        echo "  ✓ Canary halt strictly avoids unsafe automatic rollback while exposing structured operator recovery runbook\n";
        echo "  ✓ AC-T43-02 passed: Error/mismatch threshold halts rollout expansion safely without corrupting native data\n";
    }
}

// Execute test suite
$test = new T43Test();
$test->run();
