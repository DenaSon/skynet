<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Modules\Sales\Application\ReadCrmSales;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** CRM-01B: root-only composition of existing identity and Sales read models. */
final readonly class ReadCrmDirectory
{
    private const array CUSTOMER_SEGMENTS = ['valuable', 'loyal', 'new_customer', 'trial_only', 'wallet_positive', 'at_risk', 'old_interaction', 'unknown_interaction'];

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadCrmSales $sales,
        private ReadAdminBusinessReport $business,
        private ReadAdminCashReceipts $cashReceipts,
        private ReadAdminWalletSummary $wallets,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function handle(int $actorId, string $section, array $filters): array
    {
        $filters = $this->validateFilters($actorId, $section, $filters);

        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $from = match ($filters['period']) {
            'today' => $today->copy(),
            'yesterday' => $today->copy()->subDay(),
            '7' => $today->copy()->subDays(6),
            '30' => $today->copy()->subDays(29),
            'custom' => Carbon::parse($filters['from'], 'Asia/Tehran')->startOfDay(),
            default => Carbon::parse('1970-01-01', 'Asia/Tehran'),
        };
        $until = match ($filters['period']) {
            'yesterday' => $today->copy(),
            'custom' => Carbon::parse($filters['until'], 'Asia/Tehran')->startOfDay()->addDay(),
            default => $today->copy()->addDay(),
        };
        $from = $from->utc();
        $until = $until->utc();

        return DB::transaction(function () use ($actorId, $section, $filters, $from, $until): array {
            if ($section === 'dashboard') {
                return $this->overview($actorId, $from, $until);
            }
            if ($section === 'contacts') {
                return ['rows' => $this->contactPage($actorId, $filters, $from, $until)];
            }

            $segments = null;
            $counts = null;
            if ($section === 'customers' && ($filters['segment'] ?? 'off') !== 'off') {
                $segments = $this->customerSegments($actorId);
                $summary = DB::query()->fromSub(clone $segments, 'segment_counts')->selectRaw('COUNT(*) as total');
                foreach (self::CUSTOMER_SEGMENTS as $key) {
                    $summary->selectRaw("COALESCE(SUM($key), 0) as $key");
                }
                $counts = (array) $summary->first();
            }

            return [
                'rows' => $this->accountPage($actorId, $section === 'resellers', $filters, $from, $until, $segments),
                'segments' => $counts,
            ];
        });
    }

    /**
     * Shared validation for directory reads and saved customer criteria.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function validateFilters(int $actorId, string $section, array $filters): array
    {
        $this->access->handle($actorId);
        $filters = Validator::make([...$filters, 'section' => $section], [
            'section' => ['required', Rule::in(['dashboard', 'customers', 'resellers', 'contacts'])],
            'period' => ['required', Rule::in(['today', 'yesterday', '7', '30', 'all', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
            'until' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:from'],
            'registeredFrom' => ['nullable', 'date_format:Y-m-d'],
            'registeredUntil' => ['nullable', 'date_format:Y-m-d', ...(! empty($filters['registeredFrom']) ? ['after_or_equal:registeredFrom'] : [])],
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['all', 'active', 'inactive'])],
            'telegram' => ['required', Rule::in(['all', 'linked', 'unlinked'])],
            'purchase' => ['required', Rule::in(['all', 'yes', 'no'])],
            'phone' => ['required', Rule::in(['all', 'yes', 'no'])],
            'groupContacts' => ['sometimes', 'boolean'],
            'reseller' => ['nullable', 'integer', 'min:1'],
            'product' => ['nullable', 'string', 'max:80'],
            'orderStatus' => ['required', Rule::in(['all', 'pending_payment', 'paid', 'fulfilled', 'refunded', 'cancelled'])],
            'sort' => ['required', Rule::in(['id', 'name', 'created_at', 'is_active', 'transactions', 'revenue_rials', 'phones', 'name_only', 'order_id', 'performance_rank'])],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
            'page' => ['required', 'integer', 'min:1', 'max:100000'],
            'segment' => ['sometimes', 'string', Rule::in(['off', 'all', ...self::CUSTOMER_SEGMENTS])],
            'advancedMode' => ['sometimes', 'string', Rule::in(['and', 'or'])],
            'advancedConditions' => ['sometimes', 'array', 'list', 'max:5'],
            'advancedConditions.*' => ['required', 'array:field,operator,value'],
            'advancedConditions.*.field' => ['required', 'string', Rule::in(['active', 'telegram', 'orders', 'transactions', 'revenue', 'wallet', 'wallet_known', 'trial', 'seen', 'last_seen'])],
            'advancedConditions.*.operator' => ['required', 'string', Rule::in(['eq', 'gte', 'lte'])],
            'advancedConditions.*.value' => ['required'],
        ])->validate();

        $conditions = $filters['advancedConditions'] ?? [];
        if (($conditions !== [] || ($filters['segment'] ?? 'off') !== 'off') && $section !== 'customers') {
            throw ValidationException::withMessages(['advancedConditions' => 'Invalid customer filter scope.']);
        }
        foreach ($conditions as $condition) {
            $binary = in_array($condition['field'], ['active', 'telegram', 'wallet_known', 'trial', 'seen'], true);
            $rules = match ($condition['field']) {
                'active', 'telegram', 'wallet_known', 'trial', 'seen' => ['required', 'integer', 'min:0', 'max:1'],
                'last_seen' => ['required', 'date_format:Y-m-d'],
                'wallet' => ['required', 'integer', 'min:-1000000000000', 'max:1000000000000'],
                'revenue' => ['required', 'integer', 'min:0', 'max:1000000000000'],
                default => ['required', 'integer', 'min:0', 'max:1000000000'],
            };
            Validator::make($condition, [
                'value' => $rules,
                'operator' => [$binary ? Rule::in(['eq']) : Rule::in(['eq', 'gte', 'lte'])],
            ])->validate();
        }

        return $filters;
    }

    /** The profile and directory must classify accounts identically. */
    public function profileAccount(int $actorId, int $subjectId, bool $reseller): ?object
    {
        $this->access->handle($actorId);

        return $this->accounts($reseller)
            ->leftJoin('telegram_accounts as telegram', 'telegram.user_id', '=', 'users.id')
            ->addSelect('telegram.telegram_user_id')->where('users.id', $subjectId)->first();
    }

    /** @return array<string, mixed>|null */
    public function resellerPerformance(int $actorId): ?array
    {
        $this->access->handle($actorId);
        try {
            return app(ReadCrmResellerPerformance::class)->handle($actorId);
        } catch (AuthorizationException|QueryException) {
            Log::warning('CRM reseller performance unavailable');

            return null;
        }
    }

    /** Authorized shared commercial cohort for CRM aggregate reports. */
    public function commercialAccounts(int $actorId, bool $resellers): Builder
    {
        $this->access->handle($actorId);

        return $this->accounts($resellers);
    }

    private function accounts(bool $resellers): Builder
    {
        $query = User::query()
            ->whereHas('roles', static fn ($roles) => $roles->where('name', $resellers ? 'reseller' : 'member'))
            ->whereDoesntHave('roles', static fn ($roles) => $roles->whereIn('name', $resellers ? ['admin', 'super-admin'] : ['admin', 'super-admin', 'reseller']));

        return $query->select('users.id', 'users.name', 'users.is_active', 'users.created_at')->toBase();
    }

    private function rankedAccounts(int $actorId, bool $resellers, Carbon $from, Carbon $until): Builder
    {
        $query = DB::query()->fromSub($this->accounts($resellers), 'accounts')
            ->leftJoinSub($this->sales->purchases($actorId, $from, $until, $resellers), 'purchases', 'purchases.buyer_id', '=', 'accounts.id')
            ->leftJoin('telegram_accounts as telegram', 'telegram.user_id', '=', 'accounts.id')
            ->select('accounts.*')
            ->selectRaw('CASE WHEN telegram.user_id IS NULL THEN 0 ELSE 1 END as telegram_linked, COALESCE(purchases.transactions, 0) as transactions, COALESCE(purchases.order_count, 0) as order_count, COALESCE(purchases.revenue_rials, 0) as revenue_rials');

        if ($resellers) {
            $query->leftJoinSub($this->sales->contactCounts($actorId, $from, $until), 'contacts', 'contacts.buyer_id', '=', 'accounts.id')
                ->selectRaw('COALESCE(contacts.phones, 0) as phones, COALESCE(contacts.name_only, 0) as name_only, COALESCE(contacts.purchased_phones, 0) as purchased_phones, COALESCE(contacts.purchased_name_only, 0) as purchased_name_only');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function overview(int $actorId, Carbon $from, Carbon $until): array
    {
        $direct = $this->accounts(false)->where('users.created_at', '>=', $from)->where('users.created_at', '<', $until);
        $resellers = $this->accounts(true)->where('users.created_at', '>=', $from)->where('users.created_at', '<', $until);
        $counts = DB::query()->fromSub($this->sales->contactCounts($actorId, $from, $until), 'counts')
            ->joinSub($this->accounts(true), 'resellers', 'resellers.id', '=', 'counts.buyer_id')
            ->selectRaw('COALESCE(SUM(counts.phones), 0) as phones, COALESCE(SUM(counts.name_only), 0) as name_only, COALESCE(SUM(counts.purchased_phones), 0) as purchased_phones, COALESCE(SUM(counts.purchased_name_only), 0) as purchased_name_only')->first();
        $channels = $this->business->settledChannelBreakdownBetween($actorId, $from, $until)['channels'];

        // The two financial reads are independently authorized and may fail independently.
        $cash = null;
        $wallets = null;
        try {
            $cash = $this->cashReceipts->incomingTotalsBetween($actorId, $from, $until);
        } catch (AuthorizationException|QueryException) {
            // Unknown is not a recorded zero.
        }
        try {
            $wallets = $this->wallets->handle($actorId);
        } catch (AuthorizationException|QueryException) {
            // Preserve the rest of the dashboard if this financial read fails.
        }

        return [
            'cash' => $cash,
            'wallets' => $wallets,
            'customers' => (clone $direct)->count(),
            'resellers' => (clone $resellers)->count(),
            'active' => (clone $direct)->where('users.is_active', true)->count() + (clone $resellers)->where('users.is_active', true)->count(),
            'inactive' => (clone $direct)->where('users.is_active', false)->count() + (clone $resellers)->where('users.is_active', false)->count(),
            'phones' => (int) ($counts->phones ?? 0),
            'nameOnly' => (int) ($counts->name_only ?? 0),
            'purchasedPhones' => (int) ($counts->purchased_phones ?? 0),
            'purchasedNameOnly' => (int) ($counts->purchased_name_only ?? 0),
            'channels' => $channels,
            'trend' => $this->recentTrend($actorId, $from, $until),
            'totalRials' => array_sum(array_column($channels, 'revenueRials')),
            'top' => $this->rankedAccounts($actorId, true, $from, $until)->where('purchases.transactions', '>', 0)
                ->orderByDesc('revenue_rials')->orderBy('accounts.id')->limit(10)->get(),
            'generatedAt' => Carbon::now('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }

    /**
     * At most seven Tehran civil days inside the selected window, no full ledger materialization.
     *
     * @return list<array{date:string,revenueRials:int,registrations:int,directRegistrations:int,resellerRegistrations:int}>
     */
    private function recentTrend(int $actorId, Carbon $from, Carbon $until): array
    {
        $end = $until->copy()->min(Carbon::now('Asia/Tehran')->startOfDay()->addDay()->utc());
        $start = $from->copy()->max($end->copy()->subDays(7));
        if ($start->greaterThanOrEqualTo($end)) {
            return [];
        }

        $sales = DB::query()->fromSub($this->business->crmSettledRows($actorId, $start, $end), 'settled');
        $accounts = $this->accounts(false)->select('users.created_at')->selectRaw("'direct' as cohort")
            ->unionAll($this->accounts(true)->select('users.created_at')->selectRaw("'reseller' as cohort"));
        $registrations = DB::query()->fromSub($accounts, 'commercial')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $dates = [];
        for ($day = $start->copy(); $day->lessThan($end); $day->addDay()) {
            $next = $day->copy()->addDay();
            $key = 'day_'.count($dates);
            $dates[$key] = $day->copy()->timezone('Asia/Tehran')->format('Y-m-d');
            $sales->selectRaw("COALESCE(SUM(CASE WHEN settled_at >= ? AND settled_at < ? THEN amount_rials ELSE 0 END), 0) as {$key}", [$day->copy(), $next]);
            $registrations->selectRaw("COUNT(CASE WHEN cohort = 'direct' AND created_at >= ? AND created_at < ? THEN 1 END) as direct_{$key}, COUNT(CASE WHEN cohort = 'reseller' AND created_at >= ? AND created_at < ? THEN 1 END) as reseller_{$key}", [$day->copy(), $next, $day->copy(), $next]);
        }

        $revenue = $sales->first();
        $newAccounts = $registrations->first();
        $rows = [];
        foreach ($dates as $key => $date) {
            $rows[] = [
                'date' => $date,
                'revenueRials' => (int) ($revenue->{$key} ?? 0),
                'registrations' => (int) ($newAccounts->{"direct_{$key}"} ?? 0) + (int) ($newAccounts->{"reseller_{$key}"} ?? 0),
                'directRegistrations' => (int) ($newAccounts->{"direct_{$key}"} ?? 0),
                'resellerRegistrations' => (int) ($newAccounts->{"reseller_{$key}"} ?? 0),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function accountPage(int $actorId, bool $resellers, array $filters, Carbon $from, Carbon $until, ?Builder $segments = null): LengthAwarePaginator
    {
        $query = $this->rankedAccounts($actorId, $resellers, $from, $until);
        if ($segments !== null) {
            $query->joinSub($segments, 'customer_segment', 'customer_segment.id', '=', 'accounts.id')
                ->addSelect('customer_segment.r_score', 'customer_segment.f_score', 'customer_segment.m_score', 'customer_segment.lifetime_orders', 'customer_segment.lifetime_rials', 'customer_segment.last_purchase_at');
            $segment = $filters['segment'] ?? 'all';
            if (in_array($segment, self::CUSTOMER_SEGMENTS, true)) {
                $query->where('customer_segment.'.$segment, 1);
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = $this->literalLike($search);
            $query->where(static function (Builder $nested) use ($search, $like, $resellers): void {
                $nested->whereRaw("accounts.name LIKE ? ESCAPE '!'", [$like]);
                if (ctype_digit($search) && strlen($search) <= 18) {
                    $nested->orWhere('accounts.id', $search);
                    if (! $resellers) {
                        $nested->orWhere('telegram.telegram_user_id', $search);
                    }
                }
            });
        }
        if ($filters['status'] !== 'all') {
            $query->where('accounts.is_active', $filters['status'] === 'active');
        }
        if (! $resellers) {
            if ($filters['telegram'] !== 'all') {
                $filters['telegram'] === 'linked' ? $query->whereNotNull('telegram.user_id') : $query->whereNull('telegram.user_id');
            }
            if ($filters['purchase'] !== 'all') {
                // Purchase history is all-time, independently of the selected sales window.
                $history = $this->sales->purchases($actorId, Carbon::parse('1970-01-01', 'UTC'), Carbon::now('UTC')->addDay());
                $query->leftJoinSub($history, 'history', 'history.buyer_id', '=', 'accounts.id');
                $filters['purchase'] === 'yes' ? $query->whereNotNull('history.buyer_id') : $query->whereNull('history.buyer_id');
            }
            if (! empty($filters['registeredFrom'])) {
                $query->where('accounts.created_at', '>=', Carbon::parse($filters['registeredFrom'], 'Asia/Tehran')->startOfDay()->utc());
            }
            if (! empty($filters['registeredUntil'])) {
                $query->where('accounts.created_at', '<', Carbon::parse($filters['registeredUntil'], 'Asia/Tehran')->startOfDay()->addDay()->utc());
            }
        }
        if (! $resellers && ($filters['advancedConditions'] ?? []) !== []) {
            $this->applyAdvancedConditions($query, $filters);
        }

        $sorts = ['id' => 'accounts.id', 'name' => 'accounts.name', 'created_at' => 'accounts.created_at', 'is_active' => 'accounts.is_active', 'transactions' => 'transactions', 'revenue_rials' => 'revenue_rials'];
        if ($resellers) {
            $sorts += ['phones' => 'phones', 'name_only' => 'name_only'];
        }

        if ($resellers) {
            $query->selectRaw('DENSE_RANK() OVER (ORDER BY COALESCE(purchases.revenue_rials, 0) DESC) as sales_rank');
        }

        $performance = $resellers ? $this->resellerPerformance($actorId) : null;
        if ($resellers && $filters['sort'] === 'performance_rank') {
            $ranks = [];
            foreach ($performance['rows'] ?? [] as $id => $row) {
                if ($row['rank'] !== null) {
                    $ranks[(int) $id] = (int) $row['rank'];
                }
            }
            if ($ranks !== []) {
                // Both values originate from the authorized server-side cohort,
                // never the browser. Keep unranked accounts last in either direction.
                $case = 'CASE accounts.id';
                foreach ($ranks as $id => $rank) {
                    $case .= " WHEN $id THEN $rank";
                }
                $case .= ' ELSE NULL END';
                $direction = $filters['direction'] === 'desc' ? 'DESC' : 'ASC';
                $query->orderByRaw("($case) IS NULL")->orderByRaw("($case) $direction");
            }
        } else {
            $query->orderBy($sorts[$filters['sort']] ?? 'accounts.id', $filters['direction']);
        }
        $page = $query->orderBy('accounts.id')->paginate(50, page: (int) $filters['page']);
        if ($resellers) {
            foreach ($page->items() as $row) {
                $row->performance = $performance['rows'][(int) $row->id] ?? null;
            }
        }

        return $page;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function contactPage(int $actorId, array $filters, Carbon $from, Carbon $until): LengthAwarePaginator
    {
        $query = $this->sales->contacts($actorId, $from, $until)
            ->joinSub($this->accounts(true), 'resellers', 'resellers.id', '=', 'orders.buyer_user_id')
            ->leftJoinSub($this->sales->settledOrderIds($actorId, Carbon::parse('1970-01-01', 'UTC'), Carbon::now('UTC')->addDay()), 'settled', 'settled.order_id', '=', 'orders.id')
            ->select('orders.id as order_id', 'orders.id as id', 'orders.product_name', 'orders.status', 'orders.created_at', 'resellers.name as reseller_name', 'resellers.id as reseller_id', 'labels.label', 'labels.phone')
            ->selectRaw('CASE WHEN settled.order_id IS NULL THEN 0 ELSE 1 END as purchased');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = $this->literalLike($search);
            $query->where(static fn (Builder $nested) => $nested->whereRaw("labels.label LIKE ? ESCAPE '!'", [$like])->orWhereRaw("labels.phone LIKE ? ESCAPE '!'", [$like]));
        }
        if (! empty($filters['reseller'])) {
            $query->where('resellers.id', $filters['reseller']);
        }
        if (! empty($filters['product'])) {
            $query->whereRaw("orders.product_name LIKE ? ESCAPE '!'", [$this->literalLike($filters['product'])]);
        }
        if ($filters['phone'] === 'yes') {
            $query->whereNotNull('labels.phone')->where('labels.phone', '!=', '');
        } elseif ($filters['phone'] === 'no') {
            $query->where(static fn (Builder $nested) => $nested->whereNull('labels.phone')->orWhere('labels.phone', ''));
        }
        if ($filters['orderStatus'] !== 'all') {
            $query->where('orders.status', $filters['orderStatus']);
        }
        if ($filters['purchase'] !== 'all') {
            $filters['purchase'] === 'yes' ? $query->whereNotNull('settled.order_id') : $query->whereNull('settled.order_id');
        }
        if ($filters['groupContacts'] ?? false) {
            // A normalized phone is only unique within its owning reseller.
            // A record with no phone remains independent, keyed by order.
            $group = DB::query()->fromSub($query, 'records')
                ->select('reseller_id', 'reseller_name')
                ->selectRaw("NULLIF(phone, '') as phone, MAX(order_id) as id, MAX(order_id) as order_id, MAX(created_at) as created_at,
                    COUNT(*) as records_count, SUM(purchased) as purchased_count,
                    CASE WHEN COUNT(*) = 1 THEN MIN(label) ELSE NULL END as label")
                ->groupBy('reseller_id', 'reseller_name')
                ->groupByRaw("NULLIF(phone, ''), CASE WHEN NULLIF(phone, '') IS NULL THEN order_id ELSE NULL END");
            $sort = ['order_id' => 'order_id', 'created_at' => 'created_at'][$filters['sort']] ?? 'order_id';

            return $group->orderBy($sort, $filters['direction'])->orderBy('order_id')->paginate(50, page: (int) $filters['page']);
        }

        $sort = ['order_id' => 'orders.id', 'created_at' => 'orders.created_at', 'name' => 'labels.label'][$filters['sort']] ?? 'orders.id';

        return $query->orderBy($sort, $filters['direction'])->orderBy('orders.id')->paginate(50, page: (int) $filters['page']);
    }

    /**
     * All-time canonical purchase evidence for the current direct cohort.
     * Opportunity flags overlap and require an active account; no score without a purchase.
     */
    private function customerSegments(int $actorId): Builder
    {
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $now = Carbon::now('UTC');
        $cutoff = static fn (int $days): Carbon => $today->copy()->subDays($days - 1)->utc();
        $history = $this->sales->purchases($actorId, Carbon::parse('1970-01-01', 'UTC'), $now->copy()->addSecond());
        $metrics = DB::query()->fromSub($this->accounts(false), 'cohort')
            ->leftJoinSub($history, 'lifetime', 'lifetime.buyer_id', '=', 'cohort.id')
            ->leftJoinSub(ReadAdminWalletSummary::walletRankingsQuery(), 'segment_wallet', 'segment_wallet.user_id', '=', 'cohort.id')
            ->leftJoin('telegram_bot_contacts as sales_contact', static function ($join): void {
                $join->on('sales_contact.user_id', '=', 'cohort.id')->where('sales_contact.bot_key', 'sales');
            })
            ->select('cohort.id', 'cohort.is_active', 'cohort.created_at', 'lifetime.last_purchase_at', 'sales_contact.last_seen_at', 'segment_wallet.balance_rials')
            ->selectRaw('COALESCE(lifetime.order_count, 0) as lifetime_orders, COALESCE(lifetime.revenue_rials, 0) as lifetime_rials')
            ->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM trial_claims WHERE trial_claims.user_id = cohort.id AND trial_claims.activated_at IS NOT NULL) THEN 1 ELSE 0 END as trial_activated')
            ->selectRaw('CASE WHEN lifetime.last_purchase_at IS NULL THEN NULL WHEN lifetime.last_purchase_at >= ? THEN 5 WHEN lifetime.last_purchase_at >= ? THEN 4 WHEN lifetime.last_purchase_at >= ? THEN 3 WHEN lifetime.last_purchase_at >= ? THEN 2 ELSE 1 END as r_score', [$cutoff(7), $cutoff(14), $cutoff(30), $cutoff(60)])
            ->selectRaw('CASE WHEN lifetime.order_count IS NULL THEN NULL WHEN lifetime.order_count >= 5 THEN 5 ELSE lifetime.order_count END as f_score')
            ->selectRaw('CASE WHEN lifetime.order_count IS NULL THEN NULL WHEN lifetime.revenue_rials >= 10000000 THEN 5 WHEN lifetime.revenue_rials >= 6000000 THEN 4 WHEN lifetime.revenue_rials >= 3000000 THEN 3 WHEN lifetime.revenue_rials >= 1000000 THEN 2 ELSE 1 END as m_score');

        return DB::query()->fromSub($metrics, 'metrics')->select('metrics.*')
            ->selectRaw('CASE WHEN is_active = 1 AND r_score >= 3 AND f_score >= 3 AND m_score >= 4 THEN 1 ELSE 0 END as valuable')
            ->selectRaw('CASE WHEN is_active = 1 AND lifetime_orders >= 3 THEN 1 ELSE 0 END as loyal')
            ->selectRaw('CASE WHEN is_active = 1 AND created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END as new_customer', [$cutoff(7), $now])
            ->selectRaw('CASE WHEN is_active = 1 AND trial_activated = 1 AND lifetime_orders = 0 THEN 1 ELSE 0 END as trial_only')
            ->selectRaw('CASE WHEN is_active = 1 AND balance_rials > 0 THEN 1 ELSE 0 END as wallet_positive')
            ->selectRaw('CASE WHEN is_active = 1 AND last_purchase_at < ? THEN 1 ELSE 0 END as at_risk', [$cutoff(30)])
            ->selectRaw('CASE WHEN is_active = 1 AND last_seen_at < ? THEN 1 ELSE 0 END as old_interaction', [$cutoff(30)])
            ->selectRaw('CASE WHEN is_active = 1 AND last_seen_at IS NULL THEN 1 ELSE 0 END as unknown_interaction');
    }

    /** @param array<string, mixed> $filters */
    private function applyAdvancedConditions(Builder $query, array $filters): void
    {
        $conditions = $filters['advancedConditions'];
        if (array_intersect(array_column($conditions, 'field'), ['wallet', 'wallet_known']) !== []) {
            $query->leftJoinSub(ReadAdminWalletSummary::walletRankingsQuery(), 'advanced_wallet', 'advanced_wallet.user_id', '=', 'accounts.id');
        }

        // Keep every OR inside this group; existing cohort and basic filters are mandatory.
        $query->where(static function (Builder $group) use ($conditions, $filters): void {
            foreach ($conditions as $index => $condition) {
                $apply = static function (Builder $item) use ($condition): void {
                    $field = $condition['field'];
                    $operator = match ($condition['operator']) {
                        'gte' => '>=',
                        'lte' => '<=',
                        default => '=',
                    };
                    $value = (int) $condition['value'];

                    if ($field === 'active') {
                        $item->where('accounts.is_active', $value === 1);
                    } elseif ($field === 'telegram' || $field === 'wallet_known') {
                        $column = $field === 'telegram' ? 'telegram.user_id' : 'advanced_wallet.user_id';
                        $value === 1 ? $item->whereNotNull($column) : $item->whereNull($column);
                    } elseif ($field === 'trial') {
                        $item->whereExists(static fn (Builder $trial) => $trial->selectRaw('1')
                            ->from('trial_claims')->whereColumn('trial_claims.user_id', 'accounts.id')
                            ->whereNotNull('activated_at'), 'and', $value === 0);
                    } elseif ($field === 'seen' || $field === 'last_seen') {
                        $item->whereExists(static function (Builder $contact) use ($field, $condition): void {
                            $contact->selectRaw('1')->from('telegram_bot_contacts')
                                ->whereColumn('telegram_bot_contacts.user_id', 'accounts.id')
                                ->where('bot_key', 'sales')->whereNotNull('last_seen_at');
                            if ($field === 'last_seen') {
                                $day = Carbon::parse((string) $condition['value'], 'Asia/Tehran')->startOfDay();
                                if ($condition['operator'] !== 'lte') {
                                    $contact->where('last_seen_at', '>=', $day->copy()->utc());
                                }
                                if ($condition['operator'] !== 'gte') {
                                    $contact->where('last_seen_at', '<', $day->copy()->addDay()->utc());
                                }
                            }
                        }, 'and', $field === 'seen' && $value === 0);
                    } else {
                        $column = match ($field) {
                            'orders' => 'COALESCE(purchases.order_count, 0)',
                            'transactions' => 'COALESCE(purchases.transactions, 0)',
                            'revenue' => 'COALESCE(purchases.revenue_rials, 0)',
                            'wallet' => 'advanced_wallet.balance_rials',
                            default => throw new \LogicException('Unexpected CRM filter field.'),
                        };
                        $item->whereRaw("$column $operator ?", [in_array($field, ['revenue', 'wallet'], true) ? $value * 10 : $value]);
                    }
                };
                if ($index > 0 && ($filters['advancedMode'] ?? 'and') === 'or') {
                    $group->orWhere($apply);
                } else {
                    $group->where($apply);
                }
            }
        });
    }

    private function literalLike(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
