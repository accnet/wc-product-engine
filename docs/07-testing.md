# 07 — Testing

## 1. Docs verification hiện có và runtime chưa có

```bash
node scripts/check-docs.mjs --strict
node scripts/plan.mjs check
node scripts/guard.mjs --all
```

Những scripts này chạy ngay từ docs package bằng Node ≥18, không cần npm install. PHP/browser runtime tests được T02/T04 scaffold; không đổi thiếu runner thành exit0.

## 2. Matrix và test layers

Task backlog định nghĩa **212 test requirements R01–R212** (R203–R212 bổ sung bởi P001/ADR 003). Đây là yêu cầu kiểm thử để triển khai, chưa phải 212 test đã được viết/chạy. Có thể nhiều IDs dùng chung suite nhưng evidence phải trace được từng yêu cầu.

| Test layer | Thực hiện trên | Nội dung |
|---|---|---|
| Unit | Pure domain/query compiler/DTO | Combination vector, wildcard policy, price/date rules, URL policy, CSV absent vs blank, cursor signatures |
| Contract | Schemas + pinned WC interfaces + dialect goldens | DTO/event/DB mapping/API errors/capabilities, WC method signatures, exact CSV headers |
| DB integration | MariaDB của site1.localhost (không có ma trận MySQL) | Transactions/CAS/SKU races/locks/leases/DDL resume/precision/deadlocks/retries |
| WordPress/Woo integration | DB thật của site1.localhost (backup trước test phá hủy) | Factory/CRUD/meta/taxonomy/permalink/stock/reservation/REST/admin/Store API/gallery APIs |
| Browser E2E | Certified theme + Blocks surfaces | Shop filtering/page counts, PDP selector, gallery/zoom, mini-cart/checkout, admin imports/operations |
| Differential | Same seed legacy vs engine | Supported semantics: IDs/order/price/stock/options/media/meta/lifecycle/CSV subset |
| Fault injection | site1.localhost dev, có backup | Crash preallocate/commit/postcommit, queue loss/out-of-order, DB/cache/CDN failure, migration barrier timeout |
| Scale | Quy mô thực đo của site1.localhost (hiện ~vài trăm sản phẩm); 100k/1M là mục tiêu tương lai, chưa đo (ADR 003) | Query plans/latency/storage/RAM/freshness/worker throttling under storefront load |

Các tình huống không được bỏ qua:

1. Parent chỉ có Black-M và White-L: Black-L không tồn tại; resolver, coherent catalog filter và add-to-cart đều xử lý đúng.
2. WC `Any` variation + exact variation overlap: match giống policy baseline, không resolver một variant còn native Woo JS/checkout dùng variant khác.
3. Hai variations cùng stock owner parent, stock=1, hai orders cạnh tranh: reserve/reduce nhất quán, không bán hai units khi backorders=no.
4. Paid callback lặp, retry sau timeout, cancel/reopen và partial refund: operation keys phân biệt event/item/quantity, không double change stock.
5. Giá `0` và missing price; sale start/end/DST; tax/customer dynamic context: không lẫn purchasable/free/unpriced, totals và facets đúng policy.
6. Browser chọn nhanh nhiều options: response cũ tới muộn không thay hidden variation_id/price/image hiện tại; reset gallery trở về parent.
7. CDN ảnh shared, signed delivery URL, broken URL và redirect private: không xóa shared asset, không lộ token, không fetch private network.
8. Import interrupted/resumed, shuffled parent/variation rows, duplicate SKU, blank stock, thiếu cột ảnh: không duplicate aggregate/asset, không clear data ngoài dialect rule.
9. Shopify image-only rows và variant image: không tạo variant thừa; Woo export unsupported alt/multi-gallery phải có loss report.
10. Native product mới phát sinh đơn hàng rồi rollback: mọi IDs/current stock/metadata/orders còn đúng; old snapshot không được đè current values.
11. Poison legacy price/stock/meta ở test native: PDP/API/cart/checkout không đọc poison, kể cả reservation paths.
12. Deactivate/forced plugin removal/restore từ backup: không mô tả fallback như có thể tự chạy nếu plugin code đã không còn; recovery path được rehearsed.

Required CI: schema/contracts + fast tests mỗi PR; integration matrix cho thay storage/query/stock/media; E2E certified surfaces và fault suite trước release; scale job milestone/nightly có dataset manifest. Thay code sau evidence yêu cầu chạy lại các gate bị ảnh hưởng. Không bắt chạy toàn bộ dataset scale cho sửa wording tài liệu.

## 3. Fixture setup

T01 pin test runtime/core-only theme theo 01 §8, DB private theo 01 §9. T04 seed simple/variable/wildcards/shared stock/Unicode/free vs null/sale DST/download/repeated meta/orders/shared CDN; scalable generator seed và manifest. Không clone customer PII từ production vào tests. Canonical comparators không normalize mất price/stock/IDs/media ordering.

## 4. Test authoring convention

PHP `tests/integration/TxxTest.php`, test method ghi requirement ID; unit tests pure ports/fake clock, integration real DB/WC. JS/browser test requirement liên kết task registry; assertions observable business outcome, không mirror implementation. Runtime harness phải fail khi requirement chưa implement, skipped/blocked critical không pass.

## 5. Commands sau harness implementation

```bash
npm run test:task -- T12
vendor/bin/phpunit --filter T12
npx playwright test --grep R111
node scripts/verify-task.mjs T12
```

T02 tạo npm test:task wrapper nhận ID registry và chạy commands suite hợp lệ; wrapper cấm shell command injection từ task ID, output capture/exit status vào evidence. Chạy một subset không đủ nếu task còn requirements chưa có receipt.

