<?php

namespace WPE\Compatibility\WooDataStore;

use wpdb;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\OutboxWorker;
use WPE\Compatibility\WordPress\MetaIdAllocator;

/**
 * MetaBridge — Adapts WordPress Metadata API (get/update/add/delete_post_meta)
 * to native product and variant engine storage.
 *
 * Implements:
 * - Direct mapping of known WC internal metadata keys to native typed columns (AC-T11-01).
 * - Full single/multiple query semantics for get_post_meta (R41).
 * - WordPress metadata API no-op return (false when unchanged) (R42).
 * - Recursion guard for re-entrant hook contexts (R43).
 * - Preservation of arbitrary third-party extension metadata in wpe_meta (R44).
 * - Classification checking blocking unclassified/unknown direct SQL writers (AC-T11-02).
 */
class MetaBridge
{
    private static ?MetaBridge $instance = null;
    private static array $inProgress = [];
    private static bool $filtersRegistered = false;

    private wpdb $wpdb;
    private ProductRepository $prodRepo;
    private MediaRepository $mediaRepo;
    private OutboxWorker $outboxWorker;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableMeta;
    private string $tableObjectState;
    private string $tableProductMedia;
    private string $tableProductLinks;
    private string $tableOutbox;

    /**
     * Map of owned metadata keys to property descriptors.
     */
    private const OWNED_KEYS = [
        '_price'                 => 'price',
        '_regular_price'         => 'regular_price',
        '_sale_price'            => 'sale_price',
        '_sku'                   => 'sku',
        '_manage_stock'          => 'manage_stock',
        '_stock'                 => 'stock_quantity',
        '_stock_status'          => 'stock_status',
        '_backorders'            => 'backorders',
        '_low_stock_amount'      => 'low_stock_amount',
        '_weight'                => 'weight',
        '_length'                => 'length',
        '_width'                 => 'width',
        '_height'                => 'height',
        '_tax_status'            => 'tax_status',
        '_tax_class'             => 'tax_class',
        '_virtual'               => 'virtual',
        '_downloadable'          => 'downloadable',
        '_featured'              => 'featured',
        '_thumbnail_id'          => 'image_id',
        '_product_image_gallery' => 'gallery_image_ids',
        '_upsell_ids'            => 'upsell_ids',
        '_crosssell_ids'         => 'cross_sell_ids',
        '_download_limit'        => 'download_limit',
        '_download_expiry'       => 'download_expiry',
        '_purchase_note'         => 'purchase_note',
        '_sold_individually'     => 'sold_individually',
        'total_sales'            => 'total_sales',
    ];

