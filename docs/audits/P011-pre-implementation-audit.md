# P011 pre-implementation audit

Read-only audit for plan P011 (per-product media library and variant image picker). Date: 2026-10-13. Source and database inspection only; not a WooCommerce or S3 integration certification.

## 1. Method

- Read AGENTS.md, docs/02-domain.md, docs/03-database-schema.md, docs/04-api-contract.md, docs/09-ui.md, docs/10-integrations.md, contracts/api/endpoints.json, contracts/database/schema.sql.
- Read the gallery code (`src/Compatibility/Gallery/AssetService.php`, `AttachmentBridge.php`, `GalleryAdapter.php`), `src/Infrastructure/Database/MediaRepository.php`, `SchemaMigrator.php`, the product editor service and the editor script.
- Inspected live tables and option names with WP-CLI, and the sibling storage plugin found under the plugins directory (`images-space`, plugin name "WP Object Storage Offload").

## 2. Findings

### F01 There is no upload path in this plugin

The only file upload handled by the plugin is the private CSV stage for imports. Nothing here accepts an image file, talks to S3 or writes to the uploads directory. Docs/02 states images are external URLs by default and no download or upload to the uploads directory is required; docs/10 states file materialization is not in V1. Managed uploads therefore need a contract change, not just code.

### F02 The "existing storage mechanism" is the sibling plugin, and it is not loadable on this site

- The storage plugin sits at a nested path (`plugins/images-space/images-space/images-space.php`). WordPress lists no such plugin, so its classes are not loaded.
- Its settings option exists and says enabled with provider S3 and a bucket and region configured. So the site's stored default is "S3 on", while no code can currently run it.
- The plugin offers adapters with `upload`, `exists`, `getMetadata`, `getUrl`, `delete`, `testConnection`, `isConfigured`, `getLastError` for S3, Spaces and MinIO. It has no presigned URL support.
- Its normal flow is asynchronous: local WordPress upload first, then a queued sync, then URL rewriting through attachment meta. That flow creates the media record before the remote upload is confirmed, which is the opposite of the required order (confirm, then create record).
- Its URL rewriter builds S3 URLs for old attachments from the stored provider and bucket, so it already keeps old files on the provider they were synced to.
- Credentials are stored as plain text in the options table and an earlier command of this audit printed that option to the terminal. This plan never reads credentials into the repository, docs, tests or logs. The key shown in this session should be treated as exposed and rotated.

### F03 Contract endpoints exist on paper only

Docs/04 and the endpoints contract already define `POST /admin/assets` (URL plus metadata to asset ID, permission upload_files plus engine edit policy) and `PATCH /admin/products/{id}/media` (ordered references, featured asset and variation mappings, compare and swap on the parent, no half update on an invalid reference). Neither route is registered in `src`.

### F04 Media model

- `assets`: id, kind, source URL, URL key hash, provider (this is the CDN provider name used for renditions, default value `default`), provider key, mime, width, height, alt, title, caption, attachment id (unique), delivery policy, renditions, health, version. Live data: 2007 assets, all provider `default`, all with an attachment id. Deduplication is by URL key plus full URL, so a deterministic URL makes registration idempotent.
- No columns for size in bytes, original file name, bucket, object key owned by the system, content hash, or whether the system manages the file.
- `product_media`: role featured or gallery, position, per-product alt override. Live data: 2011 featured and 5 gallery rows. Unique on product, role and position.
- `variant_media`: primary flag, position, alt override.
- `SchemaMigrator::migrate` only issues create-if-not-exists statements. It cannot add columns to an existing table. New columns on `assets` would need a new migration mechanism; new tables work with the current one.

### F05 The editor's variant image picker is not scoped to the product

The variants editor opens the global WordPress media frame and assigns by URL. `ProductEditorService::attachVariantImage` registers any URL as a new asset and attaches it. Nothing checks that the asset belongs to the product's own images, and the group assignment dialog works the same way. This violates the new rule that the backend must verify membership.

### F06 WooCommerce mapping

`MetaBridge` maps the featured image prop and the gallery prop to asset ids; `LegacyMirror` writes the featured and gallery post meta using real attachment ids produced by `AttachmentBridge::getOrCreateAttachmentId` (row-locked, one attachment per asset). The native Product image and Product gallery meta boxes still post the thumbnail and gallery keys through WooCommerce's own save, so they are a second writer once the library owns these fields. The new library must be the single writer on supported products.

### F07 Interaction between attachments created by the bridge and the storage plugin

The bridge creates attachment posts for assets without any local file. When the storage plugin is active, an added image attachment is marked pending and an attachment metadata update queues a sync, which then fails for lack of a local file and records an error on the attachment. That would show as noisy failures and could confuse status. Needs a test with the plugin's classes stubbed and a mitigation that marks bridge attachments as not syncable.

### F08 Classic editor and unsaved products

In the classic editor a new product already has an auto-draft post ID before the first save. Uploads can therefore be scoped to that ID. Products created without any ID (programmatic or REST create) need a session token claimed at creation.

### F09 Environment

PHP upload limits on the command line are small (2M per file, 8M per request, WordPress max 2 MB); the web server limits must be measured separately in the live run. Upload size and type limits must come from configuration and not from assumptions. The flag table must remain unchanged by tests.

## 3. Risks and mitigations

| Risk | Mitigation (task) |
| --- | --- |
| Silent fallback to local storage when S3 fails | storage resolver returns an explicit error and never selects another provider (task 3) |
| Upload confirmed remotely but database write fails | intent row before upload, state advance after verification, idempotent retry with the same key, scheduled cleanup of unreferenced stored objects (tasks 4, 11) |
| Duplicate media or links on retry | client upload key unique per user, deterministic object key from content hash, URL based asset deduplication, link insert guarded by primary key (tasks 4, 5) |
| Variant assigned an image of another product | membership check in the service, not only the UI (task 7) |
| Removing an image breaks featured or variant images | preview of affected places, mandatory replacement or explicit fallback, atomic apply (task 5) |
| Deleting a shared or external file | separate delete operation, managed assets only, full reference scan, never for external URLs (task 11) |
| Changing default storage rewrites old images | stored provider, bucket, key and URL per asset; resolvers never recompute from current settings (tasks 3, 11) |
| Dual writer on thumbnail and gallery keys | native boxes hidden and posted keys removed on supported products (task 8) |
| Storage plugin not loadable | resolver reports storage unavailable; prerequisite listed in plan section 3 |
| Credentials leak | credentials stay inside the storage plugin classes, tests use a fake adapter, live round trip is gated (tasks 3, 12) |

## 4. Verdict

Backend work (tasks 1 to 8) can start after approval and does not need S3. UI tasks (9, 10) must be sequenced after plan P010 or after the user decides to drop it, because both edit the editor script and service. The live S3 round trip (task 12) needs the storage plugin to be loadable and the user's explicit authorization.
