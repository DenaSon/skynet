<?php

namespace App\Interfaces\Telegram\Console;

use App\Interfaces\Telegram\Processing\SalesTelegramRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

final class RegisterWebhook extends Command
{
    protected $signature = 'telegram:webhook:set {url? : Public HTTPS webhook URL (default: existing legacy Admin URL)} {--bot=admin : admin or sales}';

    protected $description = 'Register a protected ParsaGate Telegram webhook';

    public function handle(Nutgram $adminBot, SalesTelegramRuntime $salesRuntime): int
    {
        $key = $this->option('bot');

        if (! in_array($key, ['admin', 'sales'], true)) {
            $this->error('Unknown bot. Choose admin or sales.');

            return self::FAILURE;
        }

        $sales = $key === 'sales';
        $secret = $sales ? config('telegram.sales.webhook_secret') : config('telegram.webhook_secret');
        $token = $sales ? config('telegram.sales.token') : config('nutgram.token');
        $url = (string) ($this->argument('url') ?: route($sales ? 'telegram.sales.webhook' : 'telegram.webhook'));

        if ($sales && (config('telegram.sales.enabled', false) !== true
            || $token === config('nutgram.token')
            || $secret === config('telegram.webhook_secret'))) {
            $this->error('Sales bot is disabled or its credentials are not independent.');

            return self::FAILURE;
        }

        if (! is_string($secret) || ! preg_match('/^[A-Za-z0-9_-]{1,256}$/D', $secret)
            || ! is_string($token) || $token === '') {
            $this->error('Configure the selected Telegram token and independent URL-safe webhook secret.');

            return self::FAILURE;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->error('A public HTTPS webhook URL is required.');

            return self::FAILURE;
        }

        try {
            $bot = $sales ? $salesRuntime->make() : $adminBot;
            $accepted = $bot->setWebhook(
                url: $url,
                allowed_updates: ['message', 'callback_query'],
                drop_pending_updates: false,
                secret_token: $secret,
            );
        } catch (\Throwable $e) {
            $this->error('Telegram rejected the webhook setup. Check URL and network connectivity.');
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
