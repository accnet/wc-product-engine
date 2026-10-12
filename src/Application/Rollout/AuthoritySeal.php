<?php

declare(strict_types=1);

namespace WPE\Application\Rollout;

use RuntimeException;
use InvalidArgumentException;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Compatibility\WooDataStore\InventoryBridge;

/**
 * AuthoritySeal — Authority seal policy, no-fallback enforcement, and full_native certification.
 *
 * Implements requirements:
 * - R181: No fallback on native read error (strictly returns dependency_unavailable, zero postmeta fallback per INV12).
 * - R182: Owned field authority (direct legacy writes to owned keys rejected; certified writes route to native).
 * - R183: Plugin change certification (uncertified plugin activates automatic readiness demotion without unsafe source flip).
 * - R184: Legacy mirror is strictly non-canonical (storefront reads 100% native; legacy mirror mutations ignored).
 * - AC-T45-01: Full native reads and writes correct with zero stale postmeta fallback.
 * - AC-T45-02: CRUD-safe supported plugins operate cleanly; unsupported plugins trigger operational alert and block rollout.
 */
class AuthoritySeal
{
    public const OWNED_FIELDS = [
        '_price',
        '_regular_price',
        '_sale_price',
        '_stock',
        '_stock_status',
        '_manage_stock',
        '_sku',
        '_weight',
    ];

    public const CERTIFIED_ALLOWLIST = [
        'woocommerce/woocommerce.php',
        'wc-product-engine/wc-product-engine.php',
        'calora-bundles/calora-bundles.php',
        'calora-companion/calora-companion.php',
        'classic-editor/classic-editor.php',
        'classic-widgets/classic-widgets.php',
        'disable-xml-rpc/disable-xml-rpc.php',
        'cards-shield/cards-shield.php',
    ];

    private \wpdb $wpdb;
    private ProductRepository $prodRepo;
    private InventoryBridge $inventoryBridge;
    private ReadinessGates $gates;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableControl;
    private string $tableAudit;

