# Report

## Summary

Completed P041.1: `AuthorityResolver::evictObject()` also drops `var_bundle_<id>`, `meta_map_<id>` and `var_family_<id>` (with every variation bundle the family lists), so writers that already evict an object or its parent invalidate the P040.2 request caches. Verified by the P040.2 eviction checks in `tests/integration/StorefrontQueryBudgetTest.php`, which fail without this change ("Evicting the parent must drop its variation bundles, got 34.000000"). Not a WooCommerce integration certification.
