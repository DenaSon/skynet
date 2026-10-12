# SkyNetwork — Current Context / Development Memory

**Reviewed:** 2026-10-12  
**Source of truth:** `master` code, migrations, tests, and GitHub PR/CI status—not this narrative when they disagree.  
**Checkpoint:** F10-A/B/C/D merged; latest F10-D merge PR [#23](https://github.com/DenaSon/skynet/pull/23), commit `ec43b945138af3711db03bf418d0f382495e4a97`.  
**Verified at checkpoint:** [master CI run 38213468652](https://github.com/DenaSon/skynet/actions/runs/38213468652), PHP 8.4 + 8.5 green, 200 Pest tests / 959 assertions, PHPStan and Pint green. **No live Remnawave, bank or BotFather E2E is thereby implied.**  
**CURRENT INCREMENT:** [PR #26 — F10-E1](https://github.com/DenaSon/skynet/pull/26), local owner-scoped order/Subscription bridge and safe query DTOs. **NEXT:** [Issue #24](https://github.com/DenaSon/skynet/issues/24), F10-E2 actor-authenticated shared read actions and then F10-E3 Telegram presenter. Check GitHub for PR #26 merge/CI status. There were no open PRs immediately after PR #23 merged.

> **Handoff rule:** Read this file, `AGENTS.md`, `docs/002-ARCHITECTURE.md`, `docs/003-MODULES.md`, `docs/005-ROADMAP.md`, and the relevant feature runbook **before editing**. Verify current code and branches; this checkpoint may become stale. Never assume previous chat context is accurate without checking GitHub.

## 1. Product and chosen architecture

- **SkyNetwork** is a clean Laravel successor to the Python NVL project, **not a port** of the old bot. Telegram is the first presenter; future web UI is intended to use **Livewire 4 + Mary UI** over shared Application actions. Public REST is optional, not a prerequisite.
- **Laravel 13**, **PHP >=8.4.1**, modular monolith, explicit module providers, one application/deployment and primary DB; Pest 5, Larastan 3/PHPStan and Laravel Pint. CI matrix: PHP **8.4 and 8.5**.
- Modules own business rules. `app/Interfaces/Telegram` and eventual Livewire presenters are **thin adapters**. Do not call provider infrastructure or cross-module Eloquent models directly from a presenter.
- **Remnawave** is the selected external subscription **panel/provider API** (OpenAPI **v3.4.4** pinned). This is **not** a decision that SkyNetwork's own network layer uses a particular SSH/WireGuard/Core/Edge implementation; those integration designs are separate/deferred.
- Default local environment: SQLite plus Laravel database cache/sessions/queue. A production MySQL/Redis architecture has **not been validated** in this repository. Long-running purchase/provisioning orchestration through durable queues/outbox has **not been built**.

## 2. Implemented and merged on `master`

| Increment | What actually exists | Still not implied |
| --- | --- | --- |
| F0–F3 | Laravel bootstrap, module conventions, tooling; Identity login/session/CSRF, account and restricted admin users | Complete admin web UI |
| F4–F06 | Nutgram Telegram webhook + one-time account link, Identity dynamic RBAC, private user search/status and root-only role UI | Live BotFather E2E or public API |
| F07 | Opt-in Telegram signup and immutable referral attribution, `/invite` | Rewards/commission |
| F08 | Reseller-only wallets, manual transfer claims, trusted Super Admin approval; double-entry integer-rial ledger | Bank verification automation, service debits, purchase settlement |
| F09-A | Product catalog and duration/GB pricing; live quote Actions, 5% reseller discount | Provider provisioning |
| F09-B/C | Telegram `/buy`, volume/price previews, single owner-bound `pending_payment` order, `/orders`; immutable price snapshot + order idempotency | A paid order, balance debit or activated subscription |
| F10-A — [#19](https://github.com/DenaSon/skynet/pull/19) | `RemnawaveOperationCatalog` maps **218 operations / 162 paths**, generic `RemnawaveApiClient` and HTTP fake tests | 218 high-level typed service methods or live compatibility |
| F10-B — [#20](https://github.com/DenaSon/skynet/pull/20) | `RemnawaveReadApi`, configured factory, health/inventory: nodes, hosts, internal squads, profiles; `php artisan remnawave:inspect [--nodes]` | Live panel tested |
| F10-C — [#21](https://github.com/DenaSon/skynet/pull/21) | `RemnawaveNodesApi` for **15 NodesController operations**, internal node telemetry | Authorized node management UI |
| F10-D — [#23](https://github.com/DenaSon/skynet/pull/23) | Typed `RemnawaveUsersApi`, `RemnawaveSubscriptionsApi`, validated create DTO, subscription/user projections, SQL-reserved lifecycle with read-only reconciliation | Bot/Web user-facing subscription API, payments, live writes |

**Crucial distinction:** Remnawave **route coverage is complete for pinned v3.4.4**, but **Application API for customer/reseller/admin workflows is NOT complete**. Provider wrappers live in Infrastructure and are not permission checks.

## 3. Canonical code map

| Concern | Current entry point / boundary |
| --- | --- |
| Identity and roles | `app/Modules/Identity/Application/` — `ResolveTelegramActor`, `ResolveSalesBuyer`, `BrowseUsers`, `ChangeUserStatus`, Super Admin/RBAC Actions |
| Telegram presentation | `app/Interfaces/Telegram/` — webhook, callback handlers, `ShopUi`, confirmation/support classes |
| Catalog/order read/write | `app/Modules/Sales/Application/` — `ListProducts`, `CalculateQuote`, `CreatePendingOrder`, `OrderReader`; DB `sales_orders` |
| Wallet and manual top-ups | `app/Modules/Billing/Application/` — `ViewWallet`, `RequestTopup`, `ReviewTopup` and ledger-backed records |
| Remnawave transport & DTOs | `app/Modules/Provisioning/Infrastructure/Remnawave/` — API catalog/client/factory, discovery, nodes/telemetry, Users/Subscriptions |
| Durable provider operations | `app/Modules/Provisioning/Application/RemnawaveSubscriptionLifecycle.php` |
| Provider operation storage | `database/migrations/2026_10_12_000003_create_provisioning_subscriptions.php` defines `provisioning_subscriptions`, `provisioning_operations` |
| App registration | `bootstrap/providers.php`, `config/services.php`, `routes/console.php` |
| Live diagnostic | `php artisan remnawave:inspect` (requires privately configured TLS panel/token; **not run against a real panel yet**) |

### F10-D lifecycle, precisely

- `create(reference, idempotencyKey, expireAt, trafficLimitBytes, internalSquadUuids)` reserves a **unique local subscription reference** and operation key before one external write. Remote username is deterministic from the reference; description contains a stable hashed ownership marker.
- `setExpiration` and `setTrafficLimit` use **absolute** provider fields; `enable`/`disable` change status. No automatic retry of writes. A timeout/invalid response creates `needs_review`, **not proof of failure**.
- `reconcileCreate` and `reconcileUpdate` issue **read-only** checks and verify expected state before completing. Creation also checks the **exact Internal Squad UUID set**; associative squad arrays and missing/wrong returned assignments fail closed.
- `subscription(reference)` looks up a local linked active subscription and fetches a credential-bearing URL. **It does not authenticate a customer or establish ownership of the reference.** This action must only be called through a future actor-authorized Application boundary.
- `RemnawaveUsersApi` also contains potentially destructive primitives (delete, revoke, reset, additive extend). They are **internal capabilities**, not approved business actions.

### F10-E1 — Ownership read-model (PR #26; inspect actual merge status)

- New `provisioning_order_links` **bridge table** contains unique `sales_order_id → provisioning_subscription_id` foreign keys. It deliberately has **no public/checkout writer**; F11 settlement/fulfillment will need a separately reviewed atomic binding action. The existing `provisioning_subscriptions.reference` and provider IDs never become a customer identity claim.
- `Sales/Application/OrderReader::page()` is a 1–50 item, keyset-paginated **owner-scoped** read backed by `ResolveSalesBuyer` active-account checks. The existing `recent()`/Telegram `/orders` contract remains unchanged.
- `Provisioning/Application/SubscriptionOwnershipQuery` composes only **Sales Application DTOs** with a single batched lookup of local Provisioning mapping rows. Its immutable `SubscriptionSummary`, `SubscriptionDetails`, `SubscriptionCursorPage` contain order snapshots and normalized `pending_payment/provisioning/needs_review/active/failed` statuses, **never links, usernames, tokens, references or remote numeric IDs**.
- `pending_payment` orders cannot be mapped as activated: unexpected link on an unpaid order throws. Status simulations of `paid/fulfilled` in tests **do not** implement any Sales/Billing settlement transition. Only after F11 verified payment and a trusted link can `active` be projected.
- These methods are **internal** contracts, not customer-facing authentication. F10-E2 must resolve the current Telegram/web actor and check the order owner **inside** a UI-ready Application use case; never expose `SubscriptionOwnershipQuery` directly to an untrusted handler.
- HTTP fakes verify no provider access. **F10-E1 may be CI verified in PR but is not live-panel-tested.** Do not infer any completed purchase activation.

## 4. Strict safety/financial boundaries

1. **Default-deny:** `REMNAWAVE_WRITES_ENABLED=false`, `BILLING_TOPUPS_ENABLED=false`, `TELEGRAM_PUBLIC_SIGNUP_ENABLED=false` unless separately enabled after required tests/approval. Even when the Remnawave lifecycle flag is true, **no external checkout or Telegram entry point is wired to invoke it**.
2. **Payment truth:** `sales_orders.status=pending_payment` is **not paid**. Manually approved **top-ups credit a reseller wallet**, not a purchase. No wallet debit, paid transition, outbox dispatcher or auto-activation exists.
3. **Owner truth:** Telegram usernames, provider usernames/IDs, subscription URLs and callback payloads are not authoritative ownership. Use **internal Identity user ID + Sales order owner** with fresh server-side checks.
4. **Sensitive data:** Remnawave token, panel credentials, subscription URLs, Xray/password config, bank details and Telegram secrets do not enter public source, audit logs, ordinary directory/list DTOs or UI callback data. Subscription URLs behave like bearer credentials.
5. **Elevated actions:** protected `super-admin` requires active DB role, linked Telegram ID and environment allowlist; ordinary admins cannot bypass. Mutating administrative actions need permissions, actor-bound one-use confirmation and audit, not just a visible UI button.
6. **Idempotency:** preserve database-unique order keys and provider operation keys. Never confuse an HTTP timeout with failed remote provisioning. Re-fetch/reconcile before a second attempt; do not automatically retry additive operations.
7. **Feature tests ≠ E2E:** GitHub Actions uses fake HTTP/Telegram and local DB. No real panel version/token-scope behavior, end-user BotFather flow, bank verification, network reachability or production deployment has been certified.

## 5. NEXT implementation — F10-E (open [Issue #24](https://github.com/DenaSon/skynet/issues/24))

**Start with F10-E1, not another raw SDK mapping.** Work on a topic branch + small PR; do not introduce remote writes as a side effect of a read page.

1. **F10-E1 — Ownership contract/data model.** Define immutable `Sales order ID → provisioning reference` link and ownership from Sales/Identity Application boundaries, bounded query/read DTOs and status mapping. Do **not** infer paid from `pending_payment`. Avoid raw provider IDs in public owner queries. Include safe migrations only if truly needed.
2. **F10-E2 — Authorized reusable reads.** Build `ListMySubscriptions`, `GetMySubscription`, `GetSubscriptionLink`, `ListAccessibleNodes` (names provisional) using a validated internal actor, **current** role and exact order ownership. Link retrieval separate from summary. No raw provider payloads to UI.
3. **F10-E3 — Thin Telegram presenter + tests.** Private-chat commands/callbacks over those Actions; no duplicated rules in Web/Telegram; test forged callbacks, reseller isolation, disabled actors, stale state, error/redaction and provider GET failures with fakes. Web Livewire 4/Mary UI can later reuse these Actions.
4. **F11-A/B/C later:** separate trusted settlement/wallet debit + paid-order transition + durable outbox; plan/squad mapping and controlled provisioning; privileged audited management and **isolated staging panel E2E** before any real writes.

**Acceptance for F10-E:** PHP 8.4/8.5 Pest/PHPStan/Pint green; no new direct Remnawave imports in Telegram; no auto-debit/auto-create; current docs and runbook updated. Maintain isolated branches and reviewed PRs.

## 6. Developer bootstrap / verification

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pint --test
```

For Windows PowerShell, use `Copy-Item .env.example .env`. Optional frontend: `npm install && npm run build`. Credentials and operator policy are set in **untracked** deployment configuration only. Never commit private Composer mirrors. Current repo is **public**, so review every change and full history for secrets.

## 7. Authoritative pointers and update protocol

- Architecture: [002-ARCHITECTURE.md](002-ARCHITECTURE.md); module contracts: [003-MODULES.md](003-MODULES.md); project discipline: [004-STANDARDS.md](004-STANDARDS.md); plan: [005-ROADMAP.md](005-ROADMAP.md).
- Provider: [009-REMNAWAVE-API.md](009-REMNAWAVE-API.md), [010-REMNAWAVE-STAGING.md](010-REMNAWAVE-STAGING.md), [011-REMNAWAVE-NODES.md](011-REMNAWAVE-NODES.md), [012-REMNAWAVE-USER-LIFECYCLE.md](012-REMNAWAVE-USER-LIFECYCLE.md) and [012-REMNAWAVE-USERS.md](012-REMNAWAVE-USERS.md).
- Coding agents: root [AGENTS.md](../AGENTS.md) is canonical; [CLAUDE.md](../CLAUDE.md) points there.
- After each merged increment: **(a)** verify PR/CI and actual `master`, **(b)** change this Reviewed date + implemented/next/limits, **(c)** update Roadmap and relevant boundaries/runbook in the same PR, **(d)** explicitly record any unverified live behavior. Do not turn historic PR descriptions into current status.
