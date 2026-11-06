<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminManagementKpiUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminManagementKpis;
use App\Modules\Sales\Application\ReadAdminSalesChannelKpis;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 15:30:00', 'Asia/Tehran')->utc());
    config()->set('super_admin.telegram_ids', ['991901', '991902']);
    Http::preventStrayRequests();
});

function kpiUiUser(string $localCreatedAt): User
{
    return User::factory()->create([
        'created_at' => Carbon::parse($localCreatedAt, 'Asia/Tehran')->utc(),
    ]);
}

function kpiUiRoot(): User
{
    $root = kpiUiUser('2026-09-01 12:00:00');
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 991901]);

    return $root;
}

function kpiUiBot(int $telegramId = 991901): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function kpiUiPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);
    }

    return $data;
}

/** @return array<string,mixed> */
function kpiUiRich(NutgramRequest $request): array
{
    $payload = kpiUiPayload($request);
    $rich = $payload['rich_message'] ?? [];

    return is_string($rich) ? json_decode($rich, true, 512, JSON_THROW_ON_ERROR) : $rich;
}

function kpiUiSettlement(User $buyer, string $method, string $localTime, int $amount, ?string $category = null): void
{
    $order = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => $category,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'kpi-ui',
        'product_name' => 'KPI test plan',
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => $amount, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => $amount,
        'status' => 'fulfilled', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $settledAt = Carbon::parse($localTime, 'Asia/Tehran')->utc();
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => 'kpi-ui-journal-'.$order,
        'created_at' => now(),
    ]);

    if ($method === 'bank') {
        $claim = (int) DB::table('sales_bank_payment_claims')->insertGetId([
            'sales_order_id' => $order, 'buyer_user_id' => $buyer->id,
            'photo_encrypted' => 'fixture-only',
            'photo_fingerprint' => hash('sha256', 'kpi-ui-claim-'.$order),
            'telegram_message_key' => 'kpi-ui-claim-'.$order,
            'status' => 'approved', 'reviewed_by' => $buyer->id,
            'reviewed_at' => $settledAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_bank_settlements')->insert([
            'sales_order_id' => $order, 'payment_claim_id' => $claim,
            'billing_journal_id' => $journal, 'verified_by' => $buyer->id,
            'payment_reference' => 'KPIUIBANK'.$order,
            'amount_rials' => $amount, 'settled_at' => $settledAt,
        ]);
    } else {
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $order, 'billing_journal_id' => $journal,
            'method' => 'wallet', 'amount_rials' => $amount, 'settled_at' => $settledAt,
        ]);
    }
}

it('adds a root-only KPI entry under Denaban without replacing existing stats or actions', function (): void {
    kpiUiRoot();
    $ordinary = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $ordinary->id, 'telegram_user_id' => 991903]);
    Http::fake();

    kpiUiBot()->hearCallbackQueryData('menu:admin')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $buttons = kpiUiPayload($request)['reply_markup']['inline_keyboard'];

            return ($buttons[0][0]['callback_data'] ?? null) === 'kpi:period:day'
                && ($buttons[0][0]['text'] ?? null) === '🧭 داشبورد'
                && ($buttons[0][1]['callback_data'] ?? null) === 'stats:overview'
                && ($buttons[2][0]['callback_data'] ?? null) === 'denaban:show'
                && ($buttons[2][1]['callback_data'] ?? null) === 'menu:admin:control'
                && str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'stats:overview')
                && str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'menu:admin:finance');
        }, index: 1);

    kpiUiBot(991903)->hearCallbackQueryData('menu:admin')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $buttons = json_encode(kpiUiPayload($request)['reply_markup'] ?? null, JSON_THROW_ON_ERROR);

            return ! str_contains($buttons, 'kpi:period:day');
        }, index: 1);
    Http::assertNothingSent();
});

