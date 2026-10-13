<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use RuntimeException;
use InvalidArgumentException;
use wpdb;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\WordPress\PostIdAllocator;

class ConcurrencyConflictException extends RuntimeException
{
    private array $serverData;

    public function __construct(string $message, array $serverData = [], int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->serverData = $serverData;
    }

    public function getServerData(): array
    {
        return $this->serverData;
    }
}

/**
 * ProductEditorService
 *
 * Domain service providing backend logic for the enhanced Product Variable & Attribute Editor:
 * - Full product payload aggregation (descriptive attributes, options cards, variations table).
 * - Selective combination generator preview.
 * - Bulk edit preview with before/after diffs.
 * - Concurrency conflict detection using product version.
 * - Atomic persistence with automatic LegacyMirror projection.
 */
class ProductEditorService
{
    private static ?ProductEditorService $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private MediaRepository $mediaRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableSkuRegistry;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableAssets;
    private string $tableObjectState;

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?MediaRepository $mediaRepo = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $attachmentBridge = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts      = "{$prefix}wpe_products";
        $this->tableVariants      = "{$prefix}wpe_variants";
        $this->tableInventory     = "{$prefix}wpe_inventory";
        $this->tableSkuRegistry   = "{$prefix}wpe_sku_registry";
        $this->tableOptions       = "{$prefix}wpe_options";
        $this->tableOptionValues  = "{$prefix}wpe_option_values";
        $this->tableVariantValues = "{$prefix}wpe_variant_values";
        $this->tableProductMedia  = "{$prefix}wpe_product_media";
        $this->tableVariantMedia  = "{$prefix}wpe_variant_media";
        $this->tableAssets        = "{$prefix}wpe_assets";
        $this->tableObjectState   = "{$prefix}wpe_object_state";

