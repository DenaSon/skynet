# SkyNet — Current Context

**Last reviewed:** 2026-10-11  
**Stage:** Foundation F2 completed (PR #2; merge into `master`)  
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
Composer dependency resolution and lock normalization completed in GitHub Actions. The full F2 CI run **38092853202** passed on PHP 8.4 and 8.5: **7 Pest/PHPUnit tests (10 assertions)**, Larastan level 5 with no errors, and Laravel Pint with no style issues. CI status always applies to a specific commit; review newer runs if the branch changes.

## Next steps
1. Define the first concrete Identity use case, along with minimal acceptance criteria.
2. Implement and test that Identity increment on a separate feature branch.
3. Design Administration UI in a later increment.
4. Leave all network decisions open.

## Reading order
[Vision](001-VISION.md) · [Architecture](002-ARCHITECTURE.md) · [Modules](003-MODULES.md) · [Standards](004-STANDARDS.md) · [Roadmap](005-ROADMAP.md) · [ADRs](adr/0001-modular-monolith.md)
