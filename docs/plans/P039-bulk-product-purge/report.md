# Report — P039: Complete product purge and bulk delete all products

## Summary

A permanent product delete used to cost about 1,000 queries per variable product (12 variations: 0.18–0.38 s on `test_wpe_bench`). It also left native rows behind. On live site1 the orphans are: `wpe_product_terms` 6,127, `wpe_variant_values` 1,696, `wpe_options` 438, `wpe_meta` 148, `wpe_variant_media` 136, import fingerprints 44.

- **`ProductPurger`** removes every native row of a product and its variants in one set-based pass, and retires its queued outbox events. `ProductRepository::delete($id, true)` and the `before_delete_post` lifecycle path now use it.
- **`OptionsRepository` IDs:** option and option-value IDs (`MAX(id)+1`, no lock) now move on to the next ID when the PRIMARY key is taken. That race caused the "Duplicate entry … for key PRIMARY" errors in parallel imports (job `ce25749a`).
- **`ProductBulkPurge`** plans and runs batched deletes by scope: all products, a category, a post status, or a Shopify import job (the handles in its staged CSV).
  - It skips products in orders unless asked, and refuses while an import has moved in the last 15 minutes.
  - It holds a named lock, defers term counting, and invalidates caches once at the end.
  - It records a `product_purge` job and audit entries.
- **Entry points:**
  - WP-CLI `wp wpe products purge`: dry run unless `--yes`; `--job=<id>` resumes.
  - Operations card "Danger Zone — Delete Products Permanently": Preview, then type the count, then a batched run with progress.

Speed: 100 products × 16 variations purge in 2.3 s, which is 0.023 s and about 109 queries per product. For 20k products that is roughly 8 minutes. The estimate assumes the same per-product cost and was not measured at that size.

### Verification
- On `test_wpe_bench`: ProductPurgeCompletenessTest passes (44 checks). Against the old `ProductRepository`/`OptionsRepository` it fails, reporting the leftover rows.
- Also on `test_wpe_bench`: ProductPurgeOutboxTest, ProductBulkPurgeTest (24 checks), ShopifyBatchImporterTest, ShopifyImportCategoryPathTest, ShopifyImportTaxonomyTest, VariantsRulesTest (98) and LifecycleHookRegistrationTest pass.
- `check-docs --strict`, `guard --all` and `node --check` pass.
- REST: the plan returns 200. A wrong confirmation returns 400 with the expected count. Without login the call returns 401.
- Live site1 browser check, using a fixture category with one product:
  - Preview shows "1 product(s) …".
  - Delete stays disabled until the count matches.
  - The run reaches "Done" and the product is gone.
  - No console errors.
- CLI on `test_wpe_bench`: a dry run, then `--status=trash --yes`, completed.

### Not done
- Orphan rows left by earlier deletes are not cleaned (hygiene only handles shell-less products).
- Media files are kept by design.
- No WooCommerce integration certification.
