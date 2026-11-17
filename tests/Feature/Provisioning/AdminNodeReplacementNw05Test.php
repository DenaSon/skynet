<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Interfaces\Telegram\Support\AdminNodeSetupUi;
use App\Modules\Provisioning\Application\ReplaceAdminNode;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
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
const NW05_HOST_OTHER = '550e8400-e29b-41d4-a716-446655440057';
const NW05_ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const NW05_RECORD_NODE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const NW05_RECORD_XHTTP = 'cccccccccccccccccccccccccccccccc';
const NW05_OLD_IP = '8.8.4.4';
const NW05_NEW_IP = '1.1.1.1';

/** @return array{actor:User,id:string} */
function nw05ReplacementReady(bool $directIpNode = false): array
{
    config()->set('super_admin.telegram_ids', ['985501', '985502']);
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
        'address' => $directIpNode ? NW05_OLD_IP : 'fr.coreflare.ir',
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

/** @return object{node:array<string,mixed>,hosts:list<array<string,mixed>>,records:array<string,array<string,mixed>>} */
function nw05ReplacementApi(
    bool $sharedHost = false,
    bool $mismatchedDns = false,
    bool $failDnsWrite = false,
    bool $emptyMembership = false,
    bool $sharedHostname = false,
    bool $directIpNode = false,
    bool $nodeConnected = true,
): object {
    $node = [
        'uuid' => NW05_NODE,
        'name' => 'Germany',
        'address' => $directIpNode ? NW05_OLD_IP : 'fr.coreflare.ir',
        'port' => 2222,
        'isConnected' => $nodeConnected,
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

    $hostNodes = $sharedHost ? [NW05_NODE, NW05_OTHER_NODE] : ($emptyMembership ? [] : [NW05_NODE]);
    $hosts = [
        [
            'uuid' => NW05_HOST_REALITY,
            'remark' => 'Germany-REALITY',
            'address' => $directIpNode ? NW05_OLD_IP : 'fr.coreflare.ir',
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
    if ($sharedHostname) {
        $hosts[] = [
            'uuid' => NW05_HOST_OTHER,
            'remark' => 'Other-Node-Host',
            'address' => $directIpNode ? 'xhttp.coreflare.ir' : 'fr.coreflare.ir',
            'port' => 10443,
            'isDisabled' => false,
            'inbound' => ['configProfileUuid' => NW05_PROFILE, 'configProfileInboundUuid' => NW05_INBOUND],
            'nodes' => [NW05_OTHER_NODE],
        ];
    }

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

    $shared = (object) ['node' => $node, 'hosts' => $hosts, 'records' => $records];

    Http::fake(static function (Request $request) use ($shared, $failDnsWrite) {
        $url = $request->url();
        $method = $request->method();
        $rw = 'https://rw.nw05.test/api/';

        if ($method === 'GET' && $url === $rw.'nodes') {
            return Http::response(['response' => [$shared->node]], 200);
        }
        if ($method === 'GET' && $url === $rw.'nodes/'.NW05_NODE) {
            return Http::response(['response' => $shared->node], 200);
        }
        if ($method === 'PATCH' && $url === $rw.'nodes') {
            $shared->node['address'] = $request['address'];

            return Http::response(['response' => ['uuid' => NW05_NODE]], 200);
        }
        if ($method === 'GET' && $url === $rw.'hosts') {
            return Http::response(['response' => $shared->hosts], 200);
        }
        if ($method === 'GET' && preg_match('~/hosts/([0-9a-f-]+)$~', $url, $matches) === 1) {
            $host = collect($shared->hosts)->firstWhere('uuid', $matches[1]);

            return is_array($host)
                ? Http::response(['response' => $host], 200)
                : Http::response(['error' => 'host not found'], 404);
        }
        if ($method === 'PATCH' && $url === $rw.'hosts') {
            foreach ($shared->hosts as $index => $host) {
                if ($host['uuid'] === $request['uuid']) {
                    $shared->hosts[$index]['address'] = $request['address'];
                }
            }

            return Http::response(['response' => ['uuid' => $request['uuid']]], 200);
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
                ? $shared->records[NW05_RECORD_XHTTP]
                : $shared->records[NW05_RECORD_NODE];

            return Http::response([
                'success' => true,
                'result' => [$record],
                'result_info' => ['total_count' => 1],
            ], 200);
        }
        if ($method === 'GET' && str_ends_with($url, '/dns_records/'.NW05_RECORD_NODE)) {
            return Http::response(['success' => true, 'result' => $shared->records[NW05_RECORD_NODE]], 200);
        }
        if ($method === 'GET' && str_ends_with($url, '/dns_records/'.NW05_RECORD_XHTTP)) {
            return Http::response(['success' => true, 'result' => $shared->records[NW05_RECORD_XHTTP]], 200);
        }
        if ($method === 'PATCH' && str_ends_with($url, '/dns_records/'.NW05_RECORD_NODE)) {
            if ($failDnsWrite) {
                return Http::response(['success' => false], 503);
            }
            $shared->records[NW05_RECORD_NODE]['content'] = $request['content'];
            $shared->records[NW05_RECORD_NODE]['proxied'] = $request['proxied'];

            return Http::response(['success' => true, 'result' => $shared->records[NW05_RECORD_NODE]], 200);
        }
        if ($method === 'PATCH' && str_ends_with($url, '/dns_records/'.NW05_RECORD_XHTTP)) {
            $shared->records[NW05_RECORD_XHTTP]['content'] = $request['content'];
            $shared->records[NW05_RECORD_XHTTP]['proxied'] = $request['proxied'];

            return Http::response(['success' => true, 'result' => $shared->records[NW05_RECORD_XHTTP]], 200);
        }

        return Http::response(['error' => 'unexpected NW-05 request'], 404);
    });

    return $shared;
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

    expect($progress['stage'])->toBe('awaiting_client_test')
        ->and($progress['connected'])->toBeTrue();

    $action->acceptClientTest($ready['actor']->id, $ready['id']);
    $progress = $action->progress($ready['actor']->id, $ready['id']);

    expect($progress['stage'])->toBe('completed')
        ->and($api->node['uuid'])->toBe(NW05_NODE)
        ->and($api->node['address'])->toBe('fr.coreflare.ir')
        ->and($api->records[NW05_RECORD_NODE]['content'])->toBe(NW05_NEW_IP)
        ->and($api->records[NW05_RECORD_NODE]['proxied'])->toBeFalse()
        ->and($api->records[NW05_RECORD_XHTTP]['content'])->toBe(NW05_NEW_IP)
        ->and($api->records[NW05_RECORD_XHTTP]['proxied'])->toBeTrue()
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


it('uses inbound and current-address evidence for legacy Hosts with empty Node membership', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi(emptyMembership: true);

    $review = app(ReplaceAdminNode::class)->preview($ready['actor']->id, $ready['id']);

    expect(array_column($review['hosts'], 'uuid'))->toBe([NW05_HOST_REALITY, NW05_HOST_XHTTP])
        ->and($review['hosts'][0]['nodes'])->toBe([])
        ->and($review['hosts'][0]['association'])->toBe('inbound_address')
        ->and(nw05MutationCount())->toBe(0);
});

it('rejects a hostname consumed by a different Node before any provider write', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi(sharedHostname: true);

    expect(fn () => app(ReplaceAdminNode::class)->preview($ready['actor']->id, $ready['id']))
        ->toThrow(RuntimeException::class);
    expect(nw05MutationCount())->toBe(0);
});

it('revalidates the complete Host inventory after preview and before the first write', function (): void {
    $ready = nw05ReplacementReady();
    $api = nw05ReplacementApi();
    $action = app(ReplaceAdminNode::class);
    $review = $action->preview($ready['actor']->id, $ready['id']);
    $api->hosts[0]['nodes'] = [NW05_NODE, NW05_OTHER_NODE];

    expect(fn () => $action->apply($ready['actor']->id, $ready['id'], $review))
        ->toThrow(RuntimeException::class);
    expect(nw05MutationCount())->toBe(0);
});

it('moves direct-IP Hosts and the Node address last while preserving the Node and Host UUIDs', function (): void {
    $ready = nw05ReplacementReady(directIpNode: true);
    $api = nw05ReplacementApi(directIpNode: true);
    $action = app(ReplaceAdminNode::class);
    $review = $action->preview($ready['actor']->id, $ready['id']);

    $action->apply($ready['actor']->id, $ready['id'], $review);
    $progress = $action->progress($ready['actor']->id, $ready['id']);

    expect($progress['stage'])->toBe('awaiting_client_test')
        ->and($api->node['uuid'])->toBe(NW05_NODE)
        ->and($api->node['address'])->toBe(NW05_NEW_IP)
        ->and($api->hosts[0]['uuid'])->toBe(NW05_HOST_REALITY)
        ->and($api->hosts[0]['address'])->toBe(NW05_NEW_IP)
        ->and($api->records[NW05_RECORD_XHTTP]['content'])->toBe(NW05_NEW_IP)
        ->and($api->records[NW05_RECORD_XHTTP]['proxied'])->toBeTrue()
        ->and(nw05MutationCount())->toBe(3);
});

it('does not accept the client test while Remnawave reports the Node disconnected', function (): void {
    $ready = nw05ReplacementReady();
    $api = nw05ReplacementApi(nodeConnected: false);
    $action = app(ReplaceAdminNode::class);
    $review = $action->preview($ready['actor']->id, $ready['id']);

    $action->apply($ready['actor']->id, $ready['id'], $review);
    expect($action->progress($ready['actor']->id, $ready['id']))->toMatchArray([
        'stage' => 'awaiting_client_test',
        'connected' => false,
    ]);
    expect(fn () => $action->acceptClientTest($ready['actor']->id, $ready['id']))
        ->toThrow(RuntimeException::class);

    $api->node['isConnected'] = true;
    $action->acceptClientTest($ready['actor']->id, $ready['id']);
    expect($action->progress($ready['actor']->id, $ready['id'])['stage'])->toBe('completed');
});

it('renders the replacement confirmation Rich-first and keeps an equivalent escaped HTML fallback', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi();
    $keyboard = null;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(985501, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(985501, false, 'Operator'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(function (...$arguments) use (&$keyboard): void {
        foreach ($arguments as $argument) {
            if ($argument instanceof InputRichMessage) {
                expect($argument->is_rtl)->toBeTrue()
                    ->and($argument->html)->toContain('<table bordered striped compact>')
                    ->toContain('fr.coreflare.ir', 'DNS Only', 'Hostهای حفظ‌شده');
            }
            if ($argument instanceof InlineKeyboardMarkup) {
                $keyboard = $argument;
            }
        }
        throw new TelegramException('Bad Request: rich messages unavailable', 400);
    });
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function (...$arguments) use (&$keyboard): null {
        $text = $arguments[0];
        expect($text)->toContain('8.8.4.4', '1.1.1.1', 'fr.coreflare.ir')
            ->and($text)->toContain('<code>')
            ->and(in_array('HTML', $arguments, true))->toBeTrue();
        expect($keyboard)->toBeInstanceOf(InlineKeyboardMarkup::class)
            ->and(json_encode($keyboard, JSON_THROW_ON_ERROR))->toContain('nw5:apply:');

        return null;
    });

    app(AdminNodeSetupUi::class)->prepareReplacement($bot, $ready['id']);
    expect(nw05MutationCount())->toBe(0);
});

it('renders replacement progress as an RTL Rich screen and labels the panel connection as informational', function (): void {
    $ready = nw05ReplacementReady();
    nw05ReplacementApi();
    Cache::put('nw05:state:'.$ready['id'], [
        'owner' => $ready['actor']->id,
        'node' => [
            'uuid' => NW05_NODE,
            'name' => 'Germany',
            'address' => 'fr.coreflare.ir',
            'port' => 2222,
            'connected' => true,
            'disabled' => false,
            'profileUuid' => NW05_PROFILE,
            'inboundUuids' => [NW05_INBOUND],
        ],
        'oldIp' => NW05_OLD_IP,
        'newIp' => NW05_NEW_IP,
        'nodeAddressMode' => 'domain',
        'nodeAddressStage' => 'skipped',
        'dnsRecords' => [['hostname' => 'fr.coreflare.ir', 'stage' => 'verified']],
        'directHosts' => [],
        'stage' => 'awaiting_client_test',
    ], now()->addHours(3));

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(985501, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(985501, false, 'Operator'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(function (...$arguments): null {
        foreach ($arguments as $argument) {
            if ($argument instanceof InputRichMessage) {
                expect($argument->is_rtl)->toBeTrue()
                    ->and($argument->html)->toContain('وضعیت جایگزینی سرور')
                    ->toContain('متصل · تست کاربران لازم است');
            }
        }

        return null;
    });

    app(AdminNodeSetupUi::class)->replacementOverview($bot, $ready['id']);
});
