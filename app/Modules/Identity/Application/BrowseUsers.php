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
        if ($mode !== 's' && preg_match('/\\A[amr][wp]?u?[bqvcih]?\\z/D', $mode) !== 1) {
            throw ValidationException::withMessages(['mode' => 'Invalid user directory mode.']);
        }

        // The new segment and financial rankings are trusted-Super-Admin-only.
        if (! in_array($mode, ['a', 'r', 's'], true)
            && ! app(SuperAdminTrust::class)->eligible($actor)) {
            throw new AuthorizationException;
        }

        $query = User::query()->with(['roles', 'telegramAccount'])->select('users.*');
        $direction = str_contains($mode, 'u') ? 'asc' : 'desc';
        $filter = preg_match('/[bqvcih]\\z/D', $mode) === 1 ? substr($mode, -1) : '';

        if (str_starts_with($mode, 'r')) {
            $query->whereHas('roles', static fn ($q) => $q->where('name', 'reseller'));
        } elseif (str_starts_with($mode, 'm')) {
            // Ordinary members exclude resellers and privileged staff roles.
            $query->whereHas('roles', static fn ($q) => $q->where('name', 'member'))
                ->whereDoesntHave('roles', static fn ($q) => $q->whereIn('name', ['reseller', 'admin', 'super-admin']));
        }

        if (str_contains($mode, 'w')) {
            // Reuse the ledger-backed one-row-per-wallet read model, including
            // zero for users who have never created a wallet.
            $query->leftJoinSub(ReadAdminWalletSummary::walletBalancesQuery(), 'wallet_rank',
                static fn ($join) => $join->on('wallet_rank.user_id', '=', 'users.id'))
                ->addSelect(DB::raw('COALESCE(wallet_rank.balance_rials, 0) as directory_metric'))
                ->orderBy('directory_metric', $direction);
        } elseif (str_contains($mode, 'p')) {
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
                ->orderBy('directory_metric', $direction);
        }

        // Keep a stable tie-breaker in the selected direction across every page.
        $query->orderBy('users.id', $direction);

        // At most one operational filter, scoped to local immutable/recorded facts.
        if (in_array($filter, ['b', 'c'], true)) {
            // Match the existing HasPaidPurchaseHistory definition (including historical settlements).
            // Wallet top-ups and pending/unsettled orders do not count as purchases.
            $query->whereNotExists(static function ($orders): void {
                $orders->selectRaw('1')->from('sales_orders as orders')
                    ->whereColumn('orders.buyer_user_id', 'users.id')
                    ->where(static function ($paid): void {
                        $paid->whereIn('orders.status', ['paid', 'fulfilled'])
                            ->orWhereExists(static function ($wallet): void {
                                $wallet->selectRaw('1')->from('sales_order_settlements as payment')
                                    ->whereColumn('payment.sales_order_id', 'orders.id');
                            })
                            ->orWhereExists(static function ($bank): void {
                                $bank->selectRaw('1')->from('sales_bank_settlements as payment')
                                    ->whereColumn('payment.sales_order_id', 'orders.id');
                            });
                    });
            });
        }

        if ($filter === 'q') {
            $query->whereExists(static function ($orders): void {
                $orders->selectRaw('1')->from('sales_orders as orders')
                    ->whereColumn('orders.buyer_user_id', 'users.id')
                    ->where('orders.status', 'pending_payment');
            });
        } elseif ($filter === 'v') {
            $query->where(static function ($pending): void {
                $pending->whereExists(static function ($outbox): void {
                    $outbox->selectRaw('1')->from('sales_fulfillment_outbox as fulfillment')
                        ->join('sales_orders as orders', 'orders.id', '=', 'fulfillment.sales_order_id')
                        ->whereColumn('orders.buyer_user_id', 'users.id')
                        ->where('fulfillment.state', 'needs_review');
                })->orWhereExists(static function ($trials): void {
                    $trials->selectRaw('1')->from('trial_claims as claims')
                        ->whereColumn('claims.user_id', 'users.id')
                        ->where('claims.state', 'needs_review');
                });
            });
        } elseif ($filter === 'c') {
            // Reuse the same ledger-backed wallet view as financial rankings.
            $balances = DB::query()->fromSub(ReadAdminWalletSummary::walletBalancesQuery(), 'balances')
                ->select('user_id')->where('balance_rials', '>', 0);
            $query->whereIn('users.id', $balances);
        } elseif ($filter === 'i') {
            $query->where('users.is_active', false);
        } elseif ($filter === 'h') {
            $query->where(static function ($owned): void {
                $owned->whereExists(static function ($services): void {
                    $services->selectRaw('1')->from('provisioning_order_links as links')
                        ->join('sales_orders as orders', 'orders.id', '=', 'links.sales_order_id')
                        ->whereColumn('orders.buyer_user_id', 'users.id')
                        ->whereIn('orders.status', ['paid', 'fulfilled']);
                })->orWhereExists(static function ($trials): void {
                    $trials->selectRaw('1')->from('trial_claims as claims')
                        ->whereColumn('claims.user_id', 'users.id')
                        ->whereNotNull('claims.provisioning_subscription_id');
                });
            });
        }

        if ($search !== '') {
            if (preg_match('/\\Ask_[0-9a-f]{24}\\z/D', $search) === 1) {
                // Look up the existing local provider identity; never enumerate or contact RW.
                // A reserved/needs_review subscription may not have an order link yet.
                $reference = DB::table('provisioning_subscriptions')
                    ->where('remote_username', $search)->value('reference');
                $ownerId = null;

                if (is_string($reference) && preg_match('/\\Asales-order:([1-9][0-9]*)\\z/D', $reference, $match) === 1) {
                    $ownerId = DB::table('sales_orders')->where('id', $match[1])->value('buyer_user_id');
                } elseif (is_string($reference) && preg_match('/\\Afree-trial:([1-9][0-9]*)\\z/D', $reference, $match) === 1) {
                    $ownerId = DB::table('trial_claims')->where('id', $match[1])->value('user_id');
                }

                // Missing/foreign references must never fall back to another user's name.
                $query->where('users.id', $ownerId ?? 0);
            } elseif (ctype_digit($search)) {
                // A number may be either an internal user ID or a linked numeric Telegram ID.
                // Search both with exact, indexed comparisons and preserve either match.
                if (strlen($search) > 20) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where(static function ($ids) use ($search): void {
                        $ids->where('users.id', $search)
                            ->orWhereHas('telegramAccount', static fn ($telegram) => $telegram
                                ->where('telegram_user_id', $search));
                    });
                }
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
                $actor, $user, str_contains($mode, 'w') || str_contains($mode, 'p')
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
