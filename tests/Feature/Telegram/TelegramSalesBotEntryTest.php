<?php

use App\Modules\Identity\Application\IssueTelegramLinkCode;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('nutgram.token', '87654321:admin-secret');
    config()->set('telegram.sales.token', '12345678:sales-secret');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.public_signup_enabled', false);
    Http::preventStrayRequests();
    Http::fake();
});

function sb01Bot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    // Register the real Sales-only handlers on Nutgram's test transport.
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Sales Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('shows a real Sales-only home and account for linked members without commerce operations', function (): void {
    $user = User::factory()->create(['name' => 'Buyer']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 771001]);
    $bot = sb01Bot(771001);

    $bot->hearText('/start')->reply()
        ->assertReplyText("🏠 ParsaGate\n\nخوش آمدید، Buyer.\nنوع حساب: کاربر\n\n🛒 خرید اشتراک به‌زودی فعال می‌شود.");
    $bot->hearText('/account')->reply()
        ->assertReplyText("👤 حساب من\n\nنام: Buyer\nشناسه ParsaGate: {$user->id}\nنوع حساب: کاربر\nوضعیت: 🟢 فعال");
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:account')->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('uses current roles without making Admin or legacy checkout routes available to Sales', function (): void {
    Role::findOrCreate('reseller', 'web');
    $user = User::factory()->create(['name' => "Dealer\nIgnored"]);
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 771002]);
    $bot = sb01Bot(771002);

    $bot->hearText('/start')->reply()->assertReplyText(
        "🏠 ParsaGate\n\nخوش آمدید، Dealer Ignored.\nنوع حساب: نماینده فروش\n\n🛒 خرید اشتراک به‌زودی فعال می‌شود."
    );
    $user->syncRoles(['member']);
    $bot->hearText('/start')->reply()->assertReplyText(
        "🏠 ParsaGate\n\nخوش آمدید، Dealer Ignored.\nنوع حساب: کاربر\n\n🛒 خرید اشتراک به‌زودی فعال می‌شود."
    );

    foreach (['/admin', '/roles', '/payments', '/buy', '/catalog', '/builder'] as $command) {
        $bot->hearText($command)->reply()->assertNoReply();
    }
    foreach (['menu:admin:users', 'rbac:apply:forged', 'shop:order:forged'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()->assertNoReply();
    }

    Http::assertNothingSent();
});

it('keeps public signup disabled by default even for stale consent callbacks', function (): void {
    $bot = sb01Bot(771003);
    $bot->hearText('/start')->reply()->assertReplyText(
        "🔒 برای استفاده از ParsaGate ابتدا حساب خود را متصل کنید.\n\nکد یک‌بارمصرف دریافتی از مدیر را با دستور /link وارد کنید."
    );
    $bot->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');

    expect(User::query()->count())->toBe(0)
        ->and(TelegramAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('requires explicit callback consent for member-only registration when the existing gate is on', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    $bot = sb01Bot(771004);

    $bot->hearText('/start')->reply()->assertReplyText(
        "👋 به ParsaGate خوش آمدید.\n\nبرای ساخت حساب کاربری، دکمه زیر را انتخاب کنید.\nاگر از قبل حساب دارید، کد اتصال دریافتی را با دستور /link وارد کنید."
    );
    expect(User::query()->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');
    $linked = TelegramAccount::query()->where('telegram_user_id', 771004)->firstOrFail();
    expect($linked->user->hasRole('member'))->toBeTrue()
        ->and($linked->user->hasRole('admin'))->toBeFalse()
        ->and(User::query()->count())->toBe(1);

    $bot->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');
    expect(User::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('reuses single-use account links and never revives disabled linked accounts', function (): void {
    $user = User::factory()->create(['name' => 'Linked Buyer']);
    $code = app(IssueTelegramLinkCode::class)->handle($user->id);
    $bot = sb01Bot(771005);

    $bot->hearText('/link '.$code)->reply()->assertCalled('sendMessage');
    expect(TelegramAccount::query()->where('telegram_user_id', 771005)->value('user_id'))->toBe($user->id);

    $bot->hearText('/link '.$code)->reply()
        ->assertReplyText('کد اتصال نامعتبر، منقضی یا قبلاً مصرف شده است.');
    $user->forceFill(['is_active' => false])->save();
    config()->set('telegram.public_signup_enabled', true);
    $bot->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->is_active)->toBeFalse();
    Http::assertNothingSent();
});

it('refuses group and mismatched private chats before resolving identity or registration', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    $bot = sb01Bot(771006);
    $bot->setCommonChat(Chat::make(-7700, ChatType::GROUP));
    $bot->hearText('/start')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('sales:join')->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(771007, ChatType::PRIVATE));
    $bot->hearText('/start')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('sales:join')->reply()->assertNoReply();

    expect(User::query()->count())->toBe(0);
    Http::assertNothingSent();
});
