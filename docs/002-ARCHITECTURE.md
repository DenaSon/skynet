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

## Identity implementation (F3)
Identity owns its Eloquent User model and session-authenticated web endpoints. Authentication, authorization and administrative user-management actions remain within Identity; Administration stays UI-only until a real composition requirement arises. No separate token API or public signup exists. See [Identity ADR](adr/0003-identity-foundation.md).

## Telegram-first adapter (F4)
`app/Interfaces/Telegram` owns transport handlers, bot presentation, webhook receipts and operational commands. Nutgram parses Telegram updates and sends replies. It calls Identity **Application actions** (`ResolveTelegramActor`, `IssueTelegramLinkCode`, `LinkTelegramAccount`), not Identity persistence models. Identity owns `telegram_accounts` and `telegram_link_tokens`.

`/api/telegram/webhook` is a stateless endpoint using a constant-time check of `X-Telegram-Bot-Api-Secret-Token` against a separately configured secret. Nutgram vendor safe_mode is intentionally disabled because the project controller validates that token first; use **only** the project `telegram:webhook:set` command to register the matching token.

Webhook receipts use a database unique update ID, 90-second processing lease, processed marker and retry on failure. This reduces duplicate handling but cannot provide exactly-once delivery of outbound Telegram messages. Simple commands run synchronously; time-consuming operations require durable queue design later.

The future Livewire UI may call the same Application Actions directly; REST is not mandatory for an in-process presenter. See [ADR 0004](adr/0004-telegram-first.md).

## Open decisions
Administrative UI, granular permissions, queue/cache deployment, monitoring, and all network technologies remain future work.

See [ADR 0001](adr/0001-modular-monolith.md).

## Current PHP dependency compatibility
F2 formalizes PHP >=8.4.1 in `composer.json`, with `config.platform.php=8.4.1` to resolve packages against the supported minimum. The lock file is free of workstation-only Composer mirror URLs. CI targets PHP 8.4/8.5. This is a deliberate project requirement, not Laravel's absolute minimum. See [ADR 0002](adr/0002-php-and-quality-tooling.md).

## RBAC hardening (F5)
The identity module owns dynamic roles/permissions via Spatie Permission v8 (single `web` guard). User role assignments are relational. Admin-only operations pass through Gate and application actions so future Telegram/Livewire callers share enforcement. `super-admin` remains a reserved, protected role with a **second** mandatory allowlist check against the account's linked numeric Telegram ID. ENV is not a grant of privilege and needs at least two valid unique IDs. All state changes are audited. See [ADR 0005](adr/0005-dynamic-rbac-super-admin.md).

## Remnawave Nodes integration (F10-C merged PR #21)
`Modules/Provisioning/Infrastructure/Remnawave` owns vendor-facing `RemnawaveNodesApi` and `RemnawaveNodeTelemetry`, using the common pinned `RemnawaveApiClient`. Node mutations are **internal infrastructure capabilities, not authorized user-facing operations**. Telegram, Sales and Billing never call them directly. Authorize and audit through a future Provisioning Application boundary with replay protection and operator confirmation before exposing any mutating node commands. No migrations or financial/provider side effects are introduced. See [F10-C runbook](011-REMNAWAVE-NODES.md).
