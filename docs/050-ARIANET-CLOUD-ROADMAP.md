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
