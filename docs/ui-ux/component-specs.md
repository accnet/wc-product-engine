# Component Specifications — WC Product Engine Admin UI

## 1. Flattened Product Media Library (`#wpe_product_media`)

### 1.1 Structure
- **Container:** `.wpe-section--flat` removes redundant outer borders, shadows, and padding when rendered inside `#wpe_product_media .inside`.
- **Header (`.wpe-media-section-header`):** Displays image count badge (`{total} images`) and the `View All ({total})` button (`.wpe-btn-open-media-popup`) whenever `total > 0`.
- **2-Row Inline Grid (`.wpe-media-grid--2rows`):**
  - CSS Grid: `grid-template-columns: repeat(auto-fill, minmax(96px, 1fr))` with `grid-auto-rows: 96px` and `max-height: 202px` (`2 * 96px + 10px` gap).
  - First cell (index `0`) is highlighted with a `2px solid var(--wpe-accent-primary)` border and a top-left `Featured` badge (`.wpe-media-badge-featured`).
  - Hover overlay (`.wpe-media-item-actions`) provides quick `Set Featured` (`★`) and `Unlink` (`×`) actions.
- **Compact Upload Bar (`.wpe-media-upload-zone--compact`):**
  - Single-row horizontal bar (`min-height: 44px`, `padding: 8px 12px`) with upload icon, `Click or drag images to upload` label, and `JPEG, PNG, WebP` hint.

### 1.2 Media Popup Modal (`.wpe-media-popup-backdrop`)
- Opens when clicking `View All ({total})`.
- Displays all product images in a scrollable multi-row grid (`.wpe-media-grid--popup`) with drag-and-drop sorting, `Set Featured`, `Unlink`, and direct upload dropzone.

## 2. Option Value Chips with Color Swatch (`.wpe-chip-option`)

- **Anatomy:**
  - Optional thumbnail image (`.wpe-chip-swatch-img`) when a variation/option image is assigned (`has-swatch` class).
  - Automatic color dot (`.wpe-chip-color-dot`) rendered when the option name matches a color attribute (`color`, `colour`, `màu`, `mau`, `màu sắc`) and no image overrides it.
  - Value label (`<span>`) and remove button (`.wpe-chip-remove`).
- **States:** Default, Hover, Image-assigned (`has-swatch`), Focus-visible on action buttons.

## 3. Variations Data Table & Toolbar (`.wpe-table`)

- **Toolbar (`.wpe-toolbar`):**
  - Search input (`.wpe-input-search`) with persistent focus and caret preservation across reactive re-renders.
  - Option filter dropdowns (`.wpe-select-filter`).
  - Status & stock quick filters (`All`, `Active`, `Hidden`, `In stock`, `Out of stock`).
- **Bulk Action Bar (`.wpe-bulk-bar`):**
  - Contextual appearance when `selectedRowKeys.size > 0` or via bulk action selector (`Set Regular Price`, `Set Sale Price`, `Adjust Price %`, `Toggle Stock`, `Assign Image`) with live diff preview before applying.
- **Row Controls:**
  - `36x36px` thumbnail button (`.wpe-var-thumb-btn`) opening the Variant Image Picker modal (`Single Variant` vs `Option Group` bulk assignment).
  - Inline Regular Price, Sale Price, SKU, Stock Quantity, and `Details` button (`.wpe-btn-open-drawer`).

## 4. Slide-over Variant Detail Drawer (`.wpe-drawer`)

- **Header (`.wpe-drawer-header`):**
  - Displays variation title (`S / Red`) and sequential navigation group (`.wpe-drawer-nav`) containing `.wpe-btn-drawer-prev` (`‹ Prev`), position counter (`1 / 4`), `.wpe-btn-drawer-next` (`Next ›`), and close button (`.wpe-btn-close-drawer`).
- **Navigation Behavior:**
  - Clicking `‹ Prev` or `Next ›` commits any pending input edits in the current drawer to the client draft and loads the adjacent variation in `store.filteredVariations` without closing the drawer.
- **Footer (`.wpe-drawer-footer`):**
  - `Cancel` and `Apply to Draft` buttons.

## 5. Product Information Block (`#wpe_product_info`)

- **Simple Mode:** Renders editable Regular Price (`#wpe-info-regular-price`), Sale Price (`#wpe-info-sale-price`), SKU (`#wpe-info-sku`), Stock management toggles, and Shipping dimensions.
- **Variable Mode:**
  - Displays `Reference Price (First Variant)` card (`#wpe-reference-price-card`) calculated from `store.variations[0]` (invariant to table search/filtering, distinguishing `$0.00` from `No price yet`).
  - Displays `Common Price for All Variations` card (`#wpe-common-price-card`) with `Remove common price` confirmation modal (`#wpe-common-removal-modal`) and `Re-apply to all` action.
