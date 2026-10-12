<?php

declare(strict_types=1);

namespace WPE\Compatibility\Csv\Shopify;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * ShopifyImporter
 *
 * Implements:
 * - Shopify dialect version profiles & header aliases (current vs legacy) (R136, AC-T34-01).
 * - Image-only row recognition without creating additional variations (R137, AC-T34-01).
 * - Compare-at / price rule and unit conversions (R138).
 * - Handle grouping, idempotency, and missing vs blank overwrite policies (R139, AC-T34-02).
 * - Unsupported fields validation (>3 options, multi-location, metaobjects) with loss reporting (R140).
 * - Default Title mapping to WooCommerce simple products (AC-T34-01).
 */
class ShopifyImporter
{
    private static ?ShopifyImporter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableAssets;
    private string $tableSkuRegistry;
    private string $tableOptions;
    private string $tableOptionValues;
    private string $tableVariantValues;

    /**
     * Canonical header aliases: maps legacy or alternative Shopify header names to normalized keys.
     */
    public const HEADER_ALIASES = [
        'handle'                    => 'handle',
        'url handle'                => 'handle',
        'title'                     => 'title',
        'body (html)'               => 'description',
        'description'               => 'description',
        'vendor'                    => 'vendor',
        'product category'          => 'category',
        'type'                      => 'product_type',
        'tags'                      => 'tags',
        'published'                 => 'published',
        'status'                    => 'status',
        'option1 name'              => 'option1_name',
        'option1 value'             => 'option1_value',
        'option2 name'              => 'option2_name',
        'option2 value'             => 'option2_value',
        'option3 name'              => 'option3_name',
        'option3 value'             => 'option3_value',
        'variant sku'               => 'sku',
        'sku'                       => 'sku',
        'variant price'             => 'price',
        'price'                     => 'price',
        'variant compare at price'  => 'compare_at_price',
        'compare-at price'          => 'compare_at_price',
        'compare at price'          => 'compare_at_price',
        'variant inventory qty'     => 'inventory_qty',
        'inventory qty'             => 'inventory_qty',
        'variant inventory policy'  => 'inventory_policy',
        'variant grams'             => 'grams',
        'variant weight unit'       => 'weight_unit',
        'image src'                 => 'product_image_url',
        'product image url'         => 'product_image_url',
        'image position'            => 'image_position',
        'image alt text'            => 'image_alt_text',
        'variant image'             => 'variant_image_url',
        'variant image url'         => 'variant_image_url',
    ];

    public const UNSUPPORTED_COLUMNS = [
        'metafield', 'metaobject', 'location', 'multi-location', 'market',
        'option4 name', 'option4 value', 'option5 name', 'option5 value',
    ];

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $attachmentBridge = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts      = "{$prefix}wpe_products";
        $this->tableVariants      = "{$prefix}wpe_variants";
        $this->tableInventory     = "{$prefix}wpe_inventory";
        $this->tableProductMedia  = "{$prefix}wpe_product_media";
        $this->tableVariantMedia  = "{$prefix}wpe_variant_media";
        $this->tableAssets        = "{$prefix}wpe_assets";
        $this->tableSkuRegistry   = "{$prefix}wpe_sku_registry";
        $this->tableOptions       = "{$prefix}wpe_options";
        $this->tableOptionValues  = "{$prefix}wpe_option_values";
        $this->tableVariantValues = "{$prefix}wpe_variant_values";

        $this->productRepo      = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo      = $optionsRepo ?? new OptionsRepository($this->wpdb);
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
     * Normalize raw CSV header name using alias map (R136).
     */
    public function normalizeHeader(string $rawHeader): string
    {
        $clean = strtolower(trim($rawHeader));
        return self::HEADER_ALIASES[$clean] ?? $clean;
    }

    /**
     * Parse Shopify CSV into grouped product aggregates (R136, R137, R139).
     *
     * @param string $filePath
     * @param array $options
     * @return array Grouped product aggregates
     */
    public function parseShopifyCsv(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("CSV file not found or unreadable: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Failed to open CSV file: {$filePath}");
        }

        // BOM detection
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $rawHeaders = fgetcsv($handle);
        if ($rawHeaders === false || empty($rawHeaders)) {
            fclose($handle);
            throw new RuntimeException("CSV file contains no header row");
        }

