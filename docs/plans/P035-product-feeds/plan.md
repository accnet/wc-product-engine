# P035 — Product Feeds for Google and Microsoft Merchant Center

## 1. Goal

Merchants publish product feeds that Google Merchant Center and Microsoft Merchant Center fetch from a stable URL. The feeds come from the same native authority, price/stock resolvers, variations and media that the storefront uses. They stay current through incremental updates instead of daily full rebuilds. Generation never times out or exhausts memory on a catalog of 11k parents and 172k variations. A failed or suspicious generation never replaces the last valid file.

## 2. Scope

In scope:
- **Formats**: Google RSS 2.0 (`g:` namespace) and Google TSV. Microsoft TSV, plus the Google XML file that Microsoft also accepts. Optional `.gz` copy.
- **Feed management screen** (WC Product Engine → Product Feeds): list with search, platform/status filter, sorting, enable/disable, duplicate, delete, regenerate (publish now / full rebuild), copy URL, status, diagnostics and run history.
- **Create/Edit form**: platform/format, product filters, variation mode (one item per variation or one per parent), attribute mapping, price/tax, stock, images, shipping, market, update policy.
- **Item store and update model**: per-feed normalized item payloads with a generation. Five update modes: Manual Regenerate, On Change (dirty marks), Debounced Publish (2 min quiet / 5 min max wait / 15 min min interval), Scheduled Reconciliation (rolling slice, full coverage in about 24 h), Full Rebuild (create, important config change, drift/dirty escalation, operator request).
- **Worker**: one WP-CLI tick (`wp wpe feeds tick`) run by system cron every minute, a heartbeat, and a Health warning when the worker is not running.
- **Public endpoint**: token-protected feed URL served from the last valid file, with ETag/Last-Modified and no page-cache storage.
- **PDP deep link**: the product page opens on the variation named by `attribute_*` query arguments, so a feed item's link lands on that variation's price, stock and image.

Out of scope:
- Pushing items through the Merchant API or Content API (file feeds only).
- Multi-currency, multi-country price conversion, and computing WooCommerce shipping-zone rates.
- Tax-inclusive price computation for native items. The feed uses stored prices, and the validator warns when the market expects tax-inclusive prices.
- Grouped and external products (skipped with a diagnostic reason).

## 3. Decisions

- **D01 — Native read path, legacy CRUD only for legacy authority.** Items for native/native_bridge products are read in batches straight from `wpe_*` tables through `EffectivePriceResolver`, the inventory availability rule used by `CatalogProjector`, and `wpe_product_media`/`wpe_variant_media`/`wpe_assets`. Products whose authority is legacy are read through `wc_get_product()`. Postmeta is never read for native objects. A parity test compares the native items with `wc_get_product()` on a sample.
- **D02 — Permalinks come from the post shell.** WordPress routes the storefront on `wp_posts`, so `get_permalink()` is the correct URL source. Native products without a shell are skipped (`missing_shell`).
- **D03 — Normalized payload store.** `wpe_feed_items` keeps one format-neutral payload per item with `payload_hash`, `issues`, `eligible` and `next_change`. Publishing streams these rows in key order to a `.part` file. Rendering cost is paid only for items that changed.
- **D04 — Dirty marks are cheap and deferred.** Change hooks collect product IDs in memory and flush them to `wpe_feed_dirty` (per enabled feed) at shutdown or every 500 IDs. Nothing is rendered inside a web request. Lost marks are caught by reconciliation.
- **D05 — Change signals.** These all mark products dirty: `OutboxWorker::publishEvent()` (fires `wpe_aggregate_changed`), permanent purge in `ProductRepository::delete()`, term assignment (`set_object_terms`, `deleted_term_relationships`), term edits (`edited_term` on product_cat/product_tag/product_brand), `wpe_product_media_changed`, and WooCommerce product/stock hooks for legacy-authority products. Sale windows that open or close with no write are found through `next_change`.
- **D06 — Debounce.** A feed publishes when its item hashes changed, and either no change arrived for 2 min or the first unpublished change is 5 min old. Publishes are at least 15 min apart. All three values are configurable per feed. No publish happens when nothing changed.
- **D07 — Reconciliation and escalation.** Each tick re-renders the next slice of products (enough to cover the catalog in about 24 h), compares hashes and removes items whose product is gone or no longer matches the filters. A full rebuild is requested when the dirty set exceeds 20% of the items, or when drift in a completed cycle exceeds 1%.
- **D08 — Full rebuild by generation.** A full build writes a new item generation, then switches to it. The old generation and the last published file stay in use until the switch. Dirty marks are not consumed during a build and are processed after the switch.
- **D09 — Publish gate.** A `.part` file is checked before an atomic `rename()`: XML well-formedness through streaming XMLReader, TSV column count per line, a non-zero item count, and an item count drop of no more than 50% against the last good file (configurable). A failed gate keeps the previous file and records the reason.
- **D10 — Public URL.** `/wpe-feed/{feed_key}/{token}.{xml|tsv}[.gz]` (rewrite rule), with the query fallback `?wpe_feed={feed_key}&token=…`. A 32-byte random token is stored as a SHA-256 hash and can be rotated. The response sends `X-Accel-Expires: 0`, so the nginx FastCGI cache does not store multi-hundred-MB bodies.
- **D11 — Permissions.** Viewing feeds needs `manage_woocommerce`. Creating, editing, deleting and regenerating feeds needs `wpe_manage_operations`, because a feed publishes the catalog externally.
- **D12 — PDP deep link overlay.** Selection from `attribute_*` query arguments is applied per request on top of the cached selector config, and only for the queried product. Unknown keys or values are ignored. A selection the resolver cannot keep falls back to the normal opening state.
- **D13 — Market.** One target country, language and currency per feed, and the currency must equal the store currency. Shipping is either the Merchant Center account settings (default) or a flat per-feed override. `shipping_weight` is exported from product weight.
- **D14 — No new dependencies.** Plain PHP (XMLWriter, XMLReader), jQuery and the existing `.wpe-ui` kit. Tests use the existing `wp eval-file` harness against `test_wpe_bench`.

