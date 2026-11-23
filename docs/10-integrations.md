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

Có hai update mode: `merge` không xóa variation thiếu trong file; `replace_variants` explicit, có dry-run list removals và vẫn tombstone ID được order tham chiếu. File đổi option value không tự đổi variation ID native nếu có identity mapping xác minh; export Shopify không cam kết Shopify giữ ID. Import lại Shopify CSV bỏ qua sản phẩm có input giống lần import sạch gần nhất và native state không đổi từ đó (sửa tay, đổi tồn kho hoặc import khác đều làm import lại); job config `skip_unchanged=false` ghi lại mọi sản phẩm.

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
| Handle / title / vendor / type / tags | Handle source grouping và external mapping; vendor/type custom meta/registered taxonomy theo config. Shopify Product category không tự map thành Woo category tùy ý: mặc định (`category_mode=none`) cột này chỉ được báo trong `ignored_cols`; khi operator chọn `category_mode=path` cho job, đường dẫn `A > B > C` (giải mã HTML entity, tách theo `>`, bỏ đoạn rỗng) được tạo thành cây `product_cat` (khớp theo tên dưới cùng parent, tạo mới nếu chưa có) và sản phẩm được gán vào danh mục lá; danh mục mặc định bị thay bằng danh mục lá, các danh mục khác giữ nguyên; ô rỗng không thay đổi gì. Trang danh mục cha liệt kê sản phẩm của danh mục con (`include_children`) như WordPress. `Tags` (phân tách dấu phẩy) map thành `product_tag`: ô không rỗng thay thế tags của sản phẩm, ô rỗng không xóa tags hiện có. Sản phẩm import chưa có `product_cat` nào được gán `default_product_cat` như WooCommerce làm khi lưu sản phẩm không danh mục; không xóa danh mục đã có. Các cột có mặt nhưng chưa có mapping Woo (`Product category`, `Type`, `Vendor`) được liệt kê một lần trong job (`ignored_cols`) và dòng WARNING của log khởi tạo, không bỏ qua im lặng |
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

## 12. Product feeds cho Google và Microsoft Merchant Center

