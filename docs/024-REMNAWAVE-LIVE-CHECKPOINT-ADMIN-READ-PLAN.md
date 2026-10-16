# ParsaGate — Remnawave Live Checkpoint & Admin Server Overview Plan

**Date:** 2026-10-16  
**Decision:** Planning approved by operator; **implementation NOT started**.  
**Authoritative execution scope:** [018](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md), [022](022-MB05B-OFFLINE-READINESS.md), [Telegram UX 017](017-TELEGRAM-UX-IDENTITY.md).  
**Source reviewed:** `master` at `32d747271151bce9902df38f8e5e41d081bc48cd`; live observations below are **operator-reported terminal/Telegram evidence**, not CI proof or independent server access.

## A. Real environment and evidence baseline (2026-10-16)

| Phase / subsystem | Observed result | Remaining gate |
| --- | --- | --- |
| **EPIC-RW / RW-01** isolated staging | Operator installed Remnawave **3.4.4** on a separate VPS with PostgreSQL, Valkey and Caddy/HTTPS; health probe and container health reported successful; domain `rw.coreflare.ir` is DNS-only; panel browser login succeeded; Caddy IP allowlist restricts operator UI and Runflare outbound addresses to **GET/HEAD `/api/*`** | Backup/restore proof and credential-scope audit not independently recorded; do not call RW-01 formally accepted |
| **EPIC-RW / RW-02** live reads | `php artisan remnawave:inspect` succeeded on Runflare: runtime instances **3**, nodes **0**, hosts **0**, internal squads **1**, config profiles **1**. Users/Subscriptions GET pagination and envelopes succeeded; real user ID, status, quota, expiry and squads passed; detail, accessible-nodes and protected subscription GET passed; no-token/invalid-token 401 and missing-user 404 observed | Full negative/timeout/429/5xx/SSL-malformed response matrix, redacted fixtures and formal RW-02 sign-off incomplete |
| **EPIC-RW / RW-03** controlled staging writes | One disposable user `pg_rw03_create_01`: CREATE 201 + GET verified; absolute quota PATCH 200 (1 → 2 GiB), UTC expiry PATCH 200 (14-day target), disable POST 200 and enable POST 200; GET verified identity, status, squads and unchanged fields. Existing panel user was not targeted | Laravel Application Action idempotency/reconciliation and production-DB-safe staging run not live-proven; no Node/Host; no V2Ray connectivity E2E; cleanup not authorized; phase **PARTIALLY LIVE VERIFIED**, not closed |
| **Runflare typed read batch** | **17 PASS, 1 FAIL, 0 BLOCKED**; failed case named `Subscription Ownership`, based on an unexpected check result. This is **not evidence of data leakage** and is **not proof of owner-isolation correctness** | Analyze actual redacted user-ID/type/response shape and test assumption before exposing any provider-backed user/subscription detail in Telegram |
| **Offline Laravel tests** | Operator-run batch showed **51 warnings / 239 assertions**, reported exit 0, primarily due to absent `/var/www/.env` inside Runflare container | Do **not** treat as clean isolated MySQL/SQLite acceptance or live proof; re-run safely in dedicated test/CI environment as needed |
| **Production safety** | Final read-only batch verified `REMNAWAVE_WRITES_ENABLED=false`; no real writes or DB changes were sent by that batch | Keep writes OFF. `REMNAWAVE_ADMIN_READS_VERIFIED` and `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED` stay OFF until their distinct explicit gates |

**Evidence limitations:** No actual Node exists; zero Nodes/Hosts is a valid **empty inventory** result, not an outage. Health/runtime-instance count **is not** VPS CPU/RAM/disk or VPN reachability. No raw tokens, usernames beyond the named disposable staging fixture, subscription URLs, node IPs, or private response bodies belong in logs or Telegram.

## B. Epic inventory / sequencing

| Epic | Scope now | State / next dependency |
| --- | --- | --- |
| **EPIC-RW — RW-01–04** | Record actual staging compatibility; target only the existing five admin-read endpoints for the next increment; fix **observed** adapter incompatibilities only | RW-01/02 live checkpoints recorded; RW-03 partial; RW-04 **narrow P0 read acceptance** is a prerequisite for MB-05B-live, while **full RW-04 lifecycle acceptance remains pending** |
| **EPIC-MB — MB-05B-live Admin Operations** | **NEXT APPROVED PLANNING TARGET:** existing Super Admin `/servers` live read overview, refresh/back and menu discovery, using existing shared Actions/Telegram UI | Offline MB-05B previously merged; split below into **SRO-01 → SRO-02 → SRO-03**; no code changes authorized by this planning document alone |
| **EPIC-MB — MB-05A / MB-06** | Preserve dual-bot routing, roles, previous customer/reseller actions and notifications | No new two-bot media verification, checkout or Sales activation in SRO; existing live gates remain separate |
| **EPIC-RO / EPIC-SB** | Provider administration writes, Node installation and paid/public Sales workflows | **DEFERRED / OUT OF SCOPE**, require separate planning and approval |

