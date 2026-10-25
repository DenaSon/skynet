<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\DB;

/** Bounded local operational counts only: no wallet, revenue, secrets or provider mutations. */
final readonly class ReadDelegatedAdminReports
{
    /** @return array{users:int,activeUsers:int,orders:int,paidOrders:int,activeServices:int} */
    public function handle(int $actorId): array
    {
        app(AdminCapabilityAccess::class)->require($actorId, 'reports.view');

        return [
            'users' => (int) DB::table('users')->count(),
            'activeUsers' => (int) DB::table('users')->where('is_active', true)->count(),
            'orders' => (int) DB::table('sales_orders')->count(),
            'paidOrders' => (int) DB::table('sales_orders')->whereIn('status', ['paid', 'fulfilled'])->count(),
            'activeServices' => (int) DB::table('provisioning_subscriptions')->where('state', 'active')->count(),
        ];
    }
}
