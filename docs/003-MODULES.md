# Module Boundaries

**Status:** Initial placeholders only. No module implements a user-facing use case.

| Module | Proposed responsibility | Implemented |
| --- | --- | --- |
| Identity | Users, authentication and permissions | Provider registered only; default `App\\Models\\User` still in Laravel skeleton |
| Administration | Back-office workflows and administrative UI | Provider registered only |

The two placeholder boundaries are provisional; refine ownership when the first concrete use case is selected. Avoid adding future commercial or network modules until their responsibilities are defined.

## Conventions
- Namespace: `App\\Modules\\<Name>\\...`.
- Location: `app/Modules/<Name>/`.
- Provider: `<Name>ServiceProvider.php`, explicitly registered in `bootstrap/providers.php`.
- No cross-module access to another module's private persistence or domain internals.
- Add application actions, DTOs, entities, routes, jobs, and migrations only when a feature needs them.
- Shared abstractions belong in `app/Shared/` only after a real multi-module use case warrants them.

## First acceptance check
`tests/Feature/ModuleRegistrationTest.php` verifies both providers are loaded when Laravel boots. No login, authorization or UI behavior is implied by that test.
