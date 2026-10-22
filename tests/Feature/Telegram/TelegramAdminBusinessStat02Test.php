<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'Asia/Tehran')->utc());
    config()->set('super_admin.telegram_ids', ['991501', '991502']);
    Http::preventStrayRequests();
});

/** @return array{root:User,buyer:User,oldBuyer:User,order:int,renewal:int,older:int} */
function stat02Fixture(): array
{
    $root = User::factory()->create(['created_at' => now()->subDays(50)]);
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 991501]);
    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 991503]);
    $oldBuyer = User::factory()->create(['created_at' => now()->subDays(10)]);
    TelegramAccount::query()->create(['user_id' => $oldBuyer->id, 'telegram_user_id' => 991504]);

    DB::table('telegram_bot_contacts')->insert([
        ['user_id' => $buyer->id, 'bot_key' => 'sales', 'chat_id' => 991503,
            'delivery_status' => 'reachable', 'last_seen_at' => now()->subDay(),
            'created_at' => now()->subDay(), 'updated_at' => now()],
        ['user_id' => $oldBuyer->id, 'bot_key' => 'sales', 'chat_id' => 991504,
            'delivery_status' => 'unavailable', 'last_seen_at' => now()->subDays(10),
            'created_at' => now()->subDays(10), 'updated_at' => now()],
        ['user_id' => $root->id, 'bot_key' => 'admin', 'chat_id' => 991501,
            'delivery_status' => 'reachable', 'last_seen_at' => now()->subDays(2),
            'created_at' => now()->subDays(2), 'updated_at' => now()],
    ]);

    $newOrder = static function (User $owner, string $status, int $amount): int {
        return (int) DB::table('sales_orders')->insertGetId([
            'buyer_user_id' => $owner->id,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'product_slug' => 'stat02', 'product_name' => 'Stats plan',
            'duration_days' => 30, 'traffic_gb' => 20,
            'public_price_rials' => $amount, 'discount_percent' => 0,
            'discount_rials' => 0, 'total_price_rials' => $amount,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $order = $newOrder($buyer, 'fulfilled', 1_000_000);
    $renewal = $newOrder($buyer, 'fulfilled', 2_000_000);
    $older = $newOrder($oldBuyer, 'paid', 3_000_000);
    $newOrder($buyer, 'pending_payment', 4_000_000);

    $sub = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'stat02:'.$order, 'remote_username' => 'stat02_'.$order,
        'state' => 'active', 'last_operation_key' => 'fixture',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_renewal_orders')->insert([
        'sales_order_id' => $renewal, 'source_order_id' => $order,
        'provisioning_subscription_id' => $sub, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $journal = static function (string $key): int {
        return (int) DB::table('billing_journals')->insertGetId([
            'kind' => 'purchase', 'idempotency_key' => $key, 'created_at' => now(),
        ]);
    };
    $claim = DB::table('sales_bank_payment_claims')->insertGetId([
        'sales_order_id' => $order, 'buyer_user_id' => $buyer->id,
        'photo_encrypted' => 'fixture-only', 'photo_fingerprint' => hash('sha256', 'bank-photo'),
        'telegram_message_key' => 'stat02-bank', 'status' => 'approved',
        'reviewed_by' => $root->id, 'reviewed_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_bank_settlements')->insert([
        'sales_order_id' => $order, 'payment_claim_id' => $claim,
        'billing_journal_id' => $journal('stat02-bank'),
        'verified_by' => $root->id, 'payment_reference' => 'STAT02BANK',
        'amount_rials' => 1_000_000, 'settled_at' => now(),
    ]);
    foreach ([$renewal, $older] as $id) {
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $id, 'billing_journal_id' => $journal('stat02-wallet-'.$id),
            'method' => 'wallet', 'amount_rials' => $id === $renewal ? 2_000_000 : 3_000_000,
            'settled_at' => $id === $renewal ? now()->subDays(2) : now()->subDays(10),
        ]);
    }

    // A wallet topup is not a purchase and must never inflate revenue.
    DB::table('billing_journals')->insert([
        'kind' => 'topup', 'idempotency_key' => 'stat02-topup', 'created_at' => now(),
    ]);

    return compact('root', 'buyer', 'oldBuyer', 'order', 'renewal', 'older');
}

function stat02Bot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function stat02Payload(NutgramRequest $request): array
{
    $p = FakeNutgram::getActualData($request);
    if (is_string($p['reply_markup'] ?? null)) {
        $p['reply_markup'] = json_decode($p['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $p;
}

it('aggregates verified bank and wallet settlements exactly once and separates new purchases from renewals', function (): void {
    $data = stat02Fixture();
    $read = app(ReadAdminBusinessReport::class);
    $seven = $read->handle($data['root']->id, 7);
    $thirty = $read->handle($data['root']->id, 30);

    expect($seven['revenueRials'])->toBe(3_000_000)
        ->and($seven['settled'])->toBe(2)
        ->and($seven['newPurchases'])->toBe(1)
        ->and($seven['renewals'])->toBe(1)
        ->and($seven['buyers'])->toBe(1)
        ->and($seven['fulfilled'])->toBe(2)
        ->and($seven['pendingNow'])->toBe(1)
        ->and($thirty['revenueRials'])->toBe(6_000_000)
        ->and($thirty['settled'])->toBe(3)
        ->and($thirty['newPurchases'])->toBe(2)
        ->and($thirty['renewals'])->toBe(1)
        ->and($thirty['buyers'])->toBe(2)
        ->and($thirty['fulfilled'])->toBe(2);
    Http::assertNothingSent();
});

it('reports actual last interaction separately from reachable status for Sales and Admin', function (): void {
    $data = stat02Fixture();
    $read = app(ReadAdminBusinessReport::class);
    $seven = $read->handle($data['root']->id, 7);
    $thirty = $read->handle($data['root']->id, 30);

    expect($seven['linkedTelegram'])->toBe(3)
        ->and($seven['newUsers'])->toBe(1)
        ->and($seven['salesContacts'])->toBe(2)
        ->and($seven['salesReachable'])->toBe(1)
        ->and($seven['salesEngaged'])->toBe(1)
        ->and($seven['salesNewContacts'])->toBe(1)
        ->and($seven['adminContacts'])->toBe(1)
        ->and($seven['adminReachable'])->toBe(1)
        ->and($seven['adminEngaged'])->toBe(1)
        ->and($thirty['newUsers'])->toBe(2)
        ->and($thirty['salesEngaged'])->toBe(2)
        ->and($thirty['salesNewContacts'])->toBe(2);
});

it('opens 7/30 day Sales and People submenus and links back to existing stats', function (): void {
    stat02Fixture();
    $bot = stat02Bot(991501);

    $bot->hearCallbackQueryData('stats:detail:sales:7')->reply()->assertRaw(static function (NutgramRequest $req): bool {
        $p = stat02Payload($req);

        return str_contains($p['text'], 'گزارش فروش')
            && str_contains($p['text'], '300,000 تومان')
            && str_contains($p['text'], 'تمدیدها: 1')
            && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'stats:overview')
            && ($p['parse_mode'] ?? null) === 'HTML';
    }, index: 1);
    $bot->hearCallbackQueryData('stats:detail:people:30')->reply()->assertRaw(static function (NutgramRequest $req): bool {
        $p = stat02Payload($req);

        return str_contains($p['text'], 'گزارش کاربران و تلگرام')
            && str_contains($p['text'], 'تعامل در بازه: 2')
            && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'stats:detail:sales:30');
    }, index: 1);
});

it('refuses ordinary admins, group callbacks and invalid report periods without provider writes', function (): void {
    $data = stat02Fixture();
    $bot = stat02Bot(991503);
    $bot->hearCallbackQueryData('stats:detail:sales:7')->reply()
        ->assertCalled('answerCallbackQuery');
    $bot->setCommonChat(Chat::make(-991503, ChatType::GROUP));
    $bot->hearCallbackQueryData('stats:detail:people:30')->reply()->assertNoReply();

    expect(fn () => app(ReadAdminBusinessReport::class)->handle($data['buyer']->id, 7))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminBusinessReport::class)->handle($data['root']->id, 0))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
