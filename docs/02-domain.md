# 02 — Domain

## 1. Quyết định nghiệp vụ đã chốt

| Decision | Quyết định | Hệ quả |
|---|---|---|
| D01 | Không sửa WordPress/Woo Core | Extension points/bridge/gates, không monkey-patch |
| D02 | Native tables cho sản phẩm/variants | Order/cart/payment tiếp tục WC |
| D03 | Preserve existing IDs | Migration/rollback không rekey orders/variations |
| D04 | Full Native vẫn giữ shell | Native source props, không zero-post |
| D05 | CDN URL canonical | Native gallery cần real attachment bridge |
| D06 | WC + Shopify CSV | Dialect pin + loss report, không mọi trường đều lossless |
| D07 | Migration single writer | Epoch/CAS/fence, không dual source |
| D08 | Rollback current data | Không restore old snapshot đè đơn/stock mới |

| Nội dung | Quyết định |
|---|---|
| Database bản đầu | MariaDB của site1.localhost, cùng DB và connection với WordPress; InnoDB; không làm MySQL-specific/PostgreSQL adapter trong dự án này |
| Product type native | `simple`, `variable`, `variation`; virtual/downloadable là khả năng của product, được mapping đầy đủ trước rollout |
| Type khác | grouped/external/subscription/bundle/composite giữ legacy; đăng ký adapter riêng mới được chuyển native |
| ID | Product ID/Variation ID là ID WordPress thật, không đổi khi migrate/rollback; variation dùng namespace ID chung `wp_posts` |
| Full Native Mode | Bảng plugin sở hữu product/variant props, meta của WC CRUD và inventory; đọc catalog bằng engine. Vẫn giữ post shell, taxonomy/review/attachment bridge cần thiết |
| Zero-post mode | Không thuộc bản này. Xóa toàn bộ CPT phá nhiều đường discovery/routing/query của WordPress; cần dự án tương thích riêng |
| Order/cart/payment | Tiếp tục do WooCommerce quản lý; không tạo hệ thống đơn hàng hay payment mới |
| Giá/tax | Lưu base price theo quy ước WC, một store currency; tính display tax, coupon, pricing filter bằng WooCommerce |
| Inventory | Một stock owner theo WC: variation tự quản lý hoặc kế thừa parent; không làm inventory đa warehouse kiểu Shopify |
| Images | External URL canonical; attachment bridge dùng ID thật, không ID giả/âm; không bắt buộc tải file ảnh về uploads |
| Theme | Chứng nhận classic Woo gallery + một theme tham chiếu; Blocks/Store API là adapter và gate riêng |
| CSV | Adapter Woo native dialect và Shopify current/legacy dialect có version; loss report cho trường không thể chuyển đổi |
| Admin | Màn quản trị WC hiện có và UI operations của plugin; không xây theme builder |
| Giới hạn performance | 100k/1M products là mục tiêu tương lai, chưa đo và không được tuyên bố đạt (ADR 003). Evidence chỉ ghi quy mô đã đo thực trên site1.localhost; không tuyên bố mọi query nhanh hoặc hỗ trợ mọi plugin |

## 2. Thuật ngữ

Product aggregate = parent/simple + options + variations; variant = WC variation ID thật. Stock owner = object inventory được mutate một lần. Projection = bản sao đọc, không source canonical. Cohort = parent/children/owner được chuyển authority chung. Epoch = quyền writer monotonic. Shell = WP post tối thiểu, không canonical business props. Asset = CDN source/renditions + optional real attachment. Legacy = WC CPT/meta.

## 3. Actors và permissions

| Actor | Cho phép | Không cho phép |
|---|---|---|
| Visitor | Catalog/public PDP/options/resolve | Draft/private/cost/meta/stock quantity exact |
| Product editor | Edit object trong WP capability phạm vi | Import/operations khi chưa có capability riêng |
| Media editor | Upload/select assets khi có upload_files + object edit | Xóa shared asset còn references |
| Store operator | Import/export nếu được cấp engine import/export | Cutover/rollback nếu chưa operations cap |
| Operations administrator | Validate/rollout/rollback trong scope | Bypass required gates |
| Worker | Job đã cấp, epoch/lease còn hợp lệ | User permission giả định vô hạn/epoch cũ |

