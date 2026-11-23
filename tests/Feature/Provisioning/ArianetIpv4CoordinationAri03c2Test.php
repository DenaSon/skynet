<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\CoordinateAdminArianetIpv4;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

const ARI8_SERVER = 101305418924;
const ARI8_NODE = '550e8400-e29b-41d4-a716-446655440181';
const ARI8_PEER = '550e8400-e29b-41d4-a716-446655440182';
const ARI8_PROFILE = '550e8400-e29b-41d4-a716-446655440183';
const ARI8_INBOUND = '550e8400-e29b-41d4-a716-446655440184';
const ARI8_DOMAIN_HOST = '550e8400-e29b-41d4-a716-446655440185';
const ARI8_DIRECT_HOST = '550e8400-e29b-41d4-a716-446655440186';
const ARI8_PEER_HOST = '550e8400-e29b-41d4-a716-446655440187';
const ARI8_ZONE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const ARI8_RECORD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const ARI8_OLD = '62.144.51.204';
const ARI8_NEW = '62.144.51.206';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['998801', '998802']);
    config()->set('services.arianet_cloud.read_token', 'ari8-cloud');
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.remnawave.url', 'https://rw.ari8.test');
    config()->set('services.remnawave.token', 'ari8-rw');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.cloudflare.token', 'ari8-cf');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    Http::preventStrayRequests();
});

