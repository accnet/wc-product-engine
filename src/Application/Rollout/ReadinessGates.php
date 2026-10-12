<?php

namespace WPE\Application\Rollout;

use RuntimeException;
use InvalidArgumentException;

/**
 * ReadinessGates — Gate registry, cohort manifest compiler, and pre-cutover validator.
 *
 * Implements requirements:
 * - R169: Fail-closed validation when required evidence or artifacts are missing.
 * - R170: Cohort manifest hashing, epoch matching, and gap rejection.
 * - R171: Automatic staleness detection when software versions or active plugins change.
 * - R172: Strict prevention of bypass flags (skip_required_gate) in production environments.
 * - AC-T42-01: Comprehensive fail-closed gate evaluation across G01–G12 matrix.
 * - AC-T42-02: Cutover endpoint strictly validates gate receipt token and cohort hash.
 * - AC-T42-03: Zero gate bypass in production.
 */
class ReadinessGates
{
    public const GATES = [
        'G01' => ['name' => 'Contracts',            'task' => 'T03', 'desc' => 'OpenAPI, event, DB, and CSV schema validation'],
        'G02' => ['name' => 'Data Parity',          'task' => 'T38', 'desc' => 'Shadow diff gap detection and full reconciliation'],
        'G03' => ['name' => 'CRUD & Stores',        'task' => 'T10', 'desc' => 'Woo DataStore native CRUD and lifecycle compatibility'],
        'G04' => ['name' => 'Catalog Query',        'task' => 'T17', 'desc' => 'Fast compiler, keyset pagination, and coherent facets'],
        'G05' => ['name' => 'Inventory Lifecycle',   'task' => 'T12', 'desc' => 'Stock owner, atomic reservations, and zero oversell'],
        'G06' => ['name' => 'Media & Renditions',   'task' => 'T22', 'desc' => 'Asset service, variation media, and real attachments'],
        'G07' => ['name' => 'CSV Interchange',      'task' => 'T36', 'desc' => 'Cross-format equivalence and golden dialect exports'],
        'G08' => ['name' => 'Rollback Readiness',   'task' => 'T40', 'desc' => 'Current data projection and deactivation safety'],
        'G09' => ['name' => 'Scale Performance',    'task' => 'T47', 'desc' => 'Measured p95 query budgets and bounded memory'],
        'G10' => ['name' => 'Native Independence',  'task' => 'T44', 'desc' => 'Zero reliance on legacy postmeta owned properties'],
        'G11' => ['name' => 'Security & Integrity', 'task' => 'T48', 'desc' => 'Auth, SQLi, SSRF defenses and WordPress core checksums'],
        'G12' => ['name' => 'Operations Health',    'task' => 'T41', 'desc' => 'DLQ, job lease recovery, and health monitoring'],
    ];

    private \wpdb $wpdb;
    private string $evidenceBaseDir;
    private string $signingKey;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableJobs;
    private string $tableOutbox;
    private string $tableAudit;

    public function __construct(?\wpdb $wpdb = null, ?string $evidenceBaseDir = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->evidenceBaseDir = $evidenceBaseDir ?? dirname(__DIR__, 3) . '/docs/evidence';

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableInventory   = "{$prefix}wpe_inventory";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableJobs        = "{$prefix}wpe_jobs";
        $this->tableOutbox      = "{$prefix}wpe_outbox";
        $this->tableAudit       = "{$prefix}wpe_audit";

        $this->signingKey = defined('AUTH_KEY') ? AUTH_KEY : 'wpe_readiness_gate_hmac_secret_2026';
    }

    /**
     * Captures current runtime environment for version staleness tracking (R171).
     */
    public function getCurrentEnvironment(): array
    {
        $plugins = [];
        if (function_exists('get_plugins')) {
            $allPlugins = get_plugins();
            $activeList = (array)get_option('active_plugins', []);
            foreach ($activeList as $pluginPath) {
                if (isset($allPlugins[$pluginPath])) {
                    $plugins[$pluginPath] = $allPlugins[$pluginPath]['Version'] ?? 'unknown';
                }
            }
        }

        return [
            'wordpress'   => get_bloginfo('version'),
            'woocommerce' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
            'php'         => PHP_VERSION,
            'database'    => $this->wpdb->db_version(),
            'plugins'     => $plugins,
        ];
    }

