<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\CompleteNodeSetup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const NW03_PROFILE = '550e8400-e29b-41d4-a716-446655440001';
const NW03_INBOUND = '550e8400-e29b-41d4-a716-446655440002';
const NW03_NODE = '550e8400-e29b-41d4-a716-446655440003';
const NW03_HOST = '550e8400-e29b-41d4-a716-446655440004';

/** @return array{actor:User,other:User,id:string} */
function nw03Ready(): array
{
    config()->set('super_admin.telegram_ids', ['981101', '981102']);
    config()->set('services.remnawave.url', 'https://rw.nw03.test');
    config()->set('services.remnawave.token', 'test-secret');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.node_ssh_install_enabled', true);
    Http::preventStrayRequests();

    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 981101]);
    $other = User::factory()->create();
    $other->syncRoles(['member']);
    $id = str_repeat('a', 32);
    Cache::put('nw02:status:'.$id, [
        'owner' => $actor->id,
        'ip' => '194.5.192.133',
        'name' => 'ParsaNode02',
        'profileUuid' => NW03_PROFILE,
        'inboundUuid' => NW03_INBOUND,
        'state' => 'running',
    ], now()->addHour());

    return compact('actor', 'other', 'id');
}

function nw03Api(bool $connected = true, bool $nodeWriteFails = false): object
{
    $connection = (object) ['connected' => $connected];
    Http::fake(static function (Request $r) use ($connection, $nodeWriteFails) {
        $method = $r->method();
        $url = $r->url();
        $base = 'https://rw.nw03.test/api/';

        if ($method === 'GET' && $url === $base.'nodes') {
            return Http::response(['response' => []], 200);
        }
        if ($method === 'GET' && $url === $base.'config-profiles') {
            return Http::response(['response' => ['configProfiles' => [
                ['uuid' => NW03_PROFILE, 'name' => 'Default-Profile'],
            ]]], 200);
        }
        if ($method === 'GET' && $url === $base.'config-profiles/'.NW03_PROFILE.'/inbounds') {
            return Http::response(['response' => ['inbounds' => [
                ['uuid' => NW03_INBOUND, 'tag' => 'Shadowsocks'],
            ]]], 200);
        }
        if ($method === 'POST' && $url === $base.'nodes') {
            return $nodeWriteFails
                ? Http::response(['message' => 'timeout'], 504)
                : Http::response(['response' => ['uuid' => NW03_NODE]], 201);
        }
        if ($method === 'GET' && $url === $base.'nodes/'.NW03_NODE) {
            return Http::response(['response' => [
                'uuid' => NW03_NODE, 'name' => 'ParsaNode02',
                'isConnected' => $connection->connected, 'isDisabled' => false,
            ]], 200);
        }
        if ($method === 'GET' && $url === $base.'hosts') {
            return Http::response(['response' => []], 200);
        }
        if ($method === 'POST' && $url === $base.'hosts') {
            return Http::response(['response' => ['uuid' => NW03_HOST]], 201);
        }
        if ($method === 'GET' && $url === $base.'hosts/'.NW03_HOST) {
            return Http::response(['response' => [
                'uuid' => NW03_HOST, 'remark' => 'ParsaNode02-Host',
                'address' => '194.5.192.133', 'port' => 1234, 'isDisabled' => true,
                'inbound' => [
                    'configProfileUuid' => NW03_PROFILE,
                    'configProfileInboundUuid' => NW03_INBOUND,
                ],
            ]], 200);
        }

        return Http::response(['error' => 'unknown'], 404);
    });

    return $connection;
}

it('NW-03 only allows confirmed NW-02 running state, authorized root and existing write gate', function (): void {
    $ready = nw03Ready();
    nw03Api();
    $action = app(CompleteNodeSetup::class);

    expect(fn () => $action->previewNode($ready['other']->id, $ready['id']))
        ->toThrow(AuthorizationException::class);
    config()->set('services.remnawave.writes_enabled', false);
    expect(fn () => $action->previewNode($ready['actor']->id, $ready['id']))->toThrow(RuntimeException::class);
    config()->set('services.remnawave.writes_enabled', true);

    $state = Cache::get('nw02:status:'.$ready['id']);
    $state['state'] = 'pending';
    Cache::put('nw02:status:'.$ready['id'], $state, now()->addHour());
    expect(fn () => $action->previewNode($ready['actor']->id, $ready['id']))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('NW-03 creates Node once through existing Node Action and verifies live Connected', function (): void {
    $ready = nw03Ready();
    nw03Api();
    $action = app(CompleteNodeSetup::class);
    expect($action->previewNode($ready['actor']->id, $ready['id'])['address'])->toBe('194.5.192.133')
        ->and($action->registerNode($ready['actor']->id, $ready['id']))->toBe(NW03_NODE)
        ->and($action->nodeStatus($ready['actor']->id, $ready['id'])['connected'])->toBeTrue()
        ->and($action->progress($ready['actor']->id, $ready['id'])['nodeStage'])->toBe('registered');

    expect(fn () => $action->registerNode($ready['actor']->id, $ready['id']))->toThrow(RuntimeException::class);
    expect(collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() === 'POST'))->toHaveCount(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://rw.nw03.test/api/nodes'
        && $r['address'] === '194.5.192.133'
        && $r['configProfile']['activeConfigProfileUuid'] === NW03_PROFILE
        && $r['configProfile']['activeInbounds'] === [NW03_INBOUND]);
});

it('NW-03 only creates a disabled Host after confirmed live Node connection', function (): void {
    $ready = nw03Ready();
    $connection = nw03Api(false);
    $action = app(CompleteNodeSetup::class);
    $action->registerNode($ready['actor']->id, $ready['id']);
    expect(fn () => $action->previewHost($ready['actor']->id, $ready['id'], 1234))
        ->toThrow(RuntimeException::class);
    $connection->connected = true;
    expect($action->previewHost($ready['actor']->id, $ready['id'], 1234)['remark'])->toBe('ParsaNode02-Host');
    expect($action->createHost($ready['actor']->id, $ready['id'], 1234))->toBe(NW03_HOST);
    expect($action->hostStatus($ready['actor']->id, $ready['id'])['verified'])->toBeTrue();
    expect(fn () => $action->createHost($ready['actor']->id, $ready['id'], 1234))->toThrow(RuntimeException::class);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://rw.nw03.test/api/hosts'
        && $r['isDisabled'] === true
        && $r['port'] === 1234
        && $r['inbound']['configProfileInboundUuid'] === NW03_INBOUND);
});

it('NW-03 never repeats uncertain Node POST and never reports an unverified Host as complete', function (): void {
    $ready = nw03Ready();
    nw03Api(true, true);
    $action = app(CompleteNodeSetup::class);
    expect(fn () => $action->registerNode($ready['actor']->id, $ready['id']))->toThrow(RequestException::class);
    expect($action->progress($ready['actor']->id, $ready['id'])['nodeStage'])->toBe('uncertain');
    expect(fn () => $action->registerNode($ready['actor']->id, $ready['id']))->toThrow(RuntimeException::class);
    expect(collect(Http::recorded())->filter(static fn (array $p): bool => $p[0]->method() === 'POST'))->toHaveCount(1);
});
