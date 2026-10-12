<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;

/** Consumer-independent, customer-owned directory; never calls Remnawave. */
final readonly class ListMySubscriptions
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private SubscriptionOwnershipQuery $subscriptions,
    ) {}

    public function handle(SubscriptionViewer $viewer, int $limit = 20, ?int $beforeOrderId = null): SubscriptionCursorPage
    {
        return $this->subscriptions->page($this->authorize->userId($viewer), $limit, $beforeOrderId);
    }
}
