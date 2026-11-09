<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use App\Interfaces\Telegram\Processing\ClaimState;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Processing\TelegramUpdateReceipts;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.webhook_secret', 'admin-only-secret');
    config()->set('nutgram.token', '100001:admin-test-token');
    config()->set('telegram.sales.token', '100002:sales-test-token');
    config()->set('telegram.sales.webhook_secret', 'sales-only-secret');
    config()->set('telegram.denix.token', '100003:denix-test-token');
    config()->set('telegram.denix.webhook_secret', 'denix-only-secret');
    config()->set('telegram.denix.bot_username', 'denixobot');
    config()->set('telegram.denix.group_chat_id', -100987654321);
    config()->set('telegram.denix.enabled', true);
    config()->set('denagate_ai.enabled', true);
    config()->set('cache.default', 'database');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    config()->set('super_admin.telegram_ids', ['150001', '150002']);

    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    Cache::flush();
});

function denixHeaders(): array
{
    return ['X-Telegram-Bot-Api-Secret-Token' => 'denix-only-secret'];
}

function denixMessage(int $updateId, int $userId = 150001, string $text = '/ai سلام', int $chatId = -100987654321): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'date' => 1794219000,
            'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'DenaGate Operations'],
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Manager'],
            'text' => $text,
        ],
    ];
}

function denixTrustedRoot(int $telegramId = 150001): User
{
    Role::findOrCreate('super-admin', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $user->getKey(), 'telegram_user_id' => $telegramId]);

    return $user;
}

