<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\PendingBuilderSelection;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Referral\Application\GetReferralInvite;
use App\Settings\SalesWelcomeSettings;
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

/** Decode the actual Rich HTML instead of treating a Rich payload as plain text. */
function uxr01RichHtml(Request $request): string
{
    $data = uxr01Payload($request);
    $rich = is_string($data['rich_message'] ?? null)
        ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($data['rich_message'] ?? []);

    return (string) ($rich['html'] ?? '');
}

it('shows persistent Sales keyboard on start while accepting old button labels', function (): void {
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
            [ReplyMenuLabels::SALES_TUTORIAL],
        ]);

    expect($keyboard->keyboard[0][0]->style)->toBe(ButtonStyle::SUCCESS)
        ->and($keyboard->keyboard[0][1]->style)->toBe(ButtonStyle::PRIMARY)
        ->and($keyboard->keyboard[1][0]->style)->toBeNull()
        ->and($keyboard->keyboard[1][1]->style)->toBeNull();

    $encoded = json_decode(json_encode($keyboard, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($encoded['keyboard'][0][0]['style'])->toBe('success')
        ->and($encoded['keyboard'][0][1]['style'])->toBe('primary');

    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2)
        ->assertRaw(static function (Request $request): bool {
            $body = uxr01Payload($request);

            return ($body['reply_markup']['is_persistent'] ?? false) === true
                && ($body['reply_markup']['keyboard'][0][0]['text'] ?? null) === ReplyMenuLabels::SALES_BUY
                && ($body['reply_markup']['keyboard'][1][0]['text'] ?? null) === ReplyMenuLabels::SALES_WALLET
                && ($body['reply_markup']['keyboard'][1][1]['text'] ?? null) === ReplyMenuLabels::SALES_ACCOUNT
                && ($body['reply_markup']['keyboard'][2][0]['text'] ?? null) === ReplyMenuLabels::SALES_SUPPORT;
        }, index: 0)
        ->assertReply('sendMessage', ['parse_mode' => 'HTML'])
        ->assertReplyText("<b>🏠 DenaGate</b>\n\nخوش آمدید، &lt;Buyer&gt; &amp; &quot;Name&quot;.\nنوع حساب: <b>👤 کاربر عادی</b>");
    $bot->hearText(ReplyMenuLabels::SALES_ACCOUNT)->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return str_contains(uxr01RichHtml($request), '&lt;Buyer&gt; &amp; &quot;Name&quot;')
            && ! str_contains(uxr01RichHtml($request), '<Buyer>');
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
        $reply = $bot->hearText($label)->reply();
        if (in_array($label, [ReplyMenuLabels::SALES_ACCOUNT, ReplyMenuLabels::SALES_SUPPORT], true)) {
            $reply->assertCalled('sendRichMessage')->assertCalled('sendMessage', 0);
        } else {
            $reply->assertCalled('sendMessage', in_array($label, [ReplyMenuLabels::HOME, '/cancel'], true) ? 2 : 1);
        }
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

            $buttons = $body['reply_markup']['inline_keyboard'];
            $share = $buttons[2][0]['url'] ?? '';
            $query = parse_url($share, PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $params);

            return str_contains(uxr01RichHtml($request), $url)
                && str_contains(uxr01RichHtml($request), 'دوستان ثبت‌نام‌شده: 0')
                && str_contains(uxr01RichHtml($request), 'دوستان دارای پاداش: <b>0</b>')
                && str_contains(uxr01RichHtml($request), '<details><summary>📋 شرایط دریافت پاداش</summary>')
                && $buttons[1][0]['copy_text']['text'] === $url
                && parse_url($share, PHP_URL_HOST) === 't.me'
                && parse_url($share, PHP_URL_PATH) === '/share/url'
                && ($params['url'] ?? '') === $url
                && ($params['text'] ?? '') === 'با لینک من به DenaGate بپیوند 👇';
        }, index: 1);
    $bot->hearText('/account')->reply()->assertRaw(static fn (Request $request): bool => str_contains(json_encode(uxr01Payload($request), JSON_THROW_ON_ERROR), 'menu:builder'));
    $user->syncRoles(['member']);
    $bot->hearText('/account')->reply()->assertRaw(static fn (Request $request): bool => ! str_contains(json_encode(uxr01Payload($request), JSON_THROW_ON_ERROR), 'menu:builder'));
    $bot->hearCallbackQueryData('menu:builder')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    Http::assertNothingSent();
});

