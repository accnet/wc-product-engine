# OPEN DECISIONS

> **Trạng thái (P002.4):** OD-01 … OD-11 đã được user chốt hoặc áp dụng theo mặc định đã công bố, không còn decision blocker. OD-08 và OD-09 là waiver của user (bỏ audit độc lập, bỏ 100k/1M), không phải kết quả kiểm tra.

Trạng thái: **0 quyết định đang mở; cả 7 đã chốt (OD-01 = A, OD-02 = C, OD-03 = A, OD-04 = B, OD-05 = A, OD-06 = A, OD-07 = A)**. Agent không tự quyết định thay user. Thứ tự hỏi theo dependency: OD-01 chặn các mục còn lại, OD-07 chốt sau cùng. **Decision blocker đã đóng. Chưa có thay đổi nào được áp dụng vào source, contract hay docs gốc.**

Phiên bản: git `1759717`. Sau khi user chốt, agent sẽ cập nhật mục "Kết quả" và phạm vi cập nhật.

| ID | Quyết định | Chặn | Liên quan finding | Trạng thái |
|---|---|---|---|---|
| OD-01 | Version certified (pin) | Tất cả | F01, F13 | **Answered: A** |
| OD-02 | Quy mô scale bắt buộc | S6, G09 | F02 | **Answered: C** |
| OD-03 | Chính sách freshness của evidence | S2, S7 | F03 | **Answered: A** |
| OD-04 | Phạm vi T46 | S4 | F06 | **Answered: B** |
| OD-05 | Cô lập test và dọn residue | S3 | F09 | **Answered: A** |
| OD-06 | Loại test hành vi cho selector | S5 | F05 | **Answered: A** |
| OD-07 | Mở seal contract để cập nhật status và thêm R203+ | S8 | F12, F13 | **Answered: A** |

---

## OD-01 — Version nào được chứng nhận?
Bối cảnh: contracts và docs pin WP 7.0 / WC 11.1.2. Host `site1.localhost` chạy WP 7.1.3 / WC 11.2.1. Mọi receipt đang khai 7.0 / 11.1.2 (sai).

- **A (khuyến nghị):** Re-pin contracts và docs sang WP 7.1.3 / WC 11.2.1, tái sinh props mapping và baseline, chạy lại toàn bộ evidence. Lý do: host là môi trường thật của user và 02 §1 chọn `site1.localhost` làm đích.
- **B:** Giữ pin 7.0 / 11.1.2 và dựng runtime đúng version đó (staging riêng hoặc hạ cấp host) rồi chạy lại. Chi phí vận hành cao hơn, host hiện tại không được chứng nhận.
- **C:** Chứng nhận cả hai version (matrix hai hàng), chạy suite hai lần. Đầy đủ nhất nhưng gấp đôi công việc và cần thêm một runtime.

Phạm vi cập nhật nếu chốt: `pins.json`, `baseline-lock.json`, `matrix.json`, `wc-props-mapping.json`, `docs/01`, runner, `ReadinessGates`, toàn bộ `docs/evidence/T01–T49`.

## OD-02 — Quy mô scale bắt buộc trước khi tuyên bố G09
Bối cảnh: 02 §1 và 07 §2 nói benchmark 100k/1M; hiện chỉ có ~1.2k catalog rows.

- **A:** Bắt buộc 100k và 1M trước READY.
- **B (khuyến nghị):** Bắt buộc 100k cho G09. 1M chạy như stress/capacity (07 §6 gọi 1M là stress evidence) và được báo cáo là "đã đo / chưa đo", không chặn READY, trừ khi catalog thật dự kiến lớn hơn khoảng 500k.
- **C:** Hạ claim: chỉ chứng nhận chức năng ở quy mô nhỏ, G09 ghi rõ chưa đạt, đổi "Thiết kế để benchmark 100k/1M" thành mục tiêu tương lai.

Cần thêm từ user: catalog production dự kiến bao nhiêu product/variation.
Phạm vi: T04 generator, T47, `docs/NOTES.md`, 02 §1 nếu chọn C.

## OD-03 — Chính sách freshness của evidence
Bối cảnh: digest băm toàn bộ `contracts/` và `docs/NN-*.md`; T01/T02 liệt kê file chung bị sửa liên tục; T05 thêm file vào `contracts/` sau seal.