**Dependency:** `RW-02 P0 read evidence → RW-04 bounded P0 live verification → MB-05B-live SRO phases`. Unfinished RW-03 user lifecycle/Node acceptance is **not silently waived for sales or provider-write enablement**. Narrow read-only Admin overview may proceed through its own explicit security/compatibility acceptance without declaring the entire RW-03/RW-04 accepted.

## C. Frozen next scope: MB-05B-live / Super Admin server overview (NO NODE)

**Business outcome:** The allowlisted, active, Telegram-linked **Super Admin** can open a minimal, Persian, truthful, read-only status screen for the **existing Remnawave panel** from the **Admin Bot**. No new Node or Host is required.

**Reuse (existing code):**
- `ReadAdminProviderOverview` → `RemnawaveReadApi` / `RemnawaveApiClientFactory`; root authorization via `AssertCatalogManagementAccess`; local `provisioning_operations.state=needs_review` count.
- Existing `AdminProviderOverviewUi` and private `/servers` handler; `MenuRenderer` and shared `TelegramUi` safe callback/edit methods.
- Only existing five read endpoints: `GET /api/system/health`, `/api/nodes`, `/api/hosts`, `/api/internal-squads`, `/api/config-profiles`. No new API SDK, dependency, scheduler, database table or new architectural layer.

**Visible P0 data:** panel reachable/unavailable; connected/total Nodes (show `۰ از ۰؛ هنوز سروری تعریف نشده` when empty); Hosts count; Internal Squads count; Config Profiles count; runtime instances as such (not VPS count); local provisioning `needs_review` count **clearly labeled as local requests**, not RW server errors. Show a short `به‌روزرسانی` and `بازگشت به مدیریت` callback. No live screenshot/values cached or fabricated. User count, subscription count, traffic/user-online stats and Node drill-down are **P1 future candidates**, explicitly **not part of this P0 increment**.

**Security / failure policy:** root-only, active/linked/allowlisted, private chat; authorization re-evaluated on command, menu action and refresh; ordinary Admin, reseller/member, inactive/unlinked, groups and stale/forged callbacks denied. No raw token, config, Node IP, user identifier, subscription URL or exception in Telegram. Missing config/token/401/403/429/5xx/timeout/malformed payload shows one bounded generic unavailable/error message, not a false green state. No auto-retries or side effects.

**Hard exclusions:** Node/VPS provisioning, VPN transport, panel CPU/RAM/disk and OS metrics, provider Create/Update/Delete/restart/reset, users/subscriptions detail or ownership-based operations, Plan–Squad writes, payments/checkout, sales bot activation, new secrets/ACL rules, new migrations, major UI redesign.

## D. Execution phases — small, independent, reversible

### SRO-01 — P0 live-read compatibility and gate decision

- **Scope:** Review the 5 endpoint shapes against existing read/overview DTOs, check empty-Node semantics, local `needs_review` query, auth/timeout/error redaction and existing flag; capture redacted status/shape-only evidence and any truly observed defects. Resolve a **P0-only** acceptance decision; record RW-03 ownership failure as separate blocker for **user/subscription detail**, not server overview.
- **Acceptance:** All five real GETs mapped and normalized correctly; zero real writes; unavailable/error paths fail closed; root/private/linked authorization covered by tests; no secrets; written go/no-go for activating *only* admin overview. If mismatch is found, report a bounded fix/risks and request scope approval before implementation.
- **Output:** per-endpoint P0 matrix and narrow RW-04 compatibility decision; no runtime changes.
- **Rollback:** none (read-only verification).

### SRO-02 — Minimal Admin Bot presentation integration

- **Scope:** Reuse `/servers` and `AdminProviderOverviewUi`; add one guarded **«🖥️ وضعیت سرورها»** entry in Super Admin management navigation, and a bounded **«🔄 به‌روزرسانی»** callback; preserve back navigation and all existing routes. Use current `ReadAdminProviderOverview` Action, no Telegram → raw API bypass.
- **Acceptance:** correct real data when flag enabled under verified staging; zero Node displayed as empty inventory; refresh queries current state; error/unavailable shown truthfully; other roles, groups and stale callbacks denied; old commands/buttons remain working; no user/subscription details or write calls.
- **Output:** one small additive PR with focused Pest + PHPStan/Pint, PHP 8.4/8.5 CI; no schema/dependency changes. `REMNAWAVE_ADMIN_READS_VERIFIED` remains OFF by default at merge.
- **Rollback:** feature flag OFF and hide entry; existing private `/servers` returns unavailable; no data loss.

