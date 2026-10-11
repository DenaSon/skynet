<?php

namespace App\Modules\Sales\Application;

final readonly class ProductRate
{
    public function __construct(
        public int $durationDays,
        public int $basePriceRials,
        public int $perGbRials,
    ) {}
}