Capability names/default assignment nằm tại 04 §1.8. Customer role vẫn do WP/WC quản lý, không RBAC SaaS mới.

## 4. Modes và data authority

| Mode | Nguồn chuẩn | Đọc sản phẩm | Ghi sản phẩm | Đọc catalog | Projection |
|---|---|---|---|---|---|
| `legacy` | CPT/meta WC | WC CPT | WC CRUD → CPT | Legacy | Chưa dùng bảng mới |
| `shadow` | CPT/meta WC | WC CPT | WC CRUD → CPT | Legacy + so sánh ngầm | Legacy → native/index |
| `indexed` | CPT/meta WC | WC CPT | WC CRUD → CPT | Engine ở query được hỗ trợ | Legacy → native/index |
| `native_bridge` | Bảng native của cohort | Data Store native | WC CRUD/engine → native | Engine | Native → legacy projection phục vụ tương thích/rollback |
| `full_native` | Bảng native của cohort | Native, không fallback props sang postmeta | WC CRUD/engine → native | Engine | Chỉ shell/taxonomy/media + projection legacy nếu còn rollback window |
| `rollback_draining` | Native đến khi barrier hoàn tất | Native | Đóng ghi của cohort + inventory/checkout barrier | Engine hoặc maintenance | Bắt kịp native → legacy |
| `legacy_restored` | Legacy sau switch epoch | WC CPT | WC CRUD → CPT | Legacy hoặc index mới rebuild | Legacy → native nếu bật shadow |

Không cho legacy và native đồng thời làm nguồn chuẩn của cùng aggregate. Mode/cohort registry ghi `authority_epoch`; mỗi command phải mang epoch đọc được và từ chối epoch cũ. Product + variants + stock owner cùng cohort; shared asset có lifecycle riêng. Global taxonomy tiếp tục do WP sở hữu và được project vào engine.

Sau cutover, `update_post_meta()` cho key đã được engine sở hữu phải đi qua adapter metadata API đã chứng nhận hoặc bị phát hiện và chặn rollout plugin đó. SQL ghi trực tiếp không được giả định là có hook. Không dùng last-write-wins theo timestamp để hòa giải hai nguồn.

## 5. Command/state transitions và recoverability

| Command | Precondition | State/result | Lối ra khi lỗi/kẹt |
|---|---|---|---|
| enable-shadow | Legacy source healthy | legacy → shadow | Không switch authority khi backfill failed; retry/resume |
| enable-indexed | Projection gates query pass | shadow → indexed | Index lỗi đọc legacy canonical nếu source legacy |
| validate-native | Certified paths + parity evidence | gate pass/fail, không mutate authority | Report blockers/revalidate sau repair |
| cutover | Gate pass cùng manifest/epoch; barrier acquired | indexed → native_bridge | Timeout/crash giữ nguồn cũ trước flip; sau flip recovery nguồn native |
| seal-native | Canary/native independence pass | native_bridge → full_native | Không seal, giữ native_bridge, không stale legacy fallback |
| begin-rollback | Native canonical accessible | native → rollback_draining | Maintenance cohort, retry reproject |
| finish-rollback | Current legacy parity verified | rollback_draining → legacy_restored | Timeout không flip; giữ native |
| commit-import | Validated immutable job config/hash | validated → running → completed/partial_failed | Resume/retry DLQ; cancel giữa chunks |
| cancel-job | Pending/running actor authorized | cancel_requested → cancelled | Commit chunk hiện tại xong trước dừng, không undo |
| retry-job | Retryable failure + lease available | failed → running | Fatal validation phải sửa source/validate job mới |
| asset-health | Allowed fetch URL | pending → healthy/broken | Background retry; placeholder render |

Job terminal states: completed, partial_failed, failed, cancelled; `blocked` là gate/permission/business cần repair, không tự retry vô hạn. Human approval của production rollout chỉ phát sinh nếu nhiệm vụ người dùng chưa authorize rollout; docs authoring/isolated test không cần approval thêm.

