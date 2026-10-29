<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class AssignUserRoles
{
    /**
     * Only for an explicitly pending Sales Panel request, never a general role editor.
     * Caller holds the application row lock; role and applicant are verified again.
     */
    public function approveSalesPanelRequest(int $reviewerId, int $memberId): void
    {
        DB::transaction(function () use ($reviewerId, $memberId): void {
            app(AdminCapabilityAccess::class)->require($reviewerId, 'resellers.review');
            $actor = User::query()->findOrFail($reviewerId);
            $member = User::query()->lockForUpdate()->findOrFail($memberId);

            if ($actor->is($member) || ! $member->is_active
                || ! $member->hasRole('member')
                || $member->hasAnyRole(['reseller', 'admin', 'super-admin'])
                || app(AdminCapabilityAccess::class)->isOperator($member)
                || ! DB::table('sales_panel_requests')->where('user_id', $memberId)
                    ->where('status', 'pending')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['role' => 'Only an ordinary member with a pending application can be approved.']);
            }

            $previousRoles = $member->getRoleNames()->all();
            $member->syncRoles(['reseller']);
            app(RecordIdentityAudit::class)->handle($actor, 'identity.roles.assigned', 'user', $memberId, [
                'before' => $previousRoles,
                'after' => ['reseller'],
            ]);
        });
    }

    /**
     * @param  list<string>  $roles
     */
    public function handle(User $actor, User $subject, array $roles): User
    {
        return DB::transaction(function () use ($actor, $subject, $roles): User {
            $subject = User::query()->lockForUpdate()->findOrFail($subject->getKey());
            $trust = app(SuperAdminTrust::class);

            if (! $trust->canManageUsers($actor)) {
                throw ValidationException::withMessages(['role' => 'Not authorized to change roles.']);
            }

            if (in_array('super-admin', $roles, true) || $subject->hasRole('super-admin')) {
                throw ValidationException::withMessages(['role' => 'Super Admin roles can only be enrolled from the secure CLI.']);
            }

            if ($actor->is($subject) && ! in_array('admin', $roles, true) && $actor->hasRole('admin')) {
                throw ValidationException::withMessages(['role' => 'You cannot revoke your own administrative access.']);
            }

            if (! $trust->eligible($actor)
                && (app(AdminCapabilityAccess::class)->isOperator($subject) || $roles !== ['member'])) {
                throw ValidationException::withMessages(['role' => 'Only a trusted Super Admin can assign privileged or custom roles.']);
            }

            if (count($roles) === 0 || count(array_unique($roles)) !== count($roles)
                || Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->count() !== count($roles)) {
                throw ValidationException::withMessages(['role' => 'Unknown or duplicate role requested.']);
            }

            $before = $subject->getRoleNames()->all();
            $subject->syncRoles($roles);
            app(RecordIdentityAudit::class)->handle($actor, 'identity.roles.assigned', 'user', (int) $subject->getKey(), [
                'before' => $before,
                'after' => $roles,
            ]);

            return $subject->refresh();
        });
    }
}
