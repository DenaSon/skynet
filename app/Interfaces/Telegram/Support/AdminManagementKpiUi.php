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
            .self::richInsights($data)
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
            .self::simpleInsights($data)
            ."\n\n<blockquote>فروش تسویه‌شده است، نه سود خالص یا شارژ کیف پول."
            .' «بدون مبنا» یعنی بازه قبل صفر بوده است.</blockquote>'
            ."\n🕒 ".PersianCalendar::localDateTime($data['generatedAt']).' (تهران)';
    }

    /**
     * One rule-based analysis source for both Rich and native fallback.
     * Do not infer why revenue moved from these two KPIs alone.
     *
     * @param  array<string,mixed>  $data
     * @return array<string, string>
     */
    private static function insights(array $data): array
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $salesChange = $current['revenueRials'] - $previous['revenueRials'];
        $usersChange = $current['newUsers'] - $previous['newUsers'];

        $sales = 'در بازه '.self::range($current, $data['period'])
            .'، فروش '.self::money($current['revenueRials']).' ثبت شد. ';
        if ($previous['revenueRials'] === 0) {
            $sales .= 'فروش بازه قبل صفر بوده و درصد رشد بدون مبناست.';
        } elseif ($salesChange === 0) {
            $sales .= 'فروش نسبت به بازه قبل تغییری نداشته است.';
        } else {
            $sales .= 'نسبت به بازه قبل '.self::money(abs($salesChange))
                .($salesChange > 0 ? ' افزایش' : ' کاهش')
                .' ('.self::percent($data['growth']['salesPercent']).') داشته است.';
        }

        $users = 'ثبت‌نام کاربران از '.self::count($previous['newUsers'])
            .' به '.self::count($current['newUsers']).' نفر رسیده است. ';
        if ($previous['newUsers'] === 0) {
            $users .= 'ثبت‌نام بازه قبل صفر بوده و درصد رشد بدون مبناست.';
        } elseif ($usersChange === 0) {
            $users .= 'تعداد ثبت‌نام‌ها نسبت به بازه قبل ثابت مانده است.';
        } else {
            $users .= self::count(abs($usersChange)).' نفر '
                .($usersChange > 0 ? 'افزایش' : 'کاهش')
                .' ('.self::percent($data['growth']['newUsersPercent']).') ثبت شده است.';
        }

        if ($previous['revenueRials'] === 0 || $previous['newUsers'] === 0) {
            $takeaway = 'حداقل یکی از شاخص‌ها در بازه قبل صفر بوده؛ مقایسه درصدی کامل دو شاخص ممکن نیست.';
        } elseif ($salesChange > 0 && $usersChange < 0) {
            $takeaway = 'با وجود کاهش ثبت‌نام‌ها، فروش افزایش یافته است؛ این هم‌زمانی علت رشد فروش را مشخص نمی‌کند.';
        } elseif ($salesChange < 0 && $usersChange > 0) {
            $takeaway = 'با وجود افزایش ثبت‌نام‌ها، فروش کاهش یافته است؛ علت این اختلاف از این دو شاخص مشخص نیست.';
        } else {
            $trend = static fn (int $change): string => $change > 0
                ? 'افزایش یافته' : ($change < 0 ? 'کاهش یافته' : 'ثابت مانده');
            $takeaway = 'فروش '.$trend($salesChange).' و ثبت‌نام '.$trend($usersChange)
                .'؛ این دو شاخص به‌تنهایی علت تغییرات را نشان نمی‌دهند.';
        }

        $tip = $current['revenueRials'] === 0 && $previous['revenueRials'] === 0
            ? 'اگر انتظار فروش وجود دارد، وضعیت تسویه‌های ثبت‌شده را بررسی کنید.'
            : 'برای بررسی عوامل تغییر فروش، تعداد تراکنش‌ها و میانگین مبلغ سفارش را بین دو دوره مقایسه کنید.';

        return [
            '💰 فروش' => $sales,
            '👥 کاربران' => $users,
            '📈 برداشت مدیریتی' => $takeaway,
            '💡 نکته قابل بررسی' => $tip,
        ];
    }

    /** @param  array<string,mixed>  $data */
    private static function richInsights(array $data): string
    {
        $html = '<details><summary>🧠 تحلیل عملکرد دوره</summary>';
        foreach (self::insights($data) as $title => $text) {
            $html .= '<p><b>'.TelegramUi::escape($title).'</b> '
                .TelegramUi::escape($text).'</p>';
        }

        return $html.'</details>';
    }

    /** @param  array<string,mixed>  $data */
    private static function simpleInsights(array $data): string
    {
        $html = "\n\n<b>🧠 تحلیل عملکرد دوره</b>";
        foreach (self::insights($data) as $title => $text) {
            $html .= "\n<b>".TelegramUi::escape($title).'</b> '.TelegramUi::escape($text);
        }

        return $html;
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
