<?php

namespace WPE\Application\Variant;

use RuntimeException;
use InvalidArgumentException;
use WPE\Infrastructure\Database\OptionsRepository;

/**
 * Server-side variation resolver and allowed-values engine.
 *
 * Responsibilities:
 * - Deterministic variation resolution across None, Partial, Exact, and Ambiguous states (AC-T25-01).
 * - Selection normalization by option IDs and value IDs or taxonomy keys (R102).
 * - Wildcard (ANY) precedence policy matching WooCommerce Core baseline (R100).
 * - Bounded candidate queries avoiding full JSON scans and Cartesian product generation (R99).
 * - Canonical price, sale scheduling, and inherited stock calculation (R101, AC-T25-01).
 * - Strict distinction between free items (price=0, purchasable) and missing price (null, not purchasable) (AC-T25-01).
 * - Duplicate and overlapping wildcard conflict detection and reporting without random selection (AC-T25-02).
 */
class VariantResolver
{
    private \wpdb $wpdb;
    private OptionsRepository $optionsRepo;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;
    private string $tableInventory;
    private string $tableProductMedia;
    private string $tableVariantMedia;

    private array $conflictAuditLog = [];

    public function __construct(?\wpdb $wpdb = null, ?OptionsRepository $optionsRepo = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts      = $prefix . 'wpe_products';
        $this->tableVariants      = $prefix . 'wpe_variants';
        $this->tableOptions       = $prefix . 'wpe_options';
        $this->tableOptionValues  = $prefix . 'wpe_option_values';
        $this->tableVariantValues = $prefix . 'wpe_variant_values';
        $this->tableInventory     = $prefix . 'wpe_inventory';
        $this->tableProductMedia  = $prefix . 'wpe_product_media';
        $this->tableVariantMedia  = $prefix . 'wpe_variant_media';
    }

    /**
     * Resolve variation selection for a variable product.
     *
     * @param int $productId Parent variable product ID
     * @param array $selection Key-value map of option selections
     * @param int $quantity Desired quantity (default 1)
     * @param string|null $expectedRevision Expected product revision/version
     * @param int $requestSequence Client request sequence echo
     * @return array Standard resolver response DTO
     */
    public function resolve(
        int $productId,
        array $selection,
        int $quantity = 1,
        ?string $expectedRevision = null,
        int $requestSequence = 0
    ): array {
        // 1. Fetch parent product info
        $sqlProd = "SELECT id, type, status, version FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL LIMIT 1";
        $product = $this->wpdb->get_row($this->wpdb->prepare($sqlProd, $productId), ARRAY_A);

        if (!$product) {
            return $this->buildResponse('none', $requestSequence, '0', null, [], 'invalid_product');
        }

        $currentRevision = (string)($product['version'] ?? '1');

        if ($product['type'] !== 'variable') {
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, [], 'not_variable_product');
        }

        // 2. Fetch product variation options
        $sqlOpts = "SELECT id, `key`, `name`, `position` FROM {$this->tableOptions}
                    WHERE product_id = %d AND used_for_variation = 1
                    ORDER BY `position` ASC, id ASC";
        $options = $this->wpdb->get_results($this->wpdb->prepare($sqlOpts, $productId), ARRAY_A);

