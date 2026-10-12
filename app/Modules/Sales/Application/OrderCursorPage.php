<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

final readonly class OrderCursorPage
{
    /**
     * @param  list<OrderDetails>  $orders
     */
    public function __construct(
        public array $orders,
        public ?int $nextBeforeOrderId,
    ) {}
}
