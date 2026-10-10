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
