# P014 — P013 amendment: assets, capabilities, bootstrap cleanup and closeout

## 1. Goal

Finish the work of P013.4 and P013.5 (`docs/plans/P013-architecture-hardening/plan.md`) unchanged in content. P013.4 is blocked only because its DoD test `T02Test` fails on this site's live feature-flag option, identically before and after P013 (`docs/QUESTIONS.md` OD-16). P013 is sealed once started, so the corrected DoD lives here.

## 2. Scope

In scope: exactly P013 scope items F3–F8 and the P013 closeout (see `docs/audits/P013-architecture-audit.md` and P013 §2–§3). Decisions D01–D05 of P013 apply unchanged.

Out of scope: everything P013 excludes. The uncommitted Shopify import hunks in `src/Bootstrap/PluginBootstrap.php`, `src/Admin/ShopifyImportPage.php`, `src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php` and `assets/admin/shopify-import.js` belong to another session and stay unstaged.

## 3. Decisions

- **D01: DoD substitution.** `T15Test` (hooks, transitions, cache lifecycle; passes at baseline) replaces `T02Test`. `T02Test` still runs in the full regression and must show the same failure lines as the baseline.
- **D02: P013 stays blocked.** P013.4 and P013.5 are not resumed; this plan's report is the closeout record for P013.

## 4. Documentation changes

- `docs/NOTES.md`: P014 entry.
- `docs/QUESTIONS.md`: OD-16.
- `docs/plans/P014-p013-amendment/plan.md`
- `docs/plans/P014-p013-amendment/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P014.1",
      "title": "F3-F8: Assets and Capabilities helpers, bootstrap cleanup, get_variation input hardening",
      "depends_on": [],
      "read_first": [
        "docs/audits/P013-architecture-audit.md",
        "docs/plans/P013-architecture-hardening/plan.md"
      ],
      "files": [
        "src/Support/Assets.php",
        "src/Support/Capabilities.php",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/SettingsPage.php",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Application/Variant/ResolverApi.php",
        "docs/QUESTIONS.md"
      ],
      "dod": [
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T15Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T26Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T41Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P014.2",
      "title": "Full regression, P013/P014 report and closeout",
      "depends_on": ["P014.1"],
      "read_first": [
        "docs/07-testing.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P014-p013-amendment/report.md"
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

Test notes: same as P013 (assertions on; full unit + integration suite inside a `scripts/test-isolation.mjs` snapshot/restore window; the post-change run must match the baseline's 10 site-state/pre-existing failures and add none).
