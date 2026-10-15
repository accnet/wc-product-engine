<?php

namespace WPE\Application\Variant;

use RuntimeException;
use InvalidArgumentException;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Infrastructure\Database\MediaRepository;
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
    private AssetService $assetService;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;
    private string $tableInventory;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableAssets;

    private array $conflictAuditLog = [];

    public function __construct(
        ?\wpdb $wpdb = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->assetService = $assetService ?? new AssetService($this->wpdb, new MediaRepository($this->wpdb));

        $prefix = $this->wpdb->prefix;
        $this->tableProducts      = $prefix . 'wpe_products';
        $this->tableVariants      = $prefix . 'wpe_variants';
        $this->tableOptions       = $prefix . 'wpe_options';
        $this->tableOptionValues  = $prefix . 'wpe_option_values';
        $this->tableVariantValues = $prefix . 'wpe_variant_values';
        $this->tableInventory     = $prefix . 'wpe_inventory';
        $this->tableProductMedia  = $prefix . 'wpe_product_media';
        $this->tableVariantMedia  = $prefix . 'wpe_variant_media';
        $this->tableAssets        = $prefix . 'wpe_assets';
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
        int $requestSequence = 0,
        ?array $preloadedProduct = null,
        ?array $preloadedOptions = null
    ): array {
        // 1. Fetch parent product info (or reuse preloaded row)
        if ($preloadedProduct !== null) {
            $product = $preloadedProduct;
        } else {
            $sqlProd = "SELECT id, type, status, version FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL LIMIT 1";
            $product = $this->wpdb->get_row($this->wpdb->prepare($sqlProd, $productId), ARRAY_A);
        }

        if (!$product) {
            return $this->buildResponse('none', $requestSequence, '0', null, [], 'invalid_product');
        }

        $currentRevision = (string)($product['version'] ?? '1');

        if ($product['type'] !== 'variable') {
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, [], 'not_variable_product');
        }

        // 2. Fetch product variation options (or reuse preloaded options)
        if ($preloadedOptions !== null) {
            $options = $preloadedOptions;
        } else {
            $sqlOpts = "SELECT id, `key`, `name`, `position` FROM {$this->tableOptions}
                        WHERE product_id = %d AND used_for_variation = 1
                        ORDER BY `position` ASC, id ASC";
            $options = $this->wpdb->get_results($this->wpdb->prepare($sqlOpts, $productId), ARRAY_A);
        }

        if (empty($options)) {
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, [], 'no_options');
        }

        // Batch-load option values once per resolve() call
        $allOptValues = $this->loadOptionValuesMap($options);

        // 3. Bounded Candidate Query: Fetch published variants, attribute values, and parent inventory
        $candidateData = $this->getCandidateVariants($productId, true);
        $variants = $candidateData['variants']; // [variant_id => row]
        $variantValues = $candidateData['variant_values']; // [variant_id => [option_id => ['value_id' => ..., 'is_any' => ...]]]
        $parentInv = $this->getParentInventory($productId);

        $lowestInfo = $this->resolveLowestPurchasableVariation($productId, $variants, $parentInv);
        $lowestPriceHtml = $lowestInfo['price_html'] ?? '';

        // Preload media once for thumbnails, partial candidate preview, and winning variation DTO
        $mediaBundle = $this->loadProductAndVariantMedia($productId, $variants);

        // 4. Normalize selection
        $normResult = $this->normalizeSelection($productId, $selection, $options, $allOptValues);
        if (!$normResult['valid']) {
            $initialAllowed = $this->computeAllowedValues($productId, array_keys($variants), $options, $variantValues, [], $variants, $parentInv, $quantity, $allOptValues);
            $initialThumbs = $this->resolveValueThumbnails($productId, [], $options, $variants, $variantValues, $initialAllowed['allowed_details'], $allOptValues, $mediaBundle);
            return $this->buildResponse(
                'none',
                $requestSequence,
                $currentRevision,
                null,
                $initialAllowed['allowed_values'],
                $normResult['reason'],
                $initialAllowed['allowed_details'],
                $initialThumbs,
                $lowestPriceHtml
            );
        }
        $normalizedSelection = $normResult['selection']; // [option_id => value_id]

        if (empty($variants)) {
            $emptyAllowed = [];
            $emptyDetails = [];
            foreach ($options as $opt) {
                $emptyAllowed[(string)$opt['id']] = [];
                $emptyDetails[(string)$opt['id']] = [];
            }
            $emptyThumbs = $this->resolveValueThumbnails($productId, $normalizedSelection, $options, [], [], $emptyDetails, $allOptValues, $mediaBundle);
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, $emptyAllowed, 'no_variations_exist', $emptyDetails, $emptyThumbs, '');
        }

        // 5. Match candidate variants against selection
        $matchingVariantIds = [];
        foreach ($variants as $varId => $var) {
            if ($this->doesVariantMatchSelection($varId, $normalizedSelection, $variantValues)) {
                $matchingVariantIds[] = $varId;
            }
        }

        // 6. Compute Allowed Values and Candidate Value Thumbnails
        $allowedResult = $this->computeAllowedValues(
            $productId,
            $matchingVariantIds,
            $options,
            $variantValues,
            $normalizedSelection,
            $variants,
            $parentInv,
            $quantity,
            $allOptValues
        );
        $allowedValues = $allowedResult['allowed_values'];
        $allowedDetails = $allowedResult['allowed_details'];
        $valueThumbnails = $this->resolveValueThumbnails(
            $productId,
            $normalizedSelection,
            $options,
            $variants,
            $variantValues,
            $allowedDetails,
            $allOptValues,
            $mediaBundle
        );

        // 7. Determine State: None, Partial, Exact, or Ambiguous
        if (empty($matchingVariantIds)) {
            return $this->buildResponse('none', $requestSequence, $currentRevision, null, $allowedValues, 'no_matching_variation', $allowedDetails, $valueThumbnails, $lowestPriceHtml);
        }

        $allOptionsSelected = (count($normalizedSelection) === count($options));

        if (!$allOptionsSelected) {
            // Partial selection: valid selection so far, awaiting remaining options
            $candidateImage = !empty($normalizedSelection)
                ? $this->resolveCandidatePreviewImage($matchingVariantIds, $mediaBundle['variant_primary_media'])
                : null;
            return $this->buildResponse(
                'partial',
                $requestSequence,
                $currentRevision,
                null,
                $allowedValues,
                null,
                $allowedDetails,
                $valueThumbnails,
                $lowestPriceHtml,
                $candidateImage
            );
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
                return $this->buildResponse('ambiguous', $requestSequence, $currentRevision, null, $allowedValues, 'ambiguous_match', $allowedDetails, $valueThumbnails, $lowestPriceHtml);
            }
            $winningVariant = $precedenceResult['variant'];
        }

        // 8. Hydrate Variant DTO with Price, Stock, Availability and Media (R101, AC-T25-01)
        $variantDto = $this->buildVariantDto($productId, $winningVariant, $quantity, $parentInv, $mediaBundle);

        return $this->buildResponse('exact', $requestSequence, $currentRevision, $variantDto, $allowedValues, $variantDto['reason'], $allowedDetails, $valueThumbnails, $lowestPriceHtml);
    }

    /**
     * Load option values grouped by option_id once per resolve() call, reusing preloaded values when available.
     *
     * @param array $options
     * @return array<int, array<int, array>>
     */
    private function loadOptionValuesMap(array $options): array
    {
        $allOptValues = [];
        $missingOptIds = [];

        foreach ($options as $opt) {
            $optId = (int)$opt['id'];
            if (isset($opt['values']) && is_array($opt['values'])) {
                $allOptValues[$optId] = $opt['values'];
            } else {
                $missingOptIds[] = $optId;
            }
        }

        if (!empty($missingOptIds)) {
            $idList = implode(',', array_map('intval', $missingOptIds));
            $sql = "SELECT id, option_id, value_key, label FROM {$this->tableOptionValues} WHERE option_id IN ({$idList}) ORDER BY position ASC, id ASC";
            $valRows = $this->wpdb->get_results($sql, ARRAY_A);
            if (is_array($valRows)) {
                foreach ($valRows as $vr) {
                    $allOptValues[(int)$vr['option_id']][] = $vr;
                }
            }
        }

        return $allOptValues;
    }

    /**
     * Batch-load primary media per variation and parent featured media once per resolve() call.
     */
    private function loadProductAndVariantMedia(int $productId, array $variants): array
    {
        $variantPrimaryMedia = [];
        if (!empty($variants)) {
            $varIdList = implode(',', array_map('intval', array_keys($variants)));
            $sqlVarMedia = "SELECT vm.variant_id, vm.asset_id, vm.is_primary, vm.position, vm.alt_override,
                                   a.source_url, a.provider, a.width, a.height, a.alt_text, a.title, a.health
                            FROM {$this->tableVariantMedia} vm
                            JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                            WHERE vm.variant_id IN ({$varIdList}) AND a.health != 'broken'
                            ORDER BY vm.variant_id ASC, vm.is_primary DESC, vm.position ASC, vm.asset_id ASC";
            $mediaRows = $this->wpdb->get_results($sqlVarMedia, ARRAY_A);
            if (is_array($mediaRows)) {
                foreach ($mediaRows as $mRow) {
                    $vId = (int)$mRow['variant_id'];
                    if (!isset($variantPrimaryMedia[$vId])) {
                        $variantPrimaryMedia[$vId] = $mRow;
                    }
                }
            }
        }

        $sqlParentMedia = "SELECT pm.asset_id, pm.alt_override,
                                  a.source_url, a.provider, a.width, a.height, a.alt_text, a.title, a.health
                           FROM {$this->tableProductMedia} pm
                           JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                           WHERE pm.product_id = %d AND pm.role = 'featured' AND a.health != 'broken'
                           ORDER BY pm.position ASC
                           LIMIT 1";
        $parentFeaturedRow = $this->wpdb->get_row($this->wpdb->prepare($sqlParentMedia, $productId), ARRAY_A);

        return [
            'variant_primary_media' => $variantPrimaryMedia,
            'parent_featured'       => is_array($parentFeaturedRow) ? $parentFeaturedRow : null,
        ];
    }

    /**
     * Normalize client selection into canonical [option_id => value_id] format.
     * Rejects unknown options or values belonging to other products/options (R102).
     */
    public function normalizeSelection(int $productId, array $rawSelection, array $options, ?array $allOptValues = null): array
    {
        $normalized = [];
        $optionMap = [];
        foreach ($options as $opt) {
            $optionMap[(int)$opt['id']] = $opt;
            $optionMap[strtolower((string)$opt['key'])] = $opt;
            $optionMap[strtolower((string)$opt['name'])] = $opt;
        }

        if ($allOptValues === null && !empty($rawSelection)) {
            $allOptValues = $this->loadOptionValuesMap($options);
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
            $optVals = $allOptValues[$optId] ?? [];

            // Match option value in memory from preloaded option values
            $valId = null;
            if (is_numeric($rawValue)) {
                $targetId = (int)$rawValue;
                foreach ($optVals as $vRow) {
                    if ((int)$vRow['id'] === $targetId) {
                        $valId = $targetId;
                        break;
                    }
                }
            } else {
                $cleanVal = trim((string)$rawValue);
                $cleanLower = function_exists('mb_strtolower') ? mb_strtolower($cleanVal) : strtolower($cleanVal);
                foreach ($optVals as $vRow) {
                    $vk = (string)($vRow['value_key'] ?? '');
                    $lbl = (string)($vRow['label'] ?? '');
                    $vkLower = function_exists('mb_strtolower') ? mb_strtolower($vk) : strtolower($vk);
                    $lblLower = function_exists('mb_strtolower') ? mb_strtolower($lbl) : strtolower($lbl);
                    if ($vk === $cleanVal || $lbl === $cleanVal || $vkLower === $cleanLower || $lblLower === $cleanLower) {
                        $valId = (int)$vRow['id'];
                        break;
                    }
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
     * Bounded query fetching active variants and optionally their attribute values for a product.
     */
    public function getCandidateVariants(int $productId, bool $includeValues = true): array
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

        if (!$includeValues) {
            return ['variants' => $variants, 'variant_values' => []];
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
     * Compute allowed values and details for options based on compatible variations and stock/price availability (R99).
     */
    public function computeAllowedValues(
        int $productId,
        array $candidateVariantIds,
        array $options,
        array $variantValues = [],
        ?array $normalizedSelection = null,
        ?array $variants = null,
        ?array $parentInv = null,
        int $requestedQty = 1,
        ?array $allOptValues = null
    ): array {
        $allowedValues = [];
        $allowedDetails = [];

        if ($variants === null) {
            $candidateData = $this->getCandidateVariants($productId);
            $variants = $candidateData['variants'];
            if (empty($variantValues)) {
                $variantValues = $candidateData['variant_values'];
            }
        }

        if ($parentInv === null) {
            $parentInv = $this->getParentInventory($productId);
        }

        // Pre-evaluate availability and purchasability for all active variations of this product
        $variantEvalMap = [];
        foreach ($variants as $vId => $vRow) {
            $variantEvalMap[(int)$vId] = $this->evaluateVariantAvailability($productId, $vRow, $parentInv, $requestedQty);
        }

        // All existing option values for this product
        if ($allOptValues === null) {
            $allOptValues = $this->loadOptionValuesMap($options);
        }

        foreach ($options as $opt) {
            $optId = (int)$opt['id'];
            $optIdStr = (string)$optId;
            $allowedValues[$optIdStr] = [];
            $allowedDetails[$optIdStr] = [];

            $valuesForThisOpt = $allOptValues[$optId] ?? [];

            foreach ($valuesForThisOpt as $vRow) {
                $vId = (int)$vRow['id'];
                $vIdStr = (string)$vId;

                $matchingForVal = [];
                if ($normalizedSelection !== null) {
                    $candidateSelection = $normalizedSelection;
                    $candidateSelection[$optId] = $vId;
                    foreach ($variants as $varId => $varRow) {
                        if ($this->doesVariantMatchSelection((int)$varId, $candidateSelection, $variantValues)) {
                            $matchingForVal[] = (int)$varId;
                        }
                    }
                } else {
                    $poolIds = !empty($candidateVariantIds) ? $candidateVariantIds : array_keys($variants);
                    foreach ($poolIds as $varId) {
                        if ($this->doesVariantMatchSelection((int)$varId, [$optId => $vId], $variantValues)) {
                            $matchingForVal[] = (int)$varId;
                        }
                    }
                }

                $exists = !empty($matchingForVal);
                $inStock = false;
                $purchasable = false;

                foreach ($matchingForVal as $mVarId) {
                    $ev = $variantEvalMap[$mVarId] ?? null;
                    if ($ev) {
                        if ($ev['available']) {
                            $inStock = true;
                        }
                        if ($ev['purchasable']) {
                            $purchasable = true;
                        }
                    }
                }

                $allowedDetails[$optIdStr][$vIdStr] = [
                    'exists'      => $exists,
                    'purchasable' => $purchasable,
                    'in_stock'    => $inStock,
                ];

                if ($purchasable) {
                    $allowedValues[$optIdStr][] = $vIdStr;
                }
            }
        }

        return [
            'allowed_values'  => $allowedValues,
            'allowed_details' => $allowedDetails,
        ];
    }

    /**
     * Resolve candidate image swatch thumbnails per option value using the 5-step resolution algorithm.
     *
     * For each option O_i and value v:
     * 1. Form candidate selection C_v = (normalizedSelection \ {O_i}) U {O_i => v}.
     * 2. Keep currently selected values of other options.
     * 3. Find active variations matching C_v in saved position ASC, id ASC order.
     * 4. Take the first valid own variation image; if matching variations lack own images, fall back to parent featured image then placeholder.
     * 5. If no combination matches C_v, mark unavailable and keep representative thumbnail for {O_i => v} alone (or fallback) so the swatch never disappears.
     */
    public function resolveValueThumbnails(
        int $productId,
        array $normalizedSelection,
        array $options,
        array $variants,
        array $variantValues,
        array $allowedDetails = [],
        ?array $allOptValues = null,
        ?array $mediaBundle = null
    ): array {
        $thumbnails = [];
        $optIds = array_column($options, 'id');
        if (empty($optIds)) {
            return $thumbnails;
        }

        if ($allOptValues === null) {
            $allOptValues = $this->loadOptionValuesMap($options);
        }

        if ($mediaBundle === null) {
            $mediaBundle = $this->loadProductAndVariantMedia($productId, $variants);
        }
        $variantPrimaryMedia = $mediaBundle['variant_primary_media'] ?? [];
        $parentFeaturedRow   = $mediaBundle['parent_featured'] ?? null;

        foreach ($options as $opt) {
            $optId = (int)$opt['id'];
            $optIdStr = (string)$optId;
            $thumbnails[$optIdStr] = [];

            $valuesForOpt = $allOptValues[$optId] ?? [];
            foreach ($valuesForOpt as $vRow) {
                $vId = (int)$vRow['id'];
                $vIdStr = (string)$vId;
                $vLabel = (string)$vRow['label'];

                // Step 1 & 2: Candidate selection with value v while keeping other selected options
                $candidateSelection = $normalizedSelection;
                $candidateSelection[$optId] = $vId;

                // Step 3: Find matching active variations in saved position ASC, id ASC order
                $matchingVars = [];
                foreach ($variants as $varId => $varRow) {
                    if ($this->doesVariantMatchSelection((int)$varId, $candidateSelection, $variantValues)) {
                        $matchingVars[] = (int)$varId;
                    }
                }

                $detail = $allowedDetails[$optIdStr][$vIdStr] ?? [
                    'exists'      => !empty($matchingVars),
                    'purchasable' => !empty($matchingVars),
                    'in_stock'    => !empty($matchingVars),
                ];

                $chosenMedia = null;
                $sourceType = 'placeholder';

                if (!empty($matchingVars)) {
                    // Step 4: Take first valid own variation image in matching group
                    foreach ($matchingVars as $mVarId) {
                        if (isset($variantPrimaryMedia[$mVarId])) {
                            $chosenMedia = $variantPrimaryMedia[$mVarId];
                            $sourceType = 'variation';
                            break;
                        }
                    }

                    // Step 5 fallback for matching group: parent featured image -> placeholder
                    if ($chosenMedia === null && $parentFeaturedRow) {
                        $chosenMedia = $parentFeaturedRow;
                        $sourceType = 'product_featured';
                    }
                } else {
                    // No combination matches C_v: keep representative thumbnail of value v alone (or fallback)
                    foreach ($variants as $varId => $varRow) {
                        if ($this->doesVariantMatchSelection((int)$varId, [$optId => $vId], $variantValues)) {
                            if (isset($variantPrimaryMedia[(int)$varId])) {
                                $chosenMedia = $variantPrimaryMedia[(int)$varId];
                                $sourceType = 'representative';
                                break;
                            }
                        }
                    }

                    if ($chosenMedia === null && $parentFeaturedRow) {
                        $chosenMedia = $parentFeaturedRow;
                        $sourceType = 'product_featured';
                    }
                }

                if ($chosenMedia !== null) {
                    $provider = $this->assetService->getProvider($chosenMedia['provider'] ?? 'default');
                    $origW = isset($chosenMedia['width']) ? (int)$chosenMedia['width'] : null;
                    $origH = isset($chosenMedia['height']) ? (int)$chosenMedia['height'] : null;
                    $thumbRend = $provider->getRendition((string)$chosenMedia['source_url'], 'woocommerce_gallery_thumbnail', $origW, $origH);
                    $singleRend = $provider->getRendition((string)$chosenMedia['source_url'], 'woocommerce_single', $origW, $origH);
                    $thumbUrl = (string)$thumbRend['url'];
                    if (str_contains($thumbUrl, 'cdn.shopify.com/') && !str_contains($thumbUrl, 'width=')) {
                        $thumbUrl = (string)add_query_arg('width', '160', $thumbUrl);
                    }
                    $alt = !empty($chosenMedia['alt_override'])
                        ? (string)$chosenMedia['alt_override']
                        : (!empty($chosenMedia['alt_text']) ? (string)$chosenMedia['alt_text'] : $vLabel);

                    $thumbnails[$optIdStr][$vIdStr] = [
                        'src'       => $thumbUrl,
                        'full_src'  => (string)$singleRend['url'],
                        'alt'       => $alt,
                        'source'    => $sourceType,
                        'available' => (bool)$detail['purchasable'],
                        'exists'    => (bool)$detail['exists'],
                    ];
                } else {
                    $placeholderThumb = $this->assetService->getPlaceholderDataUri(100, 100);
                    $placeholderSingle = $this->assetService->getPlaceholderDataUri(600, 600);
                    $thumbnails[$optIdStr][$vIdStr] = [
                        'src'       => $placeholderThumb,
                        'full_src'  => $placeholderSingle,
                        'alt'       => $vLabel,
                        'source'    => 'placeholder',
                        'available' => (bool)$detail['purchasable'],
                        'exists'    => (bool)$detail['exists'],
                    ];
                }
            }
        }

        return $thumbnails;
    }

    /**
     * Resolve the lowest-priced valid purchasable variation for a variable product.
     * Ties broken deterministically by saved variation position ASC, id ASC.
     * Returns null when no valid purchasable variation with a price exists (never returns fake 0).
     */
    public function resolveLowestPurchasableVariation(
        int $productId,
        ?array $variants = null,
        ?array $parentInv = null
    ): ?array {
        if ($variants === null) {
            $candidateData = $this->getCandidateVariants($productId, false);
            $variants = $candidateData['variants'];
        }
        if (empty($variants)) {
            return null;
        }

        if ($parentInv === null) {
            $parentInv = $this->getParentInventory($productId);
        }

        $lowestVariant = null;
        $lowestEval = null;
        $lowestActivePrice = null;

        foreach ($variants as $varId => $variant) {
            $eval = $this->evaluateVariantAvailability($productId, $variant, $parentInv, 1);
            if (!$eval['purchasable']) {
                continue;
            }

            $activePrice = (float)$eval['resolved_price']['active_price'];
            if ($lowestVariant === null || $activePrice < $lowestActivePrice) {
                $lowestVariant = $variant;
                $lowestEval = $eval;
                $lowestActivePrice = $activePrice;
            }
        }

        if ($lowestVariant === null || $lowestEval === null) {
            return null;
        }

        $resolvedPrice = $lowestEval['resolved_price'];

        return [
            'variant_id'    => (string)$lowestVariant['id'],
            'active_price'  => $resolvedPrice['active_price'],
            'regular_price' => $resolvedPrice['regular'],
            'sale_price'    => $resolvedPrice['sale'],
            'sale_active'   => (bool)$resolvedPrice['sale_active'],
            'on_sale'       => (bool)$resolvedPrice['on_sale'],
            'price_html'    => $this->formatVariationPriceHtml($productId, $lowestVariant, $resolvedPrice),
        ];
    }

    /**
     * Evaluate canonical price, stock inheritance, backorders, and purchasability for a single variation row.
     */
    public function evaluateVariantAvailability(
        int $productId,
        array $variant,
        ?array $parentInv = null,
        int $requestedQty = 1
    ): array {
        $variantId = (int)$variant['id'];
        $resolvedPrice = EffectivePriceResolver::resolve($variant);
        $isMissingPrice = $resolvedPrice['state'] !== EffectivePriceResolver::STATE_PRICED;

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
            $stockOwnerId = $productId;
            if ($parentInv === null) {
                $parentInv = $this->getParentInventory($productId);
            }
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

        return [
            'resolved_price'  => $resolvedPrice,
            'is_missing_price'=> $isMissingPrice,
            'stock_owner_id'  => $stockOwnerId,
            'stock_status'    => $stockStatus,
            'quantity'        => $quantity,
            'backorders'      => $backorders,
            'available'       => $available,
            'purchasable'     => $purchasable,
            'reason'          => $reason,
        ];
    }

    /**
     * Format full HTML price for a single variation (keeping regular and sale price from the same source variation,
     * preserving WooCommerce currency formatting, tax display, and price suffix).
     * Never returns fake 0 when unpriced.
     */
    public function formatVariationPriceHtml(int $productId, array $variant, ?array $resolvedPrice = null): string
    {
        $resolvedPrice = $resolvedPrice ?? EffectivePriceResolver::resolve($variant);
        if (($resolvedPrice['state'] ?? '') !== EffectivePriceResolver::STATE_PRICED || $resolvedPrice['active_price'] === null) {
            return '';
        }

        $activePrice = (float)$resolvedPrice['active_price'];
        $regularPrice = $resolvedPrice['regular'] !== null ? (float)$resolvedPrice['regular'] : $activePrice;
        $onSale = (bool)($resolvedPrice['on_sale'] ?? false);

        $displayActive = $activePrice;
        $displayRegular = $regularPrice;
        $suffix = '';

        if (function_exists('wc_get_product')) {
            $wcProduct = $productId > 0 ? wc_get_product($productId) : null;
            if (!$wcProduct instanceof \WC_Product) {
                $wcProduct = wc_get_product((int)$variant['id']);
            }
            if ($wcProduct instanceof \WC_Product) {
                if (function_exists('wc_get_price_to_display')) {
                    $displayActive = (float)wc_get_price_to_display($wcProduct, ['price' => $activePrice]);
                    $displayRegular = (float)wc_get_price_to_display($wcProduct, ['price' => $regularPrice]);
                }
                $suffix = (string)$wcProduct->get_price_suffix((string)$activePrice, 1);
            }
        }

        if ($onSale && $displayActive < $displayRegular) {
            if (function_exists('wc_format_sale_price')) {
                $innerHtml = wc_format_sale_price($displayRegular, $displayActive) . $suffix;
            } elseif (function_exists('wc_price')) {
                $innerHtml = '<del aria-hidden="true">' . wc_price($displayRegular) . '</del> <ins>' . wc_price($displayActive) . '</ins>' . $suffix;
            } else {
                $innerHtml = '<del>$' . number_format($displayRegular, 2, '.', '') . '</del> <ins>$' . number_format($displayActive, 2, '.', '') . '</ins>' . $suffix;
            }
        } else {
            if (function_exists('wc_price')) {
                $innerHtml = wc_price($displayActive) . $suffix;
            } else {
                $innerHtml = '<span class="woocommerce-Price-amount amount">$' . number_format($displayActive, 2, '.', '') . '</span>' . $suffix;
            }
        }

        return '<span class="price">' . $innerHtml . '</span>';
    }

    /**
     * Fetch parent inventory row once per resolve call.
     */
    private function getParentInventory(int $productId): ?array
    {
        $sqlParentInv = "SELECT manage_stock, quantity, stock_status, backorders FROM {$this->tableInventory} WHERE owner_id = %d LIMIT 1";
        $row = $this->wpdb->get_row($this->wpdb->prepare($sqlParentInv, $productId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Build the resolved variant DTO calculating canonical price, sale schedule, and inherited stock (R101, AC-T25-01).
     */
    private function buildVariantDto(
        int $productId,
        array $variant,
        int $requestedQty = 1,
        ?array $parentInv = null,
        ?array $mediaBundle = null
    ): array {
        $variantId = (int)$variant['id'];
        $eval = $this->evaluateVariantAvailability($productId, $variant, $parentInv, $requestedQty);
        $resolvedPrice = $eval['resolved_price'];

        $priceStr = null;
        $displayPriceStr = null;
        $priceHtml = '';

        if (!$eval['is_missing_price']) {
            $priceStr = $resolvedPrice['active_price'];
            $displayPriceStr = number_format((float)$priceStr, 2, '.', '');
            $priceHtml = $this->formatVariationPriceHtml($productId, $variant, $resolvedPrice);
        }

        // Media Asset ID & Image DTO (Variant primary or parent featured fallback)
        if ($mediaBundle !== null) {
            $mediaRow = $mediaBundle['variant_primary_media'][$variantId] ?? null;
            if (!$mediaRow && !empty($mediaBundle['parent_featured'])) {
                $mediaRow = $mediaBundle['parent_featured'];
                $mediaRow['alt_override'] = null;
            }
        } else {
            $sqlMedia = "SELECT vm.asset_id, vm.alt_override, a.source_url, a.provider, a.width, a.height, a.alt_text, a.title
                         FROM {$this->tableVariantMedia} vm
                         JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                         WHERE vm.variant_id = %d AND a.health != 'broken'
                         ORDER BY vm.is_primary DESC, vm.position ASC, vm.asset_id ASC
                         LIMIT 1";
            $mediaRow = $this->wpdb->get_row($this->wpdb->prepare($sqlMedia, $variantId), ARRAY_A);

            if (!$mediaRow) {
                $sqlParentMedia = "SELECT pm.asset_id, NULL AS alt_override, a.source_url, a.provider, a.width, a.height, a.alt_text, a.title
                                   FROM {$this->tableProductMedia} pm
                                   JOIN {$this->tableAssets} a ON a.id = pm.asset_id
                                   WHERE pm.product_id = %d AND pm.role = 'featured' AND a.health != 'broken'
                                   ORDER BY pm.position ASC
                                   LIMIT 1";
                $mediaRow = $this->wpdb->get_row($this->wpdb->prepare($sqlParentMedia, $productId), ARRAY_A);
            }
        }

        $assetId = $mediaRow ? (int)$mediaRow['asset_id'] : null;
        $imageDto = ($mediaRow && !empty($mediaRow['source_url']))
            ? $this->formatImageDtoFromMediaRow($mediaRow)
            : null;

        return [
            'id'             => (string)$variantId,
            'variation_id'   => $variantId,
            'sku'            => (string)($variant['sku'] ?? ''),
            'description'    => (string)($variant['description'] ?? ''),
            'price'          => $priceStr,
            'regular_price'  => $resolvedPrice['regular'],
            'sale_price'     => $resolvedPrice['sale'],
            'sale_active'    => (bool)$resolvedPrice['sale_active'],
            'display_price'  => $displayPriceStr,
            'price_html'     => $priceHtml,
            'stock_owner_id' => (string)$eval['stock_owner_id'],
            'available'      => $eval['available'],
            'purchasable'    => $eval['purchasable'],
            'is_purchasable' => $eval['purchasable'],
            'is_in_stock'    => $eval['available'],
            'media_asset_id' => $assetId ? (string)$assetId : null,
            'image'          => $imageDto,
            'reason'         => $eval['reason'],
        ];
    }

    /**
     * Resolve the first valid own variation image among matching candidate variations for partial selection preview.
     */
    private function resolveCandidatePreviewImage(array $matchingVariantIds, ?array $variantPrimaryMedia = null): ?array
    {
        if (empty($matchingVariantIds)) {
            return null;
        }

        if ($variantPrimaryMedia !== null) {
            foreach ($matchingVariantIds as $varId) {
                $vId = (int)$varId;
                if (isset($variantPrimaryMedia[$vId]) && !empty($variantPrimaryMedia[$vId]['source_url'])) {
                    return $this->formatImageDtoFromMediaRow($variantPrimaryMedia[$vId]);
                }
            }
            return null;
        }

        $cleanIds = array_values(array_map('intval', $matchingVariantIds));
        $idList = implode(',', $cleanIds);
        $sql = "SELECT vm.variant_id, vm.asset_id, vm.alt_override,
                       a.source_url, a.provider, a.width, a.height, a.alt_text, a.title
                FROM {$this->tableVariantMedia} vm
                JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                WHERE vm.variant_id IN ({$idList}) AND a.health != 'broken'
                ORDER BY FIELD(vm.variant_id, {$idList}), vm.is_primary DESC, vm.position ASC, vm.asset_id ASC
                LIMIT 1";
        $mediaRow = $this->wpdb->get_row($sql, ARRAY_A);
        if (!$mediaRow || empty($mediaRow['source_url'])) {
            return null;
        }

        return $this->formatImageDtoFromMediaRow($mediaRow);
    }

    /**
     * Format a standardized image DTO from a wpe_variant_media / wpe_product_media joined row.
     */
    private function formatImageDtoFromMediaRow(array $mediaRow): array
    {
        $assetId = (int)$mediaRow['asset_id'];
        $sourceUrl = (string)$mediaRow['source_url'];
        $origW = isset($mediaRow['width']) && (int)$mediaRow['width'] > 0 ? (int)$mediaRow['width'] : 600;
        $origH = isset($mediaRow['height']) && (int)$mediaRow['height'] > 0 ? (int)$mediaRow['height'] : 600;

        $provider = $this->assetService->getProvider($mediaRow['provider'] ?? 'default');
        $singleRend = $provider->getRendition($sourceUrl, 'woocommerce_single', $origW, $origH);
        $fullRend   = $provider->getRendition($sourceUrl, 'full', $origW, $origH);
        $thumbRend  = $provider->getRendition($sourceUrl, 'woocommerce_gallery_thumbnail', $origW, $origH);

        $alt = trim((string)($mediaRow['alt_override'] ?? ''));
        if ($alt === '') {
            $alt = trim((string)($mediaRow['alt_text'] ?? ($mediaRow['title'] ?? '')));
        }

        return [
            'asset_id'              => $assetId,
            'src'                   => (string)$singleRend['url'],
            'full_src'              => (string)$fullRend['url'],
            'thumb_src'             => (string)$thumbRend['url'],
            'gallery_thumbnail_src' => (string)$thumbRend['url'],
            'alt'                   => $alt,
            'title'                 => (string)($mediaRow['title'] ?? ''),
            'width'                 => (int)($singleRend['width'] ?: $origW),
            'height'                => (int)($singleRend['height'] ?: $origH),
            'full_src_w'            => (int)($fullRend['width'] ?: $origW),
            'full_src_h'            => (int)($fullRend['height'] ?: $origH),
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
        array $allowedDetails = [],
        array $valueThumbnails = [],
        string $lowestPriceHtml = '',
        ?array $candidateImage = null
    ): array {
        return [
            'state'             => $state,
            'request_sequence'  => $requestSequence,
            'product_revision'  => $productRevision,
            'variant'           => $variant,
            'candidate_image'   => $candidateImage,
            'allowed_values'    => $allowedValues,
            'allowed_details'   => $allowedDetails,
            'value_thumbnails'  => $valueThumbnails,
            'lowest_price_html' => $lowestPriceHtml,
            'reason'            => $reason,
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
