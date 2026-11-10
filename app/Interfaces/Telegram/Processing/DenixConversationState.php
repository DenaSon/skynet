<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Modules\AI\Application\ModelCatalog;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final readonly class DenixConversationState
{
    public function __construct(private ModelCatalog $models) {}

    public function model(int $chatId, ?int $threadId, int $userId): string
    {
        $cached = Cache::get($this->key($chatId, $threadId, $userId, 'model'));
        $model = is_string($cached) && in_array($cached, ModelCatalog::selectable(), true)
            ? $cached
            : $this->models->resolve();

        // Only independently verified GapGPT models may be used by Denix.
        if (! in_array($model, ModelCatalog::selectable(), true)) {
            throw new RuntimeException('Denix model is not approved for group conversations.');
        }

        if ($cached === $model) {
            Cache::put($this->key($chatId, $threadId, $userId, 'model'), $model, $this->expiresAt());
        }

        return $model;
    }

    public function selectModel(int $chatId, ?int $threadId, int $userId, int $number): ?string
    {
        $model = $this->models->selectedByNumber($number);

        if ($model === null) {
            return null;
        }

        Cache::put($this->key($chatId, $threadId, $userId, 'model'), $model, $this->expiresAt());
        Cache::forget($this->key($chatId, $threadId, $userId, 'history'));
        $this->clearExecutiveSelection($chatId, $threadId, $userId);

        return $model;
    }

    public function clearHistory(int $chatId, ?int $threadId, int $userId): void
    {
        Cache::forget($this->key($chatId, $threadId, $userId, 'history'));
        $this->clearExecutiveSelection($chatId, $threadId, $userId);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function history(int $chatId, ?int $threadId, int $userId): array
    {
        $stored = Cache::get($this->key($chatId, $threadId, $userId, 'history'));
        $max = (int) config('denagate_ai.context_max_messages', 30);
        $limit = (int) config('denagate_ai.context_max_chars', 6000);

        if (! is_array($stored) || count($stored) > $max || count($stored) % 2 !== 0) {
            return [];
        }

        $characters = 0;

        $stored = array_values($stored);

        foreach ($stored as $i => $message) {
            if (! is_array($message)
                || ($message['role'] ?? null) !== ($i % 2 === 0 ? 'user' : 'assistant')
                || ! is_string($message['content'] ?? null)
                || ($size = mb_strlen($message['content'])) < 1) {
                return [];
            }

            $characters += $size;
        }

        if ($characters > $limit) {
            return [];
        }

        /** @var list<array{role: string, content: string}> $stored */
        return $stored;
    }

    public function remember(int $chatId, ?int $threadId, int $userId, string $question, string $answer): void
    {
        $messages = [
            ...$this->history($chatId, $threadId, $userId),
            ['role' => 'user', 'content' => $question],
            ['role' => 'assistant', 'content' => $answer],
        ];

        $limit = (int) config('denagate_ai.context_max_chars', 6000);
        $max = (int) config('denagate_ai.context_max_messages', 30);

        while (count($messages) > $max
            || array_sum(array_map(static fn (array $message): int => mb_strlen($message['content']), $messages)) > $limit) {
            array_shift($messages);
            array_shift($messages);
        }

        Cache::put($this->key($chatId, $threadId, $userId, 'history'), $messages, $this->expiresAt());
    }

    /**
     * Keep only the report selector, never financial values, prompts or model output.
     *
     * @return array{dataset:string,period:string}|null
     */
    public function executiveSelection(int $chatId, ?int $threadId, int $userId): ?array
    {
        $stored = Cache::get($this->key($chatId, $threadId, $userId, 'executive'));
        $periods = [
            'sales' => ['24h', 'day', 'week', 'month'],
            'cash' => ['7', '30', 'all'],
            'accounting' => ['7', '30', 'month'],
            'wallet' => ['current'],
            'users' => ['current'],
        ];

        if (! is_array($stored) || ! is_string($stored['dataset'] ?? null)
            || ! is_string($stored['period'] ?? null)
            || ! in_array($stored['period'], $periods[$stored['dataset']] ?? [], true)) {
            return null;
        }

        return ['dataset' => $stored['dataset'], 'period' => $stored['period']];
    }

    public function rememberExecutiveSelection(int $chatId, ?int $threadId, int $userId, string $dataset, string $period): void
    {
        // Only selectors produced by an authorized canonical financial read.
        Cache::put($this->key($chatId, $threadId, $userId, 'executive'),
            ['dataset' => $dataset, 'period' => $period], $this->expiresAt());
    }

    public function clearExecutiveSelection(int $chatId, ?int $threadId, int $userId): void
    {
        Cache::forget($this->key($chatId, $threadId, $userId, 'executive'));
    }

    public function lockKey(int $chatId, ?int $threadId, int $userId): string
    {
        return $this->key($chatId, $threadId, $userId, 'lock');
    }

    private function key(int $chatId, ?int $threadId, int $userId, string $suffix): string
    {
        return 'denix:session:ai:'.$chatId.':'.($threadId ?? 0).':'.$userId.':'.$suffix;
    }

    private function expiresAt(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('denagate_ai.context_ttl_minutes', 30));
    }
}
