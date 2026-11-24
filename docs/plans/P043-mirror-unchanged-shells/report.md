# Report

## Summary

Completed P043.1: `LegacyMirror` writes a variation shell only when its title, status or parent changes, and primes the shells in one query before `clean_post_cache()`. Re-mirroring an unchanged 12-variation product on `test_wpe_bench` dropped from 97 to 63 queries; the P017 budget (`3 x variations + 60` = 96) is unchanged and `ImportPerformanceTest` passes. New checks: no shell write on an unchanged re-mirror, a changed variation status reaches only its shell (fails on the old code with "rewrote 12 variation shells"). Regression: MirrorWorkerEpochTest, OutboxProjectionWorkerTest, PostShellProjectionTest, ShopifyBatchImporterTest, T10, T39, T40, ProductEditorServiceTest. Not a WooCommerce integration certification.
