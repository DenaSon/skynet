# Roadmap

All statuses reflect **code in the repository**, not intentions.

| Increment | Scope | Status |
| --- | --- | --- |
| F0 — Laravel Bootstrap | Laravel 13 skeleton and initial push | Completed on `master` |
| F1 — Architecture & Context | Module boundaries/providers, docs-as-code, registration test | Completed on `master` (PR #1) |
| F2 — Developer Tooling | PHP >=8.4.1 and portable lock; Pest 5/Larastan 3/Pint; architecture checks and CI | Completed (PR #2; passing CI on PHP 8.4/8.5) |
| F3 — Identity | Session auth; admin bootstrap; role/active access; JSON user management; tests | Completed (PR #3, PHP 8.4/8.5 CI green; 23 tests) |
| F4 — Telegram Foundation | Nutgram, secure webhook, deduplication, private account linking, inline menus | Completed (PR #4; 40 tests, CI PHP 8.4/8.5) |
| F5 — Identity RBAC | Dynamic roles/permissions, guarded Super Admin enrollment, role migration, audit and tests | Completed (PR #5, PHP 8.4/8.5 CI green; 52 tests) |
| F06-A — Telegram Navigation | Home, account, settings, admin/read-only submenus, keyboard routing and role gating | PR #6 verified (58 tests; PHP 8.4/8.5), merge pending |
| F06-B — Telegram Users | Search, list, profile and guarded state updates | Planned |
| F06-C — Telegram RBAC | Custom role/permission workflows via Identity actions | Planned |
| Later — Web UI | Livewire 4 + Mary UI consuming the same Actions | Deferred |
| Later — Product Domains | Define network/server/business capabilities based on use cases | Undecided |

## F3 acceptance criteria
- Login/logout work through Laravel session auth with CSRF, throttling and rotation.
- An admin can be bootstrapped without shipping known passwords.
- Admin can list/create users and modify role/active state; members/guests cannot.
- Active-session deactivation, self-lockout and last-admin protections have feature tests.
- Existing tests, Larastan and Pint pass on PHP 8.4/8.5.
- Documentation matches behavior; no network features introduced.

## F4 acceptance criteria
- Portable Nutgram Composer lock resolves on PHP 8.4 / 8.5.
- Webhook rejects missing/mismatched secrets and invalid updates; retry receipts cover duplicates, in-flight and failed updates.
- User binding uses expiring one-time hashed codes, no public sign-up; private chat and active-role gates apply.
- User/admin Telegram inline menus load; no simulated admin functionality.
- Tests, static analysis and Pint checks pass; deploy steps and docs updated.

## Deferred architectural choices
All networking: WireGuard vs Outline or other transports; Core/Edge; node/agent access; tunneling, VPN provisioning and monitoring internals.

## F2 acceptance criteria
- `composer.json` and `composer.lock` install consistently using PHP >=8.4.1, without workstation mirror URLs.
- Pest executes existing tests and new architecture checks; static analysis and Pint run successfully.
- GitHub Actions runs tests, analysis and style verification on PHP 8.4 and 8.5.
- Context, standards, README and architectural rationale are synchronized.


## F06-A acceptance criteria
- `/start` and inline Home / Account / Settings / Administration navigation work in a private chat without creating messages for every click.
- Account text reflects the current Identity role set and active status; settings is explicitly read-only.
- Role previews are available only to trusted Super Admins; user-management previews to authorized admins.
- Unauthorized/stale callback queries cannot expose protected pages.
- Pest, Larastan and Pint are green on PHP 8.4 and 8.5; no migration or new dependency.
