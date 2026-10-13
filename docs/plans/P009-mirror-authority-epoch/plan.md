# P009 — Mirror worker epoch follows the authority epoch the mirror reads (OD-14)

## 1. Goal

Replace the epoch source chosen in P006 by the one the contract defines. `docs/02-domain.md` says the mode and cohort registry writes `authority_epoch` and every command must carry the epoch it read and be rejected when stale. P006.1 used `OutboxWorker::getCurrentEpoch()` (control key `current_epoch`) as the fallback worker epoch for products without object state. Investigation for OD-14 found that this is a second, independent counter: its only writer `OutboxWorker::incrementEpoch()` has no caller in `src`, while authority flips set `authority_epoch` through `LegacyMirror::switchAuthorityEpoch`. On this site the two differ (2 versus 9). The P006 fallback works today only because 9 is not below 2; a later flip to a higher authority epoch would bring `rejected_stale_epoch` back.

## 2. Scope

In scope:
- The three mirror callers (`ProductEditorService::saveProductVariants`, `ShopifyBatchImporter`, `ShopifyImporter`) take the worker epoch from `LegacyMirror::getAggregateEpoch($productId)`: the object state epoch when a row exists, otherwise the global authority epoch. This is exactly the value the epoch fence compares against, so a caller that just read it can never be stale.
- Extend `MirrorWorkerEpochTest` so it fails when the fallback comes from the other counter: it raises the global authority epoch above `current_epoch` for the duration of the test and restores it afterwards.
- Make the Shopify batch test teardown remove the rows its import creates (follow-up from P008).
- Record the resolution of OD-14 and the supersession of the P006 source choice.

Out of scope:
- Any change to `LegacyMirror`, the fence, `switchAuthorityEpoch` or the stored values of either control key.
- Removing or merging the `current_epoch` counter and `OutboxWorker::incrementEpoch` (kept; used by outbox tests).
- Surfacing mirror outcomes in the Shopify importers.

## 3. Decisions

- **D01: Source of truth is `authority_epoch`.** Contract 02 names it; the mirror fence reads it; flips write it. `current_epoch` stays an outbox counter and is not an authority input.
- **D02: Use the mirror's own accessor.** `getAggregateEpoch` already encodes "state epoch, else global". Callers stop duplicating that rule with a literal or a second counter.
- **D03: P006 is superseded on the source choice only.** Its test, its fix of the literal 1 and its docs stay; its note that `current_epoch` is the system epoch is corrected in NOTES.
- **D04: Test controls the global epoch.** The regression raises `authority_epoch` temporarily above `current_epoch` (and above 1) and restores the exact original value in a `finally` block.

## 4. Documentation changes

- `docs/plans/P009-mirror-authority-epoch/plan.md`
- `docs/plans/P009-mirror-authority-epoch/report.md`
- `docs/NOTES.md`
- `docs/QUESTIONS.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P009.1",
      "title": "Mirror callers carry the authority epoch the mirror reads",
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
      "id": "P009.2",
      "title": "Shopify batch test teardown removes everything the import creates",
      "depends_on": [
        "P009.1"
      ],
      "read_first": [
        "docs/PROGRESS.md"
      ],
      "files": [
        "tests/integration/ShopifyBatchImporterTest.php"
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
          "tests/integration/ShopifyBatchImporterTest.php"
        ],
        [
          "node",
          "scripts/plan.mjs",
          "check"
        ]
      ]
    },
    {
      "id": "P009.3",
      "title": "QUESTIONS and NOTES update, live regression run",
      "depends_on": [
        "P009.2"
      ],
      "read_first": [
        "docs/PROGRESS.md",
        "docs/06-implementation-plan.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P009-mirror-authority-epoch/plan.md"
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
          "tests/integration/ProductEditorE2ETest.php"
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
