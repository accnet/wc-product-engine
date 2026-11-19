# Report

## Summary

P014 finishes P013 (architecture hardening). P013.1–P013.3 are done in P013; P013.4/P013.5 are blocked (QUESTIONS OD-16) and their content is done here as P014.1/P014.2.

| Commit | Task | Change |
|---|---|---|
| `28fa1ee` | P013.1 | Audit `docs/audits/P013-architecture-audit.md`, `Support` layer row in 05 §2 |
| `01c75af` | P013.2 | F1: 47 constructors honour an injected `wpdb` (`global $wpdb;` used to overwrite it) |
| `1a1cf69` | P013.3 | F2: `WPE\Support\Singleton` replaces 21 identical `getInstance()` bodies |
| `308e2cd` | P014.1 | F3–F8: `Support\Assets` (filemtime versions), `Support\Capabilities`, a single default flag list in `PluginBootstrap`, one attribute-key normaliser, `OperationsPage` built only on `rest_api_init`, scalar-only cleaned `attribute_*` values in `wc-ajax=get_variation` |

No public class, method, hook, REST route, option name or stored data changed. The uncommitted Shopify import hunks of another session stayed out of every commit.

## Verification

Smoke checks in WordPress: all 21 singletons return their own class and a single instance; `/wpe/v1/status`, `/wpe/v1/operations/health`, `/wpe/v1/operations/action` registered; status route 401 anonymous, 200 administrator; asset URLs resolve with filemtime versions and fall back to the plugin version for a missing file; the default flag list has the same keys as the loaded flags.

Full unit + integration suite, assertions on. "Before" ran on the live DB at the start of P013. "After" ran against `test_wpe_regress`, a clone of the live DB reached through a separate WordPress root whose `wp-config.php` names that database; the live DB was not written (posts count and max ID identical before and after). 62 tests pass in both runs.

| Test | Before | After |
|---|---|---|
| ImportPerformanceTest | — (not yet written) | fail |
| ProductEditorServiceTest | fail | pass |
| ProductMediaRestTest | fail | fail |
| T01Test | fail | fail |
| T02Test | fail | fail |
| T04Test | fail | fail |
| T09Test | fail | pass |
| T23Test | fail | fail |
| T27Test | fail | fail |
| T43Test | fail | fail |
| T45Test | fail | fail |

All "fail | fail" rows have the same failure lines before and after, and none is caused by code:
- `T01Test`: live WordPress 7.1.5 vs pinned 7.1.3. `T23Test`/`T45Test`: core checksum output parsing, uncertified active plugin. `T27Test`: Playwright not installed. `T43Test`: no gate receipt token. `T04Test`: orphan auto-draft posts already on the site.
- `T02Test`: the site's live `wpe_feature_flags` are not at defaults. With the flags reset to the plugin defaults in the test DB only, every T02 requirement passes except AC-T02-03, which needs a product with a `product_cat` term among the first 100 products; this catalog has none (`pid=null`).
- `ProductMediaRestTest`: `a valid patch answers 200` fails before and after; not touched by P013.
- `ProductEditorServiceTest`, `T09Test` failed before and pass after: they depend on DB state that differed between the two runs, not on P013 code.
- `ImportPerformanceTest` is a new, uncommitted test of another session's import-performance work; it is not part of P013/P014.

## Incident

The P013 "before" run wrapped the suite in `scripts/test-isolation.mjs` snapshot/restore on the live DB while another session ran a Shopify import. The restore erased the last 16 products of import job `1dcbfdf1-6596-44a2-8976-911046c854b7` (back to `in_progress`, 48/64); the import must be resumed. A second live-DB run was stopped after 12 tests; its leftovers (posts 5939–5950) remain on the live site for the user to decide on. All later testing used the separate test database.
