# P013 — Architecture audit (pre-implementation)

Scope: every PHP class in `src/` (74 files, ~42.5k lines), the admin and storefront assets, and the bootstrap. Baseline at commit `0e1f082` plus the uncommitted Shopify import changes in the working tree (left untouched by this plan).

## 1. Findings acted on

| ID | Finding | Evidence | Impact |
|---|---|---|---|
| F1 | Injected `wpdb` is silently discarded | 47 constructors take `?wpdb $wpdb = null` and then run `global $wpdb;`, which rebinds the local `$wpdb` to the global and drops the argument. `$wpdb ?? $GLOBALS['wpdb']` therefore never sees the injected value. | Constructor injection does not work; a test or a second connection can't be injected. Latent today because every caller passes the global. |
| F2 | 21 copies of the same singleton accessor | `private static ?X $instance` plus an identical `getInstance()` body in 21 classes. | Duplication only; behaviour is identical. |
| F3 | Four different asset URL/version strategies | Hard-coded versions `1.2.0` (`admin.css`), `1.2.2` (`shopify-import.js`, bumped by hand), `1.1.0` (`operations.js`), the plugin version constant (storefront selector), and `filemtime` (settings, product editor). | Edited assets with a fixed version stay cached in browsers and CDNs after a deploy. The storefront selector is the most exposed. |
| F4 | Operations capability rule repeated 7 times | `current_user_can('wpe_manage_operations') \|\| current_user_can('manage_woocommerce')` in `PluginBootstrap`, `OperationsPage`, `SettingsPage` (3x). | A policy change has to be made in 7 places. |
| F5 | Default feature flags declared twice | `PluginBootstrap::loadFlags()` and `PluginBootstrap::onActivate()` carry the same 13-key array. | The two lists can drift; a new flag added to one only is a bug. |
| F6 | `OperationsPage` built on every request | Bootstrap calls `OperationsPage::init()` on `plugins_loaded`, which builds `LegacyCapture`, `LegacyMirror`, `RollbackRunner` and their repositories, then removes two of the three hooks `init()` just added. Only the REST routes are used. | Wasted work on every front-end request; add-then-remove hook coupling. |
| F7 | Attribute-display key rule duplicated | `getAttributeDisplayConfig()` and `saveAttributeDisplayConfig()` repeat the `int > 0` or `opt_<key>` key validation. | Duplication inside one class. |
| F8 | `wc-ajax=get_variation` accepts array values | `ResolverApi::handleWcAjaxGetVariation()` copies any `attribute_*` POST value, arrays included, into the selection; the resolver then casts it to the string `Array` and emits a PHP warning. | Noise in logs and an avoidable warning path on a public endpoint. WooCommerce's own handler runs `wc_clean()` here. |

## 2. Findings reviewed and left as they are

- **SQL with interpolated identifiers.** Every unprepared query found interpolates only table names built from `$wpdb->prefix` or `IN (...)` lists built from `intval`/`(int)` values (`VariantRulesService`, `ProductTypeTransition`, `ProductDataStore::child_*`). No user input reaches them. No change.
- **Large classes** (`ProductEditorService` 2267 lines, `SelectorAdapter` 1750, `CsvStager` 1261). Splitting them is a broad change to business-critical code with no defect behind it; it fails the "no refactor without benefit" rule for this plan. Recorded for a future, dedicated plan.
- **A DI container or service locator.** The plugin already wires dependencies through nullable constructor arguments with defaults. With F1 fixed that is enough; a container would add abstraction without a consumer.
- **A table-name registry.** Table names are short literal strings next to `$wpdb->prefix`; a registry would add indirection for no correctness gain.
- **WordPress Coding Standards formatting.** The code base consistently uses PSR-4/PSR-12 layout and camelCase methods. Reformatting to WPCS tabs/snake_case would rewrite every line and rename public methods (public API break). The WPCS rules that matter for safety (escaping, sanitising, nonces, capabilities, prepared SQL, i18n) are what this plan checks and applies.
- **`declare(strict_types=1)`** missing from some files. Adding it changes scalar coercion at runtime; not done without a defect behind it.

## 3. Risk and rollback

All changes keep public class names, method signatures, REST routes, option names, hooks and data untouched. Each task is one local commit; rollback is a revert of that commit. The full PHP unit/integration suite runs before and after, inside a DB snapshot/restore window.
