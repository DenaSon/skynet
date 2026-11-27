<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bounded, root-only CRM analytics over canonical recorded data. */
final readonly class ReadCrmAnalytics
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadCrmDirectory $directory,
        private ReadAdminBusinessReport $business,
        private ReadAdminCashReceipts $cash,
        private AssertBillingAccess $billingAccess,
    ) {}

    /** @param  array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function handle(int $actorId, array $filters): array
    {
        $this->access->handle($actorId);
        $this->billingAccess->financeViewer($actorId);
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $filters = Validator::make($filters, [
            'period' => ['required', Rule::in(['today', '7', '30', '90', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:'.$today->format('Y-m-d')],
            'until' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today->format('Y-m-d')],
            'channel' => ['required', Rule::in(['all', 'direct', 'reseller', 'unknown'])],
            'reseller' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $resellerId = isset($filters['reseller']) ? (int) $filters['reseller'] : null;
        $channel = $filters['channel'];
        if ($resellerId !== null && (! in_array($channel, ['all', 'reseller'], true)
            || $this->directory->profileAccount($actorId, $resellerId, true) === null)) {
            throw ValidationException::withMessages(['reseller' => 'Invalid reseller filter.']);
        }

        $start = match ($filters['period']) {
            'today' => $today->copy(),
            '7', '30', '90' => $today->copy()->subDays((int) $filters['period'] - 1),
            'custom' => Carbon::parse($filters['from'], 'Asia/Tehran')->startOfDay(),
            default => Carbon::parse('1970-01-01', 'Asia/Tehran'),
        };
        $civilEnd = $filters['period'] === 'custom'
            ? Carbon::parse($filters['until'], 'Asia/Tehran')->startOfDay()->addDay()
            : $today->copy()->addDay();
        $end = $civilEnd->copy()->min(Carbon::now('Asia/Tehran')->addSecond());
        $days = (int) $start->diffInDays($civilEnd);

        return DB::transaction(function () use ($actorId, $filters, $resellerId, $channel, $start, $end, $days): array {
            $from = $start->copy()->utc();
            $until = $end->copy()->utc();
            $sales = DB::query()->fromSub($this->business->crmSettledRows($actorId, $from, $until), 'settled');
            $direct = $this->directory->commercialAccounts($actorId, false)->select('users.created_at')->selectRaw("'direct' as cohort");
            $resellers = $this->directory->commercialAccounts($actorId, true)->select('users.created_at')->selectRaw("'reseller' as cohort");
            $accounts = DB::query()->fromSub($direct->unionAll($resellers), 'commercial')
                ->where('created_at', '>=', $from)->where('created_at', '<', $until);

            $cashRows = null;
            try {
                $cashRows = DB::query()->fromSub($this->cash->incomingRowsBetween($actorId, $from, $until), 'cash');
            } catch (AuthorizationException|QueryException) {
                Log::warning('CRM analytics cash source unavailable');
            }

            // The all-time chart begins at the first recorded event, not at an
            // artificial epoch; its totals still include the entire report window.
            if ($filters['period'] === 'all') {
                $first = [(clone $sales)->min('settled_at'), (clone $accounts)->min('created_at')];
                if ($cashRows !== null) {
                    try {
                        $first[] = (clone $cashRows)->min('occurred_at');
                    } catch (QueryException) {
                        $cashRows = null;
                        Log::warning('CRM analytics cash source unavailable');
                    }
                }
                $first = array_values(array_filter($first, 'is_string'));
                $start = $first === []
                    ? $end->copy()->subSecond()->startOfDay()
                    : Carbon::parse(min($first), 'UTC')->timezone('Asia/Tehran')->startOfDay()->max($start);
            }

            $buckets = $this->buckets($start, $end);
            [$salesCase, $salesBindings] = $this->bucketCase('settled_at', $buckets);
            [$accountCase, $accountBindings] = $this->bucketCase('created_at', $buckets);
            $globalTrend = (clone $sales)->selectRaw("$salesCase as bucket, COALESCE(SUM(amount_rials), 0) as revenue", $salesBindings)
                ->groupBy('bucket')->get()->keyBy('bucket');
            $registrations = (clone $accounts)->selectRaw("$accountCase as bucket,
                COUNT(CASE WHEN cohort = 'direct' THEN 1 END) as direct_count,
                COUNT(CASE WHEN cohort = 'reseller' THEN 1 END) as reseller_count", $accountBindings)
                ->groupBy('bucket')->get()->keyBy('bucket');

            $selected = $this->filterPurchases(clone $sales, $channel, $resellerId);
            $selectedTrend = (clone $selected)->selectRaw("$salesCase as bucket,
                COALESCE(SUM(amount_rials), 0) as revenue,
                COUNT(DISTINCT buyer_id) as buyers", $salesBindings)->groupBy('bucket')->get()->keyBy('bucket');
            $totals = (clone $selected)->selectRaw('COALESCE(SUM(amount_rials), 0) as revenue, COUNT(DISTINCT buyer_id) as buyers,
                COUNT(DISTINCT order_id) as orders_count, COUNT(DISTINCT CASE WHEN renewed = 1 THEN order_id END) as renewals')->first();

            $cashTrend = null;
            $priorCash = null;
            $previous = null;
            $previousSelected = null;
            $previousFrom = null;
            $previousUntil = null;
            if ($filters['period'] !== 'all') {
                $previousFrom = $start->copy()->subDays($days)->utc();
                $previousUntil = $end->copy()->subDays($days)->utc();
                $previous = $this->business->settledTotalsBetween($actorId, $previousFrom, $previousUntil)['revenueRials'];
                $previousSelected = $channel === 'all' && $resellerId === null
                    ? $previous
                    : (int) $this->filterPurchases(DB::query()->fromSub($this->business->crmSettledRows($actorId, $previousFrom, $previousUntil), 'settled'), $channel, $resellerId)->sum('amount_rials');
            }
            if ($cashRows !== null) {
                try {
                    [$cashCase, $cashBindings] = $this->bucketCase('occurred_at', $buckets);
                    $cashTrend = $cashRows->selectRaw("$cashCase as bucket, COALESCE(SUM(amount_rials), 0) as amount", $cashBindings)
                        ->groupBy('bucket')->get()->keyBy('bucket');
                    if ($previousFrom !== null) {
                        $priorCash = $this->cash->incomingTotalsBetween($actorId, $previousFrom, $previousUntil)['cashInRials'];
                    }
                } catch (AuthorizationException|QueryException) {
                    Log::warning('CRM analytics cash totals unavailable');
                }
            }

            $trend = [];
            foreach ($buckets as $index => $bucket) {
                $trend[] = [
                    'start' => $bucket['start']->format('Y-m-d'),
                    'end' => $bucket['end']->copy()->subSecond()->format('Y-m-d'),
                    'salesRials' => (int) ($globalTrend->get($index)->revenue ?? 0),
                    'cashRials' => $cashTrend === null ? null : (int) ($cashTrend->get($index)->amount ?? 0),
                    'selectedRials' => (int) ($selectedTrend->get($index)->revenue ?? 0),
                    'buyers' => (int) ($selectedTrend->get($index)->buyers ?? 0),
                    'directRegistrations' => (int) ($registrations->get($index)->direct_count ?? 0),
                    'resellerRegistrations' => (int) ($registrations->get($index)->reseller_count ?? 0),
                ];
            }

            $category = "CASE WHEN buyer_category IN ('direct', 'reseller') THEN buyer_category ELSE 'unknown' END";
            $groups = (clone $sales)->selectRaw("$category as channel, COALESCE(SUM(amount_rials), 0) as revenue")
                ->groupByRaw($category)->get()->keyBy('channel');
            $channels = [];
            foreach (['direct', 'reseller', 'unknown'] as $key) {
                $channels[$key] = (int) ($groups->get($key)->revenue ?? 0);
            }

            $top = (clone $sales)->where('buyer_category', 'reseller')
                ->joinSub($this->directory->commercialAccounts($actorId, true), 'accounts', 'accounts.id', '=', 'settled.buyer_id')
                ->select('accounts.id', 'accounts.name')
                ->selectRaw('SUM(amount_rials) as revenue')
                ->groupBy('accounts.id', 'accounts.name')->orderByDesc('revenue')->orderBy('accounts.id')->limit(10)->get();
            $products = (clone $selected)->join('sales_orders as orders', 'orders.id', '=', 'settled.order_id')
                ->select('orders.product_slug', 'orders.product_name')
                ->selectRaw('COUNT(DISTINCT settled.order_id) as purchases')
                ->groupBy('orders.product_slug', 'orders.product_name')->orderByDesc('purchases')
                ->orderBy('orders.product_slug')->orderBy('orders.product_name')->limit(10)->get();

            $salesRials = array_sum(array_column($trend, 'salesRials'));
            $cashRials = $cashTrend === null ? null : array_sum(array_column($trend, 'cashRials'));
            $renewals = (int) ($totals->renewals ?? 0);

            return [
                'trend' => $trend,
                'salesRials' => $salesRials,
                'cashRials' => $cashRials,
                'previousSalesRials' => $previous,
                'previousCashRials' => $priorCash,
                'previousSelectedRials' => $previousSelected,
                'selectedRials' => (int) ($totals->revenue ?? 0),
                'buyers' => (int) ($totals->buyers ?? 0),
                'newPurchases' => (int) ($totals->orders_count ?? 0) - $renewals,
                'renewals' => $renewals,
                'channels' => $channels,
                'top' => $top,
                'products' => $products,
                'start' => $start->format('Y-m-d'),
                'end' => $end->copy()->subSecond()->format('Y-m-d'),
            ];
        });
    }

    private function filterPurchases(Builder $query, string $channel, ?int $resellerId): Builder
    {
        if ($channel === 'unknown') {
            $query->where(static fn (Builder $nested) => $nested->whereNull('settled.buyer_category')->orWhereNotIn('settled.buyer_category', ['direct', 'reseller']));
        } elseif ($channel !== 'all') {
            $query->where('settled.buyer_category', $channel);
        }
        if ($resellerId !== null) {
            $query->where('settled.buyer_category', 'reseller')->where('settled.buyer_id', $resellerId);
        }

        return $query;
    }

    /** @return list<array{start:Carbon,end:Carbon}> */
    private function buckets(Carbon $start, Carbon $end): array
    {
        $days = (int) ceil($start->diffInDays($end));
        $months = max(1, (int) ceil($start->diffInMonths($end)));
        $monthStep = max(1, (int) ceil($months / 59));
        $buckets = [];
        for ($cursor = $start->copy(); $cursor->lessThan($end); $cursor = $next) {
            $next = match (true) {
                $days <= 60 => $cursor->copy()->addDay(),
                $days <= 420 => $cursor->copy()->addWeek(),
                default => $cursor->copy()->addMonthsNoOverflow($monthStep),
            };
            $next = count($buckets) === 59 ? $end->copy() : $next->min($end);
            $buckets[] = ['start' => $cursor->copy(), 'end' => $next->copy()];
        }

        return $buckets;
    }

    /** @param  list<array{start:Carbon,end:Carbon}> $buckets
     * @return array{string,list<Carbon>}
     */
    private function bucketCase(string $column, array $buckets): array
    {
        $last = count($buckets) - 1;
        if ($last <= 0) {
            // A CASE expression avoids positional GROUP BY of the constant.
            return ['CASE WHEN 1 = 1 THEN 0 ELSE 0 END', []];
        }
        $sql = 'CASE';
        $bindings = [];
        foreach ($buckets as $index => $bucket) {
            if ($index === $last) {
                break;
            }
            $sql .= " WHEN $column < ? THEN $index";
            $bindings[] = $bucket['end']->copy()->utc();
        }

        return [$sql." ELSE $last END", $bindings];
    }
}
