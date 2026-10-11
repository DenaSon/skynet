<?php

use App\Interfaces\Telegram\Support\PendingShopVolume;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
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

    $bot->hearCallbackQueryData("shop:quote:{$id}:30:10")->reply()->assertReplyText(
        "🧾 پیش‌نمایش قیمت اشتراک\n\nسرویس: اشتراک VPN\n⏳ مدت: 30 روز\n📦 حجم: 10 گیگابایت\n\n"
        ."قیمت عمومی: 30,000 تومان\nتخفیف نماینده (5٪): 1,500 تومان\n💰 قیمت نهایی: 28,500 تومان\n\n"
        .'ℹ️ این فقط نمایش قیمت لحظه‌ای است. ثبت سفارش و پرداخت در مرحله بعد فعال می‌شود.'
    );

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

    $bot->hearText('٧٥')->reply()->assertReplyText(
        "🧾 پیش‌نمایش قیمت اشتراک\n\nسرویس: اشتراک VPN\n⏳ مدت: 30 روز\n📦 حجم: 75 گیگابایت\n\n"
        ."قیمت عمومی: 160,000 تومان\n💰 قیمت نهایی: 160,000 تومان\n\n"
        .'ℹ️ این فقط نمایش قیمت لحظه‌ای است. ثبت سفارش و پرداخت در مرحله بعد فعال می‌شود.'
    );

    expect(app(PendingShopVolume::class)->peek($buyer->id))->toBeNull();
});

it('validates custom volume, prevents cross-account state use and expires it', function (): void {
    $id = shopSampleCatalog();
    $first = User::factory()->create();
    $other = User::factory()->create();
    $bot = shopBot(921004, $first);
    $otherBot = shopBot(921005, $other);

    $bot->hearCallbackQueryData("shop:custom:{$id}:30")->reply()->assertCalled('editMessageText');
    $otherBot->hearText('75')->reply()->assertNoReply();

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
    $bot->hearCallbackQueryData("shop:quote:{$id}:30:30")->reply()->assertReplyText(
        "🧾 پیش‌نمایش قیمت اشتراک\n\nسرویس: اشتراک VPN\n⏳ مدت: 30 روز\n📦 حجم: 30 گیگابایت\n\n"
        ."قیمت عمومی: 80,000 تومان\n💰 قیمت نهایی: 80,000 تومان\n\n"
        .'ℹ️ این فقط نمایش قیمت لحظه‌ای است. ثبت سفارش و پرداخت در مرحله بعد فعال می‌شود.'
    );
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
