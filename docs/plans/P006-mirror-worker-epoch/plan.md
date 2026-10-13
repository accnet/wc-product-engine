# P006 — Mirror worker epoch fallback for products without object state

## 1. Goal

Make the legacy mirror accept saves for products that have no `wpe_object_state` row yet, without changing epoch or authority semantics. Today the Variants Manager save and both Shopify importers pass a literal worker epoch of 1 when the row is missing, while `LegacyMirror` fences such a product against the global `authority_epoch` (2 on this site), so the mirror returns `rejected_stale_epoch` and the WooCommerce variation shells stay `auto-draft` with parent 0 (question OD-12).

## 2. Scope

In scope:
- Replace the literal fallback by the current system epoch from `OutboxWorker::getCurrentEpoch()` in `ProductEditorService::saveProductVariants`, `ShopifyBatchImporter` and `ShopifyImporter`. This is the same source `ProductRepository`, `InventoryBridge` and `MetaBridge` already use when they write object state.
- A regression test that fails on the old code on a site whose global epoch is above 1.

Out of scope:
- Any change to `LegacyMirror`, the epoch fence, `authority_epoch`, `current_epoch` or the authority state machine.
- Reconciling the two control keys (`authority_epoch` 2 and `current_epoch` 9 on this site); recorded as a follow-up in QUESTIONS.
- Creating `wpe_object_state` rows from the editor (option B in the analysis).
- Surfacing mirror outcomes in the Shopify importers (they still swallow them).
- Per-save catalog rebuild cost.

## 3. Decisions

- **D01: Source of the fallback epoch.** `OutboxWorker::getCurrentEpoch()` is what every native writer stores in `wpe_object_state.epoch`, so a row-less product is treated like a freshly written one. The fence keeps rejecting a worker that carries an epoch lower than an existing state row.
- **D02: LegacyMirror is untouched.** Its fence semantics (state epoch when a row exists, global epoch otherwise) are part of the sealed rollout contract.
- **D03: Test setup.** The test needs a global epoch above 1 to be a real regression. If `authority_epoch` is 1 it raises it to 2 for the duration of the test and restores the original value in a `finally` block; on this site it is already 2 and is not touched.
- **D04: Importers.** They share the defect, so they get the same one-line change; their behaviour is checked by the existing Shopify batch test plus a source guard in the new test that no literal fallback of 1 remains in the three files.

## 4. Documentation changes

- `docs/plans/P006-mirror-worker-epoch/plan.md`
- `docs/plans/P006-mirror-worker-epoch/report.md`
- `docs/NOTES.md`
- `docs/QUESTIONS.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P006.1",
      "title": "Use the current system epoch as the mirror worker epoch when a product has no object state",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/04-api-contract.md"
      ],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "src/Compatibility/Csv/Shopify/ShopifyImporter.php",
        "tests/integration/MirrorWorkerEpochTest.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "php",
          "-d",
          "zend.assertions=1",
          "-d",
          "assert.exception=1",
          "/usr/local/bin/wp",
          "eval-file",
          "tests/integration/MirrorWorkerEpochTest.php"
        ],
        [
          "php",
          "-d",
          "zend.assertions=1",
          "-d",
          "assert.exception=1",
          "/usr/local/bin/wp",
          "eval-file",
          "tests/integration/ProductEditorContractTest.php"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    },
    {
      "id": "P006.2",
      "title": "Regression run, QUESTIONS and NOTES update",
      "depends_on": [
        "P006.1"
      ],
      "read_first": [
        "docs/PROGRESS.md",
        "docs/06-implementation-plan.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P006-mirror-worker-epoch/plan.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "php",
          "-d",
          "zend.assertions=1",
          "-d",
          "assert.exception=1",
          "/usr/local/bin/wp",
          "eval-file",
          "tests/integration/ProductEditorServiceTest.php"
        ],
        [
          "php",
          "-d",
          "zend.assertions=1",
          "-d",
          "assert.exception=1",
          "/usr/local/bin/wp",
          "eval-file",
          "tests/integration/ProductEditorE2ETest.php"
        ],
        [
          "php",
          "-d",
          "zend.assertions=1",
          "-d",
          "assert.exception=1",
          "/usr/local/bin/wp",
          "eval-file",
          "tests/integration/ShopifyBatchImporterTest.php"
        ],
        [
          "node",
          "tests/e2e/VariantsBlockLive.mjs"
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
