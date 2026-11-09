<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

final readonly class DenixGroupMessage
{
    public function __construct(private DenixGroupAccess $access) {}

    public function handle(Request $request): void
    {
        $message = $request->input('message');

        if (! is_array($message) || ! in_array(data_get($message, 'chat.type'), ['group', 'supergroup'], true)) {
            return;
        }

        $chatId = data_get($message, 'chat.id');
        $userId = data_get($message, 'from.id');
        $messageId = data_get($message, 'message_id');
        $text = data_get($message, 'text');

        if (! is_int($chatId) || ! is_int($userId) || ! is_int($messageId)
            || $messageId < 1 || ! is_string($text) || data_get($message, 'from.is_bot') !== false
            || data_get($message, 'sender_chat') !== null
            || ! $this->access->allowed($chatId, $userId)) {
            return;
        }

        $threadId = data_get($message, 'message_thread_id');

        if ($threadId !== null && (! is_int($threadId) || $threadId < 1)) {
            return;
        }

        $prompt = $this->prompt($message, $text);

        if ($prompt === null || $prompt === '' || mb_strlen($prompt) > (int) config('denagate_ai.max_input_chars', 2000)) {
            return;
        }

        $connection = config('queue.default');
        $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
        $retryAfter = is_string($connection) ? config('queue.connections.'.$connection.'.retry_after') : null;

        // Never execute a paid network request in the webhook or on the default worker.
        if (! in_array($driver, ['redis', 'database'], true) || ! is_numeric($retryAfter) || (int) $retryAfter <= 45) {
            throw new RuntimeException('Denix asynchronous queue is not configured.');
        }

        $cacheName = config('cache.default');
        $cacheDriver = is_string($cacheName) ? config('cache.stores.'.$cacheName.'.driver') : null;

        if (! in_array($cacheDriver, ['redis', 'database'], true)) {
            throw new RuntimeException('Denix shared cache is not configured.');
        }

        // Message identity is independent of Telegram update_id, which is separately
        // guarded by TelegramUpdateReceipts. Keep this fence on ambiguous enqueue failures.
        $fence = 'denix:message:'.$chatId.':'.$messageId;

        if (! Cache::add($fence, true, now()->addDays(7))) {
            return;
        }

        if (RateLimiter::tooManyAttempts('denix:user:'.$userId, 5)
            || RateLimiter::tooManyAttempts('denix:group:'.$chatId, 50)) {
            return;
        }

        RateLimiter::hit('denix:user:'.$userId, 600);
        RateLimiter::hit('denix:group:'.$chatId, 86400);

        AnswerDenixMessage::dispatch($chatId, $userId, $messageId, $threadId, $prompt);
    }

    /**
     * Extract only an explicit /ai question or a reply to this exact Denix bot.
     */
    private function prompt(array $message, string $text): ?string
    {
        $username = config('telegram.denix.bot_username', 'denixobot');

        if (! is_string($username) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{4,31}\z/D', $username) !== 1) {
            return null;
        }

        if (preg_match('/\A\/ai(?:@([a-zA-Z][a-zA-Z0-9_]{4,31}))?(?:\s+([\s\S]*))?\z/iu', $text, $matches) === 1) {
            if (isset($matches[1]) && $matches[1] !== '' && strcasecmp($matches[1], $username) !== 0) {
                return null;
            }

            return trim((string) ($matches[2] ?? ''));
        }

        if (str_starts_with($text, '/')) {
            return null;
        }

        $token = config('telegram.denix.token');

        if (! is_string($token) || preg_match('/\A([1-9][0-9]*):/', $token, $idMatch) !== 1) {
            return null;
        }

        $reply = data_get($message, 'reply_to_message');

        if (! is_array($reply)
            || data_get($reply, 'from.is_bot') !== true
            || (string) data_get($reply, 'from.id') !== $idMatch[1]
            || data_get($reply, 'chat.id') !== data_get($message, 'chat.id')) {
            return null;
        }

        return trim($text);
    }
}
