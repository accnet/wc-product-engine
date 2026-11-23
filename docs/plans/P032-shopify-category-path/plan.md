# P032 — Shopify import: Product category path to WooCommerce category tree

## 1. Goal

Let a Shopify CSV import turn the `Product category` column (`A > B > C`) into a WooCommerce `product_cat` tree and file each product under the leaf, when the operator chooses it for the job. The user chose this mapping on 2026-11-22 after P031 (which kept the column unmapped per docs/10 §7).

Category archives of parent categories must then list products filed under their descendants, as WordPress does (`include_children`). Measured: `CatalogAdapters::adaptWcQueryArgs()` ignores `include_children`, so a parent category page served by the engine would be empty.

## 2. Scope

In scope:
- Import option `category_mode`: `none` (default, unchanged behavior: column reported in `ignored_cols`) or `path`. REST `shopify-import/init` accepts it (whitelisted); the import screen gets a "Product Category" select; stored in job config and in the product fingerprint.
- `path`: decode HTML entities, split on `>`, trim, drop empty segments; find each segment under its parent by name (`term_exists`) or create it; the product's categories become the leaf plus any existing category other than the default one. An empty cell changes nothing; the default category still applies to a product left without category.
- `CatalogAdapters`: `product_cat` values from query vars and tax_query clauses expand to descendant term IDs unless the clause sets `include_children` to false; `term_taxonomy_id` values are resolved to term IDs.
- Live backfill: apply the path mapping to the products of the 21/11 12:29 import from its staged CSV (`URL handle` → product slug).

Out of scope:
- Vendor/Type mapping; Woo CSV importer; Shopify standard taxonomy IDs (only the text path is used).

## 3. Decisions

- **D01 — Leaf only.** Products are assigned to the leaf term, as WooCommerce's CSV importer does for `A > B`; parents list them through `include_children`.
- **D02 — Mapping replaces only the default.** When a path is mapped, `default_product_cat` is dropped from the product and other existing categories stay.
- **D03 — Name match under parent.** Segments match existing terms by name under the same parent (case-insensitive, WordPress `term_exists`); a new term gets WordPress's unique slug.
- **D04 — Opt-in per job.** Default `none` keeps docs/10 §7's "not mapped automatically"; `path` is an explicit operator choice recorded in the job config.

## 4. Documentation changes

- `docs/10-integrations.md` §7, row "Handle / title / vendor / type / tags".
- `docs/NOTES.md` — P032 entry.
- `docs/plans/P032-shopify-category-path/plan.md`, `docs/plans/P032-shopify-category-path/report.md`.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P032.1",
      "title": "Document the opt-in Product category path mapping in docs/10 section 7",
      "depends_on": [],
      "read_first": [
        "docs/10-integrations.md"
      ],
      "files": [
        "docs/10-integrations.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    },
    {
      "id": "P032.2",
      "title": "category_mode import option (importer, REST, import screen) and include_children in the catalog adapter; tests",
      "depends_on": ["P032.1"],
      "read_first": [
        "docs/10-integrations.md",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "src/Application/Catalog/CatalogAdapters.php"
      ],
      "files": [
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "src/Admin/Rest/AdminApiController.php",
        "src/Admin/ShopifyImportPage.php",
        "assets/admin/shopify-import.js",
        "src/Application/Catalog/CatalogAdapters.php",
        "tests/integration/ShopifyImportCategoryPathTest.php",
        "tests/integration/CatalogCategoryChildrenTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"],
        ["php", "-l", "src/Admin/Rest/AdminApiController.php"],
        ["php", "-l", "src/Admin/ShopifyImportPage.php"],
        ["php", "-l", "src/Application/Catalog/CatalogAdapters.php"],
        ["node", "--check", "assets/admin/shopify-import.js"],
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/ShopifyImportCategoryPathTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/CatalogCategoryChildrenTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/ShopifyImportTaxonomyTest.php"],
        ["php", "tests/integration/ShopifyBatchImporterTest.php"],
        ["php", "tests/integration/CatalogAdapterScopeTest.php"],
        ["php", "tests/integration/T19Test.php"]
      ]
    },
    {
      "id": "P032.3",
      "title": "Backfill the category tree on the 21/11 12:29 import from its CSV; verify parent and leaf archives live; NOTES",
      "depends_on": ["P032.2"],
      "read_first": [
        "tests/integration/ProductCategoryCoverageLive.php"
      ],
      "files": [
        "tests/integration/ShopifyCategoryPathLive.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/ShopifyCategoryPathLive.php"],
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/ProductCategoryCoverageLive.php"],
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    }
  ]
}
```
