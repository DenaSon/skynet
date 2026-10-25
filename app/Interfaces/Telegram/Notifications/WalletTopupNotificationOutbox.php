<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Called inside the same outer transaction that commits a top-up photo or review. */
final readonly class WalletTopupNotificationOutbox
{
    public function __construct(
        private SuperAdminTrust $trust,
        private ResolveTelegramActor $actors,
        private TelegramRecipient $recipients,
        private AdminCapabilityAccess $capabilities,
    ) {}

    public function submitted(int $topupId): void
    {
        if (! Schema::hasColumn('telegram_notification_deliveries', 'topup_id')) {
            return; // Safe during rolling deploys.
        }

        $row = DB::table('billing_topups')->where('id', $topupId)
            ->where('status', 'submitted')->whereNull('payment_reference')->first();
        if ($row === null || DB::table('billing_topup_photos')->where('topup_id', $topupId)->doesntExist()) {
            return;
        }

        foreach ($this->capabilities->reviewerIds('topups.review') as $userId) {
            $this->record(
                'wallet:topup:'.$topupId.':submitted:root:'.$userId,
                'WalletTopupSubmitted', $userId, 'admin', $topupId,
            );
        }
    }

    public function reviewed(int $topupId): void
    {
        if (! Schema::hasColumn('telegram_notification_deliveries', 'topup_id')) {
            return;
        }

        $row = DB::table('billing_topups as t')
            ->join('billing_wallets as w', 'w.id', '=', 't.wallet_id')
            ->where('t.id', $topupId)
            ->whereIn('t.status', ['approved', 'rejected'])
            ->first(['t.status', 'w.user_id']);
        if ($row === null || $this->recipients->forActiveUser((int) $row->user_id) === null) {
            return;
        }

        $this->record(
            'wallet:topup:'.$topupId.':'.$row->status.':buyer:'.$row->user_id,
            $row->status === 'approved' ? 'WalletTopupApproved' : 'WalletTopupRejected',
            (int) $row->user_id, 'sales', $topupId,
        );
    }

    private function record(string $key, string $type, int $userId, string $bot, int $topupId): void
    {
        $enabled = config('telegram_notifications.enabled', false) === true;
        $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key,
            'event_type' => $type,
            'recipient_user_id' => $userId,
            'bot_key' => $bot,
            'topup_id' => $topupId,
            'claim_id' => null,
            'order_id' => null,
            'state' => $enabled ? 'pending' : 'suppressed',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1 || ! $enabled) {
            return;
        }

        $id = (int) DB::table('telegram_notification_deliveries')->where('event_key', $key)->value('id');
        DB::afterCommit(static function () use ($id): void {
            DispatchTelegramNotification::dispatch($id);
        });
    }
}