it('keeps account and invite simple views usable with unchanged owner-safe actions', function (): void {
    $buyer = User::factory()->create();
    $buyer->syncRoles(['member']);
    $bot = uxr01Bot($buyer);

    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $html = uxr01RichHtml($request);
        $rich = is_string($data['rich_message'] ?? null)
            ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
            : ($data['rich_message'] ?? []);

        return ($rich['is_rtl'] ?? false) === true
            && str_contains($html, '📋 سرویس‌های ثبت‌شده')
            && str_contains($html, '<summary>📊 سوابق مالی حساب</summary>')
            && str_contains($html, '<summary>🪪 اطلاعات عضویت</summary>')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:account:simple');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account:simple')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return ! isset($data['rich_message'])
            && str_contains((string) ($data['text'] ?? ''), 'خلاصه حساب')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:subs:list:0');
    }, index: 1);

    $bot->hearCallbackQueryData('sales:invite')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return str_contains(uxr01RichHtml($request), '<summary>📋 شرایط دریافت پاداش</summary>')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:invite:simple');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:invite:simple')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return ! isset($data['rich_message'])
            && str_contains((string) ($data['text'] ?? ''), 'لینک اختصاصی شما')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'copy_text');
    }, index: 1);
    Http::assertNothingSent();
});

it('does not invent a referral share destination when Sales username is not valid', function (): void {
    $bot = uxr01Bot(User::factory()->create());
    foreach (['', '@bad-bot', 'https://t.me/fake', '12345', '<script>'] as $username) {
        config()->set('telegram.sales.bot_username', $username);
        $bot->hearCallbackQueryData('sales:invite')->reply()->assertRaw(
            static function (Request $request): bool {
                $data = uxr01Payload($request);
                $markup = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

                return str_contains($data['text'], 'لینک دعوت فعلاً در دسترس نیست')
                    && ! str_contains($markup, 'share/url')
                    && ! str_contains($markup, 'copy_text');
            }, index: 1,
        );
    }
    expect(DB::table('referrals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('places My Credit on the second row for ordinary Sales members and accepts stale wallet labels', function (): void {
    $user = User::factory()->create();
    $user->assignRole('member');
    $bot = uxr01Bot($user);

    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $rows = $data['reply_markup']['keyboard'] ?? [];

        return ($data['reply_markup']['is_persistent'] ?? false) === true
            && ($rows[0][0]['text'] ?? null) === ReplyMenuLabels::SALES_BUY
            && ($rows[0][1]['text'] ?? null) === ReplyMenuLabels::SALES_SUBSCRIPTIONS
            && ($rows[1][0]['text'] ?? null) === ReplyMenuLabels::SALES_WALLET
            && ($rows[1][1]['text'] ?? null) === ReplyMenuLabels::SALES_ACCOUNT
            && ($rows[2][0]['text'] ?? null) === ReplyMenuLabels::SALES_SUPPORT
            && ($rows[2][1]['text'] ?? null) === ReplyMenuLabels::SALES_TUTORIAL
            && ($rows[3][0]['text'] ?? null) === ReplyMenuLabels::SALES_PANEL_REQUEST;
    }, index: 0)->assertRaw(static function (Request $request): bool {
        $keyboard = uxr01Payload($request)['reply_markup']['inline_keyboard'] ?? [];

        return ($keyboard[1][0]['text'] ?? null) === ReplyMenuLabels::SALES_WALLET
            && ($keyboard[1][0]['callback_data'] ?? null) === 'sales:wallet';
    }, index: 1);

    $bot->hearText(ReplyMenuLabels::SALES_WALLET)->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return str_contains(uxr01RichHtml($request), '<h4>💰 اعتبار من</h4>')
            && str_contains(uxr01RichHtml($request), 'اعتبار قابل استفاده: <b>0 تومان</b>')
            && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:wallet:simple');
    });
    $bot->hearText('💰 کیف پول')->reply()->assertCalled('sendRichMessage');
    $bot->hearCallbackQueryData('sales:wallet:simple')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return ! isset($data['rich_message'])
            && str_contains((string) ($data['text'] ?? ''), 'اعتبار قابل استفاده: <b>0 تومان</b>');
    }, index: 1);
    $bot->hearText(ReplyMenuLabels::WALLET)->reply()->assertCalled('sendRichMessage');

    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $keyboard = uxr01Payload($request)['reply_markup']['inline_keyboard'] ?? [];

        return ($keyboard[3][0]['text'] ?? null) === ReplyMenuLabels::SALES_WALLET
            && ($keyboard[3][0]['callback_data'] ?? null) === 'sales:wallet';
    }, index: 1);

    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
    Http::assertNothingSent();
});

