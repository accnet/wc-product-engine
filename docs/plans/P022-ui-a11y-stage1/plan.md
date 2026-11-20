# P022 — UI stage 1: keyboard, focus, contrast and responsive fixes

## 1. Goal

Fix the UI defects found in the 2026-11-20 UI review that block use or are clearly broken, without changing business rules, REST contracts or data. After P022:

- Every control in the product editor can be reached and operated with the keyboard, has an accessible name and shows a visible focus ring.
- Text, control borders and stock badges meet WCAG 2.2 AA contrast (4.5:1 text, 3:1 UI components).
- The product editor save bar, fullscreen editor and variants matrix work at 360, 768, 1024 and 1440 px and with a folded admin menu.
- Unavailable storefront swatches keep a legible label and mark, as `docs/09-ui.md` §4 (`mark_unavailable_options`) already requires.
- Error toasts stay until dismissed. Animations respect `prefers-reduced-motion`.

Review IDs covered: K-01, K-02, K-03, K-04, I-01, C-04, C-05, C-06, C-07, L-01, R-01, R-02, R-03, N-01, M-01.

## 2. Scope

In scope:
- `assets/admin/product-editor.js` and `.css`: native buttons for the image picker items, row thumbnail, shared-stock pill, media remove, chip remove and chip move controls; `aria-label` for icon-only buttons, matrix inputs and checkboxes; one close-button helper; focus-visible ring for buttons inside the editor; contrast fixes; responsive save bar; fullscreen overlay that does not depend on admin bar or menu sizes; matrix with fixed layout and sticky select and actions columns; persistent, dismissible error toasts; reduced-motion block.
- `assets/admin/settings.css`, `assets/admin/admin.css`: input border contrast and reduced motion.
- `assets/variant-selector/selector.css`: swatch border contrast, unavailable state without opacity, muted text that follows the theme text colour.
- `src/Admin/ShopifyImportPage.php`: layout at 360 px and dropzone border contrast (markup and inline styles only).
- New browser checks: `tests/e2e/UiStage1Live.mjs` (admin, live site) and `tests/e2e/SelectorContrast.spec.mjs` (storefront, offline).

Out of scope:
- Design tokens, removing `!important`, moving inline styles to classes, native button migration (stages 2 and 3).
- Text and i18n changes (T-05, T-06), API thumbnails (I-06), field-level error mapping (N-02), server-side removal of WooCommerce panels (A-03), deleting the Operations page UI. Each needs its own approval.
- PHP behaviour, REST routes, database, contracts.

## 3. Decisions

- **D01: Keep JS hook classes.** Elements change tag (for example `div.wpe-picker-item` becomes `button.wpe-picker-item`), but every class and `data-*` attribute that JS or tests select on stays unchanged. Existing live tests select by class, so they keep working.
- **D02: Fullscreen covers the viewport.** The fullscreen editor uses `inset: 0` with a z-index above the admin bar (99999) and below the WordPress media modal (160000), like the drawer and modals. This removes the `top: 32px; left: 160px` magic numbers and fixes the folded menu, mobile admin bar (46 px) and RTL cases.
- **D03: Contrast values.** Control borders use `#8c8f94` (the WordPress core input border, 3.24:1). Stock badges: out of stock `#b91c1c` on `#fee2e2`, in stock `#166534` on `#dcfce7`. Muted text in the editor uses at least `#64748b` on white and `#475569` on tinted backgrounds. Storefront muted text uses `color-mix(in srgb, currentColor 72%, transparent)` so it follows light and dark themes.
- **D04: Unavailable swatch.** No `opacity`. Label `#646970` on `#f6f7f7`, cross mark solid `#d63638`, so both name and mark stay legible (`docs/09-ui.md` §4). The standard disabled mode (`is-disabled-standard`) keeps its look apart from contrast.
- **D05: Toasts.** Success and warning toasts auto-hide after 5 s and pause on hover or focus. Error toasts use `role="alert"`, stay until closed and have a close button. One live region is created at start-up.
- **D06: No visual redesign.** Colours, spacing and sizes change only where a listed defect requires it. Stage 2 introduces tokens.

## 4. Documentation changes

