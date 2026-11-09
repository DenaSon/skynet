<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Jobs;

use App\Interfaces\Telegram\Processing\DenixConversationState;
use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use App\Modules\AI\Application\ModelCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class HandleDenixCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(
        public readonly int $chatId,
        public readonly int $telegramUserId,
        public readonly int $messageId,
        public readonly ?int $threadId,
        public readonly string $command,
        public readonly ?int $choice,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        DenixGroupAccess $access,
        DenixConversationState $state,
        DenixMessageSender $sender,
    ): void {
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
            $model = $state->model($this->chatId, $this->threadId, $this->telegramUserId);

            $text = match ($this->command) {
                'aimodel' => $this->modelMessage($state, $model),
                'ainew' => $this->resetMessage($state),
                'aistatus' => $this->statusMessage($model),
                default => null,
            };

            if ($text !== null && $access->allowed($this->chatId, $this->telegramUserId)) {
                $sender->send($this->chatId, $this->messageId, $this->threadId, $text);
            }
        } finally {
            $lock->release();
        }
    }

    private function modelMessage(DenixConversationState $state, string $current): string
    {
        if ($this->choice !== null) {
            $model = $state->selectModel($this->chatId, $this->threadId, $this->telegramUserId, $this->choice);

            if ($model !== null) {
                return "✅ مدل دنیکس تغییر کرد: {$model}\nحافظه گفتگوی قبلی پاک شد.";
            }
        }

        $options = [];
        foreach (ModelCatalog::SELECTABLE as $number => $model) {
            $options[] = ($number + 1).'. '.$model;
        }

        return "🤖 مدل فعلی: {$current}\n\n".implode("\n", $options)."\n\nبرای انتخاب: /aimodel 1 یا /aimodel 2";
    }

    private function resetMessage(DenixConversationState $state): string
    {
        $state->clearHistory($this->chatId, $this->threadId, $this->telegramUserId);

        return '✅ حافظه گفتگوی شما در این بخش پاک شد. مدل انتخابی تغییر نکرد.';
    }

    private function statusMessage(string $model): string
    {
        $input = (int) config('denagate_ai.max_input_chars', 2000);
        $messages = (int) config('denagate_ai.context_max_messages', 6);
        $minutes = (int) config('denagate_ai.context_ttl_minutes', 30);
        $perUser = (int) config('denagate_ai.user_requests_per_10_minutes', 5);
        $perGroup = (int) config('denagate_ai.group_requests_per_day', 50);

        return "🤖 وضعیت دنیکس\nمدل: {$model}\nحافظه: {$messages} پیام، {$minutes} دقیقه\nحداکثر سؤال: {$input} نویسه"
            ."\nسقف درخواست: {$perUser} درخواست در ۱۰ دقیقه برای هر مدیر، {$perGroup} درخواست روزانه در گروه."
            ."\n\nبرای تغییر مدل: /aimodel\nبرای شروع گفتگوی جدید: /ainew";
    }
}
