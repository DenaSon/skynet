<?php

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ListVisiblePlans;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function b11Root(): User
{
    config()->set('super_admin.telegram_ids', ['881001', '881002']);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 881001]);

    return $root;
}

function b11Definition(
    string $slug = 'standard-30',
    string $kind = 'hybrid',
    string $mode = 'preset',
    string $audience = 'both',
): PlanDefinition {
    return new PlanDefinition(
        productSlug: 'vpn-access',
        slug: $slug,
        name: 'پلن استاندارد',
        kind: $kind,
        mode: $mode,
        audience: $audience,
        trafficGb: $kind === 'time' || $mode === 'builder' ? null : 30,
        durationDays: $mode === 'builder' ? null : 30,
        minGb: $kind === 'time' ? null : 1,
        maxGb: $kind === 'time' ? null : 100,
        minDays: 7,
        maxDays: 180,
        sortOrder: 10,
        featured: true,
    );
}

function b11Product(): void
{
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN access');
}

function b11Activate(User $root, int $id, int $revision = 1): void
{
    $manage = app(ManagePlans::class);
    $token = $manage->requestChange($root->id, $id, $revision, 'activate');
    $manage->confirmChange($root->id, $token);
}

it('creates draft presets with typed limits without prices or provider writes', function (): void {
    b11Product();
    $root = b11Root();
    Http::preventStrayRequests();
    Http::fake();

    $manage = app(ManagePlans::class);
    $hybrid = $manage->create($root->id, b11Definition());
    $time = $manage->create($root->id, b11Definition('time-unlimited', 'time', 'preset', 'reseller'));
    $volume = $manage->create($root->id, b11Definition('volume-only', 'volume', 'preset', 'public'));
    $builder = $manage->create($root->id, b11Definition('custom-builder', 'hybrid', 'builder', 'reseller'));

    expect($hybrid->status)->toBe('draft')
        ->and($hybrid->trafficGb)->toBe(30)
        ->and($hybrid->durationDays)->toBe(30)
        ->and($hybrid->revision)->toBe(1)
        ->and($time->trafficGb)->toBeNull()
        ->and($volume->kind)->toBe('volume')
        ->and($builder->durationDays)->toBeNull()
        ->and($builder->trafficGb)->toBeNull()
        ->and($manage->all($root->id))->toHaveCount(4)
        ->and(DB::table('sales_plan_audits')->where('operation', 'created')->count())->toBe(4)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);

    Http::assertNothingSent();
});

it('enforces the plan type, audience and precise bounds without accepting invalid combinations', function (): void {
    b11Product();
    $root = b11Root();
    $manage = app(ManagePlans::class);

    foreach ([
        new PlanDefinition('vpn-access', 'bad_plan', 'Valid', 'hybrid', 'preset', 'both', 20, 30, 1, 100, 1, 180),
        new PlanDefinition('vpn-access', 'bad-name', "Bad\nName", 'hybrid', 'preset', 'both', 20, 30, 1, 100, 1, 180),
        new PlanDefinition('vpn-access', 'time-limit', 'Time', 'time', 'preset', 'both', 10, 30, null, null, 7, 90),
        new PlanDefinition('vpn-access', 'time-gb', 'Time', 'time', 'preset', 'both', null, 30, 1, 100, 7, 90),
        new PlanDefinition('vpn-access', 'zero-traffic', 'Zero', 'volume', 'preset', 'both', 0, 30, 1, 100, 7, 90),
        new PlanDefinition('vpn-access', 'too-much', 'Too much', 'volume', 'preset', 'both', 2049, 30, 1, 2049, 7, 90),
        new PlanDefinition('vpn-access', 'duration-bad', 'Days', 'hybrid', 'preset', 'both', 10, 181, 1, 100, 7, 180),
        new PlanDefinition('vpn-access', 'public-builder', 'Builder', 'hybrid', 'builder', 'public', null, null, 1, 100, 7, 180),
        new PlanDefinition('vpn-access', 'builder-fixed', 'Builder', 'hybrid', 'builder', 'reseller', 10, null, 1, 100, 7, 180),
        new PlanDefinition('vpn-access', 'unknown-kind', 'Unknown', 'wrong', 'preset', 'both', 10, 30, 1, 100, 7, 90),
        new PlanDefinition('vpn-access', 'unknown-audience', 'Unknown', 'hybrid', 'preset', 'everyone', 10, 30, 1, 100, 7, 90),
        new PlanDefinition('vpn-access', 'out-of-order', 'Days', 'hybrid', 'preset', 'both', 10, 30, 100, 1, 7, 90),
    ] as $definition) {
        expect(fn () => $manage->create($root->id, $definition))
            ->toThrow(ValidationException::class);
    }

    expect(DB::table('sales_plans')->count())->toBe(0);
});

