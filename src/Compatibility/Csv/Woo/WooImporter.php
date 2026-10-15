<?php

declare(strict_types=1);

namespace WPE\Compatibility\Csv\Woo;

use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use wpdb;
use WPE\Application\ImportExport\CsvStager;
use WPE\Application\ImportExport\ImportCommitter;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * WooImporter
 *
 * Implements:
 * - WooCommerce baseline CSV dialect mapping & schema precedence (R128, AC-T32-01).
 * - Parent matching via SKU or id:N prefix (R129).
 * - External image mode intercepting sideload without downloading files to uploads (R130, AC-T32-02).
 * - Download image mode preserving standard WooCommerce image import behavior (R130, AC-T32-02).
 * - Stock blank vs zero semantics and published status mapping (R131).
 * - Native WooCommerce CSV importer hooks and plugin import paths (AC-T32-01).
 */
class WooImporter
{
    private static ?WooImporter $instance = null;

    private wpdb $wpdb;
    private ProductRepository $productRepo;
    private OptionsRepository $optionsRepo;
    private AssetService $assetService;
    private AttachmentBridge $attachmentBridge;
    private CsvStager $csvStager;
    private ImportCommitter $committer;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableProductMedia;
    private string $tableVariantMedia;
    private string $tableAssets;
    private string $tableSkuRegistry;

    private string $imageMode = 'external'; // 'external' or 'download'
    private bool $hooksRegistered = false;

    public const WOO_COLUMNS = [
        'ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
        'Short description', 'Description', 'Date sale price starts', 'Date sale price ends',
        'Tax status', 'Tax class', 'In stock?', 'Stock', 'Backorders allowed?', 'Sold individually?',
        'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)', 'Allow customer reviews?',
        'Purchase note', 'Sale price', 'Regular price', 'Categories', 'Tags', 'Shipping class',
        'Images', 'Download limit', 'Download expiry days', 'Parent', 'Grouped products',
        'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position',
    ];

