<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
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
    ) {}

    /** @return array{cash:array<string,mixed>|null,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string} */
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
            'stats' => $stats,
            'pendingTopups' => $pendingTopups,
            'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }
}
