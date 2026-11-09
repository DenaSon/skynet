<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final readonly class AskAssistant
{
    private const APPROVED_URLS = [
        'https://api.gapgpt.app/v1',
        'https://api.gapapi.com/v1',
    ];

    public function __construct(private ModelCatalog $models) {}

    /**
     * @param  list<array<string, mixed>>  $history
     */
    public function handle(string $prompt, ?string $modelId = null, array $history = []): AssistantAnswer
    {
        if (config('denagate_ai.enabled') !== true) {
            throw new RuntimeException('AI is disabled.');
        }

        $model = $this->models->resolve($modelId);
        $prompt = trim($prompt);

        if ($prompt === '' || mb_strlen($prompt) > (int) config('denagate_ai.max_input_chars', 2000)) {
            throw ValidationException::withMessages(['prompt' => 'Invalid AI prompt.']);
        }

        if (count($history) > (int) config('denagate_ai.context_max_messages', 6)) {
            throw ValidationException::withMessages(['history' => 'Invalid AI history.']);
        }

        $historyCharacters = 0;
        foreach ($history as $message) {
            if (! is_array($message)
                || ! in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null)
                || ($length = mb_strlen($message['content'])) < 1
                || $length > (int) config('denagate_ai.max_output_chars', 6000)) {
                throw ValidationException::withMessages(['history' => 'Invalid AI history.']);
            }

            $historyCharacters += $length;
        }

        if ($historyCharacters > (int) config('denagate_ai.context_max_chars', 6000)) {
            throw ValidationException::withMessages(['history' => 'Invalid AI history.']);
        }

        $key = config('ai.providers.gapgpt.key');
        $url = config('ai.providers.gapgpt.url');

        // Prevent missing credentials or unintended upstream endpoints before SDK invocation.
        if (! is_string($key) || trim($key) === ''
            || ! is_string($url) || ! in_array(rtrim($url, '/'), self::APPROVED_URLS, true)) {
            throw new RuntimeException('AI provider is not configured.');
        }

        try {
            $response = (new DenixAssistant)->withMessages($history)->prompt(
                $prompt,
                provider: 'gapgpt',
                model: $model,
                timeout: (int) config('denagate_ai.timeout_seconds', 20),
            );

            $text = trim($response->text);

            if ($text === '' || mb_strlen($text) > (int) config('denagate_ai.max_output_chars', 6000)) {
                throw new RuntimeException('Invalid AI response.');
            }

            return new AssistantAnswer(
                model: $model,
                text: $text,
                inputTokens: $response->usage->inputTokens,
                outputTokens: $response->usage->outputTokens,
            );
        } catch (Throwable $error) {
            // Provider responses and exception messages may contain secrets or private prompts.
            Log::warning('AI request failed.', ['exception_type' => $error::class]);

            throw new RuntimeException('AI request could not be completed.');
        }
    }
}
