<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManagePlanSquadBinding;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const OP02_UUID = '550e8400-e29b-41d4-a716-446655440001';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['982001', '982002']);
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'test-only');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    Http::preventStrayRequests();
    app(ConfigureProduct::class)->handle('op02-vpn', 'OP-02 product');
});

function op02Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 982001]);

    return $root;
}

function op02Plan(User $root): int
{
    return app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'op02-vpn', 'op02-plan', 'Plan OP-02', 'hybrid', 'preset', 'both',
        10, 30, 1, 100, 15, 90,
    ))->id;
}

function op02Panel(string $uuid = OP02_UUID): void
{
    Http::fake([
        'https://rw.example.test/api/internal-squads' => Http::response([
            'response' => ['total' => 1, 'internalSquads' => [
                ['uuid' => $uuid, 'name' => 'Staging service'],
            ]],
        ]),
        'https://rw.example.test/api/internal-squads/*' => Http::response([
            'response' => ['uuid' => $uuid, 'name' => 'Staging service', 'inbounds' => []],
        ]),
    ]);
}

it('lists only genuine GET-only Squads and binds after a fresh remote UUID verification', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    op02Panel();

    $action = app(ManagePlanSquadBinding::class);
    expect($action->choices($root->id, $plan))->toBe([
        ['uuid' => OP02_UUID, 'name' => 'Staging service'],
    ]);
    $action->bind($root->id, $plan, 1, OP02_UUID);
    expect(DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $plan)->value('internal_squad_uuid'))->toBe(OP02_UUID)
        ->and(DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $plan)->value('verified_at'))->not->toBeNull()
        ->and(DB::table('sales_plan_audits')->where('operation', 'squad_bound')->count())->toBe(1)
        ->and(config('services.remnawave.squad_bindings_live_verified'))->toBeFalse()
        ->and(config('services.remnawave.writes_enabled'))->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(static fn ($req): bool => $req->method() === 'GET');
});

it('rejects unlinked and ordinary admins before querying provider', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 982030]);
    Http::fake();

    expect(fn () => app(ManagePlanSquadBinding::class)->choices($admin->id, $plan))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManagePlanSquadBinding::class)->bind($admin->id, $plan, 1, OP02_UUID))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('blocks unknown, foreign or stale revisions and prevents rebinding plans with orders', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    op02Panel();

    expect(fn () => app(ManagePlanSquadBinding::class)->bind($root->id, $plan, 2, OP02_UUID))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_plan_internal_squad_bindings')->count())->toBe(0);
    Http::assertSentCount(1);

    DB::table('sales_orders')->insert([
        'buyer_user_id' => $root->id,
        'idempotency_key' => hash('sha256', 'op02-sample'),
        'product_slug' => 'op02-vpn',
        'product_name' => 'OP-02 product',
        'duration_days' => 30,
        'traffic_gb' => 10,
        'public_price_rials' => 100000,
        'discount_percent' => 0,
        'discount_rials' => 0,
        'total_price_rials' => 100000,
        'status' => 'pending_payment',
        'plan_id' => $plan,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => app(ManagePlanSquadBinding::class)->bind($root->id, $plan, 1, OP02_UUID))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_plan_internal_squad_bindings')->count())->toBe(0);
});

it('fails closed when the panel cannot prove the selected UUID', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    op02Panel('550e8400-e29b-41d4-a716-446655440002');

    expect(fn () => app(ManagePlanSquadBinding::class)->bind($root->id, $plan, 1, OP02_UUID))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_plan_internal_squad_bindings')->count())->toBe(0);
    Http::assertSentCount(1);
});

it('has admin-only callback registrations without enabling a Sales Squad mutation', function (): void {
    $adminRoutes = file_get_contents(base_path('app/Interfaces/Telegram/routes/admin.php'));
    $salesRoutes = file_get_contents(base_path('app/Interfaces/Telegram/routes/sales.php'));

    expect($adminRoutes)->toContain('plan:squad:list:{id}', 'plan:squad:select:{id}:{choice}', 'plan:squad:apply:{token}')
        ->and($salesRoutes)->not->toContain('plan:squad:');
});

it('reads the connected service name from a matching panel GET without enabling checkout', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    op02Panel();

    $action = app(ManagePlanSquadBinding::class);
    expect($action->status($root->id, $plan))->toBe(['state' => 'missing', 'name' => null]);
    Http::assertNothingSent();

    $action->bind($root->id, $plan, 1, OP02_UUID);
    expect($action->status($root->id, $plan))->toBe([
        'state' => 'connected', 'name' => 'Staging service',
    ]);
    Http::assertSentCount(2);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
    expect(config('services.remnawave.writes_enabled'))->toBeFalse()
        ->and(config('services.remnawave.squad_bindings_live_verified'))->toBeFalse();
});

it('shows stale local connections without pretending they are usable or calling the panel', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    op02Panel();

    app(ManagePlanSquadBinding::class)->bind($root->id, $plan, 1, OP02_UUID);
    DB::table('sales_plans')->where('id', $plan)->update(['revision' => 2]);

    expect(app(ManagePlanSquadBinding::class)->status($root->id, $plan))
        ->toBe(['state' => 'stale', 'name' => null]);
    Http::assertSentCount(1);
});

it('does not claim a valid remote name on a missing, mismatched or failed response', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan,
        'plan_revision' => 1,
        'internal_squad_uuid' => OP02_UUID,
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(['https://rw.example.test/api/internal-squads/*' => Http::response([
        'response' => ['uuid' => '550e8400-e29b-41d4-a716-446655440002', 'name' => 'Other service'],
    ])]);
    expect(app(ManagePlanSquadBinding::class)->status($root->id, $plan))
        ->toBe(['state' => 'unavailable', 'name' => null]);

    Http::fake(['https://rw.example.test/api/internal-squads/*' => Http::response(['message' => 'offline'], 503)]);
    expect(app(ManagePlanSquadBinding::class)->status($root->id, $plan))
        ->toBe(['state' => 'unavailable', 'name' => null]);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
});

it('prevents untrusted administrators from reading plan-Squad status or making provider requests', function (): void {
    $root = op02Root();
    $plan = op02Plan($root);
    $member = User::factory()->create();
    Http::fake();

    expect(fn () => app(ManagePlanSquadBinding::class)->status($member->id, $plan))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});
