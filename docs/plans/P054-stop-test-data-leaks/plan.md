# P054 — Stop native rows leaking from deleted legacy products and leaky tests; residue gate sees orphan native rows

## 1. Goal

NativePriceReadTest found published native products with no legacy projection. On 2026-11-25:
- The live site had 17 native product rows whose WordPress shell no longer exists:
  - 8 published "R149 Direct SQL Updated Title" / "R150 Variable Parent" (T37 runs of 2026-11-21);
  - 4 trashed "R150 Term Stock Product";
  - 5 soft-deleted "Shell Integrity Probe".
- `test_wpe_bench` had 87 such rows, and more fixtures with shells.

Measured per test on `test_wpe_bench` (rows of `wpe_products` / product posts / `wpe_variants` before and after one run):
- **T37: +7 native rows.** The cause is a product defect. `wp_delete_post()` deletes postmeta row by row, and each `deleted_post_meta` (and the term removal) makes `LegacyCapture` capture the product that is being deleted. When the native rows were removed first, as T37's cleanup and a bulk purge do, the capture recreates them; otherwise it rewrites them several times during the delete (7 engine writes for one simple product). The capture hooks are registered in the `shadow` and `indexed` system modes, the pre-cutover modes.
- **T06: +5 / +18 / +10.** The test creates products and a legacy post and never removes them.
- **CatalogShellIntegrityTest: +1.** Its cleanup soft-deletes the native row (`trash` + `deleted_at`) instead of removing it.
- LifecycleHookRegistrationTest and VariationShellProjectionTest no longer leak; their old probes are leftovers.

The residue gate (T04, R212) does not see any of this. Its signatures cover untitled or `AUTO-DRAFT` native rows and orphan auto-draft posts, not native rows whose shell is gone.

The 8 imported bomber-jacket products that also lacked `_price` were a mirror backlog: 9,126 pending outbox events. The user had them drained on 2026-11-25 with `wp wpe outbox`; those 8 products are variable with no variants, so their empty legacy price is now correct.

## 2. Scope

In scope:
- **`LegacyCapture`:**
  - while a product or variation post is being permanently deleted (`before_delete_post` until `deleted_post`), meta, term, save and stock hooks for that post do not capture;
  - when the deleted post is a variation, its parent is captured once after the deletion, if the parent still exists and is under legacy authority;
  - trash and tombstone behaviour is unchanged.
- **`tests/integration/LegacyCaptureDeleteTest.php`:**
  - deleting a captured legacy product writes no native product rows during the delete and leaves no live native row: ProductLifecycle purges it when the native runtime is active, otherwise the tombstone marks it;
  - deleting a product whose native rows were removed first does not recreate them;
  - deleting a variation captures its parent exactly once.
- **T06 and CatalogShellIntegrityTest** remove what they create:
  - native rows through `ProductPurger`, plus their shells;
  - on `test_*` databases, T06 also removes the orphan auto-draft shells its failed creates allocated during the run.
- **`tests/integration/support/leak-check.php`:** `snapshot <file>` and `compare <file>` for product, post and variant rows. The DoD uses it around T06, T37 and CatalogShellIntegrityTest.
- **Residue gate:**
  - a new signature, `orphan_native_products`: native product rows without a product post, in `FixtureHarness::residueReport()` and `scripts/test-isolation.mjs`;
  - `cleanup-residue` removes them like junk native products.
- **Data:**
  - `test_wpe_bench`: back up the database, then clean with `cleanup-residue --apply`, and purge the remaining test fixtures with shells (titles of T06, T37, lifecycle and variation-shell probes). Both are logged.
  - Live site (authorized by the user on 2026-11-25): purge the 17 orphan native rows by ID with `ProductPurger`. A JSON backup of every engine row of those IDs is written first. The live database is never snapshotted or restored (P020). Live orphan auto-draft posts are left to WordPress's own auto-draft cleanup.

Out of scope:
- Installing a system cron for the outbox worker. `/etc/cron.d` is root-owned and not readable here; this is a recommendation for the operator.
- The 8 variable imported products with no variants, an import data question.

## 3. Decisions

- **D01** — A post being permanently deleted is not a source of capture. Its deletion is recorded by the existing tombstone; capturing its half-deleted state is what resurrected rows.
- **D02** — An orphan native product row (no WordPress shell) is residue. Native products always own a real shell (`PostIdAllocator`), so a missing shell only comes from a delete that bypassed the engine.
- **D03** — Live cleanup is by explicit IDs with a row-level backup, never by signature sweep.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/evidence/residue-cleanup-p054-bench.json`
- `docs/evidence/orphan-purge-p054-live.json`
- `docs/plans/P054-stop-test-data-leaks/plan.md`
- `docs/plans/P054-stop-test-data-leaks/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P054.1",
      "title": "LegacyCapture ignores hooks of posts being permanently deleted and captures a deleted variation's parent once; T37 stops leaking",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Application/Migration/LegacyCapture.php",
        "tests/integration/T37Test.php"
      ],
      "files": [
        "src/Application/Migration/LegacyCapture.php",
        "tests/integration/LegacyCaptureDeleteTest.php",
        "tests/integration/support/leak-check.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyCapture.php"],
        ["php", "-l", "tests/integration/LegacyCaptureDeleteTest.php"],
        ["php", "-l", "tests/integration/support/leak-check.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/LegacyCaptureDeleteTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/leak-check.php", "snapshot", "/tmp/wpe-p054-leak-t37.json"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T37Test.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/leak-check.php", "compare", "/tmp/wpe-p054-leak-t37.json"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T38Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T39Test.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CaptureVariationBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/LegacyHashBatchTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P054.2",
      "title": "T06 and CatalogShellIntegrityTest remove the products and shells they create",
      "depends_on": ["P054.1"],
      "read_first": [
        "tests/integration/T06Test.php",
        "tests/integration/CatalogShellIntegrityTest.php",
        "src/Infrastructure/Database/ProductPurger.php"
      ],
      "files": [
        "tests/integration/T06Test.php",
        "tests/integration/CatalogShellIntegrityTest.php"
      ],
      "dod": [
        ["php", "-l", "tests/integration/T06Test.php"],
        ["php", "-l", "tests/integration/CatalogShellIntegrityTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/leak-check.php", "snapshot", "/tmp/wpe-p054-leak-t06.json"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T06Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CatalogShellIntegrityTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/leak-check.php", "compare", "/tmp/wpe-p054-leak-t06.json"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P054.3",
      "title": "Residue gate detects orphan native rows; clean test_wpe_bench and purge the 17 live orphans with a backup; native price parity passes",
      "depends_on": ["P054.2"],
      "read_first": [
        "scripts/test-isolation.mjs",
        "src/Bootstrap/FixtureHarness.php"
      ],
      "files": [
        "scripts/test-isolation.mjs",
        "src/Bootstrap/FixtureHarness.php",
        "docs/evidence/residue-cleanup-p054-bench.json",
        "docs/evidence/orphan-purge-p054-live.json",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Bootstrap/FixtureHarness.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T04Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/NativePriceReadTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/NativeAttributeReadTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/NativePriceReadTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```

Test notes: the evidence for T04 and T37 (and its dependents) is re-run on the isolated root after P054.3, followed by a full `--verify-only`; results are in the report. The live NativePriceReadTest run reads every published product and creates one throw-away product, which it deletes (its own docblock).
