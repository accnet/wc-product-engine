# FINAL AUDIT — Consolidation

- Phiên bản audit gốc: git `1759717` (T46), 2026-10-12 (Asia/Ho_Chi_Minh). Cập nhật sau remediation P001 (commit `75b2340`) và P002.
- Kết luận ban đầu: **NOT READY**. Sau P001: CONDITIONALLY READY. Sau P002: **READY có waiver** của user (xem `READINESS-GATE.md`): audit độc lập và 100k/1M bị bỏ theo quyết định của user, không phải đã đạt.
- Cả 7 quyết định mở đã được user chốt (OD-01 = A, OD-02 = C, OD-03 = A, OD-04 = B, OD-05 = A, OD-06 = A, OD-07 = A) và đã được áp dụng bởi plan `docs/plans/P001-audit-remediation`.
- Vòng audit gốc chỉ thêm file trong `docs/audits/`. Việc sửa source, contract và docs nằm trong P001, sau khi user cho phép ("thực hiện").

## 1. Đầu vào thực tế

| Báo cáo yêu cầu | Trạng thái |
|---|---|
| `codex-technical-audit.md`, `codex-traceability-matrix.md`, `codex-blockers.md` | **Không tồn tại** |
| `claude-business-audit.md`, `claude-missing-decisions.md`, `claude-business-scenarios.md` | **Không tồn tại** |
| Thay thế theo lựa chọn B của user | `agent-technical-audit.md`, `agent-traceability-matrix.md`, `agent-blockers.md` do agent đã implement T01–T49 tự viết |

Hệ quả cho việc consolidation:
- **Merge/dedupe:** chỉ có một nguồn nên không có finding trùng giữa hai auditor. Tôi đã gộp các triệu chứng cùng nguyên nhân (F01+F01b, F03 với F13).
- **Mâu thuẫn giữa hai auditor:** không đánh giá được vì chỉ có một auditor.
- **Thiên lệch:** auditor trùng với implementer. Một số lỗi (F08, F11) nằm trong code tôi vừa viết. Kết luận cần reviewer độc lập xác nhận.
- Không báo cáo nào trong thư mục này được gán cho Codex hoặc Claude.

## 2. Findings sau đối chiếu với Source of Truth

Thứ tự ưu tiên nguồn theo AGENTS: 02 > 03 > 04 > 10 > 01 > 09 > 07 > 08 > 05 > 06. Chi tiết và lệnh evidence ở `agent-technical-audit.md`.

