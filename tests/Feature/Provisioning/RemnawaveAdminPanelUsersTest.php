<?php

use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ListAdminPanelUsers;
use App\Modules\Provisioning\Application\ReadAdminPanelUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

/** @return array{0:User,1:Nutgram} */
function rw04aActor(bool $trusted = true): array
{
    config()->set('super_admin.telegram_ids', ['995041', '995042']);
    $user = User::factory()->create();
    $user->syncRoles([$trusted ? 'super-admin' : 'member']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 995041]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(995041, false, 'RW Tester'));
    $bot->setCommonChat(Chat::make(995041, ChatType::PRIVATE));

    return [$user, $bot];
}

/** @return array<string, mixed> */
function rw04aRemoteUser(int $id): array
{
    return [
        'id' => $id,
        'username' => "fixture_{$id}",
        'shortUuid' => "short{$id}",
        'status' => $id % 2 === 0 ? 'DISABLED' : 'ACTIVE',
        'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'expireAt' => '2026-12-01T00:00:00Z',
        'subscriptionUrl' => 'https://secrets.example.test/never-leak',
        'userTraffic' => ['usedTrafficBytes' => 123],
        'activeInternalSquads' => [],
        'trojanPassword' => 'never-leak-password',
    ];
}

function rw04aFakeUsers(bool $fail = false): void
{
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'fake-token-only');
    Http::preventStrayRequests();

    Http::fake(static function ($request) use ($fail) {
        if ($fail) {
            return Http::response(['error' => 'secret-provider-error'], 503);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
        $start = (int) ($params['start'] ?? 0);
        $size = (int) ($params['size'] ?? 0);
        $items = [];

        for ($i = $start + 1; $i <= min($start + $size, 11); $i++) {
            $items[] = rw04aRemoteUser($i);
        }

        return Http::response(['response' => ['users' => $items, 'total' => 11]]);
    });
}

it('pages the real-provider projection with safe fields and GET-only calls', function (): void {
    [$root, $bot] = rw04aActor();
    rw04aFakeUsers();

    $list = app(ListAdminPanelUsers::class);
    $first = $list->handle($root->id);
    $second = $list->handle($root->id, 2);

    expect($first->state)->toBe('available')
        ->and($first->total)->toBe(11)
        ->and($first->users)->toHaveCount(10)
        ->and($first->hasNext)->toBeTrue()
        ->and($second->users)->toHaveCount(1)
        ->and($second->hasNext)->toBeFalse()
        ->and(array_keys($first->users[0]))->toBe(['id', 'username', 'status'])
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('never-leak');

    $expected = "👥 کاربران پنل\n\nتعداد: 11 | صفحه 1\n";
    for ($i = 1; $i <= 10; $i++) {
        $expected .= "\nfixture_{$i} — ".($i % 2 === 0 ? '🔴 غیرفعال' : '🟢 فعال');
    }

    $bot->hearText('/panelusers')->reply()->assertReplyText($expected);
    $bot->hearCallbackQueryData('rw:users:page:2')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');

    Http::assertSentCount(4);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'start=10')
        && str_contains($request->url(), 'size=10'));
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer fake-token-only'));
});

it('keeps the existing hybrid users menu unchanged while restricting provider reads to root', function (): void {
    [$root] = rw04aActor();
    $actor = app(ResolveTelegramActor::class)->handle(995041);
    config()->set('telegram.hybrid_menu_enabled', true);
    $buttons = app(ReplyMenuRenderer::class)->homeKeyboard($actor)->keyboard;
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$buttons));

    expect($labels)->toContain(ReplyMenuLabels::SERVERS)
        ->not->toContain('👥 کاربران پنل');
    expect($actor->canManageRoles())->toBeTrue();
});

it('fails safely with no provider configuration or a remote 503', function (): void {
    [$root, $bot] = rw04aActor();
    config()->set('services.remnawave.url', null);
    config()->set('services.remnawave.token', null);
    Http::preventStrayRequests();
    Http::fake();

    expect(app(ListAdminPanelUsers::class)->handle($root->id)->state)->toBe('error');
    $bot->hearText('/panelusers')->reply()
        ->assertReplyText("👥 کاربران پنل\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.");
    Http::assertNothingSent();

    rw04aFakeUsers(true);
    expect(app(ListAdminPanelUsers::class)->handle($root->id)->state)->toBe('error');
    Http::assertSentCount(1);
});

