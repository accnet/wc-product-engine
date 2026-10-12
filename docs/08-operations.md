# 08 — Operations

## 1. Environments

Development/test chạy trực tiếp trên site1.localhost (plugin ở thư mục này); staging representative anonymized dataset; production deployment/cutover riêng, legacy default. Target design pins chưa runtime-certified, không dùng docs zip làm plugin installable. Secrets chỉ provision runtime, không agent prompt/repo.

## 2. Build và deploy

T02 tạo lockfiles/harness, T49 package plugin reproducible; core checksum trước/sau, `wp core verify-checksums` và `wp plugin verify-checksums woocommerce` trên version của site1.localhost. Plugin package không chứa docs raw secrets/staging CSV/customer data; default activation không cutover. Install/update expand-contract trước background migrate, enable query only gates pass.

## 3. Workers và scheduling

CLI queue loop do systemd/cron (system cron thường xuyên, không dựa page traffic); concurrency1 initial, batch100 adaptive. Lease60s heartbeat20s/retry policy 10 §8; job states 02 §5. Shutdown sau checkpoint, không SIGKILL giữa aggregate intentional trừ fault tests. Monitor backlog age/count/DLQ/index freshness/migration lag.

## 4. Secret và artifact handling

Private staging outside webroot, path validate/sanitize, imports maximum configurable. Download job artifact auth từng request, expiry7days default. Không log signed URL credentials/token/import raw/PII. Cursor signing secret derive dedicated runtime secret có rotation window, không hardcode repo.

## 5. Backup và disaster recovery

Backup WP core content/terms/attachments bridge + native tables/epoch/outbox/control/current orders cùng consistent DB snapshot. Restore test private rồi evidence compare stock/IDs/authority; RPO/RTO measured site capacity. Backup cũ không thay rollback-current authority. Raw CDN files ngoài store do provider policy, native image URLs/provider keys phải restore được.

## 6. Cutover runbook

1. Backup + restore drill current schema; archive evidence và cohort manifest. Kiểm tra disk headroom/index generations.
2. Chạy final readiness validation đúng versions/plugins/pricing config; chặn mutation config trong rollout window.
3. Acquire cohort migration lock; đóng new product writes và stock mutations/new checkouts của cohort. Resolve/wait in-flight writes/reservations theo policy, không kết thúc bằng arbitrary sleep.
4. Reconcile final legacy snapshot vào native; validate critical hashes/counts/IDs, drain relevant outbox/index revision fences.
5. Switch registry/epoch atomic cho parent + children + stock owners; kiểm tra authority từ cùng transaction/control record.
6. Invalidate caches, đảm bảo shell/factory/query adapters ready, mở lại writes theo nguồn mới. Canary monitor errors/stock/parity/lag.
7. Hết observation window do baseline owner xác định mới mở rộng cohort. Full_native seal sau native independence và compatibility gates; mirror rollback retention là quyết định riêng.

## 7. Rollback runbook

1. Validate native source còn truy cập được và legacy projection capacity đủ; snapshot current native data và journal, không dùng backup cũ thay current data.
2. Block cohort writes/stock mutations; resolve in-flight checkout/reservations cùng policy; giữ order engine WC intact.
3. Reproject **current** native props/variants/meta/images/inventory vào legacy; verify latest revisions và critical semantic parity.
4. Chỉ sau mirror ready mới switch authority + epoch, invalidate caches và mở legacy writes.
5. Audit old worker fences; reconcile mới theo legacy authority; giữ native snapshot để điều tra/recovery. Nếu timeout, giữ authority native + maintenance cho cohort, không chuyển sang CPT stale.

Backup phục hồi sau database hỏng là disaster recovery khác rollback authority. Forced xóa plugin code có thể yêu cầu khôi phục plugin/bridge hoặc reproject bằng recovery package trước khi cho checkout hoạt động; không thể đảm bảo code bị xóa còn chặn request.

## 8. Alerts và runbooks

| Signal | Initial trigger | Action |
|---|---|---|
| Critical parity/stock diff | bất kỳ confirmed mismatch | Halt rollout, investigate authority/replay |
| DLQ | >0 critical events | Report IDs, repair/replay không bypass fence |
| Index freshness | p99>30s sustained5min | Throttle imports/check worker capacity |
| Worker starvation | No heartbeat/checkpoint>120s | Lease takeover, examine logs |
| Checkout regression | p95>accepted budget window5min | Halt canary expansion; current-data rollback readiness check |
| Disk headroom | Less than next generation+projection estimate | Stop rebuild, capacity plan |

Thresholds proposed baseline; not operational guarantees. Native read error returns dependency_unavailable/maintenance for cohort, không fall back stale price/stock.

## 9. Retention và cleanup

Default rollback window30days, raw job artifacts7days/audit90days (02 §14). stock_operations/idempotency order lifecycle không purge chỉ vì job retention; operation key giữ để callbacks/refunds không double-mutate. Cleanup T46 dry-run và restore gates; không delete retained shells/taxonomies/order refs/shared media. Forced missing plugin code recovery theo 08 §7, không magic deactivation callback.
