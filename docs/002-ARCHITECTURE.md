# Architecture

**Status:** Accepted architecture decision (ADR 0001). The dated phase notes below are historical; the current code snapshot is recorded here as of 2026-11-09.

## Style
**Modular Monolith:** one Laravel application, one primary database, one deployment unit. Domain boundaries exist inside the application rather than as separate services.

## Current implementation snapshot (2026-11-09)

- **Deployable:** one Laravel 13 / PHP 8.4+ application, one shared business database and common queues/scheduler. MySQL and Redis are used in the operator-reported production deployment; CI/checkout alone does not verify live infrastructure.
- **Business modules:** `Identity`, `Billing`, `Sales`, `Provisioning`, `Filtering`, `Referral`, `Onboarding`, `Trial` and the lightweight `Administration` registration. These are namespaces inside the same application, not microservices or separately deployed packages.
- **Presenters:** `app/Interfaces/Telegram` exposes separate Admin and Sales bot contexts; `app/Interfaces/Web/Live` exposes the Livewire Mini App. Both consume shared Application actions where implemented.
- **External integration:** Remnawave API adapters belong to Provisioning Infrastructure. Domain-specific queued jobs, notification outbox and scheduled tasks remain in the same Laravel deployment.
- **Known legacy seams, preserved:** selected Sales/Provisioning/Filtering/Trial application actions import Telegram notification classes; Filtering directly reads Provisioning's Remnawave Infrastructure; some Telegram UI and console commands read business tables. These are **tracked coupling**, not a reason to rewrite tested payments, monitoring or provider operations without a separate approval.
- **New regression checks:** `tests/Architecture/ModuleBoundariesTest.php` preserves the existing boundary rules and pins the known Telegram and Filtering provider imports to exact files/classes. Removing an exception is allowed; adding another requires an explicit review. This import-oriented guard does **not** prove all runtime/SQL boundaries or live behavior.

This section is a source-level snapshot, not an authorization for refactoring, migration, deployment changes or live Provider writes.

## Direction of dependencies
- **Presentation:** HTTP controllers, Livewire and Telegram adapters (and any future API adapter). Calls application use cases.
- **Application:** actions/use cases and DTOs; coordinates domain behavior.
- **Domain:** business concepts and rules, avoiding framework/UI concerns where practical.
- **Infrastructure:** Eloquent, external service clients, queues, and other technical adapters.

These are *design rules*, not an invitation to create empty folders or abstract interfaces for hypothetical features.

## Minimal layout (current)

```text
app/
  Modules/
    Identity/ Billing/ Sales/ Provisioning/
    Filtering/ Referral/ Onboarding/ Trial/ Administration/
  Interfaces/
    Telegram/  # Admin Bot + Sales Bot presentation and runtime
    Web/       # Livewire Mini App presentation
  Settings/
  Support/
bootstrap/providers.php  # explicit providers where needed
database/               # one shared migration entrypoint
tests/                  # feature and architecture regression
docs/
```

Within a module, introduce `Domain/`, `Application/`, `Infrastructure/`, and `Presentation/` folders **as real code requires them**. Do not invent a framework for discovering modules. Each module uses `App\\Modules\\...` via existing `App\\` Composer PSR-4 autoloading.

## Integration rules
Avoid reaching into another module's persistence, internal model, or domain implementation. Expose carefully scoped application contracts when genuine cross-module use cases appear. Do not build an event bus solely to couple empty modules.

## Runtime reality (updated 2026-11-09)
Production is reported by the operator as MySQL-backed with Redis-backed runtime services; queue/cache/database drivers are environment-dependent. CI uses isolated test services. This architecture review does not independently verify current Production health or deployment configuration.

## Identity implementation (F3)
Identity owns its Eloquent User model and session-authenticated web endpoints. Authentication, authorization and administrative user-management actions remain within Identity; Administration stays UI-only until a real composition requirement arises. No separate token API or public signup exists. See [Identity ADR](adr/0003-identity-foundation.md).

## Telegram-first adapter (F4)
`app/Interfaces/Telegram` owns transport handlers, bot presentation, webhook receipts and operational commands. Nutgram parses Telegram updates and sends replies. It calls Identity **Application actions** (`ResolveTelegramActor`, `IssueTelegramLinkCode`, `LinkTelegramAccount`), not Identity persistence models. Identity owns `telegram_accounts` and `telegram_link_tokens`.

`/api/telegram/webhook` is a stateless endpoint using a constant-time check of `X-Telegram-Bot-Api-Secret-Token` against a separately configured secret. Nutgram vendor safe_mode is intentionally disabled because the project controller validates that token first; use **only** the project `telegram:webhook:set` command to register the matching token.

Webhook receipts use a database unique update ID, 90-second processing lease, processed marker and retry on failure. This reduces duplicate handling but cannot provide exactly-once delivery of outbound Telegram messages. Simple commands run synchronously; time-consuming operations require durable queue design later.

The Livewire UI can call the same Application Actions directly; REST is not mandatory for an in-process presenter. See [ADR 0004](adr/0004-telegram-first.md).

## Historical open decisions (foundation checkpoint)
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

## Historical integration seam (F10-E and F11-A1 checkpoint)

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
