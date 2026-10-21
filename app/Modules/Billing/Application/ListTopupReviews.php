<?php

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Facades\DB;

final class ListTopupReviews
{
    /**
     * @return list<array{id:int,user_id:int,amount_rials:int,reference:string}>
     */
    public function handle(int $operatorId): array
    {
        app(AssertBillingAccess::class)->reviewer($operatorId);

        return DB::table('billing_topups as t')
            ->join('billing_wallets as w', 'w.id', '=', 't.wallet_id')
            ->where('t.status', 'submitted')->whereNotNull('t.payment_reference')->orderBy('t.id')->limit(10)
            ->get(['t.id', 'w.user_id', 't.amount_rials', 't.payment_reference'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'amount_rials' => (int) $row->amount_rials,
                'reference' => (string) $row->payment_reference,
            ])->all();
    }
}
