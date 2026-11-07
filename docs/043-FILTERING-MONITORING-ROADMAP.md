# DenaGate — Filtering Monitoring Center Roadmap (FLT-01 → FLT-04)

**Status:** OPERATOR-APPROVED PLANNING ONLY — 2026-11-07. **No implementation is authorized by this documentation PR.**
**Owner:** DenaGate Super Admin Bot; **Sales Bot:** unchanged.
**Implementation gates:** Four independently scoped feature PRs, each requiring an explicit instruction/approval, verification and rollback plan.

## 1. Problem and objective

On 2026-11-07, two DenaGate Nodes suffered user-access incidents, noticed late after their per-node online-user counts dropped to zero. The operator requests early alerts that are neither noisy nor excessively slow, plus a dedicated **«فیلترینگ»** Admin Bot center with independent Remnawave and Check-Host monitoring modules sharing a single incident, notification and history workflow.

**Outcomes:**
- Detect a suspicious previously-active Node approximately 1–2 minutes after two valid, consecutive one-minute observations (not a guarantee of latency).
- Independently test customer-facing TCP access from diverse Iranian Check-Host vantage points.
- Show concise per-node/module status, bounded history, manual refresh and tightly rate-limited manual checks.
- Send one opening alert per active incident, a material-severity update only when warranted, and one recovery notice upon confirmed recovery.
- Retain raw observation records for 24 hours, prune automatically every five minutes without operator confirmation; retain open incidents until resolved.
- Keep the system **clean, extensible, scalable, maintainable, testable and secure**, using the smallest sufficient design (Clean Code + Strict Scope + Minimal Complexity + Reliable Delivery).

This is a **suspected user-reachability disruption monitor**, not a definitive censorship oracle. A Remnawave-connected Node may be inaccessible from Iran; TCP success does not establish successful VLESS/XHTTP/REALITY protocol handshakes.

## 2. Source baseline verified on master, 2026-11-07

- `app/Modules/Provisioning/Infrastructure/Remnawave/RemnawaveReadApi.php`: `inventory()` normalizes Node `usersOnline`, `rxBytesPerSec`, `txBytesPerSec`, connection and disable flags. Actual rate presence/freshness must be verified on real Nodes; nullable is **UNKNOWN**, never zero.
- `RemnawaveNodeTelemetry::onlineUsers()` and `ReadAdminNodeMetrics` already provide a display-oriented per-Node online count; `ReadAdminNodeMetrics::handle(actorId)` is an actor-authorized UI read and must **not** be misused as an unattended scheduler actor.
- `NodeTcpProbe` and `network:tcp-probe` run once per minute from DenaGate's server to approved direct customer-facing endpoints. This is **not** an Iranian regional probe and its one-endpoint-per-node selection is not automatically sufficient for all transports.
- `routes/console.php` uses Laravel Scheduler with non-overlap guards. Existing Telegram delivery, operational health and Rich-first RTL UI must be reused, not duplicated.
- NVLBot read-only references: `DenaSon/nvl-bot/docs/PHASE6D_CHECKHOST.md`, `docs/PHASE6E_FILTERING.md`, `checkhost_probe.py`, `regional_filtering.py`. **Port the limited behavior into current PHP/Laravel; do not add a Python service or copy unrelated failover complexity.**
- Canonical policy: `AGENTS.md`, `docs/004-STANDARDS.md`, `docs/017-TELEGRAM-UX-IDENTITY.md`, `docs/041-ADMIN-TELEGRAM-RICH-UI-ROADMAP.md`, and the current master source.

No infrastructure, provider, data or Telegram behavior is changed by this planning artifact.

## 3. Scope boundaries and architectural contract

**Modular monolith only.** A small application-level filtering domain owns persisted observations, state transitions and incident decisions. Two read-only adapters feed normalized observations:
1. **Node monitor:** existing Remnawave authenticated reads; Node UUID as stable identity.
2. **Regional monitor:** outbound Check-Host API calls to verified public customer-facing Host:port targets from current authoritative RW inventory, using Iran nodes.

