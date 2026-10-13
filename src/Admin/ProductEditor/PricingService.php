<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use DateTimeImmutable;
use DateTimeZone;
use wpdb;
use WPE\Application\Variant\EffectivePriceResolver;
use WPE\Infrastructure\Database\OptionsRepository;

/**
 * Common price, per-variation pricing mode, materialization and reference price of the product editor.
 *
 * Storage (docs/03 §1): the common price lives in `products.extra_props.common_price` and the mode of each
 * variation in `variants.extra_props.pricing_mode` (`shared` or `own`; absent means `own`). The effective values of
 * a shared variation are written into its own price columns by the save transaction (this class only plans and
 * validates; ProductEditorService is the one writer), so every reader and WooCommerce adapter keeps reading
 * variation columns and the shared effective-price rule in EffectivePriceResolver.
 *
 * The reference price is computed on read from the first non-deleted variation by saved `(position, id)` over the
 * whole set. It is never stored, never copied to another variation and never used as a transaction price.
 */
final class PricingService
{
    private wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private string $tableProducts;
    private string $tableVariants;

    public function __construct(?wpdb $wpdb = null, ?OptionsRepository $optionsRepo = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $prefix = $this->wpdb->prefix;
        $this->tableProducts = "{$prefix}wpe_products";
        $this->tableVariants = "{$prefix}wpe_variants";
    }

    /* ---------------------------------------------------------------------- */
    /* Value normalization                                                    */
    /* ---------------------------------------------------------------------- */

