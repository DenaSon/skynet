# Module Boundaries

**Current (2026-10-12):** F0–F10-E3 merged on `master`; Telegram Identity/RBAC, Referral, Billing top-ups, Sales orders, authorized subscription reads, and Nutgram customer UI are implemented. F11-A1 wallet settlement and F11-A2 Super Admin manual bank review **merged via PR #30/#33**, both disabled by default; no consumer, live bank or provider E2E. Administration remains a shell. See [canonical context](000-CONTEXT.md).

| Module | Ownership | Merged implementation / current boundary |
| --- | --- | --- |
| Identity | Users, authenticated actors, RBAC and audit | Session and Telegram identity; role/status actions, protected roots |
| Referral / Onboarding | Invitation attribution and opt-in signup | Single-shot referral join; no financial rewards |
| Billing | All bank references, wallet balances and a unified double-entry ledger | F08 top-ups, F11-A1 wallet debit, F11-A2 direct bank journals; one `billing_journals`/`billing_postings` ledger and verified reference registry |
| Sales | Products, order/receipt states, settlement identity, review audit and outbox | `CreatePendingOrder`, guarded `SettleWalletOrder`, `ManualBankPayments`; no direct financial postings or Billing table queries; no dispatcher |
| Provisioning | Remnawave transport, node/user DTOs and durable provider operation state | F10-A/B/C/D implemented internally; no actor-authorized sales integration or live panel E2E |
| Administration | Future admin UI composition | Registered shell only |
| Telegram (interface) | Private chatbot navigation and feature presenters | Identity/RBAC/onboarding/wallet/shop/orders/subscriptions; F11-A2 receipt-photo + Super Admin approve/reject UX (OFF by default) |
| Web UI | Planned shared Application consumer | Livewire 4 + Mary UI deferred |

## Identity layout
```text
app/Modules/Identity/
  (dynamic Spatie roles/permissions replace the removed UserRole enum)
  Application/{CreateUser,UpdateUserAccess}.php
  Infrastructure/Models/User.php
  Presentation/
    Console/BootstrapAdmin.php
    Controllers/{AuthenticatedSessionController,AccountController,AdminUserController}.php
    Middleware/EnsureActiveUser.php
    Requests/
    routes/web.php
    views/login.blade.php
  IdentityServiceProvider.php
```

`config/auth.php` references `App\Modules\Identity\Infrastructure\Models\User`. Its factory remains in `database/factories` for Laravel convention. This module owns login and JSON admin-user endpoints; Administration must **not** directly access Identity's infrastructure.

## Contracts / boundaries
- This first slice provides application Actions without introducing a generic repository/service abstraction.
- Other modules must not import Identity's infrastructure model or internal controllers. Introduce deliberate read/use-case interfaces when there is a real cross-module consumer.
- The roles `admin` and `member` are starting access levels, **not** a finalized business roles taxonomy (no accountant, reseller or network-specific roles yet).
- `is_active` is enforced on protected requests, preventing disabled users from continuing existing sessions.

## HTTP endpoints
- `GET /login` (simple Blade form); `POST /login` (throttled); `POST /logout`.
- `GET /account` (authenticated JSON identity summary).
- `GET /admin/users`, `POST /admin/users`, `PATCH /admin/users/{user}`: session+CSRF protected; admin-only; JSON results.
- Public registration is intentionally absent. Bootstrap: `php artisan identity:bootstrap-admin`.

## Deferred / not yet operational
Livewire/Mary UI presentation, customer subscription reads via F10-E, paid-order transition, wallet purchase debit, provisioning outbox, provider write calls from a trusted business action, user preferences storage, password reset/email verification/2FA, live E2E and independent SSH/WireGuard transport design. **F06-B/C Telegram admin workflows are implemented**, not deferred.

## Telegram interface, not a business module (F4)
`app/Interfaces/Telegram/` contains webhook controller, Nutgram handlers, inline-menu renderer, retry receipts, and operator commands. It only imports `App\Modules\Identity\Application\*` for account linking and actor resolution. Transport data does not become a domain model. `routes/telegram.php` registers Nutgram handlers.

Within Identity, `Application/{IssueTelegramLinkCode,LinkTelegramAccount,ResolveTelegramActor,TelegramActor}` is the public use-case boundary. `Infrastructure/Models/{TelegramAccount,TelegramLinkToken}` and the new migration implement identity persistence. The link code is never stored raw. Telegram numeric IDs are immutable join keys; usernames are not authoritative. First binding requires an operator-generated one-time code.

See [Telegram operations](006-TELEGRAM-OPERATIONS.md) for setup and [ADR 0004](adr/0004-telegram-first.md) for scope.

