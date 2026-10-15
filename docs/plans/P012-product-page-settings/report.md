# Report — P012: Storefront Product Page Settings (Price Display & Variant Selector)

## Summary

Completed all 7 tasks (`P012.1`–`P012.7`) to deliver the **Settings → Product Page** (`admin.php?page=wc-product-engine&tab=product-page`) administration interface and storefront **Price Display** + **Variant Selector** enhancements without modifying WordPress or WooCommerce Core files.

### 1. Settings & Persistence (`P012.1`, `P012.2`)
- Added the `Product Page` tab (`tab=product-page`) in `src/Admin/SettingsPage.php` with two sections:
  - **Price Display**: `single_price_display` (default `false`).
  - **Variant Selector**: `show_selected_value`, `show_help_text`, `button_selector`, `color_swatches`, `image_swatches`, and `mark_unavailable_options` (all default `false` so existing behavior is preserved when off).
  - **Global Attribute Display Configuration**: per-attribute `display_type` (`auto|dropdown|button|color|image`), `help_text`, and per-term `#RRGGBB` swatch colors stored in `wpe_attribute_display_config` keyed by stable WooCommerce attribute ID (`attribute_id`).
- Omitted any `Product Information` group per scope constraints.
- Enforced `manage_woocommerce` capability and `wp_rest` nonce validation in `AdminApiController::updateSettings()`, with strict boolean type checking and `#RRGGBB` hex validation (`HTTP 422 validation_failed` on invalid input), plus `wp_cache_flush_group('wpe_Resolve')` on update.

### 2. Product Editor Per-Option UI Metadata (`P012.3`)
- Extended `ProductEditorService`, `assets/admin/product-editor.js`, and `assets/admin/product-editor.css` so each variation-forming option supports:
  - `display_type` (`auto|dropdown|button|color|image`) and `help_text` (up to 255 chars), stored in `wpe_products.extra_props.option_ui[(string)$optionId]`.
  - Per-value `#RRGGBB` color code stored in `wpe_option_values.swatch.color`.
- No database DDL migrations were needed; existing JSON columns (`wpe_products.extra_props` and `wpe_option_values.swatch`) and `wp_options` were reused.

### 3. Variant Resolver & Storefront Adapter (`P012.4`, `P012.5`)
- Extended `VariantResolver` and `ResolverApi` (`GET /wp-json/wpe/v1/products/{id}/resolve`):
  - `lowest_price_html`: formatted HTML price from the lowest effective-priced purchasable variation (`status = 'publish'` and purchasable by stock/backorder policy), keeping regular and sale price from the **same** source variation and never outputting a fake `$0.00` when no purchasable variation has a valid price.
  - `value_thumbnails`: 5-step candidate resolution per option value ordered strictly by saved variation order (`position ASC, id ASC`), falling back to parent featured image then WooCommerce placeholder, and preserving a representative thumbnail when a candidate combination is unavailable.
  - `allowed_details`: distinguishes existing combinations from purchasable combinations (`is_purchasable`, `stock_status`, `backorders`).
  - `variant.price_html` and `variant.image`: full variation price HTML and gallery image DTO (`id`, `url`, `src`, `alt`, `srcset`, `sizes`).
- Updated `SelectorAdapter` and `ProductLifecycle`:
  - Renders exactly one control per attribute (`dropdown`, `button`, `color`, or `image`) with automatic fallback to `dropdown` when the corresponding global toggle is off, keeping a hidden `<select class="wpe-select wpe-select-hidden">` synchronized for form compatibility.
  - Filters `woocommerce_get_price_html` when `single_price_display` is enabled on both single product pages (`.wpe-single-price-target`) and shop/category/search/archive product cards without altering DB, cart, checkout, or order prices.
  - Invalidates `wpe_Resolve` cache across settings, option, media, pricing, and inventory changes.

### 4. Client-Side Synchronization, Accessibility & Isolation (`P012.6`, `P012.7`)
- Updated `assets/variant-selector/selector.js` and `assets/variant-selector/selector.css`:
  - Synchronizes attribute label selected values (`Name: Label`), help text (`aria-describedby`), dynamic Image Swatch candidate thumbnails, main gallery image swap/restore, main price area updates, unavailable option styling (`.is-marked-unavailable`), and Add to Cart lock/unlock on every selection change and reset.
  - Protects against stale out-of-order responses using a monotonic `requestSeq` counter and `AbortController`.
  - Scopes all DOM lookups and updates to the product instance container (`.wpe-product-instance` / `form.closest('.product')`) so main product and quick-view instances on the same page never interfere.

### 5. Verified Surfaces & Test Evidence
- `node scripts/check-docs.mjs --strict` -> exit `0` (`errors: 0, warnings: 0`)
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/SettingsApiTest.php` -> exit `0` (`106 checks passed`)
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/ProductEditorServiceTest.php` -> exit `0`
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/T25Test.php` -> exit `0`
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/T26Test.php` -> exit `0`
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/T27Test.php` -> exit `0` (including Playwright `SelectorBehavior.spec.mjs`)
- `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/ProductPageSettingsTest.php` -> exit `0` (`51 checks passed`)
- `node tests/e2e/ProductPageSettingsLive.mjs` -> exit `0` (`21/21 live browser checks passed, 0 console errors`)
- **Verified Surfaces**: Classic WooCommerce single product (`content-single-product.php` / `variable.php`) and archive/category loop templates (`content-product.php`), Admin `Settings → Product Page` (`tab=product-page`), Product Editor Variants Manager, and `/wp-json/wpe/v1/*` REST endpoints. WooCommerce Blocks and custom third-party themes/extensions are not claimed without separate verification.