## 6. Proposed performance budgets

Những số dưới đây là **mục tiêu kiểm thử để chốt ở T01/T47**, không phải benchmark đã đo hay lời hứa độc lập với hardware. Test server/DB/cache/concurrency/dataset distribution phải được ghi rõ.

| Workload | Budget khởi điểm |
|---|---|
| Catalog IDs 48 items, selective indexed filter, warm DB, quy mô đo thực tế (1M là mục tiêu tương lai) | DB p95 ≤100 ms; so baseline cùng query, mục tiêu ≥2× nhanh hơn query đang bottleneck |
| Facet counts 3 facets có selectivity đủ | DB p95 ≤250 ms; exact all-catalog counts tách workload, không áp số này mọi trường hợp |
| Resolver full selection, product 10k variants | Server p95 ≤150 ms trên DB warm; payload không tuyến tính theo tổng variants |
| Catalog/PDP PHP processing tổng | p95 không chậm hơn baseline optimized >10% nếu không có ADR tradeoff; loại riêng CDN/browser/network khỏi server time |
| Checkout stock path | p95 không chậm hơn baseline >10%; 0 oversell khi no-backorder và 0 double stock operation trong race suite |
| Index freshness | Bình thường p95 ≤5 giây, p99 ≤30 giây trên worker capacity công bố; overload alert và catch-up target riêng |
| Import/backfill RAM | Memory bounded theo chunk/group budget; cấu hình khởi điểm ≤256 MiB worker với spill disk; group quá lớn reject hoặc spill, không OOM |
| Background load | Trong import/rebuild concurrent load, checkout vẫn giữ budget accepted; worker concurrency/throttle đo được |
| Recovery | Interrupted worker job resume không cần chạy lại toàn catalog; rollback RTO phải được đo theo cohort và mirror lag, không đặt một số chung triệu rows |

Index/generation rebuild phát sinh storage tạm ≥2 generations cộng native/legacy mirrors; capacity planning gồm retained post shells, variation shells và external attachment records. Benchmark 1M (mục tiêu tương lai, chưa thực hiện) sẽ là stress/capacity evidence, không bắt buộc public count/facet query nào cũng đạt 100 ms.

## 7. Required release gates

| Gate | Evidence tối thiểu | Fail/blocker |
|---|---|---|
| G01 Contracts | OpenAPI/event/DB/CSV validate; WC props/method coverage | Missing field/method, incompatible breaking contract |
| G02 Data parity | Count/hash + semantic diff đầy đủ critical fields cho cohort; IDs/options/stock/media references | Unresolved orphan, wrong parent/SKU, critical diff bất kỳ |
| G03 CRUD | WC object/admin/REST, meta, lifecycle, deletion/type transitions | Chỉ unit mock pass, sai return value hoặc hidden CPT fallback |
| G04 Query | IDs/order/visibility/counts/facets/page semantics đúng certified modes | False combination, double count, private leak, unsupported filter silently omitted |
| G05 Inventory | Reserve/reduce/release/refund concurrent/idempotent; parent inheritance; classic/Blocks | Oversell no-backorder, double reduce/restock, unknown stock SQL path |
| G06 Media/selector | Native classic + chosen Blocks gallery; WP image APIs; selector/server cart guards | Wrong variant image/price, race stale selection, fake attachment ID |
| G07 CSV | Woo native UI + plugin paths certified; Shopify local profiles; loss reports | Silent data loss, side-effect dry-run, duplicate resume, claim live verification khi chưa thử |
| G08 Rollback | Reproject current data, barrier/epoch/cache switch, fresh orders/stock preserved | Chỉ đổi config flag hoặc restore snapshot cũ làm mất current stock |
| G09 Performance | **Chưa đạt** (ADR 003): scripts/EXPLAIN chạy ở quy mô thực của site; chưa có dataset 100k/1M nên budget chưa được chứng minh ở quy mô mục tiêu | Latency/load vượt budget chưa có ADR; đầy RAM theo catalog size |
| G10 Native independence | Legacy owned props poisoned/off mirror vẫn pass certified flows | Native đọc stock/price/options từ stale CPT/meta |
| G11 Security/core integrity | Auth/permissions, SQL/CSV/URL/input tests; checksum Core | Public secrets/private products, injection/SSRF, Core file modified |
| G12 Operations | Job resume/leases/DLQ/lag monitoring; recovery runbook; backup restore drill | Missing evidence, stale gates, bypass flags, single writer không enforce |

Gate evidence gắn base commit + content digest, exact dependency versions, environment, cohort manifest hash, authority epoch, fixture/dataset ID, test commands, timestamps, kết quả pass/fail/blocked/skipped. Không lấy một README “tests passed” làm evidence. Gate hết hạn khi đổi versions, plugins, schema, pricing policy, source/cohort hoặc phát hiện reconciliation gap.

## 8. Lệnh lỗi và blocked environment

Ghi command, exit, env versions, stderr summary và requirement ID. Fix deterministic failure rồi rerun affected tests; flaky race test không remove assertion/skip. Missing runtime/tooling resolve T01/T02 scope; nếu không sửa được ghi QUESTIONS với default attempted/impact. Không mark done vì “máy không chạy được nhưng chắc đúng”.

## 9. Fault tests và evidence

Chỉ trên site1.localhost dev (không production), backup/Duplicator trước khi chạy; poison owned postmeta nhằm phát hiện fallback, không production. Crash preallocate/commit/outbox/shell projector; queue replay/out-of-order; checkout/refund races; migration barrier timeouts. `docs/evidence/Txx/result.json` có schema ở docs/evidence/README.md; result validation không chứng minh logic tốt, reviewers xem raw outputs/business assertions.
