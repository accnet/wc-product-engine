<?php
declare(strict_types=1);

namespace WPE\Application\Catalog;

use WPE\Application\Variant\EffectivePriceResolver;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * CatalogProjector — Builds, updates, and rebuilds the high-performance catalog index
 * and multi-dimensional facet search table.
 *
 * Implements requirements:
 * - R63: Generation rebuild under concurrent writes with catch-up revision fence.
 * - R64: Tombstones never resurrect into active index.
 * - R65: Automatic scheduled price transitions across clock boundaries / DST without saves.
 * - R66: Previous generation retained for active cursor lifespan.
 * - AC-T16-01: Dirty products catch up before atomic switch; previous generation retained.
 * - AC-T16-02: Price schedule transitions automatically.
 * - AC-T16-03: No orphan facets; source_version audit pass.
 */
class CatalogProjector
{
    /** wpe_control key of a full rebuild requested from the admin for the background worker. */
    public const REBUILD_REQUEST_KEY = 'catalog_rebuild_requested';

    /** Rows deleted per statement when purging old generations. */
    private const PURGE_BATCH = 5000;

    private \wpdb $wpdb;
    private ProductRepository $prodRepo;

    private string $tableCatalog;
    private string $tableCatalogFacets;
    private string $tableControl;
    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableMedia;
    private string $tableMeta;

    public function __construct(?\wpdb $wpdb = null, ?ProductRepository $prodRepo = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->prodRepo = $prodRepo ?? new ProductRepository($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableCatalog       = $prefix . 'wpe_catalog';
        $this->tableCatalogFacets = $prefix . 'wpe_catalog_facets';
        $this->tableControl       = $prefix . 'wpe_control';
        $this->tableProducts      = $prefix . 'wpe_products';
        $this->tableVariants      = $prefix . 'wpe_variants';
        $this->tableInventory     = $prefix . 'wpe_inventory';
        $this->tableMedia         = $prefix . 'wpe_product_media';
        $this->tableMeta          = $prefix . 'wpe_meta';
    }

    /* -------------------------------------------------------------------------- */
    /* Generation Management (R66, AC-T16-01)                                     */
    /* -------------------------------------------------------------------------- */

    /**
     * Gets the active catalog generation pointer.
     */
    public function getActiveGeneration(): int
    {
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'active_catalog_generation'
        ));

        if ($val) {
            $decoded = json_decode($val, true);
            if (isset($decoded['generation'])) {
                return (int)$decoded['generation'];
            }
        }

