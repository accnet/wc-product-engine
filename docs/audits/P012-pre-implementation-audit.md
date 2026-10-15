# P012 pre-implementation audit

Read-only repository survey for plan P012 (Settings → Product Page: storefront price display and variant selector management). Date: 2026-10-14. Source and database inspection only; not a WooCommerce Blocks or third-party theme certification.

## 1. Method

- Read `AGENTS.md`, `docs/02-domain.md`, `docs/03-database-schema.md`, `docs/04-api-contract.md`, `docs/09-ui.md`, `docs/10-integrations.md`, `contracts/database/schema.sql`.
- Inspected the settings framework (`src/Admin/SettingsPage.php`, `src/Admin/SettingsRegistry.php`, `src/Admin/Rest/AdminApiController.php`, `src/Bootstrap/PluginBootstrap.php`, `assets/admin/settings.js`, `assets/admin/settings.css`).
- Inspected the variant resolver, pricing, gallery, and storefront selector code (`src/Application/Variant/VariantResolver.php`, `src/Application/Variant/EffectivePriceResolver.php`, `src/Application/Variant/ResolverApi.php`, `src/Compatibility/StoreApi/SelectorAdapter.php`, `src/Compatibility/Gallery/GalleryAdapter.php`, `assets/variant-selector/selector.js`, `assets/variant-selector/selector.css`).
- Inspected the product editor option persistence (`src/Infrastructure/Database/OptionsRepository.php`, `src/Admin/ProductEditor/ProductEditorService.php`, `assets/admin/product-editor.js`).

## 2. Findings

### F01 Settings framework is ready for tabbed grouping, with a strict key-parity invariant

- `SettingsPage` (`src/Admin/SettingsPage.php`) renders tabs from `SettingsPage::TABS` and delegates flag persistence to `POST /wp-json/wpe/v1/admin/settings` (`AdminApiController::saveSettings`), which checks `manage_woocommerce` and validates the payload through `SettingsRegistry::validate`.
- `SettingsApiTest::testRegistryMatchesStoredDefaults` enforces that the keys returned by `PluginBootstrap::getFlags` match `SettingsRegistry::flagKeys` plus `SettingsRegistry::readOnlyKeys`. Every new boolean toggle (`single_price_display`, `show_selected_value`, `show_help_text`, `button_selector`, `color_swatches`, `image_swatches`, `mark_unavailable_options`) must be registered in both `PluginBootstrap` (default `false`) and `SettingsRegistry` with a `group` metadata field (`features` vs `product_page`) so the `Features` and `Product Page` tabs render their own sections while sharing the atomic REST validation and save bar.

### F02 Storefront resolver and selector adapters are not yet wired into PluginBootstrap

- `ResolverApi::register` (`src/Application/Variant/ResolverApi.php`) and `SelectorAdapter::register` (`src/Compatibility/StoreApi/SelectorAdapter.php`) exist and are tested in isolation (`T26Test`, `T27Test`), but neither is called in `PluginBootstrap::onPluginsLoaded`.
- `SelectorAdapter` does not yet hook into the classic WooCommerce single-product variation template (`woocommerce_dropdown_variation_attribute_options_html` / `woocommerce_before_variations_form`) to enhance attribute controls or localize per-instance configuration on the storefront.

### F03 SelectorAdapter currently guesses swatch types from attribute names

- `SelectorAdapter::detectSwatchType` checks whether the attribute key or name contains `color`, `colour`, or `pattern`, and `SelectorAdapter::resolveSwatchValue` maps a hardcoded English color list (`black`, `white`, `red`, `blue`, `green`, `yellow`, `gray`, `grey`) to hex codes.
- This violates requirement 9: each attribute must have a single explicit display type (`dropdown`, `button`, `color`, `image`) keyed by stable attribute/option ID, never guessed from "Color/Size" names, with fallback to `dropdown` when its global toggle is off, and defaulting `image` to the first variation-forming option by saved position when `image_swatches` is enabled and the option has no explicit display type configured.

### F04 Per-attribute display type, help text, and color hex storage need no DDL column changes

- `SchemaMigrator::migrate` only executes create-table-if-not-exists statements and does not alter existing tables.
- `wpe_option_values` already has a `swatch` JSON column (`LONGTEXT NULL`) supported by `OptionsRepository::createOptionValue` and `OptionsRepository::updateOptionValue`, which can store `{"color":"#rrggbb"}` per option value ID.
- `wpe_products` already has an `extra_props` JSON column (`LONGTEXT NOT NULL`), where per-option UI metadata (`display_type` in `auto|dropdown|button|color|image` and `help_text`) can be stored under `extra_props.option_ui.{option_id}` keyed by stable `wpe_options.id`. Global attribute taxonomy defaults (`pa_*`) can additionally be stored in a WordPress option (`wpe_attribute_display_config`) keyed by stable attribute taxonomy ID/slug and inherited when a product option leaves `display_type` as `auto` or `help_text` empty.

### F05 VariantResolver::computeAllowedValues hardcodes purchasable and in_stock as true

