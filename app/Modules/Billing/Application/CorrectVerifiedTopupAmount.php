<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Manual, bank-attested partial correction of an overstated wallet top-up.
 * Never replays a payment, voids the original receipt or changes a purchase.
 * Only the operator-only Artisan entry point calls this Action.
 */
final readonly class CorrectVerifiedTopupAmount
{
    public function __construct(
        private AssertBillingAccess $access,
        private AdminCapabilityAccess $operators,
    ) {}

    /** @return array{wallet_id:int,original_rials:int,verified_rials:int,adjustment_rials:int,balance_rials:int} */
    public function preview(int $operatorId, int $userId, int $topupId, int $verifiedRials): array
    {
        return DB::transaction(fn (): array => $this->inspect($operatorId, $userId, $topupId, $verifiedRials));
    }

    public function correct(
        int $operatorId,
        int $userId,
        int $topupId,
        int $verifiedRials,
        int $expectedBalanceRials,
        string $reason,
    ): int {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'An auditable correction reason is required (1-200 characters).']);
        }

        return DB::transaction(function () use (
            $operatorId, $userId, $topupId, $verifiedRials, $expectedBalanceRials, $reason
        ): int {
            $state = $this->inspect($operatorId, $userId, $topupId, $verifiedRials);
            if ($state['balance_rials'] !== $expectedBalanceRials) {
                throw ValidationException::withMessages(['wallet' => 'Wallet changed since operator review; stop and investigate.']);
            }

            $journalId = (int) DB::table('billing_journals')->insertGetId([
                'wallet_id' => $state['wallet_id'],
                'kind' => 'topup_amount_correction',
                'idempotency_key' => 'topup_amount_correction:'.$topupId,
                'created_at' => now(),
            ]);

            DB::table('billing_postings')->insert([
                [
                    'journal_id' => $journalId,
                    'wallet_id' => $state['wallet_id'],
                    'account_code' => 'wallet_liability',
                    'side' => 'debit',
                    'amount_rials' => $state['adjustment_rials'],
                ],
                [
                    'journal_id' => $journalId,
                    'wallet_id' => null,
                    'account_code' => 'cash_clearing',
                    'side' => 'credit',
                    'amount_rials' => $state['adjustment_rials'],
                ],
            ]);

            DB::table('billing_topup_amount_corrections')->insert([
                'topup_id' => $topupId,
                'billing_journal_id' => $journalId,
                'verified_amount_rials' => $verifiedRials,
                'adjustment_amount_rials' => $state['adjustment_rials'],
                'corrected_by' => $operatorId,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return $journalId;
        }, 3);
    }

    /** Locks the wallet first, like the existing purchase and reversal Actions. */
    private function inspect(int $operatorId, int $userId, int $topupId, int $verifiedRials): array
    {
        $this->access->reviewer($operatorId);

        if (! Schema::hasTable('billing_topup_amount_corrections')
            || ! Schema::hasColumn('billing_topups', 'reversal_journal_id')) {
            throw ValidationException::withMessages(['schema' => 'Correction storage is not ready.']);
        }
        if ($userId < 1 || $topupId < 1 || $verifiedRials < 10 || $verifiedRials % 10 !== 0) {
            throw ValidationException::withMessages(['amount' => 'Invalid bank-verified top-up amount.']);
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->is_active || ! $user->hasAnyRole(['member', 'reseller'])
            || $this->operators->isOperator($user)) {
            throw new AuthorizationException;
        }

        $wallet = DB::table('billing_wallets')->where('user_id', $userId)->lockForUpdate()->first();
        if ($wallet === null) {
            throw ValidationException::withMessages(['wallet' => 'Customer wallet unavailable.']);
        }
        $walletId = (int) $wallet->id;
        $topup = DB::table('billing_topups')->where('id', $topupId)
            ->where('wallet_id', $walletId)->lockForUpdate()->first();
        if ($topup === null || $topup->status !== 'approved' || $topup->reversal_journal_id !== null
            || $topup->payment_reference === null || $verifiedRials >= (int) $topup->amount_rials) {
            throw ValidationException::withMessages(['topup' => 'Top-up is missing, reversed or not overstated.']);
        }
        if (DB::table('billing_topup_amount_corrections')->where('topup_id', $topupId)->exists()
            || DB::table('billing_journals')->where('idempotency_key', 'topup_amount_correction:'.$topupId)->exists()
            || DB::table('billing_journals')->where('idempotency_key', 'topup_reversal:'.$topupId)->exists()) {
            throw ValidationException::withMessages(['topup' => 'This top-up has already been corrected or reversed.']);
        }

        $original = DB::table('billing_journals')->where('idempotency_key', 'topup:'.$topupId)->first();
        $source = $original === null ? collect()
            : DB::table('billing_postings')->where('journal_id', $original->id)->get();
        $validCredit = $source->contains(static fn (object $p): bool => $p->account_code === 'wallet_liability'
            && $p->side === 'credit' && (int) $p->wallet_id === $walletId
            && (int) $p->amount_rials === (int) $topup->amount_rials);
        $validCash = $source->contains(static fn (object $p): bool => $p->account_code === 'cash_clearing'
            && $p->side === 'debit' && $p->wallet_id === null
            && (int) $p->amount_rials === (int) $topup->amount_rials);
        $proof = DB::table('billing_verified_receipts')->where('topup_id', $topupId)
            ->where('payment_reference', $topup->payment_reference)->exists();
        $claim = DB::table('billing_bank_reference_claims')->where('source_type', 'wallet_topup')
            ->where('source_id', $topupId)->where('payment_reference', $topup->payment_reference)->exists();
        if ($original === null || ! in_array($original->kind, ['topup', 'admin_verified_topup'], true)
            || (int) $original->wallet_id !== $walletId || $source->count() !== 2
            || ! $validCredit || ! $validCash || ! $proof || ! $claim) {
            throw ValidationException::withMessages(['ledger' => 'Source bank receipt or balanced journal needs manual review.']);
        }

        // No stale snapshot for a financial correction, even if purchases followed the top-up.
        $postings = DB::table('billing_postings')->where('wallet_id', $walletId)
            ->where('account_code', 'wallet_liability')->orderBy('id')->lockForUpdate()
            ->get(['side', 'amount_rials']);
        $balance = 0;
        foreach ($postings as $posting) {
            if ($posting->side === 'credit') {
                $balance += (int) $posting->amount_rials;
            } elseif ($posting->side === 'debit') {
                $balance -= (int) $posting->amount_rials;
            } else {
                throw ValidationException::withMessages(['ledger' => 'Unexpected posting side.']);
            }
        }

        $adjustment = (int) $topup->amount_rials - $verifiedRials;
        if ($balance < $adjustment) {
            throw ValidationException::withMessages(['wallet' => 'Available wallet balance cannot cover the correction.']);
        }

        return [
            'wallet_id' => $walletId,
            'original_rials' => (int) $topup->amount_rials,
            'verified_rials' => $verifiedRials,
            'adjustment_rials' => $adjustment,
            'balance_rials' => $balance,
        ];
    }
}