/** @return array{user:User,serverId:int} */
function ari8Ready(bool $super = true): array
{
    $user = User::factory()->create();
    if ($super) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 998801]);
    DB::table('arianet_cloud_node_links')->insert([
        'cloud_server_id' => ARI8_SERVER, 'node_uuid' => ARI8_NODE,
        'created_by' => $user->id, 'updated_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('arianet_cloud_ipv4_changes')->insert([
        'cloud_server_id' => ARI8_SERVER,
        'active_server_id' => ARI8_SERVER,
        'actor_user_id' => $user->id,
        'token_hash' => hash('sha256', 'ari8'),
        'old_ipv4' => ARI8_OLD, 'new_ipv4' => ARI8_NEW,
        'fee_rials' => 2500000, 'free_left' => 0,
        'snapshot_hash' => hash('sha256', 'ari8-snapshot'),
        'status' => 'awaiting_coordination', 'action_id' => 88,
        'expires_at' => now()->addMinutes(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['user' => $user, 'serverId' => ARI8_SERVER];
}

/** @return object{node:array<string,mixed>,hosts:list<array<string,mixed>>,record:array<string,mixed>,mutations:list<string>,failDns:bool} */
function ari8Provider(bool $shared = false, bool $legacy = false, bool $peerCollision = false): object
{
    $node = [
        'uuid' => ARI8_NODE, 'name' => 'GermanyOVH',
        'address' => 'fr.coreflare.ir', 'port' => 2222,
        'isConnected' => true, 'isDisabled' => false,
        'configProfile' => [
            'activeConfigProfileUuid' => ARI8_PROFILE,
            'activeInbounds' => [['uuid' => ARI8_INBOUND]],
        ],
    ];
    $hosts = [
        [
            'uuid' => ARI8_DOMAIN_HOST, 'remark' => 'Germany REALITY',
            'address' => 'fr.coreflare.ir', 'port' => 443, 'isDisabled' => false,
            'nodes' => $shared ? [ARI8_NODE, ARI8_PEER] : [ARI8_NODE],
            'inbound' => [
                'configProfileUuid' => ARI8_PROFILE, 'configProfileInboundUuid' => ARI8_INBOUND,
            ],
        ],
        [
            'uuid' => ARI8_DIRECT_HOST, 'remark' => 'Germany other Host',
            'address' => 'fr.coreflare.ir', 'port' => 8443, 'isDisabled' => false,
            'nodes' => $legacy ? [] : [ARI8_NODE],
            'inbound' => [
                'configProfileUuid' => ARI8_PROFILE, 'configProfileInboundUuid' => ARI8_INBOUND,
            ],
        ],
        [
            'uuid' => ARI8_PEER_HOST, 'remark' => 'Netherlands',
            'address' => 'nl01.coreflare.ir', 'port' => 1234, 'isDisabled' => false,
            'nodes' => [ARI8_PEER],
            'inbound' => [
                'configProfileUuid' => ARI8_PROFILE, 'configProfileInboundUuid' => ARI8_INBOUND,
            ],
        ],
    ];
    $record = [
        'id' => ARI8_RECORD, 'name' => 'fr.coreflare.ir',
        'type' => 'A', 'content' => ARI8_OLD, 'proxied' => false,
        'proxiable' => true, 'modified_on' => '2026-11-23T15:00:00Z',
    ];
    $shared = (object) [
        'node' => $node, 'hosts' => $hosts, 'record' => $record, 'mutations' => [],
        'failDns' => false, 'peerCollision' => $peerCollision,
    ];

    Http::fake(static function (Request $req) use ($shared) {
        $url = $req->url();
        $method = $req->method();
        $rw = 'https://rw.ari8.test/api/';
        $cf = 'https://api.cloudflare.com/client/v4';

        if ($method === 'GET' && $url === 'https://api.ariaservice.net/cloud/v1/servers/'.ARI8_SERVER) {
            return Http::response(['data' => [
                'id' => ARI8_SERVER, 'name' => 'Staging Germany', 'status' => 'running',
                'datacenter' => 'ovh', 'ipv4' => ARI8_NEW,
                'ip_change' => [
                    'allowed' => true, 'pending' => false, 'free_left' => 0,
                    'used' => 1, 'currency' => 'IRR', 'fee' => 2500000,
                ],
            ]]);
        }
        if ($method === 'GET' && $url === $rw.'nodes/'.ARI8_NODE) {
            return Http::response(['response' => $shared->node]);
        }
        if ($method === 'GET' && $url === $rw.'nodes') {
            return Http::response(['response' => [
                $shared->node,
                [
                    'uuid' => ARI8_PEER, 'name' => 'Netherlands',
                    'address' => $shared->peerCollision ? 'fr.coreflare.ir' : 'nl01.coreflare.ir',
                    'port' => 2222, 'isConnected' => true, 'isDisabled' => false,
                ],
            ]]);
        }
        if ($method === 'GET' && $url === $rw.'hosts') {
            return Http::response(['response' => $shared->hosts]);
        }
        if ($method === 'GET' && preg_match('~/hosts/([0-9a-f-]+)$~', $url, $match) === 1) {
            $host = collect($shared->hosts)->firstWhere('uuid', $match[1]);

            return is_array($host) ? Http::response(['response' => $host])
                : Http::response(['error' => 'not found'], 404);
        }

        if ($method === 'PATCH' && $url === $rw.'nodes') {
            $shared->mutations[] = 'node:'.ARI8_NODE;
            $shared->node['address'] = $req['address'];

            return Http::response(['response' => ['uuid' => ARI8_NODE]]);
        }
        if ($method === 'PATCH' && $url === $rw.'hosts') {
            $shared->mutations[] = 'host:'.$req['uuid'];
            foreach ($shared->hosts as $i => $host) {
                if ($host['uuid'] === $req['uuid']) {
                    $shared->hosts[$i]['address'] = $req['address'];
                }
            }

            return Http::response(['response' => ['uuid' => $req['uuid']]]);
        }
        if ($method === 'GET' && str_starts_with($url, $cf.'/zones?')) {
            return Http::response(['success' => true, 'result' => [
                ['id' => ARI8_ZONE, 'name' => 'coreflare.ir'],
            ]]);
        }
        if ($method === 'GET' && str_contains($url, '/dns_records?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $record = ($query['name'] ?? null) === 'fr.coreflare.ir' ? [$shared->record] : [];

            return Http::response(['success' => true, 'result' => $record]);
        }
        if ($method === 'GET' && str_ends_with($url, '/dns_records/'.ARI8_RECORD)) {
            return Http::response(['success' => true, 'result' => $shared->record]);
        }
        if ($method === 'PATCH' && str_ends_with($url, '/dns_records/'.ARI8_RECORD)) {
            $shared->mutations[] = 'dns:fr.coreflare.ir';
            if ($shared->failDns) {
                return Http::response(['success' => false], 503);
            }
            $shared->record['content'] = $req['content'];
            $shared->record['proxied'] = $req['proxied'];

            return Http::response(['success' => true, 'result' => $shared->record]);
        }

        return Http::response(['error' => 'Unexpected provider request'], 404);
    });

    return $shared;
}

it('prepares only one unique managed DNS record for a domain-based Node', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    $service = app(CoordinateAdminArianetIpv4::class);
    $plan = $service->prepare($root['user']->id, ARI8_SERVER);
    expect($plan['stage'])->toBe('ready')
        ->and($plan['steps'])->toHaveCount(1)
        ->and($plan['steps'][0])->toMatchArray(['kind' => 'dns', 'name' => 'fr.coreflare.ir'])
        ->and($plan['next'])->toBe(0)
        ->and($api->mutations)->toBe([])
        ->and(DB::table('arianet_cloud_ipv4_changes')->value('coordination_state'))->not->toBeNull();
    expect(fn () => $service->prepare($root['user']->id, ARI8_SERVER))->toThrow(RuntimeException::class);
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');
});
it('continues DNS with no Remnawave mutation and requires real client acceptance', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    $service = app(CoordinateAdminArianetIpv4::class);

    $service->continueDns($root['user']->id, ARI8_SERVER);
    $status = $service->status($root['user']->id, ARI8_SERVER);
    expect($status['stage'])->toBe('awaiting_client_test')
        ->and($api->record['content'])->toBe(ARI8_NEW)
        ->and($api->record['proxied'])->toBeFalse()
        ->and($api->node['address'])->toBe('fr.coreflare.ir')
        ->and($api->hosts[1]['address'])->toBe('fr.coreflare.ir')
        ->and($api->hosts[2]['address'])->toBe('nl01.coreflare.ir')
        ->and($api->mutations)->toBe(['dns:fr.coreflare.ir']);
    $service->continueDns($root['user']->id, ARI8_SERVER);
    expect($api->mutations)->toHaveCount(1);
    $service->acceptClientTest($root['user']->id, ARI8_SERVER);
    expect($service->status($root['user']->id, ARI8_SERVER)['stage'])->toBe('completed')
        ->and(DB::table('arianet_cloud_ipv4_changes')->value('active_server_id'))->toBeNull();
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && str_contains($r->url(), 'rw.ari8.test'));
});
it('rejects a direct-IP Node or Host instead of modifying Remnawave', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    $service = app(CoordinateAdminArianetIpv4::class);

    $api->node['address'] = ARI8_OLD;
    expect(fn () => $service->prepare($root['user']->id, ARI8_SERVER))->toThrow(RuntimeException::class);
    $api->node['address'] = 'fr.coreflare.ir';
    $api->hosts[1]['address'] = ARI8_OLD;
    expect(fn () => $service->prepare($root['user']->id, ARI8_SERVER))->toThrow(RuntimeException::class);
    expect($api->mutations)->toBe([])
        ->and(DB::table('arianet_cloud_ipv4_changes')->value('coordination_state'))->toBeNull();
});
it('ignores an unrelated unassigned Host despite its shared Profile and Inbound', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    $api->hosts[2]['nodes'] = [];
    $service = app(CoordinateAdminArianetIpv4::class);

    $plan = $service->prepare($root['user']->id, ARI8_SERVER);
    expect($plan['steps'])->toHaveCount(1)
        ->and($plan['steps'][0]['kind'])->toBe('dns')
        ->and($api->mutations)->toBe([]);
});

