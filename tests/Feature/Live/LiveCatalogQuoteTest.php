<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('super_admin.telegram_ids', ['933301', '933302']);
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
});

function live3aRoot(): User
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 933301]);
    app(ConfigureProduct::class)->handle('live-three', 'سرویس نمایندگی');

    return $root;
}

function live3aReseller(int $telegramId): User
{
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $reseller->id, 'telegram_user_id' => $telegramId]);

    return $reseller;
}

function live3aPlan(User $root, string $slug, string $mode = 'preset', string $audience = 'both', int $discount = 35): int
{
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        productSlug: 'live-three',
        slug: $slug,
        name: 'پلن '.$slug,
        kind: 'hybrid',
        mode: $mode,
        audience: $audience,
        trafficGb: $mode === 'preset' ? 30 : null,
        durationDays: $mode === 'preset' ? 30 : null,
        minGb: 1,
        maxGb: 100,
        minDays: 7,
        maxDays: 180,
    ));
    $manager = app(ManagePlans::class);
    $manager->confirmChange($root->id, $manager->requestChange($root->id, $plan->id, 1, 'activate'));

    $pricing = new PlanPricingDefinition(
        method: $mode === 'preset' ? 'fixed' : 'formula',
        fixedPriceRials: $mode === 'preset' ? 1_000_003 : null,
        basePriceRials: $mode === 'preset' ? 0 : 50_000,
        perGbRials: $mode === 'preset' ? 0 : 2_000,
        perDayRials: $mode === 'preset' ? 0 : 1_000,
        resellerDiscountPercent: $discount,
        minimumTotalRials: 1,
    );
    $prices = app(ManagePlanPricing::class);
    $priceRevision = $prices->configure($root->id, $plan->id, 2, 0, $pricing);
    $prices->confirmChange(
        $root->id,
        $prices->requestChange($root->id, $plan->id, 2, $priceRevision, 'activate'),
    );

    return $plan->id;
}

