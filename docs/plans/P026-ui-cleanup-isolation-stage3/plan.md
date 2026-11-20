# P026 — UI stage 3: native buttons, !important removal, dead CSS and theme isolation

## 1. Goal

Deliver UI stage 3 from the 2026-11-20 UI review (`docs/plans/P022-ui-a11y-stage1/report.md` follow-ups), explicitly authorized by the user alongside stage 2 (`P025`):

- Remove verified dead/unused `.wpe-*` CSS rules across admin and storefront stylesheets and extract duplicated HTML template blocks in `assets/admin/product-editor.js` into focused helper functions without changing rendered DOM or JS hooks.
- Eliminate unnecessary `!important` overrides across `assets/admin/product-editor.css` (baseline: 318 -> target <= 10 for genuine panel hiding, `:focus-visible`, and `prefers-reduced-motion`) and `assets/admin/admin.css` (baseline: 2 -> 0) by using clean WordPress admin specificity and aligning buttons cleanly with native WordPress admin button semantics.
- Harden storefront selector styling (`assets/variant-selector/selector.css`) with scoped `--wpe-sel-*` custom properties, `!important` reduction, dead selector cleanup, and defensive hostile-theme isolation resets, verified by a dedicated offline Playwright theme-isolation spec (`tests/e2e/SelectorThemeIsolation.spec.mjs`) plus live storefront checks.
- Verify everything via a new Stage 3 browser & CSS-budget audit (`tests/e2e/UiStage3Live.mjs`) and full regression across Stage 1, Stage 2, and storefront suites.

## 2. Scope

In scope:
- Dead CSS cleanup in `assets/admin/product-editor.css` (24 verified unused classes), `assets/admin/admin.css` (3 verified unused classes), and `assets/variant-selector/selector.css` (2 verified unused classes).
- Deduplicating repeated template fragments in `assets/admin/product-editor.js` (media empty state, media external URL bar, bulk-edit diff preview table) without changing DOM structure, IDs, classes, or `data-*` hooks.
- Replacing unnecessary `!important` declarations in `assets/admin/product-editor.css` and `assets/admin/admin.css` using scoped `.wp-core-ui` / `#wpbody-content` specificity while preserving native WP button behavior and every P022 WCAG 2.2 AA contrast, focus, and layout guarantee.
- Storefront selector (`assets/variant-selector/selector.css`): scoped `--wpe-sel-*` custom properties, `!important` reduction on swatch rules, defensive resets against aggressive theme button/layout rules, and `tests/e2e/SelectorThemeIsolation.spec.mjs`.
- Stage 3 verification (`tests/e2e/UiStage3Live.mjs`), `docs/NOTES.md`, and `docs/plans/P026-ui-cleanup-isolation-stage3/report.md`.

Out of scope:
- Approval-gated UI items (i18n of Vietnamese strings / currency symbol, media API thumbnail URLs, field-level error mapping, server-side WooCommerce panel removal).
- WordPress or WooCommerce Core modifications.
- Changes to business logic, REST contracts, or database schema.

## 3. Decisions

- **D01: Strict `!important` budget.** Keep `!important` only where cascade isolation requires it: hiding legacy WooCommerce panels/metaboxes (`.wpe-box-hidden`, `body.wpe-mode-blocks`, `#woocommerce-product-data.wpe-is-variable`), `@media (prefers-reduced-motion: reduce)`, `:focus-visible` outlines, storefront single-price/competing-theme hiding, and `.wpe-sr-only`/`.wpe-select-hidden` visually-hidden utilities. All routine button, input, select, dashicon, and swatch styling uses normal specificity.
- **D02: Zero visual/a11y regressions.** Every P022 WCAG 2.2 AA check (`UiStage1Live.mjs` 30/30, `SelectorContrast.spec.mjs`) and every P025 Stage 2 check (`UiStage2Live.mjs` 16/16) must continue passing after every task.
- **D03: Storefront hostile-theme isolation.** Scope `--wpe-sel-*` custom properties on `.wpe-variant-selector, .wpe-product-instance` and apply defensive resets (`appearance: none`, `margin: 0`, `font-family: inherit`, `letter-spacing: normal`, `text-transform: none`, `text-shadow: none`, `box-shadow: none`, `vertical-align: middle`) using `.wpe-variant-selector` scoped selectors so aggressive theme `button` / `label` / `img` rules cannot distort swatches or reset buttons.
- **D04: Tracked evidence awareness.** `assets/variant-selector/selector.css` is a `T27` tracked file; `T27` (plus `T02` and `T41` from `P025`) evidence will be refreshed on the isolated test root (`test_wpe_regress`) after Stage 3 completes.