it('rejects a historic direct-IP step without consuming a DNS write checkpoint', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    $service = app(CoordinateAdminArianetIpv4::class);
    $service->prepare($root['user']->id, ARI8_SERVER);

    $saved = DB::table('arianet_cloud_ipv4_changes')->value('coordination_state');
    $state = json_decode((string) $saved, true, flags: JSON_THROW_ON_ERROR);
    $state['steps'][0]['kind'] = 'host';
    DB::table('arianet_cloud_ipv4_changes')->update([
        'coordination_state' => json_encode($state, JSON_THROW_ON_ERROR),
    ]);

    expect(fn () => $service->executeNext($root['user']->id, ARI8_SERVER, 0))
        ->toThrow(RuntimeException::class, 'manual review');
    expect($service->status($root['user']->id, ARI8_SERVER)['steps'][0]['state'])->toBe('ready')
        ->and($api->mutations)->toBe([]);
});

it('updates only one Cloudflare A record for a domain-matched unassigned Host', function (): void {
    $root = ari8Ready();
    $api = ari8Provider(legacy: true);
    $service = app(CoordinateAdminArianetIpv4::class);

    $service->continueDns($root['user']->id, ARI8_SERVER);
    $status = $service->status($root['user']->id, ARI8_SERVER);
    expect($status['stage'])->toBe('awaiting_client_test')
        ->and($status['steps'])->toHaveCount(1)
        ->and($api->mutations)->toBe(['dns:fr.coreflare.ir'])
        ->and($api->hosts[1]['nodes'])->toBe([])
        ->and($api->hosts[1]['address'])->toBe('fr.coreflare.ir')
        ->and($api->node['address'])->toBe('fr.coreflare.ir');
    Http::assertNotSent(static fn (Request $req): bool => $req->method() === 'PATCH'
        && str_contains($req->url(), 'rw.ari8.test'));
});

