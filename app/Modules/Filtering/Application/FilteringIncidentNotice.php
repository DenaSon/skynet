<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use App\Support\PersianCalendar;
use Carbon\CarbonImmutable;
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
        $causeCodes = [];
        $hasEnabledCause = false;
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
                    'online_drop' => 'افت غیرعادی کاربران',
                    'traffic_drop' => 'افت غیرعادی ترافیک',
                    'regional_possible' => 'دسترسی نامطمئن از ایران',
                    'regional_likely' => 'اختلال گسترده دسترسی از ایران',
                    default => null,
                };
                if ($label !== null) {
                    $causes[$label] = true;
                    $causeCodes[$cause] = true;
                    $hasEnabledCause = true;
                }
            }
        }

        if ($kind !== 'recover' && ! $hasEnabledCause) {
            return null;
        }

        // Escalation's incident last_observed_at changes on later scans.
        // The outbox intent's created_at is immutable across delivery retries.
        $eventTime = match ($kind) {
            'open' => $row->opened_at,
            'recover' => $row->resolved_at,
            default => is_string($delivery->created_at ?? null)
                ? $delivery->created_at : $row->last_observed_at,
        };
        $time = PersianCalendar::utcDateTime((string) $eventTime);
        $localHour = CarbonImmutable::parse((string) $eventTime, 'UTC')->setTimezone('Asia/Tehran')->hour;
        $period = $localHour >= 1 && $localHour < 7
            ? '🌙 هشدار شبانه'
            : '📡 هشدار فعالیت غیرعادی';

        // Use the saved observation from the event time, not a later Node GET.
        // If the historical observation is missing or stale, report UNKNOWN.
        $nodeEvidence = $this->nodeEvidenceAt((string) $row->node_uuid, CarbonImmutable::parse((string) $eventTime, 'UTC'));
        $metrics = self::activityLines($kind, $causeCodes, $nodeEvidence);

        return '<b>'.$heading.' — '.$escaped.'</b>'
            .($kind === 'recover' || $causes === [] ? ''
                : "\n".$period.' | '.(count($causes) === 1 ? 'نشانه: ' : 'نشانه‌ها: ')
                    .implode('، ', array_keys($causes)))
            .implode('', array_map(static fn (string $line): string => "\n".$line, $metrics))
            ."\n🕒 ".$time.' (تهران)';
    }

    /** @return array<string, mixed> */
    private function nodeEvidenceAt(string $uuid, CarbonImmutable $eventAt): array
    {
        $check = DB::table('filtering_checks')
            ->where('node_uuid', $uuid)->where('module', 'node')
            ->where('observed_at', '<=', $eventAt->toDateTimeString())
            ->where('observed_at', '>=', $eventAt->subMinutes(3)->toDateTimeString())
            ->orderByDesc('observed_at')->orderByDesc('id')
            ->first(['evidence']);

        $data = $check === null ? null : json_decode((string) $check->evidence, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Display only approved numeric evidence. The traffic rate is the larger
     * of interface RX/TX bytes/sec, matching MonitorNodeFiltering's signal.
     *
     * @param  array<string, true>  $causes
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    private static function activityLines(string $kind, array $causes, array $evidence): array
    {
        $online = self::nonNegative($evidence['online_users'] ?? null);
        $onlineText = $online === null ? 'نامشخص' : number_format($online).' نفر';
        if ($kind !== 'recover' && isset($causes['online_drop'])) {
            $reference = self::nonNegative($evidence['online_reference'] ?? null);
            if ($reference !== null && $reference > 0 && $online !== null) {
                $percent = self::dropPercent($reference, $online);
                $onlineText = number_format($reference).' ← '.number_format($online).' نفر ('.$percent.'٪ کاهش)';
            }
        }

        $lines = [];
        if ($kind !== 'recover' && isset($causes['traffic_drop'])) {
            $rx = self::nonNegative($evidence['rx_bytes_per_sec'] ?? null);
            $tx = self::nonNegative($evidence['tx_bytes_per_sec'] ?? null);
            $current = $rx !== null && $tx !== null ? max($rx, $tx) : null;
            $reference = self::nonNegative($evidence['traffic_reference'] ?? null);
            $percent = $reference !== null && $reference > 0 && $current !== null
                ? self::dropPercent($reference, $current).'٪'
                : 'نامشخص';
            $lines[] = '📉 افت ترافیک: '.$percent;
            $lines[] = '📊 نرخ ترافیک: '.self::rate($reference).' ← '.self::rate($current);
        }

        $lines[] = '👥 کاربران آنلاین: '.$onlineText;

        return $lines;
    }

    private static function nonNegative(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private static function dropPercent(int $baseline, int $current): int
    {
        return max(0, (int) floor(100 * (1 - $current / $baseline)));
    }

    private static function rate(?int $bytesPerSecond): string
    {
        if ($bytesPerSecond === null) {
            return 'نامشخص';
        }
        if ($bytesPerSecond >= 1048576) {
            return number_format($bytesPerSecond / 1048576, 2).' MiB/s';
        }
        if ($bytesPerSecond >= 1024) {
            return number_format($bytesPerSecond / 1024, 1).' KiB/s';
        }

        return number_format($bytesPerSecond).' B/s';
    }
}
