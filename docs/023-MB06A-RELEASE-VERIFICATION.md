# MB-06A — Admin foundation offline release & operator verification

**Scope:** Provider-independent regression and rollout checkpoint of [approved MB-06A](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md). PR #59. The code is **not** proof of actual Telegram/BotFather or production acceptance. Admin Bot still carries old member/reseller flows; the Sales Bot is a non-public technical shell.

## CI & evidence matrix

| Area | Automated checkpoint | Must verify separately |
| --- | --- | --- |
| Admin command/callback parity | Immutable MB-01 inventory + existing Admin/legacy Sales/shared registrations, plus protected `/servers`; old Reply labels still route | Actual Admin /start, /menu, users, products, plans, roles, orders, financial review screens |
| Account authorization | Existing Super Admin allowlist, private-only callback and role-revocation tests | Trusted root and ordinary admin screenshots; old buttons after revocation |
| Sales isolation | Independent webhook secrets, disabled-by-default Sales, overlapping update IDs, scoped cache/contacts | Two real non-public test bots before enabling any Sales webhook |
| Financial idempotency | Existing approved/rejected receipt, unique settlement and provisioning outbox tests | Never submit or approve real bank funds during smoke |
| Notification delivery | Repeated dispatch, 429/5xx bounded retry, 5-attempt dead terminal, lease expiry and operator-only recovery; legacy alerts suppressed from replay | Two BotFather tokens, actual JPEG/PNG transfer, chat blocked/restarted scenarios, measured latency |
| Upgrade & rollback | MySQL CI: MB-02 historical processing receipts + MB-05A historical receipt/photo preservation, nonempty notification and Plan–Squad rollback rejection | Production migrations and DB counts **read-only**; no `migrate:fresh`, `rollback` or cleanup |
| Provider features | Fake-only DTO, denied/absent/error UI; `REMNAWAVE_*_VERIFIED=false`, provider writes off | RW-04 real staging verification is later; no panel success claim |
| Runtime health | `telegram:ops:status` local aggregate counts | Queue worker/scheduler process health, webhook configuration and TLS, no token leaks, backlog stabilization |

## Safe operator preflight (SSH on deployed app)

Use only commands that inspect state. No database reset, receipt approval or provider write:

```bash
php artisan migrate:status
php artisan telegram:ops:status
php artisan schedule:list
php artisan queue:failed
php artisan route:list --path=api/telegram
```

The `telegram:ops:status` command is **read only**: it prints no tokens, user IDs, file IDs, bank references, addresses or media. It reports whether delivery, Sales runtime and provider writes are enabled, pending DB jobs, Admin/Sales webhook failures and expired processing leases, reachable private contacts, each notification state (including `suppressed`), due recoveries, failed jobs, provisioning `needs_review` and unconsumed fulfillment intents. Missing schema returns failure; never infer live worker health from zero pending jobs.

Review the environment configuration **without printing secret values**: Admin token/secret must be provisioned, Sales runtime remains OFF, `TELEGRAM_NOTIFICATIONS_ENABLED` OFF until queue/two-token smoke passes, `REMNAWAVE_WRITES_ENABLED` OFF, `REMNAWAVE_ADMIN_READS_VERIFIED` and `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED` OFF until actual panel validation.

Operational rollback for unverified newly introduced features is **flag-based**, not schema dropping or reverting finance rows. A nonempty `telegram_notification_deliveries` or `sales_plan_internal_squad_bindings` intentionally blocks destructive migration down. Rollback of code must preserve readable schema or use an approved forward-fix with backups. The Admin webhook original URL remains active during the compatibility window. No Sales Bot cutover is authorized.

## Pending real Telegram acceptance checks (operator)

- [ ] Verify both authorized Super Admins and an ordinary Admin: `/start`, `/admin`, `/users`, `/roles` (root-only), `/products`, `/plans`, `/payments` (only if enabled). Confirm concise Persian, guided forms, native copy, Back/Cancel, and revocation of stale callbacks.
- [ ] Verify an existing linked ordinary user and reseller still access `/start`, `/buy`, `/orders`, `/subscriptions`, `/catalog`, `/builder` (only authorized reseller), and original Reply labels; do not create a real paid order as part of smoke.
- [ ] With provider verification OFF, `/servers` shows only a safe unavailable message, not fake live health; ordinary users cannot open it.
- [ ] Confirm existing Admin webhook receives real messages with valid secret. Do not switch the original token or disable legacy Admin/Member routes.
- [ ] Review queue and webhook failure rates, `telegram:ops:status`, worker/scheduler uptime after the release; record evidence with private data redacted.
- [ ] Check persistent MySQL migration status and existing linked-user/receipt counts without posting actual IDs, tokens or bank details.
- [ ] If two non-public test bots exist, verify distinct Webhook/Telegram photo delivery **separately** with synthetic receipts and no real settlement. Real two-bot delivery is not implied by offline acceptance.

## Deferred scope and explicit boundaries

- **MB-06A source/CI checkpoint:** mergeable when all tests, PHPStan/Pint and MySQL migration/rollback smoke pass.
- **MB-06A live acceptance:** operator-controlled post-deploy Admin Telegram smoke and existing production-record preservation evidence still required. Until captured, status is `CODE COMPLETE — LIVE CHECK PENDING`.
- **MB-06B full acceptance:** blocked on RW-01–04 staging, MB-05B live provider verification and two-bot real message/media delivery.
- **Future public Sales Bot:** remains a separate approved Epic; no live checkout, auto-provisioning, token switch or new payment activation in MB-06A.

Never mistake HTTP fakes, queued jobs or `php artisan migrate` in disposable CI for production delivery.