- `docs/NOTES.md`: P022 entry.
- `docs/plans/P022-ui-a11y-stage1/plan.md`
- `docs/plans/P022-ui-a11y-stage1/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P022.1",
      "title": "Keyboard-operable controls and accessible names in the product editor",
      "depends_on": [],
      "read_first": [
        "docs/09-ui.md",
        "assets/admin/product-editor.js"
      ],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css",
        "tests/e2e/UiStage1Live.mjs"
      ],
      "dod": [
        ["node", "--check", "assets/admin/product-editor.js"],
        ["node", "--check", "tests/e2e/UiStage1Live.mjs"],
        ["node", "tests/e2e/UiStage1Live.mjs", "keyboard"],
        ["node", "tests/e2e/VariantImagePickerLive.mjs"],
        ["node", "tests/e2e/ProductMediaLive.mjs"],
        ["node", "tests/e2e/TwoBlockLive.mjs"]
      ]
    },
    {
      "id": "P022.2",
      "title": "Focus ring, contrast and reduced motion in admin styles",
      "depends_on": ["P022.1"],
      "read_first": [
        "assets/admin/product-editor.css",
        "assets/admin/settings.css"
      ],
      "files": [
        "assets/admin/product-editor.css",
        "assets/admin/product-editor.js",
        "assets/admin/settings.css",
        "assets/admin/admin.css",
        "tests/e2e/UiStage1Live.mjs"
      ],
      "dod": [
        ["node", "--check", "assets/admin/product-editor.js"],
        ["node", "tests/e2e/UiStage1Live.mjs", "focus"],
        ["node", "tests/e2e/UiStage1Live.mjs", "contrast"],
        ["node", "tests/e2e/VariantsBlockLive.mjs"],
        ["node", "tests/e2e/ProductPageSettingsLive.mjs"]
      ]
    },
    {
      "id": "P022.3",
      "title": "Responsive save bar, viewport fullscreen, sticky matrix columns and persistent error toasts",
      "depends_on": ["P022.2"],
      "read_first": [
        "assets/admin/product-editor.css",
        "assets/admin/product-editor.js"
      ],
      "files": [
        "assets/admin/product-editor.css",
        "assets/admin/product-editor.js",
        "tests/e2e/UiStage1Live.mjs"
      ],
      "dod": [
        ["node", "--check", "assets/admin/product-editor.js"],
        ["node", "tests/e2e/UiStage1Live.mjs", "layout"],
        ["node", "tests/e2e/VariantsBlockLive.mjs"],
        ["node", "tests/e2e/TwoBlockLive.mjs"],
        ["node", "tests/e2e/ProductInfoBlockLive.mjs"]
      ]
    },
    {
      "id": "P022.4",
      "title": "Storefront selector: control contrast, legible unavailable state, theme-aware muted text",
      "depends_on": [],
      "read_first": [
        "docs/09-ui.md",
        "assets/variant-selector/selector.css"
      ],
      "files": [
        "assets/variant-selector/selector.css",
        "tests/e2e/SelectorContrast.spec.mjs"
      ],
      "dod": [
        ["npx", "--no-install", "playwright", "test", "--config=playwright.config.mjs"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["node", "tests/e2e/ProductPageSettingsLive.mjs"]
      ]
    },
    {
      "id": "P022.5",
      "title": "Shopify import page at 360 px and dropzone contrast",
      "depends_on": ["P022.2"],
      "read_first": [
        "src/Admin/ShopifyImportPage.php"
      ],
      "files": [
        "src/Admin/ShopifyImportPage.php",
        "assets/admin/admin.css",
        "tests/e2e/UiStage1Live.mjs"
      ],
      "dod": [
        ["php", "-l", "src/Admin/ShopifyImportPage.php"],
        ["node", "tests/e2e/UiStage1Live.mjs", "import"]
      ]
    },
    {
      "id": "P022.6",
      "title": "Full stage 1 check, notes and report",
      "depends_on": ["P022.3", "P022.4", "P022.5"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P022-ui-a11y-stage1/report.md"
      ],
      "dod": [
        ["node", "tests/e2e/UiStage1Live.mjs"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```

Test notes:
- The live browser scripts run against `http://site1.localhost` and create and delete their own fixture products through WP-CLI, like the existing `*Live.mjs` scripts. They never snapshot or restore the database.
- `UiStage1Live.mjs` takes a section argument (`keyboard`, `focus`, `contrast`, `layout`, `import`). With no argument it runs every section. It measures computed colours and boxes at 360, 768, 1024 and 1440 px and can save screenshots in the same way as the existing live scripts.
- Playwright must be installed (`npm ci`) before any task starts. On 2026-11-20 `node_modules` was missing and `npx --no-install playwright` failed.
- Work on `src/`, `tests/` and `assets/` starts only after P020 has finished its evidence run, so P020 receipts do not go stale.
