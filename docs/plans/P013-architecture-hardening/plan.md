# P013 — Architecture hardening: DI, singletons, assets, capabilities

## 1. Goal

Fix the structural defects found in `docs/audits/P013-architecture-audit.md` (F1–F8) with the smallest WordPress-native change that removes each one, without changing behaviour, data, hooks, REST routes, option names or public class/method signatures.

## 2. Scope

In scope:
- F1: constructors that take `?wpdb $wpdb` use the injected instance and fall back to the global only when none is given (47 classes under `src/`).
- F2: one `WPE\Support\Singleton` trait replaces the 21 hand-written `getInstance()` accessors; `getInstance()` keeps its name and return type.
- F3: one `WPE\Support\Assets` helper gives every enqueued plugin asset its URL and a `filemtime` version (fallback: the plugin version).
- F4: one `WPE\Support\Capabilities` helper holds the plugin capability names and the operations access rule.
- F5–F7: `PluginBootstrap` keeps one default-flag list, one attribute-key normaliser, a named REST permission callback, and registers the operations REST routes without building `OperationsPage` on every request.
- F8: the `wc-ajax=get_variation` adapter accepts only scalar `attribute_*` values and cleans them with `wc_clean()`, like WooCommerce's handler.

Out of scope:
- Splitting large classes, a DI container, a table-name registry, WPCS reformatting, adding `strict_types` (reasons in the audit §2).
- The uncommitted Shopify import changes already in the working tree; they stay unstaged and are not part of any P013 commit.
- WordPress or WooCommerce Core changes.

## 3. Decisions

- **D01: `WPE\Support` namespace.** Cross-cutting, WordPress-native helpers with no business logic live in `src/Support/`. Any layer except Domain may use them. `docs/05-project-structure.md` §2 gains this row.
- **D02: Singleton trait keeps the existing contract.** `getInstance(): self`, lazily built with `new self()`, one instance per using class (trait static properties are per class). Constructors stay public because tests and services build their own instances.
- **D03: Asset version is the file modification time.** Deploying an edited asset changes its URL, so browsers and CDNs fetch it again. Missing file falls back to the plugin version.
- **D04: Operations access rule.** `Capabilities::canManageOperations()` = `wpe_manage_operations` OR `manage_woocommerce`, exactly the rule used today in all 7 places. Import/export checks in `CsvStager`, `SnapshotExporter`, `ImportCommitter` combine different capabilities and stay as they are.
- **D05: Lazy operations page.** `PluginBootstrap` registers `OperationsPage::registerRestRoutes()` inside its existing `rest_api_init` callback. The legacy `wpe-operations` admin page still redirects to the Settings → Operations tab. `OperationsPage::init()` stays public for compatibility.

## 4. Documentation changes

- `docs/05-project-structure.md` §2: add the `Support` layer row.
- `docs/NOTES.md`: P013 entry.
- `docs/audits/P013-architecture-audit.md`
- `docs/plans/P013-architecture-hardening/plan.md`
- `docs/plans/P013-architecture-hardening/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P013.1",
      "title": "Audit, Support layer contract and notes",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/05-project-structure.md",
        "docs/audits/P013-architecture-audit.md"
      ],
      "files": [
        "docs/05-project-structure.md",
        "docs/NOTES.md",
        "docs/audits/P013-architecture-audit.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P013.2",
      "title": "F1: constructor-injected wpdb is honoured in every class",
      "depends_on": ["P013.1"],
      "read_first": [
        "docs/audits/P013-architecture-audit.md"
      ],
      "files": [
        "src/Application",
        "src/Compatibility",
        "src/Admin",
        "src/Infrastructure"
      ],
      "dod": [
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/unit/T07Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T16Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T25Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaRecoveryTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorSaveTest.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P013.3",
      "title": "F2: Support\\Singleton trait replaces 21 getInstance copies",
      "depends_on": ["P013.2"],
      "read_first": [
        "docs/audits/P013-architecture-audit.md"
      ],
      "files": [
        "src/Support/Singleton.php",
        "src/Application",
        "src/Compatibility",
        "src/Admin"
      ],
      "dod": [
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T41Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T29Test.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P013.4",
      "title": "F3-F8: Assets and Capabilities helpers, bootstrap cleanup, get_variation input hardening",
      "depends_on": ["P013.3"],
      "read_first": [
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "docs/audits/P013-architecture-audit.md"
      ],
      "files": [
        "src/Support/Assets.php",
        "src/Support/Capabilities.php",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Application/Variant/ResolverApi.php"
      ],
      "dod": [
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T02Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T26Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T41Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P013.5",
      "title": "Full regression, report and closeout",
      "depends_on": ["P013.4"],
      "read_first": [
        "docs/07-testing.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P013-architecture-hardening/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```

Test notes:
- Every PHP test runs with assertions on. The full unit + integration suite (`tests/unit/*.php`, `tests/integration/*.php`) runs once before P013.2 and once after P013.4 inside a `scripts/test-isolation.mjs` snapshot/restore window; both result tables go into the report.
- Baseline before any P013 change: `ProductEditorServiceTest` (`Should have 2 descriptive attributes`) and `ProductMediaRestTest` (`a valid patch answers 200 with id and new version`) already fail. P013 does not touch their behaviour and does not use them as DoD; the post-change run must show the same two results and no new failure.
- Rollback per task is one local commit revert. No data, schema or option changes.
