# 03 — Database schema

## 1. Quy ước chung

- Tên dưới đây dùng `{$wpdb->prefix}wpe_`; không hardcode `wp_`. Site multisite dùng prefix riêng. Network-wide migration phải có task chạy riêng từng blog; không chia sẻ product ID giữa blog.
- `ID = BIGINT UNSIGNED`; `VER = BIGINT UNSIGNED`; `TS = DATETIME(6)` UTC; `AMT = DECIMAL(19,6)` (API trả string); `QTY = DECIMAL(19,6)` để không ép fractional stock thành integer; `Vn = VARCHAR(n)`. Dimensions/weight cũng decimal, kèm store unit khi mapping.
- Chuỗi label/title dùng charset/collation site; key identity/normalized SKU/hash dùng binary collation. `TEXT_JSON` nghĩa `LONGTEXT` chứa JSON được schema validator kiểm tra, không phụ thuộc native JSON type của DB.
- `B` là `TINYINT UNSIGNED` chỉ nhận 0/1. Các enum lưu `VARCHAR` + validation, không SQL ENUM; khi DB hỗ trợ CHECK thì thêm kiểm tra tương ứng. `NULL` có ý nghĩa riêng, không thay bằng 0 hoặc chuỗi rỗng tùy tiện.
- FK bên dưới là quan hệ hợp đồng. Các bảng plugin dùng transaction + lock parent + integrity audit; không yêu cầu FK vật lý đến bảng WP. Nếu thêm FK nội bộ, migration phải chứng minh `dbDelta`/upgrade/backup hoạt động trên matrix hỗ trợ. ID liên hệ WP không cascade-delete order/review.
- Mọi aggregate mutation tăng `products.version`; variant mutation tăng `variants.version` và parent version; inventory có version riêng và phát event cho parent. Writes cùng aggregate khóa theo thứ tự parent → stock owner → variant → media refs, giảm deadlock.
- DB quyền tối thiểu WordPress; không một transaction dài bao trùm cả import/backfill. DDL không được coi là rollback cùng transaction dữ liệu.
- Trường JSON `extra_props` là extension rõ schema, không dùng thay các field cần filter/sort. Mọi WC prop version đã pin phải có mapping hoặc lý do loại trừ trong `wc-props-mapping.json`.
- Khóa `extra_props` được tài liệu hóa cho editor hai block (không thêm cột, không phải field filter/sort của catalog): `products.extra_props.common_price` là object `{regular, sale, from, to}` (regular/sale là chuỗi 6 chữ số thập phân hoặc null, from/to là timestamp UTC hoặc null); `variants.extra_props.pricing_mode` nhận `shared` hoặc `own`, thiếu nghĩa là `own`. Giá hiệu lực của variation `shared` được ghi vào cột giá của variation bởi một writer duy nhất trong cùng transaction với thay đổi giá chung hoặc đổi chế độ, nên mọi reader và adapter WooCommerce tiếp tục đọc cột variation mà không cần biết giá chung. Cột giá của variable parent vẫn thuộc aggregate catalog. Giá tham chiếu được tính khi đọc từ variation đầu tiên theo `(position, id)` và không bao giờ được lưu. Code cũ bỏ qua hai khóa này nên chuyển editor mode qua lại không cần migration.
- Cấu hình hiển thị thuộc tính storefront (P012, không thêm bảng hay cột DDL mới): `products.extra_props.option_ui` là map theo `option_id` ổn định (`wpe_options.id`), mỗi phần tử gồm `display_type` (`auto`, `dropdown`, `button`, `color`, `image`) và `help_text` (chuỗi hướng dẫn đã sanitize). Mã màu của từng giá trị thuộc tính lưu trong cột `option_values.swatch` (`TEXT_JSON` dạng `{"color":"#rrggbb"}`). Cấu hình mặc định cho thuộc tính global (`pa_*`) theo attribute ID/taxonomy ổn định lưu tại WordPress option `wpe_attribute_display_config`; thuộc tính cấp sản phẩm kế thừa khi `display_type` là `auto` hoặc `help_text` rỗng và có thể ghi đè riêng.