it('requires a live allowlisted root for every management read and mutation', function (): void {
    b11Product();
    $root = b11Root();
    $buyer = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $manage = app(ManagePlans::class);
    $plan = $manage->create($root->id, b11Definition());

    expect(fn () => $manage->create($buyer->id, b11Definition('bad-access')))->toThrow(AuthorizationException::class)
        ->and(fn () => $manage->all($admin->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $manage->find($buyer->id, $plan->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $manage->requestChange($buyer->id, $plan->id, 1, 'activate'))->toThrow(AuthorizationException::class);

    $token = $manage->requestChange($root->id, $plan->id, 1, 'activate');
    $root->syncRoles(['member']);
    expect(fn () => $manage->confirmChange($root->id, $token))->toThrow(AuthorizationException::class);
    $root->assignRole('super-admin');
    config()->set('super_admin.telegram_ids', ['881002', '881003']);
    expect(fn () => $manage->confirmChange($root->id, $token))->toThrow(AuthorizationException::class);

    expect(DB::table('sales_plans')->where('id', $plan->id)->value('status'))->toBe('draft');
});

it('filters catalog by fresh reseller role, active account and parent product', function (): void {
    b11Product();
    $root = b11Root();
    Role::findOrCreate('reseller', 'web');
    $member = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->syncRoles(['member', 'reseller']);

    $manage = app(ManagePlans::class);
    $public = $manage->create($root->id, b11Definition('public-plan', 'hybrid', 'preset', 'public'));
    $both = $manage->create($root->id, b11Definition('both-plan'));
    $exclusive = $manage->create($root->id, b11Definition('reseller-plan', 'time', 'preset', 'reseller'));
    b11Activate($root, $public->id);
    b11Activate($root, $both->id);
    b11Activate($root, $exclusive->id);

    $read = app(ListVisiblePlans::class);
    expect(collect($read->handle($member->id))->pluck('slug')->all())->toBe(['public-plan', 'both-plan'])
        ->and(collect($read->handle($reseller->id))->pluck('slug')->all())->toBe(['public-plan', 'both-plan', 'reseller-plan']);

    expect(fn () => $read->find($member->id, $exclusive->id))->toThrow(ValidationException::class);
    $reseller->syncRoles(['member']);
    expect(collect($read->handle($reseller->id))->pluck('slug')->all())->not->toContain('reseller-plan');

    $member->forceFill(['is_active' => false])->save();
    expect(fn () => $read->handle($member->id))->toThrow(AuthorizationException::class);
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN access', 1, 100, false);
    expect($read->handle($reseller->id))->toBe([]);
});

it('requires actor-bound single-use confirmation and rejects stale version or expiry', function (): void {
    b11Product();
    $root = b11Root();
    $manage = app(ManagePlans::class);
    $plan = $manage->create($root->id, b11Definition());

    $other = User::factory()->create();
    $other->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 881002]);
    $token = $manage->requestChange($root->id, $plan->id, 1, 'activate');

    expect(fn () => $manage->confirmChange($other->id, $token))->toThrow(ValidationException::class);
    $active = $manage->confirmChange($root->id, $token);

    expect($active?->status)->toBe('active')
        ->and($active?->revision)->toBe(2)
        ->and(fn () => $manage->confirmChange($root->id, $token))->toThrow(ValidationException::class);

    $expire = $manage->requestChange($root->id, $plan->id, 2, 'deactivate');
    $this->travel(4)->minutes();
    expect(fn () => $manage->confirmChange($root->id, $expire))->toThrow(ValidationException::class);
    $this->travelBack();

    $stale = $manage->requestChange($root->id, $plan->id, 2, 'deactivate');
    $changed = $manage->update($root->id, $plan->id, 2, b11Definition('updated-plan'));
    expect($changed->revision)->toBe(3)
        ->and(fn () => $manage->confirmChange($root->id, $stale))->toThrow(ValidationException::class)
        ->and(DB::table('sales_plans')->where('id', $plan->id)->value('status'))->toBe('active');
});

