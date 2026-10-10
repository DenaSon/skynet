<?php

namespace App\Interfaces\Telegram\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

final class RegisterWebhook extends Command
{
    protected $signature = 'telegram:webhook:set {url? : Public HTTPS webhook URL (default: APP_URL + route)}';

    protected $description = 'Register the protected SkyNet Telegram webhook (not the Nutgram default hook command)';

    public function handle(Nutgram $bot): int
    {
        $secret = config('telegram.webhook_secret');
        $token = config('nutgram.token');
        $url = (string) ($this->argument('url') ?: route('telegram.webhook'));

        if (! is_string($secret) || ! preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $secret)
            || ! is_string($token) || $token === '') {
            $this->error('Configure TELEGRAM_TOKEN and TELEGRAM_WEBHOOK_SECRET (1–256 URL-safe characters).');

            return self::FAILURE;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->error('A public HTTPS webhook URL is required.');

            return self::FAILURE;
        }

        try {
            $accepted = $bot->setWebhook(
                url: $url,
                allowed_updates: ['message', 'callback_query'],
                drop_pending_updates: false,
                secret_token: $secret,
            );
        } catch (\Throwable $e) {
            $this->error('Telegram rejected the webhook setup. Check the URL and network connectivity.');
            // Transport exceptions may contain Bot API URLs including the bot token.
            Log::warning('Telegram webhook registration failed', ['exception_type' => $e::class]);

            return self::FAILURE;
        }

        if ($accepted !== true) {
            $this->error('Telegram did not confirm webhook registration.');

            return self::FAILURE;
        }

        $this->info('Telegram webhook registered at '.$url);

        return self::SUCCESS;
    }
}