    /**
     * Returns a reason when evidence was produced on a different runtime, otherwise null (R171).
     *
     * Compares complete version strings for WordPress, WooCommerce and PHP, and the numeric
     * database version (the evidence may carry a distribution suffix such as "-MariaDB-ubu2204").
     * Evidence without a recorded version is treated as stale, never as a pass.
     *
     * @param array<string,mixed> $evidenceEnv
     * @param array<string,mixed> $currentEnv
     */
    private function detectVersionStaleness(array $evidenceEnv, array $currentEnv): ?string
    {
        $labels = ['wordpress' => 'WordPress', 'woocommerce' => 'WooCommerce', 'php' => 'PHP', 'database' => 'Database'];
        foreach ($labels as $key => $label) {
            $recorded = isset($evidenceEnv[$key]) ? trim((string) $evidenceEnv[$key]) : '';
            $current = isset($currentEnv[$key]) ? trim((string) $currentEnv[$key]) : '';
            if ($recorded === '') {
                return "Evidence does not record the {$label} version";
            }
            if ($key === 'database') {
                $recorded = preg_match('/^\d+(?:\.\d+)*/', $recorded, $a) ? $a[0] : $recorded;
                $current = preg_match('/^\d+(?:\.\d+)*/', $current, $b) ? $b[0] : $current;
            }
            if ($recorded !== $current) {
                return "{$label} version changed from {$recorded} to {$current}";
            }
        }
        return null;
    }

    /**
     * Computes deterministic fingerprint hash of current environment (R171).
     */
    public function computeEnvironmentHash(array $env): string
    {
        $payload = [
            'wp' => $env['wordpress'] ?? '',
            'wc' => $env['woocommerce'] ?? '',
            'php' => explode('-', $env['php'] ?? '')[0],
            'db' => $env['database'] ?? '',
            'plugins' => $env['plugins'] ?? [],
        ];
        return hash('sha256', (string)wp_json_encode($payload));
    }

    /**
     * Builds and hashes a deterministic cohort manifest (R170).
     */
    public function buildCohortManifest(string $cohortId = 'all', array $options = []): array
    {
        $whereSql = "1=1";
        if ($cohortId !== 'all') {
            $catId = (int)($options['category_id'] ?? 0);
            if ($catId > 0) {
                $whereSql = "id IN (SELECT product_id FROM {$this->wpdb->prefix}wpe_product_terms WHERE term_taxonomy_id = {$catId})";
            }
        }

        $products = $this->wpdb->get_results(
            "SELECT id, version FROM {$this->tableProducts} WHERE {$whereSql} ORDER BY id ASC",
            ARRAY_A
        );

        $productIds = array_map(fn($r) => (int)$r['id'], $products);
        $totalProducts = count($productIds);

        $variantCount = 0;
        $totalStock = 0.0;
        $currentEpoch = 1;

        if ($totalProducts > 0) {
            $inIds = implode(',', $productIds);
            $variantCount = (int)$this->wpdb->get_var(
                "SELECT count(*) FROM {$this->tableVariants} WHERE product_id IN ({$inIds})"
            );

            $totalStock = (float)$this->wpdb->get_var(
                "SELECT COALESCE(SUM(quantity), 0) FROM {$this->tableInventory} WHERE owner_id IN ({$inIds})"
            );

            $maxEpoch = (int)$this->wpdb->get_var(
                "SELECT COALESCE(MAX(epoch), 1) FROM {$this->tableObjectState} WHERE object_id IN ({$inIds})"
            );
            if ($maxEpoch > 0) {
                $currentEpoch = $maxEpoch;
            }
        }

        $manifestData = [
            'cohort_id'       => $cohortId,
            'epoch'           => $currentEpoch,
            'total_products'  => $totalProducts,
            'total_variants'  => $variantCount,
            'inventory_sum'   => sprintf('%.4f', $totalStock),
            'product_ids_rev' => array_map(fn($r) => ['id' => (int)$r['id'], 'ver' => (int)$r['version']], $products),
        ];

        $cohortHash = hash('sha256', (string)wp_json_encode($manifestData));

        return [
            'cohort_id'       => $cohortId,
            'cohort_hash'     => $cohortHash,
            'epoch'           => $currentEpoch,
            'total_products'  => $totalProducts,
            'total_variants'  => $variantCount,
            'inventory_sum'   => (float)$totalStock,
            'manifest_data'   => $manifestData,
        ];
    }