### SRO-03 — Controlled live rollout and acceptance

- **Scope:** After SRO-01/02 passing and operator approval, deliberately enable **only** `REMNAWAVE_ADMIN_READS_VERIFIED=true` in trusted Runflare Environment; rebuild/apply config as deployment requires; verify Admin Bot `/servers`, menu/refresh/back, errors, role revocation, no-Node and old Admin/Member routes; check post-deploy logs/queue for regressions. Do not change provider write/binding/Sales flags.
- **Acceptance:** real Super Admin Telegram smoke on the actual Admin token and staging panel; unauthorized role denied even with old callback without `/start`; no leaked credentials; existing customer/finance routes preserved; zero provider mutations; operator-confirmed rollback (flag OFF) path. Mark **MB-05B-live P0 overview ACCEPTED** only with this evidence, not full RW-03/04/MB-06B.
- **Output:** redacted operator evidence and concise closeout update in 000/018/022/this document.
- **Rollback:** flip `REMNAWAVE_ADMIN_READS_VERIFIED=false`, apply config/redeploy if required, recheck unavailable screen; preserve DB and BotFather config.

**Merge/deploy rule:** `master` auto-deploys. Code can merge only after review and CI; Production switch stays OFF until SRO-03 explicit approval and smoke readiness.

## E. Deferred backlog / do-not-accidentally-include

1. **RW-02/03 remaining evidence:** 429/5xx/timeouts and schema normalization; live App Action idempotency/reconciliation, DB reservations and uncertain writes, no replays, disposal of staging fixture only with separate confirmation; investigate the one subscription-ownership test failure without sharing sensitive data.
2. **Future Node/Host E2E:** separate VPS/Node, subscription page/public user reachability, Xray client testing and real node/traffic metrics; zero-Node overview must work **before** this.
3. **P1 management detail:** bounded user/subscription **counts** or online/node traffic graphs **only** after real schema + RBAC compatibility checks and an explicitly approved small scope.
4. **Public Sales/Billing activation:** Plan-to-Squad mapping, checkout/settlement gates, actual provider write path, multi-bot delivery; completely separate approval.

## F. Acceptance tracking and handoff

| ID | Status as of this document | Owner / evidence |
| --- | --- | --- |
| SRO-01 | **P0 READ GATE COMPLETE — GO FOR SRO-02 ONLY** | Five operator-live GET shapes + source/HTTP-fake guards; detailed evidence in §G; no new live Action run |
| SRO-02 | **PLANNED — NOT STARTED** | Independent PR, CI, operator smoke |
| SRO-03 | **PLANNED — NOT STARTED** | Explicit operator activation + live Telegram confirmation |
| RW-03 full | **PARTIAL — OPEN** | Operator staging CRUD; application idempotency and Node E2E pending |
| MB-06B | **NOT STARTED / GATED** | Full RW/multibot live acceptance later |

**Per-phase closeout:** In 2–3 concise Persian paragraphs report **done / tested / remaining / risks**, link PR/CI/real test evidence, and update only relevant development documents. Never convert a green HTTP fake or exploratory console output into unconditional live production acceptance.

## G. SRO-01 completion evidence — 2026-10-16 (READ-ONLY, NO DEPLOY CHANGE)

**Decision: GO to SRO-02 implementation only; NO-GO to SRO-03 feature-flag activation or full RW-04 sign-off.** Scope: the **five** existing P0 Remnawave GET endpoints and private Super Admin overview, **no Node**. Source inspected on `master` at `6b49e62b2449a2470b85a6a496a765016c9e5934`; live API results were previously observed by the operator on Runflare and **were not re-executed during this documentation review**.

### P0 live endpoint / typed contract matrix

