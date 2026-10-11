# F10-D — Remnawave User Lifecycle and Durable Provisioning

**Status:** Implemented for review in PR; HTTP-fake tests only. **Not verified against a live panel. Not connected to payment, Telegram, checkout or public routes.**

## Source and contract
- Official Remnawave API: https://docs.rw/api/ (pinned OpenAPI **v3.4.4**).
- Users Controller contains 17 operations. We implemented the core user lifecycle with typed projections and guarded request DTOs. The SSE users stream and `resolve` remain raw mapped endpoints and are not exposed by this adapter.
- User IDs in v3 are **positive numbers**, not old UUID paths. `POST /api/users/{userId}/actions/extend` adds days according to current status. It is **non-idempotent** and intentionally not used by automated subscription renewal.
- `PATCH /api/users` expects a numeric `id` in the JSON body. This is used for **absolute** quota and expiration changes, avoiding additive replay hazards.
- User creation requires `username` and `expireAt`. Our restricted create DTO additionally requires quota and one or more Internal Squad UUIDs for an actually usable subscription.

## Files and responsibilities
- `Infrastructure/Remnawave/CreateRemnawaveUser` checks name, UTC expiry, quota bytes, status/reset policy, internal squads and optional description.
- `Infrastructure/Remnawave/RemnawaveUsersApi` wraps create, update, list, ID/username/short-code lookup, enable, disable, extend, reset traffic, revoke, delete, tags, accessible nodes, and subscription request history. Not an authorization layer.
- `Infrastructure/Remnawave/RemnawaveUserSnapshot` returns a small typed projection. **The subscriptionUrl is a bearer-like credential**: never log or expose it except to the confirmed owning actor.
- `Application/RemnawaveSubscriptionLifecycle` reserves an operation in SQL **before** the remote write, then marks it completed after validating response ownership and the expected remote state. No automatic retry occurs on ambiguous results.
- `provisioning_subscriptions` and `provisioning_operations` persist provider mapping, unique business references, unique idempotency keys, expected payload and recovery state. A failed transport/request outcome moves to `needs_review`, blocking any replay; a crash after reserving also requires manual reconciliation.
- `reconcileCreate()` (GET by deterministic username) and `reconcileUpdate()` (GET numeric ID) verify stable ownership marker and expected state **without issuing another write**. Operators must still assess any external drift or concurrent changes.
- **No remote subscription URL is stored in SQL**. The read-only `subscription(reference)` action retrieves it fresh after checking local ownership; a future Telegram/HTTP adapter must authorize the specific owner before using it.

## Default deny / activation gates
```dotenv
REMNAWAVE_PANEL_URL=
REMNAWAVE_API_TOKEN=
REMNAWAVE_WRITES_ENABLED=false
```

`REMNAWAVE_WRITES_ENABLED` must remain **false** on every production and staging environment until a controlled test panel and explicit approval are available. The `RemnawaveUsersApi` is internal infrastructure and bypasses this feature flag by design, so do not make it accessible through a controller, console command or queue job. The Application lifecycle is **also not wired to any external entry point**. Do not enable the flag merely because CI passes.

Before any production sales integration:
1. Verify payment settlement and authenticate/authorize the caller (Identity Application boundary), enforce order ownership, scope plan limits, and prevent cross-tenant access.
2. Bind an immutable paid `sales_orders` ID to `reference`; ensure unique source and enforce no duplicate payment capture/refunds. **There is no settled payment state integration in this PR.**
3. Use a fixed provider/tenant identity and least-privilege token, not a dynamic base URL or a customer-supplied operation ID.
4. Verify actual panel behavior for creation, subscription URL, squads, expiration semantics, reset strategies, 401/403/429, transport timeouts, response normalization, quota boundaries and partial failures.
5. Build authenticated, audited and confirmed operator workflows for deletion, revoke and reset. These remain internal adapter capabilities with no application entry point.
6. Audit hidden credential exposure in logs, exceptions, database exports, and outgoing Telegram messages.

## Recovery and limitations
- `reserved`: a write may or may not have been sent. No automatic retry.
- `needs_review`: remote request failed, response invalid, or expected state differed. Do **not** retry the same or a new key against the same reserved subscription.
- `completed`: same key and same request returns saved remote ID without sending a duplicate write.
- Reconciliation is read-only: it never assumes a remote failure means the previous write had no effect.
- No public API, Telegram commands, automatic purchase activation, debit, credit, or email notifications.
- No live server E2E. Pin to v3.4.4 until compatibility is verified; a real panel may normalize dates or quota fields differently.

Related: [API](009-REMNAWAVE-API.md), [Nodes](011-REMNAWAVE-NODES.md), [Staging](010-REMNAWAVE-STAGING.md), [Module boundaries](003-MODULES.md).
