# Report

## Summary

WooCommerce 11.3.0 added three product and variation props: `customs_commodity_code`, `customs_country_of_origin` and `customs_description`, stored in postmeta `_customs_*`. The sealed props mapping did not cover them. Under native authority they would have been dropped silently on save. Following the user's decision (OD-18, fail-closed):

- `WPE\Compatibility\WooDataStore\LegacyOnlyProps` lists the three props.
- `ProductDataStore::create()`/`update()` and `ProductVariationDataStore::create()` throw `WC_Data_Exception` `wpe_legacy_only_prop` before any write when one of them holds a value. Empty values pass, so ordinary saves are unchanged.
- `CutoverRunner::startCutover()` rejects a cohort that contains a product, or the parent of a variation, with non-empty `_customs_*` postmeta. The error names up to 20 IDs. The check runs before the gate check, and both must pass.
- `contracts/compatibility/wc-props-mapping.json` has a new `excluded_props` section with `since`, `storage`, `policy` (`legacy_only_blocks_native`) and `reason` for each prop. `props_mapping` is unchanged.

No public class, method, hook, REST route or stored data changed. On site1.localhost no product carries customs data, and the site runs at legacy authority.

## Verification

- `tests/integration/LegacyOnlyPropsTest.php`: 17 checks pass on the isolated root (`test_wpe_regress`), with fixtures removed afterwards. The checks cover:
  - Runtime coverage: every `get_data()` key of simple, variable and variation products on WooCommerce 11.3.0 is mapped or excluded. This check fails without `excluded_props`.
  - The refusal codes, and that no row is written.
  - Detection of customs data on products and on variations (flags the parent).
  - The cutover rejection.
- The run was made directly, not through `plan.mjs run`: the task was marked done before its receipt was logged.
- Unchanged and passing on the same root: `T10Test`, `T11Test`, `T15Test`, `T42Test`, `T44Test`, `ProductEditorSaveTest`, `ProductTypeTransitionTest`.
- DoD: `php -l` (3 files), `php tests/unit/T03Test.php`, `node scripts/check-docs.mjs --strict`, `node scripts/guard.mjs --all`, all exit 0.

This is not a WooCommerce integration certification. The WC 11.3.0 re-certification of the T01–T49 evidence is P020.
