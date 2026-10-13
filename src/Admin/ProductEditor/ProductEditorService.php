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
            'common_price'           => PricingService::readCommonPrice($product['extra_props'] ?? null),
            'reference_price'        => $this->pricing()->referencePrice($productId),
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
            foreach (['regular_price', 'sale_price', 'status', 'manage_stock', 'stock_quantity', 'stock_status', 'image_url', 'image_asset_id'] as $field) {
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

        // Validate the pricing part (common price, per-variation mode, prices) before anything is written.
        $pricingPlan = $this->pricing()->planSave($productId, $currentProduct, $payload);

        // Validate attribute names and the Product information block the same way.
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
                (string)$currentProduct['type'],
                isset($payload['variations']) && is_array($payload['variations']) ? $payload['variations'] : null
            );
        }

        $now = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->query("START TRANSACTION");

        try {
            // 1. Update product metadata & parent inventory
            $newVersion = (int)$currentProduct['version'] + 1;
            $pUpdates = ['updated_at' => $now, 'version' => $newVersion];
            if (isset($payload['product']) && is_array($payload['product'])) {
                $pData = $payload['product'];
                if (isset($pData['status'])) {
                    $pUpdates['status'] = $pData['status'];
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
                // Only the common_price key changes; every other extra prop of the product is preserved.
                $currentExtra = json_decode((string)($currentProduct['extra_props'] ?? ''), true) ?: [];
                $pUpdates['extra_props'] = json_encode(
                    PricingService::withCommonPrice($currentExtra, $pricingPlan['common']),
                    JSON_UNESCAPED_UNICODE
                );
            }
            $this->wpdb->update($this->tableProducts, $pUpdates, ['id' => $productId]);

            // Options are re-sequenced only when the request carries option lists. A group that is not part of the
            // request keeps its relative order and is renumbered after the groups that are, so saving only the
            // product information never moves option positions (the shift below is undone for every group).
            $syncDescriptive = isset($payload['descriptive_attributes']) && is_array($payload['descriptive_attributes']);
            $syncVariationOptions = isset($payload['variation_options']) && is_array($payload['variation_options']);
            if ($syncDescriptive || $syncVariationOptions) {
                // Shift positions of existing options up by 10000 to prevent unique key conflict during re-sequencing
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->tableOptions} SET position = position + 10000 WHERE product_id = %d",
                    $productId
                ));

                $optPosition = 0;
                // 2. Sync Descriptive Attributes (used_for_variation = 0)
                if ($syncDescriptive) {
                    $this->syncOptionGroup($productId, $payload['descriptive_attributes'], 0, $optPosition);
                } else {
                    $this->renumberOptionGroup($productId, 0, $optPosition);
                }

                // 3. Sync Variation Options (used_for_variation = 1)
                if ($syncVariationOptions) {
                    $this->syncOptionGroup($productId, $payload['variation_options'], 1, $optPosition);
                } else {
                    $this->renumberOptionGroup($productId, 1, $optPosition);
                }
            }

            // 4. Sync Variations Matrix
            if (isset($payload['variations']) && is_array($payload['variations'])) {
                $this->syncVariations($productId, $payload['variations'], $pricingPlan['rows']);
            }

            // A changed common price without a variations list still has to reach the shared variations.
            if ($pricingPlan['materialize_existing']) {
                $this->pricing()->materialize($productId, $pricingPlan['common'], $now);
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
                // Carry the authority epoch the mirror reads: the object state epoch when a row exists, otherwise the
                // global authority epoch (the value its epoch fence compares against), never a literal or another counter.
                $workerEpoch = \WPE\Application\Migration\LegacyMirror::getInstance()->getAggregateEpoch($productId);
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

    /**
     * Give the options of one group consecutive positions starting at $pos, keeping their current order.
     */
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

    private function syncVariations(int $productId, array $variations, array $pricingRows = []): void
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
            $manageStock = !empty($varData['manage_stock']) ? 1 : 0;
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
                    if ($found) {
                        $target = (int)$found['id'];
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