## 6. Giá, thuế, sale và các ví dụ

Base price string theo store currency, precision 6 decimals; null = unpriced, `0.000000` = free. WC tax/display/coupon/pricing filter xử lý tại boundary không persist display price vào base field. Regular 24.95 + sale 19.95 trong sale interval → effective base 19.95; hết interval →24.95. Tax display location/customer context phải vào cache identity. Shopify mapping price 19.95 và compare-at24.95 thành regular24.95/sale19.95; compare-at thấp hơn price lưu source/report không tự đảo.

## 7. Options và variation resolver

1. Chuẩn hóa IDs/value keys bằng mapping option; label chỉ dùng display, không identity.
2. Dùng các variation active của cùng parent, xét combination cụ thể và WC wildcard `Any`.
3. Partial selection trả allowed values tồn tại trong ít nhất một variation tương thích. Phân biệt `exists` và `purchasable`; không tự tạo Cartesian product.
4. Full selection không có variation → `none`. Nhiều matching wildcard/exact → thứ tự tương thích baseline WC (không tự ưu tiên exact nếu WC baseline chọn khác); nếu dữ liệu không quyết định duy nhất theo policy → `ambiguous`, chặn mua và tạo report.
5. Full exact trả resolved stock owner, giá và ảnh variation/parent fallback. Client response bị stale theo request_sequence bị loại; đổi parent version phải refresh options.
6. Product simple không cần option giả trong UI; Shopify default variant chỉ là mapping CSV.

Ví dụ Black-M + White-L: chọn Black làm Size chỉ cho M ở coherent mode; chọn Black-L →none, không tạo combination giả. Woo Any giữ wildcard riêng. Rename label không đổi identity value ID; reorder option không tạo variation mới.

## 8. Inventory và order interactions

Stock operation idempotency theo order/item/kind/event. Inherited-stock variants cùng owner parent, stock1 không bán được hai units với no-backorder. Reserve available = canonical quantity − active holds của orders khác; giữ current order không double-count. Classic/Blocks reservation phải bridge được và pass real integration test trước native. Fractional quantity chỉ certified khi WC path/plugin hỗ trợ, không chỉ vì DB decimal lưu được.

## 9. Catalog semantics

- Engine chỉ lấy 24–48/100 IDs + card projections; không hydrate mọi kết quả. WC object hydration batch/cache cho trang hiện tại, giữ thứ tự. Single detail dùng canonical repository/Data Store.
- OR trong các value cùng facet; AND giữa các facet. Với color=black và size=L, **variant-coherent mode** phải có cùng variation thỏa hai điều kiện; WC-compatible mode giữ semantics Woo đã pin nếu khác. API khai báo mode; UI không gộp count của hai mode.
- Taxonomy hierarchy expand bằng mapping descendants có cache version. Counts distinct parent; stock inheritance/backorders theo policy. Facet count tính self-exclusion rõ ràng, không JOIN nhân bản đếm variation thành products.
- Keyset sort dùng tuple `(sort_value,product_id)` deterministic; null-price ordering rõ (đặt cuối mặc định), collation title và direction pin. Cursor HMAC chứa query hash, generation, boundary, issued_at, expiry, cohort epoch.
- Cursor mặc định là live traversal: khi sort field thay đổi giữa request có thể dịch vị trí. Không hứa snapshot tuyệt đối. Export dùng snapshot staging immutable; public feed có thể dùng frozen ID result khi cần ổn định, TTL riêng.
- Exact totals/numbered pages có count riêng có giới hạn; deep OFFSET chỉ giữ cho WC routes cần page number và phải benchmark/cap. Cursor không tự thay SEO pagination URL truyền thống.
- Dynamic pricing/multi-currency/tax-location: cache context có allowlist; nếu engine không thể bảo đảm filter giá trước pagination thì route legacy có hỗ trợ hoặc trả blocker native cho feature đó. Không filter lại sau lấy page làm sai total/facets.
- Search bản đầu: SKU exact/prefix + title search phù hợp DB với limit. Không cam kết semantic/full-text scale triệu products bằng LIKE `%term%`; external search là extension ngoài module được chọn.

