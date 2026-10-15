# 04 — API contract

## 1. Conventions

### 1.1 Namespace và schema

Engine REST namespace `/wp-json/wpe/v1`. OpenAPI draft artifact `contracts/openapi/wpe-v1.json` seed được seal/cross-check Woo methods ở T03. WC REST `/wc/v3` và Store API không đổi public schema; adapters tại 10 §2.

### 1.2 IDs, money và timestamps

Engine IDs/revisions decimal string, amount decimal string/null, timestamps UTC RFC3339. DTO `variant.id` string; adapter Woo trả native types version pin. Không uint64→JS Number làm mất precision.

### 1.3 Pagination và queries

Public list max100, default48; variation list100. Signed cursor gắn filter hash/sort/generation/epoch/expiry. Query semantics tại 02 §9; invalid/tampered cursor trả invalid_request. Facet_semantics wc-compatible (default) hoặc variant-coherent, trả lại mode trong response. Count_mode none/exact; exact count timeout trả index_unavailable, không giả total0.

### 1.4 Null, absent và patch

PATCH chỉ sửa trường hiện diện; null chỉ field nullable. Collections phải explicit merge/replace; missing variants không delete. Empty string không tự chuyển price0. Dialect CSV khác PATCH tại 10 §6.

### 1.5 Idempotency và concurrency

Admin mutation commands có Idempotency-Key; create asset cùng body key reuse result. Update/delete requires If-Match version. Parent nested variant create/media update dùng parent version, variation patch dùng variation version và aggregate lock. Gate/cutover CAS expected_epoch + manifest_hash. Body mismatch cùng key→idempotency_conflict; missing precondition→precondition_required.

### 1.6 Error envelope

`{"code":"validation_failed","message":"Invalid selection","details":[{"pointer":"/selection/101","reason":"wrong_product"}],"request_id":"uuid","retryable":false}`.

### 1.7 Cache/rate limits

Catalog ETag generation/context; resolver no-store, canonical checkout revalidate. Default anonymous resolver limit60/minute/IP and catalog120/minute/IP, configurable; shared NAT không coi limit là fraud verdict. 429 có Retry-After. DoS bounds selection size/options limit cấu hình và query whitelist. Không cache cost/private/nonpublic variants.

### 1.8 Authentication và capabilities

| Capability | Default roles | Scope |
|---|---|---|
| WP edit_products + edit_post | WC roles có quyền native | Product CRUD object-level |
| WP upload_files + edit_post | Roles đủ hai quyền | Asset attach/media update |
| wpe_import_products | administrator only ban đầu | Imports/migrations dry-run data |
| wpe_export_products | administrator only ban đầu | Private CSV exports |
| wpe_manage_operations | administrator only ban đầu | Health/migrations/cutover/rollback/cleanup |

WP session + nonce hoặc Application Passwords HTTPS; custom caps có thể cấp Shop Manager explicit, không mặc nhiên nâng quyền. Job download chỉ creator còn cap tương ứng hoặc operations admin. Public resolver/catalog chỉ published visibility. Worker recheck actor/cap at import commit, migration operator lost permission→blocked.

### 1.9 Error codes

| Code | HTTP | Retry |
|---|---|---|
| `invalid_request` | 400 | Không tự động |
| `unauthenticated` | 401 | Không tự động |
| `forbidden` | 403 | Không tự động |
| `not_found` | 404 | Không tự động |
| `conflict` | 409 | Không tự động |
| `idempotency_conflict` | 409 | Không tự động |
| `invalid_transition` | 409 | Không tự động |
| `stale_epoch` | 409 | Không tự động |
| `ambiguous_variant` | 409 | Không tự động |
| `stale_version` | 412 | Không tự động |
| `validation_failed` | 422 | Không tự động |
| `unsupported_feature` | 422 | Không tự động |
| `precondition_required` | 428 | Không tự động |
| `rate_limited` | 429 | Có sau Retry-After/repair |
| `migration_barrier` | 503 | Có sau Retry-After/repair |
| `index_unavailable` | 503 | Có sau Retry-After/repair |
| `dependency_unavailable` | 503 | Có sau Retry-After/repair |

## 2. Endpoint matrix

