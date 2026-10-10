# Module Boundaries

**Current:** Identity F3 merged; F4 Telegram presenter introduced separately; Administration remains an empty registered shell.

| Module | Ownership | Implemented |
| --- | --- | --- |
| Identity | User persistence, session authentication, role/active-state authorization, admin user-management use cases and safe bootstrap | Identity model, actions, web controllers, form requests, middleware, console command, routes and tests |
| Administration | Future administrative interface/composition; no identity internals | Empty provider only |

## Identity layout
```text
app/Modules/Identity/
  Domain/UserRole.php
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
Livewire/Mary UI presentation, full administration panel, granular permissions, additional roles, password reset / email verification / 2FA, and networking.

## Telegram interface, not a business module (F4)
`app/Interfaces/Telegram/` contains webhook controller, Nutgram handlers, inline-menu renderer, retry receipts, and operator commands. It only imports `App\Modules\Identity\Application\*` for account linking and actor resolution. Transport data does not become a domain model. `routes/telegram.php` registers Nutgram handlers.

Within Identity, `Application/{IssueTelegramLinkCode,LinkTelegramAccount,ResolveTelegramActor,TelegramActor}` is the public use-case boundary. `Infrastructure/Models/{TelegramAccount,TelegramLinkToken}` and the new migration implement identity persistence. The link code is never stored raw. Telegram numeric IDs are immutable join keys; usernames are not authoritative. First binding requires an operator-generated one-time code.

See [Telegram operations](006-TELEGRAM-OPERATIONS.md) for setup and [ADR 0004](adr/0004-telegram-first.md) for scope.
