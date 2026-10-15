<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use RuntimeException;
use InvalidArgumentException;
use wpdb;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\DuplicateCombinationException;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Application\Migration\LegacyMirror;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Media\ProductMediaLibrary;
use WPE\Application\Media\UploadRejectedException;
use WPE\Application\Variant\EffectivePriceResolver;
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
    private ?ProductMediaLibrary $library = null;
    private ?PricingService $pricing = null;
    private ?ProductInfoService $info = null;
    private ?VariantRulesService $rules = null;
    private ?ProductTypeTransition $transition = null;

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
            $initType = 'simple';
            $terms = wp_get_object_terms($productId, 'product_type');
            if (!is_wp_error($terms) && !empty($terms)) {
                $termSlugs = wp_list_pluck($terms, 'slug');
                if (in_array('variable', $termSlugs, true)) {
                    $initType = 'variable';
                }
            }
            $this->wpdb->insert($this->tableProducts, [
                'id'                 => $productId,
                'title'              => $post->post_title ?: 'Untitled Product',
                'slug'               => $post->post_name ?: 'product-' . $productId,
                'type'               => $initType,
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

            $epoch = LegacyMirror::getInstance()->getGlobalEpoch();
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->tableObjectState}
                 (object_id, kind, parent_id, authority, epoch, legacy_revision, native_revision, legacy_applied, native_applied, tombstone, updated_at)
                 VALUES (%d, 'product', NULL, 'native', %d, 1, 1, 0, 0, 0, %s)
                 ON DUPLICATE KEY UPDATE authority = 'native', updated_at = VALUES(updated_at), tombstone = 0",
                $productId,
                $epoch,
                $now
            ));

            $product = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableProducts} WHERE id = %d",
                $productId
            ), ARRAY_A);
        } else {
            $post = get_post($productId);
            if ($post && $post->post_type === 'product') {
                $syncFields = [];
                if (($product['status'] ?? '') === 'auto-draft' && $post->post_status !== '' && $post->post_status !== 'auto-draft') {
                    $syncFields['status'] = $post->post_status;
                    $product['status'] = $post->post_status;
                }
                if (in_array($product['title'] ?? '', ['', 'Auto Draft', 'Untitled Product'], true) && $post->post_title !== '' && $post->post_title !== 'Auto Draft') {
                    $syncFields['title'] = $post->post_title;
                    $product['title'] = $post->post_title;
                }
                if (
                    (empty($product['slug']) || $product['slug'] === 'auto-draft' || str_starts_with((string)$product['slug'], 'product-'))
                    && $post->post_name !== ''
                    && $post->post_name !== 'auto-draft'
                ) {
                    $syncFields['slug'] = $post->post_name;
                    $product['slug'] = $post->post_name;
                }
                if (!empty($syncFields)) {
                    $this->wpdb->update($this->tableProducts, $syncFields, ['id' => $productId]);
                }
            }
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
        $productExtraProps = json_decode((string)($product['extra_props'] ?? ''), true);
        if (!is_array($productExtraProps)) {
            $productExtraProps = [];
        }
        $optionUiMap = is_array($productExtraProps['option_ui'] ?? null) ? $productExtraProps['option_ui'] : [];

        foreach ($allOptions as $opt) {
            $optId = (int)$opt['id'];
            $optUi = is_array($optionUiMap[(string)$optId] ?? null)
                ? $optionUiMap[(string)$optId]
                : (is_array($optionUiMap[$optId] ?? null) ? $optionUiMap[$optId] : []);
            $displayType = (string)($optUi['display_type'] ?? 'auto');
            if (!in_array($displayType, ['auto', 'dropdown', 'button', 'color', 'image'], true)) {
                $displayType = 'auto';
            }
            $helpText = (string)($optUi['help_text'] ?? '');

            $rawDefValId = !empty($opt['default_value_id']) ? (int)$opt['default_value_id'] : null;
            $defValLabel = null;
            $mappedValues = array_map(function ($v) use ($rawDefValId, &$defValLabel) {
                $vId = (int)$v['id'];
                if ($rawDefValId !== null && $vId === $rawDefValId) {
                    $defValLabel = (string)$v['label'];
                }
                $swatchData = !empty($v['swatch']) ? (json_decode((string)$v['swatch'], true) ?: []) : [];
                if (!is_array($swatchData)) {
                    $swatchData = [];
                }
                $color = isset($swatchData['color']) && is_string($swatchData['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $swatchData['color'])
                    ? strtolower($swatchData['color'])
                    : '';
                return [
                    'id'        => $vId,
                    'label'     => $v['label'],
                    'value_key' => $v['value_key'],
                    'position'  => (int)$v['position'],
                    'color'     => $color,
                    'swatch'    => $swatchData,
                ];
            }, $opt['values'] ?? []);
            $defValId = $defValLabel !== null ? $rawDefValId : null;

            $optItem = [
                'id'                 => $optId,
                'name'               => $opt['name'],
                'key'                => $opt['key'],
                'taxonomy'           => $opt['taxonomy'],
                'position'           => (int)$opt['position'],
                'visible'            => !empty($opt['visible']),
                'used_for_variation' => !empty($opt['used_for_variation']),
                'default_value_id'   => $defValId,
                'default_value'      => $defValLabel,
                'display_type'       => $displayType,
                'help_text'          => $helpText,
                'values'             => $mappedValues,
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

        $productManagesStock = !empty($parentInv['manage_stock']);
        $variations = [];
        foreach ($rawVariants as $rv) {
            $varId = (int)$rv['id'];
            $vector = $this->optionsRepo->getVariantCombinationVector($varId);

            // Fetch variant image
            $varImg = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT a.id AS asset_id, a.source_url, a.attachment_id
                 FROM {$this->tableVariantMedia} vm
                 JOIN {$this->tableAssets} a ON vm.asset_id = a.id
                 WHERE vm.variant_id = %d
                 ORDER BY vm.is_primary DESC, vm.position ASC, vm.asset_id ASC
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

            $priceInfo = EffectivePriceResolver::resolve($rv);

            $variations[] = [
                'id'              => $varId,
                'sku'             => $rv['sku'] ?? '',
                'regular_price'   => $rv['regular_price'] !== null ? (string)(float)$rv['regular_price'] : '',
                'sale_price'      => $rv['sale_price'] !== null ? (string)(float)$rv['sale_price'] : '',
                'status'          => $rv['status'] ?? 'publish',
                'position'        => (int)($rv['position'] ?? 0),
                'manage_stock'    => !empty($rv['var_manage_stock']),
                'inventory_mode'  => VariantRulesService::inventoryMode(!empty($rv['var_manage_stock']), $productManagesStock),
                'stock_quantity'  => $rv['var_quantity'] !== null ? (float)$rv['var_quantity'] : null,
                'version'         => (int)($rv['version'] ?? 1),
                'stock_status'    => $rv['var_stock_status'] ?? 'instock',
                'backorders'      => $rv['var_backorders'] ?? 'no',
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
                'image_asset_id'  => $varImg ? (string)(int)$varImg['asset_id'] : null,
                'attachment_id'   => !empty($varImg['attachment_id']) ? (int)$varImg['attachment_id'] : null,
                'sale_from'       => !empty($rv['sale_from']) ? substr((string)$rv['sale_from'], 0, 19) : null,
                'sale_to'         => !empty($rv['sale_to']) ? substr((string)$rv['sale_to'], 0, 19) : null,
                'pricing_mode'    => $priceInfo['mode'],
                'effective_price' => [
                    'regular'      => $priceInfo['regular'],
                    'sale'         => $priceInfo['sale'],
                    'active_price' => $priceInfo['active_price'],
                    'sale_active'  => $priceInfo['sale_active'],
                    'source'       => $priceInfo['source'],
                    'state'        => $priceInfo['state'],
                ],
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

        $infoBlock = $this->info()->read($product, $parentInv ?: null);

        return [
            'product' => $infoBlock + [
                'id'            => (int)$product['id'],
                'title'         => $product['title'],
                'slug'          => $product['slug'],
                'type'          => $product['type'],
                'derived_type'  => ProductTypeTransition::deriveType($variationOptions),
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
            // Shared price of the product (or null) and the read-only reference price (docs/04 §8).
            'type'                   => $product['type'],
            'derived_type'           => ProductTypeTransition::deriveType($variationOptions),
            'common_price'           => PricingService::readCommonPrice($product['extra_props'] ?? null),
            'reference_price'        => $this->pricing()->referencePrice($productId),
            'descriptive_attributes' => $descriptiveAttributes,
            'variation_options'      => $variationOptions,
            'variations'             => $variations,
            'media_library'          => $this->library()->read($productId, (int)get_current_user_id()),
            // `taxonomies` is the key consumed by the editor JS; `global_taxonomies` is kept as an alias.
            'taxonomies'             => $globalTaxonomies,
            'global_taxonomies'      => $globalTaxonomies,
        ];
    }

    /**
     * Preview the combinations the options can form, which of them exist and which a selection would create.
     *
     * Creating every combination is not required: pass `$selected` (a list of combination maps) to preview exactly
     * what a partial generation would do. Existing variations keep their IDs and data whether or not they are in
     * the selection, and variations that no longer fit the options are counted, never removed.
     *
     * @param array<int,array<string,string>>|null $selected
     * @throws EditorValidationException when the options form more than VariantRulesService::MAX_COMBINATIONS.
     */
    public function previewCombinations(int $productId, array $variationOptions, ?array $selected = null): array
    {
        $dimensions = [];
        foreach ($variationOptions as $opt) {
            $name = trim((string)($opt['name'] ?? ''));
            $vals = [];
            $seen = [];
            foreach ((array)($opt['values'] ?? []) as $v) {
                $label = is_array($v) ? trim((string)($v['label'] ?? '')) : trim((string)$v);
                if ($label !== '' && !isset($seen[VariantRulesService::norm($label)])) {
                    $seen[VariantRulesService::norm($label)] = true;
                    $vals[] = $label;
                }
            }
            if ($name !== '' && $vals !== []) {
                $dimensions[$name] = $vals;
            }
        }

        $existing = $this->getProductEditorData($productId)['variations'];
        $existingByKey = [];
        foreach ($existing as $exVar) {
            $existingByKey[VariantRulesService::comboKey($exVar['attributes'] ?? $exVar['combination'] ?? [])] = $exVar;
        }

        if ($dimensions === []) {
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
                'kept_existing_count'   => count($existing),
                'selection'             => null,
            ];
        }

        $total = 1;
        foreach ($dimensions as $values) {
            $total *= count($values);
            if ($total > VariantRulesService::MAX_COMBINATIONS) {
                throw new EditorValidationException(
                    'too_many_combinations',
                    'The options form too many combinations to list. Select specific combinations instead.',
                    [['pointer' => '/variation_options', 'reason' => 'too_many_combinations']],
                    ['limit' => VariantRulesService::MAX_COMBINATIONS]
                );
            }
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

        $existingCombinations = [];
        $newCombinations = [];
        $matchedKeys = [];
        foreach ($combinations as $comb) {
            $key = VariantRulesService::comboKey($comb);
            if (isset($existingByKey[$key])) {
                $matchedKeys[$key] = true;
                $existingCombinations[] = [
                    'attributes'  => $comb,
                    'combination' => $comb,
                    'variant_id'  => $existingByKey[$key]['id'],
                    'sku'         => $existingByKey[$key]['sku'],
                    'exists'      => true,
                ];
            } else {
                $newCombinations[] = [
                    'attributes'  => $comb,
                    'combination' => $comb,
                    'variant_id'  => null,
                    'sku'         => null,
                    'exists'      => false,
                ];
            }
        }

        $result = [
            'combinations'          => array_merge($existingCombinations, $newCombinations),
            'existing_combinations' => $existingCombinations,
            'new_combinations'      => $newCombinations,
            'total_potential'       => count($combinations),
            'total_possible'        => count($combinations),
            'total_new'             => count($newCombinations),
            'new_count'             => count($newCombinations),
            'total_existing'        => count($existingCombinations),
            'existing_count'        => count($existingCombinations),
            // Saved variations that the options do not describe (kept as they are).
            'kept_existing_count'   => count($existing) - count($matchedKeys),
            'selection'             => null,
        ];

        if ($selected !== null) {
            $validKeys = [];
            foreach ($combinations as $comb) {
                $validKeys[VariantRulesService::comboKey($comb)] = $comb;
            }
            $willCreate = [];
            $alreadyExist = [];
            $invalid = [];
            $picked = [];
            foreach ($selected as $i => $combo) {
                $combo = is_array($combo) ? $combo : [];
                $key = VariantRulesService::comboKey($combo);
                if (isset($picked[$key])) {
                    $invalid[] = ['index' => $i, 'combination' => $combo, 'reason' => 'duplicate_selection'];
                    continue;
                }
                $picked[$key] = true;
                if (!isset($validKeys[$key])) {
                    $invalid[] = ['index' => $i, 'combination' => $combo, 'reason' => 'unknown_value'];
                } elseif (isset($existingByKey[$key])) {
                    $alreadyExist[] = ['combination' => $validKeys[$key], 'variant_id' => $existingByKey[$key]['id']];
                } else {
                    $willCreate[] = ['combination' => $validKeys[$key]];
                }
            }
            $result['selection'] = [
                'requested'      => count($selected),
                'will_create'    => $willCreate,
                'already_exist'  => $alreadyExist,
                'invalid'        => $invalid,
                'create_count'   => count($willCreate),
                'expected_total' => count($existing) + count($willCreate),
            ];
        }

        return $result;
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

        if (isset($actions['image_asset_id'])) {
            // Same rule as the save: a variation image comes from this product's library.
            $this->library()->assertInLibrary($productId, [(int)$actions['image_asset_id']], [], '/actions/image_asset_id');
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
            // A shared-price row that gets its own price becomes an own-price row (never silently both).
            $pricesTouched = (isset($actions['regular_price']) && $actions['regular_price'] !== '')
                || (isset($actions['sale_price']) && $actions['sale_price'] !== '')
                || (isset($actions['adjust_price_pct']) && is_numeric($actions['adjust_price_pct']));
            if ($pricesTouched && ($current['pricing_mode'] ?? 'own') === 'shared') {
                $projected['pricing_mode'] = 'own';
            }
            if (isset($actions['inventory_mode']) && in_array($actions['inventory_mode'], [VariantRulesService::MODE_OWN, VariantRulesService::MODE_SHARED, VariantRulesService::MODE_UNTRACKED], true)) {
                $projected['manage_stock'] = $actions['inventory_mode'] === VariantRulesService::MODE_OWN;
                $projected['inventory_mode'] = $actions['inventory_mode'];
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
            if (isset($actions['image_asset_id'])) {
                $projected['image_asset_id'] = (string)(int)$actions['image_asset_id'];
            }

            $changes = [];
            foreach (['regular_price', 'sale_price', 'pricing_mode', 'status', 'manage_stock', 'stock_quantity', 'stock_status', 'image_url', 'image_asset_id'] as $field) {
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
     * Preview what changing the product-level stock policy does to the variations that inherit it.
     *
     * Turning product stock management off makes shared variations untracked; turning it on makes untracked
     * variations share it. Variations that manage their own stock are never affected.
     *
     * @param array $productStock {manage_stock?, stock_quantity?}
     */
    public function previewStockPolicy(int $productId, array $productStock): array
    {
        $data = $this->getProductEditorData($productId);
        $current = $data['product']['parent_stock'];
        $manage = array_key_exists('manage_stock', $productStock) ? !empty($productStock['manage_stock']) : (bool)$current['manage_stock'];
        $quantity = array_key_exists('stock_quantity', $productStock) && is_numeric($productStock['stock_quantity'])
            ? (float)$productStock['stock_quantity']
            : $current['stock_quantity'];

        $affected = [];
        $own = 0;
        foreach ($data['variations'] as $row) {
            if ($row['inventory_mode'] === VariantRulesService::MODE_OWN) {
                $own++;
                continue;
            }
            $to = VariantRulesService::inventoryMode(false, $manage);
            if ($to !== $row['inventory_mode']) {
                $affected[] = [
                    'variant_id' => (int)$row['id'],
                    'label'      => implode(' / ', array_map('strval', $row['combination'])),
                    'from'       => $row['inventory_mode'],
                    'to'         => $to,
                ];
            }
        }

        $effect = 'none';
        if ($affected !== []) {
            $effect = $manage ? 'untracked_become_shared' : 'shared_become_untracked';
        }

        return [
            'current'                  => ['manage_stock' => (bool)$current['manage_stock'], 'stock_quantity' => $current['stock_quantity']],
            'proposed'                 => ['manage_stock' => $manage, 'stock_quantity' => $manage ? $quantity : null],
            'effect'                   => $effect,
            'affected_variations'      => $affected,
            'own_stock_unchanged_count' => $own,
            'requires_confirmation'    => $affected !== [],
        ];
    }

    /**
     * Preview a bulk edit for a scope: the ids of the current page, or a filter resolved here over the full saved set.
     *
     * The result carries the resolved id list, its count and a token; apply uses that id list and token, so the scope
     * cannot grow between preview and apply. Variations that share one stock owner are grouped and the owner is
     * written once.
     */
    public function previewBulk(int $productId, array $scope, array $actions): array
    {
        $data = $this->getProductEditorData($productId);
        $resolved = $this->rules()->resolveScope($productId, (int)$data['product']['version'], $scope, $data['variations']);
        $preview = $this->previewBulkEdit($productId, $resolved['ids'], $actions);

        $rows = array_values(array_filter($data['variations'], static fn(array $r): bool => in_array((int)$r['id'], $resolved['ids'], true)));
        $groups = VariantRulesService::stockOwnerGroups($rows, $productId);
        $stockWrites = [];
        $skipped = [];
        if (isset($actions['stock_quantity']) && is_numeric($actions['stock_quantity'])) {
            foreach ($groups as $group) {
                $before = $group['owner_kind'] === 'product'
                    ? $data['product']['parent_stock']['stock_quantity']
                    : ($this->variationById($data, $group['owner_id'])['stock_quantity'] ?? null);
                $stockWrites[] = $group + ['quantity_before' => $before, 'quantity_after' => (float)$actions['stock_quantity']];
            }
            foreach ($rows as $row) {
                if ($row['inventory_mode'] === VariantRulesService::MODE_UNTRACKED) {
                    $skipped[] = ['variant_id' => (int)$row['id'], 'reason' => 'untracked_stock'];
                }
            }
        }

        return $preview + [
            'scope'        => $resolved,
            'stock_groups' => $groups,
            'stock_writes' => $stockWrites,
            'skipped'      => $skipped,
        ];
    }

    /**
     * Apply a previewed bulk edit through the one save path.
     *
     * The ids and the token must be the ones previewBulk issued for the current product version. The edit is merged
     * into the saved rows and saved with saveProductVariants, so pricing, stock and image rules are the same as for
     * any other save and the shared stock owner is written once.
     *
     * @param int[] $ids
     */
    public function applyBulkEdit(int $productId, array $ids, string $token, array $actions, int $actorId = 0): array
    {
        $data = $this->getProductEditorData($productId);
        $version = (int)$data['product']['version'];
        $this->rules()->assertScopeToken($productId, $version, $ids, $token);
        // The ids must still be saved variations of this product.
        $resolved = $this->rules()->resolveScope($productId, $version, ['type' => 'ids', 'ids' => $ids], $data['variations']);

        $preview = $this->previewBulkEdit($productId, $resolved['ids'], $actions);
        $afterById = [];
        foreach ($preview['diffs'] as $diff) {
            $afterById[(int)$diff['variant_id']] = $diff['after'];
        }

        $scoped = array_flip($resolved['ids']);
        $rows = [];
        foreach ($data['variations'] as $row) {
            $vid = (int)$row['id'];
            $src = isset($scoped[$vid]) ? ($afterById[$vid] ?? $row) : $row;
            $emptyToNull = static fn($v) => ($v === '' || $v === null) ? null : $v;
            $out = [
                'id'             => $vid,
                'sku'            => $row['sku'],
                'combination'    => $row['combination'],
                'regular_price'  => $emptyToNull($src['regular_price'] ?? null),
                'sale_price'     => $emptyToNull($src['sale_price'] ?? null),
                'sale_from'      => $row['sale_from'],
                'sale_to'        => $row['sale_to'],
                'pricing_mode'   => $src['pricing_mode'] ?? $row['pricing_mode'],
                'status'         => $src['status'] ?? $row['status'],
                'stock_status'   => $src['stock_status'] ?? $row['stock_status'],
                'backorders'     => $row['backorders'],
                'weight'         => $emptyToNull($row['weight']),
                'length'         => $emptyToNull($row['length']),
                'width'          => $emptyToNull($row['width']),
                'height'         => $emptyToNull($row['height']),
                'description'    => $row['description'],
                'image_asset_id' => $src['image_asset_id'] ?? $row['image_asset_id'],
            ];
            $mode = isset($scoped[$vid]) && isset($src['inventory_mode']) ? $src['inventory_mode'] : $row['inventory_mode'];
            $out['inventory_mode'] = $mode;
            if ($mode === VariantRulesService::MODE_OWN) {
                $out['stock_quantity'] = $src['stock_quantity'] ?? $row['stock_quantity'];
            }
            $rows[] = $out;
        }

        $payload = ['expected_version' => $version, 'variations' => $rows];

        // A shared stock owner (the product) is written once, whatever the number of variations that inherit it.
        $groups = VariantRulesService::stockOwnerGroups(
            array_values(array_filter($data['variations'], static fn(array $r): bool => isset($scoped[(int)$r['id']]))),
            $productId
        );
        $writesProduct = false;
        foreach ($groups as $group) {
            $writesProduct = $writesProduct || $group['owner_kind'] === 'product';
        }
        if ($writesProduct && isset($actions['stock_quantity']) && is_numeric($actions['stock_quantity'])) {
            $payload['product'] = ['stock' => ['manage_stock' => true, 'stock_quantity' => (float)$actions['stock_quantity']]];
        }

        $result = $this->saveProductVariants($productId, $payload, $actorId);
        $result['bulk'] = ['ids' => $resolved['ids'], 'count' => $resolved['count'], 'stock_groups' => $groups];

        return $result;
    }

    private function variationById(array $data, int $id): array
    {
        foreach ($data['variations'] as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }

        return [];
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
            $post = get_post($productId);
            if ($post && $post->post_type === 'product') {
                $this->getProductEditorData($productId);
                $currentProduct = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
                    $productId
                ), ARRAY_A);
            }
        }

        if (!$currentProduct) {
            throw new InvalidArgumentException("Product not found: {$productId}");
        }

        // 1. Idempotency via operation_key
        $opKey = isset($payload['operation_key']) && is_string($payload['operation_key']) && trim($payload['operation_key']) !== ''
            ? trim($payload['operation_key'])
            : null;
        if ($opKey !== null) {
            $cached = get_transient("wpe_save_op_{$productId}_{$opKey}");
            if (is_array($cached)) {
                return $cached;
            }
        }

        // 2. Concurrency conflict checks (product version and variation versions)
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

        if (isset($payload['variations']) && is_array($payload['variations'])) {
            $rawVarVersions = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, version FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL",
                $productId
            ), ARRAY_A) ?: [];
            $varVersionMap = [];
            foreach ($rawVarVersions as $rvv) {
                $varVersionMap[(int)$rvv['id']] = (int)$rvv['version'];
            }
            foreach ($payload['variations'] as $vIndex => $varData) {
                $vId = !empty($varData['id']) ? (int)$varData['id'] : 0;
                if ($vId > 0 && isset($varVersionMap[$vId])) {
                    $expVarVer = isset($varData['expected_version']) ? (int)$varData['expected_version'] : (isset($varData['version']) ? (int)$varData['version'] : null);
                    $curVarVer = $varVersionMap[$vId];
                    if ($expVarVer !== null && $expVarVer !== $curVarVer) {
                        $latest = $this->getProductEditorData($productId);
                        throw new ConcurrencyConflictException(
                            "Variation {$vId} on server has been modified by another process (Version {$curVarVer} vs {$expVarVer}).",
                            $latest
                        );
                    }
                }
            }
        }

        // 3. Type transition planning
        $transitionPlan = $this->transition()->plan($productId, $currentProduct, $payload);
        if ($transitionPlan['changed'] && $transitionPlan['to'] === 'variable' && $transitionPlan['common_price_patch'] !== null) {
            if (!array_key_exists('common_price', $payload)) {
                $payload['common_price'] = $transitionPlan['common_price_patch'];
            }
        }
        $effectiveType = $transitionPlan['changed'] ? $transitionPlan['to'] : (string)$currentProduct['type'];

        // 4. Validate attribute names and product info plan
        if (isset($payload['descriptive_attributes']) || isset($payload['variation_options'])) {
            ProductInfoService::validateAttributes(
                isset($payload['descriptive_attributes']) && is_array($payload['descriptive_attributes']) ? $payload['descriptive_attributes'] : null,
                isset($payload['variation_options']) && is_array($payload['variation_options']) ? $payload['variation_options'] : null
            );
        }
        $infoPlan = null;
        if (isset($payload['product']) && is_array($payload['product'])) {
            $infoPlan = $this->info()->plan(
                $productId,
                $currentProduct,
                $payload['product'],
                $effectiveType,
                isset($payload['variations']) && is_array($payload['variations']) ? $payload['variations'] : null
            );
        }

        // 5. Structure plan (resolves saved IDs, rejects duplicates and in-use removal)
        $tombstonedByTransition = ($transitionPlan['changed'] && $transitionPlan['to'] === 'simple')
            ? $transitionPlan['tombstone_ids']
            : [];
        $structure = $this->rules()->planStructure($productId, $payload, $tombstonedByTransition);
        if (isset($payload['variations']) && is_array($payload['variations'])) {
            foreach ($structure['variation_ids'] as $index => $matchedId) {
                if ($matchedId > 0 && is_array($payload['variations'][$index])) {
                    $payload['variations'][$index]['id'] = $matchedId;
                }
            }
        }

        // 6. Pricing plan
        $pricingPlan = $this->pricing()->planSave($productId, $currentProduct, $payload);

        // 7. Inventory plan
        $inventoryPlan = [];
        if (isset($payload['variations']) && is_array($payload['variations']) && $effectiveType === 'variable') {
            if ($infoPlan !== null && $infoPlan['inventory'] !== null) {
                $productManagesStock = !empty($infoPlan['inventory']['manage_stock']);
            } else {
                $productManagesStock = (bool)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT manage_stock FROM {$this->tableInventory} WHERE owner_id = %d",
                    $productId
                ));
            }
            $inventoryPlan = $this->rules()->planInventory($payload['variations'], $structure['variation_ids'], $productManagesStock);
        }

        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->query("START TRANSACTION");

        try {
            $transitionReport = null;
            if ($transitionPlan['changed']) {
                $transitionReport = $this->transition()->apply($productId, $transitionPlan, $now);
            }

            // 1. Update product metadata & parent inventory
            $newVersion = (int)$currentProduct['version'] + 1;
            $pUpdates = ['updated_at' => $now, 'version' => $newVersion];
            if ($transitionPlan['changed']) {
                $pUpdates['type'] = $transitionPlan['to'];
            }
            if (isset($payload['product']) && is_array($payload['product'])) {
                $pData = $payload['product'];
                if (isset($pData['status']) && $pData['status'] !== 'auto-draft') {
                    $pUpdates['status'] = $pData['status'];
                }
            }
            $wpPost = get_post($productId);
            if ($wpPost && $wpPost->post_type === 'product') {
                if ($wpPost->post_status !== '' && $wpPost->post_status !== 'auto-draft' && (($currentProduct['status'] ?? '') === 'auto-draft' || ($pUpdates['status'] ?? '') === 'auto-draft')) {
                    $pUpdates['status'] = $wpPost->post_status;
                }
                if ($wpPost->post_title !== '' && $wpPost->post_title !== 'Auto Draft' && !isset($pUpdates['title']) && in_array($currentProduct['title'] ?? '', ['', 'Auto Draft', 'Untitled Product'], true)) {
                    $pUpdates['title'] = $wpPost->post_title;
                }
                if (
                    $wpPost->post_name !== ''
                    && $wpPost->post_name !== 'auto-draft'
                    && !isset($pUpdates['slug'])
                    && (empty($currentProduct['slug']) || $currentProduct['slug'] === 'auto-draft' || str_starts_with((string)$currentProduct['slug'], 'product-'))
                ) {
                    $pUpdates['slug'] = $wpPost->post_name;
                }
            }
            if ($infoPlan !== null) {
                $pUpdates = array_merge($pUpdates, $infoPlan['columns']);
                if ($infoPlan['inventory'] !== null) {
                    $inv = $infoPlan['inventory'];
                    $invVersion = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT version FROM {$this->tableInventory} WHERE owner_id = %d",
                        $productId
                    ));
                    $invRow = [
                        'manage_stock'     => $inv['manage_stock'],
                        'quantity'         => $inv['quantity'],
                        'stock_status'     => $inv['stock_status'],
                        'backorders'       => $inv['backorders'],
                        'low_stock_amount' => $inv['low_stock_amount'],
                        'updated_at'       => $now,
                    ];
                    if ($invVersion === null) {
                        $this->wpdb->insert($this->tableInventory, $invRow + ['owner_id' => $productId, 'version' => 1]);
                    } else {
                        $this->wpdb->update($this->tableInventory, $invRow + ['version' => (int)$invVersion + 1], ['owner_id' => $productId]);
                    }
                }
                if ($infoPlan['sku']['changed']) {
                    $this->info()->applySkuRegistry($productId, $infoPlan['sku']['value']);
                }
            }
            if ($pricingPlan['common_provided']) {
                $currentExtra = json_decode((string)($currentProduct['extra_props'] ?? ''), true) ?: [];
                $pUpdates['extra_props'] = json_encode(
                    PricingService::withCommonPrice($currentExtra, $pricingPlan['common']),
                    JSON_UNESCAPED_UNICODE
                );
            }
            $this->wpdb->update($this->tableProducts, $pUpdates, ['id' => $productId]);

            // Options are re-sequenced only when the request carries option lists.
            $syncDescriptive = isset($payload['descriptive_attributes']) && is_array($payload['descriptive_attributes']);
            $syncVariationOptions = isset($payload['variation_options']) && is_array($payload['variation_options']);
            if ($syncDescriptive || $syncVariationOptions) {
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->tableOptions} SET position = position + 10000 WHERE product_id = %d",
                    $productId
                ));

                $optPosition = 0;
                if ($syncDescriptive) {
                    $this->syncOptionGroup($productId, $payload['descriptive_attributes'], 0, $optPosition);
                } else {
                    $this->renumberOptionGroup($productId, 0, $optPosition);
                }

                if ($syncVariationOptions) {
                    $this->syncOptionGroup($productId, $payload['variation_options'], 1, $optPosition);
                } else {
                    $this->renumberOptionGroup($productId, 1, $optPosition);
                }
            }

            // Sync Product Media Library draft if provided
            if (isset($payload['media_library']) && is_array($payload['media_library'])) {
                $this->syncDraftMediaLibrary($productId, $payload['media_library'], $actorId);
            }

            // Sync Variations Matrix
            if ($effectiveType === 'variable' && isset($payload['variations']) && is_array($payload['variations'])) {
                $this->syncVariations($productId, $payload['variations'], $pricingPlan['rows'], $inventoryPlan);
            }

            // Materialize common price
            if ($effectiveType === 'variable' && $pricingPlan['materialize_existing']) {
                $this->pricing()->materialize($productId, $pricingPlan['common'], $now);
            }

            // Commit transaction
            $this->wpdb->query("COMMIT");

            if (isset($payload['media_library']) && is_array($payload['media_library'])) {
                $this->syncProductMediaProjection($productId);
            }

            // Complete transition after commit
            if ($transitionPlan['changed']) {
                $this->transition()->complete($productId, $transitionPlan);
            }

            // Project canonical state to WooCommerce CPT and legacy tables
            $projection = ['legacy_mirror' => 'unknown', 'catalog_index' => 'unknown'];
            try {
                $state = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT native_revision, native_applied, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
                    $productId
                ), ARRAY_A);
                $rev = max($newVersion, $state ? max((int)$state['native_revision'], (int)$state['native_applied'] + 1) : 1);
                $workerEpoch = LegacyMirror::getInstance()->getAggregateEpoch($productId);
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$this->tableObjectState}
                     (object_id, kind, parent_id, authority, epoch, legacy_revision, native_revision, legacy_applied, native_applied, tombstone, updated_at)
                     VALUES (%d, 'product', NULL, 'native', %d, 1, %d, 0, 0, 0, %s)
                     ON DUPLICATE KEY UPDATE authority = 'native', native_revision = %d, tombstone = 0, updated_at = VALUES(updated_at)",
                    $productId,
                    $workerEpoch,
                    $rev,
                    $now,
                    $rev
                ));
                $mirror = LegacyMirror::getInstance()->mirrorAggregate($productId, $rev, $workerEpoch);
                $projection['legacy_mirror'] = (string)($mirror['status'] ?? 'unknown');
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->wpdb->prefix}wpe_outbox
                     SET state = 'completed', lease_owner = NULL, lease_until = NULL
                     WHERE aggregate_id = %d AND state = 'pending'",
                    $productId
                ));
            } catch (\Throwable $mE) {
                $projection['legacy_mirror'] = 'error';
            }

            try {
                (new CatalogProjector($this->wpdb))->buildNewGeneration();
                $projection['catalog_index'] = 'ok';
            } catch (\Throwable $cE) {
                $projection['catalog_index'] = 'error';
            }

            if (class_exists(\WPE\Compatibility\StoreApi\SelectorAdapter::class)) {
                \WPE\Compatibility\StoreApi\SelectorAdapter::instance()->invalidateCache($productId);
            }

            $savedData = $this->getProductEditorData($productId);
            $varVersions = [];
            foreach ($savedData['variations'] as $sv) {
                $varVersions[(int)$sv['id']] = (int)($sv['version'] ?? 1);
            }

            $required = [
                'new_version'        => $newVersion,
                'type'               => $savedData['product']['type'],
                'variation_versions' => $varVersions,
                'data'               => $savedData,
            ];

            $returnResult = [
                'success'     => true,
                'status'      => 'success',
                'message'     => 'Product attributes and variants saved successfully.',
                'new_version' => $newVersion,
                'required'    => $required,
                'projection'  => $projection,
                'data'        => $savedData,
            ];

            if ($transitionReport !== null) {
                $returnResult['transition'] = $transitionReport;
            }

            if ($opKey !== null) {
                set_transient("wpe_save_op_{$productId}_{$opKey}", $returnResult, 3600);
            }

            return $returnResult;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Synchronize parent product fields from WordPress/WooCommerce post & postmeta into wpe_products
     * when saving in metabox mode, and re-project native options/variations to WooCommerce shell
     * when no new variants payload was submitted (e.g. when variants were saved via inline REST first).
     */
    public function syncFromWordPressPost(int $productId, bool $syncParentMeta = false, bool $reprojectIfNoPayload = false): void
    {
        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ), ARRAY_A);

        if (!$product) {
            return;
        }

        $post = get_post($productId);
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $updates = ['updated_at' => $now];

        if ($post->post_title !== '' && $post->post_title !== 'Auto Draft') {
            $updates['title'] = $post->post_title;
        }
        if ($post->post_name !== '' && $post->post_name !== 'auto-draft') {
            $updates['slug'] = $post->post_name;
        } elseif (!empty($updates['title']) && (empty($product['slug']) || $product['slug'] === 'auto-draft' || str_starts_with((string)$product['slug'], 'product-'))) {
            $updates['slug'] = sanitize_title($updates['title']);
        }
        if ($post->post_status !== '' && $post->post_status !== 'auto-draft') {
            $updates['status'] = $post->post_status;
        }
        $updates['description'] = (string)$post->post_content;
        $updates['short_description'] = (string)$post->post_excerpt;

        if ($syncParentMeta) {
            $postedType = isset($_POST['product-type']) ? sanitize_key((string)wp_unslash($_POST['product-type'])) : '';
            if ($postedType === '') {
                $terms = wp_get_object_terms($productId, 'product_type');
                if (!is_wp_error($terms) && !empty($terms)) {
                    $termSlugs = wp_list_pluck($terms, 'slug');
                    if (in_array('variable', $termSlugs, true)) {
                        $postedType = 'variable';
                    } elseif (in_array('simple', $termSlugs, true)) {
                        $postedType = 'simple';
                    }
                }
            }
            if (in_array($postedType, ['simple', 'variable'], true)) {
                $updates['type'] = $postedType;
            }

            $rawMetaRows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->wpdb->postmeta}
                 WHERE post_id = %d AND meta_key IN ('_sku','_regular_price','_sale_price','_weight','_length','_width','_height','_manage_stock','_stock','_stock_status','_backorders')",
                $productId
            ), ARRAY_A) ?: [];
            $rawMeta = [];
            foreach ($rawMetaRows as $rm) {
                $rawMeta[$rm['meta_key']] = $rm['meta_value'];
            }

            if (array_key_exists('_sku', $rawMeta)) {
                $skuVal = trim((string)$rawMeta['_sku']);
                $updates['sku'] = $skuVal !== '' ? $skuVal : null;
            }
            if (($updates['type'] ?? $product['type']) === 'simple') {
                if (array_key_exists('_regular_price', $rawMeta)) {
                    $updates['regular_price'] = $rawMeta['_regular_price'] !== '' && is_numeric($rawMeta['_regular_price']) ? sprintf('%.6f', (float)$rawMeta['_regular_price']) : null;
                }
                if (array_key_exists('_sale_price', $rawMeta)) {
                    $updates['sale_price'] = $rawMeta['_sale_price'] !== '' && is_numeric($rawMeta['_sale_price']) ? sprintf('%.6f', (float)$rawMeta['_sale_price']) : null;
                }
            }
            foreach (['weight' => '_weight', 'length' => '_length', 'width' => '_width', 'height' => '_height'] as $col => $mKey) {
                if (array_key_exists($mKey, $rawMeta)) {
                    $updates[$col] = $rawMeta[$mKey] !== '' && is_numeric($rawMeta[$mKey]) ? sprintf('%.6f', (float)$rawMeta[$mKey]) : null;
                }
            }

            if (isset($rawMeta['_manage_stock']) || isset($rawMeta['_stock_status'])) {
                $mStock = ($rawMeta['_manage_stock'] ?? 'no') === 'yes' ? 1 : 0;
                $sQty = ($mStock && isset($rawMeta['_stock']) && $rawMeta['_stock'] !== '' && is_numeric($rawMeta['_stock']))
                    ? (float)$rawMeta['_stock']
                    : null;
                $sStatus = in_array($rawMeta['_stock_status'] ?? 'instock', ['instock', 'outofstock', 'onbackorder'], true)
                    ? $rawMeta['_stock_status']
                    : 'instock';
                $bOrders = in_array($rawMeta['_backorders'] ?? 'no', ['no', 'notify', 'yes'], true)
                    ? $rawMeta['_backorders']
                    : 'no';
                $this->wpdb->update($this->tableInventory, [
                    'manage_stock' => $mStock,
                    'quantity'     => $sQty,
                    'stock_status' => $sStatus,
                    'backorders'   => $bOrders,
                    'updated_at'   => $now,
                ], ['owner_id' => $productId]);
            }
        }

        $this->wpdb->update($this->tableProducts, $updates, ['id' => $productId]);

        if ($reprojectIfNoPayload) {
            $hasOptionsOrVariants = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT (SELECT COUNT(1) FROM {$this->tableOptions} WHERE product_id = %d) +
                        (SELECT COUNT(1) FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL)",
                $productId,
                $productId
            ));
            if ($hasOptionsOrVariants > 0 || ($updates['type'] ?? $product['type']) === 'variable') {
                try {
                    $state = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT native_revision, native_applied, epoch FROM {$this->tableObjectState} WHERE object_id = %d",
                        $productId
                    ), ARRAY_A);
                    $rev = $state ? max((int)$state['native_revision'] + 1, (int)$state['native_applied'] + 1) : 1;
                    $workerEpoch = LegacyMirror::getInstance()->getAggregateEpoch($productId);
                    $this->wpdb->query($this->wpdb->prepare(
                        "INSERT INTO {$this->tableObjectState}
                         (object_id, kind, parent_id, authority, epoch, legacy_revision, native_revision, legacy_applied, native_applied, tombstone, updated_at)
                         VALUES (%d, 'product', NULL, 'native', %d, 1, %d, 0, 0, 0, %s)
                         ON DUPLICATE KEY UPDATE authority = 'native', native_revision = %d, tombstone = 0, updated_at = VALUES(updated_at)",
                        $productId,
                        $workerEpoch,
                        $rev,
                        $now,
                        $rev
                    ));
                    LegacyMirror::getInstance()->mirrorAggregate($productId, $rev, $workerEpoch);
                    (new CatalogProjector($this->wpdb))->buildNewGeneration();
                } catch (\Throwable $e) {
                    // Best-effort re-projection on parent post update
                }
            }
        }
    }

    /**
     * Sync variations edited through WooCommerce's default Variations tab (MODE_LEGACY)
     * back into wpe_variants and wpe_inventory so turning Custom Variants UI back on
     * preserves all edits made while Custom UI was disabled.
     */
    public function syncLegacyVariationsToNative(int $productId): void
    {
        $productExists = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ));
        if ($productExists === 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $varPosts = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT ID, post_status, menu_order FROM {$this->wpdb->posts}
             WHERE post_parent = %d AND post_type = 'product_variation' AND post_status IN ('publish', 'private')
             ORDER BY menu_order ASC, ID ASC",
            $productId
        ), ARRAY_A) ?: [];

        $activeIds = [];
        foreach ($varPosts as $idx => $vp) {
            $vId = (int)$vp['ID'];
            $activeIds[] = $vId;

            $metaRows = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$this->wpdb->postmeta}
                 WHERE post_id = %d AND meta_key IN ('_sku','_regular_price','_sale_price','_manage_stock','_stock','_stock_status','_backorders','_variation_description','_weight','_length','_width','_height')",
                $vId
            ), ARRAY_A) ?: [];
            $meta = [];
            foreach ($metaRows as $mr) {
                $meta[$mr['meta_key']] = $mr['meta_value'];
            }

            $existingVar = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE id = %d",
                $vId
            ));
            if (!$existingVar) {
                continue;
            }

            $skuVal = isset($meta['_sku']) && trim((string)$meta['_sku']) !== '' ? trim((string)$meta['_sku']) : null;
            $regPrice = isset($meta['_regular_price']) && $meta['_regular_price'] !== '' && is_numeric($meta['_regular_price'])
                ? sprintf('%.6f', (float)$meta['_regular_price'])
                : null;
            $salePrice = isset($meta['_sale_price']) && $meta['_sale_price'] !== '' && is_numeric($meta['_sale_price'])
                ? sprintf('%.6f', (float)$meta['_sale_price'])
                : null;

            $varUpdates = [
                'status'        => $vp['post_status'] === 'publish' ? 'publish' : 'private',
                'sku'           => $skuVal,
                'regular_price' => $regPrice,
                'sale_price'    => $salePrice,
                'description'   => isset($meta['_variation_description']) ? (string)$meta['_variation_description'] : '',
                'position'      => (int)($vp['menu_order'] ?? $idx),
                'deleted_at'    => null,
                'updated_at'    => $now,
            ];
            foreach (['weight' => '_weight', 'length' => '_length', 'width' => '_width', 'height' => '_height'] as $col => $mKey) {
                if (array_key_exists($mKey, $meta)) {
                    $varUpdates[$col] = $meta[$mKey] !== '' && is_numeric($meta[$mKey]) ? sprintf('%.6f', (float)$meta[$mKey]) : null;
                }
            }
            $this->wpdb->update($this->tableVariants, $varUpdates, ['id' => $vId]);

            $mStock = ($meta['_manage_stock'] ?? 'no') === 'yes' ? 1 : 0;
            $sQty = ($mStock && isset($meta['_stock']) && $meta['_stock'] !== '' && is_numeric($meta['_stock']))
                ? (float)$meta['_stock']
                : null;
            $sStatus = in_array($meta['_stock_status'] ?? 'instock', ['instock', 'outofstock', 'onbackorder'], true)
                ? $meta['_stock_status']
                : 'instock';
            $bOrders = in_array($meta['_backorders'] ?? 'no', ['no', 'notify', 'yes'], true)
                ? $meta['_backorders']
                : 'no';

            $this->wpdb->update($this->tableInventory, [
                'manage_stock' => $mStock,
                'quantity'     => $sQty,
                'stock_status' => $sStatus,
                'backorders'   => $bOrders,
                'updated_at'   => $now,
            ], ['owner_id' => $vId]);
        }

        // Soft-delete any wpe_variants rows whose WC variation post was removed/trashed in legacy UI
        if (!empty($activeIds)) {
            $idList = implode(',', array_map('intval', $activeIds));
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableVariants} SET deleted_at = %s, active_combination_key = NULL, updated_at = %s
                 WHERE product_id = %d AND deleted_at IS NULL AND id NOT IN ({$idList})",
                $now,
                $now,
                $productId
            ));
        }
    }

    private function renumberOptionGroup(int $productId, int $usedForVariation, int &$pos): void
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->tableOptions} WHERE product_id = %d AND used_for_variation = %d ORDER BY position ASC, id ASC",
            $productId,
            $usedForVariation
        ));
        foreach ($ids as $id) {
            $this->wpdb->update($this->tableOptions, ['position' => $pos++], ['id' => (int)$id]);
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

        $rawExtra = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT extra_props FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ));
        $extraProps = json_decode((string)($rawExtra ?? ''), true);
        if (!is_array($extraProps)) {
            $extraProps = [];
        }
        $optionUiMap = is_array($extraProps['option_ui'] ?? null) ? $extraProps['option_ui'] : [];
        $optionUiDirty = false;

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

            if ($usedForVariation === 1 && (array_key_exists('display_type', $opt) || array_key_exists('help_text', $opt))) {
                $existingUi = is_array($optionUiMap[(string)$optId] ?? null) ? $optionUiMap[(string)$optId] : [];
                $displayType = array_key_exists('display_type', $opt)
                    ? (string)$opt['display_type']
                    : (string)($existingUi['display_type'] ?? 'auto');
                if (!in_array($displayType, ['auto', 'dropdown', 'button', 'color', 'image'], true)) {
                    throw new InvalidArgumentException("Invalid option display_type '{$displayType}' for option '{$name}'.");
                }
                $helpText = array_key_exists('help_text', $opt)
                    ? mb_substr(sanitize_text_field((string)$opt['help_text']), 0, 255)
                    : (string)($existingUi['help_text'] ?? '');

                $optionUiMap[(string)$optId] = [
                    'display_type' => $displayType,
                    'help_text'    => $helpText,
                ];
                $optionUiDirty = true;
            }

            // Sync option values. A value keeps its ID: it is matched by the submitted id first (so a renamed label is
            // the same value), then by label. Variations reference the value ID, so a rename never touches them.
            $rawVals = $opt['values'] ?? [];
            $existingVals = $this->optionsRepo->getOptionValues($optId);
            $valById = [];
            $valByName = [];
            foreach ($existingVals as $ev) {
                $valById[(int)$ev['id']] = $ev;
                $valByName[strtolower(trim($ev['label']))] = $ev;
            }

            $vPos = 0;
            $touchedValIds = [];
            $valueIdByLabel = [];
            foreach ($rawVals as $v) {
                $vLabel = is_array($v) ? trim((string)($v['label'] ?? '')) : trim((string)$v);
                if ($vLabel === '') continue;
                $lower = strtolower($vLabel);

                $valId = 0;
                if (is_array($v) && !empty($v['id']) && isset($valById[(int)$v['id']])) {
                    $valId = (int)$v['id'];
                } elseif (isset($valByName[$lower]) && !in_array((int)$valByName[$lower]['id'], $touchedValIds, true)) {
                    $valId = (int)$valByName[$lower]['id'];
                }

                $hasColorInput = is_array($v) && (array_key_exists('color', $v) || (isset($v['swatch']) && is_array($v['swatch']) && array_key_exists('color', $v['swatch'])));
                $swatchPayload = null;
                if ($hasColorInput) {
                    $rawColor = array_key_exists('color', $v) ? $v['color'] : ($v['swatch']['color'] ?? '');
                    $hexColor = strtolower(trim((string)$rawColor));
                    if ($hexColor !== '' && !preg_match('/^#[0-9a-f]{6}$/', $hexColor)) {
                        throw new InvalidArgumentException("Invalid hex color '{$rawColor}' for value '{$vLabel}'. Expected #RRGGBB.");
                    }
                    $swatchPayload = $hexColor !== '' ? ['color' => $hexColor] : [];
                }

                if ($valId > 0) {
                    $valueUpdate = ['position' => $vPos++];
                    if (trim((string)$valById[$valId]['label']) !== $vLabel) {
                        $valueUpdate['label'] = $vLabel;
                    }
                    if ($swatchPayload !== null) {
                        $existingSwatch = !empty($valById[$valId]['swatch']) ? (json_decode((string)$valById[$valId]['swatch'], true) ?: []) : [];
                        if (!is_array($existingSwatch)) {
                            $existingSwatch = [];
                        }
                        if (isset($swatchPayload['color'])) {
                            $existingSwatch['color'] = $swatchPayload['color'];
                        } else {
                            unset($existingSwatch['color']);
                        }
                        $valueUpdate['swatch'] = $existingSwatch;
                    }
                    $this->optionsRepo->updateOptionValue($valId, $valueUpdate);
                    $touchedValIds[] = $valId;
                } else {
                    $createValData = [
                        'label'     => $vLabel,
                        'value_key' => sanitize_title($vLabel),
                        'position'  => $vPos++,
                    ];
                    if ($swatchPayload !== null && !empty($swatchPayload)) {
                        $createValData['swatch'] = $swatchPayload;
                    }
                    $createdVal = $this->optionsRepo->createOptionValue($optId, $createValData);
                    $valId = (int)$createdVal['id'];
                    $touchedValIds[] = $valId;
                }
                $valueIdByLabel[$lower] = $valId;
            }

            // Default value: by id (must be a value of this option) or by label; null or empty clears it.
            if (array_key_exists('default_value_id', $opt) || array_key_exists('default_value', $opt)) {
                $defaultId = null;
                if (array_key_exists('default_value_id', $opt) && $opt['default_value_id'] !== null && $opt['default_value_id'] !== '') {
                    $defaultId = (int)$opt['default_value_id'];
                } elseif (!empty($opt['default_value']) && is_string($opt['default_value'])) {
                    $defaultId = $valueIdByLabel[strtolower(trim($opt['default_value']))] ?? null;
                }
                $this->optionsRepo->updateOption($optId, ['default_value_id' => $defaultId]);
            }

            // Delete removed values for this option (a value a live variation uses was rejected by the plan)
            foreach ($existingVals as $ev) {
                $oldValId = (int)$ev['id'];
                if (!in_array($oldValId, $touchedValIds, true)) {
                    $this->wpdb->update($this->tableOptions, ['default_value_id' => null], ['id' => $optId, 'default_value_id' => $oldValId]);
                    $this->wpdb->delete($this->tableOptionValues, ['id' => $oldValId]);
                    $this->wpdb->delete($this->tableVariantValues, ['value_id' => $oldValId]);
                }
            }
        }

        // Delete removed options in this group
        foreach ($existingIds as $oldOptId) {
            if (!in_array($oldOptId, $touchedIds, true)) {
                $this->optionsRepo->deleteOption($oldOptId);
                if (isset($optionUiMap[(string)$oldOptId])) {
                    unset($optionUiMap[(string)$oldOptId]);
                    $optionUiDirty = true;
                }
            }
        }

        if ($optionUiDirty) {
            $extraProps['option_ui'] = $optionUiMap;
            $this->wpdb->update(
                $this->tableProducts,
                ['extra_props' => json_encode($extraProps, JSON_UNESCAPED_UNICODE)],
                ['id' => $productId]
            );
        }
    }

    private function syncVariations(int $productId, array $variations, array $pricingRows = [], array $inventoryRows = []): void
    {
        $existing = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL",
            $productId
        ), ARRAY_A);
        $existingIds = array_map('intval', array_column($existing, 'id'));
        $touchedIds = [];

        $pos = 0;
        $now = gmdate('Y-m-d H:i:s.u');
        $libraryIds = null;

        foreach ($variations as $varIndex => $varData) {
            $varId = !empty($varData['id']) ? (int)$varData['id'] : 0;
            $isNew = false;

            if ($varId <= 0 || !in_array($varId, $existingIds, true)) {
                $isNew = true;
                $allocator = new PostIdAllocator($this->wpdb);
                $varId = $allocator->allocateShell('product_variation');
            }

            $sku = trim((string)($varData['sku'] ?? '')) ?: null;
            // Price columns and pricing mode come from the validated plan (shared rows carry the common price).
            $priceRow = $pricingRows[$varIndex] ?? null;
            if ($priceRow === null) {
                throw new \RuntimeException('Pricing plan is missing for variation index ' . $varIndex);
            }
            $priceColumns = $priceRow['columns'];
            $priorExtra = $isNew ? [] : (json_decode((string)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT extra_props FROM {$this->tableVariants} WHERE id = %d",
                $varId
            )), true) ?: []);
            $variantExtra = json_encode(PricingService::withPricingMode($priorExtra, $priceRow['mode']), JSON_UNESCAPED_UNICODE);
            $status = $varData['status'] ?? 'publish';
            $invRow = $inventoryRows[$varIndex] ?? null;
            if ($invRow === null) {
                throw new \RuntimeException('Inventory plan is missing for variation index ' . $varIndex);
            }
            $manageStock = $invRow['manage_stock'];
            $stockOwnerId = $manageStock ? $varId : $productId;

            if ($isNew) {
                $this->wpdb->insert($this->tableVariants, [
                    'id'               => $varId,
                    'product_id'       => $productId,
                    'sku'              => $sku,
                    'regular_price'    => $priceColumns['regular_price'],
                    'sale_price'       => $priceColumns['sale_price'],
                    'sale_from'        => $priceColumns['sale_from'],
                    'sale_to'          => $priceColumns['sale_to'],
                    'extra_props'      => $variantExtra,
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
                    'version'          => (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT version FROM {$this->tableVariants} WHERE id = %d", $varId)) + 1,
                    'sku'              => $sku,
                    'regular_price'    => $priceColumns['regular_price'],
                    'sale_price'       => $priceColumns['sale_price'],
                    'sale_from'        => $priceColumns['sale_from'],
                    'sale_to'          => $priceColumns['sale_to'],
                    'extra_props'      => $variantExtra,
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

            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->tableObjectState} (object_id, kind, parent_id, authority, tombstone, updated_at)
                 VALUES (%d, 'variation', %d, 'native', 0, %s)
                 ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), authority = 'native', tombstone = 0, updated_at = VALUES(updated_at)",
                $varId,
                $productId,
                $now
            ));

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
                } catch (DuplicateCombinationException $dE) {
                    throw new EditorValidationException(
                        'duplicate_combination',
                        'Another variation already has this combination.',
                        [['pointer' => "/variations/{$varIndex}/combination", 'reason' => 'duplicate_combination']]
                    );
                }
            }

            // Sync inventory (planned and validated before the transaction; the row keeps its version history)
            $invValues = [
                'manage_stock'  => $manageStock,
                'quantity'      => $invRow['quantity'],
                'stock_status'  => $invRow['stock_status'],
                'backorders'    => $invRow['backorders'],
                'updated_at'    => $now,
            ];
            $invVersion = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT version FROM {$this->tableInventory} WHERE owner_id = %d",
                $varId
            ));
            if ($invVersion === null) {
                $this->wpdb->insert($this->tableInventory, $invValues + ['owner_id' => $varId, 'version' => 1]);
            } else {
                $this->wpdb->update($this->tableInventory, $invValues + ['version' => (int)$invVersion + 1], ['owner_id' => $varId]);
            }

            // Sync variant image: assigned by asset id from the product library (docs/04 §7).
            if (array_key_exists('image_asset_id', $varData) || array_key_exists('image_url', $varData)) {
                $libraryIds ??= $this->library()->libraryAssetIds($productId);
                $this->syncVariantImage($varId, (int)$varIndex, $varData, $libraryIds);
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

    /**
     * Set or clear the primary image of a variation.
     *
     * The target is `image_asset_id` (preferred) or, for older clients, `image_url`. An unchanged image is always
     * accepted (legacy images outside the library stay editable). A new image must be in the product library, so
     * a browser cannot attach an arbitrary asset or URL; registering new images is the media route's job.
     *
     * @param int[] $libraryIds
     * @throws UploadRejectedException
     */
    private function syncVariantImage(int $variantId, int $index, array $varData, array $libraryIds): void
    {
        $currentRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT vm.asset_id, vm.position, vm.is_primary, vm.alt_override, a.source_url
               FROM {$this->tableVariantMedia} vm
               JOIN {$this->tableAssets} a ON a.id = vm.asset_id
              WHERE vm.variant_id = %d
              ORDER BY vm.is_primary DESC, vm.position ASC, vm.asset_id ASC",
            $variantId
        ), ARRAY_A) ?: [];
        $currentId = $currentRows !== [] ? (int)$currentRows[0]['asset_id'] : 0;

        $pointer = "/variations/{$index}/image_asset_id";
        if (array_key_exists('image_asset_id', $varData)) {
            $raw = $varData['image_asset_id'];
            if ($raw === null || $raw === '') {
                $target = 0;
            } elseif ((is_string($raw) || is_int($raw)) && preg_match('/^[1-9][0-9]*$/', (string)$raw)) {
                $target = (int)$raw;
            } else {
                throw new UploadRejectedException('invalid_request', 'The variation image id is invalid.', 400, false, [['pointer' => $pointer, 'reason' => 'invalid']]);
            }
        } else {
            $url = trim((string)($varData['image_url'] ?? ''));
            $pointer = "/variations/{$index}/image_url";
            if ($url === '') {
                $target = 0;
            } else {
                $clean = esc_url_raw($url);
                if (str_starts_with($clean, 'http://')) {
                    $clean = 'https://' . substr($clean, 7);
                }
                $target = -1;
                if ($currentId > 0 && ((string)$currentRows[0]['source_url'] === $url || (string)$currentRows[0]['source_url'] === $clean)) {
                    $target = $currentId;
                } else {
                    $found = $clean !== '' ? $this->assetService->getAssetByUrl($clean) : null;
                    if (!$found && $url !== '' && $url !== $clean) {
                        $found = $this->assetService->getAssetByUrl($url);
                    }
                    if ($found) {
                        $target = (int)$found['id'];
                    }
                }
                $attId = !empty($varData['attachment_id']) ? (int)$varData['attachment_id'] : 0;
                if ($target === -1 && $attId > 0 && get_post_type($attId) === 'attachment') {
                    $byAttId = (int)$this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT id FROM {$this->tableAssets} WHERE attachment_id = %d ORDER BY id DESC LIMIT 1",
                        $attId
                    ));
                    if ($byAttId > 0) {
                        $target = $byAttId;
                    } else {
                        $created = $this->mediaRepo->createAsset([
                            'source_url'    => $clean !== '' ? $clean : $url,
                            'attachment_id' => $attId,
                            'mime'          => get_post_mime_type($attId) ?: 'image/jpeg',
                            'alt_text'      => (string)get_post_meta($attId, '_wp_attachment_image_alt', true),
                        ]);
                        $target = (int)$created['id'];
                    }
                }
                if ($target > 0 && $attId > 0 && get_post_type($attId) === 'attachment') {
                    $productId = (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT product_id FROM {$this->tableVariants} WHERE id = %d", $variantId));
                    if ($productId > 0 && !in_array($target, $this->library()->libraryAssetIds($productId), true)) {
                        $maxPos = (int)$this->wpdb->get_var($this->wpdb->prepare(
                            "SELECT COALESCE(MAX(position), -1) FROM {$this->tableProductMedia} WHERE product_id = %d AND role = 'gallery'",
                            $productId
                        ));
                        $this->wpdb->insert($this->tableProductMedia, [
                            'product_id' => $productId,
                            'asset_id'   => $target,
                            'role'       => 'gallery',
                            'position'   => $maxPos + 1,
                        ], ['%d', '%d', '%s', '%d']);
                    }
                }
                if ($target === -1) {
                    throw new UploadRejectedException('asset_not_in_product', 'Add the image to the product first, then choose it for the variation.', 422, false, [['pointer' => $pointer, 'reason' => 'asset_not_in_product']]);
                }
            }
        }

        if ($target === $currentId) {
            return;
        }
        if ($target === 0) {
            $this->wpdb->delete($this->tableVariantMedia, ['variant_id' => $variantId]);
            return;
        }

        $this->library()->assertInLibrary(0 + (int)$this->wpdb->get_var($this->wpdb->prepare("SELECT product_id FROM {$this->tableVariants} WHERE id = %d", $variantId)), [$target], [], $pointer);

        // The chosen image becomes primary at position 0; other images of the variation keep their order.
        $this->wpdb->delete($this->tableVariantMedia, ['variant_id' => $variantId]);
        $this->wpdb->insert($this->tableVariantMedia, ['variant_id' => $variantId, 'asset_id' => $target, 'position' => 0, 'is_primary' => 1]);
        $pos = 1;
        foreach ($currentRows as $row) {
            if ((int)$row['asset_id'] === $target) {
                continue;
            }
            $this->wpdb->insert($this->tableVariantMedia, [
                'variant_id'   => $variantId,
                'asset_id'     => (int)$row['asset_id'],
                'position'     => $pos++,
                'is_primary'   => 0,
                'alt_override' => $row['alt_override'],
            ]);
        }

        try {
            $asset = $this->mediaRepo->getAssetById($target);
            $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId($target);
            if ($asset && $attachmentId > 0) {
                update_post_meta($attachmentId, '_wc_attachment_source', (string)$asset['source_url']);
            }
        } catch (\Throwable $e) {
            // The attachment shell is a compatibility projection; the canonical link is already written.
        }
    }

    /**
     * Preview giving one library image to a variation, the selected rows or every variation of an option value.
     * Reads only; the browser applies the result through the normal save so it follows the draft flow.
     *
     * @param array{variant_ids?:array<int|string>,option?:array{name?:string,value?:string}} $target
     * @return array<string,mixed>
     * @throws UploadRejectedException
     */
    public function previewImageAssignment(int $productId, int $assetId, array $target): array
    {
        $this->library()->assertInLibrary($productId, [$assetId], [], '/asset_id');

        $variations = $this->getProductEditorData($productId)['variations'];
        $byId = [];
        foreach ($variations as $v) {
            $byId[(int)$v['id']] = $v;
        }

        $scope = 'selection';
        if (isset($target['option'])) {
            $scope  = 'group';
            $name   = trim((string)($target['option']['name'] ?? ''));
            $value  = trim((string)($target['option']['value'] ?? ''));
            if ($name === '' || $value === '') {
                throw new UploadRejectedException('invalid_request', 'An option name and value are required.', 400, false, [['pointer' => '/option', 'reason' => 'required']]);
            }
            $selected = [];
            foreach ($variations as $v) {
                if (($v['attributes'][$name] ?? null) === $value) {
                    $selected[] = (int)$v['id'];
                }
            }
        } else {
            $ids = $target['variant_ids'] ?? null;
            if (!is_array($ids) || $ids === []) {
                throw new UploadRejectedException('invalid_request', 'Choose at least one variation.', 400, false, [['pointer' => '/variant_ids', 'reason' => 'required']]);
            }
            $selected = [];
            foreach (array_values($ids) as $i => $id) {
                $id = (int)$id;
                if (!isset($byId[$id])) {
                    throw new UploadRejectedException('validation_failed', 'The variation does not belong to this product.', 422, false, [['pointer' => "/variant_ids/{$i}", 'reason' => 'wrong_product']]);
                }
                $selected[] = $id;
            }
            $selected = array_values(array_unique($selected));
            $scope    = count($selected) === 1 ? 'variant' : 'selection';
        }

        $affected  = [];
        $unchanged = [];
        foreach ($selected as $id) {
            $v = $byId[$id];
            if ((string)($v['image_asset_id'] ?? '') === (string)$assetId) {
                $unchanged[] = (string)$id;
                continue;
            }
            $affected[] = [
                'variant_id'       => (string)$id,
                'sku'              => (string)($v['sku'] ?? ''),
                'attributes'       => $v['attributes'],
                'current_asset_id' => $v['image_asset_id'] ?? null,
            ];
        }

        return [
            'asset_id'       => (string)$assetId,
            'scope'          => $scope,
            'selected_count' => count($selected),
            'affected_count' => count($affected),
            'affected'       => $affected,
            'unchanged'      => $unchanged,
        ];
    }

    /**
     * Apply media_library draft changes (featured_asset_id, gallery order, alt texts/overrides) inside saveProductVariants.
     */
    private function syncDraftMediaLibrary(int $productId, array $mediaPayload, int $actorId = 0): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        if (!empty($mediaPayload['alt_texts']) && is_array($mediaPayload['alt_texts'])) {
            foreach ($mediaPayload['alt_texts'] as $key => $altEntry) {
                if (is_array($altEntry)) {
                    $aid = (int)($altEntry['asset_id'] ?? 0);
                    $rawAlt = array_key_exists('alt_text', $altEntry) ? (string)$altEntry['alt_text'] : null;
                } else {
                    $aid = (int)$key;
                    $rawAlt = is_scalar($altEntry) ? (string)$altEntry : null;
                }
                if ($aid > 0 && $rawAlt !== null) {
                    $altText = mb_substr(sanitize_text_field($rawAlt), 0, 500);
                    $this->wpdb->update(
                        $this->tableAssets,
                        ['alt_text' => $altText, 'updated_at' => $now],
                        ['id' => $aid]
                    );
                }
            }
        }

        if (array_key_exists('featured_asset_id', $mediaPayload) || isset($mediaPayload['gallery'])) {
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM {$this->tableProductMedia} WHERE product_id = %d AND role IN ('featured', 'gallery')",
                $productId
            ));

            $linkedIds = [];
            $featuredId = !empty($mediaPayload['featured_asset_id']) ? (int)$mediaPayload['featured_asset_id'] : 0;
            if ($featuredId > 0) {
                $exists = (int)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COUNT(1) FROM {$this->tableAssets} WHERE id = %d",
                    $featuredId
                ));
                if ($exists > 0) {
                    $fAlt = isset($mediaPayload['featured_alt_override']) && is_string($mediaPayload['featured_alt_override']) && trim($mediaPayload['featured_alt_override']) !== ''
                        ? mb_substr(sanitize_text_field($mediaPayload['featured_alt_override']), 0, 500)
                        : null;
                    $this->wpdb->insert($this->tableProductMedia, [
                        'product_id'   => $productId,
                        'asset_id'     => $featuredId,
                        'role'         => 'featured',
                        'position'     => 0,
                        'alt_override' => $fAlt,
                    ]);
                    $linkedIds[] = $featuredId;
                }
            }

            if (isset($mediaPayload['gallery']) && is_array($mediaPayload['gallery'])) {
                $gPos = 0;
                $seen = [];
                foreach ($mediaPayload['gallery'] as $gItem) {
                    $gId = is_array($gItem) ? (int)($gItem['asset_id'] ?? 0) : (int)$gItem;
                    if ($gId <= 0 || isset($seen[$gId])) {
                        continue;
                    }
                    $exists = (int)$this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT COUNT(1) FROM {$this->tableAssets} WHERE id = %d",
                        $gId
                    ));
                    if ($exists === 0) {
                        continue;
                    }
                    $seen[$gId] = true;
                    $gAlt = (is_array($gItem) && isset($gItem['alt_override']) && is_string($gItem['alt_override']) && trim($gItem['alt_override']) !== '')
                        ? mb_substr(sanitize_text_field($gItem['alt_override']), 0, 500)
                        : null;
                    $this->wpdb->insert($this->tableProductMedia, [
                        'product_id'   => $productId,
                        'asset_id'     => $gId,
                        'role'         => 'gallery',
                        'position'     => $gPos++,
                        'alt_override' => $gAlt,
                    ]);
                    $linkedIds[] = $gId;
                }
            }

            if ($linkedIds !== []) {
                $idList = implode(',', array_map('intval', array_unique($linkedIds)));
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->wpdb->prefix}wpe_upload_items
                     SET state = 'linked', updated_at = %s
                     WHERE product_id = %d AND asset_id IN ({$idList}) AND state = 'registered'",
                    $now,
                    $productId
                ));
            }
        }
    }

    /**
     * Project canonical product and variation media from wpe_product_media / wpe_variant_media
     * to WordPress _thumbnail_id and _product_image_gallery when the product media library changes.
     */
    public function syncProductMediaProjection(int $productId): void
    {
        $exists = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $productId
        ));
        if ($exists === 0) {
            return;
        }

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT pm.asset_id, pm.role, pm.position, a.attachment_id, a.source_url
             FROM {$this->tableProductMedia} pm
             JOIN {$this->tableAssets} a ON a.id = pm.asset_id
             WHERE pm.product_id = %d AND pm.role IN ('featured', 'gallery')
             ORDER BY pm.role ASC, pm.position ASC, pm.asset_id ASC",
            $productId
        ), ARRAY_A) ?: [];

        $featuredAttId = 0;
        $galleryAttIds = [];
        foreach ($rows as $r) {
            $assetId = (int)$r['asset_id'];
            $attId = !empty($r['attachment_id']) ? (int)$r['attachment_id'] : 0;
            if ($attId <= 0) {
                try {
                    $attId = $this->attachmentBridge->getOrCreateAttachmentId($assetId);
                } catch (\Throwable $e) {
                    $attId = 0;
                }
            }
            if ($attId > 0) {
                if ($r['role'] === 'featured' && $featuredAttId === 0) {
                    $featuredAttId = $attId;
                } elseif ($r['role'] === 'gallery') {
                    $galleryAttIds[] = $attId;
                }
            }
        }

        $varIds = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL",
            $productId
        )) ?: [];
        $varAttMap = [];
        foreach ($varIds as $rawVarId) {
            $varId = (int)$rawVarId;
            $vRow = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT vm.asset_id, a.attachment_id
                 FROM {$this->tableVariantMedia} vm
                 JOIN {$this->tableAssets} a ON a.id = vm.asset_id
                 WHERE vm.variant_id = %d
                 ORDER BY vm.is_primary DESC, vm.position ASC, vm.asset_id ASC
                 LIMIT 1",
                $varId
            ), ARRAY_A);
            $vAttId = 0;
            if ($vRow) {
                $vAttId = !empty($vRow['attachment_id']) ? (int)$vRow['attachment_id'] : 0;
                if ($vAttId <= 0) {
                    try {
                        $vAttId = $this->attachmentBridge->getOrCreateAttachmentId((int)$vRow['asset_id']);
                    } catch (\Throwable $e) {
                        $vAttId = 0;
                    }
                }
            }
            $varAttMap[$varId] = $vAttId;
        }

        \WPE\Application\Migration\LegacyCapture::withOrigin('native_projection', function () use ($productId, $featuredAttId, $galleryAttIds, $varAttMap): void {
            if ($featuredAttId > 0) {
                update_post_meta($productId, '_thumbnail_id', (string)$featuredAttId);
            } else {
                delete_post_meta($productId, '_thumbnail_id');
            }
            if ($galleryAttIds !== []) {
                update_post_meta($productId, '_product_image_gallery', implode(',', $galleryAttIds));
            } else {
                delete_post_meta($productId, '_product_image_gallery');
            }
            foreach ($varAttMap as $varId => $vAttId) {
                if ($vAttId > 0) {
                    update_post_meta($varId, '_thumbnail_id', (string)$vAttId);
                } else {
                    delete_post_meta($varId, '_thumbnail_id');
                }
            }
        });
    }

    private function rules(): VariantRulesService
    {
        return $this->rules ??= new VariantRulesService($this->wpdb);
    }

    private function transition(): ProductTypeTransition
    {
        return $this->transition ??= new ProductTypeTransition($this->wpdb, $this->optionsRepo);
    }

    public function previewTypeTransition(int $productId, string $target): array
    {
        return $this->transition()->preview($productId, $target);
    }

    private function info(): ProductInfoService
    {
        return $this->info ??= new ProductInfoService($this->wpdb);
    }

    private function pricing(): PricingService
    {
        return $this->pricing ??= new PricingService($this->wpdb, $this->optionsRepo);
    }

    private function library(): ProductMediaLibrary
    {
        return $this->library ??= new ProductMediaLibrary($this->mediaRepo, null, $this->wpdb);
    }
}
