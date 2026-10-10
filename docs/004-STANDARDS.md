# Development and Documentation Standards

## Workflow
1. Read `000-CONTEXT.md` and relevant module/architecture docs.
2. Inspect code and identify the smallest deliverable.
3. Work on a topic branch; include meaningful tests.
4. Update at least one relevant `docs/*.md` file in the same PR.
5. Run `php artisan test`, `vendor/bin/phpstan analyse --no-progress`, and `vendor/bin/pint --test` (or `composer quality`) when possible; disclose what was not run.
6. Open PR to `master`; document risks and deferred decisions.

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
