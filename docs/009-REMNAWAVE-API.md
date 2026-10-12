# Remnawave API Mapping (F10-A)

**Status:** Transport mapping merged into `master` via PR #19; **NOT** integrated with purchases or verified against a live panel.
**Pinned API:** Remnawave OpenAPI 3.4.4, 218 operations, 162 paths.
**Source:** https://docs.rw/api/ (official documentation); reproducible spec mirror: https://raw.githubusercontent.com/Jolymmiles/remnawave-api-go/v3.4.4/specs/3.4.4.json

## Boundaries
- `app/Modules/Provisioning/Infrastructure/Remnawave/RemnawaveOperationCatalog.php` maps all 218 official `operationId` values to HTTP method/path, allowed + required query keys, numeric path IDs, required top-level JSON fields, and bearer-auth requirement.
- `RemnawaveApiClient.php` is the shared HTTP transport. It accepts an operation ID plus typed path/query/body arrays; it validates mapped required path/query/body presence and numeric resource IDs, sends a Bearer token **only when required by the contract**, refuses redirects, throws on HTTP errors, and returns the original response JSON envelope.
- Do not call this client from Telegram or expose arbitrary operation IDs over HTTP. A future `RemnawaveProvider` and Provisioning Application Actions own authorization, user ownership, persistence, audit and idempotency.
- No automatic retry, including for GET. An ambiguous write result **cannot** be interpreted as failure to create; reconciliation is required before retrying provisioning.
- The catalog enforces basic presence/parameter routing metadata, but **does not** validate field formats, all nested schemas, query parameter types, API scope permissions, or response DTOs. It is not proof that a live panel accepts every operation.
- Full route coverage (218/218) is **not** a complete high-level SDK or live E2E. **Since F10-A**, PR #20 added scoped discovery, #21 added typed Nodes/telemetry, and #23 added typed Users/Subscriptions + durable lifecycle. Other mapped operation families still lack full domain wrappers; no user-facing, actor-authorized provider API or real-panel test exists.
- 208 operations are marked Bearer-protected in v3.4.4 OpenAPI; 10 public authentication/subscription routes do not receive the administrative token.
- No SDK/Composer dependency, migrations, service-provider registration, environment secrets, or remote API calls are added in F10-A.

## Example (development only)

```php
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;

$client = new RemnawaveApiClient(
    baseUrl: 'https://panel.example.com',
    apiToken: $tokenFromTrustedConfiguration,
);

$data = $client->call('UsersController_getUserById', pathParameters: ['userId' => 123]);
```

**Historical F10-A note:** The original PR did not yet register `services.remnawave`; F10-B **has since added** `RemnawaveApiClientFactory` and `config/services.php` (`REMNAWAVE_PANEL_URL`, `REMNAWAVE_API_TOKEN`, timeout). F10-D also added `REMNAWAVE_WRITES_ENABLED=false` as a default-disabled *Application lifecycle* guard. Never hardcode a token or put it in Telegram callbacks/logs.

## Current status after F10-D merge (2026-10-12)

The **transport map** is complete for pinned v3.4.4, but the **authorized API for Telegram/Livewire** is **not**: F10-E issue [#24](https://github.com/DenaSon/skynet/issues/24) is the next step. F10-D PR [#23](https://github.com/DenaSon/skynet/pull/23) is merged, and the [master CI](https://github.com/DenaSon/skynet/actions/runs/38213468652) passed PHP 8.4/8.5; provider actions are internal, no payment/actor ownership integration or live API testing. See [current context](000-CONTEXT.md).

## Validation
1. Run `php artisan test tests/Feature/Provisioning/RemnawaveApiClientTest.php`, plus Pint/Larastan and complete CI.
2. Before production provisioning, deploy an isolated Remnawave panel pinned to the same API contract and verify authentication, actual create/read/extend/disable flows, response DTO fields, permissions/scopes, pagination, 429/5xx and ambiguous-write recovery against it.
3. Re-check the official OpenAPI when bumping Remnawave. Never silently adopt another panel major release: v3 IDs use numeric `userId`, not the old user UUID.

## F10 implementation sequence (updated 2026-10-12)

- **F10-A (merged PR #19):** operation catalog, shared HTTP client and fake tests.
- **F10-B (merged PR #20):** configuration, read-only health/inventory and opt-in operator inspection. No real panel test.
- **F10-C (merged PR #21):** named NodesController APIs and read-only telemetry, internal only. No authorized UI node writes.
- **F10-D (merged PR #23):** named Users/Subscriptions APIs and **SQL-reserved** lifecycle, uniqueness and read-only uncertain-write reconciliation. No Telegram checkout activation, signed webhook ingestion, paid transition or live E2E.
- **F10-E (next, Issue #24):** actor-authorized shared read Application API for Telegram and future Livewire, beginning with order ownership and safe DTOs.
- **F11 (later):** independently verified Sales/Billing settlement and outbox, reconciled fulfillment/delivery, privileged actions and staging E2E.

Full route mapping is not equal to a fully productized customer API. **Never** provision from a `pending_payment` order.

## What is ready vs. what is still required

| Layer | Current state |
| --- | --- |
| OpenAPI method/path map | 218/218 mapped for v3.4.4 |
| Request auth / basic parameter checks | Mapped; covered by HTTP-fake tests |
| Real network calls / panel compatibility | NOT tested |
| Complete JSON Schema validation and typed DTOs | Partial: named Users/Subscriptions/Nodes DTOs and selected fields; full recursive schema validation not implemented |
| Use-case wrappers (Users, Nodes, Squads, etc.) | Users/Nodes implemented internally by F10-C/D; other groups partial or not implemented |
| API token scopes, security review, rate-limit/backoff policy | NOT tested / partly deferred |
| Provisioning orchestration, idempotency, reconciliation | F10-D **internal** SQL reservations and read-only reconciliation implemented; **no Sales/Billing/payment authorization or durable outbox** |
| Remnawave provider webhook ingestion/signature verification | NOT implemented; Telegram webhook verification is separate and implemented |
| Subscription delivery and Telegram workflows | NOT implemented |

The next **code increment** is F10-E (Issue #24): actor-authorized reads and ownership DTOs using HTTP fakes, with no live writes. Isolated read-only and then controlled write E2E against a real staging panel is a separate pre-activation gate. Never turn on live provisioning from just a green HTTP-fake test.
