# P034 — Streamline Settings and Operations UI for single-mode native_bridge

## 1. Goal

Simplify the WC Product Engine admin experience around `native_bridge` as the single standard operating mode, eliminating unsafe mode-toggle temptations and pre-cutover `shadow`/`indexed` rollout clutter from the UI while preserving underlying `legacy` fallback routing (for unsupported product types such as bundles/subscriptions) and emergency rollback safety.

## 2. Scope

In scope:
- **Settings Overview & Advanced cleanup (`SettingsPage`)**: Remove the redundant `Default routing` rollout card from the Overview tab (keeping authority diagnostics under the read-only `Advanced` tab) and surface an actionable notice in `Needs attention` when `outbox_pending` has accumulated backlog.
- **Streamlined Operations Controls (`SettingsPage` & `OperationsPage`)**: Replace pre-cutover rollout buttons (`Run Shadow Diff Audit`, `Run Keyset Backfill Batch`) on the UI with the 3 everyday catalog operations (`Sync Legacy Mirror (Drain Outbox)`, `Rebuild Catalog Index`, `Import Legacy Woo Products`) and visually isolate `Rollback to WooCommerce Legacy` inside a dedicated `Danger Zone` card with confirmation dialog.
- **One-step `import_legacy` action & CLI parity (`OperationsPage`, `Commands`, `LegacyCapture`)**: Add `import_legacy` (`wp wpe import-legacy`) that captures eligible unmigrated WooCommerce `simple`/`variable` products, skips unsupported product types and `LegacyOnlyProps` (`_customs_*`), promotes captured aggregates and variations directly to `native_bridge` at the active `authority_epoch`, and projects them into `wpe_catalog`. Fix `LegacyCapture::isNativeAuthority()` to recognize `native_bridge` and `full_native` alongside `native` so existing native products are never overwritten.
- **Client summaries & token-based styling (`settings.js`, `operations.js`, `settings.css`, `admin.css`)**: Human-readable action summaries for `outbox` and `import_legacy`, plus `.wpe-card--danger` styling with zero inline `style` attributes.
- **Verification & live outbox drain**: Add `SingleModeOperationsTest.php` on `WPE_TEST_DB` (`test_wpe_bench`), verify `T41Test`, `T37Test`, `AccessControlTest`, and `SettingsApiTest`, drain the backlog of pending outbox projections on `site1.localhost`, and record `docs/NOTES.md`.

Out of scope:
- Adding any instant mode-switch toggle/dropdown (rejected due to split-brain risk).
- Deleting underlying migration/rollback classes or `CohortRoutingDataStore` legacy routing for unsupported product types.

## 3. Decisions

- **D01 — Single-mode UX, preserved core fallback.** `native_bridge` is the sole standard operating mode presented to operators; `CohortRoutingDataStore` continues routing unsupported types (`grouped`, `external`, `bundle`, `subscription`, `_customs_*`) to WooCommerce CPT, and `RollbackRunner` remains available inside the isolated Danger Zone card before plugin deactivation.
- **D02 — Bounded web batches for `outbox` and `import_legacy`.** Web UI dispatches bounded batches (`batch_size = 200, max_batches = 25` for `outbox`; `limit = 200` per run for `import_legacy`) so HTTP requests complete safely within PHP execution limits and report remaining counts clearly; WP-CLI accepts custom `--limit` / `--batch-size` / `--max-batches`.
- **D03 — Preserve backend action handlers (`audit`, `migrate`, `mode`, `gates`, `cutover`).** Backend `OperationsPage::handleAction()` and WP-CLI commands retain existing handlers for regression contracts (`T41`, `P027`) while the Admin UI surfaces only the streamlined single-mode controls.
- **D04 — Zero inline styles.** Danger Zone styling uses CSS classes in `settings.css` and `admin.css` backed by `tokens.css` variables.

## 4. Documentation changes

- `docs/09-ui.md` §6 (`Operations dashboard`).
- `docs/NOTES.md` — P034 implementation, test verification, and live outbox drain record.
- `docs/plans/P034-streamline-single-mode-operations/plan.md`, `docs/plans/P034-streamline-single-mode-operations/report.md`.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P034.1",
      "title": "Update docs/09 section 6 for streamlined single-mode Operations controls and Danger Zone rollback",
      "depends_on": [],
      "read_first": [
        "docs/09-ui.md",
        "docs/02-domain.md"
      ],
      "files": [
        "docs/09-ui.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    },
    {
      "id": "P034.2",
      "title": "Implement import_legacy action, fix LegacyCapture native_bridge guard, streamline Settings/Operations UI and JS/CSS, add tests, and record NOTES",
      "depends_on": ["P034.1"],
      "read_first": [
        "docs/09-ui.md",
        "src/Application/Migration/LegacyCapture.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Cli/Commands.php",
        "assets/admin/settings.js",
        "assets/operations/operations.js",
        "assets/admin/settings.css",
        "assets/admin/admin.css",
        "tests/integration/T41Test.php"
      ],
      "files": [
        "src/Application/Migration/LegacyCapture.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Cli/Commands.php",
        "assets/admin/settings.js",
        "assets/operations/operations.js",
        "assets/admin/settings.css",
        "assets/admin/admin.css",
        "tests/integration/SingleModeOperationsTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyCapture.php"],
        ["php", "-l", "src/Admin/OperationsPage.php"],
        ["php", "-l", "src/Admin/SettingsPage.php"],
        ["php", "-l", "src/Cli/Commands.php"],
        ["php", "-l", "tests/integration/SingleModeOperationsTest.php"],
        ["node", "--check", "assets/admin/settings.js"],
        ["node", "--check", "assets/operations/operations.js"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/SingleModeOperationsTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T41Test.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T37Test.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/AccessControlTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
