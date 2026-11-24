# Report

## Summary

Completed P040 — storefront query budget on site1 (15,475 products / 205,044 variations / 221,949 inventory rows), measured with `SAVEQUERIES` and a full template render, and over HTTP without the page cache (average of 3).

| Page | Before | After P040.1 | After P040.2 |
|---|---|---|---|
| `/shop/` | 480 q / 751 ms SQL / 0.87–0.90 s | 134 q / 40 ms / 0.21 s | 134 q / 43 ms / 0.23 s |
| Category (9,699 products) | 443 q / 688 ms / 0.84 s | 141 q / 46 ms / 0.18 s | 0.19 s |
| Product, 8 variations | 593 q / 512 ms / 0.65 s | 204 q / 39 ms / 0.17 s | 92 q / 19 ms / 0.16 s |
| Product, 102 variations | 3,245 q / 949 ms / 1.30 s | 1,435 q / 239 ms / 0.46 s | 101 q / 32 ms / 0.21 s |

- P040.1: inventory signatures in `SelectorAdapter` read `wpe_inventory` by primary key (were full scans, same signature); `getProductCacheKey()` memoized per request; `MetaBridge` skips cached non-product posts; `AuthorityResolver::probeObject()` kept per request on read-only storefront requests (filter `wpe_authority_request_memo`).
- P040.2: variation families primed in bulk on first variation read; `wpe_meta` loaded once per object; both only on read-only storefront requests. Eviction of the new keys is in amendment P041.1.
- Rendered HTML identical before and after (except random `quantity_*` ids).
- Tests: `StorefrontQueryBudgetTest.php` (16 checks), selector, PDP, cohort routing and hydration tests on `test_wpe_bench`.
- Known pre-existing failures, unchanged: `QueryHydrationPerformanceTest` with assertions enabled (12 > 5 queries), `NativeBridgeRolloutTest` (missing gate receipt token).
- Measured at site1 scale; not a WooCommerce integration certification or G09 scale gate.
