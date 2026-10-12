# F10-D — Remnawave Users API & Subscription Lifecycle

**Status (2026-10-12):** Internal contract-checked Users and Subscriptions adapters **merged on `master` via PR #23**, PHP 8.4/8.5 CI green (master run 38213468652). No live Remnawave panel tests, no Billing settlement or Telegram/user-facing activation. This document describes the **provider adapter**; durable Application operations are in [F10-D lifecycle runbook](012-REMNAWAVE-USER-LIFECYCLE.md).

## API contract

Source: Remnawave OpenAPI v3.4.4 (`https://docs.rw/api/`; pinned mirror at `https://raw.githubusercontent.com/Jolymmiles/remnawave-api-go/v3.4.4/specs/3.4.4.json`). Version 3 uses **numeric user IDs**, rather than treating user UUIDs as primary IDs.

Files:
- `Infrastructure/Remnawave/RemnawaveUsersApi.php` — named operations wrapping the mapped HTTP client.
- `Infrastructure/Remnawave/CreateRemnawaveUser.php` — intentionally narrow, validated creation payload (username, UTC expiry, byte quota, required internal squad list, reset strategy, initial state and optional description).
- `Infrastructure/Remnawave/RemnawaveSubscriptionsApi.php` — protected subscription lookups and connection keys by user ID / username / short UUID. Raw results contain credentials and stay strictly inside trusted provider infrastructure.
- `Infrastructure/Remnawave/RemnawaveUserSnapshot.php` — immutable subset of the remote user DTO, omitting remote protocol passwords. Contains a **secret bearer subscription URL**; never dump it in logs, public JSON or diagnostics.

| User capability | Internal API | Endpoint |
| --- | --- | --- |
| Create | `create` | `POST /api/users` |
| Read by numeric ID, username or short UUID | `byId`, `byUsername`, `byShortUuid` | GET user lookup routes |
| List/pagination | `list` | `GET /api/users` |
| Update quota, expiry, assignments & metadata | `update` | `PATCH /api/users` with `id` in JSON |
| Enable/disable | `enable`, `disable` | POST state actions |
| Extend expiry | `extend` | `POST /api/users/{userId}/actions/extend`, `days` |
| Reset traffic | `resetTraffic` | POST reset action |
| Revoke subscription credentials | `revoke` | POST revoke action |
| Delete | `delete` | `DELETE /api/users/{userId}` |
| Protected subscription lookup and connection keys | `byUserId`, `byUsername`, `byShortUuid`, `connectionKeys` (SubscriptionsApi) | GET protected subscription routes |
| Discover accessible nodes / tags / request history | `accessibleNodes`, `tags`, `requestHistory` | GET metadata routes |

## Security and operational contract

- **No changes to Sales, Billing, Telegram or identity persistence.** No public HTTP route, bot callback, CLI mutation command or consumer of this adapter has been added.
- HTTP-fake tests verify API route and verb, numeric IDs, argument constraints, enum constraints, HTTP errors and malformed response envelopes. CI must pass on PHP 8.4 and 8.5 (Pest/PHPStan/Pint).
- Full recursive validation of every optional provider field remains deferred, as does a live response compatibility test.
- Client never retries writes. `create` and `extend` are **not inherently idempotent**: a timeout does not prove the operation failed. F10-D Application lifecycle persists unique operation keys and a deterministic username; after an uncertain result it requires read-only reconciliation before any further action. For additive `extend`, reconcile expiry before taking another action.
- A provider subscription URL is a bearer credential. Issue it only to the authenticated account that owns the order/subscription; store securely, and do not include it in audit metadata.
- Avoid automatic quota resets, subscription URL rotations or deletion on renewal. These are distinct destructive business actions.
- Production provisioning requires an **authorized Application orchestrator**: buyer/ownership check, payment confirmation from Billing, durable idempotency and ledger-aware order state machine, provider mapping, retries with reconciliation, transaction/outbox semantics, audit and failure recovery. None are claimed implemented here.
- Isolated staging E2E is mandatory before enabling any user writes against a live panel. Test least-privilege API token scopes, HTTPS, 401/403/429, transient 5xx and ambiguous responses.

## Intentionally deferred
- `GET /api/users/stream` streaming export (do not buffer full private user data through generic HTTP calls).
- Admin-only token management (requires administrator JWT), user bulk operations, subscription templates, HWID devices, subscription key/config delivery policy, webhook processing, order activation and automatic billing.
- Secrets may appear in the raw Remnawave JSON envelope; `RemnawaveUsersApi` projects only the explicit fields into `RemnawaveUserSnapshot`.

Related: [API mapping](009-REMNAWAVE-API.md), [Node API](011-REMNAWAVE-NODES.md), [Staging](010-REMNAWAVE-STAGING.md).

## Current contract caveats and handoff

The `RemnawaveUserSnapshot` validates and projects `activeInternalSquads` from the provider response and the F10-D lifecycle verifies the **exact requested UUID membership set** (order-insensitive) on creation and read-only reconciliation; keyed/associative input arrays are rejected before any remote write. A missing or inconsistent squad set is not successful activation.

This API remains **internal infrastructure only**. For a customer- or reseller-facing Telegram/Web API, proceed with [Issue #24 (F10-E)](https://github.com/DenaSon/skynet/issues/24). Verify the actor's current Identity status and immutable Sales order ownership in an Application Action before revealing `subscriptionUrl`. `RemnawaveUsersApi` primitive methods do not check RBAC. See [canonical project context](000-CONTEXT.md).
