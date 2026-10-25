<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateUserAccess
{
    /**
     * @param  list<string>|null  $roles
     */
    public function handle(User $actor, User $subject, ?array $roles, ?bool $active): User
    {
        return DB::transaction(function () use ($actor, $subject, $roles, $active): User {
            $subject = User::query()->lockForUpdate()->findOrFail($subject->getKey());
            $trust = app(SuperAdminTrust::class);

            if (! $trust->canManageUsers($actor)) {
                throw ValidationException::withMessages(['role' => 'Access denied.']);
            }

            if ($subject->hasRole('super-admin')) {
                throw ValidationException::withMessages(['role' => 'Super Admin accounts are protected; use a separate audited recovery procedure.']);
            }

            if ($actor->is($subject) && $active === false) {
                throw ValidationException::withMessages(['role' => 'You cannot deactivate your own account.']);
            }

            if ($roles !== null) {
                $subject = app(AssignUserRoles::class)->handle($actor, $subject, $roles);
            }

            if ($active !== null) {
                if (! $trust->eligible($actor) && app(AdminCapabilityAccess::class)->isOperator($subject)) {
                    throw ValidationException::withMessages(['role' => 'Only a trusted Super Admin can change an administrator.']);
                }

                if ($subject->isAdmin() && $active === false) {
                    $otherActiveAdmin = User::query()->whereKeyNot($subject->getKey())
                        ->where('is_active', true)->role('admin')->exists();

                    // Even one recognized Super Admin is sufficient for migration/bootstrap safety.
                    $otherSuperAdmins = User::query()->whereKeyNot($subject->getKey())
                        ->where('is_active', true)->role('super-admin')->get()
                        ->contains(fn (User $user): bool => $trust->eligible($user));

                    if (! $otherActiveAdmin && ! $otherSuperAdmins) {
                        throw ValidationException::withMessages(['role' => 'At least one active administrator is required.']);
                    }
                }

                $before = $subject->is_active;
                $subject->forceFill(['is_active' => $active])->save();
                app(RecordIdentityAudit::class)->handle($actor, 'identity.user.status_changed', 'user', (int) $subject->getKey(), [
                    'before' => $before,
                    'after' => $active,
                ]);
            }

            return $subject->refresh();
        });
    }
}
