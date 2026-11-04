<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['958801', '958802']);
    config()->set('billing.topups_enabled', true);
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::ADMIN);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function manualCreditBot(User $actor, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function manualCreditPayload(Request $request): array
{
    $body = FakeNutgram::getActualData($request);
    if (is_string($body['reply_markup'] ?? null)) {
        $body['reply_markup'] = json_decode($body['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $body;
}

function manualCreditRich(array $body): string
{
    $data = is_string($body['rich_message'] ?? null)
        ? json_decode($body['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($body['rich_message'] ?? []);

    return ($data['is_rtl'] ?? null) === true ? (string) ($data['html'] ?? '') : '';
}

it('uses red root-only wallet action, Persian amount input, escaped Rich confirmation and one-use bank credit', function (): void {
    $root = User::factory()->create(['name' => 'Root Bank Reviewer']);
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create(['name' => '<Buyer & Son>']);
    $bot = manualCreditBot($root, 958801);

    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id)->reply()->assertRaw(
        static function (Request $request) use ($buyer): bool {
            $body = manualCreditPayload($request);
            $buttons = json_encode($body['reply_markup'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return str_contains($buttons, 'finance:credit:begin:'.$buyer->id)
                && str_contains($buttons, 'افزایش اعتبار')
                && str_contains($buttons, 'danger');
        }, index: 1,
    );

    $bot->hearCallbackQueryData('finance:credit:begin:'.$buyer->id)->reply()
        ->assertCalled('editMessageText');
    $bot->hearText('BADAMOUNT')->reply()->assertCalled('sendMessage');
    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(Cache::get('telegram:finance:wallet:'.$root->id)['kind'])->toBe('manual_amount');

    $bot->hearText('۲۷٬۵۰۰')->reply()->assertCalled('sendMessage');
    expect(Cache::get('telegram:finance:wallet:'.$root->id)['amount_rials'])->toBe(275_000);
    $bot->hearText('bank-275-funds')->reply()->assertCalled('sendMessage');
    $bot->hearText('واریز اضافی <سفارش ۲۷۵>')->reply()->assertCalled('sendRichMessage');

    expect(DB::table('billing_journals')->count())->toBe(0);
    $state = Cache::get('telegram:finance:wallet:'.$root->id);
    expect($state['kind'])->toBe('manual_confirm')
        ->and($state['reason'])->toBe('واریز اضافی <سفارش ۲۷۵>');

    // The final confirmation body is bound to the same authenticated draft.
    $bot->hearCallbackQueryData('finance:credit:apply:'.$state['token'])->reply()
        ->assertCalled('editMessageText');
    expect(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(275_000)
        ->and(DB::table('billing_topups')->where('origin', 'admin_verified_bank')->count())->toBe(1);
    $bot->hearCallbackQueryData('finance:credit:apply:'.$state['token'])->reply()
        ->assertCalled('editMessageText');
    expect(DB::table('billing_journals')->count())->toBe(1);

    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id)->reply()->assertRaw(
        static function (Request $request): bool {
            $html = manualCreditRich(manualCreditPayload($request));

            return str_contains($html, 'افزایش اعتبار مدیر')
                && str_contains($html, 'BANK-275-FUNDS')
                && str_contains($html, '&lt;سفارش ۲۷۵&gt;')
                && str_contains($html, 'Root Bank Reviewer');
        }, index: 1,
    );
});

it('rejects forged and revoked-root manual credit callbacks and never credits in groups', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create();
    $bot = manualCreditBot($root, 958801);

    $bot->hearCallbackQueryData('finance:credit:begin:'.$buyer->id)->reply()
        ->assertCalled('editMessageText');
    $bot->hearText('27500')->reply()->assertCalled('sendMessage');
    $bot->hearText('VERIFYBANK123')->reply()->assertCalled('sendMessage');
    $bot->hearText('علت آزمایشی')->reply()->assertCalled('sendRichMessage');
    $state = Cache::get('telegram:finance:wallet:'.$root->id);

    config()->set('super_admin.telegram_ids', ['958802']);
    $bot->hearCallbackQueryData('finance:credit:apply:'.$state['token'])->reply()
        ->assertCalled('answerCallbackQuery');
    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);

    $ordinaryAdmin = User::factory()->admin()->create();
    $otherBot = manualCreditBot($ordinaryAdmin, 958888);
    $otherBot->hearCallbackQueryData('finance:credit:begin:'.$buyer->id)->reply()
        ->assertCalled('answerCallbackQuery');

    $bot->setCommonChat(Chat::make(-958801, ChatType::GROUP));
    $bot->hearCallbackQueryData('finance:credit:apply:'.$state['token'])->reply()->assertNoReply();
    expect(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(0);
});
