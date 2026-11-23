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

Generic Node lifecycle mutations are not exposed as unrestricted HTTP or Telegram controls. NW-05 is a narrowly-scoped, trusted Super Admin Telegram replacement flow built on Provisioning Application actions, provider write gates, one-use confirmation, per-operation checkpoints and GET-only reconciliation. It does not add general Node create/delete/restart controls. Any additional provider mutation requires its own approved scope, authorization, one-use confirmation and after-timeout reconciliation. Do not implicitly retry any mutation; a response indicating HTTP 202 is only acknowledgement, not confirmation of a node restart.


## NW-05 — existing Node server replacement

The Telegram replacement flow reuses the NW-01/NW-02 SSH installer but does **not** call Node create or delete for an existing Node. It snapshots the existing Node UUID, management address/port, Config Profile and complete active Inbound UUID set before install.

**NW-01/NW-05 Telegram input contract (2026-11-23):** The current stepwise setup/replacement Wizard takes the server name from its existing owner-bound draft, then expects **only the new public IP** in the IP step. A legitimate Node name can contain the literal `|` character (for example, `Netherlands | AMSTERDAM`); the IP-step parser must never concatenate that name with the submitted IP or split it on `|`. The legacy residual `identity` draft still exclusively accepts the old two-field `name | IP` text format. Existing name/IP validation, public SSH destination restrictions, duplicate checks, role authorization, replacement snapshot and Provider-write gates remain unchanged. Regression tests cover pipe-containing names, invalid IPs, back navigation and no remote mutation before confirmation.

Production Node address values are currently FQDNs. For those Nodes, preserve address and move the Node management hostname's existing DNS A record to the new VPS IP. A direct-IP Node is the only case where the address field is patched, with a strict before/after identity comparison. The flow is fixed to management port 2222 because the existing installer sets NODE_PORT=2222.

Host association prefers the provider's explicit Host.nodes UUID membership; a Host shared with another Node blocks the transfer. For legacy responses with nodes: [], infer association only when both the selected Node's active Config Profile UUID and one of its active Inbound UUIDs match. Contradictory profile/inbound evidence identifies an unrelated Host; missing association evidence, or matching profile/inbound with an endpoint that does not match the current public IP, is ambiguous and blocks replacement. A direct-IP Host must equal the old IP; a domain Host must resolve through exactly one editable A record in an allowlisted Cloudflare Zone to that same old IP. Include every inferred/explicitly associated Host domain alongside the Node management hostname, deduplicating case-insensitively. Preserve each Host's UUID/settings and each DNS record's Proxy flag; Node management DNS must remain DNS Only. Preview also rejects a proposed new IPv4 already assigned to any peer Node, including disabled/disconnected records; the final direct-IP Node PATCH repeats the collision guard.

Writes are sequential and individually checkpointed as uncertain before dispatch. An ambiguous outcome is reconciled with GET only; never blindly retry. The Remnawave connected flag is informational and is not proof that client traffic reaches the new VPS. The flow remains in an explicit client-test stage until a trusted operator tests a real client from a user network and confirms it. Keep the old VPS available until that test succeeds; it is never disabled or shut down automatically. The implementation's HTTP-fake tests do not establish live provider behavior.

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
5. Any additional provider write action beyond the narrowly-scoped NW-05 replacement flow requires its own guarded Application action and explicit review before exposure through the bot.

## Safety and architecture

- The operation catalog remains a transport map, not a permission system.
- No wallet, orders, user subscriptions, webhooks, databases or migrations are changed here.
- No raw node address, connection credentials, Xray config or access token is logged. Raw responses returned inside infrastructure must never flow directly to Telegram.
- If a network call times out after a write, **do not retry blindly**. Re-fetch remote state before deciding whether to retry.
- Advanced integrations (Node Plugins, Hosts, Config Profiles, inbounds, connection drops, Prometheus) remain separately gated modules. Mapping their routes is not implementation.

Related: [Remnawave API](009-REMNAWAVE-API.md), [Staging runbook](010-REMNAWAVE-STAGING.md), [Provisioning boundaries](003-MODULES.md).