it('shows an ordinary active member their own zero wallet balance without a top-up write', function (): void {
    $user = User::factory()->create();
    $user->assignRole('member');
    $bot = uxr01Bot($user);

    $bot->hearText('/account')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains(uxr01RichHtml($request), 'اعتبار من: <b>0 تومان</b>')
            && str_contains($buttons, 'sales:wallet')
            && ! str_contains($buttons, 'menu:builder');
    });
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return str_contains(uxr01RichHtml($request), '<h4>💰 اعتبار من</h4>')
            && str_contains(uxr01RichHtml($request), 'اعتبار قابل استفاده: <b>0 تومان</b>')
            && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:account');
    }, index: 1);

    expect(DB::table('billing_wallets')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
    Http::assertNothingSent();
});

it('offers a real configured Telegram contact and short guides with working back navigation', function (): void {
    $user = User::factory()->create();
    $bot = uxr01Bot($user);
    config()->set('telegram.sales.support_username', '@DenaGateOperator');
    $bot->hearText(ReplyMenuLabels::SALES_SUPPORT)->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        $buttons = array_merge(...$body['reply_markup']['inline_keyboard']);

        return str_contains(uxr01RichHtml($request), 'ارتباط با پشتیبان')
            && in_array('https://t.me/DenaGateOperator', array_column($buttons, 'url'), true);
    });
    foreach (['apps', 'android', 'ios', 'windows', 'macos', 'purchase', 'troubleshoot'] as $page) {
        $bot->hearCallbackQueryData('sales:help:'.$page)->reply()
            ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText')->assertCalled('sendMessage', 0)
            ->assertRaw(static function (Request $request) use ($page): bool {
                $data = uxr01Payload($request);
                $rich = is_string($data['rich_message'] ?? null)
                    ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                    : ($data['rich_message'] ?? []);

                return ($rich['is_rtl'] ?? false) === true
                    && str_contains(uxr01RichHtml($request), '<details>')
                    && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:simple:'.$page);
            }, index: 1);
    }
    // Old combined-desktop callback remains plain and navigates to each OS.
    $bot->hearCallbackQueryData('sales:help:desktop')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = uxr01Payload($request);
            $encoded = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return ! isset($data['rich_message'])
                && str_contains($encoded, 'sales:help:windows')
                && str_contains($encoded, 'sales:help:macos');
        }, index: 1);

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
                $html = uxr01RichHtml($request);

                return str_contains($html, '<summary>📱 مراحل نصب و اتصال</summary>')
                    && str_contains($html, '<b>'.$client.'</b>')
                    && str_contains($html, '<b>لینک اتصال</b>')
                    && $keyboard[0][0]['url'] === $download
                    && ! str_contains($html, 'Remnawave')
                    && ! str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), 'docs.rw')
                    && str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), 'sales:subs:list:0');
            }, index: 1,
        );
    }
    $bot->hearCallbackQueryData('sales:help:apps')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $encoded = json_encode($data['reply_markup']['inline_keyboard'], JSON_THROW_ON_ERROR);
        $html = uxr01RichHtml($request);

        return str_contains($encoded, 'sales:help:windows')
            && str_contains($encoded, 'sales:help:macos')
            && str_contains($html, '<summary>🪟 ویندوز</summary>')
            && ! str_contains($encoded, 'sales:help:desktop');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:help:simple:ios')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);

        return ! isset($data['rich_message'])
            && str_contains((string) ($data['text'] ?? ''), '<b>V2Box</b>')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:apps');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:support')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);
        $buttons = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);
        $html = uxr01RichHtml($request);

        return str_contains($html, '<h4>💬 پشتیبانی دناگیت</h4>')
            && str_contains($html, '<summary>🔧 مشکل اتصال دارید؟</summary>')
            && str_contains($buttons, 'sales:help:troubleshoot')
            && str_contains($buttons, 'sales:subs:list:0')
            && str_contains($buttons, 'sales:help:simple:support');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:support:simple')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);
        $buttons = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return ! isset($body['rich_message'])
            && str_contains((string) ($body['text'] ?? ''), 'پشتیبانی')
            && str_contains($buttons, 'sales:help:apps');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertNothingSent();
});

