<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Billing\Application\BrowseCustomerWallets;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CUS-360-01: support read model for one internal user, never a CRM or
 * customer-facing subscription endpoint. No provider calls or mutations.
 */
final readonly class ReadCustomerSupportProfile
{
    public function __construct(
        private BrowseUsers $users,
        private AssertBillingAccess $billingAccess,
        private BrowseCustomerWallets $wallets,
    ) {}

    /**
     * @return array{
     *   user:UserDirectoryEntry,
     *   ordersTotal:int, ordersPending:int, recentOrders:list<array{id:int,name:string,status:string,at:string}>,
     *   lastSettledAt:?string, servicesTotal:int, servicesActive:int, servicesReview:int,
     *   recentServices:list<array{id:int,name:string,state:string}>,
     *   salesContact:?array{status:string,lastSeen:string},
     *   financeAllowed:bool,walletBalanceRials:?int,financeUnavailable:bool
     * }
     */
    public function handle(int $actorId, int $subjectId): array
    {
        if ($subjectId < 1) {
            throw ValidationException::withMessages(['user' => 'Invalid user.']);
        }

        // Reuse the exact access policy of the current Admin user directory.
        // A forged callback must not reach orders, services or Billing.
        $user = $this->users->find($actorId, $subjectId);
        if ($user === null) {
            throw ValidationException::withMessages(['user' => 'User not found.']);
        }

        $orders = DB::table('sales_orders')->where('buyer_user_id', $subjectId);
        $ordersTotal = (int) (clone $orders)->count();
        $ordersPending = (int) (clone $orders)->where('status', 'pending_payment')->count();
        $recentOrders = (clone $orders)->orderByDesc('id')->limit(3)
            ->get(['id', 'product_name', 'status', 'created_at'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->product_name,
                'status' => (string) $row->status,
                'at' => (string) $row->created_at,
            ])->all();

        // Verified payment timestamp from canonical settlement evidence only;
        // an unpaid order or a wallet topup is not a completed purchase.
        $lastBank = DB::table('sales_bank_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->where('orders.buyer_user_id', $subjectId)->max('payment.settled_at');
        $lastWallet = DB::table('sales_order_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->where('orders.buyer_user_id', $subjectId)->max('payment.settled_at');
        $lastSettledAt = max((string) $lastBank, (string) $lastWallet) ?: null;

        // Match the local linked-service definition in SubscriptionOwnershipQuery.
        // These are recorded states, NOT live Remnawave availability or expiry.
        $services = DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as subscriptions', 'subscriptions.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.buyer_user_id', $subjectId)
            ->whereIn('orders.status', ['paid', 'fulfilled']);

        $serviceCounts = (clone $services)->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN subscriptions.state = 'active' THEN 1 ELSE 0 END) AS active_count")
            ->selectRaw("SUM(CASE WHEN subscriptions.state = 'needs_review' THEN 1 ELSE 0 END) AS review_count")
            ->first();
        $recentServices = (clone $services)->orderByDesc('orders.id')->limit(3)
            ->get(['orders.id', 'orders.product_name', 'subscriptions.state'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->product_name,
                'state' => (string) $row->state,
            ])->all();

        $contact = DB::table('telegram_bot_contacts')
            ->where('user_id', $subjectId)->where('bot_key', 'sales')
            ->first(['delivery_status', 'last_seen_at']);

        $financeAllowed = false;
        try {
            $this->billingAccess->reviewer($actorId);
            $financeAllowed = true;
        } catch (AuthorizationException) {
            // Managing users does not imply authority to view their finances.
        }

        $walletBalance = null;
        $financeUnavailable = false;
        if ($financeAllowed) {
            try {
                $walletBalance = $this->wallets->detail($actorId, $subjectId)['balance_rials'];
            } catch (Throwable) {
                $financeUnavailable = true;
                // Billing errors do not hide the already-authorized support view.
            }
        }

        return [
            'user' => $user,
            'ordersTotal' => $ordersTotal,
            'ordersPending' => $ordersPending,
            'recentOrders' => $recentOrders,
            'lastSettledAt' => $lastSettledAt,
            'servicesTotal' => (int) ($serviceCounts->total ?? 0),
            'servicesActive' => (int) ($serviceCounts->active_count ?? 0),
            'servicesReview' => (int) ($serviceCounts->review_count ?? 0),
            'recentServices' => $recentServices,
            'salesContact' => $contact === null ? null : [
                'status' => (string) $contact->delivery_status,
                'lastSeen' => (string) $contact->last_seen_at,
            ],
            'financeAllowed' => $financeAllowed,
            'walletBalanceRials' => $walletBalance,
            'financeUnavailable' => $financeUnavailable,
        ];
    }
}