## 2. Bảng nguồn chuẩn

Các cột không có `?` là NOT NULL; các default phải được ghi vào DDL hoàn chỉnh của T05. `?` là nullable. Các index liệt kê là hợp đồng tối thiểu, bổ sung phải dựa trên EXPLAIN.

| Bảng | Cột và kiểu | Khóa/index, ràng buộc |
|---|---|---|
| `products` | `id ID`, `type V20`, `status V20`, `slug V200`, `title TEXT`, `description LONGTEXT`, `short_description TEXT`, `catalog_visibility V20`, `sku V191?`, `regular_price AMT?`, `sale_price AMT?`, `sale_from DATETIME?`, `sale_to DATETIME?`, `virtual B`, `downloadable B`, `tax_status V20`, `tax_class V200`, `sold_individually B`, `purchase_note TEXT`, `menu_order INT`, `featured B`, `shipping_class_id ID?`, `weight AMT?`, `length AMT?`, `width AMT?`, `height AMT?`, `stock_owner_id ID?`, `default_selection TEXT_JSON`, `extra_props TEXT_JSON`, `created_at TS`, `updated_at TS`, `deleted_at TS?`, `version VER` | PK id; `(status,created_at,id)`; `(slug,id)`. Slug uniqueness theo WP permalink namespace, không chỉ unique toàn DB. Variable parent không dùng price field làm giá bán authoritative |
| `variants` | `id ID`, `product_id ID`, `status V20`, `sku V191?`, `combination_key BINARY(32)`, `active_combination_key BINARY(32)?`, `regular_price AMT?`, `sale_price AMT?`, `sale_from TS?`, `sale_to TS?`, `tax_class V200?`, `virtual B`, `downloadable B`, `weight AMT?`, `length AMT?`, `width AMT?`, `height AMT?`, `shipping_class_id ID?`, `description TEXT`, `stock_owner_id ID?`, `position INT`, `extra_props TEXT_JSON`, `version VER`, `created_at TS`, `updated_at TS`, `deleted_at TS?` | PK id; `(product_id,status,position,id)`; UNIQUE `(product_id,active_combination_key)`; active key = combination key khi chưa deleted, NULL khi tombstone, cho phép giữ lịch sử rồi tạo combination mới. Hash collision phải so vector đầy đủ và báo conflict, không coi hash là equality. Restore phải revalidate unique key |
| `sku_registry` | `normalized_sku VARBINARY(764)`, `object_id ID`, `object_kind V16`, `original_sku V191` | PK normalized_sku; UNIQUE object_id; áp dụng chung parent/simple/variation và legacy cohort. Empty SKU không có row. Policy trim/case/accent phải xác nhận tương thích baseline; không tự lowercase Unicode |
| `options` | `id ID`, `product_id ID`, `key V191`, `name V200`, `taxonomy V64?`, `position SMALLINT`, `visible B`, `used_for_variation B`, `default_value_id ID?` | PK id; UNIQUE `(product_id,key)`; UNIQUE `(product_id,position)`; WC có thể >3 options, Shopify export phải báo lỗi/loss chứ không cắt |
| `option_values` | `id ID`, `option_id ID`, `value_key V191`, `label TEXT`, `term_id ID?`, `position INT`, `swatch TEXT_JSON?` | PK id; UNIQUE `(option_id,value_key)`; `(option_id,position,id)`; global taxonomy term giữ ID WP |
| `variant_values` | `variant_id ID`, `option_id ID`, `value_id ID?`, `is_any B` | PK `(variant_id,option_id)`; `(option_id,value_id,variant_id)`; NULL chỉ hợp lệ khi wildcard WC `is_any=1`; value phải thuộc option của parent |
| `product_terms` | `product_id ID`, `term_taxonomy_id ID`, `role V32` | PK `(product_id,term_taxonomy_id)`; `(term_taxonomy_id,product_id)`; WP terms canonical, engine lưu projection mapping; role là category/tag/visibility/attribute/brand đã đăng ký |
| `meta` | `meta_id ID`, `object_id ID`, `object_kind V16`, `meta_key V255`, `meta_value LONGTEXT` | PK meta_id; `(object_id,meta_key prefix phù hợp DB)`; meta_id reserve từ WP global postmeta ID allocator (03 §5), không AUTO native độc lập; giữ repeated keys và WC meta IDs; serialize/unserialize đúng WP, không JSON hóa PHP serialized tùy tiện |
| `product_links` | `product_id ID`, `target_id ID`, `kind V16`, `position INT` | PK `(product_id,kind,target_id)`; `(target_id,kind)`; kinds upsell/crosssell; target legacy được phép |
| `downloads` | `object_id ID`, `download_id V64`, `name TEXT`, `file_url TEXT`, `position INT` | PK `(object_id,download_id)`; WC download IDs bất biến, quyền tải file vẫn do WC; limit/expiry nằm trong extra_props đã mapping |
| `inventory` | `owner_id ID`, `manage_stock B`, `quantity QTY?`, `backorders V16`, `stock_status V20`, `low_stock_amount QTY?`, `version VER`, `updated_at TS` | PK owner_id; không có quantity nếu unmanaged; backorders no/notify/yes; resolved owner là parent hoặc variation, không trừ cả hai |
| `stock_operations` | `operation_key VARBINARY(191)`, `owner_id ID`, `order_id ID?`, `order_item_id ID?`, `kind V24`, `delta QTY`, `quantity_after QTY`, `created_at TS` | PK operation_key; `(owner_id,created_at)`; key idempotency theo order/item/event, không dùng chỉ order_id vì refund từng phần |
| `reservation_bridge` | `order_id ID`, `owner_id ID`, `quantity QTY`, `expires_at TS`, `state V16`, `version VER`, `updated_at TS` | PK `(order_id,owner_id)`; `(owner_id,state,expires_at)`; là hợp đồng adapter nếu WC reservation không đọc native được. Chỉ một backend reservation sở hữu từng cohort; không ghi đồng thời hai hệ thống |

