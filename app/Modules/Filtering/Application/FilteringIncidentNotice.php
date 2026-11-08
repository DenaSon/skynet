<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use App\Support\PersianCalendar;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class FilteringIncidentNotice
{
    public function __construct(private RemnawaveApiClientFactory $clients) {}

    /** Admin-only, HTML-escaped and fail-closed against tampered/stale intents. */
    public function messageFor(object $delivery): ?string
    {
        $type = (string) $delivery->event_type;
        $kind = match ($type) {
            'FilteringOpened' => 'open',
            'FilteringEscalated' => 'escalate',
            'FilteringRecovered' => 'recover',
            default => null,
        };

        // Legacy personal intents and changed/unconfigured group IDs must
        // never leak filtering notices into private Admin Bot conversations.
        if ($kind === null || (string) $delivery->bot_key !== 'admin'
            || ! preg_match('/\Aflt:([1-9][0-9]*):'.$kind.':group:(-[1-9][0-9]*)\z/D', (string) $delivery->event_key, $matches)
            || (int) $matches[2] !== FilteringGroupDestination::chatId()) {
            return null;
        }

        $id = (int) $matches[1];
        $row = DB::table('filtering_incidents')->where('id', $id)->first();
        if ($row === null || ($kind === 'recover' && $row->state !== 'resolved')
            || ($kind !== 'recover' && $row->state === 'resolved')) {
            return null;
        }
        if ($kind !== 'open') {
            // Do not send a recovery/escalation without a previously delivered opening.
            $opening = DB::table('telegram_notification_deliveries')
                ->where('event_key', FilteringGroupDestination::eventKey($id, 'open', (int) $matches[2]))
                ->where('event_type', 'FilteringOpened')->where('state', 'sent')->exists();
            if (! $opening) {
                return null;
            }
        }

        $name = 'نود '.strtoupper(substr((string) $row->node_uuid, 0, 8));
        try {
            foreach ((new RemnawaveReadApi($this->clients->make()))->nodes() as $node) {
                if (strtolower($node->uuid) === $row->node_uuid && mb_strlen($node->name) <= 80) {
                    $name = $node->name;
                    break;
                }
            }
        } catch (Throwable) {
            // The notification still works when the monitored provider is down.
        }
        $escaped = htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $heading = match ($kind) {
            'open' => '⚠️ نیازمند بررسی',
            'escalate' => '🚨 اختلال ثبت‌شده',
            default => '✅ وضعیت عادی شد',
        };

        $causes = [];
        /** @var array<string, mixed> $modules */
        $modules = json_decode((string) $row->affected_modules, true, 512, JSON_THROW_ON_ERROR);
        foreach ($modules as $source => $module) {
            // The group message must not disclose disabled module signals.
            // A queued opening also fails closed if its only alert source was
            // disabled after it was created but before Telegram delivery.
            if (($source === 'node' && ! (config('filtering.node_monitor_enabled', false) === true
                && config('filtering.node_alerts_enabled', false) === true))
                || ($source === 'regional' && ! (config('filtering.regional_monitor_enabled', false) === true
                    && config('filtering.regional_alerts_enabled', false) === true))) {
                continue;
            }
            if (! is_array($module) || ! is_array($module['causes'] ?? null)) {
                continue;
            }
            foreach ($module['causes'] as $cause) {
                $label = match ($cause) {
                    'online_zero' => 'کاربران آنلاین صفر',
                    'traffic_zero' => 'ترافیک صفر',
                    'regional_possible' => 'دسترسی نامطمئن از ایران',
                    'regional_likely' => 'اختلال گسترده دسترسی از ایران',
                    default => null,
                };
                if ($label !== null) {
                    $causes[$label] = true;
                }
            }
        }

        if ($kind !== 'recover' && $causes === []) {
            return null;
        }

        $eventTime = match ($kind) {
            'open' => $row->opened_at,
            'recover' => $row->resolved_at,
            default => $row->last_observed_at,
        };
        $time = PersianCalendar::utcDateTime((string) $eventTime);

        return '<b>'.$heading.' — '.$escaped.'</b>'
            .($kind === 'recover' || $causes === [] ? ''
                : "\n".(count($causes) === 1 ? 'نشانه: ' : 'نشانه‌ها: ').implode('، ', array_keys($causes)))
            ."\n🕒 ".$time.' (تهران)';
    }
}
