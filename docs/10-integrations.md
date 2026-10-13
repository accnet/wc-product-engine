# 10 — Integrations

## 1. Ownership

WC/WP là runtime host. CDN là URL delivery, không bên nào được phép mutate authority registry. Shopify tích hợp offline CSV, không Shopify API/account/token ở V1. Không PostgreSQL bridge, không rewrite SQL dialect.

## 2. Woo/WordPress extension surfaces

Custom Data Store registry là integration chính; implement full Product/Variable/Variation contracts và methods actually called. Product factory, WC query, WP metadata, stock reservations, REST/Store API/admin, taxonomy/review và Blocks là adapters riêng. T01/T03 trace exact pin; unsupported extension point ghi blocker, không sửa Core.

## 3. Gallery/CDN native bridge

| Surface | Hành vi bắt buộc |
|---|---|
| WC getters | `get_image_id()`, `get_gallery_image_ids()` trả attachment ID thật; variation primary mapping chính xác |
| WP images | `wp_get_attachment_url`, `wp_get_attachment_image_src`, `wp_get_attachment_image`, metadata, alt, srcset/sizes trả CDN rendition đúng dimensions |
| Classic gallery | Giữ markup/classes/data attributes native; thumbnails, zoom, lightbox, swipe và reset variation chạy trên baseline |
| Variant JS payload | `image.src`, `full_src`, `gallery_thumbnail_src`, `srcset`, `sizes`, `alt`, dimensions và image_id đúng contract đã pin |
| Native Blocks | Adapter Store API/images và gallery blocks riêng; classic pass không tự chứng minh blocks pass |
| Media admin | Thumbnail/preview và selection bridge; action yêu cầu file local như edit/crop/regenerate báo unsupported hoặc explicit materialize; không trả fake local path |
| SEO/feed/email/cart | Public canonical URL; featured image đúng; product schema, OG/Twitter, sitemap và email được adapter chứng nhận |
| CDN unsupported | Dùng original URL kích thước thật; không tự ghép suffix `-300x300` hoặc `?width=` nếu provider không xác nhận |
| URL health | Probe background, có timeout/retry/redirect cap/SSRF validation; render page không HTTP HEAD cho từng ảnh |

