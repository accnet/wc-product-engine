# P004 — Plugin Settings UI redesign

## 1. Goal

Rebuild the **WC Product Engine → Settings** screen as a tabbed, accessible admin page (Overview, Features, Maintenance, Advanced) that:

- shows the real authority state (control-table `system_mode`, `authority_epoch`, health) and never lets the UI change it;
- groups editable feature flags with honest, server-computed status;
- keeps every change reversible (dirty state, discard, unsaved-changes guard, inline field errors).

## 2. Scope

- **In scope**
  - `src/Admin/SettingsRegistry.php` (new): single definition of editable flags (label, description, runtime status) shared by the page and REST validation.
  - `src/Admin/SettingsPage.php`: tabbed rendering, no inline styles, reads real authority state through `OperationsPage::getHealthReport()`.
  - `src/Admin/Rest/AdminApiController.php`: `POST /settings` validation. Rejects `cohort_mode`, rejects unknown keys and non-boolean values with per-field errors, returns effective flag state.
  - `src/Bootstrap/PluginBootstrap.php`: load settings assets only on the Settings screen, version them by `filemtime`.
  - `assets/admin/settings.css` (new, scoped under `.wpe-admin`), `assets/admin/settings.js` (rewrite).
  - `tests/integration/SettingsApiTest.php` (new).
- **Out of scope**
  - Any WordPress or WooCommerce Core change.
  - Changing authority mode, epoch, rollout gates or `CutoverRunner`. Mode changes remain Operations / WP-CLI only.
  - Removing or altering the Operations "Rebuild Index Generation" job action (different mechanism: job-backed). The Settings → Maintenance rebuild keeps calling `CatalogProjector` exactly as before.
  - Restyling Operations and Shopify Import pages (follow-up).
  - New persisted options (for example import defaults).
  - Deleting or weakening any existing test.

## 3. Decisions

- **D01: Authority is read-only in Settings.** The `cohort_mode` value stays stored and keeps acting as the default routing for products with no cohort rule (`PluginBootstrap::resolveAuthority` rule 3). The UI displays it as read-only, labelled separately from the global `system_mode`. REST rejects writes to it with the registered error `invalid_request` (HTTP status 400) and `reason: authority_read_only`; unknown keys and non-boolean values use the same code with `reason: unknown_setting` / `invalid_value`. This enforces `docs/02-domain.md §4–5` (no epoch/dual-writer bypass).
- **D02: Honest flag status.** At this commit, only `native_writes_enabled` is read at runtime (`filterDataStores`, which still registers no store) and `query_enabled`, `media_enabled`, `resolver_enabled` have no runtime reader in `src/`. The registry marks them `preference` and the UI says "Saved, not applied by the runtime yet" rather than promising behaviour.
- **D03: Only real dependencies are shown.** The only code-level dependency is `dependencies_satisfied` (PHP/WooCommerce version). When it is false every toggle is disabled with the reasons from `getDependencyErrors()`. No invented inter-flag dependencies.
- **D04: Rebuild stays where it is.** Maintenance tab keeps the existing synchronous rebuild (with a confirm dialog) and links to Operations for the job-backed action. Merging the two is a separate, behaviour-changing task.
- **D05: Style isolation.** CSS tokens are declared inside `.wpe-admin` in `settings.css`; `product-editor.css` is untouched.
- **D06: Docs.** No change to authoritative contracts (02/03/04/10). The behaviour is already required by 02 §4–5. Recorded in `docs/NOTES.md`.

## 4. Documentation changes

- `docs/plans/P004-settings-ui/plan.md`
- `docs/plans/P004-settings-ui/report.md`
- `docs/NOTES.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P004.1",
      "title": "Settings registry and REST hardening (reject authority writes, per-field errors, effective flags)",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/04-api-contract.md"
      ],
      "files": [
        "src/Admin/SettingsRegistry.php",
        "src/Admin/Rest/AdminApiController.php",
        "tests/integration/SettingsApiTest.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/SettingsApiTest.php"
        ]
      ]
    },
    {
      "id": "P004.2",
      "title": "Tabbed Settings page, scoped styles and interactive client (dirty state, discard, confirm dialog, aria-live)",
      "depends_on": [
        "P004.1"
      ],
      "read_first": [
        "docs/09-ui.md",
        "docs/08-operations.md"
      ],
      "files": [
        "src/Admin/SettingsPage.php",
        "src/Bootstrap/PluginBootstrap.php",
        "assets/admin/settings.css",
        "assets/admin/settings.js"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/SettingsApiTest.php"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    },
    {
      "id": "P004.3",
      "title": "Regression run, before/after evidence and notes",
      "depends_on": [
        "P004.2"
      ],
      "read_first": [
        "docs/PROGRESS.md",
        "docs/06-implementation-plan.md"
      ],
      "files": [
        "tests/integration/SettingsApiTest.php",
        "docs/NOTES.md",
        "docs/plans/P004-settings-ui/plan.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/T02Test.php"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/SettingsApiTest.php"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    }
  ]
}
```
