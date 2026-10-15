# Design System Specification — WC Product Engine Admin UI

## 1. Design Direction

**Target Aesthetic:** *Modern Commerce SaaS + Refined WordPress Native* (benchmarked against Shopify Admin, Linear, and Stripe Dashboard).

Core principles:
1. **Zero Global Pollution:** All styles are scoped under `.wpe-*` classes or `#wpe_*` containers so WordPress Core and third-party plugins remain untouched.
2. **High-Density Clarity:** Balanced 4px-based spacing (`8px`, `12px`, `16px`, `20px`, `24px`) and crisp `1px solid` neutral borders (`#e2e8f0`) without nested boxes.
3. **Predictable Feedback:** Every interactive control exposes clear `hover`, `focus-visible`, `active`, `disabled`, and `dirty` states.

## 2. Design Tokens (`assets/admin/product-editor.css`)

### 2.1 Color Palette

| Token | Value | Semantic Usage |
| --- | --- | --- |
| `--wpe-bg-surface` | `#ffffff` | Primary card, table, modal, and input background |
| `--wpe-bg-subtle` | `#f8fafc` | Table header, secondary panel, and compact upload bar background |
| `--wpe-bg-muted` | `#f1f5f9` | Hover surface for neutral buttons, chips, and subtle badges |
| `--wpe-bg-selected` | `#eff6ff` | Selected variant row and active option highlight |
| `--wpe-border-default` | `#e2e8f0` | Card, section, and table cell dividers |
| `--wpe-border-strong` | `#cbd5e1` | Input borders and interactive button outlines |
| `--wpe-text-primary` | `#0f172a` | Primary headings, body values, and table data |
| `--wpe-text-secondary` | `#475569` | Form labels, table headers, and secondary metadata |
| `--wpe-text-muted` | `#64748b` | Placeholders, helper text, and empty state descriptions |
| `--wpe-accent-primary` | `#2271b1` | Primary WordPress/Commerce action accent and focus rings |
| `--wpe-accent-hover` | `#135e96` | Hover state for primary buttons and links |
| `--wpe-status-success-bg` | `#dcfce7` | In-stock / active badge surface |
| `--wpe-status-success-text` | `#166534` | In-stock / active badge text |
| `--wpe-status-warning-bg` | `#fef3c7` | Unsaved draft / impact warning surface |
| `--wpe-status-warning-text` | `#92400e` | Unsaved draft / impact warning text |
| `--wpe-status-danger-bg` | `#fee2e2` | Error / destructive action surface |
| `--wpe-status-danger-text` | `#991b1b` | Error / destructive action text |

### 2.2 Typography Scale

| Token | Value | Usage |
| --- | --- | --- |
| `--wpe-font-sans` | `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif` | All admin UI surfaces |
| `--wpe-font-mono` | `ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace` | SKUs, IDs, and technical codes |
| `--wpe-text-xs` | `11px` | Uppercase table column headers, compact badges (`Featured`) |
| `--wpe-text-sm` | `12px` | Secondary buttons, chips, helper hints |
| `--wpe-text-base` | `13px` | Default admin body text, table cells, inputs |
| `--wpe-text-md` | `14px` | Card titles and section headers |
| `--wpe-text-lg` | `16px` | Drawer and dialog headers |

### 2.3 Spacing, Radius, Elevation & Z-Index

| Category | Token | Value | Usage |
| --- | --- | --- | --- |
| Spacing | `--wpe-space-1` .. `--wpe-space-6` | `4px`, `8px`, `12px`, `16px`, `20px`, `24px` | Standard layout padding and gaps |
| Radius | `--wpe-radius-sm` | `6px` | Badges, chips, thumbnails, compact inputs |
| Radius | `--wpe-radius-md` | `8px` | Buttons, form controls, upload zone |
| Radius | `--wpe-radius-lg` | `10px` | Option cards, inner panels |
| Radius | `--wpe-radius-xl` | `12px` | Modals and slide-over drawers |
| Shadow | `--wpe-shadow-sm` | `0 1px 2px 0 rgba(15, 23, 42, 0.05)` | Subtle card elevation |
| Shadow | `--wpe-shadow-lg` | `0 10px 25px -5px rgba(15, 23, 42, 0.12)` | Modals and slide-over drawers |
| Z-Index | `--wpe-z-drawer` | `100050` | Variant detail slide-over drawer (above `#wpadminbar`) |
| Z-Index | `--wpe-z-modal` | `100100` | Confirmation modals, image picker, media popup |
| Z-Index | `--wpe-z-fullscreen` | `100080` | Fullscreen workspace overlay |
