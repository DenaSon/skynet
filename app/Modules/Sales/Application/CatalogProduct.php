<?php

namespace App\Modules\Sales\Application;

final readonly class CatalogProduct
{
    /**
     * @param  list<ProductRate>  $rates
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public int $minGb,
        public int $maxGb,
        public array $rates,
    ) {}
}
