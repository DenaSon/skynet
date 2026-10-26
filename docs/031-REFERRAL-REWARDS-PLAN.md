# Referral rewards — approved three-phase plan

Approved 2026-10-26. This supersedes the historical F07 “no financial rewards” scope only for these increments. REF-01 is the financial foundation; REF-02 connects successful activations to durable queued processing. Both remain default-disabled; this is not a public launch.

## Rules

- One reward per newly referred person, after their first verified paid purchase has successfully activated. Payment alone, uploaded receipts, wallet topups and free trials never qualify. A later first paid purchase after a trial can qualify.
- Existing immutable signup attribution is authoritative. Opening another invite link cannot attach or change the referrer of an existing user. Usernames are not identity.
- Both participants must currently be active, linked ordinary members. Resellers and elevated/mixed roles are excluded. Discounted reseller order snapshots are rejected even if the buyer subsequently becomes a member.
- Default reward: 30,000 tomans (300,000 integer rials). Default lifetime limit: seven distinct invited buyers per referrer, NOT seven purchases by the same buyer. Renewals and repeat purchases do not earn another reward.
- The default-disabled switch, amount and limit are deployment configuration, not a new settings system or Admin UI. Invalid/zero/negative/fractional settings fail closed. A reduction never removes historical credit; an increase permits additional awards. Previously committed reward amounts never change.
- An explicit UTC rollout timestamp is required. Orders created before it cannot be backfilled. Previously valid signup attribution can qualify a future new purchase; pre-existing users without attribution cannot acquire it retroactively.

## REF-01 — internal financial foundation

`GrantReferralReward` is an internal Application action. REF-02 calls it only from the queued Sales reward outbox; no Telegram/HTTP handler credits a reward. It checks `ReadReferralPurchase`, existing exact settlement attestation, successful CREATE operation and immutable order/subscription mapping. It does no remote GET or WRITE. Completed activation is historical evidence: later expiration does not invalidate an earned reward.

The first successful purchase is determined by the completed fulfillment timestamp with order ID as tie-breaker. Previously fulfilled legacy orders also disqualify subsequent purchases. Unpaid or failed earlier orders do not. Referral reads Sales Application; Billing owns the balanced credit journal; Identity owns ordinary-member checks.

An additive `referral_rewards` table preserves referral, both users, order, frozen integer-rial amount and journal. Unique invited user, referral, order and journal prevent duplicate award identities. Both participants are locked in ascending ID order; a current locking read of the referrer's awards enforces the lifetime limit even under a stale MySQL REPEATABLE READ snapshot. The journal key is `referral:invitee:{internalUserId}`. Ledger posting and reward insert are one transaction; any failure rolls back both. Unexpected pre-existing journal conflicts fail closed.

Billing debits `referral_expense` and credits the existing `wallet_liability`. No synthetic bank receipt, topup, sale or manually authored business entry is created. The existing accounting summary includes the ledger expense exactly once in expenses/profit; ordinary sales and cash totals are unchanged. This is spendable wallet credit through existing purchases, not a cash payout feature.

Financial rows use restrictive foreign keys. The new migration refuses rollback while rewards exist; use a reviewed forward fix. Do not delete reward history to reset quota.

### Configuration (leave disabled)

```dotenv
REFERRAL_REWARDS_ENABLED=false
REFERRAL_REWARD_TOMANS=30000
REFERRAL_MAX_REWARDS_PER_REFERRER=7
REFERRAL_REWARDS_START_AT=
```

The start must be an explicit valid UTC `YYYY-MM-DDTHH:MM:SSZ` timestamp set at controlled launch, not a guessed date. Amount validation accepts positive whole tomans up to 100,000,000,000; limit accepts a positive whole count up to 1,000,000. Config strings from ENV are validated before conversion. REF-01 does not change Production configuration, data, cash or provider state. After REF-02, this switch controls capture and processing. Keep it OFF until REF-03 notification/UX and controlled live acceptance are complete.

## REF-02 — durable purchase integration

Depends on REF-01 PR #244; develop as a stacked PR while that base is unmerged. One additive migration extends the EXISTING `sales_fulfillment_outbox` with `referral_state`, a validated policy JSON snapshot, attempts, due time and a sanitized failure code. No parallel financial outbox or provider stack is introduced.

The successful `FulfillPaidPlanOrder::complete()` transaction (including GET-only reconciliation) freezes the configured amount, lifetime cap and UTC rollout boundary in the same update that records completion. Payment alone, failure and unknown CREATE outcomes cannot capture an intent. Disabled activations and pre-migration rows retain NULL state and are never backfilled. Invalid policy at capture is terminally suppressed rather than breaking an otherwise successful purchase. Missing reward columns during a rolling deploy skip capture; the feature must stay OFF until migration and worker deployment finish.

After commit, enqueue `GrantReferralRewardJob` through the existing safe Redis/database connection resolver. Enqueue/cache/queue exceptions are sanitized and cannot escape into provider fulfillment. A minute scheduler recovers only captured `pending` intents, at most 50 per dispatch. No historical-sales scan, provider GET/WRITE or purchase retry is used to recover a reward.

The worker locks the source outbox row, rechecks completed CREATE evidence, existing immutable signup referral, current ordinary-member eligibility, exact settlement and lifetime cap. The REF-01 unique award/ledger and the outbox terminal transition commit in one SQL transaction. A crash before commit leaves work pending; a lost acknowledgement after commit sees `credited` on retry and never re-credits. The original fulfillment `updated_at` is NOT changed by reward processing, preserving first-success ordering (timestamp, then order ID for equal timestamps). A second completed purchase processed first cannot bypass the first-purchase check.