it('renders accurate RTL Rich daily comparison with two tables, live filters and a Tehran footer', function (): void {
    kpiUiRoot();
    $buyer = kpiUiUser('2026-09-01 12:00:00');
    kpiUiUser('2026-11-04 10:00:00');
    kpiUiUser('2026-11-05 10:00:00');

    kpiUiSettlement($buyer, 'bank', '2026-11-04 12:00:00', 1_000_000);
    kpiUiSettlement($buyer, 'bank', '2026-11-05 10:00:00', 2_000_000);
    kpiUiSettlement($buyer, 'wallet', '2026-11-05 12:00:00', 3_000_000);
    Http::fake();

    kpiUiBot()->hearCallbackQueryData('kpi:period:day')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = kpiUiPayload($request);
            $rich = kpiUiRich($request);
            $html = (string) ($rich['html'] ?? '');
            $buttons = $payload['reply_markup']['inline_keyboard'];

            return ($rich['is_rtl'] ?? null) === true
                && str_contains($html, '<h2>🧭 داشبورد · روزانه</h2>')
                && substr_count($html, '<table bordered striped compact>') === 4
                && str_contains($html, '<h3>💰 عملکرد فروش</h3>')
                && str_contains($html, '<h3>👥 ثبت‌نام کاربران</h3>')
                && str_contains($html, '<h3>🏷️ کانال‌های فروش</h3>')
                && str_contains($html, '<details><summary>🏆 تمرکز فروش</summary>')
                && str_contains($html, '۵۰۰٬۰۰۰ تومان')
                && str_contains($html, '۱۰۰٬۰۰۰ تومان')
                && str_contains($html, '+۴۰۰٫۰٪')
                && str_contains($html, 'خریداران یکتا</td><td>اخیر: <b>۱</b><br>قبل: ۱<br>تغییر: ۰٫۰٪')
                && str_contains($html, 'تعداد سفارش‌ها</td><td>اخیر: <b>۲</b><br>قبل: ۱<br>تغییر: +۱۰۰٫۰٪')
                && str_contains($html, 'میانگین مبلغ سفارش</td><td>اخیر: <b>۲۵۰٬۰۰۰ تومان</b><br>قبل: ۱۰۰٬۰۰۰ تومان<br>تغییر: +۱۵۰٫۰٪')
                && str_contains($html, 'خرید غیرتمدیدی</td><td>اخیر: <b>۲</b><br>قبل: ۱<br>تغییر: +۱۰۰٫۰٪')
                && str_contains($html, 'تمدید</td><td>اخیر: <b>۰</b><br>قبل: ۰<br>تغییر: بدون مبنا')
                && str_contains($html, 'کاربران جدید · بازه اخیر</td><td><b>۱</b>')
                && str_contains($html, 'کاربران جدید · بازه قبل</td><td><b>۱</b>')
                && str_contains($html, '۱۴۰۵/')
                && str_contains($html, '۰۰:۰۰ تا ۱۵:۳۰')
                && str_contains($html, '<p><b>🧭 جمع‌بندی</b> ')
                && str_contains($html, '<details><summary>🧠 تحلیل عملکرد دوره</summary>')
                && str_contains($html, '<b>🧾 سفارش‌ها</b>')
                && str_contains($html, '<b>🏷️ کانال‌ها</b>')
                && str_contains($html, '<b>🏆 تمرکز فروش</b>')
                && str_contains($html, '۴۰۰٬۰۰۰ تومان افزایش (+۴۰۰٫۰٪)')
                && str_contains($html, 'فروش افزایش یافته و ثبت‌نام ثابت مانده')
                && str_contains($html, '<b>💡 نکته قابل بررسی</b>')
                && strpos($html, '</details>') < strpos($html, '<footer>')
                && str_contains($html, '<footer>🕒 ')
                && str_contains($html, 'تهران')
                && ($buttons[0][0]['callback_data'] ?? null) === 'kpi:period:day'
                && ($buttons[0][0]['text'] ?? null) === '✅ روزانه'
                && ($buttons[0][1]['callback_data'] ?? null) === 'kpi:period:week'
                && ($buttons[0][2]['callback_data'] ?? null) === 'kpi:period:month'
                && ($buttons[1][0]['callback_data'] ?? null) === 'kpi:period:day'
                && ($buttons[2][0]['callback_data'] ?? null) === 'menu:admin'
                && ! isset($payload['parse_mode']);
        }, index: 1);
    Http::assertNothingSent();

    // The native fallback is built from the same authorized Action result.
    $data = app(ReadAdminManagementKpis::class)->handle(
        User::query()->whereHas('telegramAccount', static fn ($query) => $query->where('telegram_user_id', 991901))->firstOrFail()->id,
        'day',
    );
    $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))
        ->invoke(app(AdminManagementKpiUi::class), $data);
    expect($plain)->toContain('۵۰۰٬۰۰۰ تومان')
        ->toContain('۱۰۰٬۰۰۰ تومان')
        ->toContain('+۴۰۰٫۰٪')
        ->toContain("<b>میانگین مبلغ سفارش</b>\nاخیر: ۲۵۰٬۰۰۰ تومان · قبل: ۱۰۰٬۰۰۰ تومان\nتغییر: +۱۵۰٫۰٪")
        ->toContain("<b>تعداد سفارش‌ها</b>\nاخیر: ۲ · قبل: ۱\nتغییر: +۱۰۰٫۰٪")
        ->toContain('کاربران جدید · بازه اخیر: ۱')
        ->toContain('۱۴۰۵/')
        ->toContain('🧭 جمع‌بندی')
        ->toContain('🧠 تحلیل عملکرد دوره')
        ->toContain('۴۰۰٬۰۰۰ تومان افزایش (+۴۰۰٫۰٪)')
        ->toContain('💡 نکته قابل بررسی')
        ->not->toContain('<details>')
        ->toContain('تهران');
});

