<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use wpdb;

/**
 * Rules of the Variants block that do not belong to pricing or the product information block.
 *
 * - Structure plan: which saved variation each submitted row is (by id, else by combination), duplicate and unknown
 *   combinations, and protection of option values or options that live variations still use. Editing options never
 *   creates or deletes variations; a value that a variation still uses cannot be removed silently.
 * - Inventory plan: per variation stock mode (own, shared, untracked) and the row written to the inventory table.
 * - Bulk scope: ids of the current page or a filter resolved on the server over the full saved set, signed with a
 *   short-lived token so the scope cannot grow between preview and apply.
 *
 * Everything here reads state and validates; it never writes.
 */
final class VariantRulesService
{
    public const MAX_COMBINATIONS = 2000;
    public const TOKEN_TTL = 3600;

    public const MODE_OWN = 'own';
    public const MODE_SHARED = 'shared';
    public const MODE_UNTRACKED = 'untracked';

    private const STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];
    private const BACKORDERS = ['no', 'yes', 'notify'];

    private string $tableVariants;
    private string $tableVariantValues;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableInventory;

    public function __construct(private wpdb $wpdb)
    {
        $prefix = $wpdb->prefix;
        $this->tableVariants = "{$prefix}wpe_variants";
        $this->tableVariantValues = "{$prefix}wpe_variant_values";
        $this->tableOptions = "{$prefix}wpe_options";
        $this->tableOptionValues = "{$prefix}wpe_option_values";
        $this->tableInventory = "{$prefix}wpe_inventory";
    }

    public static function norm(string $text): string
    {
        return mb_strtolower(trim($text), 'UTF-8');
    }

    /**
     * Canonical key of a combination map (option name => value label), independent of case and order.
     *
     * @param array<string,string> $combination
     */
    public static function comboKey(array $combination): string
    {
        $pairs = [];
        foreach ($combination as $name => $label) {
            $pairs[self::norm((string)$name)] = self::norm((string)$label);
        }
        ksort($pairs);

        return json_encode($pairs, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Saved, non-deleted variations in saved order with their stored combination.
     *
     * @return array<int,array{id:int,position:int,combination:array<string,string>,value_ids:int[],option_ids:int[]}>
     */
    public function liveVariations(int $productId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, position FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC",
            $productId
        ), ARRAY_A) ?: [];

        $live = [];
        foreach ($rows as $row) {
            $live[(int)$row['id']] = ['id' => (int)$row['id'], 'position' => (int)$row['position'], 'combination' => [], 'value_ids' => [], 'option_ids' => []];
        }
        if ($live === []) {
            return [];
        }

        $list = implode(',', array_map('intval', array_keys($live)));
        $links = $this->wpdb->get_results(
            "SELECT vv.variant_id, vv.option_id, vv.value_id, vv.is_any, o.name, ov.label
               FROM {$this->tableVariantValues} vv
               JOIN {$this->tableOptions} o ON o.id = vv.option_id AND o.used_for_variation = 1
               LEFT JOIN {$this->tableOptionValues} ov ON ov.id = vv.value_id
              WHERE vv.variant_id IN ({$list})",
            ARRAY_A
        ) ?: [];
        foreach ($links as $link) {
            $vid = (int)$link['variant_id'];
            if (!isset($live[$vid])) {
                continue;
            }
            $any = !empty($link['is_any']) || $link['value_id'] === null;
            $live[$vid]['combination'][(string)$link['name']] = $any ? '*' : (string)$link['label'];
            if (!$any) {
                $live[$vid]['value_ids'][] = (int)$link['value_id'];
                $live[$vid]['option_ids'][] = (int)$link['option_id'];
            }
        }

        return $live;
    }

    /**
     * Plan the structure part of a save: the saved variation behind each submitted row and the option rules.
     *
     * @param int[] $removedByTransition Variations a type transition tombstones in the same save (not "remaining").
     * Variations that block a removal are listed in the exception context under `in_use`, keyed by pointer.
     *
     * @return array{variation_ids:array<int,int>,remaining_ids:int[],new_indexes:int[]}
     * @throws EditorValidationException
     */
    public function planStructure(int $productId, array $payload, array $removedByTransition = []): array
    {
        $errors = [];
        $context = [];
        $live = $this->liveVariations($productId);
        foreach ($removedByTransition as $gone) {
            unset($live[(int)$gone]);
        }

        $model = $this->optionModel($productId, $payload, $errors);

        $variationIds = [];
        $newIndexes = [];
        $remaining = array_keys($live);
        $payloadCombos = [];

        if (isset($payload['variations']) && is_array($payload['variations'])) {
            $liveByCombo = [];
            foreach ($live as $id => $info) {
                if ($info['combination'] !== []) {
                    $liveByCombo[self::comboKey($info['combination'])][] = $id;
                }
            }

            $claimed = [];
            $seenNewCombos = [];
            // Pass 1: rows that name a saved variation by id keep it.
            foreach ($payload['variations'] as $index => $varData) {
                $varData = is_array($varData) ? $varData : [];
                $id = !empty($varData['id']) ? (int)$varData['id'] : 0;
                if ($id > 0 && isset($live[$id])) {
                    if (isset($claimed[$id])) {
                        $errors[] = ['pointer' => "/variations/{$index}/id", 'reason' => 'duplicate_variation'];
                        continue;
                    }
                    $claimed[$id] = $index;
                    $variationIds[$index] = $id;
                }
            }
            // Pass 2: rows without a usable id reuse the saved variation of the same combination.
            foreach ($payload['variations'] as $index => $varData) {
                if (isset($variationIds[$index])) {
                    continue;
                }
                $varData = is_array($varData) ? $varData : [];
                $combo = $this->submittedCombination($varData);
                $key = $combo !== [] ? self::comboKey($combo) : '';
                $match = 0;
                if ($key !== '' && isset($liveByCombo[$key])) {
                    foreach ($liveByCombo[$key] as $candidate) {
                        if (!isset($claimed[$candidate])) {
                            $match = $candidate;
                            break;
                        }
                    }
                }
                if ($match > 0) {
                    $claimed[$match] = $index;
                    $variationIds[$index] = $match;
                    continue;
                }
                $variationIds[$index] = 0;
                $newIndexes[] = $index;
                if ($key !== '') {
                    if (isset($seenNewCombos[$key]) || isset($liveByCombo[$key])) {
                        $errors[] = ['pointer' => "/variations/{$index}/combination", 'reason' => 'duplicate_combination'];
                    }
                    $seenNewCombos[$key] = true;
                }
            }
            // Two saved rows that now describe the same combination are also a duplicate.
            $byKey = [];
            foreach ($payload['variations'] as $index => $varData) {
                $combo = $this->submittedCombination(is_array($varData) ? $varData : []);
                if ($combo === [] || ($variationIds[$index] ?? 0) <= 0) {
                    continue;
                }
                $key = self::comboKey($combo);
                if (isset($byKey[$key])) {
                    $errors[] = ['pointer' => "/variations/{$index}/combination", 'reason' => 'duplicate_combination'];
                }
                $byKey[$key] = true;
                $payloadCombos[$variationIds[$index]] = $combo;
            }
            foreach ($payload['variations'] as $index => $varData) {
                $combo = $this->submittedCombination(is_array($varData) ? $varData : []);
                if ($combo === [] || !$model['known']) {
                    continue;
                }
                foreach ($combo as $name => $label) {
                    $opt = $model['by_name'][self::norm((string)$name)] ?? null;
                    if ($opt === null) {
                        $errors[] = ['pointer' => "/variations/{$index}/combination/" . $name, 'reason' => 'unknown_option'];
                    } elseif ($label !== '*' && !array_key_exists(self::norm((string)$label), $opt['values'])) {
                        $errors[] = ['pointer' => "/variations/{$index}/combination/" . $name, 'reason' => 'unknown_value'];
                    }
                }
            }
            $remaining = array_values(array_filter($variationIds, static fn(int $id): bool => $id > 0));
        }

        // A value or option that a remaining variation still uses cannot be removed.
        $usedValues = [];
        $usedOptions = [];
        foreach ($remaining as $vid) {
            if (isset($payloadCombos[$vid])) {
                foreach ($payloadCombos[$vid] as $name => $label) {
                    $opt = $model['by_name'][self::norm((string)$name)] ?? null;
                    if ($opt === null || $label === '*') {
                        continue;
                    }
                    $valueId = $opt['values'][self::norm((string)$label)] ?? null;
                    if ($valueId !== null) {
                        $usedValues[$valueId][] = $vid;
                    }
                }
            } elseif (isset($live[$vid])) {
                foreach ($live[$vid]['value_ids'] as $valueId) {
                    $usedValues[$valueId][] = $vid;
                }
                foreach ($live[$vid]['option_ids'] as $optionId) {
                    $usedOptions[$optionId][] = $vid;
                }
            }
        }
        foreach ($model['removed_values'] as $valueId => $pointer) {
            if (!empty($usedValues[$valueId])) {
                $errors[] = ['pointer' => $pointer, 'reason' => 'value_in_use'];
                $context['in_use'][$pointer] = array_values(array_unique(array_merge($context['in_use'][$pointer] ?? [], $usedValues[$valueId])));
            }
        }
        foreach ($model['removed_options'] as $optionId => $pointer) {
            if (!empty($usedOptions[$optionId])) {
                $errors[] = ['pointer' => $pointer, 'reason' => 'option_in_use'];
                $context['in_use'][$pointer] = array_values(array_unique(array_merge($context['in_use'][$pointer] ?? [], $usedOptions[$optionId])));
            }
        }
        foreach ($model['errors'] as $e) {
            $errors[] = $e;
        }

        if ($errors) {
            throw new EditorValidationException('validation_failed', 'The variation options are not valid.', $errors, $context);
        }

        return ['variation_ids' => $variationIds, 'remaining_ids' => $remaining, 'new_indexes' => $newIndexes];
    }

    /**
     * Effective option model after the save: variation-forming options by normalized name with their values, the
     * saved option and value ids that disappear, and structural errors of the submitted option lists.
     *
     * @return array{known:bool,by_name:array,removed_values:array<int,string>,removed_options:array<int,string>,errors:array}
     */
    private function optionModel(int $productId, array $payload, array &$errors): array
    {
        $stored = [];
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, name, `key`, used_for_variation FROM {$this->tableOptions} WHERE product_id = %d ORDER BY position ASC, id ASC",
            $productId
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $values = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, label FROM {$this->tableOptionValues} WHERE option_id = %d",
                (int)$row['id']
            ), ARRAY_A) ?: [];
            $stored[(int)$row['id']] = [
                'id'    => (int)$row['id'],
                'name'  => (string)$row['name'],
                'key'   => (string)$row['key'],
                'group' => (int)$row['used_for_variation'],
                'values' => array_column($values, 'label', 'id'),
            ];
        }

        $model = ['known' => true, 'by_name' => [], 'removed_values' => [], 'removed_options' => [], 'errors' => []];
        $groups = ['variation_options' => 1, 'descriptive_attributes' => 0];
        $provided = [];
        foreach ($groups as $key => $group) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $provided[$key] = $group;
            }
        }

        foreach ($groups as $key => $group) {
            if (!isset($provided[$key])) {
                if ($group === 1) {
                    foreach ($stored as $opt) {
                        if ($opt['group'] === 1) {
                            $model['by_name'][self::norm($opt['name'])] = [
                                'id'     => $opt['id'],
                                'values' => array_change_key_case(array_flip(array_map(static fn($l) => self::norm((string)$l), $opt['values'])), CASE_LOWER),
                            ];
                        }
                    }
                }
                continue;
            }

            $existing = array_filter($stored, static fn(array $o): bool => $o['group'] === $group);
            $existingByKey = [];
            foreach ($existing as $opt) {
                $existingByKey[strtolower($opt['key'])] = $opt['id'];
            }
            $matched = [];
            foreach ($payload[$key] as $i => $opt) {
                $opt = is_array($opt) ? $opt : [];
                $name = trim((string)($opt['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $optKey = sanitize_title($opt['key'] ?? $name);
                $optId = !empty($opt['id']) ? (int)$opt['id'] : ($existingByKey[strtolower($optKey)] ?? 0);
                $optId = isset($existing[$optId]) ? $optId : 0;
                if ($optId > 0) {
                    $matched[$optId] = true;
                }

                $storedValues = $optId > 0 ? $existing[$optId]['values'] : [];
                $storedByLabel = [];
                foreach ($storedValues as $vid => $label) {
                    $storedByLabel[self::norm((string)$label)] = (int)$vid;
                }

                $resolved = [];
                $seen = [];
                $keptIds = [];
                foreach ((array)($opt['values'] ?? []) as $j => $v) {
                    $label = is_array($v) ? trim((string)($v['label'] ?? '')) : trim((string)$v);
                    if ($label === '') {
                        continue;
                    }
                    $ptr = "/{$key}/{$i}/values/{$j}";
                    $normLabel = self::norm($label);
                    if (isset($seen[$normLabel])) {
                        $model['errors'][] = ['pointer' => $ptr, 'reason' => 'duplicate_value'];
                        continue;
                    }
                    $seen[$normLabel] = true;
                    $valueId = null;
                    if (is_array($v) && !empty($v['id'])) {
                        $claimed = (int)$v['id'];
                        if (!isset($storedValues[$claimed])) {
                            $model['errors'][] = ['pointer' => "{$ptr}/id", 'reason' => 'value_not_in_option'];
                            continue;
                        }
                        $valueId = $claimed;
                    } elseif (isset($storedByLabel[$normLabel])) {
                        $valueId = $storedByLabel[$normLabel];
                    }
                    if ($valueId !== null) {
                        $keptIds[$valueId] = true;
                    }
                    $resolved[$normLabel] = $valueId;
                }
                foreach ($storedValues as $vid => $label) {
                    if (!isset($keptIds[(int)$vid])) {
                        $model['removed_values'][(int)$vid] = "/{$key}/{$i}";
                    }
                }

                // Default value must be one of the option's values.
                if (array_key_exists('default_value_id', $opt) && $opt['default_value_id'] !== null && $opt['default_value_id'] !== '') {
                    if (!isset($keptIds[(int)$opt['default_value_id']])) {
                        $model['errors'][] = ['pointer' => "/{$key}/{$i}/default_value_id", 'reason' => 'default_value_invalid'];
                    }
                } elseif (!empty($opt['default_value']) && is_string($opt['default_value']) && !isset($seen[self::norm($opt['default_value'])])) {
                    $model['errors'][] = ['pointer' => "/{$key}/{$i}/default_value", 'reason' => 'default_value_invalid'];
                }

                if ($group === 1) {
                    $model['by_name'][self::norm($name)] = ['id' => $optId, 'values' => $resolved];
                }
            }
            if ($group === 1) {
                foreach ($existing as $oid => $opt) {
                    if (!isset($matched[$oid])) {
                        $model['removed_options'][$oid] = "/{$key}";
                        foreach (array_keys($opt['values']) as $vid) {
                            unset($model['removed_values'][(int)$vid]);
                        }
                    }
                }
            }
        }

        return $model;
    }

    /**
     * @return array<string,string>
     */
    private function submittedCombination(array $varData): array
    {
        $combo = $varData['combination'] ?? $varData['attributes'] ?? [];
        if (!is_array($combo)) {
            return [];
        }
        $out = [];
        foreach ($combo as $name => $label) {
            if (is_scalar($label) || $label === null) {
                $out[(string)$name] = (string)$label;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Inventory modes
    // ---------------------------------------------------------------------------------------------------------

    /** Derived mode of a saved variation: its own stock, the product's shared stock, or not tracked. */
    public static function inventoryMode(bool $variationManagesStock, bool $productManagesStock): string
    {
        if ($variationManagesStock) {
            return self::MODE_OWN;
        }

        return $productManagesStock ? self::MODE_SHARED : self::MODE_UNTRACKED;
    }

    /**
     * Plan the inventory row of every submitted variation.
     *
     * Absent keys keep the stored value of a saved variation. `inventory_mode` (own, shared, untracked) wins over
     * the legacy `manage_stock` flag. Shared needs stock management at product level and untracked needs it off,
     * so a row never claims a stock it does not have.
     *
     * @param array<int,int> $variationIds Submitted index => saved id (0 for a new variation).
     * @return array<int,array{manage_stock:int,quantity:?float,stock_status:string,backorders:string,mode:string}>
     * @throws EditorValidationException
     */
    public function planInventory(array $variations, array $variationIds, bool $productManagesStock): array
    {
        $ids = array_values(array_filter($variationIds, static fn(int $id): bool => $id > 0));
        $stored = [];
        if ($ids !== []) {
            $list = implode(',', array_map('intval', $ids));
            foreach ($this->wpdb->get_results("SELECT owner_id, manage_stock, quantity, stock_status, backorders FROM {$this->tableInventory} WHERE owner_id IN ({$list})", ARRAY_A) ?: [] as $row) {
                $stored[(int)$row['owner_id']] = $row;
            }
        }

        $errors = [];
        $plan = [];
        foreach ($variations as $index => $varData) {
            $varData = is_array($varData) ? $varData : [];
            $base = "/variations/{$index}";
            $row = $stored[$variationIds[$index] ?? 0] ?? null;

            $mode = null;
            if (array_key_exists('inventory_mode', $varData)) {
                $mode = $varData['inventory_mode'];
                if (!in_array($mode, [self::MODE_OWN, self::MODE_SHARED, self::MODE_UNTRACKED], true)) {
                    $errors[] = ['pointer' => "{$base}/inventory_mode", 'reason' => 'invalid_inventory_mode'];
                    continue;
                }
            }
            if ($mode !== null) {
                $manage = $mode === self::MODE_OWN;
            } elseif (array_key_exists('manage_stock', $varData)) {
                $manage = !empty($varData['manage_stock']);
            } else {
                $manage = $row !== null && !empty($row['manage_stock']);
            }

            if ($mode === self::MODE_SHARED && !$productManagesStock) {
                $errors[] = ['pointer' => "{$base}/inventory_mode", 'reason' => 'shared_requires_product_stock'];
                continue;
            }
            if ($mode === self::MODE_UNTRACKED && $productManagesStock) {
                $errors[] = ['pointer' => "{$base}/inventory_mode", 'reason' => 'untracked_conflicts_with_product_stock'];
                continue;
            }

            $status = array_key_exists('stock_status', $varData) ? $varData['stock_status'] : ($row['stock_status'] ?? 'instock');
            if (!in_array($status, self::STOCK_STATUSES, true)) {
                $errors[] = ['pointer' => "{$base}/stock_status", 'reason' => 'invalid_value'];
                continue;
            }
            $backorders = array_key_exists('backorders', $varData) ? $varData['backorders'] : ($row['backorders'] ?? 'no');
            if (!in_array($backorders, self::BACKORDERS, true)) {
                $errors[] = ['pointer' => "{$base}/backorders", 'reason' => 'invalid_value'];
                continue;
            }

            $quantity = null;
            if ($manage) {
                $raw = array_key_exists('stock_quantity', $varData) ? $varData['stock_quantity'] : ($row['quantity'] ?? null);
                if ($raw === null || $raw === '') {
                    if ($mode === self::MODE_OWN) {
                        $errors[] = ['pointer' => "{$base}/stock_quantity", 'reason' => 'quantity_required'];
                        continue;
                    }
                } elseif (!is_numeric($raw) || is_bool($raw)) {
                    $errors[] = ['pointer' => "{$base}/stock_quantity", 'reason' => 'invalid_quantity'];
                    continue;
                } else {
                    $quantity = (float)$raw;
                }
            }

            $plan[$index] = [
                'manage_stock' => $manage ? 1 : 0,
                'quantity'     => $quantity,
                'stock_status' => (string)$status,
                'backorders'   => (string)$backorders,
                'mode'         => self::inventoryMode($manage, $productManagesStock),
            ];
        }

        if ($errors) {
            throw new EditorValidationException('validation_failed', 'The variation stock settings are not valid.', $errors);
        }

        return $plan;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Bulk scope
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Resolve a bulk scope to saved variation ids in saved order.
     *
     * `ids` is the list of the current page; `filter` is resolved over the full saved set. Both must name saved
     * variations of the product.
     *
     * @param array<int,array> $variations Editor rows (full saved set, saved order).
     * @return array{type:string,ids:int[],count:int,token:string,expires_at:int}
     * @throws EditorValidationException
     */
    public function resolveScope(int $productId, int $productVersion, array $scope, array $variations): array
    {
        $type = (string)($scope['type'] ?? '');
        $byId = [];
        foreach ($variations as $row) {
            $byId[(int)$row['id']] = $row;
        }

        if ($type === 'ids') {
            $given = $scope['ids'] ?? null;
            if (!is_array($given)) {
                throw new EditorValidationException('validation_failed', 'The bulk scope has no ids.', [['pointer' => '/scope/ids', 'reason' => 'required']]);
            }
            $wanted = [];
            foreach ($given as $i => $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                    throw new EditorValidationException('validation_failed', 'The bulk scope has an invalid id.', [['pointer' => "/scope/ids/{$i}", 'reason' => 'invalid_value']]);
                }
                $wanted[(int)$id] = true;
            }
            foreach (array_keys($wanted) as $id) {
                if (!isset($byId[$id])) {
                    throw new EditorValidationException('validation_failed', 'The bulk scope names a variation that is not part of this product.', [['pointer' => '/scope/ids', 'reason' => 'unknown_variation']], ['variation_id' => $id]);
                }
            }
            $ids = [];
            foreach ($variations as $row) {
                if (isset($wanted[(int)$row['id']])) {
                    $ids[] = (int)$row['id'];
                }
            }
        } elseif ($type === 'filter') {
            $filter = is_array($scope['filter'] ?? null) ? $scope['filter'] : [];
            $ids = [];
            foreach ($variations as $row) {
                if ($this->matchesFilter($row, $filter)) {
                    $ids[] = (int)$row['id'];
                }
            }
        } else {
            throw new EditorValidationException('validation_failed', 'The bulk scope type is not valid.', [['pointer' => '/scope/type', 'reason' => 'invalid_value']]);
        }

        $expires = time() + self::TOKEN_TTL;

        return [
            'type'       => $type,
            'ids'        => $ids,
            'count'      => count($ids),
            'token'      => $this->sign($productId, $productVersion, $ids, $expires),
            'expires_at' => $expires,
        ];
    }

    /**
     * Check that the ids and the token are the ones a preview issued for this product version.
     *
     * @param int[] $ids
     * @throws EditorValidationException
     */
    public function assertScopeToken(int $productId, int $productVersion, array $ids, string $token): void
    {
        $ids = array_values(array_map('intval', $ids));
        $parts = explode('.', $token, 2);
        $expires = (count($parts) === 2 && ctype_digit($parts[0])) ? (int)$parts[0] : 0;
        $reason = null;
        if ($expires === 0 || !hash_equals($this->sign($productId, $productVersion, $ids, $expires), $token)) {
            $reason = 'bulk_token_invalid';
        } elseif ($expires < time()) {
            $reason = 'bulk_token_expired';
        }
        if ($reason !== null) {
            throw new EditorValidationException($reason, 'The bulk scope no longer matches the preview. Preview it again.', [['pointer' => '/scope/token', 'reason' => $reason]]);
        }
    }

    /**
     * @param int[] $ids
     */
    private function sign(int $productId, int $productVersion, array $ids, int $expires): string
    {
        $message = $productId . '|' . $productVersion . '|' . $expires . '|' . implode(',', $ids);

        return $expires . '.' . hash_hmac('sha256', $message, wp_salt('auth'));
    }

    private function matchesFilter(array $row, array $filter): bool
    {
        if (!empty($filter['search']) && is_string($filter['search'])) {
            $needle = self::norm($filter['search']);
            $hay = self::norm((string)($row['sku'] ?? '') . ' ' . implode(' ', array_map('strval', (array)($row['combination'] ?? []))));
            if (!str_contains($hay, $needle)) {
                return false;
            }
        }
        if (!empty($filter['status']) && (string)($row['status'] ?? '') !== (string)$filter['status']) {
            return false;
        }
        if (!empty($filter['pricing_mode']) && (string)($row['pricing_mode'] ?? '') !== (string)$filter['pricing_mode']) {
            return false;
        }
        if (!empty($filter['inventory_mode']) && (string)($row['inventory_mode'] ?? '') !== (string)$filter['inventory_mode']) {
            return false;
        }
        if (!empty($filter['price_state'])) {
            $unpriced = (string)($row['effective_price']['state'] ?? '') !== 'priced';
            if (($filter['price_state'] === 'missing') !== $unpriced) {
                return false;
            }
        }
        if (isset($filter['has_image']) && $filter['has_image'] !== '') {
            $has = !empty($row['image_asset_id']);
            if ((bool)$filter['has_image'] !== $has) {
                return false;
            }
        }
        if (!empty($filter['options']) && is_array($filter['options'])) {
            $combo = [];
            foreach ((array)($row['combination'] ?? []) as $name => $label) {
                $combo[self::norm((string)$name)] = self::norm((string)$label);
            }
            foreach ($filter['options'] as $name => $labels) {
                $accepted = array_map(static fn($l) => self::norm((string)$l), (array)$labels);
                if (!isset($combo[self::norm((string)$name)]) || !in_array($combo[self::norm((string)$name)], $accepted, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Group the scope by stock owner so a shared owner is written once.
     *
     * @param array<int,array> $rows Editor rows of the scope.
     * @return array<int,array{owner_id:int,owner_kind:string,variation_ids:int[],writes:int}>
     */
    public static function stockOwnerGroups(array $rows, int $productId): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $mode = (string)($row['inventory_mode'] ?? self::MODE_UNTRACKED);
            if ($mode === self::MODE_UNTRACKED) {
                continue;
            }
            $owner = $mode === self::MODE_OWN ? (int)$row['id'] : $productId;
            $groups[$owner] ??= ['owner_id' => $owner, 'owner_kind' => $mode === self::MODE_OWN ? 'variation' : 'product', 'variation_ids' => [], 'writes' => 1];
            $groups[$owner]['variation_ids'][] = (int)$row['id'];
        }

        return array_values($groups);
    }
}
