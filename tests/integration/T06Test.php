<?php
/**
 * T06 Integration Test — Product/variant repositories and aggregate commands.
 *
 * Verifies R18 (aggregate_atomicity), R19 (sku_race), R20 (variant_wrong_parent),
 * R21 (deleted_product_order_reference), R22 (stale_write_412),
 * AC-T06-01 (canonical snapshot match & outbox), and AC-T06-02 (legacy ID reuse).
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

use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OutboxWorker;
use WPE\Infrastructure\Database\StaleVersionException;
use WPE\Infrastructure\Database\DuplicateSkuException;
use WPE\Infrastructure\Database\InvalidParentException;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Bootstrap\FixtureHarness;

class T06Test
{
    private \wpdb $wpdb;
    private ProductRepository $repo;
    private PostIdAllocator $allocator;
    private OutboxWorker $outboxWorker;
    private FixtureHarness $harness;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->allocator = new PostIdAllocator($wpdb);
        $this->outboxWorker = new OutboxWorker($wpdb);
        $this->repo = new ProductRepository($wpdb, $this->allocator, $this->outboxWorker);
        $this->harness = new FixtureHarness(65432);
    }

    public function run(): int
    {
        echo "=== Running T06 Integration Tests ===\n";

        $r18 = $this->testAggregateAtomicity();
        $r19 = $this->testSkuRace();
        $r20 = $this->testVariantWrongParent();
        $r21 = $this->testDeletedProductOrderReference();
        $r22 = $this->testStaleWrite412();
        $ac01 = $this->testCanonicalSnapshotMatch();
        $ac02 = $this->testLegacyIdReuse();

        echo "\n=== Test Summary ===\n";
        echo "R18 (aggregate_atomicity): " . ($r18 ? "PASSED" : "FAILED") . "\n";
        echo "R19 (sku_race): " . ($r19 ? "PASSED" : "FAILED") . "\n";
        echo "R20 (variant_wrong_parent): " . ($r20 ? "PASSED" : "FAILED") . "\n";
        echo "R21 (deleted_product_order_reference): " . ($r21 ? "PASSED" : "FAILED") . "\n";
        echo "R22 (stale_write_412): " . ($r22 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T06-01 (canonical_snapshot_outbox): " . ($ac01 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T06-02 (legacy_id_reuse): " . ($ac02 ? "PASSED" : "FAILED") . "\n";

        if ($r18 && $r19 && $r20 && $r21 && $r22 && $ac01 && $ac02) {
            echo "All T06 tests passed successfully.\n";
            return 0;
        }

        echo "T06 tests failed.\n";
        return 1;
    }

    /**
     * R18: Verify aggregate atomicity on invalid child or failure.
     */
    private function testAggregateAtomicity(): bool
    {
        echo "[R18] Verifying aggregate atomicity on invalid variant price...\n";

        $testSku = 'ATOM-TEST-' . mt_rand(100000, 999999);
        $invalidFixture = [
            'name' => 'Atomicity Test Product',
            'type' => 'variable',
            'sku'  => $testSku,
            'regular_price' => '100000.000000',
            'variations' => [
                [
                    'sku' => $testSku . '-V1',
                    'regular_price' => '100000.000000',
                    'attributes' => ['Size' => 'M'],
                ],
                [
                    'sku' => $testSku . '-V2',
                    'regular_price' => '-25000.000000', // Invalid negative price
                    'attributes' => ['Size' => 'L'],
                ],
            ]
        ];

        $caught = false;
        try {
            $this->repo->create($invalidFixture);
        } catch (\InvalidArgumentException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected InvalidArgumentException for negative price, but none was thrown.\n";
            return false;
        }

        // Verify no row in wpe_products exists with this SKU
        $pCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_products WHERE sku = %s",
            $testSku
        ));

        // Verify no SKU in sku_registry exists
        $skuCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku = %s",
            $testSku
        ));

        if ($pCount !== 0 || $skuCount !== 0) {
            echo "  [FAIL] Partial aggregate leaked into DB: pCount={$pCount}, skuCount={$skuCount}.\n";
            return false;
        }

        echo "  [OK] Transaction rolled back cleanly; no partial aggregate rows persisted.\n";
        return true;
    }

    /**
     * R19: Verify duplicate SKU race enforcement.
     */
    private function testSkuRace(): bool
    {
        echo "[R19] Verifying duplicate SKU race and registry uniqueness...\n";

        $sku = 'RACE-SKU-' . mt_rand(100000, 999999);

        // Product A
        $prodA = $this->repo->create([
            'name'          => 'Product A SKU Race',
            'type'          => 'simple',
            'sku'           => $sku,
            'regular_price' => '50000.000000',
        ]);

        if (empty($prodA['id'])) {
            echo "  [FAIL] Failed to create initial product A.\n";
            return false;
        }

        // Product B with identical SKU
        $caught = false;
        try {
            $this->repo->create([
                'name'          => 'Product B SKU Race',
                'type'          => 'simple',
                'sku'           => $sku,
                'regular_price' => '60000.000000',
            ]);
        } catch (DuplicateSkuException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected DuplicateSkuException for duplicate SKU, but none was thrown.\n";
            return false;
        }

        echo "  [OK] Duplicate SKU was safely rejected by SKU registry uniqueness.\n";
        return true;
    }

    /**
     * R20: Verify variant wrong parent rejection.
     */
    private function testVariantWrongParent(): bool
    {
        echo "[R20] Verifying variant wrong parent rejection...\n";

        $sku = 'PARENT-TEST-' . mt_rand(100000, 999999);
        $fixture = [
            'name'          => 'Variable Product Parent Test',
            'type'          => 'variable',
            'sku'           => $sku,
            'regular_price' => '75000.000000',
            'variations'    => [
                [
                    'parent_id'     => '99999999', // Deliberate mismatch
                    'sku'           => $sku . '-V1',
                    'regular_price' => '75000.000000',
                    'attributes'    => ['Color' => 'Red'],
                ]
            ]
        ];

        $caught = false;
        try {
            $this->repo->create($fixture);
        } catch (InvalidParentException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected InvalidParentException for variant parent mismatch.\n";
            return false;
        }

        echo "  [OK] Variant with mismatched parent_id was correctly rejected.\n";
        return true;
    }

    /**
     * R21: Verify deleted product order reference and tombstone preservation.
     */
    private function testDeletedProductOrderReference(): bool
    {
        echo "[R21] Verifying deleted product order reference and soft delete tombstone...\n";

        $sku = 'TOMB-TEST-' . mt_rand(100000, 999999);
        $prod = $this->repo->create([
            'name'          => 'Tombstone Test Product',
            'type'          => 'variable',
            'sku'           => $sku,
            'regular_price' => '120000.000000',
            'variations'    => [
                [
                    'sku'           => $sku . '-V1',
                    'regular_price' => '120000.000000',
                    'attributes'    => ['Size' => 'M'],
                ]
            ]
        ]);

        $productId = (int)$prod['id'];
        $variantId = (int)$prod['variations'][0]['id'];

        // Trash the product
        $trashed = $this->repo->trash($productId);
        if (!$trashed) {
            echo "  [FAIL] Failed to trash product {$productId}.\n";
            return false;
        }

        // Active catalog read returns null
        $activeRead = $this->repo->getById($productId, false);
        if ($activeRead !== null) {
            echo "  [FAIL] Trashed product still visible in active catalog read.\n";
            return false;
        }

        // Historical order reference read (includeDeleted = true) returns snapshot
        $histRead = $this->repo->getById($productId, true);
        if (!$histRead || $histRead['status'] !== 'trash' || empty($histRead['deleted_at'])) {
            echo "  [FAIL] Historical read failed to retrieve trashed product with tombstone.\n";
            return false;
        }

        // Historical variant read also succeeds
        $varHist = $this->repo->getVariantById($variantId, true);
        if (!$varHist || $varHist['status'] !== 'trash') {
            echo "  [FAIL] Historical read failed to retrieve variant.\n";
            return false;
        }

        // Active combination key should be NULL in DB for tombstone
        $activeKey = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT active_combination_key FROM {$this->wpdb->prefix}wpe_variants WHERE id = %d",
            $variantId
        ));

        if ($activeKey !== null) {
            echo "  [FAIL] active_combination_key was not set to NULL upon trashing.\n";
            return false;
        }

        echo "  [OK] Product soft-deleted with tombstone; historical order references preserved.\n";
        return true;
    }

    /**
     * R22: Verify stale write detection with 412 Precondition Failed.
     */
    private function testStaleWrite412(): bool
    {
        echo "[R22] Verifying optimistic locking / CAS version mismatch 412...\n";

        $sku = 'CAS-TEST-' . mt_rand(100000, 999999);
        $prod = $this->repo->create([
            'name'          => 'CAS Test Product',
            'type'          => 'simple',
            'sku'           => $sku,
            'regular_price' => '80000.000000',
        ]);

        $productId = (int)$prod['id'];

        // Update to version 2
        $updated = $this->repo->update($productId, [
            'name'          => 'CAS Test Product Updated',
            'version'       => 1,
            'regular_price' => '85000.000000',
        ]);

        if ($updated['version'] !== 2) {
            echo "  [FAIL] Expected version 2 after first update, got {$updated['version']}.\n";
            return false;
        }

        // Attempt stale write using version 1
        $caught = false;
        $code = 0;
        try {
            $this->repo->update($productId, [
                'name'    => 'Stale Update Attempt',
                'version' => 1, // Stale!
            ]);
        } catch (StaleVersionException $e) {
            $caught = true;
            $code = $e->getCode();
        }

        if (!$caught || $code !== 412) {
            echo "  [FAIL] Expected StaleVersionException with code 412, caught={$caught}, code={$code}.\n";
            return false;
        }

        echo "  [OK] Stale write successfully rejected with 412 Precondition Failed.\n";
        return true;
    }

    /**
     * AC-T06-01: Canonical snapshot match and outbox transaction.
     */
    private function testCanonicalSnapshotMatch(): bool
    {
        echo "[AC-T06-01] Verifying save-and-reload matches canonical snapshot and outbox event...\n";

        $randSeed = mt_rand(100000, 999999);
        $fixture = $this->harness->generateProductFixture('variable', [
            'id'            => (string)$randSeed,
            'sku'           => 'CANON-SKU-' . $randSeed,
            'regular_price' => '250000.000000',
        ]);

        $created = $this->repo->create($fixture);
        $productId = (int)$created['id'];

        $reloaded = $this->repo->getById($productId);
        if (!$reloaded) {
            echo "  [FAIL] Failed to reload saved product {$productId}.\n";
            return false;
        }

        // Compare snapshots using FixtureHarness
        $diff = $this->harness->compareSnapshots($created, $reloaded);
        if (!$diff['identical']) {
            echo "  [FAIL] Snapshot differences detected between save and reload: " . json_encode($diff['differences']) . "\n";
            return false;
        }

        // Check transactional outbox event
        $outboxRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->wpdb->prefix}wpe_outbox WHERE aggregate_id = %d AND event_type = 'product.created'",
            $productId
        ));

        if (!$outboxRow) {
            echo "  [FAIL] Outbox event product.created not found for aggregate {$productId}.\n";
            return false;
        }

        echo "  [OK] Saved and reloaded snapshot matches canonical structure; outbox event verified.\n";
        return true;
    }

    /**
     * AC-T06-02: Legacy ID reuse during migration.
     */
    private function testLegacyIdReuse(): bool
    {
        echo "[AC-T06-02] Verifying legacy ID reuse during migration...\n";

        // Create a real legacy post in WordPress posts table
        $legacyPostId = wp_insert_post([
            'post_title'   => 'Legacy Product To Migrate',
            'post_status'  => 'publish',
            'post_type'    => 'product',
            'post_content' => 'Legacy content',
        ]);

        if (is_wp_error($legacyPostId) || (int)$legacyPostId <= 0) {
            echo "  [FAIL] Failed to create legacy post fixture in WordPress.\n";
            return false;
        }

        $legacyId = (int)$legacyPostId;
        $sku = 'LEGACY-' . $legacyId;

        $migrated = $this->repo->create([
            'id'            => $legacyId,
            'name'          => 'Migrated Legacy Product',
            'type'          => 'simple',
            'sku'           => $sku,
            'regular_price' => '150000.000000',
        ], true);

        if ((int)$migrated['id'] !== $legacyId) {
            echo "  [FAIL] Migrated product ID {$migrated['id']} does not match legacy ID {$legacyId}.\n";
            return false;
        }

        $reloaded = $this->repo->getById($legacyId);
        if (!$reloaded || (int)$reloaded['id'] !== $legacyId) {
            echo "  [FAIL] Failed to reload migrated product by legacy ID.\n";
            return false;
        }

        echo "  [OK] Legacy ID {$legacyId} was cleanly reused without re-allocation.\n";
        return true;
    }
}

$test = new T06Test();
exit($test->run());
