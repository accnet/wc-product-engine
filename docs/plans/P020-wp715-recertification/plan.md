# P020 — Re-certify on WordPress 7.1.5 / WooCommerce 11.3.0

## 1. Goal

The host runtime moved from the P001 pins (WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3.31, MariaDB 11.8.6) to WordPress 7.1.5, WooCommerce 11.3.0, PHP 8.3.36 and MariaDB 11.8.9. Every receipt is now stale (R171), so `T01Test` fails and all 12 readiness gates report `stale` (`T43Test`). Re-pin to the current runtime and re-run all T01–T49 evidence, as P001.7 did for the previous upgrade (user request of 2026-11-20).

## 2. Scope

In scope:
- The test harness runs against any WordPress root given by `WPE_WP_PATH` (default stays `/var/www/site1.localhost`).
- Pins, baseline lock, compatibility matrix and docs record the new versions.
- `T02Test` stops depending on live site state.
- The evidence run.

The evidence run uses an isolated root: the same WordPress core, plugins, themes, PHP and MariaDB server as site1.localhost, with its own `wp-config.php` and a cloned database `test_wpe_regress`. Every task runs inside a snapshot/restore window, and the live database is never snapshotted or restored. Other sessions write to it concurrently, and a live restore erased imported products on 2026-11-20 (`docs/NOTES.md`, P014).

Out of scope:
- Certifying contact-form-7 for sealing. `AuthoritySeal` allowlist, OD-17.
- Production cutover.
- 100k/1M data.
- Any WordPress or WooCommerce core change.

## 3. Decisions

- **D01: Isolated root.** The evidence runs on the isolated root described in §2. Its versions are identical to the live runtime (`scripts/evidence-lib.mjs` reads them through WP-CLI). The isolation record of every receipt names the root and the database it ran on.
- **D02: contact-form-7 stays uncertified.** contact-form-7 6.2.5 is active. A code review found it writes only its own `wpcf7_contact_form` posts through WordPress APIs, with no product or global query hooks. Adding it to the seal allowlist is a security decision for the user (OD-17). Until then `T45Test` fails correctly, and so do T46/T49, which depend on it. They are reported, not hidden.
- **D03: Residue on the test database only.** The test database's orphan auto-drafts are cleaned with `scripts/test-isolation.mjs cleanup-residue` and a backup, on `test_wpe_regress` only. The live database's residue is not touched.

## 4. Documentation changes

- `contracts/compatibility/pins.json`
- `contracts/compatibility/baseline-lock.json`
- `contracts/compatibility/matrix.json`
- `contracts/compatibility/wc-props-mapping.json` (source versions only)
- `docs/01-architecture-and-stack.md` (version table)
- `docs/PROGRESS.md` and `README.md` (version sentence)
- `docs/QUESTIONS.md` (OD-17)
- `docs/NOTES.md`
- `docs/evidence/` (receipts written by the runner)
- `docs/plans/P020-wp715-recertification/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P020.1",
      "title": "Harness honours WPE_WP_PATH; version targets read from pins",
      "depends_on": [],
      "read_first": [
        "docs/evidence/README.md",
        "docs/07-testing.md"
      ],
      "files": [
        "scripts/evidence-lib.mjs",
        "scripts/test-runtime.mjs",
        "scripts/test-isolation.mjs",
        "scripts/release/ReleasePackager.mjs",
        "tests/unit/T07Test.php",
        "tests/integration/T08Test.php",
        "tests/integration/T48Test.php",
        "tests/integration/T49Test.php"
      ],
      "dod": [
        ["node", "--check", "scripts/evidence-lib.mjs"],
        ["node", "--check", "scripts/test-runtime.mjs"],
        ["node", "--check", "scripts/test-isolation.mjs"],
        ["node", "--check", "scripts/release/ReleasePackager.mjs"],
        ["php", "-l", "tests/unit/T07Test.php"],
        ["php", "-l", "tests/integration/T08Test.php"],
        ["php", "-l", "tests/integration/T48Test.php"],
        ["php", "-l", "tests/integration/T49Test.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P020.2",
      "title": "T02Test independent of live flags and catalog categories",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md"
      ],
      "files": [
        "tests/integration/T02Test.php"
      ],
      "dod": [
        ["php", "-l", "tests/integration/T02Test.php"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P020.3",
      "title": "Re-pin contracts and docs to WP 7.1.5 / WC 11.3.0 / PHP 8.3.36 / MariaDB 11.8.9",
      "depends_on": ["P020.1"],
      "read_first": [
        "docs/01-architecture-and-stack.md"
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
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P020.4",
      "title": "Re-run T01-T49 evidence on the isolated root",
      "depends_on": ["P020.1", "P020.2", "P020.3"],
      "read_first": [
        "docs/evidence/README.md"
      ],
      "files": [
        "docs/evidence/rerun-summary.json"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    },
    {
      "id": "P020.5",
      "title": "Report, notes and closeout",
      "depends_on": ["P020.4"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P020-wp715-recertification/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```

Test notes: the per-task verification of P020.4 is `scripts/verify-task.mjs` on the isolated root (`WPE_WP_PATH` set). Those runs are logged with `plan.mjs run` so the receipts show the real exit codes. The DoD of P020.4 stays at docs/guard checks, because tasks blocked by OD-17 (T45, T46, T49) are expected to fail and must not be hidden.
