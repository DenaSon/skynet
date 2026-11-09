<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Sales\Application\ReadAdminSalesChannelKpis;
use Illuminate\Validation\ValidationException;

/**
 * AI-EXEC-01: authorized, read-only, bounded financial evidence for Deniz.
 *
 * No AI call, Telegram delivery, persistence, free-form SQL or Provider access.
 * Each dataset keeps its own canonical reporting window: do not compare cash,
 * accounting and KPI figures unless their actual date bounds match.
 */
final readonly class ReadDenizExecutiveSnapshot
{
    private const PERIODS = [
        'sales' => ['day', 'week', 'month'],
        'cash' => ['7', '30', 'all'],
        'accounting' => ['7', '30', 'month'],
        'wallet' => ['current'],
    ];

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminSalesChannelKpis $sales,
        private ReadAdminCashReceipts $cash,
        private ReadAdminAccountingReport $accounting,
        private ReadAdminWalletSummary $wallets,
    ) {}

    /** @return array<string, mixed> */
    public function handle(int $actorId, string $dataset, string $period): array
    {
        // The existing trusted-root policy is stricter than delegated finance.view.
        // Recheck on every invocation; a queued request never grants authority.
        $this->access->handle($actorId);

        if (! isset(self::PERIODS[$dataset])
            || ! in_array($period, self::PERIODS[$dataset], true)) {
            throw ValidationException::withMessages(['period' => 'Unsupported executive financial dataset or period.']);
        }

        return match ($dataset) {
            'sales' => $this->salesSnapshot($actorId, $period),
            'cash' => $this->cashSnapshot($actorId, $period),
            'accounting' => $this->accountingSnapshot($actorId, $period),
            'wallet' => $this->walletSnapshot($actorId),
        };
    }

    /** @return array<string, mixed> */
    private function salesSnapshot(int $actorId, string $period): array
    {
        // KPI totals and channel shares come from the SAME MySQL read snapshot.
        $report = $this->sales->handle($actorId, $period);
        $management = $report['management'];

        $concentration = static fn (array $window): array => [
            'topThreeRevenueRials' => $window['topThreeRevenueRials'],
            'topThreeSharePercent' => $window['topThreeSharePercent'],
            'topBuyersCount' => count($window['topBuyers']),
        ];

        return [
            ...self::metadata('sales', $period, 'ReadAdminSalesChannelKpis', $management['generatedAt']),
            'windows' => [
                'current' => $management['current'],
                'previous' => $management['previous'],
            ],
            'growth' => $management['growth'],
            'channels' => [
                'current' => $report['current']['channels'],
                'previous' => $report['previous']['channels'],
            ],
            // Never export top-buyers' IDs, names or individual order details to AI.
            'concentration' => [
                'current' => $concentration($report['current']),
                'previous' => $concentration($report['previous']),
            ],
            'limitations' => [
                'Gross settled purchases are not net profit, bank cash or wallet top-ups.',
                'Verified bank settlements remain in gross history even after a later refund; refunded wallet orders are excluded.',
                'Unknown historical sales channels must not be reassigned.',
                'Day compares elapsed Tehran time; week compares complete seven-day windows; month compares complete Persian months.',
                'Undefined growth or share is null, not zero percent.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function cashSnapshot(int $actorId, string $period): array
    {
        $report = $this->cash->handle($actorId, $period);

        return [
            ...self::metadata('cash', $period, 'ReadAdminCashReceipts', $report['generatedAt']),
            'window' => [
                'start' => $report['start'],
                'endInclusive' => $report['end'],
            ],
            'metrics' => [
                'topupsRials' => $report['topupsRials'],
                'topupsCount' => $report['topupsCount'],
                'customerTopupsRials' => $report['customerTopupsRials'],
                'customerTopupsCount' => $report['customerTopupsCount'],
                'adminTopupsRials' => $report['adminTopupsRials'],
                'adminTopupsCount' => $report['adminTopupsCount'],
                'bankSalesRials' => $report['bankSalesRials'],
                'bankSalesCount' => $report['bankSalesCount'],
                'cashInRials' => $report['cashInRials'],
                'walletSalesRials' => $report['walletSalesRials'],
                'walletSalesCount' => $report['walletSalesCount'],
                'salesRials' => $report['salesRials'],
            ],
            'pointInTime' => ['walletBalanceRials' => $report['currentWalletBalanceRials']],
            'limitations' => [
                'Only verified recorded receipts, not an external live bank statement.',
                'Cash inflow includes verified top-ups and direct bank sales; wallet-funded sales are not new cash.',
                'Top-up corrections are included; pending or rejected top-ups are excluded.',
                'The wallet balance is current, not the balance at the end of the report window.',
                'A null start means all recorded history; this is not comparable to a fixed-period KPI.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function accountingSnapshot(int $actorId, string $period): array
    {
        $report = $this->accounting->handle($actorId, $period);

        return [
            ...self::metadata('accounting', $period, 'ReadAdminAccountingReport', $report['generatedAt']),
            'window' => [
                'start' => $report['start'],
                'endInclusive' => $report['end'],
            ],
            'metrics' => [
                'salesRials' => $report['salesRials'],
                'expensesRials' => $report['expensesRials'],
                'drawsRials' => $report['drawsRials'],
                'profitRials' => $report['profitRials'],
                'afterDrawsRials' => $report['afterDrawsRials'],
                'expenseEntries' => $report['expenseEntries'],
                'drawEntries' => $report['drawEntries'],
            ],
            'limitations' => [
                'Profit is indicative: only recorded expenses and referral costs are included, not proven net profit.',
                'Owner draws are not expenses; afterDrawsRials is not the bank or cash balance.',
                'Reversals affect the period in which they were recorded.',
                'Accounting month is the current Persian month to date; KPI sales month is the previous complete Persian month.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function walletSnapshot(int $actorId): array
    {
        $report = $this->wallets->handle($actorId);

        return [
            ...self::metadata('wallet', 'current', 'ReadAdminWalletSummary', now('Asia/Tehran')->format('Y-m-d H:i')),
            'metrics' => $report,
            'limitations' => [
                'Ledger-derived balances are a point-in-time credit liability, not revenue or available bank cash.',
                'Reseller/member categories represent current roles, not historical order attribution.',
                'Pending top-ups are not verified receipts.',
            ],
        ];
    }

    /** @return array{schemaVersion:int,dataset:string,period:string,timezone:string,currency:string,source:string,generatedAt:string} */
    private static function metadata(string $dataset, string $period, string $source, string $generatedAt): array
    {
        return [
            'schemaVersion' => 1,
            'dataset' => $dataset,
            'period' => $period,
            'timezone' => 'Asia/Tehran',
            'currency' => 'IRR',
            'source' => $source,
            'generatedAt' => $generatedAt,
        ];
    }
}
