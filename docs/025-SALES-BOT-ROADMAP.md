# ParsaGate — EPIC-SB Sales Bot roadmap

**Approved:** 2026-10-17. Six independent phases. This is the approved Sales development scope, not authorization to turn on public accounts, banking, provisioning or customer cutover.

## Boundaries and delivery rules

- Exactly two Telegram bot identities, one Laravel application, shared Identity, Sales, Billing and Provisioning Application Actions, one primary database.
- Preserve the original Admin bot's supported Member/Reseller operations until a separate controlled cutover. The Sales router must never import Admin handlers or legacy Sales routes.
- Each phase: smallest PR, focused Pest regression, PHP 8.4/8.5 and disposable MySQL CI, PHPStan, Pint, review and merge to master; update only necessary docs.
- Production activation stays separate: Sales runtime, public signup, Telegram notifications, money and Remnawave writes all remain OFF until their explicitly approved gates. GitHub merge auto-deploys; source CI is not real Telegram/bank/provider E2E.

## Phase definitions and acceptance

| Phase | Deliverable | Minimum code acceptance | Exclusions |
| --- | --- | --- | --- |
| **SB-01** | Isolated Sales /start, /menu, /account, one-time /link, explicit gated new-member signup and simple role-aware Persian home | No Admin callbacks, private chat identity match, current role and active-status checks every update, signup OFF default, account never created on /start, preserve original router | Catalog, checkout, payment, provider writes or production enablement |
| **SB-02** | Plan listings, role-specific price previews and reseller builder through existing Sales Actions | Only visible/current active plans and prices, role revocation/stale callbacks fail closed, no unintended writes | Pending orders, bank settlement |
| **SB-03** | New-plan checkout and owner-scoped pending-payment orders; Plan–Squad readiness mapping | Fresh quote/revision/role checks and immutable price snapshot; idempotent order creation; no write to bank or provider | Paid transition and provisioning |
| **SB-04** | Card-to-card receipts, review in Admin Bot, buyer status and two-bot notification plumbing | Reuse ManualBankPayments and notification outbox, dedupe photo and bank claim, retain finance and delivery gates OFF | Automatic bank verification or live financial activation |
| **SB-05** | Paid-order gated provisioning, safe Plan–Squad conversion and reconciled Remnawave lifecycle | Unique order-to-provider mapping, no retry of uncertain writes, owner isolation, provider writes OFF until staging acceptance | Live VPS/Node setup or production provider writes without approval |
| **SB-06** | Customer-owned subscriptions, guarded connection link, expiry/usage/status, final Persian navigation | Never leak secrets in lists/logs; per-user authorization on every action, graceful unavailable/error views, regression and manual release checklist | Public launch before accepted live tests |

## SB-04 — Sales receipt, Admin bank review and two-bot notification source acceptance

- Reuse the existing `ManualPaymentUi`, `ManualBankPayments`, `PaymentNotificationOutbox`, `TelegramNotificationSender`, `PaymentDecisionTokens` and Admin Bot root-only `/payments`/`/bankconfirm` callbacks. Do **not** move finance review callbacks to Sales or introduce a second bank ledger.
- Sales registers just `sales:payment:start:{id}` and its private-photo listener; start requires an **owner-scoped managed-plan order**, `pending_payment`, and `SALES_MANUAL_BANK_REVIEW_ENABLED=true`. Pending upload window is 10 minutes, keyed by Sales context. A photo must be from the same verified private account. Receipt file IDs remain encrypted and image fingerprints/message IDs remain deduplicated at DB level. Photos do not mark orders paid.
- Buyer can see owner-authorized most recent receipt state `submitted/rejected/approved` on an order; an already submitted receipt hides the upload action and a rejected receipt permits another genuine image. No bank transaction references or private photo IDs appear in buyer UI.
- Existing trusted-root Admin queue, image review, independent bank-statement verification, one-time token and unified Billing journal remain the **only** approval/settlement authority. A paid order creates the existing dormant fulfillment outbox entry but **no remote provisioning** occurs in SB-04.
- Notifications reuse MB-05A durable DB outbox and bounded cross-token media bridge; the default-OFF notification gate persists a **suppressed**, non-replayable intent. In this OFF state, the legacy original Admin Bot keeps its existing best-effort notification behavior, but **Sales deliberately does not send Admin-specific bank callbacks from its Sales token**. Admin can always inspect the durable `/payments` queue; real cross-bot notification rollout is a separate approval gate.
- For an explicitly enabled non-public two-bot environment, an active root receives Sales-origin receipt media only through the Admin token (or safe identifier fallback) and an active Sales buyer with a positively reachable Sales contact receives the decision from the Sales token. No copied provider credentials, unbounded media, direct bot-to-bot IDs, or message forwarding hacks.
- **Code acceptance:** source tests for disabled gate, private/owner checks, status, duplicate receipt/claim, role denial, one independent bank reference and one journal/fulfillment intent, no provider operations, cross-token HTTP fake, full PHP 8.4/8.5 Pest/PHPStan/Pint and MySQL migration/rollback, route inventory unchanged on Admin.
- **Not yet live accepted:** two real private Telegram identities and bot tokens, real bank evidence, queue completion, outbound photo/notification delivery, Production migration and release gates. Never activate Sales, bank or notifications merely because CI is green. Rollback is the isolated additive UI/Action PR; no new migration.

