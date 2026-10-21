<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUserSnapshot;

/**
 * Read-only, credential-free live usage and status for an owned linked order, even if expired.
 * The existing access action verifies provider identity and reauthorizes after GET.
 */
final readonly class GetSubscriptionUsage
{
    public function __construct(private ActiveSubscriptionAccess $access) {}

    public function handle(SubscriptionViewer $viewer, int $orderId): SubscriptionUsage
    {
        return $this->access->read(
            $viewer,
            $orderId,
            static fn (RemnawaveUserSnapshot $remote): SubscriptionUsage => new SubscriptionUsage(
                expireAt: $remote->expireAt,
                usedBytes: $remote->usedTrafficBytes,
                limitBytes: $remote->trafficLimitBytes,
                status: $remote->status,
            ),
            requireUsable: false,
        );
    }
}
