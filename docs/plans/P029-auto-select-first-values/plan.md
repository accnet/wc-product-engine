# P029 — Auto-select first variant values toggle

## 1. Goal

Add a Product Page → Variant Selector toggle, `Auto-Select First Values` (`auto_select_first_values`, default `false`). When it is on, a variable product page opens with a complete, purchasable selection: every variation option that has no merchant-configured default gets its first available value, so price, image, `variation_id` and Add to cart are ready without a click.

Today (2026-11-21, `site1.localhost`, `native_bridge`) a product without configured defaults (e.g. ID 24704) loads with nothing selected: the engine sets the theme's `data-preselect-first="false"` and has no equivalent of its own.

## 2. Scope

In scope:
- Flag registration (`SettingsRegistry`, `PluginBootstrap::DEFAULT_FLAGS`), rendered by the existing Product Page settings screen and accepted by `wpe/v1/admin/settings` through the registry.
- `SelectorAdapter::getSelectorConfig()`: compute `autoSelection` and its resolution server-side; render SSR controls with it.
- `assets/variant-selector/selector.js`: start from `autoSelection` when present; Clear returns to the configured defaults.
- Tests: new integration test, updated `SettingsApiTest` expected key list, live browser check.

Out of scope:
- Changing how configured defaults are resolved (`wpe_options.default_value_id`, `wpe_products.default_selection`, WooCommerce default attributes).
- The theme's own preselect script (stays disabled by the engine selector).
- Catalog cards / quick add.

## 3. Decisions

- **D01 — Configured defaults win.** Auto-selection only fills options missing from the configured default selection (after resolver reconciliation).
- **D02 — First available, not first listed.** Options are walked in saved position order; each takes the first value (saved value order) that the resolver lists in `allowed_values` for the selection so far. The result therefore always matches a purchasable variation; an option with no allowed value is left empty.
- **D03 — Server-side, cached.** The walk runs inside `getSelectorConfig()` (at most one resolve per auto-filled option) and is cached with the config; the cache key already includes the Product Page flags.
- **D04 — Clear means clear.** Auto-picked values are a convenience, not merchant defaults: `defaultSelection` / `initialResolution` keep describing the configured defaults, so Clear returns to them (empty when none). `autoSelection` / `autoResolution` describe the opening state only.
- **D05 — Default off.** Existing behavior is unchanged until the toggle is switched on.

## 4. Documentation changes

- `docs/04-api-contract.md` — product_page flag list in the `wpe/v1/admin/settings` paragraph: 7 → 8 flags, add `auto_select_first_values`.
- `docs/09-ui.md` §10 Variant Selector — describe `Auto-Select First Values`.
- `docs/NOTES.md` — P029 implementation and verification entry.
- `docs/plans/P029-auto-select-first-values/plan.md`, `docs/plans/P029-auto-select-first-values/report.md`.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P029.1",
      "title": "Document the auto_select_first_values product_page flag in the settings contract and UI spec",
      "depends_on": [],
      "read_first": [
        "docs/04-api-contract.md",
        "docs/09-ui.md"
      ],
      "files": [
        "docs/04-api-contract.md",
        "docs/09-ui.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    },
    {
      "id": "P029.2",
      "title": "Implement the toggle: registry/default flag, server-side first-available auto selection with SSR, selector.js opening state and Clear semantics, tests",
      "depends_on": ["P029.1"],
      "read_first": [
        "docs/09-ui.md",
        "src/Admin/SettingsRegistry.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "assets/variant-selector/selector.js"
      ],
      "files": [
        "src/Admin/SettingsRegistry.php",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "assets/variant-selector/selector.js",
        "tests/integration/AutoSelectFirstValuesTest.php",
        "tests/integration/SettingsApiTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Admin/SettingsRegistry.php"],
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["php", "-l", "src/Compatibility/StoreApi/SelectorAdapter.php"],
        ["node", "--check", "assets/variant-selector/selector.js"],
        ["wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/AutoSelectFirstValuesTest.php"],
        ["php", "tests/integration/SettingsApiTest.php"],
        ["php", "tests/integration/ProductPageSettingsTest.php"],
        ["php", "tests/integration/T27Test.php"]
      ]
    },
    {
      "id": "P029.3",
      "title": "Live browser verification of opening state, Clear and toggle-off behavior; record NOTES",
      "depends_on": ["P029.2"],
      "read_first": [
        "tests/e2e/ProductPageSettingsLive.mjs"
      ],
      "files": [
        "tests/e2e/AutoSelectFirstValuesLive.mjs",
        "docs/NOTES.md"
      ],
      "dod": [
        ["node", "tests/e2e/AutoSelectFirstValuesLive.mjs"],
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    }
  ]
}
```
