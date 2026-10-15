# P012 — Settings Product Page price display and variant selector

## 1. Goal

Add the **Settings → Product Page** (`tab=product-page`) administration screen and storefront runtime to manage how variable product prices and variation selectors are rendered on the storefront, reusing the existing settings framework, `VariantResolver`, `EffectivePriceResolver`, `OptionsRepository`, and gallery/media services without modifying WordPress or WooCommerce Core.

Repository findings, risks, and verdict are recorded in `docs/audits/P012-pre-implementation-audit.md`.

## 2. Scope

In scope:
- **Settings → Product Page** tab (`src/Admin/SettingsPage.php`, `src/Admin/SettingsRegistry.php`, `src/Admin/Rest/AdminApiController.php`, `src/Bootstrap/PluginBootstrap.php`, `assets/admin/settings.js`, `assets/admin/settings.css`) organized into two groups:
  - **Price Display**: `single_price_display` (Single Price Display).
  - **Variant Selector**: `show_selected_value` (Show Selected Value), `show_help_text` (Show Help Text), `button_selector` (Button Selector), `color_swatches` (Color Swatches), `image_swatches` (Image Swatches), `mark_unavailable_options` (Mark Unavailable Options).
  - Global attribute display defaults (`wpe_attribute_display_config`) editable in `Settings → Product Page` and per-product option display configuration (`display_type`, `help_text`, per-value color hex in `swatch`) editable in the product `Variants` option cards.
- **Single Price Display** (`src/Compatibility/StoreApi/SelectorAdapter.php`, `src/Application/Variant/VariantResolver.php`):
  - Replaces the variable price range in the main price area (and on shop, category, search, and product loop cards) with the lowest effective price among valid, purchasable variations (`EffectivePriceResolver` + stock/backorder availability).
  - Sale and regular prices always originate from the same winning variation (`position ASC, id ASC` tie-breaker), preserving WooCommerce currency format, tax display mode, price suffix, and UTC sale schedules.
  - Updates the main price area to the resolved variation's full `price_html` when all options are selected (or on a valid default selection), reverts to the lowest price on partial selection or reset, shows unavailable status and locks Add to cart on invalid/unpurchasable combinations, and never renders a fake zero price when no valid priced variation exists.
  - Hides the secondary `.woocommerce-variation-price` below the options while keeping variation description, stock status, and purchase controls.
- **Variant Selector Enhancements** (`src/Application/Variant/VariantResolver.php`, `src/Compatibility/StoreApi/SelectorAdapter.php`, `assets/variant-selector/selector.js`, `assets/variant-selector/selector.css`):
  - `show_selected_value`: updates attribute label to `Attribute: Value Label` when selected and `Attribute` when unselected or reset (always using the display label, never slug/ID).
  - `show_help_text`: renders escaped per-attribute help text below the label linked via `aria-describedby`, with zero empty spacing when absent.
  - `button_selector`: renders attributes configured as `button` as accessible radio buttons with selected, unselected, unavailable, and focus states, responsive wrapping, and reset support; falls back to `<select>` dropdown when off.
  - `color_swatches`: renders attributes configured as `color` as color swatches with per-value hex code, visible border for light/white colors, label/tooltip + accessible name, non-color indicators for selected/unavailable states, and automatic fallback to a named button when a value lacks a hex color; falls back to dropdown when off.
  - `image_swatches`: defaults to the first variation-forming option by saved position if unconfigured, renders thumbnail + value label for each value, dynamically resolves candidate thumbnails (1. candidate value + 2. other selected values -> 3. matching active variations by saved position -> 4. first valid primary variation image -> 5. product featured image then placeholder; if no combination matches, marks unavailable while keeping representative/fallback thumbnail), updates main gallery on full selection and restores on reset; falls back to dropdown when off.
  - `mark_unavailable_options`: dims/crosses out unavailable option values while keeping label/thumbnail visible when on, or uses standard disabled state without extra decoration when off; always blocks purchasing invalid/unpurchasable combinations based on resolver + stock/backorder policy.
- **Instance Scoping, Accessibility & Caching**:
  - Per-product DOM instance scoping (`main product` + `quick view` safe), `AbortController` + `request_sequence` stale-response rejection, keyboard roving tabindex & `aria-live` announcements, and cached price/selector summaries invalidated on settings, option, media, pricing, and inventory changes.

Out of scope:
- Creating any settings under **Product Information** (explicitly excluded by specification until requested).
- Any modifications to WordPress or WooCommerce Core files.
- Mutating database, cart, checkout, or order prices when `Single Price Display` is active.
- Unverified claims of support for WooCommerce Blocks custom interactivity stores or third-party themes/extensions outside classic WooCommerce templates and the tested storefront surfaces.

