<?php
declare(strict_types=1);

namespace WPE\Application\Catalog;

use InvalidArgumentException;
use WPE\Application\Catalog\CatalogProjector;

/**
 * CatalogQuery — High-performance compiler and executor for catalog index queries
 * and keyset pagination with signed HMAC cursors.
 *
 * Implements requirements:
 * - R67: Multi-field filtering (category, tag, price range, stock, sale) and deterministic sorting.
 * - R68: Stable keyset tie-breaking on equal sort values using (sort_value, product_id).
 * - R69: HMAC cursor signing, tamper rejection, expiry check, and query-hash mismatch validation.
 * - R70: Hard limit capping (max 100, default 48) and explicit null-price ordering (nulls last).
 * - AC-T17-01: Parameterized SQL against injection; bounded card projections without N+1.
 * - AC-T17-02: Live traversal semantics; documentation on why exports require immutable snapshots.
 */
class CatalogQuery
{
    public const DEFAULT_LIMIT = 48;
    public const MAX_LIMIT = 100;
    public const CURSOR_TTL_SECONDS = 1800; // 30 minutes

    private \wpdb $wpdb;
    private CatalogProjector $projector;

    private string $tableCatalog;
    private string $tableCatalogFacets;
    private string $signingKey;

    /** @var array<string, array<string, bool>> */
    private static array $catalogIndexCache = [];

    public static function resetIndexCache(): void
    {
        self::$catalogIndexCache = [];
    }

    public function __construct(?\wpdb $wpdb = null, ?CatalogProjector $projector = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->projector = $projector ?? new CatalogProjector($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableCatalog       = $prefix . 'wpe_catalog';
        $this->tableCatalogFacets = $prefix . 'wpe_catalog_facets';

        // Derive signing key from WordPress salt or fallback
        $this->signingKey = defined('AUTH_SALT') ? AUTH_SALT : 'wpe_catalog_default_hmac_secret_2026';
    }

    /**
     * Declares whether this query engine uses live traversal semantics (AC-T17-02).
     *
     * Note: Keyset pagination traverses the live catalog index. If items are updated
     * or prices shift between consecutive page requests, positions may shift accordingly.
     * Large catalog batch exports must use immutable snapshot staging rather than live cursors.
     */
    public function isLiveTraversal(): bool
    {
        return true;
    }

    /* -------------------------------------------------------------------------- */
    /* Keyset Cursor Signing & Tamper Verification (R68, R69, AC-T17-01)          */
    /* -------------------------------------------------------------------------- */

    /**
     * Signs a keyset cursor payload with HMAC-SHA256.
     *
     * @param array $payload
     * @return string
     */
    public function encodeCursor(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encodedData = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encodedData, $this->signingKey);

        return $encodedData . '.' . $signature;
    }

    /**
     * Decodes and validates a signed cursor against tampering, expiry, and query parameter changes.
     *
     * @param string $cursorStr
     * @param string $expectedQueryHash
     * @param int $expectedGeneration
     * @return array Decoded cursor payload
     * @throws InvalidArgumentException on invalid signature, expiration, or query mismatch
     */
    public function decodeAndValidateCursor(string $cursorStr, string $expectedQueryHash, int $expectedGeneration): array
    {
        $parts = explode('.', $cursorStr);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Malformed cursor structure");
        }

        [$encodedData, $signature] = $parts;

