# P005 — Variants Manager as a standalone meta box

## 1. Goal

Move the Variants Manager out of the WooCommerce *Product data* tab strip into its own meta box on the product edit screen, so the matrix gets the full content width and Product data stays simple. While doing so, close the gaps found during the survey and make the editor tests actually assert:

- Update/Publish must never look successful when the variants part failed, and the user's draft must survive a failed save.
- The fullscreen modal, drawer and dialogs need keyboard handling and focus management.
- The editor must not fetch data or post a payload for products that are not variable or have no edits.

## 2. Scope

- **In scope**
  - `src/Admin/ProductEditor/ProductEditorBridge.php`: register a meta box on `add_meta_boxes`, remove the Product data tab and panel, print overlays in `admin_footer`, surface save failures and keep a failed draft, keep the existing nonce, capability check and `woocommerce_process_product_meta` hook.
  - `src/Admin/ProductEditor/ProductEditorService.php`: fix defects that make the editor wrong against real data (see D09) and report projection outcomes in the save result.
  - `assets/admin/product-editor.js` and `assets/admin/product-editor.css`: box visibility toggle, header badge and fullscreen button, lazy load, payload only when dirty, layer manager (focus trap, Escape, focus return), draft restore, submit guard.
  - `tests/integration/ProductEditorBridgeTest.php`, `ProductEditorE2ETest.php`, `ProductEditorServiceTest.php` (assertion guard and updated expectations), `ProductEditorContractTest.php` (new), `tests/e2e/VariantsBlockLive.mjs` (new, live browser run).
- **Out of scope**
  - Any WordPress or WooCommerce Core change.
  - Epoch, authority and `LegacyMirror` semantics. A save on this site is currently rejected by the mirror's epoch fence; this plan only reports it (D09) and records a question.
  - Per-save `CatalogProjector::buildNewGeneration()` cost.
  - The WooCommerce block-based product editor (not enabled on this site).
  - Third-party extensions that add controls to the legacy Attributes/Variations tabs (still unsupported, as before).
  - Deleting or weakening any existing assertion.

## 3. Decisions

- **D01: Placement.** Meta box id `wpe_variants_manager`, screen `product`, context `normal`, priority `high`, registered on `add_meta_boxes` with a priority after WooCommerce's. `add_meta_boxes_product` fires first and would place it above Product data.
- **D02: Tab removed.** The Variants Manager tab and its Product data panel are removed (user decision). The legacy Attributes and Variations tabs stay hidden for variable products by the existing CSS class on `#woocommerce-product-data`; other product types are unchanged.
- **D03: Normal WordPress behaviour.** The box can be collapsed, hidden through Screen Options and dragged (user decision). The header shows an unsaved/in-sync badge and a Fullscreen button, so state stays visible when collapsed. The unload guard and the submit guard in D05 cover the hidden case.
- **D04: Overlays.** Fullscreen modal and drawer are printed on `admin_footer`, not inside the postbox.
- **D05: Payload rules.** The hidden `wpe_variants_payload` is filled only when the draft is dirty and the product type selector is `variable`. An unchanged Update therefore never rewrites variants. If the draft is dirty and the type is no longer variable, submit asks for confirmation instead of silently dropping the draft. The unload prompt is skipped while the product form itself is submitting.
- **D06: Failure surfacing and draft keeping.** On an Update/Publish failure (service exception, invalid JSON, failed nonce or capability) the bridge stores an error and the submitted payload in per-user, per-product transients (60 minutes, payload capped at 2 MB). The next load of that product shows an error admin notice and the editor restores the draft as dirty with its original expected version, so a real conflict is reported again rather than overwritten. Both transients are read once.
- **D07: Lazy load.** The editor fetches data only when the product type is variable, or when the user switches to variable.
- **D08: Layers.** One layer manager handles the fullscreen modal, drawer and the generator, bulk and media dialogs: focus moves in on open, Tab is trapped, Escape closes the top layer, focus returns to the trigger.
- **D09: Service defects found by running the tests with assertions on.** `zend.assertions` is `-1` for WP-CLI on this host, so every `assert()` in the three ProductEditor tests was compiled out and they passed vacuously. With assertions on, the following were real defects and are fixed here:
  - the service returned `global_taxonomies` while the editor and the tests read `taxonomies` (global attribute dropdown was empty); `taxonomies` is now returned and `global_taxonomies` is kept as an alias;
  - descriptive attributes leaked into each variation's combination as `*`, so existing combinations never matched generator output and could be offered again as new; only options used for variation are now part of a combination;
  - bulk-edit preview ignored the `{type, value}` price actions the editor sends and did not return the per-field `changes` the editor renders, which would break the preview table; it now uses the same arithmetic as the client apply step.
  The save result also reports `projection` (`legacy_mirror`, `catalog_index`) instead of swallowing the outcome. On this site the mirror reports `rejected_stale_epoch` for a new product because the service passes worker epoch 1 while the global epoch is 2; that is an authority question, not changed here (see QUESTIONS.md).
- **D10: Tests must assert.** The three ProductEditor test files fail fast when `zend.assertions` is not `1`, and plan DoD commands run PHP with `-d zend.assertions=1 -d assert.exception=1`. `ProductEditorServiceTest::testLegacyMirrorProjection` stays red on this site because of the epoch question above and is not part of any DoD; it is not edited to pass.
- **D11: Supersedes P003 D01.** P003 stays sealed and unchanged; this plan records the change of placement.

## 4. Documentation changes

- `docs/plans/P005-variants-block/plan.md`
- `docs/plans/P005-variants-block/report.md`
- `docs/NOTES.md`
- `docs/QUESTIONS.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P005.1",
      "title": "Service contract fixes, projection outcome and non-vacuous editor tests",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/04-api-contract.md"
      ],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "tests/integration/ProductEditorServiceTest.php",
        "tests/integration/ProductEditorE2ETest.php",
        "tests/integration/ProductEditorBridgeTest.php",
        "tests/integration/ProductEditorContractTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorContractTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorE2ETest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P005.2",
      "title": "Bridge: meta box, tab removal, footer overlays, failure notice and draft keeping",
      "depends_on": ["P005.1"],
      "read_first": [
        "docs/09-ui.md",
        "docs/04-api-contract.md"
      ],
      "files": [
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "tests/integration/ProductEditorBridgeTest.php",
        "tests/integration/ProductEditorE2ETest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorE2ETest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P005.3",
      "title": "Editor assets: box visibility, header badge, lazy load, payload rules, layer manager, draft restore",
      "depends_on": ["P005.2"],
      "read_first": [
        "docs/09-ui.md"
      ],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "--check", "assets/admin/product-editor.js"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorE2ETest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P005.4",
      "title": "Live browser verification, regression run and notes",
      "depends_on": ["P005.3"],
      "read_first": [
        "docs/PROGRESS.md",
        "docs/06-implementation-plan.md"
      ],
      "files": [
        "tests/e2e/VariantsBlockLive.mjs",
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P005-variants-block/plan.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "tests/e2e/VariantsBlockLive.mjs"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorContractTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
