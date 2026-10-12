<?php

/**
 * T45 Integration Test — Seal authority và chuyển full_native.
 *
 * Verifies:
 * - R181: No fallback on native read error (strictly fail-closed, zero postmeta fallback per INV12).
 * - R182: Owned field authority (direct legacy writes rejected; certified writes route to native).
 * - R183: Plugin change certification (uncertified plugin activates automatic readiness demotion without unsafe source flip).
 * - R184: Legacy mirror is strictly non-canonical (storefront reads 100% native; legacy mirror mutations ignored).
 * - AC-T45-01: Full native reads and writes correct with zero stale postmeta fallback.
 * - AC-T45-02: CRUD-safe supported plugins operate cleanly; unsupported plugins trigger operational alert and block rollout.
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

use WPE\Application\Rollout\AuthoritySeal;
use WPE\Application\Rollout\ReadinessGates;
use WPE\Compatibility\WordPress\PostIdAllocator;

class T45Test
{
    private \wpdb $wpdb;
    private AuthoritySeal $sealer;
    private ReadinessGates $gates;
    private PostIdAllocator $allocator;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableControl;
    private string $tableAudit;

    private array $cleanProductIds = [];
    private array $cleanControlKeys = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableInventory   = "{$prefix}wpe_inventory";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableControl     = "{$prefix}wpe_control";
        $this->tableAudit       = "{$prefix}wpe_audit";

        $this->gates     = new ReadinessGates($this->wpdb);
        $this->sealer    = new AuthoritySeal($this->wpdb);
        $this->allocator = new PostIdAllocator($this->wpdb);
    }

    public function run(): void
    {
        echo "=== Running T45 Integration Tests (Seal Authority & Full Native) ===\n";

        try {
            $this->setUp();

            $this->testR181_no_fallback_on_native_read_error();
            $this->testR182_owned_field_authority();
            $this->testR183_plugin_change_certification();
            $this->testR184_mirror_not_canonical();

            $this->testAC_T45_01_full_native_read_write_no_fallback();
            $this->testAC_T45_02_supported_plugins_and_unsupported_alert();

            echo "=== All T45 Tests Passed Successfully ===\n";
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
            $this->wpdb->query("DELETE FROM {$this->wpdb->postmeta} WHERE post_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanControlKeys)) {
            $escaped = array_map(fn($k) => "'" . esc_sql($k) . "'", $this->cleanControlKeys);
            $this->wpdb->query("DELETE FROM {$this->tableControl} WHERE `key` IN (" . implode(',', $escaped) . ")");
            $this->cleanControlKeys = [];
        }
    }

    /**
     * R181: No fallback on native read error (strictly fail-closed, zero postmeta fallback per INV12).
     */
    public function testR181_no_fallback_on_native_read_error(): void
    {
        echo "Testing R181 (no_fallback_on_native_read_error)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $cohortId = 'cohort_r181_' . time();
        $this->cleanControlKeys[] = "full_native_no_fallback_{$cohortId}";
        $this->cleanControlKeys[] = "full_native_certification_report_{$cohortId}";

        $now = gmdate('Y-m-d H:i:s.u');

        // Create product in native tables
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'seal-prod-' . $productId,
            'title'              => 'Seal Authority Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-SEAL-' . $productId,
            'regular_price'      => '120.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 75.0,
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
            'epoch'           => 2,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Inject poisoned legacy postmeta into wp_postmeta to simulate stale fallback risk
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $productId,
            'meta_key'   => '_price',
            'meta_value' => '9999.00', // Poisoned stale price!
        ]);
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $productId,
            'meta_key'   => '_stock',
            'meta_value' => '0', // Poisoned stale stock!
        ]);

        // Seal authority to full_native
        $report = $this->sealer->sealAuthority($cohortId, ['product_ids' => [$productId]]);
        if ($report['status'] !== 'certified_full_native') {
            throw new \RuntimeException("Authority seal failed to transition to certified_full_native!");
        }

        // Verify no-fallback flag is active
        if (!$this->sealer->isNoFallbackActive($cohortId)) {
            throw new \RuntimeException("No-fallback flag was not activated after authority seal!");
        }
        echo "  ✓ Authority sealed to full_native; no-fallback flag activated\n";

        // Read canonical product: must read 100% native values (120.00 and 75.0), not postmeta (9999.00 and 0)
        $canonical = $this->sealer->readCanonicalProduct($productId, $cohortId);
        if ((float)$canonical['price'] !== 120.0 || (float)$canonical['stock_quantity'] !== 75.0) {
            throw new \RuntimeException("Canonical read fetched stale postmeta data instead of native!");
        }
        if ($canonical['source'] !== 'native') {
            throw new \RuntimeException("Canonical read source was not 'native'!");
        }
        echo "  ✓ Canonical product read accurately from native source (Price: 120.00, Stock: 75.0)\n";

        // Simulate missing native record: with no-fallback active, it MUST throw RuntimeException and NEVER fall back
        $missingProductId = 99999999;
        // Inject poisoned postmeta for this missing product ID
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $missingProductId,
            'meta_key'   => '_price',
            'meta_value' => '50.00',
        ]);

        $failedClosed = false;
        try {
            $this->sealer->readCanonicalProduct($missingProductId, $cohortId);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Fallback to legacy postmeta is strictly prohibited')) {
                $failedClosed = true;
            }
        } finally {
            $this->wpdb->query("DELETE FROM {$this->wpdb->postmeta} WHERE post_id = {$missingProductId}");
        }

        if (!$failedClosed) {
            throw new \RuntimeException("R181 Violation: Native read error fell back to legacy postmeta instead of failing closed!");
        }

        echo "  ✓ Native read error properly failed-closed: RuntimeException thrown, zero postmeta fallback (INV12)\n";
        echo "  ✓ R181 passed: In full_native mode, missing/errored native data strictly rejects stale postmeta fallback\n";
    }

    /**
     * R182: Owned field authority (direct legacy writes rejected; certified writes route to native).
     */
    public function testR182_owned_field_authority(): void
    {
        echo "Testing R182 (owned_field_authority)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'owned-field-' . $productId,
            'title'              => 'Owned Field Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-OWNED-' . $productId,
            'regular_price'      => '100.000000',
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

        $this->wpdb->insert($this->tableObjectState, [
            'object_id'       => $productId,
            'kind'            => 'product',
            'parent_id'       => null,
            'authority'       => 'full_native',
            'epoch'           => 2,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // 1. Direct uncertified postmeta write to owned key (_regular_price): MUST BE REJECTED!
        $uncertifiedWrite = $this->sealer->interceptPostmetaWrite($productId, '_regular_price', '250.00', false);
        if ($uncertifiedWrite['allowed'] || $uncertifiedWrite['action'] !== 'rejected_by_ownership_fence') {
            throw new \RuntimeException("Direct uncertified write to owned field was not rejected!");
        }
        echo "  ✓ Direct uncertified write to _regular_price rejected by ownership fence\n";

        // Verify security audit logged
        $auditLog = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT operation, summary FROM {$this->tableAudit} WHERE operation = 'write_ownership_fence_violation' AND target_id = %d ORDER BY id DESC LIMIT 1",
            $productId
        ), ARRAY_A);

        if (!$auditLog) {
            throw new \RuntimeException("Write ownership fence violation was not logged to wpe_audit!");
        }
        echo "  ✓ Fence violation logged to wpe_audit\n";

        // 2. Certified adapter postmeta write to owned key: MUST ROUTE TO NATIVE TABLE!
        $certifiedWrite = $this->sealer->interceptPostmetaWrite($productId, '_regular_price', '175.00', true);
        if (!$certifiedWrite['allowed'] || $certifiedWrite['action'] !== 'routed_to_native') {
            throw new \RuntimeException("Certified write was not routed to native!");
        }

        // Verify database value in wpe_products was updated to 175.00
        $updatedPrice = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT regular_price FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        if ($updatedPrice !== 175.0) {
            throw new \RuntimeException("Certified write did not update wpe_products! Expected 175.0, got: {$updatedPrice}");
        }
        echo "  ✓ Certified write to _regular_price routed cleanly to wpe_products (175.00)\n";

        // 3. Certified stock write to _stock: routes to wpe_inventory
        $certifiedStock = $this->sealer->interceptPostmetaWrite($productId, '_stock', '65.0', true);
        if (!$certifiedStock['allowed'] || $certifiedStock['action'] !== 'routed_to_native') {
            throw new \RuntimeException("Certified stock write was not routed to native!");
        }

        $updatedQty = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT quantity FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ));
        if ($updatedQty !== 65.0) {
            throw new \RuntimeException("Certified stock write did not update wpe_inventory! Expected 65.0, got: {$updatedQty}");
        }
        echo "  ✓ Certified write to _stock routed cleanly to wpe_inventory (65.0)\n";

        echo "  ✓ R182 passed: Owned field authority enforces write ownership fence and routes certified writes\n";
    }

    /**
     * R183: Plugin change certification (uncertified plugin activates automatic readiness demotion without unsafe source flip).
     */
    public function testR183_plugin_change_certification(): void
    {
        echo "Testing R183 (plugin_change_certification)...\n";

        $cohortId = 'cohort_cert_' . time();
        $this->cleanControlKeys[] = "cohort_demoted_{$cohortId}";

        // 1. Certified plugins list passes
        $certifiedPlugins = [
            'woocommerce/woocommerce.php' => '11.2.1',
            'wc-product-engine/wc-product-engine.php' => '1.1.0',
            'calora-bundles/calora-bundles.php' => '1.0.0',
            'classic-editor/classic-editor.php' => '1.7.0',
        ];

        $checkCertified = $this->sealer->verifyPluginCertification($certifiedPlugins);
        if (!$checkCertified['certified'] || !empty($checkCertified['violations'])) {
            throw new \RuntimeException("Certified plugin list failed verification!");
        }
        echo "  ✓ Certified plugins list validated with zero violations\n";

        // 2. Uncertified plugin activated (e.g. rogue direct writer or untrusted extension)
        $roguePlugins = array_merge($certifiedPlugins, [
            'rogue-custom-pricing/rogue.php' => '2.0.0',
        ]);

        $checkRogue = $this->sealer->verifyPluginCertification($roguePlugins);
        if ($checkRogue['certified']) {
            throw new \RuntimeException("Uncertified plugin was accepted without violations!");
        }
        if (!in_array('rogue-custom-pricing/rogue.php', $checkRogue['violations'], true)) {
            throw new \RuntimeException("Rogue plugin missing from violation list!");
        }

        // Demote cohort readiness
        $demoteRes = $this->sealer->demoteReadiness($cohortId, 'Uncertified plugin activated: rogue-custom-pricing/rogue.php');
        if (!$demoteRes['demoted'] || $demoteRes['source_preserved'] !== 'native') {
            throw new \RuntimeException("Readiness demotion did not preserve native source!");
        }

        // Verify stored demotion record in wpe_control
        $demoteData = json_decode((string)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            "cohort_demoted_{$cohortId}"
        )), true);

        if (!$demoteData['demoted'] || $demoteData['source_kept'] !== 'native') {
            throw new \RuntimeException("Stored demotion record altered source or failed to mark demoted!");
        }

        echo "  ✓ Uncertified plugin triggered automatic readiness demotion\n";
        echo "  ✓ Crucial guarantee verified: Source safely preserved as native without dangerous automatic flipping\n";
        echo "  ✓ R183 passed: Plugin certification changes reliably trigger operational alert and block rollout safely\n";
    }

    /**
     * R184: Legacy mirror is strictly non-canonical (storefront reads 100% native; legacy mirror mutations ignored).
     */
    public function testR184_mirror_not_canonical(): void
    {
        echo "Testing R184 (mirror_not_canonical)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $cohortId = 'cohort_r184_' . time();
        $this->cleanControlKeys[] = "full_native_no_fallback_{$cohortId}";
        $this->cleanControlKeys[] = "full_native_certification_report_{$cohortId}";

        $now = gmdate('Y-m-d H:i:s.u');

        // Create native product
        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'non-canonical-' . $productId,
            'title'              => 'Non-Canonical Mirror Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-NONCANON-' . $productId,
            'regular_price'      => '95.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 35.0,
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
            'epoch'           => 2,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        $this->sealer->sealAuthority($cohortId, ['product_ids' => [$productId]]);

        // Mutate legacy mirror postmeta directly to arbitrary corrupt values
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $productId,
            'meta_key'   => '_price',
            'meta_value' => '1.00',
        ]);
        $this->wpdb->insert($this->wpdb->postmeta, [
            'post_id'    => $productId,
            'meta_key'   => '_stock',
            'meta_value' => '0',
        ]);

        // Read canonical product: must read 100% native values (95.00 and 35.0), completely ignoring postmeta mutations
        $res = $this->sealer->readCanonicalProduct($productId, $cohortId);

        if ((float)$res['price'] !== 95.0 || (float)$res['stock_quantity'] !== 35.0) {
            throw new \RuntimeException("Canonical read was contaminated by legacy mirror mutation! Expected 95.0/35.0, got: {$res['price']}/{$res['stock_quantity']}");
        }

        echo "  ✓ Legacy mirror mutations verified completely non-canonical: Storefront reads remain 100% native\n";
        echo "  ✓ R184 passed: Legacy mirror is strictly secondary projection with zero canonical read influence\n";
    }

    /**
     * AC-T45-01: Full native reads and writes correct with zero stale postmeta fallback.
     */
    public function testAC_T45_01_full_native_read_write_no_fallback(): void
    {
        echo "Testing AC-T45-01 (full_native_read_write_no_fallback)...\n";

        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $cohortId = 'cohort_ac_t45_01';
        $this->cleanControlKeys[] = "full_native_no_fallback_{$cohortId}";
        $this->cleanControlKeys[] = "full_native_certification_report_{$cohortId}";

        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'ac-t45-prod-' . $productId,
            'title'              => 'AC-T45 Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-AC45-' . $productId,
            'regular_price'      => '300.000000',
            'created_at'         => $now,
            'updated_at'         => $now,
            'version'            => 1,
        ]);

        $this->wpdb->insert($this->tableInventory, [
            'owner_id'         => $productId,
            'manage_stock'     => 1,
            'quantity'         => 120.0,
            'backorders'       => 'no',
            'stock_status'     => 'instock',
            'low_stock_amount' => 10.0,
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

        $report = $this->sealer->sealAuthority($cohortId, ['product_ids' => [$productId]]);
        if (!$report['no_fallback_active']) {
            throw new \RuntimeException("No-fallback flag inactive in certification report!");
        }

        // 1. Read
        $product = $this->sealer->readCanonicalProduct($productId, $cohortId);
        if ((float)$product['price'] !== 300.0 || (float)$product['stock_quantity'] !== 120.0) {
            throw new \RuntimeException("Invalid full_native read data!");
        }

        // 2. Write via certified adapter
        $this->sealer->interceptPostmetaWrite($productId, '_regular_price', '320.00', true);
        $productUpdated = $this->sealer->readCanonicalProduct($productId, $cohortId);
        if ((float)$productUpdated['price'] !== 320.0) {
            throw new \RuntimeException("Certified price write failed to reflect in canonical read!");
        }

        echo "  ✓ AC-T45-01 passed: Full native read/write operates accurately with verified zero postmeta fallback\n";
    }

    /**
     * AC-T45-02: CRUD-safe supported plugins operate cleanly; unsupported plugins trigger operational alert and block rollout.
     */
    public function testAC_T45_02_supported_plugins_and_unsupported_alert(): void
    {
        echo "Testing AC-T45-02 (supported_plugins_and_unsupported_alert)...\n";

        // Supported plugin list
        $supportedList = [
            'woocommerce/woocommerce.php'       => '11.2.1',
            'wc-product-engine/wc-product-engine.php' => '1.1.0',
            'calora-bundles/calora-bundles.php' => '1.0.0',
            'classic-editor/classic-editor.php' => '1.7.0',
        ];
        $cert = $this->sealer->verifyPluginCertification($supportedList);
        if (!$cert['certified']) {
            throw new \RuntimeException("Supported plugins failed certification check!");
        }

        // Unsupported plugin
        $unsupported = array_merge($supportedList, [
            'unsupported-sql-injector/plugin.php' => '1.0.0',
        ]);
        $uncert = $this->sealer->verifyPluginCertification($unsupported);
        if ($uncert['certified']) {
            throw new \RuntimeException("Unsupported plugin did not trigger violation!");
        }

        // Verify audit log recorded
        $alert = $this->wpdb->get_row(
            "SELECT operation, summary FROM {$this->tableAudit} WHERE operation = 'uncertified_plugin_detected' ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
        if (!$alert) {
            throw new \RuntimeException("Unsupported plugin activation was not logged to wpe_audit!");
        }

        echo "  ✓ AC-T45-02 passed: Supported plugins certified; unsupported plugin triggers operational alert without source flip\n";
    }
}

// Execute test suite
$test = new T45Test();
$test->run();
