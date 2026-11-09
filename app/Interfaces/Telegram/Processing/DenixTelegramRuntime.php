<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use LogicException;
use Nutgram\Laravel\RunningMode\LaravelWebhook;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;

final class DenixTelegramRuntime implements ProcessDenixTelegramUpdate
{
    public function make(): Nutgram
    {
        $token = config('telegram.denix.token');

        if (! is_string($token) || $token === '') {
            throw new LogicException('Denix Telegram token is not configured.');
        }

        $configuration = new Configuration(
            container: app(),
            cache: app(CacheRepository::class),
            logger: Log::channel('null'),
        );

        $bot = new Nutgram($token, $configuration);
        $bot->setRunningMode(LaravelWebhook::class);

        // Deliberately no Admin, Sales, legacy, commerce or provider routes.
        require __DIR__.'/../routes/denix.php';

        return $bot;
    }

    public function handle(): void
    {
        $this->make()->run();
    }
}
