<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use App\Modules\AI\Application\AskAssistant;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.denix.enabled', true);
    config()->set('denagate_ai.enabled', true);
    config()->set('cache.default', 'database');
    config()->set('telegram.denix.token', '100003:denix-test-token');
    config()->set('telegram.denix.webhook_secret', 'denix-only-secret');
    config()->set('telegram.webhook_secret', 'admin-only-secret');
    config()->set('telegram.sales.webhook_secret', 'sales-only-secret');
    config()->set('nutgram.token', '100001:admin-token');
    config()->set('telegram.sales.token', '100002:sales-token');
    config()->set('telegram.denix.group_chat_id', -100987654321);
    config()->set('ai.providers.gapgpt.key', 'test-gapgpt-key');
    config()->set('ai.providers.gapgpt.url', 'https://api.gapgpt.app/v1');
    config()->set('super_admin.telegram_ids', ['160001', '160002']);

    Role::findOrCreate('super-admin', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 160001]);

    Cache::flush();
    Http::preventStrayRequests();
});

function denixJob(int $messageId = 77): AnswerDenixMessage
{
    return new AnswerDenixMessage(-100987654321, 160001, $messageId, 12, 'سلام');
}

function denixFakeChat(string $text = 'پاسخ دنیکس'): array
{
    return [
        'id' => 'chat-unit',
        'object' => 'chat.completion',
        'model' => 'gpt-6-luna',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
    ];
}

function executeDenixJob(AnswerDenixMessage $job): void
{
    $job->handle(app(DenixGroupAccess::class), app(AskAssistant::class), app(DenixMessageSender::class));
}

it('calls GapGPT once and delivers a plain Telegram reply to the original group topic', function (): void {
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(denixFakeChat()),
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 888]]),
    ]);

    executeDenixJob(denixJob());
    executeDenixJob(denixJob());

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request['model'] === 'gpt-6-luna'
        && $request['messages'][1]['content'] === 'سلام');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === -100987654321
        && $request['message_thread_id'] === 12
        && $request['text'] === 'پاسخ دنیکس'
        && json_decode($request['reply_parameters'], true)['message_id'] === 77
        && ! isset($request['parse_mode']));
});

it('revalidates group, kill switch and Super Admin authorization at execution time', function (): void {
    Http::fake();
    config()->set('denagate_ai.enabled', false);
    executeDenixJob(denixJob(78));

    config()->set('denagate_ai.enabled', true);
    config()->set('telegram.denix.group_chat_id', -100111222333);
    executeDenixJob(denixJob(79));

    config()->set('telegram.denix.group_chat_id', -100987654321);
    $user = TelegramAccount::query()->where('telegram_user_id', 160001)->firstOrFail()->user;
    $user->removeRole('super-admin');
    executeDenixJob(denixJob(80));

    Http::assertNothingSent();
});

it('never purchases a second AI response after a billable provider failure', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response([
        'error' => ['message' => 'private upstream details'],
    ], 503)]);

    expect(fn () => executeDenixJob(denixJob(81)))
        ->toThrow(RuntimeException::class, 'AI request could not be completed.');

    executeDenixJob(denixJob(81));
    Http::assertSentCount(1);
});

it('requires a shared replay store and never invokes the provider from an array cache', function (): void {
    Http::fake();
    config()->set('cache.default', 'array');

    expect(fn () => executeDenixJob(denixJob(83)))
        ->toThrow(RuntimeException::class, 'Denix shared cache is not configured.');

    Http::assertNothingSent();
});

it('will not resend after a Telegram delivery failure of uncertain outcome', function (): void {
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(denixFakeChat()),
        'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'private error'], 500),
    ]);

    expect(fn () => executeDenixJob(denixJob(82)))
        ->toThrow(RuntimeException::class, 'Denix response delivery failed.');

    executeDenixJob(denixJob(82));
    Http::assertSentCount(2);
});