function live3aAssertNoCommerceWrites(): void
{
    foreach ([
        'sales_orders', 'sales_order_settlements', 'billing_journals', 'billing_postings',
        'sales_fulfillment_outbox', 'provisioning_operations', 'provisioning_subscriptions',
    ] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    Http::assertNothingSent();
}

it('offers published presets with the exact buyer-specific discount and no commerce writes', function (): void {
    $root = live3aRoot();
    $first = live3aReseller(933311);
    $second = live3aReseller(933312);
    $planId = live3aPlan($root, 'preset-thirty', discount: 35);
    $otherPlan = live3aPlan($root, 'other-discount', discount: 17);

    $this->actingAs($first)->withSession(['denagate_live.user_id' => $first->id]);

    Livewire::test(Dashboard::class)
        ->assertSee('خرید سرویس')
        ->call('showSection', 'shop')
        ->assertSee('پلن preset-thirty')
        ->assertSee('پلن other-discount')
        ->call('selectPlan', $planId)
        ->assertSet('selectedPlanId', $planId)
        ->call('previewQuote')
        ->assertSee('پیش‌فاکتور شما')
        ->assertSee('100,000.3')
        ->assertSee('35,000.1')
        ->assertSee('65,000.2')
        ->assertSee('۳۵', false) // Percentage is rendered from the current published tariff.
        ->call('backToPlans')
        ->assertSet('selectedPlanId', null)
        ->call('selectPlan', $otherPlan)
        ->call('previewQuote')
        ->assertSee('17٪')
        ->call('showSection', 'reports')
        ->assertSee('گزارش فروش')
        ->call('showSection', 'credit')
        ->assertSee('اعتبار من')
        ->call('showSection', 'services')
        ->assertSee('سرویس‌های من');

    $member = User::factory()->create();
    $publicQuote = app(CalculatePlanQuote::class)->handle($member->id, $planId, 30, 30);
    $resellerQuote = app(CalculatePlanQuote::class)->handle($second->id, $planId, 30, 30);
    expect($publicQuote->discountPercent)->toBe(0)
        ->and($publicQuote->totalPriceRials)->toBe(1_000_003)
        ->and($resellerQuote->discountPercent)->toBe(35)
        ->and($resellerQuote->totalPriceRials)->toBe(650_002);

    live3aAssertNoCommerceWrites();
});

it('prices custom reseller builders only for valid integer days and GB', function (): void {
    $root = live3aRoot();
    $reseller = live3aReseller(933313);
    $planId = live3aPlan($root, 'flexible-builder', 'builder', 'reseller', 10);

    $this->actingAs($reseller)->withSession(['denagate_live.user_id' => $reseller->id]);

    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->assertSee('پلن flexible-builder')
        ->call('selectPlan', $planId)
        ->assertSet('daysInput', '7')
        ->set('daysInput', '45')
        ->set('gbInput', '70')
        ->call('previewQuote')
        ->assertSee('پیش‌فاکتور شما')
        ->assertSee('21,150')
        ->set('daysInput', '0')
        ->call('previewQuote')
        ->assertSee('مقدار روز یا حجم معتبر نیست')
        ->assertDontSee('21,150')
        ->set('daysInput', '181')
        ->call('previewQuote')
        ->assertSee('برای این انتخاب قیمت قابل ارائه نیست')
        ->set('daysInput', '1e3')
        ->call('previewQuote')
        ->assertSee('مقدار روز یا حجم معتبر نیست')
        ->set('daysInput', '30')
        ->set('gbInput', '-1')
        ->call('previewQuote')
        ->assertSee('مقدار روز یا حجم معتبر نیست')
        ->set('gbInput', '101')
        ->call('previewQuote')
        ->assertSee('برای این انتخاب قیمت قابل ارائه نیست');

    live3aAssertNoCommerceWrites();
});

it('rejects unpublished, inactive and stale quotes and never displays old prices', function (): void {
    $root = live3aRoot();
    $reseller = live3aReseller(933314);
    $active = live3aPlan($root, 'available-plan');
    $hidden = live3aPlan($root, 'withdrawn-plan');
    $unpriced = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'live-three', 'missing-price', 'بدون قیمت', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 7, 180,
    ));
    DB::table('sales_plans')->where('id', $unpriced->id)->update(['status' => 'active']);
    DB::table('sales_plan_prices')->where('plan_id', $hidden)->update(['status' => 'inactive']);

    $this->actingAs($reseller)->withSession(['denagate_live.user_id' => $reseller->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->assertSee('پلن available-plan')
        ->assertDontSee('پلن withdrawn-plan')
        ->assertDontSee('بدون قیمت')
        ->call('selectPlan', $hidden)
        ->assertSee('این پلن در دسترس نیست')
        ->call('selectPlan', $active)
        ->call('previewQuote')
        ->assertSee('65,000.2');

    DB::table('sales_plan_prices')->where('plan_id', $active)->update(['status' => 'draft']);
    $page->call('previewQuote')
        ->assertSee('این پلن دیگر قابل ارائه نیست')
        ->assertDontSee('65,000.2')
        ->call('backToPlans')
        ->assertSet('selectedPlanId', null);

    live3aAssertNoCommerceWrites();
});

it('rejects revoked, inactive, anonymous and non-reseller access', function (): void {
    $root = live3aRoot();
    $owner = live3aReseller(933315);
    live3aPlan($root, 'private-plan');

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $component = Livewire::test(Dashboard::class)->call('showSection', 'shop')->assertSee('پلن private-plan');
    $owner->syncRoles(['member']);
    $component->call('previewQuote')->assertForbidden();

    $owner->assignRole('reseller');
    $owner->forceFill(['is_active' => false])->save();
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id])
        ->get('https://live.denagate.ir/dashboard')->assertForbidden();

    $member = User::factory()->create();
    $member->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 933316]);
    $this->actingAs($member)->withSession(['denagate_live.user_id' => $member->id])
        ->get('https://live.denagate.ir/dashboard')->assertForbidden();

    $this->post('https://live.denagate.ir/logout')->assertRedirect();
    live3aAssertNoCommerceWrites();
});
