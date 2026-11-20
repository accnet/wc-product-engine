# P028 — Eliminate storefront N+1 hydration and filesorts on native catalog queries

## 1. Goal

Eliminate the extreme N+1 SQL amplification during WooCommerce storefront loop/product hydration and remove full-generation `Using filesort` scans on `wpe_catalog` so that Shop archives, filtered listings, and single variable product pages execute in bounded $O(1)$ query counts and ordered B-Tree index scans suitable for 40k+ product catalogs.

Baseline measurements recorded on `site1.localhost` (`native_bridge`, 71 products / 6,064 variations) on 2026-11-21 before P028:
- **Shop archive (12 variable products / ~900 child variations):** `20,147` SQL queries / `4,113.52 ms` (`10` SQL queries for `wpe_catalog` selection, followed by `20,137` N+1 hydration queries during `wc_get_product()`, `get_price_html()`, and `get_image()`).
- **Single variable product (`ID 387`, 74 variations):** `1,659` SQL queries / `286.99 ms`.
- **`EXPLAIN` on default Shop sort (`menu_order`) and price sorts:** `Using where; Using filesort` across all rows of the active generation due to unconditional `GROUP BY c.product_id`, `(c.min_price IS NULL) ASC` expression sorting, and missing workload indexes on `menu_order`, `rating_average`, `title_sort`, and `(generation, status, min_price, product_id)`.
- **`wpe_catalog_facets`:** `0` rows projected for native options stored in `wpe_options` / `wpe_option_values` / `wpe_variant_values`, and `CatalogQuery::executeCount()` omitted `tag`, `attributes`, and `search` filters.

Target after P028:
- Shop archive (12 variable products + price HTML + thumbnails): **<= 30 SQL queries** (< 99.8% reduction) and **< 80 ms** cold / faster warm.
- Single variable product (74 variations + price HTML): **<= 25 SQL queries** (< 98.5% reduction) and **< 40 ms**.
- `EXPLAIN` on default Shop sort (`menu_order`), `date_desc`, `popularity`, `rating`, `title_asc`, and `price_desc` / `price_asc` (non-null fast path): **zero `Using filesort`** and **zero `Using temporary`**.
- Full native option/variant facet projection into `wpe_catalog_facets` with accurate filtered `executeCount()`.

## 2. Scope

In scope:
- Direct single-query / batch-primed `ProductVariableDataStore::read_price_data()` reading active published variant prices from `wpe_variants` (respecting `sale_from`/`sale_to` schedules) without instantiating `WC_Product_Variation` objects in a loop.
- Lightweight parent hydration in `ProductDataStore::read()` + bulk `ProductDataStore::primeProducts(int[] $ids)` preloading `wpe_products`, `wpe_inventory`, featured/gallery `wpe_product_media` + `wpe_assets`, `wpe_product_links`, `wpe_meta`, `wpe_downloads`, child IDs/prices, and default attributes in bulk `WHERE ... IN (...)` queries.
- Batched variant inventory lookup (`WHERE owner_id IN (...)`) in `ProductRepository::hydrateProductAggregate()`.
- Non-persistent per-request runtime cache (`wpe_runtime` cache group, automatically invalidated on `clean_post_cache`, `wp_cache_flush`, and repository/datastore/bridge writes) in `AuthorityResolver` and `ProductDataStore`, plus `get_post($id)` memory inspection before querying `wp_posts`.
- `SchemaMigrator` (schema version `3`) idempotent index migration adding `idx_cat_menu_order`, `idx_cat_rating`, `idx_cat_title`, and `idx_cat_price` on `wpe_catalog` (authorized by `docs/03-database-schema.md` §4 without modifying sealed `contracts/`).
- `CatalogQuery` optimization: remove redundant `GROUP BY c.product_id` by converting facet joins to `EXISTS` semi-joins (preserving same-variant multi-attribute matching), optimize `price_desc` and two-leg `price_asc` null-last B-Tree traversal to avoid `(c.min_price IS NULL)` filesorts, unify filter predicates between `compileQuery()` and `executeCount()` (fixing `tag`, `attributes`, and `search` counts), and allow prefix index usage on `normalized_sku`.
- `CatalogProjector` enhancement: project product options and variant option-values from `wpe_options` / `wpe_option_values` / `wpe_variant_values` into `wpe_catalog_facets`.
- `CatalogAdapters` integration: prime `AuthorityResolver` and `ProductDataStore` in bulk for archive result IDs and wrap `executeAdaptedQuery()` with `ContextCache` when appropriate.
- Isolated regression re-certification for all 8 touched roadmap tasks (`T05`, `T06`, `T10`, `T11`, `T12`, `T16`, `T17`, `T19`) and live before/after verification on `site1.localhost`.

Out of scope:
- Modifying WordPress or WooCommerce Core files.
- Editing sealed files under `contracts/` or `docs/01..10`.
- External Redis/Memcached daemon installation.

## 3. Decisions