## 4. Documentation changes

- `docs/10-integrations.md` §12 (new): Merchant Center feed specification, attribute mapping, limits and validation rules.
- `docs/03-database-schema.md` §4: `feeds`, `feed_items`, `feed_dirty` tables; `contracts/database/schema.sql`, `contracts/database/schema-contract.json`.
- `docs/04-api-contract.md` §2 matrix and §10 (new) feed endpoints; `contracts/api/endpoints.json`, `contracts/openapi/wpe-v1.json`.
- `docs/09-ui.md` §11 (new) Product Feeds screens; §3 PDP deep-link note.
- `docs/08-operations.md` §3 feed worker cron, §8 feed alerts.
- `docs/NOTES.md` — P035 record.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P035.1",
      "title": "Document feed specification, schema, API, UI and update model contracts",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "docs/03-database-schema.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "docs/10-integrations.md",
        "docs/08-operations.md"
      ],
      "files": [
        "docs/10-integrations.md",
        "docs/03-database-schema.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "docs/08-operations.md",
        "contracts/database/schema.sql",
        "contracts/database/schema-contract.json",
        "contracts/api/endpoints.json",
        "contracts/openapi/wpe-v1.json"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    },
    {
      "id": "P035.2",
      "title": "Open the product page on the variation named by attribute query arguments",
      "depends_on": [],
      "read_first": [
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "assets/variant-selector/selector.js",
        "tests/integration/AutoSelectFirstValuesTest.php"
      ],
      "files": [
        "src/Compatibility/StoreApi/SelectorAdapter.php",
        "tests/integration/PdpUrlSelectionTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/StoreApi/SelectorAdapter.php"],
        ["php", "-l", "tests/integration/PdpUrlSelectionTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/PdpUrlSelectionTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/AutoSelectFirstValuesTest.php"]
      ]
    },
    {
      "id": "P035.3",
      "title": "Add feed tables, feed repository, config normalization and admin REST CRUD",
      "depends_on": ["P035.1"],
      "read_first": [
        "contracts/database/schema.sql",
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Admin/Rest/AdminApiController.php",
        "src/Support/Capabilities.php",
        "src/Support/RestErrors.php"
      ],
      "files": [
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Application/Feeds/FeedConfig.php",
        "src/Application/Feeds/FeedRepository.php",
        "src/Admin/Rest/FeedsApiController.php",
        "src/Bootstrap/PluginBootstrap.php",
        "tests/integration/FeedsApiTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Feeds/FeedConfig.php"],
        ["php", "-l", "src/Application/Feeds/FeedRepository.php"],
        ["php", "-l", "src/Admin/Rest/FeedsApiController.php"],
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["php", "-l", "tests/integration/FeedsApiTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedsApiTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/SchemaVersionTest.php"]
      ]
    },
    {
      "id": "P035.4",
      "title": "Build feed items from native and legacy sources with mapping and validation",
      "depends_on": ["P035.3"],
      "read_first": [
        "src/Application/Catalog/CatalogProjector.php",
        "src/Application/Variant/EffectivePriceResolver.php",
        "src/Compatibility/Gallery/GalleryAdapter.php",
        "src/Compatibility/Gallery/ImageSurfaces.php",
        "src/Support/AuthorityResolver.php"
      ],
      "files": [
        "src/Application/Feeds/FeedItemSource.php",
        "src/Application/Feeds/FeedMapper.php",
        "src/Application/Feeds/FeedValidator.php",
        "tests/integration/FeedItemSourceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Feeds/FeedItemSource.php"],
        ["php", "-l", "src/Application/Feeds/FeedMapper.php"],
        ["php", "-l", "src/Application/Feeds/FeedValidator.php"],
        ["php", "-l", "tests/integration/FeedItemSourceTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedItemSourceTest.php"]
      ]
    },
    {
      "id": "P035.5",
      "title": "Store item generations, run full rebuilds and publish validated feed files",
      "depends_on": ["P035.4"],
      "read_first": [
        "src/Application/ImportExport/SnapshotExporter.php",
        "src/Application/Feeds/FeedRepository.php",
        "src/Application/Feeds/FeedItemSource.php"
      ],
      "files": [
        "src/Application/Feeds/FeedItemStore.php",
        "src/Application/Feeds/FeedWriter.php",
        "src/Application/Feeds/FeedBuilder.php",
        "src/Cli/Commands.php",
        "tests/integration/FeedBuildPublishTest.php",
        "tests/performance/FeedBuildBenchmark.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Feeds/FeedItemStore.php"],
        ["php", "-l", "src/Application/Feeds/FeedWriter.php"],
        ["php", "-l", "src/Application/Feeds/FeedBuilder.php"],
        ["php", "-l", "src/Cli/Commands.php"],
        ["php", "-l", "tests/integration/FeedBuildPublishTest.php"],
        ["php", "-l", "tests/performance/FeedBuildBenchmark.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedBuildPublishTest.php"]
      ]
    },
    {
      "id": "P035.6",
      "title": "Mark changed products dirty and publish debounced incremental updates",
      "depends_on": ["P035.5"],
      "read_first": [
        "src/Infrastructure/Database/OutboxWorker.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "src/Application/Catalog/CatalogProjector.php",
        "src/Compatibility/WooDataStore/ProductLifecycle.php"
      ],
      "files": [
        "src/Application/Feeds/FeedChangeTracker.php",
        "src/Application/Feeds/FeedScheduler.php",
        "src/Infrastructure/Database/OutboxWorker.php",
        "src/Infrastructure/Database/ProductRepository.php",
        "tests/integration/FeedIncrementalTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Feeds/FeedChangeTracker.php"],
        ["php", "-l", "src/Application/Feeds/FeedScheduler.php"],
        ["php", "-l", "src/Infrastructure/Database/OutboxWorker.php"],
        ["php", "-l", "src/Infrastructure/Database/ProductRepository.php"],
        ["php", "-l", "tests/integration/FeedIncrementalTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedIncrementalTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductPurgeOutboxTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/OutboxProjectionWorkerTest.php"]
      ]
    },
    {
      "id": "P035.7",
      "title": "Reconcile feed items on a rolling schedule, escalate drift and record worker heartbeat",
      "depends_on": ["P035.6"],
      "read_first": [
        "src/Application/Feeds/FeedScheduler.php",
        "src/Application/Feeds/FeedItemStore.php"
      ],
      "files": [
        "src/Application/Feeds/FeedScheduler.php",
        "tests/integration/FeedReconcileTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Feeds/FeedScheduler.php"],
        ["php", "-l", "tests/integration/FeedReconcileTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedReconcileTest.php"]
      ]
    },
    {
      "id": "P035.8",
      "title": "Serve feed files from a token-protected public URL",
      "depends_on": ["P035.5"],
      "read_first": [
        "src/Application/Feeds/FeedRepository.php",
        "src/Bootstrap/PluginBootstrap.php"
      ],
      "files": [
        "src/Application/Feeds/FeedEndpoint.php",
        "src/Bootstrap/PluginBootstrap.php",
        "tests/integration/FeedEndpointTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Feeds/FeedEndpoint.php"],
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["php", "-l", "tests/integration/FeedEndpointTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedEndpointTest.php"]
      ]
    },
    {
      "id": "P035.9",
      "title": "Build the Product Feeds list and Create/Edit admin screens",
      "depends_on": ["P035.3", "P035.5", "P035.8"],
      "read_first": [
        "docs/09-ui.md",
        "src/Admin/SettingsPage.php",
        "assets/admin/admin-ui.css",
        "assets/admin/settings.js",
        "src/Bootstrap/PluginBootstrap.php"
      ],
      "files": [
        "src/Admin/FeedsPage.php",
        "src/Admin/Rest/FeedsApiController.php",
        "src/Bootstrap/PluginBootstrap.php",
        "assets/admin/feeds.js",
        "assets/admin/feeds.css",
        "tests/integration/FeedsPageTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Admin/FeedsPage.php"],
        ["php", "-l", "src/Admin/Rest/FeedsApiController.php"],
        ["php", "-l", "src/Bootstrap/PluginBootstrap.php"],
        ["node", "--check", "assets/admin/feeds.js"],
        ["php", "-l", "tests/integration/FeedsPageTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedsPageTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedsApiTest.php"]
      ]
    },
    {
      "id": "P035.10",
      "title": "Surface feed health on Overview and record operations notes",
      "depends_on": ["P035.7", "P035.9"],
      "read_first": [
        "src/Admin/SettingsPage.php",
        "docs/08-operations.md"
      ],
      "files": [
        "src/Admin/SettingsPage.php",
        "tests/integration/FeedHealthTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Admin/SettingsPage.php"],
        ["php", "-l", "tests/integration/FeedHealthTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/FeedHealthTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/SettingsApiTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
