# SkyNet

SkyNet is a new Laravel-based platform being developed as a **modular monolith**. It is a clean successor to the Python NVL project, not a translation of the existing bot.

> **Status:** Foundation F1 and F2 implemented (PRs #1 and #2); feature and network design remain deferred. No customer-facing feature, UI, network integration, or authentication flow has been implemented.

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
- Initial module shells: `Identity` and `Administration`, **without business behavior**.
- No network architecture or technology has been chosen. WireGuard, Outline, Core/Edge, tunnels, and provisioning are out of scope.

Start with [docs/000-CONTEXT.md](docs/000-CONTEXT.md), follow [AGENTS.md](AGENTS.md), and keep [docs/005-ROADMAP.md](docs/005-ROADMAP.md) current.

## Contributing

Make changes on a branch, include tests, update relevant docs in the **same PR**, and merge only when required checks are green. Never commit credentials or environment-specific Composer mirror URLs into `composer.lock`.
