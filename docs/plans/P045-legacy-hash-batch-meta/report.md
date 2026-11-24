# Report

## Summary

Completed P045.1: `LegacyCapture::computeLegacyHash()` reads the `_sku`, `_price` and `_stock` postmeta of all variations in one ordered query instead of one full postmeta read per variation; the hash payload and value are unchanged. On `test_wpe_bench`: 102-variation product 106 -> 5 queries, unchanged 12-variation re-mirror 63 -> 52 queries (P017 budget 96). `LegacyHashBatchTest.php` (4 checks) compares against a verbatim copy of the previous algorithm on 7 products and with duplicated/missing/empty variation meta, and fails on the old code (106 queries). Read-only comparison on site1: 42 products, 0 hash mismatches. The capture sync loop still reads variation meta one by one (out of scope). Not a WooCommerce integration certification.