it('never fabricates an operator link when configuration is missing or invalid', function (): void {
    $bot = uxr01Bot(User::factory()->create());
    foreach ([null, '', 'https://example.test/operator', 'bad name', '<invalid>'] as $username) {
        config()->set('telegram.sales.support_username', $username);
        $bot->hearText('/help')->reply()->assertRaw(static function (Request $request): bool {
            $body = uxr01Payload($request);

            return str_contains(uxr01RichHtml($request), 'ارتباط مستقیم فعلاً در دسترس نیست')
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
            "<b>⛔ حساب غیرفعال</b>\n\nحساب شما غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید."
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

it('accepts old Sales keyboard labels and the new /buy command with existing authorization', function (): void {
    $bot = uxr01Bot(User::factory()->create());

    $bot->hearText('🛒 خرید سرویس')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return str_contains($body['text'], '🛒 پلن‌های آماده خرید');
    });
    $bot->hearText('/buy')->reply()->assertRaw(static function (Request $request): bool {
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

it('prioritizes reseller credit and reports on row two while preserving custom plans and navigation', function (): void {
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr01Bot($buyer);
    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $keyboard = $data['reply_markup']['keyboard'] ?? [];

        return ($data['reply_markup']['is_persistent'] ?? false) === true
            && ($keyboard[0][0]['text'] ?? null) === ReplyMenuLabels::SALES_BUY
            && ($keyboard[0][1]['text'] ?? null) === ReplyMenuLabels::SALES_SUBSCRIPTIONS
            && ($keyboard[1][0]['text'] ?? null) === ReplyMenuLabels::SALES_WALLET
            && ($keyboard[1][1]['text'] ?? null) === ReplyMenuLabels::SALES_REPORTS
            && ($keyboard[2][0]['text'] ?? null) === ReplyMenuLabels::BUILDER
            && ($keyboard[2][1]['text'] ?? null) === ReplyMenuLabels::SALES_SUPPORT
            && ($keyboard[3][0]['text'] ?? null) === ReplyMenuLabels::SALES_ACCOUNT
            && ($keyboard[3][1]['text'] ?? null) === ReplyMenuLabels::SALES_TUTORIAL;
    }, index: 0);
    $bot->hearText(ReplyMenuLabels::SALES_WALLET)->reply()->assertCalled('sendRichMessage');
    $bot->hearText(ReplyMenuLabels::WALLET)->reply()->assertCalled('sendRichMessage');
    $bot->hearText(ReplyMenuLabels::BUILDER)->reply()->assertCalled('sendMessage');
});

it('shows member panel setup and reseller builder Rich guides without role escalation', function (): void {
    Role::findOrCreate('member', 'web');
    $member = User::factory()->create();
    $member->syncRoles(['member']);
    $bot = uxr01Bot($member);

    $bot->hearCallbackQueryData('sales:help:apps')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $keyboard = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains(uxr01RichHtml($request), 'راه‌اندازی پنل فروش')
            && str_contains($keyboard, 'sales:help:panel-setup')
            && ! str_contains($keyboard, 'sales:help:builder');
    }, index: 1);

    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()->assertRaw(static function (Request $request): bool {
        $html = uxr01RichHtml($request);
        $keyboard = json_encode(uxr01Payload($request)['reply_markup'], JSON_THROW_ON_ERROR);

        return substr_count($html, '<details>') === 4
            && str_contains($html, '۳۵۰٬۰۰۰ تومان')
            && str_contains($html, '۳۵٪')
            && str_contains($html, 'هزینه راه‌اندازی نیست')
            && str_contains($keyboard, 'sales:wallet:topup')
            && str_contains($keyboard, 'sales:panel:request');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:help:builder')->reply()->assertCalled('editMessageText')
        ->assertRaw(static fn (Request $request): bool => ! isset(uxr01Payload($request)['rich_message']), index: 1);

    // An actual role change invalidates old member-only guide callbacks.
    $member->syncRoles(['reseller']);
    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()
        ->assertRaw(static fn (Request $request): bool => ! isset(uxr01Payload($request)['rich_message']), index: 1);
    $bot->hearCallbackQueryData('sales:help:simple:panel-setup')->reply()
        ->assertRaw(static fn (Request $request): bool => ! isset(uxr01Payload($request)['rich_message']), index: 1);

    $bot->hearCallbackQueryData('sales:help:apps')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr01Payload($request);
        $keyboard = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains(uxr01RichHtml($request), 'راهنمای پلن‌ساز')
            && str_contains($keyboard, 'sales:help:builder')
            && ! str_contains($keyboard, 'sales:help:panel-setup');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:help:builder')->reply()->assertRaw(static function (Request $request): bool {
        $html = uxr01RichHtml($request);
        $keyboard = json_encode(uxr01Payload($request)['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($html, 'مراحل ساخت پلن')
            && str_contains($keyboard, 'menu:builder')
            && str_contains($keyboard, 'sales:help:apps');
    }, index: 1);
    Http::assertNothingSent();
});

it('shows saved member and reseller welcome text in the interactive Home without duplicate welcome messages', function (): void {
    $settings = app(SalesWelcomeSettings::class);
    $settings->member = '👋 سلام {name}؛ به دناگیت خوش آمدی <member>';
    $settings->reseller = '🧩 خوش آمدی نماینده {name} <reseller>';
    $settings->save();

    $user = User::factory()->create(['name' => '<Buyer>']);
    $bot = uxr01Bot($user);

    $bot->hearText('/start')->reply()
        ->assertCalled('sendMessage', 2)
        ->assertRaw(static function (Request $request): bool {
            $body = uxr01Payload($request);

            return ($body['text'] ?? null) === '⌨️ منوی دناگیت آماده است.'
                && ($body['reply_markup']['is_persistent'] ?? false) === true;
        }, index: 0)
        ->assertRaw(static function (Request $request): bool {
            $body = uxr01Payload($request);
            $text = (string) ($body['text'] ?? '');

            return str_contains($text, '👋 سلام &lt;Buyer&gt;؛ به دناگیت خوش آمدی &lt;member&gt;')
                && str_contains($text, '🏠 صفحه اصلی DenaGate')
                && str_contains($text, 'نوع حساب: <b>👤 کاربر عادی</b>')
                && str_contains($text, 'اعتبار من:')
                && str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'sales:buy')
                && ! str_contains($text, '<member>');
        }, index: 1);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);

        return str_contains((string) ($body['text'] ?? ''), 'به دناگیت خوش آمدی &lt;member&gt;');
    }, index: 1);

    $user->syncRoles(['reseller']);

    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr01Payload($request);
        $text = (string) ($body['text'] ?? '');

        return str_contains($text, '🧩 خوش آمدی نماینده &lt;Buyer&gt; &lt;reseller&gt;')
            && str_contains($text, 'نوع حساب: <b>🧩 پنل فروش</b>')
            && str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'menu:builder')
            && ! str_contains($text, 'به دناگیت خوش آمدی');
    }, index: 1);

    Http::assertNothingSent();
});
