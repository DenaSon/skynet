<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminCommandCenter;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

/** OPS-CMD-01: trusted private Admin presentation; no financial/provider writes. */
final readonly class AdminCommandCenterUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminCommandCenter $center,
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

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        $failures = null;
        $attention = null;
        $snapshot = null;
        try {
            $snapshot = $this->center->handle($actor->userId);
            $text = $this->report($snapshot);
            $failures = self::number($snapshot['stats'], 'notificationIssues');
            $review = self::number($snapshot['stats'], 'fulfillmentReview');
            $overdue = self::number($snapshot['stats'], 'fulfillmentOverdue');
            if ($review !== null && $overdue !== null) {
                $attention = $review + $overdue;
            }
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (\Throwable) {
            $text = "<b>📡 دنابان | وضعیت دنا</b>\n\nدریافت گزارش فعلاً ممکن نیست. دوباره بررسی کنید.";
        }

        // Financial navigation first, then actionable follow-up, then system tools.
        // Every existing callback and permission/feature gate is retained.
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                '💳 امور مالی', callback_data: 'menu:admin:finance',
            ));

        if ($attention !== null && $attention > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '⚠️ پیگیری فعال‌سازی‌ها ('.number_format($attention).')',
                callback_data: 'full:attention:1',
            ));
        }

        if ($failures !== null && $failures > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔔 بررسی اعلان‌های ناموفق ('.number_format($failures).')',
                callback_data: 'denaban:notifications:1',
            ));
        }

        if (config('billing.topups_enabled', false)) {
            $keyboard->addRow(InlineKeyboardButton::make('💳 رسیدهای شارژ', callback_data: 'finance:topups:1'));
        }
        if (config('sales.manual_bank_review_enabled', false)) {
            $keyboard->addRow(InlineKeyboardButton::make('🧾 رسیدهای خرید', callback_data: 'bank:list'));
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('🖥️ سرورها', callback_data: 'menu:admin:servers'),
            InlineKeyboardButton::make('🩺 سلامت سیستم', callback_data: 'denaban:health'),
        );
        $keyboard->addRow(
            InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: 'denaban:show'),
            InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'),
        );
        $keyboard->addRow(InlineKeyboardButton::make(
            $simple ? '✨ نمای جدید' : '📝 نمای ساده',
            callback_data: $simple ? 'denaban:show' : 'denaban:simple',
        ));

        if (! $simple && $snapshot !== null) {
            try {
                TelegramUi::editRichText(
                    $bot,
                    new InputRichMessage(html: $this->richReport($snapshot), is_rtl: true),
                    $keyboard,
                );

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Keep the existing authorized HTML screen and all navigation.
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /** @param array{cash:array<string,mixed>|null,todayCash:array<string,mixed>|null,todayKpis:array<string,mixed>|null,resellers:?int,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string} $snapshot */
    private function report(array $snapshot): string
    {
        $cash = $snapshot['cash'];
        $todayCash = $snapshot['todayCash'];
        $todayKpis = $snapshot['todayKpis'];
        $resellers = $snapshot['resellers'];
        $stats = $snapshot['stats'];
        $pendingTopups = $snapshot['pendingTopups'];

        $items = [
            $pendingTopups,
            self::number($stats, 'receiptsPending'),
            self::number($stats, 'provisioningReview'),
            self::number($stats, 'fulfillmentReview'),
            self::number($stats, 'fulfillmentOverdue'),
            self::number($stats, 'failedJobs'),
            self::number($stats, 'notificationIssues'),
        ];
        $known = array_filter($items, static fn (?int $n): bool => $n !== null);
        $incomplete = count($known) !== count($items)
            || $cash === null || $todayCash === null || $todayKpis === null
            || $resellers === null || self::number($stats, 'nodes') === null;
        $todo = array_sum($known);
        $summary = $todo > 0
            ? number_format($todo).' مورد برای پیگیری ثبت شده؛ جزئیات را بررسی کنید.'
            : ($incomplete
                ? 'وضعیت بخش‌های در دسترس بررسی شد؛ بعضی اطلاعات کامل نیست.'
                : 'در بخش‌های بررسی‌شده موردی برای پیگیری ثبت نشده.');

        $lines = [
            '<b>📡 دنابان | وضعیت دنا</b>',
            '',
            '<b>💰 وضعیت مالی</b>',
            '💵 کل واریزی امروز: '.self::toman(self::number($todayCash, 'cashInRials')),
            '🛒 فروش امروز: '.self::toman(self::number($stats, 'salesTodayRials')),
            '🛍️ تعداد فروش امروز: '.self::format(self::number($todayKpis, 'transactions')),
            '🧾 تعداد واریزی امروز: '.self::format(self::depositCount($todayCash)),
            '💰 کل واریزی از ابتدا: '.self::toman(self::number($cash, 'cashInRials')),
            '💼 مانده اعتبار: '.self::toman(self::number($cash, 'currentWalletBalanceRials')),
            '',
            '<b>👥 کاربران و اشتراک‌ها</b>',
            'کل کاربران: '.self::format(self::number($stats, 'localUsers')),
            '🤝 نمایندگان: '.self::format($resellers),
            '👤 کاربران جدید امروز: '.self::format(self::number($todayKpis, 'newUsers')),
            'اشتراک‌های فعال: '.self::format(self::number($stats, 'subscriptionsActive')),
            'آنلاین پنل: '.self::format(self::number($stats, 'online')),
            '',
            '<b>⚠️ نیازمند رسیدگی</b>',
            $summary,
            '🧾 رسیدهای خرید: '.self::format(self::number($stats, 'receiptsPending')),
            '💳 رسیدهای شارژ: '.self::format($pendingTopups),
            '📦 عملیات اشتراک: '.self::format(self::number($stats, 'provisioningReview')),
            '🔧 بررسی سفارش‌ها: '.self::format(self::number($stats, 'fulfillmentReview')),
            '⏳ فعال‌سازی‌های معوق: '.self::format(self::number($stats, 'fulfillmentOverdue')),
            '⚙️ کارهای ناموفق: '.self::format(self::number($stats, 'failedJobs')),
            '🔔 اعلان‌های ناموفق: '.self::format(self::number($stats, 'notificationIssues')),
            '',
            '<b>🖥️ وضعیت سرورها</b>',
            'نودهای متصل: '.self::format(self::number($stats, 'connected'))
                .' از '.self::format(self::number($stats, 'nodes')),
            'هاست‌ها: '.self::format(self::number($stats, 'hosts')),
            ...self::nodeLines($stats),
            '',
            '<blockquote>واریزی‌ها فقط پرداخت‌های ثبت و تأییدشده‌اند؛ نه موجودی بانک یا سود خالص. خرید با کیف پول، واریزی جدید نیست. اتصال نود نیز تضمین دسترسی کاربران نیست. «نامشخص» به معنی صفر نیست.</blockquote>',
            '🔄 آخرین بررسی: '.PersianCalendar::localDateTime($snapshot['receivedAt']).' (تهران)',
        ];

        return implode("\n", $lines);
    }

    /**
     * Read-only Rich presentation of the exact same authorized snapshot used by
     * the legacy HTML view. Tables are deliberately two-column for mobile RTL.
     *
     * @param  array{cash:array<string,mixed>|null,todayCash:array<string,mixed>|null,todayKpis:array<string,mixed>|null,resellers:?int,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string}  $snapshot
     */
    private function richReport(array $snapshot): string
    {
        $cash = $snapshot['cash'];
        $todayCash = $snapshot['todayCash'];
        $todayKpis = $snapshot['todayKpis'];
        $resellers = $snapshot['resellers'];
        $stats = $snapshot['stats'];
        $pendingTopups = $snapshot['pendingTopups'];

        $items = [
            $pendingTopups,
            self::number($stats, 'receiptsPending'),
            self::number($stats, 'provisioningReview'),
            self::number($stats, 'fulfillmentReview'),
            self::number($stats, 'fulfillmentOverdue'),
            self::number($stats, 'failedJobs'),
            self::number($stats, 'notificationIssues'),
        ];
        $known = array_filter($items, static fn (?int $n): bool => $n !== null);
        $incomplete = count($known) !== count($items)
            || $cash === null || $todayCash === null || $todayKpis === null
            || $resellers === null || self::number($stats, 'nodes') === null;
        $todo = array_sum($known);
        $summary = $todo > 0
            ? number_format($todo).' مورد برای پیگیری ثبت شده؛ جزئیات را بررسی کنید.'
            : ($incomplete
                ? 'وضعیت بخش‌های در دسترس بررسی شد؛ بعضی اطلاعات کامل نیست.'
                : 'در بخش‌های بررسی‌شده موردی برای پیگیری ثبت نشده.');

        $nodes = implode('', array_map(
            static fn (string $line): string => '<p>'.$line.'</p>',
            self::nodeLines($stats),
        ));

        return '<h2>📡 دنابان | وضعیت دنا</h2>'
            .'<h3>💰 وضعیت مالی</h3>'
            .self::richTable([
                ['💵 کل واریزی امروز', self::toman(self::number($todayCash, 'cashInRials'))],
                ['🛒 فروش امروز', self::toman(self::number($stats, 'salesTodayRials'))],
                ['🛍️ تعداد فروش امروز', self::format(self::number($todayKpis, 'transactions'))],
                ['🧾 تعداد واریزی امروز', self::format(self::depositCount($todayCash))],
                ['💰 کل واریزی از ابتدا', self::toman(self::number($cash, 'cashInRials'))],
                ['💼 مانده اعتبار', self::toman(self::number($cash, 'currentWalletBalanceRials'))],
            ])
            .'<h3>👥 کاربران و اشتراک‌ها</h3>'
            .self::richTable([
                ['کل کاربران', self::format(self::number($stats, 'localUsers'))],
                ['🤝 نمایندگان', self::format($resellers)],
                ['👤 کاربران جدید امروز', self::format(self::number($todayKpis, 'newUsers'))],
                ['اشتراک‌های فعال', self::format(self::number($stats, 'subscriptionsActive'))],
                ['آنلاین پنل', self::format(self::number($stats, 'online'))],
            ])
            .'<h3>⚠️ نیازمند رسیدگی</h3>'
            .'<p>'.TelegramUi::escape($summary).'</p>'
            .self::richTable([
                ['🧾 رسیدهای خرید', self::format(self::number($stats, 'receiptsPending'))],
                ['💳 رسیدهای شارژ', self::format($pendingTopups)],
                ['📦 عملیات اشتراک', self::format(self::number($stats, 'provisioningReview'))],
                ['🔧 بررسی سفارش‌ها', self::format(self::number($stats, 'fulfillmentReview'))],
                ['⏳ فعال‌سازی‌های معوق', self::format(self::number($stats, 'fulfillmentOverdue'))],
                ['⚙️ کارهای ناموفق', self::format(self::number($stats, 'failedJobs'))],
                ['🔔 اعلان‌های ناموفق', self::format(self::number($stats, 'notificationIssues'))],
            ])
            .'<h3>🖥️ وضعیت سرورها</h3>'
            .self::richTable([
                ['نودهای متصل', self::format(self::number($stats, 'connected'))
                    .' از '.self::format(self::number($stats, 'nodes'))],
                ['هاست‌ها', self::format(self::number($stats, 'hosts'))],
            ])
            .'<details><summary>جزئیات نودها</summary>'.$nodes.'</details>'
            .'<details><summary>توضیحات آمار</summary><p>'
            .'واریزی‌ها فقط پرداخت‌های ثبت و تأییدشده‌اند؛ نه موجودی بانک یا سود خالص. '
            .'خرید با اعتبار کیف پول، واریزی جدید نیست. اتصال نود نیز تضمین دسترسی کاربران نیست. '
            .'«نامشخص» به معنی صفر نیست.</p></details>'
            .'<footer>🔄 آخرین بررسی: '.TelegramUi::escape(PersianCalendar::localDateTime($snapshot['receivedAt']))
            .' (تهران)</footer>';
    }

    /** @param list<array{string,string}> $rows */
    private static function richTable(array $rows): string
    {
        $html = '<table bordered striped compact>';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td>'.TelegramUi::escape($label).'</td><td align="left"><b>'
                .TelegramUi::escape($value).'</b></td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * Only the already-read /api/nodes inventory is used. Never show raw
     * node addresses, private network metadata or provider credentials.
     *
     * @param  array<string,mixed>|null  $stats
     * @return list<string>
     */
    private static function nodeLines(?array $stats): array
    {
        $nodes = $stats['nodeSummaries'] ?? null;
        if (! is_array($nodes)) {
            return ['جزئیات نودها: نامشخص'];
        }

        if ($nodes === []) {
            return ['هنوز نودی ثبت نشده است.'];
        }

        if (count($nodes) > 500) {
            return ['جزئیات نودها: نامشخص'];
        }

        foreach ($nodes as $node) {
            if (! is_array($node)
                || ! is_string($node['name'] ?? null)
                || ! is_bool($node['connected'] ?? null)
                || ! is_bool($node['disabled'] ?? null)) {
                return ['جزئیات نودها: نامشخص'];
            }
        }

        // Only safe Application data enters Telegram; prioritize problematic nodes.
        usort($nodes, static function (array $a, array $b): int {
            $priority = static fn (array $node): int => $node['disabled']
                ? 3 : ($node['connected'] ? 2 : (($node['connecting'] ?? null) === true ? 1 : 0));

            return ($priority($a) <=> $priority($b)) ?: strcmp($a['name'], $b['name']);
        });

        $lines = ['<b>جزئیات نودها (گزارش RW)</b>'];
        foreach (array_slice($nodes, 0, 5) as $node) {
            $status = $node['disabled'] ? '⏸️ غیرفعال'
                : ($node['connected'] ? '🟢 متصل'
                    : (($node['connecting'] ?? null) === true ? '🟠 در حال اتصال' : '🔴 قطع'));
            $name = TelegramUi::escape(mb_substr(
                (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $node['name']), 0, 50,
            ));
            $lines[] = $status.' · '.$name;
            $lines[] = '👥 آنلاین این نود: '.self::format(self::number($node, 'usersOnline'));
            $lines[] = '📡 دریافت: '.self::rate(self::number($node, 'rxBytesPerSec'))
                .' | ارسال: '.self::rate(self::number($node, 'txBytesPerSec'));

            if (! $node['connected'] && ! $node['disabled']) {
                $lines[] = '🕒 آخرین تغییر وضعیت: '.(! is_string($node['lastStatusChange'] ?? null)
                    ? 'نامشخص' : PersianCalendar::utcDateTime($node['lastStatusChange']));
            }

            if (($node['trafficTrackingActive'] ?? null) === true
                && ($limit = self::number($node, 'trafficLimitBytes')) !== null && $limit > 0
                && ($used = self::number($node, 'trafficUsedBytes')) !== null) {
                $percent = $used / $limit * 100;
                $lines[] = '📊 مصرف از سقف نود: '.number_format($percent, 1).'٪';
            }
        }

        if (count($nodes) > 5) {
            $lines[] = 'برای سایر نودها، مدیریت سرورها را باز کنید.';
        }

        return $lines;
    }

    private static function rate(?int $bytesPerSecond): string
    {
        if ($bytesPerSecond === null) {
            return 'نامشخص';
        }

        if ($bytesPerSecond < 1024) {
            return number_format($bytesPerSecond).' B/s';
        }

        if ($bytesPerSecond < 1048576) {
            return number_format($bytesPerSecond / 1024, 1).' KiB/s';
        }

        return number_format($bytesPerSecond / 1048576, 2).' MiB/s';
    }

    /** @param array<string,mixed>|null $cash */
    private static function depositCount(?array $cash): ?int
    {
        $topups = self::number($cash, 'topupsCount');
        $bank = self::number($cash, 'bankSalesCount');

        return $topups === null || $bank === null ? null : $topups + $bank;
    }

    /** @param array<string,mixed>|null $data */
    private static function number(?array $data, string $key): ?int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : null;
    }

    private static function format(?int $value): string
    {
        return $value === null ? 'نامشخص' : number_format($value);
    }

    private static function toman(?int $rials): string
    {
        return $rials === null ? 'نامشخص' : number_format(intdiv($rials, 10)).' تومان';
    }
}
