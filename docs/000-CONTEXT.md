# SkyNet — Current Context

**Reviewed:** 2026-10-11  
**Stage:** F06-B Telegram user management implemented in `feature/06b-telegram-user-management` (CI/merge pending)  
**Behavioral truth:** repository code and tests, not aspirational plans.

## Product direction
SkyNet is a Laravel successor to Python NVL. **Telegram-first** is the accepted first UI channel. Later Livewire/API presenters call shared Application actions without duplicating business logic.

## Accepted architecture
- Laravel 13, PHP >=8.4.1, modular monolith, Pest 5, Larastan 3, Pint; CI tests PHP 8.4 / 8.5.
- Domain/Application/Infrastructure live in modules. Transport adapters live under `app/Interfaces/`.
- F3 Identity established session auth and the original `admin/member` enum. F5 replaces that single enum/column with Spatie Permission v8 role/permission records, while retaining session guards and user status.
- F4: `nutgram/laravel` ^1.7.1, Telegram adapter separate from Identity. Identity owns Telegram mappings, link-code issuance/redemption and lookup of an immutable `TelegramActor` DTO.
- Telegram and eventual Livewire use Application actions directly; an HTTP API is optional, not a dependency.
- **No networking decision**: no server SSH, WireGuard, Outline, VPN/node/provisioning, agent, Core/Edge.

## F5 implementation
- Role and permission tables from Spatie, with a migration mapping existing `admin`/`member` users into DB roles before removing the old column.
- Custom roles (`reseller`, etc.) defined via a trusted Super Admin application action; multiple roles may be assigned per user.
- Only trusted, active, Telegram-linked, allowlisted `super-admin` accounts receive special Gate privileges. At least two different numeric IDs are required in `SUPER_ADMIN_TELEGRAM_IDS`; env IDs alone do not grant any role.
- Operator-only `identity:super-admin:promote {user}` requires existing private Telegram binding, allowlist membership and explicit terminal confirmation. Neither ordinary admin routes nor role creation APIs can grant the protected role.
- Ordinary administrators may manage member accounts but cannot elevate privileges, edit peer admins or modify protected roots. All role/status updates record audit events. Root role revocation/recovery is deliberately CLI/manual-only, not via public HTTP.

## F4 implemented (merged)
- Nutgram routing for /start, /menu, /account, /admin, /link {code} and inline menu callbacks.
- Private-chat + Telegram numeric user-ID checks on every bot handler. Unknown/disabled accounts have no privileged menu. Admin menu is a placeholder, not user-management functionality.
- Operator-only `telegram:link-account {user_id}` issues a random, one-time 10-minute code stored as SHA-256 hash. Redemption refuses reassignment or expired/replayed codes; no public self-signup.
- `POST /api/telegram/webhook` validates a configured Telegram Secret Token before processing; no web session/CSRF exception.
- Update IDs stored with processing leases and completion markers; concurrent duplicate updates request retry (503); failed updates can be retried. Outbound Telegram messaging is not transactional: *exactly-once side effects cannot be guaranteed*.
- `telegram:webhook:set` registers secret via Bot API; `telegram:prune-updates` cleans 7-day completed receipts using scheduler.

## F06-A implementation
- Shared Telegram MenuRenderer: Home, Account (real current roles and status), Settings (informational), Administration and preview-only Users/Roles pages with Back/Home inline navigation.
- Menu commands and callbacks resolve the current private linked Identity actor for every interaction; role-management preview is reserved for trusted Super Admins. Nothing about stale inline keyboards is trusted.
- No migrations or new vendor packages; F06-B (user operations) and F06-C (role management) remain separate increments.

## F06-B implementation (pending CI)
- Identity read actions `BrowseUsers` and immutable DTOs serve role-guarded, paginated/searchable user listings and profiles for Telegram (no direct model access in the adapter).
- Telegram supports `/users`, `/finduser <term>`, page/detail inline callbacks, 10-minute search context and Back navigation.
- Status changes use a 3-minute, unpredictable, one-time, operator-bound cache confirmation; `ChangeUserStatus` delegates to existing `UpdateUserAccess` and re-checks authorization, expected status and protected root/self policies under a DB transaction. Changes append Identity Audit events.
- Search and user details expose data only to actors with current `users.manage`; revoked or inactive users and stale callbacks are denied. No new migrations/packages.

## Not implemented
Actual admin/consumer business operations, user self-registration, password reset, Livewire, Mini App, public REST API, server actions, SSH, network topology, long-running queues. Basic bot updates are processed synchronously; future heavy operations must be dispatched to durable jobs.

## Validation
**F06-A passed:** GitHub Actions run **38098139458** on PHP 8.4 / 8.5: 58 Pest tests, 228 assertions, Larastan and Pint green. These checks exercise Nutgram's fake Telegram transport; they do not prove that the bot has been deployed to BotFather.

**F5 passed:** GitHub Actions run **38096797661** on PHP 8.4 and 8.5: **52 Pest tests / 209 assertions**, Larastan 0 errors, Pint clean. Portable Composer lock includes Spatie v8.3.0. No BotFather/production integration was exercised.

**Previous F4 passed:** GitHub Actions run **38095212533** on PHP 8.4 and 8.5: **40 tests / 158 assertions**, Larastan **0 errors**, Pint **0 issues**. Composer lock is portable and includes Nutgram Laravel 1.7.1 / Nutgram 4.50.3. This validates repository code, **not** an actual deployed Telegram webhook or BotFather credentials.

## Next
1. Configure BotFather token, separate random webhook secret, public HTTPS URL, and operator-only account link in the deployment environment (do not commit any secrets).
2. Once F06-B CI passes and merges, implement F06-C role and permission management through Identity Actions.
3. Add Livewire only when a web interface is required.

See [Architecture](002-ARCHITECTURE.md), [Modules](003-MODULES.md), [Telegram operations](006-TELEGRAM-OPERATIONS.md), [Roadmap](005-ROADMAP.md) and [ADR 0004](adr/0004-telegram-first.md).