- **A (khuyến nghị):** Giữ digest nghiêm ngặt. Tách `test-runtime.mjs` khỏi `Files` của T01/T02 và chạy lại toàn bộ evidence ở commit freeze (S7). Đơn giản và an toàn nhất, nhưng mỗi lần đổi contract đều phải chạy lại tất cả.
- **B:** Đổi digest sang input thật của từng task (Files + contract/docs mà task trích dẫn). Ít chạy lại hơn, nhưng thay đổi công cụ chứng nhận và cần review riêng.
- **C:** A ngay bây giờ, B như cải tiến sau READY.

Phạm vi: `evidence-lib.mjs`, `tasks.json` (T01, T02 Files), 07 §7.

## OD-04 — Phạm vi T46
Bối cảnh: plan T46 yêu cầu retention cho tombstone/asset/outbox/job, archival metadata, restore instructions. Hiện chỉ xoá postmeta trùng.

- **A (khuyến nghị):** Implement đầy đủ theo 06 T46 và 02 §14 (rollback window 30d, job artifact 7d, audit 90d, gate theo cohort).
- **B:** Thu hẹp bằng ADR: T46 chỉ gồm xoá postmeta trùng có guard. Retention khác thành việc sau.
- **C:** Tách T46 thành T46a (postmeta cleanup, sửa ngay) và T46b (retention còn lại), T46b làm sau READY.

Cần thêm từ user: rollback window (giữ mặc định 30 ngày hay đổi).
Phạm vi: `RetentionCleaner.php`, `RollbackRunner.php`, `contracts/tasks.json` nếu B hoặc C.

## OD-05 — Cô lập test và dọn residue trên DB dev
Bối cảnh: 372 dòng `wp_wpe_products` (258 `AUTO-DRAFT`/title rỗng), 4 job cleanup, key control test; 21 file test không có tearDown; chỉ thấy backup `db-backup-before-p1-*.sql`.

- **A (khuyến nghị):** Tạo backup mới, dọn toàn bộ residue (xoá có điều kiện theo mẫu test), thêm namespace fixture + residue gate. Cần user cho phép xoá dữ liệu.
- **B:** Không xoá dữ liệu hiện có. Chỉ thêm cô lập cho test mới; chấp nhận benchmark/shadow diff chạy trên dữ liệu nhiễu.
- **C:** Chạy suite trên bản clone DB riêng (Duplicator hoặc `mysqldump`) và để DB hiện tại nguyên trạng.

Phạm vi: harness T04, 21 file test, thao tác DB.

## OD-06 — Test hành vi cho selector (R107, scenario 6)
Bối cảnh: hiện chỉ so khớp chuỗi source; không có trình duyệt.

- **A (khuyến nghị):** Thêm Playwright (cần cài browser và qua kiểm tra dependency), chạy trên `WPE_TEST_BASE_URL` với theme `calora`. Đúng 07 §2 "Browser E2E".
- **B:** Harness Node chạy `selector.js` với DOM giả lập và phản hồi đến sai thứ tự. Không cần browser, nhưng không chứng minh hành vi trên trình duyệt thật.
- **C:** Ghi G06 là "chưa kiểm browser" và bỏ claim liên quan tới R107 cho tới khi có browser.

Phạm vi: `tests/e2e/`, `T27Test.php`, `package.json` (nếu A).

## OD-07 — Có mở seal contract để cập nhật status và thêm R203+ không?
Bối cảnh: `regression-requirements.json` ghi cả 202 R là `specified_not_implemented`. Muốn thêm R203–R216 phải sửa contract đã seal.

- **A (khuyến nghị):** Mở seal một lần bằng task contract có ADR: cập nhật `status` từ evidence (script sinh ra) và thêm R203–R216. Chạy lại `check-docs` và seal lại.
- **B:** Giữ contract nguyên. Đặt các test mới trong một file riêng ngoài `tasks.json` và ghi chú trong docs rằng status là snapshot thời điểm spec.
- **C:** Giữ contract; không thêm R mới, chỉ gắn kiểm tra mới vào các R hiện có (R171, R185, R187, R188…).

Phạm vi: `contracts/regression-requirements.json`, `contracts/tasks.json`, `docs/06`, `docs/07`, `scripts/check-docs.mjs` nếu thay đổi schema.

---

