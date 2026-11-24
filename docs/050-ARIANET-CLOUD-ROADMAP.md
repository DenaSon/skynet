## ARI-06 — Final Hardening (2026-11-24; source PR, acceptance pending)

Operator approved narrowly scoped final hardening after reporting live ARI-05 tests. No new dependency, global lifecycle lock, migration, provider privileges or automatic provider retry. Explicit 4xx rejections in server operations release the consumed intent as failed; ambiguous outcomes remain blocked only for the affected server and can be manually reviewed after ten minutes, fresh terminal/idle provider evidence, and a private one-use operator confirmation. Reviewed is not success; never infer success or replay the previous request. The operator must independently verify the provider panel. Both flat and paginated action inventories are supported, bounded by response size and 100 pages; incomplete/unknown action history fails closed. Latest operation remains reachable after deletion. Existing plan availability no longer gates rebuild; verified completed upgrades may supersede the purchase plan without editing original receipts. Node installation and replacement use per-IP leases and existing cache checkpoints (three-hour expiry, refreshed with progress), protecting only affected addresses; rollback protection ends on explicit client acceptance or expiry of the existing resumable checkpoint. Expired workflow evidence requires operator review before destructive maintenance. DNS dependency verification remains fail-closed; no arbitrary 20-address limit. Source CI and live operational acceptance remain separate.

## ARI-05B/C — rebuild, plan upgrade and safe delete (2026-11-24; source review pending)

Extend the same operations submenu and durable journal; separate default-OFF maintenance permission prevents existing power authorization from enabling destructive operations. Select only verified available Ubuntu/Debian images, or an eligible larger plan in the same datacenter while stopped; bind fresh IRR prices to confirmation, no resource shrink or automatic power-on. Rebuild and delete require explicit provider protection=false, no local Node mapping, no direct Node/Host IP or managed hostname reference and no managed DNS A/AAAA pointing to the public IP(s). Unverifiable inventory, address, protection, rescue/locked status or external dependency blocks; external resources outside managed scope remain operator responsibility. Never change protection, DNS, Host or Node automatically. Delete only exact server, preserve snapshots and independent resources, no keep-backups conversion; warn about main disk/backup loss and remaining charges. Verify matching action and final server image/plan or provider not-found via GET. Rebuild does not prove SSH access; the provider issues a new one-time root password, which this bot deliberately discards rather than exposing in Telegram. Recover access through the trusted provider panel if needed; re-pin fingerprint and separately test SSH. The official Server schema uses status off and an empty IPv6 string when unassigned; both are handled. No hard reset, real provider writes, auto-deploy or actual ENV changes. Both migrations and green CI are mandatory before release; live tests separately approved.

## ARI-05 — Cloud server operations (2026-11-24; operator-approved)

One «⚙️ عملیات سرور» submenu in the existing private root Cloud detail. ARI-05A source: state-dependent poweron, graceful shutdown and reboot, fresh identity and one-use confirmation, per-server durable journal, independent default-OFF `ARIANET_CLOUD_OPERATIONS_ENABLED`, GET-only matching action and final server state, and no blind replay. Reuse the existing IPv4 lock and block competing pending IPv4 operations; retain all previous Cloud detail actions. One additive journal migration is required before enablement; never drop populated history. A linked/live server may be interrupted only with explicit operator confirmation. No actual provider writes in development. Hard reset/poweroff are excluded.

