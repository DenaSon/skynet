<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
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

        if ($kind === null || ! preg_match('/\Aflt:([1-9][0-9]*):'.$kind.':root:([1-9][0-9]*)\z/D', (string) $delivery->event_key, $matches)
            || (int) $matches[2] !== (int) $delivery->recipient_user_id) {
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
                ->where('event_key', "flt:{$id}:open:root:".(int) $delivery->recipient_user_id)
                ->where('event_type', 'FilteringOpened')->where('state', 'sent')->exists();
            if (! $opening) {
                return null;
            }
        }

        $name = $row->node_uuid;
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
            'open' => '⚠️ احتمال اختلال دسترسی کاربران',
            'escalate' => '🚨 افزایش شدت اختلال',
            default => '✅ رفع اختلال مشاهده‌شده',
        };

        $causes = [];
        /** @var array<string, mixed> $modules */
        $modules = json_decode((string) $row->affected_modules, true, 512, JSON_THROW_ON_ERROR);
        foreach ($modules as $module) {
            if (! is_array($module) || ! is_array($module['causes'] ?? null)) {
                continue;
            }
            foreach ($module['causes'] as $cause) {
                $label = match ($cause) {
                    'online_zero' => 'کاربران آنلاین: صفر',
                    'traffic_zero' => 'ارسال و دریافت: صفر',
                    'regional_possible' => 'اختلال احتمالی در دسترسی منطقه‌ای',
                    'regional_likely' => 'اختلال گسترده در دسترسی منطقه‌ای',
                    default => null,
                };
                if ($label !== null) {
                    $causes[$label] = true;
                }
            }
        }

        return '<b>'.$heading.'</b>'
            ."\n\nنود: <b>{$escaped}</b>"
            ."\nشناسه: <code>".htmlspecialchars((string) $row->node_uuid, ENT_QUOTES, 'UTF-8').'</code>'
            ."\nشروع بررسی: ".htmlspecialchars((string) $row->opened_at, ENT_QUOTES, 'UTF-8').' (UTC)'
            ."\nآخرین مشاهده: ".htmlspecialchars((string) $row->last_observed_at, ENT_QUOTES, 'UTF-8').' (UTC)'
            .($row->resolved_at === null ? '' : "\nزمان رفع: ".htmlspecialchars((string) $row->resolved_at, ENT_QUOTES, 'UTF-8').' (UTC)')
            .($causes === [] ? '' : "\nنشانه‌ها: ".implode('، ', array_keys($causes)))
            ."\n\nبرای مشاهده جزئیات: /filtering"
            ."\n\nاین یک هشدار مبتنی بر نشانه‌هاست؛ فیلترینگ قطعی یا سلامت مسیر کاربر را اثبات نمی‌کند.";
    }
}
