# P055 — Release 1.2.1: patch version bump and distribution zip

## 1. Goal

Ship the P054 fix as plugin version 1.2.1, with a new distribution zip (user request of 2026-11-25). Since 1.2.0 (P053, commit `2e5cd3b`):
- `LegacyCapture` no longer captures a product while it is being permanently deleted. Before the fix, a capture during the delete recreated orphan native rows in the pre-cutover system modes (P054.1).
- Test cleanup and the orphan residue signature (P054.2, P054.3). These are test-only changes and are not in the package.

The zip rebuilt after P054 still said 1.2.0, so its contents differ from the 1.2.0 zip recorded in the P053 report. A new version number keeps the two builds apart.

## 2. Scope

In scope:
- Set version 1.2.1 in:
  - the plugin header and `WPE_VERSION` in `wc-product-engine.php`;
  - `contracts/openapi/wpe-v1.json` `info.version`;
  - `package.json` and `package-lock.json`;
  - the `T49Test` fallback version.
- `contracts/compatibility/baseline-lock.json`: engine entry version 1.2.1 and the new `wc-product-engine.php` hash.
- `docs/CHANGELOG.md`: a 1.2.1 section.
- Rebuild `dist/` with `scripts/release/ReleasePackager.mjs`. Check that the zip carries the 1.2.1 header, that its `src/` matches the tree, that its PHP lints, and that it has no tests, docs or `dist` content.
- Re-run all evidence on the isolated root against `test_wpe_regress` (P049 D01), because the version change makes every receipt stale (P053). Then run a full `--verify-only`.

Out of scope:
- Deploying or activating 1.2.1 anywhere.
- The ignored local zips of 1.1.0 and 1.2.0 in `dist/`, which are left in place.

## 3. Decisions

- **D01** — 1.2.1 is a patch version: one bug fix, no schema change (`SchemaMigrator` stays at 5) and no API change.

## 4. Documentation changes

- `contracts/compatibility/baseline-lock.json`
- `contracts/openapi/wpe-v1.json` (`info.version`)
- `docs/CHANGELOG.md`
- `docs/NOTES.md`
- `docs/evidence/` (receipts written by the runner)
- `docs/plans/P055-release-1-2-1/plan.md`
- `docs/plans/P055-release-1-2-1/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P055.1",
      "title": "Bump to 1.2.1 (header, WPE_VERSION, OpenAPI, package files, T49 fallback, baseline lock, changelog) and build the distribution zip",
      "depends_on": [],
      "read_first": [
        "docs/plans/P053-release-1-2-0/plan.md",
        "scripts/release/ReleasePackager.mjs"
      ],
      "files": [
        "wc-product-engine.php",
        "contracts/openapi/wpe-v1.json",
        "package.json",
        "package-lock.json",
        "tests/integration/T49Test.php",
        "contracts/compatibility/baseline-lock.json",
        "docs/CHANGELOG.md",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "wc-product-engine.php"],
        ["php", "-l", "tests/integration/T49Test.php"],
        ["node", "scripts/release/ReleasePackager.mjs"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/T01Test.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```

Test notes: all T01–T49 evidence is refreshed with `scripts/rerun-evidence.mjs` with `WPE_WP_PATH` set to the isolated root, followed by `--verify-only` for all 49 tasks; results are in the report.
