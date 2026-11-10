<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DenixMessageSender
{
    public function __construct(private readonly DenizBusinessMessageFormatter $formatter) {}

    public function send(int $chatId, int $messageId, ?int $threadId, string $text): void
    {
        $token = config('telegram.denix.token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Denix delivery not configured.');
        }

        // Reuse the existing safe Business Markdown subset for group messages.
        // Format a bounded complete string, never truncate generated HTML tags.
        $plain = mb_substr($text, 0, 3500);
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->formatter->format($plain),
            'parse_mode' => 'HTML',
            'reply_parameters' => json_encode([
                'message_id' => $messageId,
                'allow_sending_without_reply' => false,
            ], JSON_THROW_ON_ERROR),
        ];

        if ($threadId !== null) {
            $payload['message_thread_id'] = $threadId;
        }

        try {
            $url = 'https://api.telegram.org/bot'.$token.'/sendMessage';
            $result = Http::timeout(8)->asForm()->post($url, $payload);

            if ($this->isCertainFormattingRejection($result)) {
                // A definite HTTP 400 entity parsing failure means no delivery.
                // A timeout/5xx is ambiguous: never replay the message then.
                unset($payload['parse_mode']);
                $payload['text'] = $plain;
                $result = Http::timeout(8)->asForm()->post($url, $payload);
            }

            if (! $result->successful() || $result->json('ok') !== true) {
                throw new RuntimeException('Denix delivery rejected.');
            }
        } catch (Throwable) {
            // Never retain exceptions containing Bot token, user text or response.
            throw new RuntimeException('Denix response delivery failed.');
        }
    }

    private function isCertainFormattingRejection(Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        $description = $response->json('description');

        return is_string($description)
            && (str_contains(strtolower($description), 'parse entities')
                || str_contains(strtolower($description), 'unsupported start tag')
                || str_contains(strtolower($description), 'can\'t find end tag'));
    }
}
