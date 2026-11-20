# Report — P022: UI stage 1: keyboard, focus, contrast and responsive fixes

## Summary

Completed `P022.1`–`P022.6`. The product editor can now be used with the keyboard: every control has an accessible name and a visible focus ring. Text, control borders and stock badges meet WCAG 2.2 AA contrast. The save bar, fullscreen editor and variants matrix work from 360 to 1440 px. Unavailable storefront swatches stay legible. Error toasts stay until closed, and animations follow `prefers-reduced-motion`.

| Measured (browser, computed styles) | Before | After |
|---|---|---|
| Image picker: choose an image with the keyboard | not possible (`div`) | Tab + Space/Enter |
| Buttons in the editor with a visible keyboard focus ring | none (box-shadow removed by `!important`) | 2 px outline |
| Table input border on a hovered row | 1.23:1 | 3.53:1 |
| Settings input border | 1.34:1 | 3.24:1 |
| "Out of Stock" / "In Stock" badge | 3.95 / 4.49:1 | 5.9 / 6.5:1 |
| Save bar at 360 px | −60 to 420 px (buttons cut off) | inside the viewport |
| Fullscreen editor at ≤ 782 px | starts at y = 32 under a 46 px admin bar | covers the viewport |
| Row actions at 360–1024 px | outside the visible table | sticky, always visible |
| Error toast | gone after 3.5 s | stays until closed, announced assertively |
| Storefront swatch border | 1.61:1 | 3.24:1 |
| Unavailable swatch | opacity 0.45 | opacity 1, label 5.15:1, mark 4.4:1 |
| Storefront help text on a dark theme | 2.51:1 | 10.99:1 |
| Import dropzone border | 1.67:1 | 3.1:1 |

## Tasks

- `P022.1` — Picker images, row thumbnails, row image remove, chip move/remove and the shared-stock pill are native buttons. JS hook classes and `data-*` are unchanged. The ten close buttons share `closeButtonHtml()`, which renders a dashicon with `aria-label="Close"`. Icon-only buttons, matrix inputs, selects and checkboxes are labelled with the variant or value they act on. After a re-render, focus returns to the moved chip, the option input or the row thumbnail.
- `P022.2` — `:focus-visible` outline for buttons and links in every editor layer. A control border token, `--wpe-p-color-border-control`, is used for inputs, selects and buttons. Stock badge and glyph colours meet AA. Settings inputs use `#8c8f94`. Reduced motion is covered in CSS for all layers and in jQuery through `motionMs()`.
- `P022.3` — Save bar `min(440px, 100vw - 24px)`, wrapping at ≤ 782 px. Fullscreen uses `inset: 0` at z-index 100010. The matrix uses `table-layout: fixed`, with sticky select and actions columns. Toasts use polite and assertive live regions created at start-up. Error toasts persist and have a Close button. Success and warning toasts hide after 5 s and pause on hover or focus.
- `P022.4` — Storefront swatch border, an unavailable state without opacity, and muted text at 85% of the theme text colour (`color-mix`, with an `inherit` fallback).
- `P022.5` — Import dropzone border.
- `P022.6` — Full run, `docs/NOTES.md`, this report.

## Deviations from the approved plan

- **D03, control border in the product editor.** `#7e8287` instead of `#8c8f94`, because `#8c8f94` is only 2.96:1 on the editor's tinted row-hover and card surfaces (`#f1f5f9`). Settings, which only has white surfaces, uses `#8c8f94`.
- **D03, storefront muted text.** 85% of `currentColor` instead of 72%. 72% drops a grey theme text colour such as `#555` below 4.5:1.
- **R-03 (import page overflow at 360 px).** This was an estimate in the review and did not reproduce: the unchanged page has no horizontal scroll at 360 px. Its `min-width` and `.wrap` margins are left as they were. The check stays in `UiStage1Live.mjs` as a guard.
- **Outside the plan, separate commit `3438f85`.** The `tests/e2e/ProductPageSettingsLive.mjs` fixture now creates attachments for its own assets. Since F07 (`c11a85b`), the storefront gallery skips assets that have no attachment, and the fixture inserted media rows directly. The script failed 3/26 before any P022 change, and the user chose to fix the fixture.

## Evidence

- New checks: `tests/e2e/UiStage1Live.mjs` (live; sections keyboard 12, focus 3, contrast 6, layout 6, import 3) and `tests/e2e/SelectorContrast.spec.mjs` (offline; light and dark theme). Each section was also run against the code from before its task, and it failed there.
- DoD per task is recorded in `log.jsonl`. The DoD covers `VariantImagePickerLive` 14/14, `ProductMediaLive` 28/28, `TwoBlockLive` 52/52, `VariantsBlockLive` 71/71, `ProductInfoBlockLive` 42/42, `ProductPageSettingsLive` 26/26, `ProductPageSettingsTest`, and Playwright 5/5.
- These are browser checks against site1 and a local page. They are not a WooCommerce integration certification.
- `assets/variant-selector/selector.css` is a T27 task file, so T27's evidence receipt is stale after `P022.4` until T27 is re-run on the isolated root.
- On site1, `opcache.revalidate_freq` is 60 s. A changed PHP file can take up to a minute to be served, so the checks for PHP markup waited until the new markup was served.

## Follow-ups (not in scope)

- Stage 2 (design tokens, inline styles to classes) and stage 3 (native buttons, `!important` removal, components, dead CSS, theme isolation) from the UI review.
- Approval needed: i18n of hard-coded Vietnamese strings and the fixed `$` (T-05, T-06), thumbnail URLs in the media API (I-06), field-level error mapping (N-02), and server-side removal of WooCommerce panels (A-03).
