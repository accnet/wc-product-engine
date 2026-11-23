# Report — P034: Streamline Settings and Operations UI for single-mode native_bridge

## Summary

Streamlined the WC Product Engine Settings and Operations experience around `native_bridge` as the single standard operating mode, eliminating unsafe mode-toggle switches and pre-cutover `shadow`/`indexed` rollout clutter from the UI while retaining core `legacy` fallback routing (for unsupported product types and `_customs_*` props) and an isolated emergency rollback escape hatch.

### Changes delivered
- **UI specification (`P034.1`)**: Updated `docs/09-ui.md` §6 (`Operations dashboard`) for the single-mode `native_bridge` Operations workflow (`outbox`, `rebuild`, `import_legacy`, and isolated Danger Zone `rollback` modal).
- **Streamlined Settings & Operations UX (`P034.2`)**:
  - Removed the redundant `Default routing` card from `SettingsPage` Overview (`tab=overview`), keeping read-only authority diagnostics under `Advanced`, and added a `Needs attention` warning when `outbox_pending >= 50`.
  - Replaced pre-cutover `Run Shadow Diff Audit` and `Run Keyset Backfill Batch` UI buttons on both `Settings → Operations & Health` and standalone `WooCommerce → Engine Operations` with the 3 daily catalog actions: `Sync Legacy Mirror (Drain Outbox)` (`outbox`), `Rebuild Catalog Index` (`rebuild`), and `Import Legacy Woo Products` (`import_legacy`), isolating `Rollback to WooCommerce Legacy` (`rollback`) inside `.wpe-card--danger` with modal confirmation and zero inline styles.
  - Added residual `wpe_object_state` lag reconciliation inside `OperationsPage::handleAction('outbox')` so draining an empty outbox mirrors active lagging aggregates and marks deleted/orphan state rows tombstoned, allowing health status to recover cleanly to `HEALTHY`.
  - Implemented one-step `import_legacy` (`OperationsPage::importLegacyProducts()` + `wp wpe import-legacy`) that captures unmigrated WooCommerce `simple`/`variable` products, skips unsupported product types (`grouped`, `external`, bundles, subscriptions) and `LegacyOnlyProps` (`_customs_*`), promotes imported products and variations directly to `native_bridge`, and updates `wpe_catalog`.
  - Fixed `LegacyCapture::isNativeAuthority()` to recognize `native_bridge` and `full_native` alongside `native`.
  - Updated `assets/admin/settings.js`, `assets/operations/operations.js`, `assets/admin/settings.css`, and `assets/admin/admin.css`.
- **Live outbox drain & isolated test verification**:
  - Drained the `site1.localhost` backlog via `wp wpe outbox` (`25,747` outbox events completed, `12,728` mirrored, `723` stale/deleted retired; `outbox_pending = 0`, `projection_lag = 0`, status `HEALTHY`).
  - Verified on `WPE_TEST_DB` (`test_wpe_bench`): `SingleModeOperationsTest.php`, `T41Test.php`, `T37Test.php`, `AccessControlTest.php` (29 checks), and `SettingsApiTest.php` (136 checks) — all exit 0.
