# P030 — P029 amendment: register DEFAULT_FLAGS in the architecture flag section

## 1. Goal

Unblock P029. Its sealed plan text names the flag-default constant of `PluginBootstrap`; `scripts/check-docs.mjs --strict` treats every upper-snake token outside the list in `docs/01-architecture-and-stack.md` as an unknown constant and fails, so every P029 DoD that runs the docs check fails on P029's own plan. P029 is active and cannot be resynced, so the fix lives here.

## 2. Scope

In scope: one sentence in `docs/01-architecture-and-stack.md` §5 (Modes và flags) stating where default flag values live (`DEFAULT_FLAGS` in `PluginBootstrap`), which also registers the token for the docs check.

Out of scope: any change to P029 content, code, or flag behavior.

## 3. Decisions

- **D01: Document, do not suppress.** The sentence is true and useful (operators look for flag defaults); no change to `scripts/check-docs.mjs`.

## 4. Documentation changes

- `docs/01-architecture-and-stack.md` §5.
- `docs/plans/P030-p029-flag-constant-doc/plan.md`
- `docs/plans/P030-p029-flag-constant-doc/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P030.1",
      "title": "State in docs/01 section 5 that default flag values live in the PluginBootstrap flag-default constant",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P029-auto-select-first-values/plan.md"
      ],
      "files": [
        "docs/01-architecture-and-stack.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    }
  ]
}
```
