# P003 — Product Variable and Attribute UI Enhancement

## 1. Goal

Provide a modern, high-performance, and unified product attribute and variation management experience directly inside the WooCommerce Add/Edit Product screen (`#woocommerce-product-data`) for `variable` products without modifying WooCommerce or WordPress Core. Replace the legacy fragmented two-tab split (`Attributes` and `Variations`) with a single intuitive tab "Quản lý biến thể" (`wpe_variants_manager`), while leaving non-variable products (`simple`, `grouped`, `external`) completely unaffected.

The new tab delivers three unified sections:
- **Section A (Thuộc tính mô tả):** Descriptive attributes (`used_for_variation = 0`) with chips display, global taxonomy support, order sorting, and storefront visibility toggle.
- **Section B (Options tạo biến thể):** Variation options (`used_for_variation = 1`) with cards layout, bulk paste values, identity preservation on rename/reorder, and affected SKU preview on delete.
- **Section C (Danh sách biến thể):** Variations matrix with live search, filters, pagination, option-based grouping, quick cell editing, and slide-over detail drawer.

Includes selective combination generation (no forced Cartesian expansion), bulk editing with before/after diff preview, media assignment by option group or selected rows, parent-managed vs individual stock enforcement, a synchronized Fullscreen Modal Editor sharing identical reactive draft state with the tab view, and atomic persistence with concurrency conflict detection.

## 2. Scope

- **In scope:**
  - Backend Services and REST API Controller in `src/Admin/ProductEditor/`:
    - `ProductEditorBridge.php` (hooks into `woocommerce_product_data_tabs`, `woocommerce_product_data_panels`, `woocommerce_process_product_meta`, `admin_enqueue_scripts`).
    - `ProductEditorService.php` (domain service for loading full product variant payload, selective generation preview, bulk edits preview, and atomic save).
    - `ProductEditorApiController.php` (REST endpoints under `/wpe/v1/admin/product/{id}/variants*`).
  - Frontend Assets in `assets/admin/`:
    - `product-editor.css` (custom layout, chip badges, option cards, variations table, fullscreen modal, drawer).
    - `product-editor.js` (unified reactive draft store `wpeVariantStore`, table grouping, selective generator, bulk editor, media modal integration, beforeunload dirty guard).
  - Test suites in `tests/integration/`:
    - `ProductEditorServiceTest.php`
    - `ProductEditorBridgeTest.php`
    - `ProductEditorE2ETest.php`
  - Integration in `src/Bootstrap/PluginBootstrap.php`.
- **Out of scope:**
  - Any modification to WordPress Core or WooCommerce Core files.
  - Modifying attribute/variation handling for non-variable products (`simple`, `grouped`, `external`).
  - Modifying public Storefront APIs or customer cart resolver (which already passed all T01–T49 tests).

## 3. Decisions

- **D01: Tab switching without Core hacking:** Tab `wpe_variants_manager` is assigned class `show_if_variable`. On variable products, default `attribute_options` and `variations_options` tabs are hidden via CSS/JS class binding (`.wpe-is-variable`). For non-variable products, standard tabs display normally.
- **D02: Selective Generation:** Unlike standard WooCommerce which forces a full Cartesian product ($O(N \times M)$) or manual one-by-one creation, our generator calculates combinations and renders checkboxes, allowing the operator to selectively create only intended combinations.
- **D03: Identity & Key Invariant:** Renaming an option label or reordering option values does NOT change `combination_key` or `wpe_variants.id` (preserves R25).
- **D04: Unified Reactive Draft Store:** The inline tab panel and fullscreen modal share a single JavaScript state (`wpeVariantStore`), guaranteeing that filters, dirty inputs, selection sets, and pagination stay 100% identical between tab view and modal view.
- **D05: Dual-path Save:** Supports standard WordPress "Cập nhật / Đăng" button (via serialized hidden input in form) AND direct REST API "Lưu thay đổi" (Save Changes) button in the modal. Both paths route through `ProductEditorService::saveProductVariants()` inside a single atomic database transaction.
- **D06: Stock ownership:** Variations inherit parent stock when `manage_stock = 0`, disabling row-level quantity overrides to eliminate double-counting (preserves INV02, R48).
- **D07: Third-party extension compatibility:** The variation detail drawer exposes standard hook containers (`woocommerce_product_after_variable_attributes`) so plugins adding custom fields to variations remain functional.

## 4. Documentation changes

- `docs/plans/P003-variable-ui/plan.md`
- `docs/plans/P003-variable-ui/report.md`
- `docs/NOTES.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P003.1",
      "title": "Backend Data Model, Repositories & REST API for Product Variant Editor",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/03-database-schema.md",
        "docs/04-storage-engine.md"
      ],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "src/Admin/ProductEditor/ProductEditorApiController.php",
        "tests/integration/ProductEditorServiceTest.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/ProductEditorServiceTest.php"
        ]
      ]
    },
    {
      "id": "P003.2",
      "title": "WooCommerce Admin Hooks & Product Data Tabs Integration",
      "depends_on": [
        "P003.1"
      ],
      "read_first": [
        "docs/05-data-store-contracts.md",
        "docs/07-api-and-events.md"
      ],
      "files": [
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "src/Bootstrap/PluginBootstrap.php",
        "tests/integration/ProductEditorBridgeTest.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/ProductEditorBridgeTest.php"
        ]
      ]
    },
    {
      "id": "P003.3",
      "title": "Frontend UI Components — Descriptive Attributes, Options Cards & Variations Matrix",
      "depends_on": [
        "P003.2"
      ],
      "read_first": [
        "docs/02-domain.md",
        "docs/09-ui-and-theme.md"
      ],
      "files": [
        "assets/admin/product-editor.css",
        "assets/admin/product-editor.js"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    },
    {
      "id": "P003.4",
      "title": "Modals, Bulk Operations, Fullscreen Mode & Inventory Rules",
      "depends_on": [
        "P003.3"
      ],
      "read_first": [
        "docs/09-ui-and-theme.md",
        "docs/04-storage-engine.md"
      ],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    },
    {
      "id": "P003.5",
      "title": "Comprehensive Integration Testing, Documentation & Evidence Verification",
      "depends_on": [
        "P003.4"
      ],
      "read_first": [
        "docs/PROGRESS.md",
        "docs/06-implementation-plan.md"
      ],
      "files": [
        "tests/integration/ProductEditorE2ETest.php",
        "docs/plans/P003-variable-ui/plan.md",
        "docs/NOTES.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "eval-file",
          "tests/integration/ProductEditorE2ETest.php"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    }
  ]
}
```
