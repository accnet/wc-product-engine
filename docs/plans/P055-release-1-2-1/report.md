# Report

## Summary

Plugin version 1.2.1 is prepared and packaged (P055.1). It is a patch release carrying the P054.1 fix: `LegacyCapture` no longer captures a product or variation while it is being permanently deleted. The version is set in:
- the plugin header and `WPE_VERSION`;
- the OpenAPI `info.version`;
- `package.json` and `package-lock.json`;
- the `T49Test` fallback.

`contracts/compatibility/baseline-lock.json` carries the engine version 1.2.1 and the new hash of `wc-product-engine.php`. `docs/CHANGELOG.md` has a v1.2.1 section. The schema stays at version 5.

Distribution zip: `dist/wc-product-engine-1.2.1.zip` (git-ignored).
- 702272 bytes, SHA-256 `1f430fb658483c773d8e29577961579e8b8801d0f6aa8d0e5d8a09faee5ec78e`, which matches `dist/release_manifest.json`.
- 151 files: header Version 1.2.1, `WPE_VERSION` 1.2.1, `contracts/database/schema.sql`, `contracts/compatibility/matrix.json`; no tests, docs or dist content.
- Unzipped, its PHP files lint clean and `src/` is identical to the tree.

Evidence: all receipts were stale after the version change, so T01–T49 were re-run on the isolated root against `test_wpe_regress`. Result: 49/49 run and verify; `--verify-only` gives 49/49; test-database residue is 0.

1.2.1 is not deployed or activated anywhere. This is not a WooCommerce integration certification.
