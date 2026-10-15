<?php

namespace App\Interfaces\Telegram;

use App\Interfaces\Telegram\Console\IssueLinkCommand;
use App\Interfaces\Telegram\Console\PruneUpdates;
use App\Interfaces\Telegram\Console\RegisterWebhook;
use App\Interfaces\Telegram\Processing\NutgramUpdateProcessor;
use App\Interfaces\Telegram\Processing\ProcessSalesTelegramUpdate;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Interfaces\Telegram\Processing\SalesTelegramRuntime;
use Illuminate\Support\ServiceProvider;

final class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProcessTelegramUpdate::class, NutgramUpdateProcessor::class);
        $this->app->bind(ProcessSalesTelegramUpdate::class, SalesTelegramRuntime::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/webhook.php');

        if ($this->app->runningInConsole()) {
            $this->commands([IssueLinkCommand::class, PruneUpdates::class, RegisterWebhook::class]);
        }
    }
}
