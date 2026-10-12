# Plans after initial roadmap

## 1. Lifecycle

`node scripts/plan.mjs new slug "Title"` tạo draft plan folder P001; edit plan.md business proposal + tasks/reads/files/DoD, sync; approve chỉ khi user scope đã authorize concrete plan. start→run actual command→done; block+QUESTIONS; report→close. No --force, no hand edit progress.md/INDEX.md/log.jsonl generated files.

## 2. Commands

```bash
node scripts/plan.mjs new example "Example plan"
node scripts/plan.mjs sync P001
node scripts/plan.mjs approve P001
node scripts/plan.mjs start P001.1
node scripts/plan.mjs run P001.1 -- node scripts/check-docs.mjs --strict
node scripts/plan.mjs done P001.1
node scripts/plan.mjs report P001
node scripts/plan.mjs close P001
node scripts/plan.mjs check
```

Plan task JSON canonical authored in plan.md fenced json: id,title,depends_on,read_first,files,dod (argv arrays). Script sync validates then derives registry. Proposal includes sections1 goal/2 scope/3 decisions/4 docs changes/5 tasks. Approved content hash sealed; edit needs authorized resync and approval. Log command exit/output SHA256/content digest + actor/time/commit; edits after tests invalidate receipts even without a new commit. Shell injection avoided by direct spawn argv, not shell strings; no env secrets in commands. done requires each declared DoD command succeeded since start and current task files/contracts/docs content digest evidence (commit may change without code changes). close only after tasks done + report summary written; status/report/check verify projections.

## 3. Initial plans status

Không có subsequent plan được approve trong docs creation. Initial roadmap dùng PROGRESS và contracts/tasks.json. Không chạy example commands vào repo chỉ để tạo fake plan thật; self-tests dùng temporary copy.
