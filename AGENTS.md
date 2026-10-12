# WC Product Engine — Agent instructions

## 1. Read order and precedence

Read README.md, docs/PROGRESS.md, task in docs/06-implementation-plan.md and only its Đọc trước sections. Conflict precedence: 02 >03 >04 >10 >01 >09 >07 >08 >05 >06. Draft contracts already provided; T03 seals, T01/T02 not blocked waiting T03. File paths/refs checked by scripts/check-docs.mjs.

## 2. Goal scope

Implement first unfinished phase tasks in PROGRESS topological order, no task from later phase unless user authorized. Docs package creation is complete separately, runtime tasks unchecked. User instructions authorize routine scoped fixes and supersede local workflow approval defaults; no repeated confirmation for already authorized work.

## 3. Task workflow

Read contracts/dependencies → edit task Files + tests → run task commands/DoD → save real evidence → QA/review → mark progress → one task commit if in git repo, no push. Dependencies must completed before mark task done. Do not fake pass or call docs validation runtime tests. If material blocked log docs/QUESTIONS.md with evidence/default attempted; continue independent authorized work. New feature uses scripts/plan.mjs lifecycle per docs/plans/README.md.

## 4. Non-negotiable rules

No Core modifications/monkey-patches/global SQL rewrite. No stale owned-prop legacy fallback under native authority. No dual-writer/epoch bypass. No fake attachment IDs/local paths. No browser-trusted price/stock/variant ownership. No deleting tests/skipping critical requirements to pass. No secrets/production .env/customer PII in repo or outputs. No --no-verify/--force or git push. Do not hand-edit generated plan progress/index/log. Preserve docs/PROGRESS.md, docs/QUESTIONS.md and docs/NOTES.md when refreshing docs.

Authoritative docs/contract/tool edits are allowed in an explicitly authorized docs/contract task or approved plan with listed doc paths; implementation-only tasks cannot silently redefine business rules. User authorized creation of this complete docs kit; no approval needed for authoring its initial files. Runtime deploy/cutover authorization follows current task scope, not inferred from document generation.

## 5. Check and report

`node scripts/check-docs.mjs --strict`, `node scripts/plan.mjs check`, `node scripts/guard.mjs --staged`, task test commands, `node scripts/verify-task.mjs Txx`, git diff --check. Print task/commands/exit codes/acceptance evidence/blockers. Final claims distinguish docs checks from actual WC integration certification.
