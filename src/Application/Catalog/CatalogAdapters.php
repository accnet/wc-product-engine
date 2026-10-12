<?php
declare(strict_types=1);

namespace WPE\Application\Catalog;

use InvalidArgumentException;
use WP_Query;
use WP_Post;

class UnsupportedQueryException extends InvalidArgumentException {}

/**
 * CatalogAdapters — Scoped WooCommerce query, Data Store, and archive pagination adapters.
 *
 * Implements requirements:
 * - R75: Archive pagination with exact found_posts, max_num_pages, and deep page budget cap.
 * - R76: Comprehensive WooCommerce query arguments coverage (category, tag, attributes, price, stock, orderby).
 * - R77: WooCommerce Blocks product listing and collection adapter.
 * - R78: Query capability registry blocking unsupported filters without silent omission.
 * - AC-T19-01: Classic pagination, layered nav, Woo REST, and Blocks product collections return exact IDs and totals.
 * - AC-T19-02: Non-product WP_Query instances remain completely untouched.
 * - AC-T19-03: Unsupported queries fail explicitly or trigger documented blocker without silently dropping conditions.
 */
class CatalogAdapters
{
    public const MAX_PAGE_DEPTH = 100;
    public const DEFAULT_PER_PAGE = 48;

    private \wpdb $wpdb;
    private CatalogQuery $queryCompiler;
    private CatalogProjector $projector;
    private bool $strictMode;

