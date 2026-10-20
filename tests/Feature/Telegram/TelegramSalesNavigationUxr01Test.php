<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\PendingBuilderSelection;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Referral\Application\GetReferralInvite;
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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.public_signup_enabled', false);
    config()->set('telegram.sales.bot_username', 'DenaGateSalesBot');
    config()->set('telegram.sales.support_username', null);
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'sales');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function uxr01Bot(User $user): Nutgram
{
    TelegramAccount::query()->create(['telegram_user_id' => 975001, 'user_id' => $user->id]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make(975001, false, 'Buyer'));
    $bot->setCommonChat(Chat::make(975001, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function uxr01Payload(Request $request): array
{
    $data = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('installs exactly four persistent Sales buttons and escapes dynamic HTML in home and account', function (): void {
    $user = User::factory()->create(['name' => '<Buyer> & "Name"']);
    $bot = uxr01Bot($user);
    $keyboard = app(ReplyMenuRenderer::class)->salesKeyboard();
    expect($keyboard->is_persistent)->toBeTrue()
        ->and($keyboard->resize_keyboard)->toBeTrue()
        ->and($keyboard->one_time_keyboard)->toBeFalse()
        ->and(array_map(static fn (array $row): array => array_column($row, 'text'), $keyboard->keyboard))
        ->toBe([
            [ReplyMenuLabels::SALES_BUY, ReplyMenuLabels::SALES_SUBSCRIPTIONS],
            [ReplyMenuLabels::SALES_ACCOUNT, ReplyMenuLabels::SALES_SUPPORT],
        ]);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2)
        ->assertReply('sendMessage', ['parse_mode' => 'HTML'])
        ->assertReplyText("<b>🏠 DenaGate</b>\n\nخوش آمدید، &lt;Buyer&gt; &amp; &quot;Name&quot;.\nنوع حساب: کاربر");
    $bot->hearText(ReplyMenuLabels::SALES_ACCOUNT)->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return str_contains($body['text'], '&lt;Buyer&gt; &amp; &quot;Name&quot;')
            && ! str_contains($body['text'], '<Buyer>');
    });
    Http::assertNothingSent();
});

it('routes every primary destination ahead of pending input and retires only Sales drafts', function (): void {
    $user = User::factory()->create();
    $bot = uxr01Bot($user);
    $builder = app(PendingBuilderSelection::class);
    $salesReceipt = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$user->id);
    $adminBuilder = 'telegram:builder:selection:'.$user->id;
    $adminReceipt = 'telegram:payphoto:'.$user->id;
    Cache::put($adminBuilder, ['plan_id' => 9, 'page' => 1], 600);
    Cache::put($adminReceipt, 9, 600);

    foreach ([ReplyMenuLabels::SALES_BUY, ReplyMenuLabels::SALES_SUBSCRIPTIONS,
        ReplyMenuLabels::SALES_ACCOUNT, ReplyMenuLabels::SALES_SUPPORT,
        ReplyMenuLabels::HOME, '/cancel'] as $label) {
        $builder->remember($user->id, 123, 1);
        Cache::put($salesReceipt, 123, 600);
        $bot->hearText($label)->reply()->assertCalled('sendMessage', in_array($label, [ReplyMenuLabels::HOME, '/cancel'], true) ? 2 : 1);
        expect($builder->peek($user->id))->toBeNull()
            ->and(Cache::has($salesReceipt))->toBeFalse()
            ->and(Cache::get($adminReceipt))->toBe(9)
            ->and(Cache::get($adminBuilder))->toBe(['plan_id' => 9, 'page' => 1]);
    }
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('uses the Sales identity for invitations and retains reseller access only while authorized', function (): void {
    $user = User::factory()->create();
    $user->assignRole('reseller');
    $bot = uxr01Bot($user);
    $invite = app(GetReferralInvite::class)->handle($user->id);
    $url = 'https://t.me/DenaGateSalesBot?start=ref_'.$invite->code;
    $bot->hearCallbackQueryData('sales:invite')->reply()->assertCalled('answerCallbackQuery')
        ->assertRaw(static function (Request $request) use ($url): bool {
            $body = uxr01Payload($request);

            return str_contains($body['text'], $url)
                && $body['reply_markup']['inline_keyboard'][1][0]['copy_text']['text'] === $url;
        }, index: 1);
    $bot->hearText('/account')->reply()->assertRaw(static fn (Request $request): bool =>
        str_contains(json_encode(uxr01Payload($request), JSON_THROW_ON_ERROR), 'menu:builder'));
    $user->syncRoles(['member']);
    $bot->hearText('/account')->reply()->assertRaw(static fn (Request $request): bool =>
        ! str_contains(json_encode(uxr01Payload($request), JSON_THROW_ON_ERROR), 'menu:builder'));
    $bot->hearCallbackQueryData('menu:builder')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    Http::assertNothingSent();
});

it('offers a real configured Telegram contact and short guides with working back navigation', function (): void {
    $user = User::factory()->create();
    $bot = uxr01Bot($user);
    config()->set('telegram.sales.support_username', '@DenaGateOperator');
    $bot->hearText(ReplyMenuLabels::SALES_SUPPORT)->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return $body['parse_mode'] === 'HTML'
            && $body['reply_markup']['inline_keyboard'][1][0]['url'] === 'https://t.me/DenaGateOperator';
    });
    foreach (['apps', 'android', 'ios', 'desktop', 'purchase', 'troubleshoot'] as $page) {
        $bot->hearCallbackQueryData('sales:help:'.$page)->reply()
            ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    }
    $bot->hearCallbackQueryData('sales:help:android')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return str_contains($body['text'], '<blockquote>')
            && $body['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://hiddify.com/';
    }, index: 1);
    $bot->hearCallbackQueryData('sales:support')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertNothingSent();
});

it('never fabricates an operator link when configuration is missing or invalid', function (): void {
    $bot = uxr01Bot(User::factory()->create());
    foreach ([null, '', 'https://example.test/operator', 'bad name', '<invalid>'] as $username) {
        config()->set('telegram.sales.support_username', $username);
        $bot->hearText('/help')->reply()->assertRaw(static function (Request $request): bool {
            $body = uxr01Payload($request);

            return str_contains($body['text'], 'فعلاً در دسترس نیست')
                && ! str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), '"url"');
        });
    }
    Http::assertNothingSent();
});

it('gives safe fallback guidance and rechecks private identity on every new destination', function (): void {
    $user = User::factory()->create();
    $bot = uxr01Bot($user);
    $bot->hearText('متن نامرتبط')->reply()->assertReplyText('برای ادامه، یکی از دکمه‌های منو را انتخاب کنید.');
    $bot->hearCallbackQueryData('unknown:callback')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:help:invalid')->reply()->assertCalled('editMessageText');
    $user->forceFill(['is_active' => false])->save();
    foreach ([ReplyMenuLabels::SALES_ACCOUNT, ReplyMenuLabels::SALES_SUPPORT] as $label) {
        $bot->hearText($label)->reply()->assertReplyText(
            "🔒 برای استفاده از DenaGate ابتدا حساب خود را متصل کنید.\n\nکد یک‌بارمصرف دریافتی از مدیر را با دستور /link وارد کنید."
        );
    }
    $bot->setCommonChat(Chat::make(-975001, ChatType::GROUP));
    $bot->hearText('/help')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('sales:invite')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('unknown:callback')->reply()->assertNoReply();
    Http::assertNothingSent();
});
