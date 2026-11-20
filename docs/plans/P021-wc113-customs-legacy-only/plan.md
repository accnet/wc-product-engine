# P021 — WC 11.3 customs props: legacy-only, fail-closed under native

## 1. Goal

WooCommerce 11.3.0 added three product and variation props: `customs_commodity_code`, `customs_country_of_origin` and `customs_description`. They are stored in postmeta `_customs_*`. The sealed `contracts/compatibility/wc-props-mapping.json` (WC 11.2.1) has no entry for them. `docs/03-database-schema.md` §1 requires every prop of the pinned version to be mapped or excluded with a reason.

Under native authority the engine's data store would silently drop them on save. The user chose fail-closed on 2026-11-20 (OD-18):
- The props are declared legacy-only.
- Products carrying them cannot be cut over to native.
- The native data store refuses to save them instead of losing them.

Native support can be planned later.

## 2. Scope

In scope:
- A legacy-only prop list.
- A guard in the native product/variation data store (`create`, `update`).
- A cutover pre-check in `CutoverRunner::startCutover`.
- An `excluded_props` section in the mapping.
- An integration test that also checks real runtime coverage (every `get_data()` key of simple, variable and variation products is mapped or excluded).

Out of scope:
- Native storage of customs data.
- CSV columns.
- REST fields.
- External/grouped product types, which the engine does not register a data store for.

## 3. Decisions

- **D01: Error, not silent drop.** A save through the native data store with any non-empty legacy-only prop throws `WC_Data_Exception` (`wpe_legacy_only_prop`) before any write. Empty values pass, so ordinary saves are unaffected.
- **D02: Cutover pre-check.** `startCutover` rejects a cohort when any product in it (all products when no `product_ids` are given), or any of its variations, has non-empty `_customs_*` postmeta. The error lists up to 20 IDs.
- **D03: Mapping.** `excluded_props` records each prop with `since`, `storage` and `policy` (`legacy_only_blocks_native`). `props_mapping` is unchanged.

## 4. Documentation changes

- `contracts/compatibility/wc-props-mapping.json` (`excluded_props`)
- `docs/QUESTIONS.md` (OD-18, answered)

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P021.1",
      "title": "Legacy-only customs props: data store guard, cutover pre-check, mapping exclusion, test",
      "depends_on": [],
      "read_first": [
        "docs/03-database-schema.md",
        "contracts/compatibility/wc-props-mapping.json"
      ],
      "files": [
        "src/Compatibility/WooDataStore/LegacyOnlyProps.php",
        "src/Compatibility/WooDataStore/ProductDataStore.php",
        "src/Application/Rollout/CutoverRunner.php",
        "tests/integration/LegacyOnlyPropsTest.php",
        "contracts/compatibility/wc-props-mapping.json",
        "docs/QUESTIONS.md"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/WooDataStore/LegacyOnlyProps.php"],
        ["php", "-l", "src/Compatibility/WooDataStore/ProductDataStore.php"],
        ["php", "-l", "src/Application/Rollout/CutoverRunner.php"],
        ["php", "tests/unit/T03Test.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```

Test notes: `LegacyOnlyPropsTest.php` writes products, so it runs only on the isolated root (`wp --path=<test root> eval-file`). Its result is recorded with `plan.mjs run` outside the DoD list, because the DoD argv cannot name the scratchpad root portably.
