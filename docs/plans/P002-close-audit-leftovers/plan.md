# P002 — Close audit leftovers (F15, AC anchors, waivers)

## 1. Goal

Close the open items left by docs/audits/READINESS-GATE.md after P001: fix the outbox worker defect F15 (acquireBatch ignores available_at), give each of the 22 acceptance criteria that only had requirements_only receipts a real, condition-backed anchor in the test output, and record the user decision to drop the independent audit and the 100k/1M scale work, then re-evaluate the readiness gate.

## 2. Scope

In scope: OutboxWorker::acquireBatch honoring available_at with a regression test in T09Test; AC anchor assertions in T01-T05 and T30-T34 tests (each backed by a real assertion, not an unconditional print); docs and audit updates for the waivers; full evidence rerun. Out of scope: any independent audit, seeding or measuring 100k/1M, production cutover or deploy, changes to numbered docs 01-10 or contracts other than registry status.

## 3. Decisions

User answer of 2026-10-12: drop the independent audit and drop the 100k/1M seed and measurement (OD-08, OD-09, both waived by the user, not satisfied). F15 is fixed in code (OD-10 default: fix, the schema already defines idx_state_available_lease on state, available_at). The 22 AC get real anchors (OD-11 default: add, not accept as-is). No new regression requirement IDs; the available_at test is reported under AC-T09-01.

## 4. Documentation changes

Authorized paths: docs/audits/READINESS-GATE.md, docs/audits/FINAL-AUDIT.md, docs/audits/OPEN-DECISIONS.md, docs/audits/REMEDIATION-PLAN.md, docs/QUESTIONS.md, docs/NOTES.md, docs/PROGRESS.md, README.md, docs/evidence/independent-review.md. Numbered docs 01-10 and contracts stay untouched so that evidence is not invalidated.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P002.1",
      "title": "OutboxWorker honors available_at (F15)",
      "depends_on": [],
      "read_first": [
        "docs/audits/FINAL-AUDIT.md",
        "docs/03-database-schema.md"
      ],
      "files": [
        "src/Infrastructure/Database/OutboxWorker.php",
        "tests/integration/T09Test.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "node",
          "scripts/rerun-evidence.mjs",
          "--only",
          "T09"
        ]
      ]
    },
    {
      "id": "P002.2",
      "title": "Real AC anchors for T01-T05",
      "depends_on": [
        "P002.1"
      ],
      "read_first": [
        "docs/audits/READINESS-GATE.md"
      ],
      "files": [
        "tests/integration/T01Test.php",
        "tests/integration/T02Test.php",
        "tests/unit/T03Test.php",
        "tests/integration/T04Test.php",
        "tests/integration/T05Test.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "node",
          "scripts/rerun-evidence.mjs",
          "--only",
          "T01,T02,T03,T04,T05"
        ]
      ]
    },
    {
      "id": "P002.3",
      "title": "Real AC anchors for T30-T34",
      "depends_on": [
        "P002.1"
      ],
      "read_first": [
        "docs/audits/READINESS-GATE.md"
      ],
      "files": [
        "tests/integration/T30Test.php",
        "tests/integration/T31Test.php",
        "tests/integration/T32Test.php",
        "tests/integration/T33Test.php",
        "tests/integration/T34Test.php"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "node",
          "scripts/rerun-evidence.mjs",
          "--only",
          "T30,T31,T32,T33,T34"
        ]
      ]
    },
    {
      "id": "P002.4",
      "title": "Waivers, readiness gate re-evaluation and final rerun",
      "depends_on": [
        "P002.2",
        "P002.3"
      ],
      "read_first": [
        "docs/audits/READINESS-GATE.md"
      ],
      "files": [
        "docs/audits/READINESS-GATE.md",
        "docs/audits/FINAL-AUDIT.md",
        "docs/audits/OPEN-DECISIONS.md",
        "docs/audits/REMEDIATION-PLAN.md",
        "docs/QUESTIONS.md",
        "docs/NOTES.md",
        "docs/PROGRESS.md",
        "README.md",
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
          "scripts/rerun-evidence.mjs"
        ]
      ]
    }
  ]
}
```
