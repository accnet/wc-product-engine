# Report — P019: Incremental catalog projection on import and missing SKU column fix

## Summary

Completed `P019.1`–`P019.3`. The end of an import no longer rebuilds the whole catalog, a full rebuild is about 10× cheaper, and a CSV without a variant SKU column imports instead of crashing. Catalog rows and facets are unchanged.

| Metric (64 products / 6060 variants) | P017 | P019 |
|---|---|---|
| Re-import, same file: queries | 34,403 | **857** |
| Re-import, same file: seconds per product | 0.14 | **0.08** |
| Re-import, every price changed: seconds per product | 0.57 | **0.52** |
| Re-import, every price changed: queries per variant | 36.5 | **31.0** |
| Create: seconds per product | 0.71 | 0.71 |
| Create: queries per variant | 42.2 | **36.8** |
| Peak RAM | 60.8 MB | 60.8 MB |

The P019 runs had a larger test catalog (about 428 products / 30,700 variants against about 354 / 24,600 for P017), so the old per-job rebuild would have cost more than in the P017 runs. Rows written on the create path (products 64, variants 6060, posts 6124, postmeta 67748, outbox/audit 128) are the same as in P015 and P017. All runs used `test_wpe_bench`.

Full catalog generation build (416 products, about 30,000 variants, 429 facets): 33,977 → 3,279 queries, 5.3 → 0.85 s.

## Tasks

- `P019.1` — `CatalogProjector::projectProduct()` loads the inventory of every stock owner of a variable product in one query instead of one per variation, and inserts the product's de-duplicated facets in multi-row `INSERT IGNORE` statements (500 rows each) instead of one insert per facet.
- `P019.2` — `ShopifyBatchImporter` projects each written product into the active generation right after its import and mirror, through the same `projectProduct()` upsert `evaluateScheduledPriceChanges()` uses. At job end it builds a full generation only when no generation is active; `rebuild_report.mode` says `incremental` or `full`. A projection failure adds a warning and does not fail the product. A row without a `sku` field is read as an empty SKU cell.
- `P019.3` — Benchmarks above, `docs/NOTES.md` entry, this report.

## Deviations from the approved plan

None.

## Evidence

- Projector equivalence: full generation built with the old and the new `CatalogProjector` on the same data, once as is (416 catalog rows, no facets) and once after seeding product attributes, variant attributes, a category and a tag on three test products (429 facets). Both dumps (generation and `indexed_at` removed) are byte-identical.
- `ImportPerformanceTest` (9 tests): the 7 earlier tests, plus an import that changes one price keeps the active generation, reports `incremental`, leaves every imported product's catalog row at its current `source_version` and shows the new `max_price`; and a CSV without a SKU column imports two variants with empty SKUs.
- DoD per task recorded in `log.jsonl`: T16, T17, T18, T19, T20, T47, `EffectivePriceResolverTest`, `ShopifyBatchImporterTest`, `ImportPerformanceTest`.
- Another session had uncommitted edits in `src/Application/Variant/ResolverApi.php` during this work. P019 commits contain only P019 files.

## Follow-ups (not in scope)

- `ProductEditorService` still builds a full generation after each product save. P019.1 makes that about 10× cheaper, but projecting only the saved product would make it independent of catalog size.
- Importer per-variant writes (combination vectors, variant primary images) remain the largest create-path cost.
