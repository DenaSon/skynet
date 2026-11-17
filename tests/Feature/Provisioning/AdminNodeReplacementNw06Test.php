<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\CompleteNodeSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const NW06_ZONE_ID = 'abcdef0123456789abcdef0123456789';
const NW06_RECORD_ID = 'ab010101010101010101010101010101';
const NW06_NODE_UUID = '550e8400-e29b-41d4-a716-446655440061';
const NW06_OTHER_NODE_UUID = '550e8400-e29b-41d4-a716-446655440062';
const NW06_PROFILE_UUID = '550e8400-e29b-41d4-a716-446655440063';
const NW06_INBOUND_UUID = '550e8400-e29b-41d4-a716-446655440064';
const NW06_HOST_IP_UUID = '550e8400-e29b-41d4-a716-446655440065';
const NW06_HOST_TLS_UUID = '550e8400-e29b-41d4-a716-446655440066';
const NW06_HOST_SS_UUID = '550e8400-e29b-41d4-a716-446655440067';
const NW06_INSTALL_ID = 'c606c606c606c606c606c606c606c606';
const NW06_OLD_IP = '185.194.188.191';
const NW06_NEW_IP = '194.5.192.133';

/** @return array<string,mixed> */
function nw06Node(): array
{
    return [
        'uuid' => NW06_NODE_UUID,
        'name' => 'Eurp',
        'address' => NW06_OLD_IP,
        'port' => 2222,
        'isConnected' => true,
        'isDisabled' => false,
        'configProfile' => [
            'activeConfigProfileUuid' => NW06_PROFILE_UUID,
            'activeInbounds' => [['uuid' => NW06_INBOUND_UUID]],
        ],
    ];
}

/** @return array<string,mixed> */
function nw06Host(string $uuid, string $remark, string $address, int $port, bool $shared = false): array
{
    return [
        'uuid' => $uuid,
        'remark' => $remark,
        'address' => $address,
        'port' => $port,
        'isDisabled' => false,
        'inbound' => [
            'configProfileUuid' => NW06_PROFILE_UUID,
            'configProfileInboundUuid' => NW06_INBOUND_UUID,
        ],
        'nodes' => $shared ? [NW06_NODE_UUID, NW06_OTHER_NODE_UUID] : [NW06_NODE_UUID],
    ];
}

/** @return array<string,mixed> */
function nw06DnsRecord(string $ip = NW06_OLD_IP): array
{
    return [
        'id' => NW06_RECORD_ID,
        'name' => 'ae1.coreflare.ir',
        'type' => 'A',
        'content' => $ip,
        'proxied' => true,
        'proxiable' => true,
        'modified_on' => '2026-11-17T08:00:00Z',
    ];
}

