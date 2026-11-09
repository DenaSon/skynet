<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DenizBusinessMessageSender
{
    public function send(string $connectionId, int $chatId, int $messageId, string $text): void
    {
        $token = config('telegram.denix.token');

        if (! is_string($token) || $token === '' || $connectionId === '') {
            throw new RuntimeException('Deniz Business delivery is not configured.');
        }

        // Explicit Business scope is mandatory: never fall back to a normal bot DM.
        try {
            $response = Http::timeout(6)->asForm()->post(
                'https://api.telegram.org/bot'.$token.'/sendMessage',
                [
                    'business_connection_id' => $connectionId,
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, 3500),
                    'reply_parameters' => json_encode([
                        'message_id' => $messageId,
                        'allow_sending_without_reply' => false,
                    ], JSON_THROW_ON_ERROR),
                ],
            );

            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram rejected Business delivery.');
            }
        } catch (Throwable) {
            // Sending may already have succeeded before a transport error.
            // Do not retry, and never leak the BotFather token in exception text.
            throw new RuntimeException('Deniz Business delivery failed.');
        }
    }
}
