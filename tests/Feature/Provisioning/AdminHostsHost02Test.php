<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminHosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const HOST02_A = '550e8400-e29b-41d4-a716-446655440091';
const HOST02_B = '550e8400-e29b-41d4-a716-446655440092';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['958001', '958002']);
    config()->set('services.remnawave.url', 'https://host02.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function host02User(int $id = 958001, bool $trusted = true): User
{
    $user = User::factory()->create();
    if ($trusted) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);

    return $user;
}

function host02Bot(int $id = 958001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Operator'));
    $bot->setCommonChat(Chat::make($private ? $id : -958001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array{uuid:string,remark:string,address:string,port:int,isDisabled:bool,inbound:array{configProfileUuid:string,configProfileInboundUuid:string}} */
function host02Raw(string $uuid = HOST02_A, bool $disabled = true): array
{
    return [
        'uuid' => $uuid, 'remark' => $uuid === HOST02_A ? 'Alpha' : 'Beta',
        'address' => 'edge.example.com', 'port' => 443, 'isDisabled' => $disabled,
        'inbound' => [
            'configProfileUuid' => '550e8400-e29b-41d4-a716-446655440093',
            'configProfileInboundUuid' => '550e8400-e29b-41d4-a716-446655440094',
        ],
    ];
}

/** @param list<array<string,mixed>>|null $list */
function host02Fake(?array $list = null, bool $uncertain = false): void
{
    Http::fake(static function (Request $r) use ($list, $uncertain) {
        $url = $r->url();
        if ($r->method() === 'GET' && $url === 'https://host02.test/api/hosts') {
            return Http::response(['response' => $list ?? [host02Raw(), host02Raw(HOST02_B)]]);
        }
        if ($r->method() === 'GET' && str_starts_with($url, 'https://host02.test/api/hosts/')) {
            $uuid = substr($url, strlen('https://host02.test/api/hosts/'));
            $found = collect($list ?? [host02Raw(), host02Raw(HOST02_B)])->firstWhere('uuid', $uuid);

            return $found === null ? Http::response(['message' => 'not found'], 404)
                : Http::response(['response' => $found]);
        }
        if (in_array($r->method(), ['PATCH', 'POST', 'DELETE'], true)
            && str_starts_with($url, 'https://host02.test/api/hosts')) {
            return $uncertain ? Http::response('', 504) : Http::response(['response' => ['success' => true]]);
        }

        return Http::response(['message' => 'unexpected'], 404);
    });
}

function host02Mutations(): int
{
    return collect(Http::recorded())->filter(static fn (array $a): bool => $a[0]->method() !== 'GET')->count();
}

it('blocks all mutation operations and preview when provider writes are off', function (): void {
    $root = host02User();
    host02Fake();
    $hosts = app(ManageAdminHosts::class);
    $expected = $hosts->one($root->id, HOST02_A);
    expect(fn () => $hosts->change($root->id, HOST02_A, 'enable', $expected))->toThrow(ValidationException::class);
    expect(fn () => $hosts->batch($root->id, 'enable', [$expected]))->toThrow(ValidationException::class);
    Http::assertSentCount(1);

    host02Bot()->hearCallbackQueryData('host2:prepare:'.HOST02_A.':enable')->reply()->assertCalled('editMessageText');
    host02Bot()->hearCallbackQueryData('host2:batch:begin')->reply()->assertCalled('editMessageText');
    Http::assertSentCount(1);
});

it('allows only safe editing of a known Host and sends exact PATCH fields', function (): void {
    $root = host02User();
    host02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $hosts = app(ManageAdminHosts::class);
    $expected = $hosts->one($root->id, HOST02_A);
    $hosts->change($root->id, HOST02_A, 'edit', $expected, [
        'remark' => 'TLS-New', 'address' => 'new.example.com', 'port' => 8443,
    ]);
    expect(host02Mutations())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && $r->url() === 'https://host02.test/api/hosts'
        && $r->data() === [
            'uuid' => HOST02_A, 'remark' => 'TLS-New', 'address' => 'new.example.com', 'port' => 8443,
        ]);
});

it('rejects invalid update fields and no-op changes before sending PATCH', function (): void {
    $root = host02User();
    host02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $hosts = app(ManageAdminHosts::class);
    $original = $hosts->one($root->id, HOST02_A);
    foreach ([
        ['remark' => 'Hacked', 'isDisabled' => false],
        ['remark' => 'Test', 'address' => 'https://invalid', 'port' => 443],
        ['remark' => 'Test', 'address' => 'example.com', 'port' => 0],
    ] as $fields) {
        expect(fn () => $hosts->change($root->id, HOST02_A, 'edit', $original, $fields))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => $hosts->change($root->id, HOST02_A, 'edit', $original, [
        'remark' => $original['remark'], 'address' => $original['address'], 'port' => $original['port'],
    ]))->toThrow(ValidationException::class);
    expect(host02Mutations())->toBe(0);
});

it('enables disabled hosts and disables active hosts only after matching their reviewed state', function (): void {
    $root = host02User();
    host02Fake([host02Raw(HOST02_A, true), host02Raw(HOST02_B, false)]);
    config()->set('services.remnawave.writes_enabled', true);
    $hosts = app(ManageAdminHosts::class);
    $off = $hosts->one($root->id, HOST02_A);
    $on = $hosts->one($root->id, HOST02_B);
    $hosts->change($root->id, HOST02_A, 'enable', $off);
    $hosts->change($root->id, HOST02_B, 'disable', $on);
    expect(host02Mutations())->toBe(2);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://host02.test/api/hosts/bulk/enable'
        && $r['uuids'] === [HOST02_A]);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://host02.test/api/hosts/bulk/disable'
        && $r['uuids'] === [HOST02_B]);
});

