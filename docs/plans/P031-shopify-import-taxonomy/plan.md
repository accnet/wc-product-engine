# P031 — Shopify import: default category, tags, ignored-column report

## 1. Goal

Products imported from a Shopify CSV must belong to at least one WooCommerce category and keep their tags, and the import must say which source columns it did not import.

Measured on `site1.localhost` (2026-11-22): the 21/11 12:29 import created 9,699 products with no `product_cat` at all (11,138 indexed, 9,699 without a category facet). They are on `/shop/` but on no category page. Causes in `ShopifyBatchImporter`:
- `Product category` is parsed (`category`) but never copied into the product group; `Type`, `Tags`, `Vendor` are collected and never used.
- Not even the default category is set: shells are created `auto-draft` (WordPress adds default terms only for other statuses) and the mirror writes status and `product_type` by SQL, so WooCommerce's `force_default_term` never runs.
- None of these columns is reported: the job log is silent.

## 2. Scope

In scope:
- `Tags` → `product_tag` (comma-separated, trimmed, de-duplicated; terms created by name; the CSV list replaces the product's tags when it is non-empty; an empty cell leaves existing tags).
- Default category: after each imported product is written, a product without any `product_cat` gets `default_product_cat` (as WooCommerce does for products saved without a category). Existing categories are never removed.
- Report: job config `ignored_cols` and a WARNING line in the initial job log naming present columns with no WooCommerce mapping (`Product category`, `Type`, `Vendor`).
- Fingerprint format bump so the next import of an already imported file rewrites products (writes their tags/category) instead of skipping them as unchanged.
- One-off backfill on the live site: give every product without a category the default category; catalog facets follow through the term-sync hook.

Out of scope:
- Mapping `Product category` to WooCommerce categories (docs/10 §7: not mapped automatically; needs a separate decision).
- Vendor/Type custom meta or taxonomy mapping by config.
- Woo CSV importer (already maps Categories/Tags).

## 3. Decisions

- **D01 — Terms are WordPress-owned.** Tags and categories are written with `wp_set_object_terms` on the product shell (as `PostShellBridge::setProductCategories` does), before the product is projected into the catalog.
- **D02 — Merge-safe tags.** A non-empty `Tags` cell replaces the product's tags; an empty or absent cell does not clear them.
- **D03 — Default only when empty.** The default category is added only when the product has no `product_cat`; a category set by hand or by another import stays.
- **D04 — Report, do not drop silently.** Unmapped columns are listed once per job (config + initial log), not per row.

## 4. Documentation changes

- `docs/10-integrations.md` §7, row "Handle / title / vendor / type / tags".
- `docs/NOTES.md` — P031 entry.
- `docs/plans/P031-shopify-import-taxonomy/plan.md`, `docs/plans/P031-shopify-import-taxonomy/report.md`.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P031.1",
      "title": "Document Shopify tags, default category and ignored-column reporting in docs/10 section 7",
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
      "id": "P031.2",
      "title": "ShopifyBatchImporter: tags to product_tag, default category when empty, ignored-column report, fingerprint bump; integration test",
      "depends_on": ["P031.1"],
      "read_first": [
        "docs/10-integrations.md",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "src/Compatibility/WooDataStore/PostShellBridge.php"
      ],
      "files": [
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "tests/integration/ShopifyImportTaxonomyTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"],
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/ShopifyImportTaxonomyTest.php"],
        ["php", "tests/integration/ShopifyBatchImporterTest.php"]
      ]
    },
    {
      "id": "P031.3",
      "title": "Backfill the default category on live products without one, verify storefront category coverage, record NOTES",
      "depends_on": ["P031.2"],
      "read_first": [
        "src/Application/Catalog/CatalogProjector.php"
      ],
      "files": [
        "tests/integration/ProductCategoryCoverageLive.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/ProductCategoryCoverageLive.php"],
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    }
  ]
}
```
