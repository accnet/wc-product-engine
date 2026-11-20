# P019 — Incremental catalog projection on import and missing SKU column fix

## 1. Goal

Make the end of a Shopify CSV import cost proportional to the file, not to the whole catalog, and stop the importer crashing on a file without a variant SKU column. Catalog rows and facets stay identical to what a full generation build produces.

Measured before P019 on `test_wpe_bench` (416 products, about 30,000 variants): the full generation build at the end of every import job takes 33,547 queries and 4.8 s, almost all of it one inventory read per catalog variant. It runs even when the job wrote nothing.

## 2. Scope

In scope:
- `ShopifyBatchImporter`: project each product it writes into the active catalog generation right after that product's import and mirror; build a full generation at job end only when no active generation exists.
- `CatalogProjector::projectProduct()`: read the inventory of all variations of a product in one query and insert its facets with one multi-row statement. This also speeds up full builds.
- `ShopifyBatchImporter`: a CSV without a variant SKU column imports with empty SKUs instead of a `trim(null)` TypeError.
- Tests for all three.

Out of scope:
- `ProductEditorService` and admin "rebuild" actions, which still call `buildNewGeneration()` (recorded as a follow-up).
- Catalog schema, query or facet semantics.

## 3. Decisions

- **D01: Same projection as scheduled price changes.** Products written by an import are projected with `projectProduct($id)` into the active generation, the same in-place upsert `evaluateScheduledPriceChanges()` already uses (docs/03 projection rule: upsert by source revision). Skipped (unchanged) products are not projected; they were projected when last written.
- **D02: Full build fallback.** When `getActiveGeneration()` is 0 at job end, the job builds a generation as before, so a site without a catalog still gets one. Projection failures are logged as warnings and never fail the product import.
- **D03: Identical projector output.** Batched reads and the multi-row facet insert produce the same `wpe_catalog` and `wpe_catalog_facets` rows as before. Facets are de-duplicated in PHP on the primary key as before; the insert uses `INSERT IGNORE` so a row conflict skips that row only, like the former per-row inserts.
- **D04: Missing SKU column.** A missing `sku` field is treated as an empty cell (no SKU). The fingerprint format is unchanged because such files never imported before.

## 4. Documentation changes

- `docs/NOTES.md`: P019 entry with numbers.
- `docs/plans/P019-import-catalog-incremental/plan.md`
- `docs/plans/P019-import-catalog-incremental/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P019.1",
      "title": "Batched inventory read and facet insert in CatalogProjector with identical output",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Application/Catalog/CatalogProjector.php"
      ],
      "files": [
        "src/Application/Catalog/CatalogProjector.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Catalog/CatalogProjector.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T16Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T17Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T18Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T19Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T20Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T47Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/EffectivePriceResolverTest.php"]
      ]
    },
    {
      "id": "P019.2",
      "title": "Project imported products into the active generation and accept a missing SKU column",
      "depends_on": ["P019.1"],
      "read_first": [
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"
      ],
      "files": [
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T16Test.php"]
      ]
    },
    {
      "id": "P019.3",
      "title": "Benchmark after P019, notes and report",
      "depends_on": ["P019.2"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P019-import-catalog-incremental/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
