<?php

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ListVisiblePlans;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaunchSalesCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function launchCatalogRoot(int $telegramId = 901101, bool $active = true): User
{
    $root = User::factory()->create(['is_active' => $active]);
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => $telegramId]);

    return $root;
}

/** @return array<string, array> */
function launchCatalogSnapshot(): array
{
    $result = [];
    foreach ([
        'sales_products', 'sales_product_rates', 'sales_plans', 'sales_plan_prices',
        'sales_catalog_management_audits', 'sales_plan_audits', 'sales_plan_price_audits',
        'sales_plan_confirmations', 'sales_plan_price_confirmations',
        'sales_plan_internal_squad_bindings', 'users', 'telegram_accounts', 'model_has_roles',
    ] as $table) {
        $result[$table] = DB::table($table)->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $result;
}

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['901101', '901102']);
    Http::fake();
});

it('publishes the four 25 percent uplifted and upward-rounded plans with 35 percent reseller quotes', function (): void {
    $root = launchCatalogRoot();
    $member = User::factory()->create();
    $member->assignRole('member');
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    $identities = DB::table('users')->get()->toArray();
    config()->set('sales.reseller_discount_percent', 50);

    $this->seed(LaunchSalesCatalogSeeder::class);

    $this->assertDatabaseHas('sales_products', [
        'slug' => 'denagate', 'name' => 'اشتراک DenaGate',
        'min_gb' => 1, 'max_gb' => 100, 'is_active' => true,
    ]);
    $expected = [
        ['basic-10-15', 'پایه', 10, 15, 650_000, 422_500],
        ['economy-20-30', 'اقتصادی', 20, 30, 1_250_000, 812_500],
        ['standard-50-30', 'استاندارد', 50, 30, 3_000_000, 1_950_000],
        ['quarterly-100-90', 'سه‌ماهه', 100, 90, 6_500_000, 4_225_000],
    ];
    $visible = app(ListVisiblePlans::class)->handle($member->id);
    expect(array_column($visible, 'slug'))->toBe(array_column($expected, 0))
        ->and(app(ListVisiblePlans::class)->handle($reseller->id))->toEqual($visible);

    $originalTomans = [50_000, 100_000, 240_000, 520_000];
    foreach ($expected as $index => [$slug, $name, $gb, $days, $public, $partner]) {
        $plan = $visible[$index];
        $original = $originalTomans[$index];
        $roundedToman = intdiv($original * 125 + 499_999, 500_000) * 5_000;
        expect($public)->toBe($roundedToman * 10)
            ->and($partner)->toBe(intdiv($public * 65, 100));
        $this->assertDatabaseHas('sales_plans', [
            'id' => $plan->id, 'slug' => $slug, 'name' => $name,
            'kind' => 'hybrid', 'mode' => 'preset', 'audience' => 'both', 'status' => 'active',
            'traffic_gb' => $gb, 'duration_days' => $days,
            'min_gb' => $gb, 'max_gb' => $gb, 'min_days' => $days, 'max_days' => $days,
            'sort_order' => ($index + 1) * 10,
        ]);
        $this->assertDatabaseHas('sales_plan_prices', [
            'plan_id' => $plan->id, 'method' => 'fixed', 'status' => 'active',
            'fixed_price_rials' => $public, 'reseller_discount_percent' => 35,
            'minimum_total_rials' => $partner, 'configured_plan_revision' => $plan->revision,
        ]);
        $publicQuote = app(CalculatePlanQuote::class)->handle($member->id, $plan->id, $days, $gb);
        $partnerQuote = app(CalculatePlanQuote::class)->handle($reseller->id, $plan->id, $days, $gb);
        expect($publicQuote->totalPriceRials)->toBe($public)
            ->and($publicQuote->discountPercent)->toBe(0)
            ->and($partnerQuote->publicPriceRials)->toBe($public)
            ->and($partnerQuote->discountPercent)->toBe(35)
            ->and($partnerQuote->totalPriceRials)->toBe($partner);
    }

    expect(DB::table('users')->get()->toArray())->toEqual($identities)
        ->and(DB::table('sales_catalog_management_audits')->where('actor_user_id', $root->id)->count())->toBe(2)
        ->and(DB::table('sales_plan_audits')->where('actor_user_id', $root->id)->count())->toBe(8)
        ->and(DB::table('sales_plan_price_audits')->where('actor_user_id', $root->id)->count())->toBe(8);
    foreach ([
        'sales_product_rates', 'sales_orders', 'sales_renewal_orders', 'billing_wallets',
        'billing_journals', 'billing_postings', 'sales_fulfillment_outbox',
        'provisioning_operations', 'provisioning_subscriptions', 'sales_plan_internal_squad_bindings',
        'sales_plan_confirmations', 'sales_plan_price_confirmations',
    ] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
});

it('is an exact no-op on rerun including revisions audit rows and later Squad bindings', function (): void {
    launchCatalogRoot();
    $this->seed(LaunchSalesCatalogSeeder::class);
    $plan = DB::table('sales_plans')->where('slug', 'basic-10-15')->first();
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'internal_squad_uuid' => '11111111-1111-4111-8111-111111111111',
        'plan_revision' => $plan->revision, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = launchCatalogSnapshot();
    $this->travel(1)->days();

    $this->seed(LaunchSalesCatalogSeeder::class);

    expect(launchCatalogSnapshot())->toEqual($before);
    Http::assertNothingSent();
});

it('preserves manually edited prices names and disabled states on rerun', function (): void {
    launchCatalogRoot();
    $this->seed(LaunchSalesCatalogSeeder::class);
    DB::table('sales_products')->where('slug', 'denagate')->update(['name' => 'نام جدید محصول', 'is_active' => false]);
    DB::table('sales_plans')->where('slug', 'economy-20-30')->update([
        'name' => 'پلن ویرایش‌شده', 'status' => 'inactive', 'revision' => 3,
    ]);
    DB::table('sales_plan_prices')->update([
        'fixed_price_rials' => 8_000_000, 'reseller_discount_percent' => 10,
        'status' => 'inactive', 'revision' => 3,
    ]);
    $before = launchCatalogSnapshot();

    $this->seed(LaunchSalesCatalogSeeder::class);

    expect(launchCatalogSnapshot())->toEqual($before);
});

it('completes only missing launch plans without publishing or repricing an existing draft', function (): void {
    $root = launchCatalogRoot();
    app(ConfigureProduct::class)->handle('denagate', 'محصول موجود');
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'denagate', 'basic-10-15', 'پایه ویرایش‌شده', 'hybrid', 'preset', 'both',
        10, 15, 10, 10, 15, 15,
    ));
    $before = DB::table('sales_plans')->where('id', $plan->id)->first();

    $this->seed(LaunchSalesCatalogSeeder::class);

    expect(DB::table('sales_plans')->where('id', $plan->id)->first())->toEqual($before);
    $this->assertDatabaseCount('sales_products', 1);
    $this->assertDatabaseHas('sales_products', ['slug' => 'denagate', 'name' => 'محصول موجود']);
    $this->assertDatabaseCount('sales_plans', 4);
    $this->assertDatabaseCount('sales_plan_prices', 3);
    $this->assertDatabaseMissing('sales_plan_prices', ['plan_id' => $plan->id]);
});

