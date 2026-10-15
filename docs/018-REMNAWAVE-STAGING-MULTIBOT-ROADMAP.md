# SkyNetwork — Remnawave Staging → Multi-Bot & Admin Operations Roadmap

**Status:** APPROVED RESEQUENCED PLAN — 2026-10-15; MB-01 ACCEPTED ([PR #52](https://github.com/DenaSon/skynet/pull/52)), **MB-02 MERGED — [PR #53](https://github.com/DenaSon/skynet/pull/53), commit `213458f`, PHP 8.4/8.5 and disposable MySQL migration CI green; production migration/BotFather smoke pending**; MB-03 MERGED in [PR #55](https://github.com/DenaSon/skynet/pull/55), `e8a780b`, PHP/MySQL CI green, live smoke pending; MB-04 MERGED in [PR #56](https://github.com/DenaSon/skynet/pull/56) as `8da5e13` with PHP/MySQL CI green, live smoke pending; MB-05A MERGED in [PR #57](https://github.com/DenaSon/skynet/pull/57), `580c64a`, default OFF and real two-bot delivery pending; MB-05B OFFLINE source in [PR #58](https://github.com/DenaSon/skynet/pull/58), guarded read/Plan–Squad contract, live verification pending; MB-06 not started. Remnawave staging server unavailable.  
**Execution now:** MB-01 → MB-02 → MB-03 → MB-04 → MB-05A (notifications) + MB-05B (offline provider-read contracts) → MB-06A (offline/incremental validation). Optional narrowly justified RW-04 offline preparation may proceed independently.  
**Execution when staging becomes available:** RW-01 → RW-02 → RW-03 (with separate test-write approval) → RW-04 live verification → MB-05B live verification → MB-06B final acceptance. These are **workstreams inside the same original 10 phases**, not new Epics.  
**Planning baseline:** original roadmap authored against `bfac4a5`; dependency-only replan against `master` `8c83e28`. Inspect the latest `master` before implementation.  
**Owner decision:** Complete provider-independent Multi-Bot and dedicated Admin Bot work now, without waiting for a Remnawave VPS. Defer only live Remnawave checks and related acceptance; retain one Laravel modular monolith, shared business database and authorized Application Actions. **Sales Bot public UX and production sales remain deferred.**  
**Normative UX:** [017-TELEGRAM-UX-IDENTITY.md](017-TELEGRAM-UX-IDENTITY.md).

> **Execution rule:** This is a **scope and acceptance contract**, not an implementation claim. RW-01/RW-02/RW-03 are **BLOCKED by unavailable staging**; MB-01 is **ACCEPTED (documentation-only)**; MB-03 is merged (PR #55; live smoke pending), MB-04 is merged in PR #56 (live Telegram smoke pending), MB-05A is merged in PR #57 (code verified, live pending), MB-05B offline source is in PR #58 (CI/live gates separate), MB-06 remains **NOT STARTED**; MB-02 code is MERGED; production rollout verification remains pending. Document completed **code/offline** gates separately from **live** gates; a mock cannot prove panel compatibility. This docs-only change authorizes **no coding, server action, live write, payment activation or sales cutover**. Each phase starts only on a separate explicit implementation instruction.

## 0. Approved dependency replan — work without a Remnawave server

**Reason:** The operator cannot currently provision a Remnawave staging server. This must not block unrelated Telegram architecture, identity isolation, UX or queue work.

**MB-01 accepted design handoff:** [full route inventory](019-MB01-TELEGRAM-ROUTE-INVENTORY.md) and [ADR 0009](adr/0009-dual-telegram-bot-boundaries.md). **Next eligible on separate user instruction:** MB-03 admin/sales routing and permissions with legacy-user compatibility; MB-04 dedicated Admin UX; **MB-05A** durable notification routing/media bridge developed and tested with fake Telegram endpoints (optionally with two isolated test bots); **MB-05B offline only** read DTOs, authorized Application Actions, UI unavailable/error states and static Plan–Squad mapping guards based on known contracts; **MB-06A** offline regression/upgrade/rollback checks. Limited RW-04 offline fixes only when supported by a reproduced defect or approved small read-model need.

**Still gated by real Remnawave staging:** RW-01/02/03; RW-04 **final** adapter compatibility and actual User/Node/Squad lifecycle verification; **MB-05B live** provider data and mapping validation; **MB-06B final** end-to-end acceptance of the provider-connected Admin surfaces. No fake data should be presented as a live panel, and no operator writes or plan activation are enabled by preparatory code.

**Separate Telegram reality:** Mock tests can validate dispatch logic, but cross-bot delivery of a photo requires two real bot tokens and started chats; **a Remnawave panel is not a prerequisite for this test**. Without two test bots, keep media delivery/destination verification pending; it cannot be marked LIVE VERIFIED.

**Safe production increment rule:** Since `master` auto-deploys, provider-dependent menus must be hidden or visibly unavailable until a verified real panel is configured. Keep prior customer/reseller paths working until a safe Sales Bot replacement or approved legacy bridge exists; do not announce a complete Admin-only cutover earlier. Admin-only functions may be rolled out incrementally if backwards compatible. **MB-06A can validate/deploy the provider-independent admin foundation while MB-06B and Epic-level final acceptance remain pending.**

**Sequence diagram**

```text
NOW: MB-01 [accepted] -> MB-02 [merged; live smoke pending] -> MB-03 [next] -> MB-04 -> MB-05A / MB-05B-offline -> MB-06A
      [optional narrow RW-04 offline changes may be parallel]

LATER WITH SERVER: RW-01 -> RW-02 -> RW-03 -> RW-04-live
                                                |
                       MB-05B-live verification -> MB-06B final acceptance
```

## 1. Epic index and end-state

| Epic | Phase IDs | Business outcome | Required exit |
| --- | --- | --- | --- |
| **EPIC-RW — Remnawave Staging & Operational API** | RW-01–RW-04 | Real staging server, verified version-compatible API, tested core lifecycle, typed safe adapters and operator playbook | A reproducible **staging evidence package**; all essential functions live-verified or explicitly documented as blocked |
| **EPIC-MB — Multi-Bot Foundation & Admin Operations** | MB-01–MB-06 | Two separate Telegram bot identities and routers, a usable dedicated Admin Bot, safe event notifications, staged Remnawave admin read-model, and ready-but-disabled Sales Bot infrastructure | Admin E2E and isolation accepted; **no public sales rollout or unapproved provider writes** |

**Target topology:** one Laravel app/deployment and MySQL business database; `admin` and `sales` bot contexts each have their own Token, Webhook Secret, Router, update receipt scope, cache/form scope and recipient/notification context. Domain Actions remain shared. The existing bot becomes `admin`; the `sales` bot is provisioned as a technical integration target only in this Epic. No split into two Laravel apps.

**Do not conflate:** the Remnawave 3.4.4 **218-operation transport catalog** is not 218 hardened user-facing operations. The current specialized Nodes, Users, Subscriptions, Read and lifecycle adapters are not yet live-validated. The new F11 managed plans still have **no complete paid checkout → provisioning activation** and **Plan → Internal Squad** binding is not yet operational.

## 2. Scope boundaries (all phases)

### In scope
- Install an isolated, operator-controlled Remnawave test panel; optionally one isolated disposable test node; HTTPS, secrets, network controls and documented configuration.
- Exercise essential read endpoints and a **small explicitly approved staging-only** lifecycle with disposable data, compare response/behavior with pinned OpenAPI, harden typed DTO validation and reconciliation for those flows.
- Preserve and test all pre-existing Telegram administrative Actions, commands and legacy callbacks; add independently authorized Admin Bot UX and a non-public Sales Bot runtime boundary.
- Durable notification routing/observability for operator events; bot-specific Telegram identity **delivery** and safe media transfer; reuse existing Sales/Billing outbox primitives when appropriate.
- Implement a **minimal** provider-read management surface (health, inventory, node status, authorized user/subscription summaries, provisioning needs-review) and the gated Plan ↔ Internal Squad mapping contract if required for readiness.

### Explicitly out of scope
- Shipping or advertising a public Sales Bot, customer checkout redesign, financial settlement enablement, live customer provisioning or real subscription sales.
- Building all 218 API operations into typed adapters or Telegram buttons; general-purpose API explorer, web admin, SaaS dashboard, second backend, message broker, Kubernetes overhaul or unnecessary frameworks.
- Real production changes to quotas, restarts, node deletion, user deletion/revoke/reset, panel billing, payment flags or `REMNAWAVE_WRITES_ENABLED` without a later explicit approval and staging/production safety review.
- Secrets, API tokens, subscription URLs, unredacted IPs or raw client credentials in Git, Telegram admin lists, CI logs, review artifacts or screenshots.
- Destructive production migrations, database resets or forced immediate retirement of existing customer/reseller access.

**Change-control rule:** A request outside these boundaries is **not automatically added to the running phase**. Record as a follow-up issue or explicitly approve a scoped change. No "while we are here" feature expansion.

## 3. Evidence and quality gates

Every implementation PR must:
1. Declare phase ID, task subset, exact changed module boundaries, rollout/rollback and affected data; stay as small as possible (multiple PRs within one phase are fine).
2. Add Pest regression for positive, unauthorized, invalid, stale, concurrent/replay, failure/timeout and isolation cases relevant to that PR; pass **PHP 8.4 + 8.5**, PHPStan and Pint.
3. Update **this document's phase status and evidence links**, plus only relevant context/operations/ADR docs; never silently change `master` conventions.
4. Use additive/expand-before-contract MySQL changes, preserve the production records, and avoid resetting the existing provider and Sales state.
5. Treat a merge as an **automatic production deployment**. Changes introducing an unconfigured dependency must fail closed or have an OFF-by-default gate. Verify the post-merge deployment separately; GitHub CI is not proof of live E2E.
6. On external writes, keep `REMNAWAVE_WRITES_ENABLED=false` on **production**; staging-only writes require named disposable resources and an explicit operator go-ahead **per test batch**, with no auto-retry on ambiguous outcomes.
7. Treat real Telegram smoke, API live-check and delivery tests as separate evidence items; do not store private IDs, tokens or PII in results.

**Status vocabulary:** `NOT STARTED` → `IN PROGRESS` → `CODE COMPLETE` → `LIVE VERIFIED` → `ACCEPTED`; `BLOCKED` if a dependency cannot be fulfilled. A production-dependent phase cannot be `ACCEPTED` based on mocks alone. Link a specific GitHub PR and run plus a redacted live-test record.

## 4. EPIC-RW — Remnawave Staging & Operational API (server-dependent completion)

### RW-01 — Isolated Remnawave Staging Server

**Goal:** A controlled real API target without exposing SkyNetwork production subscriptions.

**Work packages**
- Operator selects a **separate staging VPS/panel**, confirms architecture/resource requirements and an available target release. Compare actual panel API compatibility to our **pinned v3.4.4** OpenAPI before upgrading the contract.
- Install and secure panel; enable TLS; protect admin access; restrict inbound access to trusted addresses or private network; configure backups, key rotation and bounded logs. No secrets committed to repository or sent in chat.
- Create **least-privilege read-only** API credential (where supported). Configure only trusted staging env `REMNAWAVE_PANEL_URL`, `REMNAWAVE_API_TOKEN`, `REMNAWAVE_TIMEOUT`. Production write flag remains OFF.
- Prepare the staging-only inventory (test Node, Host, Config Profile, Internal Squad) **only if required** by the later test cases; clearly identify all disposable resources and the cleanup plan.
- Record actual installed panel release/API version, staging access model, who owns keys, and rollback/reprovision method **without publishing private network endpoints**.

**Deliverables:** protected panel, operational checklist, version/credential scope record, test-fixture manifest with empty/disposable inventory acceptable at this step.

**Acceptance / go-no-go:** HTTPS and scoped authentication work; production endpoints/tokens are not reused; secrets protected; no customer resources; a clean install and rollback is documented. **Environment readiness ≠ API compatibility.**

**Responsibility:** server provisioning and external account credentials need operator action. GitHub cannot be used as evidence of VPS installation.

### RW-02 — Live Read-only Contract Verification

**Depends on:** RW-01.

**Work packages**
- Run the existing **`php artisan remnawave:inspect`** (and optional `--nodes`) against the isolated panel. Test health, nodes, hosts, internal squads and config profiles.
- Verify Users/Subscriptions **GET** methods, accessible-node/traffic projections and status/expiration normalization against redacted live fixtures where data exist.
- Capture only sanitized HTTP status, operation ID, schema shape, elapsed time and release/version; verify missing config, invalid token, 401/403, 404, 429/5xx, timeout, SSL and malformed response handling.
- Compare live envelope/field types and numeric user IDs with our strict validators. Record differences as scoped issues; avoid loosening DTOs globally to fit a single unexplained response.
- If resources are absent, distinguish **empty inventory verified** from **feature untested because fixture missing**.

**Deliverables:** per-endpoint **live compatibility matrix** (operation, adapter, status, evidence, discrepancy), redacted response fixtures, defect list.

**Acceptance / go-no-go:** all five required inventory/health read paths respond and normalize correctly; essential Users/Subscriptions paths pass when fixtures exist or are marked BLOCKED with a fixture plan; no leaked credentials, write calls, Sales changes or Telegram changes.

### RW-03 — Controlled Staging Lifecycle E2E

**Depends on:** RW-02 and explicit authorization for staging-only writes.

**Work packages**
- Validate one test node and Internal Squad membership if a functional subscription requires them; use **disposable user IDs and deterministic unique test references**.
- Exercise **create → read → absolute quota/UTC expiration update → read → enable/disable → read → subscription link → accessible nodes → cleanup** on test resources only. Destructive cleanup/revoke/reset requires a separate explicit confirmation and must not be bundled into automatic test retries.
- Exercise SQL `provisioning_operations` reservations, duplicate idempotency key, replay, concurrent request, response mismatch, partial failure and `needs_review` **without issuing a second remote write** on uncertain transport results.
- Test `reconcileCreate` and `reconcileUpdate` as read-only recovery. Check ownership marker and exact Internal Squad set, quota bytes, status/expiration semantics and subscription URL confidentiality.
- Test degraded network/429/5xx/invalid result in a controlled manner; do not manufacture destructive effects on shared infrastructure.

**Deliverables:** audited staging runbook, disposable fixture references, evidence matrix, cleaned-up or explicitly quarantined test resources and reconciliation records.

**Acceptance / go-no-go:** essential lifecycle paths behave as specified on a real panel; **uncertain writes never auto-retry**; no duplicate remote user or cross-user subscription exposure; secrets stay out of DB/logs/chat; failures are clearly actionable. If version incompatibilities remain, return to RW-02 and block RW-04 acceptance.

### RW-04 — API Client Hardening & Stable Provider Contract

**Depends on:** RW-03 **only for live compatibility sign-off**. A strictly bounded **offline preparation** slice can begin earlier: adapter DTO/validation/exception tests with HTTP fakes and targeted read-only Application contracts; no claims of real API interoperability.

**Work packages**
- Fix only observed contract defects and high-value missing typed adapters for the **Admin Bot's planned P0/P1 reads** (health, nodes, user/subscription summary, hosts, Internal Squads, Config Profiles, needs-review).
- Maintain layering: `RemnawaveApiClient` transport → typed read/write adapters → **authorized Provisioning Application Actions** → Telegram presentation. Do **not** expose arbitrary `operationId` from Telegram or create raw API passthroughs.
- Preserve idempotency/reconciliation, DTO bounds, pagination and status handling, exception redaction, fixed trusted panel config and feature-flag deny rules. Extend fake and live regression evidence.
- Validate compatibility with actual staging version; decide deliberately whether to pin or upgrade the API contract. **Do not upgrade on assumption.**
- Publish clear matrix: `LIVE VERIFIED`, `HTTP-FAKE ONLY`, `MAPPED ONLY`, `NOT REQUIRED`. All remaining 218 catalog methods do **not** need to be productized.

**Deliverables:** stable typed operations/fixtures, provider compatibility report, operator runbook, updated `docs/009`–`012`, tests, and documented safe enablement boundaries.

**Offline checkpoint:** scoped DTO/HTTP-fake cases, missing-config and fail-closed tests, safe read models, CI and redacted fixtures may become **CODE COMPLETE (offline)** without a server; do not mark RW-04 or Epic-RW as LIVE VERIFIED/ACCEPTED.

**Final acceptance / go-no-go (after RW-03):** essential Admin reads and controlled lifecycle have evidence-backed *real-panel* compatibility status, no critical unresolved mismatch, CI and **staging E2E** green, `REMNAWAVE_WRITES_ENABLED=false` on production. Only live provider-connected Admin UI and final MB-06B rely on this gate; **MB-01 through MB-04 and MB-05A do not**. A panel not supporting required API behavior is a BLOCKER, not permission to bypass validation.

## 5. EPIC-MB — Two Bots, one Backend, Admin-first (may start before RW)

### MB-01 — Inventory, Bot Boundaries & Migration Contract

**Depends on:** the approved architecture scope and existing `master` code only; no RW dependency. Reviewed MB-01 source handoff: [route inventory](019-MB01-TELEGRAM-ROUTE-INVENTORY.md) and [ADR 0009](adr/0009-dual-telegram-bot-boundaries.md).

**Work packages**
- Export an authoritative inventory of **all** existing Telegram commands, callback patterns, reply labels, text/photo handlers, permissions and output notifications from `routes/telegram.php` and presenters.
- Label each action as `ADMIN`, `SALES` or `SHARED`; map to existing authorized Application Actions and preserve historical callback reachability during transition.
- Specify **BotContext** (`admin` | `sales`), per-bot registry/config, authenticated webhook dispatch, recipient context, Telegram identity and contact-consent tracking.
- Decide what happens to existing **non-admin linked users on the current bot**: count/check them in a privacy-preserving read-only preflight. **Do not remove their only working UI before a safe alternative exists.** Define temporary legacy compatibility or an explicit user migration gate, and rollback plan.
- Define minimum Admin UI navigation from current working commands and the **P0/P1** Remnawave capability matrix, with no invented functional buttons.
- Define notification contracts `ReceiptSubmitted`, `PaymentReviewed`, `ProvisioningNeedsReview`, `SubscriptionStateChanged` and media transfer strategy.

**Deliverables:** command/permission inventory with before/after destination, BotContext ADR, endpoint/schema migration plan, rollout/rollback checklist, notification schemas.

**Acceptance:** 100% existing actions inventoried; each action has a target and permission; old chat/account risk resolved in writing; no runtime change.

### MB-02 — Dual Runtime, Webhook & Scoped Identity State

**Depends on:** MB-01.

**Work packages**
- Introduce trusted `admin` and `sales` bot registry entries, with independent tokens, secrets, webhook URLs and **explicit routers/handler registries**; no reliance on hiding buttons for security.
- Support `POST /api/telegram/admin/webhook`, future `POST /api/telegram/sales/webhook`; preserve legacy `POST /api/telegram/webhook` as alias for current bot **during migration**, with identical authenticated processing and replay protections. Default `sales` OFF.
- Expand `telegram_updates` from `update_id` primary key to collision-safe unique `(bot_key, update_id)`; backfill existing receipts as `admin` without reset, reconcile constraints transactionally and plan reverse compatibility.
- Namespace per-bot cache drafts/confirmation locks/update receipts; do not accidentally invalidate active admin forms on deploy without a user-safe expiry plan.
- Preserve `telegram_accounts` as common **Telegram user identity** mapping when possible. Store per-bot started/blocked/delivery eligibility **separately**; do not require creating duplicate SkyNetwork users or assuming one bot's chat permission applies to the other.
- Register/validate webhooks with bot-specific credentials only when explicitly configured, without dropping pending updates or exposing tokens.

**Deliverables:** two isolated testable runtimes, additive migrations, environment config template (no secrets), compatibility tests and a reversible rollout guide.

**Acceptance:** identical numerical `update_id` can be independently accepted from both bots; cross-bot secret/route/handler rejected; missing token fails closed; no duplicate business execution under retries; legacy webhook still functions; no production data loss or sales activation.

### MB-03 — Admin Router & Authorization Isolation

**Depends on:** MB-02.

**Work packages**
- Move product/plan/price management, users/RBAC, financial reviews, status operations and admin monitoring under the Admin Router. Keep **existing Application authorization**, trusted Super Admin allowlist and permission checks at every action boundary.
- Keep a minimal non-public Sales Router shell and contract tests, not customer UI/checkout. No admin handler may register in the Sales Router.
- Handle old commands, keyboards and callbacks on the existing Telegram chat through a documented migration bridge; decide cutover only after MB-01 non-admin dependency preflight.
- Rebind callback ownership to bot + actor + operation + TTL, preserving existing single-use, expiry, concurrent replay and stale-record guarantees.
- Ensure no bot-controlled values can select a provider credential, permission scope, `operationId` or arbitrary webhook destination.

**Deliverables:** separated route files/registry, static route ownership inventory, role matrix, backward-compatibility tests.

**Acceptance:** forged admin callback sent to Sales Bot cannot reach admin code; private chat only; revoked actor/role immediately denied; every preexisting admin Action remains reachable with unchanged business effect; no supported nonadmin access is silently broken.

### MB-04 — Dedicated Admin Telegram UX & Existing Operations

**Depends on:** MB-03.

**Work packages**
- Replace hybrid Admin home with a concise role-aware **Admin-only** home: `کاربران`, `نقش‌ها و دسترسی‌ها`, `محصولات و پلن‌ها`, `سفارش‌ها/رسیدها` (only if enabled), `سرویس‌ها` (only actual supported reads), `وضعیت سیستم` (only real reads). Avoid inert buttons and "coming soon" engineering text.
- Preserve guided forms, copy-to-clipboard native buttons, HTML escaping, multi-field power-user shortcut, edit-single-field, Back/Cancel, confirmation and all administrative functionality delivered through PRs #43–#48.
- For admin order/customer views, add only small **authorized read-model Actions** genuinely required for operator work; do not query Sales SQL directly from presenters. No live purchase or settlement changes.
- Enforce `docs/017-TELEGRAM-UX-IDENTITY.md`; do not put Remnawave, Laravel, F-phase names, operation IDs or internals in user-facing text.
- Verify keyboard cleanup/replacement for admins after deployment while preserving existing Telegram chats.

**Deliverables:** dedicated Admin home and connected submenus, UX regression tests, unchanged functional action inventory, updated UI screenshots/checklist (sanitized).

**Acceptance:** an authorized operator can perform every previously supported admin operation without navigating customer/reseller home; one-screen/one-decision flow and no technical copy; no business behavior regression.

### MB-05 — Durable Cross-bot Notifications & Remnawave Readiness

**Depends on:** MB-04 for **MB-05A notifications** and **MB-05B offline read-model/UX work**. **RW-04 live sign-off is required only for MB-05B live provider verification/enablement.** These are two bounded workstreams within MB-05, not a new platform.

**A — Realtime-enough notification workflow**
- Use existing Laravel database queue and transactional Sales/Billing outbox where available; create only narrowly scoped missing notification envelope/dispatcher, not Redis Streams, WebSockets, Kafka or a generic event platform.
- Flow: Sales Bot receives user photo/payment claim → persist authorized claim + outbox event in one transaction → worker routes to **Admin Bot** using admin token → authorized Admin callback reviews → result event routed through **Sales Bot** only when that customer has started the bot.
- **Bot-scoped `file_id` is not portable.** Implement a safe media bridge: fetch from source bot, bound content-type/size/time, encrypted or private temporary storage, transmit through destination bot as new upload, prevent secret-bearing media logs, retention cleanup. If media bridge is not ready, **do not claim images are delivered**; show a safe admin receipt listing and retry/manual recovery.
- Persist delivery status, retry/backoff, dead-letter/recovery and correlation (event ID, recipient, bot key), and avoid alert floods; network/Telegram ambiguity may yield duplicate notification messages, but **never duplicate bank settlement**.
- Define delivery SLO as a measured staging goal rather than an unconditional guarantee (e.g. **target p95 ≤ 10 seconds** from DB commit to successful send under healthy queue/network). Telegram availability and admin-initiated chat are external prerequisites. If admin bot is blocked, claim remains visible and recoverable via queue/UI.
- Test both directions with **two real non-public test bots** if Sales Bot credentials are available. A mock-only run cannot mark bot-to-bot delivery LIVE VERIFIED.

**B — Bounded Remnawave admin read management**
- **Before the server exists:** implement typed read models, narrowly scoped authorized Application Actions, tested negative/unavailable/error states and optionally hidden provider menu entry points. HTTP fakes are only test fixtures; do **not** show a fabricated server health, node, user or subscription as live.
- **After RW-04 live sign-off:** present actual server health, node connectivity/telemetry, host/config-profile/squad summaries, guarded user/subscription status and SQL `needs_review` safely via **read-only, authorized Application Actions**.
- Prepare Plan → Internal Squad binding contract with revision/status checks and fail-closed activation guard; only treat a mapping as **verified** if the real staging panel confirms its UUID and membership. Do not turn binding creation into real sales activation.
- Keep destructive/restart/quota/revoke/reset provider operations **outside this Epic** unless separately approved with full audited Application Actions, one-time confirmation and staging/production gate.

**Deliverables:** bounded dispatcher and receipt-media bridge, delivery/recovery runbook, typed Admin provider reads (guarded/offline), provider availability states and a split evidence matrix for MB-05A / MB-05B-offline / MB-05B-live.

**MB-05A acceptance:** durable, authorized and idempotent business transitions with at-least-once notifications, recoverable unsent claims, bounded retries and secure media. Real two-bot delivery/photo smoke remains a separate live requirement when two test bots exist; no claim of live delivery from mocks.

**MB-05B offline checkpoint:** provider reads and Plan–Squad guards are tested with fakes, fail closed when unconfigured, and no simulated values are surfaced as real. **MB-05B live acceptance:** only after RW-04 live checks validate the underlying panel responses and mappings. Production provider writes stay OFF throughout.

### MB-06 — System Regression, Cutover & Operational Acceptance

**Depends on:** MB-05A and MB-05B **offline** for MB-06A. **MB-06B final acceptance** additionally requires live MB-05B verification after RW-04 and actual two-bot delivery checks. A missing provider is **not** a reason to stop the offline regression checkpoint.

**Work packages**
- Re-run command/callback parity against MB-01 inventory; test Admin and Sales isolation, callback replay, role revocation, idempotency, cross-bot `update_id`, DB migration from existing data, queue failures, incomplete media transfer and recovery.
- Perform automated PHP 8.4/8.5 Pest/PHPStan/Pint and **separate** post-deploy Telegram/manual staging checks; verify real admin form/clipboard, payment-review gating and provider read widgets.
- Validate legacy nonadmin users' access paths from MB-01; **do not terminate the old mixed endpoint** until they have an approved migration path. The legacy endpoint may remain temporarily as a guarded compatibility alias if needed.
- Review production monitoring: webhook failure, queue depth, unsent notifications, rate limit, transport timeouts, `needs_review`, token rotation and no sensitive log leakage.
- Update docs, rollout/rollback and checklist with SHA/PR/CI evidence. Mark Sales Bot **technical shell only**; do not activate public onboarding, new checkout, bank settlement or real provisioning.

**Deliverables:** release verification matrix, final Admin runbook, migration/rollback evidence, architecture/UI docs and explicit future Sales Bot backlog.

**MB-06A acceptance (provider-independent):** every existing Admin Action passes parity, two-bot isolation and backward-compatibility tests are green, database/queue/retry rollback are verified, no real payments/provider writes, existing production records intact and post-deploy Admin Bot smoke recorded. A nonadmin still using the previous bot retains its supported flows. This validates **admin foundation only**.

**MB-06B final acceptance (provider-connected):** MB-06A + RW-04 live and MB-05B live verification + measured two-bot notification/photo smoke and recovery evidence. Only then mark full EPIC-MB accepted; the public Sales Bot remains a future Epic.

## 6. Current baseline: what already exists vs what is missing

| Capability | Current observed code | Contract for these Epics |
| --- | --- | --- |
| Remnawave transport | OpenAPI 3.4.4 catalog, **218** operations; server transport/client factory | Verify actual panel response/version; not blanket SDK completeness |
| Provider Read | `RemnawaveReadApi`, `remnawave:inspect`, Nodes telemetry | Offline read Actions/DTOs can be coded and mocked now, but live provider UI must fail closed until RW-04 real verification |
| Users & Lifecycle | typed Users/Subscriptions adapters, SQL reservation and reconciliation | Scoped staging create/update/failures; production writes OFF |
| Sales Plans | managed products/plans/pricing, Telegram wizard, quote only | Preserve existing; guarded Squad binding readiness, no paid checkout |
| Identity | one `telegram_accounts` mapping; single linked Telegram user ID | Keep shared user identity, add bot-specific interaction eligibility |
| Webhook | one webhook/secret/token and `telegram_updates.update_id` PK | Authenticated dual webhook and scoped `(bot_key,update_id)` |
| Telegram UI | mixed customer/admin Router + root workflows | Separate Admin and Sales registries, admin-first UX |
| Notifications | manual bank claim alerts use the current bot client; existing Billing/Sales outbox for money flows | Explicit destination bot, media bridge, durable retry/recovery |
| Payment and Provider flags | existing defaults OFF | Preserve OFF until separate later sign-off |
| Production behavior | merges to `master` automatically deploy | Every PR additive, guarded, deploy-aware; staging live tests before write permission |

## 7. Cross-epic dependency and stop rules

- RW-01/RW-02/RW-03 are currently BLOCKED by lack of an isolated staging server; this does **not** block MB-01–MB-04, MB-05A, MB-05B-offline or MB-06A. Supply eventual staging credentials only through secure deployment controls, never commits/chat. No simulated completion.
- RW-02 essential health/inventory incompatibility blocks RW-03. RW-03 ambiguous write/reconciliation failure blocks **RW-04 live sign-off**. RW-04 critical mismatch blocks **MB-05B live provider activation and MB-06B**, but not independent Multi-Bot isolation.
- MB-02 migration must be verified using a **copy/test fixture of the existing database structure**, not `migrate:fresh` in production. A failed cutover is rolled back without destroying historical update receipts.
- MB-03 must not break a current linked non-admin merely because public Sales Bot UX has been postponed. Verify actual usage via privacy-safe read-only queries.
- MB-05 live notification verification requires two started bots and valid token scopes. Bot-to-bot message delivery is through **Laravel and Telegram API**, not by Telegram bots messaging each other. Outbox gives **at-least-once attempts**, not a guarantee of delivery or exactly-once notification.
- Do not let **InfraBillingController** API operations silently become SkyNetwork wallet/ledger operations; they are different domains.
- Unavailable or malformed external provider state is shown as unavailable, never as a fabricated green/healthy state. Redaction before logging or audit.
- Production provider writes, money flags and public sales require **new** independent authorization outside this roadmap.

## 8. Future Epics (NOT part of this commitment)

- **EPIC-SB — Sales Bot Product Experience:** new public sales onboarding, role-aware member/reseller menus, managed-plan checkout/payment handoff, post-settlement provisioning/delivery and consumer notifications, real E2E before public launch.
- **EPIC-RO — Advanced Remnawave Operations:** audited and confirmed operator writes for node/user/squad/config changes, restart/revoke/reset/delete; only with necessary business need, staging proof and separate production approval.
- Optional provider migration, generalized alert platform, extra infrastructure orchestration or second UI surface require independent scope and decision.

## 9. Tracking template

At the start and end of each phase record:

| Phase | Status | PR(s) | CI | Live/staging evidence | Blockers | Rollback verified |
| --- | --- | --- | --- | --- | --- | --- |
| RW-01 | BLOCKED | — | — | — | Staging VPS unavailable; operator setup | — |
| RW-02 | BLOCKED | — | — | — | RW-01; no live panel | — |
| RW-03 | BLOCKED | — | — | — | RW-02 + explicit staging-write approval | — |
| RW-04 | NOT STARTED | — | — | — | Offline work eligible; final sign-off needs RW-03 | — |
| MB-01 | ACCEPTED — documentation-only | [#52](https://github.com/DenaSon/skynet/pull/52) | [PHP 8.4/8.5 green](https://github.com/DenaSon/skynet/actions/runs/38781856907); post-merge CI checked separately | 113/113 registrations + 18/18 labels matched code; no live test required for docs | Nonadmin Production usage preflight required in MB-02 | No runtime/schema changes |
| MB-02 | CODE COMPLETE — rollout verification pending | [#53](https://github.com/DenaSon/skynet/pull/53) | PHP 8.4/8.5 Pest/PHPStan/Pint green on PR; final master CI separate | SQLite legacy-row preservation, collisions, route secret and bot contacts; **Production MySQL/Telegram live pending** | Read-only Production DB/schema/nonadmin preflight; Sales OFF | Code-before-migration Admin fallback + guarded schema rollback |
| MB-03 | NOT STARTED | — | — | — | MB-02 + nonadmin compatibility contract | — |
| MB-04 | NOT STARTED | — | — | — | MB-03 | — |
| MB-05 | NOT STARTED | — | — | — | A: after MB-04; B-offline: after MB-04; B-live: needs RW-04. Real delivery needs two started test bots | — |
| MB-06 | NOT STARTED | — | — | — | A: after MB-05 offline; B final: RW-04 + MB-05B-live + two-bot smoke | — |

**Next implementation step after MB-02 review/acceptance:** **MB-03 — Router Isolation**, based on [the audited inventory](019-MB01-TELEGRAM-ROUTE-INVENTORY.md) and [ADR 0009](adr/0009-dual-telegram-bot-boundaries.md). MB-02 deliberately leaves all current Admin/Member/Reseller handlers on the original bot; the isolated Sales runtime contains no handlers and stays OFF.

## 10. References

- [MB-01 route/state/permission inventory](019-MB01-TELEGRAM-ROUTE-INVENTORY.md) and [ADR 0009 — dual-bot boundaries](adr/0009-dual-telegram-bot-boundaries.md)
- [Current development context](000-CONTEXT.md)
- [Existing roadmap](005-ROADMAP.md)
- [Telegram operations](006-TELEGRAM-OPERATIONS.md) and [normative UX identity](017-TELEGRAM-UX-IDENTITY.md)
- [Remnawave API mapping](009-REMNAWAVE-API.md), [read-only staging diagnostics](010-REMNAWAVE-STAGING.md), [node adapter](011-REMNAWAVE-NODES.md), [user lifecycle and reconciliation](012-REMNAWAVE-USER-LIFECYCLE.md)
- [Sales/Remnawave boundary](015-SALES-PLAN-CATALOG.md) and [plan pricing](016-SALES-PLAN-PRICING.md)
- [Modular monolith ADR](adr/0001-modular-monolith.md), [Telegram-first ADR](adr/0004-telegram-first.md), [provider ADR](adr/0007-remnawave-client.md)