it('compares every sales metric in rich and native output without querying or rewriting the snapshot', function (): void {
    $data = [
        'period' => 'week', 'generatedAt' => '2026-11-05 15:30',
        'current' => [
            'from' => '2026-10-29 00:00:00', 'until' => '2026-11-05 00:00:00',
            'revenueRials' => 12_000_000, 'transactions' => 15, 'uniqueBuyers' => 8,
            'newPurchases' => 12, 'renewals' => 3, 'newUsers' => 10,
        ],
        'previous' => [
            'from' => '2026-10-22 00:00:00', 'until' => '2026-10-29 00:00:00',
            'revenueRials' => 10_000_000, 'transactions' => 10, 'uniqueBuyers' => 10,
            'newPurchases' => 8, 'renewals' => 2, 'newUsers' => 10,
        ],
        'growth' => ['salesPercent' => 20.0, 'newUsersPercent' => 0.0],
    ];
    $snapshot = $data;
    $ui = app(AdminManagementKpiUi::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))->invoke($ui, $data);
        $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))->invoke($ui, $data);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }

    foreach ([
        ['فروش تسویه‌شده', '۱٬۲۰۰٬۰۰۰ تومان', '۱٬۰۰۰٬۰۰۰ تومان', '+۲۰٫۰٪'],
        ['میانگین مبلغ سفارش', '۸۰٬۰۰۰ تومان', '۱۰۰٬۰۰۰ تومان', '−۲۰٫۰٪'],
        ['تعداد سفارش‌ها', '۱۵', '۱۰', '+۵۰٫۰٪'],
        ['خریداران یکتا', '۸', '۱۰', '−۲۰٫۰٪'],
        ['خرید غیرتمدیدی', '۱۲', '۸', '+۵۰٫۰٪'],
        ['تمدید', '۳', '۲', '+۵۰٫۰٪'],
    ] as [$label, $current, $previous, $change]) {
        expect($rich)->toContain('<td>'.$label.'</td><td>اخیر: <b>'.$current
            .'</b><br>قبل: '.$previous.'<br>تغییر: '.$change.'</td>')
            ->and($plain)->toContain('<b>'.$label."</b>\nاخیر: ".$current
                .' · قبل: '.$previous."\nتغییر: ".$change);
    }
    expect($data)->toBe($snapshot)
        ->and($plain)->not->toContain('<table', '<br>', '<details>')
        ->and(mb_strlen(strip_tags($plain)))->toBeLessThanOrEqual(4096);
    Http::assertNothingSent();
});

it('falls back after a rich transport error with the same comparisons and native buttons', function (): void {
    kpiUiRoot();
    $buyer = kpiUiUser('2026-09-01 12:00:00');
    kpiUiSettlement($buyer, 'bank', '2026-11-04 12:00:00', 1_000_000, 'direct');
    kpiUiSettlement($buyer, 'wallet', '2026-11-05 12:00:00', 2_000_000, 'reseller');
    $keyboard = null;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(991901, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(991901, false, 'Admin'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(function (...$arguments) use (&$keyboard): void {
        foreach ($arguments as $argument) {
            if ($argument instanceof InputRichMessage) {
                expect($argument->html)->toContain('<b>🧭 جمع‌بندی</b> ')
                    ->toContain('میانگین مبلغ سفارش</td><td>اخیر: <b>۲۰۰٬۰۰۰ تومان</b>')
                    ->toContain('فروش نمایندگی</td><td>اخیر: <b>۲۰۰٬۰۰۰ تومان</b>')
                    ->toContain('سهم فروش نمایندگی</td><td>اخیر: <b>۱۰۰٫۰٪</b>');
            }
            if ($argument instanceof InlineKeyboardMarkup) {
                $keyboard = $argument;
            }
        }
        throw new TelegramException('Bad Request: rich messages unavailable', 400);
    });
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function (...$arguments) use (&$keyboard): null {
        $text = $arguments[0];
        expect($text)->toContain('<b>🧭 جمع‌بندی</b>')
            ->toContain('<b>🧾 سفارش‌ها</b>')
            ->toContain('<b>🏷️ کانال‌ها</b>')
            ->toContain('<b>🏆 تمرکز فروش</b>')
            ->toContain("<b>میانگین مبلغ سفارش</b>\nاخیر: ۲۰۰٬۰۰۰ تومان · قبل: ۱۰۰٬۰۰۰ تومان\nتغییر: +۱۰۰٫۰٪")
            ->toContain("<b>فروش نمایندگی</b>\nاخیر: ۲۰۰٬۰۰۰ تومان · قبل: ۰ تومان\nتغییر: بدون مبنا")
            ->toContain("<b>سهم فروش نمایندگی</b>\nاخیر: ۱۰۰٫۰٪ · قبل: ۰٫۰٪\nتغییر: +۱۰۰٫۰ واحد درصد")
            ->not->toContain('<table', '<br>', '<details>');
        expect($keyboard)->toBeInstanceOf(InlineKeyboardMarkup::class)
            ->and(in_array($keyboard, $arguments, true))->toBeTrue()
            ->and(in_array('HTML', $arguments, true))->toBeTrue()
            ->and(json_encode($keyboard, JSON_THROW_ON_ERROR))->toContain('kpi:period:day', 'kpi:period:week', 'kpi:period:month', 'menu:admin');

        return null;
    });

    app(AdminManagementKpiUi::class)->show($bot);
    Http::assertNothingSent();
});

it('distinguishes missing averages from zero and compares before display rounding', function (
    int $currentRevenue, int $currentCount, int $previousRevenue, int $previousCount,
    string $currentAverage, string $previousAverage, string $averageChange, string $countChange,
): void {
    $data = [
        'period' => 'day', 'generatedAt' => '2026-11-05 15:30',
        'current' => [
            'from' => '2026-11-05 00:00:00', 'until' => '2026-11-05 15:30:00',
            'revenueRials' => $currentRevenue, 'transactions' => $currentCount,
            'uniqueBuyers' => $currentCount, 'newPurchases' => $currentCount,
            'renewals' => 0, 'newUsers' => 0,
        ],
        'previous' => [
            'from' => '2026-11-04 00:00:00', 'until' => '2026-11-04 15:30:00',
            'revenueRials' => $previousRevenue, 'transactions' => $previousCount,
            'uniqueBuyers' => $previousCount, 'newPurchases' => $previousCount,
            'renewals' => 0, 'newUsers' => 0,
        ],
        'growth' => [
            'salesPercent' => $previousRevenue === 0 ? null
                : round(($currentRevenue - $previousRevenue) / $previousRevenue * 100, 1),
            'newUsersPercent' => null,
        ],
    ];
    $ui = app(AdminManagementKpiUi::class);
    $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))->invoke($ui, $data);
    $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))->invoke($ui, $data);

    expect($rich)->toContain('میانگین مبلغ سفارش</td><td>اخیر: <b>'.$currentAverage
        .'</b><br>قبل: '.$previousAverage.'<br>تغییر: '.$averageChange.'</td>')
        ->and($plain)->toContain("<b>میانگین مبلغ سفارش</b>\nاخیر: ".$currentAverage
            .' · قبل: '.$previousAverage."\nتغییر: ".$averageChange)
        ->toContain("\nتغییر: ".$countChange)
        ->and($rich.$plain)->not->toContain('NaN', 'INF', '∞');
})->with([
    'both empty' => [0, 0, 0, 0, 'بدون سفارش', 'بدون سفارش', 'قابل محاسبه نیست', 'بدون مبنا'],
    'new sales' => [1_000_000, 2, 0, 0, '۵۰٬۰۰۰ تومان', 'بدون سفارش', 'قابل محاسبه نیست', 'بدون مبنا'],
    'no current orders' => [0, 0, 1_000_000, 2, 'بدون سفارش', '۵۰٬۰۰۰ تومان', 'قابل محاسبه نیست', '−۱۰۰٫۰٪'],
    'real zero average' => [0, 2, 1_000_000, 2, '۰ تومان', '۵۰٬۰۰۰ تومان', '−۱۰۰٫۰٪', '۰٫۰٪'],
    'zero average baseline' => [1_000_000, 2, 0, 2, '۵۰٬۰۰۰ تومان', '۰ تومان', 'بدون مبنا', '۰٫۰٪'],
    'unrounded percentage' => [100, 3, 100, 6, '۳ تومان', '۲ تومان', '+۱۰۰٫۰٪', '−۵۰٫۰٪'],
    'whole toman rounding' => [1_000_010, 3, 2_000_020, 6, '۳۳٬۳۳۴ تومان', '۳۳٬۳۳۴ تومان', '۰٫۰٪', '−۵۰٫۰٪'],
]);

