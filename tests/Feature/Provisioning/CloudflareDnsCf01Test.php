<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageCloudflareDns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CF01_ZONE_ID = 'abcdef0123456789abcdef0123456789';

function cf01Operator(): array
{
    config()->set('super_admin.telegram_ids', ['970001', '970002']);
    config()->set('services.cloudflare.token', 'cf01-test-token');
    config()->set('services.cloudflare.allowed_zones', 'denagate.ir,atel.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    Http::preventStrayRequests();

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 970001]);
    $member = User::factory()->create();
    $member->syncRoles(['member']);

    return compact('root', 'member');
}

/** @param array<string, mixed>|null $existing */
function cf01FakeDns(?array $existing = null, bool $failPost = false): object
{
    $state = (object) ['records' => $existing === null ? [] : [$existing], 'posts' => 0];
    Http::fake(static function (Request $r) use ($state, $failPost) {
        $url = $r->url();
        $base = 'https://api.cloudflare.com/client/v4/zones';
        if ($r->method() === 'GET' && str_starts_with($url, $base.'?')) {
            $name = $r->data()['name'] ?? '';
            return Http::response(['success' => true, 'result' => $name === 'denagate.ir'
                ? [['id' => CF01_ZONE_ID, 'name' => 'denagate.ir', 'status' => 'active']]
                : []]);
        }
        if (str_starts_with($url, $base.'/'.CF01_ZONE_ID.'/dns_records')) {
            if ($r->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $state->records]);
            }
            if ($r->method() === 'POST') {
                $state->posts++;
                if ($failPost) {
                    return Http::response(['success' => false, 'errors' => [['message' => 'gateway timeout']]], 504);
                }
                $data = $r->data();
                $state->records = [[
                    'id' => CF01_ZONE_ID,
                    'name' => $data['name'], 'type' => $data['type'],
                    'content' => $data['content'], 'proxied' => $data['proxied'],
                ]];
                return Http::response(['success' => true, 'result' => $state->records[0]], 201);
            }
        }

        return Http::response(['success' => false], 404);
    });

    return $state;
}

it('CF-01 lists only allowlisted Cloudflare zones and validates exact subdomains', function (): void {
    $actors = cf01Operator();
    cf01FakeDns();
    $dns = app(ManageCloudflareDns::class);

    expect($dns->wizardEnabled())->toBeTrue()
        ->and($dns->zones($actors['root']->id))->toBe([['id' => CF01_ZONE_ID, 'name' => 'denagate.ir']])
        ->and($dns->hostname($actors['root']->id, 'denagate.ir', 'USA'))->toBe('usa.denagate.ir')
        ->and($dns->hostname($actors['root']->id, 'denagate.ir', 'eu.usa.denagate.ir'))->toBe('eu.usa.denagate.ir');

    foreach (['denagate.ir', 'bad.evil.ir', '*.denagate.ir', 'https://usa.denagate.ir'] as $name) {
        expect(fn () => $dns->hostname($actors['root']->id, 'denagate.ir', $name))->toThrow(RuntimeException::class);
    }
    expect(fn () => $dns->hostname($actors['root']->id, 'evil.ir', 'usa'))->toThrow(RuntimeException::class);
    expect(fn () => $dns->zones($actors['member']->id))->toThrow(AuthorizationException::class);
});

it('CF-01 creates one DNS-only A record and a matching repeat is a GET-only no-op', function (): void {
    $actors = cf01Operator();
    $state = cf01FakeDns();
    $dns = app(ManageCloudflareDns::class);
    $before = 0;
    $callback = static function () use (&$before): void {
        $before++;
    };

    expect($dns->ensureA($actors['root']->id, CF01_ZONE_ID, 'denagate.ir',
        'usa.denagate.ir', '194.5.192.133', $callback))->toBe('created');
    expect($dns->ensureA($actors['root']->id, CF01_ZONE_ID, 'denagate.ir',
        'usa.denagate.ir', '194.5.192.133', $callback))->toBe('existing');
    expect($state->posts)->toBe(1)->and($before)->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && ($r->data()['type'] ?? null) === 'A'
        && ($r->data()['ttl'] ?? null) === 1
        && ($r->data()['proxied'] ?? null) === false);
});

