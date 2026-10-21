<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Existing durable notification deliveries; no new dispatcher or notification table. */
final readonly class SalesPanelNotificationOutbox
{
    public function __construct(
        private SuperAdminTrust $trust,
        private ResolveTelegramActor $actors,
        private TelegramRecipient $recipients,
    ) {}

    /** Must be called inside the transaction that first stores the application. */
    public function submitted(int $id): void
    {
        if (! Schema::hasColumn('telegram_notification_deliveries', 'panel_request_id')) {
            return;
        }
        if (! DB::table('sales_panel_requests')->where('id', $id)->where('status', 'pending')->exists()) {
            return;
        }

        foreach ($this->trust->allowedTelegramIds() as $telegramId) {
            $root = $this->actors->handle((int) $telegramId);
            if ($root?->canManageRoles() !== true) {
                continue;
            }
            $this->record(
                'panel:'.$id.':request:root:'.$root->userId, 'SalesPanelSubmitted',
                $root->userId, 'admin', $id,
            );
        }
    }

    /** Must be called inside the committed, one-decision reviewer transaction. */
    public function reviewed(int $id): void
    {
        if (! Schema::hasColumn('telegram_notification_deliveries', 'panel_request_id')) {
            return;
        }
        $row = DB::table('sales_panel_requests')->where('id', $id)
            ->whereIn('status', ['approved', 'rejected'])->first(['user_id', 'status']);
        if ($row === null || $this->recipients->forActiveUser((int) $row->user_id) === null) {
            return;
        }

        $this->record(
            'panel:'.$id.':'.$row->status.':buyer:'.$row->user_id,
            $row->status === 'approved' ? 'SalesPanelApproved' : 'SalesPanelRejected',
            (int) $row->user_id, 'sales', $id,
        );
    }

    private function record(string $key, string $event, int $userId, string $bot, int $requestId): void
    {
        $enabled = config('telegram_notifications.enabled', false) === true;
        $created = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key,
            'event_type' => $event,
            'recipient_user_id' => $userId,
            'bot_key' => $bot,
            'panel_request_id' => $requestId,
            'claim_id' => null,
            'order_id' => null,
            'state' => $enabled ? 'pending' : 'suppressed',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($created !== 1 || ! $enabled) {
            return;
        }
        $deliveryId = (int) DB::table('telegram_notification_deliveries')->where('event_key', $key)->value('id');
        DB::afterCommit(static function () use ($deliveryId): void {
            DispatchTelegramNotification::dispatch($deliveryId);
        });
    }
}