it('switches period in place and clearly differentiates complete weeks from complete Persian months', function (): void {
    kpiUiRoot();
    Http::fake();
    kpiUiBot()->hearCallbackQueryData('kpi:period:week')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $p = kpiUiPayload($request);
            $rich = kpiUiRich($request);
            $html = (string) ($rich['html'] ?? '');

            expect($html)->toContain(
                'داشبورد · هفتگی',
                '۷ روز کامل گذشته',
                'تا ۱۴۰۵/',
                'بدون مبنا',
                '<details><summary>🧠 تحلیل عملکرد دوره</summary>',
                'فروش بازه قبل صفر بوده',
            )->and($p['reply_markup']['inline_keyboard'][0][1]['text'] ?? null)->toBe('✅ هفتگی')
                ->and($p['reply_markup']['inline_keyboard'][0][2]['callback_data'] ?? null)->toBe('kpi:period:month');

            return true;
        }, index: 1);

    kpiUiBot()->hearCallbackQueryData('kpi:period:month')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $p = kpiUiPayload($request);
            $rich = kpiUiRich($request);
            $html = (string) ($rich['html'] ?? '');

            expect($html)->toContain(
                'داشبورد · ماهانه',
                'ماه شمسی کامل گذشته',
                'بدون مبنا',
                '<details><summary>🧠 تحلیل عملکرد دوره</summary>',
                'ثبت‌نام کاربران از ۱ به ۰ نفر',
                '۱ نفر کاهش (−۱۰۰٫۰٪)',
                '<table bordered striped compact>',
            )->not->toContain('بازۀ')
                ->and($p['reply_markup']['inline_keyboard'][0][2]['text'] ?? null)->toBe('✅ ماهانه');

            return true;
        }, index: 1);
    Http::assertNothingSent();
});