Share one incident evaluator / notification routing / status presenter and persistence policy. Provider adapters should report data, **not** send alerts or mutate incidents directly. Avoid speculative plugin frameworks, event buses, separate databases, packages, microservices, dashboards, monitoring stacks and parallel notification transports. A third source should later be addable by a small adapter/normalization path, without rewriting incident lifecycle or Telegram presentation; do not prebuild that source now.

**Scale through bounds rather than infrastructure:** per-node sampling, indexed time-based pruning, bounded reads and pagination, API timeouts, polling caps, concurrency ceilings, provider backoff and job overlap locks. Failure of one Node/provider must not suppress healthy Nodes, block bot webhooks, interfere with Sales, or trigger an unbounded queue/API storm. Use existing Laravel queue/scheduler/DB/Redis as warranted; treat Redis as ephemeral, MySQL as authoritative for active incidents. Use existing authenticated Admin notification transport.

**Security:** GET/read-only provider operations only; allowlist derived targets (public IP/approved host, service port, node binding); reject private/reserved, arbitrary user-supplied, CDN-to-origin confused and ambiguous targets. No raw subscription URLs, credentials, IP lists, Check-Host tokens, private config or unbounded response bodies in Telegram/logs. RBAC rechecked for every Admin entry and callback, private chat and Admin Bot only. No customer-facing management jargon.

**Out of scope:** automatic restart, failover, routing, DNS/Cloudflare modifications, user migration, Service/Host enable/disable, Provider writes, general-purpose port scanners, new network probes/ESP32 integration, standalone web panel, long-term analytics, advanced ML, and high-fidelity protocol handshake classification.

## 4. FLT-02 Node signal policy (operator-approved)

**Cadence:** Scheduler every minute; snapshots sampled independently per active, enabled Node, not by summing all Nodes.

Two **independent OR triggers**, requiring **two consecutive valid, fresh observations** of the same signal:
- **Online trigger:** per-Node `usersOnline === 0` twice.
- **Traffic trigger:** per-Node `rxBytesPerSec === 0 AND txBytesPerSec === 0` in each of two consecutive observations. If either rate is unavailable, that traffic sample is UNKNOWN, not zero.
- Either trigger is enough to open or update an incident; simultaneous zero online + traffic is stronger evidence, **not** a prerequisite to notify.

**Noise control:** eligible Nodes must be enabled and have recent positive activity observed by this monitor (online > 0 or RX/TX > 0) within the preceding 30 minutes. A previously never-active Node, intentionally disabled Node or newly registered Node remains IDLE/UNKNOWN instead of generating perpetual zero alerts. This is a proposed baseline rule; calibrate against live node behavior before enabling alert delivery, without changing the approved OR/two-sample policy. A missing or failed API read, duplicate/stale observation, time gap beyond the expected schedule tolerance, disabled status or unknown field must break that signal's consecutive-zero streak; do not fabricate a zero. A successful fetch timestamp shows collection time, **not** guaranteed freshness of upstream telemetry without provider evidence.

**Recovery:** after **two consecutive valid positive observations** for each previously affected signal; one source's recovery must not silently close another still-active source. An intentionally removed/disabled Node may end monitoring with a labeled administrative reason, **not** a fabricated successful recovery. Avoid alert flapping. Show per-signal cause and last-valid-check time. Unexpected provider failures are UNKNOWN and can produce an operational data-unavailable indicator, not a filtering verdict.

**Delivery:** dedupe by Node across signals and sources while an incident is open. Node escalation/regional confirmation may update the existing incident; no every-minute repeated alerts. Never auto-restart or fail over.

## 5. FLT-03 Check-Host regional policy

**Cadence:** every five minutes for approved eligible Nodes/targets. On a Node-monitor alert, enqueue at most one out-of-cycle regional confirmation per affected Node under a cooldown and provider-wide budget; no synchronous probe in Telegram webhook.

**Reference request protocol (NVLBot 6D):** discover Iranian nodes via `/nodes/hosts`; start TCP via `/check-tcp` with approved `host:port` and repeated `node` parameters; get a `request_id`; poll `/check-result/{request_id}` within strict timeout/attempt bounds. Normalize SUCCESS, FAIL, PENDING/UNKNOWN separately. A `null` response or an API error is **not** a TCP failure; protect against rate limits, retries, malformed payloads, duplicate/changed nodes and oversized outputs.

