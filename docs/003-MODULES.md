# Module Boundaries

**Current:** Identity with F5 RBAC, Telegram adapter with F06-A navigation; Administration remains an empty registered shell.

| Module | Ownership | Implemented |
| --- | --- | --- |
| Identity | User persistence, session authentication, role/active-state authorization, admin user-management use cases and safe bootstrap | Identity model, actions, web controllers, form requests, middleware, console command, routes and tests |
| Administration | Future administrative interface/composition; no identity internals | Empty provider only |

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

## Deferred
Livewire/Mary UI presentation, administrative Telegram operations (F06-B/C), user preferences storage, password reset / email verification / 2FA, and networking.

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