it('shows undefined percentages without division by zero and escapes invalid period requests', function (): void {
    kpiUiRoot();
    $buyer = kpiUiUser('2026-09-01 12:00:00');
    kpiUiSettlement($buyer, 'bank', '2026-11-05 09:00:00', 300_000);
    Http::fake();

    kpiUiBot()->hearCallbackQueryData('kpi:period:day')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = (string) (kpiUiRich($request)['html'] ?? '');

            return str_contains($html, 'بدون مبنا')
                && str_contains($html, '۳۰٬۰۰۰ تومان')
                && ! str_contains($html, '∞')
                && ! str_contains($html, 'NaN');
        }, index: 1);

    kpiUiBot()->hearCallbackQueryData('kpi:period:year')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = kpiUiPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return ($payload['parse_mode'] ?? null) === 'HTML'
                && str_contains($payload['text'], 'بازه گزارش معتبر نیست')
                && ! str_contains($payload['text'], '۳۰٬۰۰۰ تومان')
                && str_contains($buttons, 'kpi:period:day');
        }, index: 1);
    Http::assertNothingSent();
});

it('denies non-roots, revoked roots and group callbacks without reading customer financials', function (): void {
    $root = kpiUiRoot();
    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 991903]);
    Http::fake();

    kpiUiBot(991903)->hearCallbackQueryData('kpi:period:day')->reply()
        ->assertCalled('answerCallbackQuery');
    $group = kpiUiBot();
    $group->setCommonChat(Chat::make(-991901, ChatType::GROUP));
    $group->hearCallbackQueryData('kpi:period:week')->reply()->assertNoReply();

    $root->syncRoles(['member']);
    kpiUiBot()->hearCallbackQueryData('kpi:period:month')->reply()
        ->assertCalled('answerCallbackQuery');

    Http::assertNothingSent();
});

it('narrates verified weekly sales growth with lower signups without inventing a cause', function (): void {
    $data = [
        'period' => 'week',
        'generatedAt' => '2026-11-05 15:30',
        'current' => [
            'from' => '2026-10-29 00:00:00', 'until' => '2026-11-05 00:00:00',
            'revenueRials' => 87_835_000, 'transactions' => 54,
            'uniqueBuyers' => 31, 'newPurchases' => 52, 'renewals' => 2,
            'newUsers' => 207,
        ],
        'previous' => [
            'from' => '2026-10-22 00:00:00', 'until' => '2026-10-29 00:00:00',
            'revenueRials' => 43_197_500, 'transactions' => 23,
            'uniqueBuyers' => 19, 'newPurchases' => 22, 'renewals' => 1,
            'newUsers' => 248,
        ],
        'growth' => ['salesPercent' => 103.3, 'newUsersPercent' => -16.5],
    ];
    $ui = app(AdminManagementKpiUi::class);
    $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))->invoke($ui, $data);
    $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))->invoke($ui, $data);

    foreach ([$rich, $plain] as $report) {
        expect($report)->toContain('۱۴۰۵/۰۸/۰۷ تا ۱۴۰۵/۰۸/۱۳')
            ->toContain('۸٬۷۸۳٬۵۰۰ تومان')
            ->toContain('۴٬۴۶۳٬۷۵۰ تومان افزایش (+۱۰۳٫۳٪)')
            ->toContain('ثبت‌نام کاربران از ۲۴۸ به ۲۰۷ نفر')
            ->toContain('۴۱ نفر کاهش (−۱۶٫۵٪)')
            ->toContain('با وجود کاهش ثبت‌نام‌ها، فروش افزایش یافته است')
            ->toContain('تعداد تراکنش‌ها و میانگین مبلغ سفارش');
    }
    expect($rich)->toContain('<details><summary>🧠 تحلیل عملکرد دوره</summary>')
        ->toContain('</details>')
        ->and($plain)->not->toContain('<details>')
        ->not->toContain('<summary>')
        ->and($rich)->not->toContain('علت افزایش فروش، مشتریان قدیمی');
});

it('handles falling sales, stable trends and zero comparison baselines without spurious percentages', function (): void {
    $reader = new ReflectionMethod(AdminManagementKpiUi::class, 'insights');
    $data = [
        'period' => 'day',
        'current' => [
            'from' => '2026-11-05 00:00:00', 'until' => '2026-11-05 15:30:00',
            'revenueRials' => 1_000_000, 'newUsers' => 120,
        ],
        'previous' => [
            'from' => '2026-11-04 00:00:00', 'until' => '2026-11-04 15:30:00',
            'revenueRials' => 2_000_000, 'newUsers' => 100,
        ],
        'growth' => ['salesPercent' => -50.0, 'newUsersPercent' => 20.0],
    ];

    $fall = $reader->invoke(null, $data);
    expect($fall['💰 فروش'])->toContain('۱۰۰٬۰۰۰ تومان کاهش (−۵۰٫۰٪)')
        ->and($fall['👥 کاربران'])->toContain('۲۰ نفر افزایش (+۲۰٫۰٪)')
        ->and($fall['📈 برداشت مدیریتی'])->toContain('با وجود افزایش ثبت‌نام‌ها، فروش کاهش یافته است')
        ->toContain('علت این اختلاف از این دو شاخص مشخص نیست');

    $data['current']['revenueRials'] = $data['previous']['revenueRials'];
    $data['current']['newUsers'] = $data['previous']['newUsers'];
    $data['growth'] = ['salesPercent' => 0.0, 'newUsersPercent' => 0.0];
    $stable = $reader->invoke(null, $data);
    expect($stable['💰 فروش'])->toContain('تغییری نداشته')
        ->and($stable['👥 کاربران'])->toContain('ثابت مانده')
        ->and($stable['📈 برداشت مدیریتی'])->toContain('فروش ثابت مانده و ثبت‌نام ثابت مانده');

    $data['previous']['revenueRials'] = 0;
    $data['previous']['newUsers'] = 0;
    $data['growth'] = ['salesPercent' => null, 'newUsersPercent' => null];
    $noBaseline = $reader->invoke(null, $data);
    expect($noBaseline['💰 فروش'])->toContain('فروش بازه قبل صفر بوده')
        ->toContain('بدون مبنا')
        ->and($noBaseline['👥 کاربران'])->toContain('ثبت‌نام بازه قبل صفر بوده')
        ->toContain('بدون مبنا')
        ->and($noBaseline['📈 برداشت مدیریتی'])->toContain('مقایسه درصدی کامل دو شاخص ممکن نیست');

    $data['current']['revenueRials'] = 0;
    $data['current']['newUsers'] = 0;
    $allZero = $reader->invoke(null, $data);
    expect($allZero['💰 فروش'])->toContain('فروش بازه قبل صفر بوده')
        ->and($allZero['💡 نکته قابل بررسی'])->toContain('اگر انتظار فروش وجود دارد');
    expect(implode(' ', $allZero))->not->toContain('INF', 'NaN', 'رشد بی‌نهایت');
});

