# P025 Report — UI stage 2: unified design tokens and inline styles to classes

## Summary

Completed UI stage 2 from the 2026-11-20 UI review (`docs/plans/P022-ui-a11y-stage1/report.md` follow-ups) across 4 tasks (`P025.1`–`P025.4`):

- Created `assets/admin/tokens.css` as the single `:root` admin design-token stylesheet (`wpe-admin-tokens`), enqueued before every WPE admin screen stylesheet via `WPE\Support\Assets` in `src/Bootstrap/PluginBootstrap.php`, `src/Admin/ProductEditor/ProductEditorBridge.php`, and `src/Admin/OperationsPage.php`.
- Aligned `assets/admin/settings.css`, `assets/admin/admin.css`, and `assets/admin/product-editor.css` (aliasing `--wpe-p-*` to `--wpe-*`) onto the shared tokens while preserving every P022 WCAG 2.2 AA contrast token (`--wpe-border-control: #8c8f94`, `--wpe-border-control-tinted: #7e8287`, stock badges `5.9:1` / `6.5:1`).
- Eliminated 282 static inline `style="..."` attributes across admin PHP and JS templates and replaced them with token-backed semantic CSS classes and native `hidden` / `.wpe-hidden` state toggles.
- Verified with a new live browser & source-audit suite (`tests/e2e/UiStage2Live.mjs`) plus full regression across `UiStage1Live.mjs`, `ProductMediaLive.mjs`, `VariantImagePickerLive.mjs`, `TwoBlockLive.mjs`, `ProductInfoBlockLive.mjs`, `VariantsBlockLive.mjs`, `ProductEditorBridgeTest.php`, and `T41Test.php`.

## 2. Before / After Inline Style Counts

| File | Before P025 | After P025 | Remaining inline styles |
|---|---:|---:|---|
| `src/Admin/ShopifyImportPage.php` | 71 | 0 | None |
| `src/Admin/OperationsPage.php` | 12 | 0 | None |
| `src/Admin/ProductEditor/ProductEditorBridge.php` | 2 | 0 | None (overlays use native `hidden`) |
| `src/Admin/SettingsPage.php` | 0 | 0 | None |
| `assets/admin/shopify-import.js` | 1 | 0 | None |
| `assets/admin/product-editor.js` | 197 | 1 | 1 runtime dynamic value: `.wpe-chip-color-dot` `background-color` from user hex color |
| **Total** | **283** | **1** | **282 static inline styles removed (99.6%)** |

## 3. Task Breakdown & Commits

- **`P025.1` (`5aaf6d1`)**: Added `assets/admin/tokens.css`, registered `wpe-admin-tokens` dependency across `PluginBootstrap`, `ProductEditorBridge`, and `OperationsPage`, and aligned `admin.css`, `settings.css`, and `product-editor.css`.
- **`P025.2` (`ee00a94`)**: Removed all static inline styles from `ShopifyImportPage.php`, `OperationsPage.php`, `ProductEditorBridge.php`, and `shopify-import.js`; migrated editor overlays (`#wpe-fullscreen-modal-overlay`, `#wpe-variation-drawer-overlay`) to native `hidden` attribute with jQuery `.prop('hidden', ...)` compatibility.
- **`P025.3` (`9b42b3f`)**: Replaced 196 of 197 inline styles in `assets/admin/product-editor.js` with token-backed semantic classes in `assets/admin/product-editor.css`, preserving all JS hook classes, `data-*` attributes, and jQuery flex animations (`#wpe-drawer-stock-fields`, `#wpe-bulk-diff-preview`).
- **`P025.4`**: Added `tests/e2e/UiStage2Live.mjs`, verified full Stage 1 + Stage 2 browser checks, docs validation, and guard checks, and closed `P025`.

## 4. Verification Evidence

- `node tests/e2e/UiStage2Live.mjs` — **16/16 passed** (source audit + live DOM/token checks across Product Editor, Settings, Shopify Import, Operations & Health, zero console/page errors).
- `node tests/e2e/UiStage1Live.mjs` — **30/30 passed** (keyboard, focus, contrast, layout, import).
- `node tests/e2e/ProductMediaLive.mjs` — **28/28 passed**.
- `node tests/e2e/VariantImagePickerLive.mjs` — **14/14 passed**.
- `node tests/e2e/TwoBlockLive.mjs` — **52/52 passed**.
- `node tests/e2e/ProductInfoBlockLive.mjs` — **42/42 passed**.
- `node tests/e2e/VariantsBlockLive.mjs` — **71/71 passed**.
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/ProductEditorBridgeTest.php` — exit 0.
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/T41Test.php` — exit 0.
- `node scripts/check-docs.mjs --strict`, `node scripts/guard.mjs --all`, `node scripts/plan.mjs check` — exit 0.

## 5. Follow-ups & Scope Boundaries

- **Tracked evidence files modified:** `src/Bootstrap/PluginBootstrap.php` (`T02`) and `src/Admin/OperationsPage.php` (`T41`) were modified; refresh `T02` and `T41` evidence against the isolated test root (`test_wpe_regress`) after Stage 3 (`P026`).
- **Stage 3 (`P026`):** Dead CSS removal, `!important` cleanup, native WP button alignment, and storefront theme isolation.
- **Verification boundary:** These checks verify admin UI styling, accessibility, and live browser regressions on `site1.localhost`; they are not a WooCommerce integration certification or live S3 certification.
