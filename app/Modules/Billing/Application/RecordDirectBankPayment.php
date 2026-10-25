<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing-only bank payment accounting for a manually verified Sales order.
 * One shared Billing journal and exactly two opposing postings; returns its ID
 * for the immutable Sales settlement. Never a provider or external bank call.
 */
final readonly class RecordDirectBankPayment
{
    public function __construct(
        private AssertBillingAccess $access,
        private ClaimVerifiedBankReference $references,
    ) {}

    public function handle(int $reviewerId, int $orderId, int $amountRials, string $reference): int
    {
        $this->access->purchaseReviewer($reviewerId);

        if (config('sales.manual_bank_review_enabled') !== true || DB::transactionLevel() < 1) {
            throw new AuthorizationException;
        }

        if ($orderId < 1 || $amountRials < 1 || $amountRials > 1_000_000_000_000) {
            throw ValidationException::withMessages(['payment' => 'Invalid direct bank settlement.']);
        }

        $this->references->handle($reviewerId, 'sales_order', $orderId, $reference);

        $journalId = (int) DB::table('billing_journals')->insertGetId([
            'wallet_id' => null,
            'kind' => 'direct_bank_sale',
            'idempotency_key' => 'direct-bank:order:'.$orderId,
            'created_at' => now(),
        ]);

        DB::table('billing_postings')->insert([
            [
                'journal_id' => $journalId,
                'wallet_id' => null,
                'account_code' => 'cash_clearing',
                'side' => 'debit',
                'amount_rials' => $amountRials,
            ],
            [
                'journal_id' => $journalId,
                'wallet_id' => null,
                'account_code' => 'order_liability',
                'side' => 'credit',
                'amount_rials' => $amountRials,
            ],
        ]);

        return $journalId;
    }
}
