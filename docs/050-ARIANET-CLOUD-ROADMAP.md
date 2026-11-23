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
