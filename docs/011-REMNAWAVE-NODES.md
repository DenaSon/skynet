# F10-C — Remnawave Node API and Telemetry

**Status:** Internal adapters in a stacked PR; HTTP-fake contract tests added; **no live Remnawave panel test**.

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

## Node metrics, read-only

\`RemnawaveNodeTelemetry\` reads:

- \`GET /api/system/nodes/metrics\` → a safe \`uuid/name/usersOnline\` projection. It **does not** imply distinct online subscribers.
- \`GET /api/system/stats/nodes\` → \`nodeName/date/totalBytes\` series for the last seven days.
- \`GET /api/bandwidth-stats/nodes?start=...&end=...\` → typed top-level validated JSON data (still internal).
- \`POST /api/bandwidth-stats/nodes/usage?start=...&end=...\` → a read-only analytics request, despite using POST, with \`nodesUuids\` in JSON. Not a resource mutation.

Internal telemetry must not be exposed raw to customers. Node count / \`isConnected\` reflects panel-side status, not client reachability from Iran. See https://docs.rw/learn-en/nodes/ and https://docs.rw/learn-en/quick-start/.

## Real-panel E2E gate

1. Complete F10-A and F10-B review and CI, configure an isolated TLS panel with least-privilege tokens. Run \`php artisan remnawave:inspect\`.
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
