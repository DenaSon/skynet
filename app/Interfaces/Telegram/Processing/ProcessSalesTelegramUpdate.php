<?php

namespace App\Interfaces\Telegram\Processing;

interface ProcessSalesTelegramUpdate
{
    public function handle(): void;
}
