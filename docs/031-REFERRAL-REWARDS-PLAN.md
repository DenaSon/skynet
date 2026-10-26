# Referral rewards — approved three-phase plan

Approved 2026-10-26. This supersedes the historical F07 “no financial rewards” scope only for these increments. REF-01 is an internal, default-disabled foundation; it is not a public launch.

## Rules

- One reward per newly referred person, after their first verified paid purchase has successfully activated. Payment alone, uploaded receipts, wallet topups and free trials never qualify. A later first paid purchase after a trial can qualify.
- Existing immutable signup attribution is authoritative. Opening another invite link cannot attach or change the referrer of an existing user. Usernames are not identity.
- Both participants must currently be active, linked ordinary members. Resellers and elevated/mixed roles are excluded. Discounted reseller order snapshots are rejected even if the buyer subsequently becomes a member.
- Default reward: 30,000 tomans (300,000 integer rials). Default lifetime limit: seven distinct invited buyers per referrer, NOT seven purchases by the same buyer. Renewals and repeat purchases do not earn another reward.
- The default-disabled switch, amount and limit are deployment configuration, not a new settings system or Admin UI. Invalid/zero/negative/fractional settings fail closed. A reduction never removes historical credit; an increase permits additional awards. Previously committed reward amounts never change.
- An explicit UTC rollout timestamp is required. Orders created before it cannot be backfilled. Previously valid signup attribution can qualify a future new purchase; pre-existing users without attribution cannot acquire it retroactively.

## REF-01 — internal financial foundation

`GrantReferralReward` is an internal Application action with NO caller in Telegram, fulfillment, queue, scheduler, HTTP or console. It checks `ReadReferralPurchase`, existing exact settlement attestation, successful CREATE operation and immutable order/subscription mapping. It does no remote GET or WRITE. Completed activation is historical evidence: later expiration does not invalidate an earned reward.

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

The start must be an explicit valid UTC `YYYY-MM-DDTHH:MM:SSZ` timestamp set at controlled launch, not a guessed date. Amount validation accepts positive whole tomans up to 100,000,000,000; limit accepts a positive whole count up to 1,000,000. Config strings from ENV are validated before conversion. REF-01 does not change Production configuration, data, cash or provider state. Enabling its switch alone does not hook it to purchases.

## REF-02 — durable purchase integration (NOT implemented)

Capture reward eligibility from the successful activation transaction, including reconciliation success, with a durable pending intent and explicit configuration snapshot. Consume using the existing queue infrastructure with recovery for lost dispatch. Keep referral failures isolated from paid fulfillment: never turn an already successful CREATE into a retry or a second debit. Define terminal suppression for ineligible/capped claims so a later settings change does not silently backfill skipped events. Recheck eligibility and immutable settlement before grant; quota exhaustion is not a retriable infrastructure failure. Refine capture ordering with concurrent first purchases and the rollout boundary before wiring callers.

## REF-03 — notification and UX (NOT implemented)

In the same reward-credit transaction, record a unique intent in the existing Telegram notification outbox; network delivery occurs afterward. Notification retry cannot re-credit the wallet. Explain delivery uncertainty honestly; Telegram transport is not exactly-once. Update existing invitation screen with terms, registered friends vs rewarded purchases, earned total, and remaining reward slots. Preserve link/copy/share and owner isolation. Do not expose buyers' phone numbers or financial details to the referrer. No parallel notification stack.

## Acceptance

REF-01 tests cover current-member eligibility, immutable signup attribution, seven-person cap and configuration changes, exact settlement/activation evidence, replay, rollback atomicity, finance reporting, restrictive evidence retention, and no automatic integration. Disposable MySQL smoke explicitly creates overlapping transactions and an old snapshot for final-slot contention and duplicate delivery. Existing bank fulfillment integration uses fake HTTP and verifies no automatic award before an explicit internal call.

Required CI: PHP 8.4/8.5, PHPStan, Pint, MySQL migrations and referral/wallet concurrency plus Redis smoke. Source/CI results do not prove real Telegram delivery, real bank validation or Remnawave behavior. Live reward/notification acceptance and public enablement belong after REF-02/03; no real payout is authorized by this foundation.
