# Report

## Summary

Completed **P028 — Eliminate storefront N+1 hydration and filesorts on native catalog queries** across `P028.1`–`P028.4`, eliminating per-variation hydration amplification in `ProductDataStore` / `ProductRepository` / `AuthorityResolver`, adding workload B-Tree sort indexes on `wpe_catalog` (`SchemaMigrator` schema version `3`), removing `GROUP BY` + `(min_price IS NULL)` filesorts in `CatalogQuery`, projecting native `wpe_options` / `wpe_option_values` / `wpe_variant_values` into `wpe_catalog_facets`, and wiring bulk archive priming + `ContextCache` isolation in `CatalogAdapters`.

### 1. Delivered Changes

1. **Zero-N+1 Hydration & Bulk Archive Priming (`P028.1`, `P028.3`):**
   - `ProductVariableDataStore::read_price_data()` now reads published variation prices directly from primed memory or a single indexed `wpe_variants` query via `EffectivePriceResolver` (preserving UTC `sale_from`/`sale_to` schedules and display tax formatting) without instantiating `WC_Product_Variation` objects in a loop.
   - Removed duplicate `read_children()` invocation in `ProductVariableDataStore::read()`.
   - Added `ProductDataStore::primeProducts(int[] $ids)` (8 bulk `IN(...)` queries across `wpe_products`, `wpe_inventory`, `wpe_product_media` + `wpe_assets`, `wpe_product_links`, `wpe_meta`, `wpe_downloads`, `wpe_options` + `wpe_option_values`, and `wpe_variants`) and `AuthorityResolver::primeObjects(int[] $ids)` backed by the non-persistent `wpe_runtime` cache group.
   - Fixed `AuthorityResolver::resolveObjectAuthority()` to recognize `stdClass` objects cached by `_prime_post_caches()` in `'posts'` and folded `wp_posts.post_type` into `probeObject()`, eliminating 4 redundant `wp_posts` lookups per product.
   - Synchronized `AuthorityResolver::evictObject()` and `flushAll()` with `clean_post_cache` and WooCommerce 10's `ProductCache` container service so in-process writes (`ProductRepository`, `ProductDataStore`, `MetaBridge`, `InventoryBridge`) evict cached instances immediately.
   - Batched variant inventory lookups (`WHERE owner_id IN (...)`) in `ProductRepository::hydrateProductAggregate()`.
   - Wired `_prime_post_caches()`, `AuthorityResolver::primeObjects()`, `ProductDataStore::primeProducts()`, thumbnail attachment post priming, and `ContextCache` key isolation inside `CatalogAdapters`.

2. **Workload Sort Indexes, Zero-Filesort Compiler & Native Facets (`P028.2`):**
   - Bumped `SchemaMigrator` to schema version `3` and added idempotent `ensureCatalogWorkloadIndexes()` creating `idx_cat_menu_order`, `idx_cat_rating`, `idx_cat_title`, and `idx_cat_price` on `{prefix}wpe_catalog` (authorized by `03 §4`, leaving sealed `contracts/` untouched).
   - Replaced `INNER JOIN wpe_catalog_facets ... GROUP BY c.product_id` in `CatalogQuery` with PK-covering `EXISTS` semi-joins (enforcing same-variant `fa2.variant_id = fa1.variant_id OR fa1.variant_id = 0 OR fa2.variant_id = 0` on multi-attribute filters), unified `compileQuery()` and `executeCount()` predicates (`tag`, `attributes`, `search`), and implemented reverse B-Tree `price_desc` + two-leg B-Tree `price_asc` (`execute()` and `executeArchivePage()`) with zero `Using filesort` and zero `Using temporary`.
   - Fixed `CatalogProjector::projectProduct()` to bind SQL `NULL` literals properly (`min_price`, `max_price`, `image_asset_id`, `next_price_change`) instead of `%s` empty strings, and projected native product/variant options from `wpe_options`, `wpe_option_values`, and `wpe_variant_values` into `wpe_catalog_facets`.

### 2. Before vs After Measurements (`site1.localhost`, `native_bridge`, 71 Products / 6,064 Variations)

| Surface / Check | Before P028 | After P028 | Improvement |
|---|---|---|---|
| Shop archive (`12` variable products / ~`900` variations, cold) | `20,147` SQL / `4,113.52 ms` | `166` SQL / `96.83 ms` | `-99.18%` SQL, `42.5x` faster |
| Shop archive (`12` variable products / ~`900` variations, warm) | `20,137+` SQL | `108` SQL / `36.72 ms` | `-99.46%` SQL, `112.0x` faster |
| Isolated `WP_Query` + archive loop (`3` variable products / `45` variations) | `~750+` SQL | `31` SQL (`3` in primed loop) | Bounded $O(1)$ bulk queries |
| Single variable product (`ID 387`, `74` variations, cold) | `1,659` SQL / `286.99 ms` | `35` SQL / `9.75 ms` | `-97.89%` SQL, `29.4x` faster |
| `EXPLAIN` across 7 storefront sorts (`menu_order`, `date_desc`, `popularity`, `rating`, `title_asc`, `price_desc`, `price_asc`) | `Using where; Using filesort` | `0 Using filesort`, `0 Using temporary` | Pure B-Tree index order |
| `wpe_catalog_facets` active generation rows (`gen 190`) | `0` rows | `36,370` rows (`897.45 ms` full rebuild) | Full native option/variant coverage |
| Live HTTP `GET /shop/` full response | `> 4.1 s` | `HTTP 200` in `0.249 s` | Full theme + WC render |

### 3. Verification & Scope Note

- Isolated regression database (`test_wpe_regress`): `QueryHydrationPerformanceTest.php`, `CatalogIndexOptimizationTest.php`, `T48Test.php`, and refreshed evidence receipts for all 8 touched roadmap tasks (`T05`, `T06`, `T10`, `T11`, `T12`, `T16`, `T17`, `T19`) passed (`total: 0` residue).
- Live `site1.localhost` measurements reflect actual catalog size (`71` products / `6,064` variations on MariaDB `11.8.9`); this is not a 100k/1M dataset `G09` scale gate certification.
