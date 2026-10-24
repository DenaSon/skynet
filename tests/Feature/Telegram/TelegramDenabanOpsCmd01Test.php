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
    });

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
    $bot->hearCallbackQueryData('denaban:show')->reply()->assertRaw(static function (NutgramRequest $r): bool {
        $p = denabanPayload($r);

        return str_contains($p['text'], '🏔️ دنابان | وضعیت دنا')
            && str_contains($p['text'], 'دریافتی‌های تأییدشده از ابتدا: 300,000 تومان')
            && str_contains($p['text'], 'فروش امروز: 300,000 تومان')
            && str_contains($p['text'], 'نودهای متصل: نامشخص از نامشخص')
            && str_contains($p['text'], 'بعضی اطلاعات کامل نیست')
            && ! str_contains($p['text'], 'صبح بخیر')
            && ($p['parse_mode'] ?? null) === 'HTML';
    });
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
    denabanBot(991203)->hearCallbackQueryData('denaban:show')->reply()->assertCalled('answerCallbackQuery');

    $root->syncRoles(['member']);
    $bot = denabanBot(991201);
    $bot->hearCallbackQueryData('denaban:show')->reply()->assertCalled('answerCallbackQuery');
    $bot->setCommonChat(Chat::make(-991201, ChatType::GROUP));
    $bot->hearCallbackQueryData('denaban:show')->reply()->assertNoReply();
    Http::assertNothingSent();
});