## 3. Ảnh/media

| Bảng | Cột và kiểu | Khóa/index, ràng buộc |
|---|---|---|
| `assets` | `id ID AUTO`, `kind V16`, `source_url TEXT`, `url_key BINARY(32)`, `provider V64`, `provider_key TEXT?`, `mime V100?`, `width INT?`, `height INT?`, `alt_text TEXT`, `title TEXT`, `caption TEXT`, `attachment_id ID?`, `delivery_policy TEXT_JSON`, `renditions TEXT_JSON`, `health V16`, `created_at TS`, `updated_at TS`, `version VER` | PK id; UNIQUE attachment_id nếu không null; `(url_key,id)`; collision so URL đầy đủ. Không loại query string chứa resize/version/signature khi dedup; không lưu URL ký sắp hết hạn làm canonical |
| `product_media` | `product_id ID`, `asset_id ID`, `role V16`, `position INT`, `alt_override TEXT?` | PK `(product_id,asset_id,role)`; UNIQUE `(product_id,role,position)`; featured tối đa một, role gallery có thứ tự; alt của cùng asset có thể khác theo product |
| `variant_media` | `variant_id ID`, `asset_id ID`, `position INT`, `is_primary B`, `alt_override TEXT?` | PK `(variant_id,asset_id)`; UNIQUE `(variant_id,position)`; primary tối đa một theo application invariant |
| `asset_storage` | `asset_id ID`, `storage_provider V32`, `bucket V191`, `object_key TEXT`, `object_key_hash BINARY(32)`, `file_name V255`, `size_bytes BIGINT`, `content_hash BINARY(32)`, `etag V128?`, `state V16`, `created_by ID?`, `created_at TS`, `updated_at TS`, `deleted_at TS?` | PK asset_id; UNIQUE `(storage_provider,bucket,object_key_hash)`; `(state,created_at)`. Dòng tồn tại nghĩa là asset do hệ thống quản lý (managed); asset không có dòng là external URL. Provider, bucket, key lưu theo từng asset |
| `upload_items` | `id ID AUTO`, `upload_key V64`, `user_id ID`, `session_id V64`, `product_id ID?`, `state V16`, `file_name V255`, `mime V100?`, `size_bytes BIGINT?`, `content_hash BINARY(32)?`, `storage_provider V32?`, `bucket V191`, `object_key TEXT?`, `object_key_hash BINARY(32)?`, `asset_id ID?`, `error_reason V64?`, `error_message TEXT?`, `attempts INT`, `created_at TS`, `updated_at TS`, `expires_at TS` | PK id; UNIQUE `(user_id,upload_key)`; `(session_id,state)`; `(state,updated_at)`; `(product_id,state)`. Ghi ý định trước khi upload, cập nhật sau khi object được xác nhận; retry cùng upload_key tiếp tục từ state đã lưu |

