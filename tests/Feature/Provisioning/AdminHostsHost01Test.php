<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminHosts;
use GuzzleHttp\Psr7\Request as NutgramRequest;
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
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

const HOST01_HOST = '550e8400-e29b-41d4-a716-446655440081';
const HOST01_PROFILE = '550e8400-e29b-41d4-a716-446655440082';
const HOST01_INBOUND = '550e8400-e29b-41d4-a716-446655440083';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['957001', '957002']);
    config()->set('services.remnawave.url', 'https://host01.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function host01User(int $telegramId = 957001, bool $root = true): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function host01Bot(int $telegramId = 957001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -957001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @param array<string,mixed>|null $override */
function host01Fake(bool $postFails = false, bool $profilesStale = false, bool $inboundsStale = false, ?array $override = null): void
{
    Http::fake(static function (Request $r) use ($postFails, $profilesStale, $inboundsStale, $override) {
        $url = $r->url();
        if ($url === 'https://host01.test/api/hosts' && $r->method() === 'GET') {
            return Http::response(['response' => [$override ?? [
                'uuid' => HOST01_HOST, 'remark' => 'TLS-Host', 'address' => 'edge.example.com',
                'port' => 443, 'isDisabled' => true,
                'inbound' => ['configProfileUuid' => HOST01_PROFILE, 'configProfileInboundUuid' => HOST01_INBOUND],
                'privateExtra' => 'DO_NOT_RENDER',
            ]]]);
        }
        if ($url === 'https://host01.test/api/hosts/'.HOST01_HOST && $r->method() === 'GET') {
            return Http::response(['response' => $override ?? [
                'uuid' => HOST01_HOST, 'remark' => 'TLS-Host', 'address' => 'edge.example.com',
                'port' => 443, 'isDisabled' => true,
                'inbound' => ['configProfileUuid' => HOST01_PROFILE, 'configProfileInboundUuid' => HOST01_INBOUND],
                'privateExtra' => 'DO_NOT_RENDER',
            ]]);
        }
        if ($url === 'https://host01.test/api/config-profiles' && $r->method() === 'GET') {
            return Http::response(['response' => [
                'total' => $profilesStale ? 0 : 1,
                'configProfiles' => $profilesStale ? [] : [['uuid' => HOST01_PROFILE, 'name' => 'Main']],
            ]]);
        }
        if ($url === 'https://host01.test/api/config-profiles/'.HOST01_PROFILE.'/inbounds' && $r->method() === 'GET') {
            return Http::response(['response' => [
                'inbounds' => $inboundsStale ? [] : [['uuid' => HOST01_INBOUND, 'tag' => 'VLESS', 'profileUuid' => HOST01_PROFILE]],
            ]]);
        }
        if ($url === 'https://host01.test/api/hosts' && $r->method() === 'POST') {
            return $postFails ? Http::response('', 504) : Http::response(['response' => ['uuid' => HOST01_HOST]], 201);
        }

        return Http::response(['message' => 'unknown'], 404);
    });
}

it('shows bounded safe Host list and detail using only GETs', function (): void {
    $root = host01User();
    host01Fake();
    $hosts = app(ManageAdminHosts::class);

    expect($hosts->all($root->id))->toBe([[
        'uuid' => HOST01_HOST, 'remark' => 'TLS-Host', 'address' => 'edge.example.com',
        'port' => 443, 'disabled' => true,
        'profileUuid' => HOST01_PROFILE, 'inboundUuid' => HOST01_INBOUND,
    ]]);
    expect($hosts->one($root->id, HOST01_HOST)['remark'])->toBe('TLS-Host');

    host01Bot()->hearText('/hosts')->reply()->assertCalled('sendMessage');
    host01Bot()->hearCallbackQueryData('host1:list:1')->reply()->assertCalled('editMessageText');
    host01Bot()->hearCallbackQueryData('host1:view:'.HOST01_HOST)->reply()->assertCalled('editMessageText');

    Http::assertSentCount(5);
    expect(collect(Http::recorded())->every(static fn (array $item): bool => $item[0]->method() === 'GET'
        && $item[0]->hasHeader('Authorization', 'Bearer fake-token')))->toBeTrue();
});

it('labels an all-zero provider profile UUID as unknown without changing the read model', function (): void {
    $root = host01User();
    $zero = '00000000-0000-0000-0000-000000000000';
    host01Fake(override: [
        'uuid' => HOST01_HOST, 'remark' => 'TLS-Host', 'address' => 'edge.example.com',
        'port' => 443, 'isDisabled' => false,
        'inbound' => ['configProfileUuid' => $zero, 'configProfileInboundUuid' => HOST01_INBOUND],
    ]);

    // Preserve the provider payload in Application reads and mutation snapshots.
    expect(app(ManageAdminHosts::class)->one($root->id, HOST01_HOST)['profileUuid'])->toBe($zero);

    host01Bot()->hearCallbackQueryData('host1:view:'.HOST01_HOST)->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = FakeNutgram::getActualData($request);
            $message = (string) ($payload['text'] ?? '');

            return str_contains($message, 'شناسه پروفایل: نامشخص')
                && str_contains($message, 'شناسه ورودی: '.HOST01_INBOUND)
                && str_contains($message, 'ارتباط پروفایل این هاست در پنل مشخص نیست')
                && ! str_contains($message, '00000000-0000-0000-0000-000000000000');
        }, index: 1);

    expect(collect(Http::recorded())->every(static fn (array $item): bool => $item[0]->method() === 'GET'))
        ->toBeTrue();
});

