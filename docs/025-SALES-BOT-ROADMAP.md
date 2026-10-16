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
