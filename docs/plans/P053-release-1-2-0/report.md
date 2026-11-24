# Report

## Summary

Plugin version 1.2.0 is prepared and packaged (P053.1). The version bump in the plugin header, `WPE_VERSION`, the OpenAPI `info.version`, `package.json`/`package-lock.json` and `T49Test` was already in the working tree and was kept; P053 added the engine version and hash in `contracts/compatibility/baseline-lock.json` and a v1.2.0 section in `docs/CHANGELOG.md`.

Distribution zip: `dist/wc-product-engine-1.2.0.zip` (git-ignored), 701347 bytes, SHA-256 `7d7c35399f91aea4541fe31fc53518115e8662b86b37ee9574048f5ad7a2c37a`, 151 files: `wc-product-engine.php` (Version 1.2.0), `src/`, `assets/`, `contracts/database/schema.sql`, `contracts/compatibility/matrix.json`, `composer.json`, `README.md`; no tests, docs or dist content. Unzipped, its 102 PHP files lint clean and `src/` is identical to the tree. The packager stamps file times, so a rebuild changes the hash; the manifest records the hash of the build in `dist/`.

Evidence: the version change made every receipt stale, so T01–T49 were re-run on the isolated root against `test_wpe_regress`: 49/49 run and verify (`docs/evidence/rerun-summary.json`, `verify-summary.json`), test-database residue 0; T49 was re-run once more after the final package build. 1.2.0 is not deployed or activated anywhere. Not a WooCommerce integration certification.