it('keeps Denix disabled without any credentials, receipt, or queue activity', function (): void {
    config()->set('telegram.denix.enabled', false);

    $this->postJson('/api/telegram/ai/webhook', denixMessage(9001), denixHeaders())->assertServiceUnavailable();

    config()->set('telegram.denix.enabled', true);
    config()->set('denagate_ai.enabled', false);
    $this->postJson('/api/telegram/ai/webhook', denixMessage(9002), denixHeaders())->assertServiceUnavailable();

    expect(DB::table('telegram_updates')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('does not accept missing, forged, or cross-bot secrets and credentials', function (): void {
    $message = denixMessage(9003);
    $this->postJson('/api/telegram/ai/webhook', $message)->assertForbidden();
    $this->postJson('/api/telegram/ai/webhook', $message, ['X-Telegram-Bot-Api-Secret-Token' => 'admin-only-secret'])->assertForbidden();
    $this->postJson('/api/telegram/ai/webhook', $message, ['X-Telegram-Bot-Api-Secret-Token' => 'sales-only-secret'])->assertForbidden();

    config()->set('telegram.denix.token', config('nutgram.token'));
    $this->postJson('/api/telegram/ai/webhook', $message, denixHeaders())->assertServiceUnavailable();

    config()->set('telegram.denix.token', '100003:denix-test-token');
    config()->set('telegram.denix.webhook_secret', config('telegram.sales.webhook_secret'));
    $this->postJson('/api/telegram/ai/webhook', $message, denixHeaders())->assertServiceUnavailable();

    expect(DB::table('telegram_updates')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts only a valid trusted Super Admin command in the configured group', function (): void {
    denixTrustedRoot();

    $payload = denixMessage(9004, text: '/ai سلام دنیکس');
    $this->postJson('/api/telegram/ai/webhook', $payload, denixHeaders())->assertNoContent();
    $this->postJson('/api/telegram/ai/webhook', $payload, denixHeaders())->assertNoContent();

    Queue::assertPushed(AnswerDenixMessage::class, 1);
    Queue::assertPushed(AnswerDenixMessage::class, fn (AnswerDenixMessage $job): bool => $job->queue === 'ai'
        && $job->chatId === -100987654321
        && $job->telegramUserId === 150001
        && $job->messageId === 9004
        && $job->question === 'سلام دنیکس'
        && $job->tries === 1
        && $job->timeout === 40);

    expect(DB::table('telegram_updates')->where('bot_key', 'ai')->where('update_id', 9004)->value('status'))->toBe('processed')
        ->and(DB::table('telegram_bot_contacts')->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts a command explicitly addressed to denixobot rather than other Telegram bots', function (): void {
    denixTrustedRoot();

    $this->postJson('/api/telegram/ai/webhook', denixMessage(9006, text: '/ai@denixobot یک سؤال کوتاه'), denixHeaders())->assertNoContent();

    Queue::assertPushed(AnswerDenixMessage::class, 1);
    Queue::assertPushed(AnswerDenixMessage::class, fn (AnswerDenixMessage $job): bool => $job->question === 'یک سؤال کوتاه');
});

it('isolates Denix receipt identity from Admin and Sales bots', function (): void {
    denixTrustedRoot();
    $this->postJson('/api/telegram/ai/webhook', denixMessage(9005), denixHeaders())->assertNoContent();

    expect(app(TelegramUpdateReceipts::class)->claim(9005, TelegramBotContext::ADMIN))
        ->toBe(ClaimState::Claimed)
        ->and(app(TelegramUpdateReceipts::class)->claim(9005, TelegramBotContext::SALES))
        ->toBe(ClaimState::Claimed)
        ->and(DB::table('telegram_updates')->where('update_id', 9005)->count())->toBe(3);

    expect(fn () => new TelegramBotContext('mystery'))->toThrow(InvalidArgumentException::class);
});

it('ignores untrusted actors and unrelated messages without enqueueing', function (): void {
    denixTrustedRoot();
    $visitor = User::factory()->create(['is_active' => true]);
    TelegramAccount::query()->create(['user_id' => $visitor->getKey(), 'telegram_user_id' => 150003]);

    $payloads = [
        denixMessage(9010, userId: 150003),
        denixMessage(9011, chatId: -100111222333),
        denixMessage(9012, text: '/ai@anotherbot private'),
        denixMessage(9013, text: 'پیام عادی گروه'),
        denixMessage(9014, text: '/wallet'),
        denixMessage(9015, text: '/ai'),
        denixMessage(9016, text: '/ai '.str_repeat('a', 2100)),
    ];
    $payloads[] = array_replace_recursive(denixMessage(9017), ['message' => ['chat' => ['type' => 'private', 'id' => 150001]]]);
    $payloads[] = array_replace_recursive(denixMessage(9018), ['message' => ['from' => ['is_bot' => true]]]);
    $payloads[] = array_replace_recursive(denixMessage(9019), ['message' => ['from' => ['id' => 0]]]);
    $payloads[] = array_replace_recursive(denixMessage(9022), ['message' => ['sender_chat' => ['id' => -100987654321, 'type' => 'supergroup', 'title' => 'Anonymous Group']]]);

    foreach ($payloads as $payload) {
        $this->postJson('/api/telegram/ai/webhook', $payload, denixHeaders())->assertNoContent();
    }

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('only accepts replies to this Denix bot and preserves the topic ID', function (): void {
    denixTrustedRoot();

    $message = denixMessage(9020, text: 'توضیح بیشتر بده');
    $message['message']['message_thread_id'] = 12;
    $message['message']['reply_to_message'] = [
        'message_id' => 55,
        'date' => 1794219000,
        'chat' => ['id' => -100987654321, 'type' => 'supergroup', 'title' => 'DenaGate Operations'],
        'from' => ['id' => 100003, 'is_bot' => true, 'first_name' => 'Denix'],
        'text' => 'سلام',
    ];
    $this->postJson('/api/telegram/ai/webhook', $message, denixHeaders())->assertNoContent();

    $otherBot = denixMessage(9021, text: 'اشتباه');
    $otherBot['message']['reply_to_message'] = [
        'message_id' => 56,
        'date' => 1794219000,
        'chat' => ['id' => -100987654321, 'type' => 'supergroup', 'title' => 'DenaGate Operations'],
        'from' => ['id' => 100001, 'is_bot' => true, 'first_name' => 'Other Bot'],
    ];
    $this->postJson('/api/telegram/ai/webhook', $otherBot, denixHeaders())->assertNoContent();

    Queue::assertPushed(AnswerDenixMessage::class, 1);
    Queue::assertPushed(AnswerDenixMessage::class, fn (AnswerDenixMessage $job): bool => $job->question === 'توضیح بیشتر بده' && $job->threadId === 12);
});

it('rejects synchronous queues and does not create billable work inside webhooks', function (): void {
    denixTrustedRoot();
    config()->set('queue.default', 'sync');

    $this->postJson('/api/telegram/ai/webhook', denixMessage(9030), denixHeaders())->assertInternalServerError();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('fails closed when the cache cannot provide a shared replay fence', function (): void {
    denixTrustedRoot();
    config()->set('cache.default', 'array');

    $this->postJson('/api/telegram/ai/webhook', denixMessage(9040), denixHeaders())->assertInternalServerError();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('never registers the Denix webhook when its operator switches are off', function (): void {
    config()->set('telegram.denix.enabled', false);

    $this->artisan('telegram:webhook:set', ['--bot' => 'denix'])->assertFailed();
    Http::assertNothingSent();
});
