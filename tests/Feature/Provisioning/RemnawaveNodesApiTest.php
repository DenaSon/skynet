<?php

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeTelemetry;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveOperationCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TEST_NODE_UUID = '550e8400-e29b-41d4-a716-446655440000';
const TEST_PROFILE_UUID = '550e8400-e29b-41d4-a716-446655440001';
const TEST_INBOUND_UUID = '550e8400-e29b-41d4-a716-446655440002';

function nodeTestClient(): RemnawaveApiClient
{
    return new RemnawaveApiClient('https://rw.nodes.test', 'test-only-token');
}

function nodeTestApi(): RemnawaveNodesApi
{
    return new RemnawaveNodesApi(nodeTestClient());
}

function validNodeConfig(): array
{
    return [
        'activeConfigProfileUuid' => TEST_PROFILE_UUID,
        'activeInbounds' => [TEST_INBOUND_UUID],
    ];
}

it('maps all 15 NodesController operations from OpenAPI v3.4.4', function (): void {
    $ids = array_keys(RemnawaveOperationCatalog::OPERATIONS);
    $nodes = array_filter($ids, fn (string $id): bool => str_starts_with($id, 'NodesController_'));

    expect($nodes)->toHaveCount(15)
        ->and(RemnawaveOperationCatalog::find('NodesController_restartNode')['requiredBodyFields'])
        ->toBe(['forceRestart'])
        ->and(RemnawaveOperationCatalog::find('NodesController_createNode')['requiredBodyFields'])
        ->toBe(['name', 'address', 'configProfile']);
});

it('reads node list and one node over GET with bearer auth', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/nodes/'.TEST_NODE_UUID => Http::response(['response' => ['uuid' => TEST_NODE_UUID, 'name' => 'Node']], 200),
        'https://rw.nodes.test/api/nodes' => Http::response(['response' => [['uuid' => TEST_NODE_UUID]]], 200),
    ]);

    $api = nodeTestApi();

    expect($api->all())->toHaveCount(1)
        ->and($api->one(TEST_NODE_UUID)['name'])->toBe('Node');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer test-only-token'));
});

it('creates and updates nodes with required nested profile data', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/nodes' => Http::response(['response' => ['uuid' => TEST_NODE_UUID, 'name' => 'Node']], 201),
    ]);

    $api = nodeTestApi();
    $new = $api->create([
        'name' => 'Node',
        'address' => 'node.example.test',
        'configProfile' => validNodeConfig(),
    ]);

    expect($new['uuid'])->toBe(TEST_NODE_UUID);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['name'] === 'Node'
        && $request['configProfile']['activeConfigProfileUuid'] === TEST_PROFILE_UUID);
    Http::assertSentCount(1);
});

it('sends documented PATCH body UUID rather than path UUID', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://rw.nodes.test/api/nodes' => Http::response(['response' => ['uuid' => TEST_NODE_UUID]], 200)]);

    $data = nodeTestApi()->update(TEST_NODE_UUID, ['name' => 'Updated Node']);

    expect($data['uuid'])->toBe(TEST_NODE_UUID);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['uuid'] === TEST_NODE_UUID
        && $request['name'] === 'Updated Node');
    Http::assertSentCount(1);
});

it('validates UUIDs, required config profile and unsupported fields before remote writes', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $api = nodeTestApi();

    expect(fn () => $api->one('not-a-uuid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->create(['name' => 'Node', 'address' => 'example.test']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->create(['name' => 'Node', 'address' => 'example.test', 'configProfile' => ['activeConfigProfileUuid' => TEST_PROFILE_UUID, 'activeInbounds' => ['bad']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->update(TEST_NODE_UUID, ['uuid' => TEST_NODE_UUID]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->update(TEST_NODE_UUID, ['unknownSetting' => true]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->update(TEST_NODE_UUID, []))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('uses correct enable/disable node actions with no automatic retry', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/nodes/'.TEST_NODE_UUID.'/actions/*' => Http::response(['response' => ['uuid' => TEST_NODE_UUID]], 200),
    ]);

    $api = nodeTestApi();
    expect($api->enable(TEST_NODE_UUID)['uuid'])->toBe(TEST_NODE_UUID)
        ->and($api->disable(TEST_NODE_UUID)['uuid'])->toBe(TEST_NODE_UUID);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/actions/enable'));
});

it('requires forceRestart request body and accepts empty 202/204 responses', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/nodes/'.TEST_NODE_UUID.'/actions/restart' => Http::response('', 202),
        'https://rw.nodes.test/api/nodes/actions/restart-all' => Http::response('', 202),
        'https://rw.nodes.test/api/nodes/'.TEST_NODE_UUID.'/actions/reset-traffic' => Http::response('', 204),
        'https://rw.nodes.test/api/nodes/'.TEST_NODE_UUID => Http::response('', 204),
    ]);

    $api = nodeTestApi();
    $api->restart(TEST_NODE_UUID);
    $api->restartAll(true);
    $api->resetTraffic(TEST_NODE_UUID);
    $api->delete(TEST_NODE_UUID);

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/actions/restart')
        && $request['forceRestart'] === false);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/actions/restart-all')
        && $request['forceRestart'] === true);
});