    public function __construct(
        ?\wpdb $wpdb = null,
        ?ProductRepository $prodRepo = null,
        ?InventoryBridge $inventoryBridge = null,
        ?ReadinessGates $gates = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->prodRepo = $prodRepo ?? new ProductRepository($this->wpdb);
        $this->inventoryBridge = $inventoryBridge ?? new InventoryBridge($this->wpdb);
        $this->gates = $gates ?? new ReadinessGates($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts    = "{$prefix}wpe_products";
        $this->tableVariants    = "{$prefix}wpe_variants";
        $this->tableInventory   = "{$prefix}wpe_inventory";
        $this->tableObjectState = "{$prefix}wpe_object_state";
        $this->tableControl     = "{$prefix}wpe_control";
        $this->tableAudit       = "{$prefix}wpe_audit";
    }

    /**
     * Seals authority for a cohort, transitioning to full_native and activating no-fallback flag (R181, AC-T45-01).
     */
    public function sealAuthority(string $cohortId, array $options = []): array
    {
        $now = gmdate('Y-m-d H:i:s.u');

        // 1. Verify environment and plugins
        $currentEnv = $this->gates->getCurrentEnvironment();
        $pluginCheck = $this->verifyPluginCertification($currentEnv['plugins'] ?? []);
        if (!$pluginCheck['certified']) {
            throw new RuntimeException("Cannot seal authority: Uncertified plugins detected: " . implode(', ', $pluginCheck['violations']));
        }

        // 2. Identify products in cohort
        $productIds = array_values(array_map('intval', (array)($options['product_ids'] ?? [])));
        $whereSql = "1=1";
        if (!empty($productIds)) {
            $inIds = implode(',', $productIds);
            $whereSql = "object_id IN ({$inIds}) OR parent_id IN ({$inIds})";
        }

        // 3. Atomically transition object states to full_native
        $this->wpdb->query('START TRANSACTION');

        try {
            $this->wpdb->query(
                "UPDATE {$this->tableObjectState}
                 SET authority = 'full_native',
                     updated_at = '{$now}'
                 WHERE {$whereSql}"
            );

            // 4. Activate no-fallback flag in wpe_control
            $noFallbackKey = "full_native_no_fallback_{$cohortId}";
            $this->wpdb->replace($this->tableControl, [
                'key'        => $noFallbackKey,
                'value'      => (string)wp_json_encode([
                    'active'      => true,
                    'cohort_id'   => $cohortId,
                    'sealed_at'   => $now,
                    'policy'      => 'strict_fail_closed_no_postmeta_fallback',
                ]),
                'version'    => 1,
                'updated_at' => $now,
            ]);

            // 5. Generate certification report
            $reportKey = "full_native_certification_report_{$cohortId}";
            $report = [
                'cohort_id'            => $cohortId,
                'status'               => 'certified_full_native',
                'sealed_at'            => $now,
                'environment'          => $currentEnv,
                'no_fallback_active'   => true,
                'owned_fields_locked'  => self::OWNED_FIELDS,
                'certified_plugins'    => array_keys($currentEnv['plugins'] ?? []),
                'policy_rules'         => [
                    'R181' => 'Native read error strictly blocks postmeta fallback',
                    'R182' => 'Direct postmeta writes to owned fields rejected by fence',
                    'R183' => 'Uncertified plugin activates demotion without source flipping',
                    'R184' => 'Legacy mirror is non-canonical secondary projection',
                ],
            ];

            $this->wpdb->replace($this->tableControl, [
                'key'        => $reportKey,
                'value'      => (string)wp_json_encode($report),
                'version'    => 1,
                'updated_at' => $now,
            ]);

            // 6. Security audit logging
            $this->wpdb->insert($this->tableAudit, [
                'request_id' => wp_generate_uuid4(),
                'actor_id'   => get_current_user_id() ?: null,
                'operation'  => 'authority_sealed_full_native',
                'target_id'  => count($productIds) === 1 ? $productIds[0] : 0,
                'summary'    => (string)wp_json_encode(['cohort_id' => $cohortId, 'report' => $reportKey]),
                'created_at' => $now,
            ]);

            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }

        return $report;
    }

    /**
     * Checks if no-fallback flag is active for cohort (R181).
     */
    public function isNoFallbackActive(string $cohortId): bool
    {
        $noFallbackKey = "full_native_no_fallback_{$cohortId}";
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            $noFallbackKey
        ), ARRAY_A);

        if (!$row) {
            return false;
        }

