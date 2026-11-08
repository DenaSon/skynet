<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class FilteringIncidentAlerts
{
    private const STRONG_ONLINE_USERS = 3;

    private const ONLINE_BASELINE_MINUTES = 30;

    private const ONLINE_REPEAT_COOLDOWN_MINUTES = 30;

    private const MAX_RECENT_INCIDENTS = 16;

    public function __construct(
        private SuperAdminTrust $trust,
        private ResolveTelegramActor $actors,
    ) {}

    /**
     * Called inside the same SQL transaction as an incident transition.
     * Existing notification deliveries handle retries and leases. This method
     * makes no HTTP request and never sends directly from the monitor.
     */
    public function sync(int $incidentId, string $sourceModule, bool $regionalConfirmed = false): void
    {
        $nodeAlerts = config('filtering.node_monitor_enabled', false) === true
            && config('filtering.node_alerts_enabled', false) === true;
        $regionalAlerts = config('filtering.regional_monitor_enabled', false) === true
            && config('filtering.regional_alerts_enabled', false) === true;
        if (config('telegram_notifications.enabled', false) !== true
            || (! $nodeAlerts && ! $regionalAlerts)
            || ! in_array($sourceModule, ['node', 'regional'], true)) {
            return;
        }

        $incident = DB::table('filtering_incidents')->where('id', $incidentId)
            ->lockForUpdate()->first();
        if ($incident === null) {
            return;
        }

        // Recovery closes the shared incident and may be observed by either
        // scanner. For openings/escalations only the currently enabled source
        // can initiate an alert, and regional probes need two valid rounds.
        $resolved = $incident->state === 'resolved';
        if (! $resolved && (
            ($sourceModule === 'node' && ! $nodeAlerts)
            || ($sourceModule === 'regional' && (! $regionalAlerts || ! $regionalConfirmed))
        )) {
            return;
        }

        $modules = json_decode((string) $incident->affected_modules, true);
        $sourceState = is_array($modules) && is_array($modules[$sourceModule] ?? null)
            ? ($modules[$sourceModule]['state'] ?? null) : null;
        if (! $resolved && ! in_array($sourceState, ['suspicious', 'disrupted'], true)) {
            return;
        }

        $groupChatId = FilteringGroupDestination::chatId();
        if ($groupChatId === null) {
            return; // No private-chat fallback.
        }

        // A preexisting private opening or a rotated group must not strand
        // an active incident without an opening in its current group.
        $groupOpening = FilteringGroupDestination::eventKey($incidentId, 'open', $groupChatId);
        $hasGroupOpening = DB::table('telegram_notification_deliveries')
            ->where('event_key', $groupOpening)->exists();

        $kind = null;
        $type = null;
        if (! $resolved && ! $hasGroupOpening) {
            $kind = 'open';
            $type = 'FilteringOpened';
        } elseif ($resolved && $hasGroupOpening
            && $incident->last_notified_state !== null
            && $incident->last_notified_state !== 'resolved') {
            $kind = 'recover';
            $type = 'FilteringRecovered';
        } elseif ($sourceState === 'disrupted' && $incident->last_notified_state === 'suspicious') {
            $kind = 'escalate';
            $type = 'FilteringEscalated';
        }
        if ($kind === null) {
            return;
        }

        // Preserve every SQL incident and sample, but only notify on
        // online-only zero incidents after meaningful prior user activity.
        // Independent traffic/regional evidence must bypass this noise guard.
        if ($kind === 'open' && $sourceModule === 'node' && $this->deferOnlineOnlyOpening($incident)) {
            return;
        }

        // The existing delivery table requires a real user FK. Use one
        // currently authorized root as the delivery sponsor, never as the
        // destination. Group notices are one-per-event, not one-per-root.
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
                'last_notified_state' => $resolved ? 'resolved' : $sourceState,
                'last_notified_at' => now(),
            ]);
            $deliveryId = (int) DB::table('telegram_notification_deliveries')
                ->where('event_key', $key)->value('id');
            DB::afterCommit(static function () use ($deliveryId): void {
                DispatchTelegramNotification::dispatch($deliveryId);
            });
        }
    }

    /**
     * Only low-priority online-only openings are gated. If an independent
     * traffic/regional cause joins later, the same incident can still notify.
     */
    private function deferOnlineOnlyOpening(object $incident): bool
    {
        $modules = json_decode((string) $incident->affected_modules, true);
        if (! is_array($modules)) {
            return false;
        }
        $causes = $modules['node']['causes'] ?? null;
        if ($causes !== ['online_zero']) {
            return false;
        }

        return ! $this->hasStrongOnlineBaseline($incident)
            || $this->recentOnlineOnlyOpeningExists($incident);
    }

    private function hasStrongOnlineBaseline(object $incident): bool
    {
        $openedAt = CarbonImmutable::parse($incident->opened_at);
        $rows = DB::table('filtering_checks')
            ->where('node_uuid', $incident->node_uuid)
            ->where('module', 'node')
            ->where('observed_at', '>=', $openedAt->subMinutes(self::ONLINE_BASELINE_MINUTES))
            ->where('observed_at', '<=', $openedAt)
            ->orderByDesc('observed_at')->limit(32)
            ->get(['observed_at', 'evidence']);

        $previous = null;
        foreach ($rows as $row) {
            $evidence = json_decode((string) $row->evidence, true);
            $online = is_array($evidence) ? ($evidence['online_users'] ?? null) : null;
            if (! is_int($online) || $online < self::STRONG_ONLINE_USERS) {
                $previous = null;

                continue;
            }
            $observed = CarbonImmutable::parse($row->observed_at);
            if ($previous !== null) {
                $gap = $previous->timestamp - $observed->timestamp;
                if ($gap > 0 && $gap <= 180) {
                    return true;
                }
            }
            $previous = $observed;
        }

        return false;
    }

    private function recentOnlineOnlyOpeningExists(object $incident): bool
    {
        $groupChatId = FilteringGroupDestination::chatId();
        if ($groupChatId === null) {
            return false;
        }

        $recent = DB::table('filtering_incidents')
            ->where('node_uuid', $incident->node_uuid)
            ->where('id', '!=', $incident->id)
            ->where('opened_at', '>=', now()->subMinutes(self::ONLINE_REPEAT_COOLDOWN_MINUTES))
            ->whereNotNull('last_notified_state')
            ->orderByDesc('opened_at')->limit(self::MAX_RECENT_INCIDENTS)
            ->get(['id', 'opened_at']);

        foreach ($recent as $previous) {
            // A queued, failed or unavailable intent is not proof that an
            // administrator received the opening. Do not hide a new alert.
            if (! DB::table('telegram_notification_deliveries')
                ->where('event_type', 'FilteringOpened')
                ->where('event_key', FilteringGroupDestination::eventKey((int) $previous->id, 'open', $groupChatId))
                ->where('state', 'sent')->exists()) {
                continue;
            }

            // The incident's modules are cleared upon resolution; its opening
            // Node check is the durable evidence of what triggered that alert.
            $check = DB::table('filtering_checks')
                ->where('node_uuid', $incident->node_uuid)
                ->where('module', 'node')
                ->where('observed_at', '<=', $previous->opened_at)
                ->orderByDesc('observed_at')->first(['evidence']);
            $evidence = $check === null ? null : json_decode((string) $check->evidence, true);
            if (is_array($evidence) && ($evidence['causes'] ?? null) === ['online_zero']) {
                return true;
            }
        }

        return false;
    }
}