    /**
     * Validates all required release gates G01–G12 fail-closed (R169, R170, R171, AC-T42-01).
     */
    public function validateAllGates(array $cohortManifest = []): array
    {
        $manifest = !empty($cohortManifest) ? $cohortManifest : $this->buildCohortManifest('all');
        $currentEnv = $this->getCurrentEnvironment();
        $gateResults = [];
        $failedGates = [];

        foreach (self::GATES as $gateId => $gateMeta) {
            $task = $gateMeta['task'];
            $evidencePath = "{$this->evidenceBaseDir}/{$task}/result.json";

            // Check 1: Evidence file existence (R169)
            if (!file_exists($evidencePath)) {
                $gateResults[$gateId] = [
                    'name'     => $gateMeta['name'],
                    'task'     => $task,
                    'status'   => 'failed',
                    'reason'   => "Missing required evidence file at docs/evidence/{$task}/result.json",
                    'artifact' => $evidencePath,
                ];
                $failedGates[] = $gateId;
                continue;
            }

            $evidenceJson = json_decode((string)file_get_contents($evidencePath), true);
            if (!is_array($evidenceJson) || ($evidenceJson['qa_status'] ?? '') !== 'passed') {
                $gateResults[$gateId] = [
                    'name'     => $gateMeta['name'],
                    'task'     => $task,
                    'status'   => 'failed',
                    'reason'   => "Evidence file did not record qa_status=passed",
                    'artifact' => $evidencePath,
                ];
                $failedGates[] = $gateId;
                continue;
            }

            // Check 2: Version staleness (R171). Full version strings are compared: a minor or
            // patch change (e.g. WordPress 7.0 -> 7.1.3) invalidates the evidence, not only a major one.
            $evidenceEnv = $evidenceJson['environment'] ?? [];
            $staleReason = $this->detectVersionStaleness(is_array($evidenceEnv) ? $evidenceEnv : [], $currentEnv);
            if ($staleReason !== null) {
                $gateResults[$gateId] = [
                    'name'     => $gateMeta['name'],
                    'task'     => $task,
                    'status'   => 'stale',
                    'reason'   => $staleReason,
                    'artifact' => $evidencePath,
                ];
                $failedGates[] = $gateId;
                continue;
            }

            // Gate passed
            $gateResults[$gateId] = [
                'name'     => $gateMeta['name'],
                'task'     => $task,
                'status'   => 'passed',
                'artifact' => $evidencePath,
                'digest'   => $evidenceJson['content_digest'] ?? '',
            ];
        }

        // Check 3: DLQ error check (R170)
        $dlqCount = (int)$this->wpdb->get_var(
            "SELECT count(*) FROM {$this->tableOutbox} WHERE state = 'dead_letter'"
        );
        if ($dlqCount > 0) {
            $gateResults['DLQ_CHECK'] = [
                'name'   => 'Dead Letter Queue',
                'status' => 'failed',
                'reason' => "Unresolved dead_letter outbox events ({$dlqCount}) detected in wpe_outbox",
            ];
            $failedGates[] = 'DLQ_CHECK';
        }

        // Check 4: Unknown direct SQL writer plugins check (R171)
        $activePlugins = (array)get_option('active_plugins', []);
        $unknownWriters = [];
        $certifiedAllowlist = [
            'woocommerce/woocommerce.php',
            'wc-product-engine/wc-product-engine.php',
            'calora-bundles/calora-bundles.php',
            'calora-companion/calora-companion.php',
            'classic-editor/classic-editor.php',
            'classic-widgets/classic-widgets.php',
            'disable-xml-rpc/disable-xml-rpc.php',
            'cards-shield/cards-shield.php',
        ];

        foreach ($activePlugins as $p) {
            if (!in_array($p, $certifiedAllowlist, true) && !str_starts_with($p, 'wc-product-engine')) {
                // Check if plugin directly writes to postmeta
                $pPath = WP_PLUGIN_DIR . '/' . $p;
                if (file_exists($pPath) && is_file($pPath)) {
                    $content = (string)file_get_contents($pPath);
                    if (str_contains($content, 'INSERT INTO') && str_contains($content, 'postmeta')) {
                        $unknownWriters[] = $p;
                    }
                }
            }
        }

        if (!empty($unknownWriters)) {
            $gateResults['SQL_WRITER_CHECK'] = [
                'name'   => 'Direct SQL Writer Check',
                'status' => 'blocked',
                'reason' => "Uncertified direct SQL writer plugins detected: " . implode(', ', $unknownWriters),
            ];
            $failedGates[] = 'SQL_WRITER_CHECK';
        }

        $allPassed = empty($failedGates);
        $receiptToken = null;

        if ($allPassed) {
            $receiptToken = $this->generateReceiptToken($manifest);
        }

        return [
            'all_passed'          => $allPassed,
            'passed_count'        => count(self::GATES) - count($failedGates),
            'total_gates'         => count(self::GATES),
            'failed_gates'        => $failedGates,
            'gates'               => $gateResults,
            'cohort_manifest'     => $manifest,
            'environment'         => $currentEnv,
            'gate_receipt_token'  => $receiptToken,
        ];
    }

