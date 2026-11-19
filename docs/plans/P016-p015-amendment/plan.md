# P016 — P015 amendment: test DB variable and narrowed D03

## 1. Goal

Make `node scripts/check-docs.mjs --strict` pass again with P015 in the tree, and record one narrowing of a P015 decision before P015.2 is implemented. P015 is active, so its plan text cannot be resynced.

## 2. Scope

In scope:
- Register the benchmark test-database variable `WPE_TEST_DB` and the WordPress constant `DB_NAME` it sets in `docs/01-architecture-and-stack.md` §9, so the docs checker knows both names that P015's plan uses.
- Amend P015 D03 (below). All other P015 decisions, tasks and DoD stay as approved.

Out of scope:
- Any code change. P015 tasks carry all code.
- Changing the existing `WPE_TEST_DB_NAME` row, which belongs to the separate fixture-runtime test server settings (host, user, password).

## 3. Decisions

- **D01: `WPE_TEST_DB` is its own row.** It names a disposable `test_*` database on the site's own database server and credentials, used by `tests/performance/use-test-db.php` through `wp --require`. The file sets WordPress `DB_NAME` before `wp-config.php` loads and refuses names without the `test_` prefix. Default `test_wpe_bench`.
- **D02: P015 D03 narrowed.** Replaces P015 D03. The importer keeps a per-product URL → asset/attachment map, so repeated variant image URLs inside one product reuse the first resolution without calling `registerAsset()` again. `AssetService::registerAsset()` keeps its current order (validate, then de-duplicate). Reason: with the P015 D02 per-request DNS cache, validation of a known host costs no network call, so moving the de-duplication lookup ahead of validation would change the SSRF code path for no measurable gain.

## 4. Documentation changes

- `docs/01-architecture-and-stack.md` §9: one new row for `WPE_TEST_DB`, mentioning `DB_NAME`.
- `docs/plans/P016-p015-amendment/plan.md`
- `docs/plans/P016-p015-amendment/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P016.1",
      "title": "Register the benchmark test DB variable and record the narrowed P015 D03",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P015-import-performance/plan.md"
      ],
      "files": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P016-p015-amendment/plan.md",
        "docs/plans/P016-p015-amendment/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