**Coverage:** target eight available Iranian probes across diverse networks/cities when available. Never hardcode an assumption that eight nodes always exist. A verdict requires at least 4 completed probes from 4 distinct ASNs and 2 cities; otherwise UNKNOWN. City/ASN come from trusted provider metadata, not inferred from test success.

**Initial conservative thresholds, borrowed from NVLBot 6E (require calibration):**
- CLEAR: adequate coverage and no diverse failure quorum; preserve isolated failures as details.
- POSSIBLE: at least 2 failures, at least 2 failed ASNs, and failures ≥25% of completed probes.
- LIKELY candidate: failures ≥60% of completed probes, at least 4 failed probes, 4 distinct failed ASNs and 2 failed cities.
- Escalate to high-confidence regional warning only after **two consecutive eligible LIKELY rounds** and a successful independent external-control TCP test that distinguishes a target issue from broad probe/provider failure. Otherwise retain POSSIBLE/UNKNOWN as appropriate; repeated POSSIBLE alone does not silently become LIKELY.
- Recover only after repeated eligible healthy rounds; an unknown/partial response is never recovery.

**Target choice:** prioritize verified direct customer-facing TCP service ports. Validate current RW Host/Node/inbound association, public destination resolution, disabled state and transport context each run. Existing `NodeTcpProbe::targets()` may be reused where its limitations fit; it is not proof that every service port or CDN domain is covered. For Cloudflare-proxied XHTTP, disclose that generic TCP to a Node origin does not validate the end-user HTTPS/CDN path; do not equate it with an end-to-end service test. One selected target per Node in v1 unless justified within the phase and approved separately.

No client configuration changes, service restarts or automated remediation.

## 6. Incident lifecycle, data and 24h retention (FLT-01)

**One additive Laravel schema migration**, introducing two small indexed MySQL tables:
- `filtering_checks`: bounded normalized observations (`node_uuid`, `module`, `observed_at`, `outcome`, `signal values / compact evidence`, provider-check freshness/time fields). No secrets, raw full API bodies or indefinite metrics.
- `filtering_incidents`: authoritative incident identity and lifecycle (`node_uuid`, `state`, `opened_at`, `updated_at`, `resolved_at`, dedupe/notification markers, compact causes/affected modules and recovery state).

A single Node can have at most **one active composite incident** joining independent module evidence; make concurrent opens/transitions idempotent under MySQL-compatible locking/uniqueness. Clearly distinguish healthy, suspicious, disrupted and unknown states; an isolated source recovery does not resolve the composite while another source remains abnormal. Store only minimum information needed for deterministic transitions and an operator-readable 24h history. If samples are insufficient due to restarts or cache loss, safely resume from durable data or warm up without false alerts.

**Retention and cleanup (approved, automatic):**
- Raw `filtering_checks` older than **24 hours from observation timestamp** are deleted automatically.
- **Resolved** incidents older than **24 hours after resolution** are deleted automatically.
- **Active** incidents remain until ended, regardless of age; do not clear their dedupe state during pruning.
- Run Laravel Scheduler pruning every **five minutes**, without manual confirmation. Deletion is bounded/chunked and uses indexed timestamps to avoid table locks and unnecessary DB/CPU spikes.
- Retention is operationally best-effort with Scheduler uptime: overdue data is cleaned on the next successful run, not guaranteed at an exact second. Expose stale monitor/scheduler health; never call silent check failure "healthy".
- Retention must not delete records from existing Health, finance, sales, billing, Telegram or Remnawave tables. Avoid a second generic cleanup infrastructure.

**Notifications:** reuse the approved Admin Bot notification delivery with existing role/contact safeguards. One open, at most meaningful escalation, one resolution notice; delivery retries must not duplicate incidents. The existing transport's delivery guarantee/dedup mechanism must be examined in implementation; **do not assume** current financial/event outbox can be used unchanged without an adapter. No per-minute flooding. Message includes Node name, status, signal evidence, first/last observed time, and a route to the filtering page; redact network secrets.

## 7. Admin Bot UX (FLT-04)

