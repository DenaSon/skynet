<?php

use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function directoryRemoteUsernameFor(string $reference, string $state = 'active'): string
{
    $username = 'sk_'.substr(hash('sha256', $reference), 0, 24);
    DB::table('provisioning_subscriptions')->insert([
        'reference' => $reference,
        'remote_username' => $username,
        'remote_user_id' => null,
        'state' => $state,
        'last_operation_key' => $reference.':create',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $username;
}

it('finds the order buyer, including resellers, without requiring a completed provider link', function (): void {
    $admin = User::factory()->admin()->create();
    $reseller = User::factory()->create(['name' => 'Real reseller']);
    Role::findOrCreate('reseller', 'web');
    $reseller->syncRoles(['reseller']);

    $orderId = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $reseller->id,
        'idempotency_key' => str_repeat('a', 32),
        'product_slug' => 'test',
        'product_name' => 'Test subscription',
        'duration_days' => 30,
        'traffic_gb' => 10,
        'public_price_rials' => 100000,
        'discount_percent' => 0,
        'discount_rials' => 0,
        'total_price_rials' => 100000,
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $username = directoryRemoteUsernameFor('sales-order:'.$orderId, 'needs_review');
    $unrelated = User::factory()->create(['name' => $username]);
    $directory = app(BrowseUsers::class);

    $result = $directory->page($admin->id, $username);
    expect($result->total)->toBe(1)
        ->and($result->users[0]->id)->toBe($reseller->id)
        ->and($directory->page($admin->id, $username, resellersOnly: true)->users[0]->id)->toBe($reseller->id)
        ->and($directory->page($admin->id, $username, page: 2)->users)->toBe([])
        ->and($unrelated->id)->not->toBe($reseller->id);

    // A guessed RW username and an unrelated reference cannot identify a user.
    expect($directory->page($admin->id, 'sk_'.str_repeat('f', 24))->total)->toBe(0);
    $unknownReference = directoryRemoteUsernameFor('other:77');
    expect($directory->page($admin->id, $unknownReference)->total)->toBe(0);
});

it('resolves an existing free-trial username to the claiming user without provider traffic', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $trialId = DB::table('trial_claims')->insertGetId([
        'user_id' => $owner->id,
        'telegram_user_id' => 978654321,
        'phone_fingerprint' => hash('sha256', 'sample-trial-phone'),
        'state' => 'needs_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $username = directoryRemoteUsernameFor('free-trial:'.$trialId, 'needs_review');
    $directory = app(BrowseUsers::class);

    expect($directory->page($admin->id, $username)->total)->toBe(1)
        ->and($directory->page($admin->id, $username)->users[0]->id)->toBe($owner->id)
        ->and($directory->page($admin->id, (string) $owner->id)->users[0]->id)->toBe($owner->id)
        ->and($directory->page($admin->id, $owner->email)->users[0]->id)->toBe($owner->id);
});

it('preserves directory authorization for provider username search', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $search = 'sk_'.str_repeat('a', 24);
    $directory = app(BrowseUsers::class);

    expect(fn () => $directory->page($member->id, $search))->toThrow(AuthorizationException::class);
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => $directory->page($admin->id, $search))->toThrow(AuthorizationException::class);
});
