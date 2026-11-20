# QUESTIONS

## 1. Status

Hai câu hỏi mở: OD-16 và OD-17 (mục 4). Các câu hỏi OD-12, OD-13, OD-14 và OD-18 đã được trả lời, xem mục 3. OD-01…OD-11 đã được trả lời hoặc áp dụng, xem mục 3 và `docs/audits/OPEN-DECISIONS.md`. Runtime pins/extensions chưa certified là T01 deliverables.

## 2. Entry format

Mỗi entry: task ID — tóm tắt; status open/answered; doc section; commit; context; default đã thử; blocker và impact; proposed resolution; answer và affected tasks. Thiếu business rule material thì dừng dependent task, tiếp tục independent task trong phase được authorize.

## 3. Câu hỏi đã trả lời

| ID | Tóm tắt | Status | Doc section | Quyết định |
|---|---|---|---|---|
| OD-01 | Phiên bản WP/WC chuẩn | answered | 01 §1 | A: re-pin WP 7.1.3 / WC 11.2.1, chạy lại evidence |
| OD-02 | Quy mô benchmark | answered | 07 §7 | C: hạ claim, G09 chưa đạt, 100k/1M là mục tiêu tương lai |
| OD-03 | Digest evidence | answered | 06 T02 | A: giữ digest nghiêm ngặt, chạy lại evidence khi freeze |
| OD-04 | Phạm vi T46 | answered | 02 §14 | B: guarded postmeta cleanup, retention còn lại hoãn (ADR 003) |
| OD-05 | Residue DB | answered | 06 T04 | A: backup mới, dọn residue, residue gate |
| OD-06 | Test selector R107 | answered | 06 T27 | A: Playwright behavior test |
| OD-07 | Mở lại contract | answered | 06 | A: một lần, thêm R203–R212 |
| OD-08 | Audit độc lập | answered | audits/READINESS-GATE | Waiver của user: bỏ audit độc lập |
| OD-09 | Seed/đo 100k/1M | answered | 07 §7 | Waiver của user: bỏ 100k/1M, G09 vẫn chưa đạt |
| OD-10 | F15 acquireBatch | answered | P002.1 | Sửa code, có test hồi quy |
| OD-11 | 22 AC requirements_only | answered | P002.2, P002.3 | Thêm anchor có điều kiện thật |
| OD-12 | Worker epoch khi lưu biến thể từ editor | answered | 02 (epoch/authority) | Dùng epoch hệ thống hiện hành (OutboxWorker::getCurrentEpoch) thay vì hằng 1 khi sản phẩm chưa có object state; không đổi LegacyMirror/fence (P006.1) |
| OD-13 | ShopifyBatchImporterTest thất bại trên baseline | answered | 04 (import) | Nguyên nhân là fixture của test lệch cột so với header (grams 500 bị đọc thành SKU và trùng SKU thật), không phải lỗi importer; sửa fixture theo tên header, giữ nguyên assertion (P008.1) |
| OD-14 | Hai khóa epoch lệch nhau (authority_epoch 2, current_epoch 9) | answered | 02 §authority | authority_epoch là nguồn chuẩn theo contract; current_epoch là bộ đếm outbox độc lập (người ghi duy nhất không có caller trong src). Caller của mirror lấy worker epoch từ LegacyMirror::getAggregateEpoch (state epoch, nếu không có thì global authority epoch) thay cho current_epoch; không đổi giá trị hay ngữ nghĩa của hai khóa (P009.1) |
| OD-15 | P011 Per-product media library (A1–A5 & P011.12 gate) | answered | 04 §7, 09 §10 | A1: Tự động dùng Local Storage khi plugin S3 (`images-space`) chưa active và dùng S3 khi plugin active; A2: Ẩn hộp ảnh gốc của WP (`postimagediv`, `woocommerce-product-images`) trên sản phẩm Simple/Variable và thay bằng hộp Product Media (`#wpe_product_media`); A3: Upload trực tiếp trong Variant Image Picker tự động thêm vào thư viện sản phẩm và gán cho biến thể trong 1 bước; A4: Khi xóa ảnh sản phẩm, hiện Impact Preview và cho phép xóa luôn file vật lý (`DELETE /admin/assets/{id}/file`) nếu ảnh không còn nơi nào dùng; A5: Hỗ trợ cả kéo-thả nhiều file từ máy tính và thêm qua URL CDN ngoài. P011.12 (`StorageLiveRoundTripTest.php`) giữ trạng thái gated chờ môi trường cài đặt/kích hoạt plugin `images-space` S3 thật. |
| OD-18 | WooCommerce 11.3.0 thêm 3 prop customs (`customs_commodity_code`, `customs_country_of_origin`, `customs_description`, postmeta `_customs_*`) chưa có trong `wc-props-mapping.json` | answered | 03 §1, `docs/plans/P021-wc113-customs-legacy-only/plan.md` | Fail-closed (người dùng chọn 2026-11-20): 3 prop ghi trong `excluded_props` với policy `legacy_only_blocks_native`; data store native từ chối lưu giá trị khác rỗng (`wpe_legacy_only_prop`), cutover từ chối sản phẩm có dữ liệu customs. Hỗ trợ native để plan sau. |

