<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;

/** Owner-scoped paid purchase totals, not just the first history page. */
final readonly class SummarizeSalesPurchases
{
    public function __construct(private ResolveSalesBuyer $buyers) {}

    /** @return array{count:int, amountRials:int} */
    public function handle(int $buyerId): array
    {
        $this->buyers->isReseller($buyerId);

        $query = DB::table('sales_orders')
            ->where('buyer_user_id', $buyerId)
            ->whereIn('status', ['paid', 'fulfilled']);

        return [
            'count' => (clone $query)->count(),
            'amountRials' => (int) $query->sum('total_price_rials'),
        ];
    }
}
