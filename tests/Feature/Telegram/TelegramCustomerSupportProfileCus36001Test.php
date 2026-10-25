<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ReadCustomerSupportProfile;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['993601', '993602']);
    Http::preventStrayRequests();
    Http::fake();
});

function c360Bot(int $telegramId, User $actor): Nutgram
{
    TelegramAccount::query()->firstOrCreate(
        ['user_id' => $actor->id],
        ['telegram_user_id' => $telegramId],
    );
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function c360Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function c360Order(User $buyer, int $index, string $state): int
{
    return DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => 'c360-order-'.$buyer->id.'-'.$index,
        'product_slug' => 'plan', 'product_name' => 'Plan <VIP> '.$index,
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => 1_000_000, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => 1_000_000,
        'status' => $state, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function c360Service(int $orderId, string $state): void
{
    $subscriptionId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'c360-sub-'.$orderId,
        'remote_username' => 'c360-'.$orderId,
        'state' => $state, 'last_operation_key' => 'c360-op-'.$orderId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId, 'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(),
    ]);
}

it('adds one navigable profile button without removing existing user actions or losing search context', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $bot = c360Bot(993603, $admin);

    $bot->hearCallbackQueryData("users:view:{$member->id}:2:s")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($member): bool {
            $data = c360Payload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($buttons, "users:profile:{$member->id}:2:s")
                && str_contains($buttons, "users:confirm:{$member->id}:2:s")
                && str_contains($buttons, 'users:findpage:2');
        }, index: 1);

    $bot->hearCallbackQueryData("users:profile:{$member->id}:2:s")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($member): bool {
            $data = c360Payload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($data['text'], 'پرونده ۳۶۰ درجه مشتری')
                && str_contains($buttons, "users:view:{$member->id}:2:s")
                && str_contains($buttons, "users:profile:{$member->id}:2:s")
                && ! str_contains($data['text'], 'کیف پول')
                && ! str_contains($buttons, 'finance:wallet:');
        }, index: 1);
    Http::assertNothingSent();
});

it('scopes local service/order summary and settled purchase evidence to the selected user', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    c360Bot(993601, $root);

    $target = User::factory()->create(['name' => 'User <private>']);
    $other = User::factory()->create();
    $one = c360Order($target, 1, 'pending_payment');
    $two = c360Order($target, 2, 'fulfilled');
    $three = c360Order($target, 3, 'fulfilled');
    c360Service($two, 'active');
    c360Service($three, 'needs_review');
    $unrelated = c360Order($other, 4, 'fulfilled');
    c360Service($unrelated, 'active');

    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $target->id, 'bot_key' => 'sales', 'chat_id' => 177733300,
        'delivery_status' => 'blocked', 'last_seen_at' => now()->subDays(2),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $before = DB::table('sales_orders')->count();
    $read = app(ReadCustomerSupportProfile::class);
    $unpaid = $read->handle($root->id, $target->id);
    expect($unpaid['ordersTotal'])->toBe(3)
        ->and($unpaid['ordersPending'])->toBe(1)
        ->and($unpaid['servicesTotal'])->toBe(2)
        ->and($unpaid['servicesActive'])->toBe(1)
        ->and($unpaid['servicesReview'])->toBe(1)
        ->and($unpaid['lastSettledAt'])->toBeNull()
        ->and($unpaid['financeAllowed'])->toBeTrue()
        ->and($unpaid['walletBalanceRials'])->toBe(0)
        ->and($unpaid['salesContact']['status'])->toBe('blocked');

    $walletId = DB::table('billing_wallets')->insertGetId([
        'user_id' => $target->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $journalId = DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => 'c360-wallet-payment',
        'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        'journal_id' => $journalId, 'wallet_id' => $walletId,
        'account_code' => 'wallet_liability', 'side' => 'credit',
        'amount_rials' => 3_000_000,
    ]);
    DB::table('sales_order_settlements')->insert([
        'sales_order_id' => $two, 'billing_journal_id' => $journalId,
        'method' => 'wallet', 'amount_rials' => 1_000_000, 'settled_at' => now(),
    ]);
    $paid = $read->handle($root->id, $target->id);
    expect($paid['walletBalanceRials'])->toBe(3_000_000)
        ->and($paid['lastSettledAt'])->not->toBeNull();

    c360Bot(993601, $root)->hearCallbackQueryData("users:profile:{$target->id}:1:a")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($target, $other): bool {
            $data = c360Payload($request);
            $text = $data['text'];
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($text, 'User &lt;private&gt;')
                && str_contains($text, 'تعداد: 2 | فعال در سیستم: 1 | نیازمند بررسی: 1')
                && str_contains($text, 'سفارش‌ها: 3 | در انتظار پرداخت: 1')
                && str_contains($text, 'مانده ثبت‌شده: 300,000 تومان')
                && str_contains($text, 'بات فروش: مسدودشده')
                && str_contains($text, 'Plan &lt;VIP&gt;')
                && ! str_contains($text, '177733300')
                && ! str_contains($text, 'Plan &lt;VIP&gt; 4')
                && str_contains($buttons, "finance:wallet:{$target->id}");
        }, index: 1);

    expect(DB::table('sales_orders')->count())->toBe($before)
        ->and(DB::table('billing_postings')->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps sensitive wallet details hidden from ordinary user managers even with a forged callback', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    c360Order($target, 7, 'pending_payment');

    $walletId = DB::table('billing_wallets')->insertGetId([
        'user_id' => $target->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $journal = DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId, 'kind' => 'topup',
        'idempotency_key' => 'c360-finance-private', 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        'journal_id' => $journal, 'wallet_id' => $walletId,
        'account_code' => 'wallet_liability', 'side' => 'credit', 'amount_rials' => 9_876_540,
    ]);

    $data = app(ReadCustomerSupportProfile::class)->handle($admin->id, $target->id);
    expect($data['financeAllowed'])->toBeFalse()->and($data['walletBalanceRials'])->toBeNull();

    c360Bot(993603, $admin)->hearCallbackQueryData("users:profile:{$target->id}:1:a")->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = c360Payload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($payload['text'], 'پرونده ۳۶۰ درجه مشتری')
                && ! str_contains($payload['text'], '987,654')
                && ! str_contains($payload['text'], 'کیف پول')
                && ! str_contains($buttons, 'finance:wallet:');
        }, index: 1);
    Http::assertNothingSent();
});

it('rejects members, revoked admins, group callbacks and invalid identities before reading another profile', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $target = User::factory()->create();
    $reader = app(ReadCustomerSupportProfile::class);

    expect(fn () => $reader->handle($member->id, $target->id))
        ->toThrow(AuthorizationException::class);

    $group = c360Bot(993603, $admin);
    $group->setCommonChat(Chat::make(-993603, ChatType::GROUP));
    $group->hearCallbackQueryData("users:profile:{$target->id}:1:a")->reply()->assertNoReply();

    $admin->syncRoles(['member']);
    c360Bot(993603, $admin)->hearCallbackQueryData("users:profile:{$target->id}:1:a")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    c360Bot(993601, $root)->hearCallbackQueryData('users:profile:bad:1:a')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = c360Payload($request);

            return str_contains($payload['text'], 'درخواست پرونده معتبر نیست.');
        }, index: 1);

    Http::assertNothingSent();
});
