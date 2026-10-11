<?php

namespace App\Modules\Billing\Application;

final readonly class WalletSnapshot
{
    /**
     * @param  list<array{id:int,amount_rials:int,status:string,created_at:string}>  $topups
     */
    public function __construct(
        public int $balanceRials,
        public array $topups,
    ) {}
}