        $this->productRepo      = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo      = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->mediaRepo        = $mediaRepo ?? new MediaRepository($this->wpdb);
        $this->assetService     = $assetService ?? new AssetService($this->wpdb);
        $this->attachmentBridge = $attachmentBridge ?? AttachmentBridge::instance($this->wpdb);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fetch complete editor data payload for a product.
     */
    public function getProductEditorData(int $productId): array
    {
        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ), ARRAY_A);

        if (!$product) {
            // Check if WordPress post shell exists
            $post = get_post($productId);
            if (!$post || $post->post_type !== 'product') {
                throw new InvalidArgumentException("Product not found: {$productId}");
            }
            // Initialize basic product representation in wpe_products if newly created via WP Admin
            $now = gmdate('Y-m-d H:i:s.u');
            $this->wpdb->insert($this->tableProducts, [
                'id'                 => $productId,
                'title'              => $post->post_title ?: 'Untitled Product',
                'slug'               => $post->post_name ?: 'product-' . $productId,
                'type'               => 'variable',
                'status'             => $post->post_status ?: 'draft',
                'catalog_visibility' => 'visible',
                'version'            => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            $this->wpdb->insert($this->tableInventory, [
                'owner_id'     => $productId,
                'manage_stock' => 0,
                'quantity'     => null,
                'stock_status' => 'instock',
                'backorders'   => 'no',
                'version'      => 1,
                'updated_at'   => $now,
            ]);

            $product = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableProducts} WHERE id = %d",
                $productId
            ), ARRAY_A);
        }

        // Parent inventory
        $parentInv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ), ARRAY_A);

        // Fetch options and separate into descriptive vs variation options
        $allOptions = $this->optionsRepo->getOptionsByProductId($productId);
        $descriptiveAttributes = [];
        $variationOptions = [];

        foreach ($allOptions as $opt) {
            $optItem = [
                'id'                 => (int)$opt['id'],
                'name'               => $opt['name'],
                'key'                => $opt['key'],
                'taxonomy'           => $opt['taxonomy'],
                'position'           => (int)$opt['position'],
                'visible'            => !empty($opt['visible']),
                'used_for_variation' => !empty($opt['used_for_variation']),
                'default_value_id'   => $opt['default_value_id'] ? (int)$opt['default_value_id'] : null,
                'values'             => array_map(function ($v) {
                    return [
                        'id'        => (int)$v['id'],
                        'label'     => $v['label'],
                        'value_key' => $v['value_key'],
                        'position'  => (int)$v['position'],
                    ];
                }, $opt['values'] ?? []),
            ];

            if ($optItem['used_for_variation']) {
                $variationOptions[] = $optItem;
            } else {
                $descriptiveAttributes[] = $optItem;
            }
        }

        // Fetch variations
        $rawVariants = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT v.*, HEX(v.combination_key) as combination_hex,
                    i.manage_stock as var_manage_stock, i.quantity as var_quantity,
                    i.stock_status as var_stock_status, i.backorders as var_backorders
             FROM {$this->tableVariants} v
             LEFT JOIN {$this->tableInventory} i ON v.id = i.owner_id
             WHERE v.product_id = %d AND v.deleted_at IS NULL
             ORDER BY v.position ASC, v.id ASC",
            $productId
        ), ARRAY_A);

        $variations = [];
        foreach ($rawVariants as $rv) {
            $varId = (int)$rv['id'];
            $vector = $this->optionsRepo->getVariantCombinationVector($varId);

            // Fetch variant image
            $varImg = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT a.source_url, a.attachment_id
                 FROM {$this->tableVariantMedia} vm
                 JOIN {$this->tableAssets} a ON vm.asset_id = a.id
                 WHERE vm.variant_id = %d
                 LIMIT 1",
                $varId
            ), ARRAY_A);

            // Map attributes combination { option_name => value_label }
            $attributesMap = [];
            foreach ($vector as $vItem) {
                $optId = (int)$vItem['option_id'];
                $valId = (int)$vItem['value_id'];
                $optRow = $this->optionsRepo->getOptionById($optId);
                $valRow = $valId > 0 ? $this->optionsRepo->getOptionValueById($valId) : null;
                // Only options used for variation form part of a combination. Descriptive attributes
                // are stored as wildcard entries in the vector and must not leak into the editor model,
                // otherwise existing combinations can never be matched against generator output.
                if ($optRow && !empty($optRow['used_for_variation'])) {
                    $attributesMap[$optRow['name']] = $valRow ? $valRow['label'] : '*';
                }
            }

            $variations[] = [
                'id'              => $varId,
                'sku'             => $rv['sku'] ?? '',
                'regular_price'   => $rv['regular_price'] !== null ? (string)(float)$rv['regular_price'] : '',
                'sale_price'      => $rv['sale_price'] !== null ? (string)(float)$rv['sale_price'] : '',
                'status'          => $rv['status'] ?? 'publish',
                'position'        => (int)($rv['position'] ?? 0),
                'manage_stock'    => !empty($rv['var_manage_stock']),
                'stock_quantity'  => $rv['var_quantity'] !== null ? (float)$rv['var_quantity'] : null,
                'stock_status'    => $rv['var_stock_status'] ?? 'instock',
                'combination_hex' => $rv['combination_hex'],
                'attributes'      => $attributesMap,
                'combination'     => $attributesMap,
                'inventory'       => [
                    'owner_id'       => $varId,
                    'manage_stock'   => !empty($rv['var_manage_stock']) ? 1 : 0,
                    'quantity'       => $rv['var_quantity'] !== null ? (float)$rv['var_quantity'] : null,
                    'stock_status'   => $rv['var_stock_status'] ?? 'instock',
                    'backorders'     => $rv['var_backorders'] ?? 'no',
                    'stock_owner_id' => !empty($rv['stock_owner_id']) ? (int)$rv['stock_owner_id'] : $productId,
                ],
                'image_url'       => $varImg ? ($varImg['source_url'] ?? null) : null,
                'attachment_id'   => !empty($varImg['attachment_id']) ? (int)$varImg['attachment_id'] : null,
                'weight'          => $rv['weight'] ?? '',
                'length'          => $rv['length'] ?? '',
                'width'           => $rv['width'] ?? '',
                'height'          => $rv['height'] ?? '',
                'description'     => $rv['description'] ?? '',
            ];
        }

        // Global product attributes taxonomy catalogue for dropdown
        $globalTaxonomies = [];
        if (function_exists('wc_get_attribute_taxonomies')) {
            $taxonomies = wc_get_attribute_taxonomies();
            foreach ($taxonomies as $t) {
                $taxName = wc_attribute_taxonomy_name($t->attribute_name);
                $terms = get_terms(['taxonomy' => $taxName, 'hide_empty' => false]);
                $termList = [];
                if (!is_wp_error($terms) && !empty($terms)) {
                    foreach ($terms as $term) {
                        $termList[] = [
                            'id'    => (int)$term->term_id,
                            'name'  => $term->name,
                            'slug'  => $term->slug,
                        ];
                    }
                }
                $globalTaxonomies[] = [
                    'label'    => $t->attribute_label,
                    'name'     => $taxName,
                    'slug'     => $t->attribute_name,
                    'type'     => $t->attribute_type,
                    'terms'    => $termList,
                ];
            }
        }

        return [
            'product' => [
                'id'            => (int)$product['id'],
                'title'         => $product['title'],
                'slug'          => $product['slug'],
                'type'          => $product['type'],
                'status'        => $product['status'],
                'sku'           => $product['sku'] ?? '',
                'version'       => (int)($product['version'] ?? 1),
                'updated_at'    => $product['updated_at'],
                'parent_stock'  => [
                    'manage_stock'   => !empty($parentInv['manage_stock']),
                    'stock_quantity' => $parentInv && $parentInv['quantity'] !== null ? (float)$parentInv['quantity'] : null,
                    'stock_status'   => $parentInv['stock_status'] ?? 'instock',
                    'backorders'     => $parentInv['backorders'] ?? 'no',
                ],
            ],
            'descriptive_attributes' => $descriptiveAttributes,
            'variation_options'      => $variationOptions,
            'variations'             => $variations,
            // `taxonomies` is the key consumed by the editor JS; `global_taxonomies` is kept as an alias.
            'taxonomies'             => $globalTaxonomies,
            'global_taxonomies'      => $globalTaxonomies,
        ];
    }

    /**
     * Preview all candidate combinations generated from options.
     */
    public function previewCombinations(int $productId, array $variationOptions): array
    {
        if (empty($variationOptions)) {
            return [
                'existing_combinations' => [],
                'new_combinations'      => [],
                'total_potential'       => 0,
            ];
        }

        // Build list of arrays for Cartesian product
        $dimensions = [];
        $optionNames = [];
        foreach ($variationOptions as $opt) {
            $name = trim((string)$opt['name']);
            $vals = [];
            foreach ((array)($opt['values'] ?? []) as $v) {
                $label = is_array($v) ? trim((string)($v['label'] ?? '')) : trim((string)$v);
                if ($label !== '') {
                    $vals[] = $label;
                }
            }

            if (!empty($name) && !empty($vals)) {
                $dimensions[$name] = array_values($vals);
                $optionNames[] = $name;
            }
        }

        if (empty($dimensions)) {
            return [
                'combinations'          => [],
                'existing_combinations' => [],
                'new_combinations'      => [],
                'total_potential'       => 0,
                'total_possible'        => 0,
                'total_new'             => 0,
                'new_count'             => 0,
                'total_existing'        => 0,
                'existing_count'        => 0,
            ];
        }

        $combinations = [[]];
        foreach ($dimensions as $optName => $values) {
            $tmp = [];
            foreach ($combinations as $c) {
                foreach ($values as $v) {
                    $tmp[] = array_merge($c, [$optName => $v]);
                }
            }
            $combinations = $tmp;
        }

        // Fetch existing variations for this product
        $existing = $this->getProductEditorData($productId)['variations'];
        $existingSet = [];
        foreach ($existing as $exVar) {
            $comboAttrs = $exVar['attributes'] ?? $exVar['combination'] ?? [];
            ksort($comboAttrs);
            $key = json_encode($comboAttrs, JSON_UNESCAPED_UNICODE);
            $existingSet[$key] = $exVar;
        }

        $existingCombinations = [];
        $newCombinations = [];

        foreach ($combinations as $comb) {
            ksort($comb);
            $key = json_encode($comb, JSON_UNESCAPED_UNICODE);

            if (isset($existingSet[$key])) {
                $item = [
                    'attributes'  => $comb,
                    'combination' => $comb,
                    'variant_id'  => $existingSet[$key]['id'],
                    'sku'         => $existingSet[$key]['sku'],
                    'exists'      => true,
                ];
                $existingCombinations[] = $item;
            } else {
                $item = [
                    'attributes'  => $comb,
                    'combination' => $comb,
                    'variant_id'  => null,
                    'sku'         => null,
                    'exists'      => false,
                ];
                $newCombinations[] = $item;
            }
        }

        $allCombinations = array_merge($existingCombinations, $newCombinations);

        return [
            'combinations'          => $allCombinations,
            'existing_combinations' => $existingCombinations,
            'new_combinations'      => $newCombinations,
            'total_potential'       => count($combinations),
            'total_possible'        => count($combinations),
            'total_new'             => count($newCombinations),
            'new_count'             => count($newCombinations),
            'total_existing'        => count($existingCombinations),
            'existing_count'        => count($existingCombinations),
        ];
    }

    /**
     * Project a price after a bulk action. Mirrors the client-side apply step in product-editor.js.
     *
     * @param mixed $current Current stored price ('' when unset).
     * @param mixed $action  Either {type, value} or a plain number meaning "set fixed price".
     * @return float|string  New price rounded to 2 decimals, or '' when the result is not positive.
     */
    private function projectPrice($current, $action)
    {
        if (!is_array($action)) {
            $action = ['type' => 'fixed', 'value' => $action];
        }
        $type = (string)($action['type'] ?? 'fixed');
        $value = is_numeric($action['value'] ?? null) ? (float)$action['value'] : 0.0;
        $old = is_numeric($current) ? (float)$current : 0.0;

        switch ($type) {
            case 'increase_fixed':
                $new = $old + $value;
                break;
            case 'decrease_fixed':
                $new = max(0.0, $old - $value);
                break;
            case 'increase_percent':
                $new = $old * (1 + $value / 100);
                break;
            case 'decrease_percent':
                $new = max(0.0, $old * (1 - $value / 100));
                break;
            case 'fixed':
            default:
                $new = $value;
                break;
        }

        return $new > 0 ? round($new, 2) : '';
    }

    /**
     * Preview bulk edit modifications across selected variation IDs before applying.
     */
    public function previewBulkEdit(int $productId, array $variationIds, array $actions): array
    {
        $editorData = $this->getProductEditorData($productId);
        $varMap = [];
        foreach ($editorData['variations'] as $v) {
            $varMap[$v['id']] = $v;
        }

        $diffs = [];
        foreach ($variationIds as $vid) {
            $vid = (int)$vid;
            if (!isset($varMap[$vid])) {
                continue;
            }

            $current = $varMap[$vid];
            $projected = $current;

            foreach (['regular_price', 'sale_price'] as $priceField) {
                if (isset($actions[$priceField]) && $actions[$priceField] !== '') {
                    $projected[$priceField] = $this->projectPrice($current[$priceField] ?? '', $actions[$priceField]);
                }
            }
            if (isset($actions['adjust_price_pct']) && is_numeric($actions['adjust_price_pct'])) {
                $projected['regular_price'] = $this->projectPrice($current['regular_price'] ?? '', [
                    'type'  => 'increase_percent',
                    'value' => (float)$actions['adjust_price_pct'],
                ]);
            }
            if (isset($actions['status'])) {
                $projected['status'] = $actions['status'];
            }
            if (isset($actions['manage_stock'])) {
                $projected['manage_stock'] = !empty($actions['manage_stock']);
            }
            // Same guard as the client apply step and the parent-stock rule: a quantity is only
            // meaningful for a variation that manages its own stock.
            if (isset($actions['stock_quantity']) && !empty($projected['manage_stock'])) {
                $projected['stock_quantity'] = is_numeric($actions['stock_quantity']) ? (float)$actions['stock_quantity'] : null;
            }
            if (isset($actions['stock_status'])) {
                $projected['stock_status'] = $actions['stock_status'];
            }
            if (isset($actions['image_url'])) {
                $projected['image_url'] = $actions['image_url'];
            }

            $changes = [];
            foreach (['regular_price', 'sale_price', 'status', 'manage_stock', 'stock_quantity', 'stock_status', 'image_url'] as $field) {
                $before = $current[$field] ?? null;
                $after = $projected[$field] ?? null;
                if (in_array($field, ['regular_price', 'sale_price'], true)) {
                    $before = ($before === '' || $before === null) ? '' : (float)$before;
                }
                if ($before !== $after) {
                    $changes[$field] = ['before' => $before, 'after' => $after];
                }
            }

            $diffs[] = [
                'variant_id' => $vid,
                'sku'        => $current['sku'],
                'attributes' => $current['attributes'],
                'changes'    => $changes,
                'before'     => $current,
                'after'      => $projected,
            ];
        }

        return [
            'affected_count' => count($diffs),
            'diffs'          => $diffs,
        ];
    }

    /**
     * Atomically save descriptive attributes, options cards, and variations matrix for a product.
     */
    public function saveProductVariants(int $productId, array $payload, int $actorId = 0): array
    {
        $currentProduct = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ), ARRAY_A);

        if (!$currentProduct) {
            throw new InvalidArgumentException("Product not found: {$productId}");
        }

        // Concurrency conflict check
        if (isset($payload['expected_version'])) {
            $expVer = (int)$payload['expected_version'];
            $currentVer = (int)$currentProduct['version'];
            if ($expVer !== $currentVer) {
                $latest = $this->getProductEditorData($productId);
                throw new ConcurrencyConflictException(
                    "Product data on server has been modified by another process (Version {$currentVer} vs {$expVer}).",
                    $latest
                );
            }
        }

        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->query("START TRANSACTION");

        try {
            // 1. Update product metadata & parent inventory
            $newVersion = (int)$currentProduct['version'] + 1;
            $pUpdates = ['updated_at' => $now, 'version' => $newVersion];
            if (isset($payload['product'])) {
                $pData = $payload['product'];
                if (isset($pData['sku'])) {
                    $pUpdates['sku'] = trim($pData['sku']) ?: null;
                }
                if (isset($pData['status'])) {
                    $pUpdates['status'] = $pData['status'];
                }
                if (isset($pData['parent_stock'])) {
                    $ps = $pData['parent_stock'];
                    $this->wpdb->replace($this->tableInventory, [
                        'owner_id'     => $productId,
                        'manage_stock' => !empty($ps['manage_stock']) ? 1 : 0,
                        'quantity'     => !empty($ps['manage_stock']) && isset($ps['stock_quantity']) ? (float)$ps['stock_quantity'] : null,
                        'stock_status' => $ps['stock_status'] ?? 'instock',
                        'backorders'   => $ps['backorders'] ?? 'no',
                        'version'      => 1,
                        'updated_at'   => $now,
                    ]);
                }
            }
            $this->wpdb->update($this->tableProducts, $pUpdates, ['id' => $productId]);

            // Shift positions of existing options up by 10000 to prevent unique key conflict during re-sequencing
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableOptions} SET position = position + 10000 WHERE product_id = %d",
                $productId
            ));

            $optPosition = 0;
            // 2. Sync Descriptive Attributes (used_for_variation = 0)
            if (isset($payload['descriptive_attributes']) && is_array($payload['descriptive_attributes'])) {
                $this->syncOptionGroup($productId, $payload['descriptive_attributes'], 0, $optPosition);
            }

            // 3. Sync Variation Options (used_for_variation = 1)
            if (isset($payload['variation_options']) && is_array($payload['variation_options'])) {
                $this->syncOptionGroup($productId, $payload['variation_options'], 1, $optPosition);
            }

            // 4. Sync Variations Matrix
            if (isset($payload['variations']) && is_array($payload['variations'])) {
                $this->syncVariations($productId, $payload['variations']);
            }

            // 5. Commit transaction
            $this->wpdb->query("COMMIT");

            // 6. Project canonical state to WooCommerce CPT and legacy tables.
            // The outcome is reported to the caller instead of being swallowed: the native save has
            // already committed, but a rejected projection (for example a stale-epoch fence) means the
            // WooCommerce shells are not updated and the operator must know.
            $projection = ['legacy_mirror' => 'unknown', 'catalog_index' => 'unknown'];
            try {
                $state = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT native_revision, native_applied, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
                    $productId
                ), ARRAY_A);
                $rev = $state ? max((int)$state['native_revision'], (int)$state['native_applied'] + 1) : 1;
                $workerEpoch = $state ? (int)$state['epoch'] : 1;
                $mirror = LegacyMirror::getInstance()->mirrorAggregate($productId, $rev, $workerEpoch);
                $projection['legacy_mirror'] = (string)($mirror['status'] ?? 'unknown');
            } catch (\Throwable $mE) {
                $projection['legacy_mirror'] = 'error';
            }

            // 7. Update catalog projector index
            try {
                (new CatalogProjector($this->wpdb))->buildNewGeneration();
                $projection['catalog_index'] = 'ok';
            } catch (\Throwable $cE) {
                $projection['catalog_index'] = 'error';
            }

            return [
                'success'     => true,
                'status'      => 'success',
                'message'     => 'Product attributes and variants saved successfully.',
                'new_version' => $newVersion,
                'projection'  => $projection,
                'data'        => $this->getProductEditorData($productId),
            ];
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    private function syncOptionGroup(int $productId, array $optionsList, int $usedForVariation, int &$pos): void
    {
        $existing = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, `key` FROM {$this->tableOptions} WHERE product_id = %d AND used_for_variation = %d",
            $productId,
            $usedForVariation
        ), ARRAY_A);
        $existingIds = array_map('intval', array_column($existing, 'id'));
        $existingByKey = [];
        foreach ($existing as $exRow) {
            $existingByKey[strtolower($exRow['key'])] = (int)$exRow['id'];
        }
        $touchedIds = [];

        foreach ($optionsList as $opt) {
            $name = trim((string)($opt['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $optKey = sanitize_title($opt['key'] ?? $name);
            $optId = !empty($opt['id']) ? (int)$opt['id'] : (isset($existingByKey[strtolower($optKey)]) ? $existingByKey[strtolower($optKey)] : 0);
            $optPayload = [
                'name'               => $name,
                'key'                => $optKey,
                'taxonomy'           => !empty($opt['taxonomy']) ? $opt['taxonomy'] : null,
                'position'           => $pos++,
                'visible'            => isset($opt['visible']) ? (!empty($opt['visible']) ? 1 : 0) : (isset($opt['is_visible']) ? (!empty($opt['is_visible']) ? 1 : 0) : 1),
                'used_for_variation' => $usedForVariation,
            ];

            if ($optId > 0 && in_array($optId, $existingIds, true)) {
                $this->optionsRepo->updateOption($optId, $optPayload);
                $touchedIds[] = $optId;
            } else {
                $created = $this->optionsRepo->createOption($productId, $optPayload);
                $optId = (int)$created['id'];
                $touchedIds[] = $optId;
            }

            // Sync option values
            $rawVals = $opt['values'] ?? [];
            $existingVals = $this->optionsRepo->getOptionValues($optId);
            $valByName = [];
            foreach ($existingVals as $ev) {
                $valByName[strtolower(trim($ev['label']))] = $ev;
            }

            $vPos = 0;
            $touchedValIds = [];
            foreach ($rawVals as $v) {
                $vLabel = is_array($v) ? trim((string)($v['label'] ?? '')) : trim((string)$v);
                if ($vLabel === '') continue;
                $lower = strtolower($vLabel);

                if (isset($valByName[$lower])) {
                    $valId = (int)$valByName[$lower]['id'];
                    $this->optionsRepo->updateOptionValue($valId, ['position' => $vPos++]);
                    $touchedValIds[] = $valId;
                } else {
                    $createdVal = $this->optionsRepo->createOptionValue($optId, [
                        'label'     => $vLabel,
                        'value_key' => sanitize_title($vLabel),
                        'position'  => $vPos++,
                    ]);
                    $touchedValIds[] = (int)$createdVal['id'];
                }
            }

            // Delete removed values for this option
            foreach ($existingVals as $ev) {
                $oldValId = (int)$ev['id'];
                if (!in_array($oldValId, $touchedValIds, true)) {
                    $this->wpdb->delete($this->tableOptionValues, ['id' => $oldValId]);
                    $this->wpdb->delete($this->tableVariantValues, ['value_id' => $oldValId]);
                }
            }
        }

        // Delete removed options in this group
        foreach ($existingIds as $oldOptId) {
            if (!in_array($oldOptId, $touchedIds, true)) {
                $this->optionsRepo->deleteOption($oldOptId);
            }
        }
    }

    private function syncVariations(int $productId, array $variations): void
    {
        $existing = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL",
            $productId
        ), ARRAY_A);
        $existingIds = array_map('intval', array_column($existing, 'id'));
        $touchedIds = [];

        $pos = 0;
        $now = gmdate('Y-m-d H:i:s.u');

        foreach ($variations as $varData) {
            $varId = !empty($varData['id']) ? (int)$varData['id'] : 0;
            $isNew = false;

            if ($varId <= 0 || !in_array($varId, $existingIds, true)) {
                $isNew = true;
                $allocator = new PostIdAllocator($this->wpdb);
                $varId = $allocator->allocateShell('product_variation');
            }

            $sku = trim((string)($varData['sku'] ?? '')) ?: null;
            $regularPrice = isset($varData['regular_price']) && is_numeric($varData['regular_price']) ? sprintf('%.2f', (float)$varData['regular_price']) : null;
            $salePrice = isset($varData['sale_price']) && is_numeric($varData['sale_price']) ? sprintf('%.2f', (float)$varData['sale_price']) : null;
            $status = $varData['status'] ?? 'publish';
            $manageStock = !empty($varData['manage_stock']) ? 1 : 0;
            $stockOwnerId = $manageStock ? $varId : $productId;

            if ($isNew) {
                $this->wpdb->insert($this->tableVariants, [
                    'id'               => $varId,
                    'product_id'       => $productId,
                    'sku'              => $sku,
                    'regular_price'    => $regularPrice,
                    'sale_price'       => $salePrice,
                    'status'           => $status,
                    'position'         => $pos++,
                    'stock_owner_id'   => $stockOwnerId,
                    'weight'           => $varData['weight'] ?? null,
                    'length'           => $varData['length'] ?? null,
                    'width'            => $varData['width'] ?? null,
                    'height'           => $varData['height'] ?? null,
                    'description'      => $varData['description'] ?? '',
                    'version'          => 1,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
            } else {
                $this->wpdb->update($this->tableVariants, [
                    'sku'              => $sku,
                    'regular_price'    => $regularPrice,
                    'sale_price'       => $salePrice,
                    'status'           => $status,
                    'position'         => $pos++,
                    'stock_owner_id'   => $stockOwnerId,
                    'weight'           => $varData['weight'] ?? null,
                    'length'           => $varData['length'] ?? null,
                    'width'            => $varData['width'] ?? null,
                    'height'           => $varData['height'] ?? null,
                    'description'      => $varData['description'] ?? '',
                    'updated_at'       => $now,
                ], ['id' => $varId]);
            }

            // Sync SKU in registry
            if ($sku) {
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$this->tableSkuRegistry} (normalized_sku, object_id, object_kind, original_sku)
                     VALUES (%s, %d, 'variation', %s)
                     ON DUPLICATE KEY UPDATE object_id = VALUES(object_id), object_kind = VALUES(object_kind), original_sku = VALUES(original_sku)",
                    $sku, $varId, $sku
                ));
            }

            // Assign combination vector
            $combo = $varData['combination'] ?? $varData['attributes'] ?? [];
            if (!empty($combo) && is_array($combo)) {
                try {
                    $this->optionsRepo->assignVariantCombination($productId, $varId, $combo);
                } catch (\Throwable $cE) {
                    // Ignore or log combination assignment error
                }
            }

            // Sync inventory
            $stockQty = $manageStock && isset($varData['stock_quantity']) && is_numeric($varData['stock_quantity']) ? (float)$varData['stock_quantity'] : null;
            $stockStatus = $varData['stock_status'] ?? 'instock';

            $this->wpdb->replace($this->tableInventory, [
                'owner_id'     => $varId,
                'manage_stock' => $manageStock,
                'quantity'     => $stockQty,
                'stock_status' => $stockStatus,
                'backorders'   => $varData['backorders'] ?? 'no',
                'version'      => 1,
                'updated_at'   => $now,
            ]);

            // Sync variant image
            if (!empty($varData['image_url'])) {
                $this->attachVariantImage($varId, $varData['image_url']);
            } elseif (array_key_exists('image_url', $varData) && empty($varData['image_url'])) {
                $this->wpdb->delete($this->tableVariantMedia, ['variant_id' => $varId]);
            }

            $touchedIds[] = $varId;
        }

        // Soft-delete removed variations (tombstone)
        foreach ($existingIds as $oldVarId) {
            if (!in_array($oldVarId, $touchedIds, true)) {
                $this->wpdb->update($this->tableVariants, [
                    'status'                 => 'trash',
                    'deleted_at'             => $now,
                    'active_combination_key' => null,
                    'updated_at'             => $now,
                ], ['id' => $oldVarId]);

                $this->wpdb->update($this->tableObjectState, [
                    'tombstone'  => 1,
                    'updated_at' => $now,
                ], ['object_id' => $oldVarId]);
            }
        }
    }

    private function attachVariantImage(int $variantId, string $url): void
    {
        $clean = esc_url_raw(trim($url));
        if ($clean === '') return;

        if (str_starts_with($clean, 'http://')) {
            $clean = 'https://' . substr($clean, 7);
        }

        try {
            $asset = $this->assetService->registerAsset($clean);
            $this->assetService->attachVariantImage($variantId, (int)$asset['id'], 0, true);
            $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
            update_post_meta($attachmentId, '_wc_attachment_source', $clean);
        } catch (\Throwable $e) {
            // Non-fatal asset registration catch
        }
    }
}
