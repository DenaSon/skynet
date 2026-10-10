<?php

namespace App\Interfaces\Telegram\Http;

use App\Interfaces\Telegram\Processing\ClaimState;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Interfaces\Telegram\Processing\TelegramUpdateReceipts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class TelegramWebhookController
{
    public function __invoke(
        Request $request,
        ProcessTelegramUpdate $processor,
        TelegramUpdateReceipts $receipts,
    ): Response {
        $secret = config('telegram.webhook_secret');

        // Fail closed even for local/testing environments.
        if (! is_string($secret) || $secret === '') {
            return response('Webhook not configured.', 503);
        }

        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($secret, $provided)) {
            return response('Forbidden.', 403);
        }

        $updateId = $request->input('update_id');

        if (! is_int($updateId) || $updateId < 0) {
            return response('Invalid update.', 422);
        }

        $state = $receipts->claim($updateId);

        if ($state === ClaimState::Processed) {
            return response()->noContent();
        }

        if ($state === ClaimState::Busy) {
            return response('Update in progress.', 503)->header('Retry-After', '5');
        }

        try {
            $processor->handle();
            $receipts->processed($updateId);
        } catch (Throwable $exception) {
            $receipts->failed($updateId);
            report($exception);

            return response('Update processing failed.', 500);
        }

        return response()->noContent();
    }
}
