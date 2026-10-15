<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramRecipient;
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

    private function record(
        string $key, string $type, int $recipientId, string $bot, int $claimId, int $orderId,
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
