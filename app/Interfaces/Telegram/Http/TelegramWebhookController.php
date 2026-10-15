<?php

namespace App\Interfaces\Telegram\Http;

use App\Interfaces\Telegram\Processing\ClaimState;
use App\Interfaces\Telegram\Processing\ProcessSalesTelegramUpdate;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Interfaces\Telegram\Processing\TelegramBotContacts;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Processing\TelegramUpdateReceipts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class TelegramWebhookController
{
    public function __invoke(
        Request $request,
        ProcessTelegramUpdate $adminProcessor,
        ProcessSalesTelegramUpdate $salesProcessor,
        TelegramUpdateReceipts $receipts,
        TelegramBotContacts $contacts,
    ): Response {
        $context = TelegramBotContext::current();
        $sales = $context->key === TelegramBotContext::SALES;

        $secret = $sales ? config('telegram.sales.webhook_secret') : config('telegram.webhook_secret');
        $token = $sales ? config('telegram.sales.token') : config('nutgram.token');

        if ($sales && (config('telegram.sales.enabled', false) !== true
            || ! is_string($token) || $token === ''
            || $token === config('nutgram.token')
            || $secret === config('telegram.webhook_secret'))) {
            return response('Webhook not configured.', 503);
        }

        // Do not create a receipt or instantiate a bot before checking its route secret.
        if (! is_string($secret) || $secret === '') {
            return response('Webhook not configured.', 503);
        }

        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($secret, $provided)) {
            return response('Forbidden.', 403);
        }

        // The code may deploy before migrate. Do not claim Sales updates in old schema.
        if ($sales && ! Schema::hasColumn('telegram_updates', 'bot_key')) {
            return response('Webhook not configured.', 503);
        }

        $updateId = $request->input('update_id');

        if (! is_int($updateId) || $updateId < 0) {
            return response('Invalid update.', 422);
        }

        $state = $receipts->claim($updateId, $context->key);

        if ($state === ClaimState::Processed) {
            return response()->noContent();
        }

        if ($state === ClaimState::Busy) {
            return response('Update in progress.', 503)->header('Retry-After', '5');
        }

        try {
            if ($sales) {
                $salesProcessor->handle();
            } else {
                $adminProcessor->handle();
            }

            // Contact bookkeeping must never force a completed business update to run twice.
            try {
                $contacts->record($request, $context->key);
            } catch (Throwable $ignored) {
                Log::warning('Telegram contact tracking failed', [
                    'bot_key' => $context->key,
                    'exception_type' => $ignored::class,
                ]);
            }

            $receipts->processed($updateId, $context->key);
        } catch (Throwable $exception) {
            $receipts->failed($updateId, $context->key);
            Log::warning('Telegram update processing failed', [
                'bot_key' => $context->key,
                'update_id' => $updateId,
                'exception_type' => $exception::class,
            ]);

            return response('Update processing failed.', 500);
        }

        return response()->noContent();
    }
}
