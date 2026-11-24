# Report

## Summary

Completed P052.1, the continuation of blocked P051.2. CatalogIndexOptimizationTest now expects the schema version SchemaMigrator declares (5, at least 3) and still checks every workload index and the zero-filesort plans. QueryHydrationPerformanceTest measures its two P028 data-store budgets (post-prime loop <= 5, cold archive loop <= 32) with the variant selector UI and Single Price Display off, which their per-card price reads would otherwise add (12 and 42 queries with them on; the batched card path is budgeted by ArchiveCardBatchTest, which passes). The features stay off to the end of the test because a mid-test flags update invalidates the ContextCache the last step checks; the test restores the original flags in finally. Measured: 3 and 32 queries; budgets unchanged. All DoD commands ran with assertions enabled on `test_wpe_bench`. Not a WooCommerce integration certification.
