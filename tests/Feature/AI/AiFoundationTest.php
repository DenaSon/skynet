<?php

declare(strict_types=1);

use App\Modules\AI\Application\AskAssistant;
use App\Modules\AI\Application\DenixAssistant;
use App\Modules\AI\Application\DenizKnowledge;
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

it('keeps the Admin group instructions unchanged and limits customer support guidance to Business conversations', function (): void {
    $knowledge = app(DenizKnowledge::class);
    $group = $knowledge->instructions();
    $support = $knowledge->instructions(customerSupport: true);

    expect($group)->toContain('فعال‌سازی پس از بررسی و تأیید مدیریت انجام می‌شود و فوری یا تضمینی نیست.')
        ->not->toContain('@denaGateBot', 'اکنون در گفتگوی خصوصی حساب پشتیبانی', 'نقش مشاور حرفه‌ای و معرف محصولات', 'تقلید لهجه')
        ->and($support)->toContain(
            '@denaGateBot',
            'اکنون در گفتگوی خصوصی حساب پشتیبانی',
            'نقش مشاور حرفه‌ای و معرف محصولات',
            'در حداکثر دو جمله کوتاه و محترمانه پاسخ بده',
            'در درخواست‌های نامرتبط تکراری وارد بحث طولانی نشو',
            'صرف وجود لهجه یا شوخی نشانه نامرتبط‌بودن نیست',
            'پس از تکمیل شرایط و تأیید درخواست توسط مدیریت، نمایندگی فعال می‌شود',
        )
        ->not->toContain('فعال‌سازی پس از بررسی و تأیید مدیریت انجام می‌شود و فوری یا تضمینی نیست.');
});

it('sends support-only bot navigation, restrained emoji and human handoff rules in the System prompt', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response(gapgptChatResponse())]);

    app(AskAssistant::class)->handle('از کجا اعتبارم را افزایش بدم؟', 'gpt-6-luna', customerSupport: true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request['model'] === 'gpt-6-luna'
        && str_contains($request['messages'][0]['content'], '@denaGateBot')
        && str_contains($request['messages'][0]['content'], 'در بعضی پاسخ‌های مناسب از یک ایموجی')
        && str_contains($request['messages'][0]['content'], 'پشتیبان انسانی')
        && str_contains($request['messages'][0]['content'], 'سؤال تکراری نپرس')
        && str_contains($request['messages'][0]['content'], 'تقلید لهجه')
        && str_contains($request['messages'][0]['content'], 'در حداکثر دو جمله کوتاه')
        && str_contains($request['messages'][0]['content'], 'گویش محلی')
        && ! isset($request['tools']));

    expect((new DenixAssistant)->instructions())->toBe(app(DenizKnowledge::class)->instructions())
        ->and((new DenixAssistant(customerSupport: true))->instructions())->toBe(app(DenizKnowledge::class)->instructions(true));
});

it('sends truthful sales consultation guidance only to customer-support AI calls', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response(gapgptChatResponse())]);

    app(AskAssistant::class)->handle('شرایط نمایندگی و تضمین فعال‌سازی چیه؟', customerSupport: true);

    Http::assertSent(fn ($request): bool => $request['messages'][0]['role'] === 'system'
        && str_contains($request['messages'][0]['content'], '۳۵٪')
        && str_contains($request['messages'][0]['content'], '۹۰۰٬۰۰۰ تومان')
        && str_contains($request['messages'][0]['content'], 'فعال‌سازی نمایندگی انجام می‌شود')
        && str_contains($request['messages'][0]['content'], 'مزیت‌های محصول را با جمله‌های هشداردهنده پشت‌سرهم خنثی نکن')
        && str_contains($request['messages'][0]['content'], '@denaGateBot')
        && ! str_contains($request['messages'][0]['content'], 'فوری یا تضمینی نیست')
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
