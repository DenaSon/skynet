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
