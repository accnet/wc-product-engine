# P001 — Audit remediation (OD-01..OD-07)

## 1. Goal

Close the P0/P1 findings of docs/audits/FINAL-AUDIT.md using the user decisions recorded in docs/audits/OPEN-DECISIONS.md: evidence recorded against the real pinned runtime, per-requirement receipts, isolated tests, guarded retention cleanup, behavioral selector test, and a re-run of all 49 task evidence files.

## 2. Scope

In scope: re-pin to the host runtime (OD-01 A), downgrade scale claims instead of seeding 100k/1M (OD-02 C), strict digest with runner removed from T01/T02 Files (OD-03 A), T46 narrowed to guarded postmeta cleanup (OD-04 B), backup plus residue cleanup plus residue gate (OD-05 A), Playwright selector test (OD-06 A), one-time contract reopening (OD-07 A). Out of scope: production cutover, 100k/1M datasets, retention of tombstone/asset/outbox/job data, archival metadata, restore instructions, any change to WordPress or WooCommerce core.

## 3. Decisions

OD-01 A, OD-02 C, OD-03 A, OD-04 B, OD-05 A, OD-06 A, OD-07 A (user answers of 2026-10-12). New requirement IDs R203-R213 replace the earlier proposal numbering. Requirement status moves from specified_not_implemented to revalidation_pending, then verified only after all 49 receipts pass. The status field is excluded from the evidence content digest so that status updates do not invalidate receipts.

## 4. Documentation changes

Authorized paths: contracts/compatibility/*.json, contracts/tasks.json, contracts/regression-requirements.json, contracts/structure.json, docs/01, docs/02, docs/05, docs/06, docs/07, docs/08, docs/PROGRESS.md, docs/QUESTIONS.md, docs/CHANGELOG.md, docs/NOTES.md, docs/adr/003-audit-remediation-scope.md, docs/evidence/README.md, docs/evidence/independent-review.md, docs/audits/*, README.md, scripts/check-docs.mjs.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P001.1",
      "title": "Re-baseline contracts, docs and validators",
      "depends_on": [],
      "read_first": [
        "docs/audits/FINAL-AUDIT.md",
        "docs/audits/OPEN-DECISIONS.md",
        "docs/02-domain.md"
      ],
      "files": [
        "contracts/compatibility/pins.json",
        "contracts/compatibility/baseline-lock.json",
        "contracts/compatibility/matrix.json",
        "contracts/compatibility/wc-props-mapping.json",
        "contracts/tasks.json",
        "contracts/regression-requirements.json",
        "contracts/structure.json",
        "docs/01-architecture-and-stack.md",
        "docs/02-domain.md",
        "docs/05-project-structure.md",
        "docs/06-implementation-plan.md",
        "docs/07-testing.md",
        "docs/08-operations.md",
        "docs/PROGRESS.md",
        "docs/QUESTIONS.md",
        "docs/CHANGELOG.md",
        "docs/adr/003-audit-remediation-scope.md",
        "README.md",
        "scripts/check-docs.mjs",
        "tests/integration/T01Test.php"
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
        ],
        [
          "node",
          "scripts/guard.mjs",
          "--all"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T01Test.php"
        ]
      ]
    },
    {
      "id": "P001.2",
      "title": "Evidence tooling: runtime env, per-requirement receipts, base commit binding",
      "depends_on": [
        "P001.1"
      ],
      "read_first": [
        "docs/07-testing.md",
        "docs/evidence/README.md"
      ],
      "files": [
        "scripts/evidence-lib.mjs",
        "scripts/test-runtime.mjs",
        "scripts/verify-task.mjs",
        "tests/integration/T02Test.php",
        "docs/evidence/README.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T02Test.php"
        ],
        [
          "node",
          "scripts/test-runtime.mjs",
          "T02"
        ],
        [
          "node",
          "scripts/verify-task.mjs",
          "T02"
        ]
      ]
    },
    {
      "id": "P001.3",
      "title": "ReadinessGates full version staleness",
      "depends_on": [
        "P001.2"
      ],
      "read_first": [
        "docs/07-testing.md"
      ],
      "files": [
        "src/Application/Rollout/ReadinessGates.php",
        "tests/integration/T42Test.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T42Test.php"
        ]
      ]
    },
    {
      "id": "P001.4",
      "title": "Test isolation, residue gate and residue cleanup",
      "depends_on": [
        "P001.2"
      ],
      "read_first": [
        "docs/07-testing.md",
        "docs/audits/agent-technical-audit.md"
      ],
      "files": [
        "scripts/test-isolation.mjs",
        "scripts/test-runtime.mjs",
        "src/Bootstrap/FixtureHarness.php",
        "tests/integration/T04Test.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T04Test.php"
        ],
        [
          "node",
          "scripts/test-runtime.mjs",
          "T04"
        ],
        [
          "node",
          "scripts/verify-task.mjs",
          "T04"
        ]
      ]
    },
    {
      "id": "P001.5",
      "title": "T46 guards and T40 mirror-stopped rollback block",
      "depends_on": [
        "P001.4"
      ],
      "read_first": [
        "docs/02-domain.md",
        "docs/08-operations.md"
      ],
      "files": [
        "src/Application/Rollout/RetentionCleaner.php",
        "src/Application/Migration/RollbackRunner.php",
        "tests/integration/T46Test.php",
        "tests/integration/T40Test.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T40Test.php"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T46Test.php"
        ]
      ]
    },
    {
      "id": "P001.6",
      "title": "Playwright selector stale-response behavior test",
      "depends_on": [
        "P001.4"
      ],
      "read_first": [
        "docs/07-testing.md",
        "docs/09-ui.md"
      ],
      "files": [
        "playwright.config.mjs",
        "tests/e2e/SelectorBehavior.spec.mjs",
        "tests/integration/T27Test.php",
        "assets/variant-selector/selector.js"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "npx",
          "--no-install",
          "playwright",
          "test",
          "--config=playwright.config.mjs"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--allow-root",
          "eval-file",
          "tests/integration/T27Test.php"
        ]
      ]
    },
    {
      "id": "P001.7",
      "title": "Re-run all evidence T01-T49 on pinned runtime",
      "depends_on": [
        "P001.3",
        "P001.5",
        "P001.6"
      ],
      "read_first": [
        "docs/evidence/README.md"
      ],
      "files": [
        "scripts/rerun-evidence.mjs"
      ],
      "dod": [
        [
          "node",
          "scripts/rerun-evidence.mjs"
        ]
      ]
    },
    {
      "id": "P001.8",
      "title": "Refresh docs, requirement status and readiness gate",
      "depends_on": [
        "P001.7"
      ],
      "read_first": [
        "docs/audits/READINESS-GATE.md"
      ],
      "files": [
        "contracts/regression-requirements.json",
        "docs/PROGRESS.md",
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/07-testing.md",
        "README.md",
        "docs/audits/READINESS-GATE.md",
        "docs/audits/FINAL-AUDIT.md",
        "docs/evidence/independent-review.md"
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
        ],
        [
          "node",
          "scripts/guard.mjs",
          "--all"
        ],
        [
          "node",
          "scripts/rerun-evidence.mjs",
          "--verify-only"
        ]
      ]
    }
  ]
}
```
