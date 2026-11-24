# P039 — Complete product purge and bulk delete all products

## 1. Goal

Store owners need to remove a whole catalog (for example 20k imported products that must be re-imported with categories) without hours of per-post deletes. Today a permanent delete costs about 1,000 queries per variable product and still leaves native child rows behind (`wpe_options`, `wpe_option_values`, `wpe_variant_values`, `wpe_variant_media`, `wpe_product_terms`, `wpe_meta`, `shopify_import_fp:*` keys). Option and option-value IDs are allocated with an unlocked `MAX(id)+1`, so concurrent imports fail with "Duplicate entry … for key PRIMARY".

## 2. Scope

In scope:
- `ProductPurger`: one set-based removal of every native row owned by a list of products and their variants (all `wpe_*` product tables, catalog rows of every generation, import fingerprints, queued outbox events). `ProductRepository::delete($id, true)` uses it, so a single permanent delete is complete too.
- Option / option-value ID allocation retries on a duplicate primary key with the next candidate ID.
- `ProductBulkPurge`: dry-run plan and batched purge of products selected by all / import job / status / category, keyset by ID, with a `wpe_jobs` record (`product_purge`) and an audit entry. Native rows go through `ProductPurger`, variation shells and their meta/term rows/lookup rows are removed set-based, parent shells go through `wp_delete_post` inside the projection guard so third-party `delete_post` hooks still run. Legacy-authority products are deleted with `wp_delete_post`.
- WP-CLI `wp wpe products purge` (dry-run unless `--yes`) and Operations action `purge_products` (dry-run / one step of batches) with a Danger Zone card that requires typing the product count.
- Tests, docs 08 §9 and 09 §6.

Out of scope: deleting media files or attachments, deleting taxonomy terms, deleting orders or order items, trash-based bulk delete.

## 3. Decisions

- **D01** — Products referenced by order items (`_product_id` / `_variation_id`) are skipped unless `include_ordered` is set. Orders keep their item names either way.
- **D02** — Media is kept. The result reports the count of attachments that were attached to purged products so `wp wpe media report` can follow up.
- **D03** — The purge refuses to start while a Shopify import job is `pending` or `in_progress`, and holds a MySQL named lock so two purges never run together.
- **D04** — Term counts of `product_cat` / `product_tag` / `product_brand` are recounted once at the end; WooCommerce product transients and the catalog context cache are invalidated once at the end, not per product.
- **D05** — Without `--yes` (CLI) or a confirmation equal to the selected count (Operations), the action is a dry run.

## 4. Documentation changes

- `docs/08-operations.md` §9 (bulk purge runbook paragraph).
- `docs/09-ui.md` §6 (Operations Danger Zone purge card).
- `docs/NOTES.md` (append entry).

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P039.1",
      "title": "ProductPurger removes every native row of a purged product; option IDs retry on duplicate key",
      "depends_on": [],
      "read_first": [
        "docs/03-database-schema.md",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Infrastructure/Database/OptionsRepository.php",
        "src/Compatibility/WooDataStore/ProductLifecycle.php"
      ],
      "files": [
        "src/Infrastructure/Database/ProductPurger.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Infrastructure/Database/OptionsRepository.php",
        "tests/integration/ProductPurgeCompletenessTest.php",
        "tests/integration/ProductPurgeOutboxTest.php"
      ],
      "dod": [
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPurgeCompletenessTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPurgeOutboxTest.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P039.2",
      "title": "Bulk product purge: dry-run plan, batched purge job, WP-CLI command and Operations Danger Zone action",
      "depends_on": ["P039.1"],
      "read_first": [
        "docs/08-operations.md",
        "docs/09-ui.md",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Cli/Commands.php"
      ],
      "files": [
        "src/Application/Catalog/ProductBulkPurge.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "assets/admin/settings.js",
        "src/Cli/Commands.php",
        "tests/integration/ProductBulkPurgeTest.php",
        "docs/08-operations.md",
        "docs/09-ui.md",
        "docs/NOTES.md"
      ],
      "dod": [
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductBulkPurgeTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPurgeCompletenessTest.php"],
        ["node", "--check", "assets/admin/settings.js"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
