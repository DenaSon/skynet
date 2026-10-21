<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesPanelRequests;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

/** Sales-native Nutgram bot backed by an actual private linked account. */
function polish152Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function polish152Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('displays an ordinary member role and real wallet balance after returning home', function (): void {
    $member = User::factory()->create();
    $bot = polish152Bot(987001, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], 'نوع حساب: <b>👤 کاربر عادی</b>')
            && str_contains($body['text'], 'موجودی کیف پول: <b>0 تومان</b>')
            && str_contains($markup, 'sales:wallet')
            && str_contains($markup, 'sales:account')
            && ! str_contains($markup, 'menu:builder')
            && ($body['parse_mode'] ?? null) === 'HTML';
    }, index: 1);

    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return str_contains($body['text'], 'نوع حساب: <b>👤 کاربر عادی</b>');
    }, index: 1);
    Http::assertNothingSent();
});

it('shows panel request status for an ordinary member but never promotes via navigation', function (): void {
    $member = User::factory()->create();
    $bot = polish152Bot(987002, $member);

    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return str_contains(json_encode($body['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '🧩 درخواست پنل فروش');
    }, index: 1);

    app(SalesPanelRequests::class)->submit($member->id, 'سارا', 'محمدی', '09123456789');
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return str_contains(json_encode($body['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '📨 وضعیت درخواست پنل فروش');
    }, index: 1);

    expect($member->fresh()->hasRole('reseller'))->toBeFalse()
        ->and(DB::table('sales_panel_requests')->count())->toBe(1);
    Http::assertNothingSent();
});

it('gives active Sales Panel holders their unique home builder action without another application form', function (): void {
    Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
    $member = User::factory()->create();
    $member->syncRoles(['reseller']);
    $bot = polish152Bot(987003, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], 'نوع حساب: <b>🧩 پنل فروش</b>')
            && str_contains($body['text'], 'موجودی کیف پول: <b>0 تومان</b>')
            && str_contains($markup, 'menu:builder')
            && str_contains($markup, 'sales:wallet');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], '🧩 پنل فروش')
            && str_contains($markup, 'menu:builder')
            && ! str_contains($markup, 'sales:panel:request');
    }, index: 1);

    expect(ReplyMenuLabels::all())->toContain(ReplyMenuLabels::LEGACY_BUILDER)
        ->toContain(ReplyMenuLabels::LEGACY_WALLET)
        ->toContain(ReplyMenuLabels::BUILDER)
        ->toContain(ReplyMenuLabels::WALLET);
    Http::assertNothingSent();
});

it('keeps referral unavailable-state navigation open to home and account', function (): void {
    config()->set('telegram.sales.bot_username', '');
    $member = User::factory()->create();
    $bot = polish152Bot(987004, $member);

    $bot->hearCallbackQueryData('sales:invite')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], 'لینک دعوت فعلاً در دسترس نیست')
            && str_contains($markup, 'sales:account')
            && str_contains($markup, 'sales:home');
    }, index: 1);
    Http::assertNothingSent();
});
