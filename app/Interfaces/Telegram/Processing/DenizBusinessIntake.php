<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Interfaces\Telegram\Jobs\AnswerDenizBusinessMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Isolated authenticated Business intake. Never performs billable/Telegram
 * network IO inside webhook handlers; the AI worker checks live permissions.
 */
final class DenizBusinessIntake
{
    public function __construct(private readonly DenizBusinessConnectionGuard $access) {}

    public function handle(Request $request): void
    {
        $connection = $request->input('business_connection');

        if (is_array($connection)) {
            $this->connection($connection);

            return;
        }

        $message = $request->input('business_message');

        if (is_array($message)) {
            $this->message($message);
        }

        // Edits and deletions are intentionally consumed without any response.
    }

    /** @param array<string, mixed> $event */
    private function connection(array $event): void
    {
        $ownerId = $this->trustedOwnerId();
        $connectionId = $event['id'] ?? null;

        if ($ownerId === null || ! $this->validId($connectionId)
            || data_get($event, 'user.id') !== $ownerId
            || data_get($event, 'user.is_bot') !== false
            || ! is_bool($event['is_enabled'] ?? null)) {
            return;
        }

        $enabled = $event['is_enabled'];
        $canReply = data_get($event, 'rights.can_reply') === true;
        $key = $this->key($connectionId);

        if (! $enabled || ! $canReply) {
            Cache::forget($key);
        } else {
            // Short-lived and keyed by the exact Telegram connection identity.
            // Phase two will revalidate live rights before any customer reply.
            Cache::put($key, ['owner_id' => $ownerId, 'active' => true, 'can_reply' => true], now()->addDays(7));
        }

        Log::info('Deniz Business connection validated.', [
            'active' => $enabled,
            'can_reply' => $canReply,
        ]);
    }

    /** @param array<string, mixed> $message */
    private function message(array $message): void
    {
        // The deployment gate, operator switch and global AI switch must all
        // be ON before accepting a billable customer request.
        if (! $this->access->enabled()) {
            return;
        }

        $ownerId = $this->trustedOwnerId();
        $connectionId = $message['business_connection_id'] ?? null;
        $chatId = data_get($message, 'chat.id');
        $fromId = data_get($message, 'from.id');
        $messageId = $message['message_id'] ?? null;
        $date = $message['date'] ?? null;
        $text = $message['text'] ?? null;

        if ($ownerId === null || ! $this->validId($connectionId)
            || data_get($message, 'chat.type') !== 'private'
            || ! is_int($chatId) || $chatId < 1
            || ! is_int($fromId) || $fromId < 1
            || data_get($message, 'from.is_bot') !== false
            || data_get($message, 'sender_business_bot') !== null
            || data_get($message, 'sender_chat') !== null
            || ! is_int($messageId) || $messageId < 1
            || ! is_int($date) || $date < now()->timestamp - 86400
            || $date > now()->timestamp + 60) {
            return;
        }

        $connectionKey = hash('sha256', $connectionId);
        $handoffKey = 'denix:business:handoff:'.$connectionKey.':'.$chatId;

        if ($fromId === $ownerId) {
            // A human agent has answered from the connected Business account.
            // Suppress all subsequent AI replies in that conversation for 24h.
            if ($chatId !== $ownerId) {
                Cache::put($handoffKey, true, now()->addDay());
            }

            return;
        }

        if ($fromId !== $chatId || ! is_string($text) || trim($text) === ''
            || mb_strlen($text) > (int) config('denagate_ai.max_input_chars', 2000)
            || Cache::has($handoffKey) && trim($text) !== 'شروع دنیز') {
            return;
        }

        $cacheName = config('cache.default');
        $cacheDriver = is_string($cacheName) ? config('cache.stores.'.$cacheName.'.driver') : null;
        $queueName = config('queue.default');
        $queueDriver = is_string($queueName) ? config('queue.connections.'.$queueName.'.driver') : null;
        $retryAfter = is_string($queueName) ? config('queue.connections.'.$queueName.'.retry_after') : null;

        if (! in_array($cacheDriver, ['redis', 'database'], true)
            || ! in_array($queueDriver, ['redis', 'database'], true)
            || ! is_numeric($retryAfter) || (int) $retryAfter <= 45) {
            throw new RuntimeException('Deniz Business async queue/cache is not configured.');
        }

        // Webhook receipts protect update_id; this independent seven-day
        // fence protects against the same message arriving under another update.
        $fence = 'denix:business:message:'.$connectionKey.':'.$chatId.':'.$messageId;

        if (! Cache::add($fence, true, now()->addDays(7))) {
            return;
        }

        $kind = match (trim($text)) {
            'پشتیبان انسانی', 'اپراتور', 'صحبت با پشتیبان' => 'handoff',
            'شروع دنیز' => 'resume',
            default => 'answer',
        };
        $retrySeconds = 0;

        if ($kind === 'answer') {
            $userKey = 'denix:business:quota:user:'.$connectionKey.':'.$chatId;
            $dailyKey = 'denix:business:quota:day:'.$connectionKey.':'.$chatId;
            $globalKey = 'denix:business:quota:global';

            $limitedKey = match (true) {
                RateLimiter::tooManyAttempts($userKey, 5) => $userKey,
                RateLimiter::tooManyAttempts($dailyKey, 20) => $dailyKey,
                RateLimiter::tooManyAttempts($globalKey, 50) => $globalKey,
                default => null,
            };

            if ($limitedKey !== null) {
                $noticeKey = 'denix:business:quota:notice:'.$connectionKey.':'.$chatId;

                if (RateLimiter::tooManyAttempts($noticeKey, 1)) {
                    return;
                }

                $kind = 'quota';
                $retrySeconds = RateLimiter::availableIn($limitedKey);
                RateLimiter::hit($noticeKey, 3600);
            } else {
                RateLimiter::hit($userKey, 600);
                RateLimiter::hit($dailyKey, 86400);
                RateLimiter::hit($globalKey, 86400);
            }
        }

        // Missing connection update cache isn't authority to deny forever:
        // getBusinessConnection at execution is the authoritative live check.
        AnswerDenizBusinessMessage::dispatch(
            $connectionId, $chatId, $fromId, $messageId, $date, trim($text), $kind, $retrySeconds,
        );
    }

    private function trustedOwnerId(): ?int
    {
        $id = filter_var(config('telegram.denix.support_business_user_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($id) ? $id : null;
    }

    private function validId(mixed $id): bool
    {
        return is_string($id) && $id !== '' && strlen($id) <= 256
            && trim($id) === $id;
    }

    private function key(string $id): string
    {
        return 'denix:business:connection:'.hash('sha256', $id);
    }
}