Remaining independent ARI-05 scope: rebuild (select supported image, respect rebuild protection, main-disk loss, reverify SSH identity), change plan (stopped only, eligible upgrades, fresh IRR price and no shrinking), and delete (explicit irreversible confirmation, delete protection, prove no Node/Host/DNS dependencies, no automatic snapshot/resource deletion or protection removal). These are not implemented by ARI-05A. Use the same submenu and journal in subsequent reviewed PRs. See [official Cloud API](https://docs.ariaservice.net/en/cloud-api). Live operations require separate explicit confirmation and provider verification.

# Arianet Cloud integration — authoritative delivery status (2026-11-23)

## ARI-03D — Domain-first Production IPv4 change (2026-11-23)

**Implementation reference:** [PR #561](https://github.com/DenaSon/skynet/pull/561) simplifies the existing ARI-03B/03C2 implementation. Check the linked PR and CI for current merge status; historical staged sections remain below for context. **A merged PR or green CI alone is not evidence of a live IP change, Cloudflare propagation or client connectivity.**

**Fixed DenaGate operational rule:** Remnawave Nodes and their user-facing Hosts use predefined Cloudflare-managed **domain names**, not direct IPv4 endpoints. A Node or associated Host configured with a direct IPv4 fails the normal change preflight; do not silently invent a direct-IP recovery path. Remnawave Node UUID, address and Host entries remain unchanged during a normal Cloud IP replacement.

**Simple Admin UX:** `کنترل پنل → سرورها → سرورهای ابری → انتخاب سرور → پیش‌نمایش تعویض IPv4`. Show live server/IPv4, linked Node, actual IRR fee, and **exact managed DNS hostnames**. Only offer `تعویض واقعی IP` when eligible and both independent Cloud and Cloudflare write gates are ready. A single private-root, 10-minute actor-bound confirmation includes amount and DNS targets. Upon POST 202 show `در انتظار نتیجه`; the operator uses GET-only `بررسی نتیجه`. When the new public IPv4 is verified, `تکمیل DNS` continues the **same approved operation**, no new paid confirmation. Report each DNS record and require an actual user-client test before completion. Rich RTL with the existing secure HTML fallback.

**Data/write contracts:** Reuse the current `ArianetCloudWriteClient`, `ChangeAdminArianetIpv4`, `InspectAdminArianetIpv4Dependencies`, `CoordinateAdminArianetIpv4`, `ManageCloudflareDns`, and the **existing** `arianet_cloud_ipv4_changes` journal/checkpoints; no new migration, service, scheduler, dependency or separate lifecycle. Production permits **only** `changeIpv4` through the existing default-OFF `ARIANET_CLOUD_WRITES_ENABLED` gate; Cloud `createServer` remains hard-blocked in Production pending separately approved ARI-04B. One Bearer token (`ARIANET_CLOUD_READ_TOKEN`) is reused. Require the existing independent `CLOUDFLARE_DNS_WRITES_ENABLED` gate; do **not** widen Remnawave write privileges.

**Ownership:** Only selected Node UUID's explicitly assigned Hosts and the exact management hostname define DNS targets; deduplicate and verify unique, editable, allowlisted A records pointing to the immutable old IPv4. Preserve each record's Proxy status and record ID. Other-Node/unassigned Hosts on unrelated endpoints—even if sharing Profile/Inbound—are counted but **not** target DNS writes and alone do not block the selected Node. Shared, endpoint-colliding, direct-IP or ambiguous potentially affected Hosts remain blocked for manual ownership correction. No IP/Host/Node PATCH in Remnawave.

**Resilience:** Recheck Cloud fee/quota, target inventory and DNS records before the single POST. Journal the confirmation as reserved before sending; never replay ambiguous Cloud POST. Verify the exact provider action by GET and new public IPv4 before touching Cloudflare. Persist each DNS step as uncertain before PATCH; verify by GET; continue only verified steps without automatic rollback or duplicate PATCH. If changed/ambiguous, stop with a recoverable status. DNS propagation and real user reachability are distinct from API write success. A live production IP-change has **not** been authorized for any named server by this implementation approval; require transaction-specific fee/server approval before first use.

**Release acceptance:** independent PR review, PHP 8.4/8.5 CI, PHPStan, Pint, MySQL where applicable, one-POST/replay/failure/unique-A/DNS-only/RBAC/Rich-fallback regression, deploy verification, and separate live operator-controlled acceptance. Merging deploys Production code but must not silently enable ENV flags or trigger any Cloud/CF write. Existing historical staged steps in stored journals must not be replayed as direct-IP Node/Host changes.


**Reference:** latest `master` after [PR #559](https://github.com/DenaSon/skynet/pull/559), commit `56ca4576b24969f7f246ea68f3456e7b779ed48b`.

**Read this section first.** The per-phase sections below are historical design and acceptance contracts. Early language about a mandatory `ARIANET_CLOUD_WRITE_TOKEN`, absent implementations, or "ready for PR" is superseded by this snapshot and current `master` source. Future phases listed here are **not implemented** and cannot authorize provider writes.

**ARI-03D domain-matched Host compatibility patch (2026-11-23):**
An operator-observed Production report for GermanyOVH had two Frankfurt Hosts
with empty `Host.nodes` membership, both using exactly the Node management
hostname `fr.coreflare.ir`, with one valid matching Cloudflare DNS A record.
This is a supported domain-only case: do not require a Remnawave Host
membership mutation just to change the Cloud server IPv4. The read-only
impact audit now distinguishes explicitly assigned Hosts from empty-membership
Hosts on the exact Node hostname, with a dedicated count in Admin Rich UI.
Such Hosts remain subject to verified managed A records, peer-Node/Host
collision exclusion and existing 10-record bounds; direct IP, shared
memberships, mismatched DNS and unrelated hosts are still blocked or excluded
as before. The Cloudflare-only coordination verifies both exact hostname
and unchanged empty membership, never PATCHes Remnawave, and retains one
journaled DNS operation and GET recovery. Code and fake-HTTP tests are not
live Cloudflare updates or proof of restored client connectivity.

## Delivery matrix — what is actually merged

| Phase | PR | Implemented | Verification boundary |
| --- | --- | --- | --- |
| ARI-01A | [#548](https://github.com/DenaSon/skynet/pull/548) | Bounded, fixed-origin Cloud GET client for plans, servers, networking, costs and actions | Live Runflare GET responses observed; no real writes |
| ARI-01B | [#549](https://github.com/DenaSon/skynet/pull/549) | Restricted, default-off single-server create / IPv4-change POST transport | Fake-HTTP tests only, not a purchase UI |
| ARI-02A | [#550](https://github.com/DenaSon/skynet/pull/550) | Private Super Admin Rich RTL Cloud inventory, detail, plans, costs and history | Operator tested real Telegram UI |
| ARI-02A fixes | [#552](https://github.com/DenaSon/skynet/pull/552), [#553](https://github.com/DenaSon/skynet/pull/553) | Live `vcpu`/`ram_mb` and IRR-rate fields, action-history response without pagination meta | API data shape inspected; regression CI |
| ARI-02B | [#551](https://github.com/DenaSon/skynet/pull/551) | Durable one-to-one Cloud server ID ↔ Remnawave Node UUID mapping/relink/unlink | Linked Node observed via private UI; no external mutation |
| ARI-03A | [#554](https://github.com/DenaSon/skynet/pull/554) | Read-only IPv4-change eligibility, exact fee, Node and management A-record preview | Real Telegram preview screenshot accepted |
| ARI-03B | [#555](https://github.com/DenaSon/skynet/pull/555) | Non-production one-shot IPv4 action, durable intent, single POST, GET reconciliation | CI fake HTTP only; **no live IP change** |
| ARI-01C | [#556](https://github.com/DenaSon/skynet/pull/556) | One Arianet Bearer token for reads and gated writes; hard Production POST ban | Code/tests verified, no live POST |
| ARI-03C1 | [#557](https://github.com/DenaSon/skynet/pull/557) | Read-only Host/Node/Cloudflare dependency audit | Real Telegram audit screenshots accepted |
| ARI-03C2 | [#558](https://github.com/DenaSon/skynet/pull/558) | Non-production staged DNS → direct-IP Host → direct-IP Node changes with durable checkpoints, GET-only recovery and client-test acceptance | Production **status screen only** tested; no live cross-provider Write |
| ARI-03C Host ownership | [#559](https://github.com/DenaSon/skynet/pull/559) | Fix shared-Profile/Inbound false ownership and distinguish other/unassigned/shared Hosts | CI 2,155 tests / 15,837 assertions, PHPStan, Pint, MySQL; **real post-deploy audit retest pending** |
| ARI-04A | [#563](https://github.com/DenaSon/skynet/pull/563), [#564](https://github.com/DenaSon/skynet/pull/564) | Fresh quote and existing public SSH-key selection | Operator observed real quote |
| ARI-04B | [#567](https://github.com/DenaSon/skynet/pull/567) | Single paid create, durable one-use confirmation, purchase-only default-OFF flag | Merged with green CI; no live purchase |
| ARI-04C | Current source PR | Verified VPS readiness and supervised handoff to existing NW-05 | Source review/CI; live acceptance pending |

All listed PRs #548–#559 are merged on `master`. Source test success, Production deployment, live UI observation, provider write acceptance and actual client-connectivity verification are **five different evidence levels**. Do not call ARI-03 end-to-end accepted based on CI or the current read-only screens.

## Actual application/configuration contract

- Provider API: `https://api.ariaservice.net/cloud/v1` (Cloud, **not** the separate VPS `/api/v1`).
- One existing token, `ARIANET_CLOUD_READ_TOKEN`, is used for both Cloud GET and the guarded POST transport. Its scopes may include `cloud:servers:read` / `cloud:servers:write`, but token possession is **not** application authorization. `ARIANET_CLOUD_WRITE_TOKEN` is **obsolete** and no longer consumed.
- Current config defaults: `ARIANET_CLOUD_TIMEOUT=10`, `ARIANET_CLOUD_WRITES_ENABLED=false`, `CLOUDFLARE_DNS_WRITES_ENABLED=false`. `REMNAWAVE_WRITES_ENABLED` is a pre-existing independent flag (example default `true`); do not change it globally simply to disable Arianet. The actual Runflare ENV must be verified separately.
- `ArianetCloudWriteClient` always rejects a Cloud POST in `production` **even if its flag is enabled**. ARI-03B and ARI-03C2 also independently require a non-production application. Production Admin Bot shows authorized GET previews/status but must have **no execution controls** for these stages.
- Private trusted Super Admin only; not the Sales Bot, Deniz or a filtering-triggered automation. No token or raw provider response in bot messages/logs. One-use confirmations and actor-bound authorization remain required.
- Existing tables: `arianet_cloud_node_links` (local one-to-one mapping), `arianet_cloud_ipv4_changes` (durable one-active-operation journal, plus `coordination_state` JSON checkpoint). Never drop populated journals or blindly retry uncertain writes.
- Reuse existing NW-01/NW-02/NW-03 supervised installation/Host assignment and NW-05 identity-preserving Node replacement. No parallel installer or billing system.

## Operator-verified live read-only observations

- Real Cloud server `ubuntu-2404-ovh-6eym` was shown running on IPv4 `62.144.51.204`, locally mapped to Remnawave Node `GermanyOVH` with management hostname `fr.coreflare.ir`.
- The management A record resolved to the same IPv4 and was DNS Only. The then-current provider preview reported zero free changes and a next fee of **2,500,000 IRR = 250,000 toman**; this is a dated observation, not a standing quote or cost authorization.
- The original impact report displayed seven apparent related Host entries and three A records, including UAE/NL addresses and a direct-IP Host on a different IPv4. It correctly reported **نیازمند رسیدگی**. Such entries must not be modified on behalf of the Arianet Node.
- PR #559 now requires explicit `Host.nodes` membership for **confirmed** ownership. Shared membership blocks execution. Empty membership on the exact old IP or selected Node hostname remains **ambiguous**. Unassigned Hosts on another endpoint are excluded from the target list, even with a shared Profile/Inbound, but **shared configuration and unresolved ownership remain a blocking audit issue**. A Host explicitly belonging only to other Node UUIDs is excluded; address collisions still block. **Revised Production counts have not yet been visually retested after the patch**.
- The operator confirmed ARI-03C2 Production status UI: "no coordination plan" is expected without a completed IP-change action; execute buttons correctly remain absent. This does **not** constitute a real write or client connection test.
- **No real Cloud create, IPv4-change POST, Remnawave/Cloudflare coordination PATCH, or successful replacement of a live Node has been recorded in this Arianet sequence.**

## Safe Production smoke after PR #559 deployment

1. Verify deployed commit and applicable migration status; in private Admin Bot open: **کنترل پنل → سرورها → سرورهای ابری → انتخاب سرور → پیش‌نمایش تعویض IPv4 → بررسی دامنه‌ها و اتصال‌ها**.
2. Confirm the report now separates "انتساب قطعی به نود", shared/ambiguous, other-Node and unassigned-other Host counts. UAE/NL/other-IP Hosts must not appear as **proven Cloud Node targets** solely because they share a Config Profile/Inbound.
3. Any unassigned Host with shared settings remains a **manual review blocker**. Review actual Host UUID/membership in Remnawave instead of forcing readiness.
4. Open **هماهنگی اتصال‌ها**: without a new completed IP change, see "no plan"; no Production write/confirmation button should exist. Confirm list/detail/history/fee/DNS read screens are unaffected.
5. Record redacted real Telegram/Provider GET evidence. Do not change any Production write ENV or DNS/Host/IP while doing the read-only smoke.

---

# ARI-01A — Arianet Cloud read-only client

**Status:** Source implementation prepared for PR review. No Production or live API acceptance is claimed.

## Goal

Shorten the future recovery path for filtered DenaGate Nodes, beginning with a
small independently testable Cloud API transport. This phase builds **only**
read-only capability; it does not execute recovery, swap IPs, buy servers,
change Cloudflare DNS, attach Cloud server IDs to Remnawave Nodes or alter the Bot.

Provider reference: https://docs.ariaservice.net/cloud-api
Cloud API origin: `https://api.ariaservice.net/cloud/v1`
Current published datacenter is OVH. Do not hard-code OVH as the only possible
datacenter: the API can add others. VPS `/api/v1` is a separate product and
**must not** be mixed with Cloud inventory.

## ARI-01A contract

- `ArianetCloudReadClient` lives in the existing Provisioning module, has a
  **fixed HTTPS origin**, accepts no arbitrary path/host/method and only sends GET.
- It reads datacenters, datacenter-scoped plans, account pricing, limits, bounded
  server pages, server details/networking/costs/IP-change history and account/
  per-server actions. Existing response envelopes are preserved for future
  Application projections. These raw envelopes must not be sent to Telegram.
- A separate `ARIANET_CLOUD_READ_TOKEN` must have only
  `cloud:servers:read`, ideally restricted to Runflare outbound IPs in the
  Arianet token allowlist. Secrets stay in deployment ENV. No token is required
  on hosts where the integration is unused.
- Requests have bounded timeouts and pages, no redirects or automatic retries;
  malformed/oversized responses and HTTP 401/403/429/503 fail closed. No provider
  response body or bearer credential is added to exception text or logs.
- No database changes, new packages, routes, jobs, schedulers, settings, public
  API, Telegram actions or provider writes. This PR is deploy-safe while unconfigured.

## Acceptance and live verification

1. Pest fake-HTTP tests must cover exact GET paths, token, envelope preservation,
   invalid parameters, missing configuration, status errors, redirects, response
   bounds, connection failure and no retries or write requests.
2. PHP 8.4 Fast CI, PHPStan, Pint and applicable MySQL smoke must pass on the
   PR head. Fake HTTP validates transport behavior, not live API compatibility.
3. **Pending real read-only acceptance:** operator provisions an independent
   read-only token via protected deployment secret (never chat, PR, logs or
   screenshots); allowlist outbound IP as appropriate. After deployment, an
   operator-approved bounded GET test should verify catalog, inventory,
   networking, IP-change eligibility/cost and monthly-rate currency against the
   Aria dashboard for a real Cloud server. Collect only redacted outcomes.
4. No merge or Production activation is inferred from source tests. Master merge
   requires explicit operator approval and the normal deployment/review gates.

## Follow-up phases (not implemented or authorized here)

- **ARI-01B:** separately guarded Cloud write operations and uncertainty
  reconciliation contracts, offline-tested without any live purchase/IP change.
- **ARI-02:** private Super Admin read-only presentation and server identity
  mapping to RW (provider server IDs remain stable when IPs change).
- **ARI-03:** operator-confirmed IP replacement with explicit cost preview,
  Cloudflare coordination and a real client-connectivity acceptance gate.
- **ARI-04:** operator-confirmed purchase, Cloud creation and handoff to existing
  NW-01/02 and NW-05 supervised installation/replacement workflows.
- Automatic filtering-triggered provider operations remain out of scope until
  a separate approved Filtering Center improvement.


## ARI-01B — Narrow Cloud write transports (2026-11-23; source PR)

**Scope:** Offline-tested client methods only for:
1. Single Cloud server creation (POST `/servers`, 201). Requires an existing
   **public SSH key ID**, fixed `count=1`, hostname/datacenter/plan/system-image
   validation. No cloud-init, arbitrary JSON, password delivery, multi-create,
   snapshot/restore, delete or other server actions.
2. IPv4 replacement (POST `/servers/{id}/actions/change_ipv4`, 202); optional
   `confirm_paid: true` is sent **only when the future authorizing Application
   Action has independently confirmed the latest fee**.
3. Result projection limited to `server_id` and `action_id`; raw provider
   responses, especially the one-time `root_password`, are never returned,
   persisted or logged.

**Transport safety:** fixed HTTPS base URL, exact operation paths, independent
`ARIANET_CLOUD_WRITE_TOKEN`, `ARIANET_CLOUD_WRITES_ENABLED=false` by default,
refuse missing/shared read/write token, bounded timeout/body and redirect denial.
Known 4xx rejections fail closed; connection failures, 429, 5xx, redirects, wrong
successful status and malformed receipts raise `ArianetCloudWriteUncertain`.
No automatic retries, no external write in PR tests.

**Critical follow-up before enabling writes:** This phase is deliberately NOT
an authorization/workflow layer. No UI, route, command or scheduler invokes the
write client. The later Application Action must enforce **trusted Super Admin
private-chat authorization at execution time, fresh read-only snapshot and fee
(in IRR), exact old IPv4 check, explicit one-use confirmation, per-server/provider
operation locks, durable create intent/idempotency and GET-only reconciliation**
against inventory/actions. A successful HTTP 201/202 means *accepted*, never
*completed*. Do not automatically reissue create or IP-change on ambiguous
outcomes. It must also coordinate Cloudflare/Remnawave changes and real client
testing under separately approved ARI-03/04 phases.

**Ops:** Do not configure the production write token or enable the new flag in
ARI-01B. Existing ARI-01A reads, credentials and live behavior are unchanged.
No new migration, dependency, Bot feature, cron, data model, Cloud purchase or
IP change is part of this PR. Real Cloud write acceptance requires separate
operator approval with a disposable staging server; fake HTTP is not live proof.

**Source of API contract:** OpenAPI 3.1 at
https://api.ariaservice.net/cloud/v1/openapi.json (createServer / serverAction).


## ARI-02A — Private Cloud read screens (2026-11-23)

**Approved scope:** A single «☁️ سرورهای ابری» entry from the existing
Admin Bot «وضعیت سرورها» screen. Trusted private Super Admins can browse
a bounded/paginated Arianet Cloud inventory, inspect server name/state,
IPv4/IPv6 and provider-reported IP change allowance/free quota/current fee,
copy IPv4, inspect per-server hourly/monthly estimated costs and action
history, and browse available datacenters/plans. Rich RTL first, existing
native HTML fallback, refresh and contextual back navigation on every page.

**Architecture:** Use the existing ARI-01A GET-only client behind a new
authorized Provisioning Application read projection. Every callback and
Application call checks the existing linked, active trusted-root authorization.
Untrusted Cloud strings are bounded/sanitized, then escaped before Rich/native
markup; no raw provider envelope, exception body or credentials reach Telegram.
Bound list sizes (8 servers/page, 5 actions/page, 5 plans/page); no N+1 costs
for an account with multiple servers. When exactly one server exists, a
second GET may supply its complete monthly-rate estimate. Missing cost or
ambiguous money/currency is shown as unavailable, never as zero.

**Non-goals:** No new database table/identity mapping (ARI-02B is separate),
Cloud write token, Cloud purchase, IP replacement, confirmed fee/payment,
Remnawave or Cloudflare mutation, filtering automation, scheduler, queue,
settings or package. Existing server/Node/Host/DNS actions and navigation
must remain unchanged. This PR does not enable ARI-01B write transport.

**Acceptance:** Fake-HTTP and fake-Telegram tests cover trusted/private
root, denied delegated and revoked users, GET-only reads, response bounds,
provider errors/partial failures, real IP copy button, Persian currency
display, Rich escaping/fallback semantics and navigation. PHP 8.4 full Pest
CI, PHPStan, Pint, MySQL smoke where required. Real Telegram iOS/desktop
rendering and live Cloud GET smoke are operator checks after deployment;
source tests do not prove either.

**Follow-up:** ARI-02B separate Super Admin-confirmed Cloud-server-ID to
Remnawave-node mapping; ARI-03 root-confirmed, price/IP-verified change and
coordinated DNS/connection check; ARI-04 safe purchase and existing NW-01/02/05
handoff. Never treat filtering alarms as automatic authority to write.


## ARI-02B — Confirmed Cloud Server ID ↔ Remnawave Node UUID mapping (2026-11-23)

**Approved scope:** a manual, root-only local mapping from the ARI-02A Cloud
server details. Show existing mapping and verified Node name when available;
select another existing Remnawave Node, see a Rich RTL preview with Cloud
IPv4, Node address and a distinct exact-IP/domain/different-IP warning,
then confirm once to save/reassign or explicitly detach. Never infer mapping
from a matching hostname. For Node management hostnames the IP is *not*
automatically verified; the operator must separately inspect its DNS target
before accepting a mismatch or domain-only address.

**Data:** One additive table `arianet_cloud_node_links` with
`cloud_server_id` unsigned bigint PK and unique lowercase `node_uuid`.
Store only that identity pair, created_by/updated_by and timestamps. Neither
IP nor DNS hostname is used as the identity key. Unique constraints prevent
two Cloud servers linking one Node or one Cloud server linking two Nodes.
There is no external-provider FK: Cloud and RW identities are remote data.
Rollback refuses to drop a table containing confirmed mappings.

**Execution boundary:** Trusted, active Super Admin with a configured
allowed private Telegram identity is checked in UI and independent
Provisioning Application Action. Choose only from bounded live RW GET
inventory (max 500 Nodes), excluding already assigned Nodes; up to eight
candidates per page, exact IPv4 matches first. A one-use actor-scoped
ten-minute preview is stored in the existing cache. On confirmation, consume
the token before mutation, reauthorize, re-read Cloud/Node and reject changed
Cloud IPv4 or Node address; serialize writes with a cache lock, transaction,
current-mapping compare and database uniqueness. After any failure, the
operator reopens the preview. Unlink validates the current saved relation
without requiring live provider uptime. Expired/replayed/stale/forged and
revoked/group requests must not write any local state.

**Non-goals:** No Cloud IPv4 change/purchase, no provider writes, no Remnawave
Node update, no Cloudflare DNS update, no automatic DNS resolution or filtering
remediation, no installer changes or background tasks, and no new dependency
or ENV flag. The ARI-01B write transport remains default OFF. The mapping
records an operator-asserted relationship, **not** proof of IP reachability,
connection quality or Cloudflare configuration.

**Acceptance:** Fake Cloud/RW GET + fake Telegram and RefreshDatabase tests
must validate exact mapping/replacement/unlink, one-use confirmation,
role revocation, candidate ordering, IP/domain/mismatch warnings,
same-Node cross-server rejection, stale reads, no provider POST, native RTL
Rich and fallback, and the strict Admin-only route inventory. Run PHP 8.4
suite, PHPStan, Pint and disposable MySQL migration/rollback before approved
merge. Live Runflare migration, API reads and Telegram iPhone/desktop
presentation remain separate post-deploy checks; no live mapping is
created automatically by this PR.


## ARI-03A — Read-only IPv4 change preview (2026-11-23; PR)

**Operator-approved scope:** Add one native Rich RTL «🔎 پیش‌نمایش تعویض IPv4»
button to existing Cloud server details. For a private trusted Super Admin,
read a fresh Cloud server snapshot and show its old IPv4, live
`ip_change.allowed`, `pending`, `free_left`, number of previous changes and
verified IRR fee. Read the existing Cloud Server ID ↔ Remnawave Node UUID
mapping; show the Node management address and distinguish matching direct
IPv4 from mismatched IPv4 and domain-based addresses. If a Node management
hostname belongs to an existing allowed Cloudflare zone, reuse the existing
`ManageCloudflareDns::replacementRecord` **GET-only** validation to show one
unique editable A record's current IPv4 and proxy state. Any unavailable,
ambiguous, out-of-zone or unsupported DNS remains **unverified**.

**Safety boundary:** This is not execution readiness/authorization. The
preview is not stored, creates no operation intent and contains **no**
confirmation, submit, POST, PATCH or mutation route. No Cloud write Client,
Remnawave update or Cloudflare write call is reached; all provider calls are
GET. Display errors as unavailable, not absent; missing or non-IRR fees as
unknown, not free. Show a warning about IP-change impact, direct IP Node
address changes, and the incomplete scope of DNS discovery. Specifically,
**subscription Host domains, external DNS, CDN/protocol targets, other
Cloudflare records and actual connectivity are not exhaustively inspected**
by ARI-03A, and therefore may require manual planning in ARI-03B/C.
Never claim a positive match guarantees safe change or client reachability.

**Acceptance:** Private root-only route isolation, safe Rich RTL and HTML
fallback, exact source IP and fee display, native copy/current-IP button,
refresh/back controls, tested Cloud/RW/Cloudflare GET-only integration,
same/different/unavailable DNS, direct-IP and hostname cases, missing mapping,
missing fee, denied and group requests; CI PHP 8.4, PHPStan, Pint and
applicable MySQL smoke. Live Telegram/Runflare GET preview requires
separate operator acceptance after deployment.

**Explicitly out of scope:** Cloud IP replacement, Cloudflare DNS updates,
Remnawave Node/Host address changes, purchase, root confirmation, automatic
reconciliation/probes, persistent events, settings, ENV, database migrations,
additional packages or new scheduler jobs. Keep `ARIANET_CLOUD_WRITES_ENABLED`
false; ARI-03B and ARI-03C require independent operator approvals and PRs.


## ARI-03B — Staging-only one-shot Cloud IPv4 write (2026-11-23)

**Approved handoff:** ARI-03A has been confirmed visually on a real
production Cloud server; Node `GermanyOVH` / `fr.coreflare.ir` currently
points to its original IP. Changing this server's IP without coordinating DNS
and Remnawave would disrupt active clients. ARI-03B therefore implements an
**independently staged, non-production-only** write path, not production
authorization. No Production write is permitted even if
`ARIANET_CLOUD_WRITES_ENABLED` is accidentally enabled.

**Application:** The trusted-root Admin Bot can (only in staging where the
existing Cloud write transport is deliberately enabled) prepare one ten-minute,
actor-bound Cloud IP-change confirmation. Before preparation and immediately
before apply the Action reads a fresh Cloud/Node/managed-DNS snapshot,
requiring a running Cloud server, public old IPv4, `ip_change.allowed=true`,
`pending=false`, verified free quota, and an explicit valid IRR fee when
quota is exhausted. The linked RW Node must have a matching direct IPv4 or an
allowed, unique, unproxied Cloudflare A record matching the old IPv4. It also
rejects stale price, quota, IP, Node or DNS changes. Confirmation must be
one-time and cannot be shared between operators.

**Durability:** A small additive `arianet_cloud_ipv4_changes` journal stores
immutable old IP, baseline fee, fingerprint and a single active slot per Cloud
server. Before any outgoing provider POST it durably marks a confirmation
`reserved`. The ARI-01B transport then sends precisely one POST
`/servers/{id}/actions/change_ipv4`, including `confirm_paid: true` only
for a currently verified paid change. A 202 is `accepted`, not success.
An uncertain timeout, 429, 5xx or malformed receipt remains blocked
`uncertain`. A rejected/ambiguous result never triggers automatic retry.
The only supported cancellation releases a **prepared, never-sent** token;
accepted/uncertain operations stay reserved.

**Reconciliation:** An operator requests a bounded GET of the exact
`action_id`; require id, command and server resource identity to match.
For action `success`, read the server's new public IPv4 and verify it differs
from the old one. Mark `awaiting_coordination` and keep the active slot
reserved; do not call this completed client connectivity. Missing identity,
unverified IP, failed or uncertain actions require manual review, with no
automatic write retry or DNS/Remnawave changes.

**UI:** Rich RTL and authorized HTML fallback, with trusted Super Admin
authorization on both callback and application. The prepare button is
**absent on Production**. Native buttons for confirm/cancel, and operator
status/reconcile are Admin-only; no Sales/Business bot routes added.

**Safety & acceptance:** CI uses fake provider HTTP with exact single POST,
staging-only gate, changed price/IP/Node/DNS, role revocation, replay,
one-active-server DB constraint, definite/uncertain outcomes and GET
reconciliation. MySQL migration/rollback, PHP 8.4, PHPStan and Pint required.
Runflare Production must retain `ARIANET_CLOUD_WRITES_ENABLED=false` and
`ARIANET_CLOUD_WRITE_TOKEN` unset. A controlled **disposable staging Cloud
server** with verified independent DNS/Node setup and explicit operator
approval is still required before any live API write; do not use the currently
mapped Production server for a test.

**Next ARI-03C (not authorized by this phase):** coordinate all assigned
Remnawave Host endpoints, Node DNS, Cloudflare records and real client
connectivity before permitting production IP replacement. Production
execution remains hard-disabled in this phase. No new settings or ENV,
background jobs, billing transactions or provider mutations are performed
during deployment.
## ARI-01C — one Arianet API token, strict write gates (2026-11-23)

The operator has one Arianet Cloud Bearer API token. Arianet documentation
allows issuing one token with both `cloud:servers:read` and
`cloud:servers:write` scopes. A separate `ARIANET_CLOUD_WRITE_TOKEN` is
**not** required or consumed. All GET requests and gated write transport now
read `ARIANET_CLOUD_READ_TOKEN`, the existing deployed setting. No token
value or permission is modified by the source change.

**Security:** `ARIANET_CLOUD_WRITES_ENABLED=false` stays the independent
default-OFF kill switch. The write transport now also rejects all POSTs in
`production` even if the flag is misconfigured. ARI-03B continues to require
trusted private root, one-use confirmation, fresh fee/Node/DNS snapshot and
non-production operation. Enabling write scopes on the existing token does not
enable any server mutation. Do not alter live Production credentials or flags
in this PR. A staging-only disposable server and separately authorized real
test are still needed. Every existing GET remains available with the same token.
## ARI-03C1 — Host/Node/DNS coordination impact audit (2026-11-23)

**Operator-approved ARI-03C scope, first safe increment.** A private,
trusted-root read-only report is linked from the existing Cloud IPv4 preview
and summarizes all known Remnawave Host/Node and Cloudflare dependencies.
The report reuses the current `PreviewAdminArianetIpv4Change`,
`ChangeAdminArianetIpv4::status`, `ManageAdminNodes`,
`ManageAdminHosts::replacementInventory` and the existing allowlisted
`ManageCloudflareDns::replacementRecord` GET checks. No alternative
provider adapter, no extra API credentials, no new dependency or scheduler.

**Evidence and bounded behavior:** For the linked Node, inspect its live
profile, inbounds, management address/port and its complete bounded Host
inventory (up to 300 hosts). Include explicitly assigned Hosts, direct-IP
endpoints, managed unique A records and management DNS. Host membership
shared with peers, legacy Hosts without confirmed Node membership,
unverified DNS, mismatched IP, unsafe Proxy on management DNS,
peer-Node endpoint overlap and unsafe/unavailable provider data all block a
clean report. Incomplete Host/DNS ownership is **not** interpreted as an
empty/nonexistent dependency. Limit summarized Host and DNS details to ten;
do not N+1 query every unrelated Host hostname. An existing
`awaiting_coordination` IPv4 change is read by ID and its old/new IPs
are shown separately, requiring the live Cloud IPv4 to match the new IP.
Missing or ambiguous data is displayed as requiring manual review.

**User experience:** Native Rich RTL summary and escaped Host/DNS tables,
same authorized HTML fallback and refresh/back controls. No technical phase
names, raw tokens/provider envelopes, or implicit success promise in the UI.
The report makes clear that a clean match does **not** attest actual client
reachability, CDN targets, out-of-zone/external DNS or comprehensive packet
routing. A non-root/group request never reads provider data.

**No writes in ARI-03C1:** This phase only analyzes dependencies and does
not mutate Cloudflare A records, Remnawave Node/Host state, the Cloud IPv4
or billing. `ARIANET_CLOUD_WRITES_ENABLED=false` in Production remains
mandatory; the write transport remains hard-blocked there. A subsequent
**ARI-03C2** needs a separately reviewed staged, durable step-by-step
DNS/Host/Node coordination and operator-verified client connection test
before any permission for Production IPv4 operations. The existing
NW-05 replacement write checkpoints should be reused rather than
duplicating their logic. Never auto-retry ambiguous DNS or Provider writes.

**Acceptance:** PHP 8.4 full suite, PHPStan, Pint, disposable MySQL
smoke, Admin-only route inventory, provider GET-only assertions, live-shape
Host/DNS/Node fixtures, shared and ambiguous Host detection, Cloud
post-change target read, missing mapping, denied/groups and native Rich
rendering. Real Telegram and DNS inventory QA after release remains
operator evidence separate from CI.

## ARI-03C2 — staging-only durable DNS/Host/Node coordination (2026-11-23)

**Approved incremental implementation:** The operator verified the ARI-03C1
real Telegram impact audit: seven Host entries, including endpoints associated
with other providers/regions, one direct-IP Host on a different IPv4, and
A records for UAE and Netherlands that do not point to the Arianet server.
This is **NOT** sufficient evidence to mutate those Hosts or DNS records.
The current audit correctly reports "نیازمند رسیدگی" and must fail closed
for a staged coordination preparation. No ownership inference from shared
Profile/Inbound or an apparently similar hostname may override conflicting
Remote Node/Host membership.

**Execution boundary:** ARI-03C2 is available only outside Production,
and only with the *existing* Remnawave and Cloudflare independently gated
write transports deliberately enabled. The Arianet Cloud API write switch
remains default OFF; the Cloud write transport and this coordination action
are both hard-blocked in Production. No new secrets, API connectors, queue
jobs or schedulers. No live Provider requests occur as part of CI/deployment.

**Plan preparation:** Only after ARI-03B has a durable active status
`awaiting_coordination` with a verified *new* Cloud IPv4 may the trusted
private root prepare this plan. Reuse the ARI-03C1 GET impact audit and
current `ManageAdminNodes`, `ManageAdminHosts`, `ManageCloudflareDns`
snapshots. Reject *all* reported issues, ambiguous legacy/shared Host
membership, DNS content different from the immutable old IPv4, wrong Node
mapping, unavailable records and non-public IPv4. Save the bounded, exact
expected provider identities and ordered DNS → direct-IP Host → direct-IP
Node steps in an additive JSON column of the existing durable
`arianet_cloud_ipv4_changes` row. Do not create a parallel lifecycle.

**One-step execution:** A root must explicitly press the next individual
step button. Re-check the Cloud and complete Node/Host inventory plus
current step identity under the existing Cloud operation lock. Mark that
step `uncertain` durably **before** the first provider mutation. Reuse
`ManageCloudflareDns::changeA` with strict A-record uniqueness and
preserved Proxy, `ManageAdminHosts::updateReplacementAddress` for
direct-IP Hosts, and `ManageAdminNodes::updateReplacementAddress` for
direct-IP Nodes. The existing Actions revalidate protected identities.
After every mutation independently GET-confirm the desired new IPv4 and
protected fields, marking the step verified; never replay a consumed step
callback. If the HTTP response or PHP process fails, leave it uncertain,
and let the operator reconcile **only with GET**, never automatic retry.
Do not automatically roll back partially applied provider settings.

**Operator acceptance:** After all steps verify, mark
`awaiting_client_test`. The operator must independently test a real
client through an affected connection before explicitly accepting. Recheck
Node connection and all DNS/Host/Node endpoints before completing the
journal and releasing its active Cloud server reservation. Do not equate
Remnawave connected status with proof of actual client traffic.

**Safety gaps and release:** The production VPS may not be used as a
disposable test target; authorization of any live Cloud/CF/RW mutation
requires separate explicit operator approval. The app implements only
staging execution for this milestone. Real client, CDN/external DNS,
filtering and transport quality must be tested independently.
The current seven-Host ambiguous production audit blocks any attempt
to prepare/execute a coordination plan. Fix membership/assignment
evidence through the normal reviewed operations, not by forcing the
write flow. Testing uses fake HTTP/Telegram/MySQL (not real Provider)
plus CI PHP 8.4, PHPStan, Pint and MySQL/rollback. Production must
keep `ARIANET_CLOUD_WRITES_ENABLED=false` and all other provider
write gates at their existing approved values. No ENV changes required
for read-only Prod UI.

## ARI-03C ownership correction — shared Profiles are not Host ownership

The production audit showed UAE, NL and a direct-IP Host belonging to
other servers among seven Cloud Node dependencies because empty Host
membership was treated as a match when a Config Profile/Inbound was shared.
Profiles and Inbounds are reusable across Nodes, NOT ownership evidence.

- Host explicitly assigned to selected Node UUID: included. Multiple Node
  membership stays shared/blocked until resolved.
- Host with empty Node membership and exact old IP or Node hostname:
  included as legacy/ambiguous and blocked pending manual verification.
- Host explicitly assigned only to another Node: excluded and counted
  separately, though exact address collisions still block.
- Host with empty membership and another endpoint: excluded and counted
  separately, even if its Profile/Inbound matches the selected Node.
  Any such matching Profile/Inbound leaves the overall audit BLOCKED until
  actual Host ownership is verified, rather than incorrectly declaring ready.
- UI separates explicitly assigned, shared, ambiguous and unrelated counts;
  unrelated Host domains do not trigger any DNS checks.

ARI-03C2 continues to fail closed when Host assignment is unresolved, and
revalidates membership before each write. No Provider writes, ENV, schema,
dependencies or payment changes. Verify the actual Frankfurt Host assignment
in Remnawave before any live node replacement or production operation.


---

## ARI-04 — Cloud VPS purchase and existing Node replacement (live acceptance pending)

**User goal recorded on 2026-11-23:** exercise an actual Arianet Cloud Write by purchasing **one** Cloud VPS **inside the private Admin Bot**, then using it to replace one existing Remnawave Node while retaining the Node's identity and client configurations. This expresses the future product goal, **not approval to charge the Arianet account, enable Production writes, modify real DNS/Remnawave data or retire a server now**.

**Decision still open:** which exact Node will be replaced (NL vs UAE or another approved Node), exact Cloud datacenter/plan, image, hostname, SSH public key ID, expected charge, billing limit, maintenance window, user-network test and rollback destination. No Node has been selected for real replacement in the current request. Do not default to the existing `GermanyOVH` or modify its current live Cloud server.

### ARI-04A — private read-only purchase preflight and quote

**Scope:** extend the existing `☁️ سرورهای ابری` Admin Bot screen with a selectable single-server quote workflow. Reuse `ReadAdminArianetCloud` and existing plan/datacenter browse rather than building a duplicate catalog. Validate fresh datacenter availability, active plan ID, CPU/RAM/disk, plan availability, hourly/monthly amounts and `currency === IRR`. Show `unknown` if any required price is missing; never use 0 as a fallback.

**Creation prerequisites:** the existing write client accepts a restricted tuple of validated (`name`, `datacenter`, `plan_id`, `image`, one existing `ssh_key_id`) with `count=1`. The current read client does **not** expose SSH-key or OS-image discovery, so first verify the actual provider API contract and supply a *validated existing public SSH key ID* and an officially supported image. Do not invent image slugs or key IDs, transmit private SSH keys, present a fabricated cash balance, or infer that a purchase can be made from the existing read-only plan page. Display the intended replacement Node only as an optional read-only target; no link or Node mutation.

**Acceptance:** trusted private root, Rich RTL + authorized fallback, live provider GET validation, missing data and stale quote blocking, pricing/IRR tests, exact single-server preview, no POST; independent PR/CI and real bot visual check.

**ARI-04A implementation checkpoint (2026-11-23; review [PR #563](https://github.com/DenaSon/skynet/pull/563)):**
A private root-only purchase-quote wizard is scoped to `سرورهای ابری → پیش‌نمایش خرید VPS`.
The workflow selects an orderable datacenter, an active/available plan, a nondeprecated Ubuntu/Debian
system image available in that datacenter and fitting the plan disk, then accepts a safe server name
and an existing numeric public SSH-key ID. It validates the Cloud `GET /datacenters`,
`GET /plans?datacenter=...`, `GET /images?type=system&datacenter=...` and
the **existing** account SSH public key via `GET /api/v1/ssh-keys/{id}`.
The latter read requires the token's `ssh-keys:read` scope; if unavailable, quote validation fails
closed and the operator sees a short message, never a fabricated key. Only id/name are displayed,
not the key material. The quote uses strict `IRR` hourly/monthly plan prices and its minimum
up-front hours. It explicitly labels these as **base plan estimates**, not a validated
all-in invoice, account balance or authorized charge. The draft is private, TTL-bounded in
the existing cache; no new DB schema, secret store, queue, write action or buying button is created.

**ARI-04A SSH-key picker UX patch (2026-11-23):** replace the manual numeric SSH-key ID
entry after the server name with an eight-item paginated button list read from the
same account's `GET /api/v1/ssh-keys?page=N&limit=8`, guarded by the existing
`ssh-keys:read` permission. Display only escaped key names and numeric IDs, never
public-key bytes or private keys. Each button is revalidated against a fresh
account-key list for that page, and the existing quote still revalidates the
selected key through `GET /ssh-keys/{id}`. Empty catalog, inaccessible scope,
stale/forged callbacks and expired draft fail closed with refresh/return controls.
No SSH key creation, extra secrets, Cloud purchase/POST, Node change or new
schema/dependency is introduced. Fake HTTP and Admin Telegram tests are separate
from a live provider-key-list acceptance.

**NW-SSH-KEY-01 preparatory phase (2026-11-24):** The operator reports
registering a dedicated Arianet Ed25519 public key and a protected Runflare
`NODE_SSH_PRIVATE_KEY_B64` Secret. The proposed implementation reuses the
existing `postStart` SSH runtime with an optional, fingerprint-pinned
(`SHA256:y/drdp9P+7FscuHjq1ckcCjUjZhnuS+Chl9HJfYehZQ`) decode
for two permission-isolated runtime paths: PHP-FPM `www-data` at
`/run/secrets/denagate-node-ssh` and queue `www` at
`/run/denagate-node-worker/identity`, both root-owned 0640. Separate
paths are necessary because these existing processes are not assumed to
share a supplementary group. Missing/malformed/unexpected keys fail closed
without breaking password SSH or the application. No SSH-key login behavior
or new Cloud/Remnawave/DNS write is introduced in this first phase; the
actual secret injection, process access and fingerprint still require a
separate Production check after deploy. SSH-key authentication of the
existing preflight/installer is NW-SSH-KEY-02 and needs its own PR/CI/live
acceptance; stage 1 does not license real VPS purchase.

**NW-SSH-KEY-02 dual-auth source scope (2026-11-24):** Existing NW-01/NW-02
preflight and installer now accept an explicit `password|key` auth mode,
with missing mode still treated as legacy Password for enqueued jobs. For key
mode the Admin wizard selects a non-exportable dedicated server-managed
Ed25519 identity after `root`; OpenSSH is restricted to exactly that identity
and the existing pinned host fingerprint. UI revisits auth choice on Back,
preserves password message deletion, retains RBAC and the separate install
confirmation. The queue never receives private key bytes, only the mode.
No Cloud purchases, new VPS, RW Node replacement or DNS writes are initiated
by source changes. Green CI is **not** a live provider login or live purchase
approval; test the actual provider-created VPS only after a separately approved
transaction, then independently authorize cutover.

**Historical integration gap (resolved in source by NW-SSH-KEY-02, pending live acceptance):** NW-01/NW-02's previous installer supported SSH **password authentication**,
whereas this Cloud create transport takes exactly one **existing public SSH key**. The original ARI-04A work did not include password-based Cloud purchases or the later NW-SSH-KEY-02 key installer. Before ARI-04B
live purchases and ARI-04C handoff, choose and separately approve the simplest safe way to
bridge this mismatch; do not buy a server and assume the old installer will work.
No real purchase or Node/DNS mutation is authorized by ARI-04A.

**ARI-04B source implementation (2026-11-24; subject to PR CI/live acceptance):**
one dedicated `ARIANET_CLOUD_PURCHASE_ENABLED=false` Production create gate
is added to the existing restricted `POST /servers` transport. The previous
generic Cloud write flag alone never permits a Production purchase; the purchase
switch does not turn on unrelated IPv4 writes. Private root-only Admin callbacks
prepare a single durable account-wide intent in `arianet_cloud_purchases`,
freeze exact server identity and IRR plan-only quote, and show a separate Rich
RTL financial confirmation. Confirm atomically consumes the token/reserves the
operation in DB before **at most one** Cloud POST. Invalid/mismatched responses,
transport failures and ambiguous errors must not be replayed. Persist only
matched server/action IDs. GET-only reconciliation accepts verified matching
create action and server identity; unknown outcomes remain blocked for manual
review. No actual VPS purchase, Runflare flag update, Remnawave or DNS operation
is executed by this source PR. Hourly/24-hour wallet requirement, possible
additional primary IPv4 and tax costs, and powered-off billing are explicitly
disclosed. The operator must issue a separate final real purchase approval.

### ARI-04B — durable, one-use, explicitly paid single-Cloud-create action

**Scope:** a separate PR and deployment safety review. Reserve a durable purchase intent with an immutable (server name, exact plan/datacenter/image/key, cost snapshot, actor, expiration), strict one-active-intent limit, and one-use actor-bound paid-confirmation under a lock **before** any HTTP POST. Revalidate fresh plan availability and IRR quote at confirmation. Require explicit confirmation of real financial exposure; refuse changed price or unavailable resources. The UI must be clear about ongoing billing, not just creation success.

**Production gate change is required:** current `ArianetCloudWriteClient` rejects **every** POST in Production, including `createServer`; setting `ARIANET_CLOUD_WRITES_ENABLED=true` is insufficient. For this phase, implement a separate default-OFF *purchase-only* authorization path with independent kill switch and exact create method authorization, reviewed before rollout. Keep `changeIpv4` and every unrelated Cloud POST blocked in Production. Reuse the single Bearer token; do not reintroduce `ARIANET_CLOUD_WRITE_TOKEN`. New purchase-only ENV names are **proposals, not existing configuration**; do not add/enable them before their scoped implementation is reviewed.

**One POST only:** use the existing restricted `createServer` transport (`POST /servers`, expected 201 and matching server/action receipts, one public SSH key, `count=1`). An HTTP success means *accepted for creation*, not ready/paid reconciliation. Discard any one-time root password; never show, store or log it. Persist trusted server/action identifiers and separate accepted, uncertain, needs_review and completed states. Timeout/429/5xx/malformed result remains **uncertain and non-retryable**; only operator-triggered, bounded GET inventory/actions may reconcile the same request. In uncertain cases, do not create another VPS to "fix" the first one. Do not add an unreviewed billing-wallet journal or second financial ledger for provider purchase.

**Acceptance:** fake HTTP (one POST, one price-confirmed actor, replay/timeout/ambiguous and wrong receipt, Production purchase-only gate, IPv4 still prohibited, lock/database uniqueness), migration/rollback, PHP 8.4/8.5 as CI requires, PHPStan, Pint, Admin Bot Rich/fallback and denied Sales/group access. A green PR **is not permission for a live purchase**: obtain a **separate explicit final user approval** with current plan, hourly/monthly estimate, possible ongoing charges and account resource availability.

### ARI-04C — creation readiness and supervised replacement handoff

**Scope:** after one authorized live create, poll only the matching action/server with bounded GET and independently verify a new public IPv4 and eligible server state. Do not infer readiness from POST 201. For the same newly created VPS, reuse existing supervised `NW-01/NW-02/NW-03` SSH install and `NW-05` replacement UX/actions; avoid parallel SSH installers, Host APIs, DNS update logic or direct DB mutation of the Remnawave identity mapping.

**Pre-replacement gates:** select and confirm the exact existing Node UUID/name and **entire** live Host/Inbound/Config Profile inventory. Run ownership audit again; only Node UUID membership with verified endpoints is a write target. Resolve any shared/unassigned Host ambiguity and DNS mismatch with the normal operator-reviewed Remnawave flow, not a bypass. Ensure new IP is not assigned to any peer Node, including disabled/disconnected ones. Preview affected domain A records, their Proxy state, DNS-only management endpoint, direct-IP Hosts, routing, fallback/rollback plan, risk of client interruption and accepted maintenance window.

**Execution and acceptance:** require a *separate* one-use operator confirmation for replacing the selected live Node. Wait for new VPS installer connectivity; use NW-05's reviewed DNS/Host/Node update flow, stable Node UUID and identity-preserving checks. Persist one-shot uncertain checkpoints before each remote PATCH; reconcile by GET, never automatically replay partial operations. Keep the old VPS online and intact. Test at least one real client through the affected Node from an actual user network (including relevant Iranian operator where practical), check connectivity/traffic, then accept. Never call a green RW node flag alone a successful client test. Retirement/cleanup of the old VPS is a future explicit operational decision, **not** this automatic flow.

## ARI-04C — verified Cloud handoff to existing NW-05 (2026-11-24)

Operator approved completing the source phase before all real operational tests.
From the private Admin Cloud inventory, resume the actor-owned latest purchase.
Only a journaled completed create with matching live successful create action
and server ID, name, datacenter, plan, running status and global public IPv4
may enter the readiness screen. No POST or purchase switch is required for
this read. Show the ordered system image and public-key ID as order metadata,
not proof of actual OS or SSH access. Disabled Nodes are omitted from the
bounded selection. Reuse the exact existing NW-05 inventory audit before
handing off to NW-01/02 with the purchased IPv4, root, port 22 and key mode
prefilled. An already locally linked Cloud server is rejected at handoff.
Keep explicit SSH fingerprint approval, SSH OS/auth preflight and separate
one-use install and cutover confirmations. Back from the Cloud preview
returns to the order rather than allowing a different IP to be silently
substituted. Bind the purchase ID in existing installation checkpoints;
freshly recheck the owned public VPS before scan, preflight, enqueue, launch
and NW-05 cutover/acceptance. Preserve existing manual/password installs,
Host/Node identity and DNS verification. Read-only installer polling uses
the existing protected PHP-FPM key if available; launch keeps the separate
queue key. No additional table, scheduler, flag, secret, parallel installer,
automatic remapping, real purchase, install, DNS/Node mutation or VPS deletion.
Once real replacement and client connectivity are accepted, use the existing
explicit Cloud link/unlink flow to correct local inventory mapping where
needed; retain the old VPS for rollback. CI is not live acceptance.

### Required real Write test / operator go-no-go

1. First verify the PR #559 **read-only** audit in live Admin Bot and resolve target ownership evidence, without changing anything automatically.
2. Select **one** replacement Node and confirm which existing production customer connections are affected. Do **not** select by matching Profile alone.
3. On a deliberately scoped single-VM test, display a fresh official datacenter/plan/OS/key quote with monetary exposure. Secure user approval for the *exact live purchase*, not a blanket permission for all future creates.
4. Enable only the reviewed purchase-specific gate for this operation; observe exactly one POST and action ID, verify through GET and disable the gate after the batch. If ambiguous, stop and reconcile before any new purchase.
5. Install and verify new VPS independently, with old production Node preserved. Require a new approval before any production Node/Host/DNS replacement, then record genuine user-client connectivity.
6. Report outcomes separately: code/CI, deployed bot, real provider purchase, verified VPS provisioning, Node cutover, client acceptance, rollback/old-VPS retention and any ongoing cost. Record no secrets/PII in the evidence.

**Do not begin** real purchase, IP replacement, direct Cloud Write, DNS/Host mutation, auto-filtering remediation, multi-VPS creation or decommission under this *documentation-only* update. ARI-04A → ARI-04B → ARI-04C are **proposed, independently reviewed implementation phases**, not merged features. A Production write-path design approval and a separate per-transaction operator approval are mandatory.