it('marks provider timeout uncertain before any replay and only reconciles via GET', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    $api->failDns = true;
    $service = app(CoordinateAdminArianetIpv4::class);
    $service->prepare($root['user']->id, ARI8_SERVER);

    expect(fn () => $service->executeNext($root['user']->id, ARI8_SERVER, 0))
        ->toThrow(RuntimeException::class, 'reconciliation');
    expect($service->status($root['user']->id, ARI8_SERVER)['steps'][0]['state'])->toBe('uncertain')
        ->and($api->mutations)->toBe(['dns:fr.coreflare.ir']);
    expect(fn () => $service->executeNext($root['user']->id, ARI8_SERVER, 0))->toThrow(RuntimeException::class);
    expect(fn () => $service->reconcile($root['user']->id, ARI8_SERVER))->toThrow(RuntimeException::class);
    expect($api->mutations)->toHaveCount(1);

    $api->record['content'] = ARI8_NEW;
    $service->reconcile($root['user']->id, ARI8_SERVER);
    expect($service->status($root['user']->id, ARI8_SERVER)['steps'][0]['state'])->toBe('verified')
        ->and($api->mutations)->toHaveCount(1);
});

it('blocks shared Host ownership and peer collisions before DNS coordination', function (): void {
    foreach (['shared', 'peerCollision'] as $issue) {
        $root = ari8Ready();
        $api = ari8Provider(
            shared: $issue === 'shared',
            legacy: $issue === 'legacy',
            peerCollision: $issue === 'peerCollision',
        );
        $service = app(CoordinateAdminArianetIpv4::class);

        expect(fn () => $service->prepare($root['user']->id, ARI8_SERVER))->toThrow(RuntimeException::class);
        expect(DB::table('arianet_cloud_ipv4_changes')->value('coordination_state'))->toBeNull()
            ->and($api->mutations)->toBe([]);

        DB::table('arianet_cloud_ipv4_changes')->delete();
        DB::table('arianet_cloud_node_links')->delete();
        TelegramAccount::query()->where('user_id', $root['user']->id)->delete();
    }
});