Add a dedicated native **red/danger** main Reply Keyboard button labeled **«فیلترینگ»** for trusted **Super Admin** only, preserving every existing menu button/order, authorization and stale-keyboard behavior. Verify installed Nutgram/ButtonStyle and current Telegram support for the chosen reply-button style; use a safe non-colored fallback where unsupported. Do not expose to Sales Bot or delegated Admin unless separately authorized.

New **RTL Rich-first** center with the existing authorized native fallback:
- Compact overall counts (healthy/suspicious/disrupted/unknown) and per-module freshness/cadence.
- **«وضعیت نودها»**: per-Node online, RX/TX, incident state, timestamp and cause.
- **«بررسی دسترسی»**: last Iran Check-Host result, completed/success/failure/pending counts and limited coverage notes.
- **«هشدارها و تاریخچه»**: recent incidents, chronological paging within the 24h retention policy.
- **«تازه‌سازی»**: read existing latest state; distinct optional **«بررسی مجدد»** is authorized, asynchronous, cooldown-/rate-limited, and never accepts arbitrary target input.
- Compact **back/home** paths and concise contextual footer; no internal class names, phases, tool/API words, tokens, raw paths or verbose developer diagnostics in Telegram UI.

No giant unpaginated histories, inline destructive actions, protocol-level debugging, dashboard redesign or parallel root navigation.

## 8. Four implementation gates and acceptance criteria

### FLT-01 — Foundation, schema, cleanup (one scoped PR)
- Review master and relevant current docs; finalize minimal rows/columns/indexes, incident transition contract, permission boundaries and practical locking/idempotency.
- Add the **one additive migration** for `filtering_checks` and `filtering_incidents`; application service for observation/incident lifecycle, shared status read, bounded cleanup command + every-five-minute Scheduler + lightweight tests. No live monitor polling or Telegram filtering UI yet.
- Tests: MySQL migration/rollback, schema compatibility, concurrency/idempotence, active incident never pruned, resolved incident age measured from `resolved_at`, exact >24h cutoff, old/new/unknown boundaries, no other table cleanup, chunked prune, scheduler overlap guard.
- Phase acceptance: foundation is independently safe to merge/deploy, feature inert until next phases. **Do not implement FLT-02/03/04 in this PR.**

### FLT-02 — Remnawave Node monitor + alerts (one scoped PR)
- Reuse existing validated Remnawave rates/online data, one-minute background scan and one shared incident evaluator; add notification integration with idempotent opening/recovery.
- Tests: two consecutive zero online OR two consecutive both-zero rates, positive baseline, independent signals, positive/partial/unknown/rate unavailable, provider timeout, stale samples, disabled/new/idle Nodes, node-UUID identity, lost cache/restart, flapping, alert dedupe, incident recovery and bounds.
- Phase acceptance: fake Provider/Telegram tests green + real read-only Remnawave field/freshness smoke before enabling live alerts. No claim that mock responses prove real panel availability.

### FLT-03 — Iran Check-Host module (one scoped PR)
- Minimal bounded PHP HTTP adapter + scheduled regional read-only scans; reuse phase-1 incident contract; controlled out-of-cycle confirmation from Node alert.
- Tests: regional node discovery, allowlisted targets and SSRF protection, timeouts/rate limits, async polling, pending vs failure, minimum coverage, ASN/city diversity, POSSIBLE/LIKELY and external-control gates, sequential confirmation/recovery, output sanitation, probe budget/overlap, independence from FLT-02.
- Phase acceptance: fake HTTP tests green + one limited, explicitly read-only live Check-Host probe against a known safe target and one approved live Node service target before making production verdict claims.

### FLT-04 — Telegram Admin UX + integrated acceptance (one scoped PR)
- Add red «فیلترینگ» Super Admin reply entry, compact RTL Rich-first screens, fallback, paginated statuses/history, refresh and guarded manual check paths.
- Tests: private/root authorization, delegated/non-admin/Sales and revoked-role denial, old keyboard preserved, callback freshness/forgery handling, rich/fallback parity, pagination/24h cutoff, rate limits, no sensitive fields, end-to-end **mock** alerts/recovery across both modules; operator visually verifies real Telegram Android/iOS and real Node/provider smoke separately.
- Phase acceptance: full supported CI PHP 8.4/8.5, PHPStan, Pint, MySQL and relevant regression suite, then production operator smoke. Finish with the report **انجام‌شده / تست‌شده / باقی‌مانده / ریسک‌ها**.

