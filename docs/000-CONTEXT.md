# SkyNet — Current Context

**Reviewed:** 2026-10-11  
**Stage:** F09-A Product Catalog & Pricing implemented in `feature/09a-sales-catalog-pricing` (CI/merge pending). F09-B/C not implemented.  
**Behavioral truth:** repository code and tests, not aspirational plans.

## Product direction
SkyNet is a Laravel successor to Python NVL. **Telegram-first** is the accepted first UI channel. Later Livewire/API presenters call shared Application actions without duplicating business logic.

## Accepted architecture
- Laravel 13, PHP >=8.4.1, modular monolith, Pest 5, Larastan 3, Pint; CI tests PHP 8.4 / 8.5.
- Domain/Application/Infrastructure live in modules. Transport adapters live under `app/Interfaces/`.
- F3 Identity established session auth and the original `admin/member` enum. F5 replaces that single enum/column with Spatie Permission v8 role/permission records, while retaining session guards and user status.
- F4: `nutgram/laravel` ^1.7.1, Telegram adapter separate from Identity. Identity owns Telegram mappings, link-code issuance/redemption and lookup of an immutable `TelegramActor` DTO.
- Telegram and eventual Livewire use Application actions directly; an HTTP API is optional, not a dependency.
- **Provider direction:** Remnawave is the chosen initial future provisioning integration (F10), replacing the prior Marzban proposal. No provider SDK, Remnawave API connection, nodes or networking code exists yet.

## F5 implementation
- Role and permission tables from Spatie, with a migration mapping existing `admin`/`member` users into DB roles before removing the old column.
- Custom roles (`reseller`, etc.) defined via a trusted Super Admin application action; multiple roles may be assigned per user.
- Only trusted, active, Telegram-linked, allowlisted `super-admin` accounts receive special Gate privileges. At least two different numeric IDs are required in `SUPER_ADMIN_TELEGRAM_IDS`; env IDs alone do not grant any role.
- Operator-only `identity:super-admin:promote {user}` requires existing private Telegram binding, allowlist membership and explicit terminal confirmation. Neither ordinary admin routes nor role creation APIs can grant the protected role.
- Ordinary administrators may manage member accounts but cannot elevate privileges, edit peer admins or modify protected roots. All role/status updates record audit events. Root role revocation/recovery is deliberately CLI/manual-only, not via public HTTP.

## F4 implemented (merged)
- Nutgram routing for /start, /menu, /account, /admin, /link {code} and inline menu callbacks.
- Private-chat + Telegram numeric user-ID checks on every bot handler. Unknown/disabled accounts have no privileged menu. F06-A introduced a navigation shell; F06-B replaced the users preview with real read/search/enable/disable workflows.
- Operator-only `telegram:link-account {user_id}` issues a random, one-time 10-minute code stored as SHA-256 hash. Redemption refuses reassignment or expired/replayed codes; no public self-signup.
- `POST /api/telegram/webhook` validates a configured Telegram Secret Token before processing; no web session/CSRF exception.
- Update IDs stored with processing leases and completion markers; concurrent duplicate updates request retry (503); failed updates can be retried. Outbound Telegram messaging is not transactional: *exactly-once side effects cannot be guaranteed*.
- `telegram:webhook:set` registers secret via Bot API; `telegram:prune-updates` cleans 7-day completed receipts using scheduler.

## F06-A implementation
- Shared Telegram MenuRenderer: Home, Account (real current roles and status), Settings (informational), Administration and preview-only Users/Roles pages with Back/Home inline navigation.
- Menu commands and callbacks resolve the current private linked Identity actor for every interaction; role-management preview is reserved for trusted Super Admins. Nothing about stale inline keyboards is trusted.
- No migrations or new vendor packages; F06-B user operations and F06-C role management are now complete as separate increments.

## F06-B completed (PR #8)
- Identity read actions `BrowseUsers` and immutable DTOs serve role-guarded, paginated/searchable user listings and profiles for Telegram (no direct model access in the adapter).
- Telegram supports `/users`, `/finduser <term>`, page/detail inline callbacks, 10-minute search context and Back navigation.
- Status changes use a 3-minute, unpredictable, one-time, operator-bound cache confirmation; `ChangeUserStatus` delegates to existing `UpdateUserAccess` and re-checks authorization, expected status and protected root/self policies under a DB transaction. Changes append Identity Audit events.
- Search and user details expose data only to actors with current `users.manage`; revoked or inactive users and stale callbacks are denied. No new migrations/packages.

## F06-C completed (PR #10)
- Trusted Super Admins can browse/view roles and available code-defined permissions, create custom DB roles via `/newrole <slug>`, toggle allowed custom role permissions, and add/remove user roles through inline keyboards.
- Identity Application Actions `ManageRoles`, `ToggleRolePermission` and `ToggleMemberRole` own reads, authorization, stale-state rejection and audit logging. Telegram UI receives immutable DTOs rather than DB models.
- All RBAC writes require a three-minute, single-use, per-operator confirmation in lock-capable Laravel Cache, with authorization checked again at mutation time.
- Built-in `member`, `admin`, `super-admin` role definitions cannot be modified via Telegram, and `super-admin` cannot be assigned or removed there. Operator-bound allowlist is checked for each callback. No new migrations or dependencies.

