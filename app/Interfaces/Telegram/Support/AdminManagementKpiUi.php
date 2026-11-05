<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ReadAdminManagementKpis;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** KPI-UI-01: independent, read-only Rich executive comparisons for trusted roots. */
final readonly class AdminManagementKpiUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminManagementKpis $kpis,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $period = 'day'): void
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

        if (! in_array($period, ['day', 'week', 'month'], true)) {
            $this->render($bot, 'بازه گزارش معتبر نیست.', $this->keyboard('day'));

            return;
        }

        $keyboard = $this->keyboard($period);
        $rich = null;
        try {
            $data = $this->kpis->handle($actor->userId, $period);
            $text = $this->simpleReport($data);
            $rich = $this->richReport($data);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $text = 'بازه گزارش معتبر نیست.';
        } catch (Throwable) {
            $text = 'دریافت شاخص‌های مدیریتی فعلاً ممکن نیست. دوباره بررسی کنید.';
        }

        $this->render($bot, $text, $keyboard, $rich);
    }

    private function keyboard(string $selected): InlineKeyboardMarkup
    {
        $button = static fn (string $period, string $label): InlineKeyboardButton => InlineKeyboardButton::make(
            ($selected === $period ? '✅ ' : '').$label,
            callback_data: 'kpi:period:'.$period,
        );

        return InlineKeyboardMarkup::make()
            ->addRow(
                $button('day', 'روزانه'),
                $button('week', 'هفتگی'),
                $button('month', 'ماهانه'),
            )
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'kpi:period:'.$selected))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
    }

    /**
     * @param  array<string,mixed>  $data  ReadAdminManagementKpis authorized result
     */
    private function richReport(array $data): string
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $growth = $data['growth'];

        return '<h2>📊 شاخص‌های مدیریتی · '.self::label($data['period']).'</h2>'
            .'<p>'.self::description($data['period']).'</p>'
            .'<p>📅 بازه اخیر: '.self::range($current, $data['period']).'</p>'
            .'<p>📅 بازه مقایسه: '.self::range($previous, $data['period']).'</p>'
            .'<h3>💰 عملکرد فروش</h3>'
            .self::table([
                ['فروش بازه اخیر', self::money($current['revenueRials'])],
                ['فروش بازه قبل', self::money($previous['revenueRials'])],
                ['تغییر فروش', self::percent($growth['salesPercent'])],
                ['تراکنش‌ها · بازه اخیر', self::count($current['transactions'])],
                ['خریداران یکتا · بازه اخیر', self::count($current['uniqueBuyers'])],
                ['خرید جدید · بازه اخیر', self::count($current['newPurchases'])],
                ['تمدید · بازه اخیر', self::count($current['renewals'])],
            ])
            .'<h3>👥 ثبت‌نام کاربران</h3>'
            .self::table([
                ['کاربران جدید · بازه اخیر', self::count($current['newUsers'])],
                ['کاربران جدید · بازه قبل', self::count($previous['newUsers'])],
                ['تغییر ثبت‌نام', self::percent($growth['newUsersPercent'])],
            ])
            .'<footer>🕒 '.TelegramUi::escape(PersianCalendar::localDateTime($data['generatedAt']))
            .' (تهران) · فروش تسویه‌شده، نه سود خالص یا شارژ کیف پول.'
            .' «بدون مبنا» یعنی مقدار بازه قبل صفر بوده است.</footer>';
    }

    /**
     * Native HTML fallback uses the exact same authorized result and buttons.
     *
     * @param  array<string,mixed>  $data
     */
    private function simpleReport(array $data): string
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $growth = $data['growth'];

        return '<b>📊 شاخص‌های مدیریتی · '.self::label($data['period']).'</b>'
            ."\n".self::description($data['period'])
            ."\n📅 بازه اخیر: ".self::range($current, $data['period'])
            ."\n📅 بازه مقایسه: ".self::range($previous, $data['period'])
            ."\n\n<b>💰 عملکرد فروش</b>"
            ."\nفروش بازه اخیر: ".self::money($current['revenueRials'])
            ."\nفروش بازه قبل: ".self::money($previous['revenueRials'])
            ."\nتغییر فروش: ".self::percent($growth['salesPercent'])
            ."\nتراکنش‌ها: ".self::count($current['transactions'])
            ."\nخریداران یکتا: ".self::count($current['uniqueBuyers'])
            ."\nخرید جدید: ".self::count($current['newPurchases'])
            ."\nتمدید: ".self::count($current['renewals'])
            ."\n\n<b>👥 ثبت‌نام کاربران</b>"
            ."\nکاربران جدید · بازه اخیر: ".self::count($current['newUsers'])
            ."\nکاربران جدید · بازه قبل: ".self::count($previous['newUsers'])
            ."\nتغییر ثبت‌نام: ".self::percent($growth['newUsersPercent'])
            ."\n\n<blockquote>فروش تسویه‌شده است، نه سود خالص یا شارژ کیف پول."
            .' «بدون مبنا» یعنی بازه قبل صفر بوده است.</blockquote>'
            ."\n🕒 ".PersianCalendar::localDateTime($data['generatedAt']).' (تهران)';
    }

    private static function label(string $period): string
    {
        return match ($period) {
            'day' => 'روزانه',
            'week' => 'هفتگی',
            'month' => 'ماهانه',
            default => '',
        };
    }

    private static function description(string $period): string
    {
        return match ($period) {
            'day' => 'امروز تا این ساعت، در مقایسه با دیروز تا همین ساعت',
            'week' => '۷ روز کامل گذشته، در مقایسه با ۷ روز قبل‌تر',
            'month' => 'ماه شمسی کامل گذشته، در مقایسه با ماه قبل‌تر',
            default => '',
        };
    }

    /** @param array{from:string,until:string} $window */
    private static function range(array $window, string $period): string
    {
        $start = PersianCalendar::date(substr($window['from'], 0, 10));

        if ($period === 'day') {
            return $start.' · '.PersianCalendar::digits(substr($window['from'], 11, 5))
                .' تا '.PersianCalendar::digits(substr($window['until'], 11, 5));
        }

        $lastDay = Carbon::parse($window['until'], 'Asia/Tehran')->subSecond()->format('Y-m-d');

        return $start.' تا '.PersianCalendar::date($lastDay);
    }

    /** @param list<array{string,string}> $rows */
    private static function table(array $rows): string
    {
        $html = '<table bordered striped compact>';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
                .TelegramUi::escape($value).'</b></td></tr>';
        }

        return $html.'</table>';
    }

    private static function count(int $value): string
    {
        return PersianCalendar::digits(number_format($value));
    }

    private static function money(int $rials): string
    {
        return TelegramUi::tomanPersian($rials).' تومان';
    }

    private static function percent(?float $growth): string
    {
        if ($growth === null) {
            return 'بدون مبنا';
        }

        $sign = $growth > 0 ? '+' : ($growth < 0 ? '−' : '');
        $amount = str_replace('.', '٫', number_format(abs($growth), 1));

        return $sign.PersianCalendar::digits($amount).'٪';
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, ?string $rich = null): void
    {
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Native fallback keeps exactly the same data and navigation.
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
