# Report

## Summary

Shopify CSV imports now give every product a WooCommerce category and keep its tags, and the job says which columns it did not import.

| Task | Change |
|---|---|
| P031.1 | docs/10 §7: Tags → `product_tag`, default category when a product has none, unmapped columns reported |
| P031.2 | `ShopifyBatchImporter::applyProductTaxonomy()` after the mirror and before catalog projection; `ignored_cols` + initial-log WARNING; import fingerprint format 2; `ShopifyImportTaxonomyTest.php` |
| P031.3 | Live backfill of `Uncategorized` on the 9,699 products of the 21/11 12:29 import (no other product lacked a category); `ProductCategoryCoverageLive.php`; NOTES |

Root cause: the importer never wrote terms, and WooCommerce's default category never applied because shells are created `auto-draft` and the mirror writes status and `product_type` by SQL (no `set_object_terms`, so `force_default_term` never ran).

Not done (needs a decision, docs/10 §7 forbids it today): mapping Shopify `Product category` (here `Apparel & Accessories > Clothing > Shirts & Tops > All Over Print Hoodie`, HTML-escaped in the file) to WooCommerce categories. Vendor/Type mapping by config is also not implemented.

Verification: ShopifyImportTaxonomyTest (10), ShopifyBatchImporterTest, MirrorWorkerEpochTest (23, assertions on), ImportPerformanceTest on `test_wpe_bench`, ProductCategoryCoverageLive (0 without category; 11,138 indexed with a category facet; `uncategorized` archive 11,138). Shop and `/product-category/uncategorized/` both show 11,138 results.
