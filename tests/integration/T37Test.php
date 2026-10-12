<?php
/**
 * T37 Integration Test — Change capture and reconciliation.
 *
 * Verifies:
 * - R149 (missed_hook_reconcile): Scanner detects missed hooks via high-water mark + content hashes, repairs native projection.
 * - R150 (term_stock_delete_capture): Post/term/stock/delete hooks capture changes; child variations coalesce to parent aggregate ID.
 * - R151 (raw_sql_detected_audit): Direct SQL modifications without hooks are detected and audited.
 * - R152 (origin_guard_no_pingpong): withOrigin('native_projection') suppresses legacy capture hooks to prevent sync loops.
 * - AC-T37-01: Missed hook reconciliation repair verified without recursive sync loop.
 * - AC-T37-02: Aggregates under native authority log alert and reject legacy overwrite.
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

use WPE\Application\Migration\LegacyCapture;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Compatibility\Gallery\AssetService;

class T37Test
{
    private \wpdb $wpdb;
    private LegacyCapture $capture;
    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableAudit;
    private string $tableControl;
    private string $tableProductTerms;
    private string $tableSkuRegistry;

    private array $cleanProductIds = [];
    private array $cleanTermIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $prefix = $this->wpdb->prefix;

        $this->tableProducts     = "{$prefix}wpe_products";
        $this->tableVariants     = "{$prefix}wpe_variants";
        $this->tableInventory    = "{$prefix}wpe_inventory";
        $this->tableObjectState  = "{$prefix}wpe_object_state";
        $this->tableAudit        = "{$prefix}wpe_audit";
        $this->tableControl      = "{$prefix}wpe_control";
        $this->tableProductTerms = "{$prefix}wpe_product_terms";
        $this->tableSkuRegistry  = "{$prefix}wpe_sku_registry";

        $this->capture = LegacyCapture::getInstance();
        $this->capture->registerHooks();

        wp_set_current_user(1);
    }

    public function run(): void
    {
        echo "Running T37 Integration Tests...\n";
        $this->cleanUp();

        try {
            $this->testR149_missed_hook_reconcile();
            $this->testR150_term_stock_delete_capture();
            $this->testR151_raw_sql_detected_audit();
            $this->testR152_origin_guard_no_pingpong();
            $this->testAC_T37_01_missed_hook_repair_and_no_loop();
            $this->testAC_T37_02_native_authority_no_overwrite();
            echo "All T37 tests passed successfully!\n";
        } finally {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        if (empty($this->cleanProductIds) && empty($this->cleanTermIds)) {
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

        foreach ($this->cleanTermIds as $tid) {
            wp_delete_term($tid, 'product_cat');
        }
        $this->cleanTermIds = [];

        $this->capture->clearDirtyAggregates();
    }

    private function createLegacySimpleProduct(string $title, float $price, int $stock = 10): int
    {
        $postData = [
            'post_title'   => $title,
            'post_name'    => sanitize_title($title) . '-' . wp_generate_password(4, false, false),
            'post_content' => 'Description for ' . $title,
            'post_excerpt' => 'Short desc for ' . $title,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ];
        $postId = wp_insert_post($postData);
        if (is_wp_error($postId) || $postId <= 0) {
            throw new \RuntimeException("Failed to create test product");
        }

        $sku = 'SKU-' . strtoupper(wp_generate_password(6, false, false));
        update_post_meta($postId, '_sku', $sku);
        update_post_meta($postId, '_regular_price', (string)$price);
        update_post_meta($postId, '_price', (string)$price);
        update_post_meta($postId, '_manage_stock', 'yes');
        update_post_meta($postId, '_stock', (string)$stock);
        update_post_meta($postId, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');

        $this->cleanProductIds[] = $postId;
        return $postId;
    }

    private function createLegacyVariableProduct(string $title): array
    {
        $postId = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => sanitize_title($title) . '-' . wp_generate_password(4, false, false),
            'post_content' => 'Variable product description',
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        $sku = 'SKU-VAR-' . strtoupper(wp_generate_password(6, false, false));
        update_post_meta($postId, '_sku', $sku);

        // Add variation
        $varId = wp_insert_post([
            'post_title'   => "Variation for {$title}",
            'post_status'  => 'publish',
            'post_type'    => 'product_variation',
            'post_parent'  => $postId,
        ]);

        $varSku = $sku . '-V1';
        update_post_meta($varId, '_sku', $varSku);
        update_post_meta($varId, '_regular_price', '25.00');
        update_post_meta($varId, '_price', '25.00');
        update_post_meta($varId, '_manage_stock', 'yes');
        update_post_meta($varId, '_stock', '5');
        update_post_meta($varId, '_stock_status', 'instock');

        $this->cleanProductIds[] = $postId;
        return [$postId, $varId];
    }

    public function testR149_missed_hook_reconcile(): void
    {
        echo "Testing R149 (missed_hook_reconcile)...\n";

        $productId = $this->createLegacySimpleProduct('R149 Original Product', 19.99, 15);
        $this->capture->captureLegacyProduct($productId, 'initial_sync');

        $originalRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT title, regular_price FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);
        if ($originalRow['title'] !== 'R149 Original Product') {
            throw new \RuntimeException("Initial capture failed to store product in native table");
        }

        // Simulate missed hook: update post directly via SQL without WordPress hooks firing
        $newTitle = 'R149 Direct SQL Updated Title';
        $futureGmt = gmdate('Y-m-d H:i:s', time() + 5);
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->posts} SET post_title = %s, post_modified_gmt = %s WHERE ID = %d",
            $newTitle,
            $futureGmt,
            $productId
        ));

        // Scanner runs
        $scanResult = $this->capture->runReconciliationScan(['full_scan' => true, 'force_repair' => true]);

        if ($scanResult['repaired'] < 1) {
            throw new \RuntimeException("Reconciliation scanner failed to detect and repair missed hook");
        }

        // Verify native table was repaired
        $updatedTitle = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT title FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        if ($updatedTitle !== $newTitle) {
            throw new \RuntimeException("Native storage title was not repaired: expected {$newTitle}, got {$updatedTitle}");
        }

        // Verify object state legacy hash updated
        $computedHash = $this->capture->computeLegacyHash($productId);
        $storedHash = bin2hex($this->wpdb->get_var($this->wpdb->prepare(
            "SELECT legacy_hash FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        )));
        if ($storedHash !== $computedHash) {
            throw new \RuntimeException("Stored legacy hash does not match computed hash after reconciliation");
        }

        echo "  ✓ R149 passed: missed hook detected and repaired via reconciliation scanner\n";
    }

    public function testR150_term_stock_delete_capture(): void
    {
        echo "Testing R150 (term_stock_delete_capture)...\n";

        // 1. Taxonomy Term Hook
        $productId = $this->createLegacySimpleProduct('R150 Term Stock Product', 45.00, 20);
        $termResult = wp_insert_term('R150 Category ' . wp_generate_password(4, false, false), 'product_cat');
        if (is_wp_error($termResult)) {
            throw new \RuntimeException("Failed to create test category: " . $termResult->get_error_message());
        }
        $termId = (int)$termResult['term_id'];
        $ttId   = (int)$termResult['term_taxonomy_id'];
        $this->cleanTermIds[] = $termId;

        $this->capture->clearDirtyAggregates();
        wp_set_object_terms($productId, [$termId], 'product_cat');

        // Check wpe_product_terms
        $termAssigned = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$this->tableProductTerms} WHERE product_id = %d AND term_taxonomy_id = %d",
            $productId,
            $ttId
        ));
        if ((int)$termAssigned !== $ttId) {
            throw new \RuntimeException("Taxonomy hook did not synchronize term to wpe_product_terms");
        }
        if (!in_array($productId, $this->capture->getDirtyAggregates(), true)) {
            throw new \RuntimeException("Taxonomy hook did not mark product aggregate dirty");
        }

        // 2. Stock Hook
        $this->capture->clearDirtyAggregates();
        wc_update_product_stock($productId, 77);

        $invQty = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT quantity FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ));
        if ($invQty !== 77) {
            throw new \RuntimeException("Stock hook did not synchronize inventory quantity to native storage: expected 77, got {$invQty}");
        }

        // 3. Trash / Delete Hook
        $this->capture->clearDirtyAggregates();
        wp_trash_post($productId);

        $status = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT status FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        $tombstone = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT tombstone FROM {$this->tableObjectState} WHERE object_id = %d",
            $productId
        ));
        if ($status !== 'trash' || $tombstone !== 1) {
            throw new \RuntimeException("Trash hook did not set status to trash and tombstone to 1");
        }

        // 4. Variation Coalescing to Parent
        [$varParentId, $variationId] = $this->createLegacyVariableProduct('R150 Variable Parent');
        $this->capture->clearDirtyAggregates();

        // Update postmeta on variation
        update_post_meta($variationId, '_regular_price', '39.99');

        $dirty = $this->capture->getDirtyAggregates();
        if (!in_array($varParentId, $dirty, true)) {
            throw new \RuntimeException("Variation update did not coalesce to parent aggregate ID in dirty registry");
        }
        if (in_array($variationId, $dirty, true)) {
            throw new \RuntimeException("Variation ID was erroneously registered as an aggregate instead of coalescing to parent");
        }

        echo "  ✓ R150 passed: terms, stock, trash hooks captured and variations coalesce to parent\n";
    }

    public function testR151_raw_sql_detected_audit(): void
    {
        echo "Testing R151 (raw_sql_detected_audit)...\n";

        $productId = $this->createLegacySimpleProduct('R151 Raw SQL Product', 50.00, 10);
        $this->capture->captureLegacyProduct($productId, 'initial_sync');

        // Clear audit for this product
        $this->wpdb->delete($this->tableAudit, ['target_id' => $productId]);

        // Direct SQL update to wp_postmeta and touch post_modified_gmt without WordPress hooks
        $futureGmt = gmdate('Y-m-d H:i:s', time() + 10);
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->postmeta} SET meta_value = '999.00' WHERE post_id = %d AND meta_key = '_price'",
            $productId
        ));
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->posts} SET post_modified_gmt = %s WHERE ID = %d",
            $futureGmt,
            $productId
        ));

        // Run scanner
        $scanResult = $this->capture->runReconciliationScan(['full_scan' => true, 'force_repair' => true]);

        // Verify audit log has raw_sql_detected alert
        $auditRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT operation, summary FROM {$this->tableAudit} WHERE target_id = %d AND operation = 'raw_sql_detected'",
            $productId
        ), ARRAY_A);

        if (empty($auditRows)) {
            throw new \RuntimeException("Reconciliation scanner did not log raw_sql_detected audit record for direct SQL mutation");
        }

        echo "  ✓ R151 passed: direct SQL mutations detected and logged in wpe_audit\n";
    }

    public function testR152_origin_guard_no_pingpong(): void
    {
        echo "Testing R152 (origin_guard_no_pingpong)...\n";

        $productId = $this->createLegacySimpleProduct('R152 Origin Guard Product', 30.00, 10);
        $this->capture->captureLegacyProduct($productId, 'initial_sync');

        $this->capture->clearDirtyAggregates();

        if (LegacyCapture::isSyncing()) {
            throw new \RuntimeException("isSyncing() should be false initially");
        }

        $insideSyncing = false;
        $insideOrigin  = '';

        LegacyCapture::withOrigin('native_projection', function () use ($productId, &$insideSyncing, &$insideOrigin) {
            $insideSyncing = LegacyCapture::isSyncing();
            $insideOrigin  = LegacyCapture::getOrigin();

            // Perform post update that would normally trigger save_post hook
            wp_update_post([
                'ID'         => $productId,
                'post_title' => 'R152 Projected Title Under Guard',
            ]);
        });

        if (!$insideSyncing || $insideOrigin !== 'native_projection') {
            throw new \RuntimeException("withOrigin failed to set syncing depth and origin inside context");
        }

        if (LegacyCapture::isSyncing()) {
            throw new \RuntimeException("isSyncing() failed to restore to false after withOrigin context exited");
        }

        $dirty = $this->capture->getDirtyAggregates();
        if (in_array($productId, $dirty, true)) {
            throw new \RuntimeException("Origin guard failed to suppress hook during native_projection; product was marked dirty");
        }

        echo "  ✓ R152 passed: origin guard successfully suppresses legacy hooks during native projection\n";
    }

    public function testAC_T37_01_missed_hook_repair_and_no_loop(): void
    {
        echo "Testing AC-T37-01 (missed hook repair without sync loop)...\n";

        $productId = $this->createLegacySimpleProduct('AC-T37-01 Hook Repair Product', 15.00, 5);
        $this->capture->captureLegacyProduct($productId, 'initial');

        // Direct SQL update
        $futureGmt = gmdate('Y-m-d H:i:s', time() + 15);
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->posts} SET post_title = 'AC-T37-01 Repaired Title', post_modified_gmt = %s WHERE ID = %d",
            $futureGmt,
            $productId
        ));

        $scan = $this->capture->runReconciliationScan(['full_scan' => true, 'force_repair' => true]);
        if ($scan['repaired'] < 1) {
            throw new \RuntimeException("Reconciliation scan failed to repair missed hook");
        }

        $repairedTitle = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT title FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        if ($repairedTitle !== 'AC-T37-01 Repaired Title') {
            throw new \RuntimeException("Native aggregate was not updated by reconciliation repair");
        }

        // Now verify native projection running inside guard does not loop back into capture
        $this->capture->clearDirtyAggregates();
        LegacyCapture::withOrigin('native_projection', function () use ($productId) {
            wp_update_post([
                'ID'         => $productId,
                'post_title' => 'AC-T37-01 Protected Projected Title',
            ]);
        });

        if (in_array($productId, $this->capture->getDirtyAggregates(), true)) {
            throw new \RuntimeException("Projection triggered capture hook loop, violating AC-T37-01");
        }

        echo "  ✓ AC-T37-01 passed: missed hook repaired by scanner and projection does not trigger sync loop\n";
    }

    public function testAC_T37_02_native_authority_no_overwrite(): void
    {
        echo "Testing AC-T37-02 (native authority protection against legacy overwrite)...\n";

        $productId = $this->createLegacySimpleProduct('AC-T37-02 Legacy Base Product', 100.00, 50);
        $this->capture->captureLegacyProduct($productId, 'initial');

        // Set authority to native
        $this->capture->setAuthority($productId, 'native');

        // Update native product title directly to represent canonical native data
        $canonicalTitle = 'AC-T37-02 Canonical Native Engine Title';
        $this->wpdb->update($this->tableProducts, ['title' => $canonicalTitle], ['id' => $productId]);

        // Clear audit for this product
        $this->wpdb->delete($this->tableAudit, ['target_id' => $productId]);

        // Attempt 1: Legacy hook triggered
        $captureResult = $this->capture->captureLegacyProduct($productId, 'save_post');
        if ($captureResult['status'] !== 'blocked_native_authority' || $captureResult['overwrote'] !== false) {
            throw new \RuntimeException("Legacy capture allowed overwrite on product under native authority");
        }

        // Verify native table was NOT overwritten
        $currentNativeTitle = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT title FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        if ($currentNativeTitle !== $canonicalTitle) {
            throw new \RuntimeException("Native product was erroneously overwritten: expected '{$canonicalTitle}', got '{$currentNativeTitle}'");
        }

        // Verify audit alert was logged
        $alertCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableAudit} WHERE target_id = %d AND operation = 'native_authority_legacy_mismatch'",
            $productId
        ));
        if ($alertCount < 1) {
            throw new \RuntimeException("native_authority_legacy_mismatch alert was not recorded in wpe_audit");
        }

        // Attempt 2: Direct raw SQL update followed by reconciliation scanner
        $futureGmt = gmdate('Y-m-d H:i:s', time() + 25);
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->wpdb->posts} SET post_title = 'Rogue Raw SQL Title', post_modified_gmt = %s WHERE ID = %d",
            $futureGmt,
            $productId
        ));

        $scan = $this->capture->runReconciliationScan(['full_scan' => true, 'force_repair' => true]);
        if ($scan['alerts'] < 1) {
            throw new \RuntimeException("Reconciliation scanner did not flag alert for raw SQL mismatch under native authority");
        }

        // Verify native table STILL untouched
        $finalNativeTitle = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT title FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        if ($finalNativeTitle !== $canonicalTitle) {
            throw new \RuntimeException("Reconciliation scanner erroneously repaired native data under native authority: got '{$finalNativeTitle}'");
        }

        echo "  ✓ AC-T37-02 passed: legacy mutations under native authority generate alerts and never overwrite native storage\n";
    }
}

// Execute test
$test = new T37Test();
$test->run();