| ID | Sev | Loại | Tóm tắt | Evidence đã chạy lại | Trạng thái sau P001 |
|---|---|---|---|---|---|
| F01 | P0 | Kỹ thuật + quyết định | 49/49 receipt khai WP 7.0 / WC 11.1.2; host 7.1.3 / 11.2.1; pins cũng là 7.0 / 11.1.2 | `wp core version`, đọc 49 `result.json`, grep runner | **Đã đóng** (P001.1–P001.2, P001.7): pins = runtime, 49/49 receipt khai đúng version |
| F01b | P1 | Kỹ thuật | `ReadinessGates` chỉ so thành phần đầu của version | Đọc `ReadinessGates.php` L212–247 | **Đã đóng** (P001.3): so version đầy đủ, R171 |
| F02 | P0 | Kỹ thuật + quyết định | Benchmark chạy trên 372 product / 1,213 catalog rows, không có 100k/1M | `wp db query`, đọc `T47Test`, `FixtureHarness` | **Đóng bằng quyết định** (OD-02 = C, waiver W2): claim đã hạ, G09 "chưa đạt". Không đo 100k/1M |
| F03 | P1 | Kỹ thuật + quyết định | 8 task `verify-task` fail vì evidence stale | Chạy `verify-task` T01–T49 | **Đã đóng** (P001.7): `verify-task` exit 0 cho 49/49 |
| F04 | P1 | Kỹ thuật | Receipt "passed" cố định, 11 AC không anchor | Đọc runner, regex test | **Đã đóng** (P001.2, P002.2, P002.3): receipt từng R từ output (212/212) và 110/110 AC có anchor |
| F05 | P1 | Kỹ thuật + quyết định | R107 kiểm bằng `str_contains` trên JS source; không có browser test | Đọc `T27Test.php` L207–209, `ls tests/e2e` | **Đã đóng** (P001.6): Playwright 1.63.0 + mutation control, R211 |
| F06 | P1 | Kỹ thuật + nghiệp vụ | T46 thiếu rollback window, gate theo cohort, retention/archival/restore instructions | Grep `src`, đọc `RetentionCleaner` | **Đã đóng theo scope hẹp** (P001.5, ADR 003): rollback window, gate theo cohort. Retention/archival hoãn (OD-04) |
| F07 | P1 | Kỹ thuật | `mirror_stopped` không có consumer; rollback không bị chặn | Grep `src` | **Đã đóng** (P001.5): `assertMirrorNotStopped`, R210 |
| F08 | P1 | Kỹ thuật | Cleanup/reproject mặc định toàn catalog; xoá khi chưa có native row; AC-T46-03 test đã ghi lại 372 product | Đọc code + test, đếm DB | **Đã đóng** (P001.5): bắt buộc `product_ids`, native row, seal; R206–R209 |
| F09 | P1 | Kỹ thuật + quyết định | Residue test trên DB dev; 21 file test không có tearDown; không thấy backup sau P1 | Query DB, grep tests, `ls backups` | **Đã đóng** (P001.4): backup, dọn residue, isolation receipt, R212 |
| F10–F13 | P2 | Kỹ thuật / tài liệu | Commit receipt lệch một bước; test AC-T46-02 yếu; status/tài liệu lỗi thời; xung đột seal T03 vs T05 | Xem technical audit | F10, F11, F12, F13 đã xử lý trong P001.1–P001.5 và P001.8; không còn mở |

## 3. Loại bỏ / hạ mức (false positives và điều chỉnh)

| Nghi vấn | Kết luận | Lý do |
|---|---|---|
| "Test thiếu `declare(strict_types=1)`" | **False positive** | `wp eval-file` bọc code trong `eval()`; khai báo này gây fatal. Test cố ý bỏ |
| "T05 sửa `contracts/` là vi phạm seal" | **Hạ xuống F13 (P2)** | T05 `Files` liệt kê `contracts/database/schema.sql`, plan cho phép. Lỗi nằm ở thiết kế digest, không ở implementation |
| "Commit receipt lệch là P1" | **Hạ xuống F10 (P2)** | Digest nội dung vẫn gắn đúng file; chỉ mapping commit→task lệch |
| "8 task stale nghĩa là code hồi quy" | **Không kết luận** | Stale chỉ nghĩa digest đổi. Chưa chạy lại test nên chưa biết có hồi quy chức năng hay không |
| "Ngày tài liệu sai (11 vs 12/10)" | **Withdrawn** | Đã có giải thích múi giờ trong `independent-review.md` |
| "T46 R187 test không chứng minh gate" | **Chấp nhận một phần** | Test chứng minh gate chặn khi cờ cohort = false. Không chứng minh rollback window và không phải đường tắt T49 (F06) |

## 4. Tách vấn đề kỹ thuật và quyết định nghiệp vụ

- **Chỉ kỹ thuật (không cần user quyết):** F01b, F04, F07, F08, F10, F11.
- **Cần quyết định trước khi sửa:** F01/F13 (pin nào), F02 (scale bắt buộc), F03 (chính sách freshness), F05 (loại test browser), F06 (scope T46), F09 (xoá residue), F12 (mở seal để cập nhật status). Mỗi mục là một OD trong `OPEN-DECISIONS.md`.

