<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUserSnapshot;

/** Credential-free, owner-verified GET check for REN-01 draft eligibility. */
final readonly class ReadRenewableSubscription
{
    public function __construct(private ActiveSubscriptionAccess $access) {}

    public function eligible(SubscriptionViewer $viewer, int $orderId, string $expectedSquadUuid): bool
    {
        return $this->access->read(
            $viewer, $orderId,
            static fn (RemnawaveUserSnapshot $remote): bool =>
                in_array($remote->status, ['ACTIVE', 'LIMITED', 'EXPIRED'], true)
                && $remote->trafficLimitStrategy === 'NO_RESET'
                && $remote->activeInternalSquadUuids === [strtolower($expectedSquadUuid)],
            requireUsable: false,
        );
    }
}
