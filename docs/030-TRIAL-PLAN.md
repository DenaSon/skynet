# TRIAL — DenaGate free evaluation subscription

**Decision / scope (2026-10-26).** A single **2-hour, 250 MiB** free trial for ordinary active Sales Bot members with no previous paid purchase, independently of Sales orders, Billing, pricing, resellers and Referral Rewards. Trial activation does NOT qualify for any referral credit. Only the trial feature is in scope; no loyalty, birthday or CRM work.

## Release sequence and evidence gates

### TRIAL-01 — Admission/identity (this branch)
- New additive `trial_claims` table with unique `user_id` and unique keyed HMAC phone fingerprint. Never store the raw telephone number.
- `ReserveFreeTrial` is an internal Application action. Check the existing, active `member` role, Sales-linked Telegram ID and `contact.user_id === message.from.id`. The interface must obtain this input ONLY from a private Telegram `request_contact` response, not from editable text. Admin/Reseller/disabled accounts are denied.
- Reject users with any genuine paid history (even settled, older orders), repeat claims and duplicate numbers. Enforce a daily global reservation limit through a transactional MySQL day row and immutable claims, independent of Cache locks.
- `TRIAL_ENABLED=false` by default; require an independent 32+ character random `TRIAL_PHONE_HMAC_KEY` and `TRIAL_DAILY_LIMIT` (default 20). Missing key fails closed. Do not reuse or rotate the key without an explicit migration plan; rotating it invalidates phone deduplication evidence.
- **TRIAL-01 is foundation only:** no Telegram entry point, no provider CREATE, no enqueue, no activated subscription, no financial effects. Reservation state is `reserved`. Do not enable TRIAL_ENABLED in production until TRIAL-02/03 are accepted.
- Test separate identities, different representations of a phone, role/identity rejections, previous paid history and daily ceiling. A synthetic fixture is not live Telegram contact acceptance.

### TRIAL-02 — Fulfillment + customer UI (later independent PR)
- Read Contact via native private ReplyKeyboard `request_contact`, verify contact owner and require explicit confirmation. Restore normal Sales reply keyboard afterward.
- Preserve one durable reservation per user/phone across repeated callbacks. Reuse `RemnawaveSubscriptionLifecycle` and its one-create/uncertain-outcome reconciliation semantics on an asynchronous provider queue. Frozen two-hour UTC expiry and exact 250 MiB bytes; distinguish finished/ambiguous outcomes. Never send a provider CREATE on Telegram webhook.
- Add a dedicated enable/verified Squad gate; no real Provider write without operator-approved disposable user test and exact Squad verification.
- Show trial in Sales account using owner-verified GET, credential-safe link and existing QR rendering; use hours/minutes, not the paid-plan GB/days display. No paid Sale/Order/Ledger or renewal.

### TRIAL-03 — Trial end & conversion message (later independent PR)
- Keep paid subscription warning path (24h, 1h, 350MiB) unchanged; trial has no warning before expiry/quota exhaustion.
- Reuse the existing Scheduler/notification-delivery outbox to inspect trial status and issue ONE terminal message when expiry OR quota is exhausted, with CTA to existing Sales buy/plan menu. Check real provider state/owner on delivery, survive retries and outages without duplicate notification.
- Exclude trial from Referral rewards by positive proof of a real paid+fulfilled Sales order, never from a generic provider activation.
- Regression: bank receipts, wallet, reseller discounts, paid subscription alerts, renewals, reporting, Sales/Admin route isolation and existing callbacks.

## Safety / rollout
- Zero live Provider writes, financial entries, migrations or production flags are authorized by source review alone.
- CI on PHP 8.4/8.5 + MySQL, PHPStan and Pint are mandatory, with additional real Telegram private contact/Remnawave activation/end-of-life checks under separate operator permission.
- A Telegram ID cannot be changed by editing `@username`; a separate Telegram account with another number remains a possible abuse vector. Daily quota and 250 MiB upper bound mitigate cost; they cannot prove one real human.
- No production merge until independent review; master merge auto-deploys.


