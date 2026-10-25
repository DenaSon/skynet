<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Validation\ValidationException;

/** Only promotes an active ordinary member through the audited Identity role Action. */
final readonly class ApproveSalesPanelMember
{
    public function __construct(private ManageRoles $roles, private AssignUserRoles $assign) {}

    public function handle(int $rootId, int $memberId): void
    {
        app(AdminCapabilityAccess::class)->require($rootId, 'resellers.review');
        $trusted = app(SuperAdminTrust::class)->eligible(User::query()->with('telegramAccount')->findOrFail($rootId));
        $member = User::query()->lockForUpdate()->find($memberId);
        if ($member === null || ! $member->is_active
            || ! $member->hasRole('member')
            || $member->hasAnyRole(['reseller', 'admin', 'super-admin'])) {
            throw ValidationException::withMessages(['role' => 'Applicant is no longer an ordinary member.']);
        }

        if ($trusted) {
            // Keep the original audited root flow unchanged.
            $this->assign->handle($this->roles->authorize($rootId), $member, ['reseller']);
        } else {
            // The only delegated elevation is pending ordinary member → reseller.
            $this->assign->approveSalesPanelRequest($rootId, $memberId);
        }
    }
}
