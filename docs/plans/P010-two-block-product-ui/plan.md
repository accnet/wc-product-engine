# P010 — Two-block product management UI

## 1. Goal

Replace the P005 Variants Manager meta box and the legacy General, Inventory, Shipping, Attributes and Variations controls on Simple and Variable products with two blocks: "Product information" and "Variants". The blocks are one editor over one draft and one save. The product type (Simple or Variable) follows the options: any variation-forming option makes the product Variable, none makes it Simple. Backend stays compatible with the WooCommerce Simple and Variable model and keeps the product ID across a type change.

The work includes the backend rules the UI depends on: one shared effective-price resolver, a common price with a per-variation pricing mode, a read-only reference price, previewed type transitions, stock-owner-safe bulk edit and a single-writer save with a recovery path.

Repository findings, risks and verdict are in `docs/audits/P010-pre-implementation-audit.md`.

## 2. Scope

In scope:
- Block "Product information": regular price, sale price, sale schedule, product-level SKU, stock management and backorders, weight, dimensions, descriptive attributes (Material, Brand, Fit), other common settings the backend supports.
- Block "Variants": options and values (cards and chips, paste values, reorder, default value), selective combination generator, variation table (image, combination, SKU, pricing mode, regular and sale price, stock, status, search, filters, grouping, pagination), quick edit, detail drawer, bulk edit with before and after preview, page versus all-filtered selection, expand to a full screen modal sharing the same draft.
- Common price and pricing mode (shared or own) for variations; reference price when no common price exists.
- Automatic Simple to Variable and Variable to Simple transition with preview; other product types are untouched.
- Inventory modes (own, shared, untracked), stock policy change preview, media assignment and unlink.
- Replacement of the legacy controls on supported products and a server-side single writer guard.
- Save lifecycle: unsaved state, leave warning, field and cell validation, error summary, permission and conflict checks, draft kept on failure, retry.
- Docs and contract amendments listed in section 4.

Out of scope:
- Any change to WordPress or WooCommerce core, or global SQL rewriting.
- Product types other than Simple and Variable (grouped, external, bundles, subscriptions) and third-party tabs.
- Changing how WooCommerce aggregates catalog price ranges for a variable parent (`sync_price` keeps the minimum over variations).
- Cutover, deploy and authority epoch changes; the feature flag table stays as it is.
- Removing the P005 meta box code before task 11 (it is the fallback).
- New schema columns unless decision D01 is overturned.

## 3. Decisions

Defaults below are applied unless the user overturns them; D01, D07, D08 and D09 are the ones the user may want to change.

