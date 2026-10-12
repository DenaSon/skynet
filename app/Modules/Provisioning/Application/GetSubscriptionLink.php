<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\SubscriptionViewer;

/** Dedicated credential-retrieval action, separate from all summary DTOs. */
final readonly class GetSubscriptionLink
{
    public function __construct(private ActiveSubscriptionAccess $access) {}

    public function handle(SubscriptionViewer $viewer, int $orderId): SubscriptionConnectionDetails
    {
        return $this->access->read(
            $viewer,
            $orderId,
            static fn ($remote): SubscriptionConnectionDetails => new SubscriptionConnectionDetails(
                $orderId,
                $remote->subscriptionUrl,
            ),
        );
    }
}
