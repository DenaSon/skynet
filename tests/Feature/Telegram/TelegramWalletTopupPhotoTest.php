<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('billing.topups_enabled', true);
    config()->set('billing.payment_card_number', '6037991234567890');
    config()->set('billing.payment_instructions', 'به نام <Finance & Team>');
    config()->set('sales.manual_bank_review_enabled', true);
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

/** Make a real Sales-only Nutgram test bot bound to a real user. */
function wallet02Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Wallet Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('offers private step-by-step wallet recharge and records photo without any credit', function (): void {
    $buyer = User::factory()->create();
    $bot = wallet02Bot(985101, $buyer);
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearText('۵۰۰۰۰')->reply()->assertCalled('sendMessage');

    $topup = DB::table('billing_topups')->first();
    expect($topup)->not->toBeNull()
        ->and((int) $topup->amount_rials)->toBe(500000)
        ->and($topup->status)->toBe('pending')
        ->and(DB::table('billing_journals')->count())->toBe(0);

    $bot->hearMessage(['message_id' => 101, 'photo' => [
        ['file_id' => 'wallet-photo-file', 'file_unique_id' => 'wallet-unique-photo', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText("🕘 عکس رسید شارژ #{$topup->id} دریافت شد.\n\nدر انتظار بررسی پرداخت توسط مدیر است. موجودی هنوز افزایش نیافته است.");

    expect(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('billing_topups')->value('status'))->toBe('submitted')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('keeps top-up input distinct from purchase payment and does not accept foreign or expired photo', function (): void {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $bot = wallet02Bot(985102, $buyer);
    $foreign = wallet02Bot(985103, $stranger);

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearText('50000')->reply()->assertCalled('sendMessage');

    $foreign->hearMessage(['message_id' => 200, 'photo' => [
        ['file_id' => 'foreign-wallet-photo', 'file_unique_id' => 'foreign-wallet-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('billing_topup_photos')->count())->toBe(0);

    $bot->hearText('/cancel')->reply()->assertCalled('sendMessage', 2);
    $bot->hearMessage(['message_id' => 201, 'photo' => [
        ['file_id' => 'cancelled-photo-id', 'file_unique_id' => 'cancelled-photo-uid', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearText('50000')->reply()->assertCalled('sendMessage');
    $this->travel(11)->minutes();
    $bot->hearMessage(['message_id' => 202, 'photo' => [
        ['file_id' => 'expired-photo-id', 'file_unique_id' => 'expired-photo-uid', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('billing_topup_photos')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps wallet photo submission closed if top-up flag is off', function (): void {
    $user = User::factory()->create();
    $bot = wallet02Bot(985104, $user);
    config()->set('billing.topups_enabled', false);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(Cache::get(TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$user->id)))->toBeNull();
    Http::assertNothingSent();
});