- **D01: Storage of common price and pricing mode.** The common price is stored in the product extra props JSON under a documented `common_price` object (regular, sale, from, to as 6 decimal strings or null). The pricing mode is stored in each variation's extra props under `pricing_mode` with values shared or own (absent means own for legacy rows, so old data never changes meaning). No schema migration. Docs/03 gets an explicit amendment for these keys; they are not filter or sort fields in the catalog. The parent price columns stay owned by the catalog aggregate.
- **D02: One effective-price resolver.** A new `EffectivePriceResolver` takes a variation row, the common price and the current time and returns regular, sale, active price, whether a sale is active, source (shared, own, missing) and a state. The variant resolver, cart validation, catalog projector and meta bridge all call it. Sale is active only inside its window and only when it is not above regular; null means unpriced and 0.000000 means free and they are never merged. Cart validation therefore stops charging a scheduled sale early.
- **D03: Materialization by one writer.** When the common price or a variation's mode changes, the editor service writes the effective values into the shared-mode variation columns inside the save transaction. Existing readers and WooCommerce adapters keep reading variation columns, so nothing downstream needs to know about the common price. Own-mode variations are never touched by a common price change. A new variation defaults to shared only when a common price is configured.
- **D04: Reference price.** Computed read-only on the server from the first non-deleted variation ordered by saved position then ID, over the full set (never the filtered, sorted or paged view). Response shape: state (ok, unpriced, none), source variation ID and label such as "Black / M", regular, sale, schedule. When that variation has no price the state is unpriced and the next variation is not tried. It is shown only in Product information, only when no common price exists, is never persisted and never changes a variation or the transaction price. A variation without a price stays flagged "Missing price" in the table.
- **D05: Removing a used common price.** The server returns a preview listing shared-mode variations that would lose their price. Apply requires each of them to be set to own mode with an explicit price (or an explicit choice to leave it unpriced and flagged). Silent price loss is rejected by validation.
- **D06: Type is derived, not chosen.** The type is computed from the draft: no variation-forming option gives Simple, one or more gives Variable. Descriptive attributes never count. Options without a valid variation give an incomplete configuration state that is not purchasable. The backend changes type only on a successful save and never on load; legacy inconsistent rows are shown as they are with a warning.
- **D07: Variable to Simple handling of other variations.** The user picks the receiving variation when there are several. Its price, SKU, weight, dimensions, image and own stock become the product data. The other variations are listed in the preview and, only after explicit confirmation, are soft deleted through the existing tombstone behavior (restorable, order links preserved). Stock is never summed. Cancel keeps the previous configuration. Default alternative if the user prefers: block the transition until the extra variations are deleted manually first.
- **D08: Simple to Variable handling.** The Simple price is proposed as common price. Product SKU stays at product level. Stock stays at product level as shared stock that new variations inherit; nothing is duplicated into combinations. The type flips in the same save transaction as the first created variation set; if no variation is created the product stays Simple.
- **D09: Single writer.** On a supported product the new blocks are the only writer. Client side: the legacy General, Inventory, Shipping, Attributes and Variations panels and the product type selector are hidden. Server side: at priority 1 of the product meta save the owned legacy keys are removed from the request when the blocks payload is present, so WooCommerce's own save at priority 10 cannot write them. A test fails if any owned key reaches a data store write from the legacy path. Third-party tabs are left alone.
- **D10: Save is one request, one database transaction.** Product information, options, variations and type change are validated together; product version and variation versions are the conflict tokens. The database part is atomic (all or nothing). The legacy mirror projection and catalog build run after commit and are reported separately with an operation key so a retry is idempotent. A failure of the required part keeps the draft and never reports overall success.
- **D11: Shared draft.** One in-memory draft object with a version counter feeds the page blocks and the full screen modal. Filters, selection and sort are part of that state. Closing the modal neither saves nor discards.
- **D12: Bulk edit scope.** Every bulk request states its scope: ids on the current page or the full filter query resolved on the server at preview time to an id list with a count. Apply uses the previewed id list and the preview token, so the scope cannot grow between preview and apply. Rows that share one stock owner are grouped and the owner is written once.
- **D13: Recovery.** A WordPress option and a filter select the editor mode: blocks (default after release), P005 meta box, or legacy tabs. Switching is a settings change, takes effect on the next product screen load, requires no data migration, and never leaves data in a shape the other modes cannot read (pricing mode and common price live in extra props that older code ignores, and effective values are already materialized in the columns).
- **D14: Supersession.** This plan supersedes the placement and UI decisions of P005 and P003 for supported products. P005 code stays in the repository as the fallback until task 11.

## 4. Documentation changes

- `docs/03-database-schema.md`: documented extra props keys `common_price` and `pricing_mode`, effective price rules, reference price is not stored.
- `docs/04-api-contract.md`: editor payload additions (product block, common price, pricing mode, reference price, type derivation, transition preview, bulk scope token, save result with required and projection parts, conflict tokens).
- `docs/09-ui.md`: two-block layout, legacy control replacement, draft and modal behavior, states and validation rules.
- `docs/02-domain.md`: one short clarification that a variable parent may carry a common price that is not the authoritative selling price.
- `docs/NOTES.md`
- `docs/QUESTIONS.md`
- `docs/audits/P010-pre-implementation-audit.md`
- `docs/plans/P010-two-block-product-ui/plan.md`
- `docs/plans/P010-two-block-product-ui/report.md`

## 5. Tasks

Acceptance criteria map: A1 two blocks replace old UI (tasks 7, 8, 9, 10); A2 simple has no fake variation (5, 6); A3 options trigger Variable and descriptive attributes do not (5); A4 common price applies only to shared rows and own prices stay (3); A5 reference price correct and independent of filters, sort and paging (3, 10); A6 reference price never edits prices or transaction price (2, 3); A7 null versus 0 (2, 3); A8 missing price not masked (2, 3); A9 type change keeps product ID and order links (5); A10 option edits keep variation IDs (6); A11 bulk scope and shared stock written once (6); A12 modal and page share the draft (10); A13 save and reload correct, failures keep input (7, 8, 10); A14 legacy single writer (7); A15 recovery works (11).

