# REVIEW

## 1. Review checklist

- Identity/parent/option/stock-owner invariants 02 §13 pass real tests.
- Authority/epoch/CAS enforced, postmeta fallback not hidden native.
- DB+outbox atomic native; legacy gaps reconciliation/barrier explicit.
- Stock reserve/reduce/refund race tests use real WC paths, no double operations.
- Public permissions/URLs/private files and input budgets checked.
- Gallery native/Blocks/version profiles differentiated, no fake attachment/file paths.
- CSV missing/blank, resume, image mapping and loss report verified.
- Migration/rollback preserves current data and historical order IDs.
- Contracts/docs/task manifests match, Core checksum unchanged.
- Tests output traceable to commit/env, no skipped critical as pass; performance claims measured.

## 2. Decision

review_approved only if all critical requirements pass; otherwise request_changes with task/contract section/file/evidence and minimum repair. Docs validator pass is not runtime certification. Independent docs review evidence at docs/evidence/independent-review.md.
