# Report

## Summary

Completed P050.1: T17 R67 and T18 R71 now scope their fixtures with run-unique category facets, so catalog products already in the test databases (which, since P028, also carry the generic black/red/m facets, prices and words the tests filtered on) can no longer crowd the fixtures off the bounded 48-result first page. Only test files changed; no assertion and no product code changed. T17 and T18 pass in full on `test_wpe_bench` with assertions enabled; the T17/T18/T19 receipts were re-run on the isolated root against `test_wpe_regress` and pass, and `node scripts/rerun-evidence.mjs --verify-only` now gives 49/49 tasks on WooCommerce 11.3.1 (P049 had 47/49). Test database residue stayed at 0. Not a WooCommerce integration certification.