/** @return array{actor:User,state:object,expected:array<string,mixed>} */
function nw06ReplacementFixture(bool $sharedHost = false, bool $duplicateDns = false, bool $failNodePatch = false): array
{
    config()->set('super_admin.telegram_ids', ['980601', '980602']);
    config()->set('services.remnawave.url', 'https://rw.nw06.test');
    config()->set('services.remnawave.token', 'rw-nw06-test-token');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.node_ssh_install_enabled', true);
    config()->set('services.cloudflare.token', 'cf-nw06-test-token');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    Http::preventStrayRequests();

    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 980601]);

    $hosts = [
        nw06Host(NW06_HOST_IP_UUID, 'Eurp-SS', NW06_OLD_IP, 1234),
        nw06Host(NW06_HOST_TLS_UUID, 'Eurp-REALITY', 'ae1.coreflare.ir', 443, $sharedHost),
        nw06Host(NW06_HOST_SS_UUID, 'Eurp-XHTTP', 'ae1.coreflare.ir', 8443),
    ];
    $records = [nw06DnsRecord()];
    if ($duplicateDns) {
        $records[] = [
            ...nw06DnsRecord('185.194.188.192'),
            'id' => 'ab020202020202020202020202020202',
        ];
    }

    $state = (object) [
        'node' => nw06Node(),
        'hosts' => $hosts,
        'records' => $records,
        'nodePatches' => 0,
        'hostPatches' => 0,
        'dnsPatches' => 0,
        'failNodePatch' => $failNodePatch,
    ];
    $expected = [
        'uuid' => NW06_NODE_UUID,
        'name' => 'Eurp',
        'address' => NW06_OLD_IP,
        'port' => 2222,
        'connected' => true,
        'disabled' => false,
        'profileUuid' => NW06_PROFILE_UUID,
        'inboundUuids' => [NW06_INBOUND_UUID],
    ];

    Cache::put('nw02:status:'.NW06_INSTALL_ID, [
        'owner' => $actor->id,
        'state' => 'running',
        'ip' => NW06_NEW_IP,
        'name' => 'Eurp',
        'profileUuid' => NW06_PROFILE_UUID,
        'inboundUuid' => NW06_INBOUND_UUID,
        'replacementNode' => $expected,
        'replacementNodeStage' => 'ready',
    ], now()->addHours(3));

    Http::fake(static function (Request $request) use ($state) {
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);
        $method = $request->method();
        $rw = 'https://rw.nw06.test/api/';
        $cf = '/client/v4';

        if ($method === 'GET' && $url === $rw.'nodes') {
            return Http::response(['response' => [$state->node]]);
        }
        if ($method === 'GET' && $url === $rw.'nodes/'.NW06_NODE_UUID) {
            return Http::response(['response' => $state->node]);
        }
        if ($method === 'PATCH' && $url === $rw.'nodes') {
            $state->nodePatches++;
            if ($state->failNodePatch) {
                return Http::response(['error' => 'uncertain'], 504);
            }
            $payload = $request->data();
            $state->node['address'] = $payload['address'];

            return Http::response(['response' => $state->node]);
        }
        if ($method === 'GET' && $url === $rw.'config-profiles') {
            return Http::response(['response' => ['configProfiles' => [
                ['uuid' => NW06_PROFILE_UUID, 'name' => 'UAE-Main'],
            ]]]);
        }
        if ($method === 'GET' && $url === $rw.'config-profiles/'.NW06_PROFILE_UUID.'/inbounds') {
            return Http::response(['response' => ['inbounds' => [
                ['uuid' => NW06_INBOUND_UUID, 'tag' => 'VLESS'],
            ]]]);
        }
        if ($method === 'GET' && $url === $rw.'hosts') {
            return Http::response(['response' => $state->hosts]);
        }
        if ($method === 'GET' && is_string($path) && preg_match('#/api/hosts/([0-9a-f-]+)$#', $path, $match) === 1) {
            foreach ($state->hosts as $host) {
                if ($host['uuid'] === $match[1]) {
                    return Http::response(['response' => $host]);
                }
            }

            return Http::response(['error' => 'missing'], 404);
        }
        if ($method === 'PATCH' && $url === $rw.'hosts') {
            $state->hostPatches++;
            $payload = $request->data();
            foreach ($state->hosts as $index => $host) {
                if ($host['uuid'] === $payload['uuid']) {
                    $state->hosts[$index]['remark'] = $payload['remark'];
                    $state->hosts[$index]['address'] = $payload['address'];
                    $state->hosts[$index]['port'] = $payload['port'];

                    return Http::response(['response' => $state->hosts[$index]]);
                }
            }

            return Http::response(['error' => 'missing'], 404);
        }

        if ($method === 'GET' && $path === $cf.'/zones') {
            $name = $request->data()['name'] ?? null;

            return Http::response(['success' => true, 'result' => $name === 'coreflare.ir'
                ? [['id' => NW06_ZONE_ID, 'name' => 'coreflare.ir']]
                : []]);
        }

        $recordsPath = $cf.'/zones/'.NW06_ZONE_ID.'/dns_records';
        if ($method === 'GET' && $path === $recordsPath) {
            $name = $request->data()['name'] ?? null;
            $rows = array_values(array_filter(
                $state->records,
                static fn (array $record): bool => $record['name'] === $name,
            ));

            return Http::response(['success' => true, 'result' => $rows]);
        }
        if ($method === 'GET' && is_string($path) && str_starts_with($path, $recordsPath.'/')) {
            $recordId = substr($path, strlen($recordsPath) + 1);
            foreach ($state->records as $record) {
                if ($record['id'] === $recordId) {
                    return Http::response(['success' => true, 'result' => $record]);
                }
            }

            return Http::response(['success' => false], 404);
        }
        if ($method === 'PATCH' && is_string($path) && str_starts_with($path, $recordsPath.'/')) {
            $recordId = substr($path, strlen($recordsPath) + 1);
            $state->dnsPatches++;
            foreach ($state->records as $index => $record) {
                if ($record['id'] === $recordId) {
                    $state->records[$index]['content'] = $request->data()['content'];
                    $state->records[$index]['proxied'] = $request->data()['proxied'];
                    $state->records[$index]['modified_on'] = '2026-11-17T09:00:00Z';

                    return Http::response(['success' => true, 'result' => $state->records[$index]]);
                }
            }
        }

        return Http::response(['error' => 'unexpected request', 'url' => $url], 404);
    });

    return compact('actor', 'state', 'expected');
}

