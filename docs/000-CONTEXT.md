# SkyNet — Current Context

**Last reviewed:** 2026-10-11  
**Stage:** Foundation / first architecture PR  
**Source of truth for behavior:** current repository code

## Product direction
A clean Laravel successor to the Python NVL bot, built to support multiple interfaces eventually. The goal at this stage is a reliable *foundation*, not a working service-management product.

## Accepted decisions
- Laravel 13 in a **modular monolith**. Its declared PHP requirement allows 8.3, but the current committed dependencies require **PHP >=8.4.1**.
- Domain-oriented boundaries, without heavy DDD ceremony.
- Explicit module provider registration; no custom framework or automatic module discovery.
- Documentation-as-code: each meaningful change updates relevant docs in the same PR.
- Work on feature branches, review via PR before merging to `master`.
- **Network layer is undecided.** No WireGuard, Outline, Core/Edge, agent, or VPN-specific interfaces yet.

## Actual implementation (this branch)
- Vanilla Laravel framework and skeleton routes/tests.
- Empty `Identity` and `Administration` module boundaries with registered providers.
- One feature test checks that both providers are registered.
- GitHub Actions tests configured for PHP 8.4/8.5 after discovering that the existing Symfony 8 lock file cannot install on PHP 8.3.

## Not implemented
Identity flows, admin dashboard, Livewire/Mary UI, business modules, provisioning, monitoring, network services, and package/tooling upgrades. PHPunit remains the current test runner; Pest and Larastan are planned. SQLite/database-backed local defaults are retained until configuration work is performed.

## Next small increment
Validate PR checks and merge; then decide whether to support PHP 8.3 by re-locking compatible Symfony 7.4 dependencies, or formally raise the application's minimum PHP requirement to 8.4.1. Continue developer tooling and local/test configuration without changing network architecture. See [005-ROADMAP.md](005-ROADMAP.md).

## Reading order
1. This file
2. [001-VISION.md](001-VISION.md)
3. [002-ARCHITECTURE.md](002-ARCHITECTURE.md)
4. [003-MODULES.md](003-MODULES.md)
5. [004-STANDARDS.md](004-STANDARDS.md)
6. [005-ROADMAP.md](005-ROADMAP.md)
7. [Architecture decisions](adr/0001-modular-monolith.md)
