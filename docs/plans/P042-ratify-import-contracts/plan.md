# P042 — Ratify the 2026-11-24 import contract and docs changes

## 1. Goal

Commit `5d5fe74` (Shopify import performance and category default, 2026-11-24) changed authoritative files without an approved plan, which AGENTS.md §4 requires for contract and docs edits:
- `contracts/database/schema.sql`: new index `idx_parent_kind (parent_id, kind)` on `wpe_object_state`, added on existing sites by `SchemaMigrator` schema version 5.
- `docs/03-database-schema.md` §3 (`object_state` row): the same index, "for the mirror marking variations by product".
- `docs/10-integrations.md` §7: Shopify `Product category` maps to the WooCommerce category tree by default (`category_mode=path`, also for a missing or unknown value); only an explicit `none` leaves it unmapped. Previously the default was `none`.

This plan records the business decision and its reasons, closes one drift the change left behind, and adds evidence that the code, the contract and the docs agree.

Reasons, from `docs/NOTES.md` (2026-11-24):
- The mirror's `UPDATE wpe_object_state ... WHERE parent_id = %d AND kind = 'variation'` had no usable index (the existing one starts with `authority`). It was 12% of import SQL time on site1 (~221k rows).
- Job `ce25749a…` imported 4,115 products that all had a `Product category`, and with the `none` default every product landed in Uncategorized. Existing categories are reused (matched by name under the same parent) and missing ones created, so mapping by default is safe for catalogs that already have a category tree. Approved by the user on 2026-11-25 by requesting this ratification.

## 2. Scope

In scope:
- Ratify the three edits above as they stand in `5d5fe74`; no change to their meaning.
- Close the drift: `contracts/database/schema-contract.json` (`object_state.keys_contract`) still lists only `(authority,parent_id,object_id)`. Bring it in line with docs/03.
- `tests/integration/ObjectStateIndexTest.php` checks that:
  - `schema.sql`, `schema-contract.json` and docs/03 all declare the index.
  - `SchemaMigrator::migrate()` adds it to an existing table once, so a second run leaves it in place.
  - The schema version is 5.
  - The mirror's per-parent query uses the index.
  - `ShopifyBatchImporter::normalizeCategoryMode()` maps missing or unknown values to `path` and keeps `none`, matching docs/10 §7.
- Re-run the Shopify category and taxonomy tests as evidence for docs/10 §7.

Out of scope: further import changes, ImportPerformanceTest's pre-existing 97/96 mirror query budget failure.

## 3. Decisions

- **D01** — `idx_parent_kind (parent_id, kind)` is part of the schema contract. The existing `idx_authority_parent_object` stays because authority scans use it.
- **D02** — Shopify `category_mode` defaults to `path`: the column is mapped unless the operator picks `none` for a job. Existing jobs keep the mode stored in their config; the fingerprint carries it, so a re-import with a different mode rewrites products.
- **D03** — The machine-readable `schema-contract.json` mirrors docs/03 wording for `keys_contract`, as for the other tables.

## 4. Documentation changes

- `contracts/database/schema-contract.json` (`object_state.keys_contract`)
- `contracts/database/schema.sql` (ratified, unchanged)
- `docs/03-database-schema.md` §3 `object_state` row (ratified, unchanged)
- `docs/10-integrations.md` §7 Shopify mapping row (ratified, unchanged)
- `docs/NOTES.md`
- `docs/plans/P042-ratify-import-contracts/plan.md`
- `docs/plans/P042-ratify-import-contracts/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P042.1",
      "title": "Ratify idx_parent_kind and the path category default; sync schema-contract.json and add contract/code agreement checks",
      "depends_on": [],
      "read_first": [
        "docs/03-database-schema.md",
        "docs/10-integrations.md",
        "contracts/database/schema.sql",
        "contracts/database/schema-contract.json",
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"
      ],
      "files": [
        "contracts/database/schema-contract.json",
        "contracts/database/schema.sql",
        "docs/03-database-schema.md",
        "docs/10-integrations.md",
        "tests/integration/ObjectStateIndexTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "tests/integration/ObjectStateIndexTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ObjectStateIndexTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyImportCategoryPathTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyImportTaxonomyTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
