# Architecture

**Status:** Foundation decision; feature design pending.

## Style
**Modular Monolith:** one Laravel application, one primary database, one deployment unit. Domain boundaries exist inside the application rather than as separate services.

## Direction of dependencies
- **Presentation:** HTTP controllers, future Livewire/API/Telegram adapters. Calls application use cases.
- **Application:** actions/use cases and DTOs; coordinates domain behavior.
- **Domain:** business concepts and rules, avoiding framework/UI concerns where practical.
- **Infrastructure:** Eloquent, external service clients, queues, and other technical adapters.

These are *design rules*, not an invitation to create empty folders or abstract interfaces for hypothetical features.

## Minimal layout

```text
app/
  Modules/
    Identity/
      IdentityServiceProvider.php
    Administration/
      AdministrationServiceProvider.php
  Shared/              # create only on demonstrated cross-module needs
bootstrap/providers.php # explicit provider list
database/               # shared migration entrypoint for now
tests/
docs/
```

Within a module, introduce `Domain/`, `Application/`, `Infrastructure/`, and `Presentation/` folders **as real code requires them**. Do not invent a framework for discovering modules. Each module uses `App\\Modules\\...` via existing `App\\` Composer PSR-4 autoloading.

## Integration rules
Avoid reaching into another module's persistence, internal model, or domain implementation. Expose carefully scoped application contracts when genuine cross-module use cases appear. Do not build an event bus solely to couple empty modules.

## Runtime reality
The initial repository retains Laravel's default local SQLite, database queue/cache/session settings. Production MySQL and Redis have been discussed as future environment choices but have **not** been configured or verified here.

## Open decisions
Identity ownership and feature boundaries, administrative UI, queue/cache deployment, monitoring, and all network technologies remain future work.

See [ADR 0001](adr/0001-modular-monolith.md).
