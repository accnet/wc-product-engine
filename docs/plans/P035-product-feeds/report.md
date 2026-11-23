# Report — P035: Product Feeds for Google and Microsoft Merchant Center

## Summary

Added a Product Feeds module that publishes Google RSS 2.0 / TSV and Microsoft TSV (or Google-format XML) feeds at a token-protected URL. Items come from the native authority (or WooCommerce CRUD for legacy-authority products), are stored per generation and kept current incrementally. The five agreed update modes are implemented: Manual Regenerate, On Change, Debounced Publish, Scheduled Reconciliation and Full Rebuild. A failed or suspicious publish never replaces the last good file.

### Changes delivered
- **P035.1 Contracts**: docs/10 §12 (spec, mapping, validation, update model, URL), docs/03 §4 (three tables), docs/04 §2/§10 (10 admin routes), docs/09 §3/§11, docs/08 §3/§8; `schema.sql`, `schema-contract.json`, `endpoints.json`, OpenAPI.
- **P035.2 PDP deep link**: `SelectorAdapter::getRenderConfig()` applies `attribute_*` query arguments as the opening selection, per request, for the queried product only, never in the cached config.
- **P035.3 Definitions**: `FeedConfig` (normalization, render hash, store hash), `FeedRepository` (CAS version, HMAC token from a stored seed, private directory), `FeedsApiController` CRUD/duplicate/rotate-token; schema version 4.
- **P035.4 Items**: `FeedItemSource` (batched native read path, legacy CRUD path), `FeedMapper` (mapping sources, variants/parent modes, deep links, images, sale windows, identifiers), `FeedValidator` (errors, warnings, exclusions).
- **P035.5 Build/publish**: `FeedItemStore`, `FeedWriter` (streaming XML/TSV + verification), `FeedBuilder` (time-boxed generation builds, publish gate, gzip copy, run history in `wpe_jobs`), `wp wpe feeds list|tick|build|publish|status`.
- **P035.6 Incremental**: `FeedChangeTracker` (outbox action, purge action, term/media/WooCommerce hooks), `FeedScheduler` tick (dirty processing, due sale windows, debounce, escalations).
- **P035.7 Reconciliation**: rolling slices (about 24 h per cycle), orphan removal, drift > 1% → rebuild, worker heartbeat.
- **P035.8 Endpoint**: `FeedEndpoint` (pretty and query URLs, one 404 for all failures, ETag/Last-Modified, `X-Accel-Expires: 0`).
- **P035.9 UI**: `FeedsPage` list + Create/Edit with status card, Preview and Diagnostics; `feeds.js`, `feeds.css` on the `.wpe-ui` kit; regenerate/preview/diagnostics routes.
- **P035.10 Health**: Overview "Needs attention" items for a stale worker, feeds in error and feeds not published for 48 h; NOTES record.

### Verification
- All task DoD commands exit 0 on `test_wpe_bench` (receipts in this folder). Full regression rerun with assertions enabled: FeedsApi 45, FeedItemSource 395 (incl. 100-variation parity with `wc_get_product()`), FeedBuildPublish 26, FeedIncremental 20, FeedReconcile 13, FeedEndpoint 19, FeedsPage 51, FeedHealth 7, PdpUrlSelection 14, AutoSelectFirstValues 11, ProductPurgeOutbox, SchemaVersion 10, SingleModeOperations, ProductTypeTransition 58, SettingsApi 136, T03 unit; `check-docs --strict`, `guard --all`, `plan check`, `git diff --check` clean.
- Live catalog benchmark (site1, temporary feed deleted afterwards): 11,138 products / 172,192 items built and published in 54 s, 284 MB XML, about 42 MB peak memory; publish alone 6.7 s.
- HTTP smoke through nginx on site1: 200 with XML, gzip copy identical, wrong token 404, not stored by the FastCGI cache.
- Headless Chromium on the live admin: create with field validation, rebuild queued → worker build, preview, diagnostics, list search/duplicate/toggle/delete, no JS errors.

### Deviations and limits
- D10 in plan.md says the token is stored as a SHA-256 hash; implemented as an HMAC of a stored random seed so the admin can show the URL again (docs updated). Revocation by rotation is unchanged.
- Products the feed's filters leave out store no items (keeps narrow feeds small); diagnostics therefore do not count them.
- nginx FastCGI caching strips conditional request headers, so 304 responses are only reachable without that cache.
- Not a Merchant Center acceptance certification: feeds follow the documented rules but were not submitted to Google or Microsoft. No commit was made because the working tree holds unrelated uncommitted work.

### Follow-up (2026-11-23): build right after Create
- Before: a new feed (or a queued Full rebuild on a catalog over 2,000 products) waited for `wp wpe feeds tick`; with no system cron it stayed "New" indefinitely.
- Now `POST /admin/feeds/{id}/regenerate` accepts `mode: continue`, which advances a requested or running build by one 20-second step and never starts a new one (`idle` when nothing is pending, 202 `building`, 200 `completed`, 409 while the worker holds the lock). The Edit screen drives these steps whenever a build is pending, so Create, Save with a render change, and Full rebuild finish and publish while the page is open. If the page is closed, the worker resumes from the saved cursor.
- Verified: FeedsPageTest 58 checks. Headless Chromium on live site1: a new feed covering the full catalog (172,148 items) built and published in 59 s with no worker, its public URL returned 200, a reload started nothing, and there were no JS errors. The temporary feed was deleted.
