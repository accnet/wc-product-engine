<?php

namespace WPE\Infrastructure\Database;

use RuntimeException;
use InvalidArgumentException;

class DuplicateCombinationException extends RuntimeException
{
    public function __construct(string $message = "Duplicate variant combination detected", int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class CombinationHashCollisionException extends RuntimeException
{
    public function __construct(string $message = "Combination hash collision detected for differing selection vectors", int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Options, values, and combination integrity repository.
 * Manages product options (attributes), option values, and variant attribute assignments.
 * Enforces combination uniqueness, parent ownership bounds, ANY wildcard vs empty string semantics,
 * and vector-based collision detection without implicit Cartesian variant generation.
 */
class OptionsRepository
{
    private \wpdb $wpdb;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;
    private string $tableVariants;
    private string $tableProducts;

    public function __construct(?\wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];

        $prefix = $this->wpdb->prefix;
        $this->tableOptions       = $prefix . 'wpe_options';
        $this->tableOptionValues = $prefix . 'wpe_option_values';
        $this->tableVariantValues = $prefix . 'wpe_variant_values';
        $this->tableVariants      = $prefix . 'wpe_variants';
        $this->tableProducts      = $prefix . 'wpe_products';
    }

    /**
     * Create an option for a product.
     * Does NOT generate variants in wpe_variants (R27).
     */
    public function createOption(int $productId, array $data): array
    {
        $key = sanitize_title($data['key'] ?? $data['name'] ?? '');
        if ($key === '') {
            throw new InvalidArgumentException("Option key or name cannot be empty.");
        }

        $name = trim((string)($data['name'] ?? $key));

        // Determine position if not given
        if (!isset($data['position'])) {
            $maxPos = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT MAX(position) FROM {$this->tableOptions} WHERE product_id = %d",
                $productId
            ));
            $position = $maxPos !== null ? ((int)$maxPos + 1) : 0;
        } else {
            $position = (int)$data['position'];
        }

        if (!empty($data['id'])) {
            $optionId = (int)$data['id'];
        } else {
            $maxId = (int)$this->wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$this->tableOptions}");
            $optionId = $maxId + 1;
        }

        $res = $this->wpdb->insert(
            $this->tableOptions,
            [
                'id'                 => $optionId,
                'product_id'         => $productId,
                'key'                => $key,
                'name'               => $name,
                'taxonomy'           => $data['taxonomy'] ?? null,
                'position'           => $position,
                'visible'            => isset($data['visible']) ? (!empty($data['visible']) ? 1 : 0) : 1,
                'used_for_variation' => isset($data['used_for_variation']) ? (!empty($data['used_for_variation']) ? 1 : 0) : 1,
                'default_value_id'   => null,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d']
        );

        if ($res === false) {
            throw new RuntimeException("Failed to insert option: " . $this->wpdb->last_error);
        }

        // If values are supplied in creation payload, create them
        $values = [];
        if (!empty($data['values']) && is_array($data['values'])) {
            $pos = 0;
            foreach ($data['values'] as $v) {
                if (is_string($v)) {
                    $valData = ['label' => $v, 'position' => $pos++];
                } else {
                    $valData = $v;
                    if (!isset($valData['position'])) {
                        $valData['position'] = $pos++;
                    }
                }
                $values[] = $this->createOptionValue($optionId, $valData);
            }
        }

        return $this->getOptionById($optionId);
    }

    /**
     * Update an option while preserving its ID (R25).
     */
    public function updateOption(int $optionId, array $data): array
    {
        $current = $this->getOptionById($optionId);
        if (!$current) {
            throw new RuntimeException("Option not found: {$optionId}");
        }

        $updates = [];
        $formats = [];

        if (isset($data['name'])) {
            $updates['name'] = trim((string)$data['name']);
            $formats[] = '%s';
        }

        if (isset($data['key'])) {
            $updates['key'] = sanitize_title($data['key']);
            $formats[] = '%s';
        }

        if (isset($data['position'])) {
            $updates['position'] = (int)$data['position'];
            $formats[] = '%d';
        }

        if (isset($data['visible'])) {
            $updates['visible'] = !empty($data['visible']) ? 1 : 0;
            $formats[] = '%d';
        }

        if (isset($data['used_for_variation'])) {
            $updates['used_for_variation'] = !empty($data['used_for_variation']) ? 1 : 0;
            $formats[] = '%d';
        }

        if (array_key_exists('default_value_id', $data)) {
            $defId = $data['default_value_id'] !== null ? (int)$data['default_value_id'] : null;
            if ($defId !== null) {
                // AC-T07-01: Verify default_value_id belongs to this option
                $val = $this->getOptionValueById($defId);
                if (!$val || (int)$val['option_id'] !== $optionId) {
                    throw new InvalidArgumentException("Default value ID {$defId} does not belong to option {$optionId}.");
                }
            }
            $updates['default_value_id'] = $defId;
            $formats[] = $defId !== null ? '%d' : null;
        }

        if (!empty($updates)) {
            $res = $this->wpdb->update($this->tableOptions, $updates, ['id' => $optionId], $formats, ['%d']);
            if ($res === false) {
                throw new RuntimeException("Failed to update option: " . $this->wpdb->last_error);
            }
        }

        return $this->getOptionById($optionId);
    }

    /**
     * Get an option by its ID.
     */
    public function getOptionById(int $optionId): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableOptions} WHERE id = %d",
            $optionId
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['values'] = $this->getOptionValues($optionId);
        return $row;
    }

    /**
     * Get all options for a product, ordered by position.
     */
    public function getOptionsByProductId(int $productId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->tableOptions} WHERE product_id = %d ORDER BY position ASC, id ASC",
            $productId
        ), ARRAY_A);

        $options = [];
        foreach ($rows as $row) {
            $row['values'] = $this->getOptionValues((int)$row['id']);
            $options[] = $row;
        }

        return $options;
    }

    /**
     * Delete an option and its associated values.
     */
    public function deleteOption(int $optionId): bool
    {
        $this->wpdb->delete($this->tableOptionValues, ['option_id' => $optionId]);
        $this->wpdb->delete($this->tableVariantValues, ['option_id' => $optionId]);
        $res = $this->wpdb->delete($this->tableOptions, ['id' => $optionId]);
        return $res !== false;
    }

    /**
     * Create an option value.
     * Supports Unicode keys/labels (R23) and empty string value_key (R26).
     * Does NOT generate variants in wpe_variants (R27).
     */
    public function createOptionValue(int $optionId, array $data): array
    {
        $label = (string)($data['label'] ?? '');

        // Support empty string value_key distinctly from ANY (R26)
        if (array_key_exists('value_key', $data)) {
            $valueKey = (string)$data['value_key'];
        } else {
            // Keep Unicode value key intact (R23)
            $valueKey = $this->normalizeUnicodeKey($label);
        }

        if (!isset($data['position'])) {
            $maxPos = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT MAX(position) FROM {$this->tableOptionValues} WHERE option_id = %d",
                $optionId
            ));
            $position = $maxPos !== null ? ((int)$maxPos + 1) : 0;
        } else {
            $position = (int)$data['position'];
        }

        if (!empty($data['id'])) {
            $valueId = (int)$data['id'];
        } else {
            $maxId = (int)$this->wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$this->tableOptionValues}");
            $valueId = $maxId + 1;
        }

        $res = $this->wpdb->insert(
            $this->tableOptionValues,
            [
                'id'        => $valueId,
                'option_id' => $optionId,
                'value_key' => $valueKey,
                'label'     => $label,
                'term_id'   => $data['term_id'] ?? null,
                'position'  => $position,
                'swatch'    => isset($data['swatch']) ? json_encode($data['swatch'], JSON_UNESCAPED_UNICODE) : null,
            ],
            ['%d', '%d', '%s', '%s', '%d', '%d', '%s']
        );

        if ($res === false) {
            throw new RuntimeException("Failed to insert option value: " . $this->wpdb->last_error);
        }

        return $this->getOptionValueById($valueId);
    }

    /**
     * Update an option value while preserving its ID (R25).
     */
    public function updateOptionValue(int $valueId, array $data): array
    {
        $current = $this->getOptionValueById($valueId);
        if (!$current) {
            throw new RuntimeException("Option value not found: {$valueId}");
        }

        $updates = [];
        $formats = [];

        if (isset($data['label'])) {
            $updates['label'] = (string)$data['label'];
            $formats[] = '%s';
        }

        if (array_key_exists('value_key', $data)) {
            $updates['value_key'] = (string)$data['value_key'];
            $formats[] = '%s';
        }

        if (isset($data['position'])) {
            $updates['position'] = (int)$data['position'];
            $formats[] = '%d';
        }

        if (isset($data['swatch'])) {
            $updates['swatch'] = json_encode($data['swatch'], JSON_UNESCAPED_UNICODE);
            $formats[] = '%s';
        }

        if (!empty($updates)) {
            $res = $this->wpdb->update($this->tableOptionValues, $updates, ['id' => $valueId], $formats, ['%d']);
            if ($res === false) {
                throw new RuntimeException("Failed to update option value: " . $this->wpdb->last_error);
            }
        }

        return $this->getOptionValueById($valueId);
    }

    /**
     * Get an option value by its ID.
     */
    public function getOptionValueById(int $valueId): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableOptionValues} WHERE id = %d",
            $valueId
        ), ARRAY_A);
    }

    /**
     * Get all values for an option, ordered by position.
     */
    public function getOptionValues(int $optionId): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->tableOptionValues} WHERE option_id = %d ORDER BY position ASC, id ASC",
            $optionId
        ), ARRAY_A);
    }

    /**
     * Build canonical ordered selection vector for a product's variation selections.
     * Enforces parent ownership boundary (AC-T07-01) and distinguishes ANY from empty (R26).
     *
     * @param int $productId
     * @param array $selections Array of selections e.g. [option_id => value_id_or_any_marker]
     * @return array Canonical ordered vector
     */
    public function buildCombinationVector(int $productId, array $selections): array
    {
        $options = $this->getOptionsByProductId($productId);
        if (empty($options)) {
            return [];
        }

        // Sort options deterministically by id
        usort($options, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);

        $vector = [];

        foreach ($options as $opt) {
            $optId = (int)$opt['id'];
            $optKey = $opt['key'];
            $optName = $opt['name'];

            // Match selection by option_id, option key, or option name
            $sel = null;
            if (array_key_exists($optId, $selections)) {
                $sel = $selections[$optId];
            } elseif (array_key_exists((string)$optId, $selections)) {
                $sel = $selections[(string)$optId];
            } elseif (array_key_exists($optKey, $selections)) {
                $sel = $selections[$optKey];
            } elseif (array_key_exists($optName, $selections)) {
                $sel = $selections[$optName];
            }

            // Check if wildcard ANY
            $isAny = false;
            $valueId = null;

            if ($sel === null || $sel === '*' || $sel === 'ANY' || (is_array($sel) && !empty($sel['is_any']))) {
                $isAny = true;
            } else {
                // Specific selection
                $targetValId = is_array($sel) ? ($sel['value_id'] ?? null) : $sel;

                if (is_numeric($targetValId)) {
                    $valRow = $this->getOptionValueById((int)$targetValId);
                } else {
                    // Look up by value_key or label
                    $valRow = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT * FROM {$this->tableOptionValues} WHERE option_id = %d AND (value_key = %s OR label = %s)",
                        $optId,
                        (string)$targetValId,
                        (string)$targetValId
                    ), ARRAY_A);
                }

                if (!$valRow) {
                    throw new InvalidArgumentException("Value '{$targetValId}' not found for option '{$optName}' (ID: {$optId}).");
                }

                // AC-T07-01: Verify value belongs to this parent's option
                if ((int)$valRow['option_id'] !== $optId) {
                    throw new InvalidArgumentException(
                        "Value ID {$valRow['id']} belongs to option {$valRow['option_id']}, not option {$optId} of parent product {$productId}."
                    );
                }

                $valueId = (int)$valRow['id'];
            }

            $vector[] = [
                'option_id' => $optId,
                'is_any'    => $isAny ? 1 : 0,
                'value_id'  => $valueId,
            ];
        }

        return $vector;
    }

    /**
     * Compute 64-char hex combination key from canonical vector.
     */
    public function buildCombinationKey(array $vector): string
    {
        $canonicalJson = json_encode($vector, JSON_UNESCAPED_UNICODE);
        return hash('sha256', $canonicalJson);
    }

    /**
     * Validate and assign combination to a variant.
     * Enforces combination uniqueness (AC-T07-02), reports duplicate combinations without overwriting,
     * and compares vectors upon hash collision (R24).
     *
     * @param int $productId
     * @param int $variantId
     * @param array $selections
     * @param callable|null $hashCollisionSimulator Used in tests to simulate cryptographic hash collision
     * @return string The assigned 64-char hex combination key
     */
    public function assignVariantCombination(int $productId, int $variantId, array $selections, ?callable $hashCollisionSimulator = null): string
    {
        $vector = $this->buildCombinationVector($productId, $selections);

        // Compute combination key
        if ($hashCollisionSimulator !== null) {
            $combHex = $hashCollisionSimulator($vector);
        } else {
            $combHex = $this->buildCombinationKey($vector);
        }

        // Check active combinations on other variants of this product
        $existingVariants = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, HEX(active_combination_key) AS active_comb_hex
             FROM {$this->tableVariants}
             WHERE product_id = %d AND deleted_at IS NULL AND active_combination_key IS NOT NULL AND id != %d",
            $productId,
            $variantId
        ), ARRAY_A);

        foreach ($existingVariants as $ev) {
            $existingHex = strtolower($ev['active_comb_hex'] ?? '');
            if ($existingHex === strtolower($combHex)) {
                // Hash match detected: compare full selection vectors (R24)
                $existingVector = $this->getVariantCombinationVector((int)$ev['id']);

                if ($existingVector === $vector) {
                    // Exact duplicate combination: AC-T07-02
                    throw new DuplicateCombinationException(
                        "Duplicate combination detected: variant {$variantId} has identical selection to variant {$ev['id']}. Cannot overwrite."
                    );
                } else {
                    // Different vectors but identical hash: R24
                    throw new CombinationHashCollisionException(
                        "Combination hash collision detected between variant {$variantId} and variant {$ev['id']}. Vectors differ but hash matches. Cannot rely on hash alone."
                    );
                }
            }
        }

        // Write variant_values rows
        $this->wpdb->delete($this->tableVariantValues, ['variant_id' => $variantId]);
        foreach ($vector as $vItem) {
            $this->wpdb->insert(
                $this->tableVariantValues,
                [
                    'variant_id' => $variantId,
                    'option_id'  => $vItem['option_id'],
                    'value_id'   => $vItem['value_id'],
                    'is_any'     => $vItem['is_any'],
                ],
                ['%d', '%d', '%d', '%d']
            );
        }

        // Update combination_key and active_combination_key on wpe_variants
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableVariants}
             SET combination_key = UNHEX(%s), active_combination_key = UNHEX(%s)
             WHERE id = %d",
            $combHex,
            $combHex,
            $variantId
        ));

        return $combHex;
    }

    /**
     * Reconstruct canonical selection vector from stored wpe_variant_values for a variant.
     */
    public function getVariantCombinationVector(int $variantId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT option_id, value_id, is_any
             FROM {$this->tableVariantValues}
             WHERE variant_id = %d
             ORDER BY option_id ASC",
            $variantId
        ), ARRAY_A);

        $vector = [];
        foreach ($rows as $r) {
            $vector[] = [
                'option_id' => (int)$r['option_id'],
                'is_any'    => (int)$r['is_any'],
                'value_id'  => $r['value_id'] !== null ? (int)$r['value_id'] : null,
            ];
        }

        return $vector;
    }

    /**
     * Normalize label to a URL and collation friendly Unicode value key.
     */
    private function normalizeUnicodeKey(string $label): string
    {
        $trimmed = trim($label);
        if ($trimmed === '') {
            return '';
        }
        $key = mb_strtolower($trimmed, 'UTF-8');
        $key = preg_replace('/[^\p{L}\p{N}\-_]+/u', '-', $key);
        return trim($key, '-');
    }
}
