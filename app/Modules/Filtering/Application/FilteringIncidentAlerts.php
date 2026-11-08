<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use Illuminate\Support\Facades\DB;

final readonly class FilteringIncidentAlerts
{
    public function __construct(
        private SuperAdminTrust $trust,
        private ResolveTelegramActor $actors,
    ) {}

    /**
     * Called inside the same SQL transaction as an incident transition.
     * Existing notification deliveries handle retries and leases. This method
     * makes no HTTP request and never sends directly from the monitor.
     */
    public function sync(int $incidentId): void
    {
        if (! (
            (config('filtering.node_monitor_enabled', false) === true && config('filtering.node_alerts_enabled', false) === true)
            || (config('filtering.regional_monitor_enabled', false) === true && config('filtering.regional_alerts_enabled', false) === true)
        ) || config('telegram_notifications.enabled', false) !== true) {
            return;
        }

        $incident = DB::table('filtering_incidents')->where('id', $incidentId)
            ->lockForUpdate()->first();
        if ($incident === null) {
            return;
        }

        $kind = null;
        $type = null;
        if ($incident->state === 'resolved' && $incident->last_notified_state !== null
            && $incident->last_notified_state !== 'resolved') {
            $kind = 'recover';
            $type = 'FilteringRecovered';
        } elseif ($incident->state !== 'resolved' && $incident->last_notified_state === null) {
            $kind = 'open';
            $type = 'FilteringOpened';
        } elseif ($incident->state === 'disrupted' && $incident->last_notified_state === 'suspicious') {
            $kind = 'escalate';
            $type = 'FilteringEscalated';
        }
        if ($kind === null) {
            return;
        }

        // The existing delivery table requires a real user FK. Use one
        // currently authorized root as the delivery sponsor, never as the
        // destination. Group notices are one-per-event, not one-per-root.
        $groupChatId = FilteringGroupDestination::chatId();
        if ($groupChatId === null) {
            return; // No fallback to private Admin Bot messages.
        }

        $recipientUserId = null;
        foreach ($this->trust->allowedTelegramIds() as $telegramId) {
            $actor = $this->actors->handle((int) $telegramId);
            if ($actor !== null && $actor->canManageRoles()) {
                $recipientUserId = $actor->userId;
                break;
            }
        }
        if ($recipientUserId === null) {
            return;
        }

        $key = FilteringGroupDestination::eventKey($incidentId, $kind, $groupChatId);
        $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key,
            'event_type' => $type,
            'recipient_user_id' => $recipientUserId,
            'bot_key' => 'admin',
            'state' => 'pending',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 1) {
            DB::table('filtering_incidents')->where('id', $incidentId)->update([
                'last_notified_state' => $incident->state,
                'last_notified_at' => now(),
            ]);
            $deliveryId = (int) DB::table('telegram_notification_deliveries')
                ->where('event_key', $key)->value('id');
            DB::afterCommit(static function () use ($deliveryId): void {
                DispatchTelegramNotification::dispatch($deliveryId);
            });
        }
    }
}
