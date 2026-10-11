# Development and Documentation Standards

## Workflow
1. Read `000-CONTEXT.md` and relevant module/architecture docs.
2. Inspect code and identify the smallest deliverable.
3. Work on a topic branch; include meaningful tests.
4. Update at least one relevant `docs/*.md` file in the same PR.
5. Run `php artisan test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pint --test` (or `composer quality`) when possible; disclose what was not run.
6. Open PR to `master`; document risks and deferred decisions.

## Telegram security baseline (F4)
- Only private Telegram chats can link or display account/admin menus; identify users by numeric Telegram ID, not username.
- No Bot API token, webhook secret or one-time link code in the Git repository or logs. Never report raw Nutgram or HTTP exceptions; log sanitized type/operation IDs because API URLs can embed the token.
- Verify configured webhook secret (constant-time compare), fail closed when absent; bypass only web session/CSRF by using the stateless API middleware group.
- Use DB-backed update receipts and retry on transient failures; outbound messages are not exactly-once side effects.
- A Telegram handler cannot run a shell command or directly mutate domain persistence. Call approved Application use cases. Dispatch long-running operations through durable queues in future increments.
- Configure the webhook via `telegram:webhook:set` (not the vendor hook command). Do not enable public account sign-up implicitly.

## Identity security baseline
- No public registration or default credentials; bootstrap an admin interactively using Artisan.
- Admin JSON endpoints use web-session auth and CSRF; never treat them as token-auth APIs.
- Use request validation and explicit access checks; never fill role or active flags from untrusted mass-assignment.
- Login is throttled, session ID rotated on login, session invalidated on logout/disable, and last-admin/self-lockout checks apply.

## Code
- Follow Laravel/PHP conventions and PSR-4 naming.
- Keep application rules outside controllers, Livewire components, and future bot adapters.
- Prefer typed public method signatures and focused classes.
- Do not introduce generic repositories, event buses, module loaders, or premature abstractions.
- Keep migrations reversible when feasible; never commit `.env` or credentials.
- Unit/feature tests are required for behavior changes. Pest architecture tests cover explicit module boundaries; update them when contracts intentionally change.
- Use PHP >=8.4.1; do not bypass Composer platform requirements.
- Keep `composer.lock` portable: no private/workstation-specific mirror or credentials in package URLs.

## Documentation rules
- **Code is the behavioral truth.** Mark proposed work and decisions honestly.
- Update `000-CONTEXT.md` for meaningful status/progress changes.
- Update `002-ARCHITECTURE.md` or `003-MODULES.md` for architectural/module changes.
- Update `005-ROADMAP.md` when milestones move.
- Add a small ADR for nontrivial, persistent decisions or changes to prior decisions.
- Keep documents short, with links instead of duplicated narratives.

## Commit / PR
Use descriptive conventional commits (e.g., `chore:`, `docs:`, `feat:`, `test:`). PR description must state tests run, documents changed, and any known limitations.

## Dynamic RBAC and protected roots (F5)
- Never treat `SUPER_ADMIN_TELEGRAM_IDS` as an authorization grant. A trusted root must be active, have the DB `super-admin` role, and be linked to an allowlisted numeric ID; require at least **two** distinct allowlisted IDs.
- Do not expose Super Admin role assignment, removal or root account disablement via Telegram or web interfaces. Provision from the secure CLI with confirmation.
- All permission decisions must run in Application Actions / Gates; UI-only menu checks do not count.
- Keep audit events for role/privilege mutations, without secrets, tokens or passwords.
- A role can be added dynamically; adding a new permission means first defining the corresponding application authorization check.

## Telegram UI navigation (F06-A)
- Explicit callback names only; never trust UI state or message visibility as proof of authorization.
- Re-resolve the active linked Identity account on each command and callback; use the Identity Application DTO rather than reaching into persistence from a Telegram handler.
- Unknown/deactivated accounts never see protected pages. Role-management previews require a trusted Super Admin; admin-user previews require `users.manage` authority.
- Reply to callback queries and edit the existing message for navigation. Avoid pretending a placeholder is a functional settings/users/roles page.

## F06-B user operations and confirmation
- Do not load Identity Infrastructure models inside Telegram presentation classes: browse, lookup and status-change Actions must perform current authorization.
- Always recheck actor role/activity on callback, including old buttons; role management and Super Admin protection remain enforced by Identity.
- A confirmed mutation is a one-time cache token, bound to the initiating internal actor and expires after 3 minutes. Use a cache lock for concurrent callback consumption. Final DB operation checks previous active state to reject replay/races.
- Telegram messages must never contain password hashes, link tokens or raw Telegram IDs. A name/email shown in plain text must have control characters normalized.
- Page/search queries must be bounded. Search context should expire and remain scoped to one internal actor.

## Telegram RBAC mutation safeguards (F06-C)
- A Telegram UI callback cannot grant or revoke the protected Super Admin role. Root enrollment stays in the operator CLI.
- Only active, linked, ENV-allowlisted **trusted Super Admins** may create/modify custom roles or assign multiple roles.
- Built-in `member`, `admin`, and `super-admin` role permissions are protected from Telegram edits. New permission types must be created in code with server-side enforcement before they are offered to roles.
- Every write requires a short-lived, unpredictable, one-time confirmation tied to the initiating **internal user ID**, stored in a lock-capable cache. Re-validate the DB state and actor trust inside an Identity transaction; reject stale/replayed toggles; append an audit event.
- Do not store raw Telegram IDs/tokens in role UI, callback strings or audit records. Present only predefined permissions and validated role names.

## F07 public onboarding
- Public signup is an explicit opt-in: never auto-enroll an account merely from `/start`; private-chat click plus `TELEGRAM_PUBLIC_SIGNUP_ENABLED` required. Avoid intercepting existing `/link` operator setup.
- Use numeric Telegram IDs as binding keys; `@username`, referral slug, names and arbitrary message text are never authorization identities.
- Register `member` only; never upgrade existing accounts or reactivate disabled accounts via onboarding. Do not give public Telegram users a verified email or reusable web password.
- Referral attribution is immutable, at most one referrer per newly created account, with a DB unique constraint. Invalid or missing codes never block standalone signup.
- No marketing reward or monetary ledger row can be implied by an invitation.
