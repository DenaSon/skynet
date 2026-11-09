<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use App\Interfaces\Telegram\Jobs\HandleDenixCommand;
use App\Interfaces\Telegram\Jobs\SendDenixQuotaNotice;
use App\Interfaces\Telegram\Processing\DenixConversationState;
use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'database');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    config()->set('denagate_ai.enabled', true);
    config()->set('telegram.denix.enabled', true);
    config()->set('telegram.denix.group_chat_id', -100987654321);
    config()->set('telegram.denix.token', '100003:denix-test-token');
    config()->set('telegram.denix.webhook_secret', 'denix-test-secret');
    config()->set('telegram.denix.bot_username', 'denixobot');
    config()->set('telegram.webhook_secret', 'admin-test-secret');
    config()->set('telegram.sales.webhook_secret', 'sales-test-secret');
    config()->set('nutgram.token', '100001:admin-token');
    config()->set('telegram.sales.token', '100002:sales-token');
    config()->set('super_admin.telegram_ids', ['180001', '180002']);
    Role::findOrCreate('super-admin', 'web');

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 180001]);

    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();
});

function denixCMessage(int $messageId, string $text, int $sender = 180001, int $chat = -100987654321, ?int $thread = 12): array
{
    $message = [
        'message_id' => $messageId,
        'date' => 1794219000,
        'chat' => ['id' => $chat, 'type' => 'supergroup', 'title' => 'DenaGate Admin'],
        'from' => ['id' => $sender, 'is_bot' => false, 'first_name' => 'Operator'],
        'text' => $text,
    ];

    if ($thread !== null) {
        $message['message_thread_id'] = $thread;
    }

    return ['update_id' => $messageId, 'message' => $message];
}

function denixCPost(TestCase $test, array $payload): void
{
    $test->postJson('/api/telegram/ai/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'denix-test-secret',
    ])->assertNoContent();
}

function denixCRun(HandleDenixCommand $job): void
{
    $job->handle(
        app(DenixGroupAccess::class),
        app(DenixConversationState::class),
        app(DenixMessageSender::class),
    );
}

it('routes numbered model choices and status/reset commands only to the AI queue', function (): void {
    denixCPost($this, denixCMessage(10001, '/aimodel'));
    denixCPost($this, denixCMessage(10002, '/aimodel 2'));
    denixCPost($this, denixCMessage(10003, '/aistatus'));
    denixCPost($this, denixCMessage(10004, '/ainew'));
    denixCPost($this, denixCMessage(10004, '/ainew'));

    Queue::assertPushed(HandleDenixCommand::class, 4);
    Queue::assertNotPushed(AnswerDenixMessage::class);
    Queue::assertPushed(HandleDenixCommand::class, fn (HandleDenixCommand $job): bool => $job->queue === 'ai' && $job->chatId === -100987654321 && $job->threadId === 12
        && $job->command === 'aimodel' && $job->choice === 2);
    Http::assertNothingSent();
});

it('chooses a live-verified model, clears only that context and displays safe status', function (): void {
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    $state = app(DenixConversationState::class);
    $state->remember(-100987654321, 12, 180001, 'سؤال', 'پاسخ');

    denixCRun(new HandleDenixCommand(-100987654321, 180001, 10100, 12, 'aimodel', 2));

    expect($state->model(-100987654321, 12, 180001))->toBe('gapgpt-deepseek-v4.1-flash')
        ->and($state->history(-100987654321, 12, 180001))->toBeEmpty();

    $state->remember(-100987654321, 12, 180001, 'جدید', 'جواب');
    denixCRun(new HandleDenixCommand(-100987654321, 180001, 10101, 12, 'aistatus', null));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request['text'], 'gapgpt-deepseek-v4.1-flash')
        && str_contains($request['text'], '30 دقیقه')
        && ! str_contains($request['text'], 'denix-test-secret')
        && ! str_contains($request['text'], '100987654321'));

    denixCRun(new HandleDenixCommand(-100987654321, 180001, 10102, 12, 'ainew', null));
    expect($state->history(-100987654321, 12, 180001))->toBeEmpty()
        ->and($state->model(-100987654321, 12, 180001))->toBe('gapgpt-deepseek-v4.1-flash');

    Http::assertSentCount(3);
});

it('never selects a raw unverified model name or a nonexistent number', function (): void {
    denixCPost($this, denixCMessage(10201, '/aimodel gpt-6.1-sol'));
    denixCPost($this, denixCMessage(10202, '/aimodel 9'));
    denixCPost($this, denixCMessage(10203, '/aimodel@anotherbot 2'));

    Queue::assertPushed(HandleDenixCommand::class, 2);
    Queue::assertPushed(HandleDenixCommand::class, fn (HandleDenixCommand $job): bool => $job->command === 'aimodel' && $job->choice === 0);
    Queue::assertNotPushed(AnswerDenixMessage::class);
    expect(app(DenixConversationState::class)->model(-100987654321, 12, 180001))->toBe('gpt-6-luna');
});

