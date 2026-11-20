# P024 — P011 amendment: close out on local + fake provider scope (OD-19 Option B)

## 1. Goal

Close out plan `P011` (`docs/plans/P011-product-media-library/plan.md`) on the local storage and fake-provider verification scope after the user selected OD-19 Option B on 2026-11-20. Tasks `P011.1`–`P011.11` are complete (`done`); only `P011.12` (`StorageLiveRoundTripTest.php`) remains blocked because the sibling object storage plugin (`images-space`) is not installed on `site1.localhost` and `images_space_settings` does not exist. Because `P011` cannot be resynced once tasks are done and `P011.12` must never fake-pass on local storage, `P011.12` remains blocked and this amendment plan carries the verified local + fake-provider regression run and honest closeout documentation.

## 2. Scope

In scope:
- Record the user's OD-19 Option B decision in `docs/QUESTIONS.md` (waive live S3 round-trip certification on this host; keep `StorageLiveRoundTripTest.php` intact and fail-closed for any future environment where `images-space` is installed and configured).
- Re-verify the full P011 media integration and live browser test suite on WP 7.1.5 / WC 11.3.0 / PHP 8.3.36 / MariaDB 11.8.9 (`MediaSchemaTest`, `StorageResolverTest`, `ManagedUploadTest`, `ProductMediaLibraryTest`, `ProductMediaRestTest`, `VariantImagePickerRulesTest`, `MediaLegacyWriterGuardTest`, `MediaRecoveryTest`, `ProductMediaLive.mjs`, `VariantImagePickerLive.mjs`).
- Write the closeout summary in `docs/plans/P011-product-media-library/report.md`, `docs/plans/P024-p011-closeout-local-waiver/report.md`, and `docs/NOTES.md`, clearly distinguishing local + fake-provider verification from live S3 certification.

Out of scope:
- Installing, configuring, or certifying the `images-space` S3 plugin.
- Weakening or modifying `tests/integration/StorageLiveRoundTripTest.php` to pass on local storage.
- Runtime code, schema, REST route, or WordPress/WooCommerce Core changes.

## 3. Decisions

- **D01: No fake pass for `P011.12`.** `tests/integration/StorageLiveRoundTripTest.php` stays strictly fail-closed (exit 1 when `images-space` is absent or unconfigured). `P011.12` remains `blocked` and `P011` remains `in_progress` by design (matching the `P006`/`P007` and `P013`/`P014` amendment pattern).
- **D02: Explicit waiver scope.** User waived live S3 round-trip certification on `site1.localhost` (OD-19 Option B, 2026-11-20). Local storage and fake-provider tests do not constitute live S3 certification; if `images-space` is installed later, `P011.12` can be resumed and verified against real object storage.

## 4. Documentation changes

- `docs/QUESTIONS.md`: move OD-19 to answered (Option B waiver).
- `docs/NOTES.md`: P024 entry.
- `docs/plans/P011-product-media-library/report.md`: closeout pointer and honest scope record.
- `docs/plans/P024-p011-closeout-local-waiver/plan.md`
- `docs/plans/P024-p011-closeout-local-waiver/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P024.1",
      "title": "Record OD-19 Option B waiver, run full P011 local + fake-provider regression and close out",
      "depends_on": [],
      "read_first": [
        "docs/QUESTIONS.md",
        "docs/10-integrations.md",
        "docs/plans/P011-product-media-library/plan.md"
      ],
      "files": [
        "docs/QUESTIONS.md",
        "docs/NOTES.md",
        "docs/plans/P011-product-media-library/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaSchemaTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/StorageResolverTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ManagedUploadTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductMediaLibraryTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductMediaRestTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/VariantImagePickerRulesTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaLegacyWriterGuardTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaRecoveryTest.php"],
        ["node", "tests/e2e/ProductMediaLive.mjs"],
        ["node", "tests/e2e/VariantImagePickerLive.mjs"],
        ["node", "scripts/guard.mjs", "--all"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
