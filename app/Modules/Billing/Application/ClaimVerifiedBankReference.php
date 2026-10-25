<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Billing alone owns the globally unique bank reference across F08 topups and
 * F11 orders. The operator still must independently verify real bank evidence.
 * This action must participate in the caller's settlement DB transaction.
 */
final readonly class ClaimVerifiedBankReference
{
    public function __construct(private AssertBillingAccess $access) {}

    public function handle(int $reviewerId, string $sourceType, int $sourceId, string $reference): void
    {
        if ($sourceType === 'sales_order') {
            $this->access->purchaseReviewer($reviewerId);
        } elseif ($sourceType === 'wallet_topup') {
            $this->access->topupReviewer($reviewerId);
        } else {
            throw new AuthorizationException;
        }

        if (DB::transactionLevel() < 1) {
            throw new AuthorizationException;
        }

        if ($sourceId < 1 || preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $reference) !== 1) {
            throw ValidationException::withMessages(['payment' => 'Invalid verified bank reference.']);
        }

        // Defensive collision check for verified F08 rows as well as the
        // unique, source-bound shared registry (including concurrent claims).
        if (DB::table('billing_verified_receipts')->where('payment_reference', $reference)->exists()) {
            throw ValidationException::withMessages(['payment' => 'Bank reference already verified.']);
        }

        DB::table('billing_bank_reference_claims')->insert([
            'payment_reference' => $reference,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'verified_at' => now(),
        ]);
    }
}
