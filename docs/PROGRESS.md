# PROGRESS — WC Product Engine

## 1. Trạng thái

Bộ docs đã tạo; runtime implementation chưa bắt đầu. `[x]` chỉ dùng khi evidence task pass; không đánh dấu từ docs validation.

## 2. P0 — Foundation

- [x] T01 — Pin baseline và compatibility inventory
- [x] T02 — Plugin bootstrap và feature flags
- [x] T03 — Khóa database/API/event/CSV contracts
- [x] T04 — Fixture và differential test harness

## 3. P1 — Storage và Woo compatibility

- [x] T05 — DDL và migration runner
- [x] T09 — Outbox, jobs, epoch và idempotency persistence
- [x] T06 — Product/variant repositories và aggregate commands
- [x] T07 — Options, values và combination integrity
- [x] T08 — Media và metadata persistence
- [x] T10 — Woo Product Data Stores
- [x] T11 — WC metadata và WordPress metadata bridge
- [x] T12 — Stock owner, reservation và order lifecycle
- [x] T13 — Post shell, factory, taxonomy và permalink bridge
- [x] T14 — WC admin, REST v3 và Store API adapters
- [x] T15 — Hooks, product transitions và cache lifecycle

## 4. P2 — Catalog/media/variants

- [x] T16 — Projection builder và generation rebuild
- [x] T17 — Fast query compiler và keyset pagination
- [x] T18 — Facets coherent theo variant và counts
- [x] T19 — Tích hợp wc_get_products, shop archives và admin lists
- [x] T20 — Cache context, invalidation và pricing compatibility
- [x] T21 — URL registry, providers và rendition contract
- [x] T22 — Real attachment bridge và Media Library behavior
- [x] T23 — Native gallery và variation image payload
- [x] T24 — Ảnh trên cart/email/SEO/feed/Blocks
- [x] T25 — Server resolver và allowed-values engine
- [x] T26 — Resolver API và native AJAX variation adapter
- [x] T27 — Selector UI, swatches và asynchronous behavior
- [x] T28 — Server add-to-cart validation và session restore

## 5. P3 — CSV interchange

- [x] T29 — Streaming parser, staging và dry-run validation
- [x] T30 — Commit worker, resume và conflict policy
- [x] T31 — Snapshot export và secure report delivery
- [x] T32 — Native Woo import adapter và external image mode
- [x] T33 — Woo export adapter và round-trip
- [x] T34 — Current/legacy parser và product grouping
- [x] T35 — Native Shopify exporter và loss policy
- [x] T36 — Cross-format equivalence và import/export QA

## 6. P4 — Migration/recovery

- [x] T37 — Change capture và reconciliation
- [x] T38 — Backfill triệu products và shadow diff
- [x] T39 — Native→legacy mirror và write ownership fence
- [x] T40 — Rollback orchestration và deactivation safety
- [ ] T41 — Operations UI, health và CLI

## 7. P5 — Evidence và readiness

- [ ] T44 — Chứng minh đọc native không dựa core postmeta
- [ ] T47 — Benchmark realistic scale và EXPLAIN budgets
- [ ] T48 — E2E compatibility/security/fault-injection suite
- [ ] T42 — Readiness gates và cohort manifest

## 8. P6 — Controlled rollout/release

- [ ] T43 — Cutover barrier, canary và monitored rollout
- [ ] T45 — Seal authority và chuyển full_native
- [ ] T49 — Release packaging, docs và staged delivery

## 9. P7 — Retention cleanup

- [ ] T46 — Retention, garbage collection và legacy cleanup