## 9. Change control, rollout and evidence

- This PR is **documentation-only**; creates **no** migrations, jobs, tables, DNS, Provider calls, bot menu entries or Production configuration changes.
- Each future FLT phase requires a **separate explicit implementation instruction** and one independently reviewable PR. Do not merge unfinished phases. Respect current `master`, CI/production auto-deploy behavior and review the diff before merging. Keep rollback practical and no destructive schema operations.
- Never claim live monitoring/alerts/Host-check verification from documentation or mock CI alone. Record actual versioned API compatibility, timeouts, false-positive sample findings and operator acceptance.
- Monitoring must remain **extensible and scalable without overengineering**: small cohesive actions, reused adapters/outbox/health/Telegram primitives, explicit contracts, bounded resource consumption, test seams and no unnecessary abstractions, dependencies or rewrites. Architecture changes beyond these constraints require risk/alternative assessment and explicit approval.
- Update this reference and the minimum necessary docs at phase completion; do not silently change threshold, retention, permission, rollout or recovery semantics.

## 10. Starting references / handoff

- [Master context](000-CONTEXT.md), [Master roadmap](005-ROADMAP.md)
- [Telegram UX and identity](017-TELEGRAM-UX-IDENTITY.md), [Rich-first UI policy](041-ADMIN-TELEGRAM-RICH-UI-ROADMAP.md)
- [Remnawave Nodes/metrics](011-REMNAWAVE-NODES.md), [Operational Health](036-DENABAN-OPERATIONAL-HEALTH.md)
- [NVLBot Check-Host 6D](https://github.com/DenaSon/nvl-bot/blob/master/docs/PHASE6D_CHECKHOST.md), [Filtering classification 6E](https://github.com/DenaSon/nvl-bot/blob/master/docs/PHASE6E_FILTERING.md)

**Next explicit scope: FLT-01 only.**

## 11. FLT-01 implementation contract (PR #403; pending operator merge approval)

- **Persistence:** `filtering_checks` stores one immutable normalized observation per Node UUID / module / observation-second (strict uniqueness), its outcome, optional compact allowlisted counters, provider timestamp and received-at time. `filtering_incidents` stores a composite active incident keyed by a nullable unique `active_node_uuid`, affected-module state/cause/timestamps, opened/last-observed/resolved times and two reserved notification markers. Only two small indexed tables and one additive migration are introduced.
- **Application boundary:** `FilteringFoundation::record()` accepts pre-classified `node` or `regional` observations with `healthy`, `suspicious`, `disrupted` or `unknown` outcomes; raw remote bodies, addresses, secrets and unknown evidence keys are rejected. Duplicate samples and older out-of-order observations cannot reapply a transition. The persisted active incident is authoritative across restarts; an unknown result cannot start or resolve a filtering incident. The same Node can have no more than one active composite incident; each affected module must recover before closure. Provider-rate thresholds and consecutive-sample policy remain exclusively FLT-02/03.
- **Retention:** `filtering:prune` runs every five minutes with the existing Scheduler's non-overlap guard. It removes checks with `observed_at < now()-24h` and only resolved incidents with `resolved_at < now()-24h`; open incidents are never selected. Each invocation processes at most ten batches of 200 rows **per table**. If a backlog exceeds this bound, the next scheduler runs continue cleanup. Rollback refuses to delete any retained evidence; an empty schema can roll back safely.
- **Verification and rollout:** Pest tests cover lifecycle/replay/unknown/out-of-order/invalid input, strict retention boundaries, active incidents, batching and Scheduler registration. The existing disposable MySQL job also exercises migration/rollback and forked competing Node incident openings. No data collection, Telegram alerts/menus, provider calls, remediation, Production migration or activation is part of this PR. FLT-02 remains separately gated and must verify real provider telemetry freshness and notification semantics before enabling alarms.

This section records only the FLT-01 implementation design for review; merge, Production migration and acceptance still require the operator's explicit decision.
