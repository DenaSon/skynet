<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;

/** Local, available even when the provider is offline. Never contains credentials. */
final readonly class GetMySubscription
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private SubscriptionOwnershipQuery $subscriptions,
    ) {}

    public function handle(SubscriptionViewer $viewer, int $orderId): SubscriptionDetails
    {
        return $this->subscriptions->detail($this->authorize->userId($viewer), $orderId);
    }
}
