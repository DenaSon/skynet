<?php

use App\Interfaces\Telegram\Http\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Preserve the original endpoint and token for existing users and old keyboards.
// Both Admin URLs intentionally claim the same (admin, update_id) receipt.
Route::middleware('api')->post('/api/telegram/webhook', TelegramWebhookController::class)
    ->defaults('bot_key', 'admin')
    ->name('telegram.webhook');

Route::middleware('api')->post('/api/telegram/admin/webhook', TelegramWebhookController::class)
    ->defaults('bot_key', 'admin')
    ->name('telegram.admin.webhook');

// Fail-closed unless explicitly configured; no Sales handlers are registered in MB-02.
Route::middleware('api')->post('/api/telegram/sales/webhook', TelegramWebhookController::class)
    ->defaults('bot_key', 'sales')
    ->name('telegram.sales.webhook');
