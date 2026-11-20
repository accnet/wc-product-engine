# Report

## Summary

site1.localhost moved from the P001 pins (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3.31, MariaDB 11.8.6, theme calora) to WordPress 7.1.5, WooCommerce 11.3.0, PHP 8.3.36, MariaDB 11.8.9 and theme storefront 4.6.2. P020 re-pins to that runtime and re-runs the T01–T49 evidence.

| Commit | Task | Change |
|---|---|---|
| `20b2fa4` | P020.1 | Harness honours `WPE_WP_PATH`: `test-runtime.mjs`, `ReleasePackager.mjs`, and T07/T08 run with plain `php`. T48 and T49 use the root they run in. Isolation receipts record `wp_root` and `database`. The release certified target and T49 read the pinned versions. |
| `c9dd67f` | P020.2 | `T02Test` R03 checks activation on the current site and on a fresh install (flags restored), not the site's live flags. AC-T02-03 creates a fixture product and category when the catalog has none. |
| `4601899` | P021.1 | WooCommerce 11.3.0 customs props are legacy-only and fail closed (OD-18, separate plan P021). |
| `7b0a65d` | P020.3 | `pins.json`, `baseline-lock.json` (core and WooCommerce hashes, theme, current plugins), `matrix.json`, mapping source versions, docs/01 version table and `WPE_WP_PATH` row, README/PROGRESS pointers, OD-17. |
| `eee5103` | (fix) | Three editor tests deleted `wpe_options` without their `wpe_option_values`/`wpe_variant_values`; orphans collided with the next `MAX(id)+1` option. |
| `b3884de` | P020.4 | The T01–T49 receipts. |

## Where the evidence ran

The evidence ran on an isolated WordPress root built from symlinks to the site1.localhost core, plugins and themes. Its own `wp-config.php` names the cloned database `test_wpe_regress`, and `wp-load.php` is a copy rather than a symlink, so `php`-run tests resolve `ABSPATH` to the root.

Versions read through WP-CLI are identical to the live site (`scripts/evidence-lib.mjs`). Every receipt's isolation record names that root and `test_wpe_regress`. The live database was never snapshotted, restored or written by the run; other sessions were writing to it at the time.

Before the run, the test database's residue was removed with a backup (`docs/evidence/residue-cleanup-p020-testdb.json`): 174 orphan auto-drafts and 1 test control key. Playwright 1.63.0 was installed from `package-lock.json` (`npm ci`) together with its chromium-headless-shell build.

## Results

`node scripts/rerun-evidence.mjs --verify-only` (`docs/evidence/verify-summary.json`) gives:
- **46/49 tasks verify.**
- **12/12 readiness gates pass** and issue a gate receipt token. `T43Test` (cutover) passes.
- The result is the same when verifying against the live root.

| Task | Result | Reason |
|---|---|---|
| T45 | fails | `AuthoritySeal` refuses to seal while contact-form-7 6.2.5 is active and not on the certified allowlist. This is correct, fail-closed behaviour. OD-17 is waiting for a user decision. |
| T49 | own run passes, verify fails | Depends on T45's receipt. |
| T46 | fails verify | Depends on T45 and T49. |

The runs were made in three batches; each receipt records its own commit:
1. All 49 tasks. 28 passed. T07, T25, T27, T28 and T36 failed on `Duplicate entry '<id>-<key>' for key 'uniq_option_value_key'`, and 16 more failed through dependencies.
   - The cause was 26 orphan option values left in the test database by earlier test runs (fixed in `eee5103`). The live database had none.
2. The 21 failed tasks, after the fix. 18 passed.
3. T49 alone, after rebuilding `dist/` with `ReleasePackager.mjs`. The ignored `dist/release_manifest.json` was a 7.1.3 build that `T49Test` reuses when it exists.

`docs/evidence/rerun-summary.json` holds the last batch only; `verify-summary.json` is the full 49-task check.

## Follow-ups (not done here)

- **OD-17:** certify contact-form-7 6.2.5 for sealing, deactivate it, or accept the T45/T46/T49 failures.
- **`docs/PROGRESS.md`** still marks T45, T46 and T49 `[x]`. P020 does not list that section, so it needs a docs amendment.
- **`OptionsRepository` IDs:** `createOption`/`createOptionValue` allocate IDs with `MAX(id)+1`. That races between concurrent writers and reuses the IDs of deleted rows. The residue gate (`scripts/test-isolation.mjs`) does not detect orphan option values.
- **T49 packaging:** `T49Test` should rebuild the package instead of reusing an existing `dist/` build.
- **Playwright cache:** installing the 1.63.0 browser removed chromium build 1248 from the shared Playwright cache. Build 1255 (Playwright 1.65.1) was reinstalled; 1248 must be downloaded again where it is needed.

This is evidence on site1.localhost's runtime at its data scale, not a WooCommerce integration certification or a 100k/1M benchmark.
