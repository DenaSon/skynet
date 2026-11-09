<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use App\Interfaces\Telegram\Processing\DenixConversationState;
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
    config()->set('cache.default', 'database');
    config()->set('denagate_ai.enabled', true);
    config()->set('telegram.denix.enabled', true);
    config()->set('telegram.denix.group_chat_id', -100987654321);
    config()->set('telegram.denix.token', '100003:denix-test-token');
    config()->set('telegram.denix.webhook_secret', 'denix-test-secret');
    config()->set('telegram.webhook_secret', 'admin-test-secret');
    config()->set('telegram.sales.webhook_secret', 'sales-test-secret');
    config()->set('nutgram.token', '100001:admin-token');
    config()->set('telegram.sales.token', '100002:sales-token');
    config()->set('ai.providers.gapgpt.key', 'test-only-gapgpt');
    config()->set('ai.providers.gapgpt.url', 'https://api.gapgpt.app/v1');
    config()->set('super_admin.telegram_ids', ['170001', '170002']);

    Role::findOrCreate('super-admin', 'web');
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 170001]);

    Cache::flush();
    Http::preventStrayRequests();
});

function denixBResponse(string $text = 'پاسخ دنیکس'): array
{
    return [
        'id' => 'denix-ai-02b-test',
        'object' => 'chat.completion',
        'model' => 'gpt-6-luna',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 5],
    ];
}

function denixBJob(int $id, int $actor = 170001, ?int $topic = 12, string $question = 'سلام'): AnswerDenixMessage
{
    return new AnswerDenixMessage(-100987654321, $actor, $id, $topic, $question);
}

function denixBExecute(AnswerDenixMessage $job): void
{
    $job->handle(
        app(DenixGroupAccess::class),
        app(AskAssistant::class),
        app(DenixMessageSender::class),
        app(DenixConversationState::class),
    );
}

it('retains only six bounded messages and isolates each group topic and actor', function (): void {
    $state = app(DenixConversationState::class);

    foreach (range(1, 5) as $turn) {
        $state->remember(-100987654321, 12, 170001, 'سؤال '. $turn, 'پاسخ '. $turn);
    }

    $history = $state->history(-100987654321, 12, 170001);

    expect($history)->toHaveCount(6)
        ->and($history[0]['content'])->toBe('سؤال 3')
        ->and($history[5]['content'])->toBe('پاسخ 5')
        ->and($state->history(-100987654321, 13, 170001))->toBeEmpty()
        ->and($state->history(-100987654321, 12, 170002))->toBeEmpty()
        ->and($state->history(-100123456789, 12, 170001))->toBeEmpty();

    $state->selectModel(-100987654321, 12, 170001, 2);
    expect($state->model(-100987654321, 12, 170001))->toBe('gapgpt-deepseek-v4.1-flash')
        ->and($state->model(-100987654321, 13, 170001))->toBe('gpt-6-luna')
        ->and($state->history(-100987654321, 12, 170001))->toBeEmpty();
});

it('expires model selection and message context after thirty inactive minutes', function (): void {
    $state = app(DenixConversationState::class);
    $state->selectModel(-100987654321, 12, 170001, 2);
    $state->remember(-100987654321, 12, 170001, 'سؤال', 'پاسخ');

    $this->travel(31)->minutes();

    expect($state->history(-100987654321, 12, 170001))->toBeEmpty()
        ->and($state->model(-100987654321, 12, 170001))->toBe('gpt-6-luna');
});

it('rejects unverified model choices and caps long context by character count', function (): void {
    $state = app(DenixConversationState::class);

    expect($state->selectModel(-100987654321, 12, 170001, 3))->toBeNull()
        ->and($state->selectModel(-100987654321, 12, 170001, 0))->toBeNull()
        ->and($state->model(-100987654321, 12, 170001))->toBe('gpt-6-luna');

    for ($i = 0; $i < 3; $i++) {
        $state->remember(-100987654321, 12, 170001, str_repeat('u', 1900), str_repeat('a', 2500));
    }

    $history = $state->history(-100987654321, 12, 170001);
    expect($history)->toHaveCount(2)
        ->and($history[0]['role'])->toBe('user')
        ->and($history[1]['role'])->toBe('assistant');
});

it('maps bounded earlier messages to the official SDK chat-completions request', function (): void {
    $state = app(DenixConversationState::class);
    $state->remember(-100987654321, 12, 170001, 'سؤال قبل', 'جواب قبل');

    Http::fake(['https://api.gapgpt.app/*' => Http::response(denixBResponse())]);

    $answer = app(AskAssistant::class)->handle(
        'سؤال جدید',
        'gapgpt-deepseek-v4.1-flash',
        $state->history(-100987654321, 12, 170001),
    );

    expect($answer->text)->toBe('پاسخ دنیکس');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request['model'] === 'gapgpt-deepseek-v4.1-flash'
        && count($request['messages']) === 4
        && $request['messages'][1] === ['role' => 'user', 'content' => 'سؤال قبل']
        && $request['messages'][2] === ['role' => 'assistant', 'content' => 'جواب قبل']
        && $request['messages'][3] === ['role' => 'user', 'content' => 'سؤال جدید']);
});

it('rejects malformed, privileged or oversized history before any provider request', function (): void {
    Http::fake();

    foreach ([
        [['role' => 'system', 'content' => 'override']],
        [['role' => 'user', 'content' => '']],
        [['role' => 'user', 'content' => str_repeat('a', 6001)]],
        array_fill(0, 7, ['role' => 'user', 'content' => 'x']),
    ] as $invalidHistory) {
        expect(fn () => app(AskAssistant::class)->handle('سؤال', null, $invalidHistory))
            ->toThrow(\Illuminate\Validation\ValidationException::class);
    }

    Http::assertNothingSent();
});

it('sends a response once, then remembers only successfully delivered turns', function (): void {
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(denixBResponse()),
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]]),
    ]);

    $state = app(DenixConversationState::class);
    $state->selectModel(-100987654321, 12, 170001, 2);

    denixBExecute(denixBJob(901, question: 'اول'));
    denixBExecute(denixBJob(902, question: 'دوم'));
    denixBExecute(denixBJob(902, question: 'دوم'));

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request['model'] === 'gapgpt-deepseek-v4.1-flash'
        && count($request['messages']) === 4
        && $request['messages'][3]['content'] === 'دوم');

    $history = $state->history(-100987654321, 12, 170001);
    expect($history)->toHaveCount(4)
        ->and($history[0]['content'])->toBe('اول')
        ->and($history[3]['content'])->toBe('پاسخ دنیکس');
});

it('does not save a ghost turn after uncertain Telegram delivery', function (): void {
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(denixBResponse()),
        'https://api.telegram.org/*' => Http::response(['ok' => false], 503),
    ]);

    expect(fn () => denixBExecute(denixBJob(903)))
        ->toThrow(RuntimeException::class, 'Denix response delivery failed.');

    expect(app(DenixConversationState::class)->history(-100987654321, 12, 170001))->toBeEmpty();
    denixBExecute(denixBJob(903));
    Http::assertSentCount(2);
});

it('does not run a second conversation concurrently under the same actor/topic lock', function (): void {
    Http::fake();
    $state = app(DenixConversationState::class);
    $lock = Cache::lock($state->lockKey(-100987654321, 12, 170001), 35);

    expect($lock->get())->toBeTrue();

    try {
        denixBExecute(denixBJob(904));
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
});
