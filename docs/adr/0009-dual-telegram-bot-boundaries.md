# ADR 0009 — Two Telegram Bot Contexts, One Laravel Backend (MB-01)

**Date:** 2026-10-15  
**Status:** Accepted as an **implementation contract**, not implemented  
**Scope:** MB-01 architecture decision. Planned implementation belongs to MB-02–MB-06.  
**Sources:** [approved execution roadmap](../018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md), [audited route inventory](../019-MB01-TELEGRAM-ROUTE-INVENTORY.md), [existing Telegram ADR](0004-telegram-first.md), [UX identity](../017-TELEGRAM-UX-IDENTITY.md).

## Context and non-negotiable invariants

Today `routes/telegram.php` registers admin, customer and reseller routes on one Nutgram instance with one token, webhook, update ID namespace, shared ephemeral state and one account-link identity. `ManualPaymentUi` also delivers receipts and payment results using the currently running bot, which will **not** remain correct after two tokens exist. The approved objective is a private, usable Admin Bot plus a technically prepared, **not public** Sales Bot. There is no Remnawave staging server, but that does not block this work.

- Maintain **one Laravel modular monolith, one primary MySQL business database, one deployment**, and existing Identity/Sales/Billing/Provisioning **Application Actions**. No second HTTP application, event platform, extra ORM, or general bot-framework abstraction.
- Preserve all existing actor/RBAC guards, operations, callbacks, pricing, audit, Financial/Provider OFF flags, data, order ownership and legacy interactions. GitHub `master` merges auto-deploy.
- Protect the **existing Telegram-linked non-admin** population. GitHub code cannot establish their actual production count. **Never replace the only working customer/reseller path with an Admin-only bot until a tested alternative or an explicit legacy compatibility bridge is accepted.**
- A private Telegram chat and permission checks on **every update** remain mandatory; a bot username, callback label or hidden keyboard does not authorize business actions.

## Decision A — Fixed runtime identity, not actor-selected

Two **fixed server-side bot keys**, `admin` and `sales`, select separate trusted token, secret and Nutgram router/runtime. `BotContext` is **request/command resolved after a trusted endpoint/secret match** and is never read from arbitrary Telegram update JSON, command text or `callback_data`.

| Concern | Current bot / Admin | Future Sales |
| --- | --- | --- |
| Identity | Existing linked BotFather bot reused | New token, independent BotFather bot |
| Endpoint | Legacy `POST /api/telegram/webhook` + future `POST /api/telegram/admin/webhook` alias during transition | Future `POST /api/telegram/sales/webhook` (OFF by default) |
| Credential config | Existing `TELEGRAM_TOKEN` / `TELEGRAM_WEBHOOK_SECRET` must keep working, with a deliberate admin override only if needed | Separate token, secret and username, required only when sales explicitly enabled |
| Handler registration | Explicit Admin route set; legacy compatibility route set is retained until replacement | Explicit minimal sales shell; **no Admin action handlers registered** |
| Business identity | Same Identity lookup by Telegram user ID, fresh authorization | Same internal user identity, separately tracked contact eligibility |
| Provider and bank writes | Guarded existing Action boundaries, flags OFF | No new consumer purchase, bank or provider writes |

**Implementation preference:** small, explicit runtime/registration wiring in the existing `app/Interfaces/Telegram` folder. Avoid a new registry hierarchy, plugin loaders, custom service containers, duplicate Laravel app or stateful process-global mutable bot configuration. Preserve current `telegram:webhook:set` semantics for existing deployments; additional bot argument may be added later with a safe no-op if unconfigured.

**Security:** Validate route-specific secret in constant time, reject missing configuration, authenticate **before** claiming an update or initializing a bot; validate the update ID; then use bot-scoped processing. Do not accept token/URL overrides from a Telegram message. Never leak tokens or full API transport exception strings.

## Decision B — Update de-duplication, form state and rollout

**Current:** `telegram_updates` has an unsigned big integer `update_id` primary key, 90s processing lease, retry behavior and 7-day pruning of processed receipts. Both bots may legitimately emit the same numeric update ID.

**Target:** `(bot_key, update_id)` is unique. Query `claim`, `processed`, `failed`, pruning and indexes by the authenticated bot. Old receipts belong to `admin`. The existing legacy URL maps to that same admin scope, so it cannot double-claim the same Admin update through two URLs.

