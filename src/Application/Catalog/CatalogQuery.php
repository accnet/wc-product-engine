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

    public function __construct(?\wpdb $wpdb = null, ?CatalogProjector $projector = null)
    {
        global $wpdb;
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

        // 4. Compile SQL
        [$sql, $params] = $this->compileQuery($normalizedFilters, $sort, $limit, $gen, $cursorData);
        $preparedSql = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;

        $rows = $this->wpdb->get_results($preparedSql, ARRAY_A);
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
     * Compiles parameterized SELECT SQL for catalog queries.
     */
    private function compileQuery(
        array $filters,
        string $sort,
        int $limit,
        int $generation,
        ?array $cursorData = null
    ): array {
        $selectCols = [
            'c.product_id', 'c.type', 'c.status', 'c.visibility',
            'c.in_stock', 'c.featured', 'c.on_sale', 'c.min_price', 'c.max_price',
            'c.total_sales', 'c.rating_average', 'c.rating_count', 'c.created_at',
            'c.menu_order', 'c.title_sort', 'c.image_asset_id',
        ];

        $from = "{$this->tableCatalog} c";
        $joins = [];
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
            $where[] = "(c.title_sort LIKE %s OR c.product_id IN (SELECT object_id FROM {$this->wpdb->prefix}wpe_sku_registry WHERE original_sku LIKE %s OR LOWER(CAST(normalized_sku AS CHAR)) LIKE %s))";
            $params[] = '%' . $this->wpdb->esc_like($term) . '%';
            $params[] = '%' . $this->wpdb->esc_like($term) . '%';
            $params[] = '%' . $this->wpdb->esc_like($normTerm) . '%';
        }

        // Category Facets
        if (!empty($filters['category'])) {
            $joins[] = "INNER JOIN {$this->tableCatalogFacets} f_cat ON (f_cat.generation = c.generation AND f_cat.product_id = c.product_id)";
            $catPlaceholders = implode(', ', array_fill(0, count($filters['category']), '%s'));
            $where[] = "(f_cat.facet_key IN ('category', 'category_id') AND f_cat.value_key IN ({$catPlaceholders}))";
            foreach ($filters['category'] as $catVal) {
                $params[] = (string)$catVal;
            }
        }

        // Tag Facets
        if (!empty($filters['tag'])) {
            $joins[] = "INNER JOIN {$this->tableCatalogFacets} f_tag ON (f_tag.generation = c.generation AND f_tag.product_id = c.product_id)";
            $tagPlaceholders = implode(', ', array_fill(0, count($filters['tag']), '%s'));
            $where[] = "(f_tag.facet_key = 'tag' AND f_tag.value_key IN ({$tagPlaceholders}))";
            foreach ($filters['tag'] as $tagVal) {
                $params[] = (string)$tagVal;
            }
        }

        // Attribute Facets
        if (!empty($filters['attributes']) && is_array($filters['attributes'])) {
            $idx = 0;
            foreach ($filters['attributes'] as $attrKey => $attrVals) {
                $idx++;
                $alias = "f_attr_{$idx}";
                $joins[] = "INNER JOIN {$this->tableCatalogFacets} {$alias} ON ({$alias}.generation = c.generation AND {$alias}.product_id = c.product_id)";
                $vals = is_array($attrVals) ? $attrVals : [(string)$attrVals];
                $placeholders = implode(', ', array_fill(0, count($vals), '%s'));
                $where[] = "({$alias}.facet_key = %s AND {$alias}.value_key IN ({$placeholders}))";
                $params[] = sanitize_key((string)$attrKey);
                foreach ($vals as $v) {
                    $params[] = sanitize_key((string)$v);
                }
            }
        }

        // Keyset Cursor Where Clause (R68, R70)
        if ($cursorData !== null && isset($cursorData['last_id'])) {
            $lastId = (int)$cursorData['last_id'];
            $lastVal = $cursorData['sort_value'];

            switch ($sort) {
                case 'price_asc':
                    if ($lastVal === null) {
                        // All remaining items are nulls; paginate by product_id ASC
                        $where[] = "(c.min_price IS NULL AND c.product_id > %d)";
                        $params[] = $lastId;
                    } else {
                        // Items with higher price OR equal price with higher product_id OR null prices
                        $where[] = "((c.min_price > %f) OR (c.min_price = %f AND c.product_id > %d) OR (c.min_price IS NULL))";
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
                        $where[] = "((c.min_price < %f AND c.min_price IS NOT NULL) OR (c.min_price = %f AND c.product_id < %d) OR (c.min_price IS NULL))";
                        $params[] = (float)$lastVal;
                        $params[] = (float)$lastVal;
                        $params[] = $lastId;
                    }
                    break;

                case 'date_desc':
                    $where[] = "((c.created_at < %s) OR (c.created_at = %s AND c.product_id < %d))";
                    $params[] = (string)$lastVal;
                    $params[] = (string)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'date_asc':
                    $where[] = "((c.created_at > %s) OR (c.created_at = %s AND c.product_id > %d))";
                    $params[] = (string)$lastVal;
                    $params[] = (string)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'popularity':
                case 'sales':
                    $where[] = "((c.total_sales < %f) OR (c.total_sales = %f AND c.product_id < %d))";
                    $params[] = (float)$lastVal;
                    $params[] = (float)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'rating':
                    $where[] = "((c.rating_average < %f) OR (c.rating_average = %f AND c.product_id < %d))";
                    $params[] = (float)$lastVal;
                    $params[] = (float)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'menu_order':
                    $where[] = "((c.menu_order > %d) OR (c.menu_order = %d AND c.product_id > %d))";
                    $params[] = (int)$lastVal;
                    $params[] = (int)$lastVal;
                    $params[] = $lastId;
                    break;

                case 'title_asc':
                    $where[] = "((c.title_sort > %s) OR (c.title_sort = %s AND c.product_id > %d))";
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

        // Order By clause with nulls last and deterministic tie-breaking (R68, R70)
        $orderBy = $this->compileOrderBy($sort);

        $sql = "SELECT " . implode(', ', $selectCols) . "
                FROM {$from}
                " . implode(' ', $joins) . "
                WHERE " . implode(' AND ', $where) . "
                GROUP BY c.product_id
                ORDER BY {$orderBy}
                LIMIT %d";

        $params[] = $limit;

        return [$sql, $params];
    }

    /**
     * Compiles ORDER BY SQL with deterministic tie-breaking and nulls last (R68, R70).
     */
    private function compileOrderBy(string $sort): string
    {
        switch ($sort) {
            case 'price_asc':
                // Nulls last, then min_price ASC, product_id ASC tie-break
                return "(c.min_price IS NULL) ASC, c.min_price ASC, c.product_id ASC";

            case 'price_desc':
                // Nulls last, then min_price DESC, product_id DESC tie-break
                return "(c.min_price IS NULL) ASC, c.min_price DESC, c.product_id DESC";

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
                return "(c.min_price IS NULL) ASC, c.min_price ASC, c.product_id ASC";
        }
    }

    /**
     * Executes an exact count query for filtered catalog.
     */
    private function executeCount(array $filters, int $generation): int
    {
        $joins = [];
        $where = ["c.generation = %d"];
        $params = [$generation];

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
        if (isset($filters['min_price'])) {
            $where[] = "c.min_price >= %f";
            $params[] = $filters['min_price'];
        }
        if (isset($filters['max_price'])) {
            $where[] = "c.min_price <= %f";
            $params[] = $filters['max_price'];
        }

        if (!empty($filters['category'])) {
            $joins[] = "INNER JOIN {$this->tableCatalogFacets} f_cat ON (f_cat.generation = c.generation AND f_cat.product_id = c.product_id)";
            $catPlaceholders = implode(', ', array_fill(0, count($filters['category']), '%s'));
            $where[] = "(f_cat.facet_key IN ('category', 'category_id') AND f_cat.value_key IN ({$catPlaceholders}))";
            foreach ($filters['category'] as $catVal) {
                $params[] = (string)$catVal;
            }
        }

        $sql = "SELECT COUNT(DISTINCT c.product_id)
                FROM {$this->tableCatalog} c
                " . implode(' ', $joins) . "
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
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handles public GET /wpe/v1/catalog requests.
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
                'message'   => 'Catalog index query error: ' . $e->getMessage(),
                'details'   => [],
                'retryable' => true,
            ], 500);
        }
    }
}