it('supports deactivation, archival and permanent deletion only for unsold plans', function (): void {
    b11Product();
    $root = b11Root();
    $manage = app(ManagePlans::class);
    $plan = $manage->create($root->id, b11Definition());
    b11Activate($root, $plan->id);

    expect(fn () => $manage->requestChange($root->id, $plan->id, 2, 'delete'))->toThrow(ValidationException::class)
        ->and(fn () => $manage->requestChange($root->id, $plan->id, 2, 'archive'))->toThrow(ValidationException::class);

    $deactivate = $manage->requestChange($root->id, $plan->id, 2, 'deactivate');
    $inactive = $manage->confirmChange($root->id, $deactivate);
    expect($inactive?->status)->toBe('inactive');

    $archive = $manage->requestChange($root->id, $plan->id, 3, 'archive');
    $archived = $manage->confirmChange($root->id, $archive);
    expect($archived?->status)->toBe('archived')
        ->and(fn () => $manage->update($root->id, $plan->id, 4, b11Definition()))
        ->toThrow(ValidationException::class);

    $delete = $manage->requestChange($root->id, $plan->id, 4, 'delete');
    expect($manage->confirmChange($root->id, $delete))->toBeNull()
        ->and(DB::table('sales_plans')->count())->toBe(0)
        ->and(DB::table('sales_plan_audits')->where('operation', 'deleted')->count())->toBe(1);
});

it('cannot delete a plan tied to an existing immutable order', function (): void {
    b11Product();
    $root = b11Root();
    $manage = app(ManagePlans::class);
    $plan = $manage->create($root->id, b11Definition());

    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    $buyer = User::factory()->create();
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
    DB::table('sales_orders')->where('id', $order->id)->update(['plan_id' => $plan->id]);

    $token = $manage->requestChange($root->id, $plan->id, 1, 'delete');

    expect(fn () => $manage->confirmChange($root->id, $token))->toThrow(ValidationException::class)
        ->and(DB::table('sales_plans')->where('id', $plan->id)->exists())->toBeTrue()
        ->and(DB::table('sales_orders')->where('id', $order->id)->value('plan_id'))->toBe($plan->id)
        ->and(DB::table('sales_plan_audits')->where('operation', 'deleted')->count())->toBe(0);
});

it('does not connect new plans to F09 quote/checkout or alter existing payment gates', function (): void {
    b11Product();
    $root = b11Root();
    $plan = app(ManagePlans::class)->create($root->id, b11Definition('not-yet-priced'));
    b11Activate($root, $plan->id);

    $buyer = User::factory()->create();
    expect(app(ListVisiblePlans::class)->handle($buyer->id))->toHaveCount(1)
        ->and(DB::table('sales_product_rates')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(config('sales.manual_bank_review_enabled'))->toBeFalse()
        ->and(config('sales.wallet_settlement_enabled'))->toBeFalse()
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
});
