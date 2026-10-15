<?php

namespace WPE\Infrastructure\Database;

use RuntimeException;
use InvalidArgumentException;
use WPE\Compatibility\WordPress\PostIdAllocator;

class StaleVersionException extends RuntimeException
{
    public function __construct(string $message = "Stale write detected: aggregate version mismatch", int $code = 412, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class DuplicateSkuException extends RuntimeException
{
    public function __construct(string $message = "Duplicate SKU conflict", int $code = 409, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class InvalidParentException extends InvalidArgumentException
{
    public function __construct(string $message = "Variant parent ID mismatch", int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Product aggregate repository and commands for native storage.
 * Handles canonical product and variation writes with atomic aggregate boundaries,
 * version CAS, parent locking, SKU registry enforcement, and transactional outbox/audit.
 */
class ProductRepository
{
    private \wpdb $wpdb;
    private PostIdAllocator $postIdAllocator;
    private OutboxWorker $outboxWorker;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableSkuRegistry;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableProductMedia;
    private string $tableProductLinks;
    private string $tableDownloads;

    public function __construct(?\wpdb $wpdb = null, ?PostIdAllocator $postIdAllocator = null, ?OutboxWorker $outboxWorker = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->postIdAllocator = $postIdAllocator ?? new PostIdAllocator($this->wpdb);
        $this->outboxWorker = $outboxWorker ?? new OutboxWorker($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts     = $prefix . 'wpe_products';
        $this->tableVariants     = $prefix . 'wpe_variants';
        $this->tableSkuRegistry  = $prefix . 'wpe_sku_registry';
        $this->tableInventory    = $prefix . 'wpe_inventory';
        $this->tableObjectState  = $prefix . 'wpe_object_state';
        $this->tableProductMedia = $prefix . 'wpe_product_media';
        $this->tableProductLinks = $prefix . 'wpe_product_links';
        $this->tableDownloads    = $prefix . 'wpe_downloads';
    }

    /**
     * Create a new canonical product aggregate.
     *
     * @param array $data Canonical product data
     * @param bool $isMigration If true, reuses provided ID from legacy WooCommerce post
     * @return array Created canonical product aggregate
     */
    public function create(array $data, bool $isMigration = false): array
    {
        $type = $data['type'] ?? 'simple';

        // 1. Allocate or verify post ID
        if ($isMigration) {
            if (empty($data['id'])) {
                throw new InvalidArgumentException("Migration create requires an existing ID.");
            }
            $productId = $this->postIdAllocator->reuseLegacyId((int)$data['id'], 'product');
        } else {
            if (!empty($data['id']) && $this->postIdAllocator->postExists((int)$data['id'])) {
                $productId = (int)$data['id'];
            } else {
                $productId = $this->postIdAllocator->allocateShell('product');
            }
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $epoch = $this->outboxWorker->getCurrentEpoch();
        $version = 1;

        // Start transaction
        $this->wpdb->query("START TRANSACTION");

        try {
            // Price & input validation
            $regularPrice = $this->validatePrice($data['regular_price'] ?? null, 'regular_price');
            $salePrice = $this->validatePrice($data['sale_price'] ?? null, 'sale_price');
            $title = $data['name'] ?? $data['title'] ?? 'Product ' . $productId;
            $slug = $data['slug'] ?? sanitize_title($title);
            $sku = isset($data['sku']) ? trim((string)$data['sku']) : null;
            $status = $data['status'] ?? 'publish';

            $claimedSkus = [];
            if (!empty($sku)) {
                $this->registerSku($sku, $productId, 'product', $claimedSkus);
            }

            // Insert into wpe_products
            $insertResult = $this->wpdb->insert(
                $this->tableProducts,
                [
                    'id'                 => $productId,
                    'type'               => $type,
                    'status'             => $status,
                    'slug'               => $slug,
                    'title'              => $title,
                    'description'        => $data['description'] ?? '',
                    'short_description'  => $data['short_description'] ?? '',
                    'catalog_visibility' => $data['catalog_visibility'] ?? 'visible',
                    'sku'                => $sku,
                    'regular_price'      => $regularPrice,
                    'sale_price'         => $salePrice,
                    'sale_from'          => $data['sale_from'] ?? null,
                    'sale_to'            => $data['sale_to'] ?? null,
                    'virtual'            => !empty($data['virtual']) ? 1 : 0,
                    'downloadable'       => !empty($data['downloadable']) ? 1 : 0,
                    'tax_status'         => $data['tax_status'] ?? 'taxable',
                    'tax_class'          => $data['tax_class'] ?? '',
                    'sold_individually'  => !empty($data['sold_individually']) ? 1 : 0,
                    'purchase_note'      => $data['purchase_note'] ?? '',
                    'menu_order'         => (int)($data['menu_order'] ?? 0),
                    'featured'           => !empty($data['featured']) ? 1 : 0,
                    'shipping_class_id'  => $data['shipping_class_id'] ?? null,
                    'weight'             => $data['weight'] ?? null,
                    'length'             => $data['length'] ?? null,
                    'width'              => $data['width'] ?? null,
                    'height'             => $data['height'] ?? null,
                    'stock_owner_id'     => $productId,
                    'default_selection'  => json_encode($data['default_selection'] ?? [], JSON_UNESCAPED_UNICODE),
                    'extra_props'        => json_encode($data['extra_props'] ?? [], JSON_UNESCAPED_UNICODE),
                    'created_at'         => $now,
                    'updated_at'         => $now,
                    'deleted_at'         => null,
                    'version'            => $version,
                ]
            );

            if ($insertResult === false) {
                throw new RuntimeException("Failed to insert product row: " . $this->wpdb->last_error);
            }

            // Insert into wpe_inventory
            $manageStock = !empty($data['manage_stock']) ? 1 : 0;
            $stockQty = $this->validateQuantity($data['stock_quantity'] ?? null);
            $stockStatus = $data['stock_status'] ?? ($manageStock && (float)$stockQty <= 0 ? 'outofstock' : 'instock');

            $invResult = $this->wpdb->insert(
                $this->tableInventory,
                [
                    'owner_id'         => $productId,
                    'manage_stock'     => $manageStock,
                    'quantity'         => $stockQty,
                    'backorders'       => $data['backorders'] ?? 'no',
                    'stock_status'     => $stockStatus,
                    'low_stock_amount' => $data['low_stock_amount'] ?? null,
                    'version'          => $version,
                    'updated_at'       => $now,
                ]
            );

            if ($invResult === false) {
                throw new RuntimeException("Failed to insert inventory row: " . $this->wpdb->last_error);
            }

            // Process gallery images if provided
            if (!empty($data['gallery_image_ids']) && is_array($data['gallery_image_ids'])) {
                $pos = 1;
                foreach ($data['gallery_image_ids'] as $assetId) {
                    $this->wpdb->insert(
                        $this->tableProductMedia,
                        [
                            'product_id'   => $productId,
                            'asset_id'     => (int)$assetId,
                            'role'         => 'gallery',
                            'position'     => $pos++,
                            'alt_override' => null,
                        ]
                    );
                }
            }

            // Process variations if variable product
            $incomingParentId = isset($data['id']) ? (string)$data['id'] : null;
            $createdVariations = [];
            if ($type === 'variable' && !empty($data['variations']) && is_array($data['variations'])) {
                foreach ($data['variations'] as $vData) {
                    $createdVariations[] = $this->saveVariantRow($productId, $vData, $isMigration, $claimedSkus, $now, $epoch, $incomingParentId);
                }
            }

            // Update wpe_object_state
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$this->tableObjectState}
                 (object_id, kind, parent_id, authority, epoch, legacy_revision, native_revision, legacy_applied, native_applied, tombstone, updated_at)
                 VALUES (%d, 'product', NULL, 'native', %d, 1, %d, 0, %d, 0, %s)
                 ON DUPLICATE KEY UPDATE
                 epoch = VALUES(epoch), native_revision = VALUES(native_revision), native_applied = VALUES(native_applied), updated_at = VALUES(updated_at), tombstone = 0",
                $productId, $epoch, $version, $version, $now
            ));

            // Record outbox event and audit log
            $this->outboxWorker->publishEvent(
                $productId,
                $version,
                $epoch,
                'product.created',
                [
                    'product_id' => $productId,
                    'type'       => $type,
                    'status'     => $status,
                    'sku'        => $sku,
                    'version'    => $version,
                ]
            );

            $actorId = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->generateUuid();
            $this->outboxWorker->logAudit(
                'product.create',
                $productId,
                $actorId,
                $requestId,
                ['product_id' => $productId, 'status' => $status, 'type' => $type]
            );

            $this->wpdb->query("COMMIT");
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }

        return $this->getById($productId);
    }

    /**
     * Update an existing product aggregate with CAS version check and aggregate locking.
     *
     * @param int $productId
     * @param array $data
     * @return array Updated canonical product aggregate
     * @throws StaleVersionException (412) on version mismatch
     */
    public function update(int $productId, array $data): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $epoch = $this->outboxWorker->getCurrentEpoch();

        // Start transaction
        $this->wpdb->query("START TRANSACTION");

        try {
            // Lock parent row FOR UPDATE
            $current = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableProducts} WHERE id = %d FOR UPDATE",
                $productId
            ), ARRAY_A);

            if (!$current) {
                throw new RuntimeException("Product not found: {$productId}");
            }

            $currentVersion = (int)$current['version'];

            // Optimistic Locking / CAS check (R22)
            if (isset($data['version']) && (int)$data['version'] !== $currentVersion) {
                throw new StaleVersionException(
                    "Stale write detected for product {$productId}: expected version {$data['version']}, current database version is {$currentVersion}",
                    412
                );
            }

            $newVersion = $currentVersion + 1;

            // Price validation
            $regularPrice = array_key_exists('regular_price', $data)
                ? $this->validatePrice($data['regular_price'], 'regular_price')
                : $current['regular_price'];
            $salePrice = array_key_exists('sale_price', $data)
                ? $this->validatePrice($data['sale_price'], 'sale_price')
                : $current['sale_price'];

            $title = $data['name'] ?? $data['title'] ?? $current['title'];
            $slug = $data['slug'] ?? $current['slug'];
            $sku = array_key_exists('sku', $data)
                ? (isset($data['sku']) ? trim((string)$data['sku']) : null)
                : $current['sku'];
            $status = $data['status'] ?? $current['status'];

            $claimedSkus = [];
            if (!empty($sku)) {
                $this->registerSku($sku, $productId, 'product', $claimedSkus);
            } else {
                $this->wpdb->delete($this->tableSkuRegistry, ['object_id' => $productId]);
            }

            // Update wpe_products
            $updateProductResult = $this->wpdb->update(
                $this->tableProducts,
                [
                    'status'             => $status,
                    'slug'               => $slug,
                    'title'              => $title,
                    'description'        => $data['description'] ?? $current['description'],
                    'short_description'  => $data['short_description'] ?? $current['short_description'],
                    'catalog_visibility' => $data['catalog_visibility'] ?? $current['catalog_visibility'],
                    'sku'                => $sku,
                    'regular_price'      => $regularPrice,
                    'sale_price'         => $salePrice,
                    'sale_from'          => $data['sale_from'] ?? $current['sale_from'],
                    'sale_to'            => $data['sale_to'] ?? $current['sale_to'],
                    'virtual'            => isset($data['virtual']) ? (!empty($data['virtual']) ? 1 : 0) : $current['virtual'],
                    'downloadable'       => isset($data['downloadable']) ? (!empty($data['downloadable']) ? 1 : 0) : $current['downloadable'],
                    'tax_status'         => $data['tax_status'] ?? $current['tax_status'],
                    'tax_class'          => $data['tax_class'] ?? $current['tax_class'],
                    'sold_individually'  => isset($data['sold_individually']) ? (!empty($data['sold_individually']) ? 1 : 0) : $current['sold_individually'],
                    'purchase_note'      => $data['purchase_note'] ?? $current['purchase_note'],
                    'menu_order'         => isset($data['menu_order']) ? (int)$data['menu_order'] : $current['menu_order'],
                    'featured'           => isset($data['featured']) ? (!empty($data['featured']) ? 1 : 0) : $current['featured'],
                    'shipping_class_id'  => $data['shipping_class_id'] ?? $current['shipping_class_id'],
                    'weight'             => $data['weight'] ?? $current['weight'],
                    'length'             => $data['length'] ?? $current['length'],
                    'width'              => $data['width'] ?? $current['width'],
                    'height'             => $data['height'] ?? $current['height'],
                    'default_selection'  => isset($data['default_selection']) ? json_encode($data['default_selection'], JSON_UNESCAPED_UNICODE) : $current['default_selection'],
                    'extra_props'        => isset($data['extra_props']) ? json_encode($data['extra_props'], JSON_UNESCAPED_UNICODE) : $current['extra_props'],
                    'updated_at'         => $now,
                    'version'            => $newVersion,
                ],
                ['id' => $productId]
            );

            if ($updateProductResult === false) {
                throw new RuntimeException("Failed to update product row: " . $this->wpdb->last_error);
            }

            // Update inventory
            if (isset($data['manage_stock']) || isset($data['stock_quantity']) || isset($data['stock_status']) || isset($data['backorders'])) {
                $curInv = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d FOR UPDATE",
                    $productId
                ), ARRAY_A);

                $manageStock = isset($data['manage_stock']) ? (!empty($data['manage_stock']) ? 1 : 0) : (int)($curInv['manage_stock'] ?? 0);
                $stockQty = isset($data['stock_quantity']) ? $this->validateQuantity($data['stock_quantity']) : ($curInv['quantity'] ?? null);
                $stockStatus = $data['stock_status'] ?? ($curInv['stock_status'] ?? 'instock');

                $this->wpdb->replace(
                    $this->tableInventory,
                    [
                        'owner_id'         => $productId,
                        'manage_stock'     => $manageStock,
                        'quantity'         => $stockQty,
                        'backorders'       => $data['backorders'] ?? ($curInv['backorders'] ?? 'no'),
                        'stock_status'     => $stockStatus,
                        'low_stock_amount' => $data['low_stock_amount'] ?? ($curInv['low_stock_amount'] ?? null),
                        'version'          => $newVersion,
                        'updated_at'       => $now,
                    ]
                );
            }

            // Update gallery images if supplied
            if (isset($data['gallery_image_ids']) && is_array($data['gallery_image_ids'])) {
                $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId, 'role' => 'gallery']);
                $pos = 1;
                foreach ($data['gallery_image_ids'] as $assetId) {
                    $this->wpdb->insert(
                        $this->tableProductMedia,
                        [
                            'product_id'   => $productId,
                            'asset_id'     => (int)$assetId,
                            'role'         => 'gallery',
                            'position'     => $pos++,
                            'alt_override' => null,
                        ]
                    );
                }
            }

            // Update variations if supplied
            if (isset($data['variations']) && is_array($data['variations'])) {
                foreach ($data['variations'] as $vData) {
                    $this->saveVariantRow($productId, $vData, false, $claimedSkus, $now, $epoch);
                }
            }

            // Update wpe_object_state
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableObjectState}
                 SET native_revision = %d, native_applied = %d, updated_at = %s, tombstone = 0
                 WHERE object_id = %d",
                $newVersion, $newVersion, $now, $productId
            ));

            // Record outbox event and audit
            $this->outboxWorker->publishEvent(
                $productId,
                $newVersion,
                $epoch,
                'product.updated',
                [
                    'product_id' => $productId,
                    'status'     => $status,
                    'sku'        => $sku,
                    'version'    => $newVersion,
                ]
            );

            $actorId = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->generateUuid();
            $this->outboxWorker->logAudit(
                'product.update',
                $productId,
                $actorId,
                $requestId,
                ['product_id' => $productId, 'status' => $status]
            );

            $this->wpdb->query("COMMIT");
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }

        return $this->getById($productId);
    }

    /**
     * Get product aggregate by ID.
     */
    public function getById(int $productId, bool $includeDeleted = false): ?array
    {
        $sql = "SELECT * FROM {$this->tableProducts} WHERE id = %d";
        if (!$includeDeleted) {
            $sql .= " AND deleted_at IS NULL AND status != 'trash'";
        }

        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $productId), ARRAY_A);
        if (!$row) {
            return null;
        }

        return $this->hydrateProductAggregate($row, $includeDeleted);
    }

    /**
     * Get product aggregate by SKU.
     */
    public function getBySku(string $sku, bool $includeDeleted = false): ?array
    {
        $trimmed = trim($sku);
        if ($trimmed === '') {
            return null;
        }

        $lower = mb_strtolower($trimmed, 'UTF-8');
        $reg = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT object_id, object_kind FROM {$this->tableSkuRegistry} WHERE normalized_sku = %s OR normalized_sku = %s",
            $trimmed,
            $lower
        ));

        if (!$reg) {
            return null;
        }

        if ($reg->object_kind === 'variation') {
            $variant = $this->getVariantById((int)$reg->object_id, $includeDeleted);
            if (!$variant) {
                return null;
            }
            return $this->getById((int)$variant['parent_id'], $includeDeleted);
        }

        return $this->getById((int)$reg->object_id, $includeDeleted);
    }

    /**
     * Get variant row by ID.
     */
    public function getVariantById(int $variantId, bool $includeDeleted = false): ?array
    {
        $sql = "SELECT * FROM {$this->tableVariants} WHERE id = %d";
        if (!$includeDeleted) {
            $sql .= " AND deleted_at IS NULL AND status != 'trash'";
        }

        $row = $this->wpdb->get_row($this->wpdb->prepare($sql, $variantId), ARRAY_A);
        if (!$row) {
            return null;
        }

        $inv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $variantId
        ), ARRAY_A);

        return [
            'id'             => (string)$row['id'],
            'parent_id'      => (string)$row['product_id'],
            'sku'            => $row['sku'],
            'status'         => $row['status'],
            'regular_price'  => $row['regular_price'] !== null ? sprintf('%.6f', (float)$row['regular_price']) : null,
            'sale_price'     => $row['sale_price'] !== null ? sprintf('%.6f', (float)$row['sale_price']) : null,
            'manage_stock'   => !empty($inv['manage_stock']),
            'stock_quantity' => $inv && $inv['quantity'] !== null ? sprintf('%.6f', (float)$inv['quantity']) : null,
            'position'       => (int)$row['position'],
            'version'        => (int)$row['version'],
            'deleted_at'     => $row['deleted_at'],
        ];
    }

    /**
     * Soft delete (trash) product aggregate and its variants (tombstone).
     */
    public function trash(int $productId): bool
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $epoch = $this->outboxWorker->getCurrentEpoch();

        $this->wpdb->query("START TRANSACTION");

        try {
            $current = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableProducts} WHERE id = %d FOR UPDATE",
                $productId
            ), ARRAY_A);

            if (!$current) {
                $this->wpdb->query("ROLLBACK");
                return false;
            }

            $newVersion = (int)$current['version'] + 1;

            // Set parent to trash and deleted_at
            $this->wpdb->update(
                $this->tableProducts,
                [
                    'status'     => 'trash',
                    'deleted_at' => $now,
                    'updated_at' => $now,
                    'version'    => $newVersion,
                ],
                ['id' => $productId]
            );

            // Set variants to trash, deleted_at, active_combination_key = NULL (tombstone)
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableVariants}
                 SET status = 'trash', deleted_at = %s, active_combination_key = NULL, updated_at = %s, version = version + 1
                 WHERE product_id = %d",
                $now, $now, $productId
            ));

            // Set tombstone in wpe_object_state
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableObjectState}
                 SET tombstone = 1, updated_at = %s, native_revision = %d
                 WHERE object_id = %d OR parent_id = %d",
                $now, $newVersion, $productId, $productId
            ));

            $this->outboxWorker->publishEvent(
                $productId,
                $newVersion,
                $epoch,
                'product.trashed',
                ['product_id' => $productId, 'status' => 'trash', 'version' => $newVersion]
            );

            $actorId = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->generateUuid();
            $this->outboxWorker->logAudit(
                'product.trash',
                $productId,
                $actorId,
                $requestId,
                ['product_id' => $productId, 'action' => 'trash']
            );

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Restore soft-deleted product and its variants.
     */
    public function restore(int $productId): bool
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $epoch = $this->outboxWorker->getCurrentEpoch();

        $this->wpdb->query("START TRANSACTION");

        try {
            $current = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableProducts} WHERE id = %d FOR UPDATE",
                $productId
            ), ARRAY_A);

            if (!$current) {
                $this->wpdb->query("ROLLBACK");
                return false;
            }

            $newVersion = (int)$current['version'] + 1;

            $this->wpdb->update(
                $this->tableProducts,
                [
                    'status'     => 'publish',
                    'deleted_at' => null,
                    'updated_at' => $now,
                    'version'    => $newVersion,
                ],
                ['id' => $productId]
            );

            // Restore active_combination_key = combination_key for variants
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableVariants}
                 SET status = 'publish', deleted_at = NULL, active_combination_key = combination_key, updated_at = %s, version = version + 1
                 WHERE product_id = %d",
                $now, $productId
            ));

            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$this->tableObjectState}
                 SET tombstone = 0, updated_at = %s, native_revision = %d
                 WHERE object_id = %d OR parent_id = %d",
                $now, $newVersion, $productId, $productId
            ));

            $this->outboxWorker->publishEvent(
                $productId,
                $newVersion,
                $epoch,
                'product.restored',
                ['product_id' => $productId, 'status' => 'publish', 'version' => $newVersion]
            );

            $actorId = function_exists('get_current_user_id') ? get_current_user_id() : null;
            $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->generateUuid();
            $this->outboxWorker->logAudit(
                'product.restore',
                $productId,
                $actorId,
                $requestId,
                ['product_id' => $productId, 'action' => 'restore']
            );

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Delete product. If $force = false, performs soft delete; if true, permanently purges.
     */
    public function delete(int $productId, bool $force = false): bool
    {
        if (!$force) {
            return $this->trash($productId);
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->query("START TRANSACTION");

        try {
            $variants = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->tableVariants} WHERE product_id = %d",
                $productId
            ));

            $allIds = array_merge([$productId], array_map('intval', $variants));

            foreach ($allIds as $id) {
                $this->wpdb->delete($this->tableSkuRegistry, ['object_id' => $id]);
                $this->wpdb->delete($this->tableInventory, ['owner_id' => $id]);
                $this->wpdb->delete($this->tableObjectState, ['object_id' => $id]);
            }

            $this->wpdb->delete($this->tableProductMedia, ['product_id' => $productId]);
            $this->wpdb->delete($this->tableProductLinks, ['product_id' => $productId]);
            $this->wpdb->delete($this->tableDownloads, ['object_id' => $productId]);
            $this->wpdb->delete($this->tableVariants, ['product_id' => $productId]);
            $this->wpdb->delete($this->tableProducts, ['id' => $productId]);

            $this->wpdb->query("COMMIT");
            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query("ROLLBACK");
            throw $e;
        }
    }

    /**
     * Save a single variation row inside aggregate transaction.
     */
    private function saveVariantRow(int $productId, array $vData, bool $isMigration, array &$claimedSkus, string $now, int $epoch, ?string $incomingParentId = null): array
    {
        // Variant parent ID validation (R20)
        if (isset($vData['parent_id'])) {
            if ($incomingParentId !== null && (string)$vData['parent_id'] === $incomingParentId) {
                $vData['parent_id'] = (string)$productId;
            }
            if ((int)$vData['parent_id'] !== $productId) {
                throw new InvalidParentException(
                    "Variant parent ID mismatch: variation specifies parent_id {$vData['parent_id']} but aggregate product ID is {$productId}"
                );
            }
        }

        // Allocate or reuse variant ID
        if ($isMigration && !empty($vData['id'])) {
            $variantId = $this->postIdAllocator->reuseLegacyId((int)$vData['id'], 'product_variation');
        } elseif (!empty($vData['id']) && $this->postIdAllocator->postExists((int)$vData['id'])) {
            $variantId = (int)$vData['id'];
        } else {
            $variantId = $this->postIdAllocator->allocateShell('product_variation');
        }

        // Check if existing variant belongs to another parent
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT product_id, version FROM {$this->tableVariants} WHERE id = %d",
            $variantId
        ), ARRAY_A);

        if ($existing && (int)$existing['product_id'] !== $productId) {
            throw new InvalidParentException(
                "Variant ID {$variantId} already belongs to another parent {$existing['product_id']}, cannot attach to {$productId}"
            );
        }

        $vVersion = $existing ? ((int)$existing['version'] + 1) : 1;

        // Price validation
        $vRegularPrice = $this->validatePrice($vData['regular_price'] ?? null, 'variation.regular_price');
        $vSalePrice = $this->validatePrice($vData['sale_price'] ?? null, 'variation.sale_price');
        $vSku = isset($vData['sku']) ? trim((string)$vData['sku']) : null;
        $status = $vData['status'] ?? 'publish';

        if (!empty($vSku)) {
            $this->registerSku($vSku, $variantId, 'variation', $claimedSkus);
        }

        // Generate combination_key from attributes
        $attrs = $vData['attributes'] ?? [];
        if (is_array($attrs)) {
            ksort($attrs);
            $canonicalAttrs = json_encode($attrs, JSON_UNESCAPED_UNICODE);
        } else {
            $canonicalAttrs = (string)$attrs;
        }
        $combHex = hash('sha256', $canonicalAttrs);

        // Insert or update variant row preserving SQL NULL for nullable fields
        $variantRowData = [
            'product_id'        => $productId,
            'status'            => $status,
            'sku'               => ($vSku !== null && $vSku !== '') ? $vSku : null,
            'regular_price'     => $vRegularPrice,
            'sale_price'        => $vSalePrice,
            'sale_from'         => $vData['sale_from'] ?? null,
            'sale_to'           => $vData['sale_to'] ?? null,
            'tax_class'         => $vData['tax_class'] ?? null,
            'virtual'           => !empty($vData['virtual']) ? 1 : 0,
            'downloadable'      => !empty($vData['downloadable']) ? 1 : 0,
            'weight'            => $vData['weight'] ?? null,
            'length'            => $vData['length'] ?? null,
            'width'             => $vData['width'] ?? null,
            'height'            => $vData['height'] ?? null,
            'shipping_class_id' => $vData['shipping_class_id'] ?? null,
            'description'       => $vData['description'] ?? '',
            'stock_owner_id'    => $variantId,
            'position'          => (int)($vData['position'] ?? 0),
            'extra_props'       => json_encode($vData['extra_props'] ?? [], JSON_UNESCAPED_UNICODE),
            'version'           => $vVersion,
            'updated_at'        => $now,
            'deleted_at'        => null,
        ];

        if ($existing) {
            $saveVariantRes = $this->wpdb->update(
                $this->tableVariants,
                $variantRowData,
                ['id' => $variantId]
            );
        } else {
            $variantRowData['id'] = $variantId;
            $variantRowData['combination_key'] = str_repeat("\0", 32);
            $variantRowData['created_at'] = $now;
            $saveVariantRes = $this->wpdb->insert(
                $this->tableVariants,
                $variantRowData
            );
        }

        if ($saveVariantRes === false) {
            throw new RuntimeException("Failed to save variant row: " . $this->wpdb->last_error);
        }

        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->tableVariants}
             SET combination_key = UNHEX(%s), active_combination_key = UNHEX(%s)
             WHERE id = %d",
            $combHex,
            $combHex,
            $variantId
        ));

        // Variant inventory
        $vManageStock = !empty($vData['manage_stock']) ? 1 : 0;
        $vStockQty = $this->validateQuantity($vData['stock_quantity'] ?? null);
        $vStockStatus = $vData['stock_status'] ?? ($vManageStock && (float)$vStockQty <= 0 ? 'outofstock' : 'instock');

        $this->wpdb->replace(
            $this->tableInventory,
            [
                'owner_id'         => $variantId,
                'manage_stock'     => $vManageStock,
                'quantity'         => $vStockQty,
                'backorders'       => $vData['backorders'] ?? 'no',
                'stock_status'     => $vStockStatus,
                'low_stock_amount' => $vData['low_stock_amount'] ?? null,
                'version'          => $vVersion,
                'updated_at'       => $now,
            ]
        );

        // Update wpe_object_state for variation
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableObjectState}
             (object_id, kind, parent_id, authority, epoch, legacy_revision, native_revision, legacy_applied, native_applied, tombstone, updated_at)
             VALUES (%d, 'variation', %d, 'native', %d, 1, %d, 0, %d, 0, %s)
             ON DUPLICATE KEY UPDATE
             epoch = VALUES(epoch), native_revision = VALUES(native_revision), native_applied = VALUES(native_applied), updated_at = VALUES(updated_at), tombstone = 0",
            $variantId, $productId, $epoch, $vVersion, $vVersion, $now
        ));

        return [
            'id'             => (string)$variantId,
            'parent_id'      => (string)$productId,
            'sku'            => $vSku,
            'attributes'     => $attrs,
            'regular_price'  => $vRegularPrice,
            'sale_price'     => $vSalePrice,
            'manage_stock'   => (bool)$vManageStock,
            'stock_quantity' => $vStockQty,
            'position'       => (int)($vData['position'] ?? 0),
            'version'        => $vVersion,
        ];
    }

    /**
     * Register SKU into wpe_sku_registry with strict uniqueness check.
     */
    private function registerSku(string $sku, int $objectId, string $objectKind, array &$claimedSkus): void
    {
        $original = trim($sku);
        if ($original === '') {
            return;
        }

        // Check inside same batch/aggregate
        if (isset($claimedSkus[$original])) {
            throw new DuplicateSkuException("Duplicate SKU '{$original}' detected within aggregate payload.");
        }
        $claimedSkus[$original] = $objectId;

        // Check globally in DB
        $existing = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT object_id FROM {$this->tableSkuRegistry} WHERE normalized_sku = %s",
            $original
        ));

        if ($existing && (int)$existing->object_id !== $objectId) {
            throw new DuplicateSkuException("Duplicate SKU '{$original}' already registered to object {$existing->object_id}.");
        }

        // Upsert SKU registry
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableSkuRegistry} (normalized_sku, object_id, object_kind, original_sku)
             VALUES (%s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE normalized_sku = VALUES(normalized_sku), object_kind = VALUES(object_kind), original_sku = VALUES(original_sku)",
            $original,
            $objectId,
            $objectKind,
            $original
        ));
    }

    /**
     * Validate and normalize a price value to 6 decimal places.
     */
    private function validatePrice($price, string $fieldName): ?string
    {
        if ($price === null || $price === '') {
            return null;
        }
        if (!is_numeric($price)) {
            throw new InvalidArgumentException("Invalid {$fieldName}: price must be numeric, got '{$price}'");
        }
        if ((float)$price < 0) {
            throw new InvalidArgumentException("Invalid {$fieldName}: price cannot be negative, got '{$price}'");
        }
        return sprintf('%.6f', (float)$price);
    }

    /**
     * Validate and normalize quantity.
     */
    private function validateQuantity($qty): ?string
    {
        if ($qty === null || $qty === '') {
            return null;
        }
        if (!is_numeric($qty)) {
            throw new InvalidArgumentException("Invalid stock quantity: must be numeric, got '{$qty}'");
        }
        return sprintf('%.6f', (float)$qty);
    }

    /**
     * Hydrate full product aggregate array.
     */
    private function hydrateProductAggregate(array $row, bool $includeDeleted): array
    {
        $productId = (int)$row['id'];

        $inv = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $productId
        ), ARRAY_A);

        $mediaRows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT asset_id FROM {$this->tableProductMedia} WHERE product_id = %d AND role = 'gallery' ORDER BY position ASC",
            $productId
        ));
        $galleryIds = array_map('strval', $mediaRows ?: []);

        $varSql = "SELECT * FROM {$this->tableVariants} WHERE product_id = %d";
        if (!$includeDeleted) {
            $varSql .= " AND deleted_at IS NULL AND status != 'trash'";
        }
        $varSql .= " ORDER BY position ASC, id ASC";

        $varRows = $this->wpdb->get_results($this->wpdb->prepare($varSql, $productId), ARRAY_A);
        $variations = [];
        if (!empty($varRows)) {
            foreach ($varRows as $vr) {
                $vId = (int)$vr['id'];
                $vInv = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
                    $vId
                ), ARRAY_A);

                $variations[] = [
                    'id'             => (string)$vr['id'],
                    'parent_id'      => (string)$vr['product_id'],
                    'sku'            => $vr['sku'],
                    'status'         => $vr['status'],
                    'regular_price'  => $vr['regular_price'] !== null ? sprintf('%.6f', (float)$vr['regular_price']) : null,
                    'sale_price'     => $vr['sale_price'] !== null ? sprintf('%.6f', (float)$vr['sale_price']) : null,
                    'manage_stock'   => !empty($vInv['manage_stock']),
                    'stock_quantity' => $vInv && $vInv['quantity'] !== null ? sprintf('%.6f', (float)$vInv['quantity']) : null,
                    'position'       => (int)$vr['position'],
                    'version'        => (int)$vr['version'],
                    'deleted_at'     => $vr['deleted_at'],
                ];
            }
        }

        return [
            'id'                => (string)$row['id'],
            'type'              => $row['type'],
            'status'            => $row['status'],
            'name'              => $row['title'],
            'title'             => $row['title'],
            'description'       => $row['description'] ?? '',
            'short_description' => $row['short_description'] ?? '',
            'slug'              => $row['slug'],
            'sku'               => $row['sku'],
            'regular_price'     => $row['regular_price'] !== null ? sprintf('%.6f', (float)$row['regular_price']) : null,
            'sale_price'        => $row['sale_price'] !== null ? sprintf('%.6f', (float)$row['sale_price']) : null,
            'virtual'           => !empty($row['virtual']),
            'downloadable'      => !empty($row['downloadable']),
            'manage_stock'      => !empty($inv['manage_stock']),
            'stock_quantity'    => $inv && $inv['quantity'] !== null ? sprintf('%.6f', (float)$inv['quantity']) : null,
            'stock_status'      => $inv['stock_status'] ?? 'instock',
            'gallery_image_ids' => $galleryIds,
            'version'           => (int)$row['version'],
            'created_at'        => $row['created_at'],
            'updated_at'        => $row['updated_at'],
            'deleted_at'        => $row['deleted_at'],
            'variations'        => $variations,
        ];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
