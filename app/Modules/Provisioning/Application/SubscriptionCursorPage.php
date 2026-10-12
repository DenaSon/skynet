<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class SubscriptionCursorPage
{
    /**
     * @param  list<SubscriptionSummary>  $items
     */
    public function __construct(
        public array $items,
        public ?int $nextBeforeOrderId,
    ) {}
}