it('shows direct reseller and unknown sales with percentage-point share movements and top-three concentration', function (): void {
    $root = kpiUiRoot();
    $direct = kpiUiUser('2026-09-01 12:00:00');
    $resellerOne = kpiUiUser('2026-09-01 12:00:00');
    $resellerTwo = kpiUiUser('2026-09-01 12:00:00');
    $historical = kpiUiUser('2026-09-01 12:00:00');

    kpiUiSettlement($direct, 'bank', '2026-11-04 11:00:00', 1_000_000, 'direct');
    kpiUiSettlement($resellerOne, 'wallet', '2026-11-04 12:00:00', 1_000_000, 'reseller');
    kpiUiSettlement($direct, 'bank', '2026-11-05 09:00:00', 2_000_000, 'direct');
    kpiUiSettlement($resellerOne, 'wallet', '2026-11-05 10:00:00', 3_000_000, 'reseller');
    kpiUiSettlement($resellerTwo, 'bank', '2026-11-05 11:00:00', 1_000_000, 'reseller');
    kpiUiSettlement($historical, 'bank', '2026-11-05 12:00:00', 1_000_000);

    $report = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'day');
    expect($report['management']['current']['revenueRials'])->toBe(7_000_000)
        ->and($report['current']['topThreeRevenueRials'])->toBe(6_000_000)
        ->and($report['current']['topThreeSharePercent'])->toEqualWithDelta(600 / 7, 0.000001);

    $ui = app(AdminManagementKpiUi::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))
            ->invoke($ui, $report['management'], $report);
        $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))
            ->invoke($ui, $report['management'], $report);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }

    foreach ([
        ['فروش مستقیم', '۲۰۰٬۰۰۰ تومان', '۱۰۰٬۰۰۰ تومان', '+۱۰۰٫۰٪'],
        ['سهم فروش مستقیم', '۲۸٫۶٪', '۵۰٫۰٪', '−۲۱٫۴ واحد درصد'],
        ['فروش نمایندگی', '۴۰۰٬۰۰۰ تومان', '۱۰۰٬۰۰۰ تومان', '+۳۰۰٫۰٪'],
        ['سهم فروش نمایندگی', '۵۷٫۱٪', '۵۰٫۰٪', '+۷٫۱ واحد درصد'],
        ['کانال نامشخص', '۱۰۰٬۰۰۰ تومان', '۰ تومان', 'بدون مبنا'],
        ['سهم نامشخص', '۱۴٫۳٪', '۰٫۰٪', '+۱۴٫۳ واحد درصد'],
        ['نمایندگان خریدار', '۲', '۱', '+۱۰۰٫۰٪'],
        ['فروش حداکثر ۳ خریدار برتر', '۶۰۰٬۰۰۰ تومان', '۲۰۰٬۰۰۰ تومان', '+۲۰۰٫۰٪'],
        ['سهم خریداران برتر از کل', '۸۵٫۷٪', '۱۰۰٫۰٪', '−۱۴٫۳ واحد درصد'],
        ['تعداد خریداران برتر', '۳', '۲', '+۵۰٫۰٪'],
    ] as [$label, $current, $previous, $change]) {
        expect($rich)->toContain('<td>'.$label.'</td><td>اخیر: <b>'.$current
            .'</b><br>قبل: '.$previous.'<br>تغییر: '.$change.'</td>')
            ->and($plain)->toContain('<b>'.$label."</b>\nاخیر: ".$current
                .' · قبل: '.$previous."\nتغییر: ".$change);
    }

    expect($rich)->toContain('<details><summary>🏆 تمرکز فروش</summary>')
        ->toContain('<h3>🏷️ کانال‌های فروش</h3>')
        ->and($plain)->not->toContain('<details>', '<table', '<br>')
        ->and($rich.$plain)->not->toContain('INF', 'NaN', '∞')
        ->and(mb_strlen(strip_tags($plain)))->toBeLessThanOrEqual(4096);

    // The real Telegram path must use the same authoritative channel snapshot.
    kpiUiBot()->hearCallbackQueryData('kpi:period:day')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = (string) (kpiUiRich($request)['html'] ?? '');

            return str_contains($html, 'فروش نمایندگی</td><td>اخیر: <b>۴۰۰٬۰۰۰ تومان</b>')
                && str_contains($html, 'سهم خریداران برتر از کل</td><td>اخیر: <b>۸۵٫۷٪</b>');
        }, index: 1);
    Http::assertNothingSent();
});

