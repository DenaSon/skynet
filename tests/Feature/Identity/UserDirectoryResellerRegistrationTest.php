<?php

declare(strict_types=1);

use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('projects reseller form fields only in details and checks reviewer permission on every read', function (): void {
    Permission::findOrCreate('users.view', 'web');
    Permission::findOrCreate('resellers.review', 'web');
    Role::findOrCreate('reseller', 'web');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');
    $reseller = User::factory()->create(['is_active' => false]);
    $reseller->syncRoles(['reseller']);
    DB::table('sales_panel_requests')->insert([
        'user_id' => $reseller->id,
        'first_name' => 'رضا',
        'last_name' => 'کریمی',
        'mobile' => '09123456789',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $directory = app(BrowseUsers::class);
    $listEntry = collect($directory->page($viewer->id)->users)->first(fn ($item) => $item->id === $reseller->id);
    expect($listEntry?->resellerRegistrationName)->toBeNull()
        ->and($listEntry?->resellerRegistrationMobile)->toBeNull()
        ->and($directory->find($viewer->id, $reseller->id)?->resellerRegistrationName)->toBe('رضا کریمی')
        ->and($directory->find($viewer->id, $reseller->id)?->resellerRegistrationMobile)->toBeNull();

    $viewer->givePermissionTo('resellers.review');
    expect($directory->find($viewer->id, $reseller->id)?->resellerRegistrationMobile)->toBe('09123456789');

    $viewer->revokePermissionTo('resellers.review');
    expect($directory->find($viewer->id, $reseller->id)?->resellerRegistrationMobile)->toBeNull();

    $reseller->syncRoles(['member']);
    expect($directory->find($viewer->id, $reseller->id)?->resellerRegistrationName)->toBeNull();

    $viewer->revokePermissionTo('users.view');
    expect(fn () => $directory->find($viewer->id, $reseller->id))
        ->toThrow(AuthorizationException::class);
});
