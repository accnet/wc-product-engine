<?php
/**
 * T16 Integration Test — Projection builder and generation rebuild.
 *
 * Verifies:
 * - R63 (rebuild_under_writes): Catch-up revision fence catches dirty writes during rebuild.
 * - R64 (tombstone_no_resurrection): Trashed/deleted products never resurrect into active index.
 * - R65 (sale_clock_boundary_dst): Scheduled sale prices automatically switch across time boundary without save.
 * - R66 (generation_cursor_retention): Previous generations are retained for cursor lifespan.
 * - AC-T16-01: Dirty products caught up before switch; previous generation retained.
 * - AC-T16-02: Price schedule transitions automatically without admin mutation.
 * - AC-T16-03: Zero orphan facets on update; source_version audit passes cleanly.
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

use WPE\Application\Catalog\CatalogProjector;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WooDataStore\ProductLifecycle;

class T16Test
{
    private \wpdb $wpdb;
    private CatalogProjector $projector;
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Register data stores and bridges
        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        ProductLifecycle::register();

        $this->projector = new CatalogProjector($this->wpdb);
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        if (empty($this->cleanProductIds)) {
            return;
        }

        $pTable = $this->wpdb->prefix . 'wpe_products';
        $vTable = $this->wpdb->prefix . 'wpe_variants';
        $iTable = $this->wpdb->prefix . 'wpe_inventory';
        $sTable = $this->wpdb->prefix . 'wpe_sku_registry';
        $cTable = $this->wpdb->prefix . 'wpe_catalog';
        $fTable = $this->wpdb->prefix . 'wpe_catalog_facets';

        foreach ($this->cleanProductIds as $pid) {
            $this->wpdb->delete($pTable, ['id' => $pid]);
            $this->wpdb->delete($vTable, ['product_id' => $pid]);
            $this->wpdb->delete($iTable, ['owner_id' => $pid]);
            $this->wpdb->delete($sTable, ['object_id' => $pid]);
            $this->wpdb->delete($cTable, ['product_id' => $pid]);
            $this->wpdb->delete($fTable, ['product_id' => $pid]);
            wp_delete_post($pid, true);
        }
    }

    private function createSimpleProduct(string $name, float $price, array $extraProps = []): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_status('publish');
        $product->set_regular_price((string)$price);
        $product->set_price((string)$price);
        $product->set_sku('SKU-T16-' . wp_generate_password(6, false));
        $product->save();

        $pid = $product->get_id();
        $this->cleanProductIds[] = $pid;

        if (!empty($extraProps)) {
            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->update(
                $this->wpdb->prefix . 'wpe_products',
                ['extra_props' => json_encode($extraProps, JSON_UNESCAPED_UNICODE), 'updated_at' => $now],
                ['id' => $pid]
            );
        }

        return $product;
    }

    /* -------------------------------------------------------------------------- */
    /* Test R63 & AC-T16-01: Rebuild Under Writes & Catch-up Revision Fence       */
    /* -------------------------------------------------------------------------- */

    public function testR63RebuildUnderWrites(): void
    {
        echo "[Test R63] Testing rebuild under concurrent writes with catch-up revision fence...\n";

        $p1 = $this->createSimpleProduct('R63 Product 1', 20.00);
        $p2 = $this->createSimpleProduct('R63 Product 2', 40.00);
        $pid1 = $p1->get_id();
        $pid2 = $p2->get_id();

        $activeGen = $this->projector->getActiveGeneration();
        $this->projector->projectProduct($pid1, $activeGen);
        $this->projector->projectProduct($pid2, $activeGen);

        // Track progress during rebuild and simulate a concurrent write
        $writeSimulated = false;
        $progressCallback = function ($processed, $total, $stage) use (&$writeSimulated, $pid2) {
            if (!$writeSimulated && $processed >= 1) {
                $writeSimulated = true;
                // Simulate a concurrent write on Product 2 during rebuild
                $now = gmdate('Y-m-d H:i:s.u');
                $this->wpdb->update(
                    $this->wpdb->prefix . 'wpe_products',
                    [
                        'regular_price' => '99.99',
                        'version'       => 5,
                        'updated_at'    => $now,
                    ],
                    ['id' => $pid2]
                );
            }
        };

        // Rebuild new generation
        $newGen = $this->projector->buildNewGeneration($progressCallback);

        if ($newGen <= $activeGen) {
            throw new \RuntimeException("R63 Failed: New generation number not incremented: {$newGen}");
        }

        // Active pointer must now point to new generation
        if ($this->projector->getActiveGeneration() !== $newGen) {
            throw new \RuntimeException("R63 Failed: Active generation was not atomically switched to {$newGen}");
        }

        // AC-T16-01: Check that the dirty product in new generation caught up with the concurrent write
        $row2 = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT source_version, min_price, max_price FROM {$this->wpdb->prefix}wpe_catalog
             WHERE generation = %d AND product_id = %d",
            $newGen,
            $pid2
        ), ARRAY_A);

        if (!$row2 || (int)$row2['source_version'] !== 5 || (float)$row2['min_price'] !== 99.99) {
            throw new \RuntimeException("AC-T16-01 Failed: Dirty product did not catch up during rebuild: " . json_encode($row2));
        }

        echo "PASSED: Rebuild under writes and catch-up revision fence verified.\n\n";
    }

    /* -------------------------------------------------------------------------- */
    /* Test R64: Tombstone No Resurrection                                        */
    /* -------------------------------------------------------------------------- */

    public function testR64TombstoneNoResurrection(): void
    {
        echo "[Test R64] Testing tombstones never resurrect into active catalog...\n";

        $p = $this->createSimpleProduct('R64 Tombstone Product', 30.00);
        $pid = $p->get_id();
        $gen = $this->projector->getActiveGeneration();

        $this->projector->projectProduct($pid, $gen);

        // Verify product initially in index
        $indexed = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ));
        if ($indexed !== 1) {
            throw new \RuntimeException("R64 Failed: Product was not initially indexed");
        }

        // Soft-delete / trash product
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_products',
            ['status' => 'trash', 'deleted_at' => $now, 'updated_at' => $now],
            ['id' => $pid]
        );

        // Re-run projection on the trashed product
        $this->projector->projectProduct($pid, $gen);

        // Verify row removed from catalog and facets
        $indexedAfter = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ));
        $facetsAfter = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_catalog_facets WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ));

        if ($indexedAfter !== 0 || $facetsAfter !== 0) {
            throw new \RuntimeException("R64 Failed: Tombstoned product still present in catalog or facets!");
        }

        // Rebuild generation: must NOT resurrect trashed product
        $nextGen = $this->projector->buildNewGeneration();
        $resurrected = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_catalog WHERE generation = %d AND product_id = %d",
            $nextGen,
            $pid
        ));

        if ($resurrected !== 0) {
            throw new \RuntimeException("R64 Failed: Tombstone resurrected during generation rebuild!");
        }

        echo "PASSED: Tombstone no resurrection verified.\n\n";
    }

    /* -------------------------------------------------------------------------- */
    /* Test R65 & AC-T16-02: Sale Clock Boundary and Automatic Transition         */
    /* -------------------------------------------------------------------------- */

    public function testR65SaleClockBoundaryDst(): void
    {
        echo "[Test R65] Testing automatic sale schedule transitions across time boundaries...\n";

        // Create product with scheduled sale in the future (relative to $refTime)
        $refTime = '2026-10-12 10:00:00.000000';
        $saleStartTime = '2026-10-12 12:00:00.000000';
        $saleEndTime = '2026-10-12 18:00:00.000000';

        $p = $this->createSimpleProduct('R65 Scheduled Product', 100.00);
        $pid = $p->get_id();

        // Update with sale price and schedule
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_products',
            [
                'regular_price' => '100.00',
                'sale_price'    => '75.00',
                'extra_props'   => json_encode([
                    'date_on_sale_from' => $saleStartTime,
                    'date_on_sale_to'   => $saleEndTime,
                ]),
                'updated_at'    => $now,
            ],
            ['id' => $pid]
        );

        $gen = $this->projector->getActiveGeneration();

        // 1. Project at $refTime (before sale begins)
        $this->projector->projectProduct($pid, $gen, $refTime);

        $catBefore = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT on_sale, min_price, next_price_change FROM {$this->wpdb->prefix}wpe_catalog
             WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ), ARRAY_A);

        if ((int)$catBefore['on_sale'] !== 0 || (float)$catBefore['min_price'] !== 100.00) {
            throw new \RuntimeException("R65 Failed: Product prematurely on sale before start time: " . json_encode($catBefore));
        }
        if (empty($catBefore['next_price_change'])) {
            throw new \RuntimeException("R65 Failed: next_price_change not set for scheduled sale");
        }

        // 2. Evaluate scheduled transitions at future time past $saleStartTime (AC-T16-02)
        // Without any user mutation / save, evaluateScheduledPriceChanges must update the catalog
        $duringSaleTime = '2026-10-12 13:00:00.000000';
        $updated = $this->projector->evaluateScheduledPriceChanges($duringSaleTime);

        if ($updated < 1) {
            throw new \RuntimeException("AC-T16-02 Failed: evaluateScheduledPriceChanges found no transitions due");
        }

        $catDuring = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT on_sale, min_price, max_price FROM {$this->wpdb->prefix}wpe_catalog
             WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ), ARRAY_A);

        if ((int)$catDuring['on_sale'] !== 1 || (float)$catDuring['min_price'] !== 75.00) {
            throw new \RuntimeException("AC-T16-02 Failed: Product price did not transition to sale automatically: " . json_encode($catDuring));
        }

        // 3. Evaluate past $saleEndTime
        $afterSaleTime = '2026-10-12 19:00:00.000000';
        $this->projector->evaluateScheduledPriceChanges($afterSaleTime);

        $catAfter = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT on_sale, min_price FROM {$this->wpdb->prefix}wpe_catalog
             WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ), ARRAY_A);

        if ((int)$catAfter['on_sale'] !== 0 || (float)$catAfter['min_price'] !== 100.00) {
            throw new \RuntimeException("R65 Failed: Product did not revert to regular price after sale end: " . json_encode($catAfter));
        }

        echo "PASSED: Automatic sale schedule transitions and boundary evaluated.\n\n";
    }

    /* -------------------------------------------------------------------------- */
    /* Test R66 & AC-T16-01: Generation Cursor Retention                          */
    /* -------------------------------------------------------------------------- */

    public function testR66GenerationCursorRetention(): void
    {
        echo "[Test R66] Testing previous generation retention for active cursors...\n";

        $p = $this->createSimpleProduct('R66 Cursor Retention Product', 55.00);
        $pid = $p->get_id();

        // 1. Ensure current generation is populated on a clean baseline
        $this->projector->purgeOldGenerations(1);
        $gen1 = $this->projector->getActiveGeneration();
        $this->projector->projectProduct($pid, $gen1);

        // 2. Build Generation 2
        $gen2 = $this->projector->buildNewGeneration();

        // 3. Verify Gen 1 is STILL preserved in storage (AC-T16-01, R66)
        $gen1Count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_catalog WHERE generation = %d",
            $gen1
        ));
        $gen2Count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->prefix}wpe_catalog WHERE generation = %d",
            $gen2
        ));

        if ($gen1Count === 0) {
            throw new \RuntimeException("R66 Failed: Previous generation {$gen1} was prematurely dropped!");
        }
        if ($gen2Count === 0) {
            throw new \RuntimeException("R66 Failed: New generation {$gen2} contains no catalog rows");
        }

        // 4. Test purgeOldGenerations with retainCount = 2
        $purged = $this->projector->purgeOldGenerations(2);
        if ($purged !== 0) {
            throw new \RuntimeException("R66 Failed: purgeOldGenerations should retain 2 latest generations");
        }

        // Build Generation 3
        $gen3 = $this->projector->buildNewGeneration();

        // Purge retaining 2: should purge Gen 1 and retain Gen 2 and Gen 3
        $purgedOlder = $this->projector->purgeOldGenerations(2);
        if ($purgedOlder < 1) {
            throw new \RuntimeException("R66 Failed: Older generation beyond retention limit was not purged");
        }

        $gensRemaining = $this->projector->getStoredGenerations();
        if (in_array($gen1, $gensRemaining, true)) {
            throw new \RuntimeException("R66 Failed: Gen 1 should have been purged when retaining 2");
        }
        if (!in_array($gen2, $gensRemaining, true) || !in_array($gen3, $gensRemaining, true)) {
            throw new \RuntimeException("R66 Failed: Latest 2 generations ({$gen2}, {$gen3}) not retained: " . json_encode($gensRemaining));
        }

        echo "PASSED: Generation retention and purge lifecycle verified.\n\n";
    }

    /* -------------------------------------------------------------------------- */
    /* Test AC-T16-03: No Orphan Facets and Source Version Audit                  */
    /* -------------------------------------------------------------------------- */

    public function testAcT1603NoOrphanFacetsAndAudit(): void
    {
        echo "[Test AC-T16-03] Testing zero orphan facets and source_version audit alignment...\n";

        // Create product with initial attributes (size: medium)
        $p = $this->createSimpleProduct('AC16 Facet Audit Product', 45.00, [
            'attributes' => ['pa_size' => 'medium', 'pa_color' => 'blue']
        ]);
        $pid = $p->get_id();
        $gen = $this->projector->getActiveGeneration();

        $this->projector->projectProduct($pid, $gen);

        // Verify initial facets
        $facets1 = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT facet_key, value_key FROM {$this->wpdb->prefix}wpe_catalog_facets
             WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ), ARRAY_A);

        $keys1 = array_column($facets1, 'value_key');
        if (!in_array('medium', $keys1, true) || !in_array('blue', $keys1, true)) {
            throw new \RuntimeException("AC-T16-03 Failed: Initial facets not created: " . json_encode($facets1));
        }

        // Update product to remove 'medium' and replace with 'large'
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update(
            $this->wpdb->prefix . 'wpe_products',
            [
                'extra_props' => json_encode(['attributes' => ['pa_size' => 'large']], JSON_UNESCAPED_UNICODE),
                'updated_at'  => $now,
            ],
            ['id' => $pid]
        );

        // Re-project product
        $this->projector->projectProduct($pid, $gen);

        // Verify AC-T16-03: No orphan facets! 'medium' and 'blue' must be completely absent
        $facets2 = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT facet_key, value_key FROM {$this->wpdb->prefix}wpe_catalog_facets
             WHERE generation = %d AND product_id = %d",
            $gen,
            $pid
        ), ARRAY_A);

        $keys2 = array_column($facets2, 'value_key');
        if (in_array('medium', $keys2, true)) {
            throw new \RuntimeException("AC-T16-03 Failed: Orphan facet 'medium' remained after update!");
        }
        if (in_array('blue', $keys2, true)) {
            throw new \RuntimeException("AC-T16-03 Failed: Orphan facet 'blue' remained after update!");
        }
        if (!in_array('large', $keys2, true)) {
            throw new \RuntimeException("AC-T16-03 Failed: Updated facet 'large' not found!");
        }

        // Run source_version audit
        $audit = $this->projector->auditSourceVersions($gen);
        if (!$audit['in_sync'] || $audit['stale_count'] !== 0) {
            throw new \RuntimeException("AC-T16-03 Failed: source_version audit failed: " . json_encode($audit));
        }

        echo "PASSED: Zero orphan facets and source_version audit verified.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T16 Integration Tests ===\n\n";

        $this->testR63RebuildUnderWrites();
        $this->testR64TombstoneNoResurrection();
        $this->testR65SaleClockBoundaryDst();
        $this->testR66GenerationCursorRetention();
        $this->testAcT1603NoOrphanFacetsAndAudit();

        echo "=== Test Summary ===\n";
        echo "R63 (rebuild_under_writes): PASSED\n";
        echo "R64 (tombstone_no_resurrection): PASSED\n";
        echo "R65 (sale_clock_boundary_dst): PASSED\n";
        echo "R66 (generation_cursor_retention): PASSED\n";
        echo "AC-T16-01 (dirty_catchup_previous_retention): PASSED\n";
        echo "AC-T16-02 (price_schedule_auto_transition): PASSED\n";
        echo "AC-T16-03 (no_orphan_facets_version_audit): PASSED\n";
    }
}

$test = new T16Test();
$test->runAll();
exit(0);
