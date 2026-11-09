<?php

declare(strict_types=1);

use App\Modules\AI\Application\AskAssistant;
use App\Modules\AI\Application\ModelCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set('denagate_ai.enabled', true);
    config()->set('ai.providers.gapgpt.key', 'test-only-ai-key');
    config()->set('ai.providers.gapgpt.url', 'https://api.gapgpt.app/v1');
});

function gapgptChatResponse(string $text = 'پاسخ آزمایشی'): array
{
    return [
        'id' => 'fake-chat',
        'object' => 'chat.completion',
        'model' => 'gpt-6-luna',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 12, 'total_tokens' => 21],
    ];
}

it('uses the official OpenAI-compatible chat-completions contract and the chosen model', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response(gapgptChatResponse())]);

    $answer = app(AskAssistant::class)->handle('سلام', 'gpt-6-luna');

    expect($answer->text)->toBe('پاسخ آزمایشی')
        ->and($answer->model)->toBe('gpt-6-luna')
        ->and($answer->inputTokens)->toBe(9)
        ->and($answer->outputTokens)->toBe(12);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer test-only-ai-key')
        && $request['model'] === 'gpt-6-luna'
        && $request['messages'][0]['role'] === 'system'
        && str_contains($request['messages'][0]['content'], 'دنیز')
        && $request['messages'][1]['role'] === 'user'
        && $request['messages'][1]['content'] === 'سلام'
        && $request['max_tokens'] === 700
        && ! isset($request['tools']));
});

it('rejects disabled AI before sending a request', function (): void {
    config()->set('denagate_ai.enabled', false);
    Http::fake();

    expect(fn () => app(AskAssistant::class)->handle('سلام'))->toThrow(RuntimeException::class, 'AI is disabled.');
    Http::assertNothingSent();
});

it('rejects missing credentials and untrusted upstream URLs', function (): void {
    Http::fake();
    config()->set('ai.providers.gapgpt.key', null);
    expect(fn () => app(AskAssistant::class)->handle('سلام'))->toThrow(RuntimeException::class, 'AI provider is not configured.');

    config()->set('ai.providers.gapgpt.key', 'test-only-ai-key');
    config()->set('ai.providers.gapgpt.url', 'http://169.254.169.254/latest/meta-data');
    expect(fn () => app(AskAssistant::class)->handle('سلام'))->toThrow(RuntimeException::class, 'AI provider is not configured.');
    Http::assertNothingSent();
});

it('rejects unknown model identifiers and invalid input without network IO', function (): void {
    Http::fake();

    expect(fn () => app(AskAssistant::class)->handle('hello', 'unapproved-model'))->toThrow(ValidationException::class);
    expect(fn () => app(AskAssistant::class)->handle('   '))->toThrow(ValidationException::class);
    expect(fn () => app(AskAssistant::class)->handle(str_repeat('A', 2001)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('keeps the documented model choices explicit and small', function (): void {
    expect(ModelCatalog::MODELS)->toBe([
        'gpt-6-luna',
        'gapgpt-deepseek-v4.1-flash',
        'gpt-6.1-terra',
        'gpt-6.1-sol',
        'gemini-2.5-flash-lite',
        'gpt-5-nano',
    ]);
});

it('hides upstream response bodies and credentials on provider failures', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response([
        'error' => ['message' => 'SECRET-test-only-ai-key should never be exposed'],
    ], 429)]);

    try {
        app(AskAssistant::class)->handle('سلام');
        $this->fail('The provider failure should have been rejected.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('AI request could not be completed.')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('sanitizes upstream server errors rather than exposing provider content', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response([
        'error' => ['message' => 'private upstream response'],
    ], 503)]);

    expect(fn () => app(AskAssistant::class)->handle('سلام'))
        ->toThrow(RuntimeException::class, 'AI request could not be completed.');
});

it('sanitizes transport failures without retrying a billable request', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => function () {
        throw new ConnectionException('private transport details');
    }]);

    expect(fn () => app(AskAssistant::class)->handle('سلام'))
        ->toThrow(RuntimeException::class, 'AI request could not be completed.');
});

it('rejects empty or unreasonably long provider text', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::sequence()
        ->push(gapgptChatResponse(' '))
        ->push(gapgptChatResponse(str_repeat('x', 6001)))]);

    expect(fn () => app(AskAssistant::class)->handle('سلام'))->toThrow(RuntimeException::class, 'AI request could not be completed.');
    expect(fn () => app(AskAssistant::class)->handle('سلام'))->toThrow(RuntimeException::class, 'AI request could not be completed.');
});


it('records safe, actionable provider diagnostics without upstream message or credentials', function (): void {
    Log::spy();
    Http::fake(['https://api.gapgpt.app/*' => Http::response([
        'error' => ['message' => 'SECRET-test-only-ai-key private conversation'],
    ], 503)]);

    expect(fn () => app(AskAssistant::class)->handle('سلام'))
        ->toThrow(RuntimeException::class, 'AI request could not be completed.');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'AI request failed.'
            && in_array($context['category'] ?? null, ['http_error', 'provider_or_sdk_error'], true)
            && is_string($context['exception_type'] ?? null)
            && is_string($context['source_file'] ?? null)
            && is_int($context['source_line'] ?? null)
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'SECRET-test-only-ai-key')
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'private conversation'),
    );
});

it('distinguishes an invalid model response in sanitized diagnostics', function (): void {
    Log::spy();
    Http::fake(['https://api.gapgpt.app/*' => Http::response(gapgptChatResponse(' '))]);

    expect(fn () => app(AskAssistant::class)->handle('سلام'))
        ->toThrow(RuntimeException::class, 'AI request could not be completed.');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'AI request failed.'
            && ($context['category'] ?? null) === 'invalid_response'
            && ($context['exception_type'] ?? null) === RuntimeException::class,
    );
});
