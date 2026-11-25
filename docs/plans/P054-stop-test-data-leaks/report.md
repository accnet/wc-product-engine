# Report

## Summary

P054 stops native product rows leaking from deleted legacy products and from leaky tests, and makes the residue gate see them.

- **P054.1 (commit `1a0a19e`)** — `LegacyCapture` no longer captures a post between `before_delete_post` and `deleted_post`. When a variation is deleted, its surviving parent is captured once afterwards. This was a product defect in the pre-cutover system modes (`shadow`, `indexed`): `wp_delete_post()` fires `deleted_post_meta` per meta row, and each capture either rewrote the half-deleted product or recreated native rows that a purge had already removed. `LegacyCaptureDeleteTest.php` covers deleting a captured product, deleting after a purge, and deleting one variation. T37 left +7 native rows per run before the fix and 0 after (`leak-check.php` snapshot/compare).
- **P054.2 (commit `7db2236`)** — T06 (+5 products / +18 posts / +10 variants per run) and CatalogShellIntegrityTest (soft delete) now remove what they create with `ProductPurger` and their shells. Leak check: 0.
- **P054.3** — New residue signature `orphan_native_products` (a `wpe_products` row without a `product` post) in `FixtureHarness::residueReport()` and `scripts/test-isolation.mjs`; `cleanup-residue` removes these rows like junk native products. Data cleanup:
  - `test_wpe_bench`: full dump first, then cleaned; residue 0 (`docs/evidence/residue-cleanup-p054-bench.json`).
  - Live site (user approval of 2026-11-25; no snapshot or restore): exactly 17 orphan IDs, all test leftovers. Every engine row of those IDs was backed up to `docs/evidence/orphan-purge-p054-live.json` (17 products, 17 inventory, 17 object_state, 4 SKU, 5 outbox), then removed with `ProductPurger`: 55 rows, 0 left. Live orphan auto-draft posts were not touched.
  - `test_wpe_regress` (the isolated evidence root, not listed in §2): the new signature found 14 T37 leftovers of 2026-11-19/20 that blocked the T04/T06/T37 evidence. They were removed with a full dump first (`docs/evidence/residue-cleanup-p054-regress.json`).

DoD for P054.3 (assertions on), all exit 0:
- On `test_wpe_bench`: T04; NativePriceReadTest (1,091 checks); NativeAttributeReadTest (339,023 checks).
- On the live site: NativePriceReadTest, 30,507 checks. It failed before the purge.
- check-docs strict, 0 errors; guard, 0 errors.

Evidence on the isolated root: T04, T06 and T37 were re-run and pass. `rerun-evidence.mjs --verify-only` verifies 49/49.

Earlier the same day the live outbox was drained with `wp wpe outbox`: 9,126 pending events processed and 0 left. No system cron runs the workers yet; adding one is a recommendation for the operator.

These are docs checks and integration tests on site1. They are not a WooCommerce integration certification.