- **D01: Non-persistent `wpe_runtime` object cache group with strict write eviction.** Register `wpe_runtime` via `wp_cache_add_non_persistent_groups()` and hook `clean_post_cache` so per-request caches never leak stale data across requests or across in-process writes (`ProductRepository`, `ProductDataStore`, `MetaBridge`, `InventoryBridge`).
- **D02: Direct SQL price matrix in `read_price_data()`.** `ProductVariableDataStore::read_price_data()` reads `(id, regular_price, sale_price, sale_from, sale_to)` directly from primed memory or a single indexed query on `wpe_variants` (`idx_product_status_pos_id`), computing active sale schedule timestamps in UTC and formatting decimals identically to WooCommerce.
- **D03: Workload indexes via `SchemaMigrator` v3.** Bump `SchemaMigrator` schema version from `2` to `3` and add missing composite sort indexes on `{prefix}wpe_catalog` via idempotent `SHOW INDEX` checks inside `SchemaMigrator::migrate()`, keeping `contracts/database/schema.sql` untouched.
- **D04: `EXISTS` semi-joins and two-leg `price_asc` index scan.** Replacing `INNER JOIN wpe_catalog_facets ... GROUP BY c.product_id` with correlated `EXISTS (...)` preserves uniqueness of `(generation, product_id)` on `wpe_catalog`, letting MariaDB stream rows directly in index order and stop at `LIMIT`. For `price_asc`, scanning `min_price IS NOT NULL ORDER BY min_price ASC, product_id ASC LIMIT K` first and topping up with `min_price IS NULL ORDER BY product_id ASC` only when `< K` rows return preserves exact nulls-last semantics (`R68`, `R70`) with zero `filesort`.
- **D05: Native option facets in `CatalogProjector`.** Alongside taxonomy terms and `extra_props.attributes`, `CatalogProjector` projects active `wpe_variant_values` joined with `wpe_options` and `wpe_option_values` (both `pa_<key>` / option `key` and `value_key`) at variant level (`variant_id = v.id`).

## 4. Documentation changes

- `docs/NOTES.md` (record P028 benchmark metrics, index additions, and verification summary)
- `docs/plans/P028-query-hydration-performance/plan.md`
- `docs/plans/P028-query-hydration-performance/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P028.1",
      "title": "Eliminate N+1 hydration in ProductDataStore, ProductRepository, and AuthorityResolver with bulk priming and clean_post_cache eviction",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Support/AuthorityResolver.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "src/Compatibility/WooDataStore/CohortRoutingDataStore.php"
      ],
      "files": [
        "src/Support/AuthorityResolver.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "src/Compatibility/WooDataStore/CohortRoutingDataStore.php",
        "src/Compatibility/WooDataStore/MetaBridge.php",
        "src/Compatibility/WooDataStore/InventoryBridge.php",
        "tests/integration/QueryHydrationPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Support/AuthorityResolver.php"],
        ["php", "-l", "src/Infrastructure/Database/ProductRepository.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/ProductDataStore.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/CohortRoutingDataStore.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/MetaBridge.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/InventoryBridge.php"],
        ["php", "-l", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P028.2",
      "title": "Add wpe_catalog sort indexes (SchemaMigrator v3), zero-filesort EXISTS/B-Tree compilation in CatalogQuery, and native option facets in CatalogProjector",
      "depends_on": ["P028.1"],
      "read_first": [
        "docs/03-database-schema.md",
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Application/Catalog/CatalogProjector.php",
        "src/Application/Catalog/CatalogQuery.php"
      ],
      "files": [
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Application/Catalog/CatalogProjector.php",
        "src/Application/Catalog/CatalogQuery.php",
        "tests/integration/CatalogIndexOptimizationTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Infrastructure/Database/SchemaMigrator.php"],
        ["php", "-l", "src/Application/Catalog/CatalogProjector.php"],
        ["php", "-l", "src/Application/Catalog/CatalogQuery.php"],
        ["php", "-l", "tests/integration/CatalogIndexOptimizationTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P028.3",
      "title": "Wire bulk archive priming and ContextCache in CatalogAdapters and verify end-to-end query budgets",
      "depends_on": ["P028.2"],
      "read_first": [
        "src/Application/Catalog/CatalogAdapters.php",
        "src/Application/Catalog/ContextCache.php"
      ],
      "files": [
        "src/Application/Catalog/CatalogAdapters.php",
        "tests/integration/QueryHydrationPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Catalog/CatalogAdapters.php"],
        ["php", "-l", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P028.4",
      "title": "Re-certify T05/T06/T10/T11/T12/T16/T17/T19 on isolated root, rebuild live facets/indexes, benchmark before/after, update NOTES and report",
      "depends_on": ["P028.3"],
      "read_first": [
        "docs/07-testing.md",
        "docs/plans/README.md"
      ],
      "files": [
        "docs/evidence/T05/result.json",
        "docs/evidence/T06/result.json",
        "docs/evidence/T10/result.json",
        "docs/evidence/T11/result.json",
        "docs/evidence/T12/result.json",
        "docs/evidence/T16/result.json",
        "docs/evidence/T17/result.json",
        "docs/evidence/T19/result.json",
        "docs/NOTES.md",
        "docs/plans/P028-query-hydration-performance/report.md"
      ],
      "dod": [
        ["node", "scripts/verify-task.mjs", "T05"],
        ["node", "scripts/verify-task.mjs", "T06"],
        ["node", "scripts/verify-task.mjs", "T10"],
        ["node", "scripts/verify-task.mjs", "T11"],
        ["node", "scripts/verify-task.mjs", "T12"],
        ["node", "scripts/verify-task.mjs", "T16"],
        ["node", "scripts/verify-task.mjs", "T17"],
        ["node", "scripts/verify-task.mjs", "T19"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