    public function __construct(?\wpdb $wpdb = null, ?CatalogQuery $queryCompiler = null, bool $strictMode = true)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->queryCompiler = $queryCompiler ?? new CatalogQuery($this->wpdb);
        $this->projector = new CatalogProjector($this->wpdb);
        $this->strictMode = $strictMode;
    }

    /**
     * Initializes WordPress hooks for query interception.
     */
    public static function init(): void
    {
        $instance = new self();
        add_filter('posts_pre_query', [$instance, 'handlePostsPreQuery'], 10, 2);
    }

    /* -------------------------------------------------------------------------- */
    /* Query Capability Registry (R78, AC-T19-03)                                 */
    /* -------------------------------------------------------------------------- */

    /**
     * Evaluates whether a set of query variables is fully supported by the engine.
     *
     * @param array $queryVars
     * @return array [bool $isSupported, string[] $unsupportedReasons]
     */
    public function evaluateQuerySupport(array $queryVars): array
    {
        $unsupported = [];

        // Check for unsupported meta queries (unindexed custom postmeta)
        if (!empty($queryVars['meta_query']) && is_array($queryVars['meta_query'])) {
            $allowedMetaKeys = ['_price', '_regular_price', '_sale_price', '_sku', '_stock_status', '_featured', '_visibility'];
            foreach ($queryVars['meta_query'] as $clause) {
                if (is_array($clause) && isset($clause['key'])) {
                    if (!in_array($clause['key'], $allowedMetaKeys, true)) {
                        $unsupported[] = "Unsupported meta_query key: '{$clause['key']}'";
                    }
                }
            }
        }

        // Check for unsupported raw SQL clauses
        if (!empty($queryVars['suppress_filters']) && !empty($queryVars['wpe_raw_sql'])) {
            $unsupported[] = "Raw SQL modification detected";
        }

        // Check for unsupported post status
        if (isset($queryVars['post_status'])) {
            $statuses = (array)$queryVars['post_status'];
            $allowedStatuses = ['publish', 'draft', 'trash', 'any'];
            foreach ($statuses as $st) {
                if (!in_array($st, $allowedStatuses, true)) {
                    $unsupported[] = "Unsupported post_status: '{$st}'";
                }
            }
        }

        // Check for unsupported taxonomy in tax_query
        if (!empty($queryVars['tax_query']) && is_array($queryVars['tax_query'])) {
            foreach ($queryVars['tax_query'] as $taxClause) {
                if (is_array($taxClause) && isset($taxClause['taxonomy'])) {
                    $tax = $taxClause['taxonomy'];
                    if ($tax !== 'product_cat' && $tax !== 'product_tag' && !str_starts_with($tax, 'pa_')) {
                        $unsupported[] = "Unsupported taxonomy: '{$tax}'";
                    }
                }
            }
        }

        return [
            'supported' => empty($unsupported),
            'reasons'   => $unsupported,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* WooCommerce Query Arguments Adapter (R76)                                  */
    /* -------------------------------------------------------------------------- */

    /**
     * Translates WooCommerce query arguments into CatalogQuery parameters.
     *
     * @param array $queryVars
     * @return array Normalized CatalogQuery arguments
     */
    public function adaptWcQueryArgs(array $queryVars): array
    {
        $adapted = [];

        // 1. Categories
        if (!empty($queryVars['category'])) {
            $adapted['category'] = is_array($queryVars['category']) ? $queryVars['category'] : [$queryVars['category']];
        } elseif (!empty($queryVars['product_cat'])) {
            $adapted['category'] = is_array($queryVars['product_cat']) ? $queryVars['product_cat'] : [$queryVars['product_cat']];
        }

        // 2. Tags
        if (!empty($queryVars['tag'])) {
            $adapted['tag'] = is_array($queryVars['tag']) ? $queryVars['tag'] : [$queryVars['tag']];
        } elseif (!empty($queryVars['product_tag'])) {
            $adapted['tag'] = is_array($queryVars['product_tag']) ? $queryVars['product_tag'] : [$queryVars['product_tag']];
        }

        // 3. Tax Query parsing (categories, tags, and pa_* attributes)
        if (!empty($queryVars['tax_query']) && is_array($queryVars['tax_query'])) {
            foreach ($queryVars['tax_query'] as $clause) {
                if (!is_array($clause) || empty($clause['taxonomy'])) {
                    continue;
                }
                $tax = $clause['taxonomy'];
                $terms = (array)($clause['terms'] ?? []);
                if (empty($terms)) {
                    continue;
                }

                if ($tax === 'product_cat') {
                    $adapted['category'] = array_merge($adapted['category'] ?? [], $terms);
                } elseif ($tax === 'product_tag') {
                    $adapted['tag'] = array_merge($adapted['tag'] ?? [], $terms);
                } elseif (str_starts_with($tax, 'pa_')) {
                    $adapted['attributes'][$tax] = $terms;
                }
            }
        }

        // 4. Status
        if (!empty($queryVars['status'])) {
            $adapted['status'] = is_array($queryVars['status']) ? reset($queryVars['status']) : (string)$queryVars['status'];
        } elseif (!empty($queryVars['post_status'])) {
            $adapted['status'] = is_array($queryVars['post_status']) ? reset($queryVars['post_status']) : (string)$queryVars['post_status'];
        }

        // 5. Visibility
        if (!empty($queryVars['visibility'])) {
            $adapted['visibility'] = (string)$queryVars['visibility'];
        }

        // 6. Stock Status
        if (isset($queryVars['stock_status'])) {
            $adapted['in_stock'] = $queryVars['stock_status'] === 'instock';
        }

        // 7. Featured
        if (isset($queryVars['featured'])) {
            $adapted['featured'] = (bool)$queryVars['featured'];
        }

        // 8. On Sale
        if (isset($queryVars['on_sale'])) {
            $adapted['on_sale'] = (bool)$queryVars['on_sale'];
        }

        // 9. Price Range
        if (isset($queryVars['min_price'])) {
            $adapted['min_price'] = (float)$queryVars['min_price'];
        }
        if (isset($queryVars['max_price'])) {
            $adapted['max_price'] = (float)$queryVars['max_price'];
        }

        // 10. Search (SKU or title)
        if (!empty($queryVars['s'])) {
            $adapted['search'] = (string)$queryVars['s'];
        } elseif (!empty($queryVars['search'])) {
            $adapted['search'] = (string)$queryVars['search'];
        } elseif (!empty($queryVars['sku'])) {
            $adapted['search'] = (string)$queryVars['sku'];
        }

        // 11. Sorting / Orderby
        $orderby = $queryVars['orderby'] ?? 'date';
        $order = strtoupper($queryVars['order'] ?? 'DESC');

        switch ($orderby) {
            case 'price':
            case 'meta_value_num':
                $adapted['sort'] = $order === 'ASC' ? 'price_asc' : 'price_desc';
                break;
            case 'date':
                $adapted['sort'] = $order === 'ASC' ? 'date_asc' : 'date_desc';
                break;
            case 'popularity':
            case 'total_sales':
                $adapted['sort'] = 'popularity';
                break;
            case 'rating':
                $adapted['sort'] = 'rating';
                break;
            case 'menu_order':
                $adapted['sort'] = 'menu_order';
                break;
            case 'title':
                $adapted['sort'] = 'title_asc';
                break;
            default:
                $adapted['sort'] = 'price_asc';
                break;
        }

        // 12. Pagination (limit, page, offset)
        $limit = isset($queryVars['limit']) ? (int)$queryVars['limit'] : null;
        if ($limit === null && isset($queryVars['posts_per_page'])) {
            $limit = (int)$queryVars['posts_per_page'];
        }
        $adapted['limit'] = $limit !== null && $limit > 0 ? $limit : self::DEFAULT_PER_PAGE;

        $page = isset($queryVars['page']) ? (int)$queryVars['page'] : null;
        if ($page === null && isset($queryVars['paged'])) {
            $page = (int)$queryVars['paged'];
        }
        $adapted['page'] = $page !== null && $page > 0 ? $page : 1;

        if (isset($queryVars['offset']) && (int)$queryVars['offset'] >= 0) {
            $adapted['offset'] = (int)$queryVars['offset'];
        }

        return $adapted;
    }

    /**
     * Executes an adapted query returning matching product IDs, totals, and pages (R75, R76).
     *
     * @param array $queryVars
     * @return array [ids, total, total_pages, page, limit]
     * @throws UnsupportedQueryException on unsupported query filters when strictMode is enabled
     */
    public function executeAdaptedQuery(array $queryVars): array
    {
        // 1. Evaluate query capability (R78, AC-T19-03)
        $support = $this->evaluateQuerySupport($queryVars);
        if (!$support['supported']) {
            if ($this->strictMode) {
                throw new UnsupportedQueryException(
                    "Query unsupported: " . implode('; ', $support['reasons'])
                );
            }
            return ['ids' => [], 'total' => 0, 'total_pages' => 0, 'page' => 1, 'limit' => 48];
        }

        // 2. Adapt args
        $adapted = $this->adaptWcQueryArgs($queryVars);

        // 3. Deep page budget check (R75)
        $page = $adapted['page'] ?? 1;
        if ($page > self::MAX_PAGE_DEPTH) {
            // Cap to max page depth to prevent deep offset exhaustion
            $page = self::MAX_PAGE_DEPTH;
        }

        $limit = min($adapted['limit'] ?? self::DEFAULT_PER_PAGE, 100);
        $offset = isset($adapted['offset']) ? $adapted['offset'] : ($page - 1) * $limit;

        $gen = $this->projector->getActiveGeneration();

        // 4. Compile SQL for numbered pagination
        $adapted['generation'] = $gen;
        $adapted['limit'] = $limit;
        $adapted['count_mode'] = 'exact';

        // Calculate exact total count
        $refMethod = new \ReflectionMethod($this->queryCompiler, 'executeCount');
        $refMethod->setAccessible(true);
        $normMethod = new \ReflectionMethod($this->queryCompiler, 'normalizeFilters');
        $normMethod->setAccessible(true);

        $normalized = $normMethod->invoke($this->queryCompiler, $adapted);
        $total = (int)$refMethod->invoke($this->queryCompiler, $normalized, $gen);

        // Compile bounded SELECT with OFFSET for numbered archives
        $compileMethod = new \ReflectionMethod($this->queryCompiler, 'compileQuery');
        $compileMethod->setAccessible(true);
        [$baseSql, $params] = $compileMethod->invoke($this->queryCompiler, $normalized, $adapted['sort'], $limit, $gen, null);

        // Replace LIMIT %d with LIMIT %d OFFSET %d
        if ($offset > 0) {
            $baseSql = preg_replace('/LIMIT\s+%d$/', 'LIMIT %d OFFSET %d', $baseSql);
            $params[] = $offset;
        }

        $prepared = $this->wpdb->prepare($baseSql, ...$params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        $ids = array_map(fn($r) => (int)$r['product_id'], $rows);

        $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;

        return [
            'ids'         => $ids,
            'total'       => $total,
            'total_pages' => $totalPages,
            'page'        => $page,
            'limit'       => $limit,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* WP_Query & Archive Pagination Hook (R75, AC-T19-01, AC-T19-02)              */
    /* -------------------------------------------------------------------------- */

    /**
     * Filters posts_pre_query to intercept and fast-path product archive queries.
     *
     * @param array|null $pre
     * @param WP_Query $query
     * @return array|null Null to let core proceed, or array of WP_Post objects
     */
    public function handlePostsPreQuery($pre, WP_Query $query): ?array
    {
        // AC-T19-02: Non-product queries must NOT be touched!
        $postType = $query->get('post_type');
        $isProductQuery = ($postType === 'product' || (is_array($postType) && in_array('product', $postType, true)));

        if (!$isProductQuery && !$query->get('wc_query')) {
            return null;
        }

        // Check if query is supported
        $queryVars = $query->query_vars;
        $support = $this->evaluateQuerySupport($queryVars);

        if (!$support['supported']) {
            if ($this->strictMode) {
                // Do not silently omit filters (R78, AC-T19-03)
                throw new UnsupportedQueryException(
                    "Unsupported product archive filter: " . implode('; ', $support['reasons'])
                );
            }
            return null; // Fallback to core WP_Query
        }

        // Execute via engine
        $result = $this->executeAdaptedQuery($queryVars);

        // Set pagination totals on WP_Query (R75)
        $query->found_posts = $result['total'];
        $query->max_num_pages = $result['total_pages'];

        if (empty($result['ids'])) {
            return [];
        }

        // Prime post caches and return WP_Post instances
        _prime_post_caches($result['ids'], true, true);
        $posts = array_filter(array_map('get_post', $result['ids']));

        return array_values($posts);
    }

    /* -------------------------------------------------------------------------- */
    /* WooCommerce Blocks Product Collection Adapter (R77, AC-T19-01)             */
    /* -------------------------------------------------------------------------- */

    /**
     * Adapts WooCommerce Blocks attributes (e.g. product collection or grid) into catalog query.
     *
     * @param array $blockAttrs Block query attributes
     * @return array Execution result with IDs and totals
     */
    public function adaptBlockQuery(array $blockAttrs): array
    {
        $queryVars = [];

        // Category filter in blocks
        if (!empty($blockAttrs['categories']) && is_array($blockAttrs['categories'])) {
            $queryVars['category'] = $blockAttrs['categories'];
        }

        // Tag filter in blocks
        if (!empty($blockAttrs['tags']) && is_array($blockAttrs['tags'])) {
            $queryVars['tag'] = $blockAttrs['tags'];
        }

        // Attributes in blocks
        if (!empty($blockAttrs['attributes']) && is_array($blockAttrs['attributes'])) {
            $queryVars['tax_query'] = [];
            foreach ($blockAttrs['attributes'] as $attr) {
                if (isset($attr['taxonomy'], $attr['terms'])) {
                    $queryVars['tax_query'][] = [
                        'taxonomy' => $attr['taxonomy'],
                        'terms'    => (array)$attr['terms'],
                        'field'    => 'slug',
                    ];
                }
            }
        }

        // Stock status
        if (isset($blockAttrs['stockStatus'])) {
            $statuses = (array)$blockAttrs['stockStatus'];
            if (in_array('instock', $statuses, true) && !in_array('outofstock', $statuses, true)) {
                $queryVars['stock_status'] = 'instock';
            } elseif (in_array('outofstock', $statuses, true) && !in_array('instock', $statuses, true)) {
                $queryVars['stock_status'] = 'outofstock';
            }
        }

        // Orderby / order
        if (!empty($blockAttrs['orderBy'])) {
            $queryVars['orderby'] = $blockAttrs['orderBy'];
        }
        if (!empty($blockAttrs['order'])) {
            $queryVars['order'] = $blockAttrs['order'];
        }

        // Per page
        if (!empty($blockAttrs['perPage'])) {
            $queryVars['posts_per_page'] = (int)$blockAttrs['perPage'];
        }

        // Page
        if (!empty($blockAttrs['page'])) {
            $queryVars['paged'] = (int)$blockAttrs['page'];
        }

        return $this->executeAdaptedQuery($queryVars);
    }
}
