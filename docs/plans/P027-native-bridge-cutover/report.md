# Report

## Summary

P027 closes the runtime wiring, authority-enum, outbox-epoch, and state-synchronization gaps required to transition a store safely from `legacy` through `shadow` and `indexed` to `native_bridge` (canary cohort first, then full eligible catalog) and back via governed rollback.

| Task | Scope | Outcome |
|---|---|---|
| `P027.1` | `AuthorityResolver`, `CohortRoutingDataStore`, conditional `PluginBootstrap` bridge wiring, `MetaBridge` origin/mode guard, `InventoryBridge` mixed-order guard | Passed (`CohortRoutingDataStoreTest`, `LegacyOnlyPropsTest`) |
| `P027.2` | `ProductRepository` `authority_epoch` & `native_revision` tracking, `LegacyMirror` `native_bridge` preservation & lag metrics, deduplicating `OutboxProjectionWorker` | Passed (`OutboxProjectionWorkerTest`, `MirrorWorkerEpochTest`) |
| `P027.3` | `CutoverRunner`/`RollbackRunner` atomic state sync (`wpe_control` + `wpe_cohort_routing` + `cohort_mode`), `reconcileFinalGaps()`, accurate `OperationsPage` counts, CLI `mode`/`outbox`/`gates`/`cutover`/`hygiene` | Passed (`NativeBridgeRolloutTest`) |
| `P027.4` | Re-certification of touched roadmap tasks (`T02`, `T06`, `T11`, `T12`, `T39`, `T40`, `T41`, `T43`) on isolated `test_wpe_regress` root | Passed (`verify-task.mjs` exit 0 for all 8 tasks) |

## Architectural fixes delivered

1. **Per-product DataStore routing (`CohortRoutingDataStore` + `AuthorityResolver`):** WooCommerce caches a single DataStore instance per product type (`product`, `product-simple`, `product-variable`, `product-variation`). `CohortRoutingDataStore` routes each product/variation operation to `ProductDataStore` when governed by `['native', 'native_bridge', 'full_native']` and delegates cleanly to `WC_Product_Data_Store_CPT` for `legacy` cohorts, `grouped`/`external` products, and products blocked by `LegacyOnlyProps`.
2. **Unified authority reader semantics:** Preserved `CutoverRunner`'s `'native_bridge'` and `AuthoritySeal`'s `'full_native'` state values while updating `MetaBridge`, `InventoryBridge`, `LegacyMirror`, and `OperationsPage` to treat `['native', 'native_bridge', 'full_native']` uniformly as native authority. Added `LegacyCapture::getOrigin() === 'native_projection'` bypass in `MetaBridge` so `LegacyMirror` projections never trigger recursive interception.
3. **Outbox epoch alignment & coalesced projection (`OutboxProjectionWorker`):** `ProductRepository` now stamps outbox events with `max(current_epoch, authority_epoch)` and leaves `native_applied < native_revision` under active mirroring cohorts (`cohort_canary`, `native_bridge`) until `OutboxProjectionWorker` mirrors the aggregate to `wp_posts`/`wp_postmeta`/`wc_product_meta_lookup` and incrementally refreshes `wpe_catalog`.
4. **Synchronized rollout/rollback state & operator runbook CLI:** `CutoverRunner` and `RollbackRunner` keep `wpe_object_state`, `wpe_control` (`system_mode`, `authority_epoch`), `wpe_cohort_routing`, and `wpe_feature_flags['cohort_mode']` synchronized. `OperationsPage::getHealthReport()` excludes empty `auto-draft` WP shells from `legacy_products` and counts `dead_letter` in DLQ.

## Governed operator runbook (`legacy -> shadow -> indexed -> native_bridge`)

```bash
# 0. Inspect and clean unreferenced pre-cutover residue (after DB backup)
wp wpe hygiene
wp wpe hygiene --apply
wp wpe outbox --batch-size=100 --max-batches=100

# 1. Shadow mode & parity audit
wp wpe mode shadow
wp wpe migrate --batch-size=250
wp wpe audit --limit=500
wp wpe health

# 2. Indexed catalog read mode
wp wpe rebuild
wp wpe mode indexed

# 3. Canary cutover to native_bridge (selected product IDs)
wp wpe gates --cohort=canary_1
wp wpe cutover --cohort=canary_1 --product-ids=<id1,id2> --token=<gate_receipt_token>
wp wpe outbox

# 4. Full eligible catalog cutover to native_bridge
wp wpe gates --cohort=all
wp wpe cutover --cohort=all --token=<gate_receipt_token>
```

## Verification limits

All write and cutover tests in P027 ran against the isolated WordPress root (`test_wpe_regress`); `site1.localhost` remained untouched in `legacy` mode unless explicitly cut over by operator command. This is evidence at `site1.localhost` scale, not an independent WooCommerce ecosystem audit or 40k/100k benchmark.
