<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\SubscriptionOwnershipQuery;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\OrderDetails;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function e1Catalog(): void
{
    app(ConfigureProduct::class)->handle('test-vpn', 'VPN Plan');
    app(ConfigureRate::class)->handle('test-vpn', 30, 100_000, 20_000);
}

function e1Order(User $buyer): OrderDetails
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'test-vpn', 30, 10);

    return app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
}

function e1Subscription(int $orderId, string $state = 'active'): int
{
    $id = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'test-order-'.$orderId,
        'remote_username' => 'sk_test_'.$orderId,
        'remote_user_id' => $orderId + 1000,
        'state' => $state,
        'last_operation_key' => 'test-operation-'.$orderId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId,
        'provisioning_subscription_id' => $id,
        'created_at' => now(),
    ]);

    return $id;
}

it('lists only the active buyers own orders with safe keyset cursor pagination and no provider requests', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();
    $other = User::factory()->create();

    for ($i = 0; $i < 23; $i++) {
        e1Order($buyer);
    }

    e1Order($other);
    Http::preventStrayRequests();
    Http::fake();
    $reader = app(SubscriptionOwnershipQuery::class);

    $first = $reader->page($buyer->id, 20);
    $second = $reader->page($buyer->id, 20, $first->nextBeforeOrderId);

    expect($first->items)->toHaveCount(20)
        ->and($second->items)->toHaveCount(3)
        ->and($first->nextBeforeOrderId)->toBeInt()
        ->and($second->nextBeforeOrderId)->toBeNull()
        ->and($first->items[0]->orderId)->toBeGreaterThan($first->items[19]->orderId)
        ->and($first->items[19]->orderId)->toBeGreaterThan($second->items[0]->orderId)
        ->and(collect([...$first->items, ...$second->items])->pluck('status')->unique()->all())->toBe(['pending_payment'])
        ->and($reader->page($other->id)->items)->toHaveCount(1);

    Http::assertNothingSent();
});

it('rejects invalid paging and disabled buyers without exposing foreign rows', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $order = e1Order($buyer);
    $query = app(SubscriptionOwnershipQuery::class);

    expect(fn () => $query->page($buyer->id, 0))->toThrow(ValidationException::class)
        ->and(fn () => $query->page($buyer->id, 51))->toThrow(ValidationException::class)
        ->and(fn () => $query->page($buyer->id, 20, 0))->toThrow(ValidationException::class)
        ->and(fn () => $query->detail($other->id, $order->id))->toThrow(ValidationException::class);

    $buyer->forceFill(['is_active' => false])->save();

    expect(fn () => $query->page($buyer->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $query->detail($buyer->id, $order->id))->toThrow(AuthorizationException::class);
});

it('keeps pending orders unlinked, with immutable Sales pricing, and never reveals credentials', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();
    $order = e1Order($buyer);
    $query = app(SubscriptionOwnershipQuery::class);

    $detail = $query->detail($buyer->id, $order->id);

    expect($detail->summary->orderId)->toBe($order->id)
        ->and($detail->summary->durationDays)->toBe(30)
        ->and($detail->summary->trafficGb)->toBe(10)
        ->and($detail->summary->totalPriceRials)->toBe(300_000)
        ->and($detail->summary->status)->toBe('pending_payment')
        ->and($detail->canRequestLink)->toBeFalse()
        ->and(DB::table('provisioning_order_links')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);

    $json = json_encode($detail, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('remote_user_id')
        ->not->toContain('subscriptionUrl')
        ->not->toContain('reference')
        ->not->toContain('remote_username');
});

it('normalizes paid and provider state only after a valid Sales-owned link', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();
    $order = e1Order($buyer);
    $query = app(SubscriptionOwnershipQuery::class);

    // Test-only database settlement simulates the future F11 paid transition;
    // no production settlement action exists in F10-E1.
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'paid']);
    expect($query->detail($buyer->id, $order->id)->summary->status)->toBe('provisioning');

    $subId = e1Subscription($order->id, 'reserved');

    expect($query->detail($buyer->id, $order->id)->summary->status)->toBe('provisioning');

    DB::table('provisioning_subscriptions')->where('id', $subId)->update(['state' => 'needs_review']);
    expect($query->detail($buyer->id, $order->id)->summary->status)->toBe('needs_review');

    DB::table('provisioning_subscriptions')->where('id', $subId)->update(['state' => 'failed']);
    expect($query->detail($buyer->id, $order->id)->summary->status)->toBe('failed');

    DB::table('provisioning_subscriptions')->where('id', $subId)->update(['state' => 'active']);
    $detail = $query->detail($buyer->id, $order->id);

    expect($detail->summary->status)->toBe('active')
        ->and($detail->canRequestLink)->toBeTrue()
        ->and(json_encode($detail, JSON_THROW_ON_ERROR))->not->toContain('sk_test_')
        ->not->toContain((string) ($order->id + 1000));

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'refunded']);
    $revoked = $query->detail($buyer->id, $order->id);
    expect($revoked->summary->status)->toBe('failed')
        ->and($revoked->canRequestLink)->toBeFalse();
});

it('fails closed when an unpaid order is illegitimately linked or a state is unknown', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();
    $order = e1Order($buyer);
    $query = app(SubscriptionOwnershipQuery::class);

    e1Subscription($order->id);

    expect(fn () => $query->detail($buyer->id, $order->id))
        ->toThrow(UnexpectedValueException::class, 'unpaid');

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'paid']);
    DB::table('provisioning_subscriptions')->where('reference', 'test-order-'.$order->id)
        ->update(['state' => 'unrecognized']);

    expect(fn () => $query->detail($buyer->id, $order->id))
        ->toThrow(UnexpectedValueException::class, 'Unrecognized provisioning');

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'unrecognized']);

    expect(fn () => $query->detail($buyer->id, $order->id))
        ->toThrow(UnexpectedValueException::class, 'Unrecognized Sales');
});

it('enforces one immutable Sales-order/provider-subscription pair with SQL foreign keys', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();
    $first = e1Order($buyer);
    $second = e1Order($buyer);

    $subscriptionId = e1Subscription($first->id);

    expect(fn () => DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $second->id,
        'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $first->id,
        'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('provisioning_order_links')->insert([
        'sales_order_id' => 999_999,
        'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(2);
});

it('batches subscription mapping lookups once for a page rather than performing provider N+1 reads', function (): void {
    e1Catalog();
    $buyer = User::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        $order = e1Order($buyer);
        DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'paid']);
        e1Subscription($order->id);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    $results = app(SubscriptionOwnershipQuery::class)->page($buyer->id, 12);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $mapped = array_filter($queries, static fn (array $entry): bool => str_contains($entry['query'], 'provisioning_order_links'));

    expect($results->items)->toHaveCount(12)
        ->and(count($mapped))->toBe(1)
        ->and(collect($results->items)->pluck('status')->unique()->all())->toBe(['active']);
});
