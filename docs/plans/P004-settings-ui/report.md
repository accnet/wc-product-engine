# Report

## Summary

P004 redesigned the WC Product Engine Settings screen and hardened the REST endpoint behind it.

What changed:
- Settings is four tabs (Overview, Features, Maintenance, Advanced), deep-linkable and allow-listed. Overview shows the real system mode, epoch and health taken from the Operations report, not from the `cohort_mode` flag. Previously Settings showed the stored default routing flag while Operations said legacy_restored, epoch 2, degraded.
- Authority (`cohort_mode`) is read-only in Settings. The old screen let any manage_woocommerce user set full_native directly, bypassing the governed rollout state machine. The REST settings endpoint now validates the whole payload atomically, rejects `cohort_mode`, unknown keys and non-boolean values with per-field errors and writes nothing on error. The stored `cohort_mode` is preserved because it is still the default-cohort route.
- Features uses a single registry (`SettingsRegistry`) and labelled switches, a save bar with unsaved count, discard, per-field errors with focus management, an unsaved-changes guard and an aria-live region. The index rebuild uses a native confirm dialog.
- Runtime status is honest: only `native_writes_enabled` is read by the runtime; `query_enabled`, `media_enabled` and `resolver_enabled` are labelled "saved, not applied by the runtime yet".
- Assets are scoped under `.wpe-admin` and load only on the Settings screen.

Plan notes:
- Plan was amended once through the lifecycle (block, edit, sync, approve, start) because the docs checker rejected an unregistered error code; the contract vocabulary reason code was used instead.
- Deviation from the analysis: the synchronous Settings rebuild was kept next to the job-backed Operations rebuild (decision D04). Maintenance links to Operations. Merging the mechanisms is a follow-up.
- Not done: restyle of Operations and Shopify Import pages, Import defaults tab.

Evidence:
- `wp eval-file tests/integration/SettingsApiTest.php`: 76 checks passed, option verified identical afterwards.
- Playwright run against site1.localhost: 50 of 50 checks (tabs, keyboard toggle, dirty/discard/save sending only changed flags, DB persistence, REST 400 for authority, field-error UI, rebuild dialog with a mocked rebuild route, hostile tab fallback, 360px without overflow, no unexpected console errors, flags identical to the start). The one bug it found (the "inactive" badge was always visible because `display` overrode `hidden`) was fixed and a regression check added.
- Before/after screenshots are kept with the conversation artifacts, not in the repo.
- `node scripts/check-docs.mjs --strict` and `node scripts/plan.mjs check` pass.
- T02Test passes only against defaults: the live site has `cohort_mode=full_native` and zero product-category relationships, so R03, R04 and AC-T02-02/03 fail on the live state regardless of this plan. It was run with a temporary, reversible fixture (default flags, one temporary category link), then the original flags were restored and verified identical.

Caveats:
- The E2E rebuild step used a mocked route, so no real index rebuild was triggered by the tests.
- These are docs and local integration checks, not a WooCommerce integration certification and not a production cutover authorization.
