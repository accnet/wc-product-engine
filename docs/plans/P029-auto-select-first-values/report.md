# Report

## Summary

P029 adds Product Page → Variant Selector toggle `Auto-Select First Values` (`auto_select_first_values`, default off). When on, a variable product opens with a complete, purchasable selection: each option without a merchant-configured default takes its first available value; price, image, `variation_id` and Add to cart are ready on load. Configured defaults always win; Clear returns to them.

| Task | Change |
|---|---|
| P029.1 | docs/04 settings paragraph (7 → 8 product_page flags), docs/09 §10 behavior of the toggle |
| P029.2 | `SettingsRegistry` + `PluginBootstrap` default flag; `SelectorAdapter::autoSelectFirstValues()` and `autoSelection`/`autoResolution` config (SSR checked state, server-rendered option states, Single Price Display); `selector.js` opening state and cache priming; `AutoSelectFirstValuesTest.php`; `SettingsApiTest.php` expected key list |
| P029.3 | Live `tests/e2e/AutoSelectFirstValuesLive.mjs` (13/13 on product 24704), `docs/NOTES.md` |

Decision change found while testing (within D02): resolver `allowed_values` only accounts for options before the one being filled, so a first-listed option could pick a value that conflicts with a configured default on a later option and the resolver would then overwrite that default. Each candidate is now tried through the resolver and kept only if every value already chosen is preserved.

Amendment P030 registered `DEFAULT_FLAGS` in docs/01 §5 so the sealed P029 plan text passes `check-docs --strict`.

Not covered: WooCommerce Blocks product templates and catalog quick-add (out of scope). Pre-existing, unrelated failures elsewhere in the suite (T17 R67, T37, NativeAttributeReadTest on fixture 9581) are unchanged.
