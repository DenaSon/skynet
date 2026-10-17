<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminConfigProfiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const CFG02_PROFILE = '550e8400-e29b-41d4-a716-446655440101';
const CFG02_OTHER = '550e8400-e29b-41d4-a716-446655440102';
const CFG02_NODE = '550e8400-e29b-41d4-a716-446655440103';
const CFG02_HOST = '550e8400-e29b-41d4-a716-446655440104';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['959001', '959002']);
    config()->set('services.remnawave.url', 'https://cfg02.test');
    config()->set('services.remnawave.token', 'offline-only-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function cfg02Root(int $telegramId = 959001, bool $trusted = true): User
{
    $user = User::factory()->create();
    if ($trusted) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function cfg02Bot(int $telegramId = 959001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -959001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

function cfg02Host(?string $profile = CFG02_OTHER): array
{
    $host = [
        'uuid' => CFG02_HOST, 'remark' => 'Safe', 'address' => 'edge.example.com',
        'port' => 443, 'isDisabled' => true,
    ];
    if ($profile !== null) {
        $host['inbound'] = [
            'configProfileUuid' => $profile,
            'configProfileInboundUuid' => '550e8400-e29b-41d4-a716-446655440105',
        ];
    }

    return $host;
}

/**
 * @param  list<array<string,mixed>>|null  $nodes
 * @param  list<array<string,mixed>>|null  $hosts
 */
function cfg02Fake(?array $nodes = [], ?array $hosts = [], bool $uncertain = false, string $name = 'Main'): void
{
    Http::fake(static function (Request $request) use ($nodes, $hosts, $uncertain, $name) {
        $url = $request->url();
        if ($request->method() === 'GET') {
            return match ($url) {
                'https://cfg02.test/api/config-profiles' => Http::response(['response' => [
                    'configProfiles' => [['uuid' => CFG02_PROFILE, 'name' => $name]],
                ]]),
                'https://cfg02.test/api/config-profiles/'.CFG02_PROFILE.'/inbounds' => Http::response(['response' => ['inbounds' => []]]),
                'https://cfg02.test/api/nodes' => $nodes === null ? Http::response(['error' => 'down'], 503)
                    : Http::response(['response' => $nodes]),
                'https://cfg02.test/api/hosts' => $hosts === null ? Http::response(['error' => 'down'], 503)
                    : Http::response(['response' => $hosts]),
                default => Http::response(['message' => 'unexpected'], 404),
            };
        }

        return $uncertain ? Http::response('', 504) : Http::response(['response' => [
            'uuid' => '550e8400-e29b-41d4-a716-446655440109',
        ]]);
    });
}

function cfg02MutationCount(): int
{
    return collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() !== 'GET')->count();
}

it('keeps all profile mutations default-disabled and leaves no text draft', function (): void {
    $root = cfg02Root();
    cfg02Fake();
    $profiles = app(ManageAdminConfigProfiles::class);
    $expected = $profiles->existing($root->id, CFG02_PROFILE);

    expect(fn () => $profiles->create($root->id, 'New'))->toThrow(ValidationException::class);
    expect(fn () => $profiles->rename($root->id, $expected, 'Updated'))->toThrow(ValidationException::class);
    expect(fn () => $profiles->delete($root->id, $expected))->toThrow(ValidationException::class);
    cfg02Bot()->hearCallbackQueryData('cfg2:begin')->reply()->assertCalled('editMessageText');
    expect(Cache::get('cfg02:draft:'.$root->id))->toBeNull()
        ->and(cfg02MutationCount())->toBe(0);
});

it('creates only a secret-free, inactive empty-inbound base profile with an authorized POST', function (): void {
    $root = cfg02Root();
    cfg02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $uuid = app(ManageAdminConfigProfiles::class)->create($root->id, 'New-Base');
    expect($uuid)->toBe('550e8400-e29b-41d4-a716-446655440109')
        ->and(cfg02MutationCount())->toBe(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://cfg02.test/api/config-profiles'
        && $request->data() === [
            'name' => 'New-Base',
            'config' => [
                'log' => ['loglevel' => 'warning'],
                'inbounds' => [],
                'outbounds' => [['tag' => 'DIRECT', 'protocol' => 'freedom']],
            ],
        ]);
});

it('accepts rename by UUID and name only, without mutating Xray or Inbound data', function (): void {
    $root = cfg02Root();
    cfg02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    $expected = $service->existing($root->id, CFG02_PROFILE);
    $service->rename($root->id, $expected, 'Updated');
    expect(cfg02MutationCount())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && $r->url() === 'https://cfg02.test/api/config-profiles'
        && $r->data() === ['uuid' => CFG02_PROFILE, 'name' => 'Updated']);
});

it('rejects malformed names, no-op changes and unexpected profile identifiers before writes', function (): void {
    $root = cfg02Root();
    cfg02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    foreach (['', ' new', "New\nX", str_repeat('A', 65)] as $name) {
        expect(fn () => $service->create($root->id, $name))->toThrow(ValidationException::class);
    }
    $expected = $service->existing($root->id, CFG02_PROFILE);
    expect(fn () => $service->rename($root->id, $expected, 'Main'))->toThrow(ValidationException::class);
    expect(fn () => $service->existing($root->id, 'bad-uuid'))->toThrow(ValidationException::class);
    expect(cfg02MutationCount())->toBe(0);
});

it('deletes only when the entire current Node and Host inventory proves no references', function (): void {
    $root = cfg02Root();
    cfg02Fake(hosts: [cfg02Host()]);
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    $expected = $service->existing($root->id, CFG02_PROFILE);
    $service->delete($root->id, $expected);
    expect(cfg02MutationCount())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'DELETE'
        && $r->url() === 'https://cfg02.test/api/config-profiles/'.CFG02_PROFILE);
});