it('validates bulk actions and update inputs', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $api = nodeTestApi();

    expect(fn () => $api->bulkAction([], 'RESTART'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->bulkAction([TEST_NODE_UUID, TEST_NODE_UUID], 'DISABLE'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->bulkAction([TEST_NODE_UUID], 'DELETE'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->bulkUpdate([TEST_NODE_UUID], ['badField' => 'value']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->changeProfiles([TEST_NODE_UUID], ['activeConfigProfileUuid' => TEST_PROFILE_UUID]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->reorder([]))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('sends bulk actions, profile changes, ordering, and node tags using mapped endpoints', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/nodes/tags' => Http::response(['response' => ['tags' => ['PROD']]], 200),
        'https://rw.nodes.test/api/nodes/actions/reorder' => Http::response(['response' => []], 200),
        'https://rw.nodes.test/api/nodes/bulk-actions/*' => Http::response('', 204),
        'https://rw.nodes.test/api/nodes/bulk-actions' => Http::response('', 204),
    ]);

    $api = nodeTestApi();
    expect($api->tags())->toBe(['tags' => ['PROD']]);
    $api->bulkAction([TEST_NODE_UUID], 'RESTART');
    $api->bulkUpdate([TEST_NODE_UUID], ['countryCode' => 'DE']);
    $api->changeProfiles([TEST_NODE_UUID], validNodeConfig());
    $api->reorder([['uuid' => TEST_NODE_UUID, 'viewPosition' => 0]]);

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rw.nodes.test/api/nodes/bulk-actions'
        && $request['action'] === 'RESTART');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rw.nodes.test/api/nodes/bulk-actions/profile-modification'
        && $request['configProfile']['activeInbounds'] === [TEST_INBOUND_UUID]);
});

it('rejects malformed node object envelopes instead of silently showing success', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://rw.nodes.test/api/nodes/'.TEST_NODE_UUID => Http::response(['response' => [1, 2, 3]], 200)]);

    expect(fn () => nodeTestApi()->one(TEST_NODE_UUID))->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

it('projects online node metrics without exporting sensitive telemetry', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/system/nodes/metrics' => Http::response([
            'response' => ['nodes' => [[
                'nodeUuid' => TEST_NODE_UUID, 'nodeName' => 'Node',
                'usersOnline' => 12, 'proxyUrl' => 'socks5://secret',
            ]]],
        ], 200),
        'https://rw.nodes.test/api/system/stats/nodes' => Http::response([
            'response' => ['lastSevenDays' => [['nodeName' => 'Node', 'date' => '2026-10-10', 'totalBytes' => '1234']]],
        ], 200),
    ]);

    $monitor = new RemnawaveNodeTelemetry(nodeTestClient());
    expect($monitor->onlineUsers())->toBe([['uuid' => TEST_NODE_UUID, 'name' => 'Node', 'usersOnline' => 12]])
        ->and($monitor->lastSevenDays()[0]['totalBytes'])->toBe('1234');

    Http::assertSentCount(2);
});

it('queries node bandwidth by date without exporting a public endpoint', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.nodes.test/api/bandwidth-stats/nodes/usage*' => Http::response(['response' => ['nodes' => []]], 200),
        'https://rw.nodes.test/api/bandwidth-stats/nodes?*' => Http::response(['response' => ['categories' => [], 'sparklineData' => [], 'topNodes' => [], 'series' => []]], 200),
    ]);

    $monitor = new RemnawaveNodeTelemetry(nodeTestClient());
    $start = '2026-10-10T00:00:00Z';
    $end = '2026-10-11T00:00:00Z';

    expect($monitor->usageHistory($start, $end)['categories'])->toBe([])
        ->and($monitor->usageForNodes([TEST_NODE_UUID], $start, $end)['nodes'])->toBe([]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/api/bandwidth-stats/nodes/usage')
        && $request['nodesUuids'] === [TEST_NODE_UUID]);
});

it('rejects invalid ranges and malformed metrics before any HTTP request', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $monitor = new RemnawaveNodeTelemetry(nodeTestClient());

    expect(fn () => $monitor->usageHistory('yesterday', 'tomorrow'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $monitor->usageHistory('2026-10-11T00:00:00Z', '2026-10-10T00:00:00Z'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $monitor->usageForNodes([], '2026-10-10T00:00:00Z', '2026-10-11T00:00:00Z'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});