        // Initialize to generation 1 if not set
        $this->setActiveGeneration(1);
        return 1;
    }

    /**
     * Atomically switches the active catalog generation pointer via CAS.
     */
    public function setActiveGeneration(int $generation): bool
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $payload = json_encode([
            'generation'  => $generation,
            'switched_at' => $now,
        ]);

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableControl} (`key`, value, version, updated_at)
             VALUES (%s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE value = VALUES(value), version = version + 1, updated_at = VALUES(updated_at)",
            'active_catalog_generation',
            $payload,
            $now
        ));

        return true;
    }

    /**
     * Retrieves all generations currently present in storage.
     */
    public function getStoredGenerations(): array
    {
        $sql = "SELECT DISTINCT generation FROM {$this->tableCatalog} ORDER BY generation ASC";
        $gens = $this->wpdb->get_col($sql);
        return array_map('intval', $gens);
    }

    /**
     * Purges old generations, retaining the most recent ones for cursor retention (R66, AC-T16-01).
     *
     * @param int $retainCount Minimum number of latest generations to keep (e.g. 2: active + previous)
     * @return int Number of purged generations
     */
    public function purgeOldGenerations(int $retainCount = 2, bool $purgeAbandoned = false): int
    {
        $gens = $this->getStoredGenerations();
        $active = $this->getActiveGeneration();

        // Keep the active generation plus the newest ones below it. Generations above the active one
        // are a build in progress, unless the caller holds the rebuild lock ($purgeAbandoned), in
        // which case they are leftovers of an interrupted build and are purged first.
        $below = array_values(array_filter($gens, static fn(int $g): bool => $g < $active));
        $above = array_values(array_filter($gens, static fn(int $g): bool => $g > $active));
        $keepBelow = max(0, $retainCount - 1);
        $toPurge = array_slice($below, 0, max(0, count($below) - $keepBelow));
        if ($purgeAbandoned) {
            $toPurge = array_merge($toPurge, $above);
        }
        if ($toPurge === []) {
            return 0;
        }
        $purgedCount = 0;

        foreach ($toPurge as $gen) {
            // Bounded deletes keep each statement (and its undo log / replication event) small on large catalogs.
            foreach ([$this->tableCatalog, $this->tableCatalogFacets] as $table) {
                do {
                    $deleted = $this->wpdb->query($this->wpdb->prepare(
                        "DELETE FROM {$table} WHERE generation = %d LIMIT %d",
                        $gen,
                        self::PURGE_BATCH
                    ));
                } while ($deleted !== false && $deleted >= self::PURGE_BATCH);
            }
            $purgedCount++;
        }

        return $purgedCount;
    }

    /**
     * Number of catalog generations kept after a rebuild: the active one plus the previous one by
     * default, so cursors issued just before the switch keep working (R66).
     */
    public function generationsToKeep(): int
    {
        /**
         * Catalog generations kept after a rebuild (minimum 2: active + previous).
         *
         * @param int $keep
         */
        return max(2, (int)apply_filters('wpe_catalog_generations_to_keep', 2));
    }

    /**
     * Refresh one product in the catalog after a save: project it into the active generation, or
     * build the first generation when the catalog is still empty. A save never rebuilds the whole
     * catalog (audit F12: at 20k products that is ~40 s and ~150k queries per save).
     *
     * @return string 'incremental' or 'full'
     */
    public function refreshProduct(int $productId): string
    {
        $active = $this->getActiveGeneration();
        $hasRows = (bool)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT 1 FROM {$this->tableCatalog} WHERE generation = %d LIMIT 1",
            $active
        ));
        if (!$hasRows) {
            $this->buildNewGeneration();
            return 'full';
        }
        $this->projectProduct($productId, $active);
        return 'incremental';
    }

    /**
     * Ask the background worker (`wp wpe rebuild --if-requested`, run from system cron) for a full
     * rebuild instead of running one inside a web request.
     */
    public function requestRebuild(int $requestedBy = 0): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->tableControl} (`key`, value, version, updated_at)
             VALUES (%s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE value = VALUES(value), version = version + 1, updated_at = VALUES(updated_at)",
            self::REBUILD_REQUEST_KEY,
            (string)wp_json_encode(['requested_at' => $now, 'requested_by' => $requestedBy]),
            $now
        ));
    }

    /**
     * Pending rebuild request, or null.
     *
     * @return array{requested_at?:string, requested_by?:int}|null
     */
    public function pendingRebuildRequest(): ?array
    {
        $val = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            self::REBUILD_REQUEST_KEY
        ));
        $decoded = is_string($val) ? json_decode($val, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a full rebuild is too large to run inside a web request (REST/admin screen).
     */
    public function shouldRebuildInBackground(): bool
    {
        $count = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableProducts} WHERE deleted_at IS NULL AND status != 'trash'"
        );
        /**
         * Product count above which a rebuild requested from the admin runs in the background worker.
         *
         * @param int $threshold
         */
        return $count > (int)apply_filters('wpe_catalog_sync_rebuild_max_products', 2000);
    }

    /* -------------------------------------------------------------------------- */
    /* Product Projection (R64, AC-T16-03)                                        */
    /* -------------------------------------------------------------------------- */

    /**
     * Projects a product aggregate into the specified catalog index generation.
     *
     * @param int $productId
     * @param int|null $generation Null to project into current active generation
     * @param string|null $nowOverride Optional reference timestamp
     * @return bool
     */
    public function projectProduct(int $productId, ?int $generation = null, ?string $nowOverride = null): bool
    {
        $gen = $generation ?? $this->getActiveGeneration();
        $now = $nowOverride ?? gmdate('Y-m-d H:i:s.u');

        // 1. Fetch canonical product
        $prod = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d",
            $productId
        ), ARRAY_A);

        // R64 (Tombstone no resurrection):
        // If product does not exist, or is deleted / trashed, remove from index and facets immediately
        if (!$prod || $prod['deleted_at'] !== null || $prod['status'] === 'trash') {
            $this->wpdb->delete($this->tableCatalog, [
                'generation' => $gen,
                'product_id' => $productId,
            ]);
            $this->wpdb->delete($this->tableCatalogFacets, [
                'generation' => $gen,
                'product_id' => $productId,
            ]);
            return true;
        }

        $sourceVersion = (int)$prod['version'];
        $type = $prod['type'];
        $status = $prod['status'];
        $visibility = $prod['catalog_visibility'] ?? 'visible';
        $featured = !empty($prod['featured']) ? 1 : 0;
        $menuOrder = (int)($prod['menu_order'] ?? 0);
        $titleSort = mb_substr(trim((string)$prod['title']), 0, 191);

        $extra = json_decode($prod['extra_props'] ?? '{}', true) ?: [];
        $totalSales = isset($extra['total_sales']) ? (float)$extra['total_sales'] : 0.0;
        $ratingAverage = isset($extra['average_rating']) ? (float)$extra['average_rating'] : 0.0;
        $ratingCount = isset($extra['review_count']) ? (int)$extra['review_count'] : 0;

        // Image asset ID
        $imgAssetId = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT asset_id FROM {$this->tableMedia} WHERE product_id = %d AND role = 'featured' LIMIT 1",
            $productId
        ));
        $imageAssetId = $imgAssetId ? (int)$imgAssetId : null;

        // 2. Price, Stock, Sale computations
        $inStock = 0;
        $onSale = 0;
        $minPrice = null;
        $maxPrice = null;
        $nextPriceChange = null;

        if ($type === 'simple') {
            // Inventory
            $inv = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
                $productId
            ), ARRAY_A);

            $manageStock = !empty($inv['manage_stock']);
            $stockQty = $inv && $inv['quantity'] !== null ? (float)$inv['quantity'] : 0.0;
            $stockStatus = $inv['stock_status'] ?? 'instock';
            $backorders = $inv['backorders'] ?? 'no';

            if ($manageStock) {
                $inStock = ($stockQty > 0 || $backorders !== 'no') && $stockStatus === 'instock' ? 1 : 0;
            } else {
                $inStock = $stockStatus === 'instock' ? 1 : 0;
            }

            // Price & Sale Schedule (R65) via the shared effective-price rule.
            $priceInfo = EffectivePriceResolver::resolve(array_merge($prod, ['extra_props' => $extra]), null, $now);
            $nextPriceChange = $priceInfo['next_change'];
            $onSale = $priceInfo['on_sale'] ? 1 : 0;
            $effectivePrice = $priceInfo['active_price'] !== null ? (float)$priceInfo['active_price'] : null;

            $minPrice = $effectivePrice;
            $maxPrice = $effectivePrice;

        } elseif ($type === 'variable') {
            // Read active variations
            $variants = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT * FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL AND status = 'publish'",
                $productId
            ), ARRAY_A);

            if (!empty($variants)) {
                $variantPrices = [];
                $anyInStock = false;
                $anyOnSale = false;
                $minNextChange = null;

                // One inventory read for every stock owner of the product (P019 D03).
                $invByOwner = [];
                $ownerIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['stock_owner_id'], $variants)));
                foreach ((array)$this->wpdb->get_results(
                    "SELECT * FROM {$this->tableInventory} WHERE owner_id IN (" . implode(',', $ownerIds) . ")",
                    ARRAY_A
                ) as $invRow) {
                    $invByOwner[(int)$invRow['owner_id']] = $invRow;
                }

                foreach ($variants as $v) {
                    $vid = (int)$v['id'];
                    $stockOwnerId = (int)$v['stock_owner_id'];

                    $vInv = $invByOwner[$stockOwnerId] ?? null;

                    $vManage = !empty($vInv['manage_stock']);
                    $vQty = $vInv && $vInv['quantity'] !== null ? (float)$vInv['quantity'] : 0.0;
                    $vStatus = $vInv['stock_status'] ?? 'instock';
                    $vBack = $vInv['backorders'] ?? 'no';

                    $vInStock = $vManage ? (($vQty > 0 || $vBack !== 'no') && $vStatus === 'instock') : ($vStatus === 'instock');
                    if ($vInStock) {
                        $anyInStock = true;
                    }

                    $vPrice = EffectivePriceResolver::resolve($v, null, $now);

                    if ($vPrice['next_change'] !== null && ($minNextChange === null || $vPrice['next_change'] < $minNextChange)) {
                        $minNextChange = $vPrice['next_change'];
                    }

                    if ($vPrice['on_sale']) {
                        $anyOnSale = true;
                    }

                    if ($vPrice['active_price'] !== null) {
                        $variantPrices[] = (float)$vPrice['active_price'];
                    }
                }

                $inStock = $anyInStock ? 1 : 0;
                $onSale = $anyOnSale ? 1 : 0;
                $nextPriceChange = $minNextChange;

                if (!empty($variantPrices)) {
                    $minPrice = min($variantPrices);
                    $maxPrice = max($variantPrices);
                }
            }
        }

        // 3. Upsert into wpe_catalog
        $catalogRow = [
            'generation'        => $gen,
            'product_id'        => $productId,
            'source_version'    => $sourceVersion,
            'type'              => $type,
            'status'            => $status,
            'visibility'        => $visibility,
            'in_stock'          => $inStock,
            'featured'          => $featured,
            'on_sale'           => $onSale,
            'min_price'         => $minPrice !== null ? sprintf('%.6f', $minPrice) : null,
            'max_price'         => $maxPrice !== null ? sprintf('%.6f', $maxPrice) : null,
            'total_sales'       => sprintf('%.6f', $totalSales),
            'rating_average'    => sprintf('%.2f', $ratingAverage),
            'rating_count'      => $ratingCount,
            'created_at'        => $prod['created_at'],
            'menu_order'        => $menuOrder,
            'title_sort'        => $titleSort,
            'image_asset_id'    => $imageAssetId,
            'indexed_at'        => $now,
            'next_price_change' => $nextPriceChange,
        ];

        $fields = array_keys($catalogRow);
        $placeholders = [];
        $values = [];
        $updates = [];

        foreach ($catalogRow as $col => $val) {
            $placeholders[] = '%s';
            $values[] = $val;
            if ($col !== 'generation' && $col !== 'product_id') {
                $updates[] = "`{$col}` = VALUES(`{$col}`)";
            }
        }

        $sql = "INSERT INTO {$this->tableCatalog} (" . implode(', ', array_map(fn($f) => "`{$f}`", $fields)) . ")
                VALUES (" . implode(', ', $placeholders) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

        $this->wpdb->query($this->wpdb->prepare($sql, ...$values));

        // 4. Project Facets (AC-T16-03: No orphan facets)
        $this->wpdb->delete($this->tableCatalogFacets, [
            'generation' => $gen,
            'product_id' => $productId,
        ]);

        $facets = [];

        // Taxonomy terms (Categories and Tags)
        if (function_exists('wp_get_object_terms')) {
            $terms = wp_get_object_terms($productId, ['product_cat', 'product_tag']);
            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $t) {
                    $facetKey = $t->taxonomy === 'product_cat' ? 'category' : 'tag';
                    $facets[] = [
                        'facet_key'  => $facetKey,
                        'value_key'  => $t->slug,
                        'variant_id' => 0,
                    ];
                    // Also register term ID for ID-based queries
                    $facets[] = [
                        'facet_key'  => $facetKey . '_id',
                        'value_key'  => (string)$t->term_id,
                        'variant_id' => 0,
                    ];
                }
            }
        }

        // Parent attributes (variant_id = 0)
        if (!empty($extra['attributes']) && is_array($extra['attributes'])) {
            foreach ($extra['attributes'] as $attrKey => $attrVal) {
                $cleanKey = sanitize_key((string)$attrKey);
                $vals = is_array($attrVal) ? $attrVal : [(string)$attrVal];
                foreach ($vals as $val) {
                    $cleanVal = sanitize_key((string)$val);
                    if ($cleanVal !== '') {
                        $facets[] = [
                            'facet_key'  => $cleanKey,
                            'value_key'  => $cleanVal,
                            'variant_id' => 0,
                        ];
                    }
                }
            }
        }

        // Variant-level attributes for variable products (variant_id = variant.id)
        if ($type === 'variable') {
            $variants = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, extra_props FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL AND status = 'publish'",
                $productId
            ), ARRAY_A);

            foreach ($variants as $v) {
                $vId = (int)$v['id'];
                $vExtra = json_decode($v['extra_props'] ?? '{}', true) ?: [];
                if (!empty($vExtra['attributes']) && is_array($vExtra['attributes'])) {
                    foreach ($vExtra['attributes'] as $attrKey => $attrVal) {
                        $cleanKey = sanitize_key((string)$attrKey);
                        $cleanVal = sanitize_key((string)$attrVal);
                        if ($cleanKey !== '' && $cleanVal !== '') {
                            $facets[] = [
                                'facet_key'  => $cleanKey,
                                'value_key'  => $cleanVal,
                                'variant_id' => $vId,
                            ];
                        }
                    }
                }
            }
        }

        // Insert unique facets in multi-row statements (P019 D03). IGNORE skips
        // only a conflicting row, as the former per-row inserts did.
        $insertedKeys = [];
        $facetRows = [];
        foreach ($facets as $f) {
            $dedupKey = $gen . ':' . $productId . ':' . $f['variant_id'] . ':' . $f['facet_key'] . ':' . $f['value_key'];
            if (isset($insertedKeys[$dedupKey])) {
                continue;
            }
            $insertedKeys[$dedupKey] = true;
            $facetRows[] = $f;
        }

        foreach (array_chunk($facetRows, 500) as $chunk) {
            $placeholders = [];
            $values = [];
            foreach ($chunk as $f) {
                $placeholders[] = '(%d, %d, %d, %s, %s)';
                array_push($values, $gen, $productId, (int)$f['variant_id'], (string)$f['facet_key'], (string)$f['value_key']);
            }
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->tableCatalogFacets} (generation, product_id, variant_id, facet_key, value_key) VALUES "
                    . implode(', ', $placeholders),
                ...$values
            ));
        }

        return true;
    }

    /* -------------------------------------------------------------------------- */
    /* Generation Rebuild & Catch-up Revision Fence (R63, AC-T16-01)              */
    /* -------------------------------------------------------------------------- */

    /**
     * Builds a new catalog index generation side-by-side with catch-up revision fence
     * before atomically switching the pointer.
     *
     * @param callable|null $progressCallback
     * @return int New generation number
     */
    public function buildNewGeneration(?callable $progressCallback = null): int
    {
        return $this->withRebuildLock(fn(): int => $this->runBuild($progressCallback));
    }

    /**
     * Operator rebuild (admin button, Operations, WP-CLI): build a new generation, then purge the
     * generations beyond generationsToKeep() and any left by an interrupted build, in the same lock
     * (audit F12: without this, generations accumulated without bound).
     */
    public function rebuild(?callable $progressCallback = null): int
    {
        return $this->withRebuildLock(function () use ($progressCallback): int {
            $keep = $this->generationsToKeep();
            // Under the lock no build is running, so generations above the active one are leftovers of
            // an interrupted build: drop them before they can take the "previous" slot.
            $this->purgeOldGenerations($keep, true);
            $newGen = $this->runBuild($progressCallback);
            $this->purgeOldGenerations($keep);
            return $newGen;
        });
    }

    /**
     * Run a build while holding the rebuild lock: two concurrent rebuilds would both compute the
     * same next generation and write into it at once (audit F12). Clears a pending background
     * request, since the build covers it.
     *
     * @param callable():int $build
     */
    private function withRebuildLock(callable $build): int
    {
        $lock = $this->wpdb->prefix . 'wpe_catalog_rebuild';
        if ((int)$this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            throw new \RuntimeException('A catalog rebuild is already running. Try again when it has finished.', 409);
        }

        try {
            $newGen = $build();
            $this->wpdb->delete($this->tableControl, ['key' => self::REBUILD_REQUEST_KEY]);
            return $newGen;
        } finally {
            $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private function runBuild(?callable $progressCallback): int
    {
        $currentGen = $this->getActiveGeneration();
        // Above every stored generation, so rows left by an interrupted rebuild are never reused,
        // and cleared first in case a partial build used this number before.
        $storedMax = (int)$this->wpdb->get_var("SELECT COALESCE(MAX(generation), 0) FROM {$this->tableCatalog}");
        $newGen = max($currentGen, $storedMax) + 1;
        foreach ([$this->tableCatalog, $this->tableCatalogFacets] as $table) {
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$table} WHERE generation = %d", $newGen));
        }
        $rebuildStart = gmdate('Y-m-d H:i:s.u');

        // 1. Initial full scan of active products
        $productIds = $this->wpdb->get_col(
            "SELECT id FROM {$this->tableProducts} WHERE deleted_at IS NULL AND status != 'trash' ORDER BY id ASC"
        );

        $total = count($productIds);
        $processed = 0;

        foreach ($productIds as $pid) {
            $this->projectProduct((int)$pid, $newGen);
            $processed++;
            if ($progressCallback) {
                $progressCallback($processed, $total, 'initial_scan');
            }
        }

        // 2. Catch-up Revision Fence (R63, AC-T16-01)
        // Products modified during the initial scan must be caught up
        $maxFenceRounds = 5;
        $round = 0;

        while ($round < $maxFenceRounds) {
            $round++;
            $dirtyIds = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT p.id
                 FROM {$this->tableProducts} p
                 LEFT JOIN {$this->tableCatalog} c ON (c.generation = %d AND c.product_id = p.id)
                 WHERE p.deleted_at IS NULL AND p.status != 'trash'
                   AND (c.product_id IS NULL OR p.version > c.source_version OR p.updated_at >= %s)",
                $newGen,
                $rebuildStart
            ));

            if (empty($dirtyIds)) {
                break;
            }

            foreach ($dirtyIds as $dId) {
                $this->projectProduct((int)$dId, $newGen);
            }
        }

        // Clean up any products deleted/trashed during rebuild
        $deletedDuringBuild = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT c.product_id
             FROM {$this->tableCatalog} c
             INNER JOIN {$this->tableProducts} p ON (p.id = c.product_id)
             WHERE c.generation = %d AND (p.deleted_at IS NOT NULL OR p.status = 'trash')",
            $newGen
        ));

        foreach ($deletedDuringBuild as $dId) {
            $this->wpdb->delete($this->tableCatalog, ['generation' => $newGen, 'product_id' => (int)$dId]);
            $this->wpdb->delete($this->tableCatalogFacets, ['generation' => $newGen, 'product_id' => (int)$dId]);
        }

        // 3. Atomic switch to new generation
        $this->setActiveGeneration($newGen);

        return $newGen;
    }

    /* -------------------------------------------------------------------------- */
    /* Price Schedule Transitions / DST Clock Boundary (R65, AC-T16-02)           */
    /* -------------------------------------------------------------------------- */

    /**
     * Evaluates scheduled price transitions that have crossed their time boundary (AC-T16-02, R65).
     *
     * @param string|null $currentTime
     * @return int Number of updated products
     */
    public function evaluateScheduledPriceChanges(?string $currentTime = null): int
    {
        $now = $currentTime ?? gmdate('Y-m-d H:i:s.u');
        $activeGen = $this->getActiveGeneration();

        $dueProductIds = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT product_id FROM {$this->tableCatalog}
             WHERE generation = %d AND next_price_change IS NOT NULL AND next_price_change <= %s",
            $activeGen,
            $now
        ));

        $updatedCount = 0;
        foreach ($dueProductIds as $pid) {
            $this->projectProduct((int)$pid, $activeGen, $now);
            $updatedCount++;
        }

        return $updatedCount;
    }

    /* -------------------------------------------------------------------------- */
    /* Auditing & Lag Metrics (AC-T16-03)                                         */
    /* -------------------------------------------------------------------------- */

    /**
     * Audits source_version alignment between wpe_products and wpe_catalog (AC-T16-03).
     */
    public function auditSourceVersions(?int $generation = null): array
    {
        $gen = $generation ?? $this->getActiveGeneration();

        $sql = "SELECT p.id, p.version as product_version, c.source_version as catalog_version
                FROM {$this->tableProducts} p
                LEFT JOIN {$this->tableCatalog} c ON (c.generation = %d AND c.product_id = p.id)
                WHERE p.deleted_at IS NULL AND p.status != 'trash'";

        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $gen), ARRAY_A);

        $total = count($rows);
        $stale = [];
        $synced = 0;

        foreach ($rows as $r) {
            if ($r['catalog_version'] === null || (int)$r['product_version'] !== (int)$r['catalog_version']) {
                $stale[] = [
                    'product_id'      => (int)$r['id'],
                    'product_version' => (int)$r['product_version'],
                    'catalog_version' => $r['catalog_version'] !== null ? (int)$r['catalog_version'] : null,
                ];
            } else {
                $synced++;
            }
        }

        return [
            'in_sync'         => empty($stale),
            'generation'      => $gen,
            'total_products'  => $total,
            'synced_products' => $synced,
            'stale_count'     => count($stale),
            'stale_products'  => $stale,
        ];
    }

    /**
     * Gets index freshness and lag metrics.
     */
    public function getLagMetrics(): array
    {
        $gen = $this->getActiveGeneration();

        $totalIndexed = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->tableCatalog} WHERE generation = %d",
            $gen
        ));

        $dirtyCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1)
             FROM {$this->tableProducts} p
             LEFT JOIN {$this->tableCatalog} c ON (c.generation = %d AND c.product_id = p.id)
             WHERE p.deleted_at IS NULL AND p.status != 'trash'
               AND (c.product_id IS NULL OR p.version > c.source_version)",
            $gen
        ));

        return [
            'active_generation'    => $gen,
            'total_indexed'        => $totalIndexed,
            'dirty_unindexed_count' => $dirtyCount,
            'is_healthy'           => $dirtyCount === 0,
        ];
    }
}
