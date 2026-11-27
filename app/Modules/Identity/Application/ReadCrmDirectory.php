<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Modules\Sales\Application\ReadCrmSales;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** CRM-01B: root-only composition of existing identity and Sales read models. */
final readonly class ReadCrmDirectory
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadCrmSales $sales,
        private ReadAdminBusinessReport $business,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function handle(int $actorId, string $section, array $filters): array
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
            'sort' => ['required', Rule::in(['id', 'name', 'created_at', 'is_active', 'transactions', 'revenue_rials', 'phones', 'name_only', 'order_id'])],
            'direction' => ['required', Rule::in(['asc', 'desc'])],
            'page' => ['required', 'integer', 'min:1', 'max:100000'],
        ])->validate();

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

            return ['rows' => $this->accountPage($actorId, $section === 'resellers', $filters, $from, $until)];
        });
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
            ->selectRaw('CASE WHEN telegram.user_id IS NULL THEN 0 ELSE 1 END as telegram_linked, COALESCE(purchases.transactions, 0) as transactions, COALESCE(purchases.revenue_rials, 0) as revenue_rials');

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

        return [
            'customers' => (clone $direct)->count(),
            'resellers' => (clone $resellers)->count(),
            'active' => (clone $direct)->where('users.is_active', true)->count() + (clone $resellers)->where('users.is_active', true)->count(),
            'inactive' => (clone $direct)->where('users.is_active', false)->count() + (clone $resellers)->where('users.is_active', false)->count(),
            'phones' => (int) ($counts->phones ?? 0),
            'nameOnly' => (int) ($counts->name_only ?? 0),
            'purchasedPhones' => (int) ($counts->purchased_phones ?? 0),
            'purchasedNameOnly' => (int) ($counts->purchased_name_only ?? 0),
            'channels' => $channels,
            'totalRials' => array_sum(array_column($channels, 'revenueRials')),
            'top' => $this->rankedAccounts($actorId, true, $from, $until)->where('purchases.transactions', '>', 0)
                ->orderByDesc('revenue_rials')->orderBy('accounts.id')->limit(10)->get(),
            'generatedAt' => Carbon::now('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function accountPage(int $actorId, bool $resellers, array $filters, Carbon $from, Carbon $until): LengthAwarePaginator
    {
        $query = $this->rankedAccounts($actorId, $resellers, $from, $until);
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
        $sorts = ['id' => 'accounts.id', 'name' => 'accounts.name', 'created_at' => 'accounts.created_at', 'is_active' => 'accounts.is_active', 'transactions' => 'transactions', 'revenue_rials' => 'revenue_rials'];
        if ($resellers) {
            $sorts += ['phones' => 'phones', 'name_only' => 'name_only'];
        }

        if ($resellers) {
            $query->selectRaw('DENSE_RANK() OVER (ORDER BY COALESCE(purchases.revenue_rials, 0) DESC) as sales_rank');
        }

        return $query->orderBy($sorts[$filters['sort']] ?? 'accounts.id', $filters['direction'])
            ->orderBy('accounts.id')->paginate(25, page: (int) $filters['page']);
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

            return $group->orderBy($sort, $filters['direction'])->orderBy('order_id')->paginate(25, page: (int) $filters['page']);
        }

        $sort = ['order_id' => 'orders.id', 'created_at' => 'orders.created_at', 'name' => 'labels.label'][$filters['sort']] ?? 'orders.id';

        return $query->orderBy($sort, $filters['direction'])->orderBy('orders.id')->paginate(25, page: (int) $filters['page']);
    }

    private function literalLike(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