Asset chỉ xóa khi không còn references, bridge attachment không dùng ở nơi khác và đã qua retention. Xóa sản phẩm không xóa file CDN hay ảnh sản phẩm khác. Attachment ID thật chỉ cấp lúc cần compatibility; mode gallery native chứng nhận yêu cầu cấp đủ ID ảnh hiển thị, nên tradeoff dung lượng `wp_posts` phải đưa vào benchmark.

Managed asset và upload: asset được tạo chỉ sau khi object đã được xác nhận tồn tại với đúng kích thước tại provider; upload lỗi không tạo asset. `upload_items.state` gồm receiving, stored, registered, failed, cleaned. Object đã stored nhưng chưa có asset sau thời gian retention (mặc định 48 giờ) là ứng viên dọn mồ côi, chỉ xóa sau khi quét lại references. Đổi storage mặc định chỉ ảnh hưởng upload mới; không sửa hoặc viết lại provider, bucket, key, URL của asset cũ. Gỡ ảnh khỏi product chỉ xóa dòng `product_media`/`variant_media`; xóa file là thao tác riêng, chỉ managed asset, chỉ khi không còn reference ở product, variation, attachment bridge hoặc retention hold.

## 4. Index, đồng bộ, import và vận hành

| Bảng | Cột và kiểu | Khóa/index, ràng buộc |
|---|---|---|
| `catalog` | `generation ID`, `product_id ID`, `source_version VER`, `type V20`, `status V20`, `visibility V20`, `in_stock B`, `featured B`, `on_sale B`, `min_price AMT?`, `max_price AMT?`, `total_sales QTY`, `rating_average DECIMAL(4,2)`, `rating_count BIGINT`, `created_at TS`, `menu_order INT`, `title_sort V191`, `image_asset_id ID?`, `indexed_at TS`, `next_price_change TS?` | PK `(generation,product_id)`; `(generation,status,visibility,in_stock,min_price,product_id)`; `(generation,status,created_at,product_id)`; `(generation,status,total_sales,product_id)`; index riêng rating/menu_order theo workload. Giá min/max chỉ của variation khả dụng theo policy được pin |
| `catalog_facets` | `generation ID`, `product_id ID`, `variant_id ID` (0 cho product-level), `facet_key VARBINARY(191)`, `value_key VARBINARY(191)` | PK `(generation,facet_key,value_key,product_id,variant_id)`; `(generation,product_id,variant_id)`; variant-level JOIN phải cùng variant khi filter tổ hợp |
| `object_state` | `object_id ID`, `kind V16`, `parent_id ID?`, `authority V16`, `epoch VER`, `legacy_revision VER`, `native_revision VER`, `legacy_applied VER`, `native_applied VER`, `legacy_hash BINARY(32)?`, `native_hash BINARY(32)?`, `tombstone B`, `updated_at TS` | PK object_id; `(authority,parent_id,object_id)`; revisions monotonic theo nguồn, không thay bằng updated_at |
| `outbox` | `event_id CHAR(36)`, `aggregate_id ID`, `revision VER`, `epoch VER`, `event_type V64`, `payload TEXT_JSON`, `state V16`, `attempts INT`, `available_at TS`, `lease_owner V100?`, `lease_until TS?`, `created_at TS`, `last_error TEXT?` | PK event_id; UNIQUE `(aggregate_id,epoch,revision,event_type)`; `(state,available_at,lease_until,event_id)`; consumer dùng applied revision để retry idempotent |
| `jobs` | `id CHAR(36)`, `kind V24`, `status V24`, `config TEXT_JSON`, `checkpoint TEXT_JSON`, `counts TEXT_JSON`, `lease_owner V100?`, `lease_until TS?`, `cancel_requested B`, `created_at TS`, `updated_at TS`, `error_code V64?` | PK id; `(status,lease_until,id)`; resume dựa persisted checkpoint, không UI request |
| `import_rows` | `job_id CHAR(36)`, `row_number BIGINT`, `group_key VARBINARY(191)`, `raw_fields TEXT_JSON`, `mapped_payload LONGTEXT?`, `status V24`, `errors TEXT_JSON`, `warnings TEXT_JSON`, `target_id ID?`, `content_hash BINARY(32)` | PK `(job_id,row_number)`; `(job_id,group_key,row_number)`; nhóm quá lớn spill disk/private staging, không giữ toàn file RAM |
| `external_ids` | `source V64`, `source_scope VARBINARY(191)`, `external_key VARBINARY(191)`, `object_kind V16`, `object_id ID` | PK `(source,source_scope,external_key,object_kind)`; `(object_id,source)`; handle mutable không dùng làm identity native sau import |
| `control` | `key VARBINARY(191)`, `value TEXT_JSON`, `version VER`, `updated_at TS` | PK key; lưu generation đang active, cohort config, epoch và cutover checkpoint qua CAS |
| `audit` | `id ID AUTO`, `actor_id ID?`, `request_id CHAR(36)`, `operation V64`, `target_id ID?`, `job_id CHAR(36)?`, `before_revision VER?`, `after_revision VER?`, `summary TEXT_JSON`, `created_at TS` | PK id; `(target_id,created_at,id)`; không log secret, signed URL query/token hoặc toàn bộ import raw vào audit |

