<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Jobs;

use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class SendDenixQuotaNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly int $chatId,
        public readonly int $telegramUserId,
        public readonly int $messageId,
        public readonly ?int $threadId,
        public readonly string $quota,
        public readonly int $remainingSeconds,
    ) {
        $this->onQueue('ai');
    }

    public function handle(DenixGroupAccess $access, DenixMessageSender $sender): void
    {
        // A queued notice never bypasses the same group/role/kill-switch checks.
        if (! $access->allowed($this->chatId, $this->telegramUserId)
            || ! in_array($this->quota, ['user', 'group', 'control'], true)) {
            return;
        }

        $cacheStore = config('cache.default');
        $driver = is_string($cacheStore) ? config('cache.stores.'.$cacheStore.'.driver') : null;

        if (! in_array($driver, ['redis', 'database'], true)) {
            throw new RuntimeException('Deniz shared cache is not configured.');
        }

        // Never resend a notice after an ambiguous Telegram delivery, even on manual job replay.
        if (! Cache::add('denix:quota-notice:'.$this->chatId.':'.$this->messageId, true, now()->addDays(7))) {
            return;
        }

        $remainingMinutes = max(1, (int) ceil(max(0, $this->remainingSeconds) / 60));
        $hours = intdiv($remainingMinutes, 60);
        $minutes = $remainingMinutes % 60;

        $time = $hours === 0
            ? "{$remainingMinutes} دقیقه"
            : ($minutes === 0 ? "{$hours} ساعت" : "{$hours} ساعت و {$minutes} دقیقه");

        $text = match ($this->quota) {
            'group' => "⏳ سهمیه گفتگوی گروه فعلاً تکمیل شده. حدود {$time} دیگر دوباره فعال می‌شود.",
            'control' => "⏳ تعداد درخواست‌های کنترلی شما زیاد شده. حدود {$time} دیگر دوباره تلاش کن.",
            default => "⏳ سهمیه گفتگوی شما فعلاً تکمیل شده. حدود {$time} دیگر می‌تونی دوباره سؤال بپرسی.",
        };

        // No model request, conversation-state write, or retry of ambiguous sends.
        $sender->send($this->chatId, $this->messageId, $this->threadId, $text);
    }
}
