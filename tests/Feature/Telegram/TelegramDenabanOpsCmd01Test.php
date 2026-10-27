<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminCommandCenter;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['991201', '991202']);
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('services.remnawave.url', 'https://denaban.test');
    config()->set('services.remnawave.token', 'fixture');
    Http::preventStrayRequests();
});

function denabanRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 991201]);

    return $root;
}

function denabanBot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function denabanRichHtml(NutgramRequest $request): string
{
    $payload = denabanPayload($request);
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return (string) ($rich['html'] ?? '');
}

function denabanPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('puts Denaban first in both private root Admin menus and excludes ordinary admins', function (): void {
    denabanRoot();
    $actor = app(ResolveTelegramActor::class)->handle(991201);
    $keyboard = app(ReplyMenuRenderer::class)->adminKeyboard($actor);
    expect($keyboard->keyboard[0][0]->text)->toBe(ReplyMenuLabels::DENABAN);

    $bot = denabanBot(991201);
    $bot->hearCallbackQueryData('menu:admin')->reply()->assertRaw(static function (NutgramRequest $r): bool {
        $payload = denabanPayload($r);

        return ($payload['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'denaban:show';
    }, index: 1);

    $ordinary = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $ordinary->id, 'telegram_user_id' => 991203]);
    $ordinaryActor = app(ResolveTelegramActor::class)->handle(991203);
    $labels = array_map(static fn ($button): string => $button->text,
        array_merge(...app(ReplyMenuRenderer::class)->adminKeyboard($ordinaryActor)->keyboard));
    expect($labels)->not->toContain(ReplyMenuLabels::DENABAN);
});

it('shows canonical verified inflows and sales independently of unavailable RW', function (): void {
    $root = denabanRoot();
    $orderId = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $root->id, 'idempotency_key' => 'denaban-order',
        'product_slug' => 'denaban', 'product_name' => 'Test',
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => 3_000_000, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => 3_000_000,
        'status' => 'fulfilled', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $journalId = DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => 'denaban-journal', 'created_at' => now(),
    ]);
    $claimId = DB::table('sales_bank_payment_claims')->insertGetId([
        'sales_order_id' => $orderId, 'buyer_user_id' => $root->id,
        'photo_encrypted' => 'fixture', 'photo_fingerprint' => hash('sha256', 'denaban'),
        'telegram_message_key' => 'denaban-claim', 'status' => 'approved',
        'reviewed_by' => $root->id, 'reviewed_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_bank_settlements')->insert([
        'sales_order_id' => $orderId, 'payment_claim_id' => $claimId,
        'billing_journal_id' => $journalId, 'verified_by' => $root->id,
        'payment_reference' => 'DENABAN-VERIFIED',
        'amount_rials' => 3_000_000, 'settled_at' => now(),
    ]);
    Http::fake(['https://denaban.test/*' => Http::response([], 503)]);
    $before = DB::table('billing_journals')->count();
    $snap = app(ReadAdminCommandCenter::class)->handle($root->id);
    expect($snap['cash']['cashInRials'])->toBe(3_000_000)
        ->and($snap['stats']['salesTodayRials'])->toBe(3_000_000)
        ->and($snap['stats']['nodes'])->toBeNull()
        ->and($snap['pendingTopups'])->toBe(0);

    $bot = denabanBot(991201);
    $bot->hearCallbackQueryData('denaban:simple')->reply()->assertRaw(static function (NutgramRequest $r): bool {
        $p = denabanPayload($r);

        return str_contains($p['text'], '🏔️ دنابان | وضعیت دنا')
            && str_contains($p['text'], 'دریافتی‌های تأییدشده از ابتدا: 300,000 تومان')
            && str_contains($p['text'], 'فروش امروز: 300,000 تومان')
            && str_contains($p['text'], 'نودهای متصل: نامشخص از نامشخص')
            && str_contains($p['text'], 'بعضی اطلاعات کامل نیست')
            && ! str_contains($p['text'], 'صبح بخیر')
            && ($p['parse_mode'] ?? null) === 'HTML';
    }, index: 1);
    expect(DB::table('billing_journals')->count())->toBe($before);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');
});

