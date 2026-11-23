# P037 — Register the P036 feed fallback constants in docs/01

## 1. Goal

Amendment to P036: the opt-out constant for the in-request feed fallback runner, and the host terms the P036 plan names, are registered in the docs/01 §9 environment table so docs checks recognize them.

## 2. Scope

In scope: one row in docs/01 §9 for the opt-out constant, mentioning that site1 disables WP-Cron and that the fallback uses a global MySQL named lock.

Out of scope: any behaviour change; P036 carries the runtime work.

## 3. Decisions

- **D01** — The constant is optional; undefined means the fallback is on, `false` turns it off (P036 D03).

## 4. Documentation changes

- `docs/01-architecture-and-stack.md` §9.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P037.1",
      "title": "Register the feed fallback opt-out constant and the host terms P036 relies on in docs/01 section 9",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P036-feeds-without-cron/plan.md"
      ],
      "files": [
        "docs/01-architecture-and-stack.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ]
      ]
    }
  ]
}
```
