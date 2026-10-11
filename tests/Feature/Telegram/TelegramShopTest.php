<?php

use App\Interfaces\Telegram\Support\CheckoutTokens;
use App\Interfaces\Telegram\Support\PendingShopVolume;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CalculateQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function shopBot(int $telegramId, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Customer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function shopSampleCatalog(): int
{
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN');
    app(ConfigureRate::class)->handle('vpn-access', 15, 60_000, 30_000);
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    app(ConfigureRate::class)->handle('vpn-access', 60, 160_000, 17_000);
    app(ConfigureRate::class)->handle('vpn-access', 90, 230_000, 15_000);

    return (int) DB::table('sales_products')->where('slug', 'vpn-access')->value('id');
}

it('shows price with each duration on the first buy screen instead of guessed prices', function (): void {
    $productId = shopSampleCatalog();
    $buyer = User::factory()->create();
    $bot = shopBot(921001, $buyer);

    $bot->hearText('/buy')->reply()->assertReplyText(
        "🛒 خرید اشتراک\n\nسرویس: اشتراک VPN\n⏳ مدت را انتخاب کنید:\nقیمت‌ها از حجم 1 گیگابایت محاسبه شده‌اند.\n"
    );

    $bot->hearCallbackQueryData('menu:shop')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    $bot->hearCallbackQueryData("shop:volume:{$productId}:30")->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
});

it('quotes a preset volume and displays reseller pricing from the same public rate', function (): void {
    $id = shopSampleCatalog();
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->assignRole(['member', 'reseller']);
    $bot = shopBot(921002, $reseller);

    $bot->hearCallbackQueryData("shop:quote:{$id}:30:10")->reply()->assertCalled('editMessageText');


    expect(DB::table('billing_wallets')->count())->toBe(0);
});

it('accepts custom Persian and Arabic numerals only after the custom-volume selection', function (): void {
    $id = shopSampleCatalog();
    $buyer = User::factory()->create();
    $bot = shopBot(921003, $buyer);

    $bot->hearText('۷۵')->reply()->assertNoReply();

    $bot->hearCallbackQueryData("shop:custom:{$id}:30")->reply()
        ->assertCalled('editMessageText');
    expect(app(PendingShopVolume::class)->peek($buyer->id))->toBe(['product' => 'vpn-access', 'days' => 30]);

    $bot->hearText('٧٥')->reply()->assertCalled('editMessageText');


    expect(app(PendingShopVolume::class)->peek($buyer->id))->toBeNull();
});

it('validates custom volume, prevents cross-account state use and expires it', function (): void {
    $id = shopSampleCatalog();
    $first = User::factory()->create();
    $other = User::factory()->create();
    $bot = shopBot(921004, $first);
    $otherBot = shopBot(921005, $other);

    $bot->setCommonUser(TelegramUser::make(921004, false, 'First'));
    $bot->setCommonChat(Chat::make(921004, ChatType::PRIVATE));
    $bot->hearCallbackQueryData("shop:custom:{$id}:30")->reply()->assertCalled('editMessageText');
    $otherBot->setCommonUser(TelegramUser::make(921005, false, 'Other'));
    $otherBot->setCommonChat(Chat::make(921005, ChatType::PRIVATE));
    $otherBot->hearText('75')->reply();
    $bot->setCommonUser(TelegramUser::make(921004, false, 'First'));
    $bot->setCommonChat(Chat::make(921004, ChatType::PRIVATE));

    $bot->hearText('0')->reply()->assertCalled('sendMessage');
    $bot->hearText('101')->reply()->assertCalled('sendMessage');
    $bot->hearText('invalid')->reply()->assertCalled('sendMessage');
    expect(app(PendingShopVolume::class)->peek($first->id))->not->toBeNull();

    $this->travel(11)->minutes();
    $bot->hearText('75')->reply()->assertNoReply();
    expect(app(PendingShopVolume::class)->peek($first->id))->toBeNull();
});

it('denies group and unlinked users, and refuses a disabled user despite old buttons', function (): void {
    $id = shopSampleCatalog();
    $unlinked = shopBot(921006);
    $unlinked->hearText('/buy')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $unlinked->hearCallbackQueryData("shop:quote:{$id}:30:50")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $user = User::factory()->create();
    $bot = shopBot(921007, $user);
    $bot->setCommonChat(Chat::make(-123456, ChatType::GROUP));
    $bot->hearText('/buy')->reply()->assertNoReply();
    $bot->hearCallbackQueryData("shop:quote:{$id}:30:50")->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(921007, ChatType::PRIVATE));
    $user->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData("shop:quote:{$id}:30:50")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});

