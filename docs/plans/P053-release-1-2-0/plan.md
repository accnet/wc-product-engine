# P053 — Release 1.2.0: version bump and distribution zip

## 1. Goal

Ship the work since 1.1.0 as plugin version 1.2.0, with a reproducible distribution zip (user request of 2026-11-25). Main changes in 1.2.0:
- full product purge and bulk delete (P039);
- Shopify import performance and category paths by default;
- storefront query budget work (P040, P041, P047): category and product pages 0.65–1.30 s → 0.15–0.21 s TTFB on site1;
- legacy mirror and capture batching (P043–P046);
- test isolation fixes (P048, P050–P052);
- the WooCommerce 11.3.1 re-certification (P049).

The working tree already holds the first bump edits:
- plugin header and `WPE_VERSION`;
- `contracts/openapi/wpe-v1.json` `info.version`;
- `package.json` and `package-lock.json`;
- `T49Test` reading the zip name from `WPE_VERSION`;
- `dist/wc-product-engine-1.2.0.zip` built at 03:45.

They are reviewed and kept, not redone.

## 2. Scope

In scope:
- Keep the bump edits listed in §1.
- `contracts/compatibility/baseline-lock.json`: engine entry version 1.2.0 and the new `wc-product-engine.php` hash.
- `docs/CHANGELOG.md`: a 1.2.0 section.
- Rebuild `dist/` with `scripts/release/ReleasePackager.mjs` after all edits, and check that the zip carries the 1.2.0 header, the runtime contract files, and no tests, docs or `dist` content.
- Re-run the evidence that depends on the engine version or the package: T01 (baseline lock), T45 (seal), T46 and T49 (release packaging). Run on the isolated root against `test_wpe_regress` (P049 D01), then a full `--verify-only`.

Out of scope:
- Deploying or activating 1.2.0 anywhere.
- The T45 fixture lists, which name historical versions as test data.
- `dist/wc-product-engine-1.1.0.zip`, an ignored local build that is left in place.

## 3. Decisions

- **D01** — 1.2.0 is a minor version: new features and performance work, with the schema migrated in place (`SchemaMigrator` 5) and no breaking API change.
- **D02** — `dist/` stays ignored by git. The zip is a local build artifact, and its SHA-256 is recorded in the report and the release manifest.

## 4. Documentation changes

- `contracts/compatibility/baseline-lock.json`
- `contracts/openapi/wpe-v1.json` (`info.version`)
- `docs/CHANGELOG.md`
- `docs/NOTES.md`
- `docs/evidence/` (receipts written by the runner)
- `docs/plans/P053-release-1-2-0/plan.md`
- `docs/plans/P053-release-1-2-0/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P053.1",
      "title": "Bump to 1.2.0 (header, WPE_VERSION, OpenAPI, package files, baseline lock, changelog) and build the distribution zip",
      "depends_on": [],
      "read_first": [
        "docs/08-operations.md",
        "scripts/release/ReleasePackager.mjs",
        "tests/integration/T49Test.php"
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

Test notes: T01, T45, T46 and T49 evidence is refreshed with `scripts/rerun-evidence.mjs --only T01,T45,T46,T49` with `WPE_WP_PATH` set to the isolated root, followed by `--verify-only` for all 49 tasks; results are in the report.