    public static function instance(?wpdb $wpdb = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($wpdb);
        }
        return self::$instance;
    }

    public function __construct(?wpdb $wpdb = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->prodRepo = new ProductRepository($this->wpdb);
        $this->mediaRepo = new MediaRepository($this->wpdb, new MetaIdAllocator($this->wpdb));
        $this->outboxWorker = new OutboxWorker($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableProducts     = $prefix . 'wpe_products';
        $this->tableVariants     = $prefix . 'wpe_variants';
        $this->tableInventory    = $prefix . 'wpe_inventory';
        $this->tableMeta         = $prefix . 'wpe_meta';
        $this->tableObjectState  = $prefix . 'wpe_object_state';
        $this->tableProductMedia = $prefix . 'wpe_product_media';
        $this->tableProductLinks = $prefix . 'wpe_product_links';
        $this->tableOutbox       = $prefix . 'wpe_outbox';
    }

    /**
     * Register WordPress metadata API filters.
     */
    public static function register(): void
    {
        if (self::$filtersRegistered) {
            return;
        }

        add_filter('get_post_metadata', [self::class, 'filterGetPostMetadata'], 10, 4);
        add_filter('update_post_metadata', [self::class, 'filterUpdatePostMetadata'], 10, 5);
        add_filter('add_post_metadata', [self::class, 'filterAddPostMetadata'], 10, 5);
        add_filter('delete_post_metadata', [self::class, 'filterDeletePostMetadata'], 10, 5);

        self::$filtersRegistered = true;
    }

    /**
     * Check if a given post ID is managed under native authority.
     */
    public function isNativeAuthority(int $objectId): bool
    {
        if ($objectId <= 0) {
            return false;
        }

        // Check wpe_object_state first
        $state = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT authority, tombstone FROM {$this->tableObjectState} WHERE object_id = %d",
            $objectId
        ), ARRAY_A);

        if ($state) {
            return $state['authority'] === 'native' && empty($state['tombstone']);
        }

        // Check if exists in wpe_products or wpe_variants
        $isProd = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL",
            $objectId
        ));
        if ($isProd > 0) return true;

        $isVar = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableVariants} WHERE id = %d AND deleted_at IS NULL",
            $objectId
        ));
        return $isVar > 0;
    }

    /* -------------------------------------------------------------------------- */
    /* Filter Handlers                                                            */
    /* -------------------------------------------------------------------------- */

    public static function filterGetPostMetadata($check, $objectId, $metaKey, $single)
    {
        $bridge = self::instance();
        if (!$bridge->isNativeAuthority((int)$objectId)) {
            return $check;
        }

        return $bridge->getPostMeta((int)$objectId, (string)$metaKey, (bool)$single);
    }

    public static function filterUpdatePostMetadata($check, $objectId, $metaKey, $metaValue, $prevValue)
    {
        $bridge = self::instance();
        if (!$bridge->isNativeAuthority((int)$objectId)) {
            return $check;
        }

        $guardKey = $objectId . ':' . $metaKey;
        if (!empty(self::$inProgress[$guardKey])) {
            return null; // Recursion protection (R43)
        }

        self::$inProgress[$guardKey] = true;
        try {
            $result = $bridge->updatePostMeta((int)$objectId, (string)$metaKey, $metaValue, $prevValue);
            return $result;
        } finally {
            unset(self::$inProgress[$guardKey]);
        }
    }

    public static function filterAddPostMetadata($check, $objectId, $metaKey, $metaValue, $unique)
    {
        $bridge = self::instance();
        if (!$bridge->isNativeAuthority((int)$objectId)) {
            return $check;
        }

        $guardKey = $objectId . ':' . $metaKey;
        if (!empty(self::$inProgress[$guardKey])) {
            return null;
        }

        self::$inProgress[$guardKey] = true;
        try {
            $result = $bridge->addPostMeta((int)$objectId, (string)$metaKey, $metaValue, (bool)$unique);
            return $result;
        } finally {
            unset(self::$inProgress[$guardKey]);
        }
    }

    public static function filterDeletePostMetadata($check, $objectId, $metaKey, $metaValue, $deleteAll)
    {
        $bridge = self::instance();
        if (!$bridge->isNativeAuthority((int)$objectId)) {
            return $check;
        }

        $guardKey = $objectId . ':' . $metaKey;
        if (!empty(self::$inProgress[$guardKey])) {
            return null;
        }

        self::$inProgress[$guardKey] = true;
        try {
            $result = $bridge->deletePostMeta((int)$objectId, (string)$metaKey, $metaValue, (bool)$deleteAll);
            return $result;
        } finally {
            unset(self::$inProgress[$guardKey]);
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Core Bridge Logic                                                          */
    /* -------------------------------------------------------------------------- */

    /**
     * Get post metadata (R41).
     * In WordPress metadata filter hook: returning an array of values wraps the single/multiple
     * result so that WordPress core can extract $check[0] if $single=true, or return $check if $single=false.
     * Returning null falls back to WordPress default ("" for single, [] for multiple).
     */
    public function getPostMeta(int $objectId, string $metaKey, bool $single)
    {
        // 1. If metaKey is empty string, return all metadata map
        if ($metaKey === '') {
            $all = $this->getAllPostMeta($objectId);
            return $single ? [$all] : $all;
        }

        // 2. If metaKey is an owned key
        if (isset(self::OWNED_KEYS[$metaKey])) {
            $prop = self::OWNED_KEYS[$metaKey];
            $val = $this->getOwnedProperty($objectId, $prop);

            if ($val === null || $val === '') {
                return null;
            }

            return [$val];
        }

        // 3. Arbitrary / third-party metadata in wpe_meta (R44)
        $meta = $this->mediaRepo->getMeta($objectId, $metaKey, false);

        if (empty($meta)) {
            return null;
        }

        return array_map('maybe_unserialize', $meta);
    }

    /**
     * Update post metadata (R42, R43, AC-T11-01).
     */
    public function updatePostMeta(int $objectId, string $metaKey, $metaValue, $prevValue = ''): bool
    {
        // Check current value for no-op return (R42)
        $currentWrap = $this->getPostMeta($objectId, $metaKey, true);
        $currentVal = !empty($currentWrap) ? $currentWrap[0] : '';

        // Normalize comparison for numeric and string values
        $isEqual = false;
        if ($currentVal === $metaValue) {
            $isEqual = true;
        } elseif (is_numeric($currentVal) && is_numeric($metaValue) && (float)$currentVal === (float)$metaValue) {
            $isEqual = true;
        } elseif (is_string($currentVal) && is_string($metaValue) && trim($currentVal) === trim((string)$metaValue)) {
            $isEqual = true;
        }

        if ($isEqual) {
            return false; // WordPress contract: returns false if value is unchanged
        }

        // 1. Owned keys update
        if (isset(self::OWNED_KEYS[$metaKey])) {
            $prop = self::OWNED_KEYS[$metaKey];
            $updated = $this->setOwnedProperty($objectId, $prop, $metaValue);

            if ($updated) {
                // Publish outbox event and audit log (AC-T11-01) with monotonic revision
                $epoch = $this->outboxWorker->getCurrentEpoch();
                $newRev = (int)$this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT COALESCE(MAX(revision), 0) + 1 FROM {$this->tableOutbox} WHERE aggregate_id = %d AND epoch = %d",
                    $objectId,
                    $epoch
                ));

                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$this->tableObjectState} SET native_revision = %d, native_applied = %d, updated_at = %s WHERE object_id = %d",
                    $newRev,
                    $newRev,
                    gmdate('Y-m-d H:i:s.u'),
                    $objectId
                ));

                $this->outboxWorker->publishEvent(
                    $objectId,
                    $newRev,
                    $epoch,
                    'product.metadata_updated',
                    [
                        'object_id' => $objectId,
                        'meta_key'  => $metaKey,
                        'value'     => $metaValue,
                    ]
                );

                $actorId = function_exists('get_current_user_id') ? get_current_user_id() : null;
                $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('req_', true);
                $this->outboxWorker->logAudit(
                    'metadata.update',
                    $objectId,
                    $actorId,
                    $requestId,
                    ['meta_key' => $metaKey, 'value' => $metaValue]
                );
                return true;
            }
            return false;
        }

        // 2. Arbitrary custom metadata update (R44)
        $this->mediaRepo->addMeta($objectId, 'product', $metaKey, $metaValue, true);
        return true;
    }

    /**
     * Add post metadata.
     */
    public function addPostMeta(int $objectId, string $metaKey, $metaValue, bool $unique = false)
    {
        if ($unique) {
            $existing = $this->getPostMeta($objectId, $metaKey, true);
            if (!empty($existing) && $existing[0] !== '') {
                return false;
            }
        }

        if (isset(self::OWNED_KEYS[$metaKey])) {
            $this->updatePostMeta($objectId, $metaKey, $metaValue);
            return true;
        }

        // Add to wpe_meta
        $metaId = $this->mediaRepo->addMeta($objectId, 'product', $metaKey, $metaValue, false);
        return $metaId > 0 ? $metaId : true;
    }

    /**
     * Delete post metadata.
     */
    public function deletePostMeta(int $objectId, string $metaKey, $metaValue = '', bool $deleteAll = false): bool
    {
        if (isset(self::OWNED_KEYS[$metaKey])) {
            $prop = self::OWNED_KEYS[$metaKey];
            return $this->resetOwnedProperty($objectId, $prop);
        }

        // Delete from wpe_meta
        $this->wpdb->delete($this->tableMeta, [
            'object_id' => $objectId,
            'meta_key'  => $metaKey,
        ]);
        return true;
    }

    /* -------------------------------------------------------------------------- */
    /* Property Helpers                                                           */
    /* -------------------------------------------------------------------------- */

    private function getOwnedProperty(int $objectId, string $prop)
    {
        // Determine whether product or variation
        $isVar = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableVariants} WHERE id = %d",
            $objectId
        ));

        $table = $isVar > 0 ? $this->tableVariants : $this->tableProducts;
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $objectId
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        // Inventory properties
        if (in_array($prop, ['manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount'], true)) {
            $inv = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
                $objectId
            ), ARRAY_A);

            if (!$inv) {
                return null;
            }

            switch ($prop) {
                case 'manage_stock':
                    return !empty($inv['manage_stock']) ? 'yes' : 'no';
                case 'stock_quantity':
                    return $inv['quantity'] !== null ? sprintf('%.6f', (float)$inv['quantity']) : null;
                case 'stock_status':
                    return $inv['stock_status'] ?? 'instock';
                case 'backorders':
                    return $inv['backorders'] ?? 'no';
                case 'low_stock_amount':
                    return $inv['low_stock_amount'] !== null ? (string)$inv['low_stock_amount'] : '';
            }
        }

        // Media properties
        if ($prop === 'image_id') {
            $featured = $this->mediaRepo->getFeaturedImage($objectId);
            if (!$featured) {
                return '';
            }
            $attId = !empty($featured['attachment_id']) ? $featured['attachment_id'] : ($featured['id'] ?? '');
            return (string)$attId;
        }

        if ($prop === 'gallery_image_ids') {
            $gallery = $this->mediaRepo->getGallery($objectId);
            $ids = [];
            foreach ($gallery as $item) {
                $id = !empty($item['attachment_id']) ? $item['attachment_id'] : ($item['id'] ?? null);
                if ($id !== null) {
                    $ids[] = (string)$id;
                }
            }
            return implode(',', $ids);
        }

        // Links
        if ($prop === 'upsell_ids') {
            $links = $this->mediaRepo->getLinks($objectId, 'upsell');
            return array_map('intval', array_column($links, 'target_id'));
        }

        if ($prop === 'cross_sell_ids') {
            $links = $this->mediaRepo->getLinks($objectId, 'crosssell');
            return array_map('intval', array_column($links, 'target_id'));
        }

        // Scalar columns
        switch ($prop) {
            case 'price':
                // Shared rule: a scheduled sale is not exposed to WooCommerce before its window opens.
                $priceInfo = \WPE\Application\Variant\EffectivePriceResolver::resolve($row);
                return $priceInfo['active_price'] ?? '';
            case 'regular_price':
            case 'sale_price':
                return $row[$prop] !== null ? sprintf('%.6f', (float)$row[$prop]) : '';
            case 'virtual':
            case 'downloadable':
            case 'featured':
            case 'sold_individually':
                return !empty($row[$prop]) ? 'yes' : 'no';
            case 'sku':
                return $row['sku'] ?? '';
            case 'weight':
            case 'length':
            case 'width':
            case 'height':
            case 'tax_class':
            case 'tax_status':
            case 'purchase_note':
                return isset($row[$prop]) && $row[$prop] !== null ? (string)$row[$prop] : '';
            case 'total_sales':
                $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
                return (string)($extra['total_sales'] ?? '0');
            default:
                return $row[$prop] ?? null;
        }
    }

    private function setOwnedProperty(int $objectId, string $prop, $value): bool
    {
        $isVar = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableVariants} WHERE id = %d",
            $objectId
        ));

        $table = $isVar > 0 ? $this->tableVariants : $this->tableProducts;
        $now = gmdate('Y-m-d H:i:s.u');

        // Inventory properties
        if (in_array($prop, ['manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount'], true)) {
            $invUpdate = ['updated_at' => $now];
            if ($prop === 'manage_stock') {
                $invUpdate['manage_stock'] = ($value === 'yes' || $value === true || $value === 1 || $value === '1') ? 1 : 0;
            } elseif ($prop === 'stock_quantity') {
                $invUpdate['quantity'] = $value !== null && $value !== '' ? sprintf('%.6f', (float)$value) : null;
            } elseif ($prop === 'stock_status') {
                $invUpdate['stock_status'] = in_array($value, ['instock', 'outofstock', 'onbackorder'], true) ? $value : 'instock';
            } elseif ($prop === 'backorders') {
                $invUpdate['backorders'] = in_array($value, ['no', 'notify', 'yes'], true) ? $value : 'no';
            } elseif ($prop === 'low_stock_amount') {
                $invUpdate['low_stock_amount'] = $value !== '' && $value !== null ? (int)$value : null;
            }

            return $this->wpdb->update($this->tableInventory, $invUpdate, ['owner_id' => $objectId]) !== false;
        }

        // Media properties
        if ($prop === 'image_id') {
            $assetId = (int)$value;
            if ($assetId > 0) {
                return $this->mediaRepo->setFeaturedImage($objectId, $assetId);
            }
            $this->wpdb->delete($this->tableProductMedia, ['product_id' => $objectId, 'role' => 'primary']);
            return true;
        }

        if ($prop === 'gallery_image_ids') {
            $ids = is_array($value) ? $value : array_filter(array_map('intval', explode(',', (string)$value)));
            return $this->mediaRepo->setGallery($objectId, $ids);
        }

        // Links
        if ($prop === 'upsell_ids') {
            $ids = is_array($value) ? array_map('intval', $value) : [];
            return $this->mediaRepo->setLinks($objectId, 'upsell', $ids);
        }
        if ($prop === 'cross_sell_ids') {
            $ids = is_array($value) ? array_map('intval', $value) : [];
            return $this->mediaRepo->setLinks($objectId, 'crosssell', $ids);
        }

        // Scalar columns
        $payload = ['updated_at' => $now];
        switch ($prop) {
            case 'regular_price':
            case 'sale_price':
                $payload[$prop] = $value !== '' && $value !== null ? sprintf('%.6f', (float)$value) : null;
                break;
            case 'sku':
                $sku = trim((string)$value);
                $payload['sku'] = $sku !== '' ? $sku : null;
                if ($payload['sku']) {
                    $kind = $isVar > 0 ? 'variation' : 'product';
                    $this->wpdb->query($this->wpdb->prepare(
                        "INSERT INTO {$this->wpdb->prefix}wpe_sku_registry (normalized_sku, object_id, object_kind, original_sku)
                         VALUES (%s, %d, %s, %s)
                         ON DUPLICATE KEY UPDATE normalized_sku = VALUES(normalized_sku), object_kind = VALUES(object_kind), original_sku = VALUES(original_sku)",
                        $payload['sku'], $objectId, $kind, $payload['sku']
                    ));
                }
                break;
            case 'virtual':
            case 'downloadable':
            case 'featured':
            case 'sold_individually':
                $payload[$prop] = ($value === 'yes' || $value === true || $value === 1 || $value === '1') ? 1 : 0;
                break;
            case 'weight':
            case 'length':
            case 'width':
            case 'height':
            case 'tax_class':
            case 'tax_status':
            case 'purchase_note':
                $payload[$prop] = $value !== '' && $value !== null ? (string)$value : null;
                break;
            case 'total_sales':
                $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT extra_props FROM {$table} WHERE id = %d", $objectId), ARRAY_A);
                $extra = json_decode($row['extra_props'] ?? '{}', true) ?: [];
                $extra['total_sales'] = (int)$value;
                $payload['extra_props'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
                break;
            default:
                return false;
        }

        return $this->wpdb->update($table, $payload, ['id' => $objectId]) !== false;
    }

    private function resetOwnedProperty(int $objectId, string $prop): bool
    {
        return $this->setOwnedProperty($objectId, $prop, null);
    }

    private function getAllPostMeta(int $objectId): array
    {
        $all = [];

        // Add all owned keys
        foreach (self::OWNED_KEYS as $metaKey => $prop) {
            $val = $this->getOwnedProperty($objectId, $prop);
            if ($val !== null && $val !== '') {
                $all[$metaKey] = [$val];
            }
        }

        // Add arbitrary meta
        $metaMap = $this->mediaRepo->getMeta($objectId);
        foreach ($metaMap as $key => $values) {
            $all[$key] = array_map('maybe_unserialize', $values);
        }

        return $all;
    }

    /* -------------------------------------------------------------------------- */
    /* Extension Compatibility & SQL Writer Safety (AC-T11-02)                    */
    /* -------------------------------------------------------------------------- */

    /**
     * Check if a plugin is allowed to operate under native cohort authority.
     * Blocks unclassified plugins or plugins with uncertified direct SQL writers.
     */
    public function canPluginWriteNative(string $pluginSlug): bool
    {
        $matrixPath = dirname(__DIR__, 3) . '/contracts/compatibility/matrix.json';
        if (!file_exists($matrixPath)) {
            return false;
        }

        $matrix = json_decode(file_get_contents($matrixPath), true);
        $plugins = $matrix['third_party_plugins'] ?? $matrix['plugins'] ?? [];

        foreach ($plugins as $p) {
            if ($p['slug'] === $pluginSlug) {
                // If status is blocked or classification is SQL-writer, reject native
                if ($p['status'] === 'blocked' || $p['classification'] === 'SQL-writer') {
                    return false;
                }
                return $p['status'] === 'supported';
            }
        }

        // Unclassified / unknown plugins default to FALSE (blocked from native cohort authority)
        return false;
    }
}
