<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Carbon;

/**
 * Read-only channel data over the existing authorized KPI periods.
 * This Action does not change the Telegram dashboard in phase 2.
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
    }
}
