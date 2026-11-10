<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use App\Support\PersianCalendar;

/**
 * Read-only strategy evidence built strictly from existing root-authorized
 * reports. Periods are intentionally labelled, not normalized or blended.
 */
final readonly class ReadDenizDecisionBrief
{
    public function __construct(private ReadDenizExecutiveSnapshot $snapshots) {}

    /** @return array<string, mixed> */
    public function handle(int $actorId): array
    {
        // Each source independently verifies active trusted Super Admin access.
        // None performs Remnawave or any other Provider request.
        $sales = $this->snapshots->handle($actorId, 'sales', 'week');
        $accounting = $this->snapshots->handle($actorId, 'accounting', 'month');
        $wallet = $this->snapshots->handle($actorId, 'wallet', 'current');

        $current = $sales['windows']['current'];
        $previous = $sales['windows']['previous'];
        $channels = $sales['channels']['current'];
        $book = $accounting['metrics'];
        $balances = $wallet['metrics'];

        $percent = static fn (int $part, int $total): ?float => $total > 0
            ? round(100 * $part / $total, 1) : null;

        $expenseShare = $percent($book['expensesRials'], $book['salesRials']);
        $resellerCreditShare = $percent($balances['resellerBalanceRials'], $balances['balanceRials']);
        $orderGrowth = $previous['transactions'] > 0
            ? round(100 * ($current['transactions'] - $previous['transactions']) / $previous['transactions'], 1)
            : null;

        // Candidate observations are decision prompts, not automated verdicts.
        // Thresholds are screening heuristics and must not imply causal proof.
        $signals = [];
        if ($expenseShare !== null && $expenseShare < 5 && $book['salesRials'] >= 1_000_000) {
            $signals[] = [
                'type' => 'cost_recording',
                'evidence' => 'سهم هزینه‌های ثبت‌شده '.$expenseShare.'٪ از فروش ماه جاری است.',
                'hypothesis' => 'ثبت هزینه‌های عملیاتی ممکن است ناقص باشد؛ سود قطعی قابل نتیجه‌گیری نیست.',
                'nextCheck' => 'ثبت هزینه سرور، زیرساخت و کارمزدها را با اسناد واقعی تطبیق دهید.',
                'successMetric' => 'تکمیل ثبت هزینه‌ها و نسبت هزینه ثبت‌شده به فروش',
            ];
        }

        $revenueGrowth = $sales['growth']['salesPercent'];
        if ($revenueGrowth !== null && $revenueGrowth <= -15 && $previous['transactions'] >= 3) {
            $signals[] = [
                'type' => 'sales_decline',
                'evidence' => 'فروش تسویه‌شده در دو بازه هم‌طول '.$revenueGrowth.'٪ تغییر کرده است.',
                'hypothesis' => 'افت فروش نیاز به تفکیک تغییر تعداد سفارش و میانگین مبلغ سفارش دارد.',
                'nextCheck' => 'تعداد سفارش، خریداران یکتا، تمدید و کانال‌های مستقیم/نمایندگی را کنار هم بررسی کنید.',
                'successMetric' => 'فروش و تعداد سفارش در ۷ روز کامل بعدی',
            ];
        }
        $concentration = $sales['concentration']['current']['topThreeSharePercent'];
        if ($concentration !== null && $concentration >= 65 && $current['transactions'] >= 5) {
            $signals[] = [
                'type' => 'buyer_concentration',
                'evidence' => 'سهم سه خریدار برتر از فروش دوره '.$concentration.'٪ است.',
                'hypothesis' => 'وابستگی فروش به تعداد محدودی خریدار ارزش پایش دارد؛ این به‌تنهایی نشانه ریزش نیست.',
                'nextCheck' => 'روند خریداران یکتا و سهم سه خریدار برتر را در دوره بعد بسنجید.',
                'successMetric' => 'سهم سه خریدار برتر و تعداد خریداران یکتا',
            ];
        }
        if ($resellerCreditShare !== null && $resellerCreditShare >= 70 && $balances['balanceRials'] > 0) {
            $signals[] = [
                'type' => 'reseller_credit',
                'evidence' => $resellerCreditShare.'٪ از مانده فعلی کیف پول‌ها نزد نمایندگان است.',
                'hypothesis' => 'تمرکز تعهد اعتباری، لزوماً اعتبار راکد یا فروش آینده نیست.',
                'nextCheck' => 'روند سفارش نمایندگی را جداگانه بررسی کنید؛ مانده لحظه‌ای را با فروش تاریخی جمع نزنید.',
                'successMetric' => 'فروش و تعداد سفارش نمایندگی در دوره بعد',
            ];
        }

        return [
            'currency' => 'IRR',
            'timeFa' => PersianCalendar::localDateTime($sales['generatedAt']),
            'periods' => [
                'sales' => '۷ روز کامل اخیر در برابر ۷ روز کامل قبل؛ شامل امروز نیست',
                'accounting' => PersianCalendar::date($accounting['window']['start']).' تا '.PersianCalendar::date($accounting['window']['endInclusive']).' (ماه جاری)',
                'wallet' => 'مانده لحظه‌ای؛ نه مانده پایان دوره فروش',
            ],
            'sales' => [
                'revenueRials' => $current['revenueRials'],
                'previousRevenueRials' => $previous['revenueRials'],
                'orders' => $current['transactions'],
                'previousOrders' => $previous['transactions'],
                'buyers' => $current['uniqueBuyers'],
                'renewals' => $current['renewals'],
                'salesGrowthPercent' => $revenueGrowth,
                'orderGrowthPercent' => $orderGrowth,
                'directRials' => $channels['direct']['revenueRials'],
                'resellerRials' => $channels['reseller']['revenueRials'],
                'unknownRials' => $channels['unknown']['revenueRials'],
                'topThreeSharePercent' => $concentration,
            ],
            'accounting' => [
                'salesRials' => $book['salesRials'],
                'expensesRials' => $book['expensesRials'],
                'expenseEntries' => $book['expenseEntries'],
                'recordedExpenseSharePercent' => $expenseShare,
            ],
            'wallet' => [
                'balanceRials' => $balances['balanceRials'],
                'resellerSharePercent' => $resellerCreditShare,
            ],
            'candidates' => array_slice($signals, 0, 3),
            'limits' => 'بازه گزارش‌ها متفاوت است؛ جمع، نسبت یا علت مشترک نساز. سود خالص، گردش بانکی، هزینه جذب، ROI و نرخ ریزش از این داده ثابت نمی‌شود. پرچم‌ها فرضیه بررسی‌اند.',
        ];
    }
}
