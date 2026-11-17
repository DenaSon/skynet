<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReplaceAdminNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const NW05_NODE = '550e8400-e29b-41d4-a716-446655440051';
const NW05_OTHER_NODE = '550e8400-e29b-41d4-a716-446655440052';
const NW05_PROFILE = '550e8400-e29b-41d4-a716-446655440053';
const NW05_INBOUND = '550e8400-e29b-41d4-a716-446655440054';
const NW05_HOST_REALITY = '550e8400-e29b-41d4-a716-446655440055';
const NW05_HOST_XHTTP = '550e8400-e29b-41d4-a716-446655440056';
const NW05_ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const NW05_RECORD_NODE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const NW05_RECORD_XHTTP = 'cccccccccccccccccccccccccccccccc';
const NW05_OLD_IP = '8.8.4.4';
const NW05_NEW_IP = '1.1.1.1';

/** @return array{actor:User,id:string} */
function nw05ReplacementReady(): array
{
    config()->set('super_admin.telegram_ids', ['985501']);
    config()->set('services.remnawave.url', 'https://rw.nw05.test');
    config()->set('services.remnawave.token', 'nw05-test-token');
    config()->set('services.remnawave.timeout', 15);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.node_ssh_install_enabled', true);
    config()->set('services.cloudflare.token', 'nw05-cloudflare-token');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    Http::preventStrayRequests();

    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 985501]);
    $id = str_repeat('d', 32);
    $target = [
        'uuid' => NW05_NODE,
        'name' => 'Germany',
        'address' => 'fr.coreflare.ir',
        'port' => 2222,
        'connected' => true,
        'disabled' => false,
        'profileUuid' => NW05_PROFILE,
        'inboundUuids' => [NW05_INBOUND],
    ];
    Cache::put('nw02:status:'.$id, [
        'owner' => $actor->id,
        'ip' => NW05_NEW_IP,
        'name' => 'Germany',
        'profileUuid' => NW05_PROFILE,
        'inboundUuid' => NW05_INBOUND,
        'state' => 'running',
        'replacement' => $target,
    ], now()->addHours(3));

    return compact('actor', 'id');
}

/**
 * @return array{node:array<string,mixed>,records:array<string,array<string,mixed>>}
 */
function nw05ReplacementApi(bool $sharedHost = false, bool $mismatchedDns = false, bool $failDnsWrite = false): array
{
    $node = [
        'uuid' => NW05_NODE,
        'name' => 'Germany',
        'address' => 'fr.coreflare.ir',
        'port' => 2222,
        'isConnected' => true,
        'isDisabled' => false,
        'configProfile' => [
            'activeConfigProfileUuid' => NW05_PROFILE,
            'activeInbounds' => [[
                'uuid' => NW05_INBOUND,
                'profileUuid' => NW05_PROFILE,
                'tag' => 'VLESS REALITY',
                'type' => 'VLESS',
                'network' => 'tcp',
                'security' => 'reality',
                'port' => 443,
                'rawInbound' => null,
            ]],
        ],
    ];

    $hostNodes = $sharedHost ? [NW05_NODE, NW05_OTHER_NODE] : [NW05_NODE];
    $hosts = [
        [
            'uuid' => NW05_HOST_REALITY,
            'remark' => 'Germany-REALITY',
            'address' => 'fr.coreflare.ir',
            'port' => 443,
            'isDisabled' => false,
            'inbound' => ['configProfileUuid' => NW05_PROFILE, 'configProfileInboundUuid' => NW05_INBOUND],
            'nodes' => $hostNodes,
        ],
        [
            'uuid' => NW05_HOST_XHTTP,
            'remark' => 'Germany-XHTTP',
            'address' => 'xhttp.coreflare.ir',
            'port' => 8443,
            'isDisabled' => false,
            'inbound' => ['configProfileUuid' => NW05_PROFILE, 'configProfileInboundUuid' => NW05_INBOUND],
            'nodes' => [NW05_NODE],
        ],
    ];

    $records = [
        NW05_RECORD_NODE => [
            'id' => NW05_RECORD_NODE, 'name' => 'fr.coreflare.ir', 'type' => 'A',
            'content' => NW05_OLD_IP, 'proxied' => false, 'proxiable' => true,
            'modified_on' => '2026-11-17T08:00:00.000Z',
        ],
        NW05_RECORD_XHTTP => [
            'id' => NW05_RECORD_XHTTP, 'name' => 'xhttp.coreflare.ir', 'type' => 'A',
            'content' => $mismatchedDns ? '9.9.9.9' : NW05_OLD_IP,
            'proxied' => true, 'proxiable' => true,
            'modified_on' => '2026-11-17T08:00:00.000Z',
        ],
    ];

    Http::fake(static function (Request $request) use (&$node, $hosts, &$records, $failDnsWrite) {
        $url = $request->url();
        $method = $request->method();
        $rw = 'https://rw.nw05.test/api/';

        if ($method === 'GET' && $url === $rw.'nodes/'.NW05_NODE) {
            return Http::response(['response' => $node], 200);
        }
        if ($method === 'GET' && $url === $rw.'hosts') {
            return Http::response(['response' => $hosts], 200);
        }

        $cf = 'https://api.cloudflare.com/client/v4';
        if ($method === 'GET' && str_starts_with($url, $cf.'/zones?')) {
            return Http::response([
                'success' => true,
                'result' => [['id' => NW05_ZONE, 'name' => 'coreflare.ir']],
                'result_info' => ['total_count' => 1],
            ], 200);
        }
        if ($method === 'GET' && str_contains($url, '/dns_records?')) {
            $record = str_contains($url, 'xhttp.coreflare.ir')
                ? $records[NW05_RECORD_XHTTP]
                : $records[NW05_RECORD_NODE];

            return Http::response([
                'success' => true,
                'result' => [$record],
                'result_info' => ['total_count' => 1],
            ], 200);
        }
        if ($method === 'GET' && str_ends_with($url, '/dns_records/'.NW05_RECORD_NODE)) {
            return Http::response(['success' => true, 'result' => $records[NW05_RECORD_NODE]], 200);
        }
        if ($method === 'GET' && str_ends_with($url, '/dns_records/'.NW05_RECORD_XHTTP)) {
            return Http::response(['success' => true, 'result' => $records[NW05_RECORD_XHTTP]], 200);
        }
        if ($method === 'PATCH' && str_ends_with($url, '/dns_records/'.NW05_RECORD_NODE)) {
            if ($failDnsWrite) {
                return Http::response(['success' => false], 503);
            }
            $records[NW05_RECORD_NODE]['content'] = $request['content'];
            $records[NW05_RECORD_NODE]['proxied'] = $request['proxied'];

            return Http::response(['success' => true, 'result' => $records[NW05_RECORD_NODE]], 200);
        }
        if ($method === 'PATCH' && str_ends_with($url, '/dns_records/'.NW05_RECORD_XHTTP)) {
            $records[NW05_RECORD_XHTTP]['content'] = $request['content'];
            $records[NW05_RECORD_XHTTP]['proxied'] = $request['proxied'];

            return Http::response(['success' => true, 'result' => $records[NW05_RECORD_XHTTP]], 200);
        }

        return Http::response(['error' => 'unexpected NW-05 request'], 404);
    });

    return compact('node', 'records');
}

