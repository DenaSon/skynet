# SkyNet — Agent Instructions

SkyNet is a Laravel 13 application being developed as a **modular monolith**.

## Current tooling
- PHP 8.4.1+, Laravel 13, Pest 5, Larastan 3, Laravel Pint.
- Check with `php artisan test`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test` (or `composer quality`).
- Never commit local Composer mirror URLs to `composer.lock`; lock files must install on GitHub-hosted CI.

## Start here
1. Read `docs/000-CONTEXT.md` before changing code.
2. Read the relevant architecture/module/roadmap documents.
3. Inspect the actual code; documentation never overrides observed behavior.
4. Make the smallest change that solves the current task, and add or update tests.
5. Update at least one relevant document in the **same PR** as any meaningful development change.

## Fixed boundaries
- One Laravel application, one deployment unit, and one primary database.
- Modules are organized under `app/Modules/<Module>/`, registered explicitly in `bootstrap/providers.php`.
- Keep domain/application logic independent of HTTP, Livewire, and Telegram interfaces.
- Avoid unnecessary repositories, interfaces, events, or package dependencies.
- No network/control-plane technology has been selected. **Do not** introduce WireGuard, Outline, Core/Edge, SSH tunnel, proxy, agent, or provisioning abstractions without a separate decision.
- Do not copy the existing Python NVL bot architecture into this foundation.

## Before declaring a task complete
- Run applicable tests / formatters when an execution environment is available. Otherwise say explicitly that they were not run.
- Check module boundaries and security implications.
- Update `docs/000-CONTEXT.md` for status changes; update `docs/002-ARCHITECTURE.md`, `docs/003-MODULES.md`, and/or an ADR for architectural changes.
- Keep `docs/005-ROADMAP.md` consistent with implemented work.
- Record proposed decisions as proposed, never implemented.
- Prefer PRs into `master`; do not make undocumented direct changes to `master`.

See `docs/004-STANDARDS.md` for coding and documentation conventions.
