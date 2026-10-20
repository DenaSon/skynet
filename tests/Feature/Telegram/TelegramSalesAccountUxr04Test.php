<?php

declare(strict_types=1);

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\SummarizeSalesPurchases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.public_signup_enabled', false);
    config()->set('services.remnawave.writes_enabled', false);
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
    Http::fake();
    app(ConfigureProduct::class)->handle('uxr04-item', '<Plan> & Title');
    app(ConfigureRate::class)->handle('uxr04-item', 30, 100_000, 20_000);
});

function uxr04Buyer(int $telegramId, bool $reseller = false): User
{
    $user = User::factory()->create(['name' => '<Customer> & Co.']);
    if ($reseller) {
        Role::findOrCreate('reseller', 'web');
        $user->syncRoles(['reseller']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function uxr04Order(User $buyer, string $status = 'pending_payment'): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'uxr04-item', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    if ($status !== 'pending_payment') {
        DB::table('sales_orders')->where('id', $order)->update(['status' => $status]);
    }

    return $order;
}

function uxr04Link(int $orderId, string $state = 'active'): void
{
    $subscription = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'uxr04-order:'.$orderId,
        'remote_username' => 'sk_'.substr(hash('sha256', 'uxr04-order:'.$orderId), 0, 24),
        'remote_user_id' => $orderId + 500,
        'state' => $state,
        'last_operation_key' => 'uxr04:'.$orderId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId, 'provisioning_subscription_id' => $subscription,
        'created_at' => now(),
    ]);
}

function uxr04Bot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function uxr04Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('calculates paid purchase totals for the entire owned history without counting pending or rejected orders', function (): void {
    $buyer = uxr04Buyer(988101);
    $other = uxr04Buyer(988102);
    uxr04Order($buyer);
    $paid = uxr04Order($buyer, 'paid');
    $fulfilled = uxr04Order($buyer, 'fulfilled');
    uxr04Order($other, 'fulfilled');

    $expected = (int) DB::table('sales_orders')->whereIn('id', [$paid, $fulfilled])->sum('total_price_rials');
    expect(app(SummarizeSalesPurchases::class)->handle($buyer->id))->toBe([
        'count' => 2, 'amountRials' => $expected,
    ]);

    $bot = uxr04Bot(988101);
    $bot->hearText('/account')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr04Payload($request);

        return str_contains($data['text'], '&lt;Customer&gt; &amp; Co.')
            && str_contains($data['text'], 'خریدهای پرداخت‌شده: 2')
            && str_contains($data['text'], 'عضویت:')
            && $data['parse_mode'] === 'HTML';
    });
    Http::assertNothingSent();
});

it('counts all linked owned services regardless of pagination and never calls the provider in a summary', function (): void {
    $buyer = uxr04Buyer(988103);
    $other = uxr04Buyer(988104);
    for ($n = 0; $n < 8; $n++) {
        uxr04Link(uxr04Order($buyer, 'fulfilled'), $n === 7 ? 'needs_review' : 'active');
    }
    uxr04Order($buyer);
    uxr04Link(uxr04Order($other, 'fulfilled'));
    $viewer = SubscriptionViewer::telegram(988103);
    $services = app(ListMyServices::class);
    $first = $services->handle($viewer, 6);
    expect($services->counts($viewer))->toBe(['total' => 8, 'activeRecorded' => 7, 'needsReview' => 1])
        ->and($first->items)->toHaveCount(6)
        ->and($first->nextBeforeOrderId)->not->toBeNull();
    $next = $services->handle($viewer, 6, $first->nextBeforeOrderId);
    expect($next->items)->toHaveCount(2);
    $bot = uxr04Bot(988103);
    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(uxr04Payload($request)['text'], 'سرویس‌های ثبت‌شده: <b>8</b>'),
        index: 1,
    );
    Http::assertNothingSent();
});

it('gates wallet behind fresh reseller authorization and keeps unauthorized or revoked users private', function (): void {
    $member = uxr04Buyer(988105);
    $bot = uxr04Bot(988105);
    $bot->hearText('/account')->reply()->assertRaw(static function (Request $request): bool {
        $markup = json_encode(uxr04Payload($request)['reply_markup'], JSON_THROW_ON_ERROR);

        return ! str_contains($markup, 'sales:wallet');
    });
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains(uxr04Payload($request)['text'], 'فقط برای نمایندگان');
    }, index: 1);

    $member->syncRoles(['reseller']);
    $bot->hearText('/account')->reply()->assertRaw(static function (Request $request): bool {
        $data = uxr04Payload($request);

        return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:wallet')
            && str_contains($data['text'], 'موجودی نمایندگی');
    });
    $member->syncRoles(['member']);
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    $member->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('does not expose a foreign service or secret from manipulated callbacks', function (): void {
    $buyer = uxr04Buyer(988106);
    uxr04Buyer(988107);
    $foreign = uxr04Order($buyer, 'fulfilled');
    uxr04Link($foreign);
    $bot = uxr04Bot(988107);
    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:view:'.$foreign.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('sales:subs:link:'.$foreign.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertNothingSent();
});
