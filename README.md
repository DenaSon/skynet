# SkyNet

SkyNet is a new Laravel-based platform being developed as a **modular monolith**. It is a clean successor to the Python NVL project, not a translation of the existing bot.

> **Status:** Foundation F1 and F2 merged; F3 Identity is merged. F4 Telegram-first foundation is implemented and CI verified (PR #4); all networking remains deferred. Telegram navigation and Identity authentication exist; business operations, web UI and networking remain deferred.

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
- No network architecture or technology has been chosen. WireGuard, Outline, Core/Edge, tunnels, and provisioning are out of scope.

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
