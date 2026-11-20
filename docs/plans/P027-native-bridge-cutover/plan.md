# P027 — Runtime wiring and governed cutover to native_bridge

## 1. Goal

Bridge the runtime wiring and state-synchronization gaps between the individually certified domain services (`ProductDataStore`, `MetaBridge`, `InventoryBridge`, `AdminApiBridge`, `CatalogAdapters`, `CartValidation`, `LegacyCapture`, `LegacyMirror`, `OutboxWorker`, `CutoverRunner`, `RollbackRunner`, `ReadinessGates`, `OperationsPage`) and live WordPress/WooCommerce bootstrap so that a store can transition safely through `legacy -> shadow -> indexed -> native_bridge` (canary cohort first, then full eligible catalog) without breaking legacy-only products or losing rollback parity.

Deep code and database audit on 2026-11-20 identified five concrete blockers preventing live `native_bridge` operation today:

1. **Unwired runtime DataStore and bridges in `PluginBootstrap`:**
   - `PluginBootstrap::filterDataStores()` (`src/Bootstrap/PluginBootstrap.php:331-340`) is still a stub returning `$stores` unchanged even when `native_writes_enabled = true`.
   - Simply registering `ProductDataStore::registerDataStores()` globally would break mixed-authority catalogs (`cohort_canary`, `grouped`/`external` products, or products with `LegacyOnlyProps` customs data from P021) because WooCommerce resolves one Data Store class per product type (`product`, `product-simple`, `product-variable`, `product-variation`), not per product ID.
   - `CatalogAdapters`, `CartValidation`, `InventoryBridge`, `MetaBridge`, `AdminApiBridge`, `ImageSurfaces`, `WooImporter`/`WooExporter`, and `LegacyCapture` are not conditionally booted in `PluginBootstrap::onPluginsLoaded()`.
2. **`wpe_object_state.authority` enum divergence across readers and writers:**
   - `CutoverRunner::performAtomicEpochFlip()` (`src/Application/Rollout/CutoverRunner.php:470`) writes `authority = 'native_bridge'` (asserted by `T43Test` and `T49Test`), and `AuthoritySeal` writes `authority = 'full_native'` (asserted by `T45Test` and `RetentionCleaner`).
   - Meanwhile, `MetaBridge::isNativeAuthority()` (`src/Compatibility/WooDataStore/MetaBridge.php:135`), `LegacyMirror` (`src/Application/Migration/LegacyMirror.php:645,707,727`), and `OperationsPage::getHealthReport()` (`src/Admin/OperationsPage.php:185`) check only `authority = 'native'`, ignoring `'native_bridge'` and `'full_native'`. Conversely, when no `wpe_object_state` row exists, `MetaBridge::isNativeAuthority()` checks `SELECT COUNT(1) FROM wpe_products`, which would falsely hijack shadowed rows if `MetaBridge` were active during `shadow`/`indexed`.
3. **Outbox epoch and native revision drift (`P009` follow-up) & missing Outbox Drain Worker:**
   - `ProductRepository` (`src/Infrastructure/Database/ProductRepository.php:96`) stamps `wpe_outbox.epoch` from `OutboxWorker::getCurrentEpoch()` (`current_epoch = 1`) instead of `LegacyMirror::getAggregateEpoch($productId)` (`authority_epoch = 2+`), and native DataStore writes do not advance `wpe_object_state.native_revision`.
   - There is no CLI/cron outbox consumer draining `wpe_outbox` into `LegacyMirror::mirrorAggregate()` and `CatalogProjector::projectProduct()` (`site1.localhost` currently has `4,763` pending outbox events at `epoch = 1` and `33` lagging projections).
4. **Three-way state split between `CutoverRunner`, `OperationsPage`, and `PluginBootstrap`, plus missing CLI rollout commands:**
   - `CutoverRunner` updates `wpe_object_state` and `wpe_control['cohort_active_*']`, `OperationsPage` reads `wpe_control['system_mode']` (and counts `149` `auto-draft` WP posts as Legacy Products), while `PluginBootstrap::resolveProductAuthority()` reads `wp_options` (`wpe_cohort_routing` and `wpe_feature_flags['cohort_mode']`).
   - `CutoverRunner::reconcileFinalGaps()` is currently a no-op placeholder, and `src/Cli/Commands.php` has no governed `mode`, `outbox`, `gates`, or `cutover` subcommands.
5. **Pre-cutover data residue on `site1.localhost`:**
   - All `71` published WP products (`4` simple, `67` variable, `0` customs/unsupported) already exist in `wpe_products`, alongside `44` non-published residue rows in `wpe_products` (`22` `auto-draft` shells, `22` missing WP shells), `211` orphan `wpe_object_state` product rows, and `4,763` historical `pending` outbox rows.

## 2. Scope

