# Remnawave API Mapping (F10-A)

**Status:** Contract-mapped transport foundation in this PR; **NOT** integrated with purchases or a live panel.
**Pinned API:** Remnawave OpenAPI 3.4.4, 218 operations, 162 paths.
**Source:** https://docs.rw/api/ (official documentation); reproducible spec mirror: https://raw.githubusercontent.com/Jolymmiles/remnawave-api-go/v3.4.4/specs/3.4.4.json

## Boundaries
- `app/Modules/Provisioning/Infrastructure/Remnawave/RemnawaveOperationCatalog.php` maps all 218 official `operationId` values to HTTP method/path, required query keys and JSON body availability.
- `RemnawaveApiClient.php` is the shared HTTP transport. It accepts an operation ID plus typed path/query/body arrays; it validates mapped required parameters, sends a Bearer token, refuses redirects, throws on HTTP errors, and returns the original response JSON envelope.
- Do not call this client from Telegram or expose arbitrary operation IDs over HTTP. A future `RemnawaveProvider` and Provisioning Application Actions own authorization, user ownership, persistence, audit and idempotency.
- No automatic retry, including for GET. An ambiguous write result **cannot** be interpreted as failure to create; reconciliation is required before retrying provisioning.
- The catalog is deliberately a route map, **not** runtime request DTO validation, permissions, or proof that a live panel accepts every operation.
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
