# P040 — Storefront query budget: indexed selector signatures, request-scoped authority memo, batched variation hydration

## 1. Goal

Cut the SQL cost of rendering shop/category archives and single product pages on a catalog the size of site1 (15,475 products / 205,044 variations / 221,949 inventory rows), measured on 2026-11-25 with `SAVEQUERIES` and a full template render (no page cache):

| Page | Queries | SQL time | TTFB (HTTP) |
|---|---|---|---|
| `/shop/` | 480 | 751 ms | 0.87–0.90 s |
| Category (9,699 products) | 443 | 688 ms | 0.83–0.85 s |
| Product, 8 variations | 593 | 512 ms | 0.65 s |
| Product, 102 variations | 3,245 | 949 ms | 1.30 s |

Catalog selection itself (P028) costs 34–58 ms; the cost is in rendering:
- `SelectorAdapter` cache-key fingerprints read `wpe_inventory WHERE owner_id = %d OR owner_id IN (SELECT …)`, which MariaDB runs as a full scan (`type=ALL`, ~222k rows, ~50 ms per call): 13 calls per archive page, 9–10 per product page.
- `getProductCacheKey()` is not memoized within a request (8–10 identical calls per product page).
- `MetaBridge::isNativeAuthority()` runs for every `get_post_metadata` call, including attachments and menu items, and queries `wpe_object_state` each time; `AuthorityResolver::probeObject()` reads its cache but never writes it. 346 queries per archive page for 29 distinct non-product IDs; about 1,900 queries on the 102-variation page.
- Each variation read by `wc_get_product()` (the theme gallery loops over all variations) costs about 30 queries, and `wpe_meta` is read one key at a time.

Targets (site1, same pages): archive pages ≤ 150 queries and ≤ 0.2 s TTFB after P040.1; product pages ≤ 150 queries regardless of variation count after P040.2.

## 2. Scope

In scope:
- P040.1 (low risk, no behavior change):
  - Rewrite both inventory signature subqueries in `SelectorAdapter` as a join against a derived ID list (product ID `UNION ALL` its variant IDs), so `wpe_inventory` is read by primary key. The signature string stays identical.
  - Memoize `getProductCacheKey()` per request like `lowestKeyMemo`; `invalidateCache()` clears it.
  - `MetaBridge::isNativeAuthority()` returns `false` without SQL when the post cache already holds the object and its `post_type` is neither `product` nor `product_variation`. Native authority only ever applies to products and variations (docs/02 §4).
  - `AuthorityResolver::probeObject()` stores its result in the non-persistent `wpe_runtime` group only in read-only storefront requests (front-end `GET`/`HEAD`, not admin, AJAX, cron or WP-CLI; overridable by the `wpe_authority_request_memo` filter). Eviction stays as is (`clean_post_cache`, `evictObject`, `flushAll`).
  - `MetaBridge::isNativeAuthority()` reads object state through `probeObject()` so it shares that memo.
- P040.2:
  - When a variable product's children are read, bulk-prime the variation rows, their inventory, variant values, media, links, downloads and `wpe_meta` in `IN (...)` queries, and reuse the parent row instead of re-reading it per variation.
  - `MediaRepository::getMeta()` loads all meta of an object once per request instead of one key at a time.
- Integration tests with query budgets and correctness checks; before/after numbers in NOTES.

Out of scope:
- Theme (StarterKit) changes, WordPress/WooCommerce core changes.
- Replacing read-time fingerprints with write-time version counters (follow-up).
- Count caching for `executeCount` on hosts without a persistent object cache.

## 3. Decisions

- **D01** — The inventory signature keeps the same content and order (`owner_id` ascending over the product row and its variants), so existing cache keys stay valid after deploy.
- **D02** — The probe memo is request-scoped and limited to read-only storefront requests. Admin, REST, cron and CLI paths write `wpe_object_state` from places that do not all call `evictObject()` (LegacyMirror, CutoverRunner, ProductEditorService), so caching there could serve a stale authority within the same process.
- **D03** — The post-type gate only skips objects whose post is already in the object cache; an object with no cached post goes through the full check, so native variations without a shell are unaffected.
- **D04** — Measurements are on site1 and `test_wpe_bench`; this is not a WooCommerce integration certification or a 100k/1M scale gate.

## 4. Documentation changes

- `docs/NOTES.md` (P040 measurements and summary)
- `docs/plans/P040-storefront-query-budget/plan.md`
- `docs/plans/P040-storefront-query-budget/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P040.1",
      "title": "Indexed inventory signatures, per-request product cache key memo, and request-scoped authority probe memo with a non-product post-type gate",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Support/AuthorityResolver.php",
        "src/Compatibility/WooDataStore/MetaBridge.php"
      ],
      "files": [
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Support/AuthorityResolver.php",
        "src/Compatibility/WooDataStore/MetaBridge.php",
        "tests/integration/StorefrontQueryBudgetTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/StoreApi/SelectorAdapter.php"],
        ["php", "-l", "src/Support/AuthorityResolver.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/MetaBridge.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/StorefrontQueryBudgetTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/AutoSelectFirstValuesTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/PdpUrlSelectionTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CohortRoutingDataStoreTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P040.2",
      "title": "Bulk-prime variation hydration when children are read and load wpe_meta per object once per request",
      "depends_on": ["P040.1"],
      "read_first": [
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "src/Infrastructure/Database/MediaRepository.php"
      ],
      "files": [
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "src/Infrastructure/Database/MediaRepository.php",
        "tests/integration/StorefrontQueryBudgetTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/WooDataStore/ProductDataStore.php"],
        ["php", "-l", "src/Infrastructure/Database/MediaRepository.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/StorefrontQueryBudgetTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CohortRoutingDataStoreTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
