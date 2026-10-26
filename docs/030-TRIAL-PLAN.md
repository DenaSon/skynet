# TRIAL — DenaGate free evaluation subscription

**Approved scope, 2026-10-26:** one free **2-hour / 250 MiB** evaluation for an active ordinary Sales Bot member without previous paid purchases. No reseller access, renewal, Sales order, Billing journal, referral reward, new CRM, marketing engine or provider webhook. Source/CI completion is separate from live Telegram/Remnawave acceptance and Production rollout.

## Phase status and dependencies

| Phase | Source | Evidence | Deployment |
| --- | --- | --- | --- |
| TRIAL-01 | [PR #238](https://github.com/DenaSon/skynet/pull/238), `e23a89d` | Local PHP 8.5: 1,021 tests / 6,928 assertions; PHP 8.4/8.5, PHPStan, Pint and concurrent MySQL [CI passed](https://github.com/DenaSon/skynet/actions/runs/40290319136) | Merged to master: `bac5b7f` |
| TRIAL-02 | [PR #239](https://github.com/DenaSon/skynet/pull/239), `7161110`, based on #238 | Local PHP 8.5: 1,044 tests / 7,027 assertions; PHP 8.4/8.5, PHPStan, Pint, real Redis/MySQL worker-claim races with fake external API [CI passed](https://github.com/DenaSon/skynet/actions/runs/40292433187) | Merged to master: `039e44d` |
| TRIAL-03 | [PR #240](https://github.com/DenaSon/skynet/pull/240), `feda9ce`, based on #239: provider-verified completion, one terminal notice and final Contact rebind review | Local PHP 8.5: 1,057 tests / 7,087 assertions; PHPStan/Pint passed. PHP 8.4/8.5/PHPStan/Pint/MySQL/Redis [CI passed](https://github.com/DenaSon/skynet/actions/runs/40294338221); real acceptance remains pending | Merged to master: `61e189d`; rollout OFF |

The operator explicitly authorized merging all three phases on 2026-10-26. They were merged in order #238 → #239 → #240, retargeting each dependent PR to master after its prerequisite. Final implementation merge is `61e189d3f452e9c526c85e514cbc071c9fd6fec7`; its Git tree exactly matches the tested #240 head. Every master merge triggers auto-deploy, but this is not evidence that Runflare deployment/migrations completed. Production ENV/data/provider writes and public trial enablement remain separate, unperformed operator actions.

## Admission and identity

- `ReserveFreeTrial` accepts a native private Sales self-contact only through the Telegram presenter. Require `contact.user_id === message.from.id`, the currently linked numeric Telegram ID and an active ordinary `member` without additional roles/privileges. Username is not identity.
- Native `request_contact` Reply keyboard is temporary. No typed, forwarded or foreign contact is accepted. Display only a masked read-only phone and require an unpredictable actor-bound confirmation within five minutes. The server draft contains only HMAC, masked text, numeric Telegram ID and confirmation token; final confirmation must match the same Telegram account even after DenaGate relinking.
- Normalize international/known Iranian forms before keyed HMAC-SHA256 with the fixed `denagate:trial-phone:v1:` namespace. Raw phone is never stored in the trial claim, queue, callback or log. The native Telegram contact itself remains in the user's private chat. A masked number is not editable input.
- Permanent SQL uniqueness covers **user ID, Telegram ID and phone fingerprint**. Preserve evidence after expiry, failures and relinking; restrict user deletion. The Interface never imports Identity persistence to authorize admission.
- Identity owns the fresh ordinary-member check. Sales owns prior paid/fulfilled order or wallet/bank settlement history. A wallet recharge is not a purchase. Check again inside admission and on the worker before CREATE.

## Limits and switches

| Configuration | Default | Purpose |
| --- | --- | --- |
| `TRIAL_ENABLED` | `false` | New trial admission/activation and scheduled trial processing |
| `TRIAL_DAILY_LIMIT` | `20` | 1–1000; both reservation-day and activation-attempt-day ceilings |
| `TRIAL_PHONE_HMAC_KEY` | empty | Independent stable random secret, 32+ characters; missing/weak/reused APP_KEY fails closed |
| `TRIAL_INTERNAL_SQUAD_UUID` | empty | Exact intended trial Squad |
| `TRIAL_VERIFIED_SQUAD_UUID` | empty | Must equal the exact Squad after separately approved disposable-user verification |

Global `REMNAWAVE_WRITES_ENABLED` is still required. Existing Sales/notification switches and separate tokens govern actual bot/notification delivery. No trial code changes any Production ENV.

`trial_admission_days` serializes admission using a locked MySQL day row, independent of Cache leases. Dates use **Asia/Tehran**. TRIAL-02 adds `activation_count` in the same table: the worker claims a slot on its actual execution day, so old queued reservations cannot bypass today's activation ceiling. A claimed/rejected/uncertain CREATE consumes its slot conservatively; capacity-blocked untouched reservations wait for a later day. No automatic entitlement release or second trial.

Do not rotate/reuse the HMAC secret without an explicit deduplication migration plan. Changing `@username` cannot change a Telegram ID. A person owning different Telegram accounts/numbers remains a possible abuse vector; the byte/day limits bound cost rather than proving one human.

## Existing provider lifecycle and precise time/volume

Reuse `RemnawaveSubscriptionLifecycle`, `provisioning_subscriptions`, `provisioning_operations`, `ProviderQueueConnection` and existing SQL at-most-one-write semantics. No Provider WRITE runs in a Telegram webhook. `ProvisionFreeTrialJob` uses only asynchronous Redis/database connections; no sync/null fallback can execute CREATE inline.

The verified upstream contract is Remnawave **3.4.4**, commit `b22970cc88481a7e278b5767721672a18f8b2ada`, `libs/contract/commands/users/create-user.command.ts` and `src/modules/users/users.service.ts`. It accepts absolute ISO UTC `expireAt`, byte `trafficLimitBytes`, `NO_RESET` and Internal Squad UUIDs. There is no relative-duration/activation field in this CREATE contract.

- Freeze the UTC CREATE instant **on the worker immediately before its single CREATE attempt**, never on Contact receipt, confirmation or queue admission. Persist the target first: `expireAt = frozen instant + 7200 seconds`, `trafficLimitBytes = 262144000`, strategy `NO_RESET` and the verified Squad.
- Only a confirmed provider result with the exact identity/ownership marker, status, expiry, quota and Squad becomes locally active and gets a success intent. `activation_started_at` is the frozen CREATE clock; `activated_at` records local confirmation. Time spent waiting in Queue never reduces the window. HTTP/Node propagation and real clock accuracy still require live measurement; source tests do not prove VPN connectivity or a full 7200 seconds measured from the later HTTP response.
- Reference `free-trial:{claimId}` and operation key `free-trial:{claimId}:create` are independent of paid order references. No free Sales order, zero-price paid plan, fractional GB or zero-day relaxation.
- States: `reserved → provisioning → active → ended`; ineligible worker requests become `failed`; uncertain/crashed CREATEs become `needs_review`. Scheduler recovers **only untouched reserved claims** and quarantines stale provisioning claims. Never reset claimed/uncertain state to reserved.
- Privileged `ProvisionFreeTrial::reconcile(trialId, operatorId)` uses the existing GET-only reconciliation and original frozen tuple. A missing operation or mismatch stays blocked; no blind second CREATE. Access is trusted-root only.

## Customer reads and UX

Sales home/account has one optional trial entry plus `/trial`. Contact/cancel/navigation restore the existing MENU-01 native menu by removing the temporary Reply keyboard. Navigation retires this draft before existing forms can receive text. A final Contact rebind check was added during TRIAL-03 review.

`ReadFreeTrial` verifies current ordinary owner, exact trial/provider reference, remote numeric ID and immutable marker before/after GET. Validate the frozen quota/expiry/Squad/NO_RESET. Safe status DTOs contain no credentials. Display actual MiB plus hours/minutes; no fabricated zero/status on a failed GET.

Only an ACTIVE, unexpired, unexhausted, locally active owned trial exposes its credential through a separate action. Reuse `SubscriptionQrPng`, private Telegram delivery and copy controls; no credentials in list/outbox/callback/log. No trial renewal action exists. Existing paid services, wallet, bank receipts, renewal, Referral attribution and Admin routes remain intact.

## One completion notification

- `TrialEndMonitor` follows the existing paid-alert GET-only scan pattern, scheduled every minute with `withoutOverlapping`. Real RW quota/time is read through the owned Trial Application action; local expiry or a failed/unavailable/mismatched GET is never sufficient evidence.
- Time reaching zero **or** usage reaching the exact 250 MiB cap ends the trial. A disabled status alone does not prove early expiry. Both conditions together still create one terminal transition and a single unique `free-trial:{id}:ended` intent.
- `TrialActivated` and `TrialEnded` share **the existing** `telegram_notification_deliveries`, dispatcher, worker, sender and operator failure list. No parallel transport. Final copy uses «🎁 تست رایگان شما به پایان رسید» and existing `sales:buy` / `menu:plan-catalog` callbacks.
- Paid warnings remain unchanged. Trials have no paid order/link and therefore never enter the existing 24h, 1h or 350 MiB paid-alert query. No ordinary warning is added to trial processing.
- Delivery rechecks current owner/provider. Read outages happen before the terminal send fence and use the existing outbox retry/backoff. SQL `end_notice_started_at` fences the final Telegram send; repeated workers, expired leases and manual outbox retry cannot send another accepted final notice.
- **Ambiguous send tradeoff:** Telegram sendMessage has no application idempotency key. After a timeout/unknown response or process crash following the send fence, retain the fence and show a reviewable unavailable notification instead of blindly resending. Delivery can be missed in that case; do not claim guaranteed exactly-once delivery. A positively rejected 429/blocked request can safely release the fence for existing retry/recovery. Paid-notification behavior is unchanged.

## Migrations and rollout/rollback

All source migrations are additive: claim foundation (`000001`), SQL admission-day quota (`000002`), activation linkage/worker-day quota/outbox FK (`000003`), terminal state/send evidence (`000004`). Empty disposable schemas support rollback; populated claims/quota/activation/end evidence refuse destructive rollback. Never run `migrate:fresh` on customer data or erase trial evidence to recover a failed activation.

Before rollout, independently review all three PRs and exact-head PHP 8.4/8.5/PHPStan/Pint/MySQL/Redis CI, then obtain separately authorized controlled Telegram/Remnawave acceptance. Verify native Contact owner/rebind/cancel/keyboard rendering, actual two-hour/250 MiB behavior and clock/Node propagation, one end CTA, no paid warnings, private QR/import, uncertain-write reconciliation, worker/Scheduler and rollback. No real provider write, bank transaction, Production migration, secret or ENV change is authorized by this document.

Keep `TRIAL_ENABLED=false` until those gates pass. To pause trial processing after release, disable the independent trial switch through an approved operator action; RW's already-created expiry/quota still enforce limits. Preserve claims, quota rows, frozen operations and notification evidence. Existing authorized owners can still check their recorded trial through a stale private status route. Never clear terminal send fences as a routine retry mechanism.