In scope:
- Unified runtime authority helper (`src/Support/AuthorityResolver.php`) recognizing `['native', 'native_bridge', 'full_native']` as native authority, respecting tombstones, `LegacyOnlyProps`, and unsupported product types (`grouped`, `external`, custom).
- Cohort-aware WooCommerce DataStore router (`src/Compatibility/WooDataStore/CohortRoutingDataStore.php`) delegating per-product operations to native `ProductDataStore` / `ProductVariableDataStore` / `ProductVariationDataStore` when under native authority and to `WC_Product_Data_Store_CPT` / `WC_Product_Variable_Data_Store_CPT` / `WC_Product_Variation_Data_Store_CPT` when under legacy authority.
- Conditional runtime hook wiring in `PluginBootstrap` for `CohortRoutingDataStore`, `CatalogAdapters`, `CartValidation`, `InventoryBridge`, `MetaBridge`, `AdminApiBridge`, `ImageSurfaces`, `WooImporter`/`WooExporter`, and `LegacyCapture`.
- Outbox epoch alignment in `ProductRepository`, native revision tracking in `wpe_object_state`, `LegacyMirror` recognition of `native_bridge`, and a deduplicating `OutboxProjectionWorker` (`src/Application/Migration/OutboxProjectionWorker.php`) exposed via `wp wpe outbox`.
- Atomic synchronization of `system_mode`, `authority_epoch`, `wpe_cohort_routing`, and `wpe_feature_flags['cohort_mode']` across `CutoverRunner` and `RollbackRunner`, real `reconcileFinalGaps()` execution in `CutoverRunner`, accurate `OperationsPage::getHealthReport()` counts (excluding `auto-draft` posts, counting `native`/`native_bridge`/`full_native`, including `dead_letter` in DLQ), and governed CLI subcommands (`wp wpe mode`, `wp wpe outbox`, `wp wpe gates`, `wp wpe cutover`, `wp wpe hygiene`).
- Integration tests on an isolated WordPress root covering mixed-cohort CRUD, checkout stock reduction/restock, `native_bridge` outbox mirroring, canary cutover, and full rollback, plus re-certification of affected roadmap receipts (`T02`, `T06`, `T11`, `T12`, `T39`, `T40`, `T41`, `T43`).

Out of scope:
- Executing an irreversible live cutover on `site1.localhost` or production without separate explicit operator cutover authorization (`AGENTS.md` §4).
- Sealing `full_native` (`AuthoritySeal`) or running postmeta cleanup (`RetentionCleaner` / `T46`).
- Modifying WordPress or WooCommerce Core files.
- Native storage for WC 11.3 customs props (`_customs_*` stays legacy-only per P021 / OD-18) or `grouped`/`external` product types.

## 3. Decisions

- **D01: Preserve `CutoverRunner` and `AuthoritySeal` state values; unify all readers.** `CutoverRunner` continues writing `wpe_object_state.authority = 'native_bridge'` and `AuthoritySeal` continues writing `'full_native'` so `T43`, `T45`, `T46`, and `T49` contracts remain unchanged. Every runtime reader (`AuthorityResolver`, `MetaBridge`, `InventoryBridge`, `LegacyMirror`, `OperationsPage`) treats `in_array($authority, ['native', 'native_bridge', 'full_native'], true)` as native authority, while `RetentionCleaner` continues requiring `'full_native'`.
- **D02: Per-product cohort routing in WooCommerce DataStores.** Because WooCommerce caches DataStore instances per product type, `CohortRoutingDataStore` wraps native and CPT stores and routes each call by inspecting `AuthorityResolver::isNativeProduct($id)` (for existing IDs) and target cohort mode + supported type (`simple`, `variable`, `variation`) + `LegacyOnlyProps` check (for `create`). Mixed carts and canary cohorts work side-by-side in the same request.
- **D03: Origin-guarded `MetaBridge` and mixed-order `InventoryBridge`.** `MetaBridge` bypasses interception whenever `LegacyCapture::getCurrentOrigin() === 'native_projection'` (so `LegacyMirror` never loops) and checks `wpe_object_state` first; when no state row exists, it falls back to `PluginBootstrap::resolveProductAuthority($id)` unless `ProductDataStore` was registered directly in standalone integration tests. `InventoryBridge` order hooks process native-authority line items and leave legacy-authority line items to WooCommerce CPT stock handling.
- **D04: Outbox carries `authority_epoch` and deduplicates per aggregate batch.** Native mutations stamp outbox events with `max(OutboxWorker::getCurrentEpoch(), LegacyMirror::getAggregateEpoch($aggregateId))` and increment `wpe_object_state.native_revision`. `OutboxProjectionWorker` groups acquired events by `aggregate_id`, projects only the highest revision via `LegacyMirror::mirrorAggregate()` (when authority is `native` or `native_bridge`) plus incremental `CatalogProjector::projectProduct()`, and retires stale pre-cutover or deleted-aggregate events with audit records rather than dead-letter loops.
- **D05: Single atomic state view across `wpe_object_state`, `wpe_control`, and `wp_options`.** `CutoverRunner` updates `wpe_control['authority_epoch']`, `wpe_control['system_mode']`, `wpe_cohort_routing` (for product/category canary cohorts), and `wpe_feature_flags['cohort_mode']` (`cohort_canary` for scoped cohorts, `native_bridge` for `all`); `RollbackRunner` restores them cleanly to `legacy` / `legacy_restored`.
- **D06: Non-destructive hygiene & isolated verification first.** All integration and cutover drills in this plan run against the isolated test WordPress root so `site1.localhost` is never mutated without explicit operator cutover execution; `wp wpe hygiene --dry-run` reports orphan shells/state/outbox residue before any cleanup is applied.

