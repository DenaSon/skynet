# Development and Documentation Standards

## Workflow
1. Read `000-CONTEXT.md` and relevant module/architecture docs.
2. Inspect code and identify the smallest deliverable.
3. Work on a topic branch; include meaningful tests.
4. Update at least one relevant `docs/*.md` file in the same PR.
5. Run tests and static checks when possible; disclose what was not run.
6. Open PR to `master`; document risks and deferred decisions.

## Code
- Follow Laravel/PHP conventions and PSR-4 naming.
- Keep application rules outside controllers, Livewire components, and future bot adapters.
- Prefer typed public method signatures and focused classes.
- Do not introduce generic repositories, event buses, module loaders, or premature abstractions.
- Keep migrations reversible when feasible; never commit `.env` or credentials.
- Unit/feature tests are required for behavior changes; add architecture tests for enforceable boundaries once a real rule exists.

## Documentation rules
- **Code is the behavioral truth.** Mark proposed work and decisions honestly.
- Update `000-CONTEXT.md` for meaningful status/progress changes.
- Update `002-ARCHITECTURE.md` or `003-MODULES.md` for architectural/module changes.
- Update `005-ROADMAP.md` when milestones move.
- Add a small ADR for nontrivial, persistent decisions or changes to prior decisions.
- Keep documents short, with links instead of duplicated narratives.

## Commit / PR
Use descriptive conventional commits (e.g., `chore:`, `docs:`, `feat:`, `test:`). PR description must state tests run, documents changed, and any known limitations.
