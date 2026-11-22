# SUP-DIAG-01 — Customer service diagnostics (2026-11-22)

**Current status (2026-11-22):** SUP-DIAG-01 source PRs #525–#527 and last-seen patch #528 are merged. The operator reported a successful real Remnawave connection observation in Admin Bot; comprehensive live acceptance is **not yet recorded**. SUP-DIAG-02's scoped privacy-aware IP/history expansion is under review in #529–#530. No additional remote operation, schema migration, Deniz tool, or production flag change is part of these PRs.

## Delivery sequence

1. [#525](https://github.com/DenaSon/skynet/pull/525) — root-scoped, identity/owner-verified read-only diagnostics: per-user traffic 1/7/30 calendar days, recent subscription request history and per-service accessible nodes matched to the existing Filtering Center.
2. [#526](https://github.com/DenaSon/skynet/pull/526) — separately gated, root-only connection observation POST + bounded GET result; Cache::add reservation before POST prevents a duplicate request after an uncertain outcome.
3. [#527](https://github.com/DenaSon/skynet/pull/527) — Admin Telegram 360-service-detail entry and RTL Rich-first diagnostic pages, same-view native fallback and contextual return navigation.

The earlier stacked source PRs have been merged to master; they remain historical development records. Production behavior and live acceptance are tracked separately from CI.

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

## SUP-DIAG-02 — scoped root support details (operator-approved 2026-11-22)

**Dependency chain:** [#529](https://github.com/DenaSon/skynet/pull/529) (per-node IP details) → [#530](https://github.com/DenaSon/skynet/pull/530) (subscription-request metadata/history). Both require CI, review and separate production acceptance.

### Approved data release in private Super Admin diagnostics only

- The existing connection observation POST and GET job results are unchanged: same allowlisted root, exact order owner and remote user identity, atomic Cache reservation, short TTL and no auto-replay. New detail callbacks only **read the existing job**.
- Show validated IPv4/IPv6 per observed node, node country from RW-provided countryCode (not client origin), Tehran last-seen time and relative age. Sort newest first; scan at most 10,000 returned observations per node, retain 10 for at most 8 nodes, show 5 IPs/page over 2 pages. Count means observed address records, **not** confirmed currently active devices.
- The root-only result overview remains **IP-free**. A per-node detail callback sd:ip{0-7}p{1-2} using the existing router reveals IPs only on request, with native Telegram copy buttons and Back. No IPs in callbacks, broadcast, or newly written logs.
- Existing per-user subscription request history GET returns at most 24 recent records. Display 4 per page over up to 6 pages (sd:h{1-6}) with request time, validated request IPv4/IPv6, bounded escaped User-Agent, srrResponseType, and sanitized srrRuleName. Neither the User-Agent nor subscription request alone proves VPN connectivity.
- Reuse existing ReadCustomerServiceDiagnostics, RemnawaveUsersApi, CustomerServiceDiagnosticsUi, PersianCalendar and TelegramUi::copy; no migrations, dependencies, Provider mutation, scheduler, Deniz tools, new permissions or customer/reseller access.
- The general policy against raw IPs in admin lists **remains**. This is an explicit operator-approved exception for a single, owner-verified service in a private trusted-root diagnostic detail. Telegram messages can outlive short-lived Cache entries.

### Acceptance before closing the phase

1. Pest: current trusted root, order ownership, forged/stale callbacks, IPv4/IPv6, empty/malformed timestamps/IP, overlong or hostile User-Agent, bidi controls, bounded pagination, cache expiration, deduped POST, native copy and Rich/native escaping.
2. Per-PR CI: PHP 8.4, PHPStan, Pint and MySQL green; PHP 8.5 full/scheduled compatibility per repository policy. Fakes do not prove live acceptance.
3. Controlled real private Admin Bot check on a known owned service: job result, per-IP lastSeen/country, timezone, copy/back/page navigation, request history and fallbacks. Record only redacted QA evidence. The operator has already enabled the existing connection diagnostic ENV; these PRs never change it.
4. Merge to auto-deploying master only after code review/CI and explicit go-ahead, then verify deployed revision and Telegram UI separately.
5. Close SUP-DIAG-02 only after both PRs, actual deploy, and live acceptance are evidenced. Deniz integration remains another phase.

**Rollback:** revert #530 then #529; no schema rollback. If the existing provider observation gate requires immediate containment, disable REMNAWAVE_CONNECTION_DIAGNOSTICS_ENABLED separately.
