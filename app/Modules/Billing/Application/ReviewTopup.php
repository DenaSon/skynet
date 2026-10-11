<?php

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewTopup
{
    public function handle(int $operatorId, int $topupId, bool $approve, string $expectedReference): bool
    {
        return DB::transaction(function () use ($operatorId, $topupId, $approve, $expectedReference): bool {
            app(AssertBillingAccess::class)->reviewer($operatorId);

            $topup = DB::table('billing_topups')->where('id', $topupId)->lockForUpdate()->first();

            if ($topup === null || $topup->status !== 'submitted' || $topup->payment_reference !== $expectedReference) {
                throw ValidationException::withMessages(['topup' => 'Review request is stale or missing.']);
            }

            if ($approve) {
                // Unique bank reference across approved payments: one verified transfer = one credit.
                if (DB::table('billing_verified_receipts')->where('payment_reference', $expectedReference)->exists()) {
                    throw ValidationException::withMessages(['payment' => 'Payment reference already credited.']);
                }

                DB::table('billing_verified_receipts')->insert([
                    'payment_reference' => $expectedReference,
                    'topup_id' => $topupId,
                    'verified_at' => now(),
                ]);

                $journalId = (int) DB::table('billing_journals')->insertGetId([
                    'wallet_id' => $topup->wallet_id,
                    'kind' => 'topup',
                    'idempotency_key' => 'topup:'.$topupId,
                    'created_at' => now(),
                ]);

                // Balanced double-entry: incoming cash debit, customer liability credit.
                DB::table('billing_postings')->insert([
                    [
                        'journal_id' => $journalId,
                        'wallet_id' => null,
                        'account_code' => 'cash_clearing',
                        'side' => 'debit',
                        'amount_rials' => $topup->amount_rials,
                    ],
                    [
                        'journal_id' => $journalId,
                        'wallet_id' => $topup->wallet_id,
                        'account_code' => 'wallet_liability',
                        'side' => 'credit',
                        'amount_rials' => $topup->amount_rials,
                    ],
                ]);
            }

            DB::table('billing_topups')->where('id', $topupId)->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $operatorId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }
}
