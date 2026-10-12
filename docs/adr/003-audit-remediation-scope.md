# ADR 003 — Audit remediation scope

Status: accepted by the user on 2026-10-12 (OD-01 … OD-07, docs/audits/OPEN-DECISIONS.md). Implemented by plan P001.

## 1. Context

The consolidated audit (docs/audits/FINAL-AUDIT.md) found that all 49 evidence receipts recorded WordPress 7.0 and WooCommerce 11.1.2 while the host ran 7.1.3 and 11.2.1, that scale budgets had been measured on about 1.2k catalog rows, that eight receipts were stale, and that T46 did not implement several plan deliverables and guards.

## 2. Decisions

| Topic | Decision | Consequence |
|---|---|---|
| Pins | Certified baseline is the live host: WordPress 7.1.3, WooCommerce 11.2.1. Tools that are not installed (composer, phpunit, phpcodesniffer, wordpress_coding_standards) are recorded as `not_installed` | Tests assert pins equal the runtime (R203); all receipts are regenerated |
| Scale | 100k and 1M datasets are future targets and are not claimed. G09 is recorded as not achieved. Evidence must state the measured number of products and catalog rows | T38 and T47 titles and wording change; no scale certification |
| Freshness | Digest stays strict (task Files plus contracts plus numbered docs). `scripts/test-runtime.mjs` is removed from T01 and T02 Files. The `status` field of `contracts/regression-requirements.json` is excluded from the digest | Every receipt is regenerated at the freeze commit |
| T46 | Narrowed to guarded postmeta cleanup (explicit scope, native row present, authority sealed, rollback window, per-cohort restore gate, resumable chunks) plus reprojection before rollback | Retention of tombstone, asset, outbox and job data, archival metadata and restore instructions are deferred. The 7 day job artifact and 90 day audit retention in 02 §14 are configuration defaults only, not enforced by T46 |
| Test isolation | Every runtime test runs between a database snapshot and a restore, with a residue gate | R212 |
| Selector | R107 behavior is tested in a real browser with Playwright | R211 |
| Contract | The registry was reopened once. Requirement status moves from `specified_not_implemented` to `revalidation_pending`, then `verified` after all receipts pass. R203 … R212 were added | 212 requirements |

## 3. Evidence receipt semantics

`commit` in a receipt is the base commit (HEAD when the test ran). The receipt also records the git blob SHA of every task file. The pair of base commit and blob SHAs, together with the content digest, binds a receipt to exact file content. A receipt cannot contain the SHA of the commit that stores it.

## 4. Non goals

No change to WordPress or WooCommerce core. No production cutover or rollout authorization.