## 5. Transaction và consistency

**Native command:** xác thực → đọc registry/epoch → khóa aggregate → CAS version → validate SKU/options/media → ghi các bảng native + outbox + audit trong cùng transaction → COMMIT → invalidation/hook hậu commit → queue consumers. Hook có side effect ngoài DB không được gọi trong đoạn có retry transaction. Không giả định exactly-once delivery: event có event_id, subscriber phải idempotent; hook sequence chuẩn được test trên version đã pin.

**Legacy write:** WC CPT hooks không tạo outbox atomic với toàn bộ chuỗi ghi Core. Hook tạo dirty marker/outbox best-effort; journal + reconciliation quét sửa gap. Trong legacy mode chỉ đọc legacy cho giao dịch mua hàng. Cutover yêu cầu write barrier và snapshot đối chiếu cuối, không chỉ tin queue rỗng.

**Projection:** đọc canonical aggregate mới nhất → upsert mọi row bằng source revision → bỏ event cũ/tombstone → xác nhận checkpoint sau commit. Có dedup, retry exponential backoff có jitter, lease timeout, dead-letter queue và replay. Action Scheduler chỉ dùng wake-up/job scheduling; không tạo một action vĩnh viễn cho từng dòng trong catalog triệu sản phẩm.

**Stock:** catalog là eventual consistent; cart/checkout/resolver kiểm tra lại canonical. Reserve có lock cùng owner, tính tồn khả dụng trừ reservation chưa hết hạn và tránh double-count reservation của order hiện tại. Commit/reduce/restock/refund idempotent theo operation key. Các đường stock SQL/lookup WC phải được inventory bridge kiểm chứng; thiếu bridge là blocker native, không fallback quantity cũ.

