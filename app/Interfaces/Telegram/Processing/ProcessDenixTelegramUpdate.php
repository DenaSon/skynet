<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

interface ProcessDenixTelegramUpdate
{
    public function handle(): void;
}
