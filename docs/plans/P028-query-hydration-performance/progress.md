# P028 — Eliminate storefront N+1 hydration and filesorts on native catalog queries

Status: done

- [x] P028.1 — Eliminate N+1 hydration in ProductDataStore, ProductRepository, and AuthorityResolver with bulk priming and clean_post_cache eviction (done)
- [x] P028.2 — Add wpe_catalog sort indexes (SchemaMigrator v3), zero-filesort EXISTS/B-Tree compilation in CatalogQuery, and native option facets in CatalogProjector (done)
- [x] P028.3 — Wire bulk archive priming and ContextCache in CatalogAdapters and verify end-to-end query budgets (done)
- [x] P028.4 — Re-certify T05/T06/T10/T11/T12/T16/T17/T19 on isolated root, rebuild live facets/indexes, benchmark before/after, update NOTES and report (done)
