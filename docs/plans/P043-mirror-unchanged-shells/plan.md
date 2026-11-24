# P043 — Legacy mirror skips unchanged variation shells and primes their post cache

## 1. Goal

`ImportPerformanceTest` fails with "Mirror used 97 queries for 12 variations (budget 96)". The P017 budget is `3 × variations + 60`. Re-mirroring an unchanged 12-variation product on `test_wpe_bench` (2026-11-25) ran:
- 12 × `UPDATE wp_posts` rewriting each variation shell's title, status, parent and `post_modified` even when nothing changed;
- 12 × `SELECT * FROM wp_posts WHERE ID = N` from `clean_post_cache()` (it loads each post before clearing it, and the shells were not in the object cache);
- 13 × postmeta reads in `LegacyCapture::computeLegacyHash()`;
- 61 fixed queries; the fixed part grew by one since the budget was set.

Two of the three per-variation queries are avoidable without changing what the mirror writes. The goal is to bring an unchanged re-mirror well under the existing budget instead of raising the budget, which also speeds up imports and outbox projection.

## 2. Scope

In scope (`src/Application/Migration/LegacyMirror.php`):
- Read the existing variation shells' `post_title`, `post_status` and `post_parent` in the query that already lists them (same query count). Update a shell only when one of these differs from what the mirror would write.
- Before the per-variation `clean_post_cache()` loop, prime the shells with one `_prime_post_caches()` call (posts only, no term or meta priming), so `clean_post_cache()` finds them in memory. The hooks it fires (`clean_post_cache`, authority eviction, WooCommerce caches) run as before.
- `tests/integration/ImportPerformanceTest.php`: keep the P017 budget, and add checks that an unchanged re-mirror writes no variation shell row, and that a changed variation status is still written to its shell.

Out of scope:
- `computeLegacyHash()` per-variation postmeta reads (separate change).
- The parent product shell update, which always runs.

## 3. Decisions

- **D01** — An unchanged variation shell keeps its `post_modified`. The mirror runs with origin `native_projection`, so no reader relies on the bump. `LegacyCapture` uses `post_modified_gmt` as a high-water mark for legacy edits, so fewer bumps only removes noise.
- **D02** — Shells are compared on the columns the mirror writes for an existing variation (title, status, parent). A shell that differs on any of them is rewritten in full, with a new `post_modified`, as today.
- **D03** — The P017 budget stays `3 × variations + 60`; the test is not loosened.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P043-mirror-unchanged-shells/plan.md`
- `docs/plans/P043-mirror-unchanged-shells/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P043.1",
      "title": "Skip unchanged variation shell updates and prime shells before clean_post_cache in LegacyMirror; ImportPerformanceTest passes its P017 budget",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Application/Migration/LegacyMirror.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "files": [
        "src/Application/Migration/LegacyMirror.php",
        "tests/integration/ImportPerformanceTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyMirror.php"],
        ["php", "-l", "tests/integration/ImportPerformanceTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/MirrorWorkerEpochTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/OutboxProjectionWorkerTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/PostShellProjectionTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
