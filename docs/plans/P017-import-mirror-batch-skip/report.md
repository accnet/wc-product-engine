# Report — P017: Batched legacy meta writes and skip unchanged products on import

## Summary

Completed `P017.1`–`P017.3`, the two follow-ups left by P015 (E: batch the legacy mirror's meta writes, F: skip unchanged products on re-import). Imported data is unchanged.

| Metric (64 products / 6060 variants) | P015 final | P017 |
|---|---|---|
| Create: seconds per product | 0.97 | **0.71** |
| Create: variants per second | 97.7 | **133.3** |
| Create: queries per variant | 67.2 | **42.2** |
| Create: longest batch request | 24.5 s | **17.0 s** |
| Re-import, same file: seconds per product | 0.80 | **0.14** |
| Re-import, same file: queries per variant | 59.5 | **5.7** |
| Re-import, same file: rows written | outbox/audit 128 | **0** |
| Re-import, every price changed: seconds per product | — | 0.57 |
| Peak RAM | 60.8 MB | 60.8 MB |

Rows created on the create path (products 64, variants 6060, posts 6124, postmeta 67748, outbox/audit 128) are identical to P015. All runs used `test_wpe_bench`. The test catalog had grown to about 354 products / 24,600 variants, so the per-job catalog rebuild is larger than in the P015 runs; the gains above are measured despite that.

## Tasks

- `P017.1` — `LegacyMirror::mirrorAggregate()` queues every meta value (last value per post/key wins, as when written in turn) and flushes once per aggregate. The flush reads existing rows for the queued keys in one query per 500 posts, inserts missing keys in multi-row INSERTs, updates the lowest-`meta_id` row of a key only when its value differs (NULL counts as different), and clears each post's meta cache. Variation post existence, inventory, attribute values and primary images are read once per aggregate; variations without an image lose `_thumbnail_id` in one DELETE.
- `P017.2` — `ShopifyBatchImporter` computes a fingerprint per product group (parsed group without row numbers or byte offsets, `update_mode`, `image_mode`, `WPE_VERSION`, format number). After an import that wrote the product, mirrored it and raised no warnings, it stores `{hash, state, variant_count, type, title}` in `wpe_control` under `shopify_import_fp:<product_id>`; otherwise it deletes any stored record. A later group is skipped when the product resolves, is not deleted, has no pending mirror, the hash matches and the native state digest matches. Skipped products count in `products_processed`/`variants_processed` and in the new `products_skipped`, and log `[SKIP]`. Job option `skip_unchanged` (default true; jobs created before P017 have no flag and import everything).
- `P017.3` — Benchmarks above, `docs/NOTES.md` entry, this report.

## Deviations from the approved plan

- D04/D05: the plan compared the product's native `version`. Inventory, options, option values and media are written without bumping the product version, so a stock sale or an options edit would not have been seen. The stored record keeps `state`, a SHA-256 over every native row the importer writes for the product (product, variants incl. tombstones, inventory, options, option values, variant values, product and variant media, product terms, `wpe_meta`), instead of `version`. This is stricter: any such change re-imports the product.
- D05: a fingerprint is also not stored when the import raised warnings (for example an image URL rejected by validation), so the next import retries the product.
- `docs/01-architecture-and-stack.md` gained a `WPE_VERSION` row through amendment plan P018, because `check-docs --strict` rejected the name in the sealed P017 plan text.
- `ImportPerformanceTest` now restores the public DNS resolver after its SSRF check, so later tests in the file import images normally.

## Evidence

- `ImportPerformanceTest` (7 tests): the 5 P015 tests, plus the batched mirror (no postmeta writes when unchanged, restores a changed value and a missing key, updates only the first row of a duplicated key, at most 3 queries per variation + 60) and skipping (all products skipped with 0 outbox rows and unchanged versions; a stock change and a changed row each re-import only their product and restore CSV values; `skip_unchanged=false` imports all).
- Mirror equivalence: 5 sample products (variable with images, variable with tombstoned variants, simple with inventory, two with `wpe_meta`) wiped and re-mirrored with old and new code; the dumps of posts, postmeta and term relationships are byte-identical.
- DoD per task recorded in `log.jsonl`. Extra regression on `test_wpe_bench`: T06, T08, T15, T30–T40, T48, `VariantsRulesTest` (98 checks), `ProductEditorServiceTest` and `MirrorWorkerEpochTest` pass. T31 exited 255 once in that run and passed in three later runs, with and without the P017 changes; the first failure was not reproduced.

## Follow-ups (not in scope)

- Catalog rebuild per job: `CatalogProjector::buildNewGeneration()` re-projects the whole catalog at the end of every import (about 1 query per catalog variant plus facet inserts). On an unchanged re-import it is most of the 34,403 queries. Projecting only the products the job wrote would make it proportional to the file.
- Importer per-variant writes (combination vectors, variant primary images) are about 20 queries per variant on the create path.
- A Shopify CSV without a variant SKU column fails with a `trim(null)` TypeError in `ShopifyBatchImporter` while reading rows (existing behaviour, found while testing).
