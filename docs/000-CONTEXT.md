# SkyNet — Current Context

**Last reviewed:** 2026-10-11  
**Stage:** Foundation F2 developer tooling — implementation committed in feature branch; PR/CI review pending  
**Behavioral source of truth:** current repository code

## Product direction
A clean Laravel successor to the Python NVL bot, able to grow beyond Telegram interfaces. Current objective: a maintainable engineering foundation, not a working service-management product.

## Confirmed decisions
- Laravel 13, **PHP >=8.4.1**, single Modular Monolith, one primary database and deployable.
- Domain-oriented modules with explicit registration; avoid empty DDD layers/custom framework.
- Pest **5**, Larastan **3** (PHPStan level 5), Laravel Pint; run checks in CI.
- Explicit feature branches and documentation-as-code: update a relevant document with meaningful code changes.
- **Network technology UNDECIDED.** No WireGuard, Outline, Core/Edge, agent, provisioning, VPN protocol or adapter abstractions yet.

## Current code
- Foundation F1 merged on `master`: provisional `Identity` and `Administration` modules with empty providers and provider-registration tests.
- F2 branch installs Pest and its Laravel plugin via Composer with a committed portable lock file; PHPUnit is a Pest dependency, no longer directly required.
- F2 adds Pest architecture tests in `tests/Architecture`, `tests/Pest.php`, Larastan configuration, and PHP 8.4/8.5 CI quality checks.
- `composer.lock` URLs have been normalized from a workstation-only Runflare mirror to public package sources.
- Laravel's default SQLite and database queue/session/cache remain local defaults; production MySQL/Redis not yet configured.

## Still not implemented
Authentication flows, dashboard, Livewire/Mary UI, business domains, integrations, networking, service provisioning, monitoring.

## Operational validation
Composer lock was successfully resolved in a dedicated GitHub Actions environment using PHP 8.4. Full application tests/static checks in the F2 PR are **pending** until its CI completes; do not represent them as passing without verification.

## Next steps
1. Validate and merge F2 once CI succeeds.
2. Identify first concrete Identity use case; implement only the authentication/authorization features required for that use case.
3. Design the Administration UI increment separately.
4. Leave network decisions open.

## Reading order
[Vision](001-VISION.md) · [Architecture](002-ARCHITECTURE.md) · [Modules](003-MODULES.md) · [Standards](004-STANDARDS.md) · [Roadmap](005-ROADMAP.md) · [ADRs](adr/0001-modular-monolith.md)
