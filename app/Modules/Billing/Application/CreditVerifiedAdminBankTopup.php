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
 * Root-attested bank receipt deposited into an eligible customer's wallet.
 * Delegates all journal/posting/reference rules to the existing ReviewTopup.
 * Never settles an order or calls the remote subscription provider.
 */
final readonly class CreditVerifiedAdminBankTopup
{
    public function __construct(
        private AssertBillingAccess $access,
        private WalletAccess $wallets,
        private ReviewTopup $review,
    ) {}

    public function handle(int $actorId, int $customerId, int $amountRials, string $bankReference, string $reason): int
    {
        $this->access->reviewer($actorId);

        if (config('billing.topups_enabled', false) !== true) {
            throw new AuthorizationException;
        }

        $reference = strtoupper(trim($bankReference));
        $reason = trim($reason);

        // The interface accepts integer tomans; every amount is persisted as integer rials.
        if ($customerId < 1 || $amountRials < 10 || $amountRials > 100_000_000) {
            throw ValidationException::withMessages(['amount' => 'Amount must be between 1 and 10,000,000 tomans.']);
        }
        if (preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $reference) !== 1
            || str_starts_with($reference, 'MANUAL-CLAIM-')) {
            throw ValidationException::withMessages(['reference' => 'A real verified bank reference is required.']);
        }
        if ($reason === '' || mb_strlen($reason) > 200) {
            throw ValidationException::withMessages(['reason' => 'A concise reason is required.']);
        }

        return DB::transaction(function () use ($actorId, $customerId, $amountRials, $reference, $reason): int {
            // Recheck immediately before the write, including allowlist revocation.
            $this->access->reviewer($actorId);
            $customer = User::query()->find($customerId);
            if ($customer === null || ! $customer->is_active
                || app(AdminCapabilityAccess::class)->isOperator($customer)) {
                throw new AuthorizationException;
            }
            $walletId = $this->wallets->customerWalletId($customerId);
            DB::table('billing_wallets')->where('id', $walletId)->lockForUpdate()->first();

            // The reference-derived request key and globally unique bank-reference
            // claim both protect against repeated callbacks and concurrent submissions.
            $topupId = (int) DB::table('billing_topups')->insertGetId([
                'wallet_id' => $walletId,
                'request_key' => 'admin:bank:'.hash('sha256', $reference),
                'amount_rials' => $amountRials,
                'status' => 'submitted',
                'payment_reference' => $reference,
                'origin' => 'admin_verified_bank',
                'admin_reason' => $reason,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Writes the shared bank claim, immutable verified receipt, balanced
            // postings, reviewed_by/reviewed_at and approval in this transaction.
            $this->review->handle($actorId, $topupId, true, $reference, $amountRials);

            return $topupId;
        }, 3);
    }
}