it('permits single deletion only if the host is already disabled and no longer changed', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake([host02Raw(HOST02_A, true), host02Raw(HOST02_B, false)]);
    $hosts = app(ManageAdminHosts::class);
    $disabled = $hosts->one($root->id, HOST02_A);
    $enabled = $hosts->one($root->id, HOST02_B);
    $hosts->change($root->id, HOST02_A, 'delete', $disabled);
    expect(fn () => $hosts->change($root->id, HOST02_B, 'delete', $enabled))->toThrow(ValidationException::class);
    expect(host02Mutations())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'DELETE'
        && $r->url() === 'https://host02.test/api/hosts/'.HOST02_A);
});

it('stops stale single mutation before it reaches the provider', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake([host02Raw(HOST02_A, true)]);
    $hosts = app(ManageAdminHosts::class);
    $old = $hosts->one($root->id, HOST02_A);
    $old['port'] = 8443;
    expect(fn () => $hosts->change($root->id, HOST02_A, 'enable', $old))->toThrow(ValidationException::class);
    expect(host02Mutations())->toBe(0);
});

it('issues one exact batch POST for selected hosts and rejects unknown or no-op targets', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake();
    $hosts = app(ManageAdminHosts::class);
    $all = $hosts->all($root->id);
    $hosts->batch($root->id, 'enable', $all);
    expect(host02Mutations())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://host02.test/api/hosts/bulk/enable'
        && $r['uuids'] === [HOST02_A, HOST02_B]);
    expect(fn () => $hosts->batch($root->id, 'delete', $all))->toThrow(ValidationException::class);
    expect(fn () => $hosts->batch($root->id, 'enable', [$all[0], $all[0]]))->toThrow(ValidationException::class);
    $stale = $all;
    $stale[1]['remark'] = 'Changed';
    expect(fn () => $hosts->batch($root->id, 'enable', $stale))->toThrow(ValidationException::class);
    expect(host02Mutations())->toBe(1);
});

