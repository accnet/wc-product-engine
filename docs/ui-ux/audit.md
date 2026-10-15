# UI/UX Audit — Product Edit Interface (`post.php?post={id}&action=edit`)

## 1. Executive Summary

This audit examines the existing WordPress & WooCommerce Product Edit interface managed by **WC Product Engine** (`WPE\Admin\ProductEditor\ProductEditorBridge` and `assets/admin/product-editor.js`). The objective is to identify structural, visual, interaction, and accessibility friction points and establish a prioritized modernization plan aligned with Modern Commerce SaaS standards (Shopify Admin, Linear, Stripe Dashboard) while remaining 100% native-compatible with WordPress Admin (`post.php`).

## 2. Interface Inventory

| Surface ID | Component / Metabox | Editor Mode | Primary User Goal |
| --- | --- | --- | --- |
| `#wpe_product_media` | Product Media Library | Metabox & Blocks | Upload, reorder, set featured image, and manage product gallery images with variant impact protection |
| `#wpe_product_info` | Product Information Block | Blocks | Manage pricing (Simple or Common/Reference), inventory, SKU, and shipping dimensions |
| `#wpe_variants_manager` | Product Variants Block | Blocks | Manage descriptive attributes, variation options, and variant matrix in standalone block mode |
| `#wpe-product-editor-inline-app` | Inline Variants Panel | Metabox & Blocks | Full-featured variant matrix, option builder, search/filter toolbar, bulk edit, and image assignment |
| `.wpe-drawer` | Variant Slide-over Drawer | Metabox & Blocks | Edit complete details (SKU, pricing, stock, dimensions, description, image) of a single variation |
| `.wpe-fullscreen-overlay` | Fullscreen Variants Editor | Metabox & Blocks | High-density workspace for products with large numbers of variations and attributes |

## 3. Core Usability & Architecture Findings

### 3.1 Redundant Media Metaboxes & Double Card Wrapping
- **Finding:** On `post.php`, WordPress Core renders `#postimagediv` (Featured Image) and WooCommerce renders `#woocommerce-product-images` (Product Gallery) in the right sidebar alongside `#wpe_product_media`. Having three separate media surfaces confuses operators about which box is authoritative.
- **Finding:** Inside `#wpe_product_media`, `.wpe-section` adds a second bordered card with its own padding and header inside `.postbox`, creating a nested box-in-box visual defect and wasting horizontal space.
- **Remediation:** Hide `#postimagediv` and `#woocommerce-product-images` via `.wpe-box-hidden` when native media management is active. Flatten `.wpe-section--flat` inside `#wpe_product_media` and replace the oversized dropzone with a compact horizontal toolbar bar (`.wpe-media-upload-zone--compact`).

### 3.2 Language Mixing in Admin Labels
- **Finding:** Several newly added media library controls mixed Vietnamese labels (`Xem thêm`, `Tất cả hình ảnh`, `Đóng`) into an otherwise English admin interface (`Featured`, `Set Featured`, `Unlink`, `Variations`).
- **Remediation:** Standardize all user-facing strings in `#wpe_product_media` and its modal to consistent English (`View All ({total})`, `All Images ({total})`, `Close`, `Click or drag images to upload`).

### 3.3 Search Input Focus Loss During Reactive Re-renders
- **Finding:** Typing in `.wpe-input-search` triggered `renderVariantsApp()` or `renderFullscreenApp()`, which replaced `container.innerHTML` and destroyed input focus and cursor selection (`selectionStart`/`selectionEnd`) after every keystroke.
- **Remediation:** Capture active focus and caret range before replacing `innerHTML` and restore them immediately after DOM injection.

### 3.4 Option Value Chips Lacking Visual Swatch Context
- **Finding:** Option value chips in Section B (`Variation Options`) displayed text labels and image thumbnails when assigned, but lacked a visual color dot indicator for color options (`Color`, `Colour`, `Màu sắc`).
- **Remediation:** Render a deterministic `.wpe-chip-color-dot` swatch inside option chips when the option represents a color and no thumbnail image overrides it.

### 3.5 High-Click Sequential Variant Editing in Slide-over Drawer
- **Finding:** Editing multiple variations sequentially via the Slide-over Drawer required closing the drawer, locating the next row in the table, and clicking `Details` again.
- **Remediation:** Add `‹ Prev` and `Next ›` navigation buttons (`.wpe-drawer-nav`) directly in the drawer header to cycle through `store.filteredVariations` while auto-committing current drawer edits to the client draft.

### 3.6 Unnecessary Variant Fetch on Simple Products in Metabox Mode
- **Finding:** Opening a `simple` product in metabox mode immediately invoked `loadData()` (`GET /variants` + `GET /media`), even though the Variants tab is inactive for simple products in metabox mode.
- **Remediation:** Introduce `loadMediaOnly()` (`GET /media`) on initial load for simple products in metabox mode, deferring full `loadData()` until the user switches `#product-type` to `variable`.

## 4. Accessibility & Technical Styling Debt

| Area | Issue Identified | Target Standard |
| --- | --- | --- |
| CSS Custom Properties | Fragmented colors (`#007cba`, `#2271b1`, `#4f46e5`, `#008060`) across components | Unified `--wpe-*` token system in `:root` scoped to `.wpe-*` |
| Keyboard Navigation | Modals and drawers needed consistent `Escape` dismissal and focus restoration | Full `Escape` handling and deterministic focus restoration |
| Contrast & Readability | Secondary labels and badges used inconsistent font sizes (`10px` to `15px`) | Standardized typography scale (`11px` uppercase headers to `18px` modal titles) |
| Save Guard | Media changes did not mark the form dirty for `beforeunload` protection | `markMediaDirty()` integrated with `beforeunload` leave guard |
