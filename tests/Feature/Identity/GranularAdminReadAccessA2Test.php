<?php

declare(strict_types=1);

use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Application\ReadCustomerSupportProfile;
use App\Modules\Identity\Application\ReadDelegatedAdminReports;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminProviderOverview;
use App\Modules\Provisioning\Application\ReadCustomerLiveServices;
use App\Modules\Sales\Application\ManualBankPayments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['995001', '995002']);
    Http::preventStrayRequests();
    Http::fake();
});

/** @param list<string> $permissions */
function a2Operator(int $telegram, array $permissions, string $roleName): User
{
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegram]);
    $role = Role::findOrCreate($roleName, 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

it('creates four additive read grants without changing built-in admin or payment reviewer roles', function (): void {
    $permissions = Permission::query()->whereIn('name', [
        'support.view', 'reports.view', 'finance.view', 'servers.view',
    ])->pluck('name')->all();
    expect($permissions)->toHaveCount(4)
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('users.manage'))->toBeTrue()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('finance.view'))->toBeFalse()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('servers.view'))->toBeFalse()
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('reports.view'))->toBeFalse();
});

it('permits support-only customer 360 and exact-owner local services, excluding finance', function (): void {
    $support = a2Operator(995010, ['support.view'], 'support-only');
    $customer = User::factory()->create();
    $actor = app(ResolveTelegramActor::class)->handle(995010);
    expect($actor?->canEnterAdmin())->toBeTrue()
        ->and($actor?->allows('support.view'))->toBeTrue()
        ->and($actor?->allows('users.manage'))->toBeFalse()
        ->and($actor?->allows('finance.view'))->toBeFalse();

    $profile = app(ReadCustomerSupportProfile::class)->handle($support->id, $customer->id);
    $services = app(ReadCustomerLiveServices::class)->page($support->id, $customer->id, 1);
    $entry = app(BrowseUsers::class)->find($support->id, $customer->id);

    expect($profile['financeAllowed'])->toBeFalse()
        ->and($profile['walletBalanceRials'])->toBeNull()
        ->and($entry?->canChangeStatus)->toBeFalse()
        ->and($services['items'])->toBe([]);
    expect(fn () => app(ReadAdminWalletSummary::class)->handle($support->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadDelegatedAdminReports::class)->handle($support->id))
        ->toThrow(AuthorizationException::class);
});

it('keeps users.view-only accounts out of customer 360 and protects old admin behavior', function (): void {
    $viewer = a2Operator(995011, ['users.view'], 'directory-only');
    $target = User::factory()->create();
    expect(app(BrowseUsers::class)->find($viewer->id, $target->id))->not->toBeNull();
    expect(fn () => app(ReadCustomerSupportProfile::class)->handle($viewer->id, $target->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadCustomerLiveServices::class)->page($viewer->id, $target->id, 1))
        ->toThrow(AuthorizationException::class);

    $admin = User::factory()->admin()->create();
    expect(app(ReadCustomerSupportProfile::class)->handle($admin->id, $target->id)['user']->id)
        ->toBe($target->id);
});

it('requires finance.view for ledger-derived balances, not bank approval or accounting mutation', function (): void {
    $finance = a2Operator(995012, ['finance.view'], 'finance-read');
    $actor = app(ResolveTelegramActor::class)->handle(995012);
    expect($actor?->canEnterAdmin())->toBeTrue()
        ->and($actor?->allows('topups.review'))->toBeFalse()
        ->and($actor?->allows('purchases.review'))->toBeFalse();

    $summary = app(ReadAdminWalletSummary::class)->handle($finance->id);
    $wallets = app(BrowseCustomerWallets::class)->page($finance->id);
    expect($summary['walletsTotal'])->toBeGreaterThanOrEqual(0)
        ->and($wallets)->toBeArray()
        ->and(DB::table('billing_journals')->count())->toBe(0);
    expect(fn () => app(ReviewTopup::class)->handle($finance->id, 1, false, 'REJECTED'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManualBankPayments::class)->pending($finance->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadDelegatedAdminReports::class)->handle($finance->id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('returns only safe operational counts to reports.view, without bank or wallet data', function (): void {
    $reporter = a2Operator(995013, ['reports.view'], 'reports-read');
    $target = User::factory()->create();

    $report = app(ReadDelegatedAdminReports::class)->handle($reporter->id);
    expect($report)->toHaveKeys(['users', 'activeUsers', 'orders', 'paidOrders', 'activeServices'])
        ->not->toHaveKey('walletBalanceRials')
        ->not->toHaveKey('salesTodayRials')
        ->and($report['users'])->toBeGreaterThanOrEqual(2);
    expect(fn () => app(ReadAdminWalletSummary::class)->handle($reporter->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadCustomerSupportProfile::class)->handle($reporter->id, $target->id))
        ->toThrow(AuthorizationException::class);
});

it('allows servers.view only the bounded GET overview, not infrastructure mutations', function (): void {
    $observer = a2Operator(995014, ['servers.view'], 'servers-read');
    $actor = app(ResolveTelegramActor::class)->handle(995014);
    expect($actor?->canEnterAdmin())->toBeTrue()
        ->and($actor?->allows('servers.view'))->toBeTrue()
        ->and($actor?->canManageRoles())->toBeFalse();

    $overview = app(ReadAdminProviderOverview::class)->handle($observer->id);
    expect($overview->state)->toBeIn(['error', 'unavailable', 'available']);
    expect(fn () => app(AssertCatalogManagementAccess::class)->handle($observer->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminWalletSummary::class)->handle($observer->id))
        ->toThrow(AuthorizationException::class);
});

it('immediately revokes read access for removed roles and disabled users', function (): void {
    $operator = a2Operator(995015, ['support.view', 'reports.view', 'finance.view', 'servers.view'], 'all-read');
    $target = User::factory()->create();

    expect(app(ReadCustomerSupportProfile::class)->handle($operator->id, $target->id)['user']->id)
        ->toBe($target->id);
    expect(app(ReadDelegatedAdminReports::class)->handle($operator->id))->toHaveKey('users');
    $operator->removeRole('all-read');

    expect(fn () => app(ReadCustomerSupportProfile::class)->handle($operator->id, $target->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminProviderOverview::class)->handle($operator->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminWalletSummary::class)->handle($operator->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadDelegatedAdminReports::class)->handle($operator->id))
        ->toThrow(AuthorizationException::class);

    $operator->assignRole('all-read');
    DB::table('users')->where('id', $operator->id)->update(['is_active' => false]);
    expect(app(ResolveTelegramActor::class)->handle(995015))->toBeNull();
    expect(fn () => app(ReadDelegatedAdminReports::class)->handle($operator->id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});