it('rejects nonroot and revoked callbacks before contacting the provider', function (): void {
    [$user, $bot] = rw04aActor(false);
    rw04aFakeUsers();

    expect(fn () => app(ListAdminPanelUsers::class)->handle($user->id))
        ->toThrow(AuthorizationException::class);
    $bot->hearText('/panelusers')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData('rw:users:page:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->setCommonChat(Chat::make(-995041, ChatType::GROUP));
    $bot->hearText('/panelusers')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('rw:users:page:1')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('never performs provider requests for malformed page callbacks', function (): void {
    [$root, $bot] = rw04aActor();
    rw04aFakeUsers();
    $bot->hearCallbackQueryData('rw:users:page:9999')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    expect(fn () => app(ListAdminPanelUsers::class)->handle($root->id, 0))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});


function rw04bFakeDetail(bool $mismatch = false, bool $fail = false): void
{
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'fake-token-only');
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/users/42' => $fail
            ? Http::response(['error' => 'private'], 503)
            : Http::response(['response' => rw04aRemoteUser(42)]),
        'https://rw.example.test/api/subscriptions/by-id/42' => Http::response([
            'response' => [
                'isFound' => true,
                'user' => [
                    'username' => $mismatch ? 'wrong_person' : 'fixture_42',
                    'shortUuid' => 'short42',
                ],
                'links' => ['vless://never-leak-config'],
                'ssConfLinks' => [],
                'subscriptionUrl' => 'https://secrets.example.test/never-leak-link',
            ],
        ]),
    ]);
}

it('shows only checked safe details and correctly matches v3 subscription username and shortUuid', function (): void {
    [$root, $bot] = rw04aActor();
    rw04bFakeDetail();

    $detail = app(ReadAdminPanelUser::class)->handle($root->id, 42);
    expect($detail->state)->toBe('available')
        ->and($detail->id)->toBe(42)
        ->and($detail->username)->toBe('fixture_42')
        ->and($detail->subscriptionMatched)->toBeTrue()
        ->and($detail->trafficLimitBytes)->toBe(1073741824)
        ->and($detail->usedTrafficBytes)->toBe(123)
        ->and(json_encode($detail, JSON_THROW_ON_ERROR))->not->toContain('never-leak')
        ->not->toContain('vless://')
        ->not->toContain('short42');

    $bot->hearCallbackQueryData('rw:users:view:42:1')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');

    Http::assertSentCount(4);
    Http::assertSent(static fn ($req): bool => $req->method() === 'GET'
        && str_ends_with($req->url(), '/api/subscriptions/by-id/42'));
});

it('rejects a subscription for a different username rather than trusting a missing user.id', function (): void {
    [$root, $bot] = rw04aActor();
    rw04bFakeDetail(mismatch: true);

    expect(app(ReadAdminPanelUser::class)->handle($root->id, 42)->state)->toBe('error');
    $bot->hearCallbackQueryData('rw:users:view:42:1')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');

    Http::assertSentCount(4);
    Http::assertSent(static fn ($req): bool => $req->method() === 'GET');
});

it('denies revoked, nonroot and group access to provider details without any network calls', function (): void {
    [$root, $bot] = rw04aActor();
    rw04bFakeDetail();
    $root->syncRoles(['member']);

    expect(fn () => app(ReadAdminPanelUser::class)->handle($root->id, 42))
        ->toThrow(AuthorizationException::class);
    $bot->hearCallbackQueryData('rw:users:view:42:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
    $bot->setCommonChat(Chat::make(-995041, ChatType::GROUP));
    $bot->hearCallbackQueryData('rw:users:view:42:1')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('denies invalid IDs and keeps HTTP failures as generic errors', function (): void {
    [$root, $bot] = rw04aActor();
    rw04bFakeDetail();

    expect(fn () => app(ReadAdminPanelUser::class)->handle($root->id, 0))
        ->toThrow(InvalidArgumentException::class);

    $bot->hearCallbackQueryData('rw:users:view:0:1')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    Http::assertNothingSent();

    rw04bFakeDetail(fail: true);
    expect(app(ReadAdminPanelUser::class)->handle($root->id, 42)->state)->toBe('error');
    Http::assertSentCount(1);
});
