# Remnawave API Mapping (F10-A)

**Status:** Contract-mapped transport foundation in this PR; **NOT** integrated with purchases or a live panel.
**Pinned API:** Remnawave OpenAPI 3.4.4, 218 operations, 162 paths.
**Source:** https://docs.rw/api/ (official documentation); reproducible spec mirror: https://raw.githubusercontent.com/Jolymmiles/remnawave-api-go/v3.4.4/specs/3.4.4.json

## Boundaries
- `app/Modules/Provisioning/Infrastructure/Remnawave/RemnawaveOperationCatalog.php` maps all 218 official `operationId` values to HTTP method/path, allowed + required query keys, numeric path IDs, required top-level JSON fields, and bearer-auth requirement.
- `RemnawaveApiClient.php` is the shared HTTP transport. It accepts an operation ID plus typed path/query/body arrays; it validates mapped required path/query/body presence and numeric resource IDs, sends a Bearer token **only when required by the contract**, refuses redirects, throws on HTTP errors, and returns the original response JSON envelope.
- Do not call this client from Telegram or expose arbitrary operation IDs over HTTP. A future `RemnawaveProvider` and Provisioning Application Actions own authorization, user ownership, persistence, audit and idempotency.
- No automatic retry, including for GET. An ambiguous write result **cannot** be interpreted as failure to create; reconciliation is required before retrying provisioning.
- The catalog enforces basic presence/parameter routing metadata, but **does not** validate field formats, all nested schemas, query parameter types, API scope permissions, or response DTOs. It is not proof that a live panel accepts every operation.
- Full route coverage (218/218) is NOT complete high-level SDK or E2E coverage. CRUD methods, pagination, schema-checked DTOs and webhooks are follow-up increments.
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

Configure credentials only when wiring the client in a follow-up phase; `services.remnawave` is **not yet registered** in this PR. Never hardcode a token or put it in Telegram callbacks/logs.

## Validation
1. Run `php artisan test tests/Feature/Provisioning/RemnawaveApiClientTest.php`, plus Pint/Larastan and complete CI.
2. Before production provisioning, deploy an isolated Remnawave panel pinned to the same API contract and verify authentication, actual create/read/extend/disable flows, response DTO fields, permissions/scopes, pagination, 429/5xx and ambiguous-write recovery against it.
3. Re-check the official OpenAPI when bumping Remnawave. Never silently adopt another panel major release: v3 IDs use numeric `userId`, not the old user UUID.

## F10 sequencing
- F10-A: operation catalog, HTTP client, fake HTTP tests (this PR).
- F10-B: configured, secured read-only health/resource discovery and real-panel smoke tests.
- F10-C/D: authorized user provisioning and webhook/reconciliation, with real panel E2E.
- F10-E through F10-H: lifecycle, monitoring/nodes, devices, templates, and advanced features behind application-layer permissions.

Production sales state transitions and wallet debits remain out of scope; pending orders never provision merely by existing.

## What is ready vs. what is still required

| Layer | Current state |
| --- | --- |
| OpenAPI method/path map | 218/218 mapped for v3.4.4 |
| Request auth / basic parameter checks | Mapped; covered by HTTP-fake tests |
| Real network calls / panel compatibility | NOT tested |
| Complete JSON Schema validation and typed DTOs | NOT implemented |
| Use-case wrappers (Users, Nodes, Squads, etc.) | NOT implemented |
| API token scopes, security review, rate-limit/backoff policy | NOT tested / partly deferred |
| Provisioning orchestration, idempotency, reconciliation | NOT implemented |
| Webhook ingestion/signature verification | NOT implemented |
| Subscription delivery and Telegram workflows | NOT implemented |

The next functional integration begins with read-only API methods against a test panel; user operations follow only when request/response schema validation is in place. Never turn on live provisioning from just a green HTTP-fake test.
