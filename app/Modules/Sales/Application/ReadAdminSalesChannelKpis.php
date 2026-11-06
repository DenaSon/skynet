<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only channel data over the existing authorized KPI periods.
 * All report reads share one short transaction (MySQL REPEATABLE READ).
 */
final readonly class ReadAdminSalesChannelKpis
{
    public function __construct(
        private ReadAdminManagementKpis $management,
        private ReadAdminBusinessReport $business,
    ) {}

    /** @return array{management:array<string,mixed>,current:array<string,mixed>,previous:array<string,mixed>} */
    public function handle(int $actorId, string $period): array
    {
        // Keep totals, channels and concentration on the same database snapshot.
        // No row locks or external calls belong inside this read transaction.
        return DB::transaction(function () use ($actorId, $period): array {
            // Existing Action enforces a freshly trusted root and the allowed period.
            $snapshot = $this->management->handle($actorId, $period);
            $result = [];
            foreach (['current', 'previous'] as $window) {
                $from = Carbon::parse($snapshot[$window]['from'], $snapshot['timezone'])->utc();
                $until = Carbon::parse($snapshot[$window]['until'], $snapshot['timezone'])->utc();

                $result[$window] = $from->equalTo($until)
                    ? [
                        'channels' => [
                            'direct' => ['revenueRials' => 0, 'transactions' => 0, 'uniqueBuyers' => 0],
                            'reseller' => ['revenueRials' => 0, 'transactions' => 0, 'uniqueBuyers' => 0],
                            'unknown' => ['revenueRials' => 0, 'transactions' => 0, 'uniqueBuyers' => 0],
                        ],
                        'topBuyers' => [], 'topThreeRevenueRials' => 0, 'topThreeSharePercent' => null,
                    ]
                    : $this->business->settledChannelBreakdownBetween($actorId, $from, $until);
            }

            return ['management' => $snapshot, 'current' => $result['current'], 'previous' => $result['previous']];
        });
    }
}