it('does not allow a non-root, forged group, or revoked root to mutate session state', function (): void {
    $visitor = User::factory()->create(['is_active' => true]);
    TelegramAccount::query()->create(['user_id' => $visitor->id, 'telegram_user_id' => 180003]);

    denixCPost($this, denixCMessage(10301, '/aimodel 2', 180003));
    denixCPost($this, denixCMessage(10302, '/aimodel 2', 180001, -100111222333));
    Queue::assertNothingPushed();

    $user = TelegramAccount::query()->where('telegram_user_id', 180001)->firstOrFail()->user;
    $user->removeRole('super-admin');
    denixCRun(new HandleDenixCommand(-100987654321, 180001, 10303, 12, 'aimodel', 2));

    expect(app(DenixConversationState::class)->model(-100987654321, 12, 180001))->toBe('gpt-6-luna');
    Http::assertNothingSent();
});

it('keeps control commands available without consuming the five paid requests quota', function (): void {
    foreach (range(1, 6) as $number) {
        denixCPost($this, denixCMessage(10400 + $number, '/ai سؤال '.$number));
    }
    denixCPost($this, denixCMessage(10420, '/aistatus'));

    Queue::assertPushed(AnswerDenixMessage::class, 5);
    Queue::assertPushed(HandleDenixCommand::class, 1);
    Queue::assertPushed(SendDenixQuotaNotice::class, 1);
    Http::assertNothingSent();
});

it('keeps every Denix command fail-closed when the global AI switch is disabled', function (): void {
    config()->set('denagate_ai.enabled', false);

    $this->postJson('/api/telegram/ai/webhook', denixCMessage(10501, '/aistatus'), [
        'X-Telegram-Bot-Api-Secret-Token' => 'denix-test-secret',
    ])->assertServiceUnavailable();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('queues a free, timed reply when a manager exceeds the paid conversation quota', function (): void {
    $key = 'denix:user:180001';

    foreach (range(1, 5) as $unused) {
        RateLimiter::hit($key, 600);
    }

    denixCPost($this, denixCMessage(10601, '/ai@denixobot سلام'));

    Queue::assertNotPushed(AnswerDenixMessage::class);
    Queue::assertPushed(SendDenixQuotaNotice::class, fn (SendDenixQuotaNotice $job): bool => $job->queue === 'ai'
        && $job->tries === 1
        && $job->quota === 'user'
        && $job->threadId === 12
        && $job->messageId === 10601
        && $job->remainingSeconds > 0
        && $job->remainingSeconds <= 600);
    Http::assertNothingSent();

    $notice = Queue::pushed(SendDenixQuotaNotice::class)->first();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

    $notice->handle(app(DenixGroupAccess::class), app(DenixMessageSender::class));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === -100987654321
        && $request['message_thread_id'] === 12
        && str_contains($request['text'], 'سهمیه گفتگوی شما')
        && str_contains($request['text'], 'دقیقه')
        && json_decode($request['reply_parameters'], true)['message_id'] === 10601);

    // A retried Telegram update must not create a second notice or AI request.
    denixCPost($this, denixCMessage(10601, '/ai@denixobot سلام'));
    Queue::assertPushed(SendDenixQuotaNotice::class, 1);
    Queue::assertNotPushed(AnswerDenixMessage::class);
});

it('explains the daily group quota independently of the manager quota', function (): void {
    for ($i = 0; $i < 50; $i++) {
        RateLimiter::hit('denix:group:-100987654321', 86400);
    }

    denixCPost($this, denixCMessage(10602, '/ai سلام'));

    Queue::assertNotPushed(AnswerDenixMessage::class);
    Queue::assertPushed(SendDenixQuotaNotice::class, fn (SendDenixQuotaNotice $job): bool => $job->quota === 'group'
        && $job->remainingSeconds > 0 && $job->remainingSeconds <= 86400);

    $notice = Queue::pushed(SendDenixQuotaNotice::class)->first();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    $notice->handle(app(DenixGroupAccess::class), app(DenixMessageSender::class));

    Http::assertSent(fn (Request $request): bool => str_contains($request['text'], 'سهمیه گفتگوی گروه')
        && str_contains($request['text'], 'ساعت'));
    Http::assertSentCount(1);
});

it('responds briefly to exhausted control-command quota without using GapGPT', function (): void {
    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('denix:control:180001', 60);
    }

    denixCPost($this, denixCMessage(10603, '/aistatus'));

    Queue::assertNotPushed(AnswerDenixMessage::class);
    Queue::assertNotPushed(HandleDenixCommand::class);
    Queue::assertPushed(SendDenixQuotaNotice::class, fn (SendDenixQuotaNotice $job): bool => $job->quota === 'control'
        && $job->remainingSeconds > 0 && $job->remainingSeconds <= 60);
});

it('never sends a pending cooldown notice after group access is revoked', function (): void {
    Http::fake();
    $user = TelegramAccount::query()->where('telegram_user_id', 180001)->firstOrFail()->user;
    $user->removeRole('super-admin');

    $job = new SendDenixQuotaNotice(-100987654321, 180001, 10604, 12, 'user', 80);
    $job->handle(app(DenixGroupAccess::class), app(DenixMessageSender::class));

    Http::assertNothingSent();
});
