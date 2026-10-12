# WC Product Engine — Docs Kit v1.1

## 1. Start

Bộ docs triển khai theo DOCS-KIT. Runtime T01–T49 đã implement trên site1.localhost nhưng chưa có kết luận READY (xem docs/audits/READINESS-GATE.md). 49 task, dependency DAG, database/API/CSV/event contracts, 212 regression requirements, native rollout/rollback gates. Đọc AGENTS.md rồi docs/02-domain.md, docs/01-architecture-and-stack.md và phase đầu docs/PROGRESS.md.

## 2. Verify docs immediately

Node ≥18, không cần dependencies:

```bash
node scripts/check-docs.mjs --strict
node scripts/plan.mjs check
node scripts/guard.mjs --all
```

Trong repository git mới: bật `git config core.hooksPath scripts/hooks`; hook chạy docs/plan guard, runtime tests/lint theo harness được T02 implement. Không initialize/overwrite repo khi chép docs; giữ PROGRESS/NOTES/QUESTIONS của project đang làm và reopen task theo CHANGELOG.

## 3. Agent prompt

```text
Hoàn thành phase đầu tiên còn task unchecked trong docs/PROGRESS.md.
Đọc AGENTS.md; tuân thủ task dependencies và Đọc trước/Files/DoD của 06.
Không sửa WordPress/Woo Core. Không nới tests/gates để pass.
Chạy commands và ghi actual evidence, task commit riêng nếu trong git repo, không push.
Không đi sang phase sau. Bị blocked ghi QUESTIONS với evidence/default attempted,
tiếp tục task độc lập được authorize. Không tự cutover production.
```

## 4. Map

01 stack, 02 domain/state/invariants, 03 schema, 04 API, 05 tree/layers, 06 tasks/DAG, 07 tests/gates, 08 operations, 09 UI, 10 integrations/dialects. REVIEW/PROGRESS/NOTES/QUESTIONS/CHANGELOG quản trị docs/work; plans/ + plan.mjs dùng việc mới. Contracts JSON là machine-readable design seeds, T03 phải seal mappings/schema completeness trước runtime consumers.

## 5. Verification limits

Docs validators kiểm cấu trúc/references/DAG/paths/errors/registry consistency. Không chứng minh PHP/WC plugin hoạt động, native checkout safe hay performance targets achieved. Pins là selected target, compatibility matrix not_tested đến T01/runtime evidence. Native retains minimal real WP shells. Source guide DOCS-KIT không included trong repo package.
