<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\AssignUserRoles;
use App\Modules\Identity\Application\ManageRoles;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManagePlanSquadBinding;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManageBaseCatalog;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\ReviewSalesPanelRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['996001', '996002']);
    Http::preventStrayRequests();
    Http::fake();
    Role::findOrCreate('reseller', 'web');
});

/** @param list<string> $permissions */
function a3Operator(int $telegramId, array $permissions): User
{
    $actor = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => $telegramId]);
    $role = Role::findOrCreate('a3-'.$telegramId, 'web');
    $role->syncPermissions($permissions);
    $actor->assignRole($role);

    return $actor;
}

function a3Pending(User $member): int
{
    return DB::table('sales_panel_requests')->insertGetId([
        'user_id' => $member->id,
        'first_name' => 'علی', 'last_name' => 'رضایی',
        'mobile' => '09123456789', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('registers only opt-in A3 grants and preserves ordinary admin and root role protections', function (): void {
    expect(Permission::query()->whereIn('name', ['plans.manage', 'resellers.review'])->count())->toBe(2);
    $admin = User::factory()->admin()->create();
    expect(app(AdminCapabilityAccess::class)->allowed($admin, 'plans.manage'))->toBeFalse()
        ->and(app(AdminCapabilityAccess::class)->allowed($admin, 'resellers.review'))->toBeFalse();
});

it('lets a linked plan manager create a validated plan with audit, without inheriting other privileges', function (): void {
    $manager = a3Operator(996010, ['plans.manage']);
    $quote = app(ResolveTelegramActor::class)->handle(996010);
    expect($quote?->canEnterAdmin())->toBeTrue()
        ->and($quote?->allows('plans.manage'))->toBeTrue()
        ->and($quote?->allows('resellers.review'))->toBeFalse()
        ->and($quote?->canManageRoles())->toBeFalse();

    app(ConfigureProduct::class)->handle('vpn-access', 'VPN access');
    $plans = app(ManagePlans::class);
    $plan = $plans->create($manager->id, new PlanDefinition(
        'vpn-access', 'a3-delegated-plan', 'Delegated plan',
        'hybrid', 'preset', 'both', 30, 30, 1, 100, 15, 90,
    ));
    expect($plans->find($manager->id, $plan->id)->slug)->toBe('a3-delegated-plan')
        ->and($plans->all($manager->id))->toHaveCount(1)
        ->and(DB::table('sales_plan_audits')->count())->toBe(1);
    expect(app(ManagePlanPricing::class)->find($manager->id, $plan->id))->toBeNull();
    expect(fn () => app(ManagePlanSquadBinding::class)->status($manager->id, $plan->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageRoles::class)->page($manager->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReviewSalesPanelRequest::class)->page($manager->id, 1))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('allows the existing base catalog read action but blocks a reseller-only reviewer from editing plans', function (): void {
    $plans = a3Operator(996011, ['plans.manage']);
    $reviewer = a3Operator(996012, ['resellers.review']);
    expect(app(ManageBaseCatalog::class)->all($plans->id))->toBeArray();
    expect(fn () => app(ManageBaseCatalog::class)->all($reviewer->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManagePlans::class)->all($reviewer->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManagePlanPricing::class)->find($reviewer->id, 1))
        ->toThrow(AuthorizationException::class);
});

it('delegates one pending Sales Panel approval through an audited reseller-only transition', function (): void {
    $reviewer = a3Operator(996020, ['resellers.review']);
    $buyer = User::factory()->create();
    $id = a3Pending($buyer);
    $review = app(ReviewSalesPanelRequest::class);
    expect($review->page($reviewer->id, 1))->toHaveCount(1)
        ->and($review->find($reviewer->id, $id)['user_id'])->toBe($buyer->id);

    $result = $review->decide($reviewer->id, $id, true);
    expect($result['status'])->toBe('approved')
        ->and($buyer->fresh()->getRoleNames()->all())->toBe(['reseller'])
        ->and(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->count())->toBe(1)
        ->and(DB::table('sales_panel_requests')->where('id', $id)->value('reviewed_by_user_id'))->toBe($reviewer->id);
    expect(fn () => $review->decide($reviewer->id, $id, true))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ManageRoles::class)->page($reviewer->id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('rejects elevated or stale applicants, self-review and direct arbitrary role assignments', function (): void {
    $reviewer = a3Operator(996021, ['resellers.review']);
    $admin = User::factory()->admin()->create();
    $elevated = a3Pending($admin);
    expect(fn () => app(ReviewSalesPanelRequest::class)->decide($reviewer->id, $elevated, true))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_panel_requests')->where('id', $elevated)->value('status'))->toBe('pending');
    expect(fn () => app(AssignUserRoles::class)->approveSalesPanelRequest($reviewer->id, $admin->id))
        ->toThrow(ValidationException::class);

    a3Pending($reviewer);
    expect(fn () => app(ReviewSalesPanelRequest::class)->decide($reviewer->id,
        (int) DB::table('sales_panel_requests')->where('user_id', $reviewer->id)->value('id'), true))
        ->toThrow(ValidationException::class);
    expect($reviewer->fresh()->hasRole('reseller'))->toBeFalse();
    expect(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->count())->toBe(0);
});

it('denies old queued actions immediately after permission revocation or disabling an account', function (): void {
    $manager = a3Operator(996030, ['plans.manage', 'resellers.review']);
    $buyer = User::factory()->create();
    $id = a3Pending($buyer);
    $manager->removeRole('a3-996030');

    expect(fn () => app(ReviewSalesPanelRequest::class)->decide($manager->id, $id, false))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManagePlans::class)->all($manager->id))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('sales_panel_requests')->where('id', $id)->value('status'))->toBe('pending');

    $manager->assignRole('a3-996030');
    DB::table('users')->where('id', $manager->id)->update(['is_active' => false]);
    expect(app(ResolveTelegramActor::class)->handle(996030))->toBeNull();
    expect(fn () => app(ReviewSalesPanelRequest::class)->page($manager->id, 1))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('lets a scoped reviewer reject a pending Sales Panel request without mutating user roles or money', function (): void {
    $reviewer = a3Operator(996022, ['resellers.review']);
    $buyer = User::factory()->create();
    $id = a3Pending($buyer);

    $result = app(ReviewSalesPanelRequest::class)->decide($reviewer->id, $id, false);

    expect($result['status'])->toBe('rejected')
        ->and($buyer->fresh()->getRoleNames()->all())->toBe(['member'])
        ->and(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_panel_requests')->where('id', $id)->value('reviewed_by_user_id'))->toBe($reviewer->id);
    expect(fn () => app(ReviewSalesPanelRequest::class)->decide($reviewer->id, $id, false))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});