        $normalizedHeaders = [];
        $unsupportedColumns = [];
        foreach ($rawHeaders as $idx => $rawH) {
            $cleaned = trim((string)$rawH);
            if ($idx === 0 && str_starts_with($cleaned, "\xEF\xBB\xBF")) {
                $cleaned = substr($cleaned, 3);
            }
            $normKey = $this->normalizeHeader($cleaned);
            $normalizedHeaders[$idx] = [
                'raw'  => $cleaned,
                'norm' => $normKey,
            ];

            // Check for unsupported fields (R140)
            foreach (self::UNSUPPORTED_COLUMNS as $unsupportedPattern) {
                if (str_contains(strtolower($cleaned), $unsupportedPattern)) {
                    $unsupportedColumns[] = $cleaned;
                    break;
                }
            }
        }

        $groups = []; // handle => product aggregate data
        $rowNum = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $rowNum++;

            // Skip empty rows
            if (empty(array_filter($cells, fn($c) => trim((string)$c) !== ''))) {
                continue;
            }

            $row = [];
            foreach ($normalizedHeaders as $idx => $hInfo) {
                $row[$hInfo['norm']] = isset($cells[$idx]) ? trim((string)$cells[$idx]) : '';
            }

            $handleKey = trim($row['handle'] ?? '');
            if ($handleKey === '') {
                // If handle is missing, assign a row-based key
                $handleKey = 'product_' . $rowNum;
            }

            if (!isset($groups[$handleKey])) {
                $groups[$handleKey] = [
                    'handle'              => $handleKey,
                    'title'               => $row['title'] ?? '',
                    'description'         => $row['description'] ?? '',
                    'vendor'              => $row['vendor'] ?? '',
                    'product_type'        => $row['product_type'] ?? '',
                    'tags'                => $row['tags'] ?? '',
                    'status'              => $this->mapStatus($row['status'] ?? $row['published'] ?? ''),
                    'options'             => [],
                    'images'              => [],
                    'variants'            => [],
                    'unsupported_columns' => array_values(array_unique($unsupportedColumns)),
                    'raw_headers'         => array_column($normalizedHeaders, 'norm'),
                ];
            } else {
                // Backfill title/description if parent row had them in subsequent row
                if ($groups[$handleKey]['title'] === '' && !empty($row['title'])) {
                    $groups[$handleKey]['title'] = $row['title'];
                }
                if ($groups[$handleKey]['description'] === '' && !empty($row['description'])) {
                    $groups[$handleKey]['description'] = $row['description'];
                }
            }

            // Image collection from product_image_url
            $imgUrl = trim($row['product_image_url'] ?? '');
            if ($imgUrl !== '') {
                $imgPos = isset($row['image_position']) && is_numeric($row['image_position']) ? (int)$row['image_position'] : count($groups[$handleKey]['images']) + 1;
                $groups[$handleKey]['images'][] = [
                    'url'      => $imgUrl,
                    'position' => $imgPos,
                    'alt'      => trim($row['image_alt_text'] ?? ''),
                ];
            }

            // Determine if this row is an IMAGE-ONLY row or blank continuation row (R137, AC-T34-01):
            $hasSku = !empty($row['sku']);
            $hasPrice = isset($row['price']) && $row['price'] !== '';
            $hasOptionVal = !empty($row['option1_value']) || !empty($row['option2_value']) || !empty($row['option3_value']);

            if (!$hasSku && !$hasPrice && !$hasOptionVal) {
                if ($imgUrl !== '' || !empty($groups[$handleKey]['variants'])) {
                    continue;
                }
            }

            // Options discovery
            for ($optIdx = 1; $optIdx <= 3; $optIdx++) {
                $optNameKey = "option{$optIdx}_name";
                $optValKey  = "option{$optIdx}_value";

                if (!empty($row[$optNameKey])) {
                    $optName = trim($row[$optNameKey]);
                    if (!isset($groups[$handleKey]['options'][$optName])) {
                        $groups[$handleKey]['options'][$optName] = [];
                    }
                    if (!empty($row[$optValKey])) {
                        $val = trim($row[$optValKey]);
                        if (!in_array($val, $groups[$handleKey]['options'][$optName], true)) {
                            $groups[$handleKey]['options'][$optName][] = $val;
                        }
                    }
                }
            }

            // Variant pricing & compare-at calculation (R138)
            $priceData = $this->calculatePrices(
                $row['price'] ?? '',
                $row['compare_at_price'] ?? ''
            );

            // Variant weight calculation (R138)
            $weightKg = $this->calculateWeight(
                $row['grams'] ?? '',
                $row['weight_unit'] ?? ''
            );

            // Variant image URL
            $varImgUrl = trim($row['variant_image_url'] ?? '');

