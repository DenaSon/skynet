# SUP-DIAG-01 — Customer service diagnostics (2026-11-22)

**Status:** Source implementation in three stacked Draft PRs; **not merged or live-verified**. Scope approved by operator. No live Provider POST, schema migration, production change or Deniz tool granted by these PRs.

## Delivery sequence

1. [#525](https://github.com/DenaSon/skynet/pull/525) — root-scoped, identity/owner-verified read-only diagnostics: per-user traffic 1/7/30 calendar days, recent subscription request history and per-service accessible nodes matched to the existing Filtering Center.
2. [#526](https://github.com/DenaSon/skynet/pull/526) — separately gated, root-only connection observation POST + bounded GET result; Cache::add reservation before POST prevents a duplicate request after an uncertain outcome.
3. [#527](https://github.com/DenaSon/skynet/pull/527) — Admin Telegram 360-service-detail entry and RTL Rich-first diagnostic pages, same-view native fallback and contextual return navigation.

PR #526 is based on #525; #527 is based on #526. Review and land in dependency order **only after CI, security review and explicit operator approval**. Retarget each stacked PR to master when its dependency lands.

## Strict scope and contract

- Reuse existing `ReadCustomerLiveServices::detail` and durable order -> provisioning link -> exact remote user binding. On every diagnostic request, require current `SuperAdminTrust` and owner/remote-ID match. Recheck the local owner/reference after the Provider call; fail closed on revocation/mapping race.
- Use pinned Remnawave **3.4.4** operations: `BandwidthStatsUsersController_getStatsNodesUsage`, `UsersController_getUserSubscriptionRequestHistory`, `UsersController_getUserAccessibleNodes`, `ConnectionsController_connectionsByUser` and `ConnectionsController_connectionsByUserResult`.
- A period is a **UTC calendar-date** range (today, 7 or 30 calendar days), not a rolling 24-hour period. Historical/period traffic is not the same metric as an account's cumulative subscription total. Show only validated totals and at most five top nodes; no fake zero from missing data.
- Subscription request history is **not** proof of successful VPN connectivity. Only present bounded timestamps; withhold request IP, User-Agent, remote record ID, sensitive metadata and raw Provider DTOs.
- `ReadFilteringCenter` is the existing source for monitored node state. No Check-Host requests, active probes or new scheduler. Unknown/stale monitoring never implies healthy/blocked.
- The connection endpoint creates an **observation job** with POST, even though it should not mutate a customer's service. It is **independently disabled by default**: `REMNAWAVE_CONNECTION_DIAGNOSTICS_ENABLED=false`. An authorized explicit button starts only one job per actor/order for a short cache TTL; ambiguous failures are not retried. Polling is a separate GET. Output contains only node names and counts of observed address records—not IP addresses, device identity or a reliable active-session count.
- Preserve all current Admin/Sales Bots, role permissions, service status confirmations, provider write gates, order/accounting operations and filtering reports. No migration, package, new credential, UI for ordinary support operators, AI agent, customer self-service or bulk diagnostics.

## Acceptance gates

1. PHP 8.4 Pest (focused and full), Pint, PHPStan and MySQL CI green on each PR; PHP 8.5/full scheduled compatibility per active CI policy. No invented CI or device acceptance.
2. Malformed response, invalid owner ID, forged callback, root revocation, stale mapping, missing Provider, overflow and sensitive-data redaction fail safely.
3. Verify contract with the real pinned RW instance using **read-only** known owned test accounts. Validate actual response shapes, UTC date/time interpretation and whether the token's scopes permit diagnostic endpoints.
4. Before **any** live connection POST, separately approve the gate change and one disposable/known test user. Confirm job duration, rate limits, no customer interruption and privacy on a real panel; keep the gate OFF otherwise.
5. Independently check real Admin Telegram Rich/native fallback, back buttons, revocation and prior service/status actions on actual device.
6. Production deployment and the behavior observed after deploy are separate from source CI. `master` auto-deploys; no merge until authorized.

## Future Deniz boundary (not implemented)

`ReadCustomerServiceDiagnostics` returns small, sanitized, actor-authorized structured data that a future independently approved Deniz tool could consume. No new model tools, autonomous searches, personal Business support access, secrets, connection-drop calls or elevated AI permission are included.

**Rollback:** revert the three PRs in reverse dependency order; keep connection diagnostic gate OFF. No persistent schema/data migration is involved.
