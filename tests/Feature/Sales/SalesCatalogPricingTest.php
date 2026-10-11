<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\ListProducts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function salesPricingFixture(): void
{
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    app(ConfigureRate::class)->handle('vpn-access', 15, 50_000, 25_000);
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    app(ConfigureRate::class)->handle('vpn-access', 60, 180_000, 18_000);
    app(ConfigureRate::class)->handle('vpn-access', 90, 240_000, 17_000);
}

it('lists enabled products and tariffs without seeding speculative prices', function (): void {
    $buyer = User::factory()->create();

    expect(app(ListProducts::class)->handle($buyer->id))->toBe([]);

    salesPricingFixture();
    $products = app(ListProducts::class)->handle($buyer->id);

    expect($products)->toHaveCount(1)
        ->and($products[0]->slug)->toBe('vpn-access')
        ->and($products[0]->name)->toBe('VPN Access')
        ->and($products[0]->minGb)->toBe(1)
        ->and($products[0]->maxGb)->toBe(100)
        ->and(collect($products[0]->rates)->pluck('durationDays')->all())->toBe([15, 30, 60, 90]);
});

it('quotes one through one hundred GB and rejects invalid volume or duration', function (): void {
    salesPricingFixture();
    $buyer = User::factory()->create();

    $one = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 1);
    $hundred = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 100);

    expect($one->publicPriceRials)->toBe(120_000)
        ->and($one->totalPriceRials)->toBe(120_000)
        ->and($one->discountPercent)->toBe(0)
        ->and($hundred->publicPriceRials)->toBe(2_100_000)
        ->and($hundred->trafficGb)->toBe(100);

    foreach ([0, -1, 101, 1000] as $volume) {
        expect(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, $volume))
            ->toThrow(ValidationException::class);
    }

    foreach ([0, 7, 45, 365] as $duration) {
        expect(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', $duration, 10))
            ->toThrow(ValidationException::class);
    }
});

it('uses one rate for direct and reseller purchases with exactly five percent off', function (): void {
    salesPricingFixture();
    Role::findOrCreate('reseller', 'web');
    $direct = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->syncRoles(['member', 'reseller']);

    $public = app(CalculateQuote::class)->handle($direct->id, 'vpn-access', 30, 10);
    $wholesale = app(CalculateQuote::class)->handle($reseller->id, 'vpn-access', 30, 10);

    expect($public->totalPriceRials)->toBe(300_000)
        ->and($wholesale->publicPriceRials)->toBe($public->publicPriceRials)
        ->and($wholesale->discountPercent)->toBe(5)
        ->and($wholesale->discountRials)->toBe(15_000)
        ->and($wholesale->totalPriceRials)->toBe(285_000);
});

it('rounds percentage discounts in integer rials without floats', function (): void {
    app(ConfigureProduct::class)->handle('small', 'Small');
    app(ConfigureRate::class)->handle('small', 15, 0, 11);
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');

    $quote = app(CalculateQuote::class)->handle($reseller->id, 'small', 15, 1);

    expect($quote->publicPriceRials)->toBe(11)
        ->and($quote->discountRials)->toBe(1)
        ->and($quote->totalPriceRials)->toBe(10);

    config()->set('sales.reseller_discount_percent', 0);
    expect(app(CalculateQuote::class)->handle($reseller->id, 'small', 15, 1)->totalPriceRials)->toBe(11);
    config()->set('sales.reseller_discount_percent', 7);
    expect(fn () => app(CalculateQuote::class)->handle($reseller->id, 'small', 15, 1))
        ->toThrow(ValidationException::class);
});

it('does not show disabled products or rates or disclose quotes for non-active buyers', function (): void {
    salesPricingFixture();
    $buyer = User::factory()->create();

    app(ConfigureRate::class)->handle('vpn-access', 15, 50_000, 25_000, false);
    expect(collect(app(ListProducts::class)->handle($buyer->id)[0]->rates)->pluck('durationDays')->all())
        ->toBe([30, 60, 90]);
    expect(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 15, 2))
        ->toThrow(ValidationException::class);

    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access', 1, 100, false);
    expect(app(ListProducts::class)->handle($buyer->id))->toBe([]);
    expect(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10))
        ->toThrow(ValidationException::class);

    $buyer->forceFill(['is_active' => false])->save();

    expect(fn () => app(ListProducts::class)->handle($buyer->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10))
        ->toThrow(AuthorizationException::class);
});

it('supports restricted product volumes and updates published prices without duplicate rates', function (): void {
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access', 10, 50);
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);

    $buyer = User::factory()->create();
    expect(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 9))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 51))
        ->toThrow(ValidationException::class);

    $original = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);
    app(ConfigureRate::class)->handle('vpn-access', 30, 120_000, 20_000);
    $latest = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);

    expect($original->totalPriceRials)->toBe(300_000)
        ->and($latest->totalPriceRials)->toBe(320_000)
        ->and(DB::table('sales_product_rates')->count())->toBe(1);
});

it('rejects invalid products, unsupported durations, unknown products and unsafe prices', function (): void {
    expect(fn () => app(ConfigureProduct::class)->handle('XX', 'Invalid'))->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureProduct::class)->handle('test', ''))->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureProduct::class)->handle('test', 'Bad'."\n".'Name'))->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureProduct::class)->handle('test', 'Test', 0, 100))->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureProduct::class)->handle('test', 'Test', 10, 101))->toThrow(ValidationException::class);

    expect(fn () => app(ConfigureRate::class)->handle('missing', 30, 20_000, 10_000))
        ->toThrow(ValidationException::class);

    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');

    expect(fn () => app(ConfigureRate::class)->handle('vpn-access', 45, 20_000, 10_000))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureRate::class)->handle('vpn-access', 30, 0, 0))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureRate::class)->handle('vpn-access', 30, -1, 10_000))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ConfigureRate::class)->handle('vpn-access', 30, 200_000_000_000, 10_000))
        ->toThrow(ValidationException::class);
});

it('keeps Sales pricing isolated from orders, wallet balances and provisioning', function (): void {
    salesPricingFixture();
    $buyer = User::factory()->create();

    app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 90, 75);
    app(ListProducts::class)->handle($buyer->id);

    expect(DB::table('billing_wallets')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
});

it('lets an operator configure catalog and prices via dedicated Artisan commands', function (): void {
    $this->artisan('sales:product:set vpn-access "VPN Access" --min-gb=1 --max-gb=100')
        ->assertExitCode(0);

    $this->artisan('sales:rate:set vpn-access 30 10000 2000')
        ->assertExitCode(0);

    expect(DB::table('sales_products')->count())->toBe(1)
        ->and(DB::table('sales_product_rates')->count())->toBe(1)
        ->and(DB::table('sales_product_rates')->value('base_price_rials'))->toBe(100_000)
        ->and(DB::table('sales_product_rates')->value('per_gb_rials'))->toBe(20_000);

    $buyer = User::factory()->create();
    expect(app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10)->totalPriceRials)->toBe(300_000);

    $this->artisan('sales:rate:set vpn-access 30 10000 2000 --inactive')->assertExitCode(0);
    expect(app(ListProducts::class)->handle($buyer->id))->toBe([]);
});
