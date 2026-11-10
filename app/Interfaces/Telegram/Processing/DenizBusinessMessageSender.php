<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DenizBusinessMessageSender
{
    public function __construct(private readonly DenizBusinessMessageFormatter $formatter) {}

    public function send(string $connectionId, int $chatId, int $messageId, string $text): void
    {
        $token = config('telegram.denix.token');

        if (! is_string($token) || $token === '' || $connectionId === '') {
            throw new RuntimeException('Deniz Business delivery is not configured.');
        }

        $plain = mb_substr($text, 0, 3500);
        $payload = [
            'business_connection_id' => $connectionId,
            'chat_id' => $chatId,
            'text' => $this->formatter->format($plain),
            'parse_mode' => 'HTML',
            'reply_parameters' => json_encode([
                'message_id' => $messageId,
                'allow_sending_without_reply' => false,
            ], JSON_THROW_ON_ERROR),
        ];

        // Keep the exact Business scope and reply target in both attempts.
        // Never fall back to a normal bot DM.
        $httpStatus = null;
        $phase = 'html';

        try {
            $url = 'https://api.telegram.org/bot'.$token.'/sendMessage';
            $response = Http::timeout(6)->asForm()->post($url, $payload);
            $httpStatus = $response->status();

            if ($this->isCertainFormattingRejection($response)) {
                // An explicit HTTP 400 entity-parse error guarantees that
                // Telegram did NOT deliver the formatted message. One plain
                // fallback is safe; network timeouts / 5xx are NOT retried.
                unset($payload['parse_mode']);
                $payload['text'] = $plain;
                $phase = 'plain_fallback';
                $response = Http::timeout(6)->asForm()->post($url, $payload);
                $httpStatus = $response->status();
            }

            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram rejected Business delivery.');
            }
        } catch (Throwable $error) {
            // A failed send can be ambiguous: log only the phase, status and type.
            // Never log URLs, Telegram bodies, message text or credentials; never retry.
            Log::warning('Deniz Business delivery failed.', [
                'phase' => $phase,
                'http_status' => $httpStatus,
                'exception_type' => $error::class,
            ]);

            throw new RuntimeException('Deniz Business delivery failed.');
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