it('rejects tampered, expired and disabled-rate callbacks without creating orders', function (): void {
    $id = shopSampleCatalog();
    $buyer = User::factory()->create();
    $bot = shopBot(921008, $buyer);

    $bot->hearCallbackQueryData("shop:quote:{$id}:30:101")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("shop:quote:{$id}:45:5")->reply()->assertCalled('editMessageText');

    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000, false);
    $bot->hearCallbackQueryData("shop:quote:{$id}:30:10")->reply()->assertCalled('editMessageText');

    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN', 1, 100, false);
    $bot->hearCallbackQueryData("shop:volume:{$id}:60")->reply()->assertCalled('editMessageText');

    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('supports a narrow product range with custom input and fresh price changes', function (): void {
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN', 25, 35);
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    $id = (int) DB::table('sales_products')->value('id');

    $buyer = User::factory()->create();
    $bot = shopBot(921009, $buyer);
    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("shop:volume:{$id}:30")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("shop:custom:{$id}:30")->reply()->assertCalled('editMessageText');
    $bot->hearText('15')->reply()->assertCalled('sendMessage');
    $bot->hearText('27')->reply()->assertCalled('sendMessage');

    app(ConfigureRate::class)->handle('vpn-access', 30, 200_000, 20_000);
    $bot->hearCallbackQueryData("shop:quote:{$id}:30:30")->reply()->assertCalled('editMessageText');

});

it('shows a clear unavailable notice if the operator has configured no real tariffs', function (): void {
    $buyer = User::factory()->create();
    $bot = shopBot(921010, $buyer);

    $bot->hearText('/buy')->reply()->assertReplyText(
        "🛒 خرید اشتراک\n\nدر حال حاضر تعرفه فعالی تعریف نشده است. خرید هنوز در دسترس نیست."
    );
});

it('supports browsing multiple products without cross-catalog unauthorized access', function (): void {
    shopSampleCatalog();
    app(ConfigureProduct::class)->handle('second', 'پروژه دوم');
    app(ConfigureRate::class)->handle('second', 30, 200_000, 22_000);

    $buyer = User::factory()->create();
    $bot = shopBot(921011, $buyer);

    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');
    $id = (int) DB::table('sales_products')->where('slug', 'second')->value('id');
    $bot->hearCallbackQueryData("shop:product:{$id}")->reply()->assertCalled('editMessageText');
});

it('registers pending-payment checkout exactly once even after replayed inline callback', function (): void {
    $id = shopSampleCatalog();
    $buyer = User::factory()->create();
    $bot = shopBot(921021, $buyer);
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 25);
    $token = app(CheckoutTokens::class)->issue($buyer->id, $quote);

    $bot->hearCallbackQueryData('shop:order:'.$token)->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('sales_orders')->value('traffic_gb'))->toBe(25)
        ->and(DB::table('sales_orders')->value('status'))->toBe('pending_payment');

    $bot->hearCallbackQueryData('shop:order:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);

    $bot->hearText('/orders')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('shop:orders')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('shop:order:view:1')->reply();
});

it('refuses another accounts checkout token and private order views', function (): void {
    shopSampleCatalog();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $ownerBot = shopBot(921022, $owner);
    $strangerBot = shopBot(921023, $stranger);

    $quote = app(CalculateQuote::class)->handle($owner->id, 'vpn-access', 30, 10);
    $token = app(CheckoutTokens::class)->issue($owner->id, $quote);

    $strangerBot->hearCallbackQueryData('shop:order:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0);

    $ownerBot->setCommonUser(TelegramUser::make(921022, false, 'Owner'));
    $ownerBot->setCommonChat(Chat::make(921022, ChatType::PRIVATE));
    $ownerBot->hearCallbackQueryData('shop:order:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(1);

    $strangerBot->setCommonUser(TelegramUser::make(921023, false, 'Other'));
    $strangerBot->setCommonChat(Chat::make(921023, ChatType::PRIVATE));
    $strangerBot->hearCallbackQueryData('shop:order:view:1')->reply();
    expect(DB::table('sales_orders')->count())->toBe(1);
});

it('rejects expired checkout and changed rates without charging any wallet', function (): void {
    shopSampleCatalog();
    $buyer = User::factory()->create();
    $bot = shopBot(921024, $buyer);

    $quote = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);
    $token = app(CheckoutTokens::class)->issue($buyer->id, $quote);
    app(ConfigureRate::class)->handle('vpn-access', 30, 300_000, 25_000);

    $bot->hearCallbackQueryData('shop:order:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0);

    $fresh = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);
    $otherToken = app(CheckoutTokens::class)->issue($buyer->id, $fresh);
    $this->travel(11)->minutes();

    $bot->hearCallbackQueryData('shop:order:'.$otherToken)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('keeps the registered order price unchanged after catalog rates change', function (): void {
    shopSampleCatalog();
    $buyer = User::factory()->create();
    $bot = shopBot(921025, $buyer);
    $quoted = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 50);
    $token = app(CheckoutTokens::class)->issue($buyer->id, $quoted);
    $bot->hearCallbackQueryData('shop:order:'.$token)->reply()->assertCalled('editMessageText');

    $old = (int) DB::table('sales_orders')->value('total_price_rials');
    app(ConfigureRate::class)->handle('vpn-access', 30, 500_000, 20_000);
    $bot->hearCallbackQueryData('shop:order:view:1')->reply();

    expect((int) DB::table('sales_orders')->value('total_price_rials'))->toBe($old);
});
