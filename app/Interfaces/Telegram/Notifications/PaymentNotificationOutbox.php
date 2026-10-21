<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramRecipient;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use Illuminate\Support\Facades\DB;

final readonly class PaymentNotificationOutbox
{
    public function __construct(
        private SuperAdminTrust $trust,
        private ResolveTelegramActor $actors,
        private TelegramRecipient $recipients,
    ) {}

    // Called INSIDE the same transaction that inserts the immutable receipt.
    public function submitted(int $claimId, int $orderId): void
    {
        foreach ($this->trust->allowedTelegramIds() as $telegramId) {
            $root = $this->actors->handle((int) $telegramId);

            if ($root?->canManageRoles() !== true) {
                continue;
            }

            $this->record(
                'receipt:'.$claimId.':root:'.$root->userId,
                'ReceiptSubmitted', $root->userId, 'admin', $claimId, $orderId,
            );
        }
    }

    // Called INSIDE the transaction that committed the single-use bank review.
    public function reviewed(int $claimId, int $orderId, int $buyerId): void
    {
        // The decision has already committed Billing settlement within the
        // caller transaction. Reuse its freshly authorized human reviewer;
        // FulfillPaidPlanOrderJob checks current permissions again at runtime.
        if (config('sales.auto_fulfillment_enabled', false) === true) {
            $reviewer = DB::table('sales_bank_payment_claims')
                ->where('id', $claimId)->where('sales_order_id', $orderId)
                ->where('status', 'approved')->value('reviewed_by');
            if (is_numeric($reviewer) && (int) $reviewer > 0) {
                DB::afterCommit(static function () use ($orderId, $reviewer): void {
                    if (config('sales.auto_fulfillment_enabled', false) === true
                        && config('services.remnawave.writes_enabled') === true
                        && config('services.remnawave.squad_bindings_live_verified') === true) {
                        FulfillPaidPlanOrderJob::dispatch($orderId, false, (int) $reviewer);
                    }
                });
            }
        }

        if ($this->recipients->forActiveUser($buyerId) === null) {
            return;
        }

        // Sales is not a live customer bot yet. Prefer a positively started
        // Sales contact only if Sales delivery is enabled and authenticated.
        $salesContact = DB::table('telegram_bot_contacts')
            ->where('user_id', $buyerId)->where('bot_key', 'sales')
            ->where('delivery_status', 'reachable')->exists();

        $salesConfigured = config('telegram.sales.enabled', false) === true
            && is_string(config('telegram.sales.token'))
            && config('telegram.sales.token') !== ''
            && config('telegram.sales.token') !== config('nutgram.token');

        $target = $salesContact && $salesConfigured ? 'sales' : 'admin';

        $this->record(
            'review:'.$claimId.':buyer:'.$buyerId,
            'PaymentReviewed', $buyerId, $target, $claimId, $orderId,
        );
    }

    /**
     * A verified customer-wallet debit and paid outbox were committed by the
     * caller transaction. Auto-fulfillment is queued after commit only.
     */
    public function walletSettled(int $orderId, int $buyerId): void
    {
        if ($orderId < 1 || $buyerId < 1) {
            return;
        }

        if (config('sales.auto_fulfillment_enabled', false) === true) {
            DB::afterCommit(static function () use ($orderId): void {
                if (config('sales.auto_fulfillment_enabled', false) === true
                    && config('services.remnawave.writes_enabled') === true
                    && config('services.remnawave.squad_bindings_live_verified') === true) {
                    FulfillPaidPlanOrderJob::dispatch($orderId, false, 0);
                }
            });
        }

        if ($this->recipients->forActiveUser($buyerId) === null
            || config('telegram.sales.enabled', false) !== true
            || ! DB::table('telegram_bot_contacts')->where('user_id', $buyerId)
                ->where('bot_key', 'sales')->where('delivery_status', 'reachable')->exists()) {
            return;
        }

        $this->record(
            'wallet:purchase:order:'.$orderId.':buyer:'.$buyerId,
            'WalletPurchaseSettled', $buyerId, 'sales', null, $orderId,
        );
    }

    /** Only after the same existing subscription was updated and fully reconciled. */
    public function renewed(int $orderId, int $buyerId): void
    {
        if ($orderId < 1 || $buyerId < 1 || $this->recipients->forActiveUser($buyerId) === null
            || config('telegram.sales.enabled', false) !== true
            || ! DB::table('telegram_bot_contacts')->where('user_id', $buyerId)
                ->where('bot_key', 'sales')->where('delivery_status', 'reachable')->exists()) {
            return;
        }

        $this->record('subscription:renewed:order:'.$orderId.':buyer:'.$buyerId,
            'SubscriptionRenewed', $buyerId, 'sales', null, $orderId);
    }

    /**
     * Called INSIDE the transaction that completed the existing RW order link.
     * Never emits a success notice on payment approval or a queued CREATE.
     */
    public function activated(int $orderId, int $buyerId): void
    {
        if ($orderId < 1 || $buyerId < 1 || $this->recipients->forActiveUser($buyerId) === null) {
            return;
        }

        $started = DB::table('telegram_bot_contacts')->where('user_id', $buyerId)
            ->where('bot_key', 'sales')->where('delivery_status', 'reachable')->exists();
        if (! $started || config('telegram.sales.enabled', false) !== true) {
            return;
        }

        $this->record(
            'subscription:activated:order:'.$orderId.':buyer:'.$buyerId,
            'SubscriptionActivated', $buyerId, 'sales', null, $orderId,
        );
    }

    /** Inside the transaction holding an authentic paid order before CREATE. */
    public function planBlocked(int $orderId): void
    {
        foreach ($this->trust->allowedTelegramIds() as $telegramId) {
            $root = $this->actors->handle((int) $telegramId);
            if ($root?->canManageRoles() === true) {
                $this->record(
                    'fulfillment:plan-blocked:'.$orderId.':root:'.$root->userId,
                    'FulfillmentPlanBlocked', $root->userId, 'admin', null, $orderId,
                );
            }
        }
    }

    private function record(
        string $key, string $type, int $recipientId, string $bot, ?int $claimId, int $orderId,
    ): void {
        $enabled = config('telegram_notifications.enabled', false) === true;

        $id = DB::table('telegram_notification_deliveries')->insertGetId([
            'event_key' => $key,
            'event_type' => $type,
            'recipient_user_id' => $recipientId,
            'bot_key' => $bot,
            'claim_id' => $claimId,
            'order_id' => $orderId,
            // Keep audit evidence but never replay alerts that the legacy
            // BotFather compatibility sender already delivered.
            'state' => $enabled ? 'pending' : 'suppressed',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($enabled) {
            DB::afterCommit(static function () use ($id): void {
                DispatchTelegramNotification::dispatch((int) $id);
            });
        }
    }
}
