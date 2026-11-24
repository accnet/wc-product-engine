# Report

## Summary

Re-certified on site1.localhost's current runtime: WordPress 7.1.5, WooCommerce 11.3.1, PHP 8.3.36, MariaDB 11.8.9, theme starterkit 1.2.7.

| Commit | Task | Change |
|---|---|---|
| `3e10021` | P049.1 | Re-pin `pins.json`, `baseline-lock.json` (WooCommerce and plugin hashes, theme, plugin lists), `matrix.json` (contact-form-7 6.2.6 inactive, cards-shield active, new wp-file-manager entry), mapping source version, docs/01 table, README/PROGRESS sentences; OD-20 opened. T01Test passes against the live site. |
| `002cdc2` | P049.2 | T01–T49 receipts re-run on the isolated root. |

WooCommerce 11.3.0 → 11.3.1 review: the official 11.3.0 package (its `woocommerce.php` hash matches the P020 lock) was diffed against the installed 11.3.1. Of 105 changed files, none are product data stores. The engine-relevant changes are:
- stock-hold lowering and release in `wc_reduce_stock_levels()`;
- download path checks;
- plain-text attribute labels;
- `WC_Data_Store::delete()` returning a value.

The evidence run covers each of these paths.

## Where the evidence ran

The isolated root uses symlinks to the live core, plugins and themes, a copied `wp-load.php`, its own uploads directory, and its own `wp-config.php` naming `test_wpe_regress`. The live database was never snapshotted, restored or written. The test database had no residue before or after the run. The release package was rebuilt before T49.

The certified plugin set is the test database's: classic-editor, classic-widgets, disable-xml-rpc, engine, woocommerce. The live site also runs cards-shield (allowlisted, matrix status `unknown`) and wp-file-manager (unreviewed, OD-20).

## Results

`node scripts/rerun-evidence.mjs` and `--verify-only` give **47/49 tasks**. All 12 readiness gates pass (T43 cutover passes), and so do T45, T46 and T49.

| Task | Result | Reason |
|---|---|---|
| T18 | fails R71 | `FacetQuery::queryProducts()` returns the 48 cheapest matches. Since P028 projects native option facets, catalog products already in the test database (from ~19.95) match `pa_color=black` / `pa_size=m` and push the fixture (price 50) off the page. The same failure occurs with the P020-era engine code on today's database, so it is not caused by 11.3.1 or by recent changes. It is a data-isolation defect in the test. |
| T19 | verify fails | Depends on T18's receipt. |

## Follow-ups

- **OD-20** (user decision): certify wp-file-manager 8.0.7, deactivate it on the live site, or accept that sealing is refused there.
- **T18 (and T17 R67)**: scope the fixtures so existing catalog data cannot crowd them out, for example with unique attribute values or a fixture-only filter, then re-run T18/T19.

This is evidence on site1.localhost's runtime at its data scale, not a WooCommerce integration certification or a 100k/1M benchmark.
