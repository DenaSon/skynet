<?php

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Facades\DB;

final class WalletAccess
{
    public function walletId(int $userId): int
    {
        app(AssertBillingAccess::class)->reseller($userId);

        // Unique user_id makes concurrent, first-time wallet creation safe.
        DB::table('billing_wallets')->insertOrIgnore([
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('billing_wallets')->where('user_id', $userId)->value('id');
    }
}