## 4. Documentation changes

- `docs/NOTES.md`: P026 entry.
- `docs/plans/P026-ui-cleanup-isolation-stage3/plan.md`
- `docs/plans/P026-ui-cleanup-isolation-stage3/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P026.1",
      "title": "Remove verified dead CSS classes and deduplicate repeated template helpers in product-editor.js",
      "depends_on": [],
      "read_first": [
        "assets/admin/product-editor.css",
        "assets/admin/admin.css",
        "assets/admin/product-editor.js"
      ],
      "files": [
        "assets/admin/product-editor.css",
        "assets/admin/admin.css",
        "assets/admin/product-editor.js"
      ],
      "dod": [
        ["node", "--check", "assets/admin/product-editor.js"],
        ["node", "tests/e2e/UiStage1Live.mjs"],
        ["node", "tests/e2e/UiStage2Live.mjs"],
        ["node", "tests/e2e/ProductMediaLive.mjs"],
        ["node", "tests/e2e/TwoBlockLive.mjs"]
      ]
    },
    {
      "id": "P026.2",
      "title": "Remove unnecessary !important overrides and align native WordPress button styles in admin CSS",
      "depends_on": ["P026.1"],
      "read_first": [
        "assets/admin/product-editor.css",
        "assets/admin/admin.css",
        "assets/admin/tokens.css"
      ],
      "files": [
        "assets/admin/product-editor.css",
        "assets/admin/admin.css"
      ],
      "dod": [
        ["node", "tests/e2e/UiStage1Live.mjs"],
        ["node", "tests/e2e/UiStage2Live.mjs"],
        ["node", "tests/e2e/VariantsBlockLive.mjs"],
        ["node", "tests/e2e/ProductInfoBlockLive.mjs"],
        ["node", "tests/e2e/VariantImagePickerLive.mjs"]
      ]
    },
    {
      "id": "P026.3",
      "title": "Harden storefront selector CSS with scoped tokens, !important reduction, dead CSS removal and theme isolation",
      "depends_on": ["P026.2"],
      "read_first": [
        "assets/variant-selector/selector.css",
        "tests/e2e/SelectorContrast.spec.mjs",
        "tests/e2e/SelectorBehavior.spec.mjs"
      ],
      "files": [
        "assets/variant-selector/selector.css",
        "tests/e2e/SelectorThemeIsolation.spec.mjs"
      ],
      "dod": [
        ["npx", "--no-install", "playwright", "test", "--config=playwright.config.mjs"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["node", "tests/e2e/ProductPageSettingsLive.mjs"]
      ]
    },
    {
      "id": "P026.4",
      "title": "Stage 3 browser and CSS-budget verification, notes and report",
      "depends_on": ["P026.3"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "tests/e2e/UiStage3Live.mjs",
        "docs/NOTES.md",
        "docs/plans/P026-ui-cleanup-isolation-stage3/report.md"
      ],
      "dod": [
        ["node", "--check", "tests/e2e/UiStage3Live.mjs"],
        ["node", "tests/e2e/UiStage3Live.mjs"],
        ["node", "tests/e2e/UiStage1Live.mjs"],
        ["node", "tests/e2e/UiStage2Live.mjs"],
        ["npx", "--no-install", "playwright", "test", "--config=playwright.config.mjs"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