| Endpoint | Request/response cốt lõi | Permission/semantics |
|---|---|---|
| `GET /catalog` | category/tag/attribute IDs, min/max base_price, in_stock, sort, limit, cursor, count_mode → card list + next_cursor + index_generation/freshness + total nếu yêu cầu | Public published/catalog-visible only; cursor gắn query hash/sort/cohort/generation |
| `GET /products/{id}` | ProductView gồm content, options, default_selection, media, price_summary, revision | Public purchasable/visible policy; admin `context=edit` riêng permission |
| `GET /products/{id}/variants` | cursor, limit → variant summaries/options/primary_media; stock availability, không public quantity chính xác mặc định | Publish visibility; không trả toàn bộ 10k variants trong một request |
| `POST /products/{id}/resolve` | selection map, quantity, expected_revision?, request_sequence → none/partial/exact/ambiguous + allowed_values + exact variant | Public; canonical resolver, rate-limited; không tạo cart/hold stock |
| `POST /admin/products` | ProductWrite + optional variants/media → id/version | `edit_products`; native writer hoặc route WC CRUD theo authority |
| `PATCH /admin/products/{id}` | chỉ field hiện diện; collection mode merge/replace bắt buộc rõ → id/version | object edit cap + CAS; không implicit xóa variants khi PATCH thiếu |
| `POST /admin/products/{id}/variants` | VariantWrite/options/SKU → id/version,parent_version | cap + parent If-Match |
| `PATCH /admin/variants/{id}` | VariantWrite patch → version,parent_version | CAS variation + lock parent |
| `DELETE /admin/products/{id}` | `force=false` mặc định → tombstone/version | trash/delete capability tương ứng; order giữ reference |
| `POST /admin/assets` | multipart file (managed upload) hoặc URL + metadata/renditions/provider (external) → asset ID/version, origin managed hoặc external, upload_key idempotent | `upload_files` + `edit_post` trên product; kiểm tra loại/kích thước; không fallback storage âm thầm |
| `PATCH /admin/products/{id}/media` | ordered refs + featured asset + variation mappings + alt override → product revision; asset phải thuộc thư viện product hoặc được link cùng request | cap + parent CAS; invalid ref hoặc ảnh đang dùng chưa có thay thế không cập nhật nửa chừng |
| `GET /admin/products/{id}/media` | thư viện ảnh của product: featured, gallery có thứ tự, ảnh chỉ gắn variation, upload chờ link, origin và trạng thái từng ảnh | `edit_post` trên product + `upload_files` |
| `POST /admin/products/{id}/media/unlink-preview` | asset IDs muốn gỡ → các vị trí bị ảnh hưởng (featured, gallery, variations) và yêu cầu thay thế; không ghi dữ liệu | `edit_post` trên product + `upload_files` |
| `DELETE /admin/assets/{id}/file` | xóa file storage của managed asset sau khi quét toàn bộ references → kết quả hoặc danh sách references chặn | `upload_files` + `delete_post` và không còn reference; external asset luôn bị từ chối |
| `POST /admin/upload-sessions/{session}/claim` | gán upload của session cho product vừa tạo → số asset đã claim | chỉ user tạo session + `edit_post` trên product |
| `POST /admin/imports` | staged file ID, format/profile, update_mode, image_mode, dry_run=true mặc định → job ID | `manage_woocommerce` + import capability; không URL/path máy chủ tùy ý |
| `POST /admin/imports/{job}/commit` | validated snapshot hash + conflict_policy → accepted job | cap; file/config bất biến sau validate; nếu đổi phải validate lại |
| `POST /admin/exports` | format/profile, filters, field set, strict/lossy → job ID | cap; cursor traversal snapshot semantics khai báo |
| `GET /admin/jobs/{id}` | status/checkpoint/counts/errors/report artifact | creator hoặc operations cap; không expose filesystem path |
| `POST /admin/jobs/{id}/cancel` | cancel requested → status | cancel giữa chunks, không rollback rows đã commit |
| `GET /admin/jobs/{id}/download` | CSV/report stream/private redirect có expiry | permission mỗi download; name/path sanitize |
| `GET /admin/health` | authority/cohorts, queue lag, mismatches, index, blockers, current epoch | operations cap; health public chỉ coarse status nếu cần |
| `POST /admin/migrations` | direction, cohort manifest, dry_run → migration job | operations cap; cohort immutable/hash |
| `POST /admin/rollouts/{cohort}/validate` | evidence snapshot → gates pass/fail | không đổi authority |
| `POST /admin/rollouts/{cohort}/cutover` | gate ID, expected epoch, manifest hash → job | operations cap; gates hết hạn/phát sinh write gap phải revalidate |
| `POST /admin/rollouts/{cohort}/rollback` | expected epoch, reason, dry_run → job | explicit restore hiện tại, không restore snapshot làm mất đơn/stock mới |