## F5 Identity RBAC extension (merged)
The old UserRole enum/column is migrated to Spatie `roles`, `permissions`, `model_has_roles`, and related pivots. The Identity Application layer exposes `CreateRole`, `AssignUserRoles`, `SuperAdminTrust` and `RecordIdentityAudit`. Web account/user endpoints retain their old `role` compatibility field and also return `roles`. Super Admin enrollment is a privileged console operation only; it cannot be performed via an HTTP endpoint. See [RBAC operations](007-RBAC-OPERATIONS.md).

## F06-A Telegram navigation shell
The Telegram adapter adds `SettingsHandler`, `AdminUsersHandler`, `AdminRolesHandler` and explicit command/callback registrations. `MenuRenderer` owns text and inline keyboard navigation, including Home, Account, Settings, Administration, and **preview-only** Users/Roles pages. Nothing is persisted by these new pages.

`ResolveTelegramActor` returns an immutable Identity Application DTO with fresh roles and `manageRoles` entitlement. The latter is available only to a trusted, allowlisted and linked Super Admin. Access is checked on **every** callback, not just while generating the keyboard. The adapter neither reads Identity models nor grants privileged roles. Later F06-B/C handlers can replace previews with actual authorized application operations.

## F06-B — User directory and status use cases
`Identity/Application/BrowseUsers` provides paginated, authorized, limited search and detail DTOs (`UserDirectoryEntry`, `UserDirectoryPage`), without exposing Eloquent models to the Telegram adapter. `ChangeUserStatus` atomically validates expected state and delegates to `UpdateUserAccess`; this preserves F05 RBAC restrictions and audit logging. The adapter owns only presentation and temporary operator-bound confirmation tokens (`UserStatusConfirmations` using Laravel Cache locks).

The first real Telegram management feature is user **view/search/enable/disable**. The UI intentionally does not create users, change roles, expose passwords or alter protected Super Admins; RBAC mutation remains F06-C. The view/read use cases are reusable from future Livewire components without an HTTP API.

## F06-C — Telegram role management and reusable actions
`Identity/Application/ManageRoles` returns immutable role + permission DTOs to any presentation adapter. `ToggleRolePermission` changes an existing permission only on a custom role, and `ToggleMemberRole` adds/removes one role on an existing non-root account while preserving multi-role membership. Both actions re-authorize a trusted Super Admin and reject changed state before delegating to Spatie/Audit. `CreateRole` and `AssignUserRoles` from F5 remain their underlying write contracts. The Telegram adapter owns only `RbacUi`, inline keyboard rendering and three-minute one-use `RbacConfirmations`. No role, permission or HTTP authorization logic is duplicated in Telegram.

## F07 — Referral and Onboarding
`Modules/Referral` owns `referral_codes`, `referrals` persistence and Actions for stable invite issuance and first-time attribution. No referral logic is embedded into Identity models or order/financial tables. `Modules/Onboarding/Application/JoinFromTelegram` composes the Identity `RegisterTelegramMember` and Referral `AttributeReferral` use cases in a single transaction, to atomically create a member and record first attribution, without transport-specific persistence logic. The Telegram adapter owns 15-minute pending deep-link context, public signup gate and the explicit consent button.

The original Identity operator-driven one-use `/link` code remains valid for existing unlinked users. Public onboarding creates a new member account only when explicitly confirmed. No email verification or web password has been issued; synthetic emails are non-routable placeholders and must not be treated as verified contact information. User passwords are high-entropy, unrecoverable random values. More complete account/contact management remains deferred.

## F08 — Billing & Wallet
`Modules/Billing/Application` owns wallet reads, topup initiation, user-submitted bank reference, root approval/rejection, and ledger entry creation. `billing_wallets` stores ownership only, not mutable money; `billing_topups` stores lifecycle; `billing_verified_receipts` uniquely identifies transfers already credited; `billing_journals` and `billing_postings` constitute a minimal two-posting ledger. `ViewWallet` sums credited/debited wallet liability postings. This is not a general-purpose accounting or bank system.

The Billing module does not depend on Identity Eloquent models: `Identity/Application/AssertBillingAccess` exposes reseller eligibility and trusted Super Admin review checks. Telegram BillingUi is a thin private-chat adapter, providing `/wallet`, `/topup <toman>`, `/paid <topup> <reference>`, `/topups` and root-only confirmation callbacks. Approved topups are not purchases: F09 Sales will decide the reservation/debit semantics after domain requirements and end-to-end payment testing.

