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

        $snapshot = null;
        try {
            $snapshot = $this->center->handle($actor->userId, compact: true);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (\Throwable) {
            // The native error screen remains available when the read fails.
        }

        // Financial navigation and system tools. Follow-up stays in Health.
        // Every existing callback and permission/feature gate is retained.
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                '💳 امور مالی', callback_data: 'menu:admin:finance',
            ));

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

        $text = $snapshot === null
            ? "<b>📡 دنابان | وضعیت دنا</b>\n\nدریافت گزارش فعلاً ممکن نیست. دوباره بررسی کنید."
            : $this->report($snapshot);

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /** @param array{cash:array<string,mixed>|null,todayCash:array<string,mixed>|null,yesterdayCash:array<string,mixed>|null,todayKpis:array<string,mixed>|null,resellers:?int,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string} $snapshot */
    private function report(array $snapshot): string
    {
        $cash = $snapshot['cash'];
        $todayCash = $snapshot['todayCash'];
        $yesterdayCash = $snapshot['yesterdayCash'];
        $todayKpis = $snapshot['todayKpis'];
        $resellers = $snapshot['resellers'];
        $stats = $snapshot['stats'];

        $lines = [
            '<b>📡 دنابان | وضعیت دنا</b>',
            '',
            '<b>💰 وضعیت مالی</b>',
            '💵 کل واریزی امروز: '.self::toman(self::number($todayCash, 'cashInRials')),
            '💵 دیروز تا همین ساعت: '.self::toman(self::number($yesterdayCash, 'cashInRials')),
            '📊 تغییر نسبت به دیروز: '.self::cashTrend($todayCash, $yesterdayCash),
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
            '',
            '<b>🖥️ وضعیت سرورها</b>',
            'مجموع آنلاین نودها: '.self::format(self::number($stats, 'nodesOnline')),
            'آخرین دریافت آمار پنل: '.self::providerTime($stats),
            '',
            '<blockquote>واریزی‌ها فقط پرداخت‌های ثبت و تأییدشده‌اند؛ نه موجودی بانک یا سود خالص. خرید با کیف پول، واریزی جدید نیست. مقایسه روزانه تا ساعت یکسان تهران است. مجموع آنلاین نودها، تعداد کاربران یکتا نیست. «نامشخص» به معنی صفر نیست.</blockquote>',
            '🔄 آخرین بررسی: '.PersianCalendar::localDateTime($snapshot['receivedAt']).' (تهران)',
        ];

        return implode("\n", $lines);
    }

    /**
     * Read-only Rich presentation of the exact same authorized snapshot used by
     * the legacy HTML view. Tables are deliberately two-column for mobile RTL.
     *
     * @param  array{cash:array<string,mixed>|null,todayCash:array<string,mixed>|null,yesterdayCash:array<string,mixed>|null,todayKpis:array<string,mixed>|null,resellers:?int,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string}  $snapshot
     */
    private function richReport(array $snapshot): string
    {
        $cash = $snapshot['cash'];
        $todayCash = $snapshot['todayCash'];
        $yesterdayCash = $snapshot['yesterdayCash'];
        $todayKpis = $snapshot['todayKpis'];
        $resellers = $snapshot['resellers'];
        $stats = $snapshot['stats'];

        return '<h2>📡 دنابان | وضعیت دنا</h2>'
            .'<h3>💰 وضعیت مالی</h3>'
            .self::richTable([
                ['💵 کل واریزی امروز', self::toman(self::number($todayCash, 'cashInRials'))],
                ['💵 دیروز تا همین ساعت', self::toman(self::number($yesterdayCash, 'cashInRials'))],
                ['📊 تغییر نسبت به دیروز', self::cashTrend($todayCash, $yesterdayCash)],
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
            ])
            .'<h3>🖥️ وضعیت سرورها</h3>'
            .self::richTable([
                ['مجموع آنلاین نودها', self::format(self::number($stats, 'nodesOnline'))],
            ])
            .'<p>آخرین دریافت آمار پنل: '.TelegramUi::escape(self::providerTime($stats)).'</p>'
            .'<details><summary>توضیحات آمار</summary><p>'
            .'واریزی‌ها فقط پرداخت‌های ثبت و تأییدشده‌اند؛ نه موجودی بانک یا سود خالص. '
            .'خرید با اعتبار کیف پول، واریزی جدید نیست. مقایسه روزانه تا ساعت یکسان تهران است. مجموع آنلاین نودها، تعداد کاربران یکتا نیست. '
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
     * Change from yesterday's same-time verified cash receipts.
     *
     * @param  array<string,mixed>|null  $today
     * @param  array<string,mixed>|null  $yesterday
     */
    private static function cashTrend(?array $today, ?array $yesterday): string
    {
        $current = self::number($today, 'cashInRials');
        $previous = self::number($yesterday, 'cashInRials');
        if ($current === null || $previous === null) {
            return 'نامشخص';
        }

        if ($previous === 0) {
            return 'بدون مبنا';
        }

        $change = round(($current - $previous) / $previous * 100, 1);
        $icon = $change > 0 ? '📈' : ($change < 0 ? '📉' : '➖');
        $sign = $change > 0 ? '+' : ($change < 0 ? '−' : '');
        $percent = PersianCalendar::digits(str_replace('.', '٫', number_format(abs($change), 1)));

        return $icon.' '.$sign.$percent.'٪';
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

    /** @param array<string,mixed>|null $stats */
    private static function providerTime(?array $stats): string
    {
        $timestamp = self::number($stats, 'providerObservedAt');

        return $timestamp === null ? 'نامشخص' : PersianCalendar::utcDateTime(gmdate('Y-m-d H:i:s', $timestamp));
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
