# P048 — Integration tests pin the cohort mode they need and restore it

## 1. Goal

The integration tests share `test_wpe_bench`, and several of them silently depend on whatever `cohort_mode` the previous test left in `wpe_feature_flags`. On 2026-11-25 all 108 integration tests (excluding the `*Live`, T47, T49 and storage round-trip tests) ran under each mode, with assertions enabled, on the same code:
- Pass only under `native_bridge`: FeedAutoRunTest, FeedBuildPublishTest, FeedEndpointTest, FeedIncrementalTest, FeedItemSourceTest, FeedReconcileTest, LifecycleHookRegistrationTest, T11Test, VariationShellProjectionTest.
- Pass only under `cohort_canary`: T26Test, T37Test, T48Test.
- Changes the mode: T40Test. Its rollback steps run `RollbackRunner`, which legitimately sets `cohort_mode` (`native_bridge` → `cohort_canary`), and the test never restores it.

Because of this, a test's result depends on the order the tests ran in, which hid real results and blocked P045–P047 DoD runs. The goal: every one of these tests passes whatever mode the DB was left in, and no test leaves the mode changed.

## 2. Scope

In scope:
- `tests/integration/support/CohortModeFixture.php`:
  - `pin($mode)` snapshots `wpe_feature_flags` once, sets `cohort_mode`, reloads the plugin flags and flushes the authority runtime cache;
  - `preserve()` takes only the snapshot;
  - both register a shutdown restore, so a failing or fatal test still puts the option back exactly (including "option absent").
- `tests/integration/support/cohort-mode.php`: a test-DB-only CLI helper, `set <mode>` or `expect <mode>` (exits 1 on mismatch), used by the DoD to start each run in a given mode and to prove nothing leaked.
- The 12 tests above pin the mode they were written for, and T40Test preserves and restores. No assertion changes.
  - Legacy-authority scenarios pin `cohort_canary`: T37 legacy capture, T48 legacy/native matrix, T26 resolver on freshly allocated shells.
  - Native-bridge scenarios pin `native_bridge`.

Out of scope:
- Other order or state dependencies seen in the same run, which fail under both modes:
  - pinned-version gates: T01, T43, NativeBridgeRolloutTest;
  - leftover junk rows: T04;
  - schema version expectation: CatalogIndexOptimizationTest;
  - R67/R71 facet tests: T17, T18;
  - legacy shell parity: NativeAttributeReadTest, NativePriceReadTest;
  - T45;
  - QueryHydrationPerformanceTest with assertions enabled;
  - PdpUrlSelectionTest, which failed only inside the full run and passes alone.
- Any production code.

## 3. Decisions

- **D01** — Pin, do not infer: each test declares its mode. Production code that changes the mode (rollback, cutover) is not touched.
- **D02** — Restoration runs from a shutdown function, so assertion failures (exceptions or fatals) cannot leak the mode.
- **D03** — Only `wpe_feature_flags` is snapshotted. In the matrix run, the routing option and the `system_mode` control row did not change across T40Test.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P048-test-cohort-mode-isolation/plan.md`
- `docs/plans/P048-test-cohort-mode-isolation/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P048.1",
      "title": "CohortModeFixture pins and restores cohort_mode; the 12 mode-dependent tests pin their mode and T40Test restores it",
      "depends_on": [],
      "read_first": [
        "docs/07-testing.md",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Application/Migration/RollbackRunner.php",
        "tests/performance/use-test-db.php"
      ],
      "files": [
        "tests/integration/support/CohortModeFixture.php",
        "tests/integration/support/cohort-mode.php",
        "tests/integration/FeedAutoRunTest.php",
        "tests/integration/FeedBuildPublishTest.php",
        "tests/integration/FeedEndpointTest.php",
        "tests/integration/FeedIncrementalTest.php",
        "tests/integration/FeedItemSourceTest.php",
        "tests/integration/FeedReconcileTest.php",
        "tests/integration/LifecycleHookRegistrationTest.php",
        "tests/integration/T11Test.php",
        "tests/integration/VariationShellProjectionTest.php",
        "tests/integration/T26Test.php",
        "tests/integration/T37Test.php",
        "tests/integration/T48Test.php",
        "tests/integration/T40Test.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "tests/integration/support/CohortModeFixture.php"],
        ["php", "-l", "tests/integration/support/cohort-mode.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/cohort-mode.php", "set", "cohort_canary"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T11Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedAutoRunTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedBuildPublishTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedEndpointTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedIncrementalTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedItemSourceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedReconcileTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/LifecycleHookRegistrationTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/VariationShellProjectionTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/cohort-mode.php", "expect", "cohort_canary"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/cohort-mode.php", "set", "native_bridge"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T26Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T37Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T48Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T40Test.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/support/cohort-mode.php", "expect", "native_bridge"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