## 4. Documentation changes

- `docs/NOTES.md` (record P027 architectural audit findings, runtime wiring design, and `native_bridge` cutover runbook)
- `docs/plans/P027-native-bridge-cutover/plan.md`
- `docs/plans/P027-native-bridge-cutover/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P027.1",
      "title": "Unified AuthorityResolver, CohortRoutingDataStore, and conditional runtime bridge wiring in PluginBootstrap",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/03-database-schema.md",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "src/Compatibility/WooDataStore/MetaBridge.php",
        "src/Compatibility/WooDataStore/InventoryBridge.php"
      ],
      "files": [
        "src/Support/AuthorityResolver.php",
        "src/Compatibility/WooDataStore/CohortRoutingDataStore.php",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Compatibility/WooDataStore/MetaBridge.php",
        "src/Compatibility/WooDataStore/InventoryBridge.php",
        "tests/integration/CohortRoutingDataStoreTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Support/AuthorityResolver.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/CohortRoutingDataStore.php"],
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/MetaBridge.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/InventoryBridge.php"],
        ["php", "-l", "tests/integration/CohortRoutingDataStoreTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P027.2",
      "title": "Outbox authority_epoch alignment, native_revision tracking, LegacyMirror native_bridge support, and OutboxProjectionWorker",
      "depends_on": ["P027.1"],
      "read_first": [
        "docs/03-database-schema.md",
        "docs/08-operations.md",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Infrastructure/Database/OutboxWorker.php",
        "src/Application/Migration/LegacyMirror.php"
      ],
      "files": [
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Application/Migration/LegacyMirror.php",
        "src/Application/Migration/OutboxProjectionWorker.php",
        "tests/integration/OutboxProjectionWorkerTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Infrastructure/Database/ProductRepository.php"],
        ["php", "-l", "src/Application/Migration/LegacyMirror.php"],
        ["php", "-l", "src/Application/Migration/OutboxProjectionWorker.php"],
        ["php", "-l", "tests/integration/OutboxProjectionWorkerTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P027.3",
      "title": "Synchronize CutoverRunner/RollbackRunner state, accurate OperationsPage health counts, and CLI rollout/hygiene commands",
      "depends_on": ["P027.2"],
      "read_first": [
        "docs/02-domain.md",
        "docs/08-operations.md",
        "src/Application/Rollout/CutoverRunner.php",
        "src/Application/Migration/RollbackRunner.php",
        "src/Admin/OperationsPage.php",
        "src/Cli/Commands.php"
      ],
      "files": [
        "src/Application/Rollout/CutoverRunner.php",
        "src/Application/Migration/RollbackRunner.php",
        "src/Admin/OperationsPage.php",
        "src/Cli/Commands.php",
        "tests/integration/NativeBridgeRolloutTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Rollout/CutoverRunner.php"],
        ["php", "-l", "src/Application/Migration/RollbackRunner.php"],
        ["php", "-l", "src/Admin/OperationsPage.php"],
        ["php", "-l", "src/Cli/Commands.php"],
        ["php", "-l", "tests/integration/NativeBridgeRolloutTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P027.4",
      "title": "Isolated regression certification for T02/T06/T11/T12/T39/T40/T41/T43, end-to-end native_bridge drill, NOTES and report",
      "depends_on": ["P027.3"],
      "read_first": [
        "docs/07-testing.md",
        "docs/plans/README.md"
      ],
      "files": [
        "docs/evidence/T02/result.json",
        "docs/evidence/T06/result.json",
        "docs/evidence/T11/result.json",
        "docs/evidence/T12/result.json",
        "docs/evidence/T39/result.json",
        "docs/evidence/T40/result.json",
        "docs/evidence/T41/result.json",
        "docs/evidence/T43/result.json",
        "docs/NOTES.md",
        "docs/plans/P027-native-bridge-cutover/report.md"
      ],
      "dod": [
        ["node", "scripts/verify-task.mjs", "T02"],
        ["node", "scripts/verify-task.mjs", "T06"],
        ["node", "scripts/verify-task.mjs", "T11"],
        ["node", "scripts/verify-task.mjs", "T12"],
        ["node", "scripts/verify-task.mjs", "T39"],
        ["node", "scripts/verify-task.mjs", "T40"],
        ["node", "scripts/verify-task.mjs", "T41"],
        ["node", "scripts/verify-task.mjs", "T43"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```

Test notes: Write-heavy integration suites (`CohortRoutingDataStoreTest.php`, `OutboxProjectionWorkerTest.php`, `NativeBridgeRolloutTest.php`, and the roadmap task runners for `T02`, `T06`, `T11`, `T12`, `T39`, `T40`, `T41`, `T43`) execute strictly against the isolated regression WordPress root (`wp --path=<isolated_root> eval-file ...`) so `site1.localhost` is never mutated during verification.
