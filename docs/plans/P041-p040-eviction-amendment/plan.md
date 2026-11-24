# P041 — P040.2 amendment: evict variation-family and meta runtime caches

## 1. Goal

P040.2 keeps, on read-only storefront requests, one bundle per variation (`var_bundle_<id>`), one family entry per variable product (`var_family_<id>`: parent data and variant IDs) and one meta map per object (`meta_map_<id>`) in the non-persistent `wpe_runtime` cache group. Writers already call `AuthorityResolver::evictObject()` (directly or through `clean_post_cache`), but it only drops `auth_probe_` and `prod_bundle_`. P040.2 cannot list `src/Support/AuthorityResolver.php` because P040 is approved and active, so this amendment owns that change: `evictObject()` must also drop the new keys, and evicting a parent must drop its family and every bundle of that family.

## 2. Scope

In scope:
- `AuthorityResolver::evictObject()` deletes `var_bundle_<id>` and `meta_map_<id>`, and when `var_family_<id>` exists, every `var_bundle_` it lists and the family entry itself.
- `flushAll()` already flushes the whole group; unchanged.
- Covered by the P040.2 checks in `tests/integration/StorefrontQueryBudgetTest.php` (a write followed by `evictObject()` on the parent or the variation shows fresh data).

Out of scope: any other cache, any change to when the caches are filled (P040.2).

## 3. Decisions

- **D01** — Evicting a parent drops the whole family: parent writes (title, tax, featured image, cross-sells) change data that every variation bundle's parent data carries.
- **D02** — No new hook: eviction stays on the existing `evictObject()` callers and `clean_post_cache`.

## 4. Documentation changes

- `docs/plans/P041-p040-eviction-amendment/plan.md`
- `docs/plans/P041-p040-eviction-amendment/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P041.1",
      "title": "AuthorityResolver::evictObject drops variation bundles, variation families (cascading to their bundles) and meta maps",
      "depends_on": [],
      "read_first": [
        "src/Support/AuthorityResolver.php",
        "docs/plans/P040-storefront-query-budget/plan.md"
      ],
      "files": [
        "src/Support/AuthorityResolver.php"
      ],
      "dod": [
        ["php", "-l", "src/Support/AuthorityResolver.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/StorefrontQueryBudgetTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
