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

const CF03_ZONE_ID = 'abcdef0123456789abcdef0123456789';
const CF03_RECORD_ID = 'ab010101010101010101010101010101';
const CF03_NODE_UUID = '550e8400-e29b-41d4-a716-446655440031';
const CF03_PROFILE_UUID = '550e8400-e29b-41d4-a716-446655440032';
const CF03_INBOUND_UUID = '550e8400-e29b-41d4-a716-446655440033';
const CF03_INSTALL_ID = 'c303c303c303c303c303c303c303c303';

function cf03SelectedA(string $ip = '185.194.188.191', bool $proxied = true): array
{
    return [
        'id' => CF03_RECORD_ID,
        'name' => 'uae.denagate.ir',
        'type' => 'A',
        'content' => $ip,
        'proxied' => $proxied,
        'proxiable' => true,
        'editable' => true,
        'modified' => '2026-11-17T08:00:00Z',
    ];
}

/** @return array{actor:User,state:object,expected:array<string,mixed>} */
function cf03NodeSetup(bool $failPatch = false, ?string $recordIp = null, int $telegramId = 980301): array
{
    config()->set('super_admin.telegram_ids', ['980301', '980302']);
    config()->set('services.remnawave.url', 'https://rw.cf03.test');
    config()->set('services.remnawave.token', 'rw-test-token');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.node_ssh_install_enabled', true);
    config()->set('services.cloudflare.token', 'cf-test-token');
    config()->set('services.cloudflare.allowed_zones', 'denagate.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    Http::preventStrayRequests();

    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => $telegramId]);

    $expected = cf03SelectedA();
    $record = [
        'id' => CF03_RECORD_ID,
        'name' => 'uae.denagate.ir',
        'type' => 'A',
        'content' => $recordIp ?? '185.194.188.191',
        'proxied' => true,
        'proxiable' => true,
        'modified_on' => '2026-11-17T08:00:00Z',
    ];
    $state = (object) ['records' => [$record], 'patches' => 0, 'failPatch' => $failPatch];
    Cache::put('nw02:status:'.CF03_INSTALL_ID, [
        'owner' => $actor->id,
        'state' => 'running',
        'ip' => '194.5.192.133',
        'name' => 'UAE-02',
        'profileUuid' => CF03_PROFILE_UUID,
        'inboundUuid' => CF03_INBOUND_UUID,
        'nodeStage' => 'registered',
        'nodeUuid' => CF03_NODE_UUID,
        'dnsName' => 'uae.denagate.ir',
        'dnsZone' => 'denagate.ir',
        'dnsZoneId' => CF03_ZONE_ID,
        'dnsRecordExpected' => $expected,
    ], now()->addHours(3));

    Http::fake(static function (Request $request) use ($state) {
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);
        $method = $request->method();
        $rw = 'https://rw.cf03.test/api/';
        $cfPath = '/client/v4';

        if ($method === 'GET' && $url === $rw.'nodes/'.CF03_NODE_UUID) {
            return Http::response(['response' => [
                'uuid' => CF03_NODE_UUID,
                'name' => 'UAE-02',
                'isConnected' => true,
                'isDisabled' => false,
            ]]);
        }
        if ($method === 'GET' && $url === $rw.'config-profiles') {
            return Http::response(['response' => ['configProfiles' => [
                ['uuid' => CF03_PROFILE_UUID, 'name' => 'Main'],
            ]]]);
        }
        if ($method === 'GET' && $url === $rw.'config-profiles/'.CF03_PROFILE_UUID.'/inbounds') {
            return Http::response(['response' => ['inbounds' => [
                ['uuid' => CF03_INBOUND_UUID, 'tag' => 'VLESS'],
            ]]]);
        }
        if ($method === 'GET' && $url === $rw.'hosts') {
            return Http::response(['response' => []]);
        }
        if ($method === 'GET' && $path === $cfPath.'/zones') {
            $name = $request->data()['name'] ?? null;

            return Http::response(['success' => true, 'result' => $name === 'denagate.ir'
                ? [['id' => CF03_ZONE_ID, 'name' => 'denagate.ir']]
                : []]);
        }
        $recordsPath = $cfPath.'/zones/'.CF03_ZONE_ID.'/dns_records';
        if ($method === 'GET' && $path === $recordsPath.'/'.CF03_RECORD_ID) {
            return Http::response(['success' => true, 'result' => $state->records[0]]);
        }
        if ($method === 'GET' && $path === $recordsPath) {
            $name = $request->data()['name'] ?? null;
            $rows = array_values(array_filter(
                $state->records,
                static fn (array $row): bool => $row['name'] === $name,
            ));

            return Http::response(['success' => true, 'result' => $rows]);
        }
        if ($method === 'PATCH' && $path === $recordsPath.'/'.CF03_RECORD_ID) {
            $state->patches++;
            if ($state->failPatch) {
                return Http::response(['success' => false], 504);
            }
            $state->records[0]['content'] = $request->data()['content'];
            $state->records[0]['proxied'] = $request->data()['proxied'];
            $state->records[0]['modified_on'] = '2026-11-17T09:00:00Z';

            return Http::response(['success' => true, 'result' => $state->records[0]]);
        }

        return Http::response(['error' => 'unexpected request'], 404);
    });

    return compact('actor', 'state', 'expected');
}

