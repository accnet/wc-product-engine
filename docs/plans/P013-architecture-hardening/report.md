# Report

## Summary

P013 (architecture hardening: F1–F8 from `docs/audits/P013-architecture-audit.md`) is complete and closed.

| Commit | Task | Change |
|---|---|---|
| `28fa1ee` | P013.1 | Pre-implementation audit `docs/audits/P013-architecture-audit.md`, `Support` layer row in `docs/05-project-structure.md` §2 |
| `01c75af` | P013.2 | F1: 47 constructors under `src/` honour an injected `wpdb` instead of overwriting it with `global $wpdb;` |
| `1a1cf69` | P013.3 | F2: `WPE\Support\Singleton` trait replaces 21 hand-written `getInstance()` accessors |
| `308e2cd` | P014.1 | F3–F8 implementation: `WPE\Support\Assets` (`filemtime` versions with plugin-version fallback), `WPE\Support\Capabilities`, single default flag list and attribute-key normaliser in `PluginBootstrap`, lazy `OperationsPage::registerRestRoutes()`, scalar-only `wc_clean()` `attribute_*` parsing in `wc-ajax=get_variation` |
| `171ff6f` | P013.4 | Re-ran and passed all 6 declared P013.4 DoD commands (`T02Test`, `T26Test`, `T41Test`, `SettingsApiTest`, `ProductPageSettingsTest`, `scripts/guard.mjs --all`) after P020.2 fixed `T02Test` site-state coupling (resolving OD-16) |

No public class name, method signature, hook, REST route, option name or stored data changed.

## Verification

1. **P013.4 DoD (2026-11-20, WP 7.1.5 / WC 11.3.0 / PHP 8.3.36 / MariaDB 11.8.9, assertions on):**
   - `T02Test`: exit 0 (R03, R04, R05, R06, R203, R204, R205, AC-T02-01..03 passed)
   - `T26Test`: exit 0
   - `T41Test`: exit 0
   - `SettingsApiTest`: exit 0 (127 checks)
   - `ProductPageSettingsTest`: exit 0 (note: restored `mark_unavailable_options` flag to its pre-run value `false` after verification)
   - `node scripts/guard.mjs --all`: exit 0 (0 errors)

2. **Full unit + integration PHP regression suite:**
   - **Commit-time baseline vs post-change (P013/P014):** 62 tests passed both before and after with zero new failures (full table in `docs/plans/P014-p013-amendment/report.md`).
   - **Closeout verification at commit `171ff6f` (2026-11-20):** ran all 74 PHP tests (`tests/unit/*.php`, `tests/integration/*.php`) with `zend.assertions=1` and `assert.exception=1` against an isolated WordPress root pointing at `test_wpe_regress` (`WPE_WP_PATH`, live database untouched: `wp_posts` count `7448` and max ID `8679` identical before and after).
     - **73/74 passed** (including `T01Test`–`T49Test` except `T04Test`, `ProductEditorServiceTest`, `ProductMediaRestTest`, `ImportPerformanceTest`, `SettingsApiTest`, `ProductPageSettingsTest`, `ShopifyBatchImporterTest`, `MirrorWorkerEpochTest`).
     - **1 non-zero exit:** `T04Test` failed R212 (`orphan_auto_draft_posts: 6` present in `test_wpe_regress` during the sequential un-snapshotted run; zero code regression, and T04 passes in isolated verification).

3. **Docs, plan and guard checks:** `node scripts/check-docs.mjs --strict`, `node scripts/guard.mjs --all`, and `node scripts/plan.mjs check` all exit 0.

## Scope limits

These checks verify the P013 architecture hardening and PHP unit/integration suite on the `site1.localhost` runtime (WP 7.1.5 / WC 11.3.0). They are not a WooCommerce ecosystem integration certification or a 100k/1M scale benchmark. No push was performed.
