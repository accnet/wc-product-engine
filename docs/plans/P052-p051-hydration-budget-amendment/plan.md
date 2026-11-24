# P052 — P051.2 amendment: schema version expectation and both data-store hydration budgets with selector price features off

## 1. Goal

P051.2 is blocked. Its scope turned off the variant selector UI and Single Price Display only around the post-prime loop of `QueryHydrationPerformanceTest`, and said the later steps run at defaults. Measured on `test_wpe_bench` (2026-11-25):
- The post-prime loop then runs 3 queries (budget 5).
- The next budgeted step is the cold archive `WP_Query` + priming + loop (budget 32, P028.3). With the features on it runs 42 queries, because each card adds its own fingerprint, candidate and parent-inventory reads.
- The P028.3 budget is a data-store budget, like the first one.

P051 has a completed task, so it cannot be resynced. This amendment carries P051.2 with the corrected scope.

## 2. Scope

In scope:
- **CatalogIndexOptimizationTest:** expect the schema version `SchemaMigrator` currently declares (and at least 3) instead of the literal 3. The index checks are unchanged.
- **QueryHydrationPerformanceTest:**
  - turn off the variant selector UI and Single Price Display for both data-store budget measurements: the post-prime loop (budget 5) and the cold archive loop (budget 32);
  - keep them off until the end of the test and let its `finally` block restore the original flags. A flags update fires the settings hooks, which invalidate the ContextCache that the last step expects to hit, so restoring mid-test would break that check. The eviction and ContextCache checks do not involve the selector features;
  - both budgets stay unchanged.
- **ArchiveCardBatchTest (P047)** keeps covering the batched card price path with the features on.

Out of scope: everything else in P051 §2.

## 3. Decisions

- **D01** — Same as P051 D02: the P028 budgets measure the data stores. The selector price path is budgeted separately (P047).

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P052-p051-hydration-budget-amendment/plan.md`
- `docs/plans/P052-p051-hydration-budget-amendment/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P052.1",
      "title": "CatalogIndexOptimizationTest expects the current schema version; QueryHydrationPerformanceTest measures both data-store budgets with selector price features off",
      "depends_on": [],
      "read_first": [
        "docs/plans/P051-bench-db-and-stale-test-fixes/plan.md",
        "docs/plans/P028-query-hydration-performance/plan.md",
        "src/Infrastructure/Database/SchemaMigrator.php"
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
