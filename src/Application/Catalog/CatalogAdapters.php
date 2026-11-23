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
    /** Largest page the index serves; larger or unbounded requests are left to WP_Query (audit LOG-01). */
    public const MAX_PER_PAGE = 100;

    /** WP_Query vars the catalog index cannot express; a non-empty value falls back to core. */
    private const UNSUPPORTED_QUERY_VARS = [
        'author', 'author_name', 'author__in', 'author__not_in',
        'date_query', 'year', 'monthnum', 'day', 'w', 'm', 'hour', 'minute', 'second',
        'post_parent', 'post_parent__in', 'post_parent__not_in',
        'post_name__in', 'name__in', 'meta_key', 'meta_value', 'meta_compare',
        'perm', 'has_password', 'post_password', 'comment_count',
    ];

    private \wpdb $wpdb;
    private CatalogQuery $queryCompiler;
    private CatalogProjector $projector;
    private ContextCache $contextCache;
    private bool $strictMode;
    private bool $persistentContextCache;

    public function __construct(
        ?\wpdb $wpdb = null,
        ?CatalogQuery $queryCompiler = null,
        bool $strictMode = true,
        ?ContextCache $contextCache = null
    ) {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->projector = new CatalogProjector($this->wpdb);
        $this->queryCompiler = $queryCompiler ?? new CatalogQuery($this->wpdb, $this->projector);
        $this->contextCache = $contextCache ?? new ContextCache($this->wpdb, $this->projector);
        $this->strictMode = $strictMode;
        $this->persistentContextCache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    public function setPersistentContextCache(bool $enabled): void
    {
        $this->persistentContextCache = $enabled;
    }

    /**
     * Initializes WordPress hooks for query interception.
     * Strict fail-closed mode is enforced under sealed full_native; under indexed / cohort_canary /
     * native_bridge (where legacy CPT & lookup tables remain canonical or actively mirrored),
     * exotic unsupported WP_Query clauses fall back cleanly instead of throwing a 500 error.
     */
    public static function init(): void
    {
        $mode = class_exists(\WPE\Bootstrap\PluginBootstrap::class)
            ? \WPE\Bootstrap\PluginBootstrap::instance()->getSystemMode()
            : 'full_native';
        $strict = ($mode === 'full_native');
        $instance = new self(null, null, $strict);
        add_filter('posts_pre_query', [$instance, 'handlePostsPreQuery'], 10, 2);
        CatalogProjector::registerTermSync();
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

        // meta_query is never translated by adaptWcQueryArgs(), so any clause would be silently
        // dropped (R78, audit LOG-01). Name the keys for diagnostics, then fall back to core.
        if (!empty($queryVars['meta_query']) && is_array($queryVars['meta_query'])) {
            $keys = [];
            array_walk_recursive($queryVars['meta_query'], static function ($value, $key) use (&$keys): void {
                if ($key === 'key' && is_scalar($value)) {
                    $keys[] = (string)$value;
                }
            });
            $unsupported[] = $keys === []
                ? 'Unsupported meta_query'
                : "Unsupported meta_query key: '" . implode("', '", array_unique($keys)) . "'";
        }

        // Query vars the catalog index cannot express (audit LOG-01).
        foreach (self::UNSUPPORTED_QUERY_VARS as $var) {
            if (!empty($queryVars[$var])) {
                $unsupported[] = "Unsupported query var: '{$var}'";
            }
        }

        // Unbounded or oversized result sets are not archive pages: posts_per_page -1 used to come
        // back as 48 rows and anything above 100 was cut to 100 (audit LOG-01).
        $perPage = $queryVars['posts_per_page'] ?? ($queryVars['limit'] ?? null);
        if (!empty($queryVars['nopaging']) || (is_numeric($perPage) && ((int)$perPage < 0 || (int)$perPage > self::MAX_PER_PAGE))) {
            $unsupported[] = 'Unsupported page size: ' . (!empty($queryVars['nopaging']) ? 'nopaging' : (string)$perPage);
        }

        if ($this->mapOrderby($queryVars) === null) {
            $orderby = $queryVars['orderby'] ?? '';
            $unsupported[] = 'Unsupported orderby: ' . (is_scalar($orderby) ? "'{$orderby}'" : wp_json_encode($orderby))
                . ' ' . strtoupper((string)($queryVars['order'] ?? ''));
        }

        // WooCommerce applies the archive price filter from $_GET in posts_clauses on the main query
        // (WC_Query::price_filter_post_clauses), so it never appears in query vars and would be
        // dropped here. handlePostsPreQuery() marks it (audit LOG-01).
        if (!empty($queryVars['wpe_wc_price_filter'])) {
            $unsupported[] = 'Unsupported WooCommerce price filter (min_price/max_price)';
        }

        // Check for unsupported raw SQL clauses
        if (!empty($queryVars['suppress_filters']) && !empty($queryVars['wpe_raw_sql'])) {
            $unsupported[] = "Raw SQL modification detected";
        }

        // Check for explicit post ID inclusion/exclusion lists not handled by CatalogQuery
        if (!empty($queryVars['post__in'])) {
            $unsupported[] = 'Unsupported explicit post__in filter';
        }
        if (!empty($queryVars['post__not_in'])) {
            $unsupported[] = 'Unsupported explicit post__not_in filter';
        }

        // Only published products are served from the index. 'any' used to become the literal filter
        // status = 'any' (0 rows), and only the first of several statuses was applied (audit LOG-01).
        if (isset($queryVars['post_status']) && $queryVars['post_status'] !== '') {
            $statuses = (array)$queryVars['post_status'];
            $allowedStatuses = ['publish'];
            foreach ($statuses as $st) {
                if ($st !== '' && !in_array($st, $allowedStatuses, true)) {
                    $unsupported[] = "Unsupported post_status: '{$st}'";
                }
            }
        }

        // Check for unsupported taxonomy in tax_query (supports nested clauses & product_visibility)
        if (!empty($queryVars['tax_query']) && is_array($queryVars['tax_query'])) {
            foreach ($this->flattenTaxClauses($queryVars['tax_query']) as $taxClause) {
                $tax = (string)$taxClause['taxonomy'];
                if ($tax === 'product_visibility') {
                    $visSlugs = $this->resolveVisibilitySlugs($taxClause);
                    foreach ($visSlugs as $slug) {
                        if (!in_array($slug, ['exclude-from-catalog', 'exclude-from-search', 'featured', 'outofstock'], true)) {
                            $unsupported[] = "Unsupported product_visibility term: '{$slug}'";
                        }
                    }
                    continue;
                }
                if ($tax !== 'product_cat' && $tax !== 'product_tag' && !str_starts_with($tax, 'pa_')) {
                    $unsupported[] = "Unsupported taxonomy: '{$tax}'";
                }
            }
        }

        return [
            'supported' => empty($unsupported),
            'reasons'   => $unsupported,
        ];
    }

    /**
     * Flatten nested WP_Tax_Query arrays into leaf clauses that specify a taxonomy.
     *
     * @return array<int, array<string, mixed>>
     */
    private function flattenTaxClauses(array $taxQuery): array
    {
        $clauses = [];
        foreach ($taxQuery as $key => $item) {
            if ($key === 'relation' || !is_array($item)) {
                continue;
            }
            if (isset($item['taxonomy'])) {
                $clauses[] = $item;
            } else {
                foreach ($this->flattenTaxClauses($item) as $sub) {
                    $clauses[] = $sub;
                }
            }
        }
        return $clauses;
    }

    /**
     * Resolve term slugs for a product_visibility tax_query clause (by slug, name, term_id, or term_taxonomy_id).
     *
     * @return string[]
     */
    private function resolveVisibilitySlugs(array $clause): array
    {
        $terms = (array)($clause['terms'] ?? []);
        if (empty($terms)) {
            return [];
        }
        $field = (string)($clause['field'] ?? 'slug');
        if ($field === 'slug' || $field === 'name') {
            return array_values(array_map(static fn($t): string => strtolower(trim((string)$t)), $terms));
        }

        $idToSlug = [];
        if (function_exists('wc_get_product_visibility_term_ids')) {
            foreach ((array)wc_get_product_visibility_term_ids() as $slug => $ttId) {
                if ((int)$ttId > 0) {
                    $idToSlug[(int)$ttId] = (string)$slug;
                }
            }
        }

        $resolved = [];
        foreach ($terms as $rawId) {
            $intId = (int)$rawId;
            if (isset($idToSlug[$intId])) {
                $resolved[] = $idToSlug[$intId];
                continue;
            }
            if (function_exists('get_term_by')) {
                $termObj = get_term_by($field === 'term_taxonomy_id' ? 'term_taxonomy_id' : 'id', $intId, 'product_visibility');
                if ($termObj && !empty($termObj->slug)) {
                    $resolved[] = (string)$termObj->slug;
                    continue;
                }
            }
            $resolved[] = 'unknown_' . $intId;
        }
        return $resolved;
    }

    /* -------------------------------------------------------------------------- */
    /* WooCommerce Query Arguments Adapter (R76)                                  */
    /* -------------------------------------------------------------------------- */

    /**
     * Catalog sort for the query's orderby/order, or null when the index cannot reproduce it.
     *
     * Before audit LOG-01 every unknown orderby (rand, relevance, post__in, 'date ID',
     * meta_value on any key...) was served as price ascending.
     */
    private function mapOrderby(array $queryVars): ?string
    {
        $orderby = $queryVars['orderby'] ?? '';
        if (!is_string($orderby)) {
            return null; // array form: multiple sort keys
        }
        $orderby = trim($orderby);
        $order = strtoupper(trim((string)($queryVars['order'] ?? '')));
        $asc = $order === 'ASC';

        if ($orderby === '') {
            // WP_Query orders a search by relevance and anything else by date DESC.
            return !empty($queryVars['s']) ? null : ($asc ? 'date_asc' : 'date_desc');
        }

        switch ($orderby) {
            case 'price':
                return $asc ? 'price_asc' : 'price_desc';
            case 'meta_value_num':
            case 'meta_value':
                return ($queryVars['meta_key'] ?? '') === '_price' ? ($asc ? 'price_asc' : 'price_desc') : null;
            case 'date':
            case 'date ID':
            case 'post_date':
            case 'ID':
            case 'id':
                return $asc ? 'date_asc' : 'date_desc';
            // The index sorts these one way only; WP_Query's default order is DESC.
            case 'popularity':
            case 'total_sales':
                return $asc ? null : 'popularity';
            case 'rating':
                return $asc ? null : 'rating';
            case 'menu_order':
            case 'menu_order title':
                return $asc ? 'menu_order' : null;
            case 'title':
            case 'post_title':
                return $asc ? 'title_asc' : null;
            default:
                return null;
        }
    }

    /**
     * Resolve product_cat query values to category facet keys, adding descendant term IDs when
     * include_children applies (WP_Tax_Query default). Unresolvable values pass through unchanged.
     *
     * @param array<int|string> $values
     * @return string[]
     */
    private function expandCategoryTerms(array $values, string $field, bool $includeChildren): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $term = null;
            if (function_exists('get_term_by')) {
                switch ($field) {
                    case 'slug':
                        $term = get_term_by('slug', $value, 'product_cat');
                        break;
                    case 'name':
                        $term = get_term_by('name', $value, 'product_cat');
                        break;
                    case 'term_taxonomy_id':
                    case 'tt_id':
                        $term = get_term_by('term_taxonomy_id', (int)$value, 'product_cat');
                        break;
                    default:
                        $term = ctype_digit($value)
                            ? get_term_by('id', (int)$value, 'product_cat')
                            : get_term_by('slug', $value, 'product_cat');
                }
            }
            if (!$term || is_wp_error($term)) {
                $out[] = $value;
                continue;
            }
            $out[] = (string)$term->term_id;
            if ($includeChildren && function_exists('get_term_children')) {
                $children = get_term_children((int)$term->term_id, 'product_cat');
                if (is_array($children)) {
                    foreach ($children as $childId) {
                        $out[] = (string)(int)$childId;
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

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
        // Slugs; WordPress and WooCommerce include child categories by default (include_children).
        if (!empty($queryVars['category'])) {
            $adapted['category'] = $this->expandCategoryTerms(
                is_array($queryVars['category']) ? $queryVars['category'] : explode(',', (string)$queryVars['category']),
                'slug',
                true
            );
        } elseif (!empty($queryVars['product_cat'])) {
            $adapted['category'] = $this->expandCategoryTerms(
                is_array($queryVars['product_cat']) ? $queryVars['product_cat'] : explode(',', (string)$queryVars['product_cat']),
                'slug',
                true
            );
        }

        // 2. Tags
        if (!empty($queryVars['tag'])) {
            $adapted['tag'] = is_array($queryVars['tag']) ? $queryVars['tag'] : [$queryVars['tag']];
        } elseif (!empty($queryVars['product_tag'])) {
            $adapted['tag'] = is_array($queryVars['product_tag']) ? $queryVars['product_tag'] : [$queryVars['product_tag']];
        }

        // 3. Tax Query parsing (categories, tags, pa_* attributes, and product_visibility)
        $excludeCatalog = false;
        $excludeSearch = false;
        if (!empty($queryVars['tax_query']) && is_array($queryVars['tax_query'])) {
            foreach ($this->flattenTaxClauses($queryVars['tax_query']) as $clause) {
                $tax = (string)$clause['taxonomy'];
                $terms = (array)($clause['terms'] ?? []);
                if (empty($terms)) {
                    continue;
                }

                if ($tax === 'product_visibility') {
                    $operator = strtoupper((string)($clause['operator'] ?? 'IN'));
                    $slugs = $this->resolveVisibilitySlugs($clause);
                    foreach ($slugs as $visSlug) {
                        if ($visSlug === 'featured') {
                            $adapted['featured'] = ($operator !== 'NOT IN');
                        } elseif ($visSlug === 'outofstock') {
                            $adapted['in_stock'] = ($operator === 'NOT IN');
                        } elseif ($visSlug === 'exclude-from-catalog' && $operator === 'NOT IN') {
                            $excludeCatalog = true;
                        } elseif ($visSlug === 'exclude-from-search' && $operator === 'NOT IN') {
                            $excludeSearch = true;
                        }
                    }
                    continue;
                }

                if ($tax === 'product_cat') {
                    $adapted['category'] = array_merge($adapted['category'] ?? [], $this->expandCategoryTerms(
                        $terms,
                        (string)($clause['field'] ?? 'term_id'),
                        !array_key_exists('include_children', $clause) || !empty($clause['include_children'])
                    ));
                } elseif ($tax === 'product_tag') {
                    $adapted['tag'] = array_merge($adapted['tag'] ?? [], $terms);
                } elseif (str_starts_with($tax, 'pa_')) {
                    $adapted['attributes'][$tax] = $terms;
                }
            }
        }

        if (!empty($adapted['category'])) {
            $adapted['category'] = array_values(array_unique(array_map('strval', $adapted['category'])));
        }
        if (!empty($adapted['tag'])) {
            $adapted['tag'] = array_values(array_unique(array_map('strval', $adapted['tag'])));
        }

        if ($excludeCatalog && $excludeSearch && empty($adapted['visibility'])) {
            $adapted['visibility'] = 'visible';
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

        // 11. Sorting / Orderby (unsupported values never reach here: evaluateQuerySupport() rejects them)
        $adapted['sort'] = $this->mapOrderby($queryVars) ?? 'date_desc';

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

        // 4. Execute via zero-filesort CatalogQuery::executeArchivePage with context-isolated caching (P028.3)
        $adapted['generation'] = $gen;
        $adapted['page'] = $page;
        $adapted['limit'] = $limit;
        $adapted['offset'] = $offset;

        \WPE\Support\AuthorityResolver::ensureRuntimeCacheGroup();
        $cacheKey = $this->contextCache->generateCacheKey($adapted, ['generation' => $gen]);

        if (function_exists('wp_cache_get')) {
            $memHit = wp_cache_get($cacheKey, \WPE\Support\AuthorityResolver::CACHE_GROUP);
            if (is_array($memHit)) {
                return $memHit;
            }
        }

        $compute = function () use ($adapted, $page, $limit): array {
            $pageRes = $this->queryCompiler->executeArchivePage($adapted);
            $total = (int)$pageRes['total'];
            $totalPages = $limit > 0 ? (int)ceil($total / $limit) : 1;

            return [
                'ids'         => $pageRes['ids'],
                'total'       => $total,
                'total_pages' => $totalPages,
                'page'        => $page,
                'limit'       => $limit,
            ];
        };

        $result = $this->persistentContextCache
            ? $this->contextCache->remember($cacheKey, $compute)
            : $compute();

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, $result, \WPE\Support\AuthorityResolver::CACHE_GROUP);
        }

        return $result;
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
        if ($pre !== null) {
            return $pre;
        }

        // AC-T19-02: Non-product queries and multi-post-type searches must NOT be touched!
        $postType = $query->get('post_type');
        $isSingleProductType = ($postType === 'product' || (is_array($postType) && count($postType) === 1 && in_array('product', $postType, true)));

        if (!$isSingleProductType && !$query->get('wc_query')) {
            return null;
        }

        // Only the storefront product archive (WooCommerce's product query or the main query) is
        // served from the index. Secondary queries from themes, widgets, sitemaps, other plugins
        // and the WC REST API keep WP_Query semantics (audit LOG-01).
        if (!$query->get('wc_query') && !$query->is_main_query()) {
            return null;
        }

        // 'id=>parent' is answered by core; decide before running any engine query.
        if ((string)$query->get('fields') === 'id=>parent') {
            return null;
        }

        $queryVars = $query->query_vars;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state, as in WC_Query
        if ($query->is_main_query() && (isset($_GET['min_price']) || isset($_GET['max_price']))) {
            $queryVars['wpe_wc_price_filter'] = true;
        }

        // Single-product lookups (/product/<slug>/, ?p=<id>) load a single post shell, not a catalog archive
        if (
            $query->is_singular
            || !empty($queryVars['p'])
            || !empty($queryVars['page_id'])
            || !empty($queryVars['name'])
            || !empty($queryVars['pagename'])
        ) {
            return null;
        }

        // WP-Admin list table queries (edit.php?post_type=product) manage drafts/private/trash across WP_Posts_List_Table
        if (
            function_exists('is_admin') && is_admin()
            && (!function_exists('wp_doing_ajax') || !wp_doing_ajax())
            && !$query->get('wc_query')
        ) {
            return null;
        }

        // Check if query is supported
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

        if (empty($result['ids'])) {
            $query->found_posts = $result['total'];
            $query->max_num_pages = $result['total_pages'];
            return [];
        }

        // Prime post caches first so the shell check below costs no extra query per ID.
        _prime_post_caches($result['ids'], true, true);
        $posts = array_values(array_filter(array_map('get_post', $result['ids'])));

        // An indexed product whose wp_posts shell is gone cannot be rendered. Serving the
        // page anyway yields short or empty pages and wrong totals, so let core answer.
        if (count($posts) !== count($result['ids'])) {
            $this->reportMissingShells($result['ids'], $posts);
            return null;
        }

        // Set pagination totals on WP_Query (R75)
        $query->found_posts = $result['total'];
        $query->max_num_pages = $result['total_pages'];

        if ((string)$query->get('fields') === 'ids') {
            return $result['ids'];
        }

        // Prime native authority probes, native product bundles, and thumbnail attachments in bulk (P028.3)
        \WPE\Support\AuthorityResolver::primeObjects($result['ids'], $this->wpdb);
        \WPE\Compatibility\WooDataStore\ProductDataStore::primeProducts($result['ids'], $this->wpdb);
        $this->primeAttachmentPostsFromBundles($result['ids']);

        return $posts;
    }

    /**
     * Log catalog rows that point at products without a wp_posts shell (once per request).
     *
     * @param int[] $ids
     * @param \WP_Post[] $posts
     */
    private function reportMissingShells(array $ids, array $posts): void
    {
        static $reported = false;
        if ($reported) {
            return;
        }
        $reported = true;

        $found = array_map(static fn($p): int => (int)$p->ID, $posts);
        $missing = array_values(array_diff(array_map('intval', $ids), $found));
        if (function_exists('do_action')) {
            do_action('wpe_catalog_missing_post_shells', $missing);
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostic
        error_log('[WPE] catalog index lists products without a post shell; archive served by core WP_Query. IDs: ' . implode(',', array_slice($missing, 0, 20)));
    }

    /**
     * Bulk-prime WP attachment posts + metadata for featured/gallery images of primed products
     * so storefront get_image() calls execute 0 per-product N+1 queries (P028.3).
     *
     * @param int[] $productIds
     */
    private function primeAttachmentPostsFromBundles(array $productIds): void
    {
        if (!function_exists('wp_cache_get') || !function_exists('_prime_post_caches')) {
            return;
        }
        $attachmentIds = [];
        foreach ($productIds as $pid) {
            $bundle = wp_cache_get('prod_bundle_' . (int)$pid, \WPE\Support\AuthorityResolver::CACHE_GROUP);
            if (!is_array($bundle)) {
                continue;
            }
            if (!empty($bundle['featured']['attachment_id'])) {
                $attachmentIds[] = (int)$bundle['featured']['attachment_id'];
            }
            if (!empty($bundle['gallery']) && is_array($bundle['gallery'])) {
                foreach ($bundle['gallery'] as $g) {
                    if (!empty($g['attachment_id'])) {
                        $attachmentIds[] = (int)$g['attachment_id'];
                    }
                }
            }
        }
        $attachmentIds = array_values(array_unique(array_filter($attachmentIds, static fn(int $id): bool => $id > 0)));
        if ($attachmentIds !== []) {
            _prime_post_caches($attachmentIds, false, true);
        }
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