it('CF-03 previews and updates exactly one selected A record while preserving Proxy and the Host FQDN', function (): void {
    $fixture = cf03NodeSetup();
    $action = app(CompleteNodeSetup::class);

    $preview = $action->previewDns($fixture['actor']->id, CF03_INSTALL_ID);
    expect($preview)->toMatchArray([
        'name' => 'uae.denagate.ir',
        'ip' => '194.5.192.133',
        'state' => 'existing',
        'mode' => 'update',
        'previousIp' => '185.194.188.191',
        'proxied' => true,
    ]);

    $action->ensureDns($fixture['actor']->id, CF03_INSTALL_ID, $preview['record']);
    expect(Cache::get('nw02:status:'.CF03_INSTALL_ID)['dnsStage'])->toBe('verified')
        ->and($fixture['state']->records[0]['content'])->toBe('194.5.192.133')
        ->and($fixture['state']->records[0]['proxied'])->toBeTrue()
        ->and($fixture['state']->patches)->toBe(1);

    expect($action->previewHost($fixture['actor']->id, CF03_INSTALL_ID, 8443)['address'])
        ->toBe('uae.denagate.ir');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://api.cloudflare.com/client/v4/zones/'.CF03_ZONE_ID
            .'/dns_records/'.CF03_RECORD_ID
        && $request->data()['content'] === '194.5.192.133'
        && $request->data()['proxied'] === true);
});

it('CF-03 refreshes a selected record after installation and rejects hostnames with multiple DNS records', function (): void {
    $changedDuringInstall = cf03NodeSetup(recordIp: '185.194.188.192');
    $action = app(CompleteNodeSetup::class);
    $preview = $action->previewDns($changedDuringInstall['actor']->id, CF03_INSTALL_ID);
    expect($preview['previousIp'])->toBe('185.194.188.192');
    $action->ensureDns($changedDuringInstall['actor']->id, CF03_INSTALL_ID, $preview['record']);
    expect($changedDuringInstall['state']->records[0]['content'])->toBe('194.5.192.133')
        ->and($changedDuringInstall['state']->patches)->toBe(1);

    $duplicate = cf03NodeSetup(telegramId: 980302);
    $duplicate['state']->records[] = [
        'id' => 'ab020202020202020202020202020202',
        'name' => 'uae.denagate.ir',
        'type' => 'A',
        'content' => '185.194.188.192',
        'proxied' => true,
        'proxiable' => true,
        'modified_on' => '2026-11-17T08:00:00Z',
    ];
    expect(fn () => app(CompleteNodeSetup::class)->previewDns($duplicate['actor']->id, CF03_INSTALL_ID))
        ->toThrow(RuntimeException::class);
    expect($duplicate['state']->patches)->toBe(0);
});

it('CF-03 marks an existing-record PATCH uncertain before writing and only reconciles with GET', function (): void {
    $fixture = cf03NodeSetup(failPatch: true);
    $action = app(CompleteNodeSetup::class);
    $preview = $action->previewDns($fixture['actor']->id, CF03_INSTALL_ID);

    expect(fn () => $action->ensureDns($fixture['actor']->id, CF03_INSTALL_ID, $preview['record']))
        ->toThrow(RuntimeException::class);
    expect(Cache::get('nw02:status:'.CF03_INSTALL_ID)['dnsStage'])->toBe('uncertain')
        ->and($fixture['state']->patches)->toBe(1);

    expect(fn () => $action->ensureDns($fixture['actor']->id, CF03_INSTALL_ID, $preview['record']))
        ->toThrow(RuntimeException::class);
    expect($fixture['state']->patches)->toBe(1);

    expect(fn () => $action->reconcileDns($fixture['actor']->id, CF03_INSTALL_ID))
        ->toThrow(RuntimeException::class);
    $fixture['state']->records[0]['content'] = '194.5.192.133';
    $fixture['state']->records[0]['modified_on'] = '2026-11-17T09:00:00Z';
    $action->reconcileDns($fixture['actor']->id, CF03_INSTALL_ID);

    expect(Cache::get('nw02:status:'.CF03_INSTALL_ID)['dnsStage'])->toBe('verified')
        ->and($fixture['state']->patches)->toBe(1);
});

it('CF-03 verifies an already matching selected A record without an unnecessary PATCH', function (): void {
    $fixture = cf03NodeSetup(recordIp: '194.5.192.133');
    $preview = app(CompleteNodeSetup::class)->previewDns($fixture['actor']->id, CF03_INSTALL_ID);

    app(CompleteNodeSetup::class)->ensureDns($fixture['actor']->id, CF03_INSTALL_ID, $preview['record']);
    expect(Cache::get('nw02:status:'.CF03_INSTALL_ID)['dnsStage'])->toBe('verified')
        ->and($fixture['state']->patches)->toBe(0);
});
