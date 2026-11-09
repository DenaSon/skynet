<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Jobs;

use App\Interfaces\Telegram\Processing\DenixConversationState;
use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use App\Modules\AI\Application\AskAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class AnswerDenixMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 40;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $chatId,
        public readonly int $telegramUserId,
        public readonly int $messageId,
        public readonly ?int $threadId,
        public readonly string $question,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        DenixGroupAccess $access,
        AskAssistant $ask,
        DenixMessageSender $sender,
        DenixConversationState $state,
    ): void {
        // Queue contents never grant access. Recheck the root identity on each run.
        if (! $access->allowed($this->chatId, $this->telegramUserId)) {
            return;
        }

        $cacheName = config('cache.default');
        $cacheDriver = is_string($cacheName) ? config('cache.stores.'.$cacheName.'.driver') : null;

        if (! in_array($cacheDriver, ['redis', 'database'], true)) {
            throw new RuntimeException('Denix shared cache is not configured.');
        }

        $lock = Cache::lock($state->lockKey($this->chatId, $this->threadId, $this->telegramUserId), 35);

        if (! $lock->get()) {
            return;
        }

        try {
            // Read and write conversation state under one per-actor/topic lock.
            $model = $state->model($this->chatId, $this->threadId, $this->telegramUserId);
            $history = $state->history($this->chatId, $this->threadId, $this->telegramUserId);

            // Claim before the billable request; retrying after a timeout or failed
            // Telegram delivery must not purchase or send an additional response.
            if (! Cache::add('denix:attempt:'.$this->chatId.':'.$this->messageId, true, now()->addDays(7))) {
                return;
            }

            $started = hrtime(true);
            $answer = $ask->handle($this->question, $model, $history);

            Log::info('Denix AI response generated.', [
                'model' => $answer->model,
                'input_tokens_reported' => $answer->inputTokens > 0 ? $answer->inputTokens : null,
                'output_tokens_reported' => $answer->outputTokens > 0 ? $answer->outputTokens : null,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);

            if (! $access->allowed($this->chatId, $this->telegramUserId)) {
                return;
            }

            $reply = mb_substr($answer->text, 0, 3500);
            $sender->send($this->chatId, $this->messageId, $this->threadId, $reply);

            // Never retain an assistant turn that was not successfully delivered.
            $state->remember($this->chatId, $this->threadId, $this->telegramUserId, $this->question, $reply);
        } finally {
            $lock->release();
        }
    }
}