## TRIAL-01 review corrections (2026-10-26)

- Added durable unique Telegram ID as well as user ID and HMAC phone; relinking an account cannot reclaim with another number.
- Replaced the expiring Cache admission lease with a locked SQL day counter (Tehran calendar). Failed/uncertain reservations remain consumed. TRIAL-02 additionally caps worker activation attempts per Tehran calendar day to cover cross-day backlogs. Rollback refuses to erase consumed quota or claims.
- Identity owns a fresh locked, active ordinary-member check; additional/custom roles or privileges fail closed. Sales owns prior paid-order/settlement history. Repeat UI checks do not replace transactional admission.
- Native-contact validation produces only a masked number and HMAC for the later short-lived confirmation draft. Raw phone parameters are sensitive; no raw phone is persisted in claims, queues, callbacks or logs.
- Review and CI evidence remain separate from real Telegram contact and Remnawave acceptance. No Production merge or rollout is authorized.


## TRIAL-02 source implementation (2026-10-26; stacked, unmerged)

- Sales-only home/account entry and `/trial`, native self-contact request, 5-minute server-only fingerprint/masked confirmation, owner-bound random one-use callback; no typed/forwarded/foreign contacts. Cancel/Home retire the draft and remove its temporary Reply keyboard, retaining MENU-01's native menu.
- Additive activation linkage/UTC timestamps and a trial FK in the existing notification deliveries. `ProvisionFreeTrialJob` uses the existing async provider connection; SQL claim + existing `RemnawaveSubscriptionLifecycle` reserve the single CREATE. No Sales/Billing/Referral writes or paid-plan validation changes.
- Gate new activation with `TRIAL_ENABLED`, global RW writes and an exact match of `TRIAL_INTERNAL_SQUAD_UUID` and `TRIAL_VERIFIED_SQUAD_UUID`. Set the latter only after separately authorized disposable-user validation on that exact Squad. Defaults are false/empty. Changing the Squad requires fresh verification.
- The pinned upstream contract at Remnawave 3.4.4 (`b22970cc88481a7e278b5767721672a18f8b2ada`, `libs/contract/commands/users/create-user.command.ts`) accepts absolute ISO UTC `expireAt`, byte `trafficLimitBytes`, `NO_RESET` and Internal Squad UUIDs. Freeze the UTC CREATE instant on the worker (never Contact/queue admission), target exactly +7200 seconds / 262144000 bytes, and confirm the response tuple through the existing lifecycle. The API has no relative activation-duration field; network/Node propagation and the real panel clock still need live acceptance. Provider success is not proof of VPN connectivity.
- Any claimed/crashed/uncertain CREATE stays blocked. Minute Scheduler recovery dispatches **only untouched `reserved` claims**, quarantining stale provisioning claims. Privileged `ProvisionFreeTrial::reconcile(trialId, operatorId)` uses existing GET-only lifecycle reconciliation; it never reissues CREATE. No new provider API/webhook or generic provisioning engine.
- Fresh ordinary-owner checks before and after GET validate remote ID/marker, exact quota/expiry/Squad/NO_RESET. Status DTOs never contain credentials; link retrieval is separate, active/unexpired/unexhausted only, with existing QR PNG renderer and private delivery.
- `TrialActivated` shares the existing durable notification table/worker/sender and rechecks owner/provider on delivery. Full trial terminal monitoring and paid-warning isolation are TRIAL-03, so keep rollout OFF.
- Source fake HTTP/Nutgram tests are not real Telegram/Remnawave acceptance. No Production ENV/data/provider action or master merge.

TRIAL-02 also locks the same day row and increments a separate `activation_count` before the one CREATE attempt. Cross-day pending reservations cannot bypass today's activation ceiling. Uncertain/rejected attempts consume that slot; untouched capacity-blocked claims remain reserved for a later day.
