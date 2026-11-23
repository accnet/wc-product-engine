# Report — P033: Default native_bridge mode and feature flags on install

## Summary

`native_bridge` is now the default mode and routing authority of WC Product Engine out of the box, paired with the full core engine and Product Page feature flag suite (`media_enabled` remaining `false`).

### Changes delivered
- **Architecture documentation (`P033.1`)**: Updated `docs/01-architecture-and-stack.md` §5 (`Modes và flags`) to document `native_bridge` and `DEFAULT_FLAGS` activation behavior alongside non-destructive seeding of `system_mode` and `authority_epoch`.
- **Bootstrap defaults & control seeding (`P033.2`)**:
  - Updated `PluginBootstrap::DEFAULT_FLAGS` (`src/Bootstrap/PluginBootstrap.php`): `cohort_mode = 'native_bridge'`, `media_enabled = false`, and all 12 core engine and Product Page flags (`variants_ui_enabled`, `query_enabled`, `resolver_enabled`, `native_writes_enabled`, `single_price_display`, `show_selected_value`, `show_help_text`, `button_selector`, `color_swatches`, `image_swatches`, `mark_unavailable_options`, `auto_select_first_values`) set to `true`.
  - Added `PluginBootstrap::ensureDefaultControlState()` invoked by `onActivate()` and first-time `ensureSchema()` (`$installed === 0`) using `INSERT IGNORE` on `wpe_control` (`system_mode = 'native_bridge'`, `authority_epoch = '1'`, `cohort_active_all`), preserving any existing saved options, epoch counters, or explicit rollback states on re-activation or upgrade.
  - Aligned fallback mode and flag resolution in `OperationsPage`, `SettingsPage`, `AuthorityResolver`, `ProductRepository`, and `SelectorAdapter`.
- **Verification (`WPE_TEST_DB` = `test_wpe_bench`, isolated from live `site1.localhost`)**:
  - `DefaultNativeBridgeModeTest.php`: exit 0 (pre-activation fallbacks, fresh activation flag/control persistence, immediate native WooCommerce CRUD + outbox `wp_postmeta` mirroring, and non-destructive re-activation).
  - `T02Test.php`: exit 0 (`R03`, `R04`, `R05`, `R06`, `R203`, `R204`, `R205`, `AC-T02-01..03`).
  - `SettingsApiTest.php`: exit 0 (136 checks).
  - `ProductPageSettingsTest.php`: exit 0.
  - `CohortRoutingDataStoreTest.php`: exit 0.
  - `NativeBridgeRolloutTest.php`: exit 0.
  - `T46Test.php`: exit 0 (including `AC-T46-02` lifecycle check).
  - `node scripts/check-docs.mjs --strict`, `node scripts/plan.mjs check`, `node scripts/guard.mjs --all`: all exit 0.
