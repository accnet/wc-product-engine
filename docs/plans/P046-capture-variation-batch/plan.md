# P046 — Legacy capture syncs variations with batched reads and writes

## 1. Goal

`LegacyCapture::captureLegacyProduct()` copies a legacy WooCommerce product into native storage. Its variation loop costs about five queries per variation:
- one full postmeta read (`loadPostMetaMap()`);
- one existence check on `wpe_variants`;
- one `UPDATE` or `INSERT` on `wpe_variants`;
- one `REPLACE` on `wpe_inventory`;
- one `REPLACE` on `wpe_sku_registry` (when the SKU is set).

Capture runs on legacy saves, in the missed-hook reconcile scan and during backfill. For a 102-variation product that is about 500 queries. The goal is to cut this to about one query per variation (the per-row variant write) plus a constant, with exactly the same resulting rows.

## 2. Scope

In scope (`src/Application/Migration/LegacyCapture.php`, variation loop of `captureLegacyProduct()`):
- Read the variation meta the loop uses (`_sku`, `_regular_price`, `_sale_price`, `_stock`, `_manage_stock`, `_stock_status`) for all variations in one query ordered by `post_id, meta_id`. As in P045, the last row of a duplicated key wins, like `loadPostMetaMap()`.
- Read which variation IDs already exist in `wpe_variants` with one `IN (...)` query.
- Write all inventory rows with one multi-row `REPLACE`, and all variation SKU registry rows with one multi-row `REPLACE`, in the same row order as the loop. MySQL/MariaDB apply a multi-row `REPLACE` row by row, so the outcome is the same as before: a SKU shared by two variations ends with the later one, and a variation whose SKU changed loses its old row through `uniq_object_id`.
- Keep the per-variation `UPDATE`/`INSERT` on `wpe_variants`, whose columns differ by path.
- Keep the same values, `NULL` handling and timestamps.

Out of scope: parent product handling, term sync, hash, object state, the importers.

Tests: `tests/integration/CaptureVariationBatchTest.php` captures a legacy variable product whose variations cover these cases:
- SKU missing, empty or duplicated across meta rows;
- SKU shared by two variations;
- empty prices;
- unmanaged stock with an empty quantity;
- out of stock;
- a variation already in `wpe_variants` (update path) next to new ones (insert path).

It compares every `wpe_variants`, `wpe_inventory` and `wpe_sku_registry` row with values derived by the previous per-variation rules. It checks that a second capture keeps the rows stable and that the query count grows by at most one per variation.

## 3. Decisions

- **D01** — Duplicated meta keys resolve to the highest `meta_id` (P045 D01).
- **D02** — Batched writes keep the loop's row order (`post_parent` index order, by ID), so `REPLACE` conflicts resolve as before.
- **D03** — With no variations, or no variation SKUs, the batched statements are skipped.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P046-capture-variation-batch/plan.md`
- `docs/plans/P046-capture-variation-batch/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P046.1",
      "title": "captureLegacyProduct variation loop: one meta read, one existence read, multi-row inventory and SKU registry REPLACE, identical rows",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "contracts/database/schema.sql",
        "src/Application/Migration/LegacyCapture.php"
      ],
      "files": [
        "src/Application/Migration/LegacyCapture.php",
        "tests/integration/CaptureVariationBatchTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyCapture.php"],
        ["php", "-l", "tests/integration/CaptureVariationBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CaptureVariationBatchTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/LegacyHashBatchTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T37Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T38Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T39Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T40Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CohortRoutingDataStoreTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/SingleModeOperationsTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
