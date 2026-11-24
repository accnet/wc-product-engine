# P051 — test_wpe_bench hygiene and stale test expectations (T04, T45, native read parity, schema version, hydration budget)

## 1. Goal

After P050 the evidence verifies 49/49 on the isolated root (`test_wpe_regress`). Several integration tests still fail when run directly on `test_wpe_bench`, the test database used for ad-hoc and DoD runs (checked 2026-11-25, assertions enabled):

| Test | Failure | Cause |
|---|---|---|
| T04 | R212 "Junk rows already present" | Two causes. `test_wpe_bench` held residue from earlier unrestored test runs (16 junk native products, 520 orphan auto-drafts, 2 test control keys). Separately, T04 compares its PHP residue gate, which reads the database the test runs on, with the Node gate (`scripts/test-isolation.mjs`). Without `WPE_WP_PATH`, the Node gate reads the live site's database, so on a `test_*` database the two gates look at different data. |
| NativeAttributeReadTest (and NativePriceReadTest, see §2 out of scope) | Product 5640 / variation 5642 differ from their legacy shells | 48 fixture products from runs of 2026-11-19/20 (T07 and similar: "Unicode Test Product", "Collision Test Product", "Gallery Test Product", …). Each is published natively, but its shell is still an `auto-draft` with no legacy meta. They fall outside the residue signatures. The live site has 2 such products, which are not touched. |
| T45 | "Uncertified plugins detected: contact-form-7" | contact-form-7 is active in `test_wpe_bench`. OD-17 (option B, user's choice of 2026-11-20) deactivated it on site1.localhost and `test_wpe_regress` only. |
| CatalogIndexOptimizationTest | "Expected schema version 3" | Stale expectation: P039/import work raised the `SchemaMigrator` schema version to 5; the indexes the test checks still exist. |
| QueryHydrationPerformanceTest (assertions on) | "Post-prime loop … must run <= 5 queries, got 12" | The P028 budget measures data-store hydration. Since then, the Single Price Display and variant selector filters add their own per-card fingerprint, candidate and parent-inventory reads to `get_price_html()`. With those features off, the loop runs 3 queries, the number P028 reported. The card price path is covered by `ArchiveCardBatchTest` (P047). |

The goal is for all of these to pass on `test_wpe_bench` without weakening what they check.

## 2. Scope

In scope:
- **`test_wpe_bench` only:**
  - back up, then remove residue with `scripts/test-isolation.mjs cleanup-residue --apply` (P020 D03 precedent); the cleanup log is kept as `docs/evidence/residue-cleanup-p051-bench.json`;
  - deactivate contact-form-7, applying the user's OD-17 option B to this test database as well;
  - purge the 48 leftover fixture products (native `publish`, shell `auto-draft`) with `ProductPurger`. The backup is taken first, and their IDs and titles are logged in `docs/evidence/fixture-purge-p051-bench.json`.
- **`scripts/test-isolation.mjs` and T04:**
  - the Node tool honours an optional `WPE_WP_REQUIRE` file, passed to WP-CLI as `--require`;
  - when T04 runs on a `test_*` database without `WPE_WP_PATH`, it points the Node gate at the same database through `tests/performance/use-test-db.php` and `WPE_TEST_DB`;
  - with `WPE_WP_PATH` set (the evidence runner), nothing changes.
- **CatalogIndexOptimizationTest:** expect the schema version `SchemaMigrator` currently declares (and at least 3) instead of the literal 3. The index checks are unchanged.
- **QueryHydrationPerformanceTest:**
  - turn off the variant selector UI and Single Price Display only around the post-prime loop measurement, then restore them;
  - the budget stays at 5;
  - the later steps run with the features at their defaults.

Out of scope:
- NativePriceReadTest. It audits the legacy `_price` of every published product in the database it runs on, and after the purge it still finds real gaps:
  - On `test_wpe_bench`: 72 more leaked fixtures that never got a legacy projection, from T37 (R149–R152, AC-T37-01), the lifecycle-hook and variation-shell probes, and migration fixtures. Several tests leak native rows that their cleanup misses.
  - On the live site: 8 leaked T37 products (R149/R150 of 2026-11-21), and 8 real imported products whose outbox mirror events are still `pending` (no mirror worker ran).
  - These are findings for a follow-up (fix the leaking tests, run the mirror worker), not a stale expectation, so the test is left unchanged.
- The live database and `test_wpe_regress`.
- Finding every test that leaves residue. The evidence runner restores the database around each task; ad-hoc runs on `test_wpe_bench` do not.
- PdpUrlSelectionTest, which failed once only inside the full 108-test run and passes alone and after its neighbours (not reproducible).

## 3. Decisions

- **D01** — Residue is removed with a backup and a log, never by hand.
- **D02** — The hydration budget keeps measuring what P028 certified: the data stores. The selector/price-display path has its own batched budget test (P047). Mixing the two made the P028 number unreachable, so the assertion was silently skipped (assertions off) or failed (assertions on).

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/01-architecture-and-stack.md` §9 (new environment row `WPE_WP_REQUIRE`)
- `docs/evidence/residue-cleanup-p051-bench.json`
- `docs/evidence/fixture-purge-p051-bench.json`
- `docs/plans/P051-bench-db-and-stale-test-fixes/plan.md`
- `docs/plans/P051-bench-db-and-stale-test-fixes/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P051.1",
      "title": "test_wpe_bench: remove residue and leftover fixture products with a backup, deactivate contact-form-7; T04 Node gate reads the same database; T04, T45 and native attribute read parity pass",
      "depends_on": [],
      "read_first": [
        "scripts/test-isolation.mjs",
        "tests/integration/T04Test.php",
        "src/Infrastructure/Database/ProductPurger.php",
        "docs/QUESTIONS.md"
      ],
      "files": [
        "docs/01-architecture-and-stack.md",
        "scripts/test-isolation.mjs",
        "tests/integration/T04Test.php",
        "docs/evidence/residue-cleanup-p051-bench.json",
        "docs/evidence/fixture-purge-p051-bench.json"
      ],
      "dod": [
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T04Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/NativeAttributeReadTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T45Test.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P051.2",
      "title": "CatalogIndexOptimizationTest expects the current schema version; QueryHydrationPerformanceTest measures the data-store budget with selector price features off",
      "depends_on": ["P051.1"],
      "read_first": [
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "docs/plans/P028-query-hydration-performance/plan.md"
      ],
      "files": [
        "tests/integration/CatalogIndexOptimizationTest.php",
        "tests/integration/QueryHydrationPerformanceTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "tests/integration/CatalogIndexOptimizationTest.php"],
        ["php", "-l", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CatalogIndexOptimizationTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/QueryHydrationPerformanceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ArchiveCardBatchTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
