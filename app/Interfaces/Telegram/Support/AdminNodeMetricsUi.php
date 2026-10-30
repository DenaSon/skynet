<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminNodeMetrics;
use App\Modules\Provisioning\Application\ReadAdminTrafficReport;
use App\Support\PersianCalendar;
use DateTimeImmutable;
use DateTimeZone;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

/** MET-01: one readable Admin Bot snapshot; no polling or background service. */
final readonly class AdminNodeMetricsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminNodeMetrics $metrics,
        private ReadAdminTrafficReport $traffic,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, bool $simple = false): void
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        $snapshot = $this->metrics->handle($actor->userId);
        // Reuse the existing MET-02 30-day historical read, not a new provider integration.
        // One 30-day series supplies both KPIs, but missing days are never counted as zero.
        $totals = ['seven' => null, 'thirty' => null];
        if ($snapshot['state'] === 'available') {
            $report = $this->traffic->summary($actor->userId, '30');
            $totals = self::periodTotals($report);
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی آمار', callback_data: 'metrics:refresh'))
            ->addRow(InlineKeyboardButton::make('📈 گزارش مصرف', callback_data: 'metrics:range:7'))
            ->addRow(InlineKeyboardButton::make('🖥️ مدیریت نودها', callback_data: 'node1:list:1'))
            ->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'))
            ->addRow(InlineKeyboardButton::make(
                $simple ? '✨ نمای جدید' : '📝 نمای ساده',
                callback_data: $simple ? 'metrics:refresh' : 'metrics:simple',
            ));

        if ($snapshot['state'] !== 'available') {
            $message = "📊 مانیتورینگ سرورها\n\nاطلاعات فعلاً در دسترس نیست. اتصال پنل را بررسی کنید و دوباره تلاش کنید.";
        } else {
            $message = "📊 مانیتورینگ سرورها\n\n"
                ."🖥️ نودها: {$snapshot['total']} | فعال: {$snapshot['enabled']} | متصل: {$snapshot['connected']}\n"
                .'👥 کاربران آنلاین کل: '.self::counter($snapshot['onlineTotal'])."\n"
                .'📈 مصرف تاریخ '.PersianCalendar::date($snapshot['trafficDate']).' (روز UTC): '.self::bytes($snapshot['todayBytes'])."\n"
                .'📈 مجموع مصرف ۷ روز اخیر: '.self::bytes($totals['seven'])."\n"
                .'📈 مجموع مصرف ۳۰ روز اخیر: '.self::bytes($totals['thirty'])."\n"
                ."\n".'وضعیت نودها:'."\n";

            if ($snapshot['nodes'] === []) {
                $message .= 'هنوز نودی ثبت نشده است.';
            } else {
                foreach (array_slice($snapshot['nodes'], 0, 10) as $node) {
                    $status = $node['disabled'] ? '⏸️' : ($node['connected'] ? '🟢' : '🔴');
                    $online = $node['online'] === null ? 'نامشخص' : (string) $node['online'];
                    $message .= "\n".$status.' '.self::safe($node['name']).' · آنلاین: '.$online
                        .' · TCP: '.self::tcp($node['tcp'], $node['disabled']);
                }
                if (count($snapshot['nodes']) > 10) {
                    $message .= "\n".'برای مشاهده سایر نودها، فهرست مدیریت را باز کنید.';
                }
            }

            $message .= "\n\n".'🕒 زمان دریافت: '.PersianCalendar::localDateTime($snapshot['receivedAt']).' (تهران)'
                ."\n".'— آمار لحظه دریافت است؛ صفر با اطلاعات ناموجود تفاوت دارد.';
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        if (! $simple && $snapshot['state'] === 'available') {
            try {
                TelegramUi::editRichText(
                    $bot,
                    new InputRichMessage(html: self::richReport($snapshot, $totals), is_rtl: true),
                    $keyboard,
                );

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Native view retains the original actions and the same trusted snapshot.
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }

    /**
     * Read-only KPI projection of an already validated MET-02 daily series.
     * Show no total unless every UTC day in its period exists; never fabricate
     * zero consumption for an omitted day or overflow an integer accumulator.
     *
     * @param  array{state:string,days:int,start:string,end:string,trend:list<array{date:string,total:int}>,nodes:list<array{uuid:string,name:string,total:int|null}>,receivedAt:string|null}  $report
     * @return array{seven:int|null,thirty:int|null}
     */
    private static function periodTotals(array $report): array
    {
        $totals = ['seven' => null, 'thirty' => null];
        if ($report['state'] !== 'available' || $report['days'] !== 30) {
            return $totals;
        }

        $daily = [];
        foreach ($report['trend'] as $item) {
            $daily[$item['date']] = $item['total'];
        }

        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $report['end'], new DateTimeZone('UTC'));
        if ($end === false) {
            return $totals;
        }

        foreach (['seven' => 7, 'thirty' => 30] as $key => $days) {
            $total = 0;
            for ($i = 0; $i < $days; $i++) {
                $day = $end->modify("-{$i} days")->format('Y-m-d');
                $bytes = $daily[$day] ?? null;
                if (! is_int($bytes) || $bytes < 0 || $bytes > PHP_INT_MAX - $total) {
                    continue 2;
                }
                $total += $bytes;
            }
            $totals[$key] = $total;
        }

        return $totals;
    }

    /**
     * @param  array{state:string,nodes:list<array{name:string,connected:bool,disabled:bool,online:int|null,tcp:array{status:string,ms:float|null,checkedAt:int|null}}>,total:int,connected:int,enabled:int,onlineTotal:int|null,todayBytes:int|null,weekBytes:int|null,trafficDate:string,receivedAt:string|null}  $snapshot
     * @param  array{seven:int|null,thirty:int|null}  $totals
     */
    private static function richReport(array $snapshot, array $totals): string
    {
        $rows = '';
        foreach (array_slice($snapshot['nodes'], 0, 10) as $node) {
            $status = $node['disabled'] ? '⏸️ غیرفعال' : ($node['connected'] ? '🟢 متصل' : '🔴 قطع');
            $rows .= '<tr><td>'.TelegramUi::escape(self::safe($node['name'])).'</td>'
                .'<td>'.TelegramUi::escape($status).'</td>'
                .'<td>👥 '.self::counter($node['online']).' · '.self::tcp($node['tcp'], $node['disabled']).'</td></tr>';
        }
        $nodes = $rows === '' ? '<p>هنوز نودی ثبت نشده است.</p>'
            : '<table bordered striped compact>'
                .'<tr><td><b>نود</b></td><td><b>وضعیت</b></td><td><b>آنلاین / TCP</b></td></tr>'
                .$rows.'</table>';
        if (count($snapshot['nodes']) > 10) {
            $nodes .= '<p>برای مشاهده سایر نودها، فهرست مدیریت را باز کنید.</p>';
        }

        $checks = '';
        foreach (array_slice($snapshot['nodes'], 0, 10) as $node) {
            if ($node['tcp']['checkedAt'] !== null && ! $node['disabled']) {
                $checks .= '<p>'.TelegramUi::escape(self::safe($node['name'])).': '
                    .self::tcpTime($node['tcp']['checkedAt']).'</p>';
            }
        }
        $checkDetails = '<details><summary>🕒 زمان آخرین بررسی TCP</summary>'
            .($checks === '' ? '<p>هنوز نتیجه‌ای ثبت نشده است.</p>' : $checks).'</details>';

        return '<h2>📊 مانیتورینگ سرورها</h2>'
            .'<h3>📈 مصرف کل شبکه</h3>'
            .'<table bordered striped compact>'
            .'<tr><td>📅 ۷ روز اخیر</td><td><b>'.self::bytes($totals['seven']).'</b></td></tr>'
            .'<tr><td>📆 ۳۰ روز اخیر</td><td><b>'.self::bytes($totals['thirty']).'</b></td></tr>'
            .'</table>'
            .'<h3>🖥️ وضعیت شبکه</h3>'
            .'<p>نودها: <b>'.$snapshot['total'].'</b> | فعال: <b>'.$snapshot['enabled']
            .'</b> | متصل: <b>'.$snapshot['connected'].'</b></p>'
            .'<p>👥 کاربران آنلاین کل: <b>'.self::counter($snapshot['onlineTotal']).'</b></p>'
            .'<h3>🌐 وضعیت نودها</h3>'.$nodes
            .'<details><summary>🔎 آمار و توضیحات تکمیلی</summary>'
            .'<p>مصرف روز '.TelegramUi::escape(PersianCalendar::date($snapshot['trafficDate']))
            .' (UTC): <b>'.self::bytes($snapshot['todayBytes']).'</b></p>'
            .'<p>ترافیک تجمیعی به معنی سرعت لحظه‌ای یا Ping نیست.</p>'
            .'<p>TCP از سرور دناگیت تا پورت تأییدشده اندازه‌گیری می‌شود؛ نه از اینترنت کاربران.</p>'
            .'<p>اطلاعات ناقص یا ناموجود، صفر محسوب نمی‌شوند.</p>'
            .'</details>'
            .$checkDetails
            .'<footer>🕒 دریافت: '.TelegramUi::escape(PersianCalendar::localDateTime($snapshot['receivedAt']))
            .' (تهران)</footer>';
    }

    /** @param array{status:string,ms:float|null,checkedAt:int|null} $value */
    private static function tcp(array $value, bool $disabled): string
    {
        if ($disabled) {
            return '⏸️ —';
        }
        if ($value['status'] === 'ok' && $value['ms'] !== null) {
            return '🟢 '.number_format($value['ms'], 1, '.', '').' ms';
        }

        return $value['status'] === 'failed' ? '🔴 بدون پاسخ' : '⚪ نامشخص';
    }

    private static function tcpTime(int $timestamp): string
    {
        return TelegramUi::escape(PersianCalendar::utcDateTime(gmdate('Y-m-d H:i:s', $timestamp)));
    }

    private static function counter(?int $value): string
    {
        return $value === null ? 'نامشخص' : number_format($value);
    }

    private static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'نامشخص';
        }
        if ($bytes < 1048576) {
            return number_format($bytes).' بایت';
        }
        if ($bytes < 1073741824) {
            return number_format($bytes / 1048576, 2).' MiB';
        }

        return number_format($bytes / 1073741824, 2).' GiB';
    }

    private static function safe(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 50);
    }
}