- In `VariantResolver::computeAllowedValues` (`src/Application/Variant/VariantResolver.php`, lines 260–329), any active (`status = 'publish'`, `deleted_at IS NULL`) variation matching a candidate selection marks its option values with `'exists' => true, 'purchasable' => true, 'in_stock' => true` without checking whether that candidate variation actually has a price (`EffectivePriceResolver::resolve`) or is in stock / allows backorders in `wpe_inventory`.
- Requirement 5, 7, and 8 require candidate values that only lead to unpriced or out-of-stock (with backorders disabled) variations to be treated as unavailable (`purchasable: false`), preventing shoppers from selecting unpurchasable combinations and driving the `mark_unavailable_options` visual state.

### F06 Candidate image swatch resolution requires ordered variation media lookup per value

- `SelectorAdapter::resolveSwatchValue` currently queries a single static variation image for a `value_id` without considering the currently selected values of other options, and returns `null` when no variation has an image.
- Requirement 7 specifies a deterministic 5-step resolution for each value of an image-swatch attribute:
  1. Form a candidate selection with that value.
  2. Keep the currently selected values of all other options.
  3. Find active variations matching the candidate selection ordered by saved variation position (`position ASC, id ASC`).
  4. Take the first valid primary variation image (`wpe_variant_media` + `wpe_assets` where `health != 'broken'`).
  5. If the matching group lacks a variation image, fall back to the parent product featured image (`wpe_product_media` with `role = 'featured'`), then the inline SVG placeholder.
- If no variation matches the candidate selection, the value is marked unavailable while keeping its representative thumbnail (resolved for that value alone, or product featured image / placeholder) so the swatch tile never disappears or misleads the shopper with an unrelated combination's image.
- Returning `value_thumbnails` in `VariantResolver::resolve` allows the client to update thumbnails on every selection change without hydrating all variations into browser memory.

### F07 Single Price Display must not rely on WooCommerce's conditional variation.price_html

- WooCommerce Core only populates `variation.price_html` inside `woocommerce_available_variation` when variations of a variable product have differing prices or when a custom filter forces it.
- When `single_price_display` is enabled:
  - The main price area (on single product pages and on shop/category/search/product cards) must show the lowest effective price among valid, purchasable, in-stock/backorderable variations, with both regular and sale prices taken from that same source variation and formatted through WooCommerce's currency, tax display, price suffix, and UTC sale schedule rules.
  - If a valid default selection resolves to an exact purchasable variation on initial load, the main price area displays that variation's formatted price.
  - Selecting all options updates the main price area to the resolved variation's full `price_html`; partial selection or reset restores the lowest effective price; non-existent or non-purchasable combinations show the unavailable state and disable Add to cart; if no valid purchasable variation has a price, no fake zero price is rendered.
  - The secondary variation price container (`.woocommerce-variation-price`) below the options is hidden while keeping variation description, stock status, and purchase controls.

### F08 Client selector DOM queries are currently document-global

- `assets/variant-selector/selector.js` queries `document.querySelector('.woocommerce-product-gallery')` and `document.querySelector('.single-product div.product .price')` globally and reads a single `window.wpeSelectorData` object.
- Requirement 10 requires scoping all DOM lookups and event handlers to the specific product container instance (`.product` / `.wpe-product-instance`) and reading per-form configuration (`data-wpe-selector-config`) so a main product and a quick-view modal on the same page never overwrite each other's price, gallery, or selection state.

## 3. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Adding new settings flags breaks `SettingsApiTest::testRegistryMatchesStoredDefaults` | Register all 7 flags in both `PluginBootstrap::loadFlags` / `onActivate` and `SettingsRegistry::flags` with default `false` |
| Sale strikethrough mixes regular price from variation A with sale price from variation B | Pick the single winning lowest-active-price purchasable variation first (`position ASC, id ASC` tie-breaker), then format both regular and sale from that same variation |
| Unpriced variable product renders `$0.00` when `single_price_display` is on | Return empty price HTML when no purchasable variation has `active_price !== null`; keep `$0.00` strictly for variations explicitly priced at `0.000000` |
| Rapid option switching applies stale price or swatch thumbnails | Enforce `AbortController` plus monotonic `request_sequence` check in `selector.js` before updating price, gallery, labels, or swatch thumbnails |
| Quick view and main product interfere on the same page | Scope price, gallery, notice, and control selectors to `form.closest('.product, .wpe-product-instance')` and read per-form `data-wpe-selector-config` |
| High variation count causes N+1 queries on shop archives or PDP | Cache lowest purchasable price summary and representative swatch thumbnails per `(product_id, product_version, catalog_generation, settings_hash, tax_context)` and invalidate on product, inventory, media, pricing, or settings updates |

## 4. Verdict

No database table or column DDL migration is required (`wpe_option_values.swatch` and `wpe_products.extra_props` already exist in `contracts/database/schema.sql`). Contract and documentation updates (`P012.1`) precede backend resolver/pricing/settings implementation (`P012.2`–`P012.5`), admin UI updates (`P012.6`), storefront selector JS/CSS updates (`P012.7`), and integration + Playwright E2E verification (`P012.8`). Support claims are scoped strictly to verified classic WooCommerce templates and engine endpoints; WooCommerce Blocks and third-party themes/extensions are not claimed without dedicated integration verification.
