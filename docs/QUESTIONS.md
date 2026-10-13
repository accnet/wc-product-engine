# QUESTIONS

## 1. Status

Có 1 câu hỏi mở (OD-12, mục 4), không chặn task nào đã authorize. OD-01…OD-11 đã được trả lời hoặc áp dụng, xem mục 3 và `docs/audits/OPEN-DECISIONS.md`. Runtime pins/extensions chưa certified là T01 deliverables.

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

Chi tiết: `docs/audits/OPEN-DECISIONS.md`, `docs/adr/003-audit-remediation-scope.md`. Affected tasks: T01, T02, T04, T27, T38, T40, T42, T46, T47.

## 4. Câu hỏi đang mở

### OD-12 — Epoch của worker khi Variants Manager lưu biến thể (P005.4)

- Status: open. Doc section: 02 (epoch/authority). Commit: chưa có (P005 chưa commit khi ghi entry).
- Context: site1.localhost đang ở chế độ legacy_restored với global epoch 2. `ProductEditorService::saveProductVariants` gọi `LegacyMirror::mirrorAggregate` với worker epoch 1 cho sản phẩm chưa có dòng `wpe_object_state`. Kết quả: mirror trả `rejected_stale_epoch` và các variation shell trong `wp_posts` giữ `auto-draft`, parent 0, nên `WC_Product_Variable::get_children()` rỗng dù trình soạn thảo đọc đủ biến thể từ bảng native.
- Evidence: chạy `/tmp/dbg4.php` (scratch, không nằm trong repo) in ra `rejected_stale_epoch worker_epoch 1 current_epoch 2`. `tests/integration/ProductEditorServiceTest.php::testLegacyMirrorProjection` thất bại trên site này với "Post parent must be product ID"; test không bị sửa và không nằm trong DoD của P005.
- Default đã thử: chỉ báo cáo, không đổi ngữ nghĩa epoch. Trước P005 kết quả mirror bị nuốt im lặng; từ P005 `saveProductVariants` trả về `projection {legacy_mirror, catalog_index}`, notice cảnh báo hiện sau Update và REST save hiện toast cảnh báo.
- Blocker/impact: dữ liệu biến thể vẫn lưu đúng ở native và editor đọc lại đúng (đã kiểm chứng bằng trình duyệt thật); nhưng các reader legacy dựa trên post shell của WooCommerce không thấy biến thể cho tới khi mirror được chấp nhận. Mỗi lần lưu còn chạy `CatalogProjector::buildNewGeneration()` đầy đủ (chi phí hiệu năng, ngoài phạm vi).
- Proposed resolution: quyết định worker epoch đúng (epoch hiện hành của site hoặc epoch gắn với object state) và hành vi mode cho lưu từ editor, sau đó thêm test hồi quy; cần chủ sở hữu contract 02 xác nhận trước khi sửa vì chạm vào epoch/authority.
- Answer: chưa có. Affected: P005 (chỉ báo cáo), ProductEditorService, LegacyMirror.
