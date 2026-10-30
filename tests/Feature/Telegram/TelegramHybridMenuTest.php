<?php

use App\Interfaces\Telegram\Support\PendingShopVolume;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
});

function hybridBot(int $telegramId, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Visitor'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/**
 * @return list<string>
 */
function hybridLabels(ReplyKeyboardMarkup $keyboard): array
{
    return array_map(
        static fn ($button): string => $button->text,
        array_merge(...$keyboard->keyboard),
    );
}

it('shows a two-column persistent home keyboard for active members', function (): void {
    $user = User::factory()->create(['name' => 'Hybrid Member']);
    $bot = hybridBot(931001, $user);

    $bot->hearText('/start')->reply()
        ->assertReplyText("🏠 DenaGate\n\nخوش آمدید، Hybrid Member.");

    $actor = app(ResolveTelegramActor::class)->handle(931001);
    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    expect($keyboard->resize_keyboard)->toBeTrue()
        ->and($keyboard->is_persistent)->toBeTrue()
        ->and(count($keyboard->keyboard))->toBe(3)
        ->and(count($keyboard->keyboard[0]))->toBe(2)
        ->and(hybridLabels($keyboard))->toContain(ReplyMenuLabels::BUY, ReplyMenuLabels::SUBSCRIPTIONS)
        ->not->toContain(ReplyMenuLabels::ADMIN, ReplyMenuLabels::SALES_WALLET, ReplyMenuLabels::WALLET);
});

it('routes reply labels before the custom-volume text matcher', function (): void {
    $user = User::factory()->create();
    $bot = hybridBot(931002, $user);
    app(PendingShopVolume::class)->remember($user->id, 'vpn', 30);

    $bot->hearText(ReplyMenuLabels::ACCOUNT)->reply()
        ->assertReplyText("👤 حساب من\n\nنام: {$user->name}\nشناسه DenaGate: {$user->id}\nنقش‌ها: کاربر\nوضعیت: 🟢 فعال");

    expect(app(PendingShopVolume::class)->peek($user->id))->toBeNull();

    $bot->hearText(ReplyMenuLabels::BUY)->reply()
        ->assertReplyText("🛒 خرید اشتراک\n\nدر حال حاضر اشتراکی برای خرید موجود نیست.");

    $bot->hearText(ReplyMenuLabels::SUBSCRIPTIONS)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::HOME)->reply()
        ->assertReplyText("🏠 DenaGate\n\nخوش آمدید، {$user->name}.");

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('keeps non-menu text and legacy commands and callbacks working', function (): void {
    $user = User::factory()->create();
    $bot = hybridBot(931003, $user);

    $bot->hearText('۷۵')->reply()->assertNoReply();
    $bot->hearText('/settings')->reply()->assertReplyText(
        "⚙️ تنظیمات\n\nدر حال حاضر گزینه‌ای برای تغییر تنظیمات وجود ندارد."
    );
    $bot->hearCallbackQueryData('menu:account')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:home')->reply()->assertCalled('editMessageText');
});

it('rejects forged menu labels sent by ordinary members', function (): void {
    $user = User::factory()->create();
    $bot = hybridBot(931004, $user);

    foreach ([ReplyMenuLabels::ADMIN, ReplyMenuLabels::ROLES, ReplyMenuLabels::SALES_WALLET, ReplyMenuLabels::WALLET] as $label) {
        $bot->hearText($label)->reply()
            ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
    }

    expect(DB::table('billing_journals')->count())->toBe(0);
});

it('shows normal admins only user management and protects against revocation', function (): void {
    $user = User::factory()->admin()->create();
    $bot = hybridBot(931005, $user);

    $actor = app(ResolveTelegramActor::class)->handle(931005);
    $labels = hybridLabels(app(ReplyMenuRenderer::class)->adminKeyboard($actor));
    expect($labels)->toContain(ReplyMenuLabels::USERS)
        ->not->toContain(ReplyMenuLabels::HOME, ReplyMenuLabels::ROLES, ReplyMenuLabels::SETTINGS, ReplyMenuLabels::TOPUPS, ReplyMenuLabels::RECEIPTS);

    $bot->hearText('/admin')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.");
    $bot->hearText(ReplyMenuLabels::USERS)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::ROLES)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    $user->syncRoles(['member']);
    $bot->hearText(ReplyMenuLabels::USERS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
});

it('gates privileged reply buttons by trusted root and finance feature switches', function (): void {
    config()->set('super_admin.telegram_ids', ['931006', '931099']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = hybridBot(931006, $root);
    $actor = app(ResolveTelegramActor::class)->handle(931006);

    $labels = hybridLabels(app(ReplyMenuRenderer::class)->adminKeyboard($actor));
    expect($labels)->toContain(ReplyMenuLabels::ROLES, ReplyMenuLabels::SALES_SECTION, ReplyMenuLabels::SETTINGS)
        ->not->toContain(ReplyMenuLabels::FINANCE_SECTION, ReplyMenuLabels::TOPUPS, ReplyMenuLabels::RECEIPTS);

    $bot->hearText(ReplyMenuLabels::TOPUPS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    config()->set('billing.topups_enabled', true);
    config()->set('sales.manual_bank_review_enabled', true);
    $labels = hybridLabels(app(ReplyMenuRenderer::class)->adminKeyboard($actor));
    expect($labels)->toContain(ReplyMenuLabels::FINANCE_SECTION)
        ->not->toContain(ReplyMenuLabels::TOPUPS, ReplyMenuLabels::RECEIPTS);

    $bot->hearText(ReplyMenuLabels::SETTINGS)->reply()
        ->assertReplyText('<b>⚙️ تنظیمات</b>'."\n\n".'متن خوشامدگویی موردنظر را برای ویرایش انتخاب کنید.');

    $bot->hearText(ReplyMenuLabels::ROLES)->reply()->assertCalled('sendMessage');

    config()->set('super_admin.telegram_ids', ['931099', '931098']);
    $bot->hearText(ReplyMenuLabels::ROLES)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
});

it('shows wallet only to resellers without implying admin authority', function (): void {
    Role::findOrCreate('reseller', 'web');
    $user = User::factory()->create();
    $user->assignRole('reseller');
    $bot = hybridBot(931007, $user);
    $actor = app(ResolveTelegramActor::class)->handle(931007);

    $labels = hybridLabels(app(ReplyMenuRenderer::class)->homeKeyboard($actor));
    expect($labels)->toContain(ReplyMenuLabels::SALES_WALLET)
        ->not->toContain(ReplyMenuLabels::ADMIN);

    $bot->hearText(ReplyMenuLabels::SALES_WALLET)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::WALLET)->reply()->assertCalled('sendMessage');
});

it('ignores group labels and removes stale keyboard for disabled or unlinked users', function (): void {
    $user = User::factory()->create();
    $bot = hybridBot(931008, $user);
    $bot->setCommonChat(Chat::make(-931008, ChatType::GROUP));

    $bot->hearText(ReplyMenuLabels::ADMIN)->reply()->assertNoReply();
    $bot->hearText(ReplyMenuLabels::BUY)->reply()->assertNoReply();

    $unlinked = hybridBot(931009);
    $unlinked->hearText(ReplyMenuLabels::ACCOUNT)->reply()
        ->assertReplyText('🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');

    $user->forceFill(['is_active' => false])->save();
    $bot->setCommonUser(TelegramUser::make(931008, false, 'Disabled'));
    $bot->setCommonChat(Chat::make(931008, ChatType::PRIVATE));
    $bot->hearText('/start')->reply()
        ->assertReplyText('🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');
});

it('can be rolled back by disabling the feature flag without intercepting messages', function (): void {
    config()->set('telegram.hybrid_menu_enabled', false);
    $user = User::factory()->create();
    $bot = hybridBot(931010, $user);

    $bot->hearText(ReplyMenuLabels::ACCOUNT)->reply()->assertNoReply();
    $bot->hearText('/start')->reply()
        ->assertReplyText("🏠 DenaGate\n\nخوش آمدید، {$user->name}.");
});

it('lets private users remove an old persistent keyboard after rollback', function (): void {
    config()->set('telegram.hybrid_menu_enabled', false);
    $user = User::factory()->create();
    $bot = hybridBot(931011, $user);

    $bot->hearText('/resetmenu')->reply()
        ->assertReplyText('✅ کیبورد سفارشی حذف شد. برای دریافت منوی فعلی /start را بزنید.');

    $bot->setCommonChat(Chat::make(-931011, ChatType::GROUP));
    $bot->hearText('/resetmenu')->reply()->assertNoReply();
});
