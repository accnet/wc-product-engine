# Agent blockers (tác giả: Antigravity agent, option B)

> Không phải báo cáo của Codex. Phiên bản: git `1759717`. Blocker = finding chặn READY. ID trùng với `agent-technical-audit.md`.

## 1. Blocker kỹ thuật (chặn READY)

| ID | Sev | Tóm tắt | Điều kiện đóng |
|---|---|---|---|
| F01 | P0 | Receipt khai WP 7.0 / WC 11.1.2, host là 7.1.3 / 11.2.1, pins cũng là 7.0 / 11.1.2 | Runner đọc version từ runtime thật; pins và runtime khớp (theo OD-01); toàn bộ evidence chạy lại |
| F01b | P1 | `ReadinessGates` bỏ qua đổi minor/patch version | So sánh version đầy đủ; thêm test R171 cho 7.0→7.1.3 |
| F02 | P0 | Không có dataset 100k/1M, benchmark chạy trên ~1.2k catalog rows | Dataset được seed có manifest; EXPLAIN và latency đo ở quy mô theo OD-02; NOTES không còn overclaim |
| F03 | P1 | 8 task stale (T01, T02, T03, T04, T06, T10, T11, T29) | `verify-task` exit 0 cho cả 49 task tại commit freeze |
| F04 | P1 | Runner ghi "passed" cố định, 11 AC không có anchor | Receipt theo từng R/AC lấy từ output thật; mọi AC có anchor |
| F05 | P1 | R107 / scenario 6 chỉ kiểm bằng chuỗi source | Test hành vi thật theo OD-06 |
| F06 | P1 | T46 thiếu rollback window, gate theo cohort, retention/archival/restore instructions | Theo OD-04 |
| F07 | P1 | Rollback không biết mirror đã dừng | `RollbackRunner` từ chối chuyển authority khi chưa reproject; test chạy qua đường rollback thật |
| F08 | P1 | Cleanup/reproject mặc định toàn catalog; xoá khi chưa có native row | Phạm vi bắt buộc tường minh; chặn khi thiếu native row/authority |
| F09 | P1 | Residue trên DB dev, 21 file test không có tearDown, thiếu backup trước test phá huỷ | Cô lập + residue gate = 0 + backup (OD-05) |

## 2. Blocker quyết định (chặn remediation)

Xem `OPEN-DECISIONS.md`: OD-01 → OD-07. OD-01 chặn tất cả phần còn lại.

## 3. Không chặn READY (P2, sửa cùng đợt)

F10, F11, F12, F13 (xem technical audit).

## 4. Blocker về quy trình audit

| ID | Mô tả | Cách đóng |
|---|---|---|
| B-AUD-1 | 6 báo cáo `codex-*`, `claude-*` không tồn tại; audit này do implementer tự làm | Reviewer độc lập (người hoặc agent khác) xác nhận hoặc bác bỏ các finding. Chưa thực hiện được trong phiên này |
| B-AUD-2 | Không có audit nghiệp vụ độc lập cho 12 kịch bản 07 §2 | Như B-AUD-1 |

## 5. Ghi chú trung thực
- Ở lượt hoàn thành trước, tôi đã báo "49/49 verified" bằng cách đọc `qa_status`, và liệt kê tên task theo trí nhớ thay vì từ `PROGRESS.md`. Cả hai đều không đáng tin. Báo cáo audit này thay thế báo cáo đó.
- Tôi chưa ghi các blocker trên vào `docs/QUESTIONS.md` vì vòng audit này không được sửa tài liệu gốc. Việc ghi sẽ làm sau khi user chốt.
