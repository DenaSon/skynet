<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Modules\Sales\Application\ReadAdminSalesChannelKpis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        'sales' => ['day', 'week', 'month', '24h'],
        'cash' => ['day', '7', '30', 'all'],
        'accounting' => ['7', '30', 'month'],
        'wallet' => ['current'],
        'users' => ['current'],
    ];

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminSalesChannelKpis $sales,
        private ReadAdminCashReceipts $cash,
        private ReadAdminAccountingReport $accounting,
        private ReadAdminWalletSummary $wallets,
        private ReadAdminBusinessReport $business,
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
            'cash' => $period === 'day'
                ? $this->dailyCashSnapshot($actorId) : $this->cashSnapshot($actorId, $period),
            'accounting' => $this->accountingSnapshot($actorId, $period),
            'wallet' => $this->walletSnapshot($actorId),
            'users' => $this->usersSnapshot($actorId),
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
                '24h compares the most recent rolling 24 hours with the preceding 24 hours; day compares elapsed Tehran time; week compares complete seven-day windows; month compares complete Persian months.',
                'Undefined growth or share is null, not zero percent.',
            ],
        ];
    }

    /**
     * Compare verified incoming bank receipts, not gross sales, over equal
     * elapsed Tehran-day windows. Finance owns all receipt/correction logic.
     *
     * @return array<string, mixed>
     */
    private function dailyCashSnapshot(int $actorId): array
    {
        $asOf = Carbon::now('Asia/Tehran');
        $today = $this->cash->handle($actorId, 'today', $asOf);
        $yesterday = $this->cash->handle($actorId, 'yesterday_same_time', $asOf);

        $window = static fn (array $report): array => [
            'date' => $report['start'],
            'cashInRials' => $report['cashInRials'],
            'topupsRials' => $report['topupsRials'],
            'topupsCount' => $report['topupsCount'],
            'bankSalesRials' => $report['bankSalesRials'],
            'bankSalesCount' => $report['bankSalesCount'],
        ];

        return [
            ...self::metadata('cash', 'day', 'ReadAdminCashReceipts', $today['generatedAt']),
            'windows' => [
                'current' => $window($today),
                'previous' => $window($yesterday),
            ],
            'sameTehranTime' => $asOf->format('H:i'),
            'growthPercent' => $yesterday['cashInRials'] > 0
                ? round(($today['cashInRials'] - $yesterday['cashInRials']) / $yesterday['cashInRials'] * 100, 1)
                : null,
            'limitations' => [
                'Both cash receipt windows are Tehran midnight to the same local clock time, not two complete days.',
                'Verified top-ups and direct bank sales are cash; wallet-funded purchases are not new cash.',
                'No verified cash in the previous window means undefined growth, never zero percent.',
                'Not a live bank statement or net profit; late receipt approvals can change previous figures.',
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

    /** @return array<string, mixed> */
    private function usersSnapshot(int $actorId): array
    {
        // Only existing local Identity/Sales data. ReadAdminDashboard is NOT used
        // because it additionally performs Remnawave network requests.
        $week = $this->business->handle($actorId, 7);
        $month = $this->business->handle($actorId, 30);

        return [
            ...self::metadata('users', 'current', 'LocalIdentityAndReadAdminBusinessReport', now('Asia/Tehran')->format('Y-m-d H:i')),
            'windows' => [
                'last7Days' => ['start' => $week['start'], 'endInclusive' => $week['end']],
                'last30Days' => ['start' => $month['start'], 'endInclusive' => $month['end']],
            ],
            'metrics' => [
                'registeredUsers' => (int) DB::table('users')->count(),
                'activeAccounts' => (int) DB::table('users')->where('is_active', true)->count(),
                'linkedTelegramAccounts' => $week['linkedTelegram'],
                'newUsers7Days' => $week['newUsers'],
                'newUsers30Days' => $month['newUsers'],
                'uniqueBuyers7Days' => $week['buyers'],
                'uniqueBuyers30Days' => $month['buyers'],
                'renewals7Days' => $week['renewals'],
                'renewals30Days' => $month['renewals'],
            ],
            'limitations' => [
                'Only local registered user accounts and settled-purchase aggregates.',
                'An active local account is not an active Remnawave subscription or an online user.',
                'Buyer counts over 7/30 days are not cohort conversion rates or retention percentages.',
                'No Remnawave, provider, network or external user-count requests were performed.',
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
