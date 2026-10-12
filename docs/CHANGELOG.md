# CHANGELOG

## 1. v1.1 — 2026-10-12

Chuyển đặc tả single-file v1.0 sang DOCS-KIT: 01–10, AGENTS/CLAUDE, review/progress/questions, contracts, validators, subsequent-plan lifecycle. T06 thêm dependency T09 để outbox transaction đúng; PROGRESS topological thay numeric. T01/T02 dùng draft contracts có sẵn, không chờ T03 tránh bootstrap cycle. Target WP/WC versions pin theo current release archives; tooling pins target chưa certified.

Agent đã code theo bản trước: đối chiếu T06 transaction và task ordering, update docs manifest giữ progress/notes/questions identity; không tự xóa runtime code. Các task bị ảnh hưởng cần rerun evidence theo bảng; project chưa implement thì tất cả vẫn unchecked.

| Task | Action nếu đã done trước v1.1 |
|---|---|
| T01 | Reverify target pins/matrix/current source |
| T02 | Align docs/hook/task command scripts |
| T03 | Seal machine contracts/paths/error registry |
| T06 | Rerun atomic outbox test after T09 dependency |
| T09 | Verify command receipt persistence/CAS |

Không claim 202 tests đã chạy; requirements chưa implemented. Docs validation evidence riêng.

## 2. Independent review repairs

T01 owns baseline runner/lock manifest; T06 owns post ID allocator; T08 owns global meta ID allocator; T10 direct-object acceptance separates downstream factory/query certification. API schemas split gate/rollback requirements. Evidence completion validates content digests/output hashes and all acceptance IDs. Reservation unknown blocks native checkout. Verification date uses Asia/Ho_Chi_Minh 12/10/2026 (UTC 11/10/2026), no date contradiction.

T02 Files/manifest bổ sung composer.lock và package-lock.json; T01 acceptance gọi đúng baseline-lock.json. Initial docs QA scripts/results và rendered milestone DAG được đóng gói để tái kiểm.

## 3. P001 — audit remediation (2026-10-12)

Kết quả audit hợp nhất (`docs/audits/FINAL-AUDIT.md`) và quyết định OD-01…OD-07 dẫn tới ADR 003:

- Re-pin contracts/docs sang WP 7.1.3, WC 11.2.1, PHP 8.3.31; công cụ dev chưa cài ghi `not_installed`, Playwright 1.63.0.
- Thêm R203–R212 (tổng 212 requirements); registry có trạng thái `revalidation_pending` cho đến khi evidence được chạy lại.
- Scale 100k/1M hạ xuống mục tiêu tương lai; G09 ghi nhận chưa đạt.
- T46 thu hẹp thành guarded postmeta cleanup; retention tombstone/asset/outbox/job hoãn.
- T01/T02 không còn sở hữu `scripts/test-runtime.mjs`.
- Evidence T01–T49 phải chạy lại và cô lập test (snapshot/residue gate).

Không claim READY; xem `docs/audits/READINESS-GATE.md`.
