<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function orderFixture(): void
{
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN');
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    app(ConfigureRate::class)->handle('vpn-access', 90, 250_000, 15_000);
}

function orderQuote(int $buyerId, int $gb = 10): \App\Modules\Sales\Application\PriceQuote
{
    return app(CalculateQuote::class)->handle($buyerId, 'vpn-access', 30, $gb);
}

it('creates a pending-payment order with an immutable pricing and volume snapshot', function (): void {
    orderFixture();
    $buyer = User::factory()->create();
    $expected = orderQuote($buyer->id);
    $token = bin2hex(random_bytes(16));

    $order = app(CreatePendingOrder::class)->handle($buyer->id, $expected, $token);

    expect($order->buyerUserId)->toBe($buyer->id)
        ->and($order->durationDays)->toBe(30)
        ->and($order->trafficGb)->toBe(10)
        ->and($order->publicPriceRials)->toBe(300_000)
        ->and($order->totalPriceRials)->toBe(300_000)
        ->and($order->discountPercent)->toBe(0)
        ->and($order->status)->toBe('pending_payment');

    // Future tariff edits must not rewrite an existing financial quote snapshot.
    app(ConfigureRate::class)->handle('vpn-access', 30, 200_000, 25_000);

    expect(app(OrderReader::class)->find($buyer->id, $order->id)->totalPriceRials)->toBe(300_000)
        ->and(app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10)->totalPriceRials)->toBe(450_000)
        ->and(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('billing_wallets')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
});

it('reseller orders snapshot discount using the same public catalog rate', function (): void {
    orderFixture();
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->assignRole(['member', 'reseller']);

    $quote = orderQuote($buyer->id);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));

    expect($order->publicPriceRials)->toBe(300_000)
        ->and($order->discountPercent)->toBe(5)
        ->and($order->discountRials)->toBe(15_000)
        ->and($order->totalPriceRials)->toBe(285_000);
});

it('prevents duplicates on repeated Telegram callback tokens even after tariff changes', function (): void {
    orderFixture();
    $buyer = User::factory()->create();
    $quote = orderQuote($buyer->id);
    $token = bin2hex(random_bytes(16));

    $first = app(CreatePendingOrder::class)->handle($buyer->id, $quote, $token);
    app(ConfigureRate::class)->handle('vpn-access', 30, 300_000, 20_000);
    $repeat = app(CreatePendingOrder::class)->handle($buyer->id, $quote, $token);

    expect($repeat->id)->toBe($first->id)
        ->and($repeat->totalPriceRials)->toBe(300_000)
        ->and(DB::table('sales_orders')->count())->toBe(1);

    $otherQuote = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);
    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $otherQuote, $token))
        ->toThrow(ValidationException::class);
});

it('rejects stale price before first insertion and forces user to review current amount', function (): void {
    orderFixture();
    $buyer = User::factory()->create();
    $oldQuote = orderQuote($buyer->id);

    app(ConfigureRate::class)->handle('vpn-access', 30, 200_000, 20_000);

    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $oldQuote, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('blocks checkout when role discount, product, duration or active status changes', function (): void {
    orderFixture();
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->assignRole(['member', 'reseller']);
    $quoted = orderQuote($buyer->id);

    $buyer->syncRoles(['member']);
    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $quoted, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);

    $buyer->assignRole('reseller');
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000, false);
    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $quoted, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);

    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN', 1, 100, false);
    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $quoted, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);

    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN');
    $buyer->forceFill(['is_active' => false])->save();
    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $quoted, bin2hex(random_bytes(16))))
        ->toThrow(AuthorizationException::class);
});

it('enforces order ownership on reads and idempotency keys across buyers', function (): void {
    orderFixture();
    $first = User::factory()->create();
    $other = User::factory()->create();
    $token = bin2hex(random_bytes(16));
    $created = app(CreatePendingOrder::class)->handle($first->id, orderQuote($first->id), $token);

    expect(app(OrderReader::class)->recent($first->id))->toHaveCount(1)
        ->and(app(OrderReader::class)->recent($other->id))->toBeEmpty();

    expect(fn () => app(OrderReader::class)->find($other->id, $created->id))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CreatePendingOrder::class)->handle($other->id, orderQuote($other->id), $token))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_orders')->count())->toBe(1);
});

it('rejects invalid checkout tokens and lists only the buyers ten newest orders', function (): void {
    orderFixture();
    $buyer = User::factory()->create();
    $quote = orderQuote($buyer->id);

    expect(fn () => app(CreatePendingOrder::class)->handle($buyer->id, $quote, 'guessed'))
        ->toThrow(ValidationException::class);

    for ($i = 0; $i < 12; $i++) {
        app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
    }

    $recent = app(OrderReader::class)->recent($buyer->id);
    expect($recent)->toHaveCount(10)
        ->and($recent[0]->id)->toBeGreaterThan($recent[9]->id);
});
