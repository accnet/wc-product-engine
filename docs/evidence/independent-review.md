# Independent documentation review

## 1. Scope and outcome

Reviewer độc lập đọc docs/contracts/scripts ở chế độ read-only trước đóng gói. Targeted re-review xác nhận không còn lỗi consistency trọng yếu trong phần đã sửa; strict docs, plan checker và guard đều exit0. Đây là review docs, không WooCommerce runtime certification. Ngày tài liệu 2026-10-12 theo Asia/Ho_Chi_Minh; UTC tại phiên làm việc là 2026-10-11.

## 2. Findings and resolution

| Finding | Resolution | Status |
|---|---|---|
| T01 runner thuộc downstream T02 | T01 owns minimal real baseline runner và baseline-lock; T02 mở rộng harness | Re-reviewed repaired |
| Shell ID allocator chỉ có downstream T13 | T06 owns PostIdAllocator; T10 kiểm direct hydration; T13 factory/permalink | Re-reviewed repaired |
| Reservation chưa certified vẫn cho native bridge | Unknown path chặn cutover; native hiện tại halt stock/checkout tới bridge hoặc current-data rollback an toàn | Re-reviewed repaired |
| Independent meta AUTO ID có thể collision | Preserve migrated IDs, new custom meta dùng real global WPpostmeta anchors; không reuse IDs | Re-reviewed repaired |
| API gate/rollback/collections/context thiếu ràng buộc | Separate DTOs, required gate/reason/snapshot/parent_version, conditional collection mode, signed stock, facet/context policy | Re-reviewed repaired |
| Plan done có thể dùng stale content/output | Content digest, output SHA và state journal; requirement/acceptance receipts có IDs/artifacts | Re-reviewed repaired |
| Machine contract/file names và ADR thiếu nhất quán | JSON canonical paths, schema.sql tại T05, ADR files và manifest đồng bộ | Re-reviewed repaired |
| T02 tạo lockfiles ngoài Files/manifest | Added composer.lock/package-lock.json; author reran structural checks | Fixed after targeted review |
| Date bị nghi ngờ sai | User timezone giải thích Oct12 so với UTC Oct11; reviewer withdrew finding | Withdrawn |

## 3. Limits

49 implementation tasks và 202 regression requirements chưa được implement hoặc chạy trên WP/Woo. Review và receipt validators không chứng minh stock concurrency, theme/Blocks compatibility, CSV round-trip, performance hay safe production cutover. T03 seal contracts; runtime evidence là điều kiện cho mỗi task và release gates. Không tạo runtime result.json giả.
