<?php

namespace App\Modules\Identity\Application;

use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BrowseUsers
{
    public function page(int $actorId, ?string $search = null, int $page = 1, bool $resellersOnly = false, string $mode = 'a'): UserDirectoryPage
    {
        $actor = $this->authorize($actorId);
        $search = trim((string) $search);

        if (mb_strlen($search) > 64) {
            throw ValidationException::withMessages(['search' => 'Search is limited to 64 characters.']);
        }

        // Legacy callers keep their original all/reseller/search behavior.
        if ($resellersOnly && $mode === 'a') {
            $mode = 'r';
        }
        if (! in_array($mode, ['a', 'r', 's', 'm', 'aw', 'mw', 'rw', 'ap', 'mp', 'rp'], true)) {
            throw ValidationException::withMessages(['mode' => 'Invalid user directory mode.']);
        }

        // The new segment and financial rankings are trusted-Super-Admin-only.
        if (! in_array($mode, ['a', 'r', 's'], true)
            && ! app(SuperAdminTrust::class)->eligible($actor)) {
            throw new AuthorizationException;
        }

        $query = User::query()->with(['roles', 'telegramAccount'])->select('users.*');

        if (in_array($mode, ['r', 'rw', 'rp'], true)) {
            $query->whereHas('roles', static fn ($q) => $q->where('name', 'reseller'));
        } elseif (in_array($mode, ['m', 'mw', 'mp'], true)) {
            // Ordinary members exclude resellers and privileged staff roles.
            $query->whereHas('roles', static fn ($q) => $q->where('name', 'member'))
                ->whereDoesntHave('roles', static fn ($q) => $q->whereIn('name', ['reseller', 'admin', 'super-admin']));
        }

        if (str_ends_with($mode, 'w')) {
            // Reuse the ledger-backed one-row-per-wallet read model, including
            // zero for users who have never created a wallet.
            $query->leftJoinSub(ReadAdminWalletSummary::walletBalancesQuery(), 'wallet_rank',
                static fn ($join) => $join->on('wallet_rank.user_id', '=', 'users.id'))
                ->addSelect(DB::raw('COALESCE(wallet_rank.balance_rials, 0) as directory_metric'))
                ->orderByDesc('directory_metric');
        } elseif (str_ends_with($mode, 'p')) {
            // Match settled bank/wallet sources used by Admin sales reporting,
            // excluding unpaid, refunded and top-up records.
            $bank = DB::table('sales_bank_settlements as payment')
                ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
                ->whereIn('orders.status', ['paid', 'fulfilled'])
                ->selectRaw('orders.buyer_user_id as user_id, payment.amount_rials as amount_rials');
            $wallet = DB::table('sales_order_settlements as payment')
                ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
                ->whereIn('orders.status', ['paid', 'fulfilled'])
                ->selectRaw('orders.buyer_user_id as user_id, payment.amount_rials as amount_rials');
            $totals = DB::query()->fromSub($bank->unionAll($wallet), 'settled')
                ->selectRaw('user_id, SUM(amount_rials) as total_rials')->groupBy('user_id');

            $query->leftJoinSub($totals, 'purchase_rank',
                static fn ($join) => $join->on('purchase_rank.user_id', '=', 'users.id'))
                ->addSelect(DB::raw('COALESCE(purchase_rank.total_rials, 0) as directory_metric'))
                ->orderByDesc('directory_metric');
        }

        // Stable tie-breaker across all pages and default newest-first mode.
        $query->orderByDesc('users.id');

        if ($search !== '') {
            if (ctype_digit($search)) {
                $query->where('id', (int) $search);
            } else {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            }
        }

        $paginator = $query->paginate(perPage: 20, page: max(1, min($page, 100000)));

        return new UserDirectoryPage(
            users: $paginator->getCollection()->map(fn (User $user): UserDirectoryEntry => $this->entry(
                $actor, $user, str_ends_with($mode, 'w') || str_ends_with($mode, 'p')
                    ? (int) $user->getAttribute('directory_metric') : null,
            ))->all(),
            page: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            total: $paginator->total(),
        );
    }

    public function find(int $actorId, int $userId): ?UserDirectoryEntry
    {
        $actor = $this->authorize($actorId);
        $user = User::query()->with(['roles', 'telegramAccount'])->find($userId);

        return $user === null ? null : $this->entry($actor, $user);
    }

    private function authorize(int $actorId): User
    {
        $actor = User::query()->with('telegramAccount')->find($actorId);

        if ($actor === null || ! app(AdminCapabilityAccess::class)->allowed($actor, 'users.view')
            && ! app(AdminCapabilityAccess::class)->allowed($actor, 'support.view')) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function entry(User $actor, User $user, ?int $metricRials = null): UserDirectoryEntry
    {
        $trust = app(SuperAdminTrust::class);
        $canChange = ! $user->hasRole('super-admin')
            && ! $actor->is($user)
            && app(AdminCapabilityAccess::class)->allowed($actor, 'users.manage')
            && ($trust->eligible($actor) || ! app(AdminCapabilityAccess::class)->isOperator($user));

        return new UserDirectoryEntry(
            id: (int) $user->getKey(),
            name: $user->name,
            email: $user->email,
            roles: $user->getRoleNames()->all(),
            active: $user->is_active,
            telegramLinked: $user->telegramAccount !== null,
            canChangeStatus: $canChange,
            telegramUserId: $user->telegramAccount === null ? null : (int) $user->telegramAccount->telegram_user_id,
            directoryMetricRials: $metricRials,
        );
    }
}
