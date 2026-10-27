<?php

use App\Interfaces\Telegram\Handlers\StartHandler;
use App\Modules\Identity\Application\IssueTelegramLinkCode;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Referral\Application\GetReferralInvite;
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
        ->assertReplyText("<b>🏠 DenaGate</b>\n\nخوش آمدید، Buyer.\nنوع حساب: <b>👤 کاربر عادی</b>");
    $bot->hearText('/account')->reply()
        ->assertCalled('sendRichMessage');
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
        "<b>🏠 DenaGate</b>\n\nخوش آمدید، Dealer Ignored.\nنوع حساب: <b>🧩 پنل فروش</b>"
    );
    $user->syncRoles(['member']);
    $bot->hearText('/start')->reply()->assertReplyText(
        "<b>🏠 DenaGate</b>\n\nخوش آمدید، Dealer Ignored.\nنوع حساب: <b>👤 کاربر عادی</b>"
    );

    // MENU-01 exposes only the existing safe plan-list view via /buy.
    $bot->hearText('/buy')->reply()->assertReply('sendMessage', ['parse_mode' => 'HTML']);
    foreach (['/admin', '/roles', '/payments'] as $command) {
        $bot->hearText($command)->reply()->assertReplyText('برای ادامه، یکی از دکمه‌های منو را انتخاب کنید.');
    }
    foreach (['menu:admin:users', 'rbac:apply:forged', 'shop:order:forged'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    }

    Http::assertNothingSent();
});

it('automatically enrolls a new private Sales buyer on /start even when global signup is disabled', function (): void {
    $bot = sb01Bot(771003);
    $bot->hearText('/start')->reply()
        ->assertCalled('sendMessage', 2)
        ->assertReplyText("<b>🏠 DenaGate</b>\n\nخوش آمدید، Sales Buyer.\nنوع حساب: <b>👤 کاربر عادی</b>");

    $account = TelegramAccount::query()->where('telegram_user_id', 771003)->firstOrFail();
    expect($account->user->is_active)->toBeTrue()
        ->and($account->user->hasRole('member'))->toBeTrue()
        ->and($account->user->hasRole('admin'))->toBeFalse()
        ->and($account->user->hasRole('reseller'))->toBeFalse()
        ->and($account->user->hasRole('super-admin'))->toBeFalse()
        ->and(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    $bot->hearText('/menu')->reply()->assertCalled('sendMessage', 2);
    $bot->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');
    expect(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->where('telegram_user_id', 771003)->value('user_id'))->toBe($account->user_id);
    Http::assertNothingSent();
});

it('keeps Sales enrollment independent of Admin signup and isolates distinct Telegram IDs', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    $first = sb01Bot(771004);
    $first->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    $second = sb01Bot(771014);
    $second->hearText('/start')->reply()->assertCalled('sendMessage', 2);

    $ids = TelegramAccount::query()->orderBy('telegram_user_id')->pluck('user_id')->all();
    expect(User::query()->count())->toBe(2)
        ->and(TelegramAccount::query()->count())->toBe(2)
        ->and($ids[0])->not->toBe($ids[1]);

    // A callback from a stale Sales invite/signup button cannot create an account.
    $unknown = sb01Bot(771024);
    $unknown->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');
    expect(User::query()->count())->toBe(2);
    Http::assertNothingSent();
});

it('keeps referral attribution only for a new Telegram ID on its first Sales /start', function (): void {
    $referrer = User::factory()->create();
    $referrer->assignRole('member');
    $code = app(GetReferralInvite::class)->handle($referrer->id)->code;
    $bot = sb01Bot(771034);

    $bot->hearText('/start ref_'.$code)->reply()->assertCalled('sendMessage', 2);
    $buyerId = (int) TelegramAccount::query()->where('telegram_user_id', 771034)->value('user_id');
    expect(DB::table('referrals')->where('invited_user_id', $buyerId)->value('referrer_user_id'))->toBe($referrer->id);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    expect(DB::table('referrals')->where('invited_user_id', $buyerId)->count())->toBe(1)
        ->and(TelegramAccount::query()->where('telegram_user_id', 771034)->count())->toBe(1);
    Http::assertNothingSent();
});

it('does not reattribute an existing invited account or allow a self-invitation', function (): void {
    $referrer = User::factory()->create();
    $referrer->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $referrer->id, 'telegram_user_id' => 771055]);
    $firstCode = app(GetReferralInvite::class)->handle($referrer->id)->code;
    sb01Bot(771055)->hearText('/start ref_'.$firstCode)->reply()->assertCalled('sendMessage', 2);
    expect(DB::table('referrals')->count())->toBe(0);

    $other = User::factory()->create();
    $other->assignRole('member');
    $secondCode = app(GetReferralInvite::class)->handle($other->id)->code;
    $buyer = sb01Bot(771056);
    $buyer->hearText('/start ref_'.$firstCode)->reply()->assertCalled('sendMessage', 2);
    $buyer->hearText('/start ref_'.$secondCode)->reply()->assertCalled('sendMessage', 2);
    $buyerId = (int) TelegramAccount::query()->where('telegram_user_id', 771056)->value('user_id');

    expect(DB::table('referrals')->count())->toBe(1)
        ->and(DB::table('referrals')->where('invited_user_id', $buyerId)->value('referrer_user_id'))->toBe($referrer->id)
        ->and(User::query()->count())->toBe(3);
    Http::assertNothingSent();
});

it('does not allow the Admin start handler to enroll an unlinked Telegram identity', function (): void {
    config()->set('telegram.public_signup_enabled', false);
    $bot = Nutgram::fake();
    $bot->setCommonUser(TelegramUser::make(771044, false, 'Unlinked Admin'));
    $bot->setCommonChat(Chat::make(771044, ChatType::PRIVATE));
    app(StartHandler::class)->__invoke($bot);

    expect(User::query()->count())->toBe(0)
        ->and(TelegramAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('reuses single-use account links and never revives disabled linked accounts', function (): void {
    $user = User::factory()->create(['name' => 'Linked Buyer']);
    $code = app(IssueTelegramLinkCode::class)->handle($user->id);
    $bot = sb01Bot(771005);

    $bot->hearText('/link '.$code)->reply()->assertCalled('sendMessage', 2);
    expect(TelegramAccount::query()->where('telegram_user_id', 771005)->value('user_id'))->toBe($user->id);

    $bot->hearText('/link '.$code)->reply()
        ->assertReplyText('کد اتصال نامعتبر، منقضی یا قبلاً مصرف شده است.');
    $user->forceFill(['is_active' => false])->save();
    config()->set('telegram.public_signup_enabled', true);
    $bot->hearText('/start')->reply()->assertReplyText(
        "<b>⛔ حساب غیرفعال</b>\n\nحساب شما غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید."
    );
    $bot->hearCallbackQueryData('sales:join')->reply()->assertCalled('editMessageText');

    expect(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->where('telegram_user_id', 771005)->value('user_id'))->toBe($user->id)
        ->and($user->fresh()->is_active)->toBeFalse();
    Http::assertNothingSent();
});

it('refuses group and mismatched private chats before resolving identity or registration', function (): void {
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
