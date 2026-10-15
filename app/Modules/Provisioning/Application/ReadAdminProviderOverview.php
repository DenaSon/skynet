<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trusted-root read Action: no raw API passthrough, mutation or credentials.
 * Panel-backed UI is gated until RW-04/MB-05B live sign-off.
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

        if (config('services.remnawave.admin_reads_verified', false) !== true) {
            return new AdminProviderOverview('unavailable');
        }

        try {
            $read = new RemnawaveReadApi($this->factory->make());
            $instances = $read->healthInstanceCount();
            $inventory = $read->inventory();

            return new AdminProviderOverview(
                state: 'available',
                instances: $instances,
                nodes: count($inventory->nodes),
                connectedNodes: $inventory->connectedNodes(),
                hosts: $inventory->hosts,
                internalSquads: $inventory->internalSquads,
                configProfiles: $inventory->configProfiles,
                needsReview: DB::table('provisioning_operations')->where('state', 'needs_review')->count(),
            );
        } catch (Throwable) {
            // Do not leak token, endpoint, transport errors or private panel data.
            return new AdminProviderOverview('error');
        }
    }
}
