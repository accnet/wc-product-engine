# P049 — Re-certify on WooCommerce 11.3.1 and the current theme and plugin set

## 1. Goal

`site1.localhost` moved on after the P020 re-pin of 2026-11-20:

| Component | P020 pin | Runtime on 2026-11-25 |
|---|---|---|
| WooCommerce | 11.3.0 | 11.3.1 |
| Theme | storefront 4.6.2 | starterkit 1.2.7 (storefront 4.6.2 inactive) |
| Active plugins | classic-editor, classic-widgets, contact-form-7 6.2.5, disable-xml-rpc, engine, woocommerce | cards-shield 3.0.8, classic-editor, classic-widgets, disable-xml-rpc, engine, woocommerce, wp-file-manager 8.0.7 (contact-form-7 6.2.6 and wp-mail-smtp inactive) |

WordPress 7.1.5, PHP 8.3.36, MariaDB 11.8.9 and Node 22.23.3 are unchanged.

Every receipt is now stale (R171). `T01Test` fails on the WooCommerce pin, all 12 readiness gates report `stale`, and `T43Test` and `NativeBridgeRolloutTest` therefore fail too. As in P020, the goal is to re-pin to the runtime and re-run the T01–T49 evidence on an isolated root, without touching the live database.

WooCommerce 11.3.0 → 11.3.1 review (diff of the official 11.3.0 package, whose `woocommerce.php` hash matches the P020 lock, against the installed 11.3.1):
- 105 changed files, mostly orders, checkout, Store API sessions, PayPal, REST authentication and email verification.
- Product data stores are unchanged: `class-wc-product-data-store-cpt.php`, `class-wc-product-variable-data-store-cpt.php` and `class-wc-product-variation-data-store-cpt.php`.
- Relevant to the engine:
  - `WC_Data_Store::delete()` now returns the store's result (engine stores return nothing, so the result is `null` as before);
  - `wc_reduce_stock_levels()` now lowers and then releases the order's stock hold (`ReserveStock::lower_held_stock_for_product()`, `wc_release_stock_for_order()`), and held stock now also counts failed, processing, on-hold and completed orders;
  - `WC_Product_Download` file-type checks drop `#` fragments and validate the parsed path;
  - attribute labels and names are stored as plain text.
- None of these changes a data store interface the engine implements; the full evidence run re-checks the checkout, stock, download and attribute paths.

## 2. Scope

In scope:
- Re-pin the contracts and docs to the runtime:
  - `pins.json`, `baseline-lock.json` (WooCommerce hash, theme, active and inactive plugins with hashes), `matrix.json`, `wc-props-mapping.json` (source version only);
  - the docs/01 version table and its re-pin sentence, the README/PROGRESS version sentence;
  - `docs/QUESTIONS.md`: new OD-20 for wp-file-manager, see D02.
- Rebuild the isolated evidence root:
  - symlinks to the live core, plugins and themes;
  - its own `wp-config.php` naming the cloned test database `test_wpe_regress`;
  - a copied `wp-load.php` and its own empty uploads directory.
- Clean test-database residue with a backup, on `test_wpe_regress` only (P020 D03).
- Re-run T01–T49 with `scripts/rerun-evidence.mjs` and `WPE_WP_PATH` set to the isolated root.
- Report per-task results, including failures, without hiding any.

Out of scope:
- Certifying wp-file-manager or any other plugin for sealing (D02).
- Production cutover, 100k/1M data, any WordPress or WooCommerce core change.
- Changing the live site's plugin or theme state.

## 3. Decisions

- **D01** — Same isolation as P020 D01: the evidence runs against `test_wpe_regress` through the isolated root. The live database is never snapshotted, restored or written. The test database's active plugin set (classic-editor, classic-widgets, disable-xml-rpc, engine, woocommerce) and theme (starterkit) are what the evidence certifies; the report states the difference from the live set.
- **D02** — wp-file-manager 8.0.7 is active on the live site. It is not in the compatibility matrix and not in the `AuthoritySeal` allowlist, and a file manager is a security-sensitive plugin. Whether to certify it, deactivate it, or accept that sealing is refused on the live site is the user's decision, so it is recorded as OD-20 and the allowlist is not changed. cards-shield 3.0.8 is already on the allowlist, with matrix status `unknown`. Its matrix entry is marked active and left `unknown`.
- **D03** — The theme pin records starterkit 1.2.7, the active theme of both the live site and the test database.

## 4. Documentation changes

- `contracts/compatibility/pins.json`
- `contracts/compatibility/baseline-lock.json`
- `contracts/compatibility/matrix.json`
- `contracts/compatibility/wc-props-mapping.json` (source versions only)
- `docs/01-architecture-and-stack.md` (version table and re-pin sentence)
- `docs/PROGRESS.md` and `README.md` (version sentence)
- `docs/QUESTIONS.md` (OD-20)
- `docs/NOTES.md`
- `docs/evidence/` (receipts written by the runner; residue cleanup log)
- `docs/plans/P049-wc1131-recertification/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P049.1",
      "title": "Re-pin contracts and docs to WooCommerce 11.3.1, theme starterkit 1.2.7 and the current plugin set; record OD-20",
      "depends_on": [],
      "read_first": [
        "docs/01-architecture-and-stack.md",
        "docs/plans/P020-wp715-recertification/plan.md",
        "contracts/compatibility/pins.json",
        "contracts/compatibility/baseline-lock.json",
        "contracts/compatibility/matrix.json"
      ],
      "files": [
        "contracts/compatibility/pins.json",
        "contracts/compatibility/baseline-lock.json",
        "contracts/compatibility/matrix.json",
        "contracts/compatibility/wc-props-mapping.json",
        "docs/01-architecture-and-stack.md",
        "docs/PROGRESS.md",
        "README.md",
        "docs/QUESTIONS.md"
      ],
      "dod": [
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "eval-file", "tests/integration/T01Test.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P049.2",
      "title": "Re-run T01-T49 evidence on the isolated root against test_wpe_regress",
      "depends_on": ["P049.1"],
      "read_first": [
        "docs/evidence/README.md",
        "docs/plans/P020-wp715-recertification/report.md"
      ],
      "files": [
        "docs/evidence/rerun-summary.json",
        "docs/evidence/verify-summary.json"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P049.3",
      "title": "Report, notes and closeout",
      "depends_on": ["P049.2"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P049-wc1131-recertification/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```

Test notes: as in P020, P049.2's per-task results come from `scripts/rerun-evidence.mjs` and `scripts/verify-task.mjs` with `WPE_WP_PATH` set to the isolated root. Those runs are logged with `plan.mjs run`, so the real exit codes are recorded. Failing tasks are reported, not hidden.