Admin/import/asset/rollout commands không có success nếu chưa commit/precondition/permission hợp lệ; async commands trả202 + job id. Product create trả201, read/update200, delete tombstone200. Canonical ProductWrite/VariantWrite/MediaPatch fields thuộc DB schema, unknown fields reject; T03 phát typed JSON schemas, không allow arbitrary client SQL/meta override.

## 3. DTO examples

```json
{
  "id": "12001",
  "type": "variable",
  "status": "publish",
  "title": "Custom T-Shirt",
  "slug": "custom-t-shirt",
  "currency": "USD",
  "version": "18",
  "options": [
    {"id":"101","key":"pa_color","name":"Color","position":1,"values":[{"id":"1001","key":"black","label":"Black"}]},
    {"id":"102","key":"pa_size","name":"Size","position":2,"values":[{"id":"1002","key":"m","label":"M"}]}
  ],
  "default_selection": {"101":"1001","102":"1002"},
  "price_summary": {"min":"19.950000","max":"24.950000","basis":"base","context_key":"store-default"},
  "media": [{"asset_id":"501","attachment_id":"90001","url":"https://cdn.example.com/shirt-black.jpg","width":1600,"height":1600,"alt":"Black shirt","position":0}],
  "variants": {"count":24,"href":"/wp-json/wpe/v1/products/12001/variants"}
}
```

```json
{
  "selection":{"101":"1001","102":"1002"},
  "quantity":"2",
  "expected_revision":"18",
  "request_sequence":7
}
```

```json
{
  "state":"exact",
  "request_sequence":7,
  "product_revision":"18",
  "variant":{"id":"12005","sku":"TEE-BLACK-M","price":"19.950000","display_price":"19.95","stock_owner_id":"12005","available":true,"purchasable":true,"media_asset_id":"501"},
  "allowed_values":{"101":["1001"],"102":["1002"]},
  "reason":null
}
```

Không tin variant ID/price/stock gửi từ browser. Add-to-cart phải kiểm tra variant thuộc parent, thuộc tính hợp lệ, quantity/stock/backorders và current price tại server.

## 4. Event contracts

```json
{
  "event_id":"f3bf7a91-07d1-4ca3-84b4-11259124fd84",
  "schema_version":1,
  "type":"wpe.product.changed.v1",
  "aggregate_id":"12001",
  "authority":"native",
  "authority_epoch":"3",
  "revision":"19",
  "occurred_at":"2026-10-11T17:08:07Z",
  "origin":"woo-crud",
  "changed_fields":["variants.price","media"],
  "correlation_id":"809c802a-29aa-42de-9936-89fccb03d03d"
}
```

Types: product.created/changed/trashed/restored/deleted; variant.changed/deleted; inventory.changed; asset.changed; taxonomy.changed; authority.changed; index.rebuild.completed. Product-scoped changes dùng parent aggregate, asset/global term dùng aggregate riêng. Consumer đọc canonical snapshot mới nhất, không apply field patch cũ qua event thiếu dữ liệu. Delivery at-least-once; ordering/revision fence per aggregate; consumer không project ngược thành command mới.

## 5. Webhooks

Không có engine webhook endpoint ngoài REST ở V1. Existing WC webhooks/order callbacks tiếp tục WC xử lý; không expose outbox public. Future external event subscriptions là plan riêng có signing/retry/replay contract, không thêm ngầm trong T09.

## 6. Rollout và nested mutation schema requirements

