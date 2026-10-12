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
Administrative Web UI, F11-A2 verified direct bank settlement, F11-B outbox dispatch/reconciliation, production DB/cache topology, authorized node actions and real provider/network E2E remain future work. F10-E1/E2/E3 are merged; F11-A1 wallet settlement/outbox storage is merged, OFF by default. Remnawave **is** selected as the external subscription panel API; independent SSH/WireGuard/Core/Edge transport choices remain open.

See [ADR 0001](adr/0001-modular-monolith.md).

## Current PHP dependency compatibility
F2 formalizes PHP >=8.4.1 in `composer.json`, with `config.platform.php=8.4.1` to resolve packages against the supported minimum. The lock file is free of workstation-only Composer mirror URLs. CI targets PHP 8.4/8.5. This is a deliberate project requirement, not Laravel's absolute minimum. See [ADR 0002](adr/0002-php-and-quality-tooling.md).

## RBAC hardening (F5)
The identity module owns dynamic roles/permissions via Spatie Permission v8 (single `web` guard). User role assignments are relational. Admin-only operations pass through Gate and application actions so future Telegram/Livewire callers share enforcement. `super-admin` remains a reserved, protected role with a **second** mandatory allowlist check against the account's linked numeric Telegram ID. ENV is not a grant of privilege and needs at least two valid unique IDs. All state changes are audited. See [ADR 0005](adr/0005-dynamic-rbac-super-admin.md).

## Remnawave Nodes integration (F10-C merged PR #21)
`Modules/Provisioning/Infrastructure/Remnawave` owns vendor-facing `RemnawaveNodesApi` and `RemnawaveNodeTelemetry`, using the common pinned `RemnawaveApiClient`. Node mutations are **internal infrastructure capabilities, not authorized user-facing operations**. Telegram, Sales and Billing never call them directly. Authorize and audit through a future Provisioning Application boundary with replay protection and operator confirmation before exposing any mutating node commands. No migrations or financial/provider side effects are introduced. See [F10-C runbook](011-REMNAWAVE-NODES.md).

## F10-D — Provider user lifecycle and uncertain-write recovery
The Provisioning Application layer coordinates a durable `provisioning_subscriptions` / `provisioning_operations` reservation before each remote write. Transport remains in Infrastructure, and Billing/Sales/Telegram have **no direct dependency** on the provider. A second write is prohibited until a previous ambiguous operation has been reconciled. The customer subscription URL is secret and only returned by internal read after provider marker verification. No HTTP route, Telegram handler or order is wired to new write actions; the module remains default-disabled. See [F10-D runbook](012-REMNAWAVE-USER-LIFECYCLE.md).

## Current integration seam (F10-E and F11-A1 merged; F11-B future)

```text
Telegram adapter / future Livewire presenter
            |
            v
Identity Application — resolve fresh active actor + RBAC
            |
            v
Sales Application — buyer-owned order and immutable price snapshot
            |
            v
F10-E authorized shared subscription reads + safe DTOs + Telegram presenter
            |
            v
Provisioning Application — SQL-reserved lifecycle and reconciliation (F10-D)
            |
            v
Provisioning Infrastructure — typed Remnawave API adapters/client (F10-A/B/C/D)
            |
            v
Remnawave v3.4.4 (NOT live-E2E verified)
```

- **Already built:** F10-D `RemnawaveSubscriptionLifecycle` and the unique `provisioning_subscriptions` / `provisioning_operations` tables. This lifecycle has **no actor authorization, Sales order ownership, payment verification or Telegram/HTTP entry point**. It must remain internal and writes default-disabled.
- **F10-E merged:** Actor-authorized subscription list/detail/connection/node Actions and Telegram presentation. A callback or provider ID is never proof of ownership; subscription URL remains a separately authorized bearer credential.
- **F11-A1 merged but disabled:** Atomic Sales/Billing wallet debit, paid-order transition and pending outbox insert in a single DB transaction, with no remote provider call or outbox consumer. F11-A2 payment verification and F11-B safe dispatch/reconciliation remain future, gated by production DB concurrency and live staging E2E.
- **Infrastructure boundary:** `RemnawaveUsersApi`, `RemnawaveSubscriptionsApi`, `RemnawaveNodesApi` and `RemnawaveNodeTelemetry` can make internal calls but **do not** implement business RBAC. Node mutations require a separate operator-authorized Application action, audit and confirmation.
- **Actual tests:** [master CI 38213468652](https://github.com/DenaSon/skynet/actions/runs/38213468652) after PR #23 merge passed PHP 8.4/8.5 (200 tests / 959 assertions). Live panel, bank and Telegram E2E not executed. Canonical state: [000-CONTEXT.md](000-CONTEXT.md).

## F11-A2 — Manual Super Admin payment gate

A private Nutgram receipt photo is an untrusted payment claim, never automatic bank proof. Sales `ManualBankPayments` reauthorizes the Identity actor/owner and the current Super Admin allowlist, persists encrypted Telegram file references and auditable approve/reject decisions, and requires the operator to manually match a bank statement reference before final confirmation. Approval reserves a globally unique F08/F11 payment reference and atomically persists direct-bank double-entry postings, Sales `paid` status and a pending outbox record. `SALES_MANUAL_BANK_REVIEW_ENABLED=false` by default; there is no live bank API or fulfillment dispatcher. Live E2E, production DB lock verification and operational bank reconciliation remain gates.

## F11-B0 — Single Billing financial ledger

Only Billing owns `billing_bank_reference_claims`, `billing_journals` and `billing_postings`. A direct bank sale records a `direct_bank_sale` journal with `wallet_id=NULL` and the exact two `cash_clearing`/`order_liability` postings. Reseller wallet reads still select their own wallet liability; no independent Sales postings ledger exists. Sales invokes Billing Application Actions for verified-bank reference claiming, direct-bank posting and wallet debit replay attestation. Sales owns order/claim/settlement snapshot, audit and pending outbox. Everything commits in one DB transaction; Telegram and Remnawave remain outside. See [ADR 0008](adr/0008-unified-billing-ledger.md).