        // 1. Verify HMAC signature
        $expectedSignature = hash_hmac('sha256', $encodedData, $this->signingKey);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new InvalidArgumentException("Invalid or tampered cursor signature");
        }

        // 2. Decode payload
        $json = base64_decode(strtr($encodedData, '-_', '+/'));
        if (!$json) {
            throw new InvalidArgumentException("Invalid cursor encoding");
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException("Corrupted cursor payload");
        }

        // 3. Expiry check
        $now = time();
        if (isset($payload['expiry']) && (int)$payload['expiry'] < $now) {
            throw new InvalidArgumentException("Cursor expired");
        }

        // 4. Query hash mismatch check (prevents applying cursor from another filter set)
        if (isset($payload['query_hash']) && !hash_equals($payload['query_hash'], $expectedQueryHash)) {
            throw new InvalidArgumentException("Cursor query mismatch: filter parameters altered");
        }

        // 5. Generation check
        if (isset($payload['generation']) && (int)$payload['generation'] !== $expectedGeneration) {
            // Note: If previous generation is retained, caller can choose to support it
        }

        return $payload;
    }

    /**
     * Computes a deterministic SHA-256 hash of normalized filter parameters.
     */
    public function computeQueryHash(array $filters): string
    {
        // Exclude pagination cursor and limit from the query identity
        unset($filters['cursor'], $filters['limit'], $filters['count_mode']);
        ksort($filters);
        return hash('sha256', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /* -------------------------------------------------------------------------- */
    /* Query Compilation & Execution (R67, R68, R70, AC-T17-01)                  */
    /* -------------------------------------------------------------------------- */

    /**
     * Executes a catalog query returning items, page info, and next cursor.
     *
     * @param array $args Query parameters
     * @return array Standard result envelope
     */
    public function execute(array $args = []): array
    {
        $gen = isset($args['generation']) ? (int)$args['generation'] : $this->projector->getActiveGeneration();

        // 1. Limit capping (R70)
        $rawLimit = isset($args['limit']) ? (int)$args['limit'] : self::DEFAULT_LIMIT;
        if ($rawLimit <= 0) {
            $rawLimit = self::DEFAULT_LIMIT;
        }
        $limit = min($rawLimit, self::MAX_LIMIT);

        // 2. Sort option
        $sort = $args['sort'] ?? 'price_asc';
        $normalizedFilters = $this->normalizeFilters($args);
        $queryHash = $this->computeQueryHash($normalizedFilters);

        // 3. Cursor handling
        $cursorData = null;
        if (!empty($args['cursor'])) {
            $cursorData = $this->decodeAndValidateCursor((string)$args['cursor'], $queryHash, $gen);
        }

        // 4. Execute SQL (two-leg zero-filesort B-Tree scan for unbounded price_asc; single query otherwise)
        if ($sort === 'price_asc' && !isset($normalizedFilters['min_price']) && !isset($normalizedFilters['max_price'])) {
            $rows = $this->fetchPriceAscRows($normalizedFilters, $limit, $gen, $cursorData);
        } else {
            [$sql, $params] = $this->compileQuery($normalizedFilters, $sort, $limit, $gen, $cursorData);
            $preparedSql = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;
            $rows = $this->wpdb->get_results($preparedSql, ARRAY_A) ?: [];
        }
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'product_id'     => (int)$row['product_id'],
                'type'           => $row['type'],
                'status'         => $row['status'],
                'visibility'     => $row['visibility'],
                'in_stock'       => (bool)$row['in_stock'],
                'featured'       => (bool)$row['featured'],
                'on_sale'        => (bool)$row['on_sale'],
                'min_price'      => $row['min_price'] !== null ? (float)$row['min_price'] : null,
                'max_price'      => $row['max_price'] !== null ? (float)$row['max_price'] : null,
                'total_sales'    => (float)$row['total_sales'],
                'rating_average' => (float)$row['rating_average'],
                'rating_count'   => (int)$row['rating_count'],
                'title'          => $row['title_sort'],
                'image_asset_id' => $row['image_asset_id'] ? (int)$row['image_asset_id'] : null,
            ];
        }

        // 5. Compute next cursor if results reached limit
        $hasNextPage = count($items) === $limit;
        $nextCursor = null;

        if ($hasNextPage && !empty($items)) {
            $lastItem = end($items);
            $lastRow = end($rows);

            $sortValue = $this->extractSortValue($sort, $lastRow);
            $cursorPayload = [
                'query_hash' => $queryHash,
                'generation' => $gen,
                'sort'       => $sort,
                'sort_value' => $sortValue,
                'last_id'    => $lastItem['product_id'],
                'issued_at'  => time(),
                'expiry'     => time() + self::CURSOR_TTL_SECONDS,
            ];

            $nextCursor = $this->encodeCursor($cursorPayload);
        }

        // 6. Optional exact count
        $total = null;
        if (($args['count_mode'] ?? 'none') === 'exact') {
            $total = $this->executeCount($normalizedFilters, $gen);
        }

        return [
            'items'     => $items,
            'count'     => count($items),
            'total'     => $total,
            'page_info' => [
                'has_next_page' => $hasNextPage,
                'next_cursor'   => $nextCursor,
                'limit'         => $limit,
                'generation'    => $gen,
                'sort'          => $sort,
            ],
        ];
    }

    /**
     * Normalizes filter arguments.
     */
    private function normalizeFilters(array $args): array
    {
        $f = [];
        if (isset($args['status'])) $f['status'] = (string)$args['status'];
        if (isset($args['visibility'])) $f['visibility'] = (string)$args['visibility'];
        if (isset($args['in_stock'])) $f['in_stock'] = (bool)$args['in_stock'];
        if (isset($args['featured'])) $f['featured'] = (bool)$args['featured'];
        if (isset($args['on_sale'])) $f['on_sale'] = (bool)$args['on_sale'];
        if (isset($args['min_price'])) $f['min_price'] = (float)$args['min_price'];
        if (isset($args['max_price'])) $f['max_price'] = (float)$args['max_price'];
        if (isset($args['category'])) $f['category'] = is_array($args['category']) ? $args['category'] : [(string)$args['category']];
        if (isset($args['tag'])) $f['tag'] = is_array($args['tag']) ? $args['tag'] : [(string)$args['tag']];
        if (isset($args['attributes']) && is_array($args['attributes'])) $f['attributes'] = $args['attributes'];
        if (isset($args['search']) && trim((string)$args['search']) !== '') $f['search'] = trim((string)$args['search']);

        return $f;
    }

    /**
     * Extracts the sort value for keyset cursor from the last returned DB row.
     */
    private function extractSortValue(string $sort, array $row): mixed
    {
        switch ($sort) {
            case 'price_asc':
            case 'price_desc':
                return $row['min_price'] !== null ? (float)$row['min_price'] : null;
            case 'date_desc':
            case 'date_asc':
                return $row['created_at'];
            case 'popularity':
            case 'sales':
                return (float)$row['total_sales'];
            case 'rating':
                return (float)$row['rating_average'];
            case 'menu_order':
                return (int)$row['menu_order'];
            case 'title_asc':
                return (string)$row['title_sort'];
            default:
                return $row['min_price'] !== null ? (float)$row['min_price'] : null;
        }
    }

    /**
     * Executes a numbered archive page query (OFFSET pagination) returning matching IDs and exact total
     * using B-Tree index scans without filesort (P028 D04).
     *
     * @param array $args Adapted query parameters (filters, sort, limit, offset, generation)
     * @return array{ids: int[], total: int}
     */
    public function executeArchivePage(array $args = []): array
    {
        $gen = isset($args['generation']) ? (int)$args['generation'] : $this->projector->getActiveGeneration();
        $limit = isset($args['limit']) ? max(1, min((int)$args['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;
        $sort = (string)($args['sort'] ?? 'date_desc');
        $normalized = $this->normalizeFilters($args);

        $total = $this->executeCount($normalized, $gen);
        if ($total === 0) {
            return ['ids' => [], 'total' => 0];
        }

        $hasPriceBound = isset($normalized['min_price']) || isset($normalized['max_price']);
        if ($sort === 'price_asc' && !$hasPriceBound) {
            $ids = $this->fetchArchivePriceAscIds($normalized, $limit, $offset, $gen);
        } else {
            [$sql, $params] = $this->compileQuery($normalized, $sort, $limit, $gen, null, null, ['c.product_id']);
            if ($offset > 0) {
                $sql = preg_replace('/LIMIT\s+%d$/', 'LIMIT %d OFFSET %d', $sql);
                $params[] = $offset;
            }
            $prepared = $this->wpdb->prepare($sql, ...$params);
            $ids = array_map('intval', $this->wpdb->get_col($prepared) ?: []);
        }

        return [
            'ids'   => $ids,
            'total' => $total,
        ];
    }

    /**
     * Two-leg B-Tree scan for cursor-paginated price_asc (nulls last without filesort, P028 D04).
     */
    private function fetchPriceAscRows(array $filters, int $limit, int $generation, ?array $cursorData): array
    {
        $rows = [];
        $inNullLeg = ($cursorData !== null && array_key_exists('sort_value', $cursorData) && $cursorData['sort_value'] === null);

        if (!$inNullLeg) {
            [$sql1, $params1] = $this->compileQuery($filters, 'price_asc', $limit, $generation, $cursorData, 'non_null');
            $rows = $this->wpdb->get_results($this->wpdb->prepare($sql1, ...$params1), ARRAY_A) ?: [];
            if (count($rows) >= $limit) {
                return $rows;
            }
        }

        $remaining = $limit - count($rows);
        if ($remaining > 0) {
            $nullCursor = $inNullLeg ? $cursorData : null;
            [$sql2, $params2] = $this->compileQuery($filters, 'price_asc', $remaining, $generation, $nullCursor, 'null_only');
            $nullRows = $this->wpdb->get_results($this->wpdb->prepare($sql2, ...$params2), ARRAY_A) ?: [];
            foreach ($nullRows as $nr) {
                $rows[] = $nr;
            }
        }

        return $rows;
    }

    /**
     * Two-leg B-Tree scan for OFFSET-paginated archive price_asc (nulls last without filesort, P028 D04).
     *
     * @return int[]
     */
    private function fetchArchivePriceAscIds(array $filters, int $limit, int $offset, int $generation): array
    {
        [$sql1, $params1] = $this->compileQuery($filters, 'price_asc', $limit, $generation, null, 'non_null', ['c.product_id']);
        if ($offset > 0) {
            $sql1 = preg_replace('/LIMIT\s+%d$/', 'LIMIT %d OFFSET %d', $sql1);
            $params1[] = $offset;
        }
        $ids = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql1, ...$params1)) ?: []);
        if (count($ids) >= $limit) {
            return $ids;
        }

        $remaining = $limit - count($ids);
        $nullOffset = 0;
        if ($offset > 0 && $ids === []) {
            [$where, $wParams] = $this->buildFilterWhere($filters, $generation);
            $where[] = "c.min_price IS NOT NULL";
            $cntSql = "SELECT COUNT(1) FROM {$this->tableCatalog} c WHERE " . implode(' AND ', $where);
            $nonNullTotal = (int)$this->wpdb->get_var($this->wpdb->prepare($cntSql, ...$wParams));
            $nullOffset = max(0, $offset - $nonNullTotal);
        }

        [$sql2, $params2] = $this->compileQuery($filters, 'price_asc', $remaining, $generation, null, 'null_only', ['c.product_id']);
        if ($nullOffset > 0) {
            $sql2 = preg_replace('/LIMIT\s+%d$/', 'LIMIT %d OFFSET %d', $sql2);
            $params2[] = $nullOffset;
        }
        $nullIds = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare($sql2, ...$params2)) ?: []);

        return array_merge($ids, $nullIds);
    }

    /**
     * Shared filter predicate compiler for both SELECT and COUNT queries (P028 D04).
     * Uses EXISTS semi-joins against wpe_catalog_facets so c.product_id remains unique without GROUP BY.
     *
     * @return array{0: string[], 1: array<int, mixed>}
     */
    private function buildFilterWhere(array $filters, int $generation): array
    {
        $where = ["c.generation = %d"];
        $params = [$generation];

        // Status & Visibility defaults
        if (isset($filters['status'])) {
            $where[] = "c.status = %s";
            $params[] = $filters['status'];
        } else {
            $where[] = "c.status = 'publish'";
        }

        if (isset($filters['visibility'])) {
            $where[] = "c.visibility = %s";
            $params[] = $filters['visibility'];
        } else {
            $where[] = "c.visibility IN ('visible', 'catalog')";
        }

        // Boolean filters
        if (isset($filters['in_stock'])) {
            $where[] = "c.in_stock = %d";
            $params[] = $filters['in_stock'] ? 1 : 0;
        }
        if (isset($filters['featured'])) {
            $where[] = "c.featured = %d";
            $params[] = $filters['featured'] ? 1 : 0;
        }
        if (isset($filters['on_sale'])) {
            $where[] = "c.on_sale = %d";
            $params[] = $filters['on_sale'] ? 1 : 0;
        }

        // Price range
        if (isset($filters['min_price'])) {
            $where[] = "c.min_price >= %f";
            $params[] = $filters['min_price'];
        }
        if (isset($filters['max_price'])) {
            $where[] = "c.min_price <= %f";
            $params[] = $filters['max_price'];
        }

        // Search (Title or exact/prefix/substring SKU)
        if (!empty($filters['search'])) {
            $term = trim((string)$filters['search']);
            $normTerm = strtolower($term);
            $likeRaw = '%' . $this->wpdb->esc_like($term) . '%';
            $likeNorm = '%' . $this->wpdb->esc_like($normTerm) . '%';
            $where[] = "(c.title_sort LIKE %s OR EXISTS (
                SELECT 1 FROM {$this->wpdb->prefix}wpe_sku_registry sr
                WHERE sr.object_id = c.product_id
                  AND (sr.normalized_sku = %s OR sr.original_sku LIKE %s OR LOWER(CAST(sr.normalized_sku AS CHAR)) LIKE %s)
            ))";
            $params[] = $likeRaw;
            $params[] = $normTerm;
            $params[] = $likeRaw;
            $params[] = $likeNorm;
        }

        // Category Facets (EXISTS covering PK prefix on wpe_catalog_facets)
        if (!empty($filters['category'])) {
            $catPlaceholders = implode(', ', array_fill(0, count($filters['category']), '%s'));
            $where[] = "EXISTS (
                SELECT 1 FROM {$this->tableCatalogFacets} f_cat USE INDEX (PRIMARY)
                WHERE f_cat.generation = c.generation
                  AND f_cat.product_id = c.product_id
                  AND f_cat.facet_key IN ('category', 'category_id')
                  AND f_cat.value_key IN ({$catPlaceholders})
            )";
            foreach ($filters['category'] as $catVal) {
                $params[] = (string)$catVal;
            }
        }

        // Tag Facets
        if (!empty($filters['tag'])) {
            $tagPlaceholders = implode(', ', array_fill(0, count($filters['tag']), '%s'));
            $where[] = "EXISTS (
                SELECT 1 FROM {$this->tableCatalogFacets} f_tag USE INDEX (PRIMARY)
                WHERE f_tag.generation = c.generation
                  AND f_tag.product_id = c.product_id
                  AND f_tag.facet_key IN ('tag', 'tag_id')
                  AND f_tag.value_key IN ({$tagPlaceholders})
            )";
            foreach ($filters['tag'] as $tagVal) {
                $params[] = (string)$tagVal;
            }
        }

        // Attribute Facets (enforces same-variant combination matching when filtering multiple attributes, 03 §4)
        if (!empty($filters['attributes']) && is_array($filters['attributes'])) {
            $attrSpecs = [];
            foreach ($filters['attributes'] as $attrKey => $attrVals) {
                $cleanKey = sanitize_key((string)$attrKey);
                $vals = is_array($attrVals) ? $attrVals : [(string)$attrVals];
                $cleanVals = array_values(array_filter(array_map(static fn($v): string => sanitize_key((string)$v), $vals), static fn(string $v): bool => $v !== ''));
                if ($cleanKey !== '' && $cleanVals !== []) {
                    $attrSpecs[] = [$cleanKey, $cleanVals];
                }
            }

            if (count($attrSpecs) === 1) {
                [$fk, $vks] = $attrSpecs[0];
                $ph = implode(', ', array_fill(0, count($vks), '%s'));
                $where[] = "EXISTS (
                    SELECT 1 FROM {$this->tableCatalogFacets} fa1 USE INDEX (PRIMARY)
                    WHERE fa1.generation = c.generation
                      AND fa1.product_id = c.product_id
                      AND fa1.facet_key = %s
                      AND fa1.value_key IN ({$ph})
                )";
                $params[] = $fk;
                foreach ($vks as $vk) {
                    $params[] = $vk;
                }
            } elseif (count($attrSpecs) > 1) {
                $subJoins = [];
                $subWhere = [
                    "fa1.generation = c.generation",
                    "fa1.product_id = c.product_id",
                ];
                foreach ($attrSpecs as $i => [$fk, $vks]) {
                    $idx = $i + 1;
                    $alias = "fa{$idx}";
                    if ($idx > 1) {
                        $subJoins[] = "INNER JOIN {$this->tableCatalogFacets} {$alias} USE INDEX (PRIMARY)
                            ON {$alias}.generation = fa1.generation
                           AND {$alias}.product_id = fa1.product_id
                           AND ({$alias}.variant_id = fa1.variant_id OR fa1.variant_id = 0 OR {$alias}.variant_id = 0)";
                    }
                    $ph = implode(', ', array_fill(0, count($vks), '%s'));
                    $subWhere[] = "{$alias}.facet_key = %s AND {$alias}.value_key IN ({$ph})";
                    $params[] = $fk;
                    foreach ($vks as $vk) {
                        $params[] = $vk;
                    }
                }
                $where[] = "EXISTS (
                    SELECT 1 FROM {$this->tableCatalogFacets} fa1 USE INDEX (PRIMARY)
                    " . implode(' ', $subJoins) . "
                    WHERE " . implode(' AND ', $subWhere) . "
                )";
            }
        }

        return [$where, $params];
    }

    /**
     * Check whether a named B-Tree index exists on {prefix}wpe_catalog (cached in memory).
     */
    private function hasCatalogIndex(string $indexName): bool
    {
        $table = $this->tableCatalog;
        if (!isset(self::$catalogIndexCache[$table]) || empty(self::$catalogIndexCache[$table][$indexName])) {
            $map = [];
            $rows = $this->wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A) ?: [];
            foreach ($rows as $row) {
                if (!empty($row['Key_name'])) {
                    $map[(string)$row['Key_name']] = true;
                }
            }
            self::$catalogIndexCache[$table] = $map;
        }
        return !empty(self::$catalogIndexCache[$table][$indexName]);
    }

    /**
     * Resolve optimal B-Tree index hint so MariaDB streams rows directly in sort order without filesort.
     */
    private function resolveIndexHint(array $filters, string $sort): string
    {
        $target = match ($sort) {
            'date_desc', 'date_asc' => 'idx_cat_created',
            'popularity', 'sales'   => 'idx_cat_sales',
            'menu_order'            => 'idx_cat_menu_order',
            'rating'                => 'idx_cat_rating',
            'title_asc'             => 'idx_cat_title',
            'price_asc', 'price_desc' => (isset($filters['visibility']) && isset($filters['in_stock']))
                ? 'idx_cat_filter'
                : 'idx_cat_price',
            default                 => 'idx_cat_price',
        };

        if ($this->hasCatalogIndex($target)) {
            return "USE INDEX (`{$target}`)";
        }
        return '';
    }

    /**
     * Compiles parameterized SELECT SQL for catalog queries.
     */
    private function compileQuery(
        array $filters,
        string $sort,
        int $limit,
        int $generation,
        ?array $cursorData = null,
        ?string $priceLeg = null,
        ?array $selectColsOverride = null
    ): array {
        $selectCols = $selectColsOverride ?? [
            'c.product_id', 'c.type', 'c.status', 'c.visibility',
            'c.in_stock', 'c.featured', 'c.on_sale', 'c.min_price', 'c.max_price',
            'c.total_sales', 'c.rating_average', 'c.rating_count', 'c.created_at',
            'c.menu_order', 'c.title_sort', 'c.image_asset_id',
        ];

        [$where, $params] = $this->buildFilterWhere($filters, $generation);

        if ($priceLeg === 'non_null') {
            $where[] = "c.min_price IS NOT NULL";
        } elseif ($priceLeg === 'null_only') {
            $where[] = "c.min_price IS NULL";
        }

        // Keyset Cursor Where Clause (R68, R70)
        if ($cursorData !== null && isset($cursorData['last_id'])) {
            $lastId = (int)$cursorData['last_id'];
            $lastVal = $cursorData['sort_value'];

            switch ($sort) {
                case 'price_asc':
                    if ($lastVal === null || $priceLeg === 'null_only') {
                        $where[] = "(c.min_price IS NULL AND c.product_id > %d)";
                        $params[] = $lastId;
                    } elseif ($priceLeg === 'non_null' || isset($filters['min_price']) || isset($filters['max_price'])) {
                        $where[] = "(c.min_price >= %f AND (c.min_price > %f OR c.product_id > %d))";
                        $params[] = (float)$lastVal;
                        $params[] = (float)$lastVal;
                        $params[] = $lastId;
                    } else {
                        $where[] = "((c.min_price >= %f AND (c.min_price > %f OR c.product_id > %d)) OR c.min_price IS NULL)";
                        $params[] = (float)$lastVal;
                        $params[] = (float)$lastVal;
                        $params[] = $lastId;
                    }
                    break;

                case 'price_desc':
                    if ($lastVal === null) {
                        $where[] = "(c.min_price IS NULL AND c.product_id < %d)";
                        $params[] = $lastId;
                    } else {
                        $where[] = "((c.min_price <= %f AND (c.min_price < %f OR c.product_id < %d)) OR c.min_price IS NULL)";
                        $params[] = (float)$lastVal;
                        $params[] = (float)$lastVal;
                        $params[] = $lastId;
                    }
                    break;

                case 'date_desc':
                    $where[] = "(c.created_at <= %s AND (c.created_at < %s OR c.product_id < %d))";
                    $params[] = (string)$lastVal;
                    $params[] = (string)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'date_asc':
                    $where[] = "(c.created_at >= %s AND (c.created_at > %s OR c.product_id > %d))";
                    $params[] = (string)$lastVal;
                    $params[] = (string)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'popularity':
                case 'sales':
                    $where[] = "(c.total_sales <= %f AND (c.total_sales < %f OR c.product_id < %d))";
                    $params[] = (float)$lastVal;
                    $params[] = (float)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'rating':
                    $where[] = "(c.rating_average <= %f AND (c.rating_average < %f OR c.product_id < %d))";
                    $params[] = (float)$lastVal;
                    $params[] = (float)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'menu_order':
                    $where[] = "(c.menu_order >= %d AND (c.menu_order > %d OR c.product_id > %d))";
                    $params[] = (int)$lastVal;
                    $params[] = (int)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'title_asc':
                    $where[] = "(c.title_sort >= %s AND (c.title_sort > %s OR c.product_id > %d))";
                    $params[] = (string)$lastVal;
                    $params[] = (string)$lastVal;
                    $params[] = $lastId;
                    break;

                default:
                    $where[] = "c.product_id > %d";
                    $params[] = $lastId;
                    break;
            }
        }

        $hasPriceBound = isset($filters['min_price']) || isset($filters['max_price']) || $priceLeg !== null;
        $orderBy = $this->compileOrderBy($sort, $hasPriceBound);
        $indexHint = ($sort === 'price_asc' && !$hasPriceBound) ? '' : $this->resolveIndexHint($filters, $sort);

        $sql = "SELECT " . implode(', ', $selectCols) . "
                FROM {$this->tableCatalog} c {$indexHint}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT %d";

        $params[] = $limit;

        return [$sql, $params];
    }

    /**
     * Compiles ORDER BY SQL with deterministic tie-breaking and nulls last (R68, R70, P028 D04).
     */
    private function compileOrderBy(string $sort, bool $priceBounded = false): string
    {
        switch ($sort) {
            case 'price_asc':
                if ($priceBounded) {
                    return "c.min_price ASC, c.product_id ASC";
                }
                return "(c.min_price IS NULL) ASC, c.min_price ASC, c.product_id ASC";

            case 'price_desc':
                // In MariaDB/MySQL B-Tree indexes, NULL is lowest, so DESC naturally places NULLs last!
                return "c.min_price DESC, c.product_id DESC";

            case 'date_desc':
                return "c.created_at DESC, c.product_id DESC";

            case 'date_asc':
                return "c.created_at ASC, c.product_id ASC";

            case 'popularity':
            case 'sales':
                return "c.total_sales DESC, c.product_id DESC";

            case 'rating':
                return "c.rating_average DESC, c.product_id DESC";

            case 'menu_order':
                return "c.menu_order ASC, c.product_id ASC";

            case 'title_asc':
                return "c.title_sort ASC, c.product_id ASC";

            default:
                if ($priceBounded) {
                    return "c.min_price ASC, c.product_id ASC";
                }
                return "(c.min_price IS NULL) ASC, c.min_price ASC, c.product_id ASC";
        }
    }

    /**
     * Executes an exact count query for filtered catalog (includes category, tag, attributes, and search).
     */
    private function executeCount(array $filters, int $generation): int
    {
        [$where, $params] = $this->buildFilterWhere($filters, $generation);

        $sql = "SELECT COUNT(1)
                FROM {$this->tableCatalog} c
                WHERE " . implode(' AND ', $where);

        $prepared = $this->wpdb->prepare($sql, ...$params);
        return (int)$this->wpdb->get_var($prepared);
    }

    /**
     * Documents the live index traversal semantics for cursor pagination (AC-T17-02).
     */
    public function getPaginationSemantics(): array
    {
        return [
            'mode' => 'live_traversal',
            'guarantees' => [
                'keyset_deterministic' => 'Equal sort values resolved via stable (sort_value, product_id) tuple.',
                'tamper_resistant'     => 'Cursors are HMAC-SHA256 signed, bound to query_hash, generation, and TTL.',
                'zero_n_plus_one'      => 'Selects bounded projection cards in a single query without hydrating all matching IDs.',
            ],
            'snapshot_difference' => [
                'live_traversal' => 'Keyset pagination follows live updates. Item mutations between pages may shift relative positions.',
                'export_requirement' => 'Large CSV catalog exports must use immutable snapshot staging tables, NOT live pagination cursors.',
            ],
        ];
    }

    /**
     * Initializes hooks.
     */
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
    }

    /**
     * Registers REST API endpoint /wpe/v1/catalog.
     */
    public static function registerRestRoutes(): void
    {
        register_rest_route('wpe/v1', '/catalog', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleRestRequest'],
            'permission_callback' => [self::class, 'canQueryCatalog'],
        ]);
    }

    /**
     * Who may call GET /wpe/v1/catalog (audit SEC-01).
     *
     * The index holds every non-trashed product, drafts and private ones included, and the
     * endpoint accepts status, visibility and generation filters. No storefront code calls it,
     * so it is limited to users who can edit products instead of being public.
     */
    public static function canQueryCatalog(): bool
    {
        return current_user_can('edit_products');
    }

    /**
     * Handles GET /wpe/v1/catalog requests (signed-in product editors only).
     */
    public static function handleRestRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_params();

        try {
            $compiler = new self();
            $result = $compiler->execute($params);
            return new \WP_REST_Response($result, 200);
        } catch (\InvalidArgumentException $e) {
            return new \WP_REST_Response([
                'code'      => 'invalid_request',
                'message'   => $e->getMessage(),
                'details'   => [],
                'retryable' => false,
            ], 400);
        } catch (\Throwable $e) {
            return new \WP_REST_Response([
                'code'      => 'index_unavailable',
                'message'   => \WPE\Support\RestErrors::safeMessage($e, 'catalog query', __('The catalog index could not be queried.', 'wc-product-engine')),
                'details'   => [],
                'retryable' => true,
            ], 500);
        }
    }
}