| P0 read operation | Existing adapter and safe shape | Real Runflare evidence | Decision |
| --- | --- | --- | --- |
| `GET /api/system/health` | `SystemController_getRemnawaveHealth`, `response.runtimeMetrics` list → runtime instance **count**, not VPS CPU/memory | `remnawave:inspect` reported **3** runtime instances; typed live batch `Health API: PASS` | **P0-compatible** |
| `GET /api/nodes` | `NodesController_getNodes`, list of UUID/name/boolean connected/disabled; enabled-connected count excludes disabled | **0** nodes, no Node provisioned; live batch inventory PASS | **P0-compatible; empty is expected** |
| `GET /api/hosts` | `HostsController_getHosts`, list → count | **0** Hosts; operator live inspection successful | **P0-compatible; empty is expected** |
| `GET /api/internal-squads` | `InternalSquadController_getInternalSquads`, `response.internalSquads` array + integer `total` | **1** Squad; operator live inspection successful | **P0-compatible** |
| `GET /api/config-profiles` | `ConfigProfileController_getConfigProfiles`, `response.configProfiles` array + integer `total` | **1** Profile; operator live inspection successful | **P0-compatible** |

**Local count separately:** `ReadAdminProviderOverview` reads `provisioning_operations.state='needs_review'` using a **local SELECT count**. Positive count and formatting are tested in `tests/Feature/Provisioning/AdminProviderReadinessOfflineTest.php` with a disposable test DB; **not separately executed through the live Admin Action against Production DB**. SRO-03 must verify the final screen end to end; this field is **not** a Remnawave API health metric.

### Authorization, fail-closed behavior and evidence source

- `PrivateTelegramUser` requires a private chat with the same sender; `ResolveTelegramActor` rejects inactive/unlinked accounts. `AdminProviderOverviewUi` checks trusted root and `ReadAdminProviderOverview` independently enforces `AssertCatalogManagementAccess`, backed by `SuperAdminTrust`: active `super-admin`, linked and allowlisted, with at least two configured designated IDs. Offline `AdminProviderReadinessOfflineTest` covers member, ordinary admin at Application level, inactive, group, flag OFF and allowed reads; **new refresh callback denial** is an SRO-02 test obligation.
- `REMNAWAVE_ADMIN_READS_VERIFIED` defaults OFF: unavailable with **zero** panel requests. When enabled after explicit approval, trusted factory URL/token are server-side only. `RemnawaveReadApi` validates required shape and fails closed; Action converts missing credentials, malformed response, authorization errors, network exceptions/timeout/429/5xx to generic `error` and never exposes exception/token/Node IP/subscription URL to the Telegram UI. This behavior is **source-inspected and HTTP-fake tested**, **not** real-fault-injected for every status.
- `RemnawaveApiClient` uses bounded timeout, denies redirects, has **no retry**, and P0 Action invokes GET only. Operator live batch confirmed invalid API token **401** and missing user **404** (user 404 is outside five P0 endpoints). Real 403/429/5xx/timeout negative checks are pending broader RW-02/04 assurance; avoid labeling them live-verified.
- No observed P0 incompatibility warrants expanding client, schema, flags, ACL, or Node setup. The previous `Subscription Ownership` assertion failure in the **17 PASS / 1 FAIL** batch concerns a **separately excluded** user/subscription detail path. It remains open; no inference of data leakage or authorization acceptance.

### P0 gate, exclusions and next action

- **GO for SRO-02**: one small, root-only `/servers` Admin menu entry + refresh/back using existing `ReadAdminProviderOverview`/UI; display `۰ از ۰؛ هنوز سروری تعریف نشده` for empty Nodes; guard against stale/forged callbacks; keep old routes. Write no new provider adapter, DB migration, scheduler or Node agent.
- **NO-GO for SRO-03** until SRO-02 PHP 8.4/8.5, Pest, PHPStan and Pint pass and the operator explicitly approves trusted Environment flag switch plus real Telegram smoke; no Production switch was made in SRO-01. `REMNAWAVE_WRITES_ENABLED=false`, `REMNAWAVE_ADMIN_READS_VERIFIED=false` (until approved), `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED=false` remain safety requirements.
- **Not accepted**: all RW-03/RW-04 gates, live Laravel idempotency/reconciliation, Node/client connectivity, subscriber ownership read, real fault injection, two-bot media delivery, customer purchases or panel/server OS monitoring.
- **Changed in SRO-01:** documentation/evidence/status only. **No application code, schema, configuration, provider mutation, or Production activation.** There was no new live-server test or config fetch during this review. Rollback not required beyond reverting the docs PR.

**SRO-01 evidence references:** operator `remnawave:inspect` and 17/18 read-only batch in the conversation; `RemnawaveReadApi`, `RemnawaveApiClient`, `ReadAdminProviderOverview`, `SuperAdminTrust`, `AdminProviderOverviewUi`, `AdminProviderReadinessOfflineTest`, `RemnawaveReadApiTest`. An existing past CI success and live test logs are **not** newly rerun tests for this docs-only checkpoint.
