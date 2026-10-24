<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
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
use Tests\Support\PanelWalletFunding;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['977001', '977002']);
    config()->set('telegram_notifications.enabled', false);
    Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::ADMIN);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function sp02AdminBot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function sp02Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('shows the root application queue, confirms one decision and cannot replay the token', function (): void {
    $root = User::factory()->create();
    $bot = sp02AdminBot(977001, $root);
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 977099]);
    PanelWalletFunding::credit($buyer->id, 7_500_000);
    $id = app(SalesPanelRequests::class)->submit($buyer->id, 'سارا', 'رضایی', '09123456789')['id'];

    $bot->hearCallbackQueryData('menu:admin:sales')->reply()->assertRaw(static function (Request $request): bool {
        $data = sp02Payload($request);

        return str_contains(json_encode($data['reply_markup'] ?? null, JSON_THROW_ON_ERROR), 'sp:list:1');
    }, index: 1);
    $bot->hearCallbackQueryData('sp:list:1')->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('sp:detail:'.$id)->reply()->assertRaw(static function (Request $request): bool {
        $data = sp02Payload($request);

        return str_contains((string) ($data['text'] ?? ''), '09123456789')
            && str_contains(json_encode($data['reply_markup'] ?? null, JSON_THROW_ON_ERROR), 'sp:prepare:');
    }, index: 1);

    $token = '';
    $bot->hearCallbackQueryData('sp:prepare:'.$id.':a')->reply()->assertRaw(static function (Request $request) use (&$token): bool {
        $data = sp02Payload($request);
        $markup = json_encode($data['reply_markup'] ?? null, JSON_THROW_ON_ERROR);
        if (preg_match('/sp:apply:([a-f0-9]{32})/', $markup, $matches) !== 1) {
            return false;
        }
        $token = $matches[1];

        return str_contains((string) ($data['text'] ?? ''), 'تأیید و فعال‌سازی پنل فروش');
    }, index: 1);
    expect($token)->not->toBe('')
        ->and(DB::table('sales_panel_requests')->where('id', $id)->value('status'))->toBe('pending')
        ->and($buyer->fresh()->hasRole('member'))->toBeTrue();

    $bot->hearCallbackQueryData('sp:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect($buyer->fresh()->hasRole('reseller'))->toBeTrue()
        ->and(DB::table('sales_panel_requests')->where('id', $id)->value('status'))->toBe('approved');
    $bot->hearCallbackQueryData('sp:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_panel_requests')->where('id', $id)->value('status'))->toBe('approved')
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'SalesPanelApproved')->count())->toBe(1);
    Http::assertNothingSent();
});

it('denies ordinary Admin Bot users from reading applicant phone or changing roles', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = sp02AdminBot(977003, $admin);
    $buyer = User::factory()->create();
    PanelWalletFunding::credit($buyer->id, 7_500_000);
    $id = app(SalesPanelRequests::class)->submit($buyer->id, 'محمد', 'کریمی', '09123456789')['id'];

    $bot->hearCallbackQueryData('sp:list:1')->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('sp:detail:'.$id)->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('sp:prepare:'.$id.':a')->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('sp:apply:00000000000000000000000000000000')->reply()->assertCalled('answerCallbackQuery');

    expect(DB::table('sales_panel_requests')->where('id', $id)->value('status'))->toBe('pending')
        ->and($buyer->fresh()->hasRole('reseller'))->toBeFalse();
});

it('the old pending SP-01 application appears in the new Admin queue without retroactive alerts', function (): void {
    $root = User::factory()->create();
    $bot = sp02AdminBot(977001, $root);
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create();
    PanelWalletFunding::credit($buyer->id, 7_500_000);
    app(SalesPanelRequests::class)->submit($buyer->id, 'نازنین', 'فرهادی', '09120000000');

    $bot->hearText('/panelrequests')->reply()->assertCalled('sendMessage');
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0)
        ->and(DB::table('sales_panel_requests')->where('status', 'pending')->count())->toBe(1);
});
