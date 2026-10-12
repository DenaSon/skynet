# SkyNetwork — Current Context / Development Memory

**Reviewed:** 2026-10-12  
**Source of truth:** `master` code, migrations, tests, and GitHub PR/CI status—not this narrative when they disagree.  
**Checkpoint:** F11-B1.2 managed pricing merged via [PR #39](https://github.com/DenaSon/skynet/pull/39), squash SHA `027c83c95a3d0bcf89f7ad8682c5a654d5c0e924`.  
**Verified at checkpoint:** [master CI #38245902790](https://github.com/DenaSon/skynet/actions/runs/38245902790) green with Pest, PHPStan/Larastan and Pint on PHP 8.4 and 8.5; no live bank/BotFather/Remnawave or production database concurrency verification.  
**CURRENT INCREMENT:** F11-B1.1 catalog and **F11-B1.2 root-managed read-only pricing merged**. Sales owns one draft-first versioned tariff per plan, fixed or formula (base + GB + days), partner discount and min floor, hashed single-use root confirmation/audit and buyer/revision-bound immutable quote with revalidation. F09 legacy checkout remains unchanged; NEW PLANS CANNOT BE PURCHASED without B1.3 binding and explicit guarded order Action. Billing/Remnawave flags remain OFF. **NEXT: F11-B1.3 plan → Remnawave Internal Squad binding**, then guarded checkout and Telegram UX.

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

### F10-E1 — Ownership read-model (merged PR #26)

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

### F10-E2 — Authorized shared subscription read actions (PR #27, merged)

- Identity `SubscriptionViewer::telegram(verifiedTelegramId)` and `::web()` are **request contexts, not authentication tokens**. `AuthorizeSubscriptionViewer` re-resolves the linked, active Telegram actor or authenticated Laravel session on **every** action; it rejects disabled/unlinked principals. **Never build the Telegram context from an unverified callback field**; F10-E3 must supply the webhook-authenticated Telegram user ID.
- `ListMySubscriptions` and `GetMySubscription` are presentation-neutral and call existing F10-E1 owner-scoped **local** DTOs; no Remnawave HTTP calls.
- `GetSubscriptionLink` is the **sole dedicated credential action**. `ActiveSubscriptionAccess` requires exact paid/fulfilled owner order + active one-to-one link, verified remote user marker/ID, ACTIVE status, not-expired and unexhausted quota. It rechecks actor/order/link after the provider GET before returning `SubscriptionConnectionDetails` containing the URL. No URLs appear in list/detail or audit payloads.
- `ListAccessibleNodes` reads the pinned Remnawave `GET /api/users/{userId}/accessible-nodes` only for a verified, eligible subscriber and validates the matching provider ID; returns only `name` and `countryCode` (max 200 nodes); no IP/UUID/inbound/raw provider JSON leaks.
- Provider HTTP errors and invalid envelopes fail closed with a generic public-safe error, no provider exception chaining. Tests use HTTP fakes for 401/403/429/5xx, mismatched identity, node schema and post-GET revocation. **F10-E3 Telegram presentation routes are merged via #28; production deployment, BotFather and Remnawave live E2E are still unverified.** No provider writes, no real staging E2E and no automatic purchase settlement.


### F10-E3 — Telegram presenter (PR #28 merged; master CI green)

- \`routes/telegram.php\` exposes \`/subscriptions\` plus private inline callbacks for keyset-paginated listings, owned details, explicit link retrieval and accessible-node names. The home menu uses \`📦 سرویس‌های من\`; the old \`/orders\` command and callback remain supported.
- \`app/Interfaces/Telegram/Support/SubscriptionUi.php\` consumes only F10-E2 shared actions. Telegram user identity is derived only from the private verified update, never from callback data. No provider transport/model access and no new business writes.
- Pending-payment Sales orders are displayed honestly as unpaid; local \`active\` means last recorded state, not live provider attestation. Link and nodes invoke separate Application checks on every click. Credentials never appear in list/detail/callback data; explicit link response disables previews.
- UI is intentionally compact: six entries per page, status labels, back/home navigation, no new message for ordinary callbacks, useful empty/error states. Cursor/id parameters are strictly validated; old/forged callbacks are reauthorized.
- Pest fake transport covers empty states, stale/forged/private-chat access, owner-scoped paging, provider errors, read-only behavior and credential redaction. This is **not** BotFather/Remnawave live E2E.
- PR #28 final CI [38223740272](https://github.com/DenaSon/skynet/actions/runs/38223740272): PHP 8.4/8.5 Pest, PHPStan and Pint green. Squash merged as `3dd9e61974478f35c0a776855a5abe1f3836f6a8`; [master CI 38223868886](https://github.com/DenaSon/skynet/actions/runs/38223868886) green. **No production release or real BotFather/Remnawave E2E has been verified.**

### F11-A1 — PR #30 merged; master CI green; feature disabled

- An internal, verified-actor reseller-only `SettleWalletOrder` coordinates a ledger-backed debit (`Billing/Application/DebitWalletForOrder`), one immutable settlement, conditional Sales `paid` transition, and exactly one pending outbox intent in one database transaction.
- `SALES_WALLET_SETTLEMENT_ENABLED=false` by default; no Telegram/HTTP integration and no outbox consumer. No Remnawave write is reachable.
- Ledger purchase journal transfers prepaid wallet liability to an unfulfilled-order liability, not immediate revenue. Retry is bound to the exact original wallet journal/amount/order and fail-closed on inconsistent state.
- Review tests, CI and exact PR merge before calling this released. Real bank evidence, MySQL concurrent workers, provider and BotFather E2E remain unverified. See `docs/013-F11-WALLET-SETTLEMENT.md`.

### F11-A2 — Manual direct-transfer review (PR #33 merged; OFF by default)

- `ManualBankPayments` and `ManualPaymentUi` connect a pending customer order to a Telegram photo receipt, notify eligible Super Admins and let them review by private inline keyboards. Photo file IDs are encrypted, state is persisted, and an image never automatically settles an order.
- The final approval is a separate actor-bound, 3-minute one-use decision, after the Super Admin manually enters a BANK-statement-verified reference; a unique F08/F11 claim prevents payment reference reuse. Approval atomically records balanced direct-bank postings, transitions `pending_payment -> paid`, and inserts one pending fulfillment outbox event. Reject is audited and keeps order unpaid.
- OFF by default via `SALES_MANUAL_BANK_REVIEW_ENABLED=false`. Do not enable until bank matching SOP, database locking/concurrency, operator procedures and real BotFather/bank E2E are verified. F11-B provider dispatch does not exist. CI is green on merged master, but real Telegram/bank/DB concurrency tests remain pending. See `docs/014-F11-BANK-REVIEW.md`.

### F11-B0 — Unified Billing ledger and clean financial boundaries

- **Pre-production:** there is no user/financial production data. Original create-table migrations were cleaned up for fresh databases; this is not a live in-place schema upgrade. `billing_journals.wallet_id` becomes nullable for direct bank sales. F08, F11-A1 and F11-A2 use one shared `billing_journals` + `billing_postings` ledger; `sales_bank_postings` is removed from the baseline migration. Direct Sales settlement points to its unique Billing journal ID.
- `ClaimVerifiedBankReference` owns unique F08/F11 verified bank reference claiming. `RecordDirectBankPayment` posts direct bank cash-clearing debit and order-liability credit. `VerifyWalletOrderDebit` audits replay of an existing wallet purchase. **Sales does not access raw Billing tables.** Super Admin and ownership checks remain intact and the entire settlement/order/outbox/audit remains atomic.
- Architecture + regression tests cover source dependencies, raw Billing SQL within Sales, duplicate receipts, balanced journals and rollback on failed outbox. See ADR 0008. **F11-B1 is next**; no live bank confirmation, real DB concurrency, outbox consumer or Remnawave activation yet. F11-B0 was merged as PR #35, SHA `8e04572327f27d2f9c7b5dc3f280662e53135f5f`; [master CI 38238151791](https://github.com/DenaSon/skynet/actions/runs/38238151791) green.

### F11-B1.1 — Dynamic plan catalog foundation (merged PR #37)

Sales adds plan definitions, draft/published/inactive/archived lifecycle, fixed presets, reseller builders, bounds/audiences, audited optimistic revisions and short-lived single-use root confirmations. `Identity/Application/AssertCatalogManagementAccess` performs fresh allowlisted Super Admin verification. `ListVisiblePlans` respects buyer roles, status and parent-product availability. F09 quotes/orders ignore plans; price computation, Internal Squad binding, Telegram management and real provisioning are explicitly NOT implemented here. Read `docs/015-SALES-PLAN-CATALOG.md`. Merged and CI-verified in PR #37; next B1.2 pricing, B1.3 binding.

### F11-B1.2 — Managed plan pricing (merged PR #39)

- Sales-only `sales_plan_prices` is one tariff rule per plan: `fixed` presets or `formula` for presets/builders; integer-rial base, GB and day components, configured reseller percent (0–50), bounded max and minimum total; dynamic time-unlimited uses NULL GB with independent day price. No speculative prices seeded.
- Root-only `ManagePlanPricing` with monotonic plan/price revision, draft-on-edit, privileged single-use publish/unpublish and immutable audit. `CalculatePlanQuote` freshly checks buyer/role, exact preset selection or builder bounds, active plan/price and matching `configured_plan_revision`; `RevalidatePlanQuote` enforces buyer-bound full immutable quote equality before any future checkout.
- B1.2 produces **read-only price quotes, not Sales orders**: no new `sales_orders` rows from the B1.2 API; no provider binding, outbox dispatcher, Nutgram plan purchase/management UI, real payment, or remote write. Existing F09 quote/order flow untouched. Next **B1.3 provider mapping**, then explicit authorized plan checkout and UX. Read `docs/016-SALES-PLAN-PRICING.md`. Merged as PR #39; master CI 38245902790 green on PHP 8.4/8.5.

### Repository branch cleanup (2026-10-12)

GitHub still retained old feature/docs branches after squash merges, even though all approved PRs were merged into `master`. The `prune-merged-branches` workflow is intended to remove only branch refs with merged same-repo PRs and matching PR-head SHA (plus one pinned, PR32-reconciled documentation exception), never `master` or unmerged PR #17. Check the first post-merge Actions execution and current branch inventory before saying deletion succeeded. This administrative workflow changes no SkyNetwork runtime or payment/provider flag.

### Deploy blocker: MySQL index Error 1071 on first migration (2026-10-12)

The Laravel 13 initial `users.email` unique index is a 255-character `utf8mb4` key (up to 1020 bytes). The deployment server reported `max key length is 1000 bytes`, strongly suggesting a MyISAM/Aria table engine rather than InnoDB. Previously, MySQL and MariaDB connection `engine` was `null`, silently inheriting the server default. Both database connections now explicitly specify `'engine' => 'InnoDB'` in `config/database.php`, preserving required transactional/FK semantics without shrinking indexes or reducing charset coverage. This only affects **newly created tables**; it does not convert existing tables or change database-server defaults. A failed MySQL DDL migration may leave an unrecorded, incomplete `users` table: confirm table inventory, engine and row counts before any targeted cleanup. Never run `migrate:fresh` or drop a nonempty table on production. Re-deploy code, clear/rebuild config cache, restore/clean a verified disposable partial schema, then `php artisan migrate --force`. Real MySQL integration/concurrency tests remain outstanding.