it('CF-01 rejects mismatched or proxied records without changing DNS', function (): void {
    $actors = cf01Operator();
    $record = ['id' => CF01_ZONE_ID, 'type' => 'A', 'name' => 'usa.denagate.ir',
        'content' => '194.5.192.134', 'proxied' => false];
    $state = cf01FakeDns($record);
    $dns = app(ManageCloudflareDns::class);

    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID,
        'denagate.ir', 'usa.denagate.ir', '194.5.192.133', static fn () => null))
        ->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(0);

    $state->records[0]['content'] = '194.5.192.133';
    $state->records[0]['proxied'] = true;
    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID,
        'denagate.ir', 'usa.denagate.ir', '194.5.192.133', static fn () => null))
        ->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(0);
});

it('CF-01 keeps DNS writes independently disabled and never leaks the token into outputs', function (): void {
    $actors = cf01Operator();
    $state = cf01FakeDns();
    $dns = app(ManageCloudflareDns::class);
    config()->set('services.cloudflare.dns_writes_enabled', false);

    expect($dns->wizardEnabled())->toBeFalse();
    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID, 'denagate.ir',
        'usa.denagate.ir', '194.5.192.133', static fn () => null))->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(0);
});

it('CF-01 surfaces ambiguous external failures without a write retry', function (): void {
    $actors = cf01Operator();
    $state = cf01FakeDns(null, failPost: true);
    $dns = app(ManageCloudflareDns::class);
    $marked = 0;

    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID,
        'denagate.ir', 'usa.denagate.ir', '194.5.192.133',
        static function () use (&$marked): void {
            $marked++;
        }))->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(1)->and($marked)->toBe(1);
});

/** @return array{root:User,id:string,state:object} */
function cf01InstalledNode(bool $withDns = true, bool $failDns = false): array
{
    $actors = cf01Operator();
    config()->set('services.remnawave.url', 'https://rw.cf01.test');
    config()->set('services.remnawave.token', 'rw-fixture-token');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.node_ssh_install_enabled', true);
    $id = str_repeat('e', 32);
    $nodeId = '550e8400-e29b-41d4-a716-446655440023';
    $profile = '550e8400-e29b-41d4-a716-446655440021';
    $inbound = '550e8400-e29b-41d4-a716-446655440022';
    $hostId = '550e8400-e29b-41d4-a716-446655440024';
    $name = 'CF01-Test-Node';
    $data = [
        'owner' => $actors['root']->id, 'state' => 'running',
        'ip' => '194.5.192.133', 'name' => $name,
        'profileUuid' => $profile, 'inboundUuid' => $inbound,
        'nodeStage' => 'registered', 'nodeUuid' => $nodeId,
    ];
    if ($withDns) {
        $data += [
            'dnsName' => 'usa.denagate.ir', 'dnsZone' => 'denagate.ir', 'dnsZoneId' => CF01_ZONE_ID,
        ];
    }
    \Illuminate\Support\Facades\Cache::put('nw02:status:'.$id, $data, now()->addHour());
    $state = (object) ['records' => [], 'posts' => 0, 'hostPosts' => 0, 'failDns' => $failDns];
    Http::fake(static function (Request $r) use ($nodeId, $profile, $inbound, $hostId, $name, $state) {
        $url = $r->url();
        $method = $r->method();
        $rw = 'https://rw.cf01.test/api/';
        $cf = 'https://api.cloudflare.com/client/v4/zones';
        if ($method === 'GET' && str_starts_with($url, $cf.'?')) {
            return Http::response(['success' => true, 'result' => [[
                'id' => CF01_ZONE_ID, 'name' => 'denagate.ir',
            ]]]);
        }
        if (str_starts_with($url, $cf.'/'.CF01_ZONE_ID.'/dns_records')) {
            if ($method === 'GET') {
                return Http::response(['success' => true, 'result' => $state->records]);
            }
            if ($method === 'POST') {
                $state->posts++;
                if ($state->failDns) {
                    return Http::response(['success' => false], 504);
                }
                $data = $r->data();
                $state->records = [[
                    'id' => CF01_ZONE_ID, 'type' => $data['type'],
                    'name' => $data['name'], 'content' => $data['content'],
                    'proxied' => $data['proxied'],
                ]];
                return Http::response(['success' => true, 'result' => $state->records[0]], 201);
            }
        }
        if ($method === 'GET' && $url === $rw.'nodes/'.$nodeId) {
            return Http::response(['response' => [
                'uuid' => $nodeId, 'name' => $name,
                'isConnected' => true, 'isDisabled' => false,
            ]]);
        }
        if ($method === 'GET' && $url === $rw.'config-profiles') {
            return Http::response(['response' => ['configProfiles' => [
                ['uuid' => $profile, 'name' => 'Default-Profile'],
            ]]]);
        }
        if ($method === 'GET' && $url === $rw.'config-profiles/'.$profile.'/inbounds') {
            return Http::response(['response' => ['inbounds' => [
                ['uuid' => $inbound, 'tag' => 'Shadowsocks'],
            ]]]);
        }
        if ($method === 'GET' && $url === $rw.'hosts') {
            return Http::response(['response' => []]);
        }
        if ($method === 'POST' && $url === $rw.'hosts') {
            $state->hostPosts++;
            return Http::response(['response' => ['uuid' => $hostId]], 201);
        }
        if ($method === 'GET' && $url === $rw.'hosts/'.$hostId) {
            return Http::response(['response' => [
                'uuid' => $hostId, 'remark' => $name.'-Host',
                'address' => 'usa.denagate.ir', 'port' => 1234,
                'isDisabled' => true, 'inbound' => [
                    'configProfileUuid' => $profile, 'configProfileInboundUuid' => $inbound,
                ],
            ]]);
        }

        return Http::response(['error' => 'unexpected test request'], 404);
    });

    return ['root' => $actors['root'], 'id' => $id, 'state' => $state];
}

