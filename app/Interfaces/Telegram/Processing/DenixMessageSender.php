<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DenixMessageSender
{
    public function send(int $chatId, int $messageId, ?int $threadId, string $text): void
    {
        $token = config('telegram.denix.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Denix delivery not configured.');
        }

        // Plain text intentionally avoids HTML injection or invalid markup.
        // Clamp below Telegram's 4096-character limit.
        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 3500),
            'reply_parameters' => json_encode([
                'message_id' => $messageId,
                'allow_sending_without_reply' => false,
            ], JSON_THROW_ON_ERROR),
        ];

        if ($threadId !== null) {
            $payload['message_thread_id'] = $threadId;
        }

        try {
            $result = Http::timeout(8)->asForm()->post(
                'https://api.telegram.org/bot'.$token.'/sendMessage',
                $payload,
            );

            if (! $result->successful() || $result->json('ok') !== true) {
                throw new RuntimeException('Denix delivery rejected.');
            }
        } catch (Throwable) {
            // Never retain exceptions containing a BotFather token or upstream text.
            throw new RuntimeException('Denix response delivery failed.');
        }
    }
}
