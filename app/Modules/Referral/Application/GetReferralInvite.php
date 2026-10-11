<?php

namespace App\Modules\Referral\Application;

use App\Modules\Identity\Application\ResolveActiveUser;
use App\Modules\Referral\Infrastructure\Models\Referral;
use App\Modules\Referral\Infrastructure\Models\ReferralCode;
use Illuminate\Support\Facades\DB;

final class GetReferralInvite
{
    public function handle(int $userId): ReferralInvite
    {
        app(ResolveActiveUser::class)->handle($userId);

        $code = DB::transaction(function () use ($userId): string {
            $existing = ReferralCode::query()->where('user_id', $userId)->first();

            if ($existing !== null) {
                return $existing->code;
            }

            // Lock the parent user to serialize two simultaneous code-generation requests.
            app(ResolveActiveUser::class)->handle($userId, lock: true);
            $existing = ReferralCode::query()->where('user_id', $userId)->first();

            if ($existing !== null) {
                return $existing->code;
            }

            $code = bin2hex(random_bytes(12));
            ReferralCode::query()->create(['user_id' => $userId, 'code' => $code]);

            return $code;
        });

        return new ReferralInvite(
            code: $code,
            joinedCount: Referral::query()->where('referrer_user_id', $userId)->count(),
        );
    }
}
