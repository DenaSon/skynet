# SkyNet

SkyNet is a new Laravel-based platform being developed as a **modular monolith**. It is a clean successor to the Python NVL project, not a translation of the existing bot.

> **Current checkpoint (2026-10-12):** F0–F10-D **merged on `master`**: Identity/RBAC, Telegram onboarding/referrals, reseller manual wallets, Sales catalog + `pending_payment` orders, and internal Remnawave transport/Nodes/Users/subscription lifecycle. [Master CI 38213468652](https://github.com/DenaSon/skynet/actions/runs/38213468652): PHP 8.4/8.5 green, 200 tests / 959 assertions. **Not live-E2E validated; no paid-order transition, auto-activation or customer subscription UI.** Next: [Issue #24 (F10-E)](https://github.com/DenaSon/skynet/issues/24).

## Current stack

- Laravel 13 and **PHP 8.4.1+** (minimum explicitly aligned with the committed lock file).
- Pest 5 + the Laravel plugin, including lightweight architecture tests.
- Larastan 3 / PHPStan (level 5), Laravel Pint, PHPUnit as a transitive Pest dependency.
- SQLite with database-backed sessions, cache and queue in the default local skeleton. Production MySQL/Redis configuration is **deferred**.
- Existing CI tests PHP 8.4 and 8.5, runs static analysis and checks Pint formatting.

## Quick start

Prerequisites: PHP **8.4.1+**, Composer 2, Node.js and npm.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan test
```

PowerShell: `Copy-Item .env.example .env` may be used instead of `cp`. Configure the local database before running migrations. Start locally using `php artisan serve` and `npm run dev`.

## Quality checks

```bash
php artisan test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test

# Equivalent aggregate (requires dev dependencies installed)
composer quality
```

The repository checks these on pull requests. Architecture tests currently enforce only **real, narrow** rules between provisional modules; revise tests as module contracts are deliberately defined.

## Architecture

- One Laravel application, one deployable, one primary datastore.
- Explicit module service providers under `app/Modules`; registration in `bootstrap/providers.php`.
- `Identity` implements session login/logout, an account endpoint, restricted admin user management and admin bootstrapping. `Administration` remains an empty future UI module.
- **Remnawave** is selected for the external subscription panel API (OpenAPI v3.4.4). Internal provider operation catalog, discovery, node and user wrappers, and SQL-reserved lifecycle are implemented; user-facing provider actions are **not** wired. Independent WireGuard/SSH/Core/Edge/tunnel/network transport design remains a separate decision.

Start with [docs/000-CONTEXT.md](docs/000-CONTEXT.md), follow [AGENTS.md](AGENTS.md), and keep [docs/005-ROADMAP.md](docs/005-ROADMAP.md) current.

## Contributing

Make changes on a branch, include tests, update relevant docs in the **same PR**, and merge only when required checks are green. Never commit credentials or environment-specific Composer mirror URLs into `composer.lock`.

## Identity foundation (F3)

After `php artisan migrate`, create the **first** administrator by running `php artisan identity:bootstrap-admin` interactively. Password input is hidden and must have at least 12 characters. No admin is seeded and there is no public signup.

Open `/login` to sign in. `/account` returns the authenticated user summary. Session-authenticated administrators can use JSON endpoints `GET/POST /admin/users` and `PATCH /admin/users/{user}`. The endpoints use Laravel web sessions and CSRF tokens—not API bearer tokens. A future web administration UI is deferred. Account deactivation invalidates the current session on the next authenticated request; other sessions are not proactively listed or revoked. See [Identity documentation](docs/003-MODULES.md).

## Telegram-first interface (F4)

SkyNet's first interactive channel is **Telegram**, not Livewire. Install with `composer install`, migrate with `php artisan migrate`, and configure `TELEGRAM_TOKEN` + a distinct random `TELEGRAM_WEBHOOK_SECRET` in your local/deployment `.env`. Never commit the values. Set a public HTTPS `APP_URL` and run `php artisan telegram:webhook:set` for production. For local bot experiments, Nutgram's `nutgram:run` supports polling, but do not poll and run the production webhook simultaneously.

After bootstrapping the first administrator, run `php artisan telegram:link-account 1` (replace 1 with the real user ID), then send the printed single-use `/link <code>` in a **private** chat with the bot. No public registrations or default Telegram admins are created. Commands: `/start`, `/menu`, `/account`, `/settings`, `/admin`; buttons use inline callbacks. F04 established secure transport and linking; F06-B now adds guarded user directory, search and confirmed activation/deactivation. Role edits are implemented in F06-C for trusted Super Admins with one-time confirmation.

Read [Telegram setup and operations](docs/006-TELEGRAM-OPERATIONS.md). The existing Laravel web login remains for backwards compatibility; new user-facing features target Telegram until a future Livewire presenter is approved.

## Dynamic RBAC and protected Super Admins (F5, CI verified)
Identity roles are DB-backed via Spatie Permission v8. `admin` and `member` records are migrated from the former enum column; new role names such as `reseller` need no PHP enum edit. One user may have several roles. **Super Admin is a special protected root**: configure at least two distinct numeric IDs in `SUPER_ADMIN_TELEGRAM_IDS` and enroll each linked active user via `php artisan identity:super-admin:promote <user_id>`. ENV alone never grants access. Ordinary admin web endpoints cannot promote, demote or disable a Super Admin; all RBAC mutations are subject to server-side authorization and recorded in an identity audit table. See [RBAC operations](docs/007-RBAC-OPERATIONS.md).

## Telegram navigation shell (F06-A)
The first Telegram UI is deliberately read-only: linked members see Home → Account / Settings; authorized administrators see the Administration menu, whose Users and Roles pages are labeled as upcoming F06-B/F06-C work. Role management navigation is available only to trusted Super Admins. Every command and callback resolves the **current** linked active account and privileges, so revoked access or an old keyboard cannot bypass authorization. The Settings page does not persist settings; Referral remains deferred. No new tables, packages or business actions are required. See [Telegram operations](docs/006-TELEGRAM-OPERATIONS.md).

## Telegram user management (F06-B)
Linked administrators with current `users.manage` permission can open `/users` or the Users admin button to browse accounts (six per page), view profiles, search via `/finduser <term>`, and confirm enable/disable changes. The interface is a thin presenter calling the **Identity Application layer**, preserving the same RBAC policies and audit events as the existing web endpoints. Status confirmation uses one-time, three-minute, cache-backed operator-bound tokens; configure a persistent, lock-capable Laravel cache backend (the default database cache works). Super Admin accounts and self-deactivation remain protected. Role edits, new accounts and referral onboarding are not part of F06-B. See [Telegram operations](docs/006-TELEGRAM-OPERATIONS.md).

## F06-C — Telegram RBAC management
Trusted Super Admins can now use `/roles`, `/newrole <slug>`, and inline role/permission management. The Users profile menu exposes a root-only role assignment screen; multiple roles can be added or removed. Each mutation requires an actor-bound three-minute one-time confirmation and calls Identity Application Actions with fresh RBAC checks and audit logging. System roles are protected from permission edits; the `super-admin` role can never be granted or revoked by the Telegram UI. No new vendor dependencies or migrations. See `docs/006-TELEGRAM-OPERATIONS.md`.

## F07 — Telegram Onboarding & Referral
Public Telegram signups are deployment-opt-in: `TELEGRAM_PUBLIC_SIGNUP_ENABLED=false` by default. Configure the exact `TELEGRAM_BOT_USERNAME` and enable public signup **only after the two trusted Super Admin accounts are linked**. `/start` or `/start ref_<code>` merely offers an explicit join button; `/link <code>` still links existing operator-created identities. A new member gets a unique Telegram binding, no usable web credentials or verified email, and exactly one initial optional referrer. Linked active accounts can use `/invite` to get their invitation URL and confirmed count. No sales, wallet or rewards are added in this phase. See [F07 runbook](docs/006-TELEGRAM-OPERATIONS.md).

## F08 — Reseller wallets and manual credit
An account with the explicitly assigned `reseller` role can use `/wallet`, `/topup <amount_toman>` and `/paid <request_id> <bank_reference>` in a **private Telegram chat**. A self-reported bank reference does not credit the wallet. An active trusted Super Admin independently verifies actual receipt, then reviews via `/topups`; approval creates an atomic two-posting, integer-rial ledger journal and one uniquely verified bank reference. Other admins are denied. Balances derive only from liability postings; there are no withdrawals, transfers, service purchases or Marzban integration.

Set `BILLING_TOPUPS_ENABLED=true` only after migration, real BotFather and bank verification E2E. Payment instructions are configured privately in `BILLING_PAYMENT_INSTRUCTIONS`; never commit account/bank secrets. See `docs/006-TELEGRAM-OPERATIONS.md`.

## F09-A — Sales Catalog & Pricing
This increment provides **read-only** `ListProducts` and `CalculateQuote` Application Actions (not Telegram menus/orders yet). A product supports volumes from 1 to 100 GB and duration rates for 15, 30, 60 and 90 days. The same public base + per-GB tariff applies to everybody; active `reseller` users receive 5% off in integer-rial arithmetic (the code setting may be reduced to 0%). No guessed real prices are seeded. Terminal-only Artisan commands configure products and tariffs. Example setup and E2E checks are documented in [Sales catalog operations](docs/008-SALES-CATALOG-OPERATIONS.md). F09-B now surfaces these quotes from the initial Telegram menu. F09-C orders and F10-A/B/C/D provider adapters were subsequently implemented; F11 payment settlement and live provider activation remain deferred.

## F09-B — Telegram price preview
`/buy` (or **🛒 خرید اشتراک** on the Home screen) shows current duration options with **prices from the first buying page**. Select a duration, choose a preset volume or tap **✏️ حجم دلخواه** and send an integer GB amount (English/Persian/Arabic numerals). A temporary 10-minute per-account selection limits free-text volume input. The preview recalculates the real public rate and any 5% reseller discount with no purchase/order/payment/provisioning. Catalog prices must be configured first using `sales:product:set` and `sales:rate:set`. See [operations](docs/008-SALES-CATALOG-OPERATIONS.md).

## F09-C — Pending-payment orders
After a price preview, **✅ ثبت سفارش** atomically creates one buyer-owned `pending_payment` order **only if the price and role still match current server catalog data**. The ephemeral 10-minute buyer-bound checkout reference and unique DB key make retries idempotent. Home **📋 سفارش‌های من** and `/orders` list the account's last ten orders; individual order details retain original price even if the operator updates tariffs later. No payment, wallet debit, balance reservation or Remnawave provisioning occurs in F09-C.

**F09 completion:** B and C are tested together in PR #16: 135 passing Pest tests / 660 assertions on PHP 8.4 and 8.5 with Larastan and Pint green. This is code/fake transport verification, **not** real Telegram payment or Remnawave activation.

## F10 — Remnawave progress and next starting point

- **Merged:** [#19](https://github.com/DenaSon/skynet/pull/19) 218-operation v3.4.4 transport map; [#20](https://github.com/DenaSon/skynet/pull/20) read-only inventory/inspect CLI; [#21](https://github.com/DenaSon/skynet/pull/21) Nodes API + telemetry; [#23](https://github.com/DenaSon/skynet/pull/23) Users/Subscriptions adapters and durable local lifecycle with read-only uncertain-write reconciliation. The lifecycle checks remote user/expected **Internal Squad membership**; it is not an Identity/Sales authorization layer.
- **Safety:** `REMNAWAVE_WRITES_ENABLED=false` is the default. No Telegram or checkout handler invokes provider writes. Links are bearer-like credentials; never place them in normal summaries, logs or public callback data. **No real Remnawave panel E2E has occurred.**
- **Next:** [Issue #24 — F10-E](https://github.com/DenaSon/skynet/issues/24): F10-E1 order-to-subscription ownership, F10-E2 reusable authenticated read Actions, F10-E3 thin Telegram presenter. Future Livewire 4/Mary UI will reuse these Actions.
- **After F10-E:** F11 needs independently verified order settlement, reseller wallet debits, durable outbox and reconciled provider delivery **before** any paid subscription automation or staging sign-off.

**Developer handoff:** Start at [docs/000-CONTEXT.md](docs/000-CONTEXT.md) (current project memory), then [AGENTS.md](AGENTS.md), [architecture](docs/002-ARCHITECTURE.md), [modules](docs/003-MODULES.md), [roadmap](docs/005-ROADMAP.md), and [Remnawave F10-D operations](docs/012-REMNAWAVE-USER-LIFECYCLE.md). Previous sections on this README preserve the chronological scope of their original phases; this status and Context describe **current** capabilities.

## Deployment note: MySQL/MariaDB storage engine and partial migrations

SkyNetwork requires **transactional InnoDB** tables for foreign keys, auditability and the future Billing settlement path. The MySQL and MariaDB connections pin `engine => InnoDB` in `config/database.php`, regardless of a server whose default table engine is MyISAM/Aria. A 255-character UTF-8 `users.email` unique index can exceed MyISAM's 1000-byte limit (error 1071). **Do not bypass this by switching to MyISAM, turning off foreign keys or reducing the database charset.**

Read-only diagnosis from the Laravel container:

```bash
php artisan tinker --execute='dump(DB::select("SELECT VERSION() AS version, @@default_storage_engine AS default_engine")); dump(DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME"));'
php artisan migrate:status
```

A failed first migration may have committed a partial `users` table without recording the migration. On a **confirmed disposable empty database only**, remove only that confirmed empty partial table or provision a fresh empty DB, then redeploy and run `php artisan config:clear && php artisan config:cache && php artisan migrate --force`. For databases with any important data, take a verified backup and investigate/migrate in place instead of dropping anything. Re-check created table engines: they must all be InnoDB. **Changing this application setting does not alter existing table engines or the DB server default**, so configure the database service separately if other applications need InnoDB. The app runs in ephemeral containers; never rely on changes made only inside one pod.
