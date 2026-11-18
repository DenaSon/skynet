<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReverseDirectBankReceipt
{
    public function __construct(private AssertBillingAccess $access) {}

    public function receipts(int $operator): Builder
    {
        $this->access->reviewer($operator);

        return DB::table('sales_bank_settlements as s')
            ->join('sales_bank_payment_claims as c', 'c.id', '=', 's.payment_claim_id')
            ->join('sales_orders as o', 'o.id', '=', 's.sales_order_id')
            ->whereColumn('c.sales_order_id', 'o.id')->whereColumn('c.buyer_user_id', 'o.buyer_user_id')
            ->where('o.status', 'fulfilled')->whereIn('c.status', ['approved', 'voided'])
            ->select('s.payment_claim_id as receipt', 's.sales_order_id as order_id', 's.billing_journal_id as journal',
                's.amount_rials as amount', 'c.status', 'o.buyer_user_id as buyer');
    }

    public function handle(int $operator, int $receipt, int $orderId, int $journal, int $amount): int
    {
        return DB::transaction(function () use ($operator, $receipt, $orderId, $journal, $amount): int {
            $this->access->reviewer($operator);
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $row = $this->receipts($operator)->where('c.id', $receipt)->lockForUpdate()->first();
            if ($order === null || $row === null || $row->status !== 'approved' || (int) $row->order_id !== $orderId
                || (int) $row->journal !== $journal || (int) $row->amount !== $amount || $amount <= 0) {
                throw ValidationException::withMessages(['receipt' => 'Receipt changed or already reversed.']);
            }

            // Same proof as fulfillment: original two postings, owner, bank reference, amount and exclusive settlement.
            app(FulfillPaidPlanOrder::class)->assertSettlement($order);
            $id = (int) DB::table('billing_journals')->insertGetId([
                'wallet_id' => null, 'kind' => 'direct_bank_sale_reversal',
                'idempotency_key' => 'reverse:direct-bank:journal:'.$journal, 'created_at' => now(),
            ]);
            foreach (['cash_clearing' => 'credit', 'order_liability' => 'debit'] as $account => $side) {
                DB::table('billing_postings')->insert([
                    'journal_id' => $id, 'wallet_id' => null, 'account_code' => $account,
                    'side' => $side, 'amount_rials' => $amount,
                ]);
            }
            DB::table('sales_payment_review_audits')->insert([
                'payment_claim_id' => $receipt, 'reviewer_user_id' => $operator,
                'decision' => 'no_bank_deposit', 'created_at' => now(),
            ]);
            DB::table('sales_bank_payment_claims')->where('id', $receipt)->update(['status' => 'voided', 'updated_at' => now()]);

            return $id;
        }, 3);
    }
}