## F07 implementation (PR #12)
- Explicit private-chat opt-in join via `onboarding:join`, no account created merely from `/start`. Existing operator `/link` workflow is preserved. Public signup is off by default; set `TELEGRAM_PUBLIC_SIGNUP_ENABLED=true` only after operator bootstrap.
- Identity `RegisterTelegramMember` creates a **member-only** active account with a random non-routable placeholder email, unknown random web password, no verified email claim, and a unique Telegram binding; repeated updates are idempotent.
- Referral module issues stable cryptographically random per-user codes, renders `t.me/<bot>?start=ref_<code>` via `/invite`, and counts verified completed signups; a pending code in short-lived cache is applied only during the same DB transaction as a brand-new account. Existing accounts can never be reattributed; no rewards/commission logic.
- Orchestration in `Onboarding/Application/JoinFromTelegram` composes Identity and Referral Actions, while the Telegram adapter only holds temporary pending context and menus. No networking or money operations.

## F08 completed in code (PR #13)
- Billing is a small isolated module with reseller wallet account per Identity user, manual bank-transfer top-up requests and one trusted-Super-Admin review workflow. All monetary values are positive integer rials internally, displayed as toman in Telegram. No direct payout, transfer or purchase features.
- An append-only-by-application two-posting journal is the source of wallet balance: a verified incoming transfer debits `cash_clearing` and credits `wallet_liability` atomically; there is no `users.balance` field. Independent bank verification is the operator's responsibility.
- A submitted payment reference never increases credit until a trusted reviewer confirms it. DB-unique verified bank references and per-topup journal idempotency keys prevent multiple topups crediting the same reported transfer. Rejection creates no postings.
- Temporary 3-minute, one-use, root-bound Telegram confirmation tokens gate reviewer actions. Billing Application checks fresh reseller/root roles and active status; Telegram IDs are not financial actor IDs. `BILLING_TOPUPS_ENABLED` is off by default.
- Ledger balance, statement, claims, review and audit snapshots are reusable by future Sales/Livewire; no direct Marzban/SSH or shopping logic.

## F09-A Product Catalog & Pricing (CI pending)
- `Sales/Application` owns operator-configured `sales_products` and `sales_product_rates`, immutable DTOs (`CatalogProduct`, `ProductRate`, `PriceQuote`) and read/quote Actions for any active customer. A product may limit volumes within the global 1–100 GB window; initial supported durations: 15, 30, 60 and 90 days.
- Prices use one public rate per product/duration (`base_price_rials + volume_gb × per_gb_rials`) and a role-derived reseller discount of 5% (also allows 0% via code config); all calculations in integer rials with documented half-up discount rounding. No separate reseller price catalog.
- Rates are **not seeded** with fictional values. Terminal-only `sales:product:set` and `sales:rate:set` commands let an operator configure the product and tariffs. Catalog and quote reject inactive users, disabled products/rates, illegal volumes/durations and invalid prices.
- F09-A is backend-only. No Telegram purchase menu, order row, wallet debit or Remnawave call exists; F09-B will consume quotes to display entry-menu prices, F09-C will persist an immutable order-price snapshot.

## Not implemented
Full administration workflows (user creation), customer business operations, web self-registration, password reset, Livewire, Mini App, public REST API, server actions, SSH, network topology, long-running queues. Basic bot updates are processed synchronously; future heavy operations must be dispatched to durable jobs.

## Validation
**F09-A:** Await CI results. There is no production catalog/bot/payment E2E in this increment.

**F08 passed:** GitHub Actions run **38102895313** on PHP 8.4 and 8.5: **106 Pest tests / 494 assertions**, Larastan 0 errors, Pint clean. Tests do **not** verify real money, actual bank transfers or BotFather E2E.

**F07 passed:** GitHub Actions run **38101861799** on PHP 8.4 and 8.5: **95 Pest tests / 426 assertions**, Larastan 0 errors and Pint clean. These are fake-transport/unit/feature tests; BotFather/private-chat E2E is **not** yet verified.

**F06-C passed:** GitHub Actions run **38099720840** (PHP 8.4/8.5): **83 Pest tests / 359 assertions**, Larastan 0 errors, Pint clean. This verifies Nutgram fake transport and Identity actions, not production BotFather deployment.

**F06-B passed:** GitHub Actions run **38098764186** on PHP 8.4 and 8.5: **69 Pest tests / 295 assertions**, Larastan 0 errors, Pint clean. This covers Nutgram fake transport, not an actual deployed Telegram bot.

**F06-A passed:** GitHub Actions run **38098139458** on PHP 8.4 / 8.5: 58 Pest tests, 228 assertions, Larastan and Pint green. These checks exercise Nutgram's fake Telegram transport; they do not prove that the bot has been deployed to BotFather.

**F5 passed:** GitHub Actions run **38096797661** on PHP 8.4 and 8.5: **52 Pest tests / 209 assertions**, Larastan 0 errors, Pint clean. Portable Composer lock includes Spatie v8.3.0. No BotFather/production integration was exercised.

**Previous F4 passed:** GitHub Actions run **38095212533** on PHP 8.4 and 8.5: **40 tests / 158 assertions**, Larastan **0 errors**, Pint **0 issues**. Composer lock is portable and includes Nutgram Laravel 1.7.1 / Nutgram 4.50.3. This validates repository code, **not** an actual deployed Telegram webhook or BotFather credentials.

## Next
1. Configure BotFather token, separate random webhook secret, public HTTPS URL, and operator-only account link in the deployment environment (do not commit any secrets).
2. Deploy F07/F08 migrations and finish BotFather real E2E. Verify and sign off a manual bank check workflow before enabling top-up requests in production.
3. Confirm real tariff figures, configure the product/rates via Artisan, and validate the quote use cases in a test environment; F09-B can then build the Telegram duration/volume menu and F09-C orders.
3. Add Livewire only when a web interface is required.

See [Architecture](002-ARCHITECTURE.md), [Modules](003-MODULES.md), [Telegram operations](006-TELEGRAM-OPERATIONS.md), [Roadmap](005-ROADMAP.md) and [ADR 0004](adr/0004-telegram-first.md).