it('NW-06 preflights and replaces a Node address while preserving UUID, profile, inbounds, Host settings and DNS Proxy', function (): void {
    $fixture = nw06ReplacementFixture();
    $action = app(CompleteNodeSetup::class);

    $review = $action->previewReplacementNode($fixture['actor']->id, NW06_INSTALL_ID);
    expect($review)->toMatchArray([
        'uuid' => NW06_NODE_UUID,
        'oldIp' => NW06_OLD_IP,
        'newIp' => NW06_NEW_IP,
        'hostCount' => 3,
        'dnsCount' => 1,
        'inboundCount' => 1,
    ]);
    expect($fixture['state']->nodePatches)->toBe(0)
        ->and($fixture['state']->hostPatches)->toBe(0)
        ->and($fixture['state']->dnsPatches)->toBe(0);

    $action->replaceNodeAddress($fixture['actor']->id, NW06_INSTALL_ID, $review['expected']);
    expect($fixture['state']->node['address'])->toBe(NW06_NEW_IP)
        ->and($fixture['state']->node['uuid'])->toBe(NW06_NODE_UUID)
        ->and($fixture['state']->node['configProfile']['activeConfigProfileUuid'])->toBe(NW06_PROFILE_UUID)
        ->and($fixture['state']->node['configProfile']['activeInbounds'])->toBe([['uuid' => NW06_INBOUND_UUID]]);

    $plan = $action->previewReplacementHosts($fixture['actor']->id, NW06_INSTALL_ID);
    expect($plan['hostUpdates'])->toHaveCount(1)
        ->and($plan['dnsUpdates'])->toHaveCount(1)
        ->and($plan['hosts'])->toHaveCount(3);

    $action->transferReplacementHosts($fixture['actor']->id, NW06_INSTALL_ID, $plan);
    $progress = $action->replacementProgress($fixture['actor']->id, NW06_INSTALL_ID);

    expect($fixture['state']->hosts[0]['address'])->toBe(NW06_NEW_IP)
        ->and($fixture['state']->hosts[0]['port'])->toBe(1234)
        ->and($fixture['state']->hosts[0]['nodes'])->toBe([NW06_NODE_UUID])
        ->and($fixture['state']->hosts[1]['address'])->toBe('ae1.coreflare.ir')
        ->and($fixture['state']->hosts[1]['port'])->toBe(443)
        ->and($fixture['state']->hosts[2]['address'])->toBe('ae1.coreflare.ir')
        ->and($fixture['state']->hosts[2]['port'])->toBe(8443)
        ->and($fixture['state']->records[0]['content'])->toBe(NW06_NEW_IP)
        ->and($fixture['state']->records[0]['proxied'])->toBeTrue()
        ->and($fixture['state']->nodePatches)->toBe(1)
        ->and($fixture['state']->hostPatches)->toBe(1)
        ->and($fixture['state']->dnsPatches)->toBe(1)
        ->and($progress['complete'])->toBeTrue()
        ->and($progress['verified'])->toBe(2);
    
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://rw.nw06.test/api/nodes'
        && array_keys($request->data()) === ['uuid', 'address']
        && $request->data()['uuid'] === NW06_NODE_UUID
        && $request->data()['address'] === NW06_NEW_IP);
});

it('NW-06 blocks a shared Host before changing the existing Node address', function (): void {
    $fixture = nw06ReplacementFixture(sharedHost: true);

    expect(fn () => app(CompleteNodeSetup::class)->previewReplacementNode($fixture['actor']->id, NW06_INSTALL_ID))
        ->toThrow(RuntimeException::class);

    expect($fixture['state']->nodePatches)->toBe(0)
        ->and($fixture['state']->hostPatches)->toBe(0)
        ->and($fixture['state']->dnsPatches)->toBe(0)
        ->and($fixture['state']->node['address'])->toBe(NW06_OLD_IP);
});

it('NW-06 blocks ambiguous Cloudflare A records before changing the existing Node address', function (): void {
    $fixture = nw06ReplacementFixture(duplicateDns: true);

    expect(fn () => app(CompleteNodeSetup::class)->previewReplacementNode($fixture['actor']->id, NW06_INSTALL_ID))
        ->toThrow(RuntimeException::class);

    expect($fixture['state']->nodePatches)->toBe(0)
        ->and($fixture['state']->hostPatches)->toBe(0)
        ->and($fixture['state']->dnsPatches)->toBe(0)
        ->and($fixture['state']->node['address'])->toBe(NW06_OLD_IP);
});

it('NW-06 never retries an uncertain Node address PATCH and reconciles with reads only', function (): void {
    $fixture = nw06ReplacementFixture(failNodePatch: true);
    $action = app(CompleteNodeSetup::class);
    $review = $action->previewReplacementNode($fixture['actor']->id, NW06_INSTALL_ID);

    expect(fn () => $action->replaceNodeAddress($fixture['actor']->id, NW06_INSTALL_ID, $review['expected']))
        ->toThrow(Throwable::class);
    expect(Cache::get('nw02:status:'.NW06_INSTALL_ID)['replacementNodeStage'])->toBe('uncertain')
        ->and($fixture['state']->nodePatches)->toBe(1);

    expect(fn () => $action->replaceNodeAddress($fixture['actor']->id, NW06_INSTALL_ID, $review['expected']))
        ->toThrow(RuntimeException::class);
    expect($fixture['state']->nodePatches)->toBe(1);

    $action->reconcileReplacementNode($fixture['actor']->id, NW06_INSTALL_ID);
    expect(Cache::get('nw02:status:'.NW06_INSTALL_ID)['replacementNodeStage'])->toBe('ready')
        ->and($fixture['state']->nodePatches)->toBe(1);
});