it('permits DNS-only continuation in Production with existing Cloudflare write gate', function (): void {
    $root = ari8Ready();
    $api = ari8Provider();
    app()->detectEnvironment(static fn (): string => 'production');
    try {
        $service = app(CoordinateAdminArianetIpv4::class);
        expect($service->enabled())->toBeTrue();
        $service->continueDns($root['user']->id, ARI8_SERVER);
        expect($service->status($root['user']->id, ARI8_SERVER)['stage'])->toBe('awaiting_client_test');
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
    expect($api->mutations)->toBe(['dns:fr.coreflare.ir']);
});
it('denies non-root and altered actor access to coordination records', function (): void {
    $root = ari8Ready();
    ari8Provider();
    $other = User::factory()->create();
    expect(fn () => app(CoordinateAdminArianetIpv4::class)->status($other->id, ARI8_SERVER))
        ->toThrow(AuthorizationException::class);

    $service = app(CoordinateAdminArianetIpv4::class);
    $service->prepare($root['user']->id, ARI8_SERVER);
    $anotherRoot = User::factory()->create();
    $anotherRoot->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $anotherRoot->id, 'telegram_user_id' => 998802]);
    config()->set('super_admin.telegram_ids', ['998801', '998802']);
    expect(fn () => $service->executeNext($anotherRoot->id, ARI8_SERVER, 0))->toThrow(RuntimeException::class);
});

function ari8Bot(bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make(998801, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? 998801 : -998877,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari8Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('shows a single DNS continuation action in private Rich RTL status', function (): void {
    $root = ari8Ready();
    ari8Provider();
    $service = app(CoordinateAdminArianetIpv4::class);
    $service->prepare($root['user']->id, ARI8_SERVER);

    ari8Bot()->hearCallbackQueryData('ari7:status:'.ARI8_SERVER.':1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = ari8Payload($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $buttons = json_encode($data['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return ($rich['is_rtl'] ?? false) === true
                && str_contains((string) ($rich['html'] ?? ''), 'هماهنگی اتصال‌ها')
                && str_contains((string) ($rich['html'] ?? ''), 'fr.coreflare.ir')
                && str_contains($buttons, 'ari7:prepare:'.ARI8_SERVER.':1')
                && str_contains($buttons, 'ari6:impact:'.ARI8_SERVER.':1')
                && ! str_contains($buttons, 'ari7:step:'.ARI8_SERVER.':1:1');
        }, index: 1);

    Http::assertNotSent(static fn (Request $req): bool => $req->method() !== 'GET');
});

it('denies root-only staged operations in group chats and to non-trusted users', function (): void {
    ari8Ready(false);
    Http::fake();
    ari8Bot()->hearCallbackQueryData('ari7:prepare:'.ARI8_SERVER.':1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    ari8Bot(private: false)->hearCallbackQueryData('ari7:prepare:'.ARI8_SERVER.':1')->reply();
    Http::assertNothingSent();
});

it('keeps DNS continuation visible on Production only for an active changed IP', function (): void {
    ari8Ready();
    Http::fake();
    app()->detectEnvironment(static fn (): string => 'production');
    try {
        ari8Bot()->hearCallbackQueryData('ari7:status:'.ARI8_SERVER.':1')->reply()
            ->assertRaw(static function (NutgramRequest $request): bool {
                $buttons = json_encode(ari8Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

                return str_contains($buttons, 'ari7:prepare:'.ARI8_SERVER.':1')
                    && ! str_contains($buttons, 'ari7:step:')
                    && str_contains($buttons, 'ari6:impact:'.ARI8_SERVER.':1');
            }, index: 1);
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
    Http::assertNothingSent();
});