**Mandatory expand/compatibility strategy for MB-02:**
1. **Read-only preflight:** check actual Production MySQL schema/indexes, rows and retention metrics; inventory linked non-admin activity using aggregated counts only; record current webhook, flags, latest merged SHA and backout.
2. Add `bot_key` **with default `admin`** and safe non-destructive backfill. Do not blindly drop the old primary key while old code is still active. Stage indexes and alter uniqueness only when the deployed code understands both scopes. Design the MySQL primary/unique transition against real schema and test it with a representative copy, preserving all old rows.
3. Keep Sales webhook **OFF and unregistered** while old rollback code can still be used. Once two-bot Update receipts have been accepted, **do not roll back to an old version which queries only by `update_id`**; it can mix bot rows. Roll forward with a compatible fixed release, or disable Sales and migrate/restore only via explicitly reviewed migration. **No destructive down-migration on Production.**
4. Scope drafts, locks and one-shot tokens to `bot_key`. For existing in-flight cache keys use an expiring compatibility read/consume policy (short TTL), not wholesale cache clearing. Consume legacy confirmations only on the original Admin bot with actor + TTL checks; never allow old tokens in Sales Bot.
5. Preserve the original `/api/telegram/webhook` and its credentials until operator-controlled BotFather webhook cutover has been verified. Telegram only maintains one webhook URL **per bot** at a time; never enable two active deliveries for the same bot or drop pending updates.

**Acceptance required in MB-02:** independent handling of equal `update_id` values; cross-token secret rejected; both legacy/new URL for Admin scope dedupe the same update; fail-closed Sales when missing/off; in-flight form compatibility, DB rows retained, no business effect, safe rollout and rollback evidence.

## Decision C — One actor, two bot contact destinations

Identity remains one SkyNetwork user joined to one Telegram numeric user ID. **Do not clone users or assign roles based on which bot they open.** Each bot has its own `start/chat availability/blocked` delivery state; the server must not infer "started sales bot" from a currently linked Admin chat. Store the smallest per-bot contact record actually required for notifications, with positive evidence from a private update or Telegram send; do not introduce account re-linking solely for switching bots.

The existing `ResolveTelegramActor` returns current roles and trusted-root eligibility. Before displaying Admin UX, check active actor and `isAdmin`; for root-only mutations, retain the original `canManageRoles` allowlist check and authoritative module access. A revoked/disabled root must lose access immediately, including old keyboard presses.

`/start`, `/menu`, `/resetmenu`, `/link`, account/settings are classified `SHARED` intent, but **each bot still applies its local admission policy**. Admin Bot does not provide public signup or reseller purchase UX. Preserve the existing mixed route as legacy bridge for nonadmins until the future Sales Bot can actually serve them.

**Hard transition condition:** before an Admin-only home or command retirement becomes externally observable on the old bot, an operator must inspect anonymized Production counts of linked nonadmin and recent interactions and approve a concrete compatibility/communication plan. If safe migration is not available, keep legacy command behavior active while internally isolating the new routers. MB-03/MB-04 may still finish their **internal** changes without disabling customer access.

## Decision D — Registration inventory is the regression baseline

The complete current source inventory lives in [docs/019](../019-MB01-TELEGRAM-ROUTE-INVENTORY.md): **27 command**, **84 callback**, **1 photo**, **1 text** registrations, plus 18 known persistent Reply labels. Ownership is marked `ADMIN`, `SALES`, `SHARED` or `MIXED`.

- Admin: current `/admin`, `/users` / `users:*`, `/roles` / `rbac:*`, `/products` / `product:*`, `/plans` / `plan:*`, top-up review and bank-receipt review under existing flags. Keep `users.manage` and trusted-root boundaries distinct.
- Sales (legacy until Sales Bot is viable): F09 `/buy` and orders, managed-plan catalog/quote, `/builder`, reseller wallet/topup, buyer bank photo submission, referrals, `/subscriptions` owner-scoped link/node reads. Do **not** suggest a new managed-plan checkout is active.
- Mixed `onText`: current precedence `ReplyMenuHandler` → Product form → Plan form → Reseller Builder → Shop custom volume. Preserve legacy precedence and make new per-bot handlers strictly narrower.
- Old Reply labels and callback strings remain compatible for **the original bot**; Admin and Sales runtime must never register each other's privileged handlers.

**MB-03 acceptance:** full registration parity, unauthorized/stale/forged callback denial, private-chat-only operations, unchanged outcome of all existing Actions and no accidental nonadmin cutoff.

## Decision E — Cross-bot notifications are backend-owned, durable and media-aware