    /**
     * Normalize an amount to the contract string (six decimals) or null for empty input.
     *
     * Empty input (null, empty string) is "no price". Zero is a real price and stays "0.000000".
     *
     * @throws EditorValidationException
     */
    public static function normalizeAmount($value, string $pointer): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new EditorValidationException('validation_failed', 'Price must be a number.', [['pointer' => $pointer, 'reason' => 'invalid_amount']]);
        }
        $float = (float)$value;
        if ($float < 0 || $float >= 1.0E+13) {
            throw new EditorValidationException('validation_failed', 'Price must be zero or greater.', [['pointer' => $pointer, 'reason' => 'invalid_amount']]);
        }
        return number_format($float, 6, '.', '');
    }

    /**
     * Normalize a UTC instant (ISO 8601 or `Y-m-d H:i:s`; no zone means UTC) to `Y-m-d H:i:s` or null.
     *
     * @throws EditorValidationException
     */
    public static function normalizeInstant($value, string $pointer): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            throw new EditorValidationException('validation_failed', 'Date must be a timestamp string.', [['pointer' => $pointer, 'reason' => 'invalid_date']]);
        }
        try {
            $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new EditorValidationException('validation_failed', 'Date is not valid.', [['pointer' => $pointer, 'reason' => 'invalid_date']]);
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Validate and normalize a common price input. All empty (or null) means "no common price".
     *
     * @return array{regular:?string,sale:?string,from:?string,to:?string}|null
     * @throws EditorValidationException
     */
    public static function normalizeCommonPrice($input, string $pointer = '/common_price'): ?array
    {
        if ($input === null) {
            return null;
        }
        if (!is_array($input)) {
            throw new EditorValidationException('validation_failed', 'Common price must be an object.', [['pointer' => $pointer, 'reason' => 'invalid_type']]);
        }

        $regular = self::normalizeAmount($input['regular'] ?? ($input['regular_price'] ?? null), $pointer . '/regular');
        $sale    = self::normalizeAmount($input['sale'] ?? ($input['sale_price'] ?? null), $pointer . '/sale');
        $from    = self::normalizeInstant($input['from'] ?? ($input['sale_from'] ?? null), $pointer . '/from');
        $to      = self::normalizeInstant($input['to'] ?? ($input['sale_to'] ?? null), $pointer . '/to');

        if ($regular === null && $sale === null && $from === null && $to === null) {
            return null;
        }

        $errors = self::pricingRuleErrors($regular, $sale, $from, $to, $pointer, 'sale', 'from', 'to');
        if ($errors) {
            throw new EditorValidationException('validation_failed', 'Common price is not valid.', $errors);
        }

        return ['regular' => $regular, 'sale' => $sale, 'from' => $from, 'to' => $to];
    }

    /**
     * Rules shared by common and own prices: a sale needs a regular price and may not exceed it, a schedule needs a
     * sale price, and the window must be ordered.
     *
     * @return array<int,array{pointer:string,reason:string}>
     */
    public static function pricingRuleErrors(?string $regular, ?string $sale, ?string $from, ?string $to, string $base, string $saleKey, string $fromKey, string $toKey): array
    {
        $errors = [];
        if ($sale !== null && $regular === null) {
            $errors[] = ['pointer' => "{$base}/{$saleKey}", 'reason' => 'sale_requires_regular'];
        }
        if ($sale !== null && $regular !== null && (float)$sale > (float)$regular) {
            $errors[] = ['pointer' => "{$base}/{$saleKey}", 'reason' => 'sale_above_regular'];
        }
        if (($from !== null || $to !== null) && $sale === null) {
            $errors[] = ['pointer' => "{$base}/" . ($from !== null ? $fromKey : $toKey), 'reason' => 'schedule_requires_sale'];
        }
        if ($from !== null && $to !== null && strtotime($from . ' UTC') > strtotime($to . ' UTC')) {
            $errors[] = ['pointer' => "{$base}/{$toKey}", 'reason' => 'sale_window_invalid'];
        }
        return $errors;
    }

    /**
     * Common price stored in a product's extra props, or null.
     *
     * @param array|string|null $extraProps
     * @return array{regular:?string,sale:?string,from:?string,to:?string}|null
     */
    public static function readCommonPrice($extraProps): ?array
    {
        $extra = is_array($extraProps) ? $extraProps : (json_decode((string)$extraProps, true) ?: []);
        $c = $extra['common_price'] ?? null;
        if (!is_array($c)) {
            return null;
        }
        $out = [
            'regular' => isset($c['regular']) && $c['regular'] !== '' ? (string)$c['regular'] : null,
            'sale'    => isset($c['sale']) && $c['sale'] !== '' ? (string)$c['sale'] : null,
            'from'    => !empty($c['from']) ? (string)$c['from'] : null,
            'to'      => !empty($c['to']) ? (string)$c['to'] : null,
        ];
        return ($out['regular'] === null && $out['sale'] === null) ? null : $out;
    }

    /**
     * Return the product extra props with the common price set or removed, leaving every other key untouched.
     */
    public static function withCommonPrice(array $extra, ?array $common): array
    {
        if ($common === null) {
            unset($extra['common_price']);
        } else {
            $extra['common_price'] = $common;
        }
        return $extra;
    }

    /**
     * Price columns of a variation row that follows the common price.
     *
     * @return array{regular_price:?string,sale_price:?string,sale_from:?string,sale_to:?string}
     */
    public static function columnsFromCommon(array $common): array
    {
        return [
            'regular_price' => $common['regular'],
            'sale_price'    => $common['sale'],
            'sale_from'     => $common['from'],
            'sale_to'       => $common['to'],
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Save planning                                                          */
    /* ---------------------------------------------------------------------- */

    /**
     * Validate the pricing part of a save request and decide the columns the writer must store.
     *
     * Nothing is written here. The plan is:
     *  - `common_provided`: the request carries `common_price` (absent means unchanged);
     *  - `common`: the common price that is in force after the save;
     *  - `rows`: for every submitted variation index its `mode` and the price `columns` to store;
     *  - `materialize_existing`: the common price changed but the request has no variations, so the writer must
     *    refresh the existing shared variations itself.
     *
     * @return array{common_provided:bool,common:?array,rows:array<int,array{mode:string,columns:array}>,materialize_existing:bool}
     * @throws EditorValidationException
     */
    public function planSave(int $productId, array $productRow, array $payload): array
    {
        $stored = self::readCommonPrice($productRow['extra_props'] ?? null);
        $commonProvided = array_key_exists('common_price', $payload);
        $common = $commonProvided ? self::normalizeCommonPrice($payload['common_price']) : $stored;
        $removing = $commonProvided && $stored !== null && $common === null;

        $existing = $this->existingVariants($productId);
        $errors = [];
        $rows = [];
        $variationsProvided = isset($payload['variations']) && is_array($payload['variations']);

        if ($variationsProvided) {
            foreach ($payload['variations'] as $index => $varData) {
                $varData = is_array($varData) ? $varData : [];
                $varId = !empty($varData['id']) ? (int)$varData['id'] : 0;
                $row = ($varId > 0 && isset($existing[$varId])) ? $existing[$varId] : null;
                $base = '/variations/' . $index;

                $existingMode = $row ? EffectivePriceResolver::modeOf($row['extra_props']) : null;
                if (array_key_exists('pricing_mode', $varData)) {
                    $mode = $varData['pricing_mode'];
                    if ($mode !== EffectivePriceResolver::MODE_SHARED && $mode !== EffectivePriceResolver::MODE_OWN) {
                        $errors[] = ['pointer' => "{$base}/pricing_mode", 'reason' => 'invalid_pricing_mode'];
                        continue;
                    }
                } elseif ($existingMode !== null) {
                    $mode = $existingMode;
                } else {
                    // A new variation follows the common price only when one is configured.
                    $mode = $common !== null ? EffectivePriceResolver::MODE_SHARED : EffectivePriceResolver::MODE_OWN;
                }

                if ($mode === EffectivePriceResolver::MODE_SHARED) {
                    if ($common === null) {
                        $errors[] = ['pointer' => "{$base}/pricing_mode", 'reason' => $removing ? 'common_price_in_use' : 'shared_requires_common_price'];
                        continue;
                    }
                    $rows[$index] = ['mode' => $mode, 'columns' => self::columnsFromCommon($common)];
                    continue;
                }

                // Own mode: submitted keys win; absent keys keep the stored value so a partial request never
                // erases a price (this also keeps the materialized price when a shared variation becomes own).
                try {
                    $columns = $this->ownColumns($varData, $row, $base);
                } catch (EditorValidationException $e) {
                    $errors = array_merge($errors, $e->details());
                    continue;
                }

                if ($removing && $existingMode === EffectivePriceResolver::MODE_SHARED
                    && $columns['regular_price'] === null && $columns['sale_price'] === null
                    && empty($varData['confirm_unpriced'])) {
                    $errors[] = ['pointer' => "{$base}/regular_price", 'reason' => 'price_required'];
                    continue;
                }

                $rows[$index] = ['mode' => $mode, 'columns' => $columns];
            }
        } elseif ($removing) {
            foreach ($existing as $varId => $row) {
                if (EffectivePriceResolver::modeOf($row['extra_props']) === EffectivePriceResolver::MODE_SHARED) {
                    $errors[] = ['pointer' => '/common_price', 'reason' => 'common_price_in_use'];
                    break;
                }
            }
        }

        if ($errors) {
            $context = $removing ? ['preview' => $this->previewCommonPriceRemoval($productId)] : [];
            throw new EditorValidationException('validation_failed', 'The pricing data is not valid.', $errors, $context);
        }

        return [
            'common_provided'      => $commonProvided,
            'common'               => $common,
            'rows'                 => $rows,
            'materialize_existing' => $commonProvided && !$variationsProvided,
        ];
    }

    /**
     * Own price columns from a submitted variation, falling back to the stored row for absent keys.
     *
     * @return array{regular_price:?string,sale_price:?string,sale_from:?string,sale_to:?string}
     * @throws EditorValidationException
     */
    private function ownColumns(array $varData, ?array $row, string $base): array
    {
        $pick = static function (string $key) use ($varData, $row) {
            if (array_key_exists($key, $varData)) {
                return $varData[$key];
            }
            return $row[$key] ?? null;
        };

        $regular = self::normalizeAmount($pick('regular_price'), "{$base}/regular_price");
        $sale    = self::normalizeAmount($pick('sale_price'), "{$base}/sale_price");
        $from    = self::normalizeInstant($pick('sale_from'), "{$base}/sale_from");
        $to      = self::normalizeInstant($pick('sale_to'), "{$base}/sale_to");

        $errors = self::pricingRuleErrors($regular, $sale, $from, $to, $base, 'sale_price', 'sale_from', 'sale_to');
        if ($errors) {
            throw new EditorValidationException('validation_failed', 'The variation price is not valid.', $errors);
        }

        return ['regular_price' => $regular, 'sale_price' => $sale, 'sale_from' => $from, 'sale_to' => $to];
    }

    /**
     * Extra props of a variation with the pricing mode set, keeping every other key.
     */
    public static function withPricingMode($extraProps, string $mode): array
    {
        $extra = is_array($extraProps) ? $extraProps : (json_decode((string)$extraProps, true) ?: []);
        $extra['pricing_mode'] = $mode === EffectivePriceResolver::MODE_SHARED ? EffectivePriceResolver::MODE_SHARED : EffectivePriceResolver::MODE_OWN;
        return $extra;
    }

    /**
     * Write the common price into every non-deleted shared variation. Own variations are never touched.
     * Call this inside the save transaction.
     *
     * @return int number of variations updated
     */
    public function materialize(int $productId, ?array $common, string $now): int
    {
        if ($common === null) {
            return 0;
        }
        $columns = self::columnsFromCommon($common);
        $count = 0;
        foreach ($this->existingVariants($productId) as $varId => $row) {
            if (EffectivePriceResolver::modeOf($row['extra_props']) !== EffectivePriceResolver::MODE_SHARED) {
                continue;
            }
            $this->wpdb->update($this->tableVariants, $columns + [
                'version'    => (int)$row['version'] + 1,
                'updated_at' => $now,
            ], ['id' => $varId]);
            $count++;
        }
        return $count;
    }

    /* ---------------------------------------------------------------------- */
    /* Read models                                                            */
    /* ---------------------------------------------------------------------- */

    /**
     * Preview of the variations that would lose their price if the common price were removed.
     *
     * Removal is rejected on save until every listed variation is switched to its own price (with an explicit
     * price or an explicit unpriced confirmation), so a price is never lost silently.
     *
     * @return array{in_use:bool,affected_count:int,affected:array<int,array<string,mixed>>,requires_action:bool}
     */
    public function previewCommonPriceRemoval(int $productId): array
    {
        $product = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableProducts} WHERE id = %d", $productId), ARRAY_A);
        $common = $product ? self::readCommonPrice($product['extra_props'] ?? null) : null;

        $affected = [];
        if ($common !== null) {
            $optionMap = $this->optionMap($productId);
            foreach ($this->existingVariants($productId) as $varId => $row) {
                if (EffectivePriceResolver::modeOf($row['extra_props']) !== EffectivePriceResolver::MODE_SHARED) {
                    continue;
                }
                $price = EffectivePriceResolver::resolve($row);
                $affected[] = [
                    'variant_id'   => $varId,
                    'label'        => $this->variationLabel($varId, $optionMap),
                    'sku'          => (string)($row['sku'] ?? ''),
                    'regular'      => $price['regular'],
                    'sale'         => $price['sale'],
                    'active_price' => $price['active_price'],
                ];
            }
        }

        return [
            'in_use'          => $common !== null,
            'affected_count'  => count($affected),
            'affected'        => $affected,
            'requires_action' => count($affected) > 0,
        ];
    }

    /**
     * Read-only reference price of a product without a common price.
     *
     * The source is the first non-deleted variation by saved `(position, id)` over the full set, regardless of any
     * filter, page or table sort. When it has no price the state is `unpriced`; the next variation is never tried.
     *
     * @return array{state:string,applies:bool,variant_id:?int,label:?string,regular:?string,sale:?string,from:?string,to:?string,active_price:?string,sale_active:bool}
     */
    public function referencePrice(int $productId): array
    {
        $product = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$this->tableProducts} WHERE id = %d", $productId), ARRAY_A);
        $applies = $product ? self::readCommonPrice($product['extra_props'] ?? null) === null : true;

        $empty = [
            'state' => 'none', 'applies' => $applies, 'variant_id' => null, 'label' => null,
            'regular' => null, 'sale' => null, 'from' => null, 'to' => null, 'active_price' => null, 'sale_active' => false,
        ];

        $first = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC LIMIT 1",
            $productId
        ), ARRAY_A);
        if (!$first) {
            return $empty;
        }

        $price = EffectivePriceResolver::resolve($first);
        $varId = (int)$first['id'];
        return [
            'state'        => $price['state'] === EffectivePriceResolver::STATE_PRICED ? 'ok' : 'unpriced',
            'applies'      => $applies,
            'variant_id'   => $varId,
            'label'        => $this->variationLabel($varId, $this->optionMap($productId)),
            'regular'      => $price['regular'],
            'sale'         => $price['sale'],
            'from'         => $this->instantOrNull($first['sale_from'] ?? null),
            'to'           => $this->instantOrNull($first['sale_to'] ?? null),
            'active_price' => $price['active_price'],
            'sale_active'  => $price['sale_active'],
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* ---------------------------------------------------------------------- */

    /** @return array<int,array<string,mixed>> non-deleted variation rows by id, in saved order */
    private function existingVariants(int $productId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, sku, regular_price, sale_price, sale_from, sale_to, extra_props, version
             FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC",
            $productId
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    /**
     * Option metadata used to build labels: option id => [position, values id => label], variation options only.
     *
     * @return array<int,array{position:int,values:array<int,string>}>
     */
    private function optionMap(int $productId): array
    {
        $map = [];
        foreach ($this->optionsRepo->getOptionsByProductId($productId) as $opt) {
            if (empty($opt['used_for_variation'])) {
                continue;
            }
            $values = [];
            foreach ($opt['values'] ?? [] as $v) {
                $values[(int)$v['id']] = (string)$v['label'];
            }
            $map[(int)$opt['id']] = ['position' => (int)$opt['position'], 'values' => $values];
        }
        return $map;
    }

    /**
     * Label of one variation such as "Black / M" (used by previews outside this class).
     */
    public function labelFor(int $productId, int $variantId): string
    {
        return $this->variationLabel($variantId, $this->optionMap($productId));
    }

    /** Label such as "Black / M", options in their saved order; "Any" for a wildcard value. */
    private function variationLabel(int $variantId, array $optionMap): string
    {
        $parts = [];
        foreach ($this->optionsRepo->getVariantCombinationVector($variantId) as $item) {
            $optId = (int)$item['option_id'];
            if (!isset($optionMap[$optId])) {
                continue;
            }
            $label = (!empty($item['is_any']) || $item['value_id'] === null)
                ? 'Any'
                : ($optionMap[$optId]['values'][(int)$item['value_id']] ?? 'Any');
            $parts[$optionMap[$optId]['position'] * 100000 + $optId] = $label;
        }
        ksort($parts);
        return $parts ? implode(' / ', $parts) : ('Variation #' . $variantId);
    }

    private function instantOrNull($value): ?string
    {
        return ($value === null || $value === '') ? null : substr((string)$value, 0, 19);
    }
}
