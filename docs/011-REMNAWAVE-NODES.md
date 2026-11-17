# F10-C — Remnawave Node API and Telemetry

**Status:** Internal adapters merged via PR #21, with passing PHP 8.4/8.5 CI and HTTP-fake tests; **no live Remnawave panel test**.

**Pinned contract:** https://docs.rw/api/ (OpenAPI v3.4.4). NodesController has 15 routes.

## Node management, internal-only

\`RemnawaveNodesApi\` is under \`Modules/Provisioning/Infrastructure/Remnawave\`, never imported by Telegram or public web controllers.

| Group | Methods | Upstream operations |
| --- | --- | --- |
| Discovery | \`all\`, \`one\`, \`tags\` | GET nodes, node by UUID and tags |
| Single-node lifecycle | \`create\`, \`update\`, \`enable\`, \`disable\`, \`delete\` | POST/PATCH/POST/DELETE |
| Maintenance | \`restart\`, \`restartAll\`, \`resetTraffic\` | POST actions |
| Topology / multi-node | \`reorder\`, \`changeProfiles\`, \`bulkAction\`, \`bulkUpdate\` | POST actions |

Request shapes follow the pinned OpenAPI contract, including mandatory \`forceRestart: bool\` on single/all restarts, \`uuid\` in PATCH JSON (not URL path), \`configProfile.activeConfigProfileUuid\` and \`configProfile.activeInbounds\` on create/profile modification, and the enumerated bulk actions \`ENABLE|DISABLE|RESTART|RESET_TRAFFIC\`. Basic UUID, required-field and allowlist validation is local. **This is NOT full recursive JSON Schema validation**; optional/nested field constraints must still be tested against a real panel.

No HTTP/Telegram mutating endpoints or admin buttons are wired. Before enabling those, add an authorized Provisioning Application action, trusted Super Admin checks, one-use operator confirmation, audit, rate limiting, and after-timeout reconciliation. Do not implicitly retry any mutation; a response indicating HTTP 202 is only acknowledgement, not confirmation of a node restart.


## NW-05 — existing Node server replacement

The Telegram replacement flow reuses the NW-01/NW-02 SSH installer but does **not** call Node create or delete for an existing Node. It snapshots the existing Node UUID, management address/port, Config Profile and complete active Inbound UUID set before install.

Production Node address values are currently FQDNs. For those Nodes, preserve address and move the Node management hostname's existing DNS A record to the new VPS IP. A direct-IP Node is the only case where the address field is patched, with a strict before/after identity comparison. The flow is fixed to management port 2222 because the existing installer sets NODE_PORT=2222.

Host membership is derived only from the provider's Host.nodes UUID list. A shared Host blocks the transfer. All unique domain Host addresses are included alongside the Node management hostname; duplicate Host entries on multiple ports map to one DNS record. Require exactly one editable A record per hostname, in an allowlisted Cloudflare zone, pointing to the same old IP. Preserve each Host's settings and each DNS record's Proxy flag; Node management DNS must remain DNS Only.

Writes are sequential and individually checkpointed as uncertain before dispatch. An ambiguous outcome is reconciled with GET only; never blindly retry. Keep the old VPS available until the same Node UUID is connected through the new server and real client traffic is verified. The implementation's HTTP-fake tests do not establish live provider behavior.

## Node metrics, read-only

\`RemnawaveNodeTelemetry\` reads:

- \`GET /api/system/nodes/metrics\` → a safe \`uuid/name/usersOnline\` projection. It **does not** imply distinct online subscribers.
- \`GET /api/system/stats/nodes\` → \`nodeName/date/totalBytes\` series for the last seven days.
- \`GET /api/bandwidth-stats/nodes?start=...&end=...\` → typed top-level validated JSON data (still internal).
- \`POST /api/bandwidth-stats/nodes/usage?start=...&end=...\` → a read-only analytics request, despite using POST, with \`nodesUuids\` in JSON. Not a resource mutation.

Internal telemetry must not be exposed raw to customers. Node count / \`isConnected\` reflects panel-side status, not client reachability from Iran. See https://docs.rw/learn-en/nodes/ and https://docs.rw/learn-en/quick-start/.

Both node bandwidth operations require **calendar dates `YYYY-MM-DD`**, not UTC timestamps; same-day windows (`start == end`) are valid. Client rejects impossible dates and reversed ranges before network access.

## Real-panel E2E gate

1. F10-A/B/C/D are merged with CI green; configure an isolated TLS panel with least-privilege tokens. Run \`php artisan remnawave:inspect\`.
2. Use a separate test-only node. Validate \`all/one/tags\` and telemetry, including zero nodes, backend errors, malformed responses and timeouts.
3. For writes, explicitly approve operations against only the test node: create/configure, enable/disable, restart (with forceRestart field), reset traffic, update/reorder and delete. Confirm actual provider-side state after each action.
4. Verify panel version and API token scopes; ensure no customer or production node is affected.
5. Implement guarded Application actions before exposing any write through the bot.

## Safety and architecture

- The operation catalog remains a transport map, not a permission system.
- No wallet, orders, user subscriptions, webhooks, databases or migrations are changed here.
- No raw node address, connection credentials, Xray config or access token is logged. Raw responses returned inside infrastructure must never flow directly to Telegram.
- If a network call times out after a write, **do not retry blindly**. Re-fetch remote state before deciding whether to retry.
- Advanced integrations (Node Plugins, Hosts, Config Profiles, inbounds, connection drops, Prometheus) remain separately gated modules. Mapping their routes is not implementation.

Related: [Remnawave API](009-REMNAWAVE-API.md), [Staging runbook](010-REMNAWAVE-STAGING.md), [Provisioning boundaries](003-MODULES.md).
