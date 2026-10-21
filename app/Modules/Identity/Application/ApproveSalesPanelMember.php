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
        $root = $this->roles->authorize($rootId);
        $member = User::query()->lockForUpdate()->find($memberId);
        if ($member === null || ! $member->is_active
            || ! $member->hasRole('member')
            || $member->hasAnyRole(['reseller', 'admin', 'super-admin'])) {
            throw ValidationException::withMessages(['role' => 'Applicant is no longer an ordinary member.']);
        }

        $this->assign->handle($root, $member, ['reseller']);
    }
}