```json
{
  "tasks": [
    {
      "id": "P010.1",
      "title": "Contract and docs amendment for common price, pricing mode, type derivation and save result",
      "depends_on": [],
      "read_first": ["docs/02-domain.md", "docs/03-database-schema.md", "docs/04-api-contract.md", "docs/09-ui.md", "docs/audits/P010-pre-implementation-audit.md"],
      "files": ["docs/02-domain.md", "docs/03-database-schema.md", "docs/04-api-contract.md", "docs/09-ui.md", "docs/NOTES.md"],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.2",
      "title": "Shared effective-price resolver adopted by resolver, cart, projector and meta bridge",
      "depends_on": ["P010.1"],
      "read_first": ["docs/02-domain.md", "docs/03-database-schema.md"],
      "files": [
        "src/Application/Variant/EffectivePriceResolver.php",
        "src/Application/Variant/VariantResolver.php",
        "src/Application/Variant/CartValidation.php",
        "src/Application/Catalog/CatalogProjector.php",
        "tests/integration/EffectivePriceResolverTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/EffectivePriceResolverTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorContractTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.3",
      "title": "Common price, pricing mode, materialization, reference price and common-price removal preview",
      "depends_on": ["P010.2"],
      "read_first": ["docs/03-database-schema.md", "docs/04-api-contract.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "src/Admin/ProductEditor/PricingService.php",
        "tests/integration/PricingModeTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/PricingModeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorServiceTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.4",
      "title": "Product information block data: parent SKU, sale schedule, stock config, weight, dimensions, descriptive attributes, validation",
      "depends_on": ["P010.3"],
      "read_first": ["docs/02-domain.md", "docs/04-api-contract.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "src/Admin/ProductEditor/ProductInfoService.php",
        "tests/integration/ProductInfoBlockTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductInfoBlockTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorServiceTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.5",
      "title": "Type derivation and previewed Simple/Variable transitions around ProductLifecycle",
      "depends_on": ["P010.4"],
      "read_first": ["docs/02-domain.md", "docs/04-api-contract.md"],
      "files": [
        "src/Admin/ProductEditor/ProductTypeTransition.php",
        "src/Compatibility/WooDataStore/ProductLifecycle.php",
        "tests/integration/ProductTypeTransitionTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductTypeTransitionTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.6",
      "title": "Variants rules: selective generator, stable variation IDs, inventory modes, bulk scope and owner grouping, media",
      "depends_on": ["P010.5"],
      "read_first": ["docs/02-domain.md", "docs/04-api-contract.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "tests/integration/VariantsRulesTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/VariantsRulesTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorServiceTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.7",
      "title": "Unified save endpoint, atomic transaction, conflict tokens, partial projection result and legacy single-writer guard",
      "depends_on": ["P010.6"],
      "read_first": ["docs/04-api-contract.md", "docs/09-ui.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorApiController.php",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "src/Admin/ProductEditor/ProductEditorService.php",
        "tests/integration/ProductEditorSaveTest.php",
        "tests/integration/LegacyWriterGuardTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorSaveTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/LegacyWriterGuardTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MirrorWorkerEpochTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.8",
      "title": "Bridge: two meta boxes, legacy panel replacement, editor mode switch, assets enqueue",
      "depends_on": ["P010.7"],
      "read_first": ["docs/09-ui.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "tests/integration/ProductEditorBridgeTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.9",
      "title": "Product information block UI with reference price, transition dialogs and common-price removal dialog",
      "depends_on": ["P010.8"],
      "read_first": ["docs/09-ui.md"],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css",
        "tests/e2e/ProductInfoBlockLive.mjs"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "tests/e2e/ProductInfoBlockLive.mjs"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.10",
      "title": "Variants block UI: option cards, selective generator, table, bulk preview, drawer, shared-draft full screen modal",
      "depends_on": ["P010.9"],
      "read_first": ["docs/09-ui.md"],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css",
        "tests/e2e/VariantsBlockLive.mjs",
        "tests/e2e/TwoBlockLive.mjs"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "tests/e2e/TwoBlockLive.mjs"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P010.11",
      "title": "Regression, recovery switch verification, docs and notes closeout",
      "depends_on": ["P010.10"],
      "read_first": ["docs/PROGRESS.md", "docs/06-implementation-plan.md"],
      "files": [
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P010-two-block-product-ui/plan.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorE2ETest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorContractTest.php"],
        ["node", "tests/e2e/TwoBlockLive.mjs"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```

Test and recovery notes:
- All PHP tests run with assertions on. Live browser tests create disposable products and remove them; the feature flag table must equal the saved snapshot after the run.
- The live suite covers: Simple stays without variations, first option triggers the Simple to Variable preview, removing the last option triggers the Variable to Simple preview with a receiving variation, cancel keeps config, shared versus own pricing, reference price source label with filters, sort and page changes, null versus 0, modal and page sharing one draft, a failed save keeping every input, and the leave warning.
- Rollback per task is one local commit revert; runtime rollback is the editor mode switch in D13. A failed save leaves the previous database state untouched because of the transaction in D10.
