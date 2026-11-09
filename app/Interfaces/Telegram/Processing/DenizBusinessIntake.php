<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Settings\DenizBusinessSupportSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram Business update boundary. Phase one never calls AI, sends messages
 * or persists customer conversations. Group routing remains untouched.
 */
final class DenizBusinessIntake
{
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
        // Missing settings, configuration, or Redis must not allow AI work.
        try {
            if (! DenizBusinessSupportSettings::isEnabled()) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $ownerId = $this->trustedOwnerId();
        $connectionId = $message['business_connection_id'] ?? null;
        $text = $message['text'] ?? null;

        if ($ownerId === null || ! $this->validId($connectionId)
            || data_get($message, 'chat.type') !== 'private'
            || ! is_int(data_get($message, 'chat.id'))
            || ! is_int(data_get($message, 'from.id'))
            || data_get($message, 'from.id') < 1
            || data_get($message, 'from.is_bot') !== false
            || data_get($message, 'sender_chat') !== null
            || ! is_int($message['message_id'] ?? null)
            || $message['message_id'] < 1
            || ! is_string($text) || trim($text) === ''
            || mb_strlen($text) > (int) config('denagate_ai.max_input_chars', 2000)) {
            return;
        }

        $verified = Cache::get($this->key($connectionId));

        if (! is_array($verified) || ($verified['owner_id'] ?? null) !== $ownerId
            || ($verified['active'] ?? null) !== true
            || ($verified['can_reply'] ?? null) !== true) {
            return;
        }

        // Intentionally no enqueue, provider request, customer data log, or
        // outbound Telegram send until the separately approved phase two.
        Log::info('Deniz Business message passed intake validation.');
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
            && preg_match('/\A[A-Za-z0-9_-]+\z/D', $id) === 1;
    }

    private function key(string $id): string
    {
        return 'denix:business:connection:'.hash('sha256', $id);
    }
}
