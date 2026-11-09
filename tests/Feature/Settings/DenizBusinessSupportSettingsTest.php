<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Settings\DenizBusinessSupportSettings;
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
    config()->set('super_admin.telegram_ids', ['191101']);
    config()->set('nutgram.token', '100001:admin-bot');
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::ADMIN);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function denizBusinessAdminBot(User $user, int $telegramId = 191101): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function denizBusinessSettingsPayload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('exposes a root-only toggle under system settings and saves only after one-use confirmation', function (): void {
    $root = User::factory()->create(['is_active' => true]);
    $root->syncRoles(['super-admin']);
    $bot = denizBusinessAdminBot($root);

    $bot->hearCallbackQueryData('admin:welcome:show')->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(
            json_encode(denizBusinessSettingsPayload($request)['reply_markup'], JSON_THROW_ON_ERROR),
            'admin:deniz-support:show',
        ), index: 1,
    );

    $bot->hearCallbackQueryData('admin:deniz-support:show')->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(
            json_encode(denizBusinessSettingsPayload($request)['reply_markup'], JSON_THROW_ON_ERROR),
            'admin:deniz-support:prepare:on',
        ), index: 1,
    );

    $bot->hearCallbackQueryData('admin:deniz-support:prepare:on')->reply();
    $key = TelegramBotContext::current()->cacheKey('admin:deniz-support:confirm:'.$root->id);
    $draft = Cache::get($key);

    expect($draft['old'])->toBeFalse()
        ->and($draft['next'])->toBeTrue()
        ->and(DenizBusinessSupportSettings::isEnabled())->toBeFalse();

    $bot->hearCallbackQueryData('admin:deniz-support:apply:'.$draft['token'])->reply();
    expect(DenizBusinessSupportSettings::isEnabled())->toBeTrue()
        ->and(DB::table('settings')->where('group', 'deniz_business_support')->count())->toBe(1);

    $bot->hearCallbackQueryData('admin:deniz-support:apply:'.$draft['token'])->reply();
    expect(DenizBusinessSupportSettings::isEnabled())->toBeTrue();

    $bot->hearCallbackQueryData('admin:deniz-support:prepare:off')->reply();
    $next = Cache::get($key);
    $bot->hearCallbackQueryData('admin:deniz-support:apply:'.$next['token'])->reply();
    expect(DenizBusinessSupportSettings::isEnabled())->toBeFalse();

    Http::assertNothingSent();
});

it('rejects revoked roots and forged confirmations without changing the setting', function (): void {
    $root = User::factory()->create(['is_active' => true]);
    $root->syncRoles(['super-admin']);
    $bot = denizBusinessAdminBot($root);

    $bot->hearCallbackQueryData('admin:deniz-support:prepare:on')->reply();
    $key = TelegramBotContext::current()->cacheKey('admin:deniz-support:confirm:'.$root->id);
    $draft = Cache::get($key);

    $bot->hearCallbackQueryData('admin:deniz-support:apply:'.str_repeat('f', 32))->reply();
    expect(DenizBusinessSupportSettings::isEnabled())->toBeFalse();

    $root->removeRole('super-admin');
    $bot->hearCallbackQueryData('admin:deniz-support:apply:'.$draft['token'])->reply();

    expect(DenizBusinessSupportSettings::isEnabled())->toBeFalse();
    Http::assertNothingSent();
});

it('denies ordinary accounts from viewing or toggling Deniz support', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $bot = denizBusinessAdminBot($user, 191102);

    $bot->hearCallbackQueryData('admin:deniz-support:show')->reply();
    $bot->hearCallbackQueryData('admin:deniz-support:prepare:on')->reply();
    $bot->hearCallbackQueryData('admin:deniz-support:apply:'.str_repeat('a', 32))->reply();

    expect(DenizBusinessSupportSettings::isEnabled())->toBeFalse()
        ->and(DB::table('settings')->where('group', 'deniz_business_support')->count())->toBe(1);
    Http::assertNothingSent();
});
