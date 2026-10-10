# SkyNet — Current Context

**Reviewed:** 2026-10-11  
**Stage:** F4 Telegram Foundation — code proposed on `feature/04-telegram-foundation`, CI / merge pending  
**Behavioral truth:** repository code and tests, not aspirational plans.

## Product direction
SkyNet is a Laravel successor to Python NVL. **Telegram-first** is the accepted first UI channel. Later Livewire/API presenters call shared Application actions without duplicating business logic.

## Accepted architecture
- Laravel 13, PHP >=8.4.1, modular monolith, Pest 5, Larastan 3, Pint; CI tests PHP 8.4 / 8.5.
- Domain/Application/Infrastructure live in modules. Transport adapters live under `app/Interfaces/`.
- F3 Identity: session auth, `admin/member`, `is_active`, guarded user operations, first-admin Artisan bootstrap.
- F4: `nutgram/laravel` ^1.7.1, Telegram adapter separate from Identity. Identity owns Telegram mappings, link-code issuance/redemption and lookup of an immutable `TelegramActor` DTO.
- Telegram and eventual Livewire use Application actions directly; an HTTP API is optional, not a dependency.
- **No networking decision**: no server SSH, WireGuard, Outline, VPN/node/provisioning, agent, Core/Edge.

## F4 implemented on this branch
- Nutgram routing for /start, /menu, /account, /admin, /link {code} and inline menu callbacks.
- Private-chat + Telegram numeric user-ID checks on every bot handler. Unknown/disabled accounts have no privileged menu. Admin menu is a placeholder, not user-management functionality.
- Operator-only `telegram:link-account {user_id}` issues a random, one-time 10-minute code stored as SHA-256 hash. Redemption refuses reassignment or expired/replayed codes; no public self-signup.
- `POST /api/telegram/webhook` validates a configured Telegram Secret Token before processing; no web session/CSRF exception.
- Update IDs stored with processing leases and completion markers; concurrent duplicate updates request retry (503); failed updates can be retried. Outbound Telegram messaging is not transactional: *exactly-once side effects cannot be guaranteed*.
- `telegram:webhook:set` registers secret via Bot API; `telegram:prune-updates` cleans 7-day completed receipts using scheduler.

## Not implemented
Actual admin/consumer business operations, user self-registration, password reset, Livewire, Mini App, public REST API, server actions, SSH, network topology, long-running queues. Basic bot updates are processed synchronously; future heavy operations must be dispatched to durable jobs.

## Validation
**Pending:** GitHub Actions must run with Composer-resolved portable `composer.lock`, then Pest/Larastan/Pint on PHP 8.4/8.5. Do not call F4 complete before checks.

## Next
1. Run CI, resolve failures, merge F4 when green.
2. Configure BotFather token, separate random webhook secret, public HTTPS URL, and operator-only account link in the deployment environment (do not commit any secrets).
3. Implement real bot user/admin use cases via Identity/other domain Actions in later increments.
4. Add Livewire only when a web interface is required.

See [Architecture](002-ARCHITECTURE.md), [Modules](003-MODULES.md), [Telegram operations](006-TELEGRAM-OPERATIONS.md), [Roadmap](005-ROADMAP.md) and [ADR 0004](adr/0004-telegram-first.md).
