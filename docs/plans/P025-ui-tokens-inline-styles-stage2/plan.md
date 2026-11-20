# P025 — UI stage 2: unified design tokens and inline styles to classes

## 1. Goal

Deliver UI stage 2 from the 2026-11-20 UI review (`docs/plans/P022-ui-a11y-stage1/report.md` §Follow-ups):

- Establish one shared admin design-token stylesheet (`assets/admin/tokens.css`) used across Product Editor, Settings, Shopify Import and Operations screens, removing fragmented palette/spacing/radius/shadow declarations while preserving every WCAG 2.2 AA contrast guarantee from P022.
- Move static inline `style="..."` attributes out of `src/Admin/ShopifyImportPage.php`, `src/Admin/OperationsPage.php`, `src/Admin/ProductEditor/ProductEditorBridge.php`, `assets/admin/shopify-import.js`, and `assets/admin/product-editor.js` into semantic CSS classes.
- Keep all business logic, REST contracts, stored data, JS hook classes, `data-*` attributes and P022 accessibility/contrast/responsive behaviour unchanged.

## 2. Scope

In scope:
- New `assets/admin/tokens.css` defining shared `--wpe-*` design tokens (surfaces, borders, WCAG 1.4.11 control borders, typography, brand/semantic status colors, spacing scale, radii, elevation shadows, z-index layers).
- Enqueue `wpe-admin-tokens` as a style dependency in `src/Bootstrap/PluginBootstrap.php`, `src/Admin/ProductEditor/ProductEditorBridge.php`, and `src/Admin/OperationsPage.php` (using `WPE\Support\Assets`).
- Align `assets/admin/settings.css`, `assets/admin/admin.css`, and `assets/admin/product-editor.css` (aliasing `--wpe-p-*` to `--wpe-*`) onto the shared tokens.
- Replace static inline styles in `src/Admin/ShopifyImportPage.php`, `assets/admin/shopify-import.js`, `src/Admin/OperationsPage.php`, `src/Admin/ProductEditor/ProductEditorBridge.php`, and `assets/admin/product-editor.js` with semantic CSS classes in `assets/admin/admin.css` and `assets/admin/product-editor.css`.
- New live browser verification `tests/e2e/UiStage2Live.mjs` checking token presence, computed contrast, layout, and absence of static inline styles across admin screens.

Out of scope:
- Stage 3 items (`!important` removal, dead CSS removal, native button polish, storefront theme isolation): carried in `P026`.
- Approval-gated UI changes (i18n of Vietnamese strings / currency symbol, media API thumbnail URLs, field-level error mapping, server-side WooCommerce panel removal).
- WordPress or WooCommerce Core modifications.

## 3. Decisions

- **D01: Single token layer (`assets/admin/tokens.css`) scoped to `:root`.** Every admin screen loads `wpe-admin-tokens` before its screen stylesheet. Product editor keeps `--wpe-p-*` aliases referencing `--wpe-*` so existing rules and overrides remain compatible.
- **D02: Preserve exact P022 AA contrast tokens.** `--wpe-border-control: #8c8f94` (3.24:1 on white) for Settings/Import and `--wpe-border-control-tinted: #7e8287` (3.53:1 on `#f1f5f9`) for Product Editor; stock badges keep `#166534` on `#dcfce7` (6.5:1) and `#b91c1c` on `#fee2e2` (5.9:1).
- **D03: Zero static inline `style="..."` in admin PHP/JS templates.** Only genuinely runtime-computed values (such as a user-selected swatch hex color `background:${colorHex}` or JS-driven progress percentage width) may stay inline. Initial hidden states use CSS classes (`.wpe-hidden`) or the HTML `hidden` attribute with clean jQuery interoperability.
- **D04: No JS hook or contract regressions.** Every existing ID, class, `data-*` attribute and REST payload stays untouched.

## 4. Documentation changes

- `docs/NOTES.md`: P025 entry.
- `docs/plans/P025-ui-tokens-inline-styles-stage2/plan.md`
- `docs/plans/P025-ui-tokens-inline-styles-stage2/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P025.1",
      "title": "Shared admin design tokens stylesheet and token alignment across admin CSS",
      "depends_on": [],
      "read_first": [
        "docs/09-ui.md",
        "assets/admin/settings.css",
        "assets/admin/admin.css",
        "assets/admin/product-editor.css"
      ],
      "files": [
        "assets/admin/tokens.css",
        "assets/admin/admin.css",
        "assets/admin/settings.css",
        "assets/admin/product-editor.css",
        "src/Bootstrap/PluginBootstrap.php",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "src/Admin/OperationsPage.php"
      ],
      "dod": [
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["php", "-l", "src/Admin/ProductEditor/ProductEditorBridge.php"],
        ["php", "-l", "src/Admin/OperationsPage.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T41Test.php"],
        ["node", "tests/e2e/UiStage1Live.mjs", "contrast"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P025.2",
      "title": "Move static inline styles out of ShopifyImportPage, OperationsPage, ProductEditorBridge and shopify-import.js",
      "depends_on": ["P025.1"],
      "read_first": [
        "src/Admin/ShopifyImportPage.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "assets/admin/shopify-import.js"
      ],
      "files": [
        "src/Admin/ShopifyImportPage.php",
        "src/Admin/OperationsPage.php",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "assets/admin/shopify-import.js",
        "assets/admin/admin.css",
        "assets/admin/product-editor.css",
        "assets/admin/product-editor.js"
      ],
      "dod": [
        ["php", "-l", "src/Admin/ShopifyImportPage.php"],
        ["php", "-l", "src/Admin/OperationsPage.php"],
        ["php", "-l", "src/Admin/ProductEditor/ProductEditorBridge.php"],
        ["node", "--check", "assets/admin/shopify-import.js"],
        ["node", "--check", "assets/admin/product-editor.js"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T41Test.php"],
        ["node", "tests/e2e/UiStage1Live.mjs", "import"],
        ["node", "tests/e2e/VariantsBlockLive.mjs"]
      ]
    },
    {
      "id": "P025.3",
      "title": "Move static inline styles out of product-editor.js into semantic product-editor.css classes",
      "depends_on": ["P025.2"],
      "read_first": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css"
      ],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css"
      ],
      "dod": [
        ["node", "--check", "assets/admin/product-editor.js"],
        ["node", "tests/e2e/UiStage1Live.mjs"],
        ["node", "tests/e2e/ProductMediaLive.mjs"],
        ["node", "tests/e2e/VariantImagePickerLive.mjs"],
        ["node", "tests/e2e/TwoBlockLive.mjs"],
        ["node", "tests/e2e/ProductInfoBlockLive.mjs"]
      ]
    },
    {
      "id": "P025.4",
      "title": "Stage 2 browser checks, full regression, notes and report",
      "depends_on": ["P025.3"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "tests/e2e/UiStage2Live.mjs",
        "docs/NOTES.md",
        "docs/plans/P025-ui-tokens-inline-styles-stage2/report.md"
      ],
      "dod": [
        ["node", "--check", "tests/e2e/UiStage2Live.mjs"],
        ["node", "tests/e2e/UiStage2Live.mjs"],
        ["node", "tests/e2e/UiStage1Live.mjs"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
