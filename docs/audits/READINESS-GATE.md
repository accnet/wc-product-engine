# READINESS GATE

## Quyết định: **NOT READY**

- Phiên bản đánh giá: git `1759717`, working tree sạch, 2026-10-12 (Asia/Ho_Chi_Minh).
- Phạm vi của quyết định: cho phép tiếp tục implementation, release, cutover hoặc tuyên bố certification. Tất cả đều **bị chặn** cho tới khi gate đạt READY.
- Lưu ý: T01–T49 đã được implement và commit trước vòng audit này. READY ở đây là điều kiện để claim certification và để bắt đầu bất kỳ implementation mới nào, kể cả remediation.

## 1. Tiêu chí READY

| # | Tiêu chí | Kết quả | Căn cứ |
|---|---|---|---|
| 1 | Không còn P0/P1 unresolved | **Không đạt** | 2 P0 (F01, F02), 8 P1 (F01b, F03–F09). Xem `FINAL-AUDIT.md` §2 |
| 2 | Không còn decision blocker | **Đạt** | OD-01 … OD-07 đã được user chốt (2026-10-12), xem `OPEN-DECISIONS.md` |
| 3 | Contract quan trọng nhất quán | **Không đạt** | Pins (7.0 / 11.1.2) khác runtime (7.1.3 / 11.2.1); `status` 202 R lỗi thời; T03 seal xung đột với `schema.sql` của T05 |
| 4 | Dependency DAG hợp lệ | **Đạt** | 49 task, không cycle, không thiếu dependency, không phase inversion |
| 5 | Requirement traceability đủ phạm vi bắt buộc | **Không đạt** | R01–R202 gán đủ cho task (đạt), nhưng receipt không theo từng R/AC, 11 AC không anchor, 8 task stale, 0/49 receipt đúng version |
| 6 | Critical business scenarios có acceptance test | **Chưa thể kết luận** | 12 kịch bản có anchor test, scenario 6 chỉ kiểm bằng chuỗi source (F05), chưa có audit nghiệp vụ độc lập |
| 7 | Scope MVP không mâu thuẫn | **Không đạt (chờ áp dụng quyết định)** | OD-02 = C và OD-04 = B đã thu hẹp scope, nhưng 02 §1, 07, 06, `tasks.json` và NOTES chưa được sửa nên vẫn còn mâu thuẫn trong tài liệu hiện hành |
| 8 | Báo cáo audit phản ánh đúng phiên bản hiện hành | **Đạt có điều kiện** | Các báo cáo gắn `1759717`. Nhưng đây là audit của implementer, 6 báo cáo `codex-*`/`claude-*` không tồn tại |

Đạt 2/8 tiêu chí (DAG, decision blocker); 1 tiêu chí đạt có điều kiện; 1 chưa thể kết luận; 4 không đạt.

## 2. Lý do không chọn CONDITIONALLY READY
CONDITIONALLY READY cần mọi P0 đóng hoặc có biện pháp giảm nhẹ đã chấp nhận. Ở đây:
- F01 làm toàn bộ evidence mang thông tin môi trường sai.
- F02 khiến không thể tuyên bố gì về hiệu năng ở quy mô mục tiêu.
- User đã chốt hướng xử lý (OD-01 = A, OD-02 = C), nhưng chưa có thay đổi nào được áp dụng nên cả hai vẫn mở.

## 3. Việc cần làm để đạt READY
1. ~~User trả lời OD-01 … OD-07~~ (đã xong).
2. User cho phép bắt đầu remediation, rồi thực hiện S1 … S8 trong `REMEDIATION-PLAN.md`.
3. `node scripts/verify-task.mjs Txx` exit 0 cho cả 49 task ở commit freeze.
4. Chạy lại `check-docs --strict`, `plan.mjs check`, `guard`.
5. Một vòng audit độc lập (người hoặc agent khác) xác nhận hoặc bác bỏ finding; hiện không có audit nào ngoài audit của implementer.
6. Đánh giá lại bảng tiêu chí này.

## 4. Những điều gate này không nói
- Không nói code của 49 task sai. 8 task stale chưa được chạy lại nên chưa biết kết quả.
- Không nói production cutover được hay không được phép. Chưa có authorization và chưa có evidence.
- `check-docs` và `plan.mjs check` pass là kiểm tra tài liệu, không phải chứng nhận tích hợp WooCommerce.

## 5. Kiểm tra đã chạy cho gate này
| Lệnh | Exit | Kết quả |
|---|---|---|
| `node scripts/check-docs.mjs --strict` | 0 | 25 files, 49 tasks, 202 requirements, 0 errors |
| `node scripts/plan.mjs check` | 0 | 0 plans, 0 errors |
| `node scripts/verify-task.mjs T01…T49` | 8 task exit 1 | T01, T02, T03, T04, T06, T10, T11, T29: evidence stale |
| `wp core version`; `wp plugin get woocommerce --field=version` | 0 | 7.1.3; 11.2.1 |
| DAG và traceability script trên `contracts/tasks.json` | 0 | Không cycle; 202 R; 110 AC |
