<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;

/** Only actually linked subscriptions, never unpaid/unfulfilled orders. */
final readonly class ListMyServices
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private SubscriptionOwnershipQuery $subscriptions,
    ) {}

    public function handle(SubscriptionViewer $viewer, int $limit = 6, ?int $beforeOrderId = null): SubscriptionCursorPage
    {
        return $this->subscriptions->servicePage($this->authorize->userId($viewer), $limit, $beforeOrderId);
    }

    /** @return array{total:int, activeRecorded:int, needsReview:int} */
    public function counts(SubscriptionViewer $viewer): array
    {
        return $this->subscriptions->serviceCounts($this->authorize->userId($viewer));
    }
}
