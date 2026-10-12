# FINAL AUDIT — Consolidation

- Phiên bản audited: git `1759717` (T46), working tree sạch, 2026-10-12 (Asia/Ho_Chi_Minh).
- Kết luận: **NOT READY** (xem `READINESS-GATE.md`).
- Cả 7 quyết định mở đã được user chốt (OD-01 = A, OD-02 = C, OD-03 = A, OD-04 = B, OD-05 = A, OD-06 = A, OD-07 = A). Chưa có thay đổi nào được áp dụng vào source, contract hay docs gốc, nên các P0/P1 vẫn mở.
- Không có source, contract hay PLAN gốc nào bị sửa trong vòng audit này. Chỉ thêm 7 file trong `docs/audits/`.

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

| ID | Sev | Loại | Tóm tắt | Evidence đã chạy lại |
|---|---|---|---|---|
| F01 | P0 | Kỹ thuật + quyết định | 49/49 receipt khai WP 7.0 / WC 11.1.2; host 7.1.3 / 11.2.1; pins cũng là 7.0 / 11.1.2 | `wp core version`, đọc 49 `result.json`, grep runner |
| F01b | P1 | Kỹ thuật | `ReadinessGates` chỉ so thành phần đầu của version | Đọc `ReadinessGates.php` L212–247 |
| F02 | P0 | Kỹ thuật + quyết định | Benchmark chạy trên 372 product / 1,213 catalog rows, không có 100k/1M | `wp db query`, đọc `T47Test`, `FixtureHarness` |
| F03 | P1 | Kỹ thuật + quyết định | 8 task `verify-task` fail vì evidence stale | Chạy `verify-task` T01–T49 |
| F04 | P1 | Kỹ thuật | Receipt "passed" cố định, 11 AC không anchor | Đọc runner, regex test |
| F05 | P1 | Kỹ thuật + quyết định | R107 kiểm bằng `str_contains` trên JS source; không có browser test | Đọc `T27Test.php` L207–209, `ls tests/e2e` |
| F06 | P1 | Kỹ thuật + nghiệp vụ | T46 thiếu rollback window, gate theo cohort, retention/archival/restore instructions | Grep `src`, đọc `RetentionCleaner` |
| F07 | P1 | Kỹ thuật | `mirror_stopped` không có consumer; rollback không bị chặn | Grep `src` |
| F08 | P1 | Kỹ thuật | Cleanup/reproject mặc định toàn catalog; xoá khi chưa có native row; AC-T46-03 test đã ghi lại 372 product | Đọc code + test, đếm DB |
| F09 | P1 | Kỹ thuật + quyết định | Residue test trên DB dev; 21 file test không có tearDown; không thấy backup sau P1 | Query DB, grep tests, `ls backups` |
| F10–F13 | P2 | Kỹ thuật / tài liệu | Commit receipt lệch một bước; test AC-T46-02 yếu; status/tài liệu lỗi thời; xung đột seal T03 vs T05 | Xem technical audit |

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
`REMEDIATION-PLAN.md`. Bắt đầu bằng OD-01, vì pin quyết định việc chạy lại mọi evidence.

## 7. Regression tests cần thêm
`REMEDIATION-PLAN.md` §4 (đề xuất R203–R216 và AC bổ sung). Các ID này chưa tồn tại trong `contracts/`; thêm chúng cần OD-07.

## 8. Phát biểu giới hạn
- `check-docs` và `plan.mjs check` pass chỉ chứng minh tài liệu nhất quán về cấu trúc.
- Chưa có kết luận nào về production readiness. Cutover production chưa được authorize.
- Tôi chưa đọc lại toàn bộ source của 49 task. Danh sách finding là phần được chứng minh, không phải danh sách đầy đủ.
