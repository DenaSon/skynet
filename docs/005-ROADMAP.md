# Roadmap

All statuses reflect **code in the repository**, not intentions.

| Increment | Scope | Status |
| --- | --- | --- |
| F0 — Laravel Bootstrap | Laravel 13 skeleton and initial push | Completed on `master` |
| F1 — Architecture & Context | Module boundaries/providers, docs-as-code, registration test | Completed on `master` (PR #1) |
| F2 — Developer Tooling | PHP >=8.4.1 and portable lock; Pest 5/Larastan 3/Pint; architecture checks and CI | Completed (PR #2; passing CI on PHP 8.4/8.5) |
| F3 — Identity | Session auth; admin bootstrap; role/active access; JSON user management; tests | Completed (PR #3, PHP 8.4/8.5 CI green; 23 tests) |
| F4 — Administration UI | Decide/install Livewire/Mary UI and implement first guarded admin shell | Planned |
| F5 — Product Domains | Discover and define future business modules based on actual requirements | Undecided |

## F3 acceptance criteria
- Login/logout work through Laravel session auth with CSRF, throttling and rotation.
- An admin can be bootstrapped without shipping known passwords.
- Admin can list/create users and modify role/active state; members/guests cannot.
- Active-session deactivation, self-lockout and last-admin protections have feature tests.
- Existing tests, Larastan and Pint pass on PHP 8.4/8.5.
- Documentation matches behavior; no network features introduced.

## Deferred architectural choices
All networking: WireGuard vs Outline or other transports; Core/Edge; node/agent access; tunneling, VPN provisioning and monitoring internals.

## F2 acceptance criteria
- `composer.json` and `composer.lock` install consistently using PHP >=8.4.1, without workstation mirror URLs.
- Pest executes existing tests and new architecture checks; static analysis and Pint run successfully.
- GitHub Actions runs tests, analysis and style verification on PHP 8.4 and 8.5.
- Context, standards, README and architectural rationale are synchronized.

