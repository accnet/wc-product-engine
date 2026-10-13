# P010 pre-implementation audit

Read-only audit performed before any code for plan P010 (two-block product management UI). Date: 2026-10-13. Scope: repository state at the commit that precedes the plan. This is a source and database inspection, not a WooCommerce integration certification.

## 1. Method

- Read AGENTS.md, README.md, docs/PROGRESS.md, docs/02-domain.md, docs/03-database-schema.md, docs/04-api-contract.md, docs/09-ui.md and the P005 plan.
- Read the product editor files: `src/Admin/ProductEditor/ProductEditorService.php`, `ProductEditorBridge.php`, `ProductEditorApiController.php`, `assets/admin/product-editor.js`, `assets/admin/product-editor.css`.
- Read every price reader: `src/Application/Variant/VariantResolver.php`, `src/Application/Variant/CartValidation.php`, `src/Application/Catalog/CatalogProjector.php`, `src/Compatibility/WooDataStore/ProductDataStore.php`, the meta bridge.
- Read `src/Compatibility/WooDataStore/ProductLifecycle.php` for type switching.
- Inspected the live tables with WP-CLI (columns of the product, variant, inventory, option, media and object state tables).
- Searched for callers of the type transition method and for handlers on the legacy product data hooks.

## 2. Findings

### F01 Price logic is implemented four times and the copies disagree

| Reader | Source columns | Sale schedule | Missing price rule |
| --- | --- | --- | --- |
| Variant resolver DTO | variant columns | uses the sale from and sale to columns, sale active only inside the window | both prices null means missing price |
| Catalog projector | variant columns plus extra props | reads the schedule from extra props date keys, requires sale below regular | skips the row |
| Cart validation | variant columns | ignored: canonical price is sale, else regular | null or empty means rejected |
| Meta bridge for the price meta | variant or product columns | ignored: sale, else regular | falls through |

Consequence: a scheduled sale that is not yet active is charged at the sale price at the cart, while the catalog shows the regular price. The plan cannot promise "effective price correct per variation" until one resolver exists. This is a prerequisite, not an option.

### F02 The parent price columns are owned by the catalog aggregate

`ProductDataStore::sync_price` rewrites the parent regular and sale price with the minimum over the variations. Docs/03 states a variable parent does not use its price field as an authoritative selling price. Storing the new common price in those columns would be overwritten on the next sync. The common price must live elsewhere.

### F03 There is no pricing mode and no common price

The variant and product tables have no column for either. The product and variant extra props JSON column exists and is documented as an extension field that must not hold filter or sort fields. The editor filters a single product's variations in the client, so a mode key inside the variant JSON is not a catalog filter field; it still needs an explicit contract entry (decision D01).

### F04 Type switching exists but has no caller and no data hand-off

`ProductLifecycle::transitionType` keeps the product ID, emits the type change outbox event and dispatches the WooCommerce type changed action. Going to simple it soft deletes every variation and can set the price from options. It never moves SKU, stock, weight, dimensions or media from a chosen variation, never previews, and it is not called anywhere in `src`. Everything the brief requires around it (receiving variation, handling of the rest, no stock summing, cancel keeps config) is new orchestration.

### F05 Two writers exist on the legacy product screen

`WC_Meta_Box_Product_Data::save` runs on the product meta save hook at priority 10 and writes type, prices, sale dates, SKU, stock, backorders, weight, dimensions and attributes from the legacy General, Inventory, Shipping and Attributes inputs. The P005 bridge saves at priority 20. If the new blocks own those fields and the legacy inputs still post, the later writer silently wins. The plan needs a server-side guard that removes the owned legacy keys on supported products, plus client-side hiding. Third-party tabs exist on this site (a bundle tab); hiding only the tabs this plugin owns keeps them working.

### F06 Stock ownership

Inventory is one record per stock owner. A variation with no own stock inherits the parent owner (editor convention: stock owner equals the product ID). Bulk edit currently walks selected rows; two rows that share a parent owner would write the same record twice. Docs/02 requires a stock owner to be mutated once per operation key. The bulk apply path must group by owner.

### F07 Variation identity

Option and value edits go through `syncOptionGroup` and `syncVariations`. P005 tests show label and order edits keep variation IDs, but there is no test that proves it across a save with a combination-key change, and the generator can still insert rows implicitly. Needs an explicit identity regression test.

### F08 Media

The resolver already falls back from the variation's primary asset to the parent featured asset. The editor attaches media by URL; unlinking removes a link row only. Group and selection assignment are UI work over the existing per-variation attach call.

### F09 Save and failure behavior

P005 saves through a hidden payload filled only while dirty and variable. Failures are shown and the draft is restored once from a transient. The new design saves two blocks plus a type change in one request, so the payload, the conflict check (product version plus variation versions) and the result shape need to be extended. Each save currently triggers a full catalog generation build (known performance follow-up).

### F10 Environment

System mode is legacy restored, authority epoch is 2, the feature flag table must stay equal to the saved snapshot, the product editor is the classic screen. Tests must run with assertions on (WP-CLI default is off on this host).

## 3. Risks and mitigations

| Risk | Mitigation (task) |
| --- | --- |
| Divergent price logic charges a wrong price | one resolver adopted by all readers, with a table driven test (task 2) |
| Common price lost by catalog aggregate sync | store outside the aggregate columns, materialize effective values in the variant columns by one writer (tasks 1 and 3) |
| Dual writers on legacy fields | server-side key removal at priority 1 for supported products, client hiding, handler audit test (task 7) |
| Type change loses data silently | preview, explicit receiving variation, tombstone only after confirmation, no stock sum (task 5) |
| Shared stock updated twice by bulk edit | group by stock owner before apply (task 6) |
| Save partially applied | database part in one transaction, mirror projection reported separately with idempotent retry (task 7) |
| Large catalogs make the table slow | table works on one product's variations, server returns the ordered list once, client paginates; document the supported size and measure in the live run (task 10) |
| Rollback needed after release | switch option and filter fall back to the P005 meta box or the legacy tabs; P005 code stays until closeout (task 11) |

## 4. Verdict

Implementation can start only after the contract amendment (task 1) and the shared price resolver (task 2). Tasks 3 to 11 depend on them in order. No blocker needs the user except the four decisions listed in the plan section 3 that ship with a recorded default.
