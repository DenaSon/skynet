<?php

use App\Modules\Billing\Application\DebitWalletForOrder;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function a1Buyer(int $telegramId): User
{
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['member', 'reseller']);
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $telegramId]);

    return $buyer;
}

function a1Order(User $buyer): int
{
    app(ConfigureProduct::class)->handle('f11-plan', 'F11 plan');
    app(ConfigureRate::class)->handle('f11-plan', 30, 100_000, 20_000);
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'f11-plan', 30, 10);

    return app(CreatePendingOrder::class)
        ->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
}

function a1Credit(User $buyer, int $amountRials, string $reference): void
{
    config()->set('super_admin.telegram_ids', ['741741', '852852']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 741741]);

    $topupId = app(RequestTopup::class)->handle($buyer->id, $amountRials, 'test:topup:'.bin2hex(random_bytes(8)));
    app(SubmitTopupReference::class)->handle($buyer->id, $topupId, $reference);
    app(ReviewTopup::class)->handle($root->id, $topupId, true, strtoupper($reference));
}

it('keeps all wallet purchase writes disabled by default', function (): void {
    $buyer = a1Buyer(951001);
    $id = a1Order($buyer);

    expect(config('sales.wallet_settlement_enabled'))->toBeFalse()
        ->and(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951001), $id))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('settles a reseller order atomically and queues intent without remote writes', function (): void {
    $buyer = a1Buyer(951002);
    $id = a1Order($buyer);
    a1Credit($buyer, 1_000_000, 'F11BANK001');
    config()->set('sales.wallet_settlement_enabled', true);

    Http::preventStrayRequests();
    Http::fake();

    $expected = (int) DB::table('sales_orders')->where('id', $id)->value('total_price_rials');
    $result = app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951002), $id);

    expect($result->status)->toBe('paid')
        ->and($result->totalPriceRials)->toBe($expected)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(1_000_000 - $expected)
        ->and((int) DB::table('sales_order_settlements')->where('sales_order_id', $id)->value('amount_rials'))->toBe($expected)
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $id)->value('state'))->toBe('pending')
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $id)->value('attempts'))->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('billing_postings')->where('account_code', 'order_liability')->count())->toBe(1);

    expect((int) DB::table('billing_postings')->where('side', 'debit')->sum('amount_rials'))
        ->toBe((int) DB::table('billing_postings')->where('side', 'credit')->sum('amount_rials'));
    Http::assertNothingSent();
});

it('replays a completed purchase without a second debit or outbox event', function (): void {
    $buyer = a1Buyer(951003);
    $id = a1Order($buyer);
    a1Credit($buyer, 1_000_000, 'F11BANK002');
    config()->set('sales.wallet_settlement_enabled', true);

    $viewer = SubscriptionViewer::telegram(951003);
    $first = app(SettleWalletOrder::class)->handle($viewer, $id);
    $balance = app(ViewWallet::class)->handle($buyer->id)->balanceRials;
    app(ConfigureRate::class)->handle('f11-plan', 30, 900_000, 80_000);
    $second = app(SettleWalletOrder::class)->handle($viewer, $id);

    expect($second->id)->toBe($first->id)
        ->and($second->totalPriceRials)->toBe($first->totalPriceRials)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe($balance)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1);
});

it('rejects missing funds and never mutates the order or creates an outbox row', function (): void {
    $buyer = a1Buyer(951004);
    $id = a1Order($buyer);
    config()->set('sales.wallet_settlement_enabled', true);

    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951004), $id))
        ->toThrow(ValidationException::class);

    expect(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('spends a limited wallet across two different orders without overdraft', function (): void {
    $buyer = a1Buyer(951005);
    $one = a1Order($buyer);
    $two = a1Order($buyer);
    a1Credit($buyer, 400_000, 'F11BANK003');
    config()->set('sales.wallet_settlement_enabled', true);
    $viewer = SubscriptionViewer::telegram(951005);

    app(SettleWalletOrder::class)->handle($viewer, $one);
    expect(fn () => app(SettleWalletOrder::class)->handle($viewer, $two))
        ->toThrow(ValidationException::class);

    expect(DB::table('sales_orders')->where('id', $two)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(115_000);
});

it('rejects forged ownership, unlinked actors, disabled accounts and revoked reseller role', function (): void {
    $buyer = a1Buyer(951006);
    a1Buyer(951007);
    $id = a1Order($buyer);
    a1Credit($buyer, 1_000_000, 'F11BANK004');
    config()->set('sales.wallet_settlement_enabled', true);

    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951007), $id))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(999991), $id))
        ->toThrow(AuthorizationException::class);

    $buyer->syncRoles(['member']);
    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951006), $id))
        ->toThrow(AuthorizationException::class);

    $buyer->assignRole('reseller');
    $buyer->forceFill(['is_active' => false])->save();

    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951006), $id))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);
});

it('refuses an externally marked paid order without a genuine journal and outbox', function (): void {
    $buyer = a1Buyer(951008);
    $id = a1Order($buyer);
    a1Credit($buyer, 1_000_000, 'F11BANK005');
    config()->set('sales.wallet_settlement_enabled', true);

    DB::table('sales_orders')->where('id', $id)->update(['status' => 'paid']);

    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951008), $id))
        ->toThrow(ValidationException::class)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0);
});

it('rolls back wallet debit and paid transition if the outbox cannot be inserted', function (): void {
    $buyer = a1Buyer(951009);
    $id = a1Order($buyer);
    a1Credit($buyer, 1_000_000, 'F11BANK006');
    config()->set('sales.wallet_settlement_enabled', true);

    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $id,
        'event_key' => 'sales-order:'.$id.':create',
        'state' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(951009), $id))
        ->toThrow(QueryException::class);

    expect(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('pending_payment')
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(1_000_000)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);
});

it('never permits the low-level wallet debit as an unguarded standalone call', function (): void {
    $buyer = a1Buyer(951010);
    $id = a1Order($buyer);

    expect(fn () => app(DebitWalletForOrder::class)->handle($buyer->id, $id, 10_000))
        ->toThrow(AuthorizationException::class);

    config()->set('sales.wallet_settlement_enabled', true);
    expect(fn () => app(DebitWalletForOrder::class)->handle($buyer->id, $id, 10_000))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0);
});

it('rejects replay when the purchase ledger postings are missing or unbalanced', function (): void {
    $buyer = a1Buyer(951010);
    $id = a1Order($buyer);
    a1Credit($buyer, 1_000_000, 'F11BANK007');
    config()->set('sales.wallet_settlement_enabled', true);
    $viewer = SubscriptionViewer::telegram(951010);

    app(SettleWalletOrder::class)->handle($viewer, $id);
    $journal = (int) DB::table('billing_journals')->where('kind', 'purchase')->value('id');
    DB::table('billing_postings')->where('journal_id', $journal)
        ->where('account_code', 'order_liability')->delete();

    expect(fn () => app(SettleWalletOrder::class)->handle($viewer, $id))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1);
});

