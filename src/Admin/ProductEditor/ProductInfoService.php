<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use wpdb;

/**
 * Data rules of the "Product information" block.
 *
 * Reads the product-level facts the block shows (SKU, regular and sale price with schedule, stock configuration,
 * weight and dimensions, tax and shipping flags) and validates what a save may change. Nothing is written here:
 * `plan()` returns the columns and inventory row for ProductEditorService to store in its transaction, and
 * `applySkuRegistry()` is the one registry write that belongs to the product SKU.
 *
 * Product SKU and variation SKUs share one registry, so a product SKU can never equal a variation SKU (of this or
 * another product). Prices on this block are the direct selling price of a simple product; a variable product uses
 * the common price (PricingService) and its parent price columns stay owned by the catalog aggregate, so price
 * fields are rejected for it instead of being ignored silently.
 */
final class ProductInfoService
{
    public const TAX_STATUSES = ['taxable', 'shipping', 'none'];
    public const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];
    public const BACKORDERS = ['no', 'notify', 'yes'];
    public const MAX_SKU_LENGTH = 191;

    private wpdb $wpdb;
    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableSkuRegistry;

    public function __construct(?wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;
        $this->tableProducts = "{$prefix}wpe_products";
        $this->tableVariants = "{$prefix}wpe_variants";
        $this->tableInventory = "{$prefix}wpe_inventory";
        $this->tableSkuRegistry = "{$prefix}wpe_sku_registry";
    }

    /* ---------------------------------------------------------------------- */
    /* Read                                                                   */
    /* ---------------------------------------------------------------------- */

    /**
     * Fields of the block, merged into the editor `product` object.
     *
     * Amounts are the stored six-decimal strings or null (null is "not set", zero is a real value). For a variable
     * product the price fields are null because its price is the common price.
     */
    public function read(array $productRow, ?array $inventoryRow): array
    {
        $variable = ($productRow['type'] ?? '') === 'variable';
        $amount = static fn($v) => ($v === null || $v === '') ? null : number_format((float)$v, 6, '.', '');
        $instant = static fn($v) => empty($v) ? null : substr((string)$v, 0, 19);

        $stock = [
            'manage_stock'     => !empty($inventoryRow['manage_stock']),
            'stock_quantity'   => ($inventoryRow && $inventoryRow['quantity'] !== null) ? (float)$inventoryRow['quantity'] : null,
            'stock_status'     => $inventoryRow['stock_status'] ?? 'instock',
            'backorders'       => $inventoryRow['backorders'] ?? 'no',
            'low_stock_amount' => ($inventoryRow && ($inventoryRow['low_stock_amount'] ?? null) !== null) ? (float)$inventoryRow['low_stock_amount'] : null,
        ];

        return [
            'regular_price'     => $variable ? null : $amount($productRow['regular_price'] ?? null),
            'sale_price'        => $variable ? null : $amount($productRow['sale_price'] ?? null),
            'sale_from'         => $variable ? null : $instant($productRow['sale_from'] ?? null),
            'sale_to'           => $variable ? null : $instant($productRow['sale_to'] ?? null),
            'weight'            => $amount($productRow['weight'] ?? null),
            'length'            => $amount($productRow['length'] ?? null),
            'width'             => $amount($productRow['width'] ?? null),
            'height'            => $amount($productRow['height'] ?? null),
            'tax_status'        => (string)($productRow['tax_status'] ?? 'taxable'),
            'tax_class'         => (string)($productRow['tax_class'] ?? ''),
            'sold_individually' => !empty($productRow['sold_individually']),
            'virtual'           => !empty($productRow['virtual']),
            'stock'             => $stock,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Plan                                                                   */
    /* ---------------------------------------------------------------------- */

    /**
     * Validate the `product` part of a save request.
     *
     * Absent keys keep the stored value; a present key replaces it (null or an empty string clears nullable
     * fields). The stock object may be sent as `stock` or as the older `parent_stock`.
     *
     * @param string     $type              Product type the data will be stored under (`simple` or `variable`).
     * @param array|null $variationsPayload Variations of the same request (to detect SKU clashes), or null.
     * @return array{columns:array,inventory:?array,sku:array{value:?string,previous:?string,changed:bool}}
     * @throws EditorValidationException
     */
    public function plan(int $productId, array $productRow, array $input, string $type, ?array $variationsPayload = null): array
    {
        $errors = [];
        $columns = [];
        $skuInfo = ['value' => $productRow['sku'] !== null && $productRow['sku'] !== '' ? (string)$productRow['sku'] : null, 'previous' => null, 'changed' => false];
        $skuInfo['previous'] = $skuInfo['value'];

        // SKU (product level)
        if (array_key_exists('sku', $input)) {
            $raw = $input['sku'];
            $sku = $raw === null ? '' : (is_scalar($raw) ? trim((string)$raw) : null);
            if ($sku === null) {
                $errors[] = ['pointer' => '/product/sku', 'reason' => 'invalid_type'];
            } elseif (mb_strlen($sku) > self::MAX_SKU_LENGTH) {
                $errors[] = ['pointer' => '/product/sku', 'reason' => 'sku_too_long'];
            } else {
                $new = $sku === '' ? null : $sku;
                $skuInfo['changed'] = $new !== $skuInfo['previous'];
                $skuInfo['value'] = $new;
                $columns['sku'] = $new;
                if ($new !== null && $skuInfo['changed']) {
                    $clash = $this->skuClash($productId, $new, $variationsPayload);
                    if ($clash !== null) {
                        $errors[] = ['pointer' => '/product/sku', 'reason' => $clash];
                    }
                }
            }
        }

        // Price (simple only)
        $priceKeys = ['regular_price', 'sale_price', 'sale_from', 'sale_to'];
        $touchedPrice = false;
        foreach ($priceKeys as $k) {
            if (array_key_exists($k, $input) && $input[$k] !== null && $input[$k] !== '') {
                $touchedPrice = true;
            }
        }
        if ($type === 'variable') {
            if ($touchedPrice) {
                foreach ($priceKeys as $k) {
                    if (array_key_exists($k, $input) && $input[$k] !== null && $input[$k] !== '') {
                        $errors[] = ['pointer' => "/product/{$k}", 'reason' => 'price_not_applicable'];
                    }
                }
            }
        } else {
            $pick = static fn(string $k, $current) => array_key_exists($k, $input) ? $input[$k] : $current;
            try {
                $regular = PricingService::normalizeAmount($pick('regular_price', $productRow['regular_price'] ?? null), '/product/regular_price');
                $sale = PricingService::normalizeAmount($pick('sale_price', $productRow['sale_price'] ?? null), '/product/sale_price');
                $from = PricingService::normalizeInstant($pick('sale_from', $productRow['sale_from'] ?? null), '/product/sale_from');
                $to = PricingService::normalizeInstant($pick('sale_to', $productRow['sale_to'] ?? null), '/product/sale_to');
                $rule = PricingService::pricingRuleErrors($regular, $sale, $from, $to, '/product', 'sale_price', 'sale_from', 'sale_to');
                if ($rule) {
                    $errors = array_merge($errors, $rule);
                } elseif (array_intersect($priceKeys, array_keys($input))) {
                    $columns += ['regular_price' => $regular, 'sale_price' => $sale, 'sale_from' => $from, 'sale_to' => $to];
                }
            } catch (EditorValidationException $e) {
                $errors = array_merge($errors, $e->details());
            }
        }

        // Weight and dimensions
        foreach (['weight', 'length', 'width', 'height'] as $k) {
            if (!array_key_exists($k, $input)) {
                continue;
            }
            try {
                $columns[$k] = PricingService::normalizeAmount($input[$k], "/product/{$k}");
            } catch (EditorValidationException $e) {
                $errors[] = ['pointer' => "/product/{$k}", 'reason' => 'invalid_measure'];
            }
        }

        // Tax, flags
        if (array_key_exists('tax_status', $input)) {
            if (!in_array($input['tax_status'], self::TAX_STATUSES, true)) {
                $errors[] = ['pointer' => '/product/tax_status', 'reason' => 'invalid_value'];
            } else {
                $columns['tax_status'] = $input['tax_status'];
            }
        }
        if (array_key_exists('tax_class', $input)) {
            $tc = is_scalar($input['tax_class']) ? trim((string)$input['tax_class']) : null;
            if ($tc === null || mb_strlen($tc) > 200) {
                $errors[] = ['pointer' => '/product/tax_class', 'reason' => 'invalid_value'];
            } else {
                $columns['tax_class'] = $tc;
            }
        }
        foreach (['sold_individually', 'virtual'] as $k) {
            if (array_key_exists($k, $input)) {
                $b = $this->toBool($input[$k]);
                if ($b === null) {
                    $errors[] = ['pointer' => "/product/{$k}", 'reason' => 'invalid_value'];
                } else {
                    $columns[$k] = $b ? 1 : 0;
                }
            }
        }

        // Stock (product level; for a variable product this is the shared stock variations inherit)
        $inventory = null;
        $stockInput = $input['stock'] ?? ($input['parent_stock'] ?? null);
        if (is_array($stockInput)) {
            $current = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableInventory} WHERE owner_id = %d", $productId), ARRAY_A) ?: [];
            [$inventory, $stockErrors] = $this->planStock($stockInput, $current);
            $errors = array_merge($errors, $stockErrors);
        } elseif (array_key_exists('stock', $input) || array_key_exists('parent_stock', $input)) {
            $errors[] = ['pointer' => '/product/stock', 'reason' => 'invalid_type'];
        }

        if ($errors) {
            throw new EditorValidationException('validation_failed', 'The product information is not valid.', $errors);
        }

        return ['columns' => $columns, 'inventory' => $inventory, 'sku' => $skuInfo];
    }

    /**
     * @return array{0:?array,1:array<int,array{pointer:string,reason:string}>}
     */
    private function planStock(array $in, array $current): array
    {
        $errors = [];
        $manageRaw = array_key_exists('manage_stock', $in) ? $in['manage_stock'] : ($current['manage_stock'] ?? 0);
        $manage = $this->toBool($manageRaw);
        if ($manage === null) {
            $errors[] = ['pointer' => '/product/stock/manage_stock', 'reason' => 'invalid_value'];
            $manage = false;
        }

        $qtyKey = array_key_exists('stock_quantity', $in) ? 'stock_quantity' : (array_key_exists('quantity', $in) ? 'quantity' : null);
        $qtyRaw = $qtyKey !== null ? $in[$qtyKey] : ($current['quantity'] ?? null);
        $qty = null;
        if ($manage) {
            if ($qtyRaw === null || $qtyRaw === '') {
                $errors[] = ['pointer' => '/product/stock/stock_quantity', 'reason' => 'quantity_required'];
            } elseif (!is_numeric($qtyRaw) || is_bool($qtyRaw)) {
                $errors[] = ['pointer' => '/product/stock/stock_quantity', 'reason' => 'invalid_quantity'];
            } else {
                $qty = (float)$qtyRaw;
            }
        }

        $status = $in['stock_status'] ?? ($current['stock_status'] ?? 'instock');
        if (!in_array($status, self::STOCK_STATUSES, true)) {
            $errors[] = ['pointer' => '/product/stock/stock_status', 'reason' => 'invalid_value'];
        }
        $backorders = $in['backorders'] ?? ($current['backorders'] ?? 'no');
        if (!in_array($backorders, self::BACKORDERS, true)) {
            $errors[] = ['pointer' => '/product/stock/backorders', 'reason' => 'invalid_value'];
        }

        $lowRaw = array_key_exists('low_stock_amount', $in) ? $in['low_stock_amount'] : ($current['low_stock_amount'] ?? null);
        $low = null;
        if ($lowRaw !== null && $lowRaw !== '') {
            if (!is_numeric($lowRaw) || is_bool($lowRaw) || (float)$lowRaw < 0) {
                $errors[] = ['pointer' => '/product/stock/low_stock_amount', 'reason' => 'invalid_value'];
            } else {
                $low = (float)$lowRaw;
            }
        }

        if ($errors) {
            return [null, $errors];
        }

        return [[
            'manage_stock'     => $manage ? 1 : 0,
            'quantity'         => $qty,
            'stock_status'     => $status,
            'backorders'       => $backorders,
            'low_stock_amount' => $low,
        ], []];
    }

    /**
     * Validate the names of descriptive attributes together with the variation options.
     *
     * Names must be present and unique across both groups (they share one key space per product), and a
     * descriptive attribute needs at least one value. Variation option values are validated by the variants rules.
     *
     * @throws EditorValidationException
     */
    public static function validateAttributes(?array $descriptive, ?array $variationOptions): void
    {
        $errors = [];
        $seen = [];

        $scan = static function (?array $list, string $base, bool $needValues) use (&$errors, &$seen): void {
            foreach ((array)$list as $i => $opt) {
                $opt = is_array($opt) ? $opt : [];
                $name = trim((string)($opt['name'] ?? ''));
                if ($name === '') {
                    $errors[] = ['pointer' => "{$base}/{$i}/name", 'reason' => 'name_required'];
                    continue;
                }
                $key = function_exists('sanitize_title') ? sanitize_title((string)($opt['key'] ?? $name)) : strtolower($name);
                if (isset($seen[$key])) {
                    $errors[] = ['pointer' => "{$base}/{$i}/name", 'reason' => 'duplicate_option_name'];
                }
                $seen[$key] = true;

                if ($needValues) {
                    $has = false;
                    foreach ((array)($opt['values'] ?? []) as $v) {
                        $label = is_array($v) ? trim((string)($v['label'] ?? '')) : trim((string)$v);
                        if ($label !== '') {
                            $has = true;
                            break;
                        }
                    }
                    if (!$has) {
                        $errors[] = ['pointer' => "{$base}/{$i}/values", 'reason' => 'values_required'];
                    }
                }
            }
        };

        $scan($variationOptions, '/variation_options', false);
        $scan($descriptive, '/descriptive_attributes', true);

        if ($errors) {
            throw new EditorValidationException('validation_failed', 'The attributes are not valid.', $errors);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* SKU registry                                                           */
    /* ---------------------------------------------------------------------- */

    /**
     * Keep the registry row of the product SKU in step with the product. Call inside the save transaction after
     * `plan()` accepted the SKU, once per save.
     */
    public function applySkuRegistry(int $productId, ?string $sku): void
    {
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT normalized_sku FROM {$this->tableSkuRegistry} WHERE object_id = %d",
            $productId
        ), ARRAY_A);

        if ($sku === null) {
            if ($existing) {
                $this->wpdb->delete($this->tableSkuRegistry, ['object_id' => $productId]);
            }
            return;
        }

        if ($existing) {
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableSkuRegistry} SET normalized_sku = %s, original_sku = %s, object_kind = 'product' WHERE object_id = %d",
                $sku,
                $sku,
                $productId
            ));
            return;
        }

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableSkuRegistry} (normalized_sku, object_id, object_kind, original_sku) VALUES (%s, %d, 'product', %s)",
            $sku,
            $productId,
            $sku
        ));
    }

    /**
     * Reason code when the SKU is already taken, or null. A clash with a variation of the same product is judged
     * against the variations of this request when it carries them (a variation may be renamed in the same save).
     */
    private function skuClash(int $productId, string $sku, ?array $variationsPayload): ?string
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT object_id, object_kind FROM {$this->tableSkuRegistry}
             WHERE LOWER(CAST(normalized_sku AS CHAR CHARACTER SET utf8mb4)) = LOWER(%s) AND object_id <> %d",
            $sku,
            $productId
        ), ARRAY_A);

        $ownVariantIds = [];
        if ($variationsPayload !== null) {
            $ownVariantIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d",
                $productId
            )));
        }

        foreach ($rows as $row) {
            if ($variationsPayload !== null && in_array((int)$row['object_id'], $ownVariantIds, true)) {
                continue;
            }
            return $row['object_kind'] === 'variation' ? 'sku_in_use_by_variation' : 'sku_in_use';
        }

        if ($variationsPayload !== null) {
            foreach ($variationsPayload as $v) {
                $vs = is_array($v) ? trim((string)($v['sku'] ?? '')) : '';
                if ($vs !== '' && strcasecmp($vs, $sku) === 0) {
                    return 'sku_in_use_by_variation';
                }
            }
        }

        $own = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tableProducts} WHERE id <> %d AND LOWER(sku) = LOWER(%s) AND deleted_at IS NULL LIMIT 1",
            $productId,
            $sku
        ));
        return $own ? 'sku_in_use' : null;
    }

    private function toBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'yes' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'no' || $value === 'false' || $value === '' || $value === null) {
            return false;
        }
        return null;
    }
}
