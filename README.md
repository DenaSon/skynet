# SkyNet

SkyNet is a new Laravel-based platform being developed as a **modular monolith**. It is a clean successor to the Python NVL project, not a translation of the existing bot.

> **Status:** Foundation F1 and F2 merged; F3 Identity is implemented in its feature branch (CI review pending); all networking remains deferred. No customer-facing feature, UI, network integration, or authentication flow has been implemented.

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

Open `/login` to sign in. `/account` returns the authenticated user summary. Session-authenticated administrators can use JSON endpoints `GET/POST /admin/users` and `PATCH /admin/users/{user}`. The endpoints use Laravel web sessions and CSRF tokens—not API bearer tokens. The admin UI comes in F4. Account deactivation invalidates the current session on the next authenticated request; other sessions are not proactively listed or revoked. See [Identity documentation](docs/003-MODULES.md).
