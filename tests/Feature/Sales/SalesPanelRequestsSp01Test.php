<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesPanelRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('persists one member application with normalized phone and immutable owner data', function (): void {
    $member = User::factory()->create();
    $other = User::factory()->create();
    $requests = app(SalesPanelRequests::class);

    expect($requests->find($member->id))->toBeNull()
        ->and($requests->find($other->id))->toBeNull();

    $first = $requests->submit($member->id, 'علی', 'محمدی', '+۹۸۹۱۲۳۴۵۶۷۸۹');
    $again = $requests->submit($member->id, 'حسین', 'رضایی', '09121112233');

    expect($first['status'])->toBe('pending')
        ->and($first['first_name'])->toBe('علی')
        ->and($first['last_name'])->toBe('محمدی')
        ->and($first['mobile'])->toBe('09123456789')
        ->and($again)->toBe($first)
        ->and($requests->find($member->id))->toBe($first)
        ->and($requests->find($other->id))->toBeNull()
        ->and(DB::table('sales_panel_requests')->count())->toBe(1)
        ->and($member->fresh()->hasRole('member'))->toBeTrue()
        ->and($member->fresh()->hasRole('reseller'))->toBeFalse()
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('rejects malformed names or mobile without creating a request', function (): void {
    $member = User::factory()->create();
    $requests = app(SalesPanelRequests::class);

    expect(SalesPanelRequests::normalizeMobile('۰۹۱۲۳۴۵۶۷۸۹'))->toBe('09123456789')
        ->and(SalesPanelRequests::normalizeMobile('00989123456789'))->toBe('09123456789')
        ->and(SalesPanelRequests::normalizeMobile('123'))->toBeNull();
    foreach ([
        ['x', 'رضایی', '09123456789'],
        ['علی', '<script>', '09123456789'],
        ['علی', 'رضایی', '0912345678'],
        ['علی', 'رضایی', '09123-456789'],
    ] as $parts) {
        expect(fn () => $requests->submit($member->id, ...$parts))->toThrow(ValidationException::class);
    }
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
});

it('denies disabled members, admins and resellers and never escalates privileges', function (): void {
    $disabled = User::factory()->create(['is_active' => false]);
    Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    $admin = User::factory()->admin()->create();
    $requests = app(SalesPanelRequests::class);

    foreach ([$disabled, $reseller, $admin] as $user) {
        expect(fn () => $requests->submit($user->id, 'علی', 'رضایی', '09123456789'))
            ->toThrow(AuthorizationException::class);
    }
    expect(fn () => $requests->find($disabled->id))->toThrow(AuthorizationException::class);
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
});

it('preserves future review evidence instead of dropping application data on rollback', function (): void {
    $member = User::factory()->create();
    app(SalesPanelRequests::class)->submit($member->id, 'حسین', 'رضایی', '09123456789');
    $migration = require database_path('migrations/2026_10_21_000003_create_sales_panel_requests.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});