it('rolls back all new catalog rows and audits if a launch slug belongs to another product', function (): void {
    $root = launchCatalogRoot();
    app(ConfigureProduct::class)->handle('other-product', 'Existing product');
    app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'other-product', 'standard-50-30', 'Existing plan', 'hybrid', 'preset', 'both',
        50, 30, 50, 50, 30, 30,
    ));
    $before = launchCatalogSnapshot();

    expect(fn () => $this->seed(LaunchSalesCatalogSeeder::class))
        ->toThrow(RuntimeException::class, 'belongs to another product');

    expect(launchCatalogSnapshot())->toEqual($before);
    Http::assertNothingSent();
});

it('preserves an inactive parent and rolls back missing plan setup instead of reactivating it', function (): void {
    launchCatalogRoot();
    app(ConfigureProduct::class)->handle('denagate', 'Inactive product', active: false);
    $before = launchCatalogSnapshot();

    expect(fn () => $this->seed(LaunchSalesCatalogSeeder::class))
        ->toThrow(ValidationException::class);

    expect(launchCatalogSnapshot())->toEqual($before);
});

it('requires an eligible existing root and never seeds credentials or privileges', function (string $case): void {
    if ($case === 'admin') {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
    } elseif ($case !== 'missing') {
        $root = launchCatalogRoot(active: $case !== 'inactive');
        if ($case === 'unlinked') {
            $root->telegramAccount()->delete();
        } elseif ($case === 'unallowlisted') {
            config()->set('super_admin.telegram_ids', ['901103', '901104']);
        } elseif ($case === 'single-allowlist') {
            config()->set('super_admin.telegram_ids', ['901101']);
        }
    }
    $before = launchCatalogSnapshot();

    expect(fn () => $this->seed(LaunchSalesCatalogSeeder::class))
        ->toThrow(RuntimeException::class, 'Enroll an active');

    expect(launchCatalogSnapshot())->toEqual($before);
    Http::assertNothingSent();
})->with(['missing', 'admin', 'inactive', 'unlinked', 'unallowlisted', 'single-allowlist']);

it('uses the first eligible root rather than assuming internal user ID one', function (): void {
    launchCatalogRoot(active: false);
    $root = launchCatalogRoot(901102);

    $this->seed(LaunchSalesCatalogSeeder::class);

    expect(DB::table('sales_catalog_management_audits')->distinct()->pluck('actor_user_id')->all())->toBe([$root->id])
        ->and(DB::table('sales_plan_audits')->distinct()->pluck('actor_user_id')->all())->toBe([$root->id])
        ->and(DB::table('sales_plan_price_audits')->distinct()->pluck('actor_user_id')->all())->toBe([$root->id]);
});

it('keeps the default seeder empty so deploys and ordinary seeding never publish launch prices', function (): void {
    launchCatalogRoot();
    $before = launchCatalogSnapshot();

    $this->seed(DatabaseSeeder::class);

    expect(launchCatalogSnapshot())->toEqual($before);
    Http::assertNothingSent();
});
