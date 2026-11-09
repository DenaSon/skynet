<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Jobs;

use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use App\Modules\AI\Application\AskAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    public function handle(DenixGroupAccess $access, AskAssistant $ask, DenixMessageSender $sender): void
    {
        // A queued message is not a grant. Identity, role, group and kill switches
        // are rechecked when the worker finally starts.
        if (! $access->allowed($this->chatId, $this->telegramUserId)) {
            return;
        }

        // This durable cache fence is claimed before the billable request, not
        // after the Telegram send. Duplicate/manual retries never buy a second call.
        if (! Cache::add('denix:attempt:'.$this->chatId.':'.$this->messageId, true, now()->addDays(7))) {
            return;
        }

        $answer = $ask->handle($this->question);

        if (! $access->allowed($this->chatId, $this->telegramUserId)) {
            return;
        }

        $sender->send($this->chatId, $this->messageId, $this->threadId, $answer->text);
    }
}
