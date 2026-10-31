<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Settings\ResellerServiceStatusSettings;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['997801', '997802']);
    config()->set('nutgram.token', '12345:admin_test');
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'admin');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function resellerSettingBot(User $user, int $id): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Admin'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function resellerSettingToken(Request $request): ?string
{
    $payload = FakeNutgram::getActualData($request);
    $markup = $payload['reply_markup'] ?? null;
    if (is_string($markup)) {
        $markup = json_decode($markup, true, flags: JSON_THROW_ON_ERROR);
    }
    $text = json_encode($markup, JSON_THROW_ON_ERROR);

    return preg_match('/admin:reseller-status:apply:([a-f0-9]{32})/', $text, $matches) === 1
        ? $matches[1] : null;
}

it('starts disabled and permits only a trusted root to confirm an enable and a disable once', function (): void {
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeFalse();

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = resellerSettingBot($root, 997801);
    $bot->hearCallbackQueryData('admin:welcome:show')->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('admin:reseller-status:show')->reply()
        ->assertCalled('editMessageText');

    $token = null;
    $bot->hearCallbackQueryData('admin:reseller-status:prepare:on')->reply()
        ->assertRaw(static function (Request $request) use (&$token): bool {
            $token = resellerSettingToken($request);

            return $token !== null;
        }, index: 1);
    expect($token)->not->toBeNull();

    $bot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()
        ->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeTrue();
    $bot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()
        ->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeTrue();

    $offToken = null;
    $bot->hearCallbackQueryData('admin:reseller-status:prepare:off')->reply()
        ->assertRaw(static function (Request $request) use (&$offToken): bool {
            $offToken = resellerSettingToken($request);

            return $offToken !== null;
        }, index: 1);
    $bot->hearCallbackQueryData('admin:reseller-status:apply:'.$offToken)->reply()
        ->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeFalse()
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects other administrators and revoked or inactive roots even with a captured token', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $rootBot = resellerSettingBot($root, 997801);
    $token = null;
    $rootBot->hearCallbackQueryData('admin:reseller-status:prepare:on')->reply()
        ->assertRaw(static function (Request $request) use (&$token): bool {
            $token = resellerSettingToken($request);

            return $token !== null;
        }, index: 1);

    $ordinary = User::factory()->admin()->create();
    $ordinaryBot = resellerSettingBot($ordinary, 997803);
    $ordinaryBot->hearCallbackQueryData('admin:reseller-status:show')->reply()->assertCalled('editMessageText');
    $ordinaryBot->hearCallbackQueryData('admin:reseller-status:prepare:on')->reply()->assertCalled('editMessageText');
    $ordinaryBot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()->assertCalled('editMessageText');

    $root->syncRoles(['member']);
    $rootBot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeFalse();

    $root->syncRoles(['super-admin']);
    $root->forceFill(['is_active' => false])->save();
    $rootBot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeFalse();
    Http::assertNothingSent();
});

it('rejects expired and foreign-owner tokens and ignores group chats', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $rootBot = resellerSettingBot($root, 997801);
    $token = null;
    $rootBot->hearCallbackQueryData('admin:reseller-status:prepare:on')->reply()
        ->assertRaw(static function (Request $request) use (&$token): bool {
            $token = resellerSettingToken($request);

            return $token !== null;
        }, index: 1);
    $other = User::factory()->create();
    $other->syncRoles(['super-admin']);
    $otherBot = resellerSettingBot($other, 997802);

    $otherBot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()
        ->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeFalse();

    $rootBot->setCommonChat(Chat::make(-997801, ChatType::GROUP));
    $rootBot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()->assertNoReply();
    $rootBot->setCommonChat(Chat::make(997801, ChatType::PRIVATE));

    $this->travel(4)->minutes();
    $rootBot->hearCallbackQueryData('admin:reseller-status:apply:'.$token)->reply()
        ->assertCalled('editMessageText');
    expect(app(ResellerServiceStatusSettings::class)->enabled)->toBeFalse()
        ->and(Cache::get('admin:reseller-status:confirmation:'.$root->id.':'.$token))->toBeNull();
});
