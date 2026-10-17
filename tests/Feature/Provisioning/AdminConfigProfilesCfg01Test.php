<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminConfigProfiles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const CFG01_PROFILE = '550e8400-e29b-41d4-a716-446655440061';
const CFG01_INBOUND = '550e8400-e29b-41d4-a716-446655440062';
const CFG01_NODE = '550e8400-e29b-41d4-a716-446655440063';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['956001', '956002']);
    config()->set('services.remnawave.url', 'https://cfg01.test');
    config()->set('services.remnawave.token', 'fake-only-secret');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function cfg01Actor(int $telegramId = 956001, bool $trusted = true): User
{
    $user = User::factory()->create();
    if ($trusted) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function cfg01Bot(int $telegramId = 956001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'User'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -956001, $private ? ChatType::PRIVATE : ChatType::SUPERGROUP));

    return $bot;
}

/** @param array<mixed>|null $nodes */
function cfg01Fake(?array $nodes = null, bool $nodeInfo = true, bool $inbounds = true, bool $malformed = false): void
{
    Http::fake(static function (Request $request) use ($nodes, $nodeInfo, $inbounds, $malformed) {
        return match ($request->url()) {
            'https://cfg01.test/api/config-profiles' => Http::response(['response' => [
                'total' => 1,
                'configProfiles' => $malformed
                    ? [['uuid' => 'bad', 'name' => 'Main']]
                    : [['uuid' => CFG01_PROFILE, 'name' => 'Main', 'config' => ['secret' => 'NEVER_SHOW']]],
            ]]),
            'https://cfg01.test/api/config-profiles/'.CFG01_PROFILE.'/inbounds' => $inbounds
                ? Http::response(['response' => ['inbounds' => [
                    ['uuid' => CFG01_INBOUND, 'tag' => 'VLESS', 'profileUuid' => CFG01_PROFILE, 'secret' => 'NEVER_SHOW'],
                ]]])
                : Http::response(['message' => 'unavailable'], 503),
            'https://cfg01.test/api/nodes' => $nodeInfo
                ? Http::response(['response' => $nodes ?? [
                    ['uuid' => CFG01_NODE, 'name' => 'Edge-1',
                        'configProfile' => ['activeConfigProfileUuid' => CFG01_PROFILE, 'activeInbounds' => [CFG01_INBOUND]],
                        'secret' => 'NEVER_SHOW'],
                ]])
                : Http::response(['message' => 'unavailable'], 503),
            default => Http::response(['message' => 'not found'], 404),
        };
    });
}

it('lists safe profiles and detail inbounds, showing confirmed linked nodes using GET only', function (): void {
    $root = cfg01Actor();
    cfg01Fake();

    $reader = app(ReadAdminConfigProfiles::class);
    expect($reader->all($root->id))->toBe([['uuid' => CFG01_PROFILE, 'name' => 'Main']]);

    $detail = $reader->detail($root->id, CFG01_PROFILE);
    expect($detail)->toBe([
        'uuid' => CFG01_PROFILE, 'name' => 'Main',
        'inbounds' => [['uuid' => CFG01_INBOUND, 'name' => 'VLESS']],
        'linkedNodes' => ['Edge-1'],
    ]);
    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer fake-only-secret'));
    expect(collect(Http::recorded())->every(static fn (array $item): bool => $item[0]->method() === 'GET'))->toBeTrue();
});

it('shows unknown dependency when Node API lacks profile mapping, never a fabricated zero', function (): void {
    $root = cfg01Actor();
    cfg01Fake([['uuid' => CFG01_NODE, 'name' => 'Legacy-Node']]);

    expect(app(ReadAdminConfigProfiles::class)->detail($root->id, CFG01_PROFILE)['linkedNodes'])->toBeNull();
    Http::assertSentCount(3);
});

it('keeps existing inbounds available when the optional Node dependency API fails', function (): void {
    $root = cfg01Actor();
    cfg01Fake(nodeInfo: false);

    $detail = app(ReadAdminConfigProfiles::class)->detail($root->id, CFG01_PROFILE);
    expect($detail['inbounds'])->toHaveCount(1)
        ->and($detail['linkedNodes'])->toBeNull();
});

it('rejects stale or malformed profile identifiers without requesting arbitrary inbound details', function (): void {
    $root = cfg01Actor();
    cfg01Fake();

    expect(app(ReadAdminConfigProfiles::class)->detail($root->id, 'wrong-id'))->toBeNull();
    Http::assertNothingSent();

    expect(app(ReadAdminConfigProfiles::class)->detail($root->id, '550e8400-e29b-41d4-a716-446655440099'))->toBeNull();
    Http::assertSentCount(1);
});

it('rejects malformed remote profile data and keeps raw configs secret', function (): void {
    $root = cfg01Actor();
    cfg01Fake(malformed: true);
    expect(fn () => app(ReadAdminConfigProfiles::class)->all($root->id))
        ->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

it('shows an empty inbound list and verifies no linked nodes without claiming missing data', function (): void {
    $root = cfg01Actor();
    Http::fake([
        'https://cfg01.test/api/config-profiles' => Http::response(['response' => [
            'total' => 1, 'configProfiles' => [['uuid' => CFG01_PROFILE, 'name' => 'Empty']],
        ]]),
        'https://cfg01.test/api/config-profiles/'.CFG01_PROFILE.'/inbounds' => Http::response(['response' => ['inbounds' => []]]),
        'https://cfg01.test/api/nodes' => Http::response(['response' => []]),
    ]);

    $detail = app(ReadAdminConfigProfiles::class)->detail($root->id, CFG01_PROFILE);
    expect($detail['inbounds'])->toBe([])
        ->and($detail['linkedNodes'])->toBe([]);
});

it('renders the command and callbacks in private Admin only with safe navigation', function (): void {
    cfg01Actor();
    cfg01Fake();
    $bot = cfg01Bot();

    $bot->hearText('/profiles')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('cfg1:list:1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('cfg1:view:'.CFG01_PROFILE.':1')->reply()->assertCalled('editMessageText');

    Http::assertSentCount(5);
    expect(collect(Http::recorded())->every(static fn (array $item): bool => $item[0]->method() === 'GET'))->toBeTrue();
});

it('denies non-root and revoked actors before external requests', function (): void {
    $ordinary = cfg01Actor(956002, false);
    cfg01Fake();

    expect(fn () => app(ReadAdminConfigProfiles::class)->all($ordinary->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminConfigProfiles::class)->detail($ordinary->id, CFG01_PROFILE))
        ->toThrow(AuthorizationException::class);
    cfg01Bot(956002)->hearText('/profiles')->reply();
    cfg01Bot(956002)->hearCallbackQueryData('cfg1:view:'.CFG01_PROFILE.':1')->reply();
    Http::assertNothingSent();

    $ordinary->syncRoles(['super-admin']);
    $ordinary->forceFill(['is_active' => false])->save();
    expect(fn () => app(ReadAdminConfigProfiles::class)->all($ordinary->id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('disallows group chats and invalid pagination without provider access', function (): void {
    cfg01Actor();
    cfg01Fake();
    cfg01Bot(956001, false)->hearText('/profiles')->reply()->assertNoReply();
    cfg01Bot()->hearCallbackQueryData('cfg1:list:9999')->reply();
    cfg01Bot()->hearCallbackQueryData('cfg1:view:'.CFG01_PROFILE.':0')->reply();
    Http::assertNothingSent();
});
