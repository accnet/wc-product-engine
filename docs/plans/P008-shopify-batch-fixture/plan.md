# P008 — Fix misaligned Shopify batch test fixture (OD-13)

## 1. Goal

Make `ShopifyBatchImporterTest` pass for the right reason. OD-13 reported "Batch 2 expected 2 products processed total, got 1". Investigation showed the importer is correct and the test fixture is wrong: its hand-written CSV rows are shifted against the 48-column header. The second product row has one comma too few, so the grams value 500 is read as the variant SKU and the import fails with "Duplicate SKU '500' already registered to object 76907" because a real product on this site already owns that SKU. The second row of the first product is shifted by one column as well (its option names and values land in the wrong columns), so it only passes its variant count by accident.

## 2. Scope

In scope:
- Build the fixture rows from a map keyed by the real header names, so every value lands in its column. The data stays the same: product 1 is variable with two variants, product 2 is a simple default-title product.
- Keep every assertion unchanged.
- Record the resolution in QUESTIONS (OD-13 answered) and NOTES.

Out of scope:
- Any change to `ShopifyBatchImporter` or its counters (no defect found).
- Surfacing mirror outcomes in the importers.
- Cleanup of other residue the test leaves behind (wp_posts shells, inventory rows); noted as a follow-up.

## 3. Decisions

- **D01: Fix the fixture, not the importer.** The failing log line names the cause; the importer behaviour for valid data is covered by the unchanged assertions, which only pass if both products import without error.
- **D02: Header-keyed rows.** A helper builds each CSV line from the header list and an associative array, so a missing comma can no longer shift columns.
- **D03: SKU hygiene.** The fixture SKUs keep the TEST-P1 and TEST-P2 prefixes that the existing teardown removes from the SKU registry.

## 4. Documentation changes

- `docs/plans/P008-shopify-batch-fixture/plan.md`
- `docs/plans/P008-shopify-batch-fixture/report.md`
- `docs/NOTES.md`
- `docs/QUESTIONS.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P008.1",
      "title": "Build the Shopify batch test fixture by header name and record the OD-13 resolution",
      "depends_on": [],
      "read_first": [
        "docs/04-api-contract.md",
        "docs/PROGRESS.md"
      ],
      "files": [
        "tests/integration/ShopifyBatchImporterTest.php",
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P008-shopify-batch-fixture/plan.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "php",
          "-d",
          "zend.assertions=1",
          "-d",
          "assert.exception=1",
          "/usr/local/bin/wp",
          "eval-file",
          "tests/integration/ShopifyBatchImporterTest.php"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    }
  ]
}
```
