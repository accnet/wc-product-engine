# P050 — T17/T18 catalog filter tests scope their fixtures away from existing catalog data

## 1. Goal

In P049, T18 failed R71 (and T19 failed its verify through T18). T17 R67 has failed since 2026-11-21. Neither is a product defect:
- `FacetQuery::queryProducts()` and `CatalogQuery::execute()` return a bounded first page: 48 results, cheapest first by default.
- Since P028 projects native option facets, the catalog products already in the test databases match the generic fixture values the tests filter on: `pa_color=black/red`, `pa_size=m`, price 30–120, the word "Cotton".
- Those products push the fixtures off the page.
- The same R71 failure occurs with the P020-era engine code on today's test database (checked 2026-11-25).

The goal is that T17 and T18 test their filter semantics on their own fixtures, whatever else is in the catalog, and that the T17/T18/T19 receipts pass again.

## 2. Scope

In scope (tests only):
- **T18 R71:** the two fixtures also get a run-unique category facet. The coherent and compatible queries filter on it together with the attribute pair, so only the fixtures can match. The assertions are unchanged.
- **T17 R67:**
  - The fixture category values (`shoes`, `shirts`) get a run-unique suffix.
  - All three fixtures share a run-unique scope category.
  - The attribute, price-range and search steps add that scope category.
  - The category step keeps testing the category filter itself.
  - The assertions are unchanged.
- The same scoping for any later T17/T18 step that turns out to be crowded once R67/R71 pass.
- Re-run the T17, T18 and T19 evidence on the isolated root against `test_wpe_regress`, as in P049.

Out of scope: `FacetQuery` and `CatalogQuery` code, and the 48-result page size.

## 3. Decisions

- **D01** — Scope by an extra category facet rather than by raising the limit. A larger limit only postpones the crowding as the catalog grows. A category the fixtures alone carry makes the result independent of catalog size and keeps each assertion about the filter under test.
- **D02** — Suffixes come from `wp_generate_password()`, which the tests already use for term names, so concurrent or repeated runs do not share values.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/evidence/` (T17, T18, T19 receipts and the rerun summary written by the runner)
- `docs/plans/P050-facet-test-data-scope/plan.md`
- `docs/plans/P050-facet-test-data-scope/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P050.1",
      "title": "Scope T17 R67 and T18 R71 fixtures with run-unique category facets; re-run T17-T19 evidence",
      "depends_on": [],
      "read_first": [
        "docs/07-testing.md",
        "src/Application/Catalog/FacetQuery.php",
        "src/Application/Catalog/CatalogQuery.php",
        "tests/integration/T17Test.php",
        "tests/integration/T18Test.php"
      ],
      "files": [
        "tests/integration/T17Test.php",
        "tests/integration/T18Test.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "tests/integration/T17Test.php"],
        ["php", "-l", "tests/integration/T18Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T17Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T18Test.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```

Test notes: the T17–T19 receipts are refreshed with `scripts/rerun-evidence.mjs --only T17,T18,T19` with `WPE_WP_PATH` set to the isolated root (P049 D01). The DoD runs both tests on `test_wpe_bench`, the larger test database, where crowding is worst.
