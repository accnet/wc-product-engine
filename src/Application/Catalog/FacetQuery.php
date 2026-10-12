<?php
declare(strict_types=1);

namespace WPE\Application\Catalog;

use InvalidArgumentException;

/**
 * FacetQuery — Multi-dimensional faceted search and counting engine.
 *
 * Implements requirements:
 * - R71: Impossible combination filter (variant-coherent mode requires matching variation).
 * - R72: Distinct parent counts (products with multiple variations counted exactly once).
 * - R73: Disjunctive facet counts with strict self-exclusion.
 * - R74: Category hierarchy descendants expansion with term version/deletion safety.
 * - AC-T18-01: Black-M/White-L does not match Black-L in coherent mode; counts do not double.
 */
class FacetQuery
{
    public const MODE_COHERENT = 'variant-coherent';
    public const MODE_COMPATIBLE = 'wc-compatible';

    private \wpdb $wpdb;
    private CatalogProjector $projector;

    private string $tableCatalog;
    private string $tableCatalogFacets;

    public function __construct(?\wpdb $wpdb = null, ?CatalogProjector $projector = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->projector = $projector ?? new CatalogProjector($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableCatalog       = $prefix . 'wpe_catalog';
        $this->tableCatalogFacets = $prefix . 'wpe_catalog_facets';
    }

    /**
     * Resolves category slugs including all valid descendants in the taxonomy hierarchy (R74).
     *
     * @param array|string $categories Array or single slug/ID
     * @return string[] Array of unique category slugs
     */
    public function resolveCategorySlugs(array|string $categories): array
    {
        $input = is_array($categories) ? $categories : [$categories];
        $resolvedSlugs = [];

        foreach ($input as $cat) {
            $catStr = trim((string)$cat);
            if ($catStr === '') {
                continue;
            }

            // Look up term
            $term = is_numeric($catStr)
                ? get_term((int)$catStr, 'product_cat')
                : get_term_by('slug', $catStr, 'product_cat');

            if (!$term || is_wp_error($term)) {
                // If term is not in WP taxonomy, retain string as fallback
                $resolvedSlugs[] = $catStr;
                continue;
            }

            $resolvedSlugs[] = $term->slug;

            // Expand descendants if hierarchy exists
            if (function_exists('get_term_children')) {
                $children = get_term_children($term->term_id, 'product_cat');
                if (!is_wp_error($children) && is_array($children)) {
                    foreach ($children as $childId) {
                        $childTerm = get_term($childId, 'product_cat');
                        // Exclude deleted or invalid terms (R74)
                        if ($childTerm && !is_wp_error($childTerm) && !empty($childTerm->slug)) {
                            $resolvedSlugs[] = $childTerm->slug;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($resolvedSlugs));
    }

    /**
     * Executes a catalog query respecting facet mode (R71, AC-T18-01).
     *
     * @param array $args
     * @return array Query results with product IDs and metadata
     */
    public function queryProducts(array $args = []): array
    {
        $gen = isset($args['generation']) ? (int)$args['generation'] : $this->projector->getActiveGeneration();
        $mode = $args['facet_mode'] ?? self::MODE_COMPATIBLE;
        $limit = isset($args['limit']) ? min(max(1, (int)$args['limit']), 100) : 48;

        [$whereClauses, $params] = $this->compileFacetFilters($args, $mode, null, $gen);

        $sql = "SELECT c.product_id, c.type, c.status, c.visibility, c.in_stock,
                       c.featured, c.on_sale, c.min_price, c.max_price, c.title_sort
                FROM {$this->tableCatalog} c
                WHERE " . implode(' AND ', $whereClauses) . "
                GROUP BY c.product_id
                ORDER BY (c.min_price IS NULL) ASC, c.min_price ASC, c.product_id ASC
                LIMIT %d";

        $params[] = $limit;
        $prepared = !empty($params) ? $this->wpdb->prepare($sql, ...$params) : $sql;
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return [
            'facet_mode' => $mode,
            'generation' => $gen,
            'count'      => count($rows),
            'items'      => $rows,
            'product_ids' => array_map(fn($r) => (int)$r['product_id'], $rows),
        ];
    }

    /**
     * Computes disjunctive facet counts with self-exclusion (R72, R73, AC-T18-01).
     *
     * @param array $args Current active filters
     * @param array $requestedFacets List of facet keys to return counts for
     * @return array Formatted facet groups with counts
     */
    public function computeFacets(array $args = [], array $requestedFacets = []): array
    {
        $gen = isset($args['generation']) ? (int)$args['generation'] : $this->projector->getActiveGeneration();
        $mode = $args['facet_mode'] ?? self::MODE_COMPATIBLE;

        if (empty($requestedFacets)) {
            // Auto-discover distinct facet keys in the current generation
            $keys = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT DISTINCT facet_key FROM {$this->tableCatalogFacets} WHERE generation = %d",
                $gen
            ));
            $requestedFacets = array_filter($keys, fn($k) => !str_ends_with((string)$k, '_id'));
        }

        $results = [
            'facet_mode' => $mode,
            'generation' => $gen,
            'facets'     => [],
        ];

        foreach ($requestedFacets as $facetKey) {
            $facetKeyClean = sanitize_key((string)$facetKey);

            // Compute counts for this facet, excluding its own filter (self-exclusion, R73)
            [$whereClauses, $params] = $this->compileFacetFilters($args, $mode, $facetKeyClean, $gen);

            // Join catalog_facets for the target facet
            $sql = "SELECT f.value_key, COUNT(DISTINCT c.product_id) as product_count
                    FROM {$this->tableCatalog} c
                    INNER JOIN {$this->tableCatalogFacets} f
                        ON (f.generation = c.generation AND f.product_id = c.product_id AND f.facet_key = %s)
                    WHERE " . implode(' AND ', $whereClauses) . "
                    GROUP BY f.value_key
                    ORDER BY product_count DESC, f.value_key ASC";

            $fullParams = array_merge([$facetKeyClean], $params);
            $prepared = $this->wpdb->prepare($sql, ...$fullParams);
            $rows = $this->wpdb->get_results($prepared, ARRAY_A);

            $facetValues = [];
            foreach ($rows as $row) {
                $valKey = (string)$row['value_key'];
                $facetValues[] = [
                    'value' => $valKey,
                    'label' => $this->getFacetValueLabel($facetKeyClean, $valKey),
                    'count' => (int)$row['product_count'], // Distinct parent count (R72)
                ];
            }

            $results['facets'][$facetKeyClean] = $facetValues;
        }

        return $results;
    }

    /**
     * Compiles SQL WHERE clauses and parameters for faceted search.
     *
     * @param array $args Filter arguments
     * @param string $mode Facet mode (coherent vs compatible)
     * @param string|null $excludeFacetKey Facet key to self-exclude
     * @param int $gen Catalog index generation
     * @return array [array $whereClauses, array $params]
     */
    public function compileFacetFilters(array $args, string $mode, ?string $excludeFacetKey = null, int $gen = 1): array
    {
        $where = ["c.generation = %d"];
        $params = [$gen];

        // Status & Visibility
        if (isset($args['status'])) {
            $where[] = "c.status = %s";
            $params[] = (string)$args['status'];
        } else {
            $where[] = "c.status = 'publish'";
        }

        if (isset($args['visibility'])) {
            $where[] = "c.visibility = %s";
            $params[] = (string)$args['visibility'];
        } else {
            $where[] = "c.visibility IN ('visible', 'catalog')";
        }

        // Stock filter
        if (isset($args['in_stock'])) {
            $where[] = "c.in_stock = %d";
            $params[] = $args['in_stock'] ? 1 : 0;
        }

        // Featured filter
        if (isset($args['featured'])) {
            $where[] = "c.featured = %d";
            $params[] = $args['featured'] ? 1 : 0;
        }

        // On sale filter
        if (isset($args['on_sale'])) {
            $where[] = "c.on_sale = %d";
            $params[] = $args['on_sale'] ? 1 : 0;
        }

        // Price range
        if (isset($args['min_price'])) {
            $where[] = "c.min_price >= %f";
            $params[] = (float)$args['min_price'];
        }
        if (isset($args['max_price'])) {
            $where[] = "c.min_price <= %f";
            $params[] = (float)$args['max_price'];
        }

        // Category filter (Hierarchy expansion, R74)
        if ($excludeFacetKey !== 'category' && !empty($args['category'])) {
            $catSlugs = $this->resolveCategorySlugs($args['category']);
            if (!empty($catSlugs)) {
                $placeholders = implode(', ', array_fill(0, count($catSlugs), '%s'));
                $where[] = "EXISTS (
                    SELECT 1 FROM {$this->tableCatalogFacets} f_cat
                    WHERE f_cat.generation = c.generation
                      AND f_cat.product_id = c.product_id
                      AND f_cat.facet_key = 'category'
                      AND f_cat.value_key IN ({$placeholders})
                )";
                foreach ($catSlugs as $slug) {
                    $params[] = $slug;
                }
            }
        }

        // Tag filter
        if ($excludeFacetKey !== 'tag' && !empty($args['tag'])) {
            $tags = is_array($args['tag']) ? $args['tag'] : [$args['tag']];
            $placeholders = implode(', ', array_fill(0, count($tags), '%s'));
            $where[] = "EXISTS (
                SELECT 1 FROM {$this->tableCatalogFacets} f_tag
                WHERE f_tag.generation = c.generation
                  AND f_tag.product_id = c.product_id
                  AND f_tag.facet_key = 'tag'
                  AND f_tag.value_key IN ({$placeholders})
            )";
            foreach ($tags as $t) {
                $params[] = (string)$t;
            }
        }

        // Attribute filters
        $attributes = $args['attributes'] ?? [];
        if (!empty($attributes) && is_array($attributes)) {
            // Filter out the excluded facet key for self-exclusion
            $activeAttrs = [];
            foreach ($attributes as $attrKey => $attrVals) {
                $cleanKey = sanitize_key((string)$attrKey);
                if ($cleanKey === $excludeFacetKey) {
                    continue;
                }
                $vals = is_array($attrVals) ? $attrVals : [(string)$attrVals];
                $cleanVals = array_values(array_filter(array_map('sanitize_key', $vals)));
                if (!empty($cleanVals)) {
                    $activeAttrs[$cleanKey] = $cleanVals;
                }
            }

            if (!empty($activeAttrs)) {
                if ($mode === self::MODE_COHERENT && count($activeAttrs) > 1) {
                    // R71, AC-T18-01: Variant-coherent mode requires variation to satisfy all attributes simultaneously
                    [$coherentSql, $coherentParams] = $this->compileCoherentAttributesFilter($activeAttrs);
                    $where[] = $coherentSql;
                    $params = array_merge($params, $coherentParams);
                } else {
                    // WC-compatible mode or single attribute: separate EXISTS for each facet
                    foreach ($activeAttrs as $attrKey => $attrVals) {
                        $placeholders = implode(', ', array_fill(0, count($attrVals), '%s'));
                        $where[] = "EXISTS (
                            SELECT 1 FROM {$this->tableCatalogFacets} f_attr
                            WHERE f_attr.generation = c.generation
                              AND f_attr.product_id = c.product_id
                              AND f_attr.facet_key = %s
                              AND f_attr.value_key IN ({$placeholders})
                        )";
                        $params[] = $attrKey;
                        foreach ($attrVals as $val) {
                            $params[] = $val;
                        }
                    }
                }
            }
        }

        return [$where, $params];
    }