it('blocks deletion if a Node is linked to the Profile', function (): void {
    $root = cfg02Root();
    cfg02Fake(nodes: [[
        'uuid' => CFG02_NODE, 'name' => 'Edge',
        'configProfile' => ['activeConfigProfileUuid' => CFG02_PROFILE, 'activeInbounds' => []],
    ]]);
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    $expected = $service->existing($root->id, CFG02_PROFILE);
    expect(fn () => $service->delete($root->id, $expected))->toThrow(ValidationException::class);
    expect(cfg02MutationCount())->toBe(0);
});

it('blocks deletion if any Host is linked or its profile binding is unknown', function (array $hosts): void {
    $root = cfg02Root();
    cfg02Fake(hosts: $hosts);
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    $expected = $service->existing($root->id, CFG02_PROFILE);
    expect(fn () => $service->delete($root->id, $expected))->toThrow(ValidationException::class);
    expect(cfg02MutationCount())->toBe(0);
})->with([
    [[cfg02Host(CFG02_PROFILE)]],
    [[cfg02Host(null)]],
]);

it('blocks deletion if the Nodes endpoint fails or a Node binding is unverified', function (?array $nodes): void {
    $root = cfg02Root();
    cfg02Fake(nodes: $nodes);
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    $expected = $service->existing($root->id, CFG02_PROFILE);
    expect(fn () => $service->delete($root->id, $expected))->toThrow(ValidationException::class);
    expect(cfg02MutationCount())->toBe(0);
})->with([
    [null],
    [[['uuid' => CFG02_NODE, 'name' => 'Legacy']]],
]);

it('blocks deletion if the Hosts API fails or the profile was renamed', function (): void {
    $root = cfg02Root();
    cfg02Fake(hosts: null);
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    $expected = $service->existing($root->id, CFG02_PROFILE);
    expect(fn () => $service->delete($root->id, $expected))->toThrow(RequestException::class);
    expect(cfg02MutationCount())->toBe(0);

});

it('blocks deletion if the profile has been renamed since its preview', function (): void {
    $root = cfg02Root();
    cfg02Fake(name: 'Renamed');
    config()->set('services.remnawave.writes_enabled', true);
    $service = app(ManageAdminConfigProfiles::class);
    expect(fn () => $service->delete($root->id, [
        'uuid' => CFG02_PROFILE, 'name' => 'Main',
    ]))->toThrow(ValidationException::class);
    expect(cfg02MutationCount())->toBe(0);
});

it('enforces root role and private chat for creation and destructive callback', function (): void {
    $root = cfg02Root();
    $ordinary = cfg02Root(959002, false);
    cfg02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    expect(fn () => app(ManageAdminConfigProfiles::class)->create($ordinary->id, 'Blocked'))
        ->toThrow(AuthorizationException::class);
    $root->syncRoles(['member']);
    expect(fn () => app(ManageAdminConfigProfiles::class)->create($root->id, 'Blocked'))
        ->toThrow(AuthorizationException::class);
    cfg02Bot(959002)->hearCallbackQueryData('cfg2:begin')->reply();
    cfg02Bot(959001, false)->hearCallbackQueryData('cfg2:prepare:'.CFG02_PROFILE.':delete')->reply()->assertNoReply();
    expect(cfg02MutationCount())->toBe(0);
});

it('consumes creation confirmation before a single request, even on a provider timeout', function (): void {
    $root = cfg02Root();
    cfg02Fake(uncertain: true);
    config()->set('services.remnawave.writes_enabled', true);
    $bot = cfg02Bot();
    $bot->hearCallbackQueryData('cfg2:begin')->reply()->assertCalled('editMessageText');
    $bot->hearText('Fresh')->reply()->assertCalled('sendMessage');
    $state = Cache::get('cfg02:draft:'.$root->id);
    expect($state['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('cfg2:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('cfg2:apply:'.$state['token'])->reply();
    expect(Cache::get('cfg02:draft:'.$root->id))->toBeNull()
        ->and(cfg02MutationCount())->toBe(1);
});

it('confirms rename and delete with separate one-use tickets', function (): void {
    $root = cfg02Root();
    cfg02Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $bot = cfg02Bot();

    $bot->hearCallbackQueryData('cfg2:prepare:'.CFG02_PROFILE.':rename')->reply();
    $bot->hearText('Main-New')->reply();
    $state = Cache::get('cfg02:draft:'.$root->id);
    $bot->hearCallbackQueryData('cfg2:apply:'.$state['token'])->reply();
    $bot->hearCallbackQueryData('cfg2:apply:'.$state['token'])->reply();
    expect(cfg02MutationCount())->toBe(1);

    $bot->hearCallbackQueryData('cfg2:prepare:'.CFG02_PROFILE.':delete')->reply();
    $state = Cache::get('cfg02:draft:'.$root->id);
    $bot->hearCallbackQueryData('cfg2:apply:'.$state['token'])->reply();
    $bot->hearCallbackQueryData('cfg2:apply:'.$state['token'])->reply();
    expect(cfg02MutationCount())->toBe(2);
});