it('preserves a real Config Profile UUID in Host detail', function (): void {
    host01User();
    host01Fake();

    host01Bot()->hearCallbackQueryData('host1:view:'.HOST01_HOST)->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = FakeNutgram::getActualData($request);
            $message = (string) ($payload['text'] ?? '');

            return str_contains($message, 'شناسه پروفایل: '.HOST01_PROFILE)
                && ! str_contains($message, 'ارتباط پروفایل این هاست در پنل مشخص نیست');
        }, index: 1);
    Http::assertSentCount(1);
});

it('denies ordinary admins, disabled roots, and group-chat Host actions before provider requests', function (): void {
    $ordinary = host01User(957002, false);
    host01Fake();
    expect(fn () => app(ManageAdminHosts::class)->all($ordinary->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageAdminHosts::class)->create(
        $ordinary->id, 'Host', 'edge.example.com', 443, HOST01_PROFILE, HOST01_INBOUND,
    ))->toThrow(AuthorizationException::class);
    host01Bot(957002)->hearText('/hosts')->reply();
    host01Bot(957001, false)->hearText('/hosts')->reply()->assertNoReply();
    Http::assertNothingSent();

    $ordinary->syncRoles(['super-admin']);
    $ordinary->forceFill(['is_active' => false])->save();
    expect(fn () => app(ManageAdminHosts::class)->all($ordinary->id))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('does not enable Host creation or create a draft when global provider writes are disabled', function (): void {
    $root = host01User();
    host01Fake();
    host01Bot()->hearCallbackQueryData('host1:begin')->reply()->assertCalled('editMessageText');
    expect(Cache::get(TelegramBotContext::current()->cacheKey('host01:draft:'.$root->id)))->toBeNull();
    expect(fn () => app(ManageAdminHosts::class)->create(
        $root->id, 'TLS-Host', 'edge.example.com', 443, HOST01_PROFILE, HOST01_INBOUND,
    ))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('validates remark, hostname, numeric port, profile and inbound before attempting POST', function (): void {
    $root = host01User();
    host01Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $hosts = app(ManageAdminHosts::class);

    foreach ([
        ['', 'edge.example.com', 443],
        [str_repeat('A', 41), 'edge.example.com', 443],
        ['TLS', 'https://edge.example.com', 443],
        ['TLS', 'edge.example.com', 0],
        ['TLS', 'edge.example.com', 65536],
    ] as [$remark, $address, $port]) {
        expect(fn () => $hosts->create($root->id, $remark, $address, $port, HOST01_PROFILE, HOST01_INBOUND))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => $hosts->create($root->id, 'TLS', 'edge.example.com', 443, HOST01_PROFILE, HOST01_HOST))
        ->toThrow(ValidationException::class);
    expect(collect(Http::recorded())->filter(static fn (array $item): bool => $item[0]->method() === 'POST'))->toBeEmpty();
});

it('creates a disabled Host from exactly one confirmed wizard POST and cannot replay it', function (): void {
    $root = host01User();
    host01Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $bot = host01Bot();

    $bot->hearCallbackQueryData('host1:begin')->reply()->assertCalled('editMessageText');
    $bot->hearText('TLS-Host | edge.example.com | 443')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('host1:profile:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('host1:inbound:0')->reply()->assertCalled('editMessageText');
    $key = TelegramBotContext::current()->cacheKey('host01:draft:'.$root->id);
    $draft = Cache::get($key);
    expect($draft['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('host1:apply:'.$draft['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('host1:apply:'.$draft['token'])->reply()->assertCalled('editMessageText');
    expect(Cache::get($key))->toBeNull();

    Http::assertSentCount(5);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://host01.test/api/hosts'
        && $r['remark'] === 'TLS-Host'
        && $r['address'] === 'edge.example.com'
        && $r['port'] === 443
        && $r['isDisabled'] === true
        && $r['inbound'] === [
            'configProfileUuid' => HOST01_PROFILE,
            'configProfileInboundUuid' => HOST01_INBOUND,
        ]);
    expect(collect(Http::recorded())->filter(static fn (array $item): bool => $item[0]->method() === 'POST'))->toHaveCount(1);
});

it('consumes the one-use ticket before an uncertain provider response and never blindly retries', function (): void {
    $root = host01User();
    host01Fake(postFails: true);
    config()->set('services.remnawave.writes_enabled', true);
    $bot = host01Bot();
    $bot->hearCallbackQueryData('host1:begin')->reply();
    $bot->hearText('TLS-Host | edge.example.com | 443')->reply();
    $bot->hearCallbackQueryData('host1:profile:0')->reply();
    $bot->hearCallbackQueryData('host1:inbound:0')->reply();

    $key = TelegramBotContext::current()->cacheKey('host01:draft:'.$root->id);
    $token = Cache::get($key)['token'];
    $bot->hearCallbackQueryData('host1:apply:'.$token)->reply();
    $bot->hearCallbackQueryData('host1:apply:'.$token)->reply();

    expect(Cache::get($key))->toBeNull();
    expect(collect(Http::recorded())->filter(static fn (array $item): bool => $item[0]->method() === 'POST'))->toHaveCount(1);
});

it('fails closed when the chosen profile or inbound disappeared and sends no POST', function (bool $profileStale): void {
    $root = host01User();
    host01Fake(profilesStale: $profileStale, inboundsStale: ! $profileStale);
    config()->set('services.remnawave.writes_enabled', true);

    expect(fn () => app(ManageAdminHosts::class)->create(
        $root->id, 'TLS-Host', 'edge.example.com', 443, HOST01_PROFILE, HOST01_INBOUND,
    ))->toThrow(ValidationException::class);
    expect(collect(Http::recorded())->filter(static fn (array $item): bool => $item[0]->method() === 'POST'))->toBeEmpty();
})->with([true, false]);

it('rejects malformed list and wrong detail identity rather than forwarding raw panel data', function (): void {
    $root = host01User();
    host01Fake(override: [
        'uuid' => HOST01_HOST, 'remark' => '', 'address' => 'edge.example.com', 'port' => 443,
    ]);
    expect(fn () => app(ManageAdminHosts::class)->all($root->id))->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

it('does not allow a former Super Admin to finish a pending Host wizard', function (): void {
    $root = host01User();
    host01Fake();
    config()->set('services.remnawave.writes_enabled', true);
    $bot = host01Bot();
    $bot->hearCallbackQueryData('host1:begin')->reply();
    $bot->hearText('TLS-Host | edge.example.com | 443')->reply();
    $bot->hearCallbackQueryData('host1:profile:0')->reply();
    $bot->hearCallbackQueryData('host1:inbound:0')->reply();
    $token = Cache::get('host01:draft:'.$root->id)['token'];

    $root->syncRoles(['member']);
    $bot->hearCallbackQueryData('host1:apply:'.$token)->reply();
    expect(collect(Http::recorded())->filter(static fn (array $item): bool => $item[0]->method() === 'POST'))->toBeEmpty();
});

it('retires stale Node free-text drafts on Host begin', function (): void {
    $root = host01User();
    config()->set('services.remnawave.writes_enabled', true);
    host01Fake();
    Cache::put('node01:draft:'.$root->id, ['stage' => 'info'], now()->addMinutes(5));
    Cache::put('node02:draft:'.$root->id, ['stage' => 'edit'], now()->addMinutes(5));
    Cache::put('node03:draft:'.$root->id, ['stage' => 'value'], now()->addMinutes(5));

    host01Bot()->hearCallbackQueryData('host1:begin')->reply();
    foreach (['node01:draft:', 'node02:draft:', 'node03:draft:'] as $key) {
        expect(Cache::get($key.$root->id))->toBeNull();
    }
});
