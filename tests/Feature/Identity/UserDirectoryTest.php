<?php

use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Application\ChangeUserStatus;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('paginates consistently and searches by id, name or email', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(14)->create();
    $alice = User::factory()->create(['name' => 'Alice Baker', 'email' => 'alice@example.test']);
    $directory = app(BrowseUsers::class);

    $pageOne = $directory->page($admin->id);
    $pageTwo = $directory->page($admin->id, page: 2);

    expect($pageOne->total)->toBe(16)
        ->and($pageOne->lastPage)->toBe(3)
        ->and(count($pageOne->users))->toBe(6)
        ->and($pageTwo->users[0]->id)->toBeGreaterThan($pageOne->users[5]->id);

    expect($directory->page($admin->id, 'alice')->users[0]->id)->toBe($alice->id)
        ->and($directory->page($admin->id, 'alice@example.test')->total)->toBe(1)
        ->and($directory->page($admin->id, (string) $alice->id)->total)->toBe(1)
        ->and($directory->page($admin->id, 'no-such-user')->total)->toBe(0);
});

it('denies directory access to members and deactivated administrators', function (): void {
    $member = User::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(fn () => app(BrowseUsers::class)->page($member->id))->toThrow(AuthorizationException::class);
    $admin->forceFill(['is_active' => false])->save();
    expect(fn () => app(BrowseUsers::class)->page($admin->id))->toThrow(AuthorizationException::class);
});

it('updates status through the existing guarded action and appends an audit event', function (): void {
    $admin = User::factory()->admin()->create();
    $subject = User::factory()->create();
    $change = app(ChangeUserStatus::class);

    $updated = $change->handle($admin->id, $subject->id, true, false);
    expect($updated->active)->toBeFalse()
        ->and($subject->fresh()->is_active)->toBeFalse();

    expect(DB::table('identity_audit_events')->where('event', 'identity.user.status_changed')
        ->where('subject_id', $subject->id)->count())->toBe(1);

    expect(fn () => $change->handle($admin->id, $subject->id, true, false))
        ->toThrow(ValidationException::class);
    expect(DB::table('identity_audit_events')->where('event', 'identity.user.status_changed')->count())->toBe(1);

    expect($change->handle($admin->id, $subject->id, false, true)->active)->toBeTrue();
});

it('forbids member mutations, self-deactivation and peer admin changes', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $peer = User::factory()->admin()->create();
    $change = app(ChangeUserStatus::class);

    expect(fn () => $change->handle($member->id, $admin->id, true, false))->toThrow(ValidationException::class);
    expect(fn () => $change->handle($admin->id, $admin->id, true, false))->toThrow(ValidationException::class);
    expect(fn () => $change->handle($admin->id, $peer->id, true, false))->toThrow(ValidationException::class);
    expect($admin->fresh()->is_active)->toBeTrue()
        ->and($peer->fresh()->is_active)->toBeTrue();
});

it('protects all Super Admin accounts from Telegram status mutations', function (): void {
    config()->set('super_admin.telegram_ids', ['12121', '34343']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 12121]);

    $second = User::factory()->create();
    $second->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $second->id, 'telegram_user_id' => 34343]);

    $directory = app(BrowseUsers::class);
    expect($directory->find($root->id, $second->id)?->canChangeStatus)->toBeFalse();
    expect(fn () => app(ChangeUserStatus::class)->handle($root->id, $second->id, true, false))
        ->toThrow(ValidationException::class);
    expect($second->fresh()->is_active)->toBeTrue();
});
