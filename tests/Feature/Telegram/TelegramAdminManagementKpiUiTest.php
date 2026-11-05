<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminManagementKpiUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminManagementKpis;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
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

function kpiUiSettlement(User $buyer, string $method, string $localTime, int $amount): void
{
    $order = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
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

            return ($buttons[0][0]['callback_data'] ?? null) === 'denaban:show'
                && ($buttons[1][0]['callback_data'] ?? null) === 'kpi:period:day'
                && ($buttons[1][0]['text'] ?? null) === '📊 شاخص‌های مدیریتی'
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
                && str_contains($html, '<h2>📊 شاخص‌های مدیریتی · روزانه</h2>')
                && substr_count($html, '<table bordered striped compact>') === 2
                && str_contains($html, '<h3>💰 عملکرد فروش</h3>')
                && str_contains($html, '<h3>👥 ثبت‌نام کاربران</h3>')
                && str_contains($html, '۵۰۰٬۰۰۰ تومان')
                && str_contains($html, '۱۰۰٬۰۰۰ تومان')
                && str_contains($html, '+۴۰۰٫۰٪')
                && str_contains($html, 'خریداران یکتا · بازه اخیر</td><td><b>۱</b>')
                && str_contains($html, 'تراکنش‌ها · بازه اخیر</td><td><b>۲</b>')
                && str_contains($html, 'کاربران جدید · بازه اخیر</td><td><b>۱</b>')
                && str_contains($html, 'کاربران جدید · بازه قبل</td><td><b>۱</b>')
                && str_contains($html, '۱۴۰۵/')
                && str_contains($html, '۰۰:۰۰ تا ۱۵:۳۰')
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
        ->toContain('کاربران جدید · بازه اخیر: ۱')
        ->toContain('۱۴۰۵/')
        ->toContain('تهران');
});

it('switches period in place and clearly differentiates complete weeks from complete Persian months', function (): void {
    kpiUiRoot();
    Http::fake();
    kpiUiBot()->hearCallbackQueryData('kpi:period:week')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $p = kpiUiPayload($request);
            $rich = kpiUiRich($request);
            $html = (string) ($rich['html'] ?? '');

            return str_contains($html, 'شاخص‌های مدیریتی · هفتگی')
                && str_contains($html, '۷ روز کامل گذشته')
                && str_contains($html, 'تا ۱۴۰۵/')
                && str_contains($html, 'بدون مبنا')
                && ($p['reply_markup']['inline_keyboard'][0][1]['text'] ?? null) === '✅ هفتگی'
                && ($p['reply_markup']['inline_keyboard'][0][2]['callback_data'] ?? null) === 'kpi:period:month';
        }, index: 1);

    kpiUiBot()->hearCallbackQueryData('kpi:period:month')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $p = kpiUiPayload($request);
            $rich = kpiUiRich($request);
            $html = (string) ($rich['html'] ?? '');

            return str_contains($html, 'شاخص‌های مدیریتی · ماهانه')
                && str_contains($html, 'ماه شمسی کامل گذشته')
                && str_contains($html, 'بدون مبنا')
                && ($p['reply_markup']['inline_keyboard'][0][2]['text'] ?? null) === '✅ ماهانه'
                && str_contains($html, '<table bordered striped compact>')
                && str_contains($html, 'بازۀ') === false;
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
