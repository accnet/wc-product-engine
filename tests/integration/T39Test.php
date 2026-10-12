<?php
/**
 * T39 Integration Test — Native→legacy mirror and write ownership fence.
 *
 * Verifies:
 * - R157 (native_mirror_full_props): Projects CPT, postmeta, taxonomy, arbitrary metadata, and variations to legacy.
 * - R158 (mirror_out_of_order): Out-of-order or restarted mirror events skip obsolete revisions without reverting new data.
 * - R159 (dual_writer_rejected): Direct legacy writes under native authority are rejected unless routed by certified adapters.
 * - R160 (projection_hook_side_effect_guard): Origin guard suppresses legacy hooks during projection, preventing loops.
 * - AC-T39-01: Out-of-order mirror events do not revert state; historical stock operations remain intact.
 * - AC-T39-02: Epoch fence prevents stale workers from executing after authority switch.
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

use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Migration\LegacyCapture;
use WPE\Infrastructure\Database\ProductRepository;

class T39Test
{
    private \wpdb $wpdb;
    private LegacyMirror $mirror;
    private LegacyCapture $capture;
    private ProductRepository $productRepo;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableMeta;
    private string $tableProductTerms;
    private string $tableStockOperations;
    private string $tableAudit;
    private string $tableControl;

    private array $cleanProductIds = [];
    private array $cleanTermIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $prefix = $this->wpdb->prefix;

        $this->tableProducts        = "{$prefix}wpe_products";
        $this->tableVariants        = "{$prefix}wpe_variants";
        $this->tableInventory       = "{$prefix}wpe_inventory";
        $this->tableObjectState     = "{$prefix}wpe_object_state";
        $this->tableMeta            = "{$prefix}wpe_meta";
        $this->tableProductTerms    = "{$prefix}wpe_product_terms";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";
        $this->tableAudit           = "{$prefix}wpe_audit";
        $this->tableControl         = "{$prefix}wpe_control";

        $this->capture     = LegacyCapture::getInstance();
        $this->productRepo = new ProductRepository($this->wpdb);
        $this->mirror      = new LegacyMirror($this->wpdb, $this->capture, $this->productRepo);

        wp_set_current_user(1);
    }

    public function run(): void
    {
        echo "Running T39 Integration Tests...\n";
        $this->cleanUp();

        try {
            $this->testR157_native_mirror_full_props();
            $this->testR158_mirror_out_of_order();
            $this->testR159_dual_writer_rejected();
            $this->testR160_projection_hook_side_effect_guard();
            $this->testAC_T39_01_no_revert_and_historical_stock_preserved();
            $this->testAC_T39_02_epoch_fence_blocks_stale_worker();
            echo "All T39 tests passed successfully!\n";
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
                $this->wpdb->delete($this->tableObjectState, ['object_id' => $id]);
                $this->wpdb->delete($this->tableMeta, ['object_id' => $id]);
                $this->wpdb->delete($this->tableProductTerms, ['product_id' => $id]);
                $this->wpdb->delete($this->tableStockOperations, ['owner_id' => $id]);
                $this->wpdb->delete($this->tableAudit, ['target_id' => $id]);
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

    private function createTestNativeProduct(string $title, float $price, int $stock = 25): int
    {
        // Pre-allocate shell ID via wp_posts
        $shellId = wp_insert_post([
            'post_title'   => 'Draft Shell',
            'post_status'  => 'draft',
            'post_type'    => 'product',
        ]);

        $this->cleanProductIds[] = $shellId;
        $now = gmdate('Y-m-d H:i:s.u');

        // Insert native product
        $sku = 'SKU-NATIVE-' . strtoupper(wp_generate_password(6, false, false));
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $shellId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => sanitize_title($title) . '-' . wp_generate_password(4, false, false),
            'title'              => $title,
            'description'        => "Native description for {$title}",
            'short_description'  => "Short desc for {$title}",
            'catalog_visibility' => 'visible',
            'sku'                => $sku,
            'regular_price'      => $price,
            'sale_price'         => null,
            'weight'             => 1.5,
            'purchase_note'      => '',
            'default_selection'  => '',
            'extra_props'        => '{}',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        // Insert native inventory
        $this->wpdb->replace($this->tableInventory, [
            'owner_id'         => $shellId,
            'manage_stock'     => 1,
            'quantity'         => $stock,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => null,
            'updated_at'       => $now,
            'version'          => 1,
        ]);

        // Insert native object state
        $this->wpdb->replace($this->tableObjectState, [
            'object_id'       => $shellId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'native',
            'epoch'           => 1,
            'legacy_revision' => 0,
            'native_revision' => 1,
            'legacy_applied'  => 0,
            'native_applied'  => 0,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        return $shellId;
    }

    public function testR157_native_mirror_full_props(): void
    {
        echo "Testing R157 (native_mirror_full_props)...\n";

        $productId = $this->createTestNativeProduct('R157 Mirror Product', 49.95, 30);

        // Add custom meta to wpe_meta
        $maxMetaId = (int)$this->wpdb->get_var("SELECT COALESCE(MAX(meta_id), 0) FROM {$this->tableMeta}");
        $this->wpdb->insert($this->tableMeta, [
            'meta_id'     => $maxMetaId + 1,
            'object_id'   => $productId,
            'object_kind' => 'product',
            'meta_key'    => '_custom_brand_tier',
            'meta_value'  => 'Premium',
        ]);

        // Add taxonomy term to wpe_product_terms
        $termResult = wp_insert_term('R157 Category ' . wp_generate_password(4, false, false), 'product_cat');
        $termTaxId = (int)$termResult['term_taxonomy_id'];
        $this->cleanTermIds[] = (int)$termResult['term_id'];

        $this->wpdb->insert($this->tableProductTerms, [
            'product_id'       => $productId,
            'term_taxonomy_id' => $termTaxId,
            'role'             => 'product_cat',
        ]);

        // Add variation in wpe_variants
        $varShellId = wp_insert_post([
            'post_title'   => 'Variation Shell',
            'post_status'  => 'draft',
            'post_type'    => 'product_variation',
            'post_parent'  => $productId,
        ]);
        $now = gmdate('Y-m-d H:i:s.u');
        $combKey = hash('sha256', "var_{$varShellId}", true);
        $this->wpdb->insert($this->tableVariants, [
            'id'                     => $varShellId,
            'product_id'             => $productId,
            'status'                 => 'publish',
            'sku'                    => 'SKU-R157-V1',
            'combination_key'        => $combKey,
            'active_combination_key' => $combKey,
            'regular_price'          => 29.95,
            'sale_price'             => 24.95,
            'description'            => 'Variation desc',
            'extra_props'            => '{}',
            'created_at'             => $now,
            'updated_at'             => $now,
            'version'                => 1,
        ]);
        $this->wpdb->replace($this->tableInventory, [
            'owner_id'         => $varShellId,
            'manage_stock'     => 1,
            'quantity'         => 12,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => null,
            'updated_at'       => $now,
            'version'          => 1,
        ]);

        // Add historical stock operation to wpe_stock_operations (AC-T39-01)
        $opKey = hash('sha256', "op_initial_{$productId}", true);
        $this->wpdb->insert($this->tableStockOperations, [
            'operation_key'  => $opKey,
            'owner_id'       => $productId,
            'kind'           => 'stock_set',
            'delta'          => 30,
            'quantity_after' => 30,
            'created_at'     => $now,
        ]);

        // Execute Mirror
        $result = $this->mirror->mirrorAggregate($productId, 1, 1);
        if ($result['status'] !== 'mirrored') {
            throw new \RuntimeException("mirrorAggregate failed: status={$result['status']}");
        }

        // Verify wp_posts projection
        $post = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT post_title, post_status, post_type FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ), ARRAY_A);
        if ($post['post_title'] !== 'R157 Mirror Product' || $post['post_status'] !== 'publish') {
            throw new \RuntimeException("wp_posts projection failed: title={$post['post_title']}, status={$post['post_status']}");
        }

        // Verify wp_postmeta projection
        $regPrice = get_post_meta($productId, '_regular_price', true);
        $stockQty = get_post_meta($productId, '_stock', true);
        $brandTier = get_post_meta($productId, '_custom_brand_tier', true);

        if ((float)$regPrice !== 49.95) {
            throw new \RuntimeException("wp_postmeta _regular_price mismatch: expected 49.95, got {$regPrice}");
        }
        if ((float)$stockQty !== 30.0) {
            throw new \RuntimeException("wp_postmeta _stock mismatch: expected 30, got {$stockQty}");
        }
        if ($brandTier !== 'Premium') {
            throw new \RuntimeException("Custom wpe_meta _custom_brand_tier was not projected to wp_postmeta: got {$brandTier}");
        }

        // Verify taxonomy term projected
        $termLinked = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$this->wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d",
            $productId,
            $termTaxId
        ));
        if ((int)$termLinked !== $termTaxId) {
            throw new \RuntimeException("Taxonomy term was not projected into wp_term_relationships");
        }

        // Verify variation projected
        $varPrice = get_post_meta($varShellId, '_price', true);
        if ((float)$varPrice !== 24.95) {
            throw new \RuntimeException("Variation _price was not projected: expected 24.95, got {$varPrice}");
        }

        // Verify historical stock operations preserved intact (AC-T39-01)
        $opCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableStockOperations} WHERE owner_id = %d",
            $productId
        ));
        if ($opCount < 1) {
            throw new \RuntimeException("Historical stock operation record was lost during mirroring, violating AC-T39-01");
        }

        echo "  ✓ R157 passed: full aggregate props, metadata, terms, and variations projected to legacy; stock operations preserved\n";
    }

    public function testR158_mirror_out_of_order(): void
    {
        echo "Testing R158 (mirror_out_of_order)...\n";

        $productId = $this->createTestNativeProduct('R158 Out of Order Product', 10.00);

        // Project initial revision 5
        $this->mirror->mirrorAggregate($productId, 5, 1);

        // Current legacy title in wp_posts
        $currentLegacyTitle = 'R158 Current Newer Title';
        $this->wpdb->update($this->wpdb->posts, ['post_title' => $currentLegacyTitle], ['ID' => $productId]);

        // An out-of-order event arrives with older revision 3
        $res = $this->mirror->mirrorAggregate($productId, 3, 1);

        if ($res['status'] !== 'skipped_obsolete_revision') {
            throw new \RuntimeException("Out-of-order projection was not skipped: status={$res['status']}");
        }

        // Verify wp_posts was NOT reverted to older state (AC-T39-01)
        $postTitle = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT post_title FROM {$this->wpdb->posts} WHERE ID = %d",
            $productId
        ));
        if ($postTitle !== $currentLegacyTitle) {
            throw new \RuntimeException("Out-of-order event reverted legacy state: expected '{$currentLegacyTitle}', got '{$postTitle}'");
        }

        echo "  ✓ R158 passed: obsolete out-of-order mirror events safely skipped without reverting newer state\n";
    }

    public function testR159_dual_writer_rejected(): void
    {
        echo "Testing R159 (dual_writer_rejected)...\n";

        $productId = $this->createTestNativeProduct('R159 Dual Writer Product', 75.00);

        // Attempt 1: Unauthorized direct legacy write under native authority
        $res1 = $this->mirror->interceptLegacyWrite($productId, '_price', '999.00', 1, false);
        if ($res1['status'] !== 'rejected' || !$res1['blocked']) {
            throw new \RuntimeException("Dual-writer write was not rejected under native authority");
        }

        // Verify audit log captured alert
        $alertCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableAudit} WHERE target_id = %d AND operation = 'dual_writer_rejected'",
            $productId
        ));
        if ($alertCount < 1) {
            throw new \RuntimeException("dual_writer_rejected alert was not recorded in wpe_audit");
        }

        // Attempt 2: Certified plugin write under native authority
        $res2 = $this->mirror->interceptLegacyWrite($productId, '_certified_attr', 'CertifiedVal', 1, true);
        if ($res2['status'] !== 'routed_native' || $res2['blocked']) {
            throw new \RuntimeException("Certified plugin write was not routed to native storage");
        }

        // Verify routed value is stored in native wpe_meta
        $savedVal = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_value FROM {$this->tableMeta} WHERE object_id = %d AND meta_key = '_certified_attr'",
            $productId
        ));
        if ($savedVal !== 'CertifiedVal') {
            throw new \RuntimeException("Routed certified write was not stored in wpe_meta: got '{$savedVal}'");
        }

        echo "  ✓ R159 passed: unauthorized dual-writers rejected and certified writes routed to native storage\n";
    }

    public function testR160_projection_hook_side_effect_guard(): void
    {
        echo "Testing R160 (projection_hook_side_effect_guard)...\n";

        $productId = $this->createTestNativeProduct('R160 Hook Guard Product', 12.50);

        $this->capture->clearDirtyAggregates();

        // Project native state into legacy (which fires wp_posts and postmeta updates)
        $this->mirror->mirrorAggregate($productId, 1, 1);

        // Verify origin guard suppressed legacy capture
        $dirty = $this->capture->getDirtyAggregates();
        if (in_array($productId, $dirty, true)) {
            throw new \RuntimeException("Projection triggered capture hook loop, violating R160 and AC-T37-01");
        }

        echo "  ✓ R160 passed: origin guard successfully suppressed secondary capture hooks during projection\n";
    }

    public function testAC_T39_01_no_revert_and_historical_stock_preserved(): void
    {
        echo "Testing AC-T39-01 (no revert and historical stock preserved)...\n";

        $productId = $this->createTestNativeProduct('AC-T39-01 Product', 60.00, 15);

        // Record two historical stock operations
        $this->wpdb->insert($this->tableStockOperations, [
            'operation_key'  => hash('sha256', "op_initial_{$productId}", true),
            'owner_id'       => $productId,
            'kind'           => 'stock_set',
            'delta'          => 15,
            'quantity_after' => 15,
            'created_at'     => gmdate('Y-m-d H:i:s.u'),
        ]);
        $this->wpdb->insert($this->tableStockOperations, [
            'operation_key'  => hash('sha256', "op_sale_{$productId}", true),
            'owner_id'       => $productId,
            'kind'           => 'stock_reduce',
            'delta'          => -2,
            'quantity_after' => 13,
            'created_at'     => gmdate('Y-m-d H:i:s.u'),
        ]);

        // Mirror revision 2
        $this->mirror->mirrorAggregate($productId, 2, 1);

        // Out-of-order restart event with revision 1
        $res = $this->mirror->mirrorAggregate($productId, 1, 1);
        if ($res['status'] !== 'skipped_obsolete_revision') {
            throw new \RuntimeException("Restart / out-of-order event was not skipped");
        }

        // Verify stock operations count remains 2
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableStockOperations} WHERE owner_id = %d",
            $productId
        ));
        if ($count !== 2) {
            throw new \RuntimeException("Historical stock operations count altered: expected 2, got {$count}");
        }

        // Verify projection lag report
        $lag = $this->mirror->getProjectionLag();
        if (!isset($lag['pending_projections']) || !isset($lag['status'])) {
            throw new \RuntimeException("Projection lag metrics incomplete");
        }

        echo "  ✓ AC-T39-01 passed: out-of-order events rejected and historical stock operations preserved\n";
    }

    public function testAC_T39_02_epoch_fence_blocks_stale_worker(): void
    {
        echo "Testing AC-T39-02 (epoch fence blocks stale worker)...\n";

        $productId = $this->createTestNativeProduct('AC-T39-02 Epoch Fenced Product', 80.00);

        // Authority switch: epoch increments from 1 to 2
        $this->mirror->switchAuthorityEpoch($productId, 'native', 2);

        // Clear audit for this product
        $this->wpdb->delete($this->tableAudit, ['target_id' => $productId]);

        // Stale worker tries to mirror with old epoch 1
        $res = $this->mirror->mirrorAggregate($productId, 3, 1);

        if ($res['status'] !== 'rejected_stale_epoch') {
            throw new \RuntimeException("Stale worker with old epoch 1 was not rejected by epoch fence: status={$res['status']}");
        }

        // Verify audit record logged
        $alertCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableAudit} WHERE target_id = %d AND operation = 'epoch_fence_rejected'",
            $productId
        ));
        if ($alertCount < 1) {
            throw new \RuntimeException("epoch_fence_rejected alert was not recorded in wpe_audit");
        }

        echo "  ✓ AC-T39-02 passed: epoch fence strictly blocks stale workers after authority switch\n";
    }
}

// Execute test
$test = new T39Test();
$test->run();
