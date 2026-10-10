# SkyNet — Current Context

**Last reviewed:** 2026-10-11
**Stage:** F3 Identity implementation on `feature/03-identity` (PR/CI pending)
**Behavioral source of truth:** repository code, not planned features.

## Product
SkyNet is a new Laravel successor to Python NVL. It is a modular monolith; UI, business domains and networking are deliberately independent decisions.

## Fixed decisions
- Laravel 13, PHP >=8.4.1, Pest 5, Larastan 3, Pint, PHP 8.4/8.5 CI.
- Explicit module providers under `app/Modules`; no generic module framework or speculative DDD layers.
- Each meaningful code change includes docs in the same PR.
- No network/protocol/topology decisions (WireGuard, Outline, Core/Edge, agent, VPN provisioning).
- F3 uses Laravel session authentication and two initial roles (`admin`, `member`) without a permissions package.
- No public self-registration. First administrator is created interactively via Artisan, never a seeded password.

## Implemented
- F0–F2 on `master` (PR #1 and #2).
- F3 branch: Identity-owned User model, role enum, active flag migration, login/logout, account endpoint, restricted JSON user management, admin bootstrap command and feature/security tests.
- Infrastructure default remains SQLite + database sessions/cache/queues for local use. No production datastore is configured.

## Security properties in F3
- Login attempts throttled by email/IP, generic credential error and session ID regeneration.
- Session invalidated on logout and when an account becomes inactive.
- Admin access checked on every protected request.
- Direct role/active mass assignment is not allowed; only privileged actions can change them.
- Self-revocation and removal of the last active admin are rejected.
- Web routes retain Laravel's CSRF middleware.
- No weak seeded credentials; `DatabaseSeeder` creates no users.

## Not yet implemented
Admin UI/Livewire, public registration, password reset, email verification, 2FA, per-permission RBAC, Telegram, billing, networking, monitoring. JSON management endpoints are **not** a standalone token-authenticated API.

## Validation
F3 changes are not assumed passing until its GitHub CI is green. See PR checks.

## Next
- Complete F3 CI and merge.
- F4: guarded Administration interface, design system and chosen Livewire/Mary UI packages.
- No network design until a separate, explicit decision.

## Reading
[Vision](001-VISION.md) · [Architecture](002-ARCHITECTURE.md) · [Modules](003-MODULES.md) · [Standards](004-STANDARDS.md) · [Roadmap](005-ROADMAP.md) · [ADR 0003](adr/0003-identity-foundation.md)
