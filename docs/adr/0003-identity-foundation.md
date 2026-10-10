# ADR 0003 — Identity Foundation

**Date:** 2026-10-11
**Status:** Accepted for F3 (pending CI merge)

## Context
SkyNet needs a safe first human-user workflow before building its Administration UI. There is no decided billing model, reseller taxonomy, Telegram adapter or network data plane.

## Decision
- Keep Identity as a module owning `User` (Eloquent infrastructure), session-authenticated web interfaces, user-management application actions and credential validation.
- Use Laravel native `web` guard, session rotation, CSRF middleware, a credential/IP rate limiter, and an active-user middleware.
- Introduce only `admin` and `member` roles with `is_active`. The admin Gate authorizes user management. Do not add a permission framework without granular permission use cases.
- No public registration; create the first admin interactively with `identity:bootstrap-admin`. No pre-seeded password.
- Protect last active administrator and prevent self-demotion/deactivation; unauthorized clients cannot assign roles or change account state.
- Keep the admin-user endpoints under web session + CSRF, JSON output only until a real admin UI exists.

## Consequences
The default Laravel `App\Models\User` moves under Identity, with `config/auth.php` and factory adjusted. A migration adds role/active flags with safe member/active defaults for existing user rows. There is no account recovery UI, MFA, granular RBAC, or session list/revocation UI yet.

## Deliberate non-decisions
Telegram authentication, OAuth, API tokens, Livewire interface, Stripe/payment, VPN/network protocols, and Core/Edge topology.