it('CF-01 waits for confirmed DNS before creating the existing disabled Host with FQDN', function (): void {
    $fixture = cf01InstalledNode();
    $action = app(\App\Modules\Provisioning\Application\CompleteNodeSetup::class);
    $id = $fixture['id'];
    $root = $fixture['root']->id;

    expect($action->previewDns($root, $id)['state'])->toBe('missing');
    expect(fn () => $action->previewHost($root, $id, 1234))->toThrow(RuntimeException::class);
    $action->ensureDns($root, $id);
    expect($action->progress($root, $id)['dnsStage'])->toBe('verified')
        ->and($action->previewHost($root, $id, 1234)['address'])->toBe('usa.denagate.ir');
    $action->createHost($root, $id, 1234);
    expect($action->hostStatus($root, $id)['verified'])->toBeTrue()
        ->and($fixture['state']->posts)->toBe(1)
        ->and($fixture['state']->hostPosts)->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://rw.cf01.test/api/hosts'
        && ($r->data()['address'] ?? null) === 'usa.denagate.ir'
        && ($r->data()['isDisabled'] ?? null) === true);
});

it('CF-01 never retries an uncertain DNS POST and allows read-only reconciliation', function (): void {
    $fixture = cf01InstalledNode(failDns: true);
    $action = app(\App\Modules\Provisioning\Application\CompleteNodeSetup::class);
    $id = $fixture['id'];
    $root = $fixture['root']->id;
    $state = $fixture['state'];

    expect(fn () => $action->ensureDns($root, $id))->toThrow(RuntimeException::class);
    expect($action->progress($root, $id)['dnsStage'])->toBe('uncertain');
    expect(fn () => $action->ensureDns($root, $id))->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(1);
    expect(fn () => $action->reconcileDns($root, $id))->toThrow(RuntimeException::class);
    $state->records = [[
        'id' => CF01_ZONE_ID, 'name' => 'usa.denagate.ir', 'type' => 'A',
        'content' => '194.5.192.133', 'proxied' => false,
    ]];
    $action->reconcileDns($root, $id);
    expect($action->progress($root, $id)['dnsStage'])->toBe('verified')
        ->and($state->posts)->toBe(1);
});

it('CF-01 leaves the original IP-only Host wizard path unchanged', function (): void {
    $fixture = cf01InstalledNode(withDns: false);
    $action = app(\App\Modules\Provisioning\Application\CompleteNodeSetup::class);
    expect($action->progress($fixture['root']->id, $fixture['id'])['dnsStage'])->toBe('skipped')
        ->and($action->previewHost($fixture['root']->id, $fixture['id'], 1234)['address'])->toBe('194.5.192.133');
    expect($fixture['state']->posts)->toBe(0);
});
