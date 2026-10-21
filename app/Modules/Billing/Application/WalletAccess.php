<?php

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class WalletAccess
{
    public function walletId(int $userId): int
    {
        app(AssertBillingAccess::class)->reseller($userId);

        return $this->createWallet($userId);
    }

    /** Customer-facing wallet read only: no reseller top-up or debit permission. */
    public function customerWalletId(int $userId): int
    {
        $user = User::query()->find($userId);
        if ($user === null || ! $user->is_active || ! $user->hasAnyRole(['member', 'reseller'])) {
            throw new AuthorizationException;
        }

        return $this->createWallet($userId);
    }

    private function createWallet(int $userId): int
    {
        // Unique user_id makes concurrent, first-time wallet creation safe.
        DB::table('billing_wallets')->insertOrIgnore([
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('billing_wallets')->where('user_id', $userId)->value('id');
    }
}