## F09-A — Sales Catalog and Pricing
`Modules/Sales/Application` owns product/rate writes (operator terminal only), buyer-filtered catalog reads and read-only quote calculation. Data is in `sales_products` and `sales_product_rates`, with unique slug and product/duration constraints. `Identity/Application/ResolveSalesBuyer` enforces active-user access and resolves an active `reseller` role without importing Identity persistence into Sales. Pricing is public `base + (GB × rate)` in integer rials; 5% reseller discount is derived at quote time from the same public tariff and rounded to nearest rial (half up). A 0% configuration is allowed for parity. No role-specific price lists or marketing campaign model.

Operator setup is `sales:product:set` and `sales:rate:set` in `routes/console.php`, not an HTTP/Telegram mutation. The returned immutable `CatalogProduct`, `ProductRate`, `PriceQuote` DTOs are intended for F09-B Telegram and later Web presenters. F09-C orders and price snapshots, F10 Remnawave and F11 billing debits are independent future increments.

## F09-B — Telegram-only Subscription Builder
`Interfaces/Telegram/Support/ShopUi` consumes immutable `CatalogProduct` and `PriceQuote` DTOs through F09-A `ListProducts`/`CalculateQuote`. It owns inline navigation, a bounded product list, preset and custom volume UX, and preview formatting but no catalog writes or pricing rules. `PendingShopVolume` keeps a short-lived per-buyer pending product/duration **only while entering a custom integer**; price is recalculated by Sales on each action. The Telegram adapter deliberately has no order/payment/provisioning side effects.

## F09-C — Pending-payment order boundaries
`Modules/Sales/Application/CreatePendingOrder` owns checkout: accepts an immutable expected quote and unpredictable idempotency key, reauthorizes the active buyer, reprices with `CalculateQuote` and atomically inserts a buyer-owned `pending_payment` order with its full public/reseller quote snapshot. Repeats with a previously successful key return the same record; price/role changes before the first order require a fresh quote. `OrderReader` provides owner-scoped latest-ten and individual reads, never direct Telegram DB access.

`Interfaces/Telegram/Support/CheckoutTokens` is an ephemeral 10-minute user-bound preview, not financial proof. `ShopUi` exposes only an explicit order-create button and order-history navigation. There is no F09-C workflow that changes an order to paid, reserves or debits wallets, or provisions a provider.


## F10-A/B — Remnawave transport and scoped provider discovery (merged PRs #19–#20)
`Modules/Provisioning/Infrastructure/Remnawave` owns the external HTTP transport, pinned OpenAPI operation map, and read-only resource discovery. The `Presentation/Console/InspectRemnawave` operator command is the only new interface; `ProvisioningServiceProvider` registers it. The integration has **no dependency on Sales/Billing/Identity persistence**, and Telegram imports nothing from provider infrastructure.

A configured operator may make five read-only GET requests to health, nodes, hosts, internal squads, and config profiles. The integration projects only minimal, safe node metadata to a typed DTO. It has no external writes, no customer/role service exposure, no webhook, no cross-module events and no order-provisioning side effects. Paid-order transition and any future `RemnawaveProvider` remain gated to a separate tested increment. See [staging runbook](010-REMNAWAVE-STAGING.md).

## F10-C — Internal Nodes API and telemetry (merged PR #21)
`RemnawaveNodesApi` provides typed method names for the 15 `NodesController` routes and basic argument verification. `RemnawaveNodeTelemetry` provides read-only node online-user and bandwidth read operations using the same HTTP transport. They are infrastructure classes, not Sales/Application actions; neither is callable from Telegram, public HTTP or any auto-provisioning workflow. Sensitive node writes cannot be enabled until an explicit Application authorization/confirmation/audit boundary is implemented. See [Node API and staging gates](011-REMNAWAVE-NODES.md).

## F10-D — Provider Users and subscription operations
`Provisioning/Infrastructure/Remnawave/RemnawaveUsersApi` implements the numeric-ID v3 user lifecycle; `CreateRemnawaveUser` validates a deliberately narrow creation contract. `Provisioning/Application/RemnawaveSubscriptionLifecycle` is the **only proposed business integration entry point** and owns durable idempotency reservations, provider ownership marker validation, read-only reconciliation and subscription lookup. No actor/payment/order authorization is implemented yet, so **no external adapter may call mutating methods**. Flag `REMNAWAVE_WRITES_ENABLED=false` by default. See [F10-D runbook](012-REMNAWAVE-USER-LIFECYCLE.md).

## F10-E1/E2/E3 merged subscription authorization and presentation