Amount and rollout boundary are frozen. The effective cap is the smaller of the captured cap and the valid current cap: lowering it takes effect for pending work without clawing back money; raising it does not expand old captured work or revive terminal decisions. Settings are deployment configuration, not a new Admin settings UI. Disabling the flag or making current policy invalid pauses existing pending work; re-enabling resumes it with its original amount. Membership and purchase eligibility are checked at processing time, not promised merely by capturing an activation.

Terminal states: `credited`, `suppressed` (ineligible/capped/member revoked/config invalid at capture), `needs_review` (invalid snapshot or financial/source integrity). SQL failures roll back both money and state, then retry with 60-second incremental backoff, at most five recorded processing failures before review. If the database itself is unavailable, the original SQL intent remains pending. No exception bodies, tokens or customer data enter logs. Terminal decisions never auto-reopen after settings or membership change.

Operational commands (after migration, deployment and separately approved enablement):

```bash
php artisan sales:referrals:status
php artisan sales:referrals:dispatch
```

The first is read-only readiness/state counts; the second enqueues existing pending work and is already scheduled every minute. It cannot create new entitlements. `needs_review` requires evidence review and a future explicitly approved recovery action; do not delete financial history or manually reissue CREATE. Rollback refuses to drop columns once any captured/suppressed intent exists. No additional ENV key or seeder is required beyond REF-01.

## REF-03 — notification and invite UX (stacked source implementation)

Depends on REF-02 #245, which depends on REF-01 #244. Develop and review in that order; no feature enablement, production action or unrelated changes are included.

- A unique \`ReferralRewardCredited\` intent is inserted into the **existing** \`telegram_notification_deliveries\` table in the *same transaction* as the verified reward journal, reward evidence and \`credited\` fulfillment state. The event identity is the immutable reward ID; its \`order_id\` is for revalidation only. One failed SQL write rolls back the money and all states. Duplicate outbox/job deliveries neither post new money nor insert new notification intents.
- Notification delivery is strictly asynchronous through the **existing** dispatcher/sender/recovery. When telegram notifications are disabled at credit time, the record is permanently \`suppressed\` and is not replayed on later enablement. When enabled, queued sends require the linked, active referrer to have a reachable Sales Bot contact. The sender checks ownership, reward/order/event-key integrity and completed award state again; it sends only the earned amount and a safe invite callback, never a buyer name, phone, Telegram ID, order details or financial ledger identifiers.
- Telegram delivery uses the existing bounded retry/dead/unavailable states and is **not exactly once**: a transport timeout can occur after Telegram accepted the message. Retrying the notice never re-credits a wallet. Review unknown delivery outcomes operationally.
- Sales and legacy invite screens preserve their original navigation/link/share/copy affordances and show only owner-specific counts of registered invitees, distinct credited invitees, total historical credit, current configured per-person amount and **remaining** cap when the policy is valid and the viewer is an ordinary member. Under disabled/invalid configuration the award promotion is hidden and marked inactive; reseller or elevated accounts are not promised any reward. No invitee PII is exposed.
- No new migration, seeder, worker, queue driver, finance ledger, permission, provider call, ENV key or dependency in REF-03. Keep \`REFERRAL_REWARDS_ENABLED=false\` pending controlled acceptance. Notification enablement is independent and must already be operational.

### REF-03 source tests and acceptance gates

Cover atomic credit+notification rollback on SQL failure, unique notice on replay, suppressed notice behavior, authenticated Sales referrer delivery, forged event/ownership rejection, private content, owner-scoped live totals, changing cap/amount and unchanged invite navigation.

Before merge: PHP 8.4/8.5 CI, full suite, PHPStan, Pint, disposable MySQL migration and Redis queue checks on the final commit. After merge and migrations: operator-controlled activation with a **future** explicit UTC start, real first paid settled purchase and Remnawave activation, wallet journal/notification proof through a real Sales Bot, repeat-purchase and cap rejection, blocked-contact behavior, admin reporting, and worker/Scheduler recovery. External acceptance is NOT proven by fake HTTP/SQLite/isolated MySQL/Redis tests. Never replay old purchases, move money manually, or retry provider CREATE to recover a referral.

## Acceptance

REF-01 tests cover current-member eligibility, immutable signup attribution, seven-person cap and configuration changes, exact settlement/activation evidence, replay, rollback atomicity, finance reporting, restrictive evidence retention, and no automatic integration. Disposable MySQL smoke explicitly creates overlapping transactions and an old snapshot for final-slot contention and duplicate delivery. Bank and wallet fulfillment/reconciliation integration uses fake HTTP and verifies capture without inline credit. REF-02 adds queue recovery, frozen pricing, terminal suppression, bounded failure retries, atomic money/state rollback, and safe status tests. Disposable MySQL also exercises worker replay/cap enforcement across overlapping transactions; a real isolated Redis worker consumes a serialized reward job twice and credits once.

Required CI: PHP 8.4/8.5, PHPStan, Pint, MySQL migrations and referral/wallet concurrency plus Redis smoke. Source/CI results do not prove real Telegram delivery, real bank validation or Remnawave behavior. Live reward/notification acceptance and public enablement belong after REF-02/03; no real payout is authorized by this foundation.
