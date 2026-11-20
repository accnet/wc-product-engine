# P018 — P017 amendment: register WPE_VERSION

## 1. Goal

Make `node scripts/check-docs.mjs --strict` pass with P017 in the tree. P017 D03 names the plugin version constant `WPE_VERSION`, which the docs checker does not know, so P017.2 and P017.3 cannot pass their DoD. P017 is active, so its plan text cannot be resynced.

## 2. Scope

In scope:
- One row in `docs/01-architecture-and-stack.md` §9 for `WPE_VERSION`, the plugin version constant defined in `wc-product-engine.php`.

Out of scope:
- Any code change, and any change to P017 decisions or tasks.

## 3. Decisions

- **D01: `WPE_VERSION` is a read-only constant row.** It is defined by the plugin main file, not by the site owner. P017 D03 uses it so a plugin update never reuses an import fingerprint.

## 4. Documentation changes

- `docs/01-architecture-and-stack.md` §9: one new row for `WPE_VERSION`.
- `docs/plans/P018-p017-amendment/plan.md`
- `docs/plans/P018-p017-amendment/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P018.1",
      "title": "Register WPE_VERSION in the configuration table",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P017-import-mirror-batch-skip/plan.md"
      ],
      "files": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P018-p017-amendment/plan.md",
        "docs/plans/P018-p017-amendment/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
