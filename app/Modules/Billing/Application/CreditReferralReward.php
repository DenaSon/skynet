<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal primitive: only GrantReferralReward may supply its verified locked inputs. */
final class CreditReferralReward
{
    public function handle(int $userId, int $invitedId, int $amountRials): int
    {
        if (DB::transactionLevel() < 1 || config('referral.rewards_enabled') !== true) {
            throw new AuthorizationException;
        }
        if ($userId < 1 || $invitedId < 1 || $userId === $invitedId
            || $amountRials < 1 || $amountRials > 1_000_000_000_000) {
            throw ValidationException::withMessages(['referral' => 'Invalid referral credit.']);
        }
        $walletId = app(WalletAccess::class)->customerWalletId($userId);
        DB::table('billing_wallets')->where('id', $walletId)->lockForUpdate()->first();
        // Unique journal key is the final defense; unexpected partial history fails closed.
        $journalId = (int) DB::table('billing_journals')->insertGetId([
            'wallet_id' => $walletId, 'kind' => 'referral_reward',
            'idempotency_key' => 'referral:invitee:'.$invitedId, 'created_at' => now(),
        ]);
        DB::table('billing_postings')->insert([
            ['journal_id' => $journalId, 'wallet_id' => null, 'account_code' => 'referral_expense',
                'side' => 'debit', 'amount_rials' => $amountRials],
            ['journal_id' => $journalId, 'wallet_id' => $walletId, 'account_code' => 'wallet_liability',
                'side' => 'credit', 'amount_rials' => $amountRials],
        ]);

        return $journalId;
    }
}
