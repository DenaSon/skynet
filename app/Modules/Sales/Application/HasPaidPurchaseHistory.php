<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;

/** A wallet recharge is not a service purchase. Retain historical settlements. */
final class HasPaidPurchaseHistory
{
    public function handle(int $userId): bool
    {
        return DB::table('sales_orders as orders')->where('orders.buyer_user_id', $userId)
            ->where(function ($query): void {
                $query->whereIn('orders.status', ['paid', 'fulfilled'])
                    ->orWhereExists(function ($settled): void {
                        $settled->selectRaw('1')->from('sales_order_settlements as wallet')
                            ->whereColumn('wallet.sales_order_id', 'orders.id');
                    })
                    ->orWhereExists(function ($settled): void {
                        $settled->selectRaw('1')->from('sales_bank_settlements as bank')
                            ->whereColumn('bank.sales_order_id', 'orders.id');
                    });
            })->exists();
    }
}
