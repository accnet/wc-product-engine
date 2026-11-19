# Report — P016: P015 amendment, test DB variable and narrowed D03

## Summary

Completed `P016.1`. Documentation only, no code change.

- `docs/01-architecture-and-stack.md` §9 has a new `WPE_TEST_DB` row (default `test_wpe_bench`). It covers the disposable `test_*` database used by `tests/performance/use-test-db.php` through `wp --require`, which sets `DB_NAME` before `wp-config.php` and refuses names without the `test_` prefix. The existing `WPE_TEST_DB_NAME` row (separate fixture-runtime server) is unchanged.
- P015 D03 is narrowed (P016 D02). The importer keeps a per-product URL → asset/attachment map. `AssetService::registerAsset()` keeps validate-then-deduplicate order, so the SSRF path is unchanged.

## Why

P015 was approved with the names `WPE_TEST_DB` and `DB_NAME` in its plan text. `node scripts/check-docs.mjs --strict` rejects unregistered constant-like names. P015 is active and cannot be resynced, so the names are registered in docs/01 instead.

## Evidence

- `node scripts/check-docs.mjs --strict`: exit 0, errors 0, warnings 0.
- `node scripts/plan.mjs check`: exit 0, errors 0.