## Kết quả (sẽ điền sau khi user chốt)
| ID | Lựa chọn | Ngày | Phạm vi đã cập nhật |
|---|---|---|---|
| OD-01 | A: re-pin WP 7.1.3 / WC 11.2.1, tái sinh baseline và props mapping, chạy lại toàn bộ evidence | 2026-10-12 | Chưa cập nhật file nào; thực hiện ở S1 sau khi đóng OD còn lại và khi gate cho phép |
| OD-02 | C: hạ claim; chỉ chứng nhận chức năng ở quy mô nhỏ; G09 ghi rõ chưa đạt; 100k/1M thành mục tiêu tương lai | 2026-10-12 | Cần cập nhật khi gate cho phép: 02 §1 (dòng Giới hạn performance), 07 §2 (layer Scale), §6, §7 (G09), 06 T47 và T38 ("triệu products"), `contracts/tasks.json` T38/T47 text, `docs/NOTES.md` T47 (bỏ "realistic scale"), PROGRESS T38/T47 title. S6 trong REMEDIATION-PLAN bị thay bằng: ghi rõ quy mô đã đo thực (372 product / 1,213 catalog rows) trong evidence; F02 đóng bằng sửa claim thay vì dataset |
| OD-03 | A: giữ digest nghiêm ngặt; tách `scripts/test-runtime.mjs` khỏi `Files` của T01/T02; chạy lại toàn bộ evidence ở commit freeze | 2026-10-12 | Khi gate cho phép: `contracts/tasks.json` (T01, T02 Files), `docs/06` và `docs/05` (danh sách file), `contracts/structure.json` nếu liệt kê; S7 chạy lại T01–T49 |
| OD-04 | B: thu hẹp bằng ADR; T46 chỉ gồm xoá postmeta trùng có guard; retention tombstone/asset/outbox/job, archival metadata, restore instructions làm sau | 2026-10-12 | Khi gate cho phép: ADR mới `docs/adr/003-*.md`; `docs/06` T46 và `contracts/tasks.json` T46 (deliverables/implementation); `docs/08` §9; `docs/02` §14 (đánh dấu retention job 7d/audit 90d là hoãn). Giải thích của agent: "có guard" gồm phạm vi tường minh, native row tồn tại, authority đã seal, gate restore drill theo cohort, rollback window (mặc định 30 ngày theo 02 §14, user chưa đổi), và `RollbackRunner` chặn switch khi mirror đã dừng (F06a/b, F07, F08, F11). Phần hoãn: F06c, R215. Cần user xác nhận cách hiểu này |
| OD-05 | A: backup mới, dọn toàn bộ residue theo mẫu test, thêm namespace fixture + residue gate; user cho phép xoá dữ liệu test | 2026-10-12 | Khi gate cho phép (S3): `mysqldump` trước; xoá residue bằng danh sách ID được liệt kê và user xem trước; harness T04; 21 file test thiếu tearDown. Chưa xoá gì trong vòng audit này |
| OD-06 | A: thêm Playwright chạy trên `WPE_TEST_BASE_URL` với theme `calora` | 2026-10-12 | Khi gate cho phép (S5): `package.json`/`package-lock.json` (dependency phải qua kiểm tra scan_dependencies trước khi thêm), `tests/e2e/`, `T27Test.php`, 01 §env, 07 §5. Cần browser cài trên host |
| OD-07 | A: mở seal một lần bằng task contract có ADR; cập nhật `status` từ evidence (script sinh); thêm R203–R214 và R216 (R215 hoãn theo OD-04; R213/R214 bỏ theo OD-02 = C); seal lại | 2026-10-12 | Khi gate cho phép (S8): `contracts/regression-requirements.json`, `contracts/tasks.json`, `docs/06`, `docs/07`, `docs/adr/` (ADR mới), `scripts/check-docs.mjs` nếu đổi schema. Chưa sửa file nào |

## Quyết định bổ sung sau P001 (2026-10-12)

| ID | Quyết định | Chặn | Trạng thái |
|---|---|---|---|
| OD-08 | Bỏ audit độc lập (waiver W1): user "bỏ audit độc lập" | Tiêu chí 6, 8 | **Answered: waiver** |
| OD-09 | Bỏ seed và đo 100k/1M (waiver W2): user "bỏ seed và đo 100k/1M" | G09 | **Answered: waiver** |
| OD-10 | F15: sửa `OutboxWorker::acquireBatch` để tôn trọng `available_at` (mặc định agent đã công bố trong P002; user yêu cầu "làm các task còn lại") | P002.1 | **Applied** |
| OD-11 | 22 AC `requirements_only` nhận anchor có điều kiện thật (cùng căn cứ như OD-10) | P002.2, P002.3 | **Applied** |