it('keeps empty and historical unknown channel shares truthful in both Rich and fallback views', function (): void {
    $root = kpiUiRoot();
    $report = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'week');
    $ui = app(AdminManagementKpiUi::class);
    $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))
        ->invoke($ui, $report['management'], $report);
    $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))
        ->invoke($ui, $report['management'], $report);

    foreach ([$rich, $plain] as $value) {
        expect($value)->toContain('کانال نامشخص', 'نمایندگان خریدار', 'سهم خریداران برتر از کل')
            ->toContain('بدون مبنا', 'قابل محاسبه نیست')
            ->not->toContain('INF', 'NaN', '∞', 'سهم مستقیم از کل مشخص');
    }
    Http::assertNothingSent();
});

// Improvement phase 4: actual read-model data feeds one visible and reproducible
// summary + grounded observations. Rendering must not query or write again.
it('grounds the visible takeaway and expandable narrative in the canonical channel and order snapshot', function (): void {
    $root = kpiUiRoot();
    $direct = kpiUiUser('2026-09-01 12:00:00');
    $resellerOne = kpiUiUser('2026-09-01 12:00:00');
    $resellerTwo = kpiUiUser('2026-09-01 12:00:00');
    $historical = kpiUiUser('2026-09-01 12:00:00');

    kpiUiSettlement($direct, 'bank', '2026-11-04 11:00:00', 1_000_000, 'direct');
    kpiUiSettlement($resellerOne, 'wallet', '2026-11-04 12:00:00', 1_000_000, 'reseller');
    kpiUiSettlement($direct, 'bank', '2026-11-05 09:00:00', 2_000_000, 'direct');
    kpiUiSettlement($resellerOne, 'wallet', '2026-11-05 10:00:00', 3_000_000, 'reseller');
    kpiUiSettlement($resellerTwo, 'bank', '2026-11-05 11:00:00', 1_000_000, 'reseller');
    kpiUiSettlement($historical, 'bank', '2026-11-05 12:00:00', 1_000_000);

    $report = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'day');
    $ui = app(AdminManagementKpiUi::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))
            ->invoke($ui, $report['management'], $report);
        $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))
            ->invoke($ui, $report['management'], $report);
        $insights = (new ReflectionMethod(AdminManagementKpiUi::class, 'insights'))
            ->invoke(null, $report['management'], $report);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }

    expect($report['management']['current']['revenueRials'])->toBe(7_000_000)
        ->and($report['current']['topThreeSharePercent'])->toEqualWithDelta(600 / 7, 0.000001)
        ->and($insights['🧾 سفارش‌ها'])->toContain(
            'از ۲ به ۴', 'خریداران یکتا از ۲ به ۴',
            '۴ خرید غیرتمدیدی و ۰ تمدید',
            'میانگین سفارش: ۱۷۵٬۰۰۰ تومان (قبل: ۱۰۰٬۰۰۰ تومان)',
        )
        ->and($insights['🏷️ کانال‌ها'])->toContain(
            'مستقیم ۲۸٫۶٪ (۲۰۰٬۰۰۰ تومان)',
            'نمایندگی ۵۷٫۱٪ (۴۰۰٬۰۰۰ تومان)',
            'نامشخص ۱۴٫۳٪ (۱۰۰٬۰۰۰ تومان)',
            'نمایندگان خریدار: ۲ (قبل: ۱)',
        )
        ->and($insights['🏆 تمرکز فروش'])->toContain(
            'حداکثر ۳ خریدار برتر ۶۰۰٬۰۰۰ تومان',
            '۸۵٫۷٪ از کل فروش',
            'سهم بازه قبل: ۱۰۰٫۰٪',
        )
        ->and($insights['💡 نکته قابل بررسی'])->toContain('فروش نامشخص سفارش‌های قدیمی')
        ->and(array_keys($insights))->toContain('💰 فروش', '👥 کاربران', '📈 برداشت مدیریتی')
        ->and(array_keys($insights))->toHaveCount(7);

    foreach ([$rich, $plain] as $display) {
        expect($display)->toContain(
            '🧭 جمع‌بندی',
            '🧾 سفارش‌ها',
            '🏷️ کانال‌ها',
            '🏆 تمرکز فروش',
            'فروش نامشخص سفارش‌های قدیمی',
        )
            ->not->toContain('علت افزایش فروش، کانال نمایندگی', 'INF', 'NaN', '∞');
    }
    expect($rich)->toContain('<p><b>🧭 جمع‌بندی</b> ')
        ->toContain('<details><summary>🧠 تحلیل عملکرد دوره</summary>')
        ->and(strpos($rich, '<p><b>🧭 جمع‌بندی</b> '))->toBeLessThan(strpos($rich, '<details><summary>🧠 تحلیل عملکرد دوره</summary>'))
        ->and(substr_count($rich, '<b>💡 نکته قابل بررسی</b>'))->toBe(1)
        ->and($plain)->not->toContain('<details>', '<table', '<br>')
        ->and(substr_count($plain, '<b>💡 نکته قابل بررسی</b>'))->toBe(1)
        ->and(mb_strlen(strip_tags($plain)))->toBeLessThanOrEqual(4096);
    Http::assertNothingSent();
});

