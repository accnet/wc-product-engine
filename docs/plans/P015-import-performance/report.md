# Report — P015: Shopify CSV import performance

## Summary

Completed `P015.1`–`P015.5`. The Shopify CSV batch importer is about 10× faster on products with ~100 variants, writes 98% fewer outbox/audit rows, and produces the same data.

| Metric (create path, 64 products / 6060 variants) | Baseline | After P015.2 | After P015.4 |
|---|---|---|---|
| Seconds per product | 10.25 | 1.90 | **0.97** |
| Variants per second | 9.2 | 49.9 | **97.7** |
| Queries per product | 13,111 | 13,118 | **6,365** |
| Queries per variant | 138.5 | 138.5 | **67.2** |
| DNS lookups (seconds) | 6,920 (526 s) | 2 (0.2 s) | **1 (0.1 s)** |
| Outbox / audit rows | 6,124 / 6,124 | 6,124 / 6,124 | **128 / 128** |
| Peak RAM | 60.8 MB | 60.8 MB | 60.8 MB |
| Longest batch request | 43.9 s | 26.7 s | 24.5 s |

Rows created (products 64, variants 6060, posts 6124, postmeta 67748) are identical in all three runs. Re-importing the same file after P015.4 took 0.80 s/product and added 0 products, variants, assets, posts and postmeta rows.

## Tasks

- `P015.1` — `tests/performance/ImportBenchmark.php` runs the real importer (`initJob` + `processBatch`) and reports throughput, queries, DNS lookups, RAM and row deltas. `tests/performance/use-test-db.php` points WP-CLI at a `test_*` database and refuses any other name; every P015 benchmark and DoD run used `test_wpe_bench`.
- `P015.2` — `UrlValidator::resolveHostIps()` caches successful lookups per host for 60 s per process. Failures are not cached; `setDnsResolver()` clears the cache. Every resolved IP is still checked against private/reserved ranges on each validation (R83). The importer registers each image URL once per product and reuses its asset and attachment for repeated variant images.
- `P015.3` — `OptionsRepository` has an opt-in per-product cache for `getOptionsByProductId()` (`enableOptionsCache()` / `disableOptionsCache()`); every option and option-value write in the class clears it. The importer enables it around each product.
- `P015.4` — The importer writes all variations of a product in one `ProductRepository::update()` call (one transaction, version bump, outbox event, audit row). `update()` takes an optional `['hydrate' => false]` and then returns the saved variation rows in input order; default behaviour is unchanged. Existing variants are matched once per product. Rows addressing the same variant collapse, later row wins.

## Deviations from the approved plan

- D03 narrowed by P016 D02: the importer keeps a per-product URL → asset map; `AssetService::registerAsset()` keeps its validate-then-deduplicate order. With the DNS cache, moving the lookup ahead of validation would change the SSRF path for no measurable gain.
- D06: the options cache is opt-in instead of always on, because `ProductEditorService` writes option tables directly and an always-on cache could serve stale options inside an editor request.
- D07: SKUs are matched through `wpe_sku_registry` first, then SKUs not found there go through one batched `wpe_variants.sku IN (…)` query per product. The registry key is binary (case-sensitive) while the old lookup on `wpe_variants.sku` (`utf8mb4_unicode_ci`) was case-insensitive; the fallback keeps the old matching.
- `VariantsRulesTest` requires `zend.assertions=1`; its DoD runs enabled assertions through an extra PHP ini scan directory set in the environment, without changing the sealed command.

## Evidence

- DoD per task recorded in `log.jsonl` (lint, `ImportPerformanceTest`, `ShopifyBatchImporterTest`, `VariantsRulesTest` 98 checks).
- `ImportPerformanceTest` (5 tests): create once, idempotent re-import, DNS once per host and cache cleared on resolver swap, duplicate rows collapse with later row winning, case-insensitive SKU match on re-import; at most 2 outbox events per product.
- Extra regression on `test_wpe_bench`: T06, T08, T15, T30–T39, T32, T48 pass. T23 fails on the site's WooCommerce core checksum and `ProductMediaRestTest` fails "a valid patch answers 200"; both are pre-existing site-state failures reported by the P013/P014 session and unrelated to these files.

## Follow-ups (not in scope)

- Remaining ~67 queries per variant are mostly the legacy mirror (post meta per variation) and per-variant combination and image writes. Batching mirror meta writes (option E) is the next candidate; measure first.
- Skipping unchanged products on re-import (option F) would make re-imports near free.
- The completion batch rebuilds the whole catalog index (`CatalogProjector::buildNewGeneration()`), a fixed cost per job that grows with catalog size.
