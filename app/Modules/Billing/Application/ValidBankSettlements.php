<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Receipt-date reporting: a bank deposit established as fictitious never contributed cash or sales. */
final class ValidBankSettlements
{
    public static function query(): Builder
    {
        return DB::table('sales_bank_settlements as payment')
            ->whereNotIn('payment.payment_claim_id', self::voidedClaims());
    }

    private static function voidedClaims(): Builder
    {
        return DB::table('sales_bank_payment_claims')->where('status', 'voided')->select('id');
    }
}
