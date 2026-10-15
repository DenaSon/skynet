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

        if (is_string($token) === false || $token === '') {
            throw new \LogicException('Sales Telegram bot is not configured.');
        }

        $configuration = new Configuration(
            container: app(),
            cache: app(CacheRepository::class),
            logger: app(LoggerInterface::class),
        );

        $bot = new Nutgram($token, $configuration);
        $bot->setRunningMode(LaravelWebhook::class);

        // Only this explicit registry can attach Sales handlers. It is intentionally
        // empty until the separate Sales activation phase; Admin and legacy
        // registrations are never loaded into the Sales token's runtime.
        require __DIR__.'/../routes/sales.php';
        return $bot;
    }

    public function handle(): void
    {
        $this->make()->run();
    }
}