    public function __construct(
        ?wpdb $wpdb = null,
        ?ProductRepository $productRepo = null,
        ?OptionsRepository $optionsRepo = null,
        ?AssetService $assetService = null,
        ?AttachmentBridge $attachmentBridge = null,
        ?CsvStager $csvStager = null,
        ?ImportCommitter $committer = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts     = "{$prefix}wpe_products";
        $this->tableVariants     = "{$prefix}wpe_variants";
        $this->tableInventory    = "{$prefix}wpe_inventory";
        $this->tableProductMedia = "{$prefix}wpe_product_media";
        $this->tableVariantMedia = "{$prefix}wpe_variant_media";
        $this->tableAssets       = "{$prefix}wpe_assets";
        $this->tableSkuRegistry  = "{$prefix}wpe_sku_registry";

        $this->productRepo      = $productRepo ?? new ProductRepository($this->wpdb);
        $this->optionsRepo      = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->assetService     = $assetService ?? new AssetService($this->wpdb);
        $this->attachmentBridge = $attachmentBridge ?? AttachmentBridge::instance($this->wpdb);
        $this->csvStager        = $csvStager ?? CsvStager::getInstance();
        $this->committer        = $committer ?? new ImportCommitter($this->wpdb, $this->productRepo, $this->optionsRepo, null, $this->csvStager);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getImageMode(): string
    {
        return $this->imageMode;
    }

    public function setImageMode(string $mode): self
    {
        $m = strtolower(trim($mode));
        if (!in_array($m, ['external', 'download'], true)) {
            throw new InvalidArgumentException("Invalid image mode: {$mode}. Must be 'external' or 'download'.");
        }
        $this->imageMode = $m;
        return $this;
    }

    public function isExternalMode(): bool
    {
        return $this->imageMode === 'external';
    }

    /**
     * Register hooks with native WooCommerce CSV Importer.
     */
    public function registerHooks(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        // 1. Intercept parsed row data in native WC CSV importer
        add_filter('woocommerce_product_importer_parsed_data', [$this, 'filterNativeParsedData'], 10, 2);

        // 2. Pre-insert product object hook in native WC CSV importer
        add_filter('woocommerce_product_import_pre_insert_product_object', [$this, 'filterNativePreInsertObject'], 10, 2);

        // 3. Post-insert hook in native WC CSV importer to sync WPE catalog tables
        add_action('woocommerce_product_import_inserted_product_object', [$this, 'actionNativeInsertedObject'], 10, 2);

        // 4. Custom UI settings for image mode in WC importer form
        add_filter('woocommerce_csv_product_import_mapping_options', [$this, 'filterNativeMappingOptions'], 10, 2);

        $this->hooksRegistered = true;
    }

    /**
     * Filter parsed data before native WC creates product objects.
     * Intercepts image URLs in external mode so WC does NOT sideload images into wp-content/uploads/ (R130).
     */
    public function filterNativeParsedData(array $parsedData, $importer = null): array
    {
        if (!$this->isExternalMode()) {
            return $parsedData;
        }

        // Intercept featured image URL
        if (!empty($parsedData['raw_image_id']) && is_string($parsedData['raw_image_id']) && str_contains($parsedData['raw_image_id'], '://')) {
            $url = esc_url_raw($parsedData['raw_image_id']);
            $attachmentId = $this->interceptExternalImage($url);
            // Replace raw image URL with shell attachment ID
            // WooCommerce will reuse this existing attachment and bypass wc_rest_upload_image_from_url()
            $parsedData['raw_image_id'] = $attachmentId;
        }

        // Intercept gallery image URLs
        if (!empty($parsedData['raw_gallery_image_ids']) && is_array($parsedData['raw_gallery_image_ids'])) {
            $interceptedGalleryIds = [];
            foreach ($parsedData['raw_gallery_image_ids'] as $galleryImg) {
                if (is_string($galleryImg) && str_contains($galleryImg, '://')) {
                    $url = esc_url_raw($galleryImg);
                    $interceptedGalleryIds[] = $this->interceptExternalImage($url);
                } else {
                    $interceptedGalleryIds[] = $galleryImg;
                }
            }
            $parsedData['raw_gallery_image_ids'] = $interceptedGalleryIds;
        }

        return $parsedData;
    }

    /**
     * Filter native product object before saving in WC importer.
     * Enforces stock blank vs zero semantics and published statuses (R131).
     */
    public function filterNativePreInsertObject($object, array $data)
    {
        if (!is_object($object) || !method_exists($object, 'get_id')) {
            return $object;
        }

        // Check stock blank semantics
        if (isset($data['manage_stock'])) {
            $rawStock = $data['stock_quantity'] ?? null;
            if ($rawStock === '' || $rawStock === null) {
                // Blank stock: do not force to 0
                $object->set_manage_stock(false);
                $object->set_stock_quantity(null);
            } elseif (is_numeric($rawStock)) {
                $object->set_manage_stock(true);
                $qty = (float)$rawStock;
                $object->set_stock_quantity($qty);
                if ($qty <= 0 && $object->get_stock_status() !== 'onbackorder') {
                    $object->set_stock_status('outofstock');
                }
            }
        }

        return $object;
    }

    /**
     * Synchronize product to WPE catalog after native WC importer inserts product.
     */
    public function actionNativeInsertedObject($object, array $data): void
    {
        if (!is_object($object) || !method_exists($object, 'get_id')) {
            return;
        }

        $productId = (int)$object->get_id();
        if ($productId <= 0) {
            return;
        }

        // If external image mode, associate assets to wpe_product_media
        if ($this->isExternalMode()) {
            $imageId = (int)$object->get_image_id();
            if ($imageId > 0) {
                $assetId = $this->attachmentBridge->getAssetIdForAttachment($imageId);
                if ($assetId !== null) {
                    $this->assetService->attachProductImage($productId, $assetId, 'featured', 0);
                }
            }

            $galleryIds = $object->get_gallery_image_ids();
            if (is_array($galleryIds)) {
                $pos = 1;
                foreach ($galleryIds as $gid) {
                    $assetId = $this->attachmentBridge->getAssetIdForAttachment((int)$gid);
                    if ($assetId !== null) {
                        $this->assetService->attachProductImage($productId, $assetId, 'gallery', $pos++);
                    }
                }
            }
        }
    }

    public function filterNativeMappingOptions(array $options, string $item = ''): array
    {
        $options['wpe_external_image'] = __('WPE External Image URL (No Sideload)', 'wpe');
        return $options;
    }

    /**
     * Intercept an external image URL: register in wpe_assets and create attachment shell
     * with _wc_attachment_source without downloading any file to wp-content/uploads/ (R130, AC-T32-02).
     */
    public function interceptExternalImage(string $url): int
    {
        $cleanUrl = esc_url_raw(trim($url));
        if ($cleanUrl === '') {
            return 0;
        }

        // 1. Register canonical asset in wpe_assets
        $asset = $this->assetService->registerAsset($cleanUrl);
        $assetId = (int)$asset['id'];

        // 2. Allocate or retrieve attachment post shell via AttachmentBridge
        $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId($assetId);

        // 3. Ensure _wc_attachment_source postmeta is present so native WooCommerce recognizes it
        update_post_meta($attachmentId, '_wc_attachment_source', $cleanUrl);

        return $attachmentId;
    }

    /**
     * Parse WooCommerce CSV file and map rows into structured product aggregates (R128, R129, R131).
     *
     * @param string $filePath Absolute path to CSV file
     * @param array $options Parser options (image_mode, update_mode)
     * @return array Grouped product aggregates
     */
    public function parseWooCsv(string $filePath, array $options = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("CSV file not found or unreadable: {$filePath}");
        }

        $imageMode = $options['image_mode'] ?? $this->imageMode;

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Failed to open CSV file: {$filePath}");
        }

