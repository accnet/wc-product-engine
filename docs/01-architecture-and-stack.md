# 01 — Architecture and stack

## 1. Mục tiêu

Plugin WC Product Engine, PHP chạy trong WordPress/WooCommerce. Scope canonical tại 02 §1, data authority tại 02 §4. Bộ docs này là thiết kế triển khai v1.1; chưa có plugin sản phẩm đã implement/chứng nhận.

## 2. Context và các layer

Domain pure PHP → Application command/query services → ports → Infrastructure MariaDB (DB của site1.localhost) + WordPress/Woo adapters. HTTP/Admin/JS chỉ gọi Application. WordPress/Woo quản lý accounts/orders/cart/payment/tax; engine quản lý catalog theo mode.

## 3. Runtime

Một plugin, một database WordPress với InnoDB; không cần API service hay PostgreSQL. Transaction sử dụng cùng connection cho native tables/outbox/receipts. Workers CLI do cron/systemd chạy, Action Scheduler chỉ wake-up batching. Persistent object cache tùy site, không bắt buộc Redis để correctness.

## 4. Luồng ghi và đọc

Authority-aware commands validate epoch/CAS; transaction native + outbox; consumers tạo catalog/legacy projections. Luồng cấp post shell và consistency: 03 §5. Query compiler đọc read model và hydration page-local; checkout đọc stock canonical.

## 5. Modes và flags

Modes/allowed transitions chỉ tại 02 §4 và 02 §5. Default activate `legacy`; `shadow` do operator bật. Flags không được chuyển nguồn chuẩn trái epoch registry. Không feature flag bypass gate ở production.

## 6. Đồng bộ và lỗi

Outbox at-least-once, per-aggregate version fence; legacy capture best-effort + reconciliation. Không clock-based last-write-wins. Retry/classification theo 10 §8; migration failure theo 08 §6.

## 7. Quy ước code và ngôn ngữ

Namespace `WPE\`; PSR-4 `src/`; strict types trong domain; không gọi global WordPress trong pure domain. Runtime hooks dùng WC naming chuẩn, engine hooks prefix `wpe_`. Tài liệu tiếng Việt; identifiers/code/test names/commit tiếng Anh; admin/storefront UI tiếng Anh, dùng WP i18n. Decimal xử lý bằng string/decimal helpers, không float round-trip ở DB/API.

## 8. Stack pin và dependencies

Pin ở `contracts/compatibility/pins.json` lấy từ **môi trường thực tế** `http://site1.localhost` (`/var/www/site1.localhost`), ngày đo 12/10/2026, re-pin lần hai 12/10/2026 22:20 (ADR 003: pin trước đó 7.0 / 11.1.2 không khớp host); plugin chạy trực tiếp từ thư mục `wp-content/plugins/wc-product-engine` của site này, không dựng Docker/wp-env/site riêng. Không còn ma trận MySQL vs MariaDB: chỉ DB của site (MariaDB). Đây là baseline môi trường, **không phải supported/certified claim**. T01 đọc lại version bằng WP-CLI và ghi vào baseline; đổi version site mở lại gate bị ảnh hưởng.

| Thành phần | Pin lựa chọn | Vai trò |
|---|---|---|
| wordpress | 7.1.3 | Runtime (site1.localhost) |
| woocommerce | 11.2.1 | Runtime (site1.localhost) |
| php | 8.3.31 | Runtime (site1.localhost) |
| node | 22.22.2 | Dev/test |
| mariadb | 11.8.6 | Runtime (DB của site1.localhost) |
| composer | not_installed | Không dùng bởi task nào |
| phpunit | not_installed | Không dùng bởi task nào (tests chạy bằng wp eval-file) |
| phpcodesniffer | not_installed | Không dùng bởi task nào |
| wordpress_coding_standards | not_installed | Không dùng bởi task nào |
| playwright | 1.63.0 | Dev/test (npx toàn cục) |
| theme | calora 1.0.0 | Runtime (site1.localhost) |

Không thêm dependency runtime ngoài WC/WP trong V1 nếu không có task contract riêng. Node dùng scripts docs và browser tests; UI selector vanilla JavaScript, admin operations dùng WP packages được WC/WP cung cấp. Không tải CDN JS runtime tùy ý. Test tooling exact pins do T02 tạo lockfiles, WordPress test-library source phải cùng WP pin. Không dùng branch trunk/latest làm lock.

## 9. Configuration và environment

| Biến | Default | Ghi chú |
|---|---|---|
| `WPE_ENV` | `development` | development/test/staging/production, không tạo quyền bypass |
| `WPE_TEST_BASE_URL` | `http://127.0.0.1:8080` | Browser tests isolated |
| `WPE_TEST_DB_NAME` | `wpe_test` | Test database riêng, không dùng production |
| `WPE_TEST_DB_HOST` | `127.0.0.1` | Fixture runtime |
| `WPE_TEST_DB_USER` | `wpe_test` | User test tối thiểu |
| `WPE_TEST_DB_PASSWORD` | không có | Test secret cung cấp ngoài git |
| `WPE_WORKER_BATCH_SIZE` | `100` | Adaptive bounded batch |
| `WPE_WORKER_CONCURRENCY` | `1` | Tăng sau benchmark |
| `WPE_IMPORT_MEMORY_MB` | `256` | Spill disk/group limit |
| `WPE_PRIVATE_ARTIFACT_DIR` | ngoài webroot | Phải resolve/config validate T29 |
| `WPE_CURSOR_TTL_SECONDS` | `900` | Giữ generation cũ ≥TTL + grace |
| `WPE_LOG_LEVEL` | `info` | Không log secret/import raw/URL query credentials |

Mode/cohort authority là DB control record, không env var để tránh stale workers override. Core WP env/config tiếp tục do WordPress quản lý, không đọc `.env` thật vào prompt/commit.

## 10. Feature capability registry

Query/CRUD/gallery/stock/import/export capabilities có supported/blocked/not_tested. Unknown third-party plugin không giả định compatible. Changed plugin version/config làm certification stale; giữ authority hiện tại và halt rollout, không tự switch data source.

## 11. Security boundaries

Không raw SQL nhận từ client; prepared query compiler bounded; auth/caps 04 §1.8, URL security 10 §4, files/private artifact 08 §4. Không sửa Core, không global SQL rewrite, không fake IDs/local paths.

## 12. Technical decisions

| ADR | Quyết định | Hệ quả |
|---|---|---|
| ADR-001 | Same-DB native tables | Outbox atomic native; legacy hooks không atomic |
| ADR-002 | Retain real WP post shells | Bảo toàn IDs/discovery/permalink; zero-CPT ngoài V1 |
| ADR-003 | Read model generations | Rebuild atomic pointer, cursor retention/storage cần capacity |
| ADR-004 | External media + real attachment bridge | Native gallery compatibility, wp_posts không giảm về zero |
| ADR-005 | Single writer + epoch | Chặn dual-authority writes, rollback cần drain/reproject |
| ADR-006 | Three-state CSV fields | Absent/blank/value khác nhau, strict loss report |

## 13. Mặc định chưa xác nhận và lối ra

Target pins chưa certified: T01 kiểm thực tế và mở QUESTION chỉ khi không thể resolve package/support hợp lệ. Site-specific plugins hiện trống: default hỗ trợ core-only fixture, unknown extensions không native. Performance budgets tại 07 §6 là proposed test targets, không SLA. Không cần hỏi lại người dùng để scaffold/test isolated trong scope; thay nghiệp vụ hoặc production cutover ngoài implementation goal thì ghi concrete impact.