## 5. Task, module, contract bị ảnh hưởng
Xem `agent-traceability-matrix.md` §2–§3. Tóm tắt:
- Tooling evidence (`test-runtime.mjs`, `evidence-lib.mjs`): ảnh hưởng 49 task.
- Fixture harness T04: F02, F09.
- T40, T42, T46: F01b, F06, F07, F08.
- T27 (+T23/T24/T26): F05.
- Contract: `pins.json`, `baseline-lock.json`, `wc-props-mapping.json`, `matrix.json`, `regression-requirements.json`.

## 6. Thứ tự giải quyết theo dependency
Đã thực hiện theo `REMEDIATION-PLAN.md` (P001.1 → P001.8), mỗi task một commit.

## 7. Regression tests đã thêm
R203–R212 (10 requirement, ID cuối cùng sau OD-07; kế hoạch ban đầu đề xuất R203–R216 và đã bỏ R213–R215 theo OD-02/OD-04). Tất cả `verified`:

| ID | Nội dung | Task |
|---|---|---|
| R203–R205 | env receipt = runtime, commit/base_commit, receipt từng R/AC | T02 |
| R206–R209 | cleanup cần `product_ids`, native row + seal, rollback window, restore gate theo cohort | T46 |
| R210 | rollback bị chặn khi `mirror_stopped` | T40, T46 |
| R211 | selector stale response (test trình duyệt) | T27 |
| R212 | residue gate: test không để lại dữ liệu | T04 |

## 8. Finding mới phát hiện trong quá trình remediation
| ID | Sev | Tóm tắt | Xử lý |
|---|---|---|---|
| F14 | P1 | Pins/receipt khai các version dev-tool không có thật trên máy (pin chỉ là chuỗi trong contract, không có thứ gì kiểm tra) | Đóng: T01Test so pins với runtime; cài `@playwright/test@1.63.0` pin chính xác |
| F15 | P2 | **Đã sửa (P002.1).** T09 R32/R34 chỉ pass khi outbox trống: `OutboxWorker::acquireBatch` lấy `pending`/`failed` theo `created_at` và **bỏ qua `available_at`**, nên backlog thật (khoảng 1.1k event) làm sự kiện của test không bao giờ lọt vào batch; backoff retry cũng không có tác dụng | Test hermetic (park/restore backlog); `acquireBatch` giờ lọc `available_at <= now` (cả khi chọn và khi CAS) và sắp theo `available_at, created_at`. Test hồi quy trong T09 (AC-T09-01) fail trên code cũ và pass trên code mới |
| F16 | P3 | Receipt trước đây chỉ đọc `qa_status`; kết luận "49/49 verified" ban đầu của tôi sai | Đóng: `verify-task` kiểm env, base_commit, file_hashes, isolation, anchor |
| F17 | P3 | T11 AC-T11-02 hard-code `classic-editor`, plugin đã lên 1.7.0 và đúng ra là status `unknown` (bị chặn) | Test dùng plugin `supported` từ matrix và khẳng định `classic-editor` bị chặn |

Hai ghi chú về cô lập test: 1010 dòng do test tạo ra qua các lần chạy đã được snapshot-restore sau mỗi task; trạng thái plugin đang active đã khác baseline cũ (contact-form-7, duplicator, easy-wp-smtp không còn active), baseline T01 đã được chụp lại.

## 9. Phát biểu giới hạn
- `check-docs` và `plan.mjs check` pass chỉ chứng minh tài liệu nhất quán về cấu trúc. Bằng chứng tích hợp là 49 test chạy trên WP/WC thật của `site1.localhost`, không phải production.
- Audit do implementer viết; không có Codex/Claude audit và user đã bỏ việc này (waiver W1). Chưa có kết luận về production readiness, cutover chưa được authorize.
- `scan_dependencies` không chạy được (SecureCoder tại 127.0.0.1:11911 không phản hồi); `@playwright/test@1.63.0` đã được cài với `npm audit` 0 lỗ hổng và registry đã đối chiếu.
- Tôi chưa đọc lại toàn bộ source của 49 task. Danh sách finding là phần được chứng minh, không phải danh sách đầy đủ.