**Cache:** dữ liệu props nhạy giao dịch invalidated sau commit hoặc đọc version check; index/cache có freshness metadata. Giá schedule có job theo next boundary và reconciliation, không chỉ hook save. `full_native` không dùng postmeta stale làm fallback.

**Cấp ID và shell:** WP API có thể phát hook/cache side effect ngay khi tạo post; không giả định mọi tác động đó rollback được cùng native transaction. New product/variation preallocate shell chưa published qua WP API, sau đó commit canonical aggregate + registry + outbox; sau commit project shell qua API. Lỗi trước commit để lại shell reservation có thể dọn bằng job sau grace period; tuyệt đối không tái sử dụng ID đã cấp. Reads native dựa registry/factory adapter đã chứng nhận; product không public cho đến khi discovery/routing bridge ready. T06/T13 phải test crash trước/sau từng bước và không phát business create hook hai lần.

**Metadata ID allocation:** Existing migrated custom meta giữ numeric wp_postmeta.meta_id. Native new custom meta preallocate một row anchor thật bằng WP metadata API có origin guard trước canonical transaction; ID do wp_postmeta global AUTO cấp, sau đó native meta dùng cùng ID. Không AUTO riêng ở native. Anchor pending orphan sau failed command dọn grace period, không reuse ID. Mirror cập nhật row theo ID khi enabled; custom meta value canonical native, WP metadata API bridge đọc native. Full Native có thể giữ anchors tương thích, không zero-postmeta. Raw SQL meta reader/writer chưa adapter không certified. T08 cấp MetaIdAllocator; T11/T39 bridge và mirror validate collision/race/restore. Internal typed props price/stock không tạo arbitrary WC meta rows để né independence gate.

## 6. Enum registries

| Registry | Values | Consumer |
|---|---|---|
| authority | legacy/native | object_state |
| product_type | simple/variable/variation | products/variants type resolution |
| product_status | publish/draft/pending/private/trash | WC status mapping; custom status cần adapter |
| visibility | visible/catalog/search/hidden | WC policy |
| backorders | no/notify/yes | inventory |
| stock_status | instock/outofstock/onbackorder | inventory |
| outbox_state | pending/processing/applied/dead_letter | jobs worker |
| job_status | uploaded/staging/validated/queued/running/cancel_requested/cancelled/completed/partial_failed/failed/blocked | import/export/migration |
| reservation_state | held/committed/released/expired | một backend owner/cohort |
| asset_health | pending/healthy/broken | background health |
| media_role | featured/gallery | product_media |

Mode registry không duplicate ở đây: 02 §4. Application validation bắt buộc ngay cả DB có CHECK. `idempotency receipts` dùng control namespaced records trong V1; key body hash/expiry/result lưu TEXT_JSON trong cùng transaction, T09 không tự invent service khác.

## 7. Quan hệ, bootstrap và seed

Product1-N variants/options/product_media/meta; option1-N values; variationN-M option-values theo selection map; assetN-M products/variants; stock owner1-N reservations/operations. Terms/reviews WP canonical; không foreign key cascade order tables. T01 seed core-only fixture; T04 edge-case fixture theo 07 §3; default control mode legacy, schema version1, epoch1, active index generation chưa có. `contracts/database/schema-contract.json` là bản schema mô tả bằng máy, executable migrations được T05 tạo và kiểm hai DB.
