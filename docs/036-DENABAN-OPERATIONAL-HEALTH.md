# DenaGate — Operational Health in Denaban (2026-10-30)

## New checks (added to the four existing Spatie checks, plus Redis when applicable)

| Code | Label | Signal | Status thresholds |
| --- | --- | --- | --- |
| ops_cpu | مصرف CPU | cgroup v2 `cpu.stat` usage delta over a one-second sample, divided by elapsed monotonic time and fractional `cpu.max` quota; optional `cpu.pressure` 10s wait shown as context only | Warning >=80% quota; failed >=95%; missing/invalid cgroup data: warning/unavailable |
| ops_memory | RAM | cgroup v2 current/max (includes filesystem cache), falling back to Linux MemAvailable | Warning >=80%; failed >=95% |
| ops_network_api | API شبکه | Existing authenticated **read-only** health operation, no writes | No runtime instance: warning; missing config: warning; unreachable / invalid: failed |
| ops_telegram_api | Telegram API | getMe for Admin + separately enabled Sales bot, 3s/2s timeouts, redirects disabled | HTTP/JSON failure: failed; missing token: warning |
| ops_queue_backlog | صف در انتظار | Laravel default queue size; no job payload inspection | >=20 warning; >=100 failed |
| ops_failed_jobs | Jobهای ناموفق | failed_jobs since last 24h | >0 warning; >=10 failed |
| ops_notification_outbox | اعلان‌های معوق | pending >10m or expired processing lease, plus 24h terminal failures (exclude superseded notifications) | >0 warning; >=10 stale failed |
| ops_sales_fulfillment | فعال‌سازی سفارش‌ها | needs_review outbox; paid pending >15m | >0 warning; >=5 review or >=10 stale failed |
| ops_subscription_jobs | زمان‌بندی اشتراک‌ها | successful scheduler command completion heartbeat in existing cache (`int` or bounded decimal string from Redis) | >=10m warning; >=15m failed; missing/invalid/disabled warning |

All new checks return numeric, whitelisted summaries only; credentials, exception
messages, raw job payload, orders, identifiers, server paths, headers, or
provider response bodies are never persisted or shown in the Telegram Health UI.

**Important (2026-11-03 fix):** The old CPU probe incorrectly divided **host**
`sys_getloadavg()` by the **container** CPU quota, causing false red health
states on shared infrastructure. The new «مصرف CPU» row measures a bounded
one-second sample of actual **container** CPU usage, normalized by the precise
fractional cgroup v2 quota (for example 0.43 CPU). Optional 10s PSI `wait` is
context, not an additional failure rule. It does not represent host CPU use or
historical host load; a one-second sample is not a minute-long trend. Unavailable,
unlimited or invalid cgroup data reports `warning / unavailable`, never green.
For Docker, RAM also refers to the PHP container, not the entire host.

## UI

The old Rich Message **«درباره این گزارش»** accordion has been removed.
Both Rich and simple views now show two separate sections:
- 🔍 بررسی‌های پایه
- 📊 منابع و عملیات

The existing Super Admin authorization, callbacks, safe refresh and RTL
Rich-to-HTML fallback are unchanged. The UI never executes probes itself.
Only known check names and a strictly bounded numeric/ASCII metric summary
are read from Spatie's latest persisted batch. A partial/missing check can
never be reported as green. One batch is capped to 24 rows.

## Operations and rollout

No Composer or migration change. Existing `DENAGATE_HEALTH_ENABLED` still
controls scheduled execution. A successful `sales:subscriptions:alerts`
invocation now best-effort caches a heartbeat, independently from sales
writes or notification delivery (failure of cache writes is ignored). Redis
may return this timestamp as a decimal string; the reader now validates and
accepts bounded decimal strings or integers, preserving future/stale limits.

Network checks happen from the **Scheduler**, NOT in Telegram webhooks, and
rely solely on existing configured bot tokens and the read-only provider
client. Check the production environment can reach the two API hosts.
No additional public routes or notifications are created.

The checks add 1 network API GET and 1-2 Telegram getMe GETs per **two-minute Health check** when
enabled; a network failure results in a warning/failed health observation,
never a service deactivation.

Acceptance: PHP 8.4/Pest, Laravel Pint, static analysis and MySQL CI before merge.
After deployment, inspect one complete fresh batch containing 13-14 records.
Validate real container CPU/RAM readings against Docker/Kubernetes accounting
and confirm no hardcoded secret appears in results or logs.
