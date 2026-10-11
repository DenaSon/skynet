<?php

namespace App\Modules\Referral\Application;

use App\Modules\Referral\Infrastructure\Models\Referral;
use App\Modules\Referral\Infrastructure\Models\ReferralCode;
use Illuminate\Support\Facades\DB;

final class AttributeReferral
{
    /**
     * Must run within the same transaction as creation of the invited user.
     */
    public function handle(?string $code, int $invitedId): bool
    {
        if ($code === null || preg_match('/^[a-f0-9]{24}$/D', $code) !== 1) {
            return false;
        }

        $ownerId = ReferralCode::query()->where('code', $code)->value('user_id');

        if ($ownerId === null || (int) $ownerId === $invitedId) {
            return false;
        }

        // Never reassign an existing referral or allow attribution of a legacy account.
        if (Referral::query()->where('invited_user_id', $invitedId)->exists()) {
            return false;
        }

        // The caller creates an active user and a link in this transaction.
        DB::table('referrals')->insert([
            'referrer_user_id' => $ownerId,
            'invited_user_id' => $invitedId,
            'created_at' => now(),
        ]);

        return true;
    }
}