it('uses a single evidence-led suggestion when concentration rises and unknown share is absent', function (): void {
    $root = kpiUiRoot();
    $buyers = [];
    foreach (range(1, 4) as $index) {
        $buyers[] = kpiUiUser('2026-09-01 12:00:00');
    }
    foreach ($buyers as $buyer) {
        kpiUiSettlement($buyer, 'wallet', '2026-11-04 10:00:00', 1_000_000, 'direct');
    }
    kpiUiSettlement($buyers[0], 'bank', '2026-11-05 10:00:00', 1_000_000, 'reseller');

    $report = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'day');
    $insights = (new ReflectionMethod(AdminManagementKpiUi::class, 'insights'))
        ->invoke(null, $report['management'], $report);
    expect($report['previous']['topThreeSharePercent'])->toBe(75.0)
        ->and($report['current']['topThreeSharePercent'])->toBe(100.0)
        ->and($insights['💡 نکته قابل بررسی'])->toContain('تغییر تمرکز فروش را در دوره بعد')
        ->and($insights['🏷️ کانال‌ها'])->toContain('نامشخص ۰٫۰٪')
        ->and(array_keys($insights))->toHaveCount(7);
    Http::assertNothingSent();
});

it('keeps missing windows and zero orders explicit instead of inventing growth or an explanation', function (): void {
    $root = kpiUiRoot();
    $report = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'week');
    $ui = app(AdminManagementKpiUi::class);
    $insights = (new ReflectionMethod(AdminManagementKpiUi::class, 'insights'))
        ->invoke(null, $report['management'], $report);
    $rich = (new ReflectionMethod(AdminManagementKpiUi::class, 'richReport'))
        ->invoke($ui, $report['management'], $report);
    $plain = (new ReflectionMethod(AdminManagementKpiUi::class, 'simpleReport'))
        ->invoke($ui, $report['management'], $report);

    expect($insights['🧾 سفارش‌ها'])->toContain('۰ به ۰', 'میانگین سفارش: بدون سفارش')
        ->and($insights['🏷️ کانال‌ها'])->toContain('مستقیم بدون مبنا', 'نمایندگی بدون مبنا', 'نامشخص بدون مبنا')
        ->and($insights['🏆 تمرکز فروش'])->toContain('معادل بدون مبنا')
        ->and($insights['💡 نکته قابل بررسی'])->toContain('سفارش تسویه‌شده‌ای ثبت نشده')
        ->and($insights['📈 برداشت مدیریتی'])->toContain('مقایسه درصدی کامل')
        ->and($rich.$plain)->not->toContain('INF', 'NaN', '∞', 'رشد قطعی');
    expect($plain)->not->toContain('<details>', '<table', '<br>')
        ->and(mb_strlen(strip_tags($plain)))->toBeLessThanOrEqual(4096);
    Http::assertNothingSent();
});

it('rounds concentration movement only after subtracting precise shares', function (bool $decline): void {
    $root = kpiUiRoot();
    $recent = [170_000, 170_000, 160_600, 124_850, 124_850, 124_850, 124_850];
    $prior = [170_000, 170_000, 159_400, 125_150, 125_150, 125_150, 125_150];
    if ($decline) {
        [$recent, $prior] = [$prior, $recent];
    }
    foreach ($recent as $index => $amount) {
        $buyer = kpiUiUser('2026-09-01 12:00:00');
        kpiUiSettlement($buyer, 'wallet', '2026-11-05 10:00:00', $amount, 'direct');
        kpiUiSettlement($buyer, 'bank', '2026-11-04 10:00:00', $prior[$index], 'direct');
    }

    $report = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'day');
    expect($report['current']['topThreeSharePercent'])->toEqualWithDelta($decline ? 49.94 : 50.06, 0.000001)
        ->and($report['previous']['topThreeSharePercent'])->toEqualWithDelta($decline ? 50.06 : 49.94, 0.000001);

    $ui = app(AdminManagementKpiUi::class);
    foreach (['richReport', 'simpleReport'] as $method) {
        $text = (new ReflectionMethod(AdminManagementKpiUi::class, $method))
            ->invoke($ui, $report['management'], $report);
        expect($text)->toContain('۵۰٫۱٪', '۴۹٫۹٪', ($decline ? '−' : '+').'۰٫۱ واحد درصد')
            ->not->toContain(($decline ? '−' : '+').'۰٫۲ واحد درصد');
    }
    Http::assertNothingSent();
})->with(['increase' => [false], 'decrease' => [true]]);
