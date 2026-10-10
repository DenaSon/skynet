<?php

namespace App\Interfaces\Telegram\Processing;

use SergiX44\Nutgram\Nutgram;

final class NutgramUpdateProcessor implements ProcessTelegramUpdate
{
    public function __construct(private readonly Nutgram $bot) {}

    public function handle(): void
    {
        $this->bot->run();
    }
}