WordPress có filter `image_downsize` và `wp_get_attachment_url`, là một phần của bridge, không đủ để thay toàn bộ Media Library. [S4](10-integrations.md#11-tài-liệu-nguồn)[S5](10-integrations.md#11-tài-liệu-nguồn) Signed URL cho display có thể sinh lúc render, nhưng export dùng URL public ổn định hoặc báo không export được. SVG không tin cậy bị từ chối; http/private/link-local/metadata IP, credentials trong URL và redirect sang mạng private bị chặn khi backend fetch. Allowlist CDN là cấu hình hữu ích, không phải thay thế kiểm tra DNS/redirect.

## 4. CDN providers, URL auth và health

Provider contract `can_transform`, `get_rendition`, `stable_export_url`, `probe`; default passthrough original URL, không invent resize URLs. Fetch HTTPS, DNS/redirect validation chặn private/link-local/metadata addresses; credentials embedded URL reject. Probe timeout connect2s/total5s, max3 redirects, HEAD fallback GET Range capped64KiB nếu provider cần; không tải full file để chỉ đo health. Display không probe mỗi request.

## 5. Local Media Library compatibility

Attachment ID thật; no fake local path cho crop/regenerate/edit. Materialize phải explicit actor upload_files, URL allowed và bounded download task riêng; chưa có trong V1 nên local file editing trả unsupported_feature. Không tự sideload vì một theme gọi metadata.

Managed upload (P011): backend upload qua adapter của plugin object storage khi cài đặt của plugin đó đang bật, ngược lại ghi vào uploads local. Khi cài đặt bật nhưng plugin không nạp được hoặc adapter chưa cấu hình, upload trả lỗi storage không khả dụng, không chuyển sang local. Credentials chỉ nằm trong plugin storage, không trả ra trình duyệt, log hay test. Object được xác nhận (tồn tại, đúng kích thước) trước khi tạo asset. Attachment do bridge tạo không có file local nên phải được đánh dấu không đồng bộ lại bởi plugin storage. Presigned upload chưa nằm trong phạm vi; nếu bổ sung thì URL phải ngắn hạn, giới hạn một object key và backend xác minh object trước khi tạo media.

## 6. CSV pipeline và Woo dialect

### 6.1 Pipeline chung

Upload private → sniff format/encoding/delimiter → stage streaming → normalize aliases → group product → validate → dry-run report → commit chunks per aggregate → index/projection catch-up → export report. Import không chạy trong một HTTP request dài; job có resume/cancel/dead-letter và WP-CLI.

Identity precedence phải theo dialect/version, không chung một rule mơ hồ. Native IDs không bao giờ bị file import cấp tùy ý. External source ID dùng `external_ids`; idempotent theo file hash + row/group + command payload. Duplicate SKU phát lỗi, không gộp hai variations vô tình. Missing column = keep; empty column = quy tắc dialect, report rõ clear/default; parser giữ phân biệt hai trạng thái.

Có hai update mode: `merge` không xóa variation thiếu trong file; `replace_variants` explicit, có dry-run list removals và vẫn tombstone ID được order tham chiếu. File đổi option value không tự đổi variation ID native nếu có identity mapping xác minh; export Shopify không cam kết Shopify giữ ID.

Export mặc định strict: trường không đại diện được → job report + không phát file thành công giả. `lossy=true` phát file kèm loss report. Engine snapshot manifest chứa ID/version/hash + canonical payload cần xuất, không chỉ ID; stream từ staging để export không trộn revisions. Snapshot capture bounded per aggregate, khai báo thời gian snapshot start/end; không giả định entire catalog point-in-time nếu không có DB snapshot chuyên dụng.

CSV UTF-8, RFC-style quoted multiline, timezone quy tắc dialect, escaped comma trong list, BOM hỗ trợ, tối đa row/field/file được config. Báo formula-like cells; có export `spreadsheet_safe` escape dành xem bằng Excel và `machine` round-trip giữ raw, tên/profile phân biệt để không âm thầm sửa SKU/option.

### 6.2 WooCommerce dialect

| CSV field | Canonical mapping và lưu ý |
|---|---|
| ID / SKU / Parent | ID existing chỉ dùng update cùng site; Parent nhận SKU hoặc `id:N`; matching precedence pin version; đối với global unique ID phải theo version hỗ trợ |
| Type | simple/variable/variation; virtual/downloadable flags parse theo native dialect; unsupported type giữ legacy hoặc error |
| Published / Visibility | Status/canonical visibility; giá trị numeric Published theo fixture version, không boolean hóa cả cột |
| Name / Description / Short description | title/content; HTML sanitize theo quyền actor, không bỏ HTML hợp lệ tùy tiện |
| Regular price / Sale price / dates | decimal string; sale schedule timezone store → UTC, DST test |
| In stock? / Stock / Backorders? | inventory owner/inheritance; blank stock không tự thành zero |
| Categories / Tags / Shipping class | WP terms + mapping; hierarchy/escaped commas; shipping class ID thật |
| Attribute N name/value(s)/visible/global/default | options + values + default; taxonomy names/value labels mapping chính xác; unlimited N theo profile Woo |
| Images | URL theo thứ tự; ảnh đầu featured; variation primary riêng. `external` mode plugin không sideload; `download` mode tương thích WC |
| Downloads / Meta / Linked products / ratings | Có mapping version hóa; metadata nhiều giá trị/type phức tạp dùng extension riêng/loss report |

Core Woo CSV thường tải URL ảnh vào Media Library; external mode là phần mở rộng của plugin, không phải hành vi Core. Import/export qua UI Woo hiện có cần hook adapter + acceptance riêng, không chỉ đọc được file Woo trong UI plugin. Native Woo CSV không đại diện đầy đủ variant multi-image gallery, alt per-product hoặc mọi extension; không hứa lossless với các trường đó. [S6](10-integrations.md#11-tài-liệu-nguồn)[S7](10-integrations.md#11-tài-liệu-nguồn)



## 7. Shopify dialect


| Nhóm | Mapping |
|---|---|
| Current/legacy headers | Current `URL handle`, `Description`, `SKU`, `Price`, `Product image URL`, `Variant image URL`; legacy `Handle`, `Body (HTML)`, `Variant SKU`, `Variant Price`, `Image Src`, `Variant Image`. Schema version/fingerprint được lưu trong job |
| Handle / title / vendor / type / tags | Handle source grouping và external mapping; vendor/type custom meta/registered taxonomy theo config. Shopify Product category không tự map thành Woo category tùy ý |
| Options | Tối đa 3 để Shopify export; option/value rows tạo combinations thật; default title mapping thành Woo simple nếu không có option thực |
| Price / Compare-at | Rule chuyển compare-at→regular, price→sale chỉ khi compare-at lớn hơn price; trường bất thường lưu source data + loss report, không đảo nghĩa tùy tiện |
| Inventory | Product CSV single-location stock; multiple locations yêu cầu inventory CSV/API khác, ngoài phạm vi; tracker/continue-selling map có cảnh báo |
| Weight / tax / shipping | grams → store unit với precision policy; charge tax/requires shipping đúng bool; fulfillment service không biến thành shipping method WC |
| Product images | Image-only row không tạo variant; position/alt giữ; primary variant image phải liên kết đúng variant, URL giữ CDN ngoài |
| Metafields / markets / linked options | Product metafields whitelist/typed mapping; variant metafields, market prices, metaobject linked options ngoài subset phải báo unsupported/preserve source sidecar, không silently drop |

Shopify product CSV có các hạn chế về inventory nhiều location, variant metafields, header và thay đổi option/variant ID. Vì vậy version adapters và loss report là bắt buộc; không thêm cột `WPE_*` vào file Shopify native. Product CSV không hỗ trợ mọi trường native engine. [S8](10-integrations.md#11-tài-liệu-nguồn)

## 8. Failure classification/timeouts/retries

| Integration/error | Policy | Recovery |
|---|---|---|
| Deadlock/lock timeout | Retry maximum3 attempts jitter50–500ms | Retry command transaction; hooks external không lặp trong retry |
| Outbox worker transient DB | Exponential1s,2s,4s capped300s, max10 | DLQ with event ID; operator replay sau repair |
| CDN timeout/5xx/429 | Retry3 background, respect Retry-After | Mark health pending/broken, page placeholder |
| CDN404 | No immediate hot-loop | broken + operator URL correction |
| CDN unsafe URL/MIME | Fatal validation | Actor sửa source; không fetch alternative private URL |
| WC version/interface mismatch | Native gate blocked | T01/T03 adapter evidence lại |
| Import validation/duplicate SKU | Per group fatal | Report row/field, sửa input job mới |
| Import DB failure | Resume checkpoint | No aggregate half-save/duplicate |
| Reservation backend unknown | Full native blocked | Trước cutover giữ legacy/indexed; nếu đã native thì halt checkout/stock writes đến khi bridge certified hoặc current-data rollback thành công |
| Shopify live account unavailable | Local schema fixture only | Mark live verification pending, không claim pass |

WC hook ordering/timing phụ thuộc pinned version, không promise exactly-once external effects. Worker lease60s heartbeat20s, task operation vượt lease phải renew hoặc abort; jobs checkpoint after commit. API HTTP retries không tự lặp POST nếu chưa có idempotency receipt.

## 9. Test doubles và real integrations

Unit có fake Clock/Provider/Repository; DB transaction/stock/reservation tests dùng real engines. CDN fake HTTP server cho404/redirect/rate limit; production CDN verification subset riêng không gửi account secrets. Shopify fixture headers local chỉ chứng minh schema mapping; staging Shopify import cần evidence live nếu được scope authorize.

## 10. Compatibility matrix và certification

`contracts/compatibility/matrix.json` ban đầu not_tested, không certified. Matrix artifact cập nhật T01 và tests; unknown plugin native blocked. Core-only fixture không chứng nhận custom customer pricing/multi-currency/multilingual/swatches khác. Không đổi current authority khi plugin thay; halt rollout + concrete blockers.

## 11. Tài liệu nguồn


Các nguồn dưới đây xác nhận extension surfaces và dialect constraints; schema, task DAG, native mode policy, test budgets và runbooks là **thiết kế đề xuất trong tài liệu này**. T01 phải pin và đọc source code release thực được chọn, không dùng `trunk` làm hợp đồng production.

| Ref | Primary source | Dùng để xác nhận |
|---|---|---|
| S1 | [WooCommerce Data Stores](https://developer.woocommerce.com/docs/best-practices/data-management/data-stores/) | Thay Data Store qua extension cơ chế WC, trách nhiệm CRUD/meta |
| S2 | [WC Product Data Store Interface](https://woocommerce.github.io/code-reference/classes/WC-Product-Data-Store-Interface.html) | Product methods ngoài CRUD |
| S3 | [WC Variable Product Data Store Interface](https://woocommerce.github.io/code-reference/classes/WC-Product-Variable-Data-Store-Interface.html) | Variable product-specific methods |
| S4 | [WordPress image_downsize filter](https://developer.wordpress.org/reference/hooks/image_downsize/) | Điểm can thiệp image sizes |
| S5 | [WordPress attachment URL filter](https://developer.wordpress.org/reference/hooks/wp_get_attachment_url/) | Điểm can thiệp delivery URL |
| S6 | [WooCommerce CSV Import/Export](https://woocommerce.com/document/product-csv-importer-exporter/) | Native CSV behavior và image sideload |
| S7 | [WooCommerce CSV Schema](https://github.com/woocommerce/woocommerce/wiki/Product-CSV-Import-Schema) | Field mappings làm baseline dialect |
| S8 | [Shopify Product CSV](https://help.shopify.com/en/manual/products/import-export/using-csv) | Current/legacy headers, options/images, overwrite behavior và format limitations |
| S9 | [wc_get_products / Product Query](https://developer.woocommerce.com/docs/features/products/wc-get-products/) | Product query abstraction và pagination semantics |
| S10 | [WC Product Factory](https://woocommerce.github.io/code-reference/classes/WC-Product-Factory.html) | Object discovery/type cần adapter riêng |
| S11 | [WC ReserveStock](https://woocommerce.github.io/code-reference/classes/Automattic-WooCommerce-Checkout-Helpers-ReserveStock.html) | Reservation có path riêng cần chứng nhận ngoài Product CRUD |

Đặc tả không khẳng định adapter hiện hữu đã hỗ trợ Full Native cho toàn bộ WC ecosystem; không có benchmark đã chạy trong lần tạo tài liệu này. Các test IDs là backlog test specifications. Mọi native support claim của bản triển khai phải gắn evidence và exact matrix.



Target release references: [WP releases](https://wordpress.org/download/releases/), [Woo releases](https://developer.woocommerce.com/releases/). T01 kiểm download checksums/release source exact versions; docs conversion không thay kết quả runtime certification.