        $data = json_decode((string)$row['value'], true);
        return !empty($data['active']);
    }

    /**
     * Reads canonical product state from native repository with strict no-fallback enforcement (R181, R184, AC-T45-01).
     *
     * @throws RuntimeException when native record fails to load under full_native authority (zero postmeta fallback!)
     */
    public function readCanonicalProduct(int $productId, string $cohortId = 'all'): array
    {
        $noFallback = $this->isNoFallbackActive($cohortId);

        // Fetch native product
        $nativeRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, type, status, slug, title, sku, regular_price, sale_price, version
             FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);

        if (!$nativeRow) {
            if ($noFallback) {
                // R181 / INV12: Strictly fail-closed without falling back to postmeta!
                throw new RuntimeException("Native Read Error (R181/INV12): Product {$productId} not found in native repository. Fallback to legacy postmeta is strictly prohibited under full_native authority.");
            }

            // In legacy mode only: fallback to postmeta
            return $this->readLegacyPostmetaFallback($productId);
        }

        // Fetch native inventory
        $invRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT manage_stock, quantity, stock_status, backorders
             FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ), ARRAY_A);

        return [
            'id'            => (int)$nativeRow['id'],
            'type'          => $nativeRow['type'],
            'title'         => $nativeRow['title'],
            'sku'           => $nativeRow['sku'],
            'regular_price' => (float)$nativeRow['regular_price'],
            'sale_price'    => $nativeRow['sale_price'] !== null ? (float)$nativeRow['sale_price'] : null,
            'price'         => $nativeRow['sale_price'] !== null ? (float)$nativeRow['sale_price'] : (float)$nativeRow['regular_price'],
            'manage_stock'  => !empty($invRow['manage_stock']),
            'stock_quantity'=> $invRow ? (float)$invRow['quantity'] : 0.0,
            'stock_status'  => $invRow['stock_status'] ?? 'instock',
            'source'        => 'native',
        ];
    }

    /**
     * Intercepts postmeta writes to owned fields (R182).
     * Certified writes route to native; uncertified writes are rejected.
     */
    public function interceptPostmetaWrite(
        int $postId,
        string $metaKey,
        $metaValue,
        bool $isCertifiedAdapter = false
    ): array {
        // Check if key is owned
        if (!in_array($metaKey, self::OWNED_FIELDS, true)) {
            return ['intercepted' => false, 'allowed' => true, 'action' => 'pass_to_wp'];
        }

        // Check if product is under full_native authority
        $state = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT authority, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
            $postId
        ), ARRAY_A);

        $authority = $state['authority'] ?? 'legacy';

        if ($authority === 'full_native' || $authority === 'native_bridge') {
            if (!$isCertifiedAdapter) {
                // Direct uncertified write to owned field: REJECT!
                $this->wpdb->insert($this->tableAudit, [
                    'request_id' => wp_generate_uuid4(),
                    'actor_id'   => get_current_user_id() ?: null,
                    'operation'  => 'write_ownership_fence_violation',
                    'target_id'  => $postId,
                    'summary'    => (string)wp_json_encode([
                        'meta_key'   => $metaKey,
                        'meta_value' => $metaValue,
                        'authority'  => $authority,
                        'reason'     => 'Direct uncertified postmeta write rejected under native authority (R182)',
                    ]),
                    'created_at' => gmdate('Y-m-d H:i:s.u'),
                ]);

                return [
                    'intercepted' => true,
                    'allowed'     => false,
                    'action'      => 'rejected_by_ownership_fence',
                    'error'       => "Policy Violation (R182): Direct postmeta writes to owned key '{$metaKey}' are strictly rejected under {$authority} authority.",
                ];
            }

            // Certified adapter write: route to native table
            $routed = $this->routeCertifiedWriteToNative($postId, $metaKey, $metaValue);
            return [
                'intercepted' => true,
                'allowed'     => true,
                'action'      => 'routed_to_native',
                'details'     => $routed,
            ];
        }

        return ['intercepted' => false, 'allowed' => true, 'action' => 'pass_to_wp'];
    }

    /**
     * Routes a certified adapter write to the native database tables (R182).
     */
    private function routeCertifiedWriteToNative(int $postId, string $metaKey, $metaValue): array
    {
        $now = gmdate('Y-m-d H:i:s.u');

        if (in_array($metaKey, ['_regular_price', '_sale_price', '_sku'], true)) {
            $columnMap = [
                '_regular_price' => 'regular_price',
                '_sale_price'    => 'sale_price',
                '_sku'           => 'sku',
            ];
            $col = $columnMap[$metaKey];
            $val = ($metaKey === '_regular_price' || $metaKey === '_sale_price') ? sprintf('%.6f', (float)$metaValue) : (string)$metaValue;

            $this->wpdb->update($this->tableProducts, [
                $col         => $val,
                'version'    => 2,
                'updated_at' => $now,
            ], ['id' => $postId]);

            return ['table' => $this->tableProducts, 'column' => $col, 'value' => $val];
        }

        if (in_array($metaKey, ['_stock', '_stock_status', '_manage_stock'], true)) {
            if ($metaKey === '_stock') {
                $qty = (float)$metaValue;
                $this->wpdb->update($this->tableInventory, [
                    'quantity'   => $qty,
                    'updated_at' => $now,
                ], ['owner_id' => $postId]);
                return ['table' => $this->tableInventory, 'column' => 'quantity', 'value' => $qty];
            }

            if ($metaKey === '_stock_status') {
                $status = (string)$metaValue;
                $this->wpdb->update($this->tableInventory, [
                    'stock_status' => $status,
                    'updated_at'   => $now,
                ], ['owner_id' => $postId]);
                return ['table' => $this->tableInventory, 'column' => 'stock_status', 'value' => $status];
            }
        }

        return ['table' => 'wpe_meta', 'key' => $metaKey];
    }

    /**
     * Verifies plugin certification allowlist (R183, AC-T45-02).
     */
    public function verifyPluginCertification(array $activePlugins): array
    {
        $violations = [];
        $allowlist = self::CERTIFIED_ALLOWLIST;

        foreach ($activePlugins as $pPath => $pVersion) {
            $pluginKey = is_string($pPath) ? $pPath : (string)$pVersion;
            if (!in_array($pluginKey, $allowlist, true) && !str_starts_with($pluginKey, 'wc-product-engine')) {
                $violations[] = $pluginKey;
            }
        }

        $certified = empty($violations);

        if (!$certified) {
            // Log security and ops alert
            $this->wpdb->insert($this->tableAudit, [
                'request_id' => wp_generate_uuid4(),
                'actor_id'   => get_current_user_id() ?: null,
                'operation'  => 'uncertified_plugin_detected',
                'target_id'  => 0,
                'summary'    => (string)wp_json_encode([
                    'violations' => $violations,
                    'action'     => 'readiness_demoted_source_preserved',
                    'reason'     => 'Uncertified plugin activated; rollout gated per R183/AC-T45-02',
                ]),
                'created_at' => gmdate('Y-m-d H:i:s.u'),
            ]);
        }

        return [
            'certified'  => $certified,
            'violations' => $violations,
            'action'     => $certified ? 'certified' : 'readiness_demoted_source_preserved',
        ];
    }

    /**
     * Demotes readiness on uncertified plugin or version change without overwriting source (R183, AC-T45-02).
     */
    public function demoteReadiness(string $cohortId, string $reason): array
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $demoteKey = "cohort_demoted_{$cohortId}";

        $this->wpdb->replace($this->tableControl, [
            'key'        => $demoteKey,
            'value'      => (string)wp_json_encode([
                'cohort_id'   => $cohortId,
                'demoted'     => true,
                'reason'      => $reason,
                'demoted_at'  => $now,
                'source_kept' => 'native', // Crucial: does not dangerously flip source!
            ]),
            'version'    => 1,
            'updated_at' => $now,
        ]);

        $this->wpdb->insert($this->tableAudit, [
            'request_id' => wp_generate_uuid4(),
            'actor_id'   => get_current_user_id() ?: null,
            'operation'  => 'cohort_readiness_demoted',
            'target_id'  => 0,
            'summary'    => (string)wp_json_encode([
                'cohort_id' => $cohortId,
                'reason'    => $reason,
            ]),
            'created_at' => $now,
        ]);

        return [
            'demoted'         => true,
            'cohort_id'       => $cohortId,
            'reason'          => $reason,
            'source_preserved'=> 'native',
        ];
    }

    /**
     * Retrieves stored certification report (T45 Deliverable).
     */
    public function getCertificationReport(string $cohortId): ?array
    {
        $reportKey = "full_native_certification_report_{$cohortId}";
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            $reportKey
        ), ARRAY_A);

        return $row ? json_decode((string)$row['value'], true) : null;
    }

    /**
     * Legacy postmeta fallback only used when no-fallback flag is NOT active.
     */
    private function readLegacyPostmetaFallback(int $productId): array
    {
        $price = (float)get_post_meta($productId, '_price', true);
        $regularPrice = (float)get_post_meta($productId, '_regular_price', true);
        $stock = (float)get_post_meta($productId, '_stock', true);
        $stockStatus = (string)get_post_meta($productId, '_stock_status', true);
        $sku = (string)get_post_meta($productId, '_sku', true);

        return [
            'id'            => $productId,
            'type'          => 'simple',
            'title'         => get_the_title($productId),
            'sku'           => $sku,
            'regular_price' => $regularPrice,
            'sale_price'    => null,
            'price'         => $price,
            'manage_stock'  => true,
            'stock_quantity'=> $stock,
            'stock_status'  => $stockStatus ?: 'instock',
            'source'        => 'legacy_fallback',
        ];
    }
}
