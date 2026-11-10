<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Support\PersianCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only management KPIs. No alternative settlement definition, cache,
 * financial journal, provider calls, or UI; the independent Rich UI is phase 4.
 */
final readonly class ReadAdminManagementKpis
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminBusinessReport $business,
    ) {}

    /**
     * @return array{
     *   period:string,timezone:string,generatedAt:string,
     *   current:array{from:string,until:string,revenueRials:int,transactions:int,uniqueBuyers:int,newPurchases:int,renewals:int,newUsers:int},
     *   previous:array{from:string,until:string,revenueRials:int,transactions:int,uniqueBuyers:int,newPurchases:int,renewals:int,newUsers:int},
     *   growth:array{salesPercent:?float,newUsersPercent:?float}
     * }
     */
    public function handle(int $actorId, string $period): array
    {
        $this->access->handle($actorId);
        if (! in_array($period, ['day', 'week', 'month', '24h'], true)) {
            throw ValidationException::withMessages(['period' => 'Unsupported KPI period.']);
        }

        $now = Carbon::now('Asia/Tehran');
        $today = $now->copy()->startOfDay();

        if ($period === '24h') {
            // A rolling 24-hour interval, not the Tehran day-to-now KPI.
            // Half-open boundaries exclude settlements at the exact request instant.
            $currentFrom = $now->copy()->subHours(24);
            $currentUntil = $now;
            $previousFrom = $now->copy()->subHours(48);
            $previousUntil = $currentFrom->copy();
        } elseif ($period === 'day') {
            // Compare the same local clock interval, not today vs all of yesterday.
            $currentFrom = $today;
            $currentUntil = $now;
            $previousFrom = $today->copy()->subDay();
            $previousUntil = $now->copy()->subDay();
        } elseif ($period === 'week') {
            // Two adjacent sets of seven complete Tehran calendar days.
            $currentFrom = $today->copy()->subDays(7);
            $currentUntil = $today;
            $previousFrom = $today->copy()->subDays(14);
            $previousUntil = $currentFrom->copy();
        } else {
            // Persian civil months may have 29, 30 or 31 days: never use
            // subMonth() or a rolling 30-day approximation.
            $thisMonth = $today->copy()->subDays(PersianCalendar::dayOfMonth($today) - 1);
            $lastMonthEnd = $thisMonth->copy()->subDay();
            $lastMonth = $lastMonthEnd->copy()->subDays(
                PersianCalendar::dayOfMonth($lastMonthEnd) - 1,
            );
            $priorMonthEnd = $lastMonth->copy()->subDay();
            $priorMonth = $priorMonthEnd->copy()->subDays(
                PersianCalendar::dayOfMonth($priorMonthEnd) - 1,
            );

            $currentFrom = $lastMonth;
            $currentUntil = $thisMonth;
            $previousFrom = $priorMonth;
            $previousUntil = $lastMonth->copy();
        }

        $current = $this->totals($actorId, $currentFrom, $currentUntil);
        $previous = $this->totals($actorId, $previousFrom, $previousUntil);

        return [
            'period' => $period,
            'timezone' => 'Asia/Tehran',
            'generatedAt' => $now->format('Y-m-d H:i'),
            'current' => $current,
            'previous' => $previous,
            'growth' => [
                'salesPercent' => self::growth($current['revenueRials'], $previous['revenueRials']),
                'newUsersPercent' => self::growth($current['newUsers'], $previous['newUsers']),
            ],
        ];
    }

    /**
     * Half-open local periods, converted to UTC exclusively at the SQL edge.
     *
     * @return array{from:string,until:string,revenueRials:int,transactions:int,uniqueBuyers:int,newPurchases:int,renewals:int,newUsers:int}
     */
    private function totals(int $actorId, Carbon $from, Carbon $until): array
    {
        // At exactly midnight, today's elapsed window is empty. No positive
        // denominator exists; return a genuine zero rather than invalid SQL.
        if ($from->equalTo($until)) {
            $sales = ['revenueRials' => 0, 'settled' => 0, 'renewals' => 0, 'buyers' => 0];
            $newUsers = 0;
        } else {
            $startUtc = $from->copy()->utc();
            $endUtc = $until->copy()->utc();
            $sales = $this->business->settledTotalsBetween($actorId, $startUtc, $endUtc);
            $newUsers = (int) DB::table('users')
                ->where('created_at', '>=', $startUtc)
                ->where('created_at', '<', $endUtc)
                ->count();
        }

        return [
            'from' => $from->format('Y-m-d H:i:s'),
            'until' => $until->format('Y-m-d H:i:s'),
            'revenueRials' => $sales['revenueRials'],
            'transactions' => $sales['settled'],
            'uniqueBuyers' => $sales['buyers'],
            'newPurchases' => $sales['settled'] - $sales['renewals'],
            'renewals' => $sales['renewals'],
            'newUsers' => $newUsers,
        ];
    }

    private static function growth(int $current, int $previous): ?float
    {
        // Zero baselines have no mathematically defined percentage change.
        return $previous === 0 ? null : round(($current - $previous) / $previous * 100, 1);
    }
}
