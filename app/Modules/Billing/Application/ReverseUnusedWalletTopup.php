<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reverses only an independently established *invalid* wallet deposit.
 * A posted wallet debit after its original credit, even if later replenished,
 * always requires manual incident review. No order/provider/refund mutation.
 */
final readonly class ReverseUnusedWalletTopup
{
    public function __construct(
        private AssertBillingAccess $access,
        private AdminCapabilityAccess $operators,
    ) {}

    /**
     * @return array{topup_id:int,user_id:int,amount_rials:int,balance_rials:int,
     *   reference:?string,origin:string,status:string,eligible:bool,block_reason:?string}
     */
    public function preview(int $operatorId, int $userId, int $topupId): array
    {
        $this->access->reviewer($operatorId);

        return DB::transaction(fn (): array => $this->inspect($operatorId, $userId, $topupId));
    }

    public function handle(
        int $operatorId,
        int $userId,
        int $topupId,
        int $expectedBalanceRials,
        string $reason,
    ): int {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'A correction reason (1–200 characters) is required.']);
        }

        return DB::transaction(function () use ($operatorId, $userId, $topupId, $expectedBalanceRials, $reason): int {
            $state = $this->inspect($operatorId, $userId, $topupId);
            if (! $state['eligible'] || $state['balance_rials'] !== $expectedBalanceRials) {
                throw ValidationException::withMessages(['topup' => 'Reversal is not safe or the wallet has changed.']);
            }

            $walletId = (int) DB::table('billing_wallets')->where('user_id', $userId)->value('id');
            $amount = $state['amount_rials'];
            $journalId = (int) DB::table('billing_journals')->insertGetId([
                'wallet_id' => $walletId,
                'kind' => 'topup_reversal',
                'idempotency_key' => 'topup_reversal:'.$topupId,
                'created_at' => now(),
            ]);

            // Reverse the *original* double-entry journal without deleting evidence.
            // Invalid cash-in and the customer's associated liability both decrease.
            DB::table('billing_postings')->insert([
                [
                    'journal_id' => $journalId, 'wallet_id' => $walletId,
                    'account_code' => 'wallet_liability', 'side' => 'debit',
                    'amount_rials' => $amount,
                ],
                [
                    'journal_id' => $journalId, 'wallet_id' => null,
                    'account_code' => 'cash_clearing', 'side' => 'credit',
                    'amount_rials' => $amount,
                ],
            ]);

            $changed = DB::table('billing_topups')->where('id', $topupId)
                ->where('status', 'approved')->whereNull('reversal_journal_id')
                ->update([
                    'status' => 'voided',
                    'reversal_reason' => $reason,
                    'reversed_by' => $operatorId,
                    'reversed_at' => now(),
                    'reversal_journal_id' => $journalId,
                    'updated_at' => now(),
                ]);

            if ($changed !== 1) {
                throw ValidationException::withMessages(['topup' => 'Topup changed before correction.']);
            }

            return $journalId;
        }, 3);
    }

    /**
     * Called under a transaction. Locks the wallet before the source topup, as
     * order checkout does, so concurrent spending cannot race the decision.
     *
     * @return array{topup_id:int,user_id:int,amount_rials:int,balance_rials:int,
     *   reference:?string,origin:string,status:string,eligible:bool,block_reason:?string}
     */
    private function inspect(int $operatorId, int $userId, int $topupId): array
    {
        $this->access->reviewer($operatorId);

        if ($userId < 1 || $topupId < 1) {
            throw ValidationException::withMessages(['topup' => 'Invalid wallet correction selection.']);
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->is_active
            || ! $user->hasAnyRole(['member', 'reseller'])
            || $this->operators->isOperator($user)) {
            throw new AuthorizationException;
        }

        $wallet = DB::table('billing_wallets')->where('user_id', $userId)->lockForUpdate()->first();
        if ($wallet === null) {
            throw ValidationException::withMessages(['wallet' => 'Customer wallet not found.']);
        }
        $walletId = (int) $wallet->id;

        $topup = DB::table('billing_topups')->where('id', $topupId)
            ->where('wallet_id', $walletId)->lockForUpdate()->first();
        if ($topup === null) {
            throw ValidationException::withMessages(['topup' => 'Topup does not belong to this customer.']);
        }

        // Locking reads, rather than a REPEATABLE READ SUM snapshot, mirror
        // DebitWalletForOrder's concurrency boundary.
        $postings = DB::table('billing_postings')
            ->where('wallet_id', $walletId)->where('account_code', 'wallet_liability')
            ->orderBy('id')->lockForUpdate()->get(['id', 'journal_id', 'side', 'amount_rials']);

        $balance = 0;
        foreach ($postings as $posting) {
            if ($posting->side === 'credit') {
                $balance += (int) $posting->amount_rials;
            } elseif ($posting->side === 'debit') {
                $balance -= (int) $posting->amount_rials;
            } else {
                throw ValidationException::withMessages(['wallet' => 'Unrecognized wallet ledger posting.']);
            }
        }

        $blocked = null;
        if ($topup->status !== 'approved' || $topup->reversal_journal_id !== null) {
            $blocked = $topup->status === 'voided'
                ? 'این شارژ قبلاً اصلاح شده است.'
                : 'فقط شارژ تأییدشده و اصلاح‌نشده قابل بررسی است.';
        } else {
            $original = DB::table('billing_journals')
                ->where('idempotency_key', 'topup:'.$topupId)->first();
            $sourcePostings = $original === null
                ? collect()
                : DB::table('billing_postings')->where('journal_id', $original->id)->get();

            $credit = $sourcePostings->first(static fn (object $p): bool =>
                $p->account_code === 'wallet_liability' && $p->side === 'credit'
                && (int) $p->wallet_id === $walletId && (int) $p->amount_rials === (int) $topup->amount_rials);
            $cash = $sourcePostings->first(static fn (object $p): bool =>
                $p->account_code === 'cash_clearing' && $p->side === 'debit'
                && $p->wallet_id === null && (int) $p->amount_rials === (int) $topup->amount_rials);

            $proof = DB::table('billing_verified_receipts')->where('topup_id', $topupId)
                ->where('payment_reference', $topup->payment_reference)->exists();
            $claim = DB::table('billing_bank_reference_claims')
                ->where('source_type', 'wallet_topup')->where('source_id', $topupId)
                ->where('payment_reference', $topup->payment_reference)->exists();
            $alreadyReversed = DB::table('billing_journals')
                ->where('idempotency_key', 'topup_reversal:'.$topupId)->exists();

            if ($original === null || ! in_array($original->kind, ['topup', 'admin_verified_topup'], true)
                || (int) $original->wallet_id !== $walletId || $sourcePostings->count() !== 2
                || $credit === null || $cash === null || ! $proof || ! $claim || $alreadyReversed) {
                $blocked = 'سند مالی یا مدارک واریز نیازمند بررسی است؛ اصلاح خودکار مجاز نیست.';
            } elseif ($postings->contains(static fn (object $p): bool =>
                $p->side === 'debit' && (int) $p->id > (int) $credit->id)) {
                // Deliberately conservative: later topups do not undo past spending.
                $blocked = 'پس از این شارژ، اعتبار کیف پول مصرف یا برداشت شده است؛ نیازمند بررسی.';
            } elseif ($balance < (int) $topup->amount_rials) {
                $blocked = 'موجودی کافی نیست؛ نیازمند بررسی.';
            }
        }

        return [
            'topup_id' => $topupId,
            'user_id' => $userId,
            'amount_rials' => (int) $topup->amount_rials,
            'balance_rials' => $balance,
            'reference' => $topup->payment_reference === null ? null : (string) $topup->payment_reference,
            'origin' => (string) $topup->origin,
            'status' => (string) $topup->status,
            'eligible' => $blocked === null,
            'block_reason' => $blocked,
        ];
    }
}