[PR #26](https://github.com/DenaSon/skynet/pull/26) introduces **F10-E1**: a one-to-one, FK-protected `provisioning_order_links` bridge (no writer until a trusted F11 settlement flow), `Sales/Application/OrderReader::page` with owner-scoped 1–50 keyset pagination, and `Provisioning/Application/SubscriptionOwnershipQuery` with secret-free `SubscriptionSummary`/`SubscriptionDetails`/`SubscriptionCursorPage` projections. It reads Sales via **Application DTOs**, batches local mapping lookups, and fails closed when an unpaid order is linked. These are **internal** methods; a caller-supplied buyer ID is not by itself authorization for a public/Telegram entry point. **F10-E2** provides fresh Identity-authorized owner-scoped list/detail/link/nodes (merged #27), and **F10-E3** provides the thin private Telegram presenter (merged #28). Future Livewire will reuse the same actions. `RemnawaveSubscriptionLifecycle::subscription(reference)` checks local mapping and remote marker, **not customer ownership**; do not call it directly from a UI handler.

F11-A1 adds default-disabled, internal reseller wallet debit + atomic paid transition and pending outbox. Existing Telegram checkout still creates `pending_payment` orders and never invokes that action. Provider writes are default-disabled and live E2E has not occurred. See [Context](000-CONTEXT.md) and [F10-D lifecycle](012-REMNAWAVE-USER-LIFECYCLE.md).

## F10-E2 — shared authenticated read entry points (PR #27)

`Identity/Application/SubscriptionViewer` describes an internal Telegram or web request context; `AuthorizeSubscriptionViewer` re-resolves fresh linked/active Identity on every use case. `ListMySubscriptions` and `GetMySubscription` use owner-scoped Sales/Application DTOs with no network. `ActiveSubscriptionAccess` is the guarded Remnawave boundary for `GetSubscriptionLink` and `ListAccessibleNodes`; it requires paid/fulfilled order, linked active provider record, marker + remote ID match, usable provider status, and repeats authorization and binding checks **after network reads**. The link DTO is a bearer credential and must only leave over a private authenticated channel. The node DTO exposes only display name and country, not IP, UUID, inbounds or provider JSON.

These read Actions are already invoked from the private Telegram UI (F10-E3, PR #28 merged). No Telegram-triggered settlement, auto-provisioning, refund synchronization, remote write or live Remnawave E2E is implemented. See [Context](000-CONTEXT.md) and [Issue #24](https://github.com/DenaSon/skynet/issues/24).

## F11-A1 — Internal wallet settlement (PR #30 merged, default-disabled)

Sales Application `SettleWalletOrder` is restricted to a freshly authorized, active reseller's own pending order. Billing Application `DebitWalletForOrder` can run only within a database transaction with the feature enabled; it locks the wallet and creates a balanced debit of prepaid liability and credit of unfulfilled order obligation. Sales atomically stores the immutable settlement, changes the order to `paid` and queues a unique pending outbox intent. An idempotent replay confirms the journal **and both exact postings**. No Telegram/HTTP/CLI caller, no consumer, no provider writes and no direct bank proof are included. Full staging/bank checks remain required.

## F11-A2 — Manual direct bank receipt settlement (PR #33 merged, default-disabled)

The Telegram presenter only accepts owner-submitted private photo updates for a pending Sales order, and forwards the photo to eligible Super Admins with approve/reject buttons and a durable review list. Sales `ManualBankPayments` owns encrypted Telegram file references, audited decisions and a bank-statement reference entered by the reviewer. Only an active allowlisted Super Admin can approve with a 3-minute actor-bound final confirmation. The unique reference claim is shared with F08 top-ups and cross-checks historical receipts. Direct-bank settlement creates balanced cash/order-liability postings, changes the order to `paid` and queues **one pending** outbox event in one transaction. No F11-B worker/Remnawave dispatch and no independent bank integration; see [F11-A2 runbook](014-F11-BANK-REVIEW.md).

## F11-B0 — Billing vs Sales (pre-production migration baseline)

The single source of monetary truth is `billing_journals` + `billing_postings`: F08 deposits and F11-A1 prepaid debits reference wallets, whereas F11-A2 verified direct bank receipts use a `direct_bank_sale` journal with `wallet_id=NULL`. Sales bank settlement retains a unique `billing_journal_id`, not a second postings table. Billing Application owns `ClaimVerifiedBankReference`, `RecordDirectBankPayment` and `VerifyWalletOrderDebit`; Sales only orchestrates buyer/claim authorization, immutable order settlement, status, audit and pending outbox in one transaction. A caller-supplied receipt is never proof of payment. No production data existed when baseline migrations were edited. See [ADR 0008](adr/0008-unified-billing-ledger.md).
