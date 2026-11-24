# Report

## Summary

Completed P046.1: the variation loop of `LegacyCapture::captureLegacyProduct()` reads variation meta and variant existence once for all variations and writes inventory and SKU registry rows with one multi-row REPLACE each, in loop order; the per-variation variant UPDATE/INSERT stays. On `test_wpe_bench` capture grows by 1 query per variation instead of 5 (16 variations: 97 -> 37 queries). `CaptureVariationBatchTest.php` (3 checks) compares every written row with the previous per-variation rules across SKU, price and stock edge cases and an update-path variant, and fails on the old code (5 queries per variation). Regression: LegacyHashBatchTest, T37, T38, T39, T40, CohortRoutingDataStoreTest, SingleModeOperationsTest with assertions enabled. Not a WooCommerce integration certification.
