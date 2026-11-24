# P045 — Legacy hash reads variation meta in one query

## 1. Goal

`LegacyCapture::computeLegacyHash()` runs at the end of every legacy mirror (`LegacyMirror`), during capture, and during the legacy change scan. For each variation it calls `loadPostMetaMap()`, which reads every postmeta row of that variation. Re-mirroring a 12-variation product on `test_wpe_bench` therefore spent 13 of its 63 queries here (P043/P044 measurement, 2026-11-25), and the cost grows with the variation count (102 queries for a 102-variation product).

The goal is to compute the same hash from a bounded number of queries, whatever the variation count. The hash must stay byte-identical, because stored `legacy_hash` values are compared against it to detect legacy edits.

## 2. Scope

In scope (`src/Application/Migration/LegacyCapture.php`):
- `computeLegacyHash()` reads the `_sku`, `_price` and `_stock` postmeta of all variations in one query, `post_id IN (...)` ordered by `post_id, meta_id`. The last row of a key wins, as with `loadPostMetaMap()` today.
- The hash payload, its field order and its JSON encoding are unchanged.

Out of scope:
- The per-variation `loadPostMetaMap()` in the capture sync loop (`syncAggregate` variations). That is a separate write path with its own per-variation queries.
- The other mirror queries.

Tests: `tests/integration/LegacyHashBatchTest.php` checks, on existing variable products and on one with duplicated, missing and empty variation meta rows, that:
- the new hash equals a reference copy of the previous algorithm;
- the query count does not depend on the number of variations.

`ImportPerformanceTest` keeps its P017 budget.

## 3. Decisions

- **D01** — Duplicate meta keys resolve to the row with the highest `meta_id`. This is what the previous per-post query returned in practice: InnoDB reads `post_id` index entries in `meta_id` order, and the map keeps the last one. An explicit `ORDER BY post_id, meta_id` makes it deterministic.
- **D02** — Only the three keys the hash uses are read. Other keys never affected the hash.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P045-legacy-hash-batch-meta/plan.md`
- `docs/plans/P045-legacy-hash-batch-meta/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P045.1",
      "title": "computeLegacyHash reads variation _sku/_price/_stock in one ordered query with an unchanged hash",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Application/Migration/LegacyCapture.php",
        "src/Application/Migration/LegacyMirror.php"
      ],
      "files": [
        "src/Application/Migration/LegacyCapture.php",
        "tests/integration/LegacyHashBatchTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyCapture.php"],
        ["php", "-l", "tests/integration/LegacyHashBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/LegacyHashBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/MirrorWorkerEpochTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T38Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T39Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T40Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CohortRoutingDataStoreTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
