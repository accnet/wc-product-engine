# Agent traceability matrix (tác giả: Antigravity agent, option B)

> Không phải báo cáo độc lập của Codex. Số liệu lấy từ `contracts/tasks.json`, `docs/evidence/*/result.json` và lệnh trong `agent-technical-audit.md` §1. Phiên bản: git `1759717`.

## 1. Traceability tổng quát

| Chỉ số | Giá trị | Nguồn |
|---|---|---|
| Task | 49 (P0 4, P1 11, P2 13, P3 8, P4 5, P5 4, P6 3, P7 1) | `tasks.json`, `PROGRESS.md` |
| Requirement R01–R202 | 202 duy nhất, liên tục, mỗi R thuộc đúng 1 task | DAG script C3 |
| Acceptance check | 110 | C3 |
| R-ID có mặt (bằng chữ) trong test file của task | 202/202 | regex, chỉ là tín hiệu có mặt |
| AC-ID không có anchor chữ trong test | 11 | xem F04 |
| `result.json` có `qa_status: passed` | 49/49 | đọc trường JSON |
| `verify-task` exit 0 tại HEAD | **41/49** | C4 |
| `result.json` ghi đúng version runtime | **0/49** | C5, C6 (F01) |
| R có `status` còn `specified_not_implemented` trong contract | 202/202 | F12 |

Các hàng "có anchor", "passed" ở trên là tín hiệu tồn tại, không phải bằng chứng ngữ nghĩa (F04).

## 2. Ma trận finding → task / module / contract / gate

| Finding | Sev | Task (trực tiếp) | Task (phải chạy lại sau sửa) | Module / file | Contract / doc | R / AC | Gate |
|---|---|---|---|---|---|---|---|
| F01 Env hard-code, pin ≠ runtime | P0 | T01, T03, T02 (runner) | T01–T49 toàn bộ | `scripts/test-runtime.mjs`, `pins.json`, `baseline-lock.json`, `wc-props-mapping.json`, `matrix.json` | 01 §ma trận phiên bản, 07 §7 | R01, R02, R07, R10, R171, R197–R201 | G01, G12 |
| F01b So version chỉ thành phần đầu | P1 | T42 | T42, T43, T45 | `ReadinessGates.php` L216–245 | 07 §7 ("gate hết hạn khi đổi versions") | R171, AC-T42-01 | G12 |
| F02 Không có evidence 100k/1M | P0 | T04, T47 | T16, T17, T18, T38, T47 | `FixtureHarness.php`, `ScaleBenchmark.mjs`, `T47Test.php` | 02 §1, 07 §2/§6 | R189–R193, AC-T47-01/02/03 | G09 |
| F03 Evidence stale (8 task) | P1 | T01, T02, T03, T04, T06, T10, T11, T29 | cùng danh sách | `evidence-lib.mjs` (digest), `tasks.json` Files của T01/T02 | 07 §7 | tất cả R/AC của 8 task | mọi gate |
| F04 Receipt cố định "passed" | P1 | T02 | T01–T49 | `scripts/test-runtime.mjs` | 07 §4 | 202 R, 110 AC (đặc biệt 11 AC không anchor) | G12 |
| F05 Selector kiểm bằng chuỗi | P1 | T27 | T23, T24, T26, T27 | `assets/variant-selector/*`, `T27Test.php`, `tests/e2e/` | 07 §2, 09 | R107, AC-T27-01 | G06 |
| F06a/b/c T46 thiếu deliverable | P1 | T46 | T46, T40 | `RetentionCleaner.php` | 02 §14, 08 §9, 06 T46 | R185, R187, AC-T46-01 | G08, G12 |
| F07 `mirror_stopped` không được enforce | P1 | T40, T46 | T40, T46 | `RollbackRunner.php`, `RetentionCleaner.php` | 02 §5 (begin/finish-rollback), INV13 | R188, AC-T40-02, AC-T46-03 | G08 |
| F08 Mặc định phạm vi = toàn catalog, xoá khi chưa có native row | P1 | T46 | T46 | `RetentionCleaner.php`, `T46Test.php` | INV02, INV13 | R185, R188 | G08 |
| F09 Test không cô lập, residue | P1 | T04 | mọi task có test ghi DB (21 file không có tearDown) | `FixtureHarness.php`, `tests/integration/*` | 07 §9, T44 AC-T44-02 | AC-T44-02, R191 | G02, G09 |
| F10 Commit trong receipt lệch một bước | P2 | T02 | — | `test-runtime.mjs` (`getCommitSha`) | 07 §7 | — | G12 |
| F11 AC-T46-02 yếu | P2 | T46 | T46 | `T46Test.php` | 06 T46 | AC-T46-02 | G12 |
| F12 Status/tài liệu lỗi thời | P2 | T03 (contract), docs | — | `regression-requirements.json`, `PROGRESS.md` §1, `independent-review.md` §3, `07` §2, `QUESTIONS.md` | AGENTS §4 (preserve PROGRESS/QUESTIONS/NOTES) | — | G01 |
| F13 Seal T03 vs `schema.sql` T05 | P2 | T03, T05 | T01–T04 | `tasks.json` (T05 Files), `evidence-lib.mjs` | 06 §T03/T05 | R14 | G01 |

## 3. Module bị ảnh hưởng nhiều nhất

| Module | Số finding | Ghi chú |
|---|---|---|
| `scripts/test-runtime.mjs` + `evidence-lib.mjs` (tooling evidence) | F01, F03, F04, F10 | Sửa một lần ở T02 scope sẽ mở khoá nhiều finding |
| `RetentionCleaner.php` / `RollbackRunner.php` (P7/P4) | F06, F07, F08, F11 | Chỉ ảnh hưởng T40/T46 |
| Fixture harness T04 | F02, F09 | Cần generator và cơ chế cô lập trước khi chạy lại bất kỳ test nào |
| Contracts (pins, status) | F01, F12, F13 | Cần quyết định trước khi sửa |

## 4. Ranh giới: những gì **không** đánh giá được
- Không có báo cáo nghiệp vụ độc lập (Claude) nên traceability "scenario → acceptance → test" chỉ có bản của agent (§4 trong `agent-technical-audit.md`).
- Không đối chiếu từng assertion của 202 R với ý nghĩa nghiệp vụ. Cần reviewer độc lập cho phần này.
