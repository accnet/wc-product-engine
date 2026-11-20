# P023 — P020 amendment: PROGRESS reflects the 7.1.5 receipts

## 1. Goal

`docs/PROGRESS.md` marks a task `[x]` only when its evidence passes. After P020, T45, T46 and T49 do not verify on WordPress 7.1.5 / WooCommerce 11.3.0 (`docs/evidence/verify-summary.json`: 46/49; T45 blocked by OD-17). Their boxes are still `[x]`, and the status sentence points to `rerun-summary.json`, which holds only P020's last run batch. P020 did not list the task checklist, so the correction is made here.

## 2. Scope

In scope: in `docs/PROGRESS.md`, the boxes of T45, T46 and T49 become `[ ]` with a short reason, and the status sentence points to `verify-summary.json` and the 46/49 result.

Out of scope: any code, test, contract or receipt.

## 3. Decisions

- **D01: Uncheck, do not delete.** The tasks stay implemented. Only their current-runtime evidence is missing. Each line names the blocker (OD-17, or its dependency on T45).

## 4. Documentation changes

- `docs/PROGRESS.md` (§1 status sentence; §8 T45, T49; §9 T46)

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P023.1",
      "title": "PROGRESS: T45/T46/T49 unchecked with reason; status sentence cites verify-summary 46/49",
      "depends_on": [],
      "read_first": [
        "docs/plans/P020-wp715-recertification/report.md"
      ],
      "files": [
        "docs/PROGRESS.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
