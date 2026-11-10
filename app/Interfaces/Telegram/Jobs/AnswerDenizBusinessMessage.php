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
use Throwable;

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
        public readonly ?string $traceId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        DenizBusinessConnectionGuard $access,
        DenizBusinessMessageSender $sender,
        DenixConversationState $state,
        AskAssistant $ask,
    ): void {
        $trace = $this->traceId ?? bin2hex(random_bytes(8));

        if (! $access->enabled()) {
            $this->logBlocked('business_disabled');
            $this->logOutcome($trace, 'business_disabled');

            return;
        }

        if (! $this->withinReplyWindow()) {
            $this->logOutcome($trace, 'reply_window_expired');

            return;
        }

        if (! in_array($this->kind, ['answer', 'quota', 'capacity', 'handoff', 'resume'], true)) {
            $this->logOutcome($trace, 'unsupported_kind');

            return;
        }

        $cacheName = config('cache.default');
        $cacheDriver = is_string($cacheName) ? config('cache.stores.'.$cacheName.'.driver') : null;

        if (! in_array($cacheDriver, ['redis', 'database'], true)) {
            $this->logOutcome($trace, 'shared_cache_invalid');

            throw new RuntimeException('Deniz Business shared cache is not configured.');
        }

        $session = hash('sha256', $this->connectionId);
        $pauseKey = 'denix:business:handoff:'.$session.':'.$this->chatId;
        $lock = Cache::lock('denix:business:lock:'.$session.':'.$this->chatId, 39);

        if (! $lock->get()) {
            $this->logOutcome($trace, 'conversation_lock_busy');

            return;
        }

        try {
            if ($this->kind !== 'resume' && Cache::has($pauseKey)) {
                $this->logOutcome($trace, 'human_handoff_active');

                return;
            }

            $attempt = 'denix:business:attempt:'.$session.':'.$this->chatId.':'.$this->messageId;

            // A replay needs no Telegram lookup, AI call, or second delivery.
            if (Cache::has($attempt)) {
                $this->logOutcome($trace, 'duplicate_attempt');

                return;
            }

            // Connection/owner/rights are fetched from Telegram on the worker.
            // Incoming business_connection payloads never authorize a paid action.
            if (! $access->allowed($this->connectionId)) {
                $this->logBlocked('connection_denied');
                $this->logOutcome($trace, 'connection_denied');

                return;
            }

            if (! Cache::add($attempt, true, now()->addDays(7))) {
                $this->logOutcome($trace, 'duplicate_attempt');

                return;
            }

            if ($this->kind !== 'answer') {
                $remainingMinutes = max(1, (int) ceil(max(0, $this->retrySeconds) / 60));
                $hours = intdiv($remainingMinutes, 60);
                $minutes = $remainingMinutes % 60;
                $time = $hours === 0
                    ? "{$remainingMinutes} دقیقه"
                    : ($minutes === 0 ? "{$hours} ساعت" : "{$hours} ساعت و {$minutes} دقیقه");

                $text = match ($this->kind) {
                    'capacity' => "⏳ ظرفیت پاسخ‌گویی هوشمند دنیز موقتاً تکمیل شده است.\n\n"
                        ."این محدودیت مربوط به سهمیه شخصی شما نیست. حدود {$time} دیگر دوباره تلاش کنید.\n\n"
                        .'برای دریافت راهنمایی از پشتیبانی انسانی، «پشتیبان انسانی» را ارسال کنید.',
                    'quota' => "⏳ تعداد پیام‌های مجاز در این بازه زمانی تکمیل شده است.\n\n"
                        ."امکان ادامه گفت‌وگو حدود {$time} دیگر فعال می‌شود.\n\n"
                        .'از همراهی شما سپاسگزاریم.',
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

                    $this->logOutcome($trace, $this->kind.'_sent');
                } else {
                    $this->logOutcome($trace, 'notice_delivery_not_authorized');
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
                $answer = $ask->handle($this->question, $model, $history, customerSupport: true);
            } catch (RuntimeException) {
                if (! Cache::has($pauseKey) && $access->enabled()
                    && $this->withinReplyWindow() && $access->allowed($this->connectionId)) {
                    $sender->send(
                        $this->connectionId,
                        $this->chatId,
                        $this->messageId,
                        '🌿 متأسفم، فعلاً در آماده‌کردن پاسخ مشکلی پیش اومده. لطفاً کمی بعد دوباره پیام بدهید.',
                    );
                    $this->logOutcome($trace, 'fallback_sent');
                } else {
                    $this->logOutcome($trace, 'fallback_not_authorized');
                }

                return;
            }

            Log::info('Deniz Business AI response generated.', [
                'trace' => $trace,
                'model' => $answer->model,
                'input_tokens_reported' => $answer->inputTokens > 0 ? $answer->inputTokens : null,
                'output_tokens_reported' => $answer->outputTokens > 0 ? $answer->outputTokens : null,
                'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);

            // A switch OFF, human response, Telegram disconnect or expired window
            // must veto a paid answer even if inference was already completed.
            if (Cache::has($pauseKey) || ! $access->enabled() || ! $this->withinReplyWindow()
                || ! $access->allowed($this->connectionId)) {
                $this->logOutcome($trace, 'post_inference_guard_changed');

                return;
            }

            $replyText = $answer->text;

            if ($firstTurn) {
                // The first reply has a mandatory AI disclosure. If the model
                // also starts with a greeting, show "سلام" only once.
                $replyText = trim(preg_replace(
                    '/^\s*سلام(?![\p{L}\p{N}])[\s،,؛.!؟:]*/u',
                    '',
                    $replyText,
                    1,
                ) ?? $replyText);
            }

            $reply = $firstTurn
                ? 'سلام، من دنیز، دستیار هوش مصنوعی دناگیت هستم. 🌿'
                    .($replyText !== '' ? "\n\n".$replyText : '')
                : $replyText;

            $reply = mb_substr($reply, 0, 3500);
            $sender->send($this->connectionId, $this->chatId, $this->messageId, $reply);

            // A failed/uncertain Telegram send must not pollute conversational memory.
            $state->remember($this->chatId, null, $this->customerId, $this->question, $reply);
            $this->logOutcome($trace, 'answer_sent');
        } catch (Throwable $error) {
            // Preserve one-shot semantics; record only safe diagnostic metadata.
            Log::warning('Deniz Business worker failed.', [
                'trace' => $trace,
                'kind' => $this->kind,
                'exception_type' => $error::class,
            ]);

            throw $error;
        } finally {
            $lock->release();
        }
    }

    private function logOutcome(string $trace, string $reason): void
    {
        Log::info('Deniz Business worker outcome.', [
            'trace' => $trace,
            'kind' => $this->kind,
            'reason' => $reason,
        ]);
    }

    private function logBlocked(string $reason): void
    {
        if (Cache::add('denix:business:ops:blocked:'.$reason, true, now()->addMinutes(10))) {
            // No personal data, Telegram connection IDs, question text or secrets.
            Log::warning('Deniz Business answer suppressed.', ['reason' => $reason]);
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
