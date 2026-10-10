<?php

namespace App\Interfaces\Telegram\Processing;

interface ProcessTelegramUpdate
{
    public function handle(): void;
}
