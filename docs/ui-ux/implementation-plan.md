# UI/UX Refactor Implementation Plan

## 1. Phase Breakdown

| Phase | Scope | Files Modified | Status |
| --- | --- | --- | --- |
| Phase 1 | Design System Tokens & Flattened Media Styling | `assets/admin/product-editor.css` | Completed |
| Phase 2 | Product Media Flattening, English Standardization & Dirty Guard | `assets/admin/product-editor.js` | Completed |
| Phase 3 | Option Color Swatch Dots, Search Focus Persistence & Drawer Navigation | `assets/admin/product-editor.js`, `assets/admin/product-editor.css` | Completed |
| Phase 4 | Metabox & Two-Block Mode Compatibility & Lazy Media Fetch | `assets/admin/product-editor.js` | Completed |
| Phase 5 | Automated Integration, E2E Browser Verification & Documentation | `docs/ui-ux/*.md`, `tests/e2e/*.mjs` | Completed |

## 2. Backward Compatibility & Safety Guarantees

1. **No WordPress or WooCommerce Core Edits:** All DOM hiding (`.wpe-box-hidden`) and metabox enhancements are scoped to the `product` post type edit screen via `ProductEditorBridge`.
2. **Contract Preservation:** REST API endpoints (`/wp-json/wpe/v1/products/{id}/variants`, `/wp-json/wpe/v1/products/{id}/media`) and hidden form payloads (`#wpe_variants_payload`, `#wpe_media_payload`) preserve their exact JSON schemas and optimistic locking (`expected_version`).
3. **Dual Editor Mode Support:** Both `metabox mode` (default) and `blocks mode` pass their respective E2E test suites without cross-mode interference.