            // Build variation row attributes
            $attributes = [];
            for ($optIdx = 1; $optIdx <= 3; $optIdx++) {
                $optNameKey = "option{$optIdx}_name";
                $optValKey  = "option{$optIdx}_value";
                if (!empty($row[$optNameKey]) && !empty($row[$optValKey])) {
                    $attributes[trim($row[$optNameKey])] = trim($row[$optValKey]);
                }
            }

            $stockQuantity = null;
            $manageStock = 0;
            if (isset($row['inventory_qty']) && $row['inventory_qty'] !== '') {
                $manageStock = 1;
                $stockQuantity = (string)(float)$row['inventory_qty'];
            }

            $groups[$handleKey]['variants'][] = [
                'sku'              => $row['sku'] !== '' ? trim($row['sku']) : null,
                'regular_price'    => $priceData['regular_price'],
                'sale_price'       => $priceData['sale_price'],
                'price_warning'    => $priceData['warning'],
                'weight'           => $weightKg,
                'manage_stock'     => $manageStock,
                'stock_quantity'   => $stockQuantity,
                'stock_status'     => ($manageStock === 1 && (float)$stockQuantity <= 0) ? 'outofstock' : 'instock',
                'attributes'       => $attributes,
                'image_url'        => $varImgUrl !== '' ? $varImgUrl : null,
                'row_number'       => $rowNum,
            ];
        }

        fclose($handle);

        return array_values($groups);
    }

    /**
     * Import parsed Shopify CSV into WooCommerce Product Engine (AC-T34-01, AC-T34-02).
     *
     * @param string $filePath
     * @param array $options
     * @return array Import summary
     */
    public function importFromCsv(string $filePath, array $options = []): array
    {
        $updateMode = $options['update_mode'] ?? 'merge'; // 'merge' preserves unmentioned variants
        $groups = $this->parseShopifyCsv($filePath, $options);

        $createdProducts = [];
        $updatedProducts = [];
        $createdVariants = [];
        $errors = [];
        $losses = [];

        foreach ($groups as $group) {
            $handle = $group['handle'];

            try {
                // Record unsupported columns if present (R140)
                if (!empty($group['unsupported_columns'])) {
                    foreach ($group['unsupported_columns'] as $col) {
                        $losses[] = [
                            'field'   => 'unsupported_shopify_column',
                            'path'    => "products[{$handle}].{$col}",
                            'reason'  => "Shopify column '{$col}' is outside the supported catalog schema and cannot be natively mapped",
                            'value'   => $col,
                        ];
                    }
                }

                // Check for >3 options loss (R140)
                if (count($group['options']) > 3) {
                    $losses[] = [
                        'field'   => 'more_than_three_options',
                        'path'    => "products[{$handle}].options",
                        'reason'  => 'Shopify standard schema supports maximum 3 options; extra options detected',
                        'value'   => array_keys($group['options']),
                    ];
                }

                // Determine Product Type: Simple vs Variable (AC-T34-01)
                // Shopify represents simple products with a single variant having Option1 = Title, Value = Default Title
                $isDefaultTitle = false;
                if (count($group['variants']) === 1) {
                    $attrs = $group['variants'][0]['attributes'];
                    if (count($attrs) === 1 && isset($attrs['Title']) && $attrs['Title'] === 'Default Title') {
                        $isDefaultTitle = true;
                    }
                }

                $isVariable = (count($group['variants']) > 1) || (!empty($group['options']) && !$isDefaultTitle);
                $productType = $isVariable ? 'variable' : 'simple';

                // Resolve existing product by Handle (slug) or SKU for handle idempotency (R139, AC-T34-02)
                $existingProductId = $this->resolveProductByHandleOrSku($handle, $group['variants']);

                // Prepare product payload
                $pPayload = [
                    'title'  => $group['title'] !== '' ? $group['title'] : ucfirst(str_replace('-', ' ', $handle)),
                    'slug'   => $handle,
                    'type'   => $productType,
                    'status' => $group['status'],
                ];

                // Check missing vs blank overwrite policy (AC-T34-02):
                // If 'description' was in CSV headers, set it (blank clears it); if missing from headers, do not include in payload
                if (in_array('description', $group['raw_headers'], true)) {
                    $pPayload['description'] = $group['description'];
                }

                if (!$isVariable && !empty($group['variants'])) {
                    $v0 = $group['variants'][0];
                    $pPayload['sku']            = $v0['sku'];
                    $pPayload['regular_price']  = $v0['regular_price'];
                    $pPayload['sale_price']     = $v0['sale_price'];
                    $pPayload['manage_stock']   = $v0['manage_stock'];
                    $pPayload['stock_quantity'] = $v0['stock_quantity'];
                    $pPayload['stock_status']   = $v0['stock_status'];
                }

                // Create or update parent product (AC-T34-02: repeated handles do NOT duplicate products)
                if ($existingProductId !== null) {
                    $this->productRepo->update($existingProductId, $pPayload);
                    $productId = $existingProductId;
                    $updatedProducts[] = $productId;
                } else {
                    $created = $this->productRepo->create($pPayload);
                    $productId = (int)$created['id'];
                    $createdProducts[] = $productId;
                }

                // Attach product gallery images (R137)
                if (!empty($group['images'])) {
                    $this->attachProductImages($productId, $group['images']);
                }

                // If variable, sync options and variations
                if ($isVariable) {
                    $this->syncShopifyOptions($productId, $group['options']);

                    foreach ($group['variants'] as $vData) {
                        $vSku = $vData['sku'];
                        $vId = $vSku ? $this->resolveVariantIdBySku($vSku) : null;

                        $vPayload = [
                            'sku'            => $vSku,
                            'regular_price'  => $vData['regular_price'],
                            'sale_price'     => $vData['sale_price'],
                            'manage_stock'   => $vData['manage_stock'],
                            'stock_quantity' => $vData['stock_quantity'],
                            'stock_status'   => $vData['stock_status'],
                            'attributes'     => $vData['attributes'],
                        ];

                        if ($vId !== null) {
                            $vPayload['id'] = $vId;
                        }

                        $this->productRepo->update($productId, ['variations' => [$vPayload]]);

                        // Fetch updated/created variant ID
                        $savedVariant = $this->wpdb->get_row($this->wpdb->prepare(
                            "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND sku = %s",
                            $productId,
                            $vSku
                        ), ARRAY_A);

                        if ($savedVariant) {
                            $savedVarId = (int)$savedVariant['id'];
                            $createdVariants[] = $savedVarId;

                            // Assign option values combination vector
                            if (!empty($vData['attributes'])) {
                                $this->assignVariantCombinationVector($productId, $savedVarId, $vData['attributes']);
                            }

                            // Variant image
                            if (!empty($vData['image_url'])) {
                                $this->attachVariantImage($savedVarId, $vData['image_url']);
                            }
                        }
                    }
                }

            } catch (\Throwable $e) {
                $errors[] = [
                    'handle' => $handle,
                    'error'  => $e->getMessage(),
                ];
            }
        }

        return [
            'status'             => empty($errors) ? 'completed' : 'partial_success',
            'created_products'   => $createdProducts,
            'updated_products'   => $updatedProducts,
            'created_variations' => $createdVariants,
            'errors'             => $errors,
            'loss_report'        => $losses,
            'total_losses'       => count($losses),
        ];
    }

    /**
     * Calculate regular_price and sale_price following Compare-at / Price rule (R138).
     *
     * Rule:
     * - Compare-at > Price: regular_price = Compare-at, sale_price = Price.
     * - Compare-at empty or <= Price: regular_price = Price, sale_price = null.
     * - Compare-at abnormal (< Price and not empty): do not invert arbitrarily; report warning.
     */
    public function calculatePrices(string $rawPrice, string $rawCompareAt): array
    {
        $p = trim($rawPrice);
        $c = trim($rawCompareAt);

        $price = is_numeric($p) ? (float)$p : null;
        $compareAt = is_numeric($c) ? (float)$c : null;
        $warning = null;

        if ($price === null) {
            return ['regular_price' => null, 'sale_price' => null, 'warning' => null];
        }

        if ($compareAt !== null && $compareAt > $price) {
            return [
                'regular_price' => sprintf('%.2f', $compareAt),
                'sale_price'    => sprintf('%.2f', $price),
                'warning'       => null,
            ];
        }

        if ($compareAt !== null && $compareAt > 0 && $compareAt < $price) {
            $warning = "Abnormal compare-at price: compare-at ({$compareAt}) is lower than price ({$price}). Using price as regular price.";
        }

        return [
            'regular_price' => sprintf('%.2f', $price),
            'sale_price'    => null,
            'warning'       => $warning,
        ];
    }

    /**
     * Calculate weight in kilograms with precision policy (R138).
     */
    public function calculateWeight(string $rawGrams, string $unit = 'g'): ?string
    {
        $g = trim($rawGrams);
        if ($g === '' || !is_numeric($g)) {
            return null;
        }

        $grams = (float)$g;
        $u = strtolower(trim($unit));

        // Convert to standard kg
        $kg = match ($u) {
            'kg'    => $grams,
            'lb'    => $grams * 0.45359237,
            'oz'    => $grams * 0.0283495,
            default => $grams / 1000.0, // 'g' or grams column
        };

        return sprintf('%.4f', $kg);
    }

    private function mapStatus(string $rawStatus): string
    {
        $s = strtolower(trim($rawStatus));
        if ($s === 'active' || $s === 'true' || $s === '1' || $s === 'publish') {
            return 'publish';
        }
        if ($s === 'draft' || $s === 'false' || $s === '0' || $s === 'archived') {
            return 'draft';
        }
        return 'publish';
    }

    private function resolveProductByHandleOrSku(string $handle, array $variants): ?int
    {
        // 1. Check by slug in wpe_products
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id FROM {$this->tableProducts} WHERE slug = %s AND deleted_at IS NULL",
            $handle
        ), ARRAY_A);

        if ($existing) {
            return (int)$existing['id'];
        }

        // 2. Check by SKU of variants in wpe_sku_registry
        foreach ($variants as $v) {
            if (!empty($v['sku'])) {
                $p = $this->productRepo->getBySku($v['sku']);
                if ($p) {
                    return (int)$p['id'];
                }
            }
        }

        return null;
    }

    private function resolveVariantIdBySku(string $sku): ?int
    {
        $v = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id FROM {$this->tableVariants} WHERE sku = %s AND deleted_at IS NULL",
            $sku
        ), ARRAY_A);
        return $v ? (int)$v['id'] : null;
    }

    private function attachProductImages(int $productId, array $images): void
    {
        $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId]);

        $pos = 0;
        foreach ($images as $img) {
            $url = esc_url_raw(trim($img['url']));
            if ($url === '') {
                continue;
            }

            $asset = $this->assetService->registerAsset($url);
            $role = ($pos === 0) ? 'featured' : 'gallery';
            $altOverride = !empty($img['alt']) ? trim($img['alt']) : null;

            $this->assetService->attachProductImage($productId, (int)$asset['id'], $role, $pos, $altOverride);
            $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
            update_post_meta($attachmentId, '_wc_attachment_source', $url);
            $pos++;
        }
    }

    private function attachVariantImage(int $variantId, string $url): void
    {
        $clean = esc_url_raw(trim($url));
        if ($clean === '') {
            return;
        }

        $asset = $this->assetService->registerAsset($clean);
        $this->assetService->attachVariantImage($variantId, (int)$asset['id'], 0, true);
        $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
        update_post_meta($attachmentId, '_wc_attachment_source', $clean);
    }

    private function syncShopifyOptions(int $productId, array $options): void
    {
        $existingOpts = $this->optionsRepo->getOptionsByProductId($productId);
        $existingByName = [];
        foreach ($existingOpts as $eo) {
            $existingByName[strtolower($eo['name'])] = $eo;
        }

        $pos = 0;
        foreach ($options as $optName => $values) {
            $lower = strtolower($optName);
            if (isset($existingByName[$lower])) {
                $optId = (int)$existingByName[$lower]['id'];
            } else {
                $created = $this->optionsRepo->createOption($productId, [
                    'name'     => $optName,
                    'key'      => sanitize_title($optName),
                    'position' => $pos,
                ]);
                $optId = (int)$created['id'];
            }

            $existingVals = $this->optionsRepo->getOptionValues($optId);
            $valLabels = array_map('strtolower', array_column($existingVals, 'label'));

            $vPos = count($existingVals);
            foreach ($values as $valLabel) {
                if (!in_array(strtolower($valLabel), $valLabels, true)) {
                    $this->optionsRepo->createOptionValue($optId, [
                        'label'     => $valLabel,
                        'value_key' => sanitize_title($valLabel),
                        'position'  => $vPos++,
                    ]);
                }
            }
            $pos++;
        }
    }

    private function assignVariantCombinationVector(int $productId, int $variantId, array $attributes): void
    {
        $options = $this->optionsRepo->getOptionsByProductId($productId);
        $selections = [];

        foreach ($options as $opt) {
            $optName = $opt['name'];
            $optId = (int)$opt['id'];

            if (isset($attributes[$optName])) {
                $targetLabel = strtolower(trim($attributes[$optName]));
                foreach ($opt['values'] as $ov) {
                    if (strtolower($ov['label']) === $targetLabel || strtolower($ov['value_key']) === $targetLabel) {
                        $selections[$optId] = (int)$ov['id'];
                        break;
                    }
                }
            }
        }

        if (!empty($selections)) {
            $this->optionsRepo->assignVariantCombination($productId, $variantId, $selections);
        }
    }
}
