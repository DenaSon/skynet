# SkyNet — Current Context

**Last reviewed:** 2026-10-11  
**Stage:** Foundation / first architecture PR  
**Source of truth for behavior:** current repository code

## Product direction
A clean Laravel successor to the Python NVL bot, built to support multiple interfaces eventually. The goal at this stage is a reliable *foundation*, not a working service-management product.

## Accepted decisions
- Laravel 13 (PHP 8.3+) in a **modular monolith**.
- Domain-oriented boundaries, without heavy DDD ceremony.
- Explicit module provider registration; no custom framework or automatic module discovery.
- Documentation-as-code: each meaningful change updates relevant docs in the same PR.
- Work on feature branches, review via PR before merging to `master`.
- **Network layer is undecided.** No WireGuard, Outline, Core/Edge, agent, or VPN-specific interfaces yet.

## Actual implementation (this branch)
- Vanilla Laravel framework and skeleton routes/tests.
- Empty `Identity` and `Administration` module boundaries with registered providers.
- One feature test checks that both providers are registered.
- Existing Laravel-skeleton GitHub Actions workflow (PHP 8.3/8.4/8.5).

## Not implemented
Identity flows, admin dashboard, Livewire/Mary UI, business modules, provisioning, monitoring, network services, and package/tooling upgrades. PHPunit remains the current test runner; Pest and Larastan are planned. SQLite/database-backed local defaults are retained until configuration work is performed.

## Next small increment
Validate PR checks and merge; then implement developer tooling and configure the chosen local/test services without changing network architecture. See [005-ROADMAP.md](005-ROADMAP.md).

## Reading order
1. This file
2. [001-VISION.md](001-VISION.md)
3. [002-ARCHITECTURE.md](002-ARCHITECTURE.md)
4. [003-MODULES.md](003-MODULES.md)
5. [004-STANDARDS.md](004-STANDARDS.md)
6. [005-ROADMAP.md](005-ROADMAP.md)
7. [Architecture decisions](adr/0001-modular-monolith.md)
