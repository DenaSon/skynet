<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminManagementKpis;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OPS-CMD-01: separate, read-only on-demand sections using canonical Actions.
 * This aggregation never recreates financial settlement calculations.
 */
final readonly class ReadAdminCommandCenter
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminCashReceipts $cash,
        private ReadAdminDashboard $dashboard,
        private ReadAdminManagementKpis $management,
    ) {}

    /** @return array{cash:array<string,mixed>|null,todayCash:array<string,mixed>|null,todayKpis:array<string,mixed>|null,resellers:?int,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string} */
    public function handle(int $actorId): array
    {
        // Authorization is mandatory and must not be swallowed as a read failure.
        $this->access->handle($actorId);

        $cash = null;
        try {
            $cash = $this->cash->handle($actorId, 'all');
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Financial data unavailable; keep other independent sections.
        }

        $todayCash = null;
        try {
            $todayCash = $this->cash->handle($actorId, 'today');
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Today's bank inflows can be unknown without hiding all-time cash.
        }

        $todayKpis = null;
        try {
            $todayKpis = $this->management->handle($actorId, 'day')['current'];
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Sales transactions and registrations remain unknown on failure.
        }

        $resellers = null;
        try {
            // Match the existing reseller directory's actual Spatie role filter.
            $resellers = User::query()
                ->whereHas('roles', static fn ($query) => $query->where('name', 'reseller'))
                ->count();
        } catch (Throwable) {
            // Unknown is not zero.
        }

        $stats = null;
        try {
            $stats = $this->dashboard->handle($actorId);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // STAT-01 unavailable; do not hide verified financial data.
        }

        $pendingTopups = null;
        try {
            // Canonical submitted-state filter from ReadAdminWalletSummary.
            $pendingTopups = (int) DB::table('billing_topups')->where('status', 'submitted')->count();
        } catch (Throwable) {
            // Unknown must not be reported as zero.
        }

        return [
            'cash' => $cash,
            'todayCash' => $todayCash,
            'todayKpis' => $todayKpis,
            'resellers' => $resellers,
            'stats' => $stats,
            'pendingTopups' => $pendingTopups,
            'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }
}