        if (empty($options)) {
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, [], 'no_options');
        }

        // 3. Normalize selection
        $normResult = $this->normalizeSelection($productId, $selection, $options);
        if (!$normResult['valid']) {
            $initialAllowed = $this->computeAllowedValues($productId, [], $options);
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, $initialAllowed['allowed_values'], $normResult['reason'], $initialAllowed['allowed_details']);
        }
        $normalizedSelection = $normResult['selection']; // [option_id => value_id]

        // 4. Bounded Candidate Query: Fetch published variants and their attribute values
        $candidateData = $this->getCandidateVariants($productId);
        $variants = $candidateData['variants']; // [variant_id => row]
        $variantValues = $candidateData['variant_values']; // [variant_id => [option_id => ['value_id' => ..., 'is_any' => ...]]]

        if (empty($variants)) {
            $emptyAllowed = [];
            foreach ($options as $opt) {
                $emptyAllowed[(string)$opt['id']] = [];
            }
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, $emptyAllowed, 'no_variations_exist');
        }

        // 5. Match candidate variants against selection
        $matchingVariantIds = [];
        foreach ($variants as $varId => $var) {
            if ($this->doesVariantMatchSelection($varId, $normalizedSelection, $variantValues)) {
                $matchingVariantIds[] = $varId;
            }
        }

        // 6. Compute Allowed Values based on currently matching candidate variants
        $allowedResult = $this->computeAllowedValues($productId, $matchingVariantIds, $options, $variantValues);
        $allowedValues = $allowedResult['allowed_values'];
        $allowedDetails = $allowedResult['allowed_details'];

        // 7. Determine State: None, Partial, Exact, or Ambiguous
        if (empty($matchingVariantIds)) {
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, $allowedValues, 'no_matching_variation', $allowedDetails);
        }

        $allOptionsSelected = (count($normalizedSelection) === count($options));

        if (!$allOptionsSelected) {
            // Partial selection: valid selection so far, awaiting remaining options
            return $this->buildResponse('partial', $requestSequence, $currentRevision, null, $allowedValues, null, $allowedDetails);
        }

        // Full selection: evaluate single or multiple matches
        $winningVariant = null;
        if (count($matchingVariantIds) === 1) {
            $winningVariant = $variants[$matchingVariantIds[0]];
        } else {
            // Multiple matching variations: evaluate Wildcard Precedence and Ambiguity Policy (R100, AC-T25-02)
            $precedenceResult = $this->evaluatePrecedence($productId, $matchingVariantIds, $variants, $variantValues);
            if ($precedenceResult['state'] === 'ambiguous') {
                $this->conflictAuditLog[] = [
                    'product_id' => $productId,
                    'conflicting_variant_ids' => $precedenceResult['conflicting_variant_ids'],
                    'selection' => $normalizedSelection,
                    'reason' => 'Duplicate overlapping Any detected without deterministic order',
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ];
                return $this->buildResponse('ambiguous', $requestSequence, $currentRevision, null, $allowedValues, 'ambiguous_match', $allowedDetails);
            }
            $winningVariant = $precedenceResult['variant'];
        }

        // 8. Hydrate Variant DTO with Price, Stock, Availability and Media (R101, AC-T25-01)
        $variantDto = $this->buildVariantDto($productId, $winningVariant, $quantity);

        return $this->buildResponse('exact', $requestSequence, $currentRevision, $variantDto, $allowedValues, $variantDto['reason'], $allowedDetails);
    }

    /**
     * Normalize client selection into canonical [option_id => value_id] format.
     * Rejects unknown options or values belonging to other products/options (R102).
     */
    public function normalizeSelection(int $productId, array $rawSelection, array $options): array
    {
        $normalized = [];
        $optionMap = [];
        foreach ($options as $opt) {
            $optionMap[(int)$opt['id']] = $opt;
            $optionMap[strtolower((string)$opt['key'])] = $opt;
            $optionMap[strtolower((string)$opt['name'])] = $opt;
        }

        foreach ($rawSelection as $rawKey => $rawValue) {
            if ($rawValue === null || $rawValue === '' || $rawValue === false) {
                continue;
            }

            // Match option
            $matchedOpt = null;
            if (is_numeric($rawKey) && isset($optionMap[(int)$rawKey])) {
                $matchedOpt = $optionMap[(int)$rawKey];
            } else {
                $cleanKey = strtolower(trim((string)$rawKey));
                if (isset($optionMap[$cleanKey])) {
                    $matchedOpt = $optionMap[$cleanKey];
                } elseif (str_starts_with($cleanKey, 'attribute_') && isset($optionMap[substr($cleanKey, 10)])) {
                    $matchedOpt = $optionMap[substr($cleanKey, 10)];
                }
            }

            if (!$matchedOpt) {
                return ['valid' => false, 'reason' => 'unknown_option', 'selection' => []];
            }

            $optId = (int)$matchedOpt['id'];

            // Match option value
            $valId = null;
            if (is_numeric($rawValue)) {
                $sqlVal = "SELECT id FROM {$this->tableOptionValues} WHERE id = %d AND option_id = %d LIMIT 1";
                $foundId = $this->wpdb->get_var($this->wpdb->prepare($sqlVal, (int)$rawValue, $optId));
                if ($foundId !== null) {
                    $valId = (int)$foundId;
                }
            } else {
                $cleanVal = trim((string)$rawValue);
                $sqlVal = "SELECT id FROM {$this->tableOptionValues} WHERE option_id = %d AND (value_key = %s OR label = %s) LIMIT 1";
                $foundId = $this->wpdb->get_var($this->wpdb->prepare($sqlVal, $optId, $cleanVal, $cleanVal));
                if ($foundId !== null) {
                    $valId = (int)$foundId;
                }
            }

            if ($valId === null) {
                return ['valid' => false, 'reason' => 'invalid_value', 'selection' => []];
            }

            $normalized[$optId] = $valId;
        }

        return ['valid' => true, 'reason' => null, 'selection' => $normalized];
    }

    /**
     * Bounded query fetching active variants and their attribute values for a product.
     */
    public function getCandidateVariants(int $productId): array
    {
        $sqlVar = "SELECT v.id, v.sku, v.regular_price, v.sale_price, v.sale_from, v.sale_to,
                          v.stock_owner_id, v.position, v.status,
                          i.manage_stock, i.quantity AS stock_qty, i.stock_status, i.backorders
                   FROM {$this->tableVariants} v
                   LEFT JOIN {$this->tableInventory} i ON i.owner_id = COALESCE(v.stock_owner_id, v.id)
                   WHERE v.product_id = %d AND v.status = 'publish' AND v.deleted_at IS NULL
                   ORDER BY v.position ASC, v.id ASC";

        $varRows = $this->wpdb->get_results($this->wpdb->prepare($sqlVar, $productId), ARRAY_A);
        if (empty($varRows)) {
            return ['variants' => [], 'variant_values' => []];
        }

        $variants = [];
        $varIds = [];
        foreach ($varRows as $row) {
            $vId = (int)$row['id'];
            $variants[$vId] = $row;
            $varIds[] = $vId;
        }

        $idList = implode(',', $varIds);
        $sqlVals = "SELECT vv.variant_id, vv.option_id, vv.value_id, vv.is_any
                    FROM {$this->tableVariantValues} vv
                    WHERE vv.variant_id IN ({$idList})";
        $valRows = $this->wpdb->get_results($sqlVals, ARRAY_A);

        $variantValues = [];
        foreach ($valRows as $vr) {
            $vId   = (int)$vr['variant_id'];
            $optId = (int)$vr['option_id'];
            $variantValues[$vId][$optId] = [
                'value_id' => $vr['value_id'] !== null ? (int)$vr['value_id'] : null,
                'is_any'   => (int)$vr['is_any'],
            ];
        }

        return ['variants' => $variants, 'variant_values' => $variantValues];
    }

    /**
     * Check if a variant matches a normalized selection vector.
     */
    private function doesVariantMatchSelection(int $variantId, array $selection, array $variantValues): bool
    {
        $assigned = $variantValues[$variantId] ?? [];

        foreach ($selection as $optId => $valId) {
            if (!isset($assigned[$optId])) {
                return false;
            }
            $entry = $assigned[$optId];
            if ($entry['is_any'] === 1) {
                continue; // Matches wildcard Any
            }
            if ($entry['value_id'] !== $valId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate precedence between multiple candidate variations matching the same selection (R100, AC-T25-02).
     * Follows WooCommerce baseline: ordered by position ASC, id ASC; flags duplicate overlapping Any as ambiguous.
     */
    private function evaluatePrecedence(int $productId, array $matchingVariantIds, array $variants, array $variantValues): array
    {
        // Check for duplicate identical vectors (overlapping Any conflict)
        $vectorFingerprints = [];
        $duplicatesFound = false;
        $conflictIds = [];

        foreach ($matchingVariantIds as $vId) {
            $assigned = $variantValues[$vId] ?? [];
            ksort($assigned);
            $fp = json_encode($assigned);

            if (isset($vectorFingerprints[$fp])) {
                $duplicatesFound = true;
                $conflictIds = array_unique(array_merge($conflictIds, [$vectorFingerprints[$fp], $vId]));
            } else {
                $vectorFingerprints[$fp] = $vId;
            }
        }

        if ($duplicatesFound) {
            return [
                'state' => 'ambiguous',
                'variant' => null,
                'conflicting_variant_ids' => $conflictIds,
            ];
        }

        // Sort matching candidates by specificity (exact values before wildcards), then by position ASC, id ASC (Woo baseline)
        usort($matchingVariantIds, function ($aId, $bId) use ($variantValues, $variants) {
            $aVals = $variantValues[$aId] ?? [];
            $bVals = $variantValues[$bId] ?? [];

            // Count exact (non-any) matches
            $aExactCount = count(array_filter($aVals, fn($v) => ($v['is_any'] === 0)));
            $bExactCount = count(array_filter($bVals, fn($v) => ($v['is_any'] === 0)));

            if ($aExactCount !== $bExactCount) {
                return $bExactCount <=> $aExactCount; // Higher exact matches come first
            }

            // Order by position ASC
            $aPos = (int)($variants[$aId]['position'] ?? 0);
            $bPos = (int)($variants[$bId]['position'] ?? 0);
            if ($aPos !== $bPos) {
                return $aPos <=> $bPos;
            }

            return $aId <=> $bId;
        });

        $winnerId = $matchingVariantIds[0];
        return [
            'state' => 'exact',
            'variant' => $variants[$winnerId],
            'conflicting_variant_ids' => [],
        ];
    }

    /**
     * Compute allowed values and details for options based on compatible variations (R99).
     */
    public function computeAllowedValues(
        int $productId,
        array $candidateVariantIds,
        array $options,
        array $variantValues = []
    ): array {
        $allowedValues = [];
        $allowedDetails = [];

        // All existing option values for this product
        $optIds = array_column($options, 'id');
        $allOptValues = [];
        if (!empty($optIds)) {
            $sql = "SELECT id, option_id, value_key, label FROM {$this->tableOptionValues} WHERE option_id IN (" . implode(',', array_map('intval', $optIds)) . ") ORDER BY position ASC, id ASC";
            $valRows = $this->wpdb->get_results($sql, ARRAY_A);
            foreach ($valRows as $vr) {
                $allOptValues[(int)$vr['option_id']][] = $vr;
            }
        }

        foreach ($options as $opt) {
            $optId = (int)$opt['id'];
            $optIdStr = (string)$optId;
            $allowedValues[$optIdStr] = [];
            $allowedDetails[$optIdStr] = [];

            $valuesForThisOpt = $allOptValues[$optId] ?? [];

            if (empty($candidateVariantIds)) {
                // If candidate variants is empty, return all values that exist in any active variation for this product
                $sqlActiveVals = "SELECT DISTINCT vv.value_id, vv.is_any
                                  FROM {$this->tableVariantValues} vv
                                  JOIN {$this->tableVariants} v ON v.id = vv.variant_id
                                  WHERE v.product_id = %d AND v.status = 'publish' AND v.deleted_at IS NULL AND vv.option_id = %d";
                $activeRows = $this->wpdb->get_results($this->wpdb->prepare($sqlActiveVals, $productId, $optId), ARRAY_A);

                $hasAny = false;
                $activeValIds = [];
                foreach ($activeRows as $ar) {
                    if ((int)$ar['is_any'] === 1) {
                        $hasAny = true;
                    } elseif ($ar['value_id'] !== null) {
                        $activeValIds[(int)$ar['value_id']] = true;
                    }
                }

                foreach ($valuesForThisOpt as $vRow) {
                    $vId = (int)$vRow['id'];
                    $vIdStr = (string)$vId;
                    if ($hasAny || isset($activeValIds[$vId])) {
                        $allowedValues[$optIdStr][] = $vIdStr;
                        $allowedDetails[$optIdStr][$vIdStr] = [
                            'exists'      => true,
                            'purchasable' => true,
                            'in_stock'    => true,
                        ];
                    }
                }
            } else {
                // Collect values from candidate variations
                $hasAny = false;
                $matchingValIds = [];

                foreach ($candidateVariantIds as $vId) {
                    $assigned = $variantValues[$vId][$optId] ?? null;
                    if (!$assigned) {
                        continue;
                    }
                    if ($assigned['is_any'] === 1) {
                        $hasAny = true;
                    } elseif ($assigned['value_id'] !== null) {
                        $matchingValIds[$assigned['value_id']] = true;
                    }
                }

                foreach ($valuesForThisOpt as $vRow) {
                    $vId = (int)$vRow['id'];
                    $vIdStr = (string)$vId;
                    if ($hasAny || isset($matchingValIds[$vId])) {
                        $allowedValues[$optIdStr][] = $vIdStr;
                        $allowedDetails[$optIdStr][$vIdStr] = [
                            'exists'      => true,
                            'purchasable' => true,
                            'in_stock'    => true,
                        ];
                    }
                }
            }
        }

        return [
            'allowed_values'  => $allowedValues,
            'allowed_details' => $allowedDetails,
        ];
    }

    /**
     * Build the resolved variant DTO calculating canonical price, sale schedule, and inherited stock (R101, AC-T25-01).
     */
    private function buildVariantDto(int $productId, array $variant, int $requestedQty = 1): array
    {
        $variantId = (int)$variant['id'];

        // 1. Price Calculation & Missing vs Free Price differentiation (AC-T25-01)
        $regPrice  = $variant['regular_price'];
        $salePrice = $variant['sale_price'];
        $saleFrom  = $variant['sale_from'];
        $saleTo    = $variant['sale_to'];

        $priceStr = null;
        $displayPriceStr = null;
        $isMissingPrice = false;

        if ($regPrice === null && $salePrice === null) {
            $isMissingPrice = true;
        } else {
            $effectivePrice = $regPrice;

            // Check scheduled sale
            if ($salePrice !== null) {
                $now = current_time('mysql');
                $isSaleActive = true;
                if ($saleFrom !== null && $now < $saleFrom) {
                    $isSaleActive = false;
                }
                if ($saleTo !== null && $now > $saleTo) {
                    $isSaleActive = false;
                }
                if ($isSaleActive) {
                    $effectivePrice = $salePrice;
                }
            }

            if ($effectivePrice !== null) {
                $priceStr = number_format((float)$effectivePrice, 6, '.', '');
                $displayPriceStr = number_format((float)$effectivePrice, 2, '.', '');
            } else {
                $isMissingPrice = true;
            }
        }

        // 2. Stock and Inventory Calculation with Inherited Stock (R101, AC-T25-01)
        $managesStock = (int)($variant['manage_stock'] ?? 0);
        $stockOwnerId = $variantId;
        $stockStatus = 'instock';
        $quantity = null;
        $backorders = 'no';

        if ($managesStock === 1) {
            $stockOwnerId = $variantId;
            $quantity     = $variant['stock_qty'] !== null ? (int)$variant['stock_qty'] : null;
            $stockStatus  = $variant['stock_status'] ?: 'instock';
            $backorders   = $variant['backorders'] ?: 'no';
        } else {
            // Inherited stock from parent product (AC-T25-01)
            $stockOwnerId = $productId;
            $sqlParentInv = "SELECT manage_stock, quantity, stock_status, backorders FROM {$this->tableInventory} WHERE owner_id = %d LIMIT 1";
            $parentInv = $this->wpdb->get_row($this->wpdb->prepare($sqlParentInv, $productId), ARRAY_A);

            if ($parentInv) {
                if ((int)$parentInv['manage_stock'] === 1) {
                    $quantity    = $parentInv['quantity'] !== null ? (int)$parentInv['quantity'] : null;
                    $stockStatus = $parentInv['stock_status'] ?: 'instock';
                    $backorders  = $parentInv['backorders'] ?: 'no';
                } else {
                    $stockStatus = $parentInv['stock_status'] ?: 'instock';
                }
            }
        }

        // Availability and Purchasability evaluation
        $available = true;
        $purchasable = true;
        $reason = null;

        if ($isMissingPrice) {
            $purchasable = false;
            $reason = 'missing_price';
        }

        if ($stockStatus === 'outofstock') {
            $available = false;
            $purchasable = false;
            $reason = $reason ?: 'out_of_stock';
        } elseif ($quantity !== null && $quantity < $requestedQty) {
            if ($backorders === 'no') {
                $available = false;
                $purchasable = false;
                $reason = $reason ?: 'out_of_stock';
            }
        }

        // 3. Media Asset ID (Variant primary or parent featured fallback)
        $sqlMedia = "SELECT asset_id FROM {$this->tableVariantMedia} WHERE variant_id = %d AND is_primary = 1 LIMIT 1";
        $assetId = $this->wpdb->get_var($this->wpdb->prepare($sqlMedia, $variantId));

        if (!$assetId) {
            $sqlParentMedia = "SELECT asset_id FROM {$this->tableProductMedia} WHERE product_id = %d AND role = 'featured' LIMIT 1";
            $assetId = $this->wpdb->get_var($this->wpdb->prepare($sqlParentMedia, $productId));
        }

        return [
            'id'             => (string)$variantId,
            'sku'            => (string)($variant['sku'] ?? ''),
            'price'          => $priceStr,
            'display_price'  => $displayPriceStr,
            'stock_owner_id' => (string)$stockOwnerId,
            'available'      => $available,
            'purchasable'    => $purchasable,
            'media_asset_id' => $assetId ? (string)$assetId : null,
            'reason'         => $reason,
        ];
    }

    /**
     * Build standardized resolver response matching DTO contract (docs/04-api-contract.md §3).
     */
    private function buildResponse(
        string $state,
        int $requestSequence,
        string $productRevision,
        ?array $variant,
        array $allowedValues,
        ?string $reason = null,
        array $allowedDetails = []
    ): array {
        return [
            'state'            => $state,
            'request_sequence' => $requestSequence,
            'product_revision' => $productRevision,
            'variant'          => $variant,
            'allowed_values'   => $allowedValues,
            'allowed_details'  => $allowedDetails,
            'reason'           => $reason,
        ];
    }

    /**
     * Retrieve logged conflict events (e.g. duplicate overlapping Any).
     */
    public function getConflictAuditLog(): array
    {
        return $this->conflictAuditLog;
    }

    /**
     * Clear logged conflicts.
     */
    public function clearConflictAuditLog(): void
    {
        $this->conflictAuditLog = [];
    }
}
