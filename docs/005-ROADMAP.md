# Roadmap

All statuses reflect **code in the repository**, not intentions.

| Increment | Scope | Status |
| --- | --- | --- |
| F0 — Laravel Bootstrap | Laravel 13 skeleton and initial push | Completed on `master` |
| F1 — Architecture & Context | Module boundaries/providers, docs-as-code, registration test | Proposed in current PR; not merged |
| F2 — Developer Tooling | Tooling decisions (Pest/Larastan/Pint), CI enhancements, local environment instructions | Planned |
| F3 — Identity | Choose authentication/authorization use cases, implement with tests | Planned |
| F4 — Administration UI | Decide/install Livewire/Mary UI and implement first guarded admin shell | Planned |
| F5 — Product Domains | Discover and define future business modules based on actual requirements | Undecided |

## Deferred architectural choices
All networking: WireGuard vs Outline or other transports; Core/Edge; node/agent access; tunneling, VPN provisioning and monitoring internals.

## Completion criteria for F1
- Providers are registered explicitly and covered by a smoke test.
- Architecture guide and current-context docs are committed.
- Agent instructions and PR template enforce updating docs alongside changes.
- Existing CI test workflow passes after the PR (validation pending until executed).
