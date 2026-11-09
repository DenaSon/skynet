<?php

declare(strict_types=1);

use App\Modules\AI\Application\AskAssistant;
use App\Modules\AI\Application\DenixAssistant;
use App\Modules\AI\Application\ModelCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config()->set('denagate_ai.enabled', true);
    config()->set('denagate_ai.verified_economy_models', []);
    config()->set('ai.providers.gapgpt.key', 'test-only-key');
    config()->set('ai.providers.gapgpt.url', 'https://api.gapgpt.app/v1');
});

it('uses short, truthful and fixed Deniz product knowledge on every request', function (): void {
    $instructions = (new DenixAssistant)->instructions();

    expect($instructions)->toContain('دنیز')
        ->toContain('دناگیت')
        ->toContain('پنل نمایندگی')
        ->toContain('دسترسی نداری')
        ->toContain('انسان واقعی نیستی')
        ->not->toContain('دنیکس')
        ->and(mb_strlen($instructions))->toBeLessThan(1100);
});

it('keeps unverified economy candidates unselectable until explicitly verified', function (): void {
    $catalog = app(ModelCatalog::class);

    expect(ModelCatalog::selectable())->toBe(['gpt-6-luna', 'gapgpt-deepseek-v4.1-flash'])
        ->and($catalog->selectedByNumber(3))->toBeNull()
        ->and(fn () => $catalog->resolve('gemini-2.5-flash-lite'))->toThrow(ValidationException::class)
        ->and(fn () => $catalog->resolve('gpt-5-nano'))->toThrow(ValidationException::class);
});

it('only exposes exact, preapproved economy candidates, preserving existing model indices', function (): void {
    config()->set('denagate_ai.verified_economy_models', [
        'untrusted-model',
        'gpt-5-nano',
        'gemini-2.5-flash-lite',
        'gpt-6.1-sol',
    ]);

    $catalog = app(ModelCatalog::class);

    expect(ModelCatalog::selectable())->toBe([
        'gpt-6-luna',
        'gapgpt-deepseek-v4.1-flash',
        'gemini-2.5-flash-lite',
        'gpt-5-nano',
    ])
        ->and($catalog->selectedByNumber(1))->toBe('gpt-6-luna')
        ->and($catalog->selectedByNumber(2))->toBe('gapgpt-deepseek-v4.1-flash')
        ->and($catalog->selectedByNumber(3))->toBe('gemini-2.5-flash-lite')
        ->and($catalog->selectedByNumber(4))->toBe('gpt-5-nano')
        ->and($catalog->selectedByNumber(5))->toBeNull()
        ->and(fn () => $catalog->resolve('untrusted-model'))->toThrow(ValidationException::class)
        ->and(fn () => $catalog->resolve('gpt-6.1-sol'))->toThrow(ValidationException::class);

    config()->set('denagate_ai.verified_economy_models', []);
    expect(ModelCatalog::selectable())->toHaveCount(2);
});

it('accepts ten bounded turns but rejects more than ten before any provider IO', function (): void {
    Http::fake(['https://api.gapgpt.app/*' => Http::response([
        'id' => 'deniz-test',
        'object' => 'chat.completion',
        'model' => 'gpt-6-luna',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'پاسخ کوتاه'], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 33, 'completion_tokens' => 12],
    ])]);

    $history = [];

    foreach (range(1, 5) as $turn) {
        $history[] = ['role' => 'user', 'content' => 'سؤال '.$turn];
        $history[] = ['role' => 'assistant', 'content' => 'پاسخ '.$turn];
    }

    expect(config('denagate_ai.context_max_messages'))->toBe(10);
    expect(app(AskAssistant::class)->handle('سؤال جدید', 'gpt-6-luna', $history)->text)->toBe('پاسخ کوتاه');

    Http::assertSent(fn ($request): bool => count($request['messages']) === 12
        && str_contains($request['messages'][0]['content'], 'دنیز')
        && str_contains($request['messages'][0]['content'], 'دناگیت')
        && $request['messages'][11]['content'] === 'سؤال جدید');

    $history[] = ['role' => 'user', 'content' => 'سؤال اضافی'];

    expect(fn () => app(AskAssistant::class)->handle('سؤال جدید', 'gpt-6-luna', $history))
        ->toThrow(ValidationException::class);

    Http::assertSentCount(1);
});
