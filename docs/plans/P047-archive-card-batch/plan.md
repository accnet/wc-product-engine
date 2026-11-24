# P047 — Archive product cards: batched price fingerprints, lowest-price inputs, gallery and owned meta

## 1. Goal

After P040, a shop or category page (12 variable products) still runs about 134 queries. Most of them are repeated once per product card (site1, 2026-11-25, `SAVEQUERIES` with the storefront request memo):

| Per-card query | Count | Caller |
|---|---|---|
| Lowest-price cache-key fingerprint | 12–13 | `SelectorAdapter::computeLowestPriceCacheKey()` from `woocommerce_variable_price_html` |
| Candidate variants + inventory | 12 | `VariantResolver::getCandidateVariants()` from `resolveLowestPurchasableVariation()` on a lowest-price cache miss |
| Parent inventory | 12 | `VariantResolver::getParentInventory()` on the same path |
| Gallery rows | 12 | `GalleryAdapter::filterProductGalleryImageIds()` → `MediaRepository::getGallery()` |
| Owned meta (`_thumbnail_id`): variation check, product row, featured image | 3 × 12 | `MetaBridge::getOwnedProperty()` |

The archive already primes every card's product bundle (`ProductDataStore::primeProducts()`: product row, inventory, featured and gallery media, links). The goal is to replace each per-card query with one query per page, or with the primed bundle, so a 12-card archive page drops by about 60–90 queries with identical output.

## 2. Scope

In scope:
- `SelectorAdapter`:
  - On the first lowest-price key lookup of a request, compute the keys of every product in the main query at once: one query for the product and variant signatures, one for the inventory signature pieces. Keys are byte-identical to `computeLowestPriceCacheKey()`.
  - On the first lowest-price cache miss of a read-only storefront request (`AuthorityResolver::isRequestMemoEnabled()`), load candidate variants and parent inventory for the main-query variable products whose lowest price is not already cached (one query each) and pass them to `resolveLowestPurchasableVariation()`. Products found in the object cache during that check are kept in memory, so they are not fetched twice.
- `VariantResolver`: public bulk loaders that return exactly what `getCandidateVariants($id, false)` and `getParentInventory($id)` return, per product.
- `GalleryAdapter::filterProductGalleryImageIds()` and `MetaBridge::getOwnedProperty()` use the primed product bundle when one exists. Without a bundle they behave as before. `ProductDataStore` exposes the bundle read-only via `primedBundle()`.
- `tests/integration/ArchiveCardBatchTest.php`:
  - bulk keys equal the per-product keys, including a product with no native row and products with no inventory rows;
  - bulk inputs equal the per-product loaders;
  - owned meta and gallery IDs from the bundle equal the direct reads;
  - an archive loop of the fixture products issues no per-card fingerprint, candidate, parent-inventory, gallery or owned-meta queries;
  - rendered price HTML is unchanged.
- Live before/after measurement on site1 in NOTES.

Out of scope: `executeCount`, theme/PHP render time, the single product page.

## 3. Decisions

- **D01** — The bulk inventory signature is assembled in PHP from per-row pieces that MySQL formats with the same concatenation expression and columns, sorted by `owner_id`, joined with `|`, and `null` when there are no rows. This keeps the bytes identical without the per-product derived table, which MariaDB cannot correlate.
- **D02** — Key priming is a pure read with the same request lifetime as the existing `lowestKeyMemo`, so it is not gated. Preloading candidate inputs is gated on read-only storefront requests, like the P040 request caches.
- **D03** — A missing parent inventory row is passed to the resolver as `[]`. It skips the re-query and is treated like the former `null` result, because the resolver only tests it for truthiness after loading.
- **D04** — Bundle-backed owned meta covers products only. A primed bundle exists only for product IDs, so variations keep the direct reads.
- **D05** — The shared `test_wpe_bench` keeps whatever `cohort_mode` the last test left. T26 (and T37) pass only under `cohort_canary`, and T11 only under `native_bridge`, with or without this change (checked on the old code). T40Test leaves `cohort_canary` behind. The DoD runs T26 with the test DB set to `cohort_canary`, and the other tests with `native_bridge`. Fixing that test isolation is out of scope.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P047-archive-card-batch/plan.md`
- `docs/plans/P047-archive-card-batch/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P047.1",
      "title": "Batch archive card price fingerprints and lowest-price inputs; serve card gallery and owned meta from the primed bundle",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Application/Variant/VariantResolver.php",
        "src/Compatibility/Gallery/GalleryAdapter.php",
        "src/Compatibility/WooDataStore/MetaBridge.php",
        "src/Compatibility/WooDataStore/ProductDataStore.php"
      ],
      "files": [
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Application/Variant/VariantResolver.php",
        "src/Compatibility/Gallery/GalleryAdapter.php",
        "src/Compatibility/WooDataStore/MetaBridge.php",
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "tests/integration/ArchiveCardBatchTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/StoreApi/SelectorAdapter.php"],
        ["php", "-l", "src/Application/Variant/VariantResolver.php"],
        ["php", "-l", "src/Compatibility/Gallery/GalleryAdapter.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/MetaBridge.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/ProductDataStore.php"],
        ["php", "-l", "tests/integration/ArchiveCardBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ArchiveCardBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/StorefrontQueryBudgetTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T23Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T24Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T25Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T26Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T27Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T11Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/PdpUrlSelectionTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/EffectivePriceResolverTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
