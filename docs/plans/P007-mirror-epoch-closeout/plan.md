# P007 — Close out P006: regression run and QUESTIONS/NOTES update

## 1. Goal

Finish the documentation and regression work that P006.2 could not complete. P006.2 listed `ShopifyBatchImporterTest` in its DoD, but that test fails identically on the unmodified baseline (batch 2 reports 1 product processed instead of 2 in the cumulative counter), so the DoD could not pass. A plan cannot be resynced once a task is done, so the corrected work is carried here and P006.2 stays blocked as superseded.

## 2. Scope

In scope:
- Record the OD-12 resolution (worker epoch fallback fixed in P006.1) and open OD-13 for the Shopify batch importer failure, with evidence.
- Add the P006 entry to `docs/NOTES.md`.
- Run the regression commands that are meaningful for the fix: the new epoch test, `ProductEditorServiceTest` (its mirror projection test passes now), `ProductEditorE2ETest` and the live browser run.

Out of scope:
- Fixing or editing `ShopifyBatchImporterTest` or the batch importer counters (open question OD-13).
- Any change to epoch or authority semantics.

## 3. Decisions

- **D01: Unrelated failure is logged, not hidden.** `ShopifyBatchImporterTest` fails the same way with and without the P006 change (checked by stashing the change), so it is recorded as OD-13 and kept out of every DoD.
- **D02: P006.2 is superseded.** It remains blocked with its reason in the log; P007.1 replaces it.

## 4. Documentation changes

- `docs/plans/P007-mirror-epoch-closeout/plan.md`
- `docs/plans/P007-mirror-epoch-closeout/report.md`
- `docs/NOTES.md`
- `docs/QUESTIONS.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P007.1",
      "title": "Regression run, QUESTIONS and NOTES update for the mirror worker epoch fix",
      "depends_on": [],
      "read_first": [
        "docs/PROGRESS.md",
        "docs/06-implementation-plan.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P007-mirror-epoch-closeout/plan.md"
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
