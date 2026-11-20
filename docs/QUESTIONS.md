# QUESTIONS

## 1. Status

Một câu hỏi mở: OD-19 (mục 4, chặn P011.12). Các câu hỏi OD-12…OD-18 đã được trả lời, xem mục 3. OD-01…OD-11 đã được trả lời hoặc áp dụng, xem mục 3 và `docs/audits/OPEN-DECISIONS.md`. Runtime pins/extensions chưa certified là T01 deliverables.

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
| OD-12 | Worker epoch khi lưu biến thể từ editor | answered | 02 (epoch/authority) | Dùng epoch hệ thống hiện hành (OutboxWorker::getCurrentEpoch) thay vì hằng 1 khi sản phẩm chưa có object state; không đổi LegacyMirror/fence (P006.1; sau đó P009 chuyển sang LegacyMirror::getAggregateEpoch theo OD-14; P006.2 hoàn tất và đóng P006 ngày 2026-11-20) |
| OD-13 | ShopifyBatchImporterTest thất bại trên baseline | answered | 04 (import) | Nguyên nhân là fixture của test lệch cột so với header (grams 500 bị đọc thành SKU và trùng SKU thật), không phải lỗi importer; sửa fixture theo tên header, giữ nguyên assertion (P008.1) |
| OD-14 | Hai khóa epoch lệch nhau (authority_epoch 2, current_epoch 9) | answered | 02 §authority | authority_epoch là nguồn chuẩn theo contract; current_epoch là bộ đếm outbox độc lập (người ghi duy nhất không có caller trong src). Caller của mirror lấy worker epoch từ LegacyMirror::getAggregateEpoch (state epoch, nếu không có thì global authority epoch) thay cho current_epoch; không đổi giá trị hay ngữ nghĩa của hai khóa (P009.1) |
| OD-15 | P011 Per-product media library (A1–A5 & P011.12 gate) | answered | 04 §7, 09 §10 | A1: Tự động dùng Local Storage khi plugin S3 (`images-space`) chưa active và dùng S3 khi plugin active; A2: Ẩn hộp ảnh gốc của WP (`postimagediv`, `woocommerce-product-images`) trên sản phẩm Simple/Variable và thay bằng hộp Product Media (`#wpe_product_media`); A3: Upload trực tiếp trong Variant Image Picker tự động thêm vào thư viện sản phẩm và gán cho biến thể trong 1 bước; A4: Khi xóa ảnh sản phẩm, hiện Impact Preview và cho phép xóa luôn file vật lý (`DELETE /admin/assets/{id}/file`) nếu ảnh không còn nơi nào dùng; A5: Hỗ trợ cả kéo-thả nhiều file từ máy tính và thêm qua URL CDN ngoài. P011.12 (`StorageLiveRoundTripTest.php`) giữ trạng thái gated chờ môi trường cài đặt/kích hoạt plugin `images-space` S3 thật. |
| OD-16 | P013.4 — `T02Test` fail vì trạng thái site, không vì code | answered | 06 T02, `docs/plans/P013-architecture-hardening/plan.md` §5 | P020.2 sửa `T02Test` tách khỏi flag và category của site; chạy lại đầy đủ 6 lệnh DoD của P013.4 đều pass (commit `171ff6f`), hoàn tất P013.5 và đóng P013 (2026-11-20). |
| OD-17 | `contact-form-7` 6.2.5 đang active nhưng không có trong allowlist seal | answered | 01 §8, `AuthoritySeal.php`, P020 D02 | Phương án B (người dùng chọn 2026-11-20): tắt plugin `contact-form-7` trên `site1.localhost` và DB test `test_wpe_regress`, giữ nguyên allowlist `AuthoritySeal` (không certify); chạy lại evidence T45 và verify T45/T49/T46 pass (49/49). |
| OD-18 | WooCommerce 11.3.0 thêm 3 prop customs (`customs_commodity_code`, `customs_country_of_origin`, `customs_description`, postmeta `_customs_*`) chưa có trong `wc-props-mapping.json` | answered | 03 §1, `docs/plans/P021-wc113-customs-legacy-only/plan.md` | Fail-closed (người dùng chọn 2026-11-20): 3 prop ghi trong `excluded_props` với policy `legacy_only_blocks_native`; data store native từ chối lưu giá trị khác rỗng (`wpe_legacy_only_prop`), cutover từ chối sản phẩm có dữ liệu customs. Hỗ trợ native để plan sau. |

Chi tiết: `docs/audits/OPEN-DECISIONS.md`, `docs/adr/003-audit-remediation-scope.md`. Affected tasks: T01, T02, T04, T27, T38, T40, T42, T46, T47, P011.12.

## 4. Câu hỏi mở

### OD-19 — P011.12 — Plugin object storage `images-space` chưa được cài/kích hoạt trên `site1.localhost`

- Status: open (P011.12 ở trạng thái `blocked`).
- Doc section: 10 §5; `docs/plans/P011-product-media-library/plan.md` §3 D02, §5 P011.12.
- Commit: test `tests/integration/StorageLiveRoundTripTest.php` đã được viết và chạy fail-closed trong commit P011.12.
- Context: P011.1–P011.11 đã hoàn tất (`done`). Chỉ còn P011.12 yêu cầu chạy round-trip thật với object storage mặc định (upload ảnh PNG nhỏ, xác minh tồn tại và đúng kích thước trên remote, đọc lại qua URL đã lưu kiểm SHA-256, xóa object và các dòng DB thử nghiệm) và bắt buộc **fail (không skip, không fallback về local storage)** khi plugin storage không nạp được hoặc chưa cấu hình.
- Default đã thử: kiểm tra `/var/www/site1.localhost/wp-content/plugins/` và `wp plugin list` (không có plugin `images-space`, cũng không có mu-plugin), kiểm tra `wp option list` (không có option `images_space_settings`). Chạy `node scripts/plan.mjs run P011.12 -- php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file tests/integration/StorageLiveRoundTripTest.php` trả về exit 1: `FAILED: Object storage plugin is not installed or loaded (ImagesSpace\Application\Settings / ImagesSpace\Storage\AdapterFactory missing). Live round trip cannot be skipped.` Không chuyển sang LocalStorageProvider để giả pass.
- Blocker và impact: P011.12 không thể pass và P011 chưa thể `close` (`in_progress`, 11/12 task `done`, P011.12 `blocked`). Không ảnh hưởng runtime: media library hiện hoạt động bình thường trên local storage theo OD-15 A1.
- Proposed resolution:
  - Phương án A: cài đặt, kích hoạt và cấu hình plugin `images-space` (S3/Spaces/MinIO thật) trên `site1.localhost`, sau đó chạy lại `P011.12` (`StorageLiveRoundTripTest.php`) để chứng nhận round-trip thật và đóng P011.
  - Phương án B: nếu môi trường này không dùng S3 thật, xác nhận miễn chứng nhận S3 live round-trip và ủy quyền tạo amendment plan đóng P011 ở phạm vi local + fake provider (ghi rõ không phải chứng nhận S3).
