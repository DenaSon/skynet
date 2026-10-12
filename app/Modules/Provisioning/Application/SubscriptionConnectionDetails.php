<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/**
 * CREDENTIAL-BEARING DTO. Deliver only through an authenticated private
 * channel; never include in lists, audit payloads or callback parameters.
 */
final readonly class SubscriptionConnectionDetails
{
    public function __construct(
        public int $orderId,
        public string $subscriptionUrl,
    ) {}
}
