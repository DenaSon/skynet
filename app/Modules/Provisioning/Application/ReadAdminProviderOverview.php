<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeSummary;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trusted-root read Action: no raw API passthrough, mutation or credentials.
 */
final readonly class ReadAdminProviderOverview
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
    ) {}

    public function handle(int $actorUserId): AdminProviderOverview
    {
        $this->access->handle($actorUserId);

        try {
            $read = new RemnawaveReadApi($this->factory->make());
            $instances = $read->healthInstanceCount();
            $inventory = $read->inventory();

            // Optional P1 metrics must not break the verified P0 overview.
            try {
                $systemStats = new AdminSystemStats(...$read->systemStats());
            } catch (Throwable) {
                $systemStats = null;
            }

            return new AdminProviderOverview(
                state: 'available',
                instances: $instances,
                nodes: count($inventory->nodes),
                connectedNodes: $inventory->connectedNodes(),
                hosts: $inventory->hosts,
                internalSquads: $inventory->internalSquads,
                configProfiles: $inventory->configProfiles,
                needsReview: DB::table('provisioning_operations')->where('state', 'needs_review')->count(),
                systemStats: $systemStats,
                nodeSummaries: array_map(static fn (RemnawaveNodeSummary $node): array => [
                    'name' => $node->name,
                    'connected' => $node->connected,
                    'disabled' => $node->disabled,
                    'connecting' => $node->connecting,
                    'usersOnline' => $node->usersOnline,
                    'rxBytesPerSec' => $node->rxBytesPerSec,
                    'txBytesPerSec' => $node->txBytesPerSec,
                    'lastStatusChange' => $node->lastStatusChange,
                    'trafficLimitBytes' => $node->trafficLimitBytes,
                    'trafficUsedBytes' => $node->trafficUsedBytes,
                    'trafficTrackingActive' => $node->trafficTrackingActive,
                ], $inventory->nodes),
            );
        } catch (Throwable) {
            // Do not leak token, endpoint, transport errors or private panel data.
            return new AdminProviderOverview('error');
        }
    }
}
