# P015 — Shopify CSV import performance

## 1. Goal

Make the Shopify CSV batch import several times faster for products with hundreds of variants and many images, using less CPU and fewer queries, without changing imported data, authority, epoch fencing or Resume/Retry idempotency.

Baseline measured on the live run of job `1dcbfdf1` (64 products, 6,060 variants, 938 unique image URLs): 0.072 products/s, 6.9 variants/s, about 13.8 s per product.

## 2. Scope

In scope (bottlenecks verified by code reading and measurement):
- B1/B3: every image reference re-resolves DNS (~64 ms each, measured) before the asset de-duplication lookup, even for assets that already exist. About 108 references per product but only about 14 unique URLs.
- B2: `ShopifyBatchImporter` calls `ProductRepository::update()` once per variant. Each call is a full product transaction (row lock, version bump, outbox event, audit row) and then re-hydrates the whole aggregate through `getById()` (106 queries for 102 variants, measured), whose result the importer ignores. Cost grows O(V²) per product.
- B4: product options are re-loaded several times per variant (`buildOptionSelections` twice, plus `buildCombinationVector` and `assignVariantCombination`).
- B5: `resolveVariantIdBySku()` filters `wpe_variants.sku`, which has no index (full scan, EXPLAIN `type: ALL`).
- A repeatable benchmark that runs only against a `test_*` database.

Out of scope:
- More parallel workers, raw SQL bulk loading into WooCommerce tables, or any bypass of the repository, epoch fence or outbox (R-rules in AGENTS.md §4).
- Batch-writing legacy post meta in `LegacyMirror` (option E) and skipping unchanged products on re-import (option F). They are follow-ups, decided after the benchmark in P015.5.
- Schema changes. B5 is solved by reading the existing `wpe_sku_registry` primary key.
- Admin UI changes.

## 3. Decisions

- **D01: Benchmarks and DoD integration runs use a `test_*` database.** `tests/performance/use-test-db.php` (loaded with `wp --require`) sets `DB_NAME` from `WPE_TEST_DB` (default `test_wpe_bench`) and refuses any other prefix. The live site database is never written by P015 commands.
- **D02: Per-request DNS cache.** `UrlValidator` caches resolved IPs per host for the life of the PHP request. Each new host is still resolved and every resolved IP is still checked against private/reserved ranges, so the SSRF rule (R83) is unchanged. A batch request lasts at most about 25 s, so the cache cannot outlive a DNS change in any meaningful way.
- **D03: Asset lookup before validation.** `AssetService::registerAsset()` returns the existing asset when the canonical URL already has a row, before DNS validation. A URL can only have a row if it passed validation when it was first registered. The importer also keeps a URL → asset map per product, so repeated variant images reuse one asset without any query.
- **D04: One repository write per product.** The importer resolves existing variants once per product (by SKU through `wpe_sku_registry`, else by combination key), builds every variation payload, and calls `ProductRepository::update()` once with all variations. The product is written in one transaction with one version bump, one outbox event and one audit row. A crash mid-product no longer leaves a half-written product. Variant ids for empty-SKU rows are read back by combination key, not by `ORDER BY id DESC LIMIT 1`.
- **D05: `update()` hydration is optional.** `ProductRepository::update()` gains an optional `$options['hydrate'] = false` that returns only `['id' => …, 'version' => …]`. Default behaviour and all existing callers are unchanged.
- **D06: Options cache inside `OptionsRepository`.** `getOptionsByProductId()` results are cached per product for the request and invalidated by every option and option-value write method in the same class.
- **D07: SKU lookup through the registry.** `resolveVariantIdBySku()` reads `wpe_sku_registry` (primary key `normalized_sku`) and keeps only active `variation` rows, instead of scanning `wpe_variants.sku`.

## 4. Documentation changes

- `docs/NOTES.md`: P015 entry with before/after benchmark numbers.
- `docs/plans/P015-import-performance/plan.md`
- `docs/plans/P015-import-performance/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P015.1",
      "title": "Import benchmark harness on a test_* database and baseline numbers",
      "depends_on": [],
      "read_first": [
        "docs/07-testing.md",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"
      ],
      "files": [
        "tests/performance/ImportBenchmark.php",
        "tests/performance/use-test-db.php",
        "docs/plans/P015-import-performance/plan.md"
      ],
      "dod": [
        ["php", "-l", "tests/performance/ImportBenchmark.php"],
        ["php", "-l", "tests/performance/use-test-db.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P015.2",
      "title": "Per-request DNS cache and asset lookup before validation",
      "depends_on": ["P015.1"],
      "read_first": [
        "docs/02-domain.md",
        "src/Compatibility/Gallery/AssetService.php"
      ],
      "files": [
        "src/Compatibility/Gallery/AssetService.php",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/Gallery/AssetService.php"],
        ["php", "-l", "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"]
      ]
    },
    {
      "id": "P015.3",
      "title": "Request cache for product options in OptionsRepository",
      "depends_on": ["P015.2"],
      "read_first": [
        "src/Infrastructure/Database/OptionsRepository.php"
      ],
      "files": [
        "src/Infrastructure/Database/OptionsRepository.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Infrastructure/Database/OptionsRepository.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/VariantsRulesTest.php"]
      ]
    },
    {
      "id": "P015.4",
      "title": "One repository write per product and SKU lookup through the registry",
      "depends_on": ["P015.3"],
      "read_first": [
        "docs/02-domain.md",
        "src/Infrastructure/Database/ProductRepository.php"
      ],
      "files": [
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Infrastructure/Database/ProductRepository.php"],
        ["php", "-l", "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/VariantsRulesTest.php"]
      ]
    },
    {
      "id": "P015.5",
      "title": "After benchmark, idempotency re-run, notes and report",
      "depends_on": ["P015.4"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P015-import-performance/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