ValidateWrite requires expected_epoch/manifest_hash/evidence_snapshot (evidence_id/artifact_hashes/generated_at). CutoverWrite requires gate_id/expected_epoch/manifest_hash. RollbackWrite requires expected_epoch/manifest_hash/reason, dry_run=true default. VariantMutationResult requires id/version/parent_version. Product PATCH có options/terms/meta/variants phải collection_mode; MediaPatch collection_mode luôn required. GET product context=view public, context=edit auth+edit_post. DELETE force=false param có trong OpenAPI.

## 7. Media library và managed upload

Thư viện ảnh của product là các dòng liên kết `product_media` (tối đa một featured, gallery có thứ tự). File thực tế nằm tại provider đã lưu cho asset.

**Upload.** `POST /admin/assets` nhận multipart với `file` và `upload_key` do client sinh, dùng lại khi retry. Backend kiểm tra quyền, loại file (jpeg, png, gif, webp, avif; SVG bị từ chối) bằng nội dung, và kích thước (nhỏ hơn giữa giới hạn WordPress và cấu hình plugin). Storage mặc định do hệ thống chọn; khi storage mặc định bật nhưng không dùng được, lỗi trả `dependency_unavailable` (503) với `details[].reason = storage_unavailable` và `retryable = true`; không bao giờ chuyển sang provider khác. Chỉ sau khi object được xác nhận mới tạo asset và dòng managed storage. Cùng `upload_key` trả lại cùng asset, không tạo asset hoặc liên kết trùng.

**Lý do chi tiết** dùng mã lỗi đã đăng ký kèm `details[].reason`: `file_type_not_allowed` và `file_too_large` (`validation_failed`), `asset_not_in_product` (`validation_failed`), `impact_requires_resolution` (`conflict`), `storage_unavailable` (`dependency_unavailable`), `asset_in_use` và `asset_external` cho xóa file (`conflict`, `validation_failed`).

**Membership.** Mọi asset được gán làm ảnh variation, featured hoặc gallery phải thuộc thư viện product hoặc được link trong cùng request; backend từ chối asset khác, không chỉ UI. Ảnh variation hiện có nhưng chưa nằm trong thư viện hiển thị là `variant_only`; giữ nguyên khi lưu không bị chặn.

**Gỡ ảnh.** Gỡ chỉ xóa liên kết. `POST /admin/products/{id}/media/unlink-preview` liệt kê vị trí bị ảnh hưởng. `PATCH` với `unlink` bắt buộc mỗi ảnh đang dùng có `replacement_asset_id` hoặc `fallback = true`, nếu không trả `impact_requires_resolution`. Thay đổi atomic.

**Xóa file.** `DELETE /admin/assets/{id}/file` chỉ cho managed asset, kiểm tra quyền và toàn bộ references, không bao giờ xóa external hay asset còn dùng bởi product khác.

**Sản phẩm chưa có ID.** Upload mang `session_id`; sau khi tạo product `POST /admin/upload-sessions/{session}/claim` gán các upload. Trong classic editor product auto-draft đã có ID nên upload dùng `product_id`.

**Đổi storage mặc định** chỉ ảnh hưởng upload mới; asset cũ giữ provider, bucket, key, URL đã lưu.

## 8. Editor hai block: Product information và Variants

Các route editor của admin UI (`wpe/v1/admin/product/{id}/variants` và các route con preview) là bề mặt riêng của màn hình sản phẩm, không thuộc ma trận endpoint công khai ở §2; phần này chốt thêm payload của chúng cho hai block. Mọi route cần `edit_post` trên product, REST nonce của cookie và không trả secret.

**Dữ liệu đọc.** Payload editor gồm: khối `product` (SKU cấp product, giá thường/sale/lịch sale, quản lý kho và backorders, trọng lượng, kích thước, `version`), `common_price` (hoặc null), `reference_price`, `type` đã lưu cùng `derived_type` suy ra từ options, `descriptive_attributes`, `variation_options` (kèm giá trị mặc định), danh sách `variations` (mỗi dòng có `pricing_mode`, giá hiệu lực và nguồn, `version`, stock owner, ảnh theo `image_asset_id`) và `warnings` cho dữ liệu cũ không nhất quán (chỉ hiển thị, không tự sửa). `reference_price` có `state` (`ok`, `unpriced`, `none`), `variant_id`, `label` dạng “Black / M”, regular, sale và lịch sale; nó luôn tính trên toàn bộ variation theo thứ tự đã lưu, không phụ thuộc phân trang.

