<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Jobs;

use App\Interfaces\Telegram\Processing\DenixConversationState;
use App\Interfaces\Telegram\Processing\DenizBusinessConnectionGuard;
use App\Interfaces\Telegram\Processing\DenizBusinessMessageSender;
use App\Modules\AI\Application\AskAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class AnswerDenizBusinessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 40;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $connectionId,
        public readonly int $chatId,
        public readonly int $customerId,
        public readonly int $messageId,
        public readonly int $messageDate,
        public readonly string $question,
        public readonly string $kind = 'answer',
        public readonly int $retrySeconds = 0,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        DenizBusinessConnectionGuard $access,
        DenizBusinessMessageSender $sender,
        DenixConversationState $state,
        AskAssistant $ask,
    ): void {
        if (! $access->enabled() || ! $this->withinReplyWindow()
            || ! in_array($this->kind, ['answer', 'quota', 'handoff', 'resume'], true)) {
            return;
        }

        $cacheName = config('cache.default');
        $cacheDriver = is_string($cacheName) ? config('cache.stores.'.$cacheName.'.driver') : null;

        if (! in_array($cacheDriver, ['redis', 'database'], true)) {
            throw new RuntimeException('Deniz Business shared cache is not configured.');
        }

        $session = hash('sha256', $this->connectionId);
        $pauseKey = 'denix:business:handoff:'.$session.':'.$this->chatId;
        $lock = Cache::lock('denix:business:lock:'.$session.':'.$this->chatId, 39);

        if (! $lock->get()) {
            return;
        }

        try {
            if ($this->kind !== 'resume' && Cache::has($pauseKey)) {
                return;
            }

            $attempt = 'denix:business:attempt:'.$session.':'.$this->chatId.':'.$this->messageId;

            // A replay needs no Telegram lookup, AI call, or second delivery.
            if (Cache::has($attempt)) {
                return;
            }

            // Connection/owner/rights are fetched from Telegram on the worker.
            // Incoming business_connection payloads never authorize a paid action.
            if (! $access->allowed($this->connectionId)) {
                return;
            }

            if (! Cache::add($attempt, true, now()->addDays(7))) {
                return;
            }

            if ($this->kind !== 'answer') {
                $text = match ($this->kind) {
                    'quota' => '⏳ سهمیه گفتگوی هوشمند فعلاً تکمیل شده است. حدود '
                        .max(1, (int) ceil($this->retrySeconds / 60)).' دقیقه دیگر دوباره تلاش کنید.',
                    'handoff' => 'حتماً 🌿 برای گفتگو با پشتیبان انسانی، پیام خود را همین‌جا بفرستید. '
                        .'دنیز تا ۲۴ ساعت در این گفتگو پاسخ خودکار نمی‌دهد. '
                        .'برای بازگشت، «شروع دنیز» را ارسال کنید.',
                    'resume' => '🌿 پاسخ‌گویی هوشمند دنیز دوباره برای این گفتگو فعال شد.',
                };

                if ($this->kind === 'resume') {
                    Cache::forget($pauseKey);
                }

                if ($access->enabled() && $this->withinReplyWindow() && $access->allowed($this->connectionId)) {
                    $sender->send($this->connectionId, $this->chatId, $this->messageId, $text);

                    if ($this->kind === 'handoff') {
                        Cache::put($pauseKey, true, now()->addDay());
                    }
                }

                return;
            }

            // Positive private chat IDs are disjoint from the negative admin group.
            // The same bounded per-chat/user memory code is reused, with no SQL history.
            $model = $state->model($this->chatId, null, $this->customerId);
            $history = $state->history($this->chatId, null, $this->customerId);
            $firstTurn = $history === [];
            $started = hrtime(true);

            try {
                $answer = $ask->handle($this->question, $model, $history);
            } catch (RuntimeException) {
                if (! Cache::has($pauseKey) && $access->enabled()
                    && $this->withinReplyWindow() && $access->allowed($this->connectionId)) {
                    $sender->send(
                        $this->connectionId,
                        $this->chatId,
                        $this->messageId,
                        '🌿 متأسفم، فعلاً در آماده‌کردن پاسخ مشکلی پیش اومده. لطفاً کمی بعد دوباره پیام بدهید.',
                    );
                }

                return;
            }

            Log::info('Deniz Business AI response generated.', [
                'model' => $answer->model,
                'input_tokens_reported' => $answer->inputTokens > 0 ? $answer->inputTokens : null,
                'output_tokens_reported' => $answer->outputTokens > 0 ? $answer->outputTokens : null,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);

            // A switch OFF, human response, Telegram disconnect or expired window
            // must veto a paid answer even if inference was already completed.
            if (Cache::has($pauseKey) || ! $access->enabled() || ! $this->withinReplyWindow()
                || ! $access->allowed($this->connectionId)) {
                return;
            }

            $reply = ($firstTurn ? "سلام، من دنیز، دستیار هوش مصنوعی دناگیت هستم. 🌿\n\n" : '').$answer->text;
            $reply = mb_substr($reply, 0, 3500);
            $sender->send($this->connectionId, $this->chatId, $this->messageId, $reply);

            // A failed/uncertain Telegram send must not pollute conversational memory.
            $state->remember($this->chatId, null, $this->customerId, $this->question, $reply);
        } finally {
            $lock->release();
        }
    }

    /** @phpstan-impure */
    private function withinReplyWindow(): bool
    {
        $now = now()->timestamp;

        return $this->messageDate > 0
            && $this->messageDate >= $now - 24 * 3600
            && $this->messageDate <= $now + 60;
    }
}