function nw05MutationCount(): int
{
    return collect(Http::recorded())
        ->filter(static fn (array $pair): bool => $pair[0]->method() !== 'GET')
        ->count();
}

it('previews the existing Node and all unique Host domains without mutating providers', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi();

    $review = app(ReplaceAdminNode::class)->preview($ready['actor']->id, $ready['id']);

    expect($review['node']['uuid'])->toBe(NW05_NODE)
        ->and($review['nodeAddressMode'])->toBe('domain')
        ->and($review['oldIp'])->toBe(NW05_OLD_IP)
        ->and($review['newIp'])->toBe(NW05_NEW_IP)
        ->and(array_column($review['dnsRecords'], 'hostname'))->toBe([
            'fr.coreflare.ir',
            'xhttp.coreflare.ir',
        ])
        ->and($review['dnsRecords'][0]['record']['proxied'])->toBeFalse()
        ->and($review['dnsRecords'][1]['record']['proxied'])->toBeTrue()
        ->and(nw05MutationCount())->toBe(0);
});

it('moves all associated A records and preserves Node identity, Host identity and Proxy state', function (): void {
    $ready = nw05ReplacementReady();
    $api = nw05ReplacementApi();
    $action = app(ReplaceAdminNode::class);
    $review = $action->preview($ready['actor']->id, $ready['id']);

    $action->apply($ready['actor']->id, $ready['id'], $review);
    $progress = $action->progress($ready['actor']->id, $ready['id']);

    expect($progress['stage'])->toBe('completed')
        ->and($progress['connected'])->toBeTrue()
        ->and($api['node']['uuid'])->toBe(NW05_NODE)
        ->and($api['node']['address'])->toBe('fr.coreflare.ir')
        ->and($api['records'][NW05_RECORD_NODE]['content'])->toBe(NW05_NEW_IP)
        ->and($api['records'][NW05_RECORD_NODE]['proxied'])->toBeFalse()
        ->and($api['records'][NW05_RECORD_XHTTP]['content'])->toBe(NW05_NEW_IP)
        ->and($api['records'][NW05_RECORD_XHTTP]['proxied'])->toBeTrue()
        ->and(nw05MutationCount())->toBe(2);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/dns_records/'.NW05_RECORD_NODE));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/dns_records/'.NW05_RECORD_XHTTP));
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET'
        && str_contains($request->url(), 'rw.nw05.test/api/nodes'));
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET'
        && str_contains($request->url(), 'rw.nw05.test/api/hosts'));
});

it('refuses shared Hosts before any provider write', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi(sharedHost: true);

    expect(fn () => app(ReplaceAdminNode::class)->preview($ready['actor']->id, $ready['id']))
        ->toThrow(RuntimeException::class);
    expect(nw05MutationCount())->toBe(0);
});

it('refuses a Host DNS record that does not point to the current Node IP', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi(mismatchedDns: true);

    expect(fn () => app(ReplaceAdminNode::class)->preview($ready['actor']->id, $ready['id']))
        ->toThrow(RuntimeException::class);
    expect(nw05MutationCount())->toBe(0);
});

it('never repeats an uncertain Cloudflare write and only reconciles with GET', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi(failDnsWrite: true);
    $action = app(ReplaceAdminNode::class);
    $review = $action->preview($ready['actor']->id, $ready['id']);

    expect(fn () => $action->apply($ready['actor']->id, $ready['id'], $review))
        ->toThrow(RuntimeException::class);

    expect(fn () => $action->reconcile($ready['actor']->id, $ready['id']))
        ->toThrow(RuntimeException::class);

    $writes = collect(Http::recorded())->filter(
        static fn (array $pair): bool => $pair[0]->method() === 'PATCH',
    );
    expect($writes)->toHaveCount(1);
});
