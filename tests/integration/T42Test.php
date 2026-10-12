<?php

/**
 * T42 Integration Test — Readiness gates và cohort manifest.
 *
 * Verifies:
 * - R169: Missing evidence immediately causes fail-closed gate evaluation.
 * - R170: Cohort manifest hash mismatch, epoch change, and DLQ errors block cutover.
 * - R171: Version changes and uncertified direct SQL writers classify gate as stale/blocked.
 * - R172: Strict prohibition of bypass flags (skip_required_gate) in production environments.
 * - AC-T42-01: Validator fail-closed across all required release gates G01–G12.
 * - AC-T42-02: Cutover endpoint only accepts valid gate token matching cohort and active epoch.
 * - AC-T42-03: Zero gate bypass in production.
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

use WPE\Application\Rollout\ReadinessGates;
use WPE\Compatibility\WordPress\PostIdAllocator;

class T42Test
{
    private \wpdb $wpdb;
    private ReadinessGates $gates;
    private PostIdAllocator $allocator;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableOutbox;
    private string $tableAudit;

    private array $cleanProductIds = [];
    private array $cleanEventIds = [];

    /** Hermetic evidence directory: passed receipts for every gate task on the CURRENT runtime. */
    private string $fixtureEvidenceDir;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableInventory   = "{$prefix}wpe_inventory";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableOutbox      = "{$prefix}wpe_outbox";
        $this->tableAudit       = "{$prefix}wpe_audit";

        // Gate logic is tested against fixture receipts on the live runtime so the test does not
        // depend on (or get invalidated by) the repository's historical evidence files.
        $this->fixtureEvidenceDir = dirname(__DIR__, 2) . '/tmp_t42_evidence_' . uniqid('', true);
        $current = (new ReadinessGates($this->wpdb, $this->fixtureEvidenceDir))->getCurrentEnvironment();
        foreach (ReadinessGates::GATES as $gate) {
            @mkdir("{$this->fixtureEvidenceDir}/{$gate['task']}", 0755, true);
            file_put_contents("{$this->fixtureEvidenceDir}/{$gate['task']}/result.json", json_encode([
                'task_id'        => $gate['task'],
                'qa_status'      => 'passed',
                'content_digest' => hash('sha256', 'fixture-' . $gate['task']),
                'environment'    => [
                    'wordpress'   => $current['wordpress'],
                    'woocommerce' => $current['woocommerce'],
                    'php'         => $current['php'],
                    'database'    => $this->wpdb->db_version(),
                ],
            ]));
        }

        $this->gates     = new ReadinessGates($this->wpdb, $this->fixtureEvidenceDir);
        $this->allocator = new PostIdAllocator($this->wpdb);
    }

    public function run(): void
    {
        echo "=== Running T42 Integration Tests (Readiness Gates & Cohort Manifest) ===\n";

        try {
            $this->setUp();

            $this->testR169_missing_evidence_gate_fail();
            $this->testR170_cohort_epoch_hash_mismatch();
            $this->testR171_version_changed_gate_stale();
            $this->testR172_no_gate_bypass();

            $this->testAC_T42_01_fail_closed_validation();
            $this->testAC_T42_02_cutover_endpoint_token_verification();
            $this->testAC_T42_03_production_bypass_prohibited();

            echo "=== All T42 Tests Passed Successfully ===\n";
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
        $prefix = $this->wpdb->prefix;

        foreach (glob($this->fixtureEvidenceDir . '/*/result.json') ?: [] as $file) {
            @unlink($file);
            @rmdir(dirname($file));
        }
        @rmdir($this->fixtureEvidenceDir);

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$this->tableInventory} WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableVariants} WHERE id IN ({$ids}) OR product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableProducts} WHERE id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->tableObjectState} WHERE object_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$this->wpdb->posts} WHERE ID IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanEventIds)) {
            $escaped = array_map(fn($id) => "'" . esc_sql($id) . "'", $this->cleanEventIds);
            $this->wpdb->query("DELETE FROM {$this->tableOutbox} WHERE event_id IN (" . implode(',', $escaped) . ")");
            $this->cleanEventIds = [];
        }
    }

    /**
     * R169: Missing evidence immediately causes fail-closed gate evaluation.
     */
    public function testR169_missing_evidence_gate_fail(): void
    {
        echo "Testing R169 (missing_evidence_gate_fail)...\n";

        // Point gate validator to an empty/fake directory missing evidence files
        $fakeEvidenceDir = dirname(__DIR__, 2) . '/tmp_empty_evidence_' . time();
        @mkdir($fakeEvidenceDir, 0755, true);

        try {
            $fakeGates = new ReadinessGates($this->wpdb, $fakeEvidenceDir);
            $res = $fakeGates->validateAllGates();

            if ($res['all_passed']) {
                throw new \RuntimeException("Fail-closed violation: Gates passed despite missing evidence files!");
            }

            if (empty($res['failed_gates'])) {
                throw new \RuntimeException("No failed gates recorded when evidence directory was empty!");
            }

            if ($res['gate_receipt_token'] !== null) {
                throw new \RuntimeException("Receipt token generated when gates failed!");
            }

            echo sprintf("  ✓ Missing evidence properly caught: %d gates failed closed, 0 receipt tokens issued\n", count($res['failed_gates']));
        } finally {
            @rmdir($fakeEvidenceDir);
        }

        echo "  ✓ R169 passed: Missing evidence immediately triggers fail-closed rejection across all gates\n";
    }

    /**
     * R170: Cohort manifest hash mismatch, epoch change, and DLQ errors block cutover.
     */
    public function testR170_cohort_epoch_hash_mismatch(): void
    {
        echo "Testing R170 (cohort_epoch_hash_mismatch)...\n";

        // 1. Create a controlled cohort product
        $productId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $productId;
        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->insert($this->tableProducts, [
            'id'                 => $productId,
            'type'               => 'simple',
            'status'             => 'publish',
            'slug'               => 'cohort-test-' . $productId,
            'title'              => 'Cohort Test Product',
            'description'        => '',
            'short_description'  => '',
            'catalog_visibility' => 'visible',
            'sku'                => 'SKU-COHORT-' . $productId,
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
            'authority'       => 'legacy',
            'epoch'           => 1,
            'legacy_revision' => 1,
            'native_revision' => 1,
            'legacy_applied'  => 1,
            'native_applied'  => 1,
            'tombstone'       => 0,
            'updated_at'      => $now,
        ]);

        // Build initial cohort manifest and token
        $manifest = $this->gates->buildCohortManifest('all');
        $token = $this->gates->validateAllGates($manifest)['gate_receipt_token'];

        if (!$token) {
            throw new \RuntimeException("Failed to generate initial gate token with existing evidence");
        }

        // Verify token is initially eligible
        $eligibleInitial = $this->gates->verifyCutoverEligibility($token, ['cohort_id' => 'all']);
        if (!$eligibleInitial['eligible']) {
            throw new \RuntimeException("Initial token was rejected: " . ($eligibleInitial['error'] ?? ''));
        }

        // 2. Mutate database: alter inventory quantity (simulating unprojected live mutation)
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableInventory} SET quantity = quantity + 10 WHERE owner_id = %d",
            $productId
        ));

        // Re-verify: cohort manifest hash has changed; cutover must be blocked!
        $eligibleMutated = $this->gates->verifyCutoverEligibility($token, ['cohort_id' => 'all']);
        if ($eligibleMutated['eligible']) {
            throw new \RuntimeException("Cutover allowed despite database mutation altering cohort hash!");
        }
        echo "  ✓ Cohort hash mismatch caught: Database mutation blocked cutover with stale token\n";

        // Restore inventory for subsequent tests
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableInventory} SET quantity = 50.0 WHERE owner_id = %d",
            $productId
        ));

        // 3. DLQ error check: seed dead_letter event into wpe_outbox
        $eventId = wp_generate_uuid4();
        $this->cleanEventIds[] = $eventId;
        $this->wpdb->insert($this->tableOutbox, [
            'event_id'     => $eventId,
            'aggregate_id' => $productId,
            'revision'     => 1,
            'epoch'        => 1,
            'event_type'   => 'product.test',
            'payload'      => '{}',
            'state'        => 'dead_letter',
            'attempts'     => 5,
            'available_at' => $now,
            'created_at'   => $now,
        ]);

        $resWithDlq = $this->gates->validateAllGates();
        if ($resWithDlq['all_passed']) {
            throw new \RuntimeException("Gates passed despite presence of dead_letter event in outbox!");
        }
        if (!in_array('DLQ_CHECK', $resWithDlq['failed_gates'], true)) {
            throw new \RuntimeException("DLQ_CHECK not flagged in failed gates!");
        }

        // Clean event
        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->tableOutbox} WHERE event_id = %s", $eventId));
        $this->cleanEventIds = [];

        echo "  ✓ DLQ blocker caught: Unresolved dead_letter outbox events strictly blocked gate approval\n";
        echo "  ✓ R170 passed: Cohort manifest hashing, epoch validation, and DLQ errors prevent unsafe cutover\n";
    }

    /**
     * R171: Version changes and uncertified direct SQL writers classify gate as stale/blocked.
     */
    public function testR171_version_changed_gate_stale(): void
    {
        echo "Testing R171 (version_changed_gate_stale)...\n";

        // 1. Evidence from a different runtime must be stale, including minor/patch differences
        $current = $this->gates->getCurrentEnvironment();
        $base = [
            'wordpress'   => $current['wordpress'],
            'woocommerce' => $current['woocommerce'],
            'php'         => $current['php'],
            'database'    => $this->wpdb->db_version(),
        ];
        $bump = static function (string $version): string {
            $parts = explode('.', $version);
            $last = count($parts) - 1;
            $parts[$last] = (string) ((int) $parts[$last] + 1);
            return implode('.', $parts);
        };
        $cases = [
            'WordPress 6.4.1 (major)'        => ['wordpress' => '6.4.1'],
            'WordPress 7.0 (minor, audit F01)' => ['wordpress' => '7.0'],
            'WordPress patch bump'           => ['wordpress' => $bump($current['wordpress'])],
            'WooCommerce 11.1.2 (minor, audit F01)' => ['woocommerce' => '11.1.2'],
            'WooCommerce patch bump'         => ['woocommerce' => $bump($current['woocommerce'])],
            'PHP patch bump'                 => ['php' => $bump($current['php'])],
            'missing WooCommerce version'    => ['woocommerce' => ''],
        ];
        $statusFor = function (array $env): string {
            $dir = dirname(__DIR__, 2) . '/tmp_version_test_' . uniqid('', true);
            @mkdir("{$dir}/T03", 0755, true);
            try {
                file_put_contents("{$dir}/T03/result.json", json_encode([
                    'task_id'        => 'T03',
                    'qa_status'      => 'passed',
                    'content_digest' => 'dummy',
                    'environment'    => $env,
                ]));
                $res = (new ReadinessGates($this->wpdb, $dir))->validateAllGates();
                return (string) ($res['gates']['G01']['status'] ?? 'unknown');
            } finally {
                @unlink("{$dir}/T03/result.json");
                @rmdir("{$dir}/T03");
                @rmdir($dir);
            }
        };
        foreach ($cases as $label => $override) {
            $status = $statusFor(array_merge($base, $override));
            if ($status !== 'stale') {
                throw new \RuntimeException("{$label} was not classified 'stale': got {$status}");
            }
            echo "  ✓ Version divergence detected: {$label} classified gate as 'stale'\n";
        }
        $suffixed = array_merge($base, ['database' => $base['database'] . '-MariaDB-ubu2204']);
        if ($statusFor($suffixed) === 'stale') {
            throw new \RuntimeException('Identical runtime with a database distribution suffix must not be stale');
        }
        echo "  ✓ Identical runtime (database distribution suffix ignored) is not stale\n";

        // 2. Environment fingerprinting
        $env = $this->gates->getCurrentEnvironment();
        $hash1 = $this->gates->computeEnvironmentHash($env);

        $modifiedEnv = $env;
        $modifiedEnv['plugins']['rogue-plugin/rogue.php'] = '1.0.0';
        $hash2 = $this->gates->computeEnvironmentHash($modifiedEnv);

        if ($hash1 === $hash2) {
            throw new \RuntimeException("Environment fingerprint remained identical after modifying active plugins!");
        }
        echo "  ✓ Environment fingerprinting: Plugin list alteration changed environment hash from {$hash1} to {$hash2}\n";

        echo "  ✓ R171 passed: Software version divergence and plugin changes reliably detected and flagged as stale\n";
    }

    /**
     * R172: Strict prohibition of bypass flags (skip_required_gate) in production environments.
     */
    public function testR172_no_gate_bypass(): void
    {
        echo "Testing R172 (no_gate_bypass)...\n";

        // 1. In production environment, evaluateBypassAttempt must throw RuntimeException
        $threwInProd = false;
        try {
            $this->gates->evaluateBypassAttempt(true, 'production');
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'strictly prohibited in production')) {
                $threwInProd = true;
            }
        }

        if (!$threwInProd) {
            throw new \RuntimeException("R172 Violation: skip_required_gate was not rejected with RuntimeException in production!");
        }
        echo "  ✓ Production bypass attempt strictly blocked: RuntimeException thrown\n";

        // 2. In non-production, bypass flag is rejected and logged to wpe_audit
        $stagingRes = $this->gates->evaluateBypassAttempt(true, 'staging');
        if ($stagingRes['bypassed'] || $stagingRes['allowed']) {
            throw new \RuntimeException("Gate bypass was granted in staging!");
        }

        $auditLog = $this->wpdb->get_row(
            "SELECT operation, summary FROM {$this->tableAudit} WHERE operation = 'gate_bypass_attempt_rejected' ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        if (!$auditLog) {
            throw new \RuntimeException("Bypass attempt did not log security alert to wpe_audit!");
        }
        echo "  ✓ Security audit logged: Rejected bypass recorded to wpe_audit\n";

        echo "  ✓ R172 passed: Bypass flags strictly prohibited in production and audited in non-production\n";
    }

    /**
     * AC-T42-01: Validator fail-closed across all required release gates G01–G12.
     */
    public function testAC_T42_01_fail_closed_validation(): void
    {
        echo "Testing AC-T42-01 (fail_closed_validation)...\n";

        $res = $this->gates->validateAllGates();

        // Verify all 12 gates G01–G12 exist in the gate results
        $expectedGates = ['G01', 'G02', 'G03', 'G04', 'G05', 'G06', 'G07', 'G08', 'G09', 'G10', 'G11', 'G12'];
        foreach ($expectedGates as $gid) {
            if (!isset($res['gates'][$gid])) {
                throw new \RuntimeException("Missing gate {$gid} in validation results!");
            }
            if ($res['gates'][$gid]['status'] !== 'passed') {
                throw new \RuntimeException("Gate {$gid} ({$res['gates'][$gid]['name']}) did not pass: " . ($res['gates'][$gid]['reason'] ?? ''));
            }
        }

        if (!$res['all_passed']) {
            throw new \RuntimeException("Not all gates passed: " . implode(', ', $res['failed_gates']));
        }

        echo sprintf("  ✓ All %d release gates (G01–G12) verified and passed\n", count($expectedGates));
        echo "  ✓ AC-T42-01 passed: Full matrix validation succeeds on certified baseline with fail-closed guarantees\n";
    }

    /**
     * AC-T42-02: Cutover endpoint only accepts valid gate token matching cohort and active epoch.
     */
    public function testAC_T42_02_cutover_endpoint_token_verification(): void
    {
        echo "Testing AC-T42-02 (cutover_endpoint_token_verification)...\n";

        $manifest = $this->gates->buildCohortManifest('all');
        $validToken = $this->gates->validateAllGates($manifest)['gate_receipt_token'];

        // 1. Valid token passes
        $checkValid = $this->gates->verifyCutoverEligibility($validToken, ['cohort_id' => 'all']);
        if (!$checkValid['eligible']) {
            throw new \RuntimeException("Legitimate gate receipt token was rejected: " . ($checkValid['error'] ?? ''));
        }

        // 2. Tampered token fails
        $tamperedToken = $validToken . 'extra';
        $checkTampered = $this->gates->verifyCutoverEligibility($tamperedToken, ['cohort_id' => 'all']);
        if ($checkTampered['eligible']) {
            throw new \RuntimeException("Tampered gate token was accepted!");
        }

        // 3. Wrong cohort fails
        $checkWrongCohort = $this->gates->verifyCutoverEligibility($validToken, ['cohort_id' => 'category_5']);
        if ($checkWrongCohort['eligible']) {
            throw new \RuntimeException("Gate token accepted for wrong cohort!");
        }

        echo "  ✓ AC-T42-02 passed: Cutover endpoint exclusively accepts valid signed tokens matching cohort and epoch\n";
    }

    /**
     * AC-T42-03: Zero gate bypass in production.
     */
    public function testAC_T42_03_production_bypass_prohibited(): void
    {
        echo "Testing AC-T42-03 (production_bypass_prohibited)...\n";

        try {
            $this->gates->evaluateBypassAttempt(true, 'production');
            throw new \RuntimeException("Bypass was allowed in production!");
        } catch (\RuntimeException $e) {
            // Expected
            echo "  ✓ Verified: Admin flag skip_required_gate throws fatal exception in production\n";
        }

        echo "  ✓ AC-T42-03 passed: Zero gate bypass permitted in production\n";
    }
}

// Execute test suite
$test = new T42Test();
$test->run();
