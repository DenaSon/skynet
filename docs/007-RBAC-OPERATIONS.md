## RBAC-A2 — delegated read-only Admin Bot permissions (2026-10-25; implementation PR under review)

User-approved Phase 2 only of [the three-phase RBAC plan](030-ADMIN-RBAC-THREE-PHASE-ROADMAP.md). Four **additive** Spatie permissions: `support.view`, `reports.view`, `finance.view`, `servers.view`. Custom roles receive these explicitly through the existing audited root-only role editor; built-in `admin` keeps `users.manage` and implicit legacy `support.view` capability, but receives **no automatic finance/report/server grant**. Root identities stay governed by the linked Telegram allowlist and existing full menus. No new bot, provider write, financial transfer, queue or secret.

- `support.view` permits basic user search and customer 360 / owner-linked service GET; it cannot change user status. The legacy `users.manage` support access stays. `users.view` alone **cannot** open the customer 360 or live service Action.
- `reports.view` provides a separate bounded local **operational counts** screen: users, active users, orders, paid orders, locally active services. No revenue, wallet balance, private accounting or special operational diagnostics.
- `finance.view` provides ledger-derived wallet balances and a read-only paginated directory through existing Billing read Actions; no receipt approval or rejection (still `purchases.review` / `topups.review`), no accounting writes or privileged finance command center.
- `servers.view` permits only the existing secret-free Remnawave **GET-only** overview and bounded node connection/status names, with a dedicated mutation-free screen. No Node/Host/Profile/DNS actions, tokens, provider mutation or reconciliation rights. Unavailable/failed panel read remains unknown; never fabricate offline state.
- Permission checks apply to fresh Admin Bot entry, menu, every read Application Action and stale callbacks. Revoked/disabled accounts fail closed. Sales Bot behavior and root-only sensitive menus are unchanged.

CI must pass PHP 8.4 / 8.5 Pest, PHPStan, Pint and disposable MySQL migration/rollback. CI fake/provider HTTP is **not** proof of real Admin Bot or provider acceptance; review safe outputs and read-only routes after auto-deploy. Phase 3 remains unimplemented until separately requested.

## RBAC-ADMIN-01 — granular Admin Bot capabilities (Phase 1 source implementation pending CI, 2026-10-25)

See [three-phase authorization roadmap](030-ADMIN-RBAC-THREE-PHASE-ROADMAP.md). The **current** built-in admin role still has users.manage and its original user-management/support reads; built-in roles are protected from generic permission mutation. The present custom-role manager supports code-defined permissions but does not unlock currently root-only finance, servers or catalog screens simply by creating a new role. The scoped Phase 1 code introduces additive permissions (pending CI/merge): users.view, purchases.view/review, topups.view/review (users.manage remains). Phase 2 support.view, reports.view, finance.view, servers.view; Phase 3 plans.manage, resellers.review. A restricted payment-only reviewer uses a **custom role** without the built-in admin role, which would otherwise add users.manage. Admin Bot entry must recognize approved scoped operator capabilities and enforce permission at menu, handler, final confirmation and Application Action, without opening Sales Bot admin controls or relaxing trusted-root checks.

**Nonnegotiable Phase 1 alert requirement:** match receipt type and view/review permissions when extending ReceiptSubmitted, WalletTopupSubmitted and ReceiptReviewReminder to non-root Admin Bot operators; keep trusted roots, per-recipient unique outbox evidence, existing rate limit/retry and no empty notification, report or delivery row. Never expose other finance categories to restricted recipients. Phase 1 implementation is separately authorized; role assignment and live bank tests still require operator acceptance.

# F5 — RBAC and Super Admin Operations

## Migration
Before production migration, back up the database. Install packages from committed `composer.lock` with `composer install`, then `php artisan migrate`.
Migration **000001** backfills existing `users.role` values into relational Spatie roles and removes that old column. Existing `admin` and `member` assignments are preserved. The rollback can only approximate single-role semantics; test restores on staging before using them.

## Two-root setup
1. Find the numeric Telegram ID of each trusted person from an independently verified source. Do not use `@username`; IDs must be distinct.
2. Put `SUPER_ADMIN_TELEGRAM_IDS=111111111,222222222` (replace examples with real IDs) in the **untracked production** `.env`. Refresh config cache: `php artisan config:clear` and rebuild if your deployment uses `config:cache`.
3. Bootstrap the first internal admin with `identity:bootstrap-admin` if no administrator exists, and create the second account through authenticated admin user creation. There is **no default password**.
4. Use `telegram:link-account <internal-user-id>` from a trusted terminal for each account, then redeem each temporary one-time code in that person's private chat.
5. On a secure operator console run **separately**: `php artisan identity:super-admin:promote <internal-user-id>` for each. Check the intended identity before confirming. The command requires an active account, a valid Telegram link, and membership in the configured allowlist. It replaces any ordinary roles and records an audit event.
6. Verify both accounts have the DB role `super-admin`, remain active and are linked to allowed IDs. Never post any Telegram API or pairing tokens to tickets.

Root permissions require all of DB role + active user + matched binding + allowlist, and at least two allowed IDs. Merely listing an ID in `.env` is not enough. Until two roots are enrolled, don't treat the system as meeting the desired redundancy level.

## Role management
Role creation is available as an **Application Action** and guarded JSON session endpoint `POST /admin/roles` (CSRF applies), authorized only for trusted Super Admins. Built-in role names are immutable via this endpoint. List names via `GET /admin/roles`. Custom names (e.g. `reseller`) can be given **existing** code-defined permissions; new permission types require code + tests. `PATCH /admin/users/{user}` accepts a single `role` or `roles` array; only trusted roots can assign privileged/custom roles. No web/Telegram endpoint grants `super-admin`.

Operations write to `identity_audit_events`, including actor, subject, event and non-secret details. Query/review that table in a secure administrative environment. `reseller` business workflows and per-resource ownership remain future work.

## Deployment safety
Run full Pest, Larastan and Pint checks. Root allowlist changes take effect on subsequent requests after config reload. Do not rely on Telegram inline menu visibility as the sole authorization control. If a root account is lost, use a reviewed console recovery procedure rather than exposing an HTTP unlock endpoint.

## Test baseline
GitHub Actions run **38096797661** passed PHP 8.4 and PHP 8.5 with **52 tests and 209 assertions**, zero Larastan errors and a clean Pint check. This is a repository CI check, **not** proof of real Telegram account enrollment or production configuration.