## 3. Decisions

- **D01: Settings keys, groups, and defaults.** All 7 toggles (`single_price_display`, `show_selected_value`, `show_help_text`, `button_selector`, `color_swatches`, `image_swatches`, `mark_unavailable_options`) are registered in `PluginBootstrap` and `SettingsRegistry` with default `false` and `group = 'product_page'`. Existing flags retain `group = 'features'`. Both tabs save through `POST /wp-json/wpe/v1/admin/settings` with `manage_woocommerce` capability and nonce verification.
- **D02: Per-attribute display type, help text, and color hex storage (no DDL migration).**
  - Each product option (`wpe_options.id`) stores its optional `display_type` (`auto|dropdown|button|color|image`) and `help_text` (sanitized string) in `wpe_products.extra_props.option_ui.{option_id}`.
  - Global attribute taxonomies (`pa_*`) store default `display_type`, `help_text`, and per-term color hex codes in WordPress option `wpe_attribute_display_config` keyed by stable attribute taxonomy ID/name, editable in `Settings → Product Page`. Product-level option settings inherit from the global taxonomy config when set to `auto` or when `help_text` is empty, and also support product-specific custom attributes.
  - Per-value color hex codes (`#rrggbb`) are stored in the existing `wpe_option_values.swatch` JSON column (`{"color":"#rrggbb"}`).
  - No schema table or column DDL migration is required.
- **D03: Effective display type precedence and single-control rule.**
  - For each variation option ordered by `position ASC, id ASC`:
    1. Determine configured type: explicit option `display_type` (`dropdown|button|color|image`), else global taxonomy default if `taxonomy` is present, else `image` if the option is at index `0` (first variation-forming option by saved position), else `dropdown`.
    2. Apply global toggle gate: `button` requires `button_selector = true`; `color` requires `color_swatches = true`; `image` requires `image_swatches = true`. If the required toggle is `false`, the effective display type falls back to `dropdown`.
    3. When effective display type is `button`, `color`, or `image`, the native `<select>` is hidden (`class="wpe-select wpe-select--hidden"`, `aria-hidden="true"`, `tabindex="-1"`) and synchronized as a backing form control so WooCommerce form submission works while only one interactive control is ever visible or focusable per attribute.
- **D04: Lowest effective purchasable variation price rule.**
  - Among active (`status = 'publish'`, `deleted_at IS NULL`) variations of a product that are both priced (`EffectivePriceResolver::resolve` returns `active_price !== null`) and available in stock/backorders (`wpe_inventory` on `stock_owner_id`), select the variation with the minimum `active_price` (ties broken by `position ASC, id ASC`).
  - Format `lowest_price_html` and each variation's `price_html` using that single variation's `regular` and `sale` amounts passed through `wc_get_price_to_display`, `wc_format_sale_price` / `wc_price`, and `$variation->get_price_suffix()`. If no purchasable variation has a price, return `''` (never fake `$0.00`).
- **D05: Availability and `allowed_values` resolution.**
  - `VariantResolver::computeAllowedValues` evaluates every candidate variation using `EffectivePriceResolver::resolve` and the resolved `stock_owner_id` inventory row (`manage_stock`, `quantity`, `backorders`, `stock_status`).
  - A value is included in `allowed_values[optionId]` only when at least one matching variation for the candidate selection `(currentSelection \ {optionId}) U {optionId: valueId}` is both priced and in-stock/backorderable.
- **D06: 5-step dynamic candidate thumbnail resolution for Image Swatches.**
  - For each value $v$ of an option $O_i$ whose effective display type is `image`:
    1. Build candidate selection $C = (S \setminus \{O_i\}) \cup \{O_i \mapsto v\}$.
    2. Keep currently selected values of all other options $O_j \neq O_i$.
    3. Query matching active variations in saved order (`v.position ASC, v.id ASC`), honoring wildcard `is_any = 1`.
    4. Select the first valid primary variation image (`wpe_variant_media` + `wpe_assets` with `health != 'broken'`, ordered by `is_primary DESC, position ASC`).
    5. If no matching variation has its own image, fall back to the parent featured image (`wpe_product_media` role `featured`), then the inline SVG placeholder.
  - If no active variation matches $C$ (combination does not exist), mark $v$ unavailable and retain $v$'s representative thumbnail (computed for $\{O_i \mapsto v\}$ alone, or product featured image / placeholder).
