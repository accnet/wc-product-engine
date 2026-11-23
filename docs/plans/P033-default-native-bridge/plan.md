# P033 — Default native_bridge mode and feature flags on install

## 1. Goal

Set `native_bridge` as the default authority and system mode of WC Product Engine out of the box, with the full engine and storefront Product Page feature flag suite enabled by default (mirroring the active `site1.localhost` configuration, keeping only `media_enabled` off). Fresh installs and activations immediately enter `native_bridge` without requiring manual flag toggles or pre-cutover mode switches, while existing stored options and control records on active stores remain untouched.

## 2. Scope

In scope:
- Update `PluginBootstrap::DEFAULT_FLAGS` so `cohort_mode` defaults to `'native_bridge'`, `media_enabled` defaults to `false`, and all core engine and Product Page flags (`variants_ui_enabled`, `query_enabled`, `resolver_enabled`, `native_writes_enabled`, `single_price_display`, `show_selected_value`, `show_help_text`, `button_selector`, `color_swatches`, `image_swatches`, `mark_unavailable_options`, `auto_select_first_values`) default to `true`.
- Seed missing control state (`system_mode`, `authority_epoch`, `cohort_active_all`) non-destructively on activation and initial schema install (`PluginBootstrap::onActivate()` and `PluginBootstrap::ensureSchema()`) without overwriting existing options or control rows.
- Align fallback mode resolution across `PluginBootstrap`, `OperationsPage`, `SettingsPage`, `AuthorityResolver`, `ProductRepository`, and `SelectorAdapter` so missing option or control entries resolve consistently to `DEFAULT_FLAGS` rather than hardcoded `'legacy'` or `false`.
- Update `docs/01-architecture-and-stack.md` §5 to document `native_bridge` and `DEFAULT_FLAGS` activation defaults.
- Update `T02Test` and `SettingsApiTest` for the new defaults, add `DefaultNativeBridgeModeTest` verifying fresh-install activation, non-destructive re-activation, and immediate `native_bridge` product creation/mirroring, and record `docs/NOTES.md`.

Out of scope:
- Modifying `SettingsRegistry` editable/read-only/admin-only key classifications (`cohort_mode` remains read-only in Settings UI/REST; authority transitions continue using governed rollout/rollback commands).
- Enabling `full_native` (`AuthoritySeal`) or `media_enabled` by default.
- Mutating live `site1.localhost` options or catalog data (which is already running `native_bridge` with this exact flag set).

## 3. Decisions

- **D01 — Full engine + Product Page flag suite on by default (`media_enabled` off).** Per user confirmation on 2026-11-22, `DEFAULT_FLAGS` matches the proven `site1.localhost` configuration: `cohort_mode = 'native_bridge'`, `media_enabled = false`, and all remaining 12 boolean flags `true`.
- **D02 — Immediate `native_bridge` on activation regardless of prior catalog state.** Per user confirmation on 2026-11-22, fresh activation unconditionally initializes `DEFAULT_FLAGS` (when `wpe_feature_flags` is absent) and seeds `wpe_control` (`system_mode = 'native_bridge'`, `authority_epoch = '1'`, `cohort_active_all`) via insert-ignore semantics so existing stores' saved flags, epoch, or explicit rollback states are never overwritten on re-activation or upgrade.
- **D03 — Preserve explicit partial-array semantics in `SelectorAdapter`.** `SelectorAdapter::getProductPageFlags()` passes `PluginBootstrap::DEFAULT_FLAGS` as the `get_option` default when `wpe_feature_flags` is absent, while respecting explicit array values stored in `wpe_feature_flags`.
- **D04 — Verify on `WPE_TEST_DB` (`test_wpe_bench`).** Integration tests run via `tests/performance/use-test-db.php` so live `site1.localhost` tables are never touched during verification.

## 4. Documentation changes

- `docs/01-architecture-and-stack.md` §5 (`Modes và flags`).
- `docs/NOTES.md` — P033 implementation and verification record.
- `docs/plans/P033-default-native-bridge/plan.md`, `docs/plans/P033-default-native-bridge/report.md`.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P033.1",
      "title": "Update docs/01 section 5 to document native_bridge and DEFAULT_FLAGS activation defaults",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/02-domain.md"
      ],
      "files": [
        "docs/01-architecture-and-stack.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    },
    {
      "id": "P033.2",
      "title": "Set native_bridge and full flag defaults in PluginBootstrap and runtime readers; update and add integration tests; record NOTES",
      "depends_on": ["P033.1"],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Support/AuthorityResolver.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "tests/integration/T02Test.php",
        "tests/integration/SettingsApiTest.php"
      ],
      "files": [
        "src/Bootstrap/PluginBootstrap.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Support/AuthorityResolver.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "tests/integration/T02Test.php",
        "tests/integration/SettingsApiTest.php",
        "tests/integration/DefaultNativeBridgeModeTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["php", "-l", "src/Admin/OperationsPage.php"],
        ["php", "-l", "src/Admin/SettingsPage.php"],
        ["php", "-l", "src/Support/AuthorityResolver.php"],
        ["php", "-l", "src/Infrastructure/Database/ProductRepository.php"],
        ["php", "-l", "src/Compatibility/StoreApi/SelectorAdapter.php"],
        ["php", "-l", "tests/integration/T02Test.php"],
        ["php", "-l", "tests/integration/SettingsApiTest.php"],
        ["php", "-l", "tests/integration/DefaultNativeBridgeModeTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/DefaultNativeBridgeModeTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T02Test.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/CohortRoutingDataStoreTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/NativeBridgeRolloutTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T46Test.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