it('never updates another root’s host on revoked role or group requests', function (): void {
    $root = host02User();
    $other = host02User(958002, false);
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake();
    $hosts = app(ManageAdminHosts::class);
    $expected = $hosts->one($root->id, HOST02_A);

    expect(fn () => $hosts->change($other->id, HOST02_A, 'enable', $expected))->toThrow(AuthorizationException::class);
    $root->syncRoles(['member']);
    expect(fn () => $hosts->batch($root->id, 'enable', [$expected]))->toThrow(AuthorizationException::class);
    host02Bot(958002)->hearCallbackQueryData('host2:prepare:'.HOST02_A.':enable')->reply();
    host02Bot(958001, false)->hearCallbackQueryData('host2:batch:begin')->reply()->assertNoReply();
    expect(host02Mutations())->toBe(0);
});

it('runs the edit wizard and consumes its confirmation before exactly one PATCH', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake();
    $bot = host02Bot();

    $bot->hearCallbackQueryData('host2:prepare:'.HOST02_A.':edit')->reply()->assertCalled('editMessageText');
    $bot->hearText('Alpha-New | new.example.com | 8443')->reply()->assertCalled('sendMessage');
    $state = Cache::get('host02:draft:'.$root->id);
    expect($state['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('host2:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('host2:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(Cache::get('host02:draft:'.$root->id))->toBeNull()
        ->and(host02Mutations())->toBe(1);
});

it('never retries an uncertain mutation when confirmation is replayed', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake(uncertain: true);
    $bot = host02Bot();
    $bot->hearCallbackQueryData('host2:prepare:'.HOST02_A.':enable')->reply();
    $token = Cache::get('host02:draft:'.$root->id)['token'];
    $bot->hearCallbackQueryData('host2:apply:'.$token)->reply();
    $bot->hearCallbackQueryData('host2:apply:'.$token)->reply();
    expect(Cache::get('host02:draft:'.$root->id))->toBeNull()
        ->and(host02Mutations())->toBe(1);
});

it('can select hosts over different pages and sends only one group request', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    $raw = [];
    for ($i = 1; $i <= 12; $i++) {
        $host = host02Raw();
        $host['uuid'] = sprintf('550e8400-e29b-41d4-a716-%012d', $i);
        $host['remark'] = 'Host-'.$i;
        $raw[] = $host;
    }
    host02Fake($raw);
    $bot = host02Bot();
    $bot->hearCallbackQueryData('host2:batch:begin')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('host2:batch:toggle:0')->reply();
    $bot->hearCallbackQueryData('host2:batch:page:2')->reply();
    $bot->hearCallbackQueryData('host2:batch:toggle:10')->reply();
    $bot->hearCallbackQueryData('host2:batch:choose:enable')->reply()->assertCalled('editMessageText');
    $state = Cache::get('host02:batch:'.$root->id);
    expect($state['stage'])->toBe('preview')->and($state['chosen'])->toHaveCount(2);
    $bot->hearCallbackQueryData('host2:batch:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('host2:batch:apply:'.$state['token'])->reply();
    expect(host02Mutations())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://host02.test/api/hosts/bulk/enable'
        && $r['uuids'] === [
            '550e8400-e29b-41d4-a716-000000000001',
            '550e8400-e29b-41d4-a716-000000000011',
        ]);
});

it('never sends a bulk operation after current host status changes', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake([host02Raw(HOST02_A, false)]);
    $hosts = app(ManageAdminHosts::class);
    $expected = $hosts->all($root->id);
    $expected[0]['disabled'] = true;
    expect(fn () => $hosts->batch($root->id, 'enable', $expected))->toThrow(ValidationException::class);
    expect(host02Mutations())->toBe(0);
});

it('does not allow text drafts to survive a new host wizard', function (): void {
    $root = host02User();
    config()->set('services.remnawave.writes_enabled', true);
    host02Fake();
    host02Bot()->hearCallbackQueryData('host2:prepare:'.HOST02_A.':edit')->reply();
    expect(Cache::get('host02:draft:'.$root->id))->toBeArray();
    host02Bot()->hearCallbackQueryData('host1:begin')->reply();
    expect(Cache::get('host02:draft:'.$root->id))->toBeNull();
});