- **D07: Instance scoping and cache invalidation.**
  - `WpeVariantSelector` scopes all DOM queries (price container, gallery, notices, labels, swatches, reset button) to `this.productRoot = this.form.closest('.product, .wpe-product-instance') || this.form.parentElement` and reads per-instance JSON config from `data-wpe-selector-config`.
  - Price and selector summaries are cached under versioned keys `(product_id, product_version, catalog_generation, settings_version, tax_display_context)` and invalidated when settings, options, variations, media, pricing, or inventory change.

## 4. Documentation changes

- `docs/02-domain.md`: document Single Price Display lowest-purchasable-variation rule, availability checks in the variation resolver, and 5-step candidate image swatch resolution.
- `docs/03-database-schema.md`: document `products.extra_props.option_ui`, `option_values.swatch` color JSON, and `wpe_attribute_display_config` option without adding schema tables or columns.
- `docs/04-api-contract.md`: document the `product_page` settings keys in `wpe/v1/admin/settings`, attribute display config payload, and `value_thumbnails` / `price_html` / `lowest_price_html` in `POST /products/{id}/resolve`.
- `docs/09-ui.md`: document the `Settings → Product Page` tab (`Price Display` and `Variant Selector` groups) and storefront selector states (`Show Selected Value`, `Show Help Text`, `Button Selector`, `Color Swatches`, `Image Swatches`, `Mark Unavailable Options`).
- `docs/NOTES.md`
- `docs/audits/P012-pre-implementation-audit.md`
- `docs/plans/P012-product-page-settings/plan.md`
- `docs/plans/P012-product-page-settings/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P012.1",
      "title": "Contract and documentation amendments for Settings Product Page, Single Price Display, and Variant Selector",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/03-database-schema.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "docs/audits/P012-pre-implementation-audit.md"
      ],
      "files": [
        "docs/02-domain.md",
        "docs/03-database-schema.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "docs/NOTES.md",
        "docs/audits/P012-pre-implementation-audit.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P012.2",
      "title": "SettingsRegistry, PluginBootstrap flags, AdminApiController persistence, and Settings Product Page tab UI",
      "depends_on": ["P012.1"],
      "read_first": [
        "docs/04-api-contract.md",
        "docs/09-ui.md"
      ],
      "files": [
        "src/Bootstrap/PluginBootstrap.php",
        "src/Admin/SettingsRegistry.php",
        "src/Admin/SettingsPage.php",
        "src/Admin/Rest/AdminApiController.php",
        "assets/admin/settings.js",
        "assets/admin/settings.css",
        "tests/integration/SettingsApiTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P012.3",
      "title": "Per-option display_type, help_text, and value color swatch persistence in ProductEditorService and editor UI",
      "depends_on": ["P012.2"],
      "read_first": [
        "docs/03-database-schema.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md"
      ],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css",
        "tests/integration/ProductEditorServiceTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorServiceTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P012.4",
      "title": "VariantResolver availability engine, 5-step candidate image swatch resolver, and full variation price_html",
      "depends_on": ["P012.3"],
      "read_first": [
        "docs/02-domain.md",
        "docs/04-api-contract.md"
      ],
      "files": [
        "src/Application/Variant/VariantResolver.php",
        "src/Application/Variant/ResolverApi.php",
        "tests/integration/T25Test.php",
        "tests/integration/T26Test.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T25Test.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T26Test.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P012.5",
      "title": "SelectorAdapter: Single Price Display hooks, stable ID display type resolution, help text, and cached storefront payload",
      "depends_on": ["P012.4"],
      "read_first": [
        "docs/02-domain.md",
        "docs/09-ui.md",
        "docs/10-integrations.md"
      ],
      "files": [
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "src/Compatibility/WooDataStore/ProductLifecycle.php",
        "tests/integration/T27Test.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/T27Test.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P012.6",
      "title": "Storefront selector JS and CSS: instance scoping, selected value label, dynamic image thumbnails, single price sync, and unavailable states",
      "depends_on": ["P012.5"],
      "read_first": [
        "docs/09-ui.md"
      ],
      "files": [
        "assets/variant-selector/selector.js",
        "assets/variant-selector/selector.css",
        "tests/integration/ProductPageSettingsTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P012.7",
      "title": "Playwright live E2E verification across Settings Product Page, storefront PDP, archive cards, quick-view isolation, and cart/checkout parity",
      "depends_on": ["P012.6"],
      "read_first": [
        "docs/07-testing.md",
        "docs/09-ui.md"
      ],
      "files": [
        "tests/e2e/ProductPageSettingsLive.mjs",
        "docs/NOTES.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductPageSettingsTest.php"],
        ["node", "tests/e2e/ProductPageSettingsLive.mjs"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
