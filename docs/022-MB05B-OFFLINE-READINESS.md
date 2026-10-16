# MB-05B Offline — Admin read models / Plan–Squad contract

**Current operator checkpoint — 2026-10-16:** Isolated Remnawave **3.4.4** staging is online; real Laravel read/health/inventory and one disposable staging user Create/absolute Update/Disable/Enable were operator-tested. RW-01/02 lack full formal sign-off; RW-03 is **PARTIAL**, no Node/Host; one typed subscription ownership assertion failed (17/18 batch), not a proven leak. The **next planning scope is MB-05B-live P0 Admin-only server overview with zero Nodes**, phase-split **SRO-01 → SRO-02 → SRO-03**; no implementation/flag changes, no sales/provider writes. See [current evidence and exact scopes](024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md). **This supersedes historical “staging unavailable / BLOCKED” statements below, which remain as dated history.**


**Scope and evidence:** [PR #58](https://github.com/DenaSon/skynet/pull/58), read-only/offline only. Remnawave staging is not available; no HTTP-fake result is evidence of a working real panel.

## Implemented contract

- `ReadAdminProviderOverview`: reauthorizes the **active linked allowlisted Super Admin** through existing `AssertCatalogManagementAccess` before configuration or network access. `AdminProviderOverview` is a secret-free typed projection of runtime instance count, nodes/connected nodes, hosts, internal squads, config profiles, and SQL `provisioning_operations.state=needs_review` count. It reuses existing `RemnawaveReadApi` for the five GET endpoints. No arbitrary operation ID, unbounded raw response, URL, token or subscription link reaches Telegram.
- The independent `REMNAWAVE_ADMIN_READS_VERIFIED=false` default prevents all provider requests from this Admin read Action and returns `unavailable`. `error` is returned on missing settings, invalid payload or network/permission errors; secrets/exceptions are never presented to Telegram. `available` is possible only after deliberate operator-controlled enablement **following RW-04 live sign-off**.
- Admin-only private `/servers` displays short Persian unavailable/error states and a working Back-to-Admin callback. It is deliberately **not advertised on the Admin menu** until real panel compatibility is accepted. Nonadmins, revoked accounts and group chats cannot read infrastructure. This is not an assertion of production health.
- `sales_plan_internal_squad_bindings` is an additive **unpopulated contract table** (one mapping per plan, squad UUID, plan revision, nullable verified timestamp). There is **no route, wizard or API to write or verify it in MB-05B offline**. Binding writes must be designed and reviewed after live panel membership checks.
- `CheckPlanSquadReadiness` reauthorizes the root, then fails closed unless `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED=true`, table exists, binding exists, parent product enabled, plan active, plan and binding revisions equal, UUID canonical-format and `verified_at` exists. This read guard is **not** connected to checkout or provider lifecycle in this phase; existing sales/provisioning remains disabled.
- Guarded migration rollback refuses to delete any binding rows. No destructive/restart/reset/quota/revoke remote operations. `REMNAWAVE_WRITES_ENABLED=false` remains unchanged.

## Split acceptance matrix

| Case | Offline evidence | Live evidence |
| --- | --- | --- |
| Root-only and private chat | Pest tests | Telegram smoke pending |
| Unconfigured, denied, revoked and malformed API | Pest HTTP fakes, zero network on default flag | Staging 401/403/429/5xx/timeouts pending |
| Health + node/host/squad/profile snapshots | Typed fake responses and bounded displays | RW-02/RW-04 real version/shape pending |
| Needs-review | Real local SQL query in test | Production SQL check pending |
| Plan–Squad revision/status/UUID guard | SQLite tests and MySQL migration smoke | Actual squad UUID/membership and operator verification pending |
| Real user/subscription summaries | Not exposed: owner-backed provider mapping lacks live evidence | Requires RW-04 and approved read authorization |
| Rollback/data safety | Guarded nonempty-down regression | Live deployment migration/recovery pending |

## Follow-up

Keep `REMNAWAVE_ADMIN_READS_VERIFIED=false`, `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED=false` and `REMNAWAVE_WRITES_ENABLED=false` in Production. No actual provider-connected menu or plan binding can be declared verified until RW-04 staging evidence and owner approval. Next provider-independent work is **MB-06A**; RW-01–04, MB-05B-live and MB-06B remain separate gated follow-ups.

## Live readiness update — 2026-10-16 (operator evidence; not implementation)

Remnawave 3.4.4 staging is now available, and the same five P0 GET routes used by `ReadAdminProviderOverview` were successfully inspected from Runflare (`/api/system/health`, nodes, hosts, squads, profiles). The previous **offline-only** section and matrix are historical implementation evidence; they must **not** be interpreted as current lack of a server. No Node/Host exists; empty inventory is expected. Source for current evidence and boundaries: [024](024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md).

**Next:** SRO-01 P0 read/gate verification → SRO-02 guarded menu + refresh/back → SRO-03 operator-approved real Admin Bot activation. Existing `/servers` screen/Action remain hidden/unavailable by default. `REMNAWAVE_WRITES_ENABLED=false` and `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED=false` remain; do **not** enable `REMNAWAVE_ADMIN_READS_VERIFIED` until the SRO gates pass. User/subscription details, unverified owner shape (one 17/18 live-batch FAIL), Node setup and paid Sales are excluded.