    /**
     * Generates a tamper-proof HMAC gate receipt token for cutover eligibility (AC-T42-02).
     */
    private function generateReceiptToken(array $manifest): string
    {
        $payload = [
            'cohort_id'   => $manifest['cohort_id'],
            'cohort_hash' => $manifest['cohort_hash'],
            'epoch'       => $manifest['epoch'],
            'issued_at'   => time(),
            'expires_at'  => time() + 3600, // 1 hour cutover window
        ];

        $json = (string)wp_json_encode($payload);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->signingKey);

        return $encoded . '.' . $signature;
    }

    /**
     * Verifies that a cutover endpoint request possesses a valid gate token for the cohort (AC-T42-02).
     */
    public function verifyCutoverEligibility(string $receiptToken, array $targetCohort): array
    {
        $parts = explode('.', $receiptToken);
        if (count($parts) !== 2) {
            return ['eligible' => false, 'error' => 'Malformed gate receipt token'];
        }

        [$encoded, $signature] = $parts;
        $expectedSig = hash_hmac('sha256', $encoded, $this->signingKey);
        if (!hash_equals($expectedSig, $signature)) {
            return ['eligible' => false, 'error' => 'Invalid or forged gate receipt token signature'];
        }

        $padded = strtr($encoded, '-_', '+/');
        $json = base64_decode($padded);
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return ['eligible' => false, 'error' => 'Corrupt token payload'];
        }

        // Expiry check
        if (time() > ($payload['expires_at'] ?? 0)) {
            return ['eligible' => false, 'error' => 'Gate receipt token has expired'];
        }

        // Cohort ID match
        $targetCohortId = $targetCohort['cohort_id'] ?? 'all';
        if ($payload['cohort_id'] !== $targetCohortId) {
            return ['eligible' => false, 'error' => "Token issued for cohort '{$payload['cohort_id']}', not '{$targetCohortId}'"];
        }

        // Cohort Hash match (R170)
        $currentManifest = $this->buildCohortManifest($targetCohortId);
        if ($payload['cohort_hash'] !== $currentManifest['cohort_hash']) {
            return [
                'eligible' => false,
                'error'    => "Cohort manifest hash mismatch! Database was mutated since gate certification (Token: {$payload['cohort_hash']}, DB: {$currentManifest['cohort_hash']})"
            ];
        }

        // Epoch match
        if ((int)$payload['epoch'] !== (int)$currentManifest['epoch']) {
            return ['eligible' => false, 'error' => "Epoch changed since token was issued"];
        }

        return [
            'eligible'    => true,
            'cohort_id'   => $payload['cohort_id'],
            'cohort_hash' => $payload['cohort_hash'],
            'epoch'       => $payload['epoch'],
        ];
    }

    /**
     * Strict evaluation of bypass flags (skip_required_gate) (R172, AC-T42-03).
     *
     * @throws RuntimeException in production environments
     */
    public function evaluateBypassAttempt(bool $skipFlag, ?string $environmentType = null): array
    {
        if (!$skipFlag) {
            return ['bypassed' => false, 'allowed' => false];
        }

        $env = $environmentType ?? (function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production');

        // Non-negotiable: in production, skip_required_gate is strictly prohibited
        if ($env === 'production') {
            throw new RuntimeException("Policy Violation (R172/INV16): skip_required_gate is strictly prohibited in production environment!");
        }

        // In non-production, log audit alert and reject bypass
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->insert($this->tableAudit, [
            'request_id' => wp_generate_uuid4(),
            'actor_id'   => get_current_user_id() ?: null,
            'operation'  => 'gate_bypass_attempt_rejected',
            'target_id'  => 0,
            'summary'    => (string)wp_json_encode(['env' => $env, 'reason' => 'skip_required_gate rejected by invariant INV16']),
            'created_at' => $now,
        ]);

        return [
            'bypassed' => false,
            'allowed'  => false,
            'error'    => 'Gate bypass rejected by policy; all release gates must pass.',
        ];
    }
}
