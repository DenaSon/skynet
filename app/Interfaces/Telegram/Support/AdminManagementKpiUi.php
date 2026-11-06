<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ReadAdminSalesChannelKpis;
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
        private ReadAdminSalesChannelKpis $channels,
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
            // One authorized snapshot includes the canonical management metrics and
            // both channel windows; do not issue an independent KPI read.
            $report = $this->channels->handle($actor->userId, $period);
            $data = $report['management'];
            $text = $this->simpleReport($data, $report);
            $rich = $this->richReport($data, $report);
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
    private function richReport(array $data, ?array $channels = null): string
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $growth = $data['growth'];

        return '<h2>📊 شاخص‌های مدیریتی · '.self::label($data['period']).'</h2>'
            .'<p>'.self::description($data['period']).'</p>'
            .'<p>📅 بازه اخیر: '.self::range($current, $data['period']).'</p>'
            .'<p>📅 بازه مقایسه: '.self::range($previous, $data['period']).'</p>'
            .'<h3>💰 عملکرد فروش</h3>'
            .self::comparisonTable(self::salesRows($data))
            .($channels === null ? '' : self::richChannels($channels))
            .'<h3>👥 ثبت‌نام کاربران</h3>'
            .self::table([
                ['کاربران جدید · بازه اخیر', self::count($current['newUsers'])],
                ['کاربران جدید · بازه قبل', self::count($previous['newUsers'])],
                ['تغییر ثبت‌نام', self::percent($growth['newUsersPercent'])],
            ])
            .self::richInsights($data, $channels)
            .'<footer>🕒 '.TelegramUi::escape(PersianCalendar::localDateTime($data['generatedAt']))
            .' (تهران) · فروش تسویه‌شده، نه سود خالص یا شارژ کیف پول.'
            .' «بدون مبنا» یعنی مقدار بازه قبل صفر بوده است.'
            .' میانگین به نزدیک‌ترین تومان گرد شده است.</footer>';
    }

    /**
     * Native HTML fallback uses the exact same authorized result and buttons.
     *
     * @param  array<string,mixed>  $data
     */
    private function simpleReport(array $data, ?array $channels = null): string
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $growth = $data['growth'];

        return '<b>📊 شاخص‌های مدیریتی · '.self::label($data['period']).'</b>'
            ."\n".self::description($data['period'])
            ."\n📅 بازه اخیر: ".self::range($current, $data['period'])
            ."\n📅 بازه مقایسه: ".self::range($previous, $data['period'])
            ."\n\n<b>💰 عملکرد فروش</b>"
            .self::simpleComparisons(self::salesRows($data))
            .($channels === null ? '' : self::simpleChannels($channels))
            ."\n\n<b>👥 ثبت‌نام کاربران</b>"
            ."\nکاربران جدید · بازه اخیر: ".self::count($current['newUsers'])
            ."\nکاربران جدید · بازه قبل: ".self::count($previous['newUsers'])
            ."\nتغییر ثبت‌نام: ".self::percent($growth['newUsersPercent'])
            .self::simpleInsights($data, $channels)
            ."\n\n<blockquote>فروش تسویه‌شده است، نه سود خالص یا شارژ کیف پول."
            .' «بدون مبنا» یعنی بازه قبل صفر بوده است.'
            .' میانگین به نزدیک‌ترین تومان گرد شده است.</blockquote>'
            ."\n🕒 ".PersianCalendar::localDateTime($data['generatedAt']).' (تهران)';
    }

    /**
     * Phase 3: display only the canonical read model created in phase 2.
     * The denominator includes UNKNOWN historical sales, not just known channels.
     *
     * @param  array<string,mixed>  $report
     */
    private static function richChannels(array $report): string
    {
        return '<h3>🏷️ کانال‌های فروش</h3>'
            .self::comparisonTable(self::channelRows($report))
            .'<details><summary>🏆 تمرکز فروش</summary>'
            .self::comparisonTable(self::concentrationRows($report))
            .'</details>'
            .'<p>کانال نامشخص شامل سفارش‌های قدیمی فاقد دسته‌بندی است.</p>';
    }

    /** @param array<string,mixed> $report */
    private static function simpleChannels(array $report): string
    {
        return "\n\n<b>🏷️ کانال‌های فروش</b>"
            .self::simpleComparisons(self::channelRows($report))
            ."\n\n<b>🏆 تمرکز فروش</b>"
            .self::simpleComparisons(self::concentrationRows($report))
            ."\nکانال نامشخص شامل سفارش‌های قدیمی فاقد دسته‌بندی است.";
    }

    /**
     * @param  array<string,mixed>  $report
     * @return list<array{string,string,string,string}>
     */
    private static function channelRows(array $report): array
    {
        $current = $report['current']['channels'];
        $previous = $report['previous']['channels'];
        $currentRevenue = $report['management']['current']['revenueRials'];
        $previousRevenue = $report['management']['previous']['revenueRials'];
        $rows = [];

        foreach ([
            'direct' => ['فروش مستقیم', 'سهم فروش مستقیم'],
            'reseller' => ['فروش نمایندگی', 'سهم فروش نمایندگی'],
            'unknown' => ['کانال نامشخص', 'سهم نامشخص'],
        ] as $category => [$salesLabel, $shareLabel]) {
            $recent = $current[$category]['revenueRials'];
            $prior = $previous[$category]['revenueRials'];
            $recentShare = self::share($recent, $currentRevenue);
            $priorShare = self::share($prior, $previousRevenue);
            $rows[] = [
                $salesLabel, self::money($recent), self::money($prior), self::comparisonPercent($recent, $prior),
            ];
            $rows[] = [
                $shareLabel, self::shareValue($recentShare), self::shareValue($priorShare),
                self::sharePointChange($recentShare, $priorShare),
            ];
        }

        $recentResellers = $current['reseller']['uniqueBuyers'];
        $priorResellers = $previous['reseller']['uniqueBuyers'];
        $rows[] = [
            'نمایندگان خریدار', self::count($recentResellers), self::count($priorResellers),
            self::comparisonPercent($recentResellers, $priorResellers),
        ];

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return list<array{string,string,string,string}>
     */
    private static function concentrationRows(array $report): array
    {
        $current = $report['current'];
        $previous = $report['previous'];

        return [
            [
                'فروش حداکثر ۳ خریدار برتر',
                self::money($current['topThreeRevenueRials']),
                self::money($previous['topThreeRevenueRials']),
                self::comparisonPercent($current['topThreeRevenueRials'], $previous['topThreeRevenueRials']),
            ],
            [
                'سهم خریداران برتر از کل',
                self::shareValue($current['topThreeSharePercent']),
                self::shareValue($previous['topThreeSharePercent']),
                self::sharePointChange($current['topThreeSharePercent'], $previous['topThreeSharePercent']),
            ],
            [
                'تعداد خریداران برتر',
                self::count(count($current['topBuyers'])),
                self::count(count($previous['topBuyers'])),
                self::comparisonPercent(count($current['topBuyers']), count($previous['topBuyers'])),
            ],
        ];
    }

    /** The share denominator includes direct, reseller and unknown settlements. */
    private static function share(int $part, int $total): ?float
    {
        return $total === 0 ? null : $part / $total * 100;
    }

    private static function shareValue(?float $value): string
    {
        return $value === null
            ? 'بدون مبنا'
            : PersianCalendar::digits(str_replace('.', '٫', number_format($value, 1))).'٪';
    }

    /** Share movement is percentage POINTS, never relative percent growth. */
    private static function sharePointChange(?float $current, ?float $previous): string
    {
        if ($current === null || $previous === null) {
            return 'قابل محاسبه نیست';
        }

        $delta = round($current - $previous, 1);
        $sign = $delta > 0 ? '+' : ($delta < 0 ? '−' : '');

        return $sign.PersianCalendar::digits(str_replace('.', '٫', number_format(abs($delta), 1)))
            .' واحد درصد';
    }

    /**
     * Display-only comparisons from the same authorized snapshot. No extra reads.
     *
     * @param  array<string,mixed>  $data
     * @return list<array{string,string,string,string}>
     */
    private static function salesRows(array $data): array
    {
        $current = $data['current'];
        $previous = $data['previous'];
        $currentAverage = self::averageOrderRials($current);
        $previousAverage = self::averageOrderRials($previous);
        $rows = [
            [
                'فروش تسویه‌شده', self::money($current['revenueRials']),
                self::money($previous['revenueRials']), self::percent($data['growth']['salesPercent']),
            ],
            [
                'میانگین مبلغ سفارش', self::averageMoney($currentAverage),
                self::averageMoney($previousAverage), self::comparisonPercent($currentAverage, $previousAverage),
            ],
        ];

        foreach ([
            'transactions' => 'تعداد سفارش‌ها',
            'uniqueBuyers' => 'خریداران یکتا',
            'newPurchases' => 'خرید غیرتمدیدی',
            'renewals' => 'تمدید',
        ] as $key => $label) {
            $rows[] = [
                $label, self::count($current[$key]), self::count($previous[$key]),
                self::comparisonPercent($current[$key], $previous[$key]),
            ];
        }

        return $rows;
    }

    /** @param array{revenueRials:int,transactions:int} $totals */
    private static function averageOrderRials(array $totals): ?float
    {
        // Averages are display statistics, never monetary postings. Keep the
        // unrounded quotient for comparisons; round only the displayed tomans.
        return $totals['transactions'] === 0 ? null : $totals['revenueRials'] / $totals['transactions'];
    }

    private static function averageMoney(?float $rials): string
    {
        return $rials === null ? 'بدون سفارش'
            : PersianCalendar::digits(number_format($rials / 10, 0, '٫', '٬')).' تومان';
    }

    private static function comparisonPercent(?float $current, ?float $previous): string
    {
        if ($current === null || $previous === null) {
            return 'قابل محاسبه نیست';
        }

        return self::percent($previous === 0.0 ? null : round(($current - $previous) / $previous * 100, 1));
    }

    /** @param list<array{string,string,string,string}> $rows */
    private static function comparisonTable(array $rows): string
    {
        $html = '<table bordered striped compact>';
        foreach ($rows as [$label, $current, $previous, $change]) {
            $html .= '<tr><td>'.TelegramUi::escape($label).'</td><td>اخیر: <b>'
                .TelegramUi::escape($current).'</b><br>قبل: '.TelegramUi::escape($previous)
                .'<br>تغییر: '.TelegramUi::escape($change).'</td></tr>';
        }

        return $html.'</table>';
    }

    /** @param list<array{string,string,string,string}> $rows */
    private static function simpleComparisons(array $rows): string
    {
        $text = '';
        foreach ($rows as [$label, $current, $previous, $change]) {
            $text .= "\n\n<b>".TelegramUi::escape($label).'</b>'
                ."\nاخیر: ".TelegramUi::escape($current).' · قبل: '.TelegramUi::escape($previous)
                ."\nتغییر: ".TelegramUi::escape($change);
        }

        return $text;
    }

    /**
     * One rule-based analysis source for both Rich and native fallback.
     * Do not infer why revenue moved from these two KPIs alone.
     *
     * @param  array<string,mixed>  $data
     * @return array<string, string>
     */
    private static function insights(array $data, ?array $channels = null): array
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

        $items = [
            '💰 فروش' => $sales,
            '👥 کاربران' => $users,
            '📈 برداشت مدیریتی' => $takeaway,
        ];

        if ($channels !== null) {
            // All further observations reuse the same authorized snapshot; no SQL,
            // current-role inference, discount-based reclassification or AI claims.
            $recent = $channels['current'];
            $prior = $channels['previous'];
            $recentCategories = $recent['channels'];
            $priorCategories = $prior['channels'];
            $items['🧾 سفارش‌ها'] = 'سفارش‌های تسویه‌شده از '.self::count($previous['transactions'])
                .' به '.self::count($current['transactions']).' و خریداران یکتا از '
                .self::count($previous['uniqueBuyers']).' به '.self::count($current['uniqueBuyers'])
                .' رسیده‌اند. از سفارش‌های اخیر '.self::count($current['newPurchases'])
                .' خرید غیرتمدیدی و '.self::count($current['renewals'])
                .' تمدید بوده است. میانگین سفارش: '.self::averageMoney(self::averageOrderRials($current))
                .' (قبل: '.self::averageMoney(self::averageOrderRials($previous)).').';

            $items['🏷️ کانال‌ها'] = 'از فروش اخیر، مستقیم '
                .self::shareValue(self::share($recentCategories['direct']['revenueRials'], $current['revenueRials']))
                .' ('.self::money($recentCategories['direct']['revenueRials']).')، نمایندگی '
                .self::shareValue(self::share($recentCategories['reseller']['revenueRials'], $current['revenueRials']))
                .' ('.self::money($recentCategories['reseller']['revenueRials']).') و نامشخص '
                .self::shareValue(self::share($recentCategories['unknown']['revenueRials'], $current['revenueRials']))
                .' ('.self::money($recentCategories['unknown']['revenueRials']).') بوده است. نمایندگان خریدار: '
                .self::count($recentCategories['reseller']['uniqueBuyers'])
                .' (قبل: '.self::count($priorCategories['reseller']['uniqueBuyers']).').';

            $items['🏆 تمرکز فروش'] = 'حداکثر '.self::count(count($recent['topBuyers']))
                .' خریدار برتر '.self::money($recent['topThreeRevenueRials'])
                .'، معادل '.self::shareValue($recent['topThreeSharePercent'])
                .' از کل فروش داشته‌اند (سهم بازه قبل: '
                .self::shareValue($prior['topThreeSharePercent']).').';

            if ($current['transactions'] === 0) {
                $tip = 'در این دوره سفارش تسویه‌شده‌ای ثبت نشده است؛ وضعیت تسویه‌ها را بررسی کنید.';
            } elseif ($recentCategories['unknown']['revenueRials'] > 0) {
                $tip = 'برای مقایسه سهم کانال‌ها، فروش نامشخص سفارش‌های قدیمی را جداگانه در نظر بگیرید؛ کانال آن‌ها قابل استنتاج قطعی نیست.';
            } elseif ($recent['topThreeSharePercent'] !== null
                && $prior['topThreeSharePercent'] !== null
                && $recent['topThreeSharePercent'] > $prior['topThreeSharePercent']) {
                $tip = 'سهم سه خریدار برتر نسبت به بازه قبل افزایش یافته؛ تغییر تمرکز فروش را در دوره بعد هم بررسی کنید.';
            }
        }

        $items['💡 نکته قابل بررسی'] = $tip;

        return $items;
    }

    /** Use the exact same short takeaway and observations in Rich and native HTML. */
    private static function richInsights(array $data, ?array $channels = null): string
    {
        $insights = self::insights($data, $channels);
        $html = '<p><b>🧭 جمع‌بندی</b> '
            .TelegramUi::escape($insights['📈 برداشت مدیریتی']).'</p>'
            .'<details><summary>🧠 تحلیل عملکرد دوره</summary>';
        foreach ($insights as $title => $text) {
            if ($title === '📈 برداشت مدیریتی') {
                continue; // Already visible; do not repeat the same sentence.
            }
            $html .= '<p><b>'.TelegramUi::escape($title).'</b> '
                .TelegramUi::escape($text).'</p>';
        }

        return $html.'</details>';
    }

    /** @param array<string,mixed> $data */
    private static function simpleInsights(array $data, ?array $channels = null): string
    {
        $insights = self::insights($data, $channels);
        $html = "\n\n<b>🧭 جمع‌بندی</b> ".TelegramUi::escape($insights['📈 برداشت مدیریتی'])
            ."\n\n<b>🧠 تحلیل عملکرد دوره</b>";
        foreach ($insights as $title => $text) {
            if ($title === '📈 برداشت مدیریتی') {
                continue;
            }
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