**Preview.** Có preview riêng cho: tổ hợp variation sẽ tạo (tổ hợp đã có, tổ hợp mới, số lượng), bulk edit (before/after), thay đổi chính sách kho, xóa giá chung đang được variation `shared` dùng, và chuyển loại Simple ↔ Variable. Bulk request luôn nêu phạm vi: danh sách id của trang hiện tại hoặc toàn bộ kết quả filter được server phân giải lúc preview thành danh sách id kèm số lượng và một token; apply dùng danh sách id và token của preview nên phạm vi không thể lớn thêm giữa preview và apply. Các dòng dùng chung một stock owner được nhóm và owner chỉ được ghi một lần.

**Lưu.** Một request lưu duy nhất mang `product`, `common_price`, `descriptive_attributes`, `variation_options`, `variations` (mỗi dòng có `pricing_mode`, id nếu đã có và `version`), `expected_version` của product, xác nhận chuyển loại nếu có và `operation_key`. Server validate toàn bộ rồi ghi trong một transaction database (hoặc tất cả hoặc không). Kết quả tách hai phần: `required` (phần đã commit: `new_version`, `type`, version từng variation, dữ liệu đã lưu) và `projection` (legacy mirror và catalog build chạy sau commit, báo riêng từng trạng thái; retry cùng `operation_key` là idempotent). Phần `required` lỗi thì không báo thành công chung và client giữ nguyên bản nháp; version lệch trả `stale_version` hoặc `conflict` kèm dữ liệu server; xóa giá chung đang dùng mà variation `shared` chưa được xử lý giá, hoặc giảm số variation khi chuyển loại mà chưa xác nhận, trả `validation_failed` với `details[].reason` nêu rõ. Giá hiệu lực của cart, checkout và order luôn lấy từ variation theo resolver giá, không từ `reference_price`.

**Một writer.** Trên sản phẩm được hỗ trợ (Simple và Variable), request lưu từ form WordPress bị loại các key legacy thuộc hai block trước khi WooCommerce lưu; editor mode (blocks, hộp Variants Manager, tab legacy) chọn qua một option và một filter để phục hồi mà không cần migration dữ liệu.

## 9. Cài đặt Product Page và dữ liệu storefront selector

Route quản trị cài đặt `wpe/v1/admin/settings` (yêu cầu quyền `manage_woocommerce` và REST nonce) quản lý thêm nhóm `product_page` gồm 7 cờ boolean (mặc định `false` để giữ nguyên hành vi hiện có): `single_price_display`, `show_selected_value`, `show_help_text`, `button_selector`, `color_swatches`, `image_swatches`, `mark_unavailable_options`, cùng cấu hình hiển thị mặc định theo attribute ID cho thuộc tính global (`attribute_display_config`). Trong payload của editor sản phẩm (`variation_options`), mỗi option có thêm `display_type` (`auto`, `dropdown`, `button`, `color`, `image`) và `help_text`, và mỗi value có `color` (hex `#rrggbb` hoặc rỗng, lưu vào `option_values.swatch`).

Phản hồi của `POST /products/{id}/resolve` bổ sung:
- `variant.price_html`: HTML giá đầy đủ của variation đã resolve (kể cả khi mọi variation có cùng mức giá), định dạng theo tiền tệ, thuế hiển thị, price suffix và lịch sale của cùng variation đó.
- `lowest_price_html`: HTML giá bán hiệu lực thấp nhất của các variations hợp lệ có thể mua của sản phẩm (chuỗi rỗng nếu không có variation hợp lệ có giá).
- `value_thumbnails`: map `{option_id: {value_id: {src, alt, srcset}}}` cho các thuộc tính kiểu Image Swatch, được tính động theo candidate selection của từng value kết hợp với các lựa chọn hiện tại của các options khác theo đúng thứ tự `position` đã lưu.
- `allowed_values` và `allowed_details`: phản ánh chính xác trạng thái `exists`, `purchasable` và `in_stock` sau khi kiểm tra cả `EffectivePriceResolver` và `wpe_inventory` (bao gồm chính sách `manage_stock` và `backorders`).
