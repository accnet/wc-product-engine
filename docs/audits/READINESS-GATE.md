# READINESS GATE

## Quyết định: **CONDITIONALLY READY** (chưa READY)

- Phiên bản đánh giá: sau P001.7 (commit `68b2879`) cộng thay đổi tài liệu của P001.8, 2026-10-12 (Asia/Ho_Chi_Minh). Lần đánh giá trước (`1759717`) là **NOT READY**.
- Runtime đã chứng nhận: WP 7.1.3, WC 11.2.1, PHP 8.3.31, MariaDB 11.8.6, theme `calora`, trên `site1.localhost` (khoảng 370 product, 1.2k catalog rows).
- Phạm vi quyết định: được phép tiếp tục phát triển và staged verification trên quy mô `site1.localhost`. **Không** được phép tuyên bố certification production, claim hiệu năng 100k/1M, hoặc cutover production. Cutover chưa được authorize và chưa có evidence.
- Không đánh dấu READY vì còn tiêu chí 6 và tiêu chí 8 chưa đạt (xem dưới). Không có ngoại lệ.

## 1. Tiêu chí READY

| # | Tiêu chí | Kết quả | Căn cứ |
|---|---|---|---|
| 1 | Không còn P0/P1 unresolved | **Đạt có điều kiện** | F01, F01b, F03–F09 đã sửa và có evidence chạy lại. F02 (scale) **không được sửa bằng đo đạc**: đóng bằng quyết định OD-02 = C, hạ claim, G09 ghi "chưa đạt". Xem `FINAL-AUDIT.md` §2 |
| 2 | Không còn decision blocker | **Đạt** | OD-01 … OD-07 đã chốt và đã áp dụng. Không còn mục open trong `docs/QUESTIONS.md` |
| 3 | Contract quan trọng nhất quán | **Đạt** | `pins.json` = runtime (T01Test kiểm), `check-docs --strict` 0 lỗi, `verify-task` exit 0 cho 49/49 trên cùng một digest (T03 và T05 đều pass) |
| 4 | Dependency DAG hợp lệ | **Đạt** | 49 task, không cycle, không thiếu dependency |
| 5 | Requirement traceability đủ phạm vi bắt buộc | **Đạt có điều kiện** | 212/212 R có receipt riêng từ output thật (`output_anchor`) và trạng thái `verified`. Nhưng 22/110 AC chỉ có `basis: requirements_only` (không anchor riêng trong output) |
| 6 | Critical business scenarios có acceptance test | **Không đạt** | 12 kịch bản có anchor test và R107 đã có test trình duyệt thật. Nhưng chưa có audit nghiệp vụ độc lập, nên không thể xác nhận kịch bản nghiệp vụ đủ và đúng |
| 7 | Scope MVP không mâu thuẫn | **Đạt** | OD-02 và OD-04 đã áp dụng vào 02, 06, 07, `tasks.json`, NOTES (ADR 003). T46 chỉ còn guarded postmeta cleanup; phần hoãn được liệt kê rõ |
| 8 | Báo cáo audit phản ánh đúng phiên bản hiện hành | **Đạt có điều kiện** | Báo cáo gắn đúng commit và đã cập nhật sau remediation. Nhưng vẫn là audit của implementer; 6 báo cáo `codex-*`/`claude-*` không tồn tại, không có reviewer độc lập |

Đạt 4/8 hoàn toàn (2, 3, 4, 7); 3 đạt có điều kiện (1, 5, 8); 1 không đạt (6).

## 2. Lý do không chọn READY
- Tiêu chí 6: thiếu audit nghiệp vụ độc lập. Đây là điều kiện người dùng đặt ra, agent tự audit không thay thế được.
- Tiêu chí 1: F02 được đóng bằng giảm claim, không bằng đo. Khả năng chịu tải 100k/1M vẫn chưa biết.
- Tiêu chí 8: audit do chính implementer thực hiện.

## 3. Lý do không còn NOT READY
- Cả hai P0 đã đóng theo cách đã được user chấp nhận: F01 bằng re-pin và chạy lại 49 task, F02 bằng hạ claim.
- Cả 8 P1 kỹ thuật đã có sửa code/test và evidence thật.
- Không còn decision blocker; DAG và contract nhất quán.

## 4. Điều kiện để lên READY
1. Một vòng audit độc lập (người hoặc agent khác) xác nhận hoặc bác bỏ F01–F15 và các kịch bản nghiệp vụ.
2. Quyết định về 22 AC `requirements_only`: thêm anchor hoặc chấp nhận bằng văn bản.
3. Quyết định về F15 (`OutboxWorker::acquireBatch` bỏ qua `available_at`): sửa trong task riêng hoặc ghi nhận rủi ro.
4. Muốn claim quy mô lớn: một plan mới để seed và đo 100k/1M, ngoài phạm vi P001.
5. Cutover production cần authorization riêng và evidence staged canary.

## 5. Những điều gate này không nói
- `check-docs` và `plan.mjs check` pass là kiểm tra tài liệu, không phải chứng nhận tích hợp WooCommerce. Chứng nhận tích hợp ở đây chỉ gồm 49 test chạy trên WP/WC thật của `site1.localhost`.
- Không nói production cutover được hay không được phép.
- Không nói code không còn lỗi: audit chưa đọc lại toàn bộ source, và một lỗi (F15) đã được phát hiện muộn do chính việc cô lập test.

## 6. Kiểm tra đã chạy cho gate này
| Lệnh | Exit | Kết quả |
|---|---|---|
| `node scripts/rerun-evidence.mjs` (DoD P001.7, chạy hai lần) | 0 | `{"tasks":49,"passed":49,"failed":[]}` |
| `node scripts/rerun-evidence.mjs --verify-only` (sau khi đặt status `verified`) | 0 | 49/49 |
| `node scripts/check-docs.mjs --strict` | 0 | 36 files, 49 tasks, 212 requirements, 0 errors |
| `node scripts/test-isolation.mjs residue` | 0 | 0 junk product, 0 orphan auto-draft, 0 cleanup job, 0 control key |
| `wp core version`; `wp plugin get woocommerce --field=version` | 0 | 7.1.3; 11.2.1 |