    /**
     * Compiles variant-coherent SQL for multiple attribute filters (R71, AC-T18-01).
     *
     * Ensures that for variable products, there exists a specific variation (variant_id > 0)
     * matching all specified attributes concurrently, or for simple products, all match at variant_id = 0.
     */
    private function compileCoherentAttributesFilter(array $activeAttrs): array
    {
        $joins = [];
        $params = [];
        $conditions = [
            "f_base.generation = c.generation",
            "f_base.product_id = c.product_id",
            "(c.type != 'variable' OR f_base.variant_id > 0)",
        ];

        $idx = 0;
        $baseAlias = 'f_base';

        foreach ($activeAttrs as $attrKey => $attrVals) {
            $idx++;
            $alias = $idx === 1 ? $baseAlias : "f_attr_{$idx}";
            $placeholders = implode(', ', array_fill(0, count($attrVals), '%s'));

            if ($idx > 1) {
                $joins[] = "INNER JOIN {$this->tableCatalogFacets} {$alias}
                            ON ({$alias}.generation = f_base.generation
                                AND {$alias}.product_id = f_base.product_id
                                AND {$alias}.variant_id = f_base.variant_id)";
            }

            $conditions[] = "{$alias}.facet_key = %s AND {$alias}.value_key IN ({$placeholders})";
            $params[] = $attrKey;
            foreach ($attrVals as $v) {
                $params[] = $v;
            }
        }

        // Subquery ensuring coherent match on same variant_id (for variable) OR variant_id = 0 (for simple)
        $sql = "EXISTS (
            SELECT 1
            FROM {$this->tableCatalogFacets} {$baseAlias}
            " . implode(' ', $joins) . "
            WHERE " . implode(' AND ', $conditions) . "
        )";

        return [$sql, $params];
    }

    /**
     * Gets a human-readable label for a facet value.
     */
    private function getFacetValueLabel(string $facetKey, string $valueKey): string
    {
        if ($facetKey === 'category') {
            $term = get_term_by('slug', $valueKey, 'product_cat');
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        } elseif ($facetKey === 'tag') {
            $term = get_term_by('slug', $valueKey, 'product_tag');
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        } else {
            // Check attribute term
            $taxonomy = str_starts_with($facetKey, 'pa_') ? $facetKey : 'pa_' . $facetKey;
            $term = get_term_by('slug', $valueKey, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        }

        return ucfirst(str_replace(['-', '_'], ' ', $valueKey));
    }
}