        // Detect BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Read headers
        $rawHeaders = fgetcsv($handle);
        if ($rawHeaders === false || empty($rawHeaders)) {
            fclose($handle);
            throw new RuntimeException("CSV file contains no header row");
        }

        $headers = [];
        foreach ($rawHeaders as $idx => $h) {
            $clean = trim((string)$h);
            if ($idx === 0 && str_starts_with($clean, "\xEF\xBB\xBF")) {
                $clean = substr($clean, 3);
            }
            $headers[$idx] = $clean;
        }

        $groups = []; // groupKey => ['parent' => array|null, 'variations' => array]
        $rowNum = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $rowNum++;

            // Skip empty rows
            if (empty(array_filter($cells, fn($c) => trim((string)$c) !== ''))) {
                continue;
            }

            $fields = [];
            foreach ($headers as $idx => $headerName) {
                if ($headerName !== '') {
                    $fields[$headerName] = isset($cells[$idx]) ? trim((string)$cells[$idx]) : '';
                }
            }

            $type = strtolower(trim($fields['Type'] ?? 'simple'));
            if ($type === '') {
                $type = 'simple';
            }

            $sku = trim($fields['SKU'] ?? '');
            $parentRef = trim($fields['Parent'] ?? '');

            // Determine group key
            if ($type === 'variation' || $parentRef !== '') {
                $groupKey = $parentRef !== '' ? $parentRef : $sku;
            } else {
                $groupKey = $sku !== '' ? $sku : 'row_' . $rowNum;
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'group_key'  => $groupKey,
                    'parent'     => null,
                    'variations' => [],
                ];
            }

            $mappedRow = $this->mapRowFields($fields, $type, $imageMode);

            if ($type === 'variation') {
                $groups[$groupKey]['variations'][] = $mappedRow;
            } else {
                $groups[$groupKey]['parent'] = $mappedRow;
            }
        }

        fclose($handle);

        return array_values($groups);
    }

    /**
     * Map individual CSV row fields into normalized domain data adhering to R129, R130, R131.
     */
    public function mapRowFields(array $fields, string $type, string $imageMode = 'external'): array
    {
        $id = !empty($fields['ID']) ? (int)$fields['ID'] : null;
        $sku = trim($fields['SKU'] ?? '');
        $title = trim($fields['Name'] ?? 'Imported Product');
        $parentRef = trim($fields['Parent'] ?? '');

        // 1. Stock Blank vs Zero Semantics (R131)
        // If stock is absent or empty string -> manage_stock = false, stock_quantity = null
        // If stock is numeric (e.g. "0" or "15") -> manage_stock = true, stock_quantity = numeric value
        $rawStock = $fields['Stock'] ?? null;
        $manageStock = 0;
        $stockQuantity = null;

        if ($rawStock !== null && $rawStock !== '') {
            $manageStock = 1;
            $stockQuantity = (string)(float)$rawStock;
        }

        $inStock = strtolower(trim($fields['In stock?'] ?? '1'));
        if ($manageStock === 1 && (float)$stockQuantity <= 0) {
            $stockStatus = 'outofstock';
        } else {
            $stockStatus = ($inStock === '0' || $inStock === 'outofstock') ? 'outofstock' : 'instock';
        }

        // 2. Published Semantics (R131)
        // '1' or 'publish' => 'publish'; '0' or 'draft' => 'draft'
        $rawPublished = strtolower(trim($fields['Published'] ?? '1'));
        $status = ($rawPublished === '0' || $rawPublished === 'draft') ? 'draft' : 'publish';

        // 3. Prices
        $regPrice = trim($fields['Regular price'] ?? '');
        $salePrice = trim($fields['Sale price'] ?? '');

        // 4. Attributes mapping (global vs local, defaults)
        $attributes = [];
        $options = [];
        for ($i = 1; $i <= 10; $i++) {
            $nameKey    = "Attribute {$i} name";
            $valKey     = "Attribute {$i} value(s)";
            $visibleKey = "Attribute {$i} visible";
            $globalKey  = "Attribute {$i} global";
            $defaultKey = "Attribute {$i} default";

            if (!empty($fields[$nameKey]) && !empty($fields[$valKey])) {
                $attrName = trim($fields[$nameKey]);
                $attrVal  = trim($fields[$valKey]);
                $isGlobal = trim($fields[$globalKey] ?? '1') === '1';
                $default  = trim($fields[$defaultKey] ?? '');

                $attributes[$attrName] = $attrVal;
                $options[] = [
                    'name'     => $attrName,
                    'values'   => array_map('trim', explode(',', $attrVal)),
                    'is_global'=> $isGlobal,
                    'default'  => $default,
                ];
            }
        }

        // 5. Images
        $rawImages = trim($fields['Images'] ?? '');
        $imageUrls = [];
        if ($rawImages !== '') {
            $imageUrls = array_map('trim', explode(',', $rawImages));
        }

        // 6. Categories & Tags
        $categories = !empty($fields['Categories']) ? array_map('trim', explode(',', $fields['Categories'])) : [];
        $tags       = !empty($fields['Tags']) ? array_map('trim', explode(',', $fields['Tags'])) : [];

        return [
            'id'                => $id,
            'type'              => $type,
            'sku'               => $sku !== '' ? $sku : null,
            'title'             => $title,
            'name'              => $title,
            'parent'            => $parentRef !== '' ? $parentRef : null,
            'status'            => $status,
            'regular_price'     => $regPrice !== '' ? $regPrice : null,
            'sale_price'        => $salePrice !== '' ? $salePrice : null,
            'manage_stock'      => $manageStock,
            'stock_quantity'    => $stockQuantity,
            'stock_status'      => $stockStatus,
            'description'       => $fields['Description'] ?? '',
            'short_description' => $fields['Short description'] ?? '',
            'catalog_visibility'=> !empty($fields['Visibility in catalog']) ? trim($fields['Visibility in catalog']) : 'visible',
            'position'          => isset($fields['Position']) && $fields['Position'] !== '' ? (int)$fields['Position'] : 0,
            'attributes'        => $attributes,
            'options'           => $options,
            'image_urls'        => $imageUrls,
            'categories'        => $categories,
            'tags'              => $tags,
            'raw_fields'        => $fields,
        ];
    }

    /**
     * Import a standard WooCommerce CSV into engine catalog (R128, R129, R130, R131, AC-T32-01).
     *
     * @param string $filePath Path to CSV
     * @param array $options Options (image_mode, conflict_policy)
     * @return array Import summary (created_products, updated_products, created_variations, errors)
     */
    public function importFromCsv(string $filePath, array $options = []): array
    {
        $imageMode = $options['image_mode'] ?? $this->imageMode;
        $groups = $this->parseWooCsv($filePath, $options);

        $createdProducts = [];
        $updatedProducts = [];
        $createdVariants = [];
        $errors = [];

        foreach ($groups as $group) {
            $groupKey = $group['group_key'];
            $parentData = $group['parent'];
            $variationDataList = $group['variations'];

            try {
                $targetProductId = null;

                // 1. Resolve Parent by ID or SKU (R129)
                if ($parentData !== null) {
                    $targetProductId = $this->resolveProductIdentity($parentData['id'], $parentData['sku']);
                } else if (!empty($variationDataList)) {
                    // Variations only group: resolve parent from variation's Parent reference
                    $parentRef = $variationDataList[0]['parent'] ?? $groupKey;
                    $targetProductId = $this->resolveParentRef($parentRef);
                    if ($targetProductId === null) {
                        throw new RuntimeException("Parent product '{$parentRef}' not found for variation group");
                    }
                }

                $isVariable = (!empty($variationDataList) || ($parentData && $parentData['type'] === 'variable'));
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

                // 2. Insert or update parent product
                if ($parentData !== null) {
                    $pPayload = [
                        'type'               => $isVariable ? 'variable' : $parentData['type'],
                        'title'              => $parentData['title'],
                        'name'               => $parentData['title'],
                        'sku'                => $parentData['sku'],
                        'description'        => $parentData['description'],
                        'short_description'  => $parentData['short_description'],
                        'regular_price'      => $parentData['regular_price'],
                        'sale_price'         => $parentData['sale_price'],
                        'status'             => $parentData['status'],
                        'catalog_visibility' => $parentData['catalog_visibility'],
                        'manage_stock'       => $parentData['manage_stock'],
                        'stock_quantity'     => $parentData['stock_quantity'],
                        'stock_status'       => $parentData['stock_status'],
                    ];

                    if ($targetProductId !== null) {
                        $this->productRepo->update($targetProductId, $pPayload);
                        $productId = $targetProductId;
                        $updatedProducts[] = $productId;
                    } else {
                        $created = $this->productRepo->create($pPayload);
                        $productId = (int)$created['id'];
                        $createdProducts[] = $productId;
                    }

                    // Process images (R130)
                    if (!empty($parentData['image_urls'])) {
                        $this->attachImagesToProduct($productId, $parentData['image_urls'], $imageMode);
                    }

                    // Process categories & tags terms
                    $this->assignProductTerms($productId, $parentData['categories'], $parentData['tags']);

                    // Process options
                    if (!empty($parentData['options'])) {
                        $this->syncOptions($productId, $parentData['options']);
                    }
                } else {
                    $productId = $targetProductId;
                }

                // 3. Process variations if variable product
                if ($isVariable && !empty($variationDataList)) {
                    foreach ($variationDataList as $vData) {
                        $vSku = $vData['sku'];
                        $vId = $this->resolveVariantIdentity($vData['id'], $vSku);

                        $vPayload = [
                            'sku'            => $vSku,
                            'regular_price'  => $vData['regular_price'],
                            'sale_price'     => $vData['sale_price'],
                            'status'         => $vData['status'],
                            'position'       => $vData['position'],
                            'description'    => $vData['description'],
                            'attributes'     => $vData['attributes'],
                            'manage_stock'   => $vData['manage_stock'] ?? 0,
                            'stock_quantity' => $vData['stock_quantity'] ?? null,
                            'stock_status'   => $vData['stock_status'] ?? 'instock',
                        ];

                        if ($vId !== null) {
                            $vPayload['id'] = $vId;
                        }

                        $this->productRepo->update($productId, ['variations' => [$vPayload]]);

                        // Fetch updated/created variant ID without colliding on empty SKU
                        if ($vId !== null) {
                            $savedVariantId = $vId;
                        } elseif ($vSku !== null && $vSku !== '') {
                            $savedVariant = $this->wpdb->get_row($this->wpdb->prepare(
                                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND sku = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
                                $productId,
                                $vSku
                            ), ARRAY_A);
                            $savedVariantId = $savedVariant ? (int)$savedVariant['id'] : 0;
                        } else {
                            $savedVariant = $this->wpdb->get_row($this->wpdb->prepare(
                                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
                                $productId
                            ), ARRAY_A);
                            $savedVariantId = $savedVariant ? (int)$savedVariant['id'] : 0;
                        }

                        if ($savedVariantId > 0) {
                            $createdVariants[] = $savedVariantId;

                            // Assign option values combination
                            if (!empty($vData['attributes'])) {
                                $this->assignVariantOptionCombination($productId, $savedVariantId, $vData['attributes']);
                            }

                            // Variant image
                            if (!empty($vData['image_urls'])) {
                                $this->attachVariantImage($savedVariantId, $vData['image_urls'][0], $imageMode);
                            }
                        }
                    }
                }

            } catch (\Throwable $e) {
                $errors[] = [
                    'group_key' => $groupKey,
                    'error'     => $e->getMessage(),
                ];
            }
        }

        return [
            'status'             => empty($errors) ? 'completed' : 'partial_success',
            'created_products'   => $createdProducts,
            'updated_products'   => $updatedProducts,
            'created_variations' => $createdVariants,
            'errors'             => $errors,
        ];
    }

    /**
     * Resolve product identity by ID or SKU (R129).
     */
    private function resolveProductIdentity(?int $id, ?string $sku): ?int
    {
        if ($sku !== null && $sku !== '') {
            $existing = $this->productRepo->getBySku($sku);
            if ($existing) {
                return (int)$existing['id'];
            }
        }

        if ($id !== null && $id > 0) {
            $existingById = $this->productRepo->getById($id);
            if ($existingById) {
                return (int)$existingById['id'];
            }
        }

        return null;
    }

    /**
     * Resolve parent reference string (e.g. 'id:123' or SKU 'MY-SKU') (R129).
     */
    public function resolveParentRef(string $parentRef): ?int
    {
        $trimmed = trim($parentRef);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'id:')) {
            $intId = (int)substr($trimmed, 3);
            if ($intId > 0) {
                $p = $this->productRepo->getById($intId);
                return $p ? (int)$p['id'] : null;
            }
        }

        if (is_numeric($trimmed)) {
            $intId = (int)$trimmed;
            $p = $this->productRepo->getById($intId);
            if ($p) {
                return (int)$p['id'];
            }
        }

        // By SKU
        $p = $this->productRepo->getBySku($trimmed);
        return $p ? (int)$p['id'] : null;
    }

    /**
     * Resolve variant identity by ID or SKU.
     */
    private function resolveVariantIdentity(?int $id, ?string $sku): ?int
    {
        if ($sku !== null && $sku !== '') {
            $v = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE sku = %s AND deleted_at IS NULL",
                $sku
            ), ARRAY_A);
            if ($v) {
                return (int)$v['id'];
            }
        }

        if ($id !== null && $id > 0) {
            $v = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE id = %d AND deleted_at IS NULL",
                $id
            ), ARRAY_A);
            if ($v) {
                return (int)$v['id'];
            }
        }

        return null;
    }

    /**
     * Attach image URLs to product based on image mode (R130, AC-T32-02).
     */
    private function attachImagesToProduct(int $productId, array $imageUrls, string $imageMode): void
    {
        $pos = 0;
        foreach ($imageUrls as $url) {
            $clean = esc_url_raw(trim($url));
            if ($clean === '') {
                continue;
            }

            if ($imageMode === 'external') {
                // External mode: zero physical downloads to uploads (R130, AC-T32-02)
                $asset = $this->assetService->registerAsset($clean);
                $role = ($pos === 0) ? 'featured' : 'gallery';
                $this->assetService->attachProductImage($productId, (int)$asset['id'], $role, $pos);
                // Also create attachment shell without downloading
                $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
                update_post_meta($attachmentId, '_wc_attachment_source', $clean);
            } else {
                // Download mode: standard WooCommerce behavior
                if (function_exists('wc_rest_upload_image_from_url')) {
                    $upload = wc_rest_upload_image_from_url($clean);
                    if (!is_wp_error($upload) && $upload > 0) {
                        if ($pos === 0) {
                            set_post_thumbnail($productId, $upload);
                        }
                    }
                }
            }
            $pos++;
        }
    }

    /**
     * Attach variant image based on image mode (R130, AC-T32-02).
     */
    private function attachVariantImage(int $variantId, string $url, string $imageMode): void
    {
        $clean = esc_url_raw(trim($url));
        if ($clean === '') {
            return;
        }

        if ($imageMode === 'external') {
            $asset = $this->assetService->registerAsset($clean);
            $this->assetService->attachVariantImage($variantId, (int)$asset['id'], 0, true);
            $attachmentId = $this->attachmentBridge->getOrCreateAttachmentId((int)$asset['id']);
            update_post_meta($attachmentId, '_wc_attachment_source', $clean);
        } else {
            if (function_exists('wc_rest_upload_image_from_url')) {
                $upload = wc_rest_upload_image_from_url($clean);
                if (!is_wp_error($upload) && $upload > 0) {
                    set_post_thumbnail($variantId, $upload);
                }
            }
        }
    }

    /**
     * Synchronize product options in OptionsRepository.
     */
    private function syncOptions(int $productId, array $options): void
    {
        $pos = 0;
        foreach ($options as $opt) {
            $name = $opt['name'];
            $key = sanitize_title($name);

            // Check if option exists
            $existingOpt = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_options WHERE product_id = %d AND (`key` = %s OR `name` = %s)",
                $productId,
                $key,
                $name
            ), ARRAY_A);

            if ($existingOpt) {
                $optId = (int)$existingOpt['id'];
            } else {
                $createdOpt = $this->optionsRepo->createOption($productId, [
                    'name'     => $name,
                    'key'      => $key,
                    'position' => $pos++,
                ]);
                $optId = (int)$createdOpt['id'];
            }

            // Create option values
            $vPos = 0;
            foreach ($opt['values'] as $valLabel) {
                if ($valLabel === '') {
                    continue;
                }
                $valKey = sanitize_title($valLabel);
                $existingVal = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT id FROM {$this->wpdb->prefix}wpe_option_values WHERE option_id = %d AND (`value_key` = %s OR `label` = %s)",
                    $optId,
                    $valKey,
                    $valLabel
                ), ARRAY_A);

                if (!$existingVal) {
                    $this->optionsRepo->createOptionValue($optId, [
                        'label'     => $valLabel,
                        'value_key' => $valKey,
                        'position'  => $vPos++,
                    ]);
                }
            }
        }
    }

    /**
     * Assign variant option combination in wpe_variant_values.
     */
    private function assignVariantOptionCombination(int $productId, int $variantId, array $attributes): void
    {
        $selections = [];
        foreach ($attributes as $attrName => $valLabel) {
            $cleanName = str_starts_with($attrName, 'pa_') ? substr($attrName, 3) : $attrName;
            $optKey = sanitize_title($cleanName);

            $optRow = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_options WHERE product_id = %d AND (`key` = %s OR `name` = %s)",
                $productId,
                $optKey,
                $cleanName
            ), ARRAY_A);

            if ($optRow) {
                $optId = (int)$optRow['id'];
                $valKey = sanitize_title($valLabel);

                $valRow = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT id FROM {$this->wpdb->prefix}wpe_option_values WHERE option_id = %d AND (`value_key` = %s OR `label` = %s)",
                    $optId,
                    $valKey,
                    $valLabel
                ), ARRAY_A);

                if ($valRow) {
                    $selections[$optId] = (int)$valRow['id'];
                }
            }
        }

        if (!empty($selections)) {
            $this->optionsRepo->assignVariantCombination($productId, $variantId, $selections);
        }
    }

    /**
     * Assign categories and tags WordPress terms.
     */
    private function assignProductTerms(int $productId, array $categories, array $tags): void
    {
        if (function_exists('wp_set_object_terms')) {
            if (!empty($categories)) {
                wp_set_object_terms($productId, $categories, 'product_cat');
            }
            if (!empty($tags)) {
                wp_set_object_terms($productId, $tags, 'product_tag');
            }
        }
    }
}
