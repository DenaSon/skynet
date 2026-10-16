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
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
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

            // Classify Telegram API failures without logging API descriptions,
            // callback tokens, chat IDs or other untrusted update content.
            $telegramError = [];
            if ($exception instanceof TelegramException) {
                $description = strtolower($exception->getMessage());
                $telegramError = [
                    'telegram_error_code' => $exception->getCode(),
                    'telegram_error_type' => match (true) {
                        str_contains($description, 'message is not modified') => 'message_not_modified',
                        str_contains($description, 'message identifier is not specified') => 'missing_message_identifier',
                        str_contains($description, 'message to edit not found') => 'edit_target_not_found',
                        str_contains($description, "message can't be edited") => 'edit_not_allowed',
                        str_contains($description, 'query is too old') => 'callback_expired',
                        str_contains($description, 'query id is invalid') => 'invalid_callback_id',
                        str_contains($description, 'chat not found') => 'chat_not_found',
                        str_contains($description, "can't parse entities") => 'invalid_message_format',
                        str_contains($description, 'reply markup is invalid') => 'invalid_reply_markup',
                        default => 'unclassified',
                    },
                    'callback_area' => match ($request->input('callback_query.data')) {
                        'bank:list' => 'bank_list',
                        'menu:admin' => 'admin_menu',
                        'menu:admin:finance' => 'finance_menu',
                        default => 'other',
                    },
                ];
            }

            Log::warning('Telegram update processing failed', [
                'bot_key' => $context->key,
                'update_id' => $updateId,
                'exception_type' => $exception::class,
                ...$telegramError,
            ]);

            return response('Update processing failed.', 500);
        }

        return response()->noContent();
    }
}