Chi tiết: `docs/audits/OPEN-DECISIONS.md`, `docs/adr/003-audit-remediation-scope.md`. Affected tasks: T01, T02, T04, T27, T38, T40, T42, T46, T47, P011.12.

## 4. Câu hỏi mở

### OD-16 — P013.4 — T02Test fail vì trạng thái site, không vì code

- Status: open (default đã áp dụng).
- Doc section: 06 T02; `docs/plans/P013-architecture-hardening/plan.md` §5.
- Commit: P013.4 code nằm trong commit P014.1.
- Context: DoD của P013.4 gồm `tests/integration/T02Test.php`. Test này fail cả ở baseline trước P013 (`Flag query_enabled must be false by default`, `Cohort conflict was not deterministic…`) vì option `wpe_feature_flags` thật của site đang bật `query_enabled`, `media_enabled`, `resolver_enabled`, `native_writes_enabled`, `single_price_display`, `show_selected_value`, `show_help_text`. Sau P013.4, T02Test cho đúng các dòng FAIL như baseline.
- Default đã thử: không đổi flag của site (đó là cấu hình của người dùng), không nới test. Block P013.4, chuyển phần việc sang amendment plan P014 với DoD dùng `T15Test` (hooks/lifecycle, pass ở baseline) thay `T02Test`.
- Blocker và impact: P013 không close được (P013.4 blocked, P013.5 phụ thuộc). Không ảnh hưởng runtime.
- Proposed resolution: nếu muốn T02 làm bằng chứng, chạy `node scripts/test-runtime.mjs T02` trên bản site có flag mặc định, hoặc xác nhận chấp nhận P014 thay P013.4/P013.5.
- Cập nhật 2026-11-20: P020.2 sửa `T02Test` để không phụ thuộc flag và category của site (R03 kiểm tra activation giữ flag đã lưu và cài mới ghi default, rồi khôi phục; AC-T02-03 tạo fixture khi catalog không có product có category). Còn lại chỉ là xác nhận chấp nhận P014 thay P013.4/P013.5.

### OD-17 — contact-form-7 6.2.5 đang active nhưng không có trong allowlist seal

- Status: open (default đã áp dụng).
- Doc section: 01 §8, `contracts/compatibility/matrix.json`; `src/Application/Rollout/AuthoritySeal.php` (allowlist plugin được certify); `docs/plans/P020-wp715-recertification/plan.md` D02.
- Context: contact-form-7 được bật trên site1.localhost (trước là 6.2.2, inactive). `AuthoritySeal::sealAuthority()` từ chối seal khi có plugin active ngoài allowlist, nên `T45Test` fail đúng thiết kế (`Uncertified plugins detected: contact-form-7/wp-contact-form-7.php`), kéo theo T46/T49. Review code 6.2.5 (20/11/2026): chỉ ghi post/meta `wpcf7_contact_form` của chính nó qua WordPress API, không SQL ghi trực tiếp, không hook vào product hay query toàn cục.
- Default đã thử: không thêm vào allowlist (đó là quyết định bảo mật), matrix ghi status `unknown`, không nới test.
- Blocker và impact: không seal được authority khi plugin này active; không ảnh hưởng site đang chạy legacy.
- Proposed resolution: (A) certify contact-form-7 6.2.5 (thêm vào allowlist + matrix `supported`, plan riêng); (B) tắt plugin trên site; (C) giữ nguyên, chấp nhận T45/T46/T49 fail.
