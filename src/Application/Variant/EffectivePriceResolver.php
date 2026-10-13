<?php

namespace WPE\Application\Variant;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The single rule set that turns a stored price row into an effective price.
 *
 * Every reader (variation resolver, cart validation, catalog projector, WooCommerce
 * property bridge) calls this class so that they cannot disagree about:
 *  - null (unpriced) versus 0 (free): a null amount is never coerced into zero;
 *  - sale windows: a sale only applies between its from/to instants (both inclusive);
 *  - sale versus regular: a sale price above the regular price is ignored;
 *  - source: whether the row follows the shared product price or its own price.
 *
 * It is pure (no database access). Callers pass the row they already loaded, optionally the
 * product-level shared price and the clock. Stored variation columns already hold the effective
 * shared price (one writer materializes them), so readers normally omit the shared price; the
 * pricing service passes it to compute previews.
 */
final class EffectivePriceResolver
{
    public const SOURCE_SHARED  = 'shared';
    public const SOURCE_OWN     = 'own';
    public const SOURCE_MISSING = 'missing';

    public const STATE_PRICED   = 'priced';
    public const STATE_UNPRICED = 'unpriced';

    public const MODE_SHARED = 'shared';
    public const MODE_OWN    = 'own';

    /**
     * @param array       $row         Row with regular_price, sale_price, sale_from, sale_to and
     *                                 optional extra_props (JSON string or array).
     * @param array|null  $commonPrice Shared price {regular, sale, from, to}; used only when the row
     *                                 is in shared mode.
     * @param string|null $nowUtc      UTC instant (Y-m-d H:i:s[.u]); defaults to the current time.
     * @return array{regular:?string,sale:?string,active_price:?string,sale_active:bool,on_sale:bool,source:string,state:string,mode:string,next_change:?string}
     */
    public static function resolve(array $row, ?array $commonPrice = null, ?string $nowUtc = null): array
    {
        $extra = self::extraProps($row['extra_props'] ?? null);
        $mode  = self::modeOf($extra);

        if ($mode === self::MODE_SHARED && $commonPrice !== null) {
            $regular = $commonPrice['regular'] ?? null;
            $sale    = $commonPrice['sale'] ?? null;
            $from    = $commonPrice['from'] ?? null;
            $to      = $commonPrice['to'] ?? null;
        } else {
            $regular = $row['regular_price'] ?? null;
            $sale    = $row['sale_price'] ?? null;
            // Variation and product columns win; extra_props keep older rows working.
            $from = $row['sale_from'] ?? ($extra['date_on_sale_from'] ?? null);
            $to   = $row['sale_to'] ?? ($extra['date_on_sale_to'] ?? null);
        }

        $regularN = self::amount($regular);
        $saleN    = self::amount($sale);
        $nowTs    = self::timestamp($nowUtc) ?? time();
        $fromTs   = self::timestamp($from);
        $toTs     = self::timestamp($to);

        $saleUsable = $saleN !== null && ($regularN === null || $saleN <= $regularN);
        $inWindow   = ($fromTs === null || $nowTs >= $fromTs) && ($toTs === null || $nowTs <= $toTs);
        $saleActive = $saleUsable && $inWindow;

        $active = $saleActive ? $saleN : $regularN;

        $nextChange = null;
        if ($saleUsable) {
            if ($fromTs !== null && $nowTs < $fromTs) {
                $nextChange = gmdate('Y-m-d H:i:s.u', $fromTs);
            } elseif ($saleActive && $toTs !== null && $nowTs < $toTs) {
                $nextChange = gmdate('Y-m-d H:i:s.u', $toTs);
            }
        }

        $priced = $active !== null;
        if (!$priced) {
            $source = self::SOURCE_MISSING;
        } else {
            $source = $mode === self::MODE_SHARED ? self::SOURCE_SHARED : self::SOURCE_OWN;
        }

        return [
            'regular'      => self::format($regularN),
            'sale'         => self::format($saleN),
            'active_price' => self::format($active),
            'sale_active'  => $saleActive,
            'on_sale'      => $saleActive && $regularN !== null && $saleN < $regularN,
            'source'       => $source,
            'state'        => $priced ? self::STATE_PRICED : self::STATE_UNPRICED,
            'mode'         => $mode,
            'next_change'  => $nextChange,
        ];
    }

    /**
     * Pricing mode stored on a row; absent or unknown means own so legacy rows keep their meaning.
     */
    public static function modeOf($extraProps): string
    {
        $extra = is_array($extraProps) ? $extraProps : self::extraProps($extraProps);
        return (($extra['pricing_mode'] ?? null) === self::MODE_SHARED) ? self::MODE_SHARED : self::MODE_OWN;
    }

    /**
     * Normalize an amount to a float. Null, empty string and non-numeric values are unpriced;
     * zero is a real price.
     */
    public static function amount($value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        return round((float)$value, 6);
    }

    /**
     * Format an amount using the contract precision (6 decimals) or null.
     */
    public static function format(?float $amount): ?string
    {
        return $amount === null ? null : number_format($amount, 6, '.', '');
    }

    private static function extraProps($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function timestamp($value): ?int
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        try {
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }
}