it('rejects unauthorized, revoked and group callbacks without RW calls', function (): void {
    $root = denabanRoot();
    $other = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 991203]);
    Http::fake();

    expect(fn () => app(ReadAdminCommandCenter::class)->handle($other->id))
        ->toThrow(AuthorizationException::class);
    denabanBot(991203)->hearCallbackQueryData('denaban:simple')->reply()->assertCalled('answerCallbackQuery');

    $root->syncRoles(['member']);
    $bot = denabanBot(991201);
    $bot->hearCallbackQueryData('denaban:simple')->reply()->assertCalled('answerCallbackQuery');
    $bot->setCommonChat(Chat::make(-991201, ChatType::GROUP));
    $bot->hearCallbackQueryData('denaban:simple')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('shows only safe per-node RW telemetry using the existing inventory GET without duplicate requests', function (): void {
    denabanRoot();
    Http::fake(static function (Request $request) {
        $payload = match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/system/health' => ['runtimeMetrics' => [['status' => 'HEALTHY']]],
            '/api/system/stats' => [
                'cpu' => ['cores' => 4],
                'memory' => ['total' => 2147483648, 'used' => 1073741824, 'free' => 1073741824],
                'users' => ['totalUsers' => 4], 'onlineStats' => ['onlineNow' => 2], 'uptime' => 850,
            ],
            '/api/nodes' => [
                [
                    'uuid' => '123e4567-e89b-12d3-a456-426614174001',
                    'name' => 'EU<internal>&', 'isConnected' => true, 'isDisabled' => false,
                    'isConnecting' => false, 'usersOnline' => 2,
                    'system' => ['stats' => ['interface' => [
                        'rxBytesPerSec' => 2048, 'txBytesPerSec' => 1048576,
                    ]]],
                    'isTrafficTrackingActive' => true, 'trafficLimitBytes' => 1000,
                    'trafficUsedBytes' => 250, 'address' => 'super-secret.private.example',
                ],
                [
                    'uuid' => '123e4567-e89b-12d3-a456-426614174002',
                    'name' => 'UAE-01', 'isConnected' => false, 'isDisabled' => false,
                    'isConnecting' => true, 'usersOnline' => 0,
                    'lastStatusChange' => '2026-10-24T18:00:00.000Z',
                    'system' => ['stats' => ['interface' => [
                        'rxBytesPerSec' => -1, 'txBytesPerSec' => 'not a number',
                    ]]],
                    'isTrafficTrackingActive' => false, 'trafficLimitBytes' => 1000,
                    'trafficUsedBytes' => 1000,
                ],
                [
                    'uuid' => '123e4567-e89b-12d3-a456-426614174003',
                    'name' => 'DE-standby', 'isConnected' => false, 'isDisabled' => true,
                ],
            ],
            '/api/hosts' => [['uuid' => '1']],
            '/api/internal-squads' => ['internalSquads' => [], 'total' => 0],
            '/api/config-profiles' => ['configProfiles' => [], 'total' => 0],
            '/api/users' => ['users' => [], 'total' => 0],
            default => null,
        };

        return $payload === null
            ? Http::response([], 404)
            : Http::response(['response' => $payload], 200);
    });

    denabanBot(991201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanPayload($request);
            $text = $payload['text'];

            return str_contains($text, 'نودهای متصل: 1 از 3')
                && str_contains($text, '🟠 در حال اتصال · UAE-01')
                && str_contains($text, '🕒 آخرین تغییر وضعیت: ۱۴۰۵/')
                && str_contains($text, '⏸️ غیرفعال · DE-standby')
                && str_contains($text, '🟢 متصل · EU&lt;internal&gt;&amp;')
                && str_contains($text, '📡 دریافت: 2.0 KiB/s | ارسال: 1.00 MiB/s')
                && str_contains($text, '📊 مصرف از سقف نود: 25.0٪')
                && str_contains($text, '📡 دریافت: نامشخص | ارسال: نامشخص')
                && ! str_contains($text, 'super-secret.private.example')
                && ! str_contains($text, 'EU<internal>');
        }, index: 1);

    // The command center does not duplicate the GET /api/nodes already made by STAT-01.
    Http::assertSentCount(7);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    expect(Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/api/nodes')))->toHaveCount(1);
});

it('keeps Denaban financial/local stats readable when the RW node inventory fails', function (): void {
    denabanRoot();
    Http::fake(['https://denaban.test/*' => Http::response([], 503)]);
    denabanBot(991201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanPayload($request);

            return str_contains($payload['text'], 'جزئیات نودها: نامشخص')
                && str_contains($payload['text'], '<b>💰 وضعیت مالی</b>')
                && str_contains($payload['text'], '<b>👥 کاربران و اشتراک‌ها</b>');
        }, index: 1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

it('renders a bounded RTL rich executive dashboard and retains navigation and simple view', function (): void {
    denabanRoot();
    Http::fake(['https://denaban.test/*' => Http::response([], 503)]);

    denabanBot(991201)->hearCallbackQueryData('denaban:show')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanPayload($request);
            $rich = is_string($payload['rich_message'] ?? null)
                ? json_decode($payload['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($payload['rich_message'] ?? []);
            $html = denabanRichHtml($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return ($rich['is_rtl'] ?? null) === true
                && str_contains($html, '<h2>🏔️ دنابان')
                && substr_count($html, '<table bordered striped compact>') === 3
                && str_contains($html, '<details><summary>جزئیات نودها</summary>')
                && str_contains($html, 'نامشخص')
                && str_contains($html, 'فروش امروز')
                && ! str_contains($html, 'super-secret')
                && str_contains($buttons, 'denaban:simple')
                && str_contains($buttons, 'denaban:show')
                && str_contains($buttons, 'menu:admin:finance')
                && str_contains($buttons, 'menu:admin:servers')
                && str_contains($buttons, 'menu:admin');
        }, index: 1);

    denabanBot(991201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return ($payload['parse_mode'] ?? null) === 'HTML'
                && str_contains($payload['text'], '🏔️ دنابان | وضعیت دنا')
                && str_contains($buttons, 'denaban:show')
                && ! str_contains($buttons, 'denaban:simple');
        }, index: 1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

it('keeps denied and revoked users out of both Denaban render modes', function (): void {
    $root = denabanRoot();
    $ordinary = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $ordinary->id, 'telegram_user_id' => 991203]);
    Http::fake();

    denabanBot(991203)->hearCallbackQueryData('denaban:show')->reply()
        ->assertCalled('answerCallbackQuery');
    denabanBot(991203)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertCalled('answerCallbackQuery');

    $root->syncRoles(['member']);
    denabanBot(991201)->hearCallbackQueryData('denaban:show')->reply()
        ->assertCalled('answerCallbackQuery');
    Http::assertNothingSent();
});
