<?php

use App\Interfaces\Telegram\Http\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Stateless API middleware: no user session, no CSRF exclusion on regular web routes.
Route::middleware('api')->post('/api/telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');
