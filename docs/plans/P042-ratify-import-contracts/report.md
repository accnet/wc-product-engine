# Report

## Summary

Completed P042.1: ratified the 2026-11-24 contract/docs edits from commit `5d5fe74` without changing their meaning — `idx_parent_kind (parent_id, kind)` on `wpe_object_state` (`contracts/database/schema.sql`, docs/03 §3, SchemaMigrator schema version 5) and the Shopify `category_mode=path` default (docs/10 §7). Closed the remaining drift in `contracts/database/schema-contract.json` (`object_state.keys_contract`), which now matches docs/03.

Evidence: `tests/integration/ObjectStateIndexTest.php` (4 checks; fails without the schema-contract.json fix), `ShopifyImportCategoryPathTest.php`, `ShopifyImportTaxonomyTest.php` on `test_wpe_bench`; site1 already has the index and schema version 5. ImportPerformanceTest's pre-existing 97/96 mirror budget failure is out of scope. Not a WooCommerce integration certification.
