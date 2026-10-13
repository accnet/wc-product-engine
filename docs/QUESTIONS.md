# QUESTIONS

## 1. Status

Có 2 câu hỏi mở (OD-13 và OD-14, mục 4), không chặn task nào đã authorize. OD-01…OD-11 đã được trả lời hoặc áp dụng, xem mục 3 và `docs/audits/OPEN-DECISIONS.md`. Runtime pins/extensions chưa certified là T01 deliverables.

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

Chi tiết: `docs/audits/OPEN-DECISIONS.md`, `docs/adr/003-audit-remediation-scope.md`. Affected tasks: T01, T02, T04, T27, T38, T40, T42, T46, T47.

## 4. Câu hỏi đang mở

### OD-13 — ShopifyBatchImporterTest thất bại trên baseline (P007.1)

- Status: open. Doc section: 04 (import). Commit: d580a3e (đã thất bại trước P006).
- Context: `tests/integration/ShopifyBatchImporterTest.php::testProcessBatchAndCheckpoint` báo "Batch 2 expected 2 products processed total, got 1". Test mong `products_processed` tích lũy qua các batch (batch_size 1, hai sản phẩm); importer trả về 1 sau batch 2.
- Evidence: chạy với `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file` có và không có thay đổi P006 (stash) cho cùng kết quả, exit 1.
- Default đã thử: không sửa test hay importer; loại khỏi mọi DoD.
- Blocker/impact: chưa rõ lỗi nằm ở bộ đếm (cumulative hay theo batch), ở cách gộp dòng theo handle ở biên batch, hay ở fixture; nếu là lỗi gộp biên batch thì có thể bỏ sót sản phẩm cuối. Không chặn P005/P006.
- Proposed resolution: một plan riêng điều tra batch 2 (sản phẩm thứ hai có được ghi không, bộ đếm lưu ở checkpoint ra sao) rồi sửa code hoặc fixture kèm test hồi quy.
- Answer: chưa có. Affected: ShopifyBatchImporter, ShopifyImportPage, assets/admin/shopify-import.js.

### OD-14 — Hai khóa epoch lệch nhau trên site (P007.1)

- Status: open. Doc section: 02 (epoch/authority). Commit: n/a.
- Context: `wpe_control.authority_epoch` là 2 (LegacyMirror dùng làm fence khi không có object state), còn `wpe_control.current_epoch` là 9 và toàn bộ 22025 dòng `wpe_object_state` mang epoch 9 (OutboxWorker, ProductRepository, InventoryBridge, MetaBridge dùng). Site đang ở chế độ legacy_restored.
- Evidence: truy vấn `wpe_control` và `wpe_object_state` ngày 2026-10-13; P006.1 chọn current_epoch làm worker epoch dự phòng, nên không còn bị fence từ chối.
- Default đã thử: không đổi giá trị hay ngữ nghĩa của hai khóa.
- Blocker/impact: không chặn; nhưng fence cho sản phẩm không có state vẫn so với khóa thấp hơn, nên một worker mang epoch trong khoảng 2..8 vẫn được chấp nhận cho sản phẩm mới.
- Proposed resolution: chủ sở hữu contract 02 xác nhận khóa nào là nguồn sự thật sau flip và rollback, rồi thống nhất hoặc đồng bộ hai khóa.
- Answer: chưa có. Affected: LegacyMirror, OutboxWorker, rollout/rollback runners.
