# MB-06A — Admin foundation offline release & operator verification

**Scope:** Provider-independent regression and rollout checkpoint of [approved MB-06A](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md). PR #59. The code is **not** proof of actual Telegram/BotFather or production acceptance. Admin Bot still carries old member/reseller flows; the Sales Bot is a non-public technical shell.

## Production operator evidence — 2026-10-16 (ParsaGate, not legacy NVL Core)

**Evidence type:** commands and Telegram smoke outcomes supplied by the operator from the deployed ParsaGate Laravel container. No independent SSH access, job execution or second-role login was performed by the reviewer. Source/CI baseline: `master` `805346678650324bcce60277c77a62ae7ec6742f`; [PR #63](https://github.com/DenaSon/skynet/pull/63) corrected Telegram HTTP 400 `message is not modified` on identical receipt/menu edits; [post-merge PHP 8.4/8.5 + MySQL CI](https://github.com/DenaSon/skynet/actions/runs/38871578375) succeeded.

| Acceptance evidence | Actual operator observation | Decision / limits |
| --- | --- | --- |
| Production database upgrade | `php artisan migrate:status` reported all 23 listed migrations as `Ran`, including bot-scoped update receipts, contacts, notification deliveries and Plan–Squad bindings | **Observed** existing deployed schema; no destructive rollback or historical data reconciliation performed in Production |
| Admin Telegram navigation | Operator successfully exercised six **read-only** areas: `/start` + `/admin`, `/users`, `/roles`, `/products` + `/plans`, `/servers` (safe unavailable) and `/payments` | **Passed for tested Super Admin session(s)**. No claim of ordinary Admin login, role revocation, destructive action, financial approval or second bot E2E |
| Finance callback defect | `/payments` list, repeated **تازه‌سازی** and **مدیریت** returned normally after PR #63; operator reported no new webhook errors | **Passed for read-only navigation**; no bank settlement tested |
| Runtime aggregate snapshot | `telegram:ops:status`: Admin webhook failures **72**, stale leases **0**, contacts **1**; Sales failures **0**, Sales contacts **0**; pending/failed DB jobs **0**; all notification states/due recovery, provisioning needs-review and fulfillment intents **0** | Historical cumulative failure count was **unchanged** across reported successful tests; do not erase or interpret 72 as current incidents. Snapshot is not active transport / worker-heartbeat proof |
| Safety flags | Telegram delivery **disabled**, Sales runtime **disabled**, Provider writes **disabled** | Matches conservative rollout; delivery and provider features not accepted |
| Worker | Process list showed `www` running `/usr/local/bin/php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=60 --max-time=3600` in new pod; Nginx and PHP-FPM remained running; `php artisan queue:failed`: none | **Worker process verified at snapshot**; no successful end-to-end real job, restart/replica or sustained-health measurement proved |
| Scheduler | Runflare cron configured for Laravel `schedule:run` every minute; **operator explicitly confirmed the scheduled execution** | **Operator-confirmed** rather than independently captured timestamped cron log; do not add a second scheduler under Supervisor |
| Supervisor | Existing `nginx` and `php-fpm` preserved; new `laravel-queue` uses `user=www`, one process per Pod; owner permission checks returned `PERMISSIONS_OK` | No app code, DB, payment flags or provider changes; multi-replica worker count and queue processing remain separate operational checks |

**Acceptance decision:** Production Admin UI/read-only finance smoke, deployed schema, worker process and Runflare Cron setup have supporting evidence. **MB-06A full acceptance is not yet claimed**, because the approved [MB-06A contract](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md) also requires real compatibility/authorization checks for currently absent linked Member, Reseller and ordinary Admin roles, plus observed job processing/operational verification. Automated role/route/isolation tests cannot replace those real-user checks. Keep the old mixed Admin webhook's legacy routes intact and all Sales/provider/notification activation flags OFF; do not create real payments merely to pass this checklist.

**Next narrow checks (only if the operator elects to complete full MB-06A live acceptance):** test an authorized non-root/Member and Reseller in a safe, non-financial way once suitable linked accounts exist; verify denial of privileged stale callbacks after role revocation with controlled test fixtures, not actual privileged customer changes; review actual worker job completion and replica topology without enabling new live delivery; retain the approved cutover/rollback constraints. Missing Remnawave staging, real two-bot notification/photo transfer and provider reads are **MB-06B** dependencies and are not silently folded into MB-06A.

## MB-06A final provider-independent CI follow-up — PR #65 (2026-10-16)

Following the operator's request to finish the phase **without adding production roles or financial data**, the existing test-only database exercises these previously unverified contracts using the **original mixed bot's actual Nutgram routes**:

- Linked **Member**: `/start`, `/buy`, `/catalog`, `/orders`, `/subscriptions` remain readable; privileged commands and forged Admin callbacks deny access.
- Linked **Reseller**: `/builder` and `/catalog` are reachable; removing the reseller role immediately denies old builder callbacks.
- Ordinary **Admin**: `/start` and `/users` remain reachable, while root-only `/roles`, `/payments`, finance callbacks are denied; removing the Admin role denies stale admin callbacks.
- A real Laravel `database` queue worker executes **one existing `DispatchTelegramNotification` job** using `queue:work --once` and drains `jobs` without failed jobs **in the isolated automated test environment** while notification delivery and Sales are OFF. No HTTP/Telegram, bank/provider write or checkout is allowed by these tests.

**Automation:** [PR #65](https://github.com/DenaSon/skynet/pull/65) passed PHP 8.4, PHP 8.5 and disposable MySQL CI ([workflow](https://github.com/DenaSon/skynet/actions/runs/38879273519)). This completes the **provider-independent code + automated regression/queue execution checkpoint** for MB-06A.

**Evidence boundary / acceptance:** The operator has no linked Member, Reseller or ordinary Admin session to smoke-test in Production, so real live parity for these roles, revoked-role live callbacks, an actual Production queue job and independent current-data restoration/reconciliation are **NOT verified**. **Do not label the full MB-06A live acceptance as passed** until those requirements can be demonstrated or the owner explicitly approves a separately documented acceptance exception. Keep Sales Bot OFF, legacy mixed routes in place, provider writes OFF, and notifications OFF; do not create Production customers, paid orders or dummy settlements to force the test. This does not affect the separately blocked MB-06B real panel/two-bot gates.

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

- **MB-06A code + provider-independent automated acceptance:** PHP 8.4/8.5/PHPStan/Pint, MySQL migration/rollback, real DB queue-worker-in-test, and simulated legacy Member/Reseller/Admin authorization passed. PR #65 adds the final focused regression; this closes the **engineering/CI scope**, not the outstanding live Production acceptance.
- **MB-06A production checkpoint (2026-10-16):** read-only Admin smoke, actual schema presence, Worker process and operator-confirmed Cron passed as documented above; **full live acceptance remains open** for non-root/nonadmin legacy compatibility, revocation and observed job execution. Historical data were not mutated or proven exhaustively reconciled.
- **MB-06B full acceptance:** blocked on RW-01–04 staging, MB-05B live provider verification and two-bot real message/media delivery.
- **Future public Sales Bot:** remains a separate approved Epic; no live checkout, auto-provisioning, token switch or new payment activation in MB-06A.

Never mistake HTTP fakes, queued jobs or `php artisan migrate` in disposable CI for production delivery.