## SB-03 — Managed-plan pending-payment checkout checkpoint

- `CreatePendingPlanOrder` reuses canonical `RevalidatePlanQuote`, current plan/price authorizations, `ResolveSalesBuyer`, `OrderReader` and the existing root-owned Squad evidence contract. It inserts **only** pending-payment records; there is no Billing/Remnawave/notification operation.
- **Plan–Squad fail-closed:** no order without `services.remnawave.squad_bindings_live_verified=true`, a verified non-null UUID, current plan revision, active product/plan and freshly authorized buyer. A private in-code readiness method shares the existing root-only predicate; the public Admin readiness check keeps its root access guard. No fake squad/Node/Host binding is provisioned. The flag stays OFF in Production until separate live verification and approval.
- **Immutable snapshot in the EXISTING Sales order:** nullable `plan_id` (already present), new nullable plan slug/kind, plan and tariff revision and verified squad UUID; current product slug/name, duration/nullable traffic, public/discount/final **integer rials** and pending-payment state. Nullable traffic is an additive widening to preserve unlimited time plans exactly; older finite F09 orders remain valid.
- **Atomic idempotency:** database-unique 32-hex checkout key, owner-bound opaque 10-minute quote token; transaction verifies current buyer, locks the plan/pricing row, calls `RevalidatePlanQuote`, checks the Squad mapping and inserts once; repeated matching token returns the same order snapshot, a different buyer/quote cannot reuse it. Never auto-provision or mark paid.
- **Sales-only Telegram UI:** review/commit, customer-owned last-ten order list and detail; price detail and reseller quotes link to the same guarded checkout, without impacting the legacy Admin token. Stale/private-chat denial and clear Back navigation. No payment or receipt action until SB-04.
- **Migration safety:** additive nullable snapshot fields; `traffic_gb` widened to nullable; rollback blocked when any managed-plan order exists to avoid losing snapshots. No destructive migration, no seeded live binding, no new dependencies.
- **Source acceptance:** verified sandbox-only fixtures and tests for missing/off/stale binding, changed plan/price/role, quote expiry, duplicates, ownership, time-based unlimited and absent billing/provider writes; full PHP 8.4/8.5, MySQL upgrade/rollback, Pest, PHPStan/Pint. Live Telegram + real Node/Squad verification remains deferred by operator. Source/CI green is **not** rollout approval.

## SB-02 — Implementation and acceptance scope

- Reuse the existing `PlanCatalogUi` and `ResellerBuilderUi` via the isolated Sales router, not copied Sales pricing/Presenter logic. Bind only their list, detail, paging, read-only quote, custom input, cancel, and back callbacks.
- A linked active user sees the priced catalog; a current reseller additionally sees the builder. Existing Application Actions reauthorize each call against current buyer, plan audience/status/revision, published price and current reseller role; no second discount policy.
- The Sales-only `menu:home` returns to `SalesHomeUi`; no Admin or legacy Shop routers are imported. Free-form text is accepted only by the existing 10-minute reseller builder selection.
- **Source acceptance:** new regression cases for member/reseller pricing, missing or withdrawn prices, role revocation, invalid/stale callback, group denial, custom builder input, zero orders/financial/provider traffic and unchanged legacy Admin route inventory. PHP 8.4/8.5, MySQL, PHPStan and Pint must pass.
- **Release:** Sales runtime/public signup/notifications/billing/provider writes remain OFF on Production. Live Telegram tests deferred by operator. Rollback is the isolated SB-02 PR, without any migration.

## SB-01 implementation contract and current checkpoint

- The existing Sales runtime and webhook already isolate token, secret, Telegram update receipts and cache. Add only a Sales route registry and one small presentation adapter. Do not touch Admin's legacy shared handlers.
- Reuse the existing ResolveTelegramActor, LinkTelegramAccount and JoinFromTelegram application actions. A newly created account is always only a member. Existing linked user/role identity is shared across both bots; a disabled account is never revived.
- Only an explicit registration-button callback may create an account when the existing public signup switch is TRUE. The default remains FALSE. /start and /menu are read-only.
- Inline menu includes only actually supported actions. Checkout/catalog UI comes later, not a dead or fake button now.
- No new migrations, environment keys, packages, backend service boundaries, Admin route change, payments or Remnawave write calls.
- **SB-01 source acceptance:** reviewed diff and green PHP 8.4/8.5, MySQL, Pest, PHPStan and Pint. **Live acceptance deferred at operator request:** BotFather Sales token /start, /account, /link, opt-in and stale-role checks, denied Admin callbacks, no Admin regression. Without actual Sales Telegram smoke, status is CODE COMPLETE, not LIVE ACCEPTED.
- **Rollback:** revert the isolated SB-01 PR. Default-disabled Sales path avoids a forced customer migration; no DB rollback is necessary.

**Production switches:** TELEGRAM_SALES_ENABLED=false; TELEGRAM_PUBLIC_SIGNUP_ENABLED=false; TELEGRAM_NOTIFICATIONS_ENABLED=false; REMNAWAVE_WRITES_ENABLED=false. These are safeguards, not application launch instructions.
