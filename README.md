# SkyNet

A new Laravel-based platform foundation, being designed as a **modular monolith**. SkyNet is a clean implementation, not a line-by-line port of the existing Python NVL project.

> **Status:** Foundation in progress. No customer-facing features, network integrations, or administration UI have been implemented yet.

## Currently in the repository

- Laravel 13 skeleton. Laravel's declared PHP requirement is 8.3+, but **the current committed lock file requires PHP 8.4.1+** because it selects Symfony 8 packages.
- Explicit module service-provider registration for `Identity` and `Administration` (empty boundaries; no feature behavior).
- Architecture/context documentation in [docs/](docs/).
- PHPUnit smoke tests and the Laravel skeleton GitHub Actions test workflow.

**Not yet installed or implemented:** Livewire, Mary UI, Pest, Larastan, MySQL/Redis deployment configuration, authentication screens, billing, and network service adapters. The Laravel skeleton currently uses SQLite/database-backed local defaults.

## Local setup

Prerequisites: **PHP 8.4.1+ for the current lock file**, Composer, and Node.js/npm. PHP 8.3 support requires a future dependency re-lock; do not run `composer install --ignore-platform-reqs` to bypass this.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan test
```

For PowerShell, use `Copy-Item .env.example .env` instead of `cp` if preferred. Configure a database for your own environment before running migrations. To serve the app use `php artisan serve` and, while developing assets, `npm run dev`.

## Architecture

- One Laravel application, one primary database and one deployment unit.
- Modules live under `app/Modules/`; providers are explicitly listed in `bootstrap/providers.php`.
- No decision has been made on network technologies or deployment topology.
- Code, tests, and documentation change together in reviewed pull requests.

Read [docs/000-CONTEXT.md](docs/000-CONTEXT.md) first and follow [AGENTS.md](AGENTS.md).

## Development workflow

Create a feature branch from `master`, implement a small tested change, update the relevant `docs/*.md` files, and open a pull request. See [docs/005-ROADMAP.md](docs/005-ROADMAP.md) for the next increment.
