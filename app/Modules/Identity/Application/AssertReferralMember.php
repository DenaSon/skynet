<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Caller locks both participants in ascending ID order inside its transaction. */
final class AssertReferralMember
{
    public function handle(int $userId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Referral membership requires a transaction.');
        }
        $user = User::query()->lockForUpdate()->find($userId);
        if ($user === null || ! $user->is_active || $user->roles()->lockForUpdate()->pluck('name')->all() !== ['member']
            || $user->permissions()->lockForUpdate()->first() !== null || ! $user->telegramAccount()->exists()) {
            throw new AuthorizationException;
        }
    }
}
