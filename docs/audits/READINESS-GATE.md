# READINESS GATE

## Quyết định: **READY** (có waiver do user chấp nhận, phạm vi giới hạn)

- Phiên bản đánh giá: sau plan P002 (`docs/plans/P002-close-audit-leftovers`), 2026-10-12 (Asia/Ho_Chi_Minh). Lịch sử: NOT READY (`1759717`) → CONDITIONALLY READY (sau P001, `75b2340`) → READY (bản này).
- Runtime đã chứng nhận: WP 7.1.3, WC 11.2.1, PHP 8.3.31, MariaDB 11.8.6, theme `calora`, trên `site1.localhost` (khoảng 370 product, 1.2k catalog rows).
- READY ở đây nghĩa là: không còn P0/P1 mở, không còn decision blocker, contract nhất quán, DAG hợp lệ, 212/212 requirement và 110/110 acceptance criterion có receipt riêng từ output test thật, `verify-task` exit 0 cho 49/49.
- READY **không** nghĩa là:
  - đã chứng nhận hiệu năng 100k/1M (user bỏ việc này, xem waiver W2);
  - đã có audit độc lập (user bỏ việc này, xem waiver W1);
  - được phép cutover hoặc deploy production. Cutover chưa được authorize và cần authorization riêng;
  - đã kiểm chứng trên môi trường khác `site1.localhost`.

## 1. Waiver do user quyết định

| ID | Nội dung | Ngày | Hệ quả |
|---|---|---|---|
| W1 (OD-08) | Bỏ audit độc lập (Codex/Claude hoặc reviewer khác) | 2026-10-12 | Không có reviewer ngoài implementer. Mọi finding trong `FINAL-AUDIT.md` do implementer tự đối chiếu. Rủi ro audit trùng với người viết code được chấp nhận |
| W2 (OD-09) | Bỏ seed và đo 100k/1M | 2026-10-12 | G09 giữ "chưa đạt". Manifest 100k/1M chỉ là định nghĩa mục tiêu tương lai, chưa chạy. Không được claim quy mô lớn |

Waiver là quyết định của user, không phải kết quả kiểm tra. Hai điểm này **không** được tính là đã đạt.

## 2. Tiêu chí READY

| # | Tiêu chí | Kết quả | Căn cứ |
|---|---|---|---|
| 1 | Không còn P0/P1 unresolved | **Đạt** | F01, F01b, F03–F09 đã sửa và có evidence chạy lại. F02 đóng bằng quyết định (OD-02 = C, W2). Finding mới F15 (P2) đã sửa trong P002.1. Xem `FINAL-AUDIT.md` |
| 2 | Không còn decision blocker | **Đạt** | OD-01 … OD-11 đã chốt; `docs/QUESTIONS.md` không còn mục mở |
| 3 | Contract quan trọng nhất quán | **Đạt** | `pins.json` = runtime (T01Test kiểm), `check-docs --strict` 0 lỗi, `verify-task` exit 0 cho 49/49 trên cùng một digest |
| 4 | Dependency DAG hợp lệ | **Đạt** | 49 task, không cycle, không thiếu dependency |
| 5 | Requirement traceability đủ phạm vi bắt buộc | **Đạt** | 212/212 R `verified`, 110/110 AC có `basis: output_anchor` (trước P002 là 88/110). Mỗi anchor chỉ in ra khi assertion thật đúng |
| 6 | Critical business scenarios có acceptance test | **Đạt, kèm W1** | 12 kịch bản có anchor test; R107 kiểm bằng trình duyệt thật (Playwright). Không có audit nghiệp vụ độc lập (W1) |
| 7 | Scope MVP không mâu thuẫn | **Đạt** | OD-02 và OD-04 đã áp dụng (ADR 003): T46 là guarded postmeta cleanup; claim scale bị hạ; phần hoãn được liệt kê rõ |
| 8 | Báo cáo audit phản ánh đúng phiên bản hiện hành | **Đạt, kèm W1** | Báo cáo gắn đúng commit và được cập nhật sau mỗi plan. Là audit của implementer (W1) |

Đạt 8/8, trong đó 2 tiêu chí (6, 8) và F02 dựa vào waiver của user.

## 3. Điều kiện còn lại (không chặn READY)
1. Cutover production cần authorization riêng, kế hoạch canary và evidence staged. Chưa có.
2. Muốn nói về quy mô lớn thì cần một plan mới (seed, đo, sửa claim G09).
3. 22 AC trước đây chỉ có `requirements_only` nay đã có anchor; mapping AC → test của T30–T34 dựa trên các test R tương ứng (assertion ném lỗi khi sai), không phải test AC riêng. Chi tiết trong output của từng task.
4. Plugin bên thứ ba `classic-editor` 1.7.0 ở trạng thái `unknown` (chưa review lại), nên bị chặn khỏi native cohort. Hành vi đúng, cần review nếu muốn dùng.

## 4. Những điều gate này không nói
- `check-docs` và `plan.mjs check` pass là kiểm tra tài liệu, không phải chứng nhận tích hợp WooCommerce. Chứng nhận tích hợp ở đây chỉ gồm 49 test chạy trên WP/WC thật của `site1.localhost`.
- Không nói code không còn lỗi: chưa có reviewer độc lập (W1), và F15 là lỗi thật chỉ lộ ra khi test được cô lập.
- Không nói production an toàn.

## 5. Kiểm tra đã chạy cho gate này
| Lệnh | Exit | Kết quả |
|---|---|---|
| `node scripts/rerun-evidence.mjs` (DoD P002.4, sau khi sửa) | 0 | `{"tasks":49,"passed":49,"failed":[]}` |
| `node scripts/check-docs.mjs --strict` | 0 | 36 files, 49 tasks, 212 requirements, 0 errors |
| `node scripts/plan.mjs check` | 0 | 2 plans, 0 errors |
| `node scripts/guard.mjs --all` | 0 | 0 errors |
| `node scripts/test-isolation.mjs residue` | 0 | 0 junk product, 0 orphan auto-draft, 0 cleanup job, 0 control key |
| Đếm `basis` trong 49 `result.json` | 0 | 212/212 R và 110/110 AC là `output_anchor` |
