# P017 — Batched legacy meta writes and skip unchanged products on import

## 1. Goal

Finish the two import follow-ups left open by P015 (options E and F):

- E: the legacy mirror (`LegacyMirror::mirrorAggregate()`) writes the same `wp_posts`/`wp_postmeta` result with far fewer queries. Measured before P017 on a 102-variant product: 3,046 queries, about 30 per variant, mostly one SELECT plus one UPDATE/INSERT per meta key.
- F: re-importing a Shopify CSV skips products whose input is identical to the last successful import and that nobody changed since, instead of rewriting every product, variant, image link and legacy row.

Imported data, authority, epoch fencing, outbox events for real changes and Resume/Retry idempotency stay unchanged.

## 2. Scope

In scope:
- `LegacyMirror`: load the existing meta of the product and all its active variations in one query, write only missing or changed values (multi-row INSERT, one UPDATE per changed row), and batch the per-variation reads (post existence, inventory, attribute values, primary image) into one query each.
- `ShopifyBatchImporter`: a per-product import fingerprint, checked before any write, and stored after a successful import and mirror.
- A test proving the mirror result is identical before and after the change, and tests for the skip rules.

Out of scope:
- The catalog generation rebuild at the end of each job (`CatalogProjector::buildNewGeneration()`). It re-projects the whole catalog, so its cost grows with the catalog, not with the file. Measured at 68% of the queries of a 3-product import on a 299-product test catalog. Recorded as a follow-up.
- Importer-side per-variant writes (option combination vectors, variant primary images), and `LegacyCapture::computeLegacyHash()`.
- Schema changes and admin UI changes.

## 3. Decisions

- **D01: Mirror output is byte-identical.** For every post the mirror touches, the set of `(post_id, meta_key, meta_value)` rows, post fields and term relationships after the mirror is the same as before P017. Only `post_modified` timestamps may differ. Verified by wiping and re-mirroring sample products with old and new code and comparing.
- **D02: Same row semantics as `updatePostMetaDirect()`.** When a key has several rows, the row with the lowest `meta_id` is the one updated, as before. A missing key is inserted. Values that already match are not written. The object cache for each touched post is still cleared.
- **D03: Fingerprint content.** SHA-256 over the parsed product group (handle, title, description, status, options, images, every variant row's fields) without file positions (row numbers, byte offsets), plus the job's `update_mode` and `image_mode`, plus `WPE_VERSION` and an importer format constant. A plugin update or a mapping change therefore never reuses an old fingerprint.
- **D04: Skip rule.** A product group is skipped only when all hold: the product resolves by handle/SKU to an existing, not-deleted product; a stored fingerprint for that product equals the new one; the product's native `version` equals the version stored with the fingerprint (no edit, stock change or other import since); and its object state has no pending mirror (`native_applied >= native_revision`). Otherwise it is imported as before.
- **D05: Fingerprint storage.** One `wpe_control` row per product, key `shopify_import_fp:<product_id>`, value JSON `{hash, version, variant_count, type, title}`. No schema change. Written only after the repository writes and a `mirrored` mirror result, so an interrupted, failed or fenced product is never skipped later.
- **D06: Escape hatch.** Job config `skip_unchanged` (default `true`). `false` imports every product as before. No UI in P017.
- **D07: Counting.** Skipped products still count in `products_processed` and `variants_processed` (stored variant count) and add to a new `products_skipped` count. The log line says `[SKIP]`.

## 4. Documentation changes

- `docs/03-database-schema.md` §`control` row: also stores per-product Shopify import fingerprints.
- `docs/10-integrations.md` §update modes: unchanged products are skipped on re-import.
- `docs/NOTES.md`: P017 entry with benchmark numbers.
- `docs/plans/P017-import-mirror-batch-skip/plan.md`
- `docs/plans/P017-import-mirror-batch-skip/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P017.1",
      "title": "Batched meta writes and reads in LegacyMirror with identical output",
      "depends_on": [],
      "read_first": [
        "docs/02-domain.md",
        "src/Application/Migration/LegacyMirror.php"
      ],
      "files": [
        "src/Application/Migration/LegacyMirror.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyMirror.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T39Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/T40Test.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/MirrorWorkerEpochTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"]
      ]
    },
    {
      "id": "P017.2",
      "title": "Skip unchanged products on Shopify re-import",
      "depends_on": ["P017.1"],
      "read_first": [
        "docs/10-integrations.md",
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"
      ],
      "files": [
        "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php",
        "tests/integration/ImportPerformanceTest.php",
        "docs/03-database-schema.md",
        "docs/10-integrations.md"
      ],
      "dod": [
        ["php", "-l", "src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["/usr/local/bin/wp", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"]
      ]
    },
    {
      "id": "P017.3",
      "title": "Benchmark after P017, notes and report",
      "depends_on": ["P017.2"],
      "read_first": [
        "docs/plans/README.md"
      ],
      "files": [
        "docs/NOTES.md",
        "docs/plans/P017-import-mirror-batch-skip/report.md"
      ],
      "dod": [
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/plan.mjs", "check"]
      ]
    }
  ]
}
```
