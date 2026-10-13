# P011 — Per-product media library and variant image picker

## 1. Goal

Give every product its own image library that is a list of links in the database, backed by files that live on the system default storage (S3, local or another provider) or by external CDN URLs. Add managed upload through the default storage, a library UI with thumbnail grid, featured image, gallery order, alt text and upload states, and a variant image picker drawer that only offers the product's own images. Gallery, featured image and variant images join the same Update and Publish draft as the rest of the product editor.

The backend enforces the rules (permissions, file checks, membership of media in the product, no silent storage fallback, safe unlink and delete). The UI is only a client of those rules.

Repository findings, risks and the verdict are in `docs/audits/P011-pre-implementation-audit.md`.

## 2. Scope

In scope:
- Managed image upload through the system default storage, with an external URL path kept for CDN images. Reuse the existing asset service, media repository, attachment bridge and gallery adapter; the only new storage code is a thin provider layer around the sibling storage plugin's adapters and a local provider.
- New data for managed files and upload intents, additive tables only.
- Product library service: list, add, remove link, reorder, featured, alt text, per-product alt override, impact preview, membership validation, compare and swap on the product version.
- REST routes already named in the contract (`POST /admin/assets`, `PATCH /admin/products/{id}/media`) plus preview, claim and file delete routes.
- Variant image assignment by asset id with membership checks; assignment to one variation, selected rows or an option group with a count preview.
- Library block and variant picker drawer in the product editor, draft and dirty state, retry, leave warning.
- Replacement of the native Product image and Product gallery controls on supported products and a single writer guard.
- Orphan cleanup, explicit file delete operation, provider switch regression, CLI report command.

Out of scope:
- Changes to WordPress or WooCommerce core, or to the storage plugin's own code.
- Presigned direct browser upload (the storage plugin has no presign capability; see D01).
- Moving or rewriting old files and URLs when the default storage changes.
- Image editing, cropping, regeneration, video and non-image files, CDN transformations.
- Import and export changes, and product types other than the ones the editor already supports.

## 3. Decisions

Defaults are applied unless the user overturns them. D02, D03, D12 and D13 are the ones most likely to need a decision.

