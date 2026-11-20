# P026 Report — UI stage 3: native buttons, !important removal, dead CSS and theme isolation

## Summary

Completed UI stage 3 from the 2026-11-20 UI review (`docs/plans/P022-ui-a11y-stage1/report.md` follow-ups) across 4 tasks (`P026.1`–`P026.4`):

- Removed all 29 verified unused `.wpe-*` CSS classes across `assets/admin/product-editor.css` (24 -> 0), `assets/admin/admin.css` (3 -> 0), and `assets/variant-selector/selector.css` (2 -> 0), and extracted 3 duplicated HTML template blocks in `assets/admin/product-editor.js` into pure helpers (`buildMediaEmptyHtml`, `buildMediaUrlBarHtml`, `showBulkDiffPreview`).
- Slashed `!important` declarations across all plugin CSS files from 343 to 22 (93.6% reduction), using clean WordPress admin (`.wp-core-ui` / `body.wp-admin`) and storefront (`.wpe-swatches .wpe-swatch.wpe-swatch`) specificity while keeping `!important` strictly limited to legacy panel hiding, `:focus-visible`, `prefers-reduced-motion`, single-price/competing-theme hiding, and `.wpe-sr-only`/`.wpe-select-hidden` visually-hidden utilities.
- Hardened `assets/variant-selector/selector.css` with scoped `--wpe-sel-*` custom properties and defensive resets against hostile theme `button`, `img`, and `label` styles, verified on light and dark themes by `tests/e2e/SelectorThemeIsolation.spec.mjs`.
- Verified with `tests/e2e/UiStage3Live.mjs` (CSS budgets, zero dead classes, live specificity checks on Product Editor, Shopify Import, and Storefront PDP) alongside full Stage 1, Stage 2, and Playwright storefront suites.

## CSS Metrics Before / After

| Stylesheet | `!important` Before | `!important` After | Unused `.wpe-*` Before | Unused `.wpe-*` After | Remaining `!important` justification |
|---|---:|---:|---:|---:|---|
| `assets/admin/tokens.css` | 0 | 0 | 0 | 0 | None |
| `assets/admin/admin.css` | 2 | 0 | 3 | 0 | None |
| `assets/admin/settings.css` | 3 | 3 | 0 | 0 | `[hidden]` (1), `prefers-reduced-motion` (2) |
| `assets/admin/product-editor.css` | 318 | 8 | 24 | 0 | Legacy WC panel/box hiding (3), `:focus-visible` outline (2), `prefers-reduced-motion` (3) |
| `assets/variant-selector/selector.css` | 20 | 11 | 2 | 0 | Single-price hide (1), competing theme hide (1), `.wpe-sr-only`/`.wpe-select-hidden` (9) |
| **Total** | **343** | **22** | **29** | **0** | **-321 `!important` (-93.6%), -29 dead classes (-100%)** |

## Task Breakdown & Commits

- **`P026.1` (`781d443`)**: Removed 24 dead classes from `product-editor.css` and 3 from `admin.css`; extracted `buildMediaEmptyHtml`, `buildMediaUrlBarHtml`, and `showBulkDiffPreview` in `product-editor.js`.
- **`P026.2` (`8315831`)**: Replaced routine `!important` overrides in `product-editor.css` (318 -> 8) and `admin.css` (2 -> 0) with clean scoped WordPress admin specificity.
- **`P026.3` (`b3e7524`)**: Added scoped `--wpe-sel-*` tokens, reduced `!important` (20 -> 11), removed 2 dead selectors, hardened hostile-theme isolation in `assets/variant-selector/selector.css`, and added `tests/e2e/SelectorThemeIsolation.spec.mjs`.
- **`P026.4`**: Added `tests/e2e/UiStage3Live.mjs`, verified full Stage 1 + Stage 2 + Stage 3 + Playwright suites, docs, and guard checks, and closed `P026`.

## Verification Evidence

- `node tests/e2e/UiStage3Live.mjs` — CSS `!important` budgets, zero dead `.wpe-*` classes, live Product Editor specificity, Shopify Import dragover highlight without `!important`, live PDP `--wpe-sel-border` token resolution, zero console/page errors.
- `npx --no-install playwright test --config=playwright.config.mjs` — **8/8 passed** (`SelectorBehavior.spec.mjs` 3/3, `SelectorContrast.spec.mjs` 2/2, `SelectorThemeIsolation.spec.mjs` 3/3).
- `node tests/e2e/UiStage1Live.mjs` — **30/30 passed**.
- `node tests/e2e/UiStage2Live.mjs` — **16/16 passed**.
- `node tests/e2e/VariantsBlockLive.mjs` — **71/71 passed** (P026.2).
- `node tests/e2e/ProductInfoBlockLive.mjs` — **42/42 passed** (P026.2).
- `node tests/e2e/VariantImagePickerLive.mjs` — **14/14 passed** (P026.2).
- `node tests/e2e/ProductMediaLive.mjs` — **28/28 passed** (P026.1).
- `node tests/e2e/TwoBlockLive.mjs` — **52/52 passed** (P026.1).
- `node tests/e2e/ProductPageSettingsLive.mjs` — **26/26 passed** (P026.3).
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/ProductPageSettingsTest.php` — exit 0 (P026.3).
- `node scripts/check-docs.mjs --strict`, `node scripts/guard.mjs --all`, `node scripts/plan.mjs check` — exit 0.

## Follow-ups & Scope Boundaries

- **Tracked evidence refresh:** Refresh `T02` (`PluginBootstrap.php`), `T27` (`selector.css`), and `T41` (`OperationsPage.php`) evidence on the isolated test root (`test_wpe_regress`) so `rerun-evidence.mjs --verify-only` passes 49/49.
- **Approval-gated items remain untouched:** i18n of Vietnamese strings / currency symbol, media API thumbnail URLs, field-level error mapping, and server-side WooCommerce panel removal were explicitly kept out of scope.
- **Verification boundary:** These checks verify UI styling, accessibility, contrast, theme isolation, and live browser regressions on `site1.localhost`; they are not a WooCommerce integration certification or live S3 certification.
