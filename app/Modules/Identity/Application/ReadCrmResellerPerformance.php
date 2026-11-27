<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Billing\Application\ReadWalletDailyAverage;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Fixed 30-day, read-only score; never changes reseller entitlements. */
final readonly class ReadCrmResellerPerformance
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private AssertBillingAccess $billingAccess,
        private ReadCrmDirectory $directory,
        private ReadAdminBusinessReport $business,
        private ReadWalletDailyAverage $wallets,
    ) {}

    /** @return array<string, mixed> */
    public function handle(int $actorId): array
    {
        $this->access->handle($actorId);
        $this->billingAccess->financeViewer($actorId);
        $end = Carbon::now('Asia/Tehran')->startOfDay();
        $start = $end->copy()->subDays(30);

        return DB::transaction(function () use ($actorId, $end, $start): array {
            $cohort = $this->directory->commercialAccounts($actorId, true);
            $accounts = $cohort->get();
            $events = DB::table('identity_audit_events as audit')
                ->joinSub($this->directory->commercialAccounts($actorId, true), 'accounts', 'accounts.id', '=', 'audit.subject_id')
                ->where('audit.subject_type', 'user')
                ->whereIn('audit.event', ['identity.user.created', 'identity.roles.assigned'])
                ->select('audit.subject_id', 'audit.event', 'audit.details', 'audit.created_at')
                ->orderBy('audit.created_at')->orderBy('audit.id')->get();
            $starts = [];
            foreach ($events as $event) {
                $id = (int) $event->subject_id;
                $details = json_decode((string) $event->details, true);
                if (! is_array($details)) {
                    $starts[$id] = null;

                    continue;
                }
                if ($event->event === 'identity.user.created') {
                    $starts[$id] = ($details['role'] ?? null) === 'reseller' ? (string) $event->created_at : null;

                    continue;
                }
                $before = $details['before'] ?? null;
                $after = $details['after'] ?? null;
                if (! is_array($before) || ! is_array($after) || ! $this->commercial($after)) {
                    $starts[$id] = null;
                } elseif (! $this->commercial($before)) {
                    $starts[$id] = (string) $event->created_at;
                }
            }

            $rows = [];
            $eligible = [];
            foreach ($accounts as $account) {
                $id = (int) $account->id;
                $since = $starts[$id] ?? null;
                $state = match (true) {
                    ! $account->is_active => 'inactive',
                    $since === null => 'unknown',
                    Carbon::parse($since, 'UTC')->greaterThan($start) => 'evaluating',
                    default => 'ranked',
                };
                $rows[$id] = ['state' => $state, 'since' => $since, 'rank' => null, 'score' => null, 'tier' => null, 'previousRank' => null];
                if ($state === 'ranked') {
                    $eligible[$id] = $since;
                }
            }

            $current = $this->scores($actorId, $eligible, $end);
            // Compare only the identical current cohort, with an entire prior
            // evaluation window for every member; never claim persisted ranks.
            $previous = [];
            if ($eligible !== [] && count(array_filter($eligible, static fn (string $since): bool => Carbon::parse($since, 'UTC')->lessThanOrEqualTo($start->copy()->subDays(30)))) === count($eligible)) {
                $previous = $this->scores($actorId, $eligible, $start);
            }
            foreach ($current as $id => $score) {
                $rows[$id] = [...$rows[$id], ...$score, 'previousRank' => $previous[$id]['rank'] ?? null];
            }

            return [
                'start' => $start->format('Y-m-d'),
                'end' => $end->copy()->subDay()->format('Y-m-d'),
                'eligibleCount' => count($eligible),
                'rows' => $rows,
            ];
        });
    }

    /** @param  array<mixed> $roles */
    private function commercial(array $roles): bool
    {
        return in_array('reseller', $roles, true)
            && ! in_array('admin', $roles, true) && ! in_array('super-admin', $roles, true);
    }

    /**
     * @param  array<int, string>  $eligible
     * @return array<int, array<string, mixed>>
     */
    private function scores(int $actorId, array $eligible, Carbon $end): array
    {
        if ($eligible === []) {
            return [];
        }

        $start = $end->copy()->subDays(30);
        $prior = $start->copy()->subDays(30);
        $ids = array_keys($eligible);
        $sales = DB::query()->fromSub($this->business->crmSettledRows($actorId, $prior->copy()->utc(), $end->copy()->utc()), 'settled')
            ->where('buyer_category', 'reseller')->whereIntegerInRaw('buyer_id', $ids)
            ->groupBy('buyer_id')->select('buyer_id')
            ->selectRaw('SUM(CASE WHEN settled_at >= ? THEN amount_rials ELSE 0 END) as revenue,
                SUM(CASE WHEN settled_at < ? THEN amount_rials ELSE 0 END) as prior_revenue,
                COUNT(DISTINCT CASE WHEN settled_at >= ? THEN order_id END) as purchases,
                MAX(CASE WHEN settled_at >= ? THEN settled_at END) as last_purchase',
                array_fill(0, 4, $start->copy()->utc()));
        // At most thirty conditional day aggregates in one query, using exact
        // Tehran boundaries instead of depending on MySQL timezone tables.
        $dayParts = [];
        $bindings = [];
        for ($day = 0; $day < 30; $day++) {
            $dayParts[] = 'MAX(CASE WHEN settled_at >= ? AND settled_at < ? THEN 1 ELSE 0 END)';
            $bindings[] = $start->copy()->addDays($day)->utc();
            $bindings[] = $start->copy()->addDays($day + 1)->utc();
        }
        $sales = $sales->selectRaw(implode(' + ', $dayParts).' as purchase_days', $bindings)->get()->keyBy('buyer_id');
        $wallets = $this->wallets->handle($actorId, $ids, $end);
        $maxRevenue = 0;
        $maxPurchases = 0;
        $maxWallet = 0.0;
        foreach ($ids as $id) {
            $maxRevenue = max($maxRevenue, (int) ($sales->get($id)->revenue ?? 0));
            $maxPurchases = max($maxPurchases, (int) ($sales->get($id)->purchases ?? 0));
            $maxWallet = max($maxWallet, $wallets[$id]['averageRials'] ?? 0);
        }

        $result = [];
        foreach ($eligible as $id => $since) {
            $sale = $sales->get($id);
            $revenue = (int) ($sale->revenue ?? 0);
            $priorRevenue = (int) ($sale->prior_revenue ?? 0);
            $purchases = (int) ($sale->purchases ?? 0);
            $days = (int) ($sale->purchase_days ?? 0);
            $last = $sale->last_purchase ?? null;
            $average = $wallets[$id]['averageRials'] ?? null;
            $growth = $priorRevenue > 0 && Carbon::parse($since, 'UTC')->lessThanOrEqualTo($prior)
                ? ($revenue - $priorRevenue) / $priorRevenue * 100 : null;
            $age = $last === null ? 30 : (int) Carbon::parse((string) $last, 'UTC')->timezone('Asia/Tehran')->startOfDay()->diffInDays($end->copy()->subDay());
            $points = [
                'revenue' => $this->normalized($revenue, $maxRevenue) * 35,
                'purchases' => $this->normalized($purchases, $maxPurchases) * 20,
                'consistency' => $days / 30 * 15,
                'growth' => ($growth === null ? 0.5 : max(0, min(1, ($growth + 100) / 200))) * 10,
                'recency' => max(0, 1 - $age / 30) * 10,
                'wallet' => $this->normalized($average ?? 0, $maxWallet) * 10,
            ];
            if ($purchases === 0) {
                $points = array_fill_keys(array_keys($points), 0.0);
            }
            $score = round(array_sum($points), 2);
            $result[$id] = [
                'score' => $score,
                'tier' => match (true) {
                    $score >= 90 => 'diamond',
                    $score >= 75 => 'gold',
                    $score >= 50 => 'silver',
                    $score >= 25 => 'bronze',
                    default => 'ordinary',
                },
                'revenueRials' => $revenue,
                'purchases' => $purchases,
                'purchaseDays' => $days,
                'growthPercent' => $growth,
                'lastPurchaseAt' => $last,
                'averageWalletRials' => $average,
                'points' => $points,
            ];
        }

        uasort($result, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $rank = 0;
        $lastScore = null;
        foreach ($result as &$row) {
            if ($row['score'] !== $lastScore) {
                $rank++;
                $lastScore = $row['score'];
            }
            $row['rank'] = $rank;
        }
        unset($row);

        return $result;
    }

    private function normalized(int|float $value, int|float $max): float
    {
        return $max > 0 ? max(0, $value) / $max : 0.0;
    }
}