Nguồn đặc tả (tra cứu 2026-11-23, phải đối chiếu lại khi nền tảng đổi đặc tả): [Google Product data specification](https://support.google.com/merchants/answer/7052112), [Google product file](https://support.google.com/merchants/answer/12631822), [Google scheduled fetch](https://support.google.com/merchants/answer/1219255), [Microsoft feed files](https://help.ads.microsoft.com/apex/index/3/en/60050), [Microsoft scheduled download](https://help.ads.microsoft.com/apex/index/3/en/56839), [Microsoft dùng file Google](https://help.ads.microsoft.com/apex/index/3/en/51110).

**Định dạng.** Google: XML RSS 2.0 với namespace `xmlns:g="http://base.google.com/ns/1.0"` (`title`, `link`, `description` không prefix, các thuộc tính khác dùng `g:`; thuộc tính lặp như `additional_image_link` là nhiều phần tử; `shipping` là phần tử lồng `g:country`, `g:service`, `g:price`) hoặc TSV UTF-8 có dòng header. Microsoft: TSV là định dạng chính (tên cột giống Google), XML chỉ được nhận khi đúng định dạng XML của Google. Giới hạn file: Google 4 GB, Microsoft 3 GB sau giải nén; cả hai nhận `.gz`. Lịch tải tự động của cả hai nền tảng tối đa mỗi ngày một lần (cùng nút "fetch now" thủ công), nên làm mới file nhanh hơn chỉ có ích khi tải thủ công hoặc ở lần tải kế tiếp.

**TSV.** Ký tự tab, CR, LF trong giá trị được thay bằng khoảng trắng; thuộc tính nhiều giá trị nối bằng dấu phẩy; `shipping` dùng dạng `country:region:service:price`. Tập cột cố định theo cấu hình feed để publish có thể stream mà không quét trước.

**Mapping mặc định (mỗi thuộc tính có thể ghi đè bằng giá trị cố định, field sản phẩm, taxonomy, option hoặc meta):**

| Thuộc tính | Nguồn |
|---|---|
| `id` | SKU của variation/sản phẩm; thiếu SKU dùng `wc_{id}`. Tối đa 50 ký tự |
| `item_group_id` | ID sản phẩm cha (chỉ khi xuất theo variation) |
| `title` | Tiêu đề sản phẩm, chế độ variation nối thêm nhãn các value (`Áo - Đỏ / XL`). Tối đa 150 ký tự |
| `description` | Mô tả variation nếu có, không thì mô tả sản phẩm rồi mô tả ngắn; bỏ HTML/shortcode, tối đa 5000 ký tự |
| `link` | `get_permalink()` của post shell; variation thêm `attribute_{option key}={value_key}` để trang mở đúng variation (09 §3) |
| `image_link`, `additional_image_link` | Ảnh primary của variation, fallback ảnh featured của sản phẩm; ảnh phụ là gallery variation rồi gallery sản phẩm, tối đa 10. URL qua `getStableExportUrl()` và `sanitizePublicUrl()`, ảnh `health = broken` bị loại |
| `price`, `sale_price`, `sale_price_effective_date` | `EffectivePriceResolver`: `price` là giá regular, `sale_price` chỉ khi sale đang hiệu lực, khoảng ngày ISO 8601 khi có lịch sale. Định dạng `12.00 USD` theo tiền tệ cửa hàng |
| `availability` | `in_stock`, `out_of_stock`, `backorder` theo cùng quy tắc tồn kho với catalog index (`manage_stock`, `quantity`, `backorders`, `stock_status`) |
| `condition` | Mặc định `new` |
| `brand` | Taxonomy `product_brand`, option `brand`, hoặc giá trị cố định |
| `gtin`, `mpn`, `identifier_exists` | `global_unique_id` trong `extra_props`; MPN mặc định là SKU; không có GTIN và MPN thì `identifier_exists = no` khi cấu hình cho phép |
| `product_type` | Đường dẫn danh mục sâu nhất `A > B > C` |
| `google_product_category` | Giá trị mặc định của feed, ghi đè theo danh mục |
| `color`, `size`, `material`, `pattern`, `gender`, `age_group` | Option value của variation theo mapping option → thuộc tính |
| `shipping_weight` | Cân nặng variation hoặc sản phẩm, đơn vị cửa hàng (`lbs` xuất là `lb`) |
| `shipping` | Không xuất (dùng cài đặt tài khoản Merchant Center) hoặc giá cố định theo feed |
| `custom_label_0`…`custom_label_4` | Giá trị cố định hoặc nguồn mapping |

**Lọc và loại trừ.** Chỉ sản phẩm `publish`, `catalog_visibility` khác `hidden`, có post shell, loại `simple` hoặc `variable`. Grouped/external bị bỏ qua với lý do. Variation `status != publish` hoặc không có giá bị loại. Cấu hình có thể lọc theo danh mục gồm/loại, bỏ hàng hết hàng, bỏ item thiếu ảnh. Sản phẩm authority legacy được đọc qua WooCommerce CRUD; sản phẩm native không bao giờ đọc postmeta.

**Validation (mã issue).** Lỗi chặn item (`eligible = 0`): `missing_id`, `missing_title`, `missing_link`, `missing_image`, `missing_price`, `invalid_price`, `missing_shell`, `unsupported_type`. Cảnh báo (vẫn xuất): `missing_brand`, `missing_identifier`, `missing_google_category`, `title_truncated`, `description_truncated`, `id_too_long`, `tax_inclusive_market`. Microsoft dùng cùng bộ luật bắt buộc. Diagnostics lưu số lượng theo mã kèm tối đa 50 item mẫu, không ghi log từng item.

**Thị trường.** Mỗi feed một quốc gia đích, ngôn ngữ và tiền tệ; tiền tệ phải bằng tiền tệ cửa hàng. Plugin không quy đổi tiền tệ và không tính lại giá gồm thuế: với thị trường yêu cầu giá gồm thuế (EU, UK, AU…) mà cửa hàng lưu giá chưa thuế, validator gắn `tax_inclusive_market`.

**Mô hình cập nhật.** Năm chế độ:
- Manual Regenerate: "Publish now" xuất file từ items hiện có; "Full rebuild" dựng lại mọi item.
- On Change: hook thay đổi đánh dấu sản phẩm bị ảnh hưởng vào `feed_dirty`; worker render lại item của các sản phẩm này.
- Debounced Publish: xuất snapshot mới khi có item đổi hash và đã yên 2 phút hoặc thay đổi đầu tiên đã chờ 5 phút; hai lần publish cách nhau tối thiểu 15 phút; không có thay đổi thì không publish.
- Scheduled Reconciliation: mỗi tick render lại một lát sản phẩm cuốn chiếu (phủ toàn catalog khoảng 24 giờ), so hash, dọn item của sản phẩm đã xóa hoặc không còn khớp bộ lọc; item có `next_change` đến hạn (sale bắt đầu/kết thúc) được render lại.
- Full Rebuild: khi tạo feed, khi `config_hash` đổi, khi cài đặt cửa hàng ảnh hưởng render đổi (tiền tệ, đơn vị cân nặng, cấu trúc permalink), khi dirty vượt 20% số item hoặc drift của một vòng đối soát vượt 1%, hoặc khi người dùng yêu cầu. Build ghi generation mới rồi chuyển; generation và file cũ phục vụ cho tới khi chuyển xong. Màn hình Edit đang mở tự chạy tiếp build đã yêu cầu (sau Create, Save đổi cấu hình hoặc Full rebuild) bằng các bước `continue` 20 giây, nên feed được dựng và publish ngay mà không chờ worker; đóng trang thì worker làm nốt từ cursor đã lưu.
- Chạy khi chưa có cron (P036): system cron `wp wpe feeds tick` là runner chính. Khi heartbeat worker vắng hoặc cũ hơn 5 phút mà có việc feed đang chờ (dirty, sale đến hạn, build đã yêu cầu/đang dựng, cửa sổ debounce), `FeedAutoRunner` chạy đúng một tick `FeedScheduler` tối đa 20 giây ở cuối request web, sau `fastcgi_finish_request()` nên người dùng không phải chờ; cùng lock theo feed, cùng luật debounce, publish gate và đối soát; một tiến trình PHP mỗi lúc, cách nhau tối thiểu 30 giây (request vừa đổi sản phẩm được chạy ngay). Fallback không ghi heartbeat, nên cảnh báo thiếu worker vẫn hiện; nó ghi lần chạy gần nhất vào `control` key `feed_autorun_last` (P038). Khi fallback bật, cảnh báo nói feed đang cập nhật từ request của site kèm lần cập nhật gần nhất và có thể publish trễ lúc site vắng; khi fallback tắt mới nói thay đổi không được publish. Tắt bằng `define('WPE_FEEDS_AUTORUN', false)` hoặc filter `wpe_feeds_autorun`. Không có request PHP nào thì không có gì chạy, nên publish sau cửa sổ yên có thể trễ hơn 5 phút trên site vắng; đối soát chỉ chạy kèm khi có việc khác.

Tín hiệu thay đổi: `wpe_aggregate_changed` phát từ `OutboxWorker::publishEvent()` (create/update/trash/restore/meta/stock/type change), purge trong `ProductRepository::delete()`, `set_object_terms`, `deleted_term_relationships`, `edited_term` (danh mục, tag, brand), `wpe_product_media_changed`, và hook WooCommerce sản phẩm/stock cho sản phẩm authority legacy.

**Publish gate.** File `.part` chỉ thay file hiện hành bằng `rename()` khi: XML well-formed (XMLReader stream) hoặc mọi dòng TSV đủ cột; số item lớn hơn 0; số item không giảm quá 50% so với file tốt gần nhất (cấu hình được). Gate lỗi giữ file cũ, ghi `last_error` và hiển thị trên Health.

**URL công khai.** `/wpe-feed/{feed_key}/{token}.xml|.tsv` (thêm `.gz` cho bản nén), fallback `?wpe_feed={feed_key}&token=…`. Token là HMAC-SHA256 (64 ký tự hex) của `token_seed` ngẫu nhiên 32 byte với salt của site, không lưu dạng rõ, so sánh hằng thời gian; xoay token bằng cách đổi seed. Phản hồi gửi `ETag`, `Last-Modified`, hỗ trợ `304`, và `X-Accel-Expires: 0` để page cache FastCGI không lưu file lớn. Feed tắt hoặc chưa có file tốt trả 404. Không chặn Googlebot/AdsBot-Google/bingbot ở đường dẫn này.
