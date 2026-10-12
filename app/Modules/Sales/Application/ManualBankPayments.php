<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F11-A2: manual, operator-confirmed bank transfer. A screenshot is never
 * bank evidence on its own. No direct provider or Telegram side effects here.
 */
final readonly class ManualBankPayments
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private AssertBillingAccess $access,
    ) {}

    public function submit(SubscriptionViewer $viewer, int $orderId, string $fileId, string $fileUniqueId, int $telegramMessageId): int
    {
        $this->enabled();

        if ($orderId < 1 || $telegramMessageId < 1 || strlen($fileId) < 5 || strlen($fileId) > 1024
            || strlen($fileUniqueId) < 5 || strlen($fileUniqueId) > 255) {
            throw ValidationException::withMessages(['receipt' => 'Invalid receipt image.']);
        }

        $buyerId = $this->authorize->userId($viewer);

        if ($viewer->channel !== 'telegram' || $viewer->telegramUserId === null) {
            throw new AuthorizationException;
        }

        $messageKey = 'tg:'.$viewer->telegramUserId.':'.$telegramMessageId;
        $fingerprint = hash('sha256', $fileUniqueId);

        return DB::transaction(function () use ($buyerId, $orderId, $fileId, $messageKey, $fingerprint): int {
            $order = DB::table('sales_orders')->where('id', $orderId)->where('buyer_user_id', $buyerId)->lockForUpdate()->first();

            if ($order === null || $order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => 'Order not payable or not owned.']);
            }

            $existing = DB::table('sales_bank_payment_claims')->where('telegram_message_key', $messageKey)->first();

            if ($existing !== null && (int) $existing->buyer_user_id === $buyerId
                && (int) $existing->sales_order_id === $orderId && $existing->status === 'submitted') {
                return (int) $existing->id;
            }

            if (DB::table('sales_bank_payment_claims')->where('sales_order_id', $orderId)->where('status', 'submitted')->exists()) {
                throw ValidationException::withMessages(['receipt' => 'This order already has a receipt pending review.']);
            }

            if (DB::table('sales_bank_payment_claims')->where('photo_fingerprint', $fingerprint)->exists()) {
                throw ValidationException::withMessages(['receipt' => 'This receipt was already submitted.']);
            }

            return (int) DB::table('sales_bank_payment_claims')->insertGetId([
                'sales_order_id' => $orderId,
                'buyer_user_id' => $buyerId,
                'photo_encrypted' => Crypt::encryptString($fileId),
                'photo_fingerprint' => $fingerprint,
                'telegram_message_key' => $messageKey,
                'status' => 'submitted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /** @return list<object> */
    public function pending(int $rootId, int $limit = 12): array
    {
        $this->enabled();
        $this->access->reviewer($rootId);

        return DB::table('sales_bank_payment_claims as claims')
            ->join('sales_orders as orders', 'orders.id', '=', 'claims.sales_order_id')
            ->where('claims.status', 'submitted')
            ->select('claims.id', 'claims.sales_order_id', 'orders.buyer_user_id', 'orders.total_price_rials', 'orders.product_name')
            ->orderBy('claims.id')->limit(min(20, max(1, $limit)))->get()->all();
    }

    public function detail(int $rootId, int $claimId): object
    {
        $this->enabled();
        $this->access->reviewer($rootId);

        $row = DB::table('sales_bank_payment_claims as claims')
            ->join('sales_orders as orders', 'orders.id', '=', 'claims.sales_order_id')
            ->where('claims.id', $claimId)
            ->select('claims.*', 'orders.total_price_rials', 'orders.product_name', 'orders.status as order_status')->first();

        if ($row === null || $row->status !== 'submitted' || $row->order_status !== 'pending_payment') {
            throw ValidationException::withMessages(['claim' => 'Claim unavailable.']);
        }

        return (object) [
            'id' => (int) $row->id,
            'orderId' => (int) $row->sales_order_id,
            'buyerUserId' => (int) $row->buyer_user_id,
            'amountRials' => (int) $row->total_price_rials,
            'productName' => (string) $row->product_name,
            'photoFileId' => Crypt::decryptString($row->photo_encrypted),
        ];
    }

    /**
     * Only a live-eligible Super Admin may make the final decision.
     * On approve they attest separately matching a real bank transaction and
     * supply that transaction's unique reference, never just the payer caption.
     */
    public function review(int $rootId, int $claimId, bool $approve, ?string $verifiedBankReference = null): object
    {
        $this->enabled();

        $reference = strtoupper(trim($verifiedBankReference ?? ''));

        if ($approve && preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $reference) !== 1) {
            throw ValidationException::withMessages(['reference' => 'Verified bank reference required.']);
        }

        return DB::transaction(function () use ($rootId, $claimId, $approve, $reference): object {
            $this->access->reviewer($rootId);
            $claim = DB::table('sales_bank_payment_claims')->where('id', $claimId)->lockForUpdate()->first();

            if ($claim === null || $claim->status !== 'submitted') {
                throw ValidationException::withMessages(['claim' => 'Claim already reviewed or unavailable.']);
            }

            $order = DB::table('sales_orders')->where('id', $claim->sales_order_id)->lockForUpdate()->first();

            if ($order === null || (int) $order->buyer_user_id !== (int) $claim->buyer_user_id
                || $order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => 'Order no longer eligible.']);
            }

            if ($approve) {
                // Historical F08 receipts live in a separate table; enforce
                // uniqueness across old top-ups, new top-ups and direct orders.
                if (DB::table('billing_verified_receipts')->where('payment_reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['reference' => 'Transfer already credited to a wallet.']);
                }

                DB::table('billing_bank_reference_claims')->insert([
                    'payment_reference' => $reference,
                    'source_type' => 'sales_order',
                    'source_id' => $order->id,
                    'verified_at' => now(),
                ]);

                $settlementId = (int) DB::table('sales_bank_settlements')->insertGetId([
                    'sales_order_id' => $order->id,
                    'payment_claim_id' => $claim->id,
                    'verified_by' => $rootId,
                    'payment_reference' => $reference,
                    'amount_rials' => $order->total_price_rials,
                    'settled_at' => now(),
                ]);

                DB::table('sales_bank_postings')->insert([
                    ['settlement_id' => $settlementId, 'account_code' => 'cash_clearing', 'side' => 'debit', 'amount_rials' => $order->total_price_rials],
                    ['settlement_id' => $settlementId, 'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => $order->total_price_rials],
                ]);

                $changed = DB::table('sales_orders')->where('id', $order->id)->where('status', 'pending_payment')
                    ->update(['status' => 'paid', 'updated_at' => now()]);

                if ($changed !== 1) {
                    throw ValidationException::withMessages(['order' => 'Order status changed.']);
                }

                DB::table('sales_fulfillment_outbox')->insert([
                    'sales_order_id' => $order->id,
                    'event_key' => 'sales-order:'.$order->id.':create',
                    'state' => 'pending',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('sales_bank_payment_claims')->where('id', $claimId)->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $rootId,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('sales_payment_review_audits')->insert([
                'payment_claim_id' => $claimId,
                'reviewer_user_id' => $rootId,
                'decision' => $approve ? 'approved' : 'rejected',
                'created_at' => now(),
            ]);

            return (object) [
                'orderId' => (int) $order->id,
                'buyerUserId' => (int) $claim->buyer_user_id,
                'approved' => $approve,
            ];
        }, 3);
    }

    private function enabled(): void
    {
        if (config('sales.manual_bank_review_enabled') !== true) {
            throw new AuthorizationException;
        }
    }
}