No Telegram bot sends a Bot API message directly to another bot; **Laravel** records a business event and sends to a **human user's started chat with the appropriate bot token**.

**Message contracts (narrow, internal, versioned only if needed):**

| Event | Durable source / invariants | Destination | Minimum payload (no secrets) | Failure behavior |
| --- | --- | --- | --- | --- |
| `ReceiptSubmitted` | Verified buyer/owner claim `sales_bank_payment_claims.id`; persist notification intent atomically with claim in MB-05A | Active trusted root recipients on Admin Bot | Event ID, claim ID, order ID, recipient user ID | Retry/backoff; claim always discoverable from Admin queue |
| `PaymentReviewed` | Committed review decision, reference independently verified by root; **no repeat settlement** | Buyer with started Sales Bot (or legacy bridge until ready) | Event ID, claim/order IDs, decision, buyer user ID | Retry or report unavailable; order view remains source of truth |
| `ProvisioningNeedsReview` | Existing durable provisioning operation in `needs_review` state | Admin Bot | Event ID, internal operation ID, safe status only | No repeated alert flood; independent operator recovery list |
| `SubscriptionStateChanged` | Only when trusted internal state actually transitions | Sales Bot owner (or legacy bridge) | Event ID, internal order/subscription ID, safe status | No secret subscription URL in generic notification |

All notifications must reuse existing **Laravel database queue**, but the existing `sales_fulfillment_outbox` is **for provisioning intent, not generic Telegram notification delivery**. In MB-05A add only the minimal durable notification record/delivery key genuinely needed for retries. No Kafka, WebSocket, generic event bus or separate infrastructure.

**Media caveat:** a `file_id` created for the Sales Bot is not safely reusable with the Admin Bot. `ManualBankPayments` currently stores an encrypted source-bot file ID and `ManualPaymentUi` sends it with the same bot. MB-05A must authorize source receipt access, securely retrieve bounded media with source bot credentials, store only private temporary/appropriately protected bytes if necessary, and upload to Admin Bot as a **new file**, with cleanup and no credential-bearing logs. Fallback: notify by claim/order identifiers and allow durable review; do not pretend a missing photo was delivered or authorize bank settlement from a photo alone.

**Delivery semantics:** at-least-once **attempts**, not guaranteed exactly-once Telegram messages. Record recipient+bot key+event key, attempts and outcome. Idempotency of the **business** decision is distinct from notification delivery. Even if a worker crashes after Telegram accepts a message, a duplicate notification can occur; a repeated approve must not double-settle. A blocked or not-started bot chat is not a success; surface delivery as unavailable and keep the claim/order accessible in-app.

## Decision F — Remnawave not part of transport rollout

`REMNAWAVE_WRITES_ENABLED=false` in production. The existing mapped 218 API operations are transport metadata, not authorization. Before staging exists, MB-05B may implement only guarded read Actions, DTOs and safe unavailable states using HTTP fakes. A green mock does not imply a live panel, real node, active subscription, or validated Internal Squad membership. Only RW-01–RW-04 live checks can establish provider capability; production provider/money writes and Sales public launch require separate approvals.

## Alternatives rejected / reasons

- **Two Laravel projects/databases:** duplicates Identity, ledger and Provisioning and creates cross-service consistency issues for no current need.
- **One bot with hidden Admin menu forever:** less operational separation than agreed; menu hiding is not permission boundary.
- **A full event bus + realtime socket platform:** unnecessary; existing Laravel DB queue and limited durable delivery satisfy the real receipt/order requirement.
- **Reusing Telegram `file_id` across bot tokens:** not portable as a correctness assumption; may silently fail.
- **Deleting legacy webhook or users immediately:** causes breaking changes because production customer/reseller usage is unknown.
- **Provider operations in Telegram presenter or all 218 SDK methods:** bypasses owner validation and expands scope.

## MB-01 acceptance recorded

- All command, callback, ReplyKeyboard, photo and text registrations classified against actual `master`.
- Minimal BotContext, RBAC, update-key, identity, notification, media, backward-compatibility, migration and rollback decisions defined.
- **No code or schema change.** Unverified production population and live external token/server tests are recorded as **explicit MB-02/MB-05 gates**, not described as completed.
- Future code PRs must test the scoped contracts above, pass PHP 8.4/8.5/PHPStan/Pint and update only relevant docs.

**Review/implementation handoff:** start MB-02 by reviewing the real `master`, `docs/019`, this ADR and current Production schema with read-only preflight; propose an additive schema/route PR; never perform destructive migration or provider writes.
