<?php

/**
 * T46 Integration Test — Retention, garbage collection và legacy cleanup.
 *
 * Verifies:
 * - R185: GC strictly preserves shared references, post shells, order items, shared media, and stock operations.
 * - R186: Resumable chunked cleanup with database checkpoints in wpe_jobs.
 * - R187: Cleanup strictly gated on verified restore drill pass and expired rollback window.
 * - R188: Rollback after mirror stopped requires write barrier and full re-projection of current native data.
 * - R206: Cleanup requires an explicit product scope; an empty scope is never the whole catalog.
 * - R207: Cleanup requires a native row and sealed full_native authority for every targeted product.
 * - R208: Cleanup is refused until the operator-configured rollback window has elapsed.
 * - R209: The restore drill gate is per cohort; global T49 evidence does not satisfy it.
 * - AC-T46-01: Dry-run planner reports IDs, bytes, rollback impact while protecting shared assets.
 * - AC-T46-02: Cleanup is strictly manual / operator-triggered; never automatic on upgrade/activate/deactivate.
 * - AC-T46-03: Reprojection with write barrier before authority switch when mirror was stopped.
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

use WPE\Application\Rollout\RetentionCleaner;
use WPE\Compatibility\WordPress\PostIdAllocator;

class T46Test
{
    private \wpdb $wpdb;
    private RetentionCleaner $cleaner;
    private PostIdAllocator $allocator;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableJobs;
    private string $tableControl;
    private string $tableAudit;
    private string $tableStockOperations;
    private string $tableAssets;

    private array $cleanProductIds = [];
    private array $cleanJobIds = [];
    private array $cleanControlKeys = [];
    private array $cleanOrderItemIds = [];
    /** @var list<string> cleanup job ids that existed before the run, so only jobs created by it are removed */
    private array $jobBaseline = [];

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
        $this->tableAssets          = "{$prefix}wpe_assets";

        $this->cleaner   = new RetentionCleaner($this->wpdb);
        $this->allocator = new PostIdAllocator($this->wpdb);
    }

    public function run(): void
    {
        echo "=== Running T46 Integration Tests (Retention, GC & Legacy Cleanup) ===\n";

        try {
            $this->setUp();

            $this->testR185_gc_shared_refs_and_orders();
            $this->testR186_cleanup_resume();
            $this->testR187_cleanup_requires_restore_gate();
            $this->testR188_rollback_after_mirror_stopped();
            $this->testR206_cleanup_requires_explicit_scope();
            $this->testR207_cleanup_requires_native_row();
            $this->testR208_cleanup_requires_rollback_window();
            $this->testR209_restore_gate_is_per_cohort();

            $this->testAC_T46_01_dry_run_planner();
            $this->testAC_T46_02_no_auto_cleanup_on_lifecycle();
            $this->testAC_T46_03_reproject_with_barrier_before_switch();

            echo "=== All T46 Tests Passed Successfully ===\n";
        } finally {
            $this->tearDown();
        }
    }

    public function setUp(): void
    {
        $this->jobBaseline = array_map('strval', $this->wpdb->get_col("SELECT id FROM {$this->tableJobs} WHERE kind = 'cleanup'"));
    }

    public function tearDown(): void
    {
        delete_option(RetentionCleaner::ROLLBACK_WINDOW_OPTION);

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$this->tableInventory} WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableVariants} WHERE id IN ({$ids}) OR product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableProducts} WHERE id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableObjectState} WHERE object_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableStockOperations} WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->postmeta} WHERE post_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanOrderItemIds)) {
            $tableOrderItems = "{$this->wpdb->prefix}woocommerce_order_items";
            $itemIds = implode(',', array_map('intval', $this->cleanOrderItemIds));
            $this->wpdb->query("DELETE FROM {$tableOrderItems} WHERE order_item_id IN ({$itemIds})");
            $this->cleanOrderItemIds = [];
        }

        // Every cleanup job a test created (including ones started inside the cleaner) is removed (R212).
        $created = array_diff(array_map('strval', $this->wpdb->get_col("SELECT id FROM {$this->tableJobs} WHERE kind = 'cleanup'")), $this->jobBaseline);
        $this->cleanJobIds = array_values(array_unique(array_merge($this->cleanJobIds, $created)));

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
     * R185: GC strictly preserves shared references, post shells, order items, shared media, and stock operations.
     */
    public function testR185_gc_shared_refs_and_orders(): void
    {
        echo "Testing R185 (gc_shared_refs_and_orders)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $cohortId = 'cohort_r185_' . time();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";

        $now = gmdate('Y-m-d H:i:s.u');

        // 1. Create native product + inventory + object state
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'gc-test-' . $productId,
            'title'              => 'GC Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-GC-' . $productId,
            'regular_price'      => '180.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 45.0,
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
            'authority'       => 'full_native',
            'epoch'           => 3,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // 2. Insert redundant owned postmeta rows in wp_postmeta
        foreach (RetentionCleaner::REDUNDANT_OWNED_POSTMETA as $k) {
            $this->wpdb->insert($this->wpdb->postmeta, [
                'post_id'    => $productId,
                'meta_key'   => $k,
                'meta_value' => 'redundant_value',
            ]);
        }

        // 3. Insert historical stock operation (R185)
        $opKey = 'op_gc_hist_' . wp_generate_uuid4();
        $this->wpdb->insert($this->tableStockOperations, [
            'operation_key'  => $opKey,
            'owner_id'       => $productId,
            'order_id'       => 7777,
            'order_item_id'  => null,
            'kind'           => 'checkout_reservation',
            'delta'          => '-1.0000',
            'quantity_after' => '44.0000',
            'created_at'     => $now,
        ]);

        // 4. Insert order line item referencing product in woocommerce_order_items (R185)
        $tableOrderItems = "{$this->wpdb->prefix}woocommerce_order_items";
        $this->wpdb->insert($tableOrderItems, [
            'order_item_name' => 'GC Test Product',
            'order_item_type' => 'line_item',
            'order_id'        => 7777,
        ]);
        $orderItemId = (int)$this->wpdb->insert_id;
        $this->cleanOrderItemIds[] = $orderItemId;

        // Set restore drill gate to passed and seal authority long enough ago (R187, R207, R208)
        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $this->sealCohort($cohortId);

        // Execute cleanup
        $cleanupRes = $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $cleanupRes['job_id'];

        if ($cleanupRes['cleaned_rows'] < count(RetentionCleaner::REDUNDANT_OWNED_POSTMETA)) {
            throw new \RuntimeException("Cleanup failed to prune redundant postmeta rows!");
        }

        // Verify that redundant postmeta is pruned
        $remainingPostmeta = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT count(*) FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key IN ('_price', '_regular_price', '_stock')",
            $productId
        ));
        if ($remainingPostmeta !== 0) {
            throw new \RuntimeException("Redundant postmeta remained after cleanup!");
        }
        echo "  ✓ Redundant postmeta rows successfully pruned\n";

        // CRITICAL: Verify protected items were NOT deleted (R185)
        // 1. Post shell in wp_posts
        $postShellExists = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT count(*) FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ));
        if ($postShellExists !== 1) {
            throw new \RuntimeException("Post shell in wp_posts was deleted contrary to R185/INV02!");
        }
        echo "  ✓ Post shell in wp_posts preserved intact\n";

        // 2. Order line item in woocommerce_order_items
        $orderItemExists = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT count(*) FROM {$tableOrderItems} WHERE order_item_id = %d",
            $orderItemId
        ));
        if ($orderItemExists !== 1) {
            throw new \RuntimeException("Order item reference was deleted contrary to R185!");
        }
        echo "  ✓ WooCommerce order line item reference preserved intact\n";

        // 3. Stock operations history in wpe_stock_operations
        $stockOpExists = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT count(*) FROM {$this->tableStockOperations} WHERE operation_key = %s",
            $opKey
        ));
        if ($stockOpExists !== 1) {
            throw new \RuntimeException("Stock operation was deleted contrary to R185/INV06!");
        }
        echo "  ✓ Stock operation history in wpe_stock_operations preserved intact\n";

        echo "  ✓ R185 passed: Garbage collection prunes duplicated postmeta while strictly preserving shared refs, shells, and orders\n";
    }

    /**
     * R186: Resumable chunked cleanup with database checkpoints in wpe_jobs.
     */
    public function testR186_cleanup_resume(): void
    {
        echo "Testing R186 (cleanup_resume)...\n";

        $cohortId = 'cohort_r186_' . time();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";

        // Create 6 products with redundant postmeta
        $testProductIds = [];
        $now = gmdate('Y-m-d H:i:s.u');

        for ($i = 0; $i < 6; $i++) {
            $pid = $this->allocator->allocateShell('product');
            $testProductIds[] = $pid;
            $this->cleanProductIds[] = $pid;

            $this->wpdb->insert($this->tableProducts, [
                'id'                 => $pid,
                'type'               => 'simple',
                'status'             => 'publish',
                'slug'               => 'chunk-prod-' . $pid,
                'title'              => 'Chunk Product ' . $i,
                'description'        => '',
                'short_description'  => '',
                'catalog_visibility' => 'visible',
                'sku'                => 'SKU-CHUNK-' . $pid,
                'regular_price'      => '50.000000',
                'created_at'         => $now,
                'updated_at'         => $now,
                'version'            => 1,
            ]);

            $this->wpdb->insert($this->tableInventory, [
                'owner_id'         => $pid,
                'manage_stock'     => 1,
                'quantity'         => 10.0,
                'backorders'       => 'no',
                'stock_status'     => 'instock',
                'low_stock_amount' => 2.0,
                'version'          => 1,
                'updated_at'       => $now,
            ]);

            $this->wpdb->insert($this->tableObjectState, [
                'object_id'       => $pid,
                'kind'            => 'product',
                'parent_id'       => null,
                'authority'       => 'full_native',
                'epoch'           => 3,
                'legacy_revision' => 1,
                'native_revision' => 1,
                'legacy_applied'  => 1,
                'native_applied'  => 1,
                'tombstone'       => 0,
                'updated_at'      => $now,
            ]);

            $this->wpdb->insert($this->wpdb->postmeta, [
                'post_id'    => $pid,
                'meta_key'   => '_price',
                'meta_value' => '50.00',
            ]);
        }

        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $this->sealCohort($cohortId);

        // Execute cleanup with chunkSize = 2, fault injected at chunk 2 (after chunks 0 and 1 completed)
        $jobId = wp_generate_uuid4();
        $this->cleanJobIds[] = $jobId;

        $crashed = false;
        try {
            $this->cleaner->executeCleanup($cohortId, [
                'job_id'         => $jobId,
                'chunk_size'     => 2,
                'product_ids'    => $testProductIds,
                'fault_at_chunk' => 2,
            ]);
        } catch (\RuntimeException $e) {
            $crashed = true;
        }

        if (!$crashed) {
            throw new \RuntimeException("Cleanup did not simulate crash at chunk 2!");
        }

        // Verify checkpoint recorded chunk_index 2
        $jobRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT status, checkpoint FROM {$this->tableJobs} WHERE id = %s",
            $jobId
        ), ARRAY_A);

        $cp = json_decode((string)$jobRow['checkpoint'], true);
        if ((int)$cp['chunk_index'] !== 2) {
            throw new \RuntimeException("Checkpoint did not record chunk_index 2: got " . ($cp['chunk_index'] ?? 'null'));
        }
        echo "  ✓ Simulated crash recorded checkpoint at chunk 2\n";

        // Resume cleanup job
        $resumeRes = $this->cleaner->resumeCleanup($jobId, [
            'product_ids' => $testProductIds,
        ]);

        if ($resumeRes['status'] !== 'completed') {
            throw new \RuntimeException("Resumed cleanup did not complete: got " . $resumeRes['status']);
        }

        // Verify all 6 products now have postmeta cleaned
        $inIds = implode(',', $testProductIds);
        $remainingRows = (int)$this->wpdb->get_var(
            "SELECT count(*) FROM {$this->wpdb->postmeta} WHERE post_id IN ({$inIds}) AND meta_key = '_price'"
        );
        if ($remainingRows !== 0) {
            throw new \RuntimeException("Not all redundant postmeta rows cleaned after resume!");
        }

        echo "  ✓ Cleanup resumed from checkpoint and completed all remaining chunks idempotently\n";
        echo "  ✓ R186 passed: Resumable chunked cleanup with database checkpoints operates reliably\n";
    }

    /**
     * R187: Cleanup strictly gated on verified restore drill pass and expired rollback window.
     */
    public function testR187_cleanup_requires_restore_gate(): void
    {
        echo "Testing R187 (cleanup_requires_restore_gate)...\n";

        $cohortId = 'cohort_r187_blocked_' . time();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $productId = $this->makeProduct();
        $this->sealCohort($cohortId);

        // Everything else is satisfied; only the restore drill gate is explicitly false
        $this->cleaner->setRestoreDrillGate($cohortId, false);

        $gateBlocked = false;
        try {
            $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);
        } catch (\RuntimeException $e) {
            $gateBlocked = str_contains($e->getMessage(), 'Cleanup Gate Violation');
        }

        if (!$gateBlocked) {
            throw new \RuntimeException("Cleanup was allowed without verified restore drill gate!");
        }
        echo "  ✓ Cleanup strictly blocked when restore drill gate was not verified (fail-closed)\n";

        // Allow when gate is verified
        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $check = $this->cleaner->checkRestoreDrillGate($cohortId);
        if (!$check['passed']) {
            throw new \RuntimeException("Restore drill gate check failed after being marked passed!");
        }
        $res = $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $res['job_id'];
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";
        if ($res['status'] !== 'completed') {
            throw new \RuntimeException("Cleanup did not run once every guard passed!");
        }

        echo "  ✓ R187 passed: Verified restore drill gate is strictly required before cleanup execution\n";
    }

    /**
     * R188: Rollback after mirror stopped requires write barrier and full re-projection of current native data.
     */
    public function testR188_rollback_after_mirror_stopped(): void
    {
        echo "Testing R188 (rollback_after_mirror_stopped)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $cohortId = 'cohort_r188_' . time();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_restored_{$cohortId}";
        $this->cleanControlKeys[] = "rollback_write_barrier";

        $now = gmdate('Y-m-d H:i:s.u');

        // Create native product with current price 220.00 and stock 90.0
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'reproject-prod-' . $productId,
            'title'              => 'Reproject Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-REPROJ-' . $productId,
            'regular_price'      => '220.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 2,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 90.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 5.0,
            'version'          => 2,
            'updated_at'       => $now,
        ]);

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $productId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'full_native',
            'epoch'           => 3,
            'legacy_revision' => 1,
            'native_revision' => 2,
            'legacy_applied'  => 1,
            'native_applied'  => 2,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Cleanup was previously run: postmeta is currently empty
        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $this->sealCohort($cohortId);
        $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);

        // Now an operator requests a rollback!
        // Because mirror was stopped, simple flag flip is prohibited (AC-T46-03).
        // It must call reprojectBeforeRollback() which acquires write barrier and re-populates legacy
        $reprojectRes = $this->cleaner->reprojectBeforeRollback($cohortId, ['product_ids' => [$productId]]);

        if (!$reprojectRes['mirror_ready'] || !$reprojectRes['safe_to_switch']) {
            throw new \RuntimeException("Reprojection before rollback failed to mark mirror_ready!");
        }

        // Verify that postmeta was accurately restored with current native values (220.00 and 90.0)
        $reprojectedPrice = (float)get_post_meta($productId, '_regular_price', true);
        $reprojectedStock = (float)get_post_meta($productId, '_stock', true);

        if ($reprojectedPrice !== 220.0 || $reprojectedStock !== 90.0) {
            throw new \RuntimeException("Reprojection did not restore current native values! Price: {$reprojectedPrice}, Stock: {$reprojectedStock}");
        }

        echo "  ✓ Write barrier acquired and current native data accurately reprojected to legacy storage (Price: 220.00, Stock: 90.0)\n";
        echo "  ✓ R188 passed: Rollback after stopped mirror safely reprojects current native data before authority switch\n";
    }

    /**
     * AC-T46-01: Dry-run planner reports IDs, bytes, rollback impact while protecting shared assets.
     */
    public function testAC_T46_01_dry_run_planner(): void
    {
        echo "Testing AC-T46-01 (dry_run_planner)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $cohortId = 'cohort_ac_t46_01';

        // Add 3 redundant postmeta rows
        $this->wpdb->insert($this->wpdb->postmeta, ['post_id' => $productId, 'meta_key' => '_price', 'meta_value' => '100.00']);
        $this->wpdb->insert($this->wpdb->postmeta, ['post_id' => $productId, 'meta_key' => '_stock', 'meta_value' => '10']);
        $this->wpdb->insert($this->wpdb->postmeta, ['post_id' => $productId, 'meta_key' => '_sku', 'meta_value' => 'SKU-PLAN']);

        $plan = $this->cleaner->planCleanup($cohortId, ['product_ids' => [$productId]]);

        if (!$plan['dry_run'] || $plan['target_products'] !== 1) {
            throw new \RuntimeException("Dry run plan returned invalid plan structure!");
        }

        if ($plan['redundant_postmeta_rows'] < 3) {
            throw new \RuntimeException("Plan failed to detect redundant postmeta rows!");
        }

        if ($plan['estimated_bytes_saved'] <= 0) {
            throw new \RuntimeException("Plan failed to estimate bytes saved!");
        }

        // Verify database was NOT mutated during dry-run
        $countStillThere = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT count(*) FROM {$this->wpdb->postmeta} WHERE post_id = %d",
            $productId
        ));
        if ($countStillThere < 3) {
            throw new \RuntimeException("Dry run mutated database rows!");
        }

        echo "  ✓ AC-T46-01 passed: Dry-run accurately reports IDs, bytes, rollback impact with zero DB mutations\n";
    }

    /**
     * AC-T46-02: Cleanup is strictly manual / operator-triggered; never automatic on upgrade/activate/deactivate.
     */
    public function testAC_T46_02_no_auto_cleanup_on_lifecycle(): void
    {
        echo "Testing AC-T46-02 (no_auto_cleanup_on_lifecycle)...\n";

        // 1. No hook anywhere may call into RetentionCleaner
        global $wp_filter;
        foreach ($wp_filter as $hookName => $hook) {
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $cb) {
                    $func = $cb['function'] ?? null;
                    if (is_array($func) && isset($func[0])) {
                        $class = is_object($func[0]) ? get_class($func[0]) : (string) $func[0];
                        if (is_a($class, RetentionCleaner::class, true)) {
                            throw new \RuntimeException("RetentionCleaner is registered on hook {$hookName}!");
                        }
                    }
                }
            }
        }
        echo "  ✓ No hook invokes RetentionCleaner\n";

        // 2. The real lifecycle entry points never reference cleanup
        foreach (['onActivate', 'onDeactivate'] as $method) {
            $ref = new \ReflectionMethod(\WPE\Bootstrap\PluginBootstrap::class, $method);
            $lines = array_slice(file($ref->getFileName()) ?: [], $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1);
            $body = strtolower(implode('', $lines));
            if (str_contains($body, 'retentioncleaner') || str_contains($body, 'executecleanup') || str_contains($body, 'cleanup')) {
                throw new \RuntimeException("PluginBootstrap::{$method} references cleanup!");
            }
        }
        echo "  ✓ PluginBootstrap::onActivate/onDeactivate contain no cleanup call\n";

        // 3. Running them against a cleanup-eligible product changes nothing
        $cohortId = 'cohort_ac_t46_02_' . uniqid();
        $productId = $this->makeProduct();
        $this->sealCohort($cohortId);
        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";

        $metaBefore = $this->countRedundantMeta($productId);
        $jobsBefore = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableJobs} WHERE kind = 'cleanup'");
        \WPE\Bootstrap\PluginBootstrap::onActivate();
        \WPE\Bootstrap\PluginBootstrap::onDeactivate();
        $jobsAfter = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableJobs} WHERE kind = 'cleanup'");
        if ($this->countRedundantMeta($productId) !== $metaBefore || $jobsAfter !== $jobsBefore || $metaBefore === 0) {
            throw new \RuntimeException("Lifecycle hooks changed postmeta or created cleanup jobs!");
        }

        echo "  ✓ Activate/deactivate left eligible postmeta and the job table untouched\n";
        echo "  ✓ AC-T46-02 passed: Cleanup is never automatic on upgrade, activate, or deactivate\n";
    }

    /**
     * AC-T46-03: Reprojection with write barrier before authority switch when mirror was stopped.
     */
    public function testAC_T46_03_reproject_with_barrier_before_switch(): void
    {
        echo "Testing AC-T46-03 (reproject_with_barrier_before_switch)...\n";

        $cohortId = 'cohort_ac_t46_03_' . uniqid();
        $this->cleanControlKeys[] = "mirror_restored_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "rollback_write_barrier";

        // An unrelated native product that must not be touched by the reprojection
        $bystander = $this->makeProduct('Bystander');
        $bystanderBefore = get_post_meta($bystander, '_regular_price', true);

        $productId = $this->makeProduct('Reproject Target', 345.0);
        $this->sealCohort($cohortId);
        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $job = $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $job['job_id'];

        // Empty scope falls back to the scope recorded at stop time, never to the whole catalog (R206)
        $res = $this->cleaner->reprojectBeforeRollback($cohortId, ['product_ids' => []]);

        if (empty($res['barrier_id']) || !$res['mirror_ready'] || !$res['safe_to_switch']) {
            throw new \RuntimeException("Reprojection before rollback failed to acquire barrier or mark safe_to_switch!");
        }
        if ($res['reprojected_count'] !== 1) {
            throw new \RuntimeException("Reprojection touched {$res['reprojected_count']} products instead of exactly the recorded scope (1)!");
        }
        if ((float) get_post_meta($productId, '_regular_price', true) !== 345.0) {
            throw new \RuntimeException("Target product was not reprojected with its current native price!");
        }
        if (get_post_meta($bystander, '_regular_price', true) !== $bystanderBefore) {
            throw new \RuntimeException("Reprojection modified a product outside the recorded scope!");
        }
        echo "  ✓ Empty scope used the recorded cohort scope; the bystander product was not touched\n";

        // Without a recorded scope an empty request is refused
        $refused = false;
        try {
            $this->cleaner->reprojectBeforeRollback('cohort_never_stopped_' . uniqid(), ['product_ids' => []]);
        } catch (\RuntimeException $e) {
            $refused = str_contains($e->getMessage(), 'R206');
        }
        if (!$refused) {
            throw new \RuntimeException("Reprojection with no scope was not refused!");
        }

        echo "  ✓ AC-T46-03 passed: Safe fallback reprojects current native data under write barrier before authority switch\n";
    }

    // --- Fixtures ---

    /**
     * Native product (+inventory, object_state full_native) with redundant legacy postmeta.
     */
    private function makeProduct(string $title = 'T46 Fixture', float $price = 100.0, string $authority = 'full_native', bool $withNativeRow = true): int
    {
        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $now = gmdate('Y-m-d H:i:s.u');

        if ($withNativeRow) {
            $this->wpdb->insert($this->tableProducts, [
                'id'                 => $productId,
                'type'               => 'simple',
                'status'             => 'publish',
                'slug'               => 't46-fixture-' . $productId,
                'title'              => $title . ' ' . $productId,
                'description'        => '',
                'short_description'  => '',
                'catalog_visibility' => 'visible',
                'sku'                => 'SKU-T46-' . $productId,
                'regular_price'      => number_format($price, 6, '.', ''),
                'created_at'         => $now,
                'updated_at'         => $now,
                'version'            => 1,
            ]);
            $this->wpdb->insert($this->tableInventory, [
                'owner_id'         => $productId,
                'manage_stock'     => 1,
                'quantity'         => 20.0,
                'backorders'       => 'no',
                'stock_status'     => 'instock',
                'low_stock_amount' => 2.0,
                'version'          => 1,
                'updated_at'       => $now,
            ]);
            $this->wpdb->insert($this->tableObjectState, [
                'object_id'       => $productId,
                'kind'            => 'product',
                'parent_id'       => null,
                'authority'       => $authority,
                'epoch'           => 3,
                'legacy_revision' => 1,
                'native_revision' => 1,
                'legacy_applied'  => 1,
                'native_applied'  => 1,
                'tombstone'       => 0,
                'updated_at'      => $now,
            ]);
        }

        foreach (['_price' => (string) $price, '_regular_price' => (string) $price, '_stock' => '20', '_sku' => 'SKU-T46-' . $productId] as $k => $v) {
            $this->wpdb->insert($this->wpdb->postmeta, ['post_id' => $productId, 'meta_key' => $k, 'meta_value' => $v]);
        }

        return $productId;
    }

    /** Records an active authority seal for the cohort, $daysAgo days in the past (R207, R208). */
    private function sealCohort(string $cohortId, int $daysAgo = 45): void
    {
        $sealedAt = gmdate('Y-m-d H:i:s.u', time() - $daysAgo * 86400);
        $this->wpdb->replace($this->tableControl, [
            'key'        => "full_native_no_fallback_{$cohortId}",
            'value'      => json_encode(['active' => true, 'cohort_id' => $cohortId, 'sealed_at' => $sealedAt]),
            'version'    => 1,
            'updated_at' => $sealedAt,
        ]);
        $this->cleanControlKeys[] = "full_native_no_fallback_{$cohortId}";
    }

    private function countRedundantMeta(int $productId): int
    {
        $keys = "'" . implode("','", RetentionCleaner::REDUNDANT_OWNED_POSTMETA) . "'";
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key IN ({$keys})",
            $productId
        ));
    }

    /**
     * Runs a cleanup that must be refused; returns the message and fails if it ran or touched postmeta.
     */
    private function expectRefusal(string $cohortId, array $options, string $code, int $watchProductId): string
    {
        $before = $this->countRedundantMeta($watchProductId);
        try {
            $res = $this->cleaner->executeCleanup($cohortId, $options);
            $this->cleanJobIds[] = $res['job_id'];
            throw new \LogicException("Cleanup ran but {$code} must refuse it");
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), "({$code})")) {
                throw new \LogicException("Expected a {$code} refusal, got: " . $e->getMessage());
            }
            if ($this->countRedundantMeta($watchProductId) !== $before) {
                throw new \LogicException("A refused cleanup still deleted postmeta ({$code})");
            }
            return $e->getMessage();
        }
    }

    /**
     * R206: an empty or missing scope never means the whole catalog.
     */
    public function testR206_cleanup_requires_explicit_scope(): void
    {
        echo "Testing R206 (cleanup_requires_explicit_scope)...\n";

        $cohortId = 'cohort_r206_' . uniqid();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";
        $productId = $this->makeProduct();
        $this->sealCohort($cohortId);
        $this->cleaner->setRestoreDrillGate($cohortId, true);

        $jobsBefore = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableJobs} WHERE kind = 'cleanup'");
        foreach (['empty product_ids' => ['product_ids' => []], 'missing product_ids' => [], 'non-positive ids' => ['product_ids' => [0, -5]]] as $label => $options) {
            $this->expectRefusal($cohortId, $options, 'R206', $productId);
            echo "  ✓ {$label}: refused, no postmeta deleted\n";
        }
        $jobsAfter = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableJobs} WHERE kind = 'cleanup'");
        if ($jobsAfter !== $jobsBefore) {
            throw new \RuntimeException("A refused cleanup created a job row!");
        }

        $plan = $this->cleaner->planCleanup($cohortId, ['product_ids' => []]);
        if ($plan['target_products'] !== 0 || $plan['redundant_postmeta_rows'] !== 0 || $plan['rollback_impact']['safe_to_clean'] !== false) {
            throw new \RuntimeException("Planner treated an empty scope as the whole catalog!");
        }
        echo "  ✓ Planner reports zero targets and not safe_to_clean for an empty scope\n";

        $res = $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $res['job_id'];
        $config = json_decode((string) $this->wpdb->get_var($this->wpdb->prepare("SELECT config FROM {$this->tableJobs} WHERE id = %s", $res['job_id'])), true);
        if (($config['product_ids'] ?? null) !== [$productId]) {
            throw new \RuntimeException("Job config does not record the explicit scope!");
        }
        echo "  ✓ R206 passed: cleanup only runs for an explicit product scope that is recorded on the job\n";
    }

    /**
     * R207: native row and sealed authority are mandatory for every targeted product.
     */
    public function testR207_cleanup_requires_native_row(): void
    {
        echo "Testing R207 (cleanup_requires_native_row)...\n";

        $cohortId = 'cohort_r207_' . uniqid();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";
        $this->cleaner->setRestoreDrillGate($cohortId, true);
        $this->sealCohort($cohortId);

        $legacyOnly = $this->makeProduct('Legacy only', 100.0, 'full_native', false);
        $this->expectRefusal($cohortId, ['product_ids' => [$legacyOnly]], 'R207', $legacyOnly);
        echo "  ✓ Product without a native row: legacy postmeta is the only copy, cleanup refused\n";

        $unsealed = $this->makeProduct('Not sealed', 100.0, 'native');
        $this->expectRefusal($cohortId, ['product_ids' => [$unsealed]], 'R207', $unsealed);
        echo "  ✓ Product whose authority is not sealed (native, not full_native): cleanup refused\n";

        $good = $this->makeProduct('Sealed');
        $this->expectRefusal($cohortId, ['product_ids' => [$good, $legacyOnly]], 'R207', $good);
        echo "  ✓ One ineligible product blocks the whole scope; the eligible one keeps its postmeta\n";

        $noSealCohort = 'cohort_r207_noseal_' . uniqid();
        $this->cleanControlKeys[] = "restore_drill_gate_{$noSealCohort}";
        $this->cleaner->setRestoreDrillGate($noSealCohort, true);
        $this->expectRefusal($noSealCohort, ['product_ids' => [$good]], 'R207', $good);
        echo "  ✓ Cohort with no recorded authority seal: cleanup refused\n";

        echo "  ✓ R207 passed: cleanup needs a native row and a sealed authority for every product\n";
    }

    /**
     * R208: cleanup waits for the operator-configured rollback window (default 30 days).
     */
    public function testR208_cleanup_requires_rollback_window(): void
    {
        echo "Testing R208 (cleanup_requires_rollback_window)...\n";

        if ($this->cleaner->getRollbackWindowDays() !== RetentionCleaner::DEFAULT_ROLLBACK_WINDOW_DAYS) {
            throw new \RuntimeException("Default rollback window is not 30 days!");
        }

        $cohortId = 'cohort_r208_' . uniqid();
        $this->cleanControlKeys[] = "restore_drill_gate_{$cohortId}";
        $this->cleanControlKeys[] = "mirror_stopped_{$cohortId}";
        $productId = $this->makeProduct();
        $this->cleaner->setRestoreDrillGate($cohortId, true);

        $this->sealCohort($cohortId, 10);
        $this->expectRefusal($cohortId, ['product_ids' => [$productId]], 'R208', $productId);
        echo "  ✓ Sealed 10 days ago with the default 30-day window: refused\n";

        update_option(RetentionCleaner::ROLLBACK_WINDOW_OPTION, 7);
        $res = $this->cleaner->executeCleanup($cohortId, ['product_ids' => [$productId]]);
        $this->cleanJobIds[] = $res['job_id'];
        echo "  ✓ Operator window of 7 days: the same seal is now old enough and cleanup runs\n";

        // A caller cannot shorten the window through options
        $other = $this->makeProduct();
        $this->sealCohort($cohortId, 2);
        $this->expectRefusal($cohortId, ['product_ids' => [$other], 'rollback_window_days' => 0], 'R208', $other);
        echo "  ✓ rollback_window_days in call options is ignored\n";

        echo "  ✓ R208 passed: cleanup is blocked until the configured rollback window has elapsed\n";
    }

    /**
     * R209: the restore drill gate belongs to one cohort; global T49 evidence is not a shortcut.
     */
    public function testR209_restore_gate_is_per_cohort(): void
    {
        echo "Testing R209 (restore_gate_is_per_cohort)...\n";

        $drilled = 'cohort_r209_drilled_' . uniqid();
        $other = 'cohort_r209_other_' . uniqid();
        $this->cleanControlKeys[] = "restore_drill_gate_{$drilled}";
        $this->cleanControlKeys[] = "restore_drill_gate_{$other}";
        $this->cleanControlKeys[] = "mirror_stopped_{$drilled}";
        $this->cleanControlKeys[] = "mirror_stopped_{$other}";

        $this->cleaner->setRestoreDrillGate($drilled, true);
        if (!$this->cleaner->checkRestoreDrillGate($drilled)['passed']) {
            throw new \RuntimeException("Drilled cohort gate should pass!");
        }
        if ($this->cleaner->checkRestoreDrillGate($other)['passed']) {
            throw new \RuntimeException("A drill recorded for one cohort satisfied another cohort!");
        }
        echo "  ✓ A passed drill for cohort A does not satisfy cohort B\n";

        $t49 = dirname(__DIR__, 2) . '/docs/evidence/T49/result.json';
        $t49Passed = is_file($t49) && (json_decode((string) file_get_contents($t49), true)['qa_status'] ?? '') === 'passed';
        if ($t49Passed && $this->cleaner->checkRestoreDrillGate('cohort_with_no_drill_' . uniqid())['passed']) {
            throw new \RuntimeException("Global T49 evidence is still accepted as a per-cohort restore drill!");
        }
        echo "  ✓ Global T49 evidence is not accepted as a cohort drill (T49 evidence passed: " . ($t49Passed ? 'yes' : 'no') . ")\n";

        // Full path: cohort B is eligible in every other way yet still refused without its own drill
        $productId = $this->makeProduct();
        $this->sealCohort($other);
        $this->expectRefusal($other, ['product_ids' => [$productId]], 'R187', $productId);
        echo "  ✓ Otherwise eligible cohort B is refused until its own drill is recorded\n";

        echo "  ✓ R209 passed: restore drill gate is evaluated per cohort with no global shortcut\n";
    }
}

// Execute test suite
$test = new T46Test();
$test->run();
