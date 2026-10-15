<?php

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Nutgram\Laravel\RunningMode\LaravelWebhook;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;

final class SalesTelegramRuntime implements ProcessSalesTelegramUpdate
{
    public function make(): Nutgram
    {
        $token = config('telegram.sales.token');

        if (! is_string($token) || $token === '') {
            throw new \LogicException('Sales Telegram bot is not configured.');
        }

        $configuration = new Configuration(
            container: app(),
            cache: app(CacheRepository::class),
            logger: app(LoggerInterface::class),
        );

        $bot = new Nutgram($token, $configuration);
        $bot->setRunningMode(LaravelWebhook::class);

        // Deliberately no handlers in MB-02. The Sales Router is an isolated,
        // disabled-by-default technical shell; MB-03 registers allowed routes.
        return $bot;
    }

    public function handle(): void
    {
        $this->make()->run();
    }
}
