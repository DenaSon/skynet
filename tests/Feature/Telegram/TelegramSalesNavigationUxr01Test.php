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
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
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
    $data = FakeNutgram::getActualData($request);
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

    expect($keyboard->keyboard[0][0]->style)->toBe(ButtonStyle::SUCCESS)
        ->and($keyboard->keyboard[0][1]->style)->toBe(ButtonStyle::PRIMARY)
        ->and($keyboard->keyboard[1][0]->style)->toBeNull()
        ->and($keyboard->keyboard[1][1]->style)->toBeNull();

    $encoded = json_decode(json_encode($keyboard, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($encoded['keyboard'][0][0]['style'])->toBe('success')
        ->and($encoded['keyboard'][0][1]['style'])->toBe('primary');

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
    $bot->hearText('/account')->reply()->assertRaw(static fn (Request $request): bool => str_contains(json_encode(uxr01Payload($request), JSON_THROW_ON_ERROR), 'menu:builder'));
    $user->syncRoles(['member']);
    $bot->hearText('/account')->reply()->assertRaw(static fn (Request $request): bool => ! str_contains(json_encode(uxr01Payload($request), JSON_THROW_ON_ERROR), 'menu:builder'));
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
    foreach (['apps', 'android', 'ios', 'windows', 'macos', 'desktop', 'purchase', 'troubleshoot'] as $page) {
        $bot->hearCallbackQueryData('sales:help:'.$page)->reply()
            ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    }
    foreach ([
        'android' => ['v2rayNG', 'https://github.com/2dust/v2rayNG/releases/latest'],
        'ios' => ['V2Box', 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690'],
        'windows' => ['v2rayN', 'https://github.com/2dust/v2rayN/releases/latest'],
        'macos' => ['V2Box', 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690'],
    ] as $page => [$client, $download]) {
        $bot->hearCallbackQueryData('sales:help:'.$page)->reply()->assertRaw(
            static function (Request $request) use ($client, $download): bool {
                $body = uxr01Payload($request);
                $keyboard = $body['reply_markup']['inline_keyboard'];

                return str_contains($body['text'], '<blockquote>')
                    && str_contains($body['text'], '<b>'.$client.'</b>')
                    && str_contains($body['text'], '<b>لینک اتصال</b>')
                    && $keyboard[0][0]['url'] === $download
                    && ! str_contains($body['text'], 'Remnawave')
                    && ! str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), 'docs.rw')
                    && str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), 'sales:subs:list:0');
            }, index: 1,
        );
    }
    $bot->hearCallbackQueryData('sales:help:apps')->reply()->assertRaw(static function (Request $request): bool {
        $keyboard = uxr01Payload($request)['reply_markup']['inline_keyboard'];
        $encoded = json_encode($keyboard, JSON_THROW_ON_ERROR);

        return str_contains($encoded, 'sales:help:windows')
            && str_contains($encoded, 'sales:help:macos')
            && ! str_contains($encoded, 'sales:help:desktop');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:help:desktop')->reply()->assertRaw(static function (Request $request): bool {
        $encoded = json_encode(uxr01Payload($request)['reply_markup']['inline_keyboard'], JSON_THROW_ON_ERROR);

        return str_contains($encoded, 'sales:help:windows') && str_contains($encoded, 'sales:help:macos');
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

it('keeps Home separate from purchase offers for all Sales home entry callbacks', function (): void {
    $bot = uxr01Bot(User::factory()->create());

    foreach (['menu:home', 'sales:home'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()
            ->assertCalled('answerCallbackQuery')
            ->assertCalled('editMessageText')
            ->assertCalled('sendMessage', 0)
            ->assertRaw(static function (Request $request): bool {
                $data = uxr01Payload($request);
                $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

                return str_contains($data['text'], '🏠 صفحه اصلی DenaGate')
                    && ! str_contains($data['text'], '🛒 پلن‌های آماده خرید')
                    && str_contains($buttons, 'sales:buy')
                    && str_contains($buttons, 'sales:subs:list:0')
                    && str_contains($buttons, 'sales:account')
                    && str_contains($buttons, 'sales:support');
            }, index: 1);
    }

    $bot->hearCallbackQueryData('sales:buy')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = uxr01Payload($request);

            return str_contains($data['text'], '🛒 پلن‌های آماده خرید');
        }, index: 1);
    $bot->hearText('/menu')->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(uxr01Payload($request)['text'], '🏠 صفحه اصلی DenaGate'),
        index: 1,
    );
    Http::assertNothingSent();
});

it('accepts old Sales keyboard labels while using native colored buttons for current navigation', function (): void {
    $bot = uxr01Bot(User::factory()->create());

    $bot->hearText('🛒 خرید سرویس')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return str_contains($body['text'], '🛒 پلن‌های آماده خرید');
    });
    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);
        $buttons = $body['reply_markup']['inline_keyboard'];

        return $buttons[0][0]['style'] === 'success'
            && $buttons[0][1]['style'] === 'primary'
            && $buttons[0][0]['callback_data'] === 'sales:buy';
    }, index: 1);
    Http::assertNothingSent();
});
