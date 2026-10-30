<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atomically register a reseller's new pending order and customer username.
 * Composes existing checkout/label Actions; never settles money or calls Provider.
 */
final readonly class RegisterPendingResellerOrder
{
    public function __construct(
        private CreatePendingPlanOrder $orders,
        private ManageResellerServiceLabels $labels,
    ) {}

    public function handle(int $buyerId, PlanPriceQuote $expected, string $key, string $username): OrderDetails
    {
        return DB::transaction(function () use ($buyerId, $expected, $key, $username): OrderDetails {
            $order = $this->orders->handle($buyerId, $expected, $key);

            // A replay may only resume the same immutable customer choice.
            $saved = $this->labels->pendingName($buyerId, $order->id);
            if ($saved !== null) {
                if ($saved !== trim($username)) {
                    throw ValidationException::withMessages(['customer' => 'This order already has another customer username.']);
                }

                return $order;
            }

            // Invalid labels roll back the order insertion, not merely the label write.
            $this->labels->savePending($buyerId, $order->id, $username);

            return $order;
        }, 3);
    }
}