- **D01: Server side upload.** The browser sends the file to a REST route and the backend streams it to the provider. Credentials never reach the browser. A presigned flow is deferred until the storage layer exposes presigning; when it does, URLs must be short lived, scoped to one object key and verified by the backend before the asset is created.
- **D02: Use the storage plugin's adapters directly, not the WordPress media pipeline.** The provider layer calls the adapter factory and settings of the sibling storage plugin when its classes are loaded. This gives a synchronous upload followed by an existence and size check, which is the required order (confirm, then create the record). The plugin's own async offload flow (local file first, queued sync) stays untouched for the normal WordPress media library. Default storage is: the plugin's settings say enabled, then that provider; otherwise the local uploads directory. If the settings say enabled but the classes are not loaded or the adapter is not configured, the upload fails with a clear storage unavailable error. There is never an automatic switch to local on failure.
- **D03: New additive tables, no change to existing columns.** `asset_storage` (one row per managed file: asset id, storage provider, bucket, object key, file name, size in bytes, content hash, etag if provided, state, uploader, timestamps) and `upload_items` (one row per upload attempt: unique upload key per user, session token, product id or null, state, object key, asset id, error text, timestamps). A managed asset is any asset that has an `asset_storage` row; every other asset is external. The existing `assets.provider` stays the CDN rendition provider name. Reason: the migrator can only create tables, and the live assets table holds about two thousand rows.
- **D04: Idempotency.** The client generates one upload key per file and reuses it on retry. The object key derives from the content hash and a sanitized file name, so a repeated upload writes the same object, and asset registration deduplicates by full URL. A retry after a partial failure resumes from the stored intent and never creates a second asset or a second link.
- **D05: Library shape.** The library is the product's `product_media` rows: at most one featured and an ordered gallery. Setting a new featured image moves the old featured one to the front of the gallery instead of dropping it. Assets that are only linked to a variation (found in legacy data) are listed as variant-only with an explicit action to add them to the product; an unchanged legacy variant image can still be saved without being added.
- **D06: Membership rule.** A variation image can only be set to an asset that is in the product's library, or that is being linked in the same save request. The service rejects any other asset id with a typed error. URL input for a variation resolves to an existing library asset by URL key or is rejected; the editor no longer registers new assets through the variation path.
- **D07: Safe unlink.** Removing an image deletes only link rows. The preview lists every place that uses the asset (featured, gallery, variations). Apply requires, for each affected place, a replacement asset from the library or an explicit confirmation to fall back to the product image or placeholder. The change is atomic. No file is touched.
- **D08: Deleting a file is a separate operation.** Only managed assets, only for an actor with the delete capability and upload capability, only when no product, variation, bridge attachment or retention hold references the asset. External assets are never deleted by storage. Shared assets are never removed while another product links them. The result lists blocking references when refused.
- **D09: Storage provider switches affect new uploads only.** Each asset keeps its stored provider, bucket, key and URL. Resolvers and the editor read the stored values and never rebuild a URL from current settings.
- **D10: Failure handling.** Upload failure creates no asset row, marks the upload item failed with the provider message and offers retry. If the object is stored but the database write fails, the upload item stays in the stored state with its object key, a retry registers the asset without uploading again, and a scheduled cleanup removes stored objects that never became assets after a retention window (default 48 hours, configurable), after re-checking that nothing references them.
- **D11: Draft and unsaved products.** The editor draft carries a media section (library order, featured, alt edits, pending uploads, variant image map). Uploads may finish before Update; the asset exists but is flagged pending link until Update links it. Update saves the media section in the same database transaction as the product, through the unified save of the editor. Discarding the draft never deletes files. A new product has an auto-draft ID in the classic editor, so uploads are scoped to that ID; products created without any ID use a session token that the creation call claims.
- **D12: WooCommerce integration through the existing bridge.** The library tables stay the source. The legacy mirror keeps writing the featured and gallery post meta with real attachment IDs from the attachment bridge; no fake IDs or local paths. The bridge must mark attachments it creates as not syncable by the storage plugin so no queued sync fails on a missing local file (verified with a stub of the plugin's hooks).
- **D13: Native image boxes are replaced.** On supported products the native Product image and Product gallery boxes are hidden and, when the library payload is present, the posted thumbnail and gallery keys are removed before WooCommerce saves, so the library is the single writer. A test fails if the legacy path writes these fields. Other product types keep the native boxes.
- **D14: File rules.** Allowed types come from the existing allowed image mime check (jpeg, png, gif, webp, avif); SVG is rejected. The type is verified by sniffing the content and extension, not the browser mime. Size limit is the smaller of the WordPress upload limit and a plugin setting (default 10 MB). Width and height come from the file. File names are sanitized and not trusted.
- **D15: Security.** REST routes require the cookie nonce, the upload capability and the product edit capability on the product; upload rate is limited per user; credentials stay inside the storage plugin's classes and are never logged, returned or written to tests. Tests use a fake provider. A live round trip against real storage is a separate gated task.
- **D16: Sequencing with P010.** Backend tasks 1 to 8 do not conflict with P010. The editor script and service are shared files, so tasks 9 and 10 start after P010 is closed, or after the user drops P010, and then target whichever editor structure is current. The unified save of P010 is used for the media section when present; otherwise the existing hidden payload is extended.
- **D17: Recovery.** The library block can be switched off with the editor mode option; then the native image boxes return and the hidden library payload is ignored. Data stays valid in that mode because link rows and asset rows are the same ones WooCommerce is mirrored from. New tables are additive and ignored by older code.

## 4. Documentation changes

- `docs/02-domain.md`: managed files are in V1 scope next to external URLs; managed versus external definition; unlink versus file delete rules.
- `docs/03-database-schema.md`: the two new tables, the relation to assets, the rules for deleting files and retention.
- `docs/04-api-contract.md`: upload and library routes, request and response shapes, error codes (storage unavailable, file rejected, asset not in product, impact requires resolution, conflict), permissions.
- `docs/09-ui.md`: library block, upload states, variant picker drawer, impact dialog.
- `docs/10-integrations.md`: default storage resolution, the storage plugin coupling, no silent fallback, provider switch behavior.
- `contracts/database/schema.sql`
- `contracts/database/schema-contract.json`
- `contracts/api/endpoints.json`
- `contracts/openapi/wpe-v1.json`
- `docs/NOTES.md`
- `docs/QUESTIONS.md`
- `docs/audits/P011-pre-implementation-audit.md`
- `docs/plans/P011-product-media-library/plan.md`
- `docs/plans/P011-product-media-library/report.md`

## 5. Tasks

Acceptance map: A1 default S3 upload really stores on S3 (3, 4, 12); A2 uploaded image appears in the right product library (4, 5, 6); A3 usable as featured, gallery and variant image (5, 7); A4 picker shows only this product's images (7, 10); A5 backend rejects media not linked to the product (7); A6 upload errors retry without silent fallback (3, 4, 9); A7 provider switch keeps old images (3, 11); A8 unlink does not delete the file (5, 11); A9 impact preview before removal (5, 9); A10 save and reload keep gallery, order and variant images (6, 7, 9); A11 unsaved product and mid-upload failure recovery (4, 11).

```json
{
  "tasks": [
    {
      "id": "P011.1",
      "title": "Contract amendment: managed files, new tables, upload and library routes",
      "depends_on": [],
      "read_first": ["docs/02-domain.md", "docs/03-database-schema.md", "docs/04-api-contract.md", "docs/10-integrations.md", "docs/audits/P011-pre-implementation-audit.md"],
      "files": [
        "docs/02-domain.md",
        "docs/03-database-schema.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "docs/10-integrations.md",
        "contracts/database/schema.sql",
        "contracts/database/schema-contract.json",
        "contracts/api/endpoints.json",
        "contracts/openapi/wpe-v1.json",
        "docs/NOTES.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.2",
      "title": "Schema for managed files and upload items, repository methods",
      "depends_on": ["P011.1"],
      "read_first": ["docs/03-database-schema.md"],
      "files": [
        "src/Infrastructure/Database/SchemaMigrator.php",
        "src/Infrastructure/Database/MediaRepository.php",
        "src/Infrastructure/Database/UploadItemRepository.php",
        "tests/integration/MediaSchemaTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaSchemaTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.3",
      "title": "Storage provider layer: resolver, storage plugin adapter, local provider, no silent fallback",
      "depends_on": ["P011.2"],
      "read_first": ["docs/10-integrations.md", "docs/04-api-contract.md"],
      "files": [
        "src/Application/Media/Storage/StorageProviderInterface.php",
        "src/Application/Media/Storage/StoragePluginProvider.php",
        "src/Application/Media/Storage/LocalStorageProvider.php",
        "src/Application/Media/Storage/StorageResolver.php",
        "tests/integration/StorageResolverTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/StorageResolverTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.4",
      "title": "Managed upload service: validation, upload, verification, idempotent registration, upload intents",
      "depends_on": ["P011.3"],
      "read_first": ["docs/02-domain.md", "docs/04-api-contract.md"],
      "files": [
        "src/Application/Media/ManagedUploadService.php",
        "src/Compatibility/Gallery/AssetService.php",
        "src/Compatibility/Gallery/AttachmentBridge.php",
        "tests/integration/ManagedUploadTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ManagedUploadTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/StorageResolverTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.5",
      "title": "Product library service: list, link, reorder, featured, alt, impact preview, safe unlink, membership",
      "depends_on": ["P011.4"],
      "read_first": ["docs/03-database-schema.md", "docs/04-api-contract.md"],
      "files": [
        "src/Application/Media/ProductMediaLibrary.php",
        "src/Infrastructure/Database/MediaRepository.php",
        "tests/integration/ProductMediaLibraryTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductMediaLibraryTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.6",
      "title": "REST routes: upload, URL add, library read and patch, unlink preview, session claim",
      "depends_on": ["P011.5"],
      "read_first": ["docs/04-api-contract.md", "contracts/api/endpoints.json"],
      "files": [
        "src/Admin/ProductEditor/ProductMediaApiController.php",
        "src/Bootstrap/PluginBootstrap.php",
        "tests/integration/ProductMediaRestTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductMediaRestTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.7",
      "title": "Variant image assignment by asset id, membership enforcement, group and selection preview, editor save integration",
      "depends_on": ["P011.6"],
      "read_first": ["docs/04-api-contract.md", "docs/02-domain.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorService.php",
        "src/Admin/ProductEditor/ProductEditorApiController.php",
        "tests/integration/VariantImagePickerRulesTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/VariantImagePickerRulesTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorServiceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MirrorWorkerEpochTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.8",
      "title": "Native image box replacement and single writer guard for thumbnail and gallery keys",
      "depends_on": ["P011.7"],
      "read_first": ["docs/09-ui.md", "docs/04-api-contract.md"],
      "files": [
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "tests/integration/MediaLegacyWriterGuardTest.php",
        "tests/integration/ProductEditorBridgeTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaLegacyWriterGuardTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorBridgeTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.9",
      "title": "Product library block UI: grid, upload states and retry, URL add, featured, order, alt, impact dialog, draft",
      "depends_on": ["P011.8"],
      "read_first": ["docs/09-ui.md", "docs/plans/P010-two-block-product-ui/plan.md"],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css",
        "src/Admin/ProductEditor/ProductEditorBridge.php",
        "tests/e2e/ProductMediaLive.mjs"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "tests/e2e/ProductMediaLive.mjs"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.10",
      "title": "Variant picker drawer: product images only, selection mark, one, selected rows or option group, count preview, add image",
      "depends_on": ["P011.9"],
      "read_first": ["docs/09-ui.md"],
      "files": [
        "assets/admin/product-editor.js",
        "assets/admin/product-editor.css",
        "tests/e2e/VariantImagePickerLive.mjs"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "tests/e2e/VariantImagePickerLive.mjs"],
        ["node", "tests/e2e/ProductMediaLive.mjs"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.11",
      "title": "Orphan cleanup job, explicit file delete, provider switch regression, CLI report, closeout docs",
      "depends_on": ["P011.10"],
      "read_first": ["docs/PROGRESS.md", "docs/08-operations.md"],
      "files": [
        "src/Application/Media/OrphanCleanup.php",
        "src/Cli/Commands.php",
        "tests/integration/MediaRecoveryTest.php",
        "docs/NOTES.md",
        "docs/QUESTIONS.md",
        "docs/plans/P011-product-media-library/plan.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/MediaRecoveryTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/ProductEditorE2ETest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    },
    {
      "id": "P011.12",
      "title": "Gated live storage round trip: real upload, verify, delete test object",
      "depends_on": ["P011.11"],
      "read_first": ["docs/QUESTIONS.md", "docs/10-integrations.md"],
      "files": [
        "tests/integration/StorageLiveRoundTripTest.php"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "eval-file", "tests/integration/StorageLiveRoundTripTest.php"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```

Test and recovery notes:
- PHP tests run with assertions on and use a fake storage provider. They cover: validation rejects bad type, oversize and fake mime; failed upload leaves no asset row; stored then database failure resumes without re-upload; retry creates one asset and one link; membership rejection for an asset of another product; unlink preview and replacement requirement; file delete refused for shared, external or referenced assets; provider switch leaves old rows and URLs unchanged; native key removal on supported products.
- The live browser suite covers: upload success, failure with retry, no silent switch, featured and gallery order after reload, alt edit, impact dialog before removal, picker listing only this product's images, assignment to one row, selected rows and an option group with the preview count, and an unsaved new product with upload before first save.
- Task 12 is the only task that talks to real storage. It reads the default storage settings at run time, uploads a small generated test image, checks it exists and its size, reads it back through the stored URL, deletes the test object and the asset rows, and fails (does not skip) when the storage plugin is not loadable or not configured. It runs only after the user authorizes it and the storage plugin is loadable; until then it stays blocked with an entry in the questions file. Docs checks and the fake provider tests are not S3 certification.
- Rollback per task is a local commit revert; runtime rollback is D17.
