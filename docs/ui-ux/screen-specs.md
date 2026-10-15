# Screen-Level Specifications — Product Edit (`post.php`)

## 1. Layout & Information Hierarchy

### 1.1 Metabox Mode (`metabox mode`)
- **Primary Column (`#post-body-content`):**
  1. `#titlediv` (WordPress Product Title)
  2. `#wpe_product_media` (Product Media Library — flattened, 2-row inline grid + compact upload bar)
  3. `#woocommerce-product-data` (Standard WooCommerce Product Data tabs for Simple/Grouped/External)
  4. `#wpe_variants_manager` (Standalone Variants Manager box when `#product-type` is `variable`, hiding legacy Attributes/Variations tabs)
- **Right Sidebar (`#side-sortables`):**
  - `#submitdiv` (Publish / Update)
  - `#postimagediv` and `#woocommerce-product-images` are hidden via `.wpe-box-hidden` so `#wpe_product_media` is the single source of truth for product media.

### 1.2 Two-Block Mode (`blocks mode`)
- **Primary Column (`#post-body-content`):**
  1. `#titlediv` (WordPress Product Title)
  2. `#wpe_product_media` (Product Media Library)
  3. `#wpe_product_info` (Product Information Block — Simple fields or Variable Reference/Common Price)
  4. `#wpe_variants_manager` (Product Variants Block — Descriptive Attributes, Variation Options, Variations Matrix)
- **Legacy Suppression:** `#woocommerce-product-data` is hidden via `.wpe-box-hidden`; `#product-type` is synchronized automatically from `derivedType` (`store.variationOptions.length > 0 ? 'variable' : 'simple'`).

## 2. Key Interaction Flows

### 2.1 Simple ↔ Variable Product Transitions (`blocks mode`)
- **Simple → Variable (`#wpe-simple-to-variable-modal`):**
  - Triggered when clicking `Add Option` on a Simple product in `blocks mode`.
  - Previews the transfer of Simple `regular_price` and `sale_price` into the Variable `commonPrice` configuration.
- **Variable → Simple (`#wpe-variable-to-simple-modal`):**
  - Triggered when deleting the last remaining Variation Option.
  - Allows the user to pick the receiving variation (`#wpe-receiver-variant-select`) and previews the resulting Simple product SKU, price, and stock (`#wpe-receiver-preview-box`).

### 2.2 Selective Combination Generator
- Clicking `Generate Variations` opens `.wpe-modal` with a preview of total combinations, existing combinations, and newly generated combinations.
- Users can select/deselect individual combinations before adding them to the draft table.

### 2.3 Variant Image Assignment (`VariantImagePicker`)
- Clicking a variation row thumbnail (`.wpe-var-thumb-btn`) or option chip swatch button opens `#wpe-variant-image-picker-modal`.
- Supports two assignment scopes:
  - **Single Variation:** Assigns selected image to one variation.
  - **By Option Value:** Bulk-assigns selected image to all variations matching an option value (e.g., all `Color = Red` variations).

### 2.4 Unsaved Draft & Error Recovery
- Any edit to Product Info, Options, Variations, or Media marks the client store dirty (`store.isDirty = true` or `mediaState.isDirty = true`).
- Displays the `Unsaved changes` header badge and activates the browser `beforeunload` confirmation guard until `#publish` is clicked.
- If a server save fails validation (e.g., duplicate SKU or stale version conflict), the client draft is preserved in `sessionStorage` and restored automatically after reload with an explanatory admin notice.