## 10. Media lifecycle

Asset identity giữ query/version URL; không automatic CDN delete. Shared asset alt có product-level override; WP attachment default alt dùng fallback/context adapter, không hứa one attachment metadata biểu diễn mọi product alt. Native gallery/SEO integration tại 10 §3 và 09 §4.

Asset có hai nguồn: external URL (không yêu cầu object key hay quyền xóa storage) và managed file do hệ thống upload lên storage mặc định (có provider, bucket, object key, kích thước, hash). Managed file nằm trong V1 qua upload server-side; vẫn không có sửa/crop file local. Mỗi product có thư viện ảnh riêng là các liên kết; ảnh variation chỉ chọn từ thư viện đó. Gỡ ảnh khỏi product chỉ bỏ liên kết; xóa file là thao tác riêng, chỉ managed asset không còn reference, không bao giờ tự động khi gỡ liên kết hay hủy bản nháp. Đổi storage mặc định không di chuyển hay viết lại ảnh cũ; upload thất bại không được thay bằng provider khác.

## 11. Import/export rules

Rules canonical tại 10 §6 và 10 §7. Dry-run không tạo products/terms/attachments. Default merge, dry_run=true, image_mode=external, strict export. Replace variations chỉ explicit preview/tombstone IDs, không tự xóa vì thiếu row. Cancel không undo committed groups.

## 12. Identity, deletion và hooks

Global WP product/variation IDs giữ bất biến; real shell preallocation lỗi được cleanup nhưng ID không reuse. Trash/restore khác permanent delete; historical order IDs vẫn dereference snapshot nếu product deleted. WC repeated meta IDs/serialization cần parity; numeric IDs reserve từ WP global meta allocator theo 03 §5, không namespace AUTO độc lập. Hook events after native commit nhưng không hứa exactly-once external delivery; legacy WP creation hooks có preallocation side effects theo 03 §5.

## 13. Bất biến

| ID | Bất biến |
|---|---|
| INV01 | Một authority mỗi aggregate và epoch fencing |
| INV02 | Product/variation ID giữ qua migrate/rollback |
| INV03 | Variant/options/value thuộc đúng parent |
| INV04 | Active SKU/combination uniqueness theo baseline identity policy |
| INV05 | Checkout revalidate canonical price/stock/options |
| INV06 | Stock owner chỉ bị mutate một lần cho mỗi operation key |
| INV07 | No-backorder concurrent checkout không oversell |
| INV08 | Projection/event cũ không overwrite revision mới/tombstone |
| INV09 | Public surfaces không leak private/cost/secret URLs |
| INV10 | Media shared không cascade delete |
| INV11 | Dry-run không mutation và resume không duplicate |
| INV12 | Full native không owned-prop fallback stale legacy |
| INV13 | Rollback giữ current products/stock/orders/meta |
| INV14 | Core files không sửa |
| INV15 | Unsupported CSV data có loss report |
| INV16 | Unknown/failed/stale gate không cutover |

## 14. Defaults, deferred decisions và câu hỏi

| Topic | Default để triển khai | Nếu đổi |
|---|---|---|
| Native types | simple/variable/variation | Thêm adapter + gates cho type khác |
| Plugin ecosystem | Core-only test fixture; unknown third parties blocked native | Populate matrix T01 |
| Stock locations | Một owner WC | Multi-warehouse plan riêng |
| Native mode rollout | legacy/shadow default, canary manifest explicit | Cutover có current parity gates |
| Rollback window | 30 ngày sau cohort cutover | Ops ADR, không cleanup trước restore drill |
| Job raw/artifacts | Retention 7 ngày; audit 90 ngày (giá trị mặc định cấu hình, enforcement hoãn theo ADR 003, không thuộc T46) | Compliance/operator cấu hình, no secret logs |
| Public cursor | 15 phút, old generation grace 5 phút | Capacity/storage + config update |
| Performance targets | Proposed 07 §6 | Benchmark evidence + ADR nếu thay budget |

Không có câu hỏi nghiệp vụ đang chặn creation bộ docs. Package/runtime verification và third-party plugins là công việc T01, không giả báo certified.
